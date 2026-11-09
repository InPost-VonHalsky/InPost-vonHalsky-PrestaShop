<?php
/**
 * CronManager
 *
 * Manages registration and execution of cron jobs.
 * Provides centralized control for scheduled tasks.
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Service\Cron;

use Inpost\VonHalsky\Service\Util\LoggerService;
use Exception;

class CronManager
{
    /**
     * @var LoggerService
     */
    private $logger;

    /**
     * @var AbstractCronJob[]
     */
    private $jobs = [];

    /**
     * @var array<string, int> Last run timestamps keyed by job name
     */
    private $lastRunTimes = [];

    private JobProgress $progress;

    /**
     * @param LoggerService $logger
     * @param JobProgress $progress
     */
    public function __construct(LoggerService $logger, JobProgress $progress)
    {
        $this->logger = $logger;
        $this->progress = $progress;
    }

    /**
     * Register a cron job
     *
     * @param AbstractCronJob $job
     * @return void
     */
    public function registerJob(AbstractCronJob $job): void
    {
        $this->jobs[$job->getName()] = $job;
        $this->logger->debug('[InPost-Cron] Registered job: ' . $job->getName());
    }

    /**
     * Get all registered jobs
     *
     * @return AbstractCronJob[]
     */
    public function getJobs(): array
    {
        return $this->jobs;
    }

    /**
     * Get a specific job by name
     *
     * @param string $name
     * @return AbstractCronJob|null
     */
    public function getJob(string $name): ?AbstractCronJob
    {
        return $this->jobs[$name] ?? null;
    }

    /**
     * Run all registered jobs
     *
     * @param bool $force Force run even if interval not elapsed
     * @return array Results keyed by job name
     */
    public function runAll(bool $force = false): array
    {
        $results = [];

        foreach ($this->jobs as $name => $job) {
            try {
                $results[$name] = $this->runJob($name, $force);
            } catch (Exception $e) {
                $this->logger->error('[InPost-Cron] Job failed: ' . $name, [
                    'error' => $e->getMessage(),
                ]);
                $results[$name] = [
                    'success' => false,
                    'error' => $e->getMessage(),
                    'job' => $name,
                ];
            }
        }

        return $results;
    }

    /**
     * Run a job right now on explicit request (CLI): ignores the interval and the
     * enabled flag, but still takes the job lock so it never overlaps the cron run.
     *
     * @param AbstractCronJob $job Job, already configured (limit/interval)
     * @return array Same shape as runJob()
     */
    public function runJobNow(AbstractCronJob $job): array
    {
        $this->registerJob($job);

        $wasEnabled = $job->isEnabled();
        $job->setEnabled(true);
        try {
            return $this->runJob($job->getName(), true);
        } finally {
            $job->setEnabled($wasEnabled);
        }
    }

    /**
     * Run a specific job by name
     *
     * @param string $name Job name
     * @param bool $force Force run even if interval not elapsed
     * @return array Job result
     */
    public function runJob(string $name, bool $force = false): array
    {
        $job = $this->getJob($name);

        if ($job === null) {
            return [
                'success' => false,
                'error' => 'Job not found: ' . $name,
                'job' => $name,
            ];
        }

        if (!$job->isEnabled()) {
            $this->logger->debug('[InPost-Cron] Job disabled: ' . $name);
            return [
                'success' => true,
                'skipped' => true,
                'reason' => 'Job disabled',
                'job' => $name,
            ];
        }

        // Check if interval has elapsed
        if (!$force && !$this->shouldRun($job)) {
            $this->logger->debug('[InPost-Cron] Job interval not elapsed: ' . $name);
            return [
                'success' => true,
                'skipped' => true,
                'reason' => 'Interval not elapsed',
                'job' => $name,
            ];
        }

        // The system cron fires every minute but a job (e.g. category_sync, ~9 min)
        // can outlive that; its last-run time is only saved on completion, so without
        // a lock every tick would start another parallel copy of the same job.
        $lock = $this->acquireLock($name);
        if ($lock === null) {
            $this->logger->info('[InPost-Cron] Job already running, skipped: ' . $name);
            return [
                'success' => true,
                'skipped' => true,
                'reason' => 'Already running',
                'job' => $name,
            ];
        }

        $this->logger->info('[InPost-Cron] Starting job: ' . $name);
        $startTime = microtime(true);
        $this->progress->begin($name, 'cron');

        try {
            $result = $job->execute();
            $duration = round(microtime(true) - $startTime, 3);

            $this->lastRunTimes[$name] = time();
            $this->saveLastRunTime($name);
            $this->saveLastResult($name, [
                'success' => (bool) ($result['success'] ?? true),
                'duration' => $duration,
                'message' => $result['message'] ?? null,
                'error' => $result['error'] ?? null,
            ]);

            $this->logger->info('[InPost-Cron] Job completed: ' . $name, [
                'duration' => $duration . 's',
                'result' => $result,
            ]);

            $success = (bool) ($result['success'] ?? true);
            $this->progress->end(
                !empty($result['stopped']) ? JobProgress::STATE_STOPPED : ($success ? JobProgress::STATE_FINISHED : JobProgress::STATE_FAILED),
                (string) ($success ? ($result['message'] ?? '') : ($result['error'] ?? $result['message'] ?? ''))
            );

            return [
                'success' => $success,
                'error' => $success ? null : ($result['error'] ?? $result['message'] ?? 'Job reported failure'),
                'result' => $result,
                'job' => $name,
                'duration' => $duration,
            ];
        } catch (\Throwable $e) {
            // Throwable, not Exception: a TypeError in one job must not abort the
            // remaining jobs of this cron run
            $duration = round(microtime(true) - $startTime, 3);

            // Record the attempt so a failing job waits for its interval instead
            // of being retried on every cron tick
            $this->lastRunTimes[$name] = time();
            $this->saveLastRunTime($name);

            $this->saveLastResult($name, [
                'success' => false,
                'duration' => $duration,
                'message' => null,
                'error' => $e->getMessage(),
            ]);

            $this->logger->error('[InPost-Cron] Job failed: ' . $name, [
                'error' => $e->getMessage(),
                'duration' => $duration . 's',
            ]);
            $this->progress->end(JobProgress::STATE_FAILED, $e->getMessage());

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'job' => $name,
                'duration' => $duration,
            ];
        } finally {
            $this->releaseLock($lock);
        }
    }

    /**
     * Run work under another job's lock without tracking it as that job's run -
     * e.g. the order event job creating shop orders under the order_sync lock.
     *
     * @param string $name Job name whose lock is taken
     * @param callable(): mixed $work
     * @return array{0: bool, 1: mixed} [lock acquired, result of $work (null when not acquired)]
     */
    public function withLock(string $name, callable $work): array
    {
        $lock = $this->acquireLock($name);
        if ($lock === null) {
            return [false, null];
        }

        try {
            return [true, $work()];
        } finally {
            $this->releaseLock($lock);
        }
    }

    /**
     * Run work belonging to a job outside the cron (e.g. the manual order sync from
     * the admin panel) under the job's lock and with progress tracking, so it never
     * runs in parallel with the cron run of the same job.
     *
     * @param string $name Job name whose lock is taken
     * @param callable(): array $work Returns the result (stats); may contain 'stopped'
     * @return array|null Result of $work, or null when the job is already running
     */
    public function runExclusive(string $name, callable $work): ?array
    {
        $lock = $this->acquireLock($name);
        if ($lock === null) {
            return null;
        }

        $this->progress->begin($name, 'manual');
        try {
            $result = $work();
            $this->progress->end(
                !empty($result['stopped']) ? JobProgress::STATE_STOPPED : JobProgress::STATE_FINISHED,
                isset($result['message']) ? (string) $result['message'] : null
            );

            return $result;
        } catch (\Throwable $e) {
            $this->progress->end(JobProgress::STATE_FAILED, $e->getMessage());
            throw $e;
        } finally {
            $this->releaseLock($lock);
        }
    }

    /**
     * Take a non-blocking per-job file lock. The OS drops it automatically if the
     * process dies, so a crashed run never leaves the job locked.
     *
     * @param string $name
     * @return resource|null Lock handle, or null when another process holds it
     */
    private function acquireLock(string $name)
    {
        $file = $this->getLockFile($name);

        $handle = @fopen($file, 'c');
        if ($handle !== false) {
            // Cron as root and the web server as www-data must share the same lock
            // file - a root-owned 0644 file would leave every web run unlocked
            @chmod($file, 0666);
        }
        if ($handle === false) {
            // Can't create the lock file - don't block the job over it
            $this->logger->warning('[InPost-Cron] Cannot open lock file, running unlocked: ' . $file);
            return fopen('php://memory', 'r');
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return null;
        }

        return $handle;
    }

    /**
     * @param resource $handle
     */
    private function releaseLock($handle): void
    {
        if (is_resource($handle)) {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function getLockFile(string $name): string
    {
        return sys_get_temp_dir() . '/inpostvonhalsky_cron_'
            . md5(_PS_ROOT_DIR_) . '_' . preg_replace('/[^a-z0-9_]/i', '', $name) . '.lock';
    }

    /**
     * Whether a job is being executed right now by another process (its lock is held).
     *
     * @param string $name
     * @return bool
     */
    public function isJobRunning(string $name): bool
    {
        $file = $this->getLockFile($name);
        if (!is_file($file)) {
            return false;
        }

        $handle = @fopen($file, 'r');
        if ($handle === false) {
            return false;
        }

        $free = flock($handle, LOCK_EX | LOCK_NB);
        if ($free) {
            flock($handle, LOCK_UN);
        }
        fclose($handle);

        return !$free;
    }

    /**
     * Last run timestamp of a job (successful or not), or null if it never ran.
     *
     * @param string $name
     * @return int|null
     */
    public function getLastRunTimestamp(string $name): ?int
    {
        return $this->getLastRunTime($name);
    }

    /**
     * Outcome of the job's last run: success, duration, message, error, finished_at.
     *
     * @param string $name
     * @return array<string,mixed>|null
     */
    public function getLastResult(string $name): ?array
    {
        $raw = \Configuration::get('INPOSTSA_CRON_LASTRESULT_' . strtoupper($name));
        if (!$raw) {
            return null;
        }

        $data = json_decode((string) $raw, true);

        return is_array($data) ? $data : null;
    }

    /**
     * @param string $name
     * @param array<string,mixed> $result
     */
    private function saveLastResult(string $name, array $result): void
    {
        $result['finished_at'] = time();
        foreach (['message', 'error'] as $key) {
            if (is_string($result[$key] ?? null)) {
                $result[$key] = mb_substr($result[$key], 0, 500);
            }
        }

        try {
            \Configuration::updateValue(
                'INPOSTSA_CRON_LASTRESULT_' . strtoupper($name),
                (string) json_encode($result, JSON_UNESCAPED_UNICODE)
            );
        } catch (\Throwable $e) {
            // Status bookkeeping must never fail the job itself
        }
    }

    /**
     * Check if a job should run based on its interval
     *
     * @param AbstractCronJob $job
     * @return bool
     */
    private function shouldRun(AbstractCronJob $job): bool
    {
        $interval = $job->getInterval();

        if ($interval <= 0) {
            return true; // No interval restriction
        }

        $lastRun = $this->getLastRunTime($job->getName());

        if ($lastRun === null) {
            return true; // Never run before
        }

        return (time() - $lastRun) >= $interval;
    }

    /**
     * Get the last run time for a job
     *
     * @param string $name
     * @return int|null Unix timestamp or null if never run
     */
    private function getLastRunTime(string $name): ?int
    {
        if (isset($this->lastRunTimes[$name])) {
            return $this->lastRunTimes[$name];
        }

        // Load from configuration
        $configKey = 'INPOSTSA_CRON_LASTRUN_' . strtoupper($name);
        $value = \Configuration::get($configKey);

        if ($value !== false && $value !== null && $value !== '') {
            $this->lastRunTimes[$name] = (int)$value;
            return (int)$value;
        }

        return null;
    }

    /**
     * Save the last run time for a job
     *
     * @param string $name
     * @return void
     */
    private function saveLastRunTime(string $name): void
    {
        $configKey = 'INPOSTSA_CRON_LASTRUN_' . strtoupper($name);
        \Configuration::updateValue($configKey, (string)time());
    }

    /**
     * Get status of all jobs
     *
     * @return array
     */
    public function getStatus(): array
    {
        $status = [];

        foreach ($this->jobs as $name => $job) {
            $lastRun = $this->getLastRunTime($name);
            $nextRun = null;

            if ($lastRun !== null && $job->getInterval() > 0) {
                $nextRun = $lastRun + $job->getInterval();
            }

            $status[$name] = [
                'name' => $name,
                'enabled' => $job->isEnabled(),
                'interval' => $job->getInterval(),
                'last_run' => $lastRun,
                'last_run_formatted' => $lastRun ? date('Y-m-d H:i:s', $lastRun) : null,
                'next_run' => $nextRun,
                'next_run_formatted' => $nextRun ? date('Y-m-d H:i:s', $nextRun) : null,
                'should_run' => $job->isEnabled() && $this->shouldRun($job),
            ];
        }

        return $status;
    }

}
