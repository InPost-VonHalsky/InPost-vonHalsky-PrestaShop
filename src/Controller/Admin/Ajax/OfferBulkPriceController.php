<?php

declare(strict_types=1);

namespace Inpost\VonHalsky\Controller\Admin\Ajax;

use Inpost\VonHalsky\Helper\RequestIds;
use Inpost\VonHalsky\Service\Offer\BulkPriceEditService;
use Inpost\VonHalsky\Service\Util\InpostLoggerService;
use PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * "Set price" dialog of the offers list (GOKINT-1098).
 *
 * preview: the price each offer would get
 * apply:   saves it; the offers are sent by the offer_sync cron
 *
 * Both take POST offer_ids[], operation (manual / percent / auto), amount,
 * percent and rounding.
 */
class OfferBulkPriceController extends FrameworkBundleAdminController
{
    private const DOMAIN = 'Modules.Inpostvonhalsky.Admin';
    public const CSRF_ID = 'inpost_offers_bulk_price';

    private BulkPriceEditService $bulkPrice;
    private InpostLoggerService $logger;

    public function __construct(BulkPriceEditService $bulkPrice, InpostLoggerService $logger)
    {
        $this->bulkPrice = $bulkPrice;
        $this->logger = $logger;
    }

    public function previewAction(Request $request): JsonResponse
    {
        return $this->handle($request, function (array $offerIds) use ($request): array {
            $rows = $this->bulkPrice->preview($offerIds, $this->params($request));
            foreach ($rows as &$row) {
                if (!empty($row['reason'])) {
                    $row['reason'] = $this->trans((string) $row['reason'], self::DOMAIN);
                }
            }

            return ['rows' => $rows];
        });
    }

    public function applyAction(Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid(self::CSRF_ID, (string) $request->request->get('_csrf'))) {
            return $this->json(['success' => false, 'error' => $this->trans('Invalid security token. Please try again.', self::DOMAIN)], 403);
        }

        return $this->handle($request, function (array $offerIds) use ($request): array {
            $result = $this->bulkPrice->apply($offerIds, $this->params($request));

            if ($result['changed'] > 0) {
                $this->addFlash('success', $this->trans('Price changed in %count% offer(s).', self::DOMAIN, ['%count%' => $result['changed']]));
            } else {
                $this->addFlash('info', $this->trans('No offer needed a change.', self::DOMAIN));
            }
            if ($result['to_send'] > 0) {
                $this->addFlash('info', $this->trans('%count% offer(s) will be sent to InPost within a few minutes.', self::DOMAIN, ['%count%' => $result['to_send']]));
            }
            if ($result['skipped'] > 0) {
                $this->addFlash('warning', $this->trans('%count% offer(s) were skipped - see the preview for the reasons.', self::DOMAIN, ['%count%' => $result['skipped']]));
            }

            return $result;
        });
    }

    /**
     * @param callable(int[]):array<string,mixed> $work
     */
    private function handle(Request $request, callable $work): JsonResponse
    {
        $offerIds = RequestIds::positiveUnique($request->request->get('offer_ids', []));
        if (empty($offerIds)) {
            return $this->json(['success' => false, 'error' => $this->trans('No offers selected.', self::DOMAIN)], 422);
        }
        if (count($offerIds) > BulkPriceEditService::MAX_OFFERS) {
            return $this->json(['success' => false, 'error' => $this->trans(
                'Select at most %count% offers at once.',
                self::DOMAIN,
                ['%count%' => BulkPriceEditService::MAX_OFFERS]
            )], 422);
        }

        try {
            return $this->json(['success' => true] + $work($offerIds));
        } catch (\InvalidArgumentException $e) {
            return $this->json(['success' => false, 'error' => $this->trans($e->getMessage(), self::DOMAIN)], 422);
        } catch (\Throwable $e) {
            $this->logger->error('OfferBulkPriceController error', ['error' => $e->getMessage()]);

            return $this->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * @return array<string,string>
     */
    private function params(Request $request): array
    {
        return [
            'operation' => (string) $request->request->get('operation', ''),
            'amount' => (string) $request->request->get('amount', ''),
            'percent' => (string) $request->request->get('percent', ''),
            'rounding' => (string) $request->request->get('rounding', ''),
        ];
    }
}
