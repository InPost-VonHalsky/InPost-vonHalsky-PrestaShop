<?php

declare(strict_types=1);

namespace Inpost\VonHalsky\Controller\Admin\Ajax;

use Inpost\VonHalsky\Repository\OfferAttachmentRepository;
use Inpost\VonHalsky\Service\Offer\OfferAttachmentException;
use Inpost\VonHalsky\Service\Offer\OfferAttachmentService;
use Inpost\VonHalsky\Service\Offer\OfferAttachmentValidator;
use Inpost\VonHalsky\Service\Util\InpostLoggerService;
use PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * "Attachments" tab of the offer form (GOKINT-1099).
 *
 * list:   the offer's attachments + the product's PrestaShop attachments that
 *         can still be added (GET offer_id)
 * upload: multipart offer_id, attachment_type, file
 * add_ps: offer_id, id_attachment, attachment_type
 * remove: offer_id, attachment_id
 * retry:  offer_id, attachment_id (only ERROR)
 *
 * The changing actions check the _csrf token. Every answer carries the fresh
 * list, so the tab simply re-renders.
 */
class OfferAttachmentController extends FrameworkBundleAdminController
{
    private const DOMAIN = 'Modules.Inpostvonhalsky.Admin';
    public const CSRF_ID = 'inpost_offer_attachments';

    /** Labels of the attachment types (translated in DOMAIN) */
    public const TYPE_LABELS = [
        'MANUAL' => 'User manual',
        'ASSEMBLY_INSTRUCTION' => 'Assembly instructions',
        'ENERGY_LABEL' => 'Energy label',
        'PRODUCT_LABEL' => 'Product label',
        'CERTIFICATE' => 'Certificate',
        'SPECIFICATION_SHEET' => 'Product specification sheet',
        'WARRANTY_CARD' => 'Warranty card',
        'SIZE_CHART' => 'Size chart',
        'GENERAL_DOCUMENT' => 'Document (e.g. safety information)',
        'OTHER' => 'Other',
    ];

    private OfferAttachmentService $attachments;
    private InpostLoggerService $logger;

    public function __construct(OfferAttachmentService $attachments, InpostLoggerService $logger)
    {
        $this->attachments = $attachments;
        $this->logger = $logger;
    }

    public function listAction(Request $request): JsonResponse
    {
        return $this->handle($request, false, function (int $offerId): ?string {
            return null;
        });
    }

    public function uploadAction(Request $request): JsonResponse
    {
        return $this->handle($request, true, function (int $offerId) use ($request): ?string {
            $file = $request->files->get('file');
            if (!$file instanceof UploadedFile) {
                // Also what PHP leaves when the request exceeded post_max_size
                throw new OfferAttachmentException('Choose a file (max %max% MB).', [
                    '%max%' => (int) (OfferAttachmentValidator::MAX_SIZE / 1048576),
                ]);
            }

            $attachment = $this->attachments->addUpload($offerId, $file, (string) $request->request->get('attachment_type'));

            return $this->addedMessage($attachment);
        });
    }

    public function addPsAction(Request $request): JsonResponse
    {
        return $this->handle($request, true, function (int $offerId) use ($request): ?string {
            $attachment = $this->attachments->addFromPsAttachment(
                $offerId,
                (int) $request->request->get('id_attachment'),
                (string) $request->request->get('attachment_type'),
                (int) $this->getContext()->language->id
            );

            return $this->addedMessage($attachment);
        });
    }

    public function removeAction(Request $request): JsonResponse
    {
        return $this->handle($request, true, function (int $offerId) use ($request): ?string {
            $gone = $this->attachments->remove($offerId, (int) $request->request->get('attachment_id'));

            return $gone
                ? $this->trans('Attachment removed.', self::DOMAIN)
                : $this->trans('The attachment could not be removed in InPost yet - it will be retried automatically.', self::DOMAIN);
        });
    }

    public function retryAction(Request $request): JsonResponse
    {
        return $this->handle($request, true, function (int $offerId) use ($request): ?string {
            $attachment = $this->attachments->retry($offerId, (int) $request->request->get('attachment_id'));

            return $this->addedMessage($attachment);
        });
    }

    /**
     * @param callable(int):?string $work Returns the success message
     */
    private function handle(Request $request, bool $changes, callable $work): JsonResponse
    {
        if ($changes && !$this->isCsrfTokenValid(self::CSRF_ID, (string) $request->request->get('_csrf'))) {
            return $this->json(['success' => false, 'error' => $this->trans('Invalid security token. Please try again.', self::DOMAIN)], 403);
        }

        $offerId = (int) ($changes ? $request->request->get('offer_id') : $request->query->get('offer_id'));
        if ($offerId <= 0) {
            return $this->json(['success' => false, 'error' => $this->trans('Offer not found.', self::DOMAIN)], 422);
        }

        try {
            $message = $work($offerId);
            $error = null;
        } catch (OfferAttachmentException $e) {
            $message = null;
            $error = $this->trans($e->getMessage(), self::DOMAIN, $e->getParams());
        } catch (\Throwable $e) {
            $this->logger->error('OfferAttachmentController error', ['offer_id' => $offerId, 'error' => $e->getMessage()]);

            return $this->json(['success' => false, 'error' => $e->getMessage()], 500);
        }

        try {
            $data = $this->attachments->listForOffer($offerId, (int) $this->getContext()->language->id);
        } catch (OfferAttachmentException $e) {
            return $this->json(['success' => false, 'error' => $this->trans($e->getMessage(), self::DOMAIN, $e->getParams())], 422);
        }

        $data['attachments'] = array_map([$this, 'translateRow'], $data['attachments']);

        return $this->json(
            ['success' => $error === null, 'message' => $message, 'error' => $error] + $data,
            $error === null ? 200 : 422
        );
    }

    /**
     * @param array<string,mixed> $attachment
     */
    private function addedMessage(array $attachment): string
    {
        switch ($attachment['status']) {
            case OfferAttachmentRepository::STATUS_ERROR:
                return $this->trans('File %name% added, but sending it to InPost failed.', self::DOMAIN, ['%name%' => $attachment['file_name']]);
            case OfferAttachmentRepository::STATUS_PENDING:
                return $this->trans('File %name% added. It will be sent to InPost once the offer is created there.', self::DOMAIN, ['%name%' => $attachment['file_name']]);
            default:
                return $this->trans('File %name% sent to InPost.', self::DOMAIN, ['%name%' => $attachment['file_name']]);
        }
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function translateRow(array $row): array
    {
        $row['type_label'] = isset(self::TYPE_LABELS[$row['type']])
            ? $this->trans(self::TYPE_LABELS[$row['type']], self::DOMAIN)
            : $row['type'];
        // Module messages are source strings; API messages come back as they are
        $row['error'] = $row['error'] !== null ? $this->trans($row['error'], self::DOMAIN) : null;

        return $row;
    }
}
