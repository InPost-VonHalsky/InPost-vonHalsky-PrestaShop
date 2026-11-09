<?php

declare(strict_types=1);

namespace Inpost\VonHalsky\Service\Offer;

use Inpost\VonHalsky\Service\Api\OfferApiService;
use Inpost\VonHalsky\Service\Util\InpostLoggerService;
use InpostApi\Model\AttachmentType;

/**
 * Diagnostic service for legacy image attachments on InPost offers.
 *
 * Image upload during sync now goes through the offer payload (images[] with
 * fileName/fileUrl/priority) in OfferBuilderService. This class only handles
 * cleanup of attachments uploaded by previous module versions via the
 * /attachments?attachmentType=IMAGE endpoint, called from the
 * `inpost:offer:images <id> --delete` CLI.
 */
class ImageAttachmentService
{
    private OfferApiService $offerApiService;
    private InpostLoggerService $logger;

    public function __construct(
        OfferApiService $offerApiService,
        InpostLoggerService $logger
    ) {
        $this->offerApiService = $offerApiService;
        $this->logger = $logger;
    }

    /**
     * Delete all image attachments from an offer.
     *
     * @param string $offerId InPost offer ID (UUID)
     * @param callable|null $onProgress Optional callback (attachmentId, success, message)
     * @return array ['total' => int, 'deleted' => int, 'failed' => int, 'errors' => array]
     */
    public function deleteAllOfferImages(string $offerId, ?callable $onProgress = null): array
    {
        $result = [
            'total' => 0,
            'deleted' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        $listResponse = $this->offerApiService->listAllOfferAttachments($offerId);

        if (!$listResponse['success']) {
            $result['errors'][] = 'Failed to list attachments: ' . ($listResponse['error'] ?? 'Unknown error');
            return $result;
        }

        // Images only: documents (manuals, energy labels...) live in the same list
        $content = array_values(array_filter($listResponse['data'], static function ($attachment): bool {
            return (string) $attachment->getAttachmentType() === AttachmentType::IMAGE;
        }));
        if (empty($content)) {
            return $result;
        }

        $result['total'] = count($content);

        foreach ($content as $attachment) {
            $attachmentId = $attachment->getId();

            $deleteResponse = $this->offerApiService->deleteOfferAttachment($offerId, $attachmentId);

            if ($deleteResponse['success']) {
                $result['deleted']++;
                if ($onProgress) {
                    $onProgress($attachmentId, true, 'Deleted');
                }
            } else {
                $result['failed']++;
                $error = $deleteResponse['error'] ?? 'Unknown error';
                $result['errors'][] = "Attachment {$attachmentId}: {$error}";
                if ($onProgress) {
                    $onProgress($attachmentId, false, $error);
                }
            }
        }

        $this->logger->info('Deleted offer images', [
            'offer_id' => $offerId,
            'total' => $result['total'],
            'deleted' => $result['deleted'],
            'failed' => $result['failed'],
        ]);

        return $result;
    }
}
