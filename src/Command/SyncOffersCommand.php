<?php
/**
 * SyncOffersCommand
 *
 * Console command for refreshing the status of submitted offers from InPost API.
 * Offers are processed least-recently-synced first, so repeated runs with a
 * limit cycle through all of them.
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Command;

use Inpost\VonHalsky\Helper\Config;
use Inpost\VonHalsky\Service\Offer\OfferService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Db;

class SyncOffersCommand extends Command
{
    protected static $defaultName = 'inpost:offer:sync';

    private OfferService $offerService;
    private Config $config;

    public function __construct(OfferService $offerService, Config $config)
    {
        parent::__construct();
        $this->offerService = $offerService;
        $this->config = $config;
    }

    protected function configure(): void
    {
        $this
            ->setName('inpost:offer:sync')
            ->setDescription('Synchronize offer status from InPost API')
            ->setHelp('Fetches current offer status from InPost and updates local database.')
            ->addOption(
                'limit',
                'l',
                InputOption::VALUE_OPTIONAL,
                'Maximum number of offers to sync',
                100
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limit = (int) $input->getOption('limit');

        $io->title('InPost Offers Synchronization');
        $io->text(sprintf('Limit: <info>%d</info>', $limit));

        $offerIds = $this->getOfferIdsToSync($this->config->getDefaultShopId(), $limit);
        if (empty($offerIds)) {
            $io->success('No offers to synchronize');
            return 0;
        }

        $stats = ['updated' => 0, 'unchanged' => 0, 'errors' => 0];
        $statusChanges = [];

        $progressBar = $io->createProgressBar(count($offerIds));
        $progressBar->start();

        foreach ($offerIds as $offerId) {
            $result = $this->offerService->refreshOfferStatus($offerId);

            if (empty($result['success'])) {
                $stats['errors']++;
            } elseif (!empty($result['status_changed'])) {
                $stats['updated']++;
                $key = sprintf('%s → %s', $result['old_status'] ?? '-', $result['new_status'] ?? '-');
                $statusChanges[$key] = ($statusChanges[$key] ?? 0) + 1;
            } else {
                $stats['unchanged']++;
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $io->newLine(2);

        $io->section('Synchronization Summary:');
        $io->table(
            ['Metric', 'Count'],
            [
                ['Total offers processed', count($offerIds)],
                ['Status changed', $stats['updated']],
                ['Unchanged', $stats['unchanged']],
                ['Errors', $stats['errors']],
            ]
        );

        if (!empty($statusChanges)) {
            $io->section('Status Changes:');
            foreach ($statusChanges as $change => $count) {
                $io->text(sprintf('  %s: <info>%d</info>', $change, $count));
            }
        }

        return $stats['errors'] > 0 ? 1 : 0;
    }

    /**
     * Submitted offers of the shop, least recently synced first
     *
     * @return int[]
     */
    private function getOfferIdsToSync(int $shopId, int $limit): array
    {
        $rows = Db::getInstance()->executeS(
            'SELECT offer_id FROM ' . _DB_PREFIX_ . 'inpost_offer
            WHERE inpost_offer_id IS NOT NULL AND inpost_offer_id != ""
            AND shop_id = ' . (int) $shopId . '
            ORDER BY synced_at ASC
            LIMIT ' . (int) $limit
        );

        return array_map('intval', array_column($rows ?: [], 'offer_id'));
    }
}
