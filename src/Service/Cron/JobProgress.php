<?php
/**
 * JobProgress
 *
 * Live progress of the background job running in this process (cron or a manual
 * run from the admin panel), stored in ps_configuration so the "Background jobs"
 * panel can show it from any web worker - also when the cron runs in another
 * container (file locks are local to one host, the database is shared).
 *
 * One record per job: state, start time, heartbeat, processed / total items,
 * counters and the last error. A stop request is a separate flag the running
 * job checks between items (cooperative stop - nothing is killed half-way).
 *
 * Values are read straight from the database (not through Configuration::get),
 * because a long-running CLI process keeps the configuration loaded at start-up.
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Service\Cron;

use Configuration;
use Db;

class JobProgress
{
    public const STATE_RUNNING = 'running';
    public const STATE_FINISHED = 'finished';
    public const STATE_FAILED = 'failed';
    public const STATE_STOPPED = 'stopped';
    /** Reported by get() when a running job stopped sending heartbeats (process killed / crashed) */
    public const STATE_STALE = 'stale';

    /**
     * A job reporting per-item progress is considered dead after this many seconds without a heartbeat
     */
    public const STALE_AFTER = 180;

    /**
     * Same, for jobs reporting only start / end (they can run for many minutes)
     */
    public const STALE_AFTER_NO_PROGRESS = 1800;

    private const KEY_PROGRESS = 'INPOSTSA_JOB_PROGRESS_';
    private const KEY_STOP = 'INPOSTSA_JOB_STOP_';

    /**
     * Seconds between progress writes / stop-flag reads (they hit the database)
     */
    private const WRITE_EVERY = 1.0;
    private const STOP_CHECK_EVERY = 2.0;

    private ?string $job = null;

    /** @var array<string,mixed> */
    private array $data = [];

    private float $lastWrite = 0.0;
    private float $lastStopCheck = 0.0;
    private bool $stopRequested = false;

    /**
     * Start tracking a job run in this process
     *
     * @param string $job Job name (e.g. order_sync)
     * @param string $trigger "cron" or "manual"
     */
    public function begin(string $job, string $trigger): void
    {
        $this->job = $job;
        $this->stopRequested = false;
        $this->lastStopCheck = 0.0;
        $this->store(self::KEY_STOP . strtoupper($job), '');

        $now = time();
        $this->data = [
            'job' => $job,
            'state' => self::STATE_RUNNING,
            'trigger' => $trigger,
            'pid' => getmypid() ?: null,
            'host' => gethostname() ?: null,
            'started_at' => $now,
            'updated_at' => $now,
            'finished_at' => null,
            'reports_progress' => false,
            'done' => 0,
            'total' => null,
            'created' => 0,
            'updated' => 0,
            'errors' => 0,
            'step' => null,
            'message' => null,
            'last_error' => null,
        ];
        $this->write(true);
    }

    /**
     * Update the progress of the job running in this process (no-op when none).
     * Writes are throttled to one per second unless $force.
     *
     * @param array<string,mixed> $fields done, total, created, updated, errors, step, last_error, ...
     */
    public function report(array $fields, bool $force = false): void
    {
        if ($this->job === null) {
            return;
        }

        $this->data = array_merge($this->data, $fields, ['reports_progress' => true]);
        $this->write($force);
    }

    /**
     * End tracking: final state and summary message
     */
    public function end(string $state, ?string $message = null): void
    {
        if ($this->job === null) {
            return;
        }

        $this->data['state'] = $state;
        $this->data['finished_at'] = time();
        if ($message !== null) {
            $this->data['message'] = mb_substr($message, 0, 500);
        }
        $this->write(true);

        $this->job = null;
        $this->data = [];
    }

    /**
     * Whether someone asked the job running in this process to stop
     * (checked between items; reads the database at most every 2 s)
     */
    public function shouldStop(): bool
    {
        if ($this->job === null) {
            return false;
        }
        if ($this->stopRequested) {
            return true;
        }

        $now = microtime(true);
        if ($now - $this->lastStopCheck < self::STOP_CHECK_EVERY) {
            return false;
        }
        $this->lastStopCheck = $now;

        $this->stopRequested = $this->read(self::KEY_STOP . strtoupper($this->job)) !== '';

        return $this->stopRequested;
    }

    /**
     * Ask a running job (in any process) to stop after the current item
     */
    public function requestStop(string $job): void
    {
        $this->store(self::KEY_STOP . strtoupper($job), (string) time());
    }

    public function isStopRequested(string $job): bool
    {
        return $this->read(self::KEY_STOP . strtoupper($job)) !== '';
    }

    /**
     * Last known progress record of a job, null when it never ran with tracking.
     * A running record without a recent heartbeat is reported as "stale".
     *
     * @return array<string,mixed>|null
     */
    public function get(string $job): ?array
    {
        $raw = $this->read(self::KEY_PROGRESS . strtoupper($job));
        $data = $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($data)) {
            return null;
        }

        if (($data['state'] ?? null) === self::STATE_RUNNING) {
            $staleAfter = !empty($data['reports_progress']) ? self::STALE_AFTER : self::STALE_AFTER_NO_PROGRESS;
            if (time() - (int) ($data['updated_at'] ?? 0) > $staleAfter) {
                $data['state'] = self::STATE_STALE;
            }
        }

        return $data;
    }

    private function write(bool $force): void
    {
        $now = microtime(true);
        if (!$force && $now - $this->lastWrite < self::WRITE_EVERY) {
            return;
        }
        $this->lastWrite = $now;

        $this->data['updated_at'] = time();
        foreach (['step', 'last_error'] as $key) {
            if (is_string($this->data[$key] ?? null)) {
                $this->data[$key] = mb_substr($this->data[$key], 0, 500);
            }
        }

        $this->store(
            self::KEY_PROGRESS . strtoupper((string) $this->job),
            (string) json_encode($this->data, JSON_UNESCAPED_UNICODE)
        );
    }

    private function store(string $key, string $value): void
    {
        try {
            Configuration::updateGlobalValue($key, $value);
        } catch (\Throwable $e) {
            // Progress bookkeeping must never fail the job itself
        }
    }

    private function read(string $key): string
    {
        try {
            // getValue() appends LIMIT 1 itself
            return (string) Db::getInstance()->getValue(
                'SELECT value FROM ' . _DB_PREFIX_ . "configuration
                 WHERE name = '" . pSQL($key) . "' AND id_shop IS NULL AND id_shop_group IS NULL"
            );
        } catch (\Throwable $e) {
            return '';
        }
    }
}
