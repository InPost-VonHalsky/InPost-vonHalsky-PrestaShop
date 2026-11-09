<?php
/**
 * ProductHintCacheRepository
 *
 * Raw-SQL repository for the product hint cache. Kept out of Doctrine (and the
 * PHPStan-excluded Entity dir) so it works identically in admin/CLI/cron and
 * avoids the TablePrefixSubscriber. Supports positive and negative caching.
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Repository;

use Db;
use Inpost\VonHalsky\Service\Util\LoggerService;

class ProductHintCacheRepository
{
    private Db $db;
    private LoggerService $logger;
    private string $table;

    public function __construct(LoggerService $logger)
    {
        $this->db = Db::getInstance();
        $this->logger = $logger;
        $this->table = _DB_PREFIX_ . 'inpost_product_hint_cache';
    }

    /**
     * Build the deterministic cache key.
     */
    private function key(string $type, string $query, string $language): string
    {
        return hash('sha256', $type . '|' . mb_strtolower(trim($query)) . '|' . $language);
    }

    /**
     * Fetch a cached entry. Returns ['negative' => true] for a cached miss,
     * ['hits' => array] for a positive hit, or null on miss/expiry.
     *
     * @return array<string,mixed>|null
     */
    public function get(string $type, string $query, string $language): ?array
    {
        try {
            $cacheKey = $this->key($type, $query, $language);
            // getRow() appends LIMIT 1 itself - adding it here yields "LIMIT 1 LIMIT 1"
            $row = $this->db->getRow(
                'SELECT `payload`, `is_negative`, `expires_at` FROM `' . $this->table . '`'
                . " WHERE `cache_key` = '" . pSQL($cacheKey) . "'"
            );

            if (!$row) {
                return null;
            }

            if (strtotime($row['expires_at']) < time()) {
                return null;
            }

            if ((int) $row['is_negative'] === 1) {
                return ['negative' => true];
            }

            $hits = json_decode((string) $row['payload'], true);
            return ['hits' => is_array($hits) ? $hits : []];
        } catch (\Exception $e) {
            $this->logger->warning('Hint cache read failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Store a cache entry. $hints null ⇒ negative cache.
     *
     * @param array<int,mixed>|null $hints
     */
    public function put(string $type, string $query, string $language, ?array $hints, int $ttl): void
    {
        try {
            $cacheKey = $this->key($type, $query, $language);
            $isNegative = $hints === null || $hints === [];
            $payload = $isNegative ? null : json_encode($hints);
            $expiresAt = date('Y-m-d H:i:s', time() + max(60, $ttl));
            $now = date('Y-m-d H:i:s');

            $data = [
                'cache_key' => pSQL($cacheKey),
                'query_type' => pSQL($type),
                'query_value' => pSQL(mb_substr(trim($query), 0, 255)),
                'language' => pSQL($language),
                'payload' => $payload === null ? null : pSQL($payload, true),
                'is_negative' => $isNegative ? 1 : 0,
                'expires_at' => pSQL($expiresAt),
                'created_at' => pSQL($now),
            ];

            // Upsert on the unique cache_key.
            $this->db->execute(
                'INSERT INTO `' . $this->table . '` '
                . '(`cache_key`,`query_type`,`query_value`,`language`,`payload`,`is_negative`,`expires_at`,`created_at`) VALUES ('
                . "'" . $data['cache_key'] . "',"
                . "'" . $data['query_type'] . "',"
                . "'" . $data['query_value'] . "',"
                . "'" . $data['language'] . "',"
                . ($payload === null ? 'NULL' : "'" . $data['payload'] . "'") . ','
                . (int) $data['is_negative'] . ','
                . "'" . $data['expires_at'] . "',"
                . "'" . $data['created_at'] . "'"
                . ') ON DUPLICATE KEY UPDATE '
                . '`query_type` = VALUES(`query_type`), '
                . '`query_value` = VALUES(`query_value`), '
                . '`language` = VALUES(`language`), '
                . '`payload` = VALUES(`payload`), '
                . '`is_negative` = VALUES(`is_negative`), '
                . '`expires_at` = VALUES(`expires_at`), '
                . '`created_at` = VALUES(`created_at`)'
            );
        } catch (\Exception $e) {
            $this->logger->warning('Hint cache write failed: ' . $e->getMessage());
        }
    }

    /**
     * Delete expired rows. Returns affected count.
     */
    public function purgeExpired(): int
    {
        try {
            $this->db->execute(
                'DELETE FROM `' . $this->table . "` WHERE `expires_at` < '" . pSQL(date('Y-m-d H:i:s')) . "'"
            );
            return (int) $this->db->Affected_Rows();
        } catch (\Exception $e) {
            $this->logger->warning('Hint cache purge failed: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Truncate the whole cache. Returns affected count.
     */
    public function clear(): int
    {
        try {
            $this->db->execute('DELETE FROM `' . $this->table . '`');
            return (int) $this->db->Affected_Rows();
        } catch (\Exception $e) {
            $this->logger->warning('Hint cache clear failed: ' . $e->getMessage());
            return 0;
        }
    }
}
