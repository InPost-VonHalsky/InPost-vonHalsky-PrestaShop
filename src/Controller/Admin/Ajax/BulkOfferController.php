<?php

declare(strict_types=1);

namespace Inpost\VonHalsky\Controller\Admin\Ajax;

use Configuration;
use Db;
use Inpost\VonHalsky\Helper\Config;
use Inpost\VonHalsky\Service\Offer\BulkOfferCreationService;
use Inpost\VonHalsky\Service\Offer\HintProductizationService;
use Inpost\VonHalsky\Service\Offer\OfferService;
use Inpost\VonHalsky\Service\Offer\ProductPreValidationService;
use Inpost\VonHalsky\Service\Util\ConfigurationService;
use Inpost\VonHalsky\Service\Util\InpostLoggerService;
use PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Inpost\VonHalsky\Helper\RequestIds;

/**
 * Bulk offer AJAX endpoints (GOKINT-930): chunked validate / create / publish.
 */
class BulkOfferController extends FrameworkBundleAdminController
{
    private ProductPreValidationService $preValidation;
    private BulkOfferCreationService $bulkCreation;
    private OfferService $offerService;
    private HintProductizationService $hintProductization;
    private Config $config;
    private InpostLoggerService $logger;

    public function __construct(
        ProductPreValidationService $preValidation,
        BulkOfferCreationService $bulkCreation,
        OfferService $offerService,
        HintProductizationService $hintProductization,
        Config $config,
        InpostLoggerService $logger
    ) {
        $this->preValidation = $preValidation;
        $this->bulkCreation = $bulkCreation;
        $this->offerService = $offerService;
        $this->hintProductization = $hintProductization;
        $this->config = $config;
        $this->logger = $logger;
    }

    private function isEnabled(): bool
    {
        return (bool) Configuration::get(ConfigurationService::BULK_LIST_ENABLED);
    }

    /**
     * @return int[]
     */
    private function intIds(Request $request, string $key): array
    {
        return RequestIds::positiveUnique($request->request->get($key, []));
    }

    /**
     * @return array<int,array{0:int,1:int}> [id_product, id_product_attribute]
     */
    private function items(Request $request): array
    {
        $items = [];
        $raw = $request->request->get('items', []);
        foreach (is_array($raw) ? $raw : [] as $item) {
            if (preg_match('/^(\d+):(\d+)$/', (string) $item, $m) && (int) $m[1] > 0) {
                $items[] = [(int) $m[1], (int) $m[2]];
            }
        }
        foreach ($this->intIds($request, 'product_ids') as $id) {
            $items[] = [$id, 0];
        }

        return $items;
    }

    public function validateBatchAction(Request $request): JsonResponse
    {
        try {
            if (!$this->isEnabled()) {
                return $this->json(['success' => false, 'error' => 'Bulk listing is disabled'], 403);
            }
            $ids = $this->intIds($request, 'product_ids');
            $shopId = $this->config->getDefaultShopId();

            // GOKINT-927: read-only preview — which products would get a category
            // auto-mapped from an InPost hint. Nothing is written here; the mapping
            // is created later, on "Create Draft".
            $hintMappable = $this->hintProductization->previewMappableFromHints($ids, $shopId);

            $results = $this->preValidation->validateBatch($ids, $shopId, $hintMappable);
            foreach ($results as &$result) {
                $result['messages'] = $this->translateMessages($result['messages'], $result['missing_attributes'] ?? []);
                foreach ($result['combinations'] as &$combination) {
                    $combination['messages'] = $this->translateMessages($combination['messages'], $combination['missing_attributes']);
                }
                unset($combination);
            }
            unset($result);

            return $this->json(['success' => true, 'results' => $results]);
        } catch (\Exception $e) {
            $this->logger->error('BulkOfferController::validateBatchAction error', ['error' => $e->getMessage()]);
            return $this->json(['success' => false, 'error' => 'Internal server error: ' . $e->getMessage()], 500);
        }
    }

    /**
     * @param string[] $messages Pre-validation messages (English source strings)
     * @param string[] $missingAttributes Names of required attributes without a value
     * @return string[]
     */
    private function translateMessages(array $messages, array $missingAttributes): array
    {
        $translated = array_map(function (string $message): string {
            return $this->trans($message, 'Modules.Inpostvonhalsky.Admin');
        }, $messages);

        if (!empty($missingAttributes)) {
            $translated[] = $this->trans(
                'Required InPost attributes without a value from PrestaShop: %names%. Fill them in the draft or map them to features in Category mapping.',
                'Modules.Inpostvonhalsky.Admin',
                ['%names%' => implode(', ', $missingAttributes)]
            );
        }

        return $translated;
    }

    public function createBatchAction(Request $request): JsonResponse
    {
        try {
            if (!$this->isEnabled()) {
                return $this->json(['success' => false, 'error' => 'Bulk listing is disabled'], 403);
            }
            // items[] = "id_product:id_product_attribute" (combinations); product_ids[] still accepted
            $items = $this->items($request);
            $ids = array_values(array_unique(array_column($items, 0)));
            $shopId = $this->config->getDefaultShopId();

            // GOKINT-927: this is where hint-based category mappings are actually
            // created (on "Create Draft"), from the first hint matched by EAN.
            // Idempotent + cached; attribute/field prefill happens inside createBatch.
            $this->hintProductization->autoMapFromHints($ids, $shopId);

            $results = $this->bulkCreation->createBatch($items, $shopId);

            return $this->json(['success' => true, 'results' => $results]);
        } catch (\Exception $e) {
            $this->logger->error('BulkOfferController::createBatchAction error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return $this->json(['success' => false, 'error' => 'Internal server error: ' . $e->getMessage()], 500);
        }
    }

    public function publishBatchAction(Request $request): JsonResponse
    {
        try {
            if (!$this->isEnabled()) {
                return $this->json(['success' => false, 'error' => 'Bulk listing is disabled'], 403);
            }
            $offerIds = $this->intIds($request, 'offer_ids');
            if (empty($offerIds)) {
                return $this->json(['success' => false, 'error' => 'offer_ids is required'], 400);
            }

            // Publishing requires is_draft = 0; flip it before sending.
            Db::getInstance()->update(
                'inpost_offer',
                ['is_draft' => 0, 'require_api_sync' => 1],
                'offer_id IN (' . implode(',', $offerIds) . ')'
            );

            $result = $this->offerService->sendOffers($offerIds, false);

            return $this->json(['success' => true, 'result' => $result]);
        } catch (\Exception $e) {
            $this->logger->error('BulkOfferController::publishBatchAction error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return $this->json(['success' => false, 'error' => 'Internal server error: ' . $e->getMessage()], 500);
        }
    }
}
