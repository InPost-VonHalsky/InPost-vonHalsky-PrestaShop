<?php

declare(strict_types=1);

namespace Inpost\VonHalsky\Controller\Admin\Ajax;

use Configuration;
use Doctrine\ORM\EntityManagerInterface;
use Inpost\VonHalsky\Helper\Config;
use Inpost\VonHalsky\Repository\OfferRepository;
use Inpost\VonHalsky\Service\Mapping\CategoryMappingService;
use Inpost\VonHalsky\Service\Offer\HintApplyService;
use Inpost\VonHalsky\Service\Offer\HintAttributeMapper;
use Inpost\VonHalsky\Service\Offer\ProductHintService;
use Inpost\VonHalsky\Service\Util\ConfigurationService;
use Inpost\VonHalsky\Service\Util\InpostLoggerService;
use PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Product hint (productization) AJAX controller (GOKINT-927).
 *
 * search: return candidate hints for a query.
 * apply:  re-fetch server-side and fill the offer's product fields + category
 *         attributes. Never trusts client-supplied field values, keeps the offer
 *         DRAFT, and sends nothing to InPost.
 */
class OfferHintController extends FrameworkBundleAdminController
{
    private ProductHintService $productHintService;
    private HintAttributeMapper $hintAttributeMapper;
    private HintApplyService $hintApplyService;
    private CategoryMappingService $categoryMappingService;
    private OfferRepository $offerRepository;
    private Config $config;
    private EntityManagerInterface $entityManager;
    private InpostLoggerService $logger;

    public function __construct(
        ProductHintService $productHintService,
        HintAttributeMapper $hintAttributeMapper,
        HintApplyService $hintApplyService,
        CategoryMappingService $categoryMappingService,
        OfferRepository $offerRepository,
        Config $config,
        EntityManagerInterface $entityManager,
        InpostLoggerService $logger
    ) {
        $this->productHintService = $productHintService;
        $this->hintAttributeMapper = $hintAttributeMapper;
        $this->hintApplyService = $hintApplyService;
        $this->categoryMappingService = $categoryMappingService;
        $this->offerRepository = $offerRepository;
        $this->config = $config;
        $this->entityManager = $entityManager;
        $this->logger = $logger;
    }

    private function isEnabled(): bool
    {
        return (bool) Configuration::get(ConfigurationService::PRODUCTIZATION_ENABLED);
    }

