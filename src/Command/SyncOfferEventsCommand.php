<?php
/**
 * SyncOfferEventsCommand
 *
 * Console command for synchronizing offer events from InPost von Halsky.
 * Runs the offer_event_sync cron job on demand (cursor-based, all events of the
 * selected organization; offers with new events are refreshed from the API).
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Command;

use Inpost\VonHalsky\Service\Cron\CronJobConfigurator;
use Inpost\VonHalsky\Service\Cron\CronManager;
use Inpost\VonHalsky\Service\Cron\OfferEventSyncJob;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class SyncOfferEventsCommand extends Command
{
    protected static $defaultName = 'inpost:offer-events:sync';

    private OfferEventSyncJob $offerEventSyncJob;
    private CronManager $cronManager;
    private CronJobConfigurator $jobConfigurator;

    public function __construct(
        OfferEventSyncJob $offerEventSyncJob,
        CronManager $cronManager,
        CronJobConfigurator $jobConfigurator
    ) {
        parent::__construct();
        $this->offerEventSyncJob = $offerEventSyncJob;
        $this->cronManager = $cronManager;
        $this->jobConfigurator = $jobConfigurator;
    }

    protected function configure(): void
    {
        $this
            ->setName('inpost:offer-events:sync')
            ->setDescription('Synchronize offer events from InPost API')
            ->setHelp(
                'Runs the offer_event_sync cron job now (same as inpost:cron:run --job=offer_event_sync --force, '
                . 'but also when the job is disabled in the module configuration).'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('InPost Offer Events Synchronization');

        $this->jobConfigurator->configure($this->offerEventSyncJob);
        $run = $this->cronManager->runJobNow($this->offerEventSyncJob);

        if (!empty($run['skipped'])) {
            $io->warning('Skipped: ' . ($run['reason'] ?? 'unknown reason') . ' (the cron job is running right now).');
            return 0;
        }

        $result = $run['result'] ?? [];
        $io->table(
            ['Metric', 'Count'],
            [
                ['Organizations', $result['organizations'] ?? 0],
                ['Events fetched', $result['events_fetched'] ?? 0],
                ['Events saved', $result['events_saved'] ?? 0],
                ['Errors', $result['errors'] ?? 0],
            ]
        );

        if (!$run['success']) {
            $io->error('Synchronization failed: ' . ($run['error'] ?? 'unknown error'));
            return 1;
        }

        $io->success($result['message'] ?? 'Events synchronized successfully');
        return 0;
    }
}
