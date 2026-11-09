<?php
/**
 * SendOfferCommand
 *
 * Console command for sending offers to InPost von Halsky.
 * Supports single offer, multiple offers, or all pending offers.
 * Uses local offer_id from ps_inpost_offer table.
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Command;

use Inpost\VonHalsky\Service\Offer\OfferService;
use Inpost\VonHalsky\Service\Util\LoggerService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class SendOfferCommand extends Command
{
    protected static $defaultName = 'inpost:offer:send';

    private OfferService $offerService;
    private LoggerService $logger;

    public function __construct(
        OfferService $offerService,
        LoggerService $logger
    ) {
        parent::__construct();
        $this->offerService = $offerService;
        $this->logger = $logger;
    }

    protected function configure(): void
    {
        $this
            ->setName('inpost:offer:send')
            ->setDescription('Send offers to InPost von Halsky')
            ->setHelp('Send one or more offers to InPost. Uses offer_id from ps_inpost_offer table.')
            ->addArgument(
                'offer_id',
                InputArgument::OPTIONAL,
                'Offer ID to send (local offer_id from ps_inpost_offer)'
            )
            ->addOption(
                'all',
                'a',
                InputOption::VALUE_NONE,
                'Send all pending offers (require_api_sync = 1)'
            )
            ->addOption(
                'force',
                'f',
                InputOption::VALUE_NONE,
                'Force re-sync even if already synced'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $offerId = $input->getArgument('offer_id');
        $all = $input->getOption('all');
        $force = $input->getOption('force');

        $io->title('InPost Offer Sending');

        // Validation: must specify either offer_id or --all
        if (!$offerId && !$all) {
            $io->error('You must specify either an offer ID or use the --all option');
            return 2;
        }

        if ($offerId && $all) {
            $io->error('Cannot use both offer ID and --all option together');
            return 2;
        }

        try {
            // Single offer mode
            if ($offerId) {
                return $this->sendSingleOffer($io, (int) $offerId, $force);
            }

            // All offers mode
            if ($all) {
                return $this->sendAllOffers($io, $force);
            }

            return 2;
        } catch (\Exception $e) {
            $io->error(sprintf('Command failed: %s', $e->getMessage()));

            $this->logger->error('Send offer command failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return 1;
        }
    }

    /**
     * Send single offer
     */
    private function sendSingleOffer(SymfonyStyle $io, int $offerId, bool $force): int
    {
        $io->section(sprintf('Sending Offer ID: %d', $offerId));
        $io->text(sprintf('Force: <info>%s</info>', $force ? 'Yes' : 'No'));

        $result = $this->offerService->sendOffer($offerId, $force);

        if ($result['success']) {
            $action = $result['data']['action'] ?? 'synced';
            $inpostOfferId = $result['data']['inpost_offer_id'] ?? 'N/A';

            $io->success(sprintf(
                'Offer %s successfully. InPost Offer ID: %s',
                $action,
                $inpostOfferId
            ));

            return 0;
        } else {
            $io->error($result['error']);

            if (!empty($result['details'])) {
                $io->section('Error Details:');
                foreach ($result['details'] as $key => $detail) {
                    if (is_array($detail)) {
                        $io->text(sprintf('<error>%s:</error>', $key));
                        foreach ($detail as $item) {
                            $io->text('  - ' . $item);
                        }
                    } else {
                        $io->text(sprintf('<error>%s:</error> %s', $key, $detail));
                    }
                }
            }

            return 1;
        }
    }

    /**
     * Send all pending offers
     */
    private function sendAllOffers(SymfonyStyle $io, bool $force): int
    {
        $io->section('Sending All Pending Offers');
        $io->text(sprintf('Force: <info>%s</info>', $force ? 'Yes' : 'No'));

        // Use syncAllOffers with require_api_sync filter (unless force)
        $filters = $force ? [] : ['require_api_sync' => true];
        $result = $this->offerService->sendAllOffers($filters, $force);

        if (!$result['success']) {
            $io->warning($result['message'] ?? 'No offers to sync');
            return 0;
        }

        // Display results
        $io->section('Synchronization Results:');

        $summary = $result['summary'] ?? [];
        $io->table(
            ['Metric', 'Count'],
            [
                ['Total offers', $summary['total'] ?? 0],
                ['Created', $summary['created'] ?? 0],
                ['Updated', $summary['updated'] ?? 0],
                ['Errors', $summary['errors'] ?? 0],
            ]
        );

        // Show errors if any
        $errors = $result['results']['errors'] ?? [];
        if (!empty($errors)) {
            $io->section('Errors:');
            foreach ($errors as $errOfferId => $error) {
                $io->text(sprintf('Offer %d: <error>%s</error>', $errOfferId, $error));
            }
        }

        $this->logger->info('Batch offer sync completed', $summary);

        return ($summary['errors'] ?? 0) > 0 ? 1 : 0;
    }
}
