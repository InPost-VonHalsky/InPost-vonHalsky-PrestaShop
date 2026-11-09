<?php
/**
 * OfferAttachmentsCommand
 *
 * Document attachments of an offer (GOKINT-1099) from the console: the local
 * queue next to what InPost has, moving the queue forward, adding a file and
 * removing an attachment - the same service the offer form uses.
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Command;

use Inpost\VonHalsky\Repository\OfferAttachmentRepository;
use Inpost\VonHalsky\Repository\OfferRepository;
use Inpost\VonHalsky\Service\Api\OfferApiService;
use Inpost\VonHalsky\Service\Offer\OfferAttachmentException;
use Inpost\VonHalsky\Service\Offer\OfferAttachmentService;
use Inpost\VonHalsky\Service\Offer\OfferAttachmentValidator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class OfferAttachmentsCommand extends Command
{
    protected static $defaultName = 'inpost:offer:attachments';

    private OfferApiService $offerApiService;
    private OfferAttachmentService $attachmentService;
    private OfferAttachmentRepository $attachmentRepository;
    private OfferRepository $offerRepository;

    public function __construct(
        OfferApiService $offerApiService,
        OfferAttachmentService $attachmentService,
        OfferAttachmentRepository $attachmentRepository,
        OfferRepository $offerRepository
    ) {
        parent::__construct();
        $this->offerApiService = $offerApiService;
        $this->attachmentService = $attachmentService;
        $this->attachmentRepository = $attachmentRepository;
        $this->offerRepository = $offerRepository;
    }

    protected function configure(): void
    {
        $this
            ->setName('inpost:offer:attachments')
            ->setDescription('Document attachments of an offer (manuals, energy labels...)')
            ->setHelp(
                "Shows the module's attachments of the offer and the attachments InPost has.\n"
                . "  --sync              send queued attachments, check the sent ones\n"
                . "  --add=FILE --type=T add a file (copied, the original stays), T e.g. MANUAL, ENERGY_LABEL\n"
                . "  --remove=ID         remove an attachment (local ID), in InPost too"
            )
            ->addArgument('offer_id', InputArgument::REQUIRED, 'Offer ID (local offer_id from ps_inpost_offer)')
            ->addOption('sync', null, InputOption::VALUE_NONE, 'Move the queue of this offer forward')
            ->addOption('add', null, InputOption::VALUE_REQUIRED, 'Path of a file to add')
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'Attachment type: ' . implode(', ', OfferAttachmentValidator::TYPES))
            ->addOption('remove', null, InputOption::VALUE_REQUIRED, 'Local attachment ID to remove');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $offerId = (int) $input->getArgument('offer_id');

        $offer = $this->offerRepository->find($offerId);
        if (!$offer) {
            $io->error(sprintf('Offer with ID %d not found', $offerId));

            return 2;
        }

        try {
            if ($input->getOption('add')) {
                $this->add($io, $offerId, (string) $input->getOption('add'), (string) $input->getOption('type'));
            }
            if ($input->getOption('remove')) {
                $gone = $this->attachmentService->remove($offerId, (int) $input->getOption('remove'));
                $gone
                    ? $io->success('Attachment removed')
                    : $io->warning('Removal in InPost failed - the cron will retry (status DELETING)');
            }
            if ($input->getOption('sync')) {
                $this->attachmentService->processOffer($offerId);
            }
        } catch (OfferAttachmentException $e) {
            $io->error(strtr($e->getMessage(), array_map('strval', $e->getParams())));

            return 1;
        }

        $this->showLocal($io, $offerId);
        if ($offer->getInpostOfferId()) {
            $this->showRemote($io, (string) $offer->getInpostOfferId());
        } else {
            $io->note('The offer is not in InPost yet - attachments wait in the queue.');
        }

        return 0;
    }

    private function add(SymfonyStyle $io, int $offerId, string $path, string $type): void
    {
        if (!is_file($path)) {
            throw new OfferAttachmentException('File %name% cannot be read.', ['%name%' => $path]);
        }

        // UploadedFile::move() moves the file - work on a copy
        $copy = tempnam(sys_get_temp_dir(), 'inpost_att_');
        copy($path, $copy);
        $file = new UploadedFile($copy, basename($path), null, null, true);

        $attachment = $this->attachmentService->addUpload($offerId, $file, $type);
        $io->success(sprintf('Added #%d %s (%s)', $attachment['id'], $attachment['file_name'], $attachment['status']));
    }

    private function showLocal(SymfonyStyle $io, int $offerId): void
    {
        $io->section('Module');
        $rows = [];
        foreach ($this->attachmentRepository->findByOffer($offerId) as $row) {
            $rows[] = [
                $row['attachment_id'],
                $row['attachment_type'],
                $row['file_name'],
                $row['source'],
                $row['status'],
                $row['inpost_attachment_id'] ?? '-',
                $row['error'] ? mb_strimwidth((string) $row['error'], 0, 60, '...') : '',
            ];
        }
        $rows
            ? $io->table(['ID', 'Type', 'Name', 'Source', 'Status', 'InPost ID', 'Error'], $rows)
            : $io->text('No attachments');
    }

    private function showRemote(SymfonyStyle $io, string $inpostOfferId): void
    {
        $io->section('InPost (' . $inpostOfferId . ')');
        $response = $this->offerApiService->listAllOfferAttachments($inpostOfferId);
        if (!$response['success']) {
            $io->error('Failed to list attachments: ' . $response['error']);

            return;
        }

        $rows = [];
        foreach ($response['data'] as $attachment) {
            $rows[] = [
                $attachment->getId(),
                $attachment->getAttachmentType(),
                $attachment->getName(),
                $attachment->getPriority(),
                $attachment->getCreatedAt() ? $attachment->getCreatedAt()->format('Y-m-d H:i:s') : '',
            ];
        }
        $rows
            ? $io->table(['Attachment ID', 'Type', 'Name', 'Priority', 'Created'], $rows)
            : $io->text('No attachments');
    }
}