    /**
     * Search hints for a query (EAN or free text).
     */
    public function searchAction(Request $request): JsonResponse
    {
        try {
            if (!$this->isEnabled()) {
                return $this->json(['success' => false, 'error' => 'Productization is disabled'], 403);
            }

            $query = trim((string) ($request->request->get('query') ?? $request->request->get('ean') ?? ''));
            if ($query === '') {
                return $this->json(['success' => false, 'error' => 'query parameter is required'], 400);
            }

            $language = $this->config->getDefaultLanguageCode();
            $hints = $this->productHintService->search($query, $language);

            $candidates = [];
            foreach ($hints as $index => $hint) {
                $candidates[] = [
                    'index' => $index,
                    'product_id' => $hint['product_id'] ?? null,
                    'name' => $hint['name'] ?? '',
                    'brand' => $hint['brand'] ?? '',
                    'ean' => $hint['ean'] ?? '',
                    'category_id' => $hint['category_id'] ?? null,
                    'attribute_count' => count($hint['attributes'] ?? []),
                ];
            }

            return $this->json(['success' => true, 'hints' => $candidates]);
        } catch (\Exception $e) {
            $this->logger->error('OfferHintController::searchAction error', ['error' => $e->getMessage()]);
            return $this->json(['success' => false, 'error' => 'Internal server error: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Apply a chosen hint to an offer (server-side re-fetch).
     */
    public function applyAction(Request $request): JsonResponse
    {
        try {
            if (!$this->isEnabled()) {
                return $this->json(['success' => false, 'error' => 'Productization is disabled'], 403);
            }

            $offerId = (int) $request->request->get('offer_id');
            $query = trim((string) ($request->request->get('query') ?? ''));
            $productId = trim((string) ($request->request->get('product_id') ?? ''));
            $overwrite = (bool) $request->request->get('overwrite');

            if (!$offerId || $query === '' || $productId === '') {
                return $this->json(['success' => false, 'error' => 'offer_id, query and product_id are required'], 400);
            }

            $offer = $this->offerRepository->find($offerId);
            if ($offer === null) {
                return $this->json(['success' => false, 'error' => 'Offer not found'], 404);
            }

            $product = $offer->getProduct();
            if ($product === null) {
                return $this->json(['success' => false, 'error' => 'Offer has no product'], 400);
            }

            $language = $this->config->getDefaultLanguageCode();

            // Re-fetch server-side; never trust a client-supplied payload.
            $hint = $this->productHintService->findByProductId($query, $productId, $language);
            if ($hint === null) {
                return $this->json(['success' => false, 'error' => 'Selected hint no longer available'], 404);
            }

            $this->entityManager->getConnection()->beginTransaction();
            try {
                $appliedFields = $this->hintApplyService->applyProductFields($product, $hint, $overwrite);
                $appliedAttributes = $this->applyAttributes($offer, $hint, $language, $overwrite);

                $this->entityManager->flush();
                $this->entityManager->getConnection()->commit();
            } catch (\Throwable $e) {
                $this->entityManager->getConnection()->rollBack();
                throw $e;
            }

            return $this->json([
                'success' => true,
                'applied' => ['fields' => $appliedFields, 'attributes' => $appliedAttributes],
                'reload' => true,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('OfferHintController::applyAction error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return $this->json(['success' => false, 'error' => 'Internal server error: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Prepare a hint selection during offer creation (GOKINT-927).
     *
     * Re-fetches the chosen hint server-side, ensures a PrestaShop↔InPost category
     * mapping exists (creating one from the hint's category when the product's
     * category has none), and returns the create-form URL to reload with the
     * mapping + hint prefill parameters. Persists nothing but the category link.
     */
    public function prepareAction(Request $request): JsonResponse
    {
        try {
            if (!$this->isEnabled()) {
                return $this->json(['success' => false, 'error' => 'Productization is disabled'], 403);
            }

            $psProductId = (int) $request->request->get('product_id');
            $hintProductId = trim((string) ($request->request->get('hint_product_id') ?? ''));
            $query = trim((string) ($request->request->get('query') ?? ''));

            if (!$psProductId || $hintProductId === '' || $query === '') {
                return $this->json(['success' => false, 'error' => 'product_id, hint_product_id and query are required'], 400);
            }

            $language = $this->config->getDefaultLanguageCode();
            $shopId = $this->config->getDefaultShopId();

            // Re-fetch server-side; never trust a client-supplied payload.
            $hint = $this->productHintService->findByProductId($query, $hintProductId, $language);
            if ($hint === null) {
                return $this->json(['success' => false, 'error' => 'Selected hint no longer available'], 404);
            }

            // Resolve the PrestaShop product's default category.
            $product = new \Product($psProductId);
            if (!\Validate::isLoadedObject($product)) {
                return $this->json(['success' => false, 'error' => 'PrestaShop product not found'], 404);
            }
            $psCategoryId = (int) $product->id_category_default;
            if (!$psCategoryId) {
                return $this->json(['success' => false, 'error' => 'Product has no default category'], 400);
            }

            // Keep an existing mapping if present; otherwise create one from the
            // hint's InPost category.
            $mappingEntityId = $this->categoryMappingService->getMappedInPostCategoryEntityId($psCategoryId, $shopId);
            if ($mappingEntityId === null) {
                $hintCategoryId = (string) ($hint['category_id'] ?? '');
                if ($hintCategoryId === '') {
                    return $this->json(['success' => false, 'error' => 'Hint has no InPost category'], 422);
                }
                try {
                    $this->categoryMappingService->mapCategory($psCategoryId, $hintCategoryId, $shopId);
                } catch (\Exception $e) {
                    // e.g. "InPost category not found ... Please sync categories first."
                    return $this->json(['success' => false, 'error' => $e->getMessage()], 422);
                }
                $mappingEntityId = $this->categoryMappingService->getMappedInPostCategoryEntityId($psCategoryId, $shopId);
                if ($mappingEntityId === null) {
                    return $this->json(['success' => false, 'error' => 'Failed to resolve category mapping after creation'], 500);
                }
            }

            $redirectUrl = $this->generateUrl('inpost_offers_create', array_filter([
                'product_id' => $psProductId,
                'product_attribute_id' => (int) $request->request->get('product_attribute_id', 0),
                'inpost_category_id' => $mappingEntityId,
                'hint_product_id' => $hintProductId,
                'hint_query' => $query,
            ]));

            return $this->json(['success' => true, 'redirect_url' => $redirectUrl]);
        } catch (\Exception $e) {
            $this->logger->error('OfferHintController::prepareAction error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return $this->json(['success' => false, 'error' => 'Internal server error: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Map hint attributes onto the offer's category attributes and upsert them.
     *
     * @param \Inpost\VonHalsky\Entity\Offer $offer
     * @param array<string,mixed> $hint
     * @return int Number of attributes applied
     */
    private function applyAttributes($offer, array $hint, string $language, bool $overwrite): int
    {
        $product = $offer->getProduct();
        $categoryMappingId = $product !== null ? (int) $product->getCategoryMappingId() : 0;
        if (!$categoryMappingId) {
            return 0;
        }

        $mapped = $this->hintAttributeMapper->map($categoryMappingId, $hint['attributes'] ?? [], $language);

        return $this->hintApplyService->applyAttributes($offer, $mapped, $overwrite);
    }
}
