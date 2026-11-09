<?php
/**
 * RefreshOfferCommand
 *
 * Console command for refreshing a single offer's status from InPost API.
 * Takes InPost Offer UUID as argument and updates local database with current status.
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Command;

use Inpost\VonHalsky\Service\Offer\OfferService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Db;

class RefreshOfferCommand extends Command
{
    protected static $defaultName = 'inpost:offer:refresh';

    private const ARG_INPOST_OFFER_ID = 'inpost_offer_id';

    private OfferService $offerService;

    public function __construct(OfferService $offerService)
    {
        parent::__construct();
        $this->offerService = $offerService;
    }

    protected function configure(): void
    {
        $this
            ->setName('inpost:offer:refresh')
            ->setDescription('Refresh single offer data from InPost API')
            ->setHelp('Fetches current offer status from InPost API by UUID and updates local database.')
            ->addArgument(
                self::ARG_INPOST_OFFER_ID,
                InputArgument::REQUIRED,
                'InPost Offer ID (UUID)'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $inpostOfferId = (string) $input->getArgument(self::ARG_INPOST_OFFER_ID);

        $io->title('InPost Offer Refresh');
        $io->text("InPost Offer ID: <info>{$inpostOfferId}</info>");

        $offerId = (int) Db::getInstance()->getValue(
            'SELECT offer_id FROM ' . _DB_PREFIX_ . 'inpost_offer WHERE inpost_offer_id = \'' . pSQL($inpostOfferId) . '\''
        );
        if (!$offerId) {
            $io->error("Local offer not found for InPost ID: {$inpostOfferId}");
            return 1;
        }

        // Same refresh as the UI / cron: status + validation from the API
        $result = $this->offerService->refreshOfferStatus($offerId);
        if (empty($result['success'])) {
            $io->error('Refresh failed: ' . ($result['error'] ?? 'unknown error'));
            return 1;
        }

        $io->definitionList(
            ['Local offer ID' => $offerId],
            ['Status' => sprintf('%s → %s', $result['old_status'] ?? '-', $result['new_status'] ?? '-')],
            ['Validation errors' => $result['validation_error_count'] ?? 0]
        );
        $io->success('Offer refreshed successfully');

        return 0;
    }
}
