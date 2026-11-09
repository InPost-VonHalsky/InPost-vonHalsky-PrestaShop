<?php
/**
 * LogJournalRepository
 *
 * Persistent journal of the module's warnings and errors, feeding the status
 * panel (counts, most frequent problems, recent entries). The regular log files
 * stay the full record; this only keeps what an operator needs to see at a glance.
 *
 * Raw SQL (like ProductHintCacheRepository) because LoggerService writes here:
 * it must work in admin/CLI/cron alike and must never log itself - any failure
 * is swallowed, otherwise a broken journal would recurse through the logger.
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Repository;

use Db;

class LogJournalRepository
{
    public const RETENTION_DAYS = 30;

    private const MAX_MESSAGE = 255;
    private const MAX_CONTEXT = 2000;

    /** Context keys whose values must never be persisted */
    private const SECRET_KEY_PATTERN = '/token|secret|password|authorization|code_verifier/i';

    /** Context keys added by LoggerService::enrichContext() - noise in the journal */
    private const IGNORED_CONTEXT_KEYS = ['module', 'php_version', 'memory_usage'];

    /** @var bool|null null = not checked yet, false = table missing (module not upgraded yet) */
    private static $available = null;

    /** @var bool Re-entrancy guard */
    private static $writing = false;

    /** Table name without the shop prefix */
    private string $tableName = 'inpost_log_journal';

    private string $table;

    public function __construct()
    {
        $this->table = _DB_PREFIX_ . $this->tableName;
    }

    /**
     * Record a warning/error. Never throws.
     *
     * @param array<string,mixed> $context
     */
    public function record(string $level, string $message, array $context = []): void
    {
        if (self::$writing || self::$available === false) {
            return;
        }

        self::$writing = true;
        try {
            $db = Db::getInstance();
            $message = mb_substr(trim($message), 0, self::MAX_MESSAGE);

            $ok = $db->insert($this->tableName, [
                'level' => pSQL(strtolower($level)),
                'fingerprint' => pSQL(sha1(strtolower($level) . '|' . self::normalize($message))),
                'message' => pSQL($message),
                'context' => pSQL($this->encodeContext($context), true),
                'created_at' => date('Y-m-d H:i:s'),
            ], false, false);

            if ($ok) {
                self::$available = true;
            } elseif (self::$available === null) {
                self::$available = $this->tableExists();
            }

            // Cheap amortised retention instead of a dedicated cron job
            if (mt_rand(1, 200) === 1) {
                $this->purgeOld();
            }
        } catch (\Throwable $e) {
            if (self::$available === null) {
                self::$available = $this->tableExists();
            }
        } finally {
            self::$writing = false;
        }
    }

    /**
     * Entry counts per level since the given moment.
     *
     * @return array{error:int,warning:int}
     */
    public function countByLevelSince(\DateTimeInterface $since): array
    {
        $counts = ['error' => 0, 'warning' => 0];
        $rows = $this->select(
            'SELECT `level`, COUNT(*) AS cnt FROM `' . $this->table . '`'
            . " WHERE `created_at` >= '" . pSQL($since->format('Y-m-d H:i:s')) . "'"
            . ' GROUP BY `level`'
        );

        foreach ($rows as $row) {
            // critical/alert/emergency count as errors
            $key = $row['level'] === 'warning' ? 'warning' : 'error';
            $counts[$key] += (int) $row['cnt'];
        }

        return $counts;
    }

    /**
     * Most frequent problems since the given moment, grouped by fingerprint
     * (message with ids/numbers stripped).
     *
     * @return array<int,array<string,mixed>>
     */
    public function topProblems(\DateTimeInterface $since, int $limit = 10): array
    {
        return $this->select(
            'SELECT `level`, MAX(`message`) AS message, COUNT(*) AS cnt,'
            . ' MIN(`created_at`) AS first_at, MAX(`created_at`) AS last_at'
            . ' FROM `' . $this->table . '`'
            . " WHERE `created_at` >= '" . pSQL($since->format('Y-m-d H:i:s')) . "'"
            . ' GROUP BY `fingerprint`, `level`'
            . ' ORDER BY cnt DESC, last_at DESC'
            . ' LIMIT ' . (int) $limit
        );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function recent(int $limit = 20): array
    {
        return $this->select(
            'SELECT `level`, `message`, `context`, `created_at` FROM `' . $this->table . '`'
            . ' ORDER BY `journal_id` DESC LIMIT ' . (int) $limit
        );
    }

    /**
     * Oldest entry date - tells the panel how far back the journal reaches.
     */
    public function oldestEntryAt(): ?string
    {
        $rows = $this->select('SELECT MIN(`created_at`) AS oldest FROM `' . $this->table . '`');

        return $rows[0]['oldest'] ?? null;
    }

    public function isAvailable(): bool
    {
        if (self::$available === null) {
            self::$available = $this->tableExists();
        }

        return self::$available;
    }

    public function purgeOld(): void
    {
        try {
            Db::getInstance()->execute(
                'DELETE FROM `' . $this->table . "` WHERE `created_at` < '"
                . pSQL(date('Y-m-d H:i:s', strtotime('-' . self::RETENTION_DAYS . ' days'))) . "'"
            );
        } catch (\Throwable $e) {
            // Retention is best-effort
        }
    }

    /**
     * Strip the variable parts (UUIDs, numbers, quoted values) so repeats of the
     * same problem share one fingerprint.
     */
    private static function normalize(string $message): string
    {
        $message = preg_replace('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', '{id}', $message);
        $message = preg_replace('/\d+/', '{n}', (string) $message);

        return strtolower((string) $message);
    }

    /**
     * @param array<string,mixed> $context
     */
    private function encodeContext(array $context): string
    {
        foreach (self::IGNORED_CONTEXT_KEYS as $key) {
            unset($context[$key]);
        }
        if (!$context) {
            return '';
        }

        array_walk_recursive($context, static function (&$value, $key): void {
            if (is_string($key) && preg_match(self::SECRET_KEY_PATTERN, $key)) {
                $value = '***';
            } elseif (is_object($value)) {
                $value = get_class($value);
            }
        });

        $json = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return mb_substr((string) $json, 0, self::MAX_CONTEXT);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function select(string $sql): array
    {
        if (!$this->isAvailable()) {
            return [];
        }

        try {
            $rows = Db::getInstance()->executeS($sql);

            return is_array($rows) ? $rows : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function tableExists(): bool
    {
        try {
            return (bool) Db::getInstance()->executeS("SHOW TABLES LIKE '" . pSQL($this->table) . "'");
        } catch (\Throwable $e) {
            return false;
        }
    }
}
