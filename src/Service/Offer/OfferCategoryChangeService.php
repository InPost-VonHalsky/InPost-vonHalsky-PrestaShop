<?php
/**
 * OfferCategoryChangeService
 *
 * Moves an existing offer to another InPost category. The offer keeps its own
 * copy of the category (offer + offer product columns), so no mapping has to
 * change for it - only the offer's attribute rows: values of attributes the new
 * category also has are kept, the rest are dropped, and the new category's
 * attributes are filled from PrestaShop (mapped features / attribute groups) and
 * from the InPost catalogue hint.
 *
 * The edited offer itself goes through the offer form (nothing is saved until
 * the merchant saves it); the other variants of the product are moved here with
 * changeSibling(). Changing the shop category link is optional and only affects
 * offers created later.
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Service\Offer;

use Db;
use Doctrine\ORM\EntityManagerInterface;
use Inpost\VonHalsky\Entity\CategoryMapping;
use Inpost\VonHalsky\Entity\Offer;
use Inpost\VonHalsky\Entity\OfferAttributes;
use Inpost\VonHalsky\Helper\Config;
use Inpost\VonHalsky\Repository\CategoryMappingRepository;
use Inpost\VonHalsky\Repository\OfferRepository;
use Inpost\VonHalsky\Service\Mapping\AttributeMappingService;
use Inpost\VonHalsky\Service\Mapping\CategoryMappingService;
use Inpost\VonHalsky\Service\Util\LoggerService;
use Inpost\VonHalsky\Service\Util\ShopLog;

class OfferCategoryChangeService
{
    private EntityManagerInterface $entityManager;
    private OfferRepository $offerRepository;
    private CategoryMappingRepository $categoryMappingRepository;
    private CategoryMappingService $categoryMappingService;
    private AttributeMappingService $attributeMappingService;
    private ProductAttributeValueResolver $attributeValueResolver;
    private HintApplyService $hintApplyService;
    private HintAttributeMapper $hintAttributeMapper;
    private ProductHintService $productHintService;
    private HintProductizationService $hintProductization;
    private ProductCombinationService $combinationService;
    private Config $config;
    private LoggerService $logger;

    public function __construct(
        EntityManagerInterface $entityManager,
        OfferRepository $offerRepository,
        CategoryMappingRepository $categoryMappingRepository,
        CategoryMappingService $categoryMappingService,
        AttributeMappingService $attributeMappingService,
        ProductAttributeValueResolver $attributeValueResolver,
        HintApplyService $hintApplyService,
        HintAttributeMapper $hintAttributeMapper,
        ProductHintService $productHintService,
        HintProductizationService $hintProductization,
        ProductCombinationService $combinationService,
        Config $config,
        LoggerService $logger
    ) {
        $this->entityManager = $entityManager;
        $this->offerRepository = $offerRepository;
        $this->categoryMappingRepository = $categoryMappingRepository;
        $this->categoryMappingService = $categoryMappingService;
        $this->attributeMappingService = $attributeMappingService;
        $this->attributeValueResolver = $attributeValueResolver;
        $this->hintApplyService = $hintApplyService;
        $this->hintAttributeMapper = $hintAttributeMapper;
        $this->productHintService = $productHintService;
        $this->hintProductization = $hintProductization;
        $this->combinationService = $combinationService;
        $this->config = $config;
        $this->logger = $logger;
    }

    /**
     * Why the category of the offer cannot be changed now, null when it can.
     * An offer waiting for the InPost review (PENDING, no result yet) is left
     * alone. Once InPost rejected it in validation (status stays PENDING, the
     * VALIDATION_FAILED event brings the errors, e.g. CATEGORY_INCORRECT) the
     * category can be changed to fix it.
     */
    public function getBlockReason(Offer $offer): ?string
    {
        if ($offer->getStatus() === Offer::STATUS_PENDING && !$this->hasInpostValidationErrors($offer)) {
            return 'The offer is being reviewed by InPost - its category can be changed once the review ends.';
        }

        return null;
    }

    /**
     * Validation errors returned by InPost for a sent offer (stored by the status
     * refresh after the VALIDATION_FAILED event)
     */
    public function hasInpostValidationErrors(Offer $offer): bool
    {
        return !empty($offer->getInpostOfferId())
            && $offer->getInpostValidationStatus() === 'INVALID'
            && !empty($offer->getValidationErrors());
    }

    /**
     * Category the offer can be moved to: an active leaf category. Fetches its
     * attributes from InPost when they were never synced.
     *
     * @throws \InvalidArgumentException With a translatable message
     */
    public function resolveTarget(int $categoryMappingId): CategoryMapping
    {
        $category = $categoryMappingId > 0 ? $this->categoryMappingRepository->find($categoryMappingId) : null;
        if (!$category instanceof CategoryMapping) {
            throw new \InvalidArgumentException('The selected InPost category does not exist - run the category sync.');
        }
        if (!$category->getIsLeaf()) {
            throw new \InvalidArgumentException('Offers can be placed only in the lowest-level InPost categories.');
        }
        if (!$category->getIsActive()) {
            throw new \InvalidArgumentException('The selected InPost category is no longer active in InPost.');
        }

        $this->ensureAttributesSynced($category);

        return $category;
    }

    /**
     * Category (inpost_category_mapping.entity_id) the offer is in now
     */
    public function getCurrentCategoryMappingId(Offer $offer): ?int
    {
        $product = $offer->getProduct();
        if ($product !== null && $product->getCategoryMappingId()) {
            return (int) $product->getCategoryMappingId();
        }

        $uuid = ($product !== null ? $product->getInpostCategoryId() : null) ?? $offer->getInpostCategoryId();
        if (!$uuid) {
            return null;
        }
        $category = $this->categoryMappingRepository->findByInpostCategoryId($uuid, $this->config->getDefaultLanguageCode());

        return $category !== null ? (int) $category->getEntityId() : null;
    }

    /**
     * Put the offer in the category: the mapping id and the category UUID on the
     * offer and on its product (the payload sends the product one)
     */
    public function applyCategory(Offer $offer, CategoryMapping $category): void
    {
        $uuid = $category->getInpostCategoryId();
        $offer->setInpostCategoryId($uuid);

        $product = $offer->getProduct();
        if ($product !== null) {
            $product->setCategoryMappingId((int) $category->getEntityId());
            $product->setCategoryId($uuid);
            $product->setInpostCategoryId($uuid);
        }
    }

    /**
     * What changing the category would do - shown before the merchant goes on.
     * Reads only, saves nothing.
     *
     * @param int|null $currentId Category the offer is in, when the caller already
     *        put the target on the offer product in memory (edit form)
     * @return array<string,mixed>
     */
    public function preview(Offer $offer, CategoryMapping $target, ?int $currentId = null): array
    {
        $language = $this->config->getDefaultLanguageCode();
        $targetId = (int) $target->getEntityId();
        $currentId = $currentId ?? $this->getCurrentCategoryMappingId($offer);

        $newAttributes = $this->attributeMappingService->getAttributesForCategoryMappingId($targetId, $language);
        $oldAttributes = $currentId ? $this->attributeMappingService->getAttributesForCategoryMappingId($currentId, $language) : [];
        $oldByCode = array_column($oldAttributes, null, 'code');

        $stored = $this->getStoredValues($offer);
        $fromPs = ProductAttributeValueResolver::applicable($this->resolvePsValues($offer, $targetId, $newAttributes));
        $fromHint = $this->getHintValues($offer, $targetId);

        $kept = [];
        $added = [];
        $newCodes = [];
        $unmappedCount = 0;
        foreach ($newAttributes as $attribute) {
            $code = (string) $attribute['code'];
            $newCodes[$code] = true;
            if (empty($attribute['ps_source_type'])) {
                $unmappedCount++;
            }

            if (!empty($stored[$code])) {
                $kept[] = [
                    'label' => $attribute['label'],
                    'values' => $this->valueLabels($attribute, $stored[$code]),
                ];
                continue;
            }

            if (isset($fromPs[$code])) {
                $fill = 'ps';
                $values = $fromPs[$code]['values'];
            } elseif (!empty($fromHint[$code])) {
                $fill = 'hint';
                $values = $fromHint[$code];
            } else {
                $fill = null;
                $values = [];
            }

            $added[] = [
                'label' => $attribute['label'],
                'required' => (bool) $attribute['required'],
                'fill' => $fill,
                'values' => $this->valueLabels($attribute, $values),
            ];
        }

        $lost = [];
        foreach ($stored as $code => $values) {
            if (isset($newCodes[$code]) || empty($values)) {
                continue;
            }
            $definition = $oldByCode[$code] ?? null;
            $lost[] = [
                'label' => $definition !== null ? $definition['label'] : $code,
                'values' => $definition !== null ? $this->valueLabels($definition, $values) : $values,
            ];
        }

        return [
            'current' => $currentId ? [
                'id' => $currentId,
                'path' => $this->categoryMappingService->getInpostCategoryPath($currentId),
            ] : null,
            'target' => [
                'id' => $targetId,
                'uuid' => $target->getInpostCategoryId(),
                'path' => $this->categoryMappingService->getInpostCategoryPath($targetId),
            ],
            'same_category' => $currentId === $targetId,
            'attributes' => [
                'kept' => $kept,
                'lost' => $lost,
                'added' => $added,
                'without_source' => $unmappedCount,
            ],
            'variants' => $this->describeSiblings($offer, $targetId),
            'shop_category' => $this->getShopCategoryLink($offer, $targetId),
            'is_sent' => !empty($offer->getInpostOfferId()),
        ];
    }

    /**
     * Values the InPost catalogue hint (matched by the product EAN) gives for the
     * category's attributes - empty when productization is off or nothing matches
     *
     * @return array<string,string[]> By InPost attribute UUID
     */
    public function getHintValues(Offer $offer, int $categoryMappingId): array
    {
        $product = $offer->getProduct();
        if (!$this->hintProductization->isEnabled() || $product === null || trim((string) $product->getEan()) === '') {
            return [];
        }

        $language = $this->config->getDefaultLanguageCode();
        try {
            $hint = $this->productHintService->findByEan((string) $product->getEan(), $language);
            if ($hint === null) {
                return [];
            }

            $values = [];
            foreach ($this->hintAttributeMapper->map($categoryMappingId, $hint['attributes'] ?? [], $language) as $entry) {
                $values[(string) $entry['inpost_attribute_id']] = [(string) $entry['value']];
            }

            return $values;
        } catch (\Throwable $e) {
            $this->logger->warning('Category change: could not read the InPost catalogue hint', [
                'offer_id' => $offer->getId(),
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Other offers of the same PrestaShop product (the other variants)
     *
     * @return Offer[]
     */
    public function getSiblings(Offer $offer): array
    {
        if (!$offer->getPrestashopOfferId()) {
            return [];
        }

        $siblings = [];
        foreach ($this->offerRepository->findBy(['prestashopOfferId' => $offer->getPrestashopOfferId(), 'shopId' => $offer->getShopId()]) as $candidate) {
            if ($candidate->getId() !== $offer->getId()) {
                $siblings[] = $candidate;
            }
        }

        return $siblings;
    }

    /**
     * Move another variant offer to the category without the form: keep the
     * values of attributes the new category also has, drop the others, fill the
     * new ones from PrestaShop and the catalogue hint. The caller flushes.
     *
     * @return string[] Labels of required attributes still without a value
     */
    public function changeSibling(Offer $offer, CategoryMapping $target): array
    {
        $language = $this->config->getDefaultLanguageCode();
        $targetId = (int) $target->getEntityId();
        $newAttributes = $this->attributeMappingService->getAttributesForCategoryMappingId($targetId, $language);
        $newCodes = array_flip(array_map('strval', array_column($newAttributes, 'code')));

        $this->applyCategory($offer, $target);

        foreach ($offer->getAttributes()->toArray() as $attribute) {
            if (isset($newCodes[(string) $attribute->getInpostAttributeId()])) {
                $attribute->setCategoryMappingId((string) $targetId);
                continue;
            }
            $offer->removeAttribute($attribute);
            $this->entityManager->remove($attribute);
        }
        // Deletes before the inserts below (same unique key)
        $this->entityManager->flush();

        $fromPs = ProductAttributeValueResolver::applicable($this->resolvePsValues($offer, $targetId, $newAttributes));
        $this->hintApplyService->applyAttributes($offer, $fromPs, false, OfferAttributes::SOURCE_PS);

        $fromHint = [];
        $byCode = array_column($newAttributes, null, 'code');
        foreach ($this->getHintValues($offer, $targetId) as $code => $values) {
            if (isset($byCode[$code])) {
                $fromHint[] = ['attribute_id' => $byCode[$code]['id'], 'inpost_attribute_id' => $code, 'values' => $values];
            }
        }
        $this->hintApplyService->applyAttributes($offer, $fromHint, false, OfferAttributes::SOURCE_HINT);

        $missing = $this->getMissingRequired($offer, $newAttributes);

        // Sent offers go to InPost with the next sync - unless a required value is
        // missing, then the merchant has to complete the variant first
        $offer->setUpdatedAt(new \DateTime());
        if ($offer->getIsDraft() === 0) {
            $offer->setRequireApiSync(empty($missing));
        }

        return $missing;
    }

    /**
     * Link the product's default shop category to the category, so offers created
     * later land there. Existing offers are not touched.
     *
     * @return int|null PrestaShop category id that was linked, null when the product has none
     */
    public function linkShopCategory(Offer $offer, CategoryMapping $target): ?int
    {
        $psCategoryId = $this->getDefaultShopCategoryId($offer);
        if (!$psCategoryId) {
            return null;
        }

        $this->categoryMappingService->mapCategory($psCategoryId, $target->getInpostCategoryId(), $this->config->getDefaultShopId());

        return $psCategoryId;
    }

    /**
     * Trace of the change in the PrestaShop log and the module log
     *
     * @param array<string,mixed> $details
     */
    public function logChange(Offer $offer, ?int $fromCategoryId, CategoryMapping $target, array $details): void
    {
        $from = $fromCategoryId ? implode(' > ', $this->categoryMappingService->getInpostCategoryPath($fromCategoryId)) : '-';
        $to = implode(' > ', $this->categoryMappingService->getInpostCategoryPath((int) $target->getEntityId()));

        ShopLog::add(
            sprintf('[InPost] Offer #%d moved to another InPost category: %s => %s. %s', (int) $offer->getId(), $from, $to, json_encode($details, JSON_UNESCAPED_UNICODE)),
            1,
            null,
            'InpostOffer',
            (int) $offer->getId()
        );
        $this->logger->info('Offer category changed', [
            'offer_id' => $offer->getId(),
            'from' => $fromCategoryId,
            'to' => $target->getEntityId(),
        ] + $details);
    }

    /**
     * Labels of the required attributes of the category without a value on the offer
     *
     * @param array<int,array<string,mixed>> $categoryAttributes
     * @return string[]
     */
    public function getMissingRequired(Offer $offer, array $categoryAttributes): array
    {
        $stored = $this->getStoredValues($offer);
        $missing = [];
        foreach ($categoryAttributes as $attribute) {
            if (!empty($attribute['required']) && empty($stored[(string) $attribute['code']])) {
                $missing[] = (string) $attribute['label'];
            }
        }

        return $missing;
    }

    /**
     * Fetch the attributes of a category that has none locally (never synced)
     */
    private function ensureAttributesSynced(CategoryMapping $category): void
    {
        $count = (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'inpost_category_attribute` WHERE inpost_category_mapping_id = ' . (int) $category->getEntityId()
        );
        if ($count > 0) {
            return;
        }

        try {
            $this->attributeMappingService->syncCategoryAttributes(
                [['id' => $category->getInpostCategoryId(), 'is_leaf' => 1]],
                $this->config->getDefaultLanguageCode()
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Category change: could not fetch the attributes of the category', [
                'category' => $category->getInpostCategoryId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function resolvePsValues(Offer $offer, int $categoryMappingId, array $categoryAttributes): array
    {
        if (!$offer->getPrestashopOfferId() || empty($categoryAttributes)) {
            return [];
        }

        try {
            return $this->attributeValueResolver->resolve(
                (int) $offer->getPrestashopOfferId(),
                $offer->getPrestashopProductAttributeId(),
                $categoryMappingId,
                $this->config->getDefaultLanguageCode(),
                $categoryAttributes
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Category change: could not read attribute values from PrestaShop', [
                'offer_id' => $offer->getId(),
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Non-empty stored attribute values by InPost attribute UUID
     *
     * @return array<string,string[]>
     */
    private function getStoredValues(Offer $offer): array
    {
        $values = [];
        foreach ($offer->getAttributes() as $attribute) {
            $code = (string) $attribute->getInpostAttributeId();
            if ($code === '') {
                continue;
            }
            $decoded = json_decode($attribute->getValue(), true);
            $list = is_array($decoded) ? $decoded : [$attribute->getValue()];
            $list = array_values(array_filter(array_map(static function ($value): string {
                return is_scalar($value) ? trim((string) $value) : '';
            }, $list), 'strlen'));
            if (!empty($list)) {
                $values[$code] = $list;
            }
        }

        return $values;
    }

    /**
     * Dictionary option codes shown as their labels
     *
     * @param string[] $values
     * @return string[]
     */
    private function valueLabels(array $attribute, array $values): array
    {
        $labels = [];
        foreach ($attribute['dictionary_values'] ?? [] as $option) {
            $labels[(string) $option['value']] = (string) $option['label'];
        }

        return array_map(static function ($value) use ($labels): string {
            return $labels[(string) $value] ?? (string) $value;
        }, $values);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function describeSiblings(Offer $offer, int $targetId): array
    {
        $siblings = $this->getSiblings($offer);
        $labels = $this->combinationService->getLabels(array_map(static function (Offer $sibling): int {
            return $sibling->getPrestashopProductAttributeId();
        }, $siblings));

        $result = [];
        foreach ($siblings as $sibling) {
            $blockReason = $this->getBlockReason($sibling);
            $result[] = [
                'id' => $sibling->getId(),
                'label' => $labels[$sibling->getPrestashopProductAttributeId()] ?? '',
                'status' => $sibling->getStatus(),
                'is_sent' => !empty($sibling->getInpostOfferId()),
                'same_category' => $this->getCurrentCategoryMappingId($sibling) === $targetId,
                'blocked' => $blockReason,
            ];
        }

        return $result;
    }

    /**
     * Default shop category of the product and the InPost category it is linked to
     *
     * @return array<string,mixed>|null
     */
    private function getShopCategoryLink(Offer $offer, int $targetId): ?array
    {
        $psCategoryId = $this->getDefaultShopCategoryId($offer);
        if (!$psCategoryId) {
            return null;
        }

        $name = (string) Db::getInstance()->getValue(
            'SELECT name FROM `' . _DB_PREFIX_ . 'category_lang` WHERE id_category = ' . (int) $psCategoryId
            . ' AND id_lang = ' . (int) $this->config->getDefaultLanguageId()
            . ' AND id_shop = ' . (int) $this->config->getDefaultShopId()
        );
        $linkedId = $this->categoryMappingService->getMappedInPostCategoryEntityId($psCategoryId, $this->config->getDefaultShopId());

        return [
            'id' => $psCategoryId,
            'name' => $name,
            'linked_path' => $linkedId ? $this->categoryMappingService->getInpostCategoryPath($linkedId) : [],
            'state' => !$linkedId ? 'none' : ($linkedId === $targetId ? 'same' : 'other'),
        ];
    }

    private function getDefaultShopCategoryId(Offer $offer): ?int
    {
        if (!$offer->getPrestashopOfferId()) {
            return null;
        }

        $id = (int) Db::getInstance()->getValue(
            'SELECT id_category_default FROM `' . _DB_PREFIX_ . 'product` WHERE id_product = ' . (int) $offer->getPrestashopOfferId()
        );

        return $id > 0 ? $id : null;
    }
}
