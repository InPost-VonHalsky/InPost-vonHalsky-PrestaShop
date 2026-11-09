<?php
/**
 * JobMonitor
 *
 * Live view of the background jobs for the "Background jobs" panel: what runs
 * right now (progress, elapsed time, ETA, counters, last error), how the last run
 * ended and when the next one is due. Cheap on purpose - a few configuration
 * rows, no table scans - so it can be polled every few seconds also when the
 * shop is under load.
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Service\Status;

use Inpost\VonHalsky\Service\Cron\AbstractCronJob;
use Inpost\VonHalsky\Service\Cron\CronJobConfigurator;
use Inpost\VonHalsky\Service\Cron\CronManager;
use Inpost\VonHalsky\Service\Cron\JobProgress;
use Symfony\Contracts\Translation\TranslatorInterface;

class JobMonitor
{
    /**
     * Human names of the jobs (translated in Modules.Inpostvonhalsky.Admin)
     */
    public const JOB_LABELS = [
        'order_sync' => 'Order import',
        'order_event_sync' => 'Order events',
        'offer_sync' => 'Offer sending',
        'offer_event_sync' => 'Offer events',
        'pending_command_sync' => 'Offer creation confirmations',
        'offer_attachment_sync' => 'Offer attachments',
        'category_sync' => 'Categories and attributes',
    ];

    /**
     * Jobs that check the stop request between items
     */
    private const STOPPABLE_JOBS = ['order_sync', 'order_event_sync'];

    private const DOMAIN = 'Modules.Inpostvonhalsky.Admin';

    private CronManager $cronManager;
    private CronJobConfigurator $jobConfigurator;
    private JobProgress $progress;
    private TranslatorInterface $translator;

    /** @var AbstractCronJob[] */
    private array $jobs;

    /**
     * @param AbstractCronJob[] $jobs
     */
    public function __construct(
        CronManager $cronManager,
        CronJobConfigurator $jobConfigurator,
        JobProgress $progress,
        TranslatorInterface $translator,
        array $jobs
    ) {
        $this->cronManager = $cronManager;
        $this->jobConfigurator = $jobConfigurator;
        $this->progress = $progress;
        $this->translator = $translator;
        $this->jobs = $jobs;
    }

    public function isKnownJob(string $name): bool
    {
        foreach ($this->jobs as $job) {
            if ($job->getName() === $name) {
                return true;
            }
        }

        return false;
    }

    public function isStoppable(string $name): bool
    {
        return in_array($name, self::STOPPABLE_JOBS, true);
    }

    public function requestStop(string $name): void
    {
        $this->progress->requestStop($name);
    }

    /**
     * @return array{now: int, jobs: array<int, array<string, mixed>>}
     */
    public function snapshot(): array
    {
        $now = time();
        $jobs = [];

        foreach ($this->jobs as $job) {
            $this->jobConfigurator->configure($job);
            $name = $job->getName();
            $interval = $job->getInterval();

            $progress = $this->progress->get($name);
            $state = $progress['state'] ?? null;
            // The file lock only sees processes on this host; the progress record
            // (heartbeat in the database) also sees a cron running in another container
            $running = $state === JobProgress::STATE_RUNNING || $this->cronManager->isJobRunning($name);

            $lastRun = $this->cronManager->getLastRunTimestamp($name);
            $lastResult = $this->cronManager->getLastResult($name);

            $done = (int) ($progress['done'] ?? 0);
            $total = isset($progress['total']) ? (int) $progress['total'] : null;
            $startedAt = isset($progress['started_at']) ? (int) $progress['started_at'] : null;
            $elapsed = $startedAt !== null
                ? (($running ? $now : (int) ($progress['finished_at'] ?? $progress['updated_at'] ?? $now)) - $startedAt)
                : null;

            $percent = null;
            $eta = null;
            if ($total !== null && $total > 0) {
                $percent = min(100, (int) floor($done * 100 / $total));
                if ($running && $done > 0 && $elapsed !== null) {
                    $eta = (int) round($elapsed / $done * max(0, $total - $done));
                }
            }

            $jobs[] = [
                'name' => $name,
                'label' => $this->label($name),
                'enabled' => $job->isEnabled(),
                'interval' => $interval,
                'state' => $running ? JobProgress::STATE_RUNNING : ($state ?? 'idle'),
                'running' => $running,
                'stoppable' => $running && $this->isStoppable($name),
                'stop_requested' => $running && $this->progress->isStopRequested($name),
                'trigger' => $progress['trigger'] ?? null,
                'host' => $progress['host'] ?? null,
                'pid' => $progress['pid'] ?? null,
                'started_at' => $startedAt,
                'heartbeat_at' => isset($progress['updated_at']) ? (int) $progress['updated_at'] : null,
                'finished_at' => isset($progress['finished_at']) ? (int) $progress['finished_at'] : null,
                'elapsed' => $elapsed,
                'eta' => $eta,
                'done' => $done,
                'total' => $total,
                'percent' => $percent,
                'reports_progress' => !empty($progress['reports_progress']),
                'created' => (int) ($progress['created'] ?? 0),
                'updated' => (int) ($progress['updated'] ?? 0),
                'errors' => (int) ($progress['errors'] ?? 0),
                'step' => $progress['step'] ?? null,
                'message' => $progress['message'] ?? null,
                'last_error' => $progress['last_error'] ?? null,
                'last_run' => $lastRun,
                'next_run' => $job->isEnabled() && $lastRun !== null && $interval > 0 ? $lastRun + $interval : null,
                'last_result' => $lastResult === null ? null : [
                    'success' => !empty($lastResult['success']),
                    'duration' => isset($lastResult['duration']) ? (float) $lastResult['duration'] : null,
                    'text' => (string) (empty($lastResult['success'])
                        ? ($lastResult['error'] ?? $lastResult['message'] ?? '')
                        : ($lastResult['message'] ?? '')),
                    'finished_at' => isset($lastResult['finished_at']) ? (int) $lastResult['finished_at'] : null,
                ],
            ];
        }

        // Running jobs first, then the order of registration
        usort($jobs, fn ($a, $b) => (int) $b['running'] <=> (int) $a['running']);

        return ['now' => $now, 'jobs' => $jobs];
    }

    private function label(string $name): string
    {
        return isset(self::JOB_LABELS[$name])
            ? $this->translator->trans(self::JOB_LABELS[$name], [], self::DOMAIN)
            : $name;
    }
}
