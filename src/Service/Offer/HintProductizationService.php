<?php
/**
 * HintProductizationService
 *
 * Applies InPost /hints productization (GOKINT-927) to the bulk offer flow
 * (GOKINT-930), mirroring the single-offer create form:
 *  - autoMapFromHints(): at pre-validation, for each product with an EAN and no
 *    category mapping, fetch the first hint and auto-create the PrestaShop↔InPost
 *    category link (existing mappings are kept; an unsynced hint category is
 *    skipped with a readable status).
 *  - applyHintToOffer(): at creation, fill the offer's product fields and category
 *    attributes from the first hint matched by EAN.
 *
 * Gated by INPOSTSA_PRODUCTIZATION_ENABLED. Never throws to the caller from the
 * batch entry points — failures are logged and reported per product.
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Service\Offer;

use Configuration;
use Inpost\VonHalsky\Entity\Offer;
use Inpost\VonHalsky\Helper\Config;
use Inpost\VonHalsky\Service\Mapping\CategoryMappingService;
use Inpost\VonHalsky\Service\Util\ConfigurationService;
use Inpost\VonHalsky\Service\Util\LoggerService;

class HintProductizationService
{
    public const MAP_MAPPED = 'mapped';
    public const MAP_KEPT = 'kept';
    public const MAP_NO_EAN = 'no_ean';
    public const MAP_NO_HINT = 'no_hint';
    public const MAP_UNSYNCED = 'unsynced';
    public const MAP_ERROR = 'error';

    private ProductHintService $productHintService;
    private HintAttributeMapper $hintAttributeMapper;
    private HintApplyService $hintApplyService;
    private CategoryMappingService $categoryMappingService;
    private Config $config;
    private LoggerService $logger;

    public function __construct(
        ProductHintService $productHintService,
        HintAttributeMapper $hintAttributeMapper,
        HintApplyService $hintApplyService,
        CategoryMappingService $categoryMappingService,
        Config $config,
        LoggerService $logger
    ) {
        $this->productHintService = $productHintService;
        $this->hintAttributeMapper = $hintAttributeMapper;
        $this->hintApplyService = $hintApplyService;
        $this->categoryMappingService = $categoryMappingService;
        $this->config = $config;
        $this->logger = $logger;
    }

    public function isEnabled(): bool
    {
        return (bool) Configuration::get(ConfigurationService::PRODUCTIZATION_ENABLED);
    }

    /**
     * Read-only preview: which products would get a category auto-mapped from an
     * InPost hint on "Create Draft". Writes nothing. A product is "mappable" when
     * it has no existing mapping, has an EAN, and a hint matched by that EAN points
     * at an InPost category that is already synced locally.
     *
     * @param int[] $productIds
     * @return array<int,bool> productId => true when it will be auto-mapped
     */
    public function previewMappableFromHints(array $productIds, int $shopId): array
    {
        if (!$this->isEnabled()) {
            return [];
        }

        $preview = [];
        foreach ($productIds as $productId) {
            $productId = (int) $productId;
            if ($productId <= 0) {
                continue;
            }
            $preview[$productId] = $this->isHintMappable($productId, $shopId);
        }

        return $preview;
    }

    /**
     * Read-only check backing previewMappableFromHints(). Never writes.
     */
    private function isHintMappable(int $psProductId, int $shopId): bool
    {
        try {
            $product = new \Product($psProductId);
            if (!\Validate::isLoadedObject($product)) {
                return false;
            }

            $psCategoryId = (int) $product->id_category_default;
            if (!$psCategoryId) {
                return false;
            }

            // Already mapped → handled as a normal (non-hint) product by validation.
            if ($this->categoryMappingService->getMappedInPostCategoryEntityId($psCategoryId, $shopId) !== null) {
                return false;
            }

            $ean = trim((string) $product->ean13);
            if ($ean === '') {
                return false;
            }

            $hint = $this->productHintService->findByEan($ean, $this->config->getDefaultLanguageCode());
            $hintCategoryId = $hint !== null ? (string) ($hint['category_id'] ?? '') : '';
            if ($hintCategoryId === '') {
                return false;
            }

            // Only "mappable" if the hint's category is synced (so mapCategory succeeds).
            return $this->categoryMappingService->isInpostCategorySynced($hintCategoryId);
        } catch (\Throwable $e) {
            $this->logger->warning('Bulk productization preview failed', [
                'product_id' => $psProductId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Auto-create category mappings from hints for a batch of products.
     *
     * @param int[] $productIds
     * @return array<int,string> productId => one of the MAP_* statuses
     */
    public function autoMapFromHints(array $productIds, int $shopId): array
    {
        if (!$this->isEnabled()) {
            return [];
        }

        $outcomes = [];
        foreach ($productIds as $productId) {
            $productId = (int) $productId;
            if ($productId <= 0) {
                continue;
            }
            $outcomes[$productId] = $this->mapSingleFromHint($productId, $shopId);
        }

        return $outcomes;
    }

    /**
     * Ensure a single product's category is mapped, creating it from the first
     * hint matched by EAN when missing. Existing mappings are kept untouched.
     */
    private function mapSingleFromHint(int $psProductId, int $shopId): string
    {
        try {
            $product = new \Product($psProductId);
            if (!\Validate::isLoadedObject($product)) {
                return self::MAP_ERROR;
            }

            $psCategoryId = (int) $product->id_category_default;
            if (!$psCategoryId) {
                return self::MAP_ERROR;
            }

            // Keep an existing mapping.
            if ($this->categoryMappingService->getMappedInPostCategoryEntityId($psCategoryId, $shopId) !== null) {
                return self::MAP_KEPT;
            }

            $ean = trim((string) $product->ean13);
            if ($ean === '') {
                return self::MAP_NO_EAN;
            }

            $hint = $this->productHintService->findByEan($ean, $this->config->getDefaultLanguageCode());
            $hintCategoryId = $hint !== null ? (string) ($hint['category_id'] ?? '') : '';
            if ($hintCategoryId === '') {
                return self::MAP_NO_HINT;
            }

            try {
                $this->categoryMappingService->mapCategory($psCategoryId, $hintCategoryId, $shopId);
            } catch (\Exception $e) {
                // e.g. "InPost category not found ... Please sync categories first."
                $this->logger->warning('Bulk productization: could not map category from hint', [
                    'product_id' => $psProductId,
                    'inpost_category_id' => $hintCategoryId,
                    'error' => $e->getMessage(),
                ]);
                return self::MAP_UNSYNCED;
            }

            return self::MAP_MAPPED;
        } catch (\Throwable $e) {
            $this->logger->error('Bulk productization: mapping from hint failed', [
                'product_id' => $psProductId,
                'error' => $e->getMessage(),
            ]);
            return self::MAP_ERROR;
        }
    }

    /**
     * Fill a freshly built offer's product fields + category attributes from the
     * first hint matched by the product's EAN. No-op when productization is off,
     * the product has no EAN/mapping, or no hint is found. Attributes are added to
     * the offer's collection and persisted by the caller's flush (cascade).
     */
    public function applyHintToOffer(Offer $offer, bool $overwrite = false): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $product = $offer->getProduct();
        if ($product === null) {
            return;
        }

        $ean = trim((string) $product->getEan());
        if ($ean === '') {
            return;
        }

        $language = $this->config->getDefaultLanguageCode();

        $hint = $this->productHintService->findByEan($ean, $language);
        if ($hint === null) {
            return;
        }

        // Product fields (fill empties unless overwriting).
        $this->hintApplyService->applyProductFields($product, $hint, $overwrite);

        // Category attributes.
        $categoryMappingId = (int) $product->getCategoryMappingId();
        if (!$categoryMappingId) {
            return;
        }

        $mapped = $this->hintAttributeMapper->map($categoryMappingId, $hint['attributes'] ?? [], $language);
        $this->hintApplyService->applyAttributes($offer, $mapped, $overwrite);
    }
}
