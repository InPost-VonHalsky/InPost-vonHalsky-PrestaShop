<?php
/**
 * OfferAttachmentService
 *
 * Document attachments of offers (GOKINT-1099): manuals, energy labels, safety
 * information... The merchant uploads a file or picks one of the product's
 * PrestaShop attachments; it is queued locally and sent to
 * POST /offers/{id}/attachments as soon as the offer exists in InPost.
 *
 * How the InPost API behaves (stage, 2026-10-28):
 *   - the upload answers 202 with an async command; /offers/commands/{id}
 *     resolves it (PENDING -> SUCCESS/FAILURE, ~20 s) but carries no attachment
 *     ID, so the attachment is found in the offer's list by name + type;
 *   - a FAILURE command (e.g. image too small) still leaves the attachment in
 *     the list -> it is deleted again;
 *   - a new upload under a name the offer already has replaces that attachment
 *     -> names are unique per offer;
 *   - DELETE is synchronous (204, also for an unknown ID).
 *
 * Lifecycle of a row: PENDING -> UPLOADING -> UPLOADED, or ERROR (rejected /
 * out of retries, the merchant may retry). Removing an uploaded attachment
 * deletes it in InPost; if that fails the row stays DELETING and the cron
 * retries.
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Service\Offer;

use Db;
use Inpost\VonHalsky\Entity\Offer;
use Inpost\VonHalsky\Repository\OfferAttachmentRepository as Repo;
use Inpost\VonHalsky\Service\Api\OfferApiService;
use Inpost\VonHalsky\Service\Util\LoggerService;
use InpostApi\Model\CommandStatus;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class OfferAttachmentService
{
    /** Transport failures (5xx, timeouts) before a row goes to ERROR */
    private const MAX_RETRIES = 3;

    /** Polls of a successful command that did not show the attachment in the list yet */
    private const MAX_MATCH_ATTEMPTS = 5;

    /** Stored files without a row are removed after this many seconds */
    private const ORPHAN_FILE_AGE = 3600;

    private Repo $repository;
    private OfferApiService $offerApi;
    private OfferAttachmentValidator $validator;
    private OfferAttachmentStorage $storage;
    private LoggerService $logger;

    public function __construct(
        Repo $repository,
        OfferApiService $offerApi,
        OfferAttachmentValidator $validator,
        OfferAttachmentStorage $storage,
        LoggerService $logger
    ) {
        $this->repository = $repository;
        $this->offerApi = $offerApi;
        $this->validator = $validator;
        $this->storage = $storage;
        $this->logger = $logger;
    }

    // ------------------------------------------------------------------
    // Merchant actions
    // ------------------------------------------------------------------

    /**
     * Attachments of an offer and the product's PrestaShop attachments that can
     * still be added. Moves the offer's queue forward first, so the tab shows
     * fresh statuses without waiting for the cron.
     *
     * @return array{attachments: array<int,array<string,mixed>>, ps_attachments: array<int,array<string,mixed>>, offer_ready: bool}
     */
    public function listForOffer(int $offerId, int $idLang): array
    {
        $offer = $this->loadOffer($offerId);
        $this->processOffer($offerId);

        $rows = $this->repository->findByOffer($offerId);
        $added = array_filter(array_map('intval', array_column($rows, 'id_ps_attachment')));

        $psAttachments = [];
        foreach ($this->psAttachments((int) $offer['prestashop_offer_id'], $idLang) as $attachment) {
            if (!in_array((int) $attachment['id_attachment'], $added, true)) {
                $psAttachments[] = [
                    'id' => (int) $attachment['id_attachment'],
                    'name' => (string) ($attachment['name'] ?: $attachment['file_name']),
                    'file_name' => (string) $attachment['file_name'],
                    'size' => (int) $attachment['file_size'],
                ];
            }
        }

        return [
            'attachments' => array_map([$this, 'present'], $rows),
            'ps_attachments' => $psAttachments,
            'offer_ready' => $this->isOfferReady($offer),
        ];
    }

    /**
     * @return array<string,mixed> The new attachment (see present())
     * @throws OfferAttachmentException
     */
    public function addUpload(int $offerId, UploadedFile $file, string $type): array
    {
        $this->loadOffer($offerId);
        $this->assertValid($this->validator->validateType($type));

        if (!$file->isValid()) {
            $tooLarge = in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
            throw new OfferAttachmentException(
                $tooLarge ? 'File %name% is too large for this server (limit: %max%).' : 'The file could not be uploaded: %error%',
                [
                    '%name%' => $file->getClientOriginalName(),
                    '%max%' => (string) ini_get('upload_max_filesize'),
                    '%error%' => $file->getErrorMessage(),
                ]
            );
        }

        $path = $file->getPathname();
        $this->assertValid($this->validator->validateFile($path, $file->getClientOriginalName()));

        $mime = $this->validator->detectMime($path);
        $fileName = $this->validator->normalizeFileName($file->getClientOriginalName(), $mime);
        $this->assertNameFree($offerId, $fileName);
        $size = (int) filesize($path);

        $localFile = $this->storage->store($file, OfferAttachmentValidator::ALLOWED_MIME[$mime]);

        $attachmentId = $this->repository->create([
            'offer_id' => $offerId,
            'attachment_type' => $type,
            'source' => Repo::SOURCE_UPLOAD,
            'local_file' => $localFile,
            'file_name' => $fileName,
            'mime' => $mime,
            'file_size' => $size,
        ]);

        return $this->afterAdd($offerId, $attachmentId);
    }

    /**
     * @return array<string,mixed> The new attachment (see present())
     * @throws OfferAttachmentException
     */
    public function addFromPsAttachment(int $offerId, int $idAttachment, string $type, int $idLang): array
    {
        $offer = $this->loadOffer($offerId);
        $this->assertValid($this->validator->validateType($type));

        $attachment = null;
        foreach ($this->psAttachments((int) $offer['prestashop_offer_id'], $idLang) as $row) {
            if ((int) $row['id_attachment'] === $idAttachment) {
                $attachment = $row;
                break;
            }
        }
        if ($attachment === null) {
            throw new OfferAttachmentException('This file is not attached to the product in PrestaShop.');
        }

        $path = _PS_DOWNLOAD_DIR_ . basename((string) $attachment['file']);
        $displayName = (string) $attachment['file_name'];
        $this->assertValid($this->validator->validateFile($path, $displayName));

        $mime = $this->validator->detectMime($path);
        $fileName = $this->validator->normalizeFileName($displayName, $mime);
        $this->assertNameFree($offerId, $fileName);

        $attachmentId = $this->repository->create([
            'offer_id' => $offerId,
            'attachment_type' => $type,
            'source' => Repo::SOURCE_PS_ATTACHMENT,
            'id_ps_attachment' => $idAttachment,
            'file_name' => $fileName,
            'mime' => $mime,
            'file_size' => (int) filesize($path),
        ]);

        return $this->afterAdd($offerId, $attachmentId);
    }

    /**
     * Remove an attachment, in InPost too
     *
     * @return bool true = gone; false = the delete in InPost failed, retried by the cron
     * @throws OfferAttachmentException
     */
    public function remove(int $offerId, int $attachmentId): bool
    {
        $row = $this->loadRow($offerId, $attachmentId);

        return $this->removeRow($row);
    }

    /**
     * Send a rejected attachment again
     *
     * @return array<string,mixed> The attachment (see present())
     * @throws OfferAttachmentException
     */
    public function retry(int $offerId, int $attachmentId): array
    {
        $row = $this->loadRow($offerId, $attachmentId);
        if ($row['status'] !== Repo::STATUS_ERROR) {
            throw new OfferAttachmentException('Only attachments with an error can be sent again.');
        }

        $this->repository->update($attachmentId, [
            'status' => Repo::STATUS_PENDING,
            'command_id' => null,
            'inpost_attachment_id' => null,
            'retry_count' => 0,
            'error' => null,
        ]);

        return $this->afterAdd($offerId, $attachmentId);
    }

    // ------------------------------------------------------------------
    // Queue
    // ------------------------------------------------------------------

    /**
     * Move one offer's attachments forward: send the queued ones, check the
     * sent ones. Used by the tab and right after the offer's create command
     * succeeded. Never throws.
     */
    public function processOffer(int $offerId): void
    {
        try {
            foreach ($this->repository->findByStatuses([Repo::STATUS_PENDING], 50, $offerId, true) as $row) {
                $this->push($row);
            }
            foreach ($this->repository->findByStatuses([Repo::STATUS_UPLOADING], 50, $offerId) as $row) {
                $this->resolve($row);
            }
        } catch (\Throwable $e) {
            $this->logger->error('[InPost-Attachments] Processing offer attachments failed', [
                'offer_id' => $offerId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Cron run over all offers
     *
     * @return array<string,int> Counters
     */
    public function processQueue(int $limit): array
    {
        $counters = ['requeued' => 0, 'sent' => 0, 'uploaded' => 0, 'failed' => 0, 'deleted' => 0, 'waiting' => 0];

        $counters['requeued'] = $this->repository->requeueForChangedOffers();

        foreach ($this->repository->findByStatuses([Repo::STATUS_PENDING], $limit, null, true) as $row) {
            $counters[$this->push($row)]++;
        }
        foreach ($this->repository->findByStatuses([Repo::STATUS_UPLOADING], $limit) as $row) {
            $counters[$this->resolve($row)]++;
        }
        foreach ($this->repository->findByStatuses([Repo::STATUS_DELETING], $limit) as $row) {
            $counters[$this->removeRow($row) ? 'deleted' : 'waiting']++;
        }

        $this->removeOrphanFiles();

        return $counters;
    }

    /**
     * Send one queued attachment
     *
     * @param array<string,mixed> $row Repository row with the offer_* columns
     * @return string Counter: sent | failed | waiting
     */
    private function push(array $row): string
    {
        $attachmentId = (int) $row['attachment_id'];
        $inpostOfferId = (string) $row['offer_inpost_id'];

        $path = $this->filePath($row);
        if ($path === null || !is_readable($path)) {
            $this->repository->update($attachmentId, [
                'status' => Repo::STATUS_ERROR,
                'error' => 'The file no longer exists in the shop.',
            ]);

            return 'failed';
        }

        $response = $this->offerApi->uploadOfferAttachment(
            $inpostOfferId,
            $path,
            (string) $row['file_name'],
            (string) $row['mime'],
            (string) $row['attachment_type']
        );

        if ($response['success'] ?? false) {
            $this->repository->update($attachmentId, [
                'status' => Repo::STATUS_UPLOADING,
                'inpost_offer_id' => $inpostOfferId,
                'command_id' => $response['data']['commandId'] ?? null,
                'inpost_attachment_id' => null,
                'retry_count' => 0,
                'error' => null,
            ]);

            return 'sent';
        }

        $statusCode = (int) ($response['status_code'] ?? 0);
        $error = (string) ($response['error'] ?? 'Unknown error');
        // 4xx = the file itself is refused (type, size...); sending it again won't help
        $rejected = $statusCode >= 400 && $statusCode < 500 && !in_array($statusCode, [401, 403, 408, 429], true);
        $retry = (int) $row['retry_count'] + 1;

        $this->logger->warning('[InPost-Attachments] Upload failed', [
            'attachment_id' => $attachmentId,
            'offer_id' => (int) $row['offer_id'],
            'status_code' => $statusCode,
            'error' => $error,
            'retry_count' => $retry,
        ]);

        $giveUp = $rejected || $retry >= self::MAX_RETRIES;
        $this->repository->update($attachmentId, [
            'status' => $giveUp ? Repo::STATUS_ERROR : Repo::STATUS_PENDING,
            'retry_count' => $retry,
            'error' => $error,
        ]);

        return $giveUp ? 'failed' : 'waiting';
    }

    /**
     * Check the upload command of a sent attachment
     *
     * @param array<string,mixed> $row Repository row
     * @return string Counter: uploaded | failed | waiting
     */
    private function resolve(array $row): string
    {
        $attachmentId = (int) $row['attachment_id'];
        $commandId = (string) $row['command_id'];

        if ($commandId === '') {
            // Sent, but the API returned no command - look for the file directly
            return $this->finishUpload($row) ? 'uploaded' : 'waiting';
        }

        $response = $this->offerApi->getOfferCommandStatus($commandId);
        if (!($response['success'] ?? false)) {
            return 'waiting';
        }

        switch ($this->commandStatus($response)) {
            case CommandStatus::SUCCESS:
                return $this->finishUpload($row) ? 'uploaded' : 'waiting';

            case CommandStatus::FAILURE:
                $error = (string) ($response['command_error_text'] ?? '') ?: 'InPost rejected the file.';
                $this->logger->warning('[InPost-Attachments] Upload rejected by InPost', [
                    'attachment_id' => $attachmentId,
                    'offer_id' => (int) $row['offer_id'],
                    'command_id' => $commandId,
                    'error' => $error,
                ]);

                // The rejected file still shows up in the offer's attachments
                $orphanId = $this->findInpostAttachment($row);
                if ($orphanId !== null) {
                    $this->offerApi->deleteOfferAttachment((string) $row['inpost_offer_id'], $orphanId);
                }

                $this->repository->update($attachmentId, [
                    'status' => Repo::STATUS_ERROR,
                    'inpost_attachment_id' => null,
                    'error' => $error,
                ]);

                return 'failed';

            default:
                return 'waiting';
        }
    }

    /**
     * Command succeeded: find the attachment ID in the offer's list
     *
     * @param array<string,mixed> $row Repository row
     * @return bool false = not listed yet, checked again next time
     */
    private function finishUpload(array $row): bool
    {
        $attachmentId = (int) $row['attachment_id'];
        $inpostId = $this->findInpostAttachment($row);
        $attempt = (int) $row['retry_count'] + 1;

        if ($inpostId === null && $attempt < self::MAX_MATCH_ATTEMPTS) {
            $this->repository->update($attachmentId, ['retry_count' => $attempt]);

            return false;
        }

        // Out of attempts: uploaded all the same; removing it later looks it up by name again
        $this->repository->update($attachmentId, [
            'status' => Repo::STATUS_UPLOADED,
            'inpost_attachment_id' => $inpostId,
            'retry_count' => 0,
            'error' => null,
        ]);

        return true;
    }

    /**
     * @param array<string,mixed> $row Repository row
     * @return bool true = gone; false = kept as DELETING for the cron
     */
    private function removeRow(array $row): bool
    {
        $attachmentId = (int) $row['attachment_id'];

        $error = $this->deleteInInpost($row);
        if ($error !== null) {
            $this->repository->update($attachmentId, [
                'status' => Repo::STATUS_DELETING,
                'error' => $error,
            ]);

            return false;
        }

        $this->repository->delete($attachmentId);
        $this->storage->delete($row['local_file'] ?? null);

        return true;
    }

    /**
     * @param array<string,mixed> $row Repository row
     * @return string|null Error, null = nothing left in InPost
     */
    private function deleteInInpost(array $row): ?string
    {
        $inpostOfferId = (string) ($row['inpost_offer_id'] ?? '');
        if ($inpostOfferId === '') {
            return null;
        }

        $inpostId = $row['inpost_attachment_id'] ?: null;
        if ($inpostId === null) {
            $commandId = (string) ($row['command_id'] ?? '');
            if ($commandId !== '') {
                $response = $this->offerApi->getOfferCommandStatus($commandId);
                if (!($response['success'] ?? false) || $this->commandStatus($response) === CommandStatus::PENDING) {
                    return 'Waiting for InPost to finish the upload before removing the file.';
                }
            }
            $inpostId = $this->findInpostAttachment($row);
        }
        if ($inpostId === null) {
            return null;
        }

        $response = $this->offerApi->deleteOfferAttachment($inpostOfferId, $inpostId);

        return ($response['success'] ?? false) || (int) ($response['status_code'] ?? 0) === 404
            ? null
            : (string) ($response['error'] ?? 'Unknown error');
    }

    /**
     * InPost attachment of a row: same name and type, not taken by another row,
     * the newest one
     *
     * @param array<string,mixed> $row Repository row
     */
    private function findInpostAttachment(array $row): ?string
    {
        $inpostOfferId = (string) ($row['inpost_offer_id'] ?? '');
        if ($inpostOfferId === '') {
            return null;
        }

        $response = $this->offerApi->listAllOfferAttachments($inpostOfferId);
        if (!($response['success'] ?? false)) {
            return null;
        }

        $taken = $this->repository->knownInpostIds((int) $row['offer_id'], (int) $row['attachment_id']);
        $name = mb_strtolower((string) $row['file_name']);
        $best = null;
        $bestTime = null;

        foreach ($response['data'] as $attachment) {
            if ((string) $attachment->getAttachmentType() !== (string) $row['attachment_type']
                || mb_strtolower((string) $attachment->getName()) !== $name
                || in_array($attachment->getId(), $taken, true)
            ) {
                continue;
            }

            $time = $attachment->getCreatedAt() ? $attachment->getCreatedAt()->getTimestamp() : 0;
            if ($best === null || $time >= $bestTime) {
                $best = (string) $attachment->getId();
                $bestTime = $time;
            }
        }

        return $best;
    }

    /**
     * Stored files whose row is gone (offer deleted, failed request...)
     */
    private function removeOrphanFiles(): void
    {
        $dir = OfferAttachmentStorage::directory();
        if (!is_dir($dir)) {
            return;
        }

        $known = array_flip(array_column(
            Db::getInstance()->executeS(
                'SELECT local_file FROM `' . _DB_PREFIX_ . 'inpost_offer_attachment` WHERE local_file IS NOT NULL'
            ) ?: [],
            'local_file'
        ));

        foreach (scandir($dir) ?: [] as $entry) {
            $path = $dir . $entry;
            if ($entry[0] === '.' || $entry === 'index.php' || isset($known[$entry]) || !is_file($path)) {
                continue;
            }
            if (time() - (int) filemtime($path) > self::ORPHAN_FILE_AGE) {
                @unlink($path);
            }
        }
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * @return array<string,mixed>
     */
    private function afterAdd(int $offerId, int $attachmentId): array
    {
        $this->processOffer($offerId);

        $row = $this->repository->find($attachmentId);
        if ($row === null) {
            throw new OfferAttachmentException('Attachment not found.');
        }

        return $this->present($row);
    }

    /**
     * Offer exists in InPost and its create command went through
     *
     * @param array<string,mixed> $offer inpost_offer row
     */
    private function isOfferReady(array $offer): bool
    {
        return !empty($offer['inpost_offer_id'])
            && (empty($offer['inpost_command_id']) || $offer['command_status'] === CommandStatus::SUCCESS)
            && $offer['status'] !== Offer::STATUS_ERROR_NOT_CREATED;
    }

    /**
     * @return array<string,mixed>
     * @throws OfferAttachmentException
     */
    private function loadOffer(int $offerId): array
    {
        $offer = Db::getInstance()->getRow(
            'SELECT offer_id, prestashop_offer_id, inpost_offer_id, inpost_command_id, command_status, status
            FROM `' . _DB_PREFIX_ . 'inpost_offer` WHERE offer_id = ' . $offerId
        );
        if (!$offer) {
            throw new OfferAttachmentException('Offer not found.');
        }

        return $offer;
    }

    /**
     * @return array<string,mixed>
     * @throws OfferAttachmentException
     */
    private function loadRow(int $offerId, int $attachmentId): array
    {
        $row = $this->repository->find($attachmentId);
        if ($row === null || (int) $row['offer_id'] !== $offerId) {
            throw new OfferAttachmentException('Attachment not found.');
        }

        return $row;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function psAttachments(int $idProduct, int $idLang): array
    {
        if ($idProduct <= 0) {
            return [];
        }

        return \Product::getAttachmentsStatic($idLang, $idProduct) ?: [];
    }

    /**
     * @param array<string,mixed> $row Repository row
     */
    private function filePath(array $row): ?string
    {
        if ($row['source'] === Repo::SOURCE_UPLOAD) {
            return $row['local_file'] ? $this->storage->path((string) $row['local_file']) : null;
        }

        $file = Db::getInstance()->getValue(
            'SELECT file FROM `' . _DB_PREFIX_ . 'attachment` WHERE id_attachment = ' . (int) $row['id_ps_attachment']
        );

        return $file ? _PS_DOWNLOAD_DIR_ . basename((string) $file) : null;
    }

    /**
     * @param array<string,mixed> $response getOfferCommandStatus() result
     */
    private function commandStatus(array $response): string
    {
        $data = $response['data'] ?? null;

        return (is_object($data) && method_exists($data, 'getStatus'))
            ? (string) $data->getStatus()
            : (string) ($data['status'] ?? '');
    }

    /**
     * @param array{0: string, 1: array<string,string|int>}|null $violation
     * @throws OfferAttachmentException
     */
    private function assertValid(?array $violation): void
    {
        if ($violation !== null) {
            throw new OfferAttachmentException($violation[0], $violation[1]);
        }
    }

    /**
     * @throws OfferAttachmentException
     */
    private function assertNameFree(int $offerId, string $fileName): void
    {
        if ($this->repository->fileNameExists($offerId, $fileName)) {
            throw new OfferAttachmentException(
                'The offer already has an attachment named %name%. InPost would replace it - rename the file or remove the other one first.',
                ['%name%' => $fileName]
            );
        }
    }

    /**
     * Row as shown in the attachments tab
     *
     * @param array<string,mixed> $row Repository row
     * @return array<string,mixed>
     */
    private function present(array $row): array
    {
        return [
            'id' => (int) $row['attachment_id'],
            'file_name' => (string) $row['file_name'],
            'type' => (string) $row['attachment_type'],
            'source' => (string) $row['source'],
            'status' => (string) $row['status'],
            'error' => $row['error'] !== null ? (string) $row['error'] : null,
            'size' => (int) $row['file_size'],
            'created_at' => (string) $row['created_at'],
        ];
    }
}
