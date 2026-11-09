<?php
/**
 * OrderSyncCommand
 *
 * Console command for synchronizing orders from InPost API.
 * Fetches orders and creates corresponding PrestaShop orders.
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Command;

use Inpost\VonHalsky\Service\Cron\CronManager;
use Inpost\VonHalsky\Service\Sync\OrderSyncService;
use Inpost\VonHalsky\Service\Util\LoggerService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class OrderSyncCommand extends Command
{
    protected static $defaultName = 'inpost:orders:sync';

    /**
     * @var OrderSyncService
     */
    private $orderSyncService;

    /**
     * @var LoggerService
     */
    private $logger;

    private CronManager $cronManager;

    /**
     * @param OrderSyncService $orderSyncService
     * @param LoggerService $logger
     * @param CronManager $cronManager
     */
    public function __construct(
        OrderSyncService $orderSyncService,
        LoggerService $logger,
        CronManager $cronManager
    ) {
        parent::__construct();
        $this->orderSyncService = $orderSyncService;
        $this->logger = $logger;
        $this->cronManager = $cronManager;
    }

    /**
     * {@inheritdoc}
     */
    protected function configure(): void
    {
        $this
            ->setName('inpost:orders:sync')
            ->setDescription('Synchronize orders from InPost API')
            ->setHelp('This command fetches orders from InPost API and creates corresponding PrestaShop orders.')
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Not supported - the command only prints a warning and exits without syncing'
            )
            ->addOption(
                'limit',
                'l',
                InputOption::VALUE_REQUIRED,
                'Sync only the newest N orders (single page, the API caps it at 30); 0 = all pages',
                0
            );
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = $input->getOption('dry-run');
        $limit = (int) $input->getOption('limit');

        $io->title('InPost Order Synchronization');

        if ($dryRun) {
            // Order sync writes customers, addresses and orders - there is no safe simulation
            $io->warning('Dry-run is not supported for order sync - nothing was done.');
            return 0;
        }

        $this->logger->info('[InPost-OrderSyncCommand] Starting order synchronization', [
            'dry_run' => $dryRun,
            'limit' => $limit,
        ]);

        try {
            $startTime = microtime(true);

            // Same lock as the order_sync cron job - never two imports at once
            $result = $this->cronManager->runExclusive(
                'order_sync',
                fn () => $limit > 0
                    ? $this->orderSyncService->syncOrders(null, $limit, false)
                    : $this->orderSyncService->syncOrders()
            );
            if ($result === null) {
                $io->warning('The order import (order_sync) is already running - try again when it finishes.');

                return 0;
            }

            $duration = round(microtime(true) - $startTime, 2);

            $io->success('Order synchronization completed');

            $io->table(
                ['Metric', 'Value'],
                [
                    ['Orders fetched', $result['total_fetched'] ?? 0],
                    ['Orders created', $result['created'] ?? 0],
                    ['Orders updated', $result['updated'] ?? 0],
                    ['Errors', $result['errors'] ?? 0],
                    ['Duration', $duration . 's'],
                ]
            );

            if (!empty($result['fetch_error'])) {
                $io->error('Fetching orders from InPost failed: ' . $result['fetch_error']);
            }

            $this->logger->info('[InPost-OrderSyncCommand] Order synchronization completed', [
                'fetched' => $result['total_fetched'] ?? 0,
                'created' => $result['created'] ?? 0,
                'updated' => $result['updated'] ?? 0,
                'errors' => $result['errors'] ?? 0,
                'duration' => $duration,
            ]);

            return ($result['errors'] ?? 0) > 0 ? 1 : 0;

        } catch (\Exception $e) {
            $io->error('Order synchronization failed: ' . $e->getMessage());

            $this->logger->error('[InPost-OrderSyncCommand] Order synchronization failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return 1;
        }
    }
}
