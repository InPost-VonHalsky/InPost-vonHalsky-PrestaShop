<?php

declare(strict_types=1);

namespace Inpost\VonHalsky\Controller\Admin\Ajax;

use Inpost\VonHalsky\Helper\RequestIds;
use Inpost\VonHalsky\Service\Offer\BulkAttributeEditService;
use Inpost\VonHalsky\Service\Util\InpostLoggerService;
use PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * "Edit attributes" dialog of the offers list (GOKINT-1102).
 *
 * attributes: attributes of the categories of the selected offers and the
 *             PrestaShop sources they can be mapped to
 * preview:    what the changes would do to the offers and category mappings
 * apply:      makes the changes; the offers are sent by the offer_sync cron
 *
 * All take POST offer_ids[]; preview and apply also
 * changes[i][key|operation|source] and changes[i][values][].
 */
class OfferBulkAttributeController extends FrameworkBundleAdminController
{
    private const DOMAIN = 'Modules.Inpostvonhalsky.Admin';
    public const CSRF_ID = 'inpost_offers_bulk_attributes';

    private BulkAttributeEditService $bulkEdit;
    private InpostLoggerService $logger;

    public function __construct(BulkAttributeEditService $bulkEdit, InpostLoggerService $logger)
    {
        $this->bulkEdit = $bulkEdit;
        $this->logger = $logger;
    }

    public function attributesAction(Request $request): JsonResponse
    {
        return $this->handle($request, function (array $offerIds): array {
            return $this->bulkEdit->collectAttributes($offerIds);
        });
    }

    public function previewAction(Request $request): JsonResponse
    {
        return $this->handle($request, function (array $offerIds) use ($request): array {
            $plan = $this->bulkEdit->plan($offerIds, $this->changes($request));

            return ['rows' => $this->translateRows($plan['rows']), 'mappings' => $plan['mappings']];
        });
    }

    public function applyAction(Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid(self::CSRF_ID, (string) $request->request->get('_csrf'))) {
            return $this->json(['success' => false, 'error' => $this->trans('Invalid security token. Please try again.', self::DOMAIN)], 403);
        }

        return $this->handle($request, function (array $offerIds) use ($request): array {
            $result = $this->bulkEdit->apply($offerIds, $this->changes($request));

            if ($result['changed'] > 0) {
                $this->addFlash('success', $this->trans('%values% attribute value(s) changed in %count% offer(s).', self::DOMAIN, [
                    '%values%' => $result['changed'],
                    '%count%' => $result['offers'],
                ]));
            } elseif ($result['mappings'] === 0) {
                $this->addFlash('info', $this->trans('No offer needed a change.', self::DOMAIN));
            }
            if ($result['mappings'] > 0) {
                $this->addFlash('success', $this->trans('PrestaShop source saved in the mapping of %count% category attribute(s).', self::DOMAIN, ['%count%' => $result['mappings']]));
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
        if (count($offerIds) > BulkAttributeEditService::MAX_OFFERS) {
            return $this->json(['success' => false, 'error' => $this->trans(
                'Select at most %count% offers at once.',
                self::DOMAIN,
                ['%count%' => BulkAttributeEditService::MAX_OFFERS]
            )], 422);
        }

        try {
            return $this->json(['success' => true] + $work($offerIds));
        } catch (\InvalidArgumentException $e) {
            return $this->json(['success' => false, 'error' => $this->trans($e->getMessage(), self::DOMAIN)], 422);
        } catch (\Throwable $e) {
            $this->logger->error('OfferBulkAttributeController error', ['error' => $e->getMessage()]);

            return $this->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function changes(Request $request): array
    {
        $changes = $request->request->get('changes', []);

        return is_array($changes) ? array_values(array_filter($changes, 'is_array')) : [];
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    private function translateRows(array $rows): array
    {
        foreach ($rows as &$row) {
            if (!empty($row['reason'])) {
                $row['reason'] = $this->trans((string) $row['reason'], self::DOMAIN, $row['reason_params']);
            }
            unset($row['reason_params']);
        }

        return $rows;
    }
}
