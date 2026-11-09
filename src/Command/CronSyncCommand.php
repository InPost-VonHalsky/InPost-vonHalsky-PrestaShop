<?php
/**
 * CronSyncCommand
 *
 * Console command for running all cron jobs.
 * Can be used from system cron or manually.
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Command;

use Inpost\VonHalsky\Service\Cron\CronManager;
use Inpost\VonHalsky\Service\Cron\AbstractCronJob;
use Inpost\VonHalsky\Service\Cron\CronJobConfigurator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class CronSyncCommand extends Command
{
    protected static $defaultName = 'inpost:cron:run';

    /**
     * @var CronManager
     */
    private $cronManager;


    /**
     * @var AbstractCronJob[]
     */
    private array $jobs;

    /**
     * @var CronJobConfigurator
     */
    private $jobConfigurator;

    /**
     * @param CronManager $cronManager
     * @param CronJobConfigurator $jobConfigurator
     * @param iterable<AbstractCronJob> $jobs
     */
    public function __construct(
        CronManager $cronManager,
        CronJobConfigurator $jobConfigurator,
        iterable $jobs = []
    ) {
        parent::__construct();
        $this->cronManager = $cronManager;
        $this->jobConfigurator = $jobConfigurator;
        $this->jobs = is_array($jobs) ? $jobs : iterator_to_array($jobs);
    }

    /**
     * {@inheritdoc}
     */
    protected function configure(): void
    {
        $this
            ->setName('inpost:cron:run')
            ->setDescription('Run all registered cron jobs')
            ->setHelp('This command runs all registered InPost cron jobs. Jobs will only run if their interval has elapsed unless --force is used.')
            ->addOption(
                'force',
                'f',
                InputOption::VALUE_NONE,
                'Force run all jobs regardless of interval'
            )
            ->addOption(
                'job',
                'j',
                InputOption::VALUE_REQUIRED,
                'Run only a specific job by name'
            )
            ->addOption(
                'status',
                's',
                InputOption::VALUE_NONE,
                'Show job status instead of running'
            );
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $force = $input->getOption('force');
        $jobName = $input->getOption('job');
        $showStatus = $input->getOption('status');

        $io->title('InPost Cron Manager [' . date('Y-m-d H:i:s') . ']');

        // Register jobs with configuration applied (intervals, limits, enabled flags)
        $this->registerConfiguredJobs();

        // Show status if requested
        if ($showStatus) {
            return $this->showStatus($io);
        }

        // Run specific job if requested
        if ($jobName !== null) {
            return $this->runSingleJob($io, $jobName, $force);
        }

        // Run all jobs
        return $this->runAllJobs($io, $force);
    }

    /**
     * Show status of all jobs
     *
     * @param SymfonyStyle $io
     * @return int
     */
    private function showStatus(SymfonyStyle $io): int
    {
        $status = $this->cronManager->getStatus();

        if (empty($status)) {
            $io->warning('No jobs registered');
            return 0;
        }

        $rows = [];
        foreach ($status as $job) {
            $rows[] = [
                $job['name'],
                $job['enabled'] ? 'Yes' : 'No',
                $job['interval'] > 0 ? $job['interval'] . 's' : 'None',
                $job['last_run_formatted'] ?? 'Never',
                $job['next_run_formatted'] ?? '-',
                $job['should_run'] ? 'Yes' : 'No',
            ];
        }

        $io->table(
            ['Job', 'Enabled', 'Interval', 'Last Run', 'Next Run', 'Should Run'],
            $rows
        );

        return 0;
    }

    /**
     * Run a single job
     *
     * @param SymfonyStyle $io
     * @param string $jobName
     * @param bool $force
     * @return int
     */
    private function runSingleJob(SymfonyStyle $io, string $jobName, bool $force): int
    {
        $io->section('Running job: ' . $jobName);

        $result = $this->cronManager->runJob($jobName, $force);

        if (!empty($result['skipped'])) {
            $io->note('Job skipped: ' . ($result['reason'] ?? 'Unknown reason'));
            return 0;
        }

        if ($result['success']) {
            $io->success('Job completed successfully');
            if (isset($result['duration'])) {
                $io->writeln('Duration: ' . $result['duration'] . 's');
            }
            if (isset($result['result']) && is_array($result['result'])) {
                $io->listing(array_map(function ($key, $value) {
                    return $key . ': ' . (is_scalar($value) ? $value : json_encode($value));
                }, array_keys($result['result']), $result['result']));
            }
            return 0;
        }

        $io->error('Job failed: ' . ($result['error'] ?? 'Unknown error'));
        return 1;
    }

    /**
     * Run all jobs
     *
     * @param SymfonyStyle $io
     * @param bool $force
     * @return int
     */
    private function runAllJobs(SymfonyStyle $io, bool $force): int
    {
        $jobs = $this->cronManager->getJobs();

        if (empty($jobs)) {
            $io->warning('No jobs registered');
            return 0;
        }

        $io->section('Running ' . count($jobs) . ' job(s)');

        if ($force) {
            $io->note('Force mode enabled - ignoring intervals');
        }

        $results = $this->cronManager->runAll($force);

        $success = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($results as $name => $result) {
            if (!empty($result['skipped'])) {
                $skipped++;
                $io->writeln(sprintf(' [SKIP] %s - %s', $name, $result['reason'] ?? 'Unknown reason'));
            } elseif ($result['success']) {
                $success++;
                $duration = isset($result['duration']) ? ' (' . $result['duration'] . 's)' : '';
                $io->writeln(sprintf(' [OK]   %s%s', $name, $duration));
            } else {
                $failed++;
                $io->writeln(sprintf(' [FAIL] %s - %s', $name, $result['error'] ?? 'Unknown error'));
            }
        }

        $io->newLine();
        $io->writeln(sprintf('Summary: %d success, %d skipped, %d failed', $success, $skipped, $failed));

        return $failed > 0 ? 1 : 0;
    }

    /**
     * Register provided cron jobs and apply configuration (enabled, interval, limit).
     */
    private function registerConfiguredJobs(): void
    {
        foreach ($this->jobs as $job) {
            // Unknown jobs keep their defaults
            $this->jobConfigurator->configure($job);
            $this->cronManager->registerJob($job);
        }
    }
}
