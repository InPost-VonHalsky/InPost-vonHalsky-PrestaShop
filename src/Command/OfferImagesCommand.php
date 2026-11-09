<?php
/**
 * OfferImagesCommand
 *
 * Console command for managing offer images in InPost von Halsky.
 * Supports listing and deleting images for offers.
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Command;

use Inpost\VonHalsky\Repository\OfferRepository;
use Inpost\VonHalsky\Service\Api\OfferApiService;
use Inpost\VonHalsky\Service\Offer\ImageAttachmentService;
use Inpost\VonHalsky\Service\Util\LoggerService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class OfferImagesCommand extends Command
{
    protected static $defaultName = 'inpost:offer:images';

    private OfferApiService $offerApiService;
    private ImageAttachmentService $imageAttachmentService;
    private OfferRepository $offerRepository;
    private LoggerService $logger;

    public function __construct(
        OfferApiService $offerApiService,
        ImageAttachmentService $imageAttachmentService,
        OfferRepository $offerRepository,
        LoggerService $logger
    ) {
        parent::__construct();
        $this->offerApiService = $offerApiService;
        $this->imageAttachmentService = $imageAttachmentService;
        $this->offerRepository = $offerRepository;
        $this->logger = $logger;
    }

    protected function configure(): void
    {
        $this
            ->setName('inpost:offer:images')
            ->setDescription('Manage offer images in InPost von Halsky')
            ->setHelp('List or delete images for an offer. Uses offer_id from ps_inpost_offer table.')
            ->addArgument(
                'offer_id',
                InputArgument::REQUIRED,
                'Offer ID (local offer_id from ps_inpost_offer)'
            )
            ->addOption(
                'delete',
                'd',
                InputOption::VALUE_NONE,
                'Delete all images from the offer'
            )
            ->addOption(
                'list',
                'l',
                InputOption::VALUE_NONE,
                'List all images for the offer (default action)'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $offerId = (int) $input->getArgument('offer_id');
        $delete = $input->getOption('delete');

        $io->title('InPost Offer Images Management');

        // Get the offer to find InPost offer ID
        $offer = $this->offerRepository->find($offerId);

        if (!$offer) {
            $io->error(sprintf('Offer with ID %d not found', $offerId));
            return 2;
        }

        $inpostOfferId = $offer->getInpostOfferId();

        if (!$inpostOfferId) {
            $io->error(sprintf('Offer %d has no InPost offer ID - not yet synced to InPost', $offerId));
            return 2;
        }

        $io->text([
            sprintf('Offer ID: <info>%d</info>', $offerId),
            sprintf('InPost Offer ID: <info>%s</info>', $inpostOfferId),
        ]);

        try {
            if ($delete) {
                return $this->deleteImages($io, $inpostOfferId);
            }

            // Default: list
            return $this->listImages($io, $inpostOfferId);
        } catch (\Exception $e) {
            $io->error(sprintf('Command failed: %s', $e->getMessage()));

            $this->logger->error('Offer images command failed', [
                'offer_id' => $offerId,
                'inpost_offer_id' => $inpostOfferId,
                'error' => $e->getMessage(),
            ]);

            return 1;
        }
    }

    private function listImages(SymfonyStyle $io, string $inpostOfferId): int
    {
        $io->section('Listing Images');

        $response = $this->offerApiService->listOfferAttachments($inpostOfferId);

        if (!$response['success']) {
            $io->error('Failed to list attachments: ' . ($response['error'] ?? 'Unknown error'));
            return 1;
        }

        $attachments = $response['data'];

        // SDK returns Attachments object with getData() method containing AttachmentInfo[]
        if (!$attachments || !method_exists($attachments, 'getData')) {
            $io->success('No images found');
            return 0;
        }

        $data = $attachments->getData();

        if (empty($data)) {
            $io->success('No images found');
            return 0;
        }

        $rows = [];
        foreach ($data as $attachment) {
            $rows[] = [
                $attachment->getId(),
                $attachment->getAttachmentType() ?? 'N/A',
                $attachment->getName() ?? 'N/A',
                $attachment->getUrl() ?? 'N/A',
            ];
        }

        $io->table(
            ['Attachment ID', 'Type', 'Name', 'URL'],
            $rows
        );

        $io->success(sprintf('Found %d image(s)', count($data)));

        return 0;
    }

    private function deleteImages(SymfonyStyle $io, string $inpostOfferId): int
    {
        $io->section('Deleting All Images');

        $result = $this->imageAttachmentService->deleteAllOfferImages(
            $inpostOfferId,
            function (string $attachmentId, bool $success, string $message) use ($io) {
                if ($success) {
                    $io->text(sprintf('  <info>✓</info> Deleted: %s', $attachmentId));
                } else {
                    $io->text(sprintf('  <error>✗</error> Failed: %s - %s', $attachmentId, $message));
                }
            }
        );

        $io->newLine();

        if ($result['total'] === 0) {
            $io->success('No images to delete');
            return 0;
        }

        $io->table(
            ['Metric', 'Count'],
            [
                ['Total images', $result['total']],
                ['Deleted', $result['deleted']],
                ['Failed', $result['failed']],
            ]
        );

        if (!empty($result['errors'])) {
            $io->section('Errors:');
            foreach ($result['errors'] as $error) {
                $io->text(sprintf('<error>%s</error>', $error));
            }
        }

        if ($result['failed'] > 0) {
            $io->warning(sprintf('Deleted %d of %d images', $result['deleted'], $result['total']));
            return 1;
        }

        $io->success(sprintf('Successfully deleted all %d image(s)', $result['deleted']));
        return 0;
    }

}
