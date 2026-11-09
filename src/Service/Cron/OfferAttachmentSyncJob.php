<?php
/**
 * OfferAttachmentSyncJob
 *
 * Moves the document attachment queue forward (GOKINT-1099): sends attachments
 * of offers that now exist in InPost, checks the upload commands, retries
 * failed deletes and removes stored files nothing refers to.
 *
 * Kept apart from PendingCommandSyncJob: an offer has one create command but
 * any number of attachment commands, tracked on inpost_offer_attachment.
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Service\Cron;

use Inpost\VonHalsky\Service\Offer\OfferAttachmentService;
use Inpost\VonHalsky\Service\Util\LoggerService;

class OfferAttachmentSyncJob extends AbstractCronJob
{
    private const JOB_NAME = 'offer_attachment_sync';

    /**
     * Default interval: 3 minutes, like the offer create commands
     */
    private const DEFAULT_INTERVAL = 180;

    private OfferAttachmentService $attachmentService;
    private LoggerService $logger;

    public function __construct(OfferAttachmentService $attachmentService, LoggerService $logger)
    {
        $this->attachmentService = $attachmentService;
        $this->logger = $logger;
        $this->interval = self::DEFAULT_INTERVAL;
        $this->limit = 50;
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return self::JOB_NAME;
    }

    /**
     * {@inheritdoc}
     */
    public function execute(): array
    {
        $counters = $this->attachmentService->processQueue($this->getLimit());

        $message = sprintf(
            'Attachments: %d sent, %d uploaded, %d failed, %d deleted, %d waiting, %d queued again',
            $counters['sent'],
            $counters['uploaded'],
            $counters['failed'],
            $counters['deleted'],
            $counters['waiting'],
            $counters['requeued']
        );

        if (array_sum($counters) > 0) {
            $this->logger->info('[InPost-OfferAttachmentSyncJob] ' . $message, $counters);
        }

        return [
            'success' => true,
            'message' => $message,
            'counters' => $counters,
        ];
    }
}
