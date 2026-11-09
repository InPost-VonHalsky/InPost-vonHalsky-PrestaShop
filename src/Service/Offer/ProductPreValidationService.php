<?php
/**
 * ProductPreValidationService
 *
 * Batch pre-validation for bulk offer creation (GOKINT-930). One status per
 * product: blocked (no mapped InPost category, or an offer already exists),
 * warning (no images / no EAN and no brand+MPN / orphaned category), or ready.
 * A product with combinations also gets one status per combination (one offer
 * each) and whether it is preselected. Uses batched SQL so 100+ products stay fast.
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Service\Offer;

use Db;
use Inpost\VonHalsky\Helper\Config;
use Inpost\VonHalsky\Service\Mapping\AttributeMappingService;
use Inpost\VonHalsky\Service\Mapping\CategoryMappingService;

class ProductPreValidationService
{
    public const STATUS_READY = 'ready';
    public const STATUS_WARNING = 'warning';
    public const STATUS_BLOCKED = 'blocked';

    private Db $db;
    private Config $config;
    private string $prefix;
    private CategoryMappingService $categoryMappingService;
    private AttributeMappingService $attributeMappingService;
    private ProductAttributeValueResolver $attributeValueResolver;
    private ProductCombinationService $combinationService;

    public function __construct(
        Config $config,
        CategoryMappingService $categoryMappingService,
        AttributeMappingService $attributeMappingService,
        ProductAttributeValueResolver $attributeValueResolver,
        ProductCombinationService $combinationService
    ) {
        $this->db = Db::getInstance();
        $this->config = $config;
        $this->prefix = _DB_PREFIX_;
        $this->categoryMappingService = $categoryMappingService;
        $this->attributeMappingService = $attributeMappingService;
        $this->attributeValueResolver = $attributeValueResolver;
        $this->combinationService = $combinationService;
    }

    /**
     * Validate a batch of product ids.
     *
     * @param int[] $productIds
     * @param int $shopId
     * @param array<int,bool> $hintMappable productId => true when a category will be
     *        auto-mapped from an InPost hint on creation (read-only preview). Such
     *        products are reported as informational rather than blocked.
     * @return array<int,array<string,mixed>>
     */
    public function validateBatch(array $productIds, int $shopId, array $hintMappable = []): array
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));
        $productIds = array_filter($productIds, static fn(int $id): bool => $id > 0);
        if (empty($productIds)) {
            return [];
        }

        $langId = $this->config->getDefaultLanguageId();
        $idList = implode(',', $productIds);

        $names = $this->fetchNames($idList, $langId);
        $imageCounts = $this->fetchImageCounts($idList);
        $identification = $this->fetchIdentification($idList);
        $categoryLinks = $this->fetchCategoryLinkState($productIds, $shopId);
        $combinationsByProduct = $this->combinationService->getCombinations($productIds, $shopId);
        $offersByProduct = $this->combinationService->getOffersByCombination($productIds, $shopId);

        $results = [];
        foreach ($productIds as $id) {
            $messages = [];
            $status = self::STATUS_READY;
            $name = $names[$id] ?? ('#' . $id);
            $combinations = $combinationsByProduct[$id] ?? [];
            $offers = $offersByProduct[$id] ?? [];
            $blocked = static function (string $message) use ($id, $name): array {
                return ['id_product' => $id, 'name' => $name, 'status' => self::STATUS_BLOCKED, 'messages' => [$message], 'combinations' => []];
            };

            // Blocked: an offer already exists for this product (the whole product,
            // or - with combinations - an active offer covering all of them)
            if (empty($combinations) && isset($offers[0])) {
                $results[$id] = $blocked('An InPost offer already exists for this product.');
                continue;
            }
            if (!empty($combinations) && isset($offers[0]) && $offers[0]['active']) {
                $results[$id] = $blocked('The product has an InPost offer covering all its combinations - close it to list the combinations separately.');
                continue;
            }

            $linkState = $categoryLinks[$id] ?? ['has_link' => false, 'has_active' => false];

            if (!$linkState['has_link']) {
                // No existing mapping. If an InPost hint can supply one, it will be
                // created on "Create Draft" — inform instead of blocking.
                if (empty($hintMappable[$id])) {
                    $results[$id] = $blocked('No mapped InPost category. Map the product category first.');
                    continue;
                }

                $messages[] = 'Found in InPost hints — category will be auto-mapped when you click "Create Draft".';
            } elseif (!$linkState['has_active']) {
                // Warning: category link exists but points only at orphaned categories.
                $status = self::STATUS_WARNING;
                $messages[] = 'Mapped InPost category is orphaned (no longer exists in InPost).';
            }

            // Warning: no images.
            if (($imageCounts[$id] ?? 0) === 0) {
                $status = self::STATUS_WARNING;
                $messages[] = 'Product has no images. Images are required to publish on InPost von Halsky.';
            }

            $ident = $identification[$id] ?? ['ean' => '', 'brand' => '', 'mpn' => ''];
            $categoryAttributes = $linkState['has_link'] ? $this->categoryAttributes($id, $shopId) : null;

            if (!empty($combinations)) {
                // One offer per combination - identification, stock and attribute
                // values are checked per combination
                $results[$id] = [
                    'id_product' => $id,
                    'name' => $name,
                    'status' => $status,
                    'messages' => $messages,
                    'missing_attributes' => [],
                    'combinations' => $this->validateCombinations($id, $combinations, $offers, $ident, $categoryAttributes),
                ];
                continue;
            }

            // Warning: no EAN and no brand + MPN.
            $hasEan = trim((string) $ident['ean']) !== '';
            $hasAlt = trim((string) $ident['brand']) !== '' && trim((string) $ident['mpn']) !== '';
            if (!$hasEan && !$hasAlt) {
                $status = self::STATUS_WARNING;
                $messages[] = 'Product has no EAN and no Brand + Manufacturer Product Number.';
            }

            // Warning: required category attributes PrestaShop gives no value for
            // (the merchant fills them in the draft; an InPost hint may fill some)
            $missingAttributes = $this->requiredAttributesWithoutValue($id, 0, $categoryAttributes);
            if (!empty($missingAttributes)) {
                $status = self::STATUS_WARNING;
            }

            $results[$id] = [
                'id_product' => $id,
                'name' => $name,
                'status' => $status,
                'messages' => $messages,
                'missing_attributes' => $missingAttributes,
                'combinations' => [],
            ];
        }

        return array_values($results);
    }

    /**
     * Status of each combination. Preselected: can be created, has an EAN and stock.
     *
     * @param array<int,array<string,mixed>> $combinations ProductCombinationService::getCombinations() entries
     * @param array<int,array<string,mixed>> $offers Offers of the product by combination
     * @param array<string,string> $ident Product identification (brand, mpn)
     * @param array{0:int,1:array}|null $categoryAttributes
     * @return array<int,array<string,mixed>>
     */
    private function validateCombinations(int $idProduct, array $combinations, array $offers, array $ident, ?array $categoryAttributes): array
    {
        $rows = [];
        foreach ($combinations as $combination) {
            $pa = (int) $combination['id_product_attribute'];
            $row = [
                'id_product_attribute' => $pa,
                'label' => $combination['label'],
                'reference' => $combination['reference'],
                'ean13' => $combination['ean13'],
                'quantity' => $combination['quantity'],
                'status' => self::STATUS_READY,
                'messages' => [],
                'missing_attributes' => [],
                'offer_id' => null,
                'selected' => false,
            ];

            if (isset($offers[$pa])) {
                $row['status'] = self::STATUS_BLOCKED;
                $row['messages'][] = 'An InPost offer already exists for this variant.';
                $row['offer_id'] = $offers[$pa]['offer_id'];
                $rows[] = $row;
                continue;
            }

            // Same as the offer: the combination's own EAN only, its own MPN or the product's
            $eanField = OfferFieldMapping::effective('EAN', \Configuration::get(OfferFieldMapping::configKey('EAN')));
            $mpnField = OfferFieldMapping::effective('MPN', \Configuration::get(OfferFieldMapping::configKey('MPN')));
            $hasEan = trim((string) ($combination[$eanField] ?? '')) !== '';
            $mpn = trim((string) ($combination[$mpnField] ?? '')) ?: trim((string) $ident['mpn']);
            if (!$hasEan && (trim((string) $ident['brand']) === '' || $mpn === '')) {
                $row['status'] = self::STATUS_WARNING;
                $row['messages'][] = 'Variant has no EAN and no Brand + Manufacturer Product Number.';
            }
            if ((int) $combination['quantity'] <= 0) {
                $row['status'] = self::STATUS_WARNING;
                $row['messages'][] = 'Variant is out of stock.';
            }
            $row['missing_attributes'] = $this->requiredAttributesWithoutValue($idProduct, $pa, $categoryAttributes);
            if (!empty($row['missing_attributes'])) {
                $row['status'] = self::STATUS_WARNING;
            }

            $row['selected'] = $hasEan && (int) $combination['quantity'] > 0;
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * InPost category (of the product's default category) and its attribute definitions
     *
     * @return array{0:int,1:array}|null [category mapping id, attributes]
     */
    private function categoryAttributes(int $idProduct, int $shopId): ?array
    {
        $idCategory = (int) $this->db->getValue(
            'SELECT id_category_default FROM `' . $this->prefix . 'product_shop`
            WHERE id_product = ' . (int) $idProduct . ' AND id_shop = ' . (int) $shopId
        );
        $categoryMappingId = $idCategory ? (int) $this->categoryMappingService->getMappedInPostCategoryEntityId($idCategory, $shopId) : 0;
        if (!$categoryMappingId) {
            return null;
        }

        return [
            $categoryMappingId,
            $this->attributeMappingService->getAttributesForCategoryMappingId($categoryMappingId, $this->config->getDefaultLanguageCode()),
        ];
    }

    /**
     * Names of the required category attributes that the mapped PrestaShop
     * features / attribute groups do not fill for the product (or combination)
     *
     * @param array{0:int,1:array}|null $categoryAttributes Result of categoryAttributes()
     * @return string[]
     */
    private function requiredAttributesWithoutValue(int $idProduct, int $idProductAttribute, ?array $categoryAttributes): array
    {
        if ($categoryAttributes === null) {
            return [];
        }

        [$categoryMappingId, $attributes] = $categoryAttributes;
        $resolved = ProductAttributeValueResolver::applicable($this->attributeValueResolver->resolve(
            $idProduct,
            $idProductAttribute,
            $categoryMappingId,
            $this->config->getDefaultLanguageCode(),
            $attributes
        ));

        $missing = [];
        foreach ($attributes as $attribute) {
            if (!empty($attribute['required']) && !isset($resolved[$attribute['code']])) {
                $missing[] = (string) $attribute['label'];
            }
        }

        return $missing;
    }

    /**
     * @return array<int,string>
     */
    private function fetchNames(string $idList, int $langId): array
    {
        $rows = $this->db->executeS(
            'SELECT id_product, name FROM `' . $this->prefix . 'product_lang` '
            . 'WHERE id_product IN (' . $idList . ') AND id_lang = ' . (int) $langId
        ) ?: [];
        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['id_product']] = $row['name'];
        }
        return $map;
    }

    /**
     * @return array<int,int>
     */
    private function fetchImageCounts(string $idList): array
    {
        $rows = $this->db->executeS(
            'SELECT id_product, COUNT(*) AS cnt FROM `' . $this->prefix . 'image` '
            . 'WHERE id_product IN (' . $idList . ') GROUP BY id_product'
        ) ?: [];
        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['id_product']] = (int) $row['cnt'];
        }
        return $map;
    }

    /**
     * EAN + brand + MPN per product, from the fields mapped in the module
     * configuration (product field mapping) - the same the offer is built from.
     *
     * @return array<int,array<string,string>>
     */
    private function fetchIdentification(string $idList): array
    {
        $sources = [];
        foreach (['ean' => 'EAN', 'brand' => 'BRAND', 'mpn' => 'MPN'] as $key => $field) {
            $sources[$key] = OfferFieldMapping::effective($field, \Configuration::get(OfferFieldMapping::configKey($field)));
        }

        $rows = $this->db->executeS(
            'SELECT p.id_product, p.reference, p.mpn, p.ean13, p.upc, p.isbn, p.supplier_reference, m.name AS manufacturer_name '
            . 'FROM `' . $this->prefix . 'product` p '
            . 'LEFT JOIN `' . $this->prefix . 'manufacturer` m ON m.id_manufacturer = p.id_manufacturer '
            . 'WHERE p.id_product IN (' . $idList . ')'
        ) ?: [];
        $map = [];
        foreach ($rows as $row) {
            $id = (int) $row['id_product'];
            foreach ($sources as $key => $source) {
                $map[$id][$key] = $this->identificationValue($id, $row, $source);
            }
        }
        return $map;
    }

    /**
     * Value of a mapped source: a product column, a feature, '' for "none" / other fields
     *
     * @param array<string,mixed> $row Product row of fetchIdentification()
     */
    private function identificationValue(int $idProduct, array $row, string $source): string
    {
        if (strpos($source, ProductFieldReader::FEATURE_PREFIX) === 0) {
            $values = $this->db->executeS(
                'SELECT fvl.value FROM `' . $this->prefix . 'feature_product` fp
                LEFT JOIN `' . $this->prefix . 'feature_value_lang` fvl
                    ON fvl.id_feature_value = fp.id_feature_value AND fvl.id_lang = ' . (int) $this->config->getDefaultLanguageId() . '
                WHERE fp.id_product = ' . $idProduct . ' AND fp.id_feature = ' . (int) substr($source, strlen(ProductFieldReader::FEATURE_PREFIX))
            ) ?: [];

            return trim(implode(', ', array_filter(array_map('trim', array_column($values, 'value')))));
        }

        return array_key_exists($source, $row) ? trim((string) $row[$source]) : '';
    }

    /**
     * For each product: whether it has any InPost category link, and whether any
     * of those links point at an active category.
     *
     * @param int[] $productIds
     * @return array<int,array<string,bool>>
     */
    private function fetchCategoryLinkState(array $productIds, int $shopId): array
    {
        $idList = implode(',', $productIds);
        $rows = $this->db->executeS(
            'SELECT cp.id_product, MAX(cm.is_active) AS has_active, COUNT(cl.link_id) AS link_count '
            . 'FROM `' . $this->prefix . 'category_product` cp '
            . 'INNER JOIN `' . $this->prefix . 'inpost_category_link` cl ON cl.prestashop_category_id = cp.id_category '
            . 'INNER JOIN `' . $this->prefix . 'inpost_category_mapping` cm ON cl.mapping_id = cm.entity_id '
            . 'WHERE cp.id_product IN (' . $idList . ') '
            . 'AND (cl.shop_id = ' . (int) $shopId . ' OR cl.shop_id IS NULL) '
            . 'GROUP BY cp.id_product'
        ) ?: [];

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['id_product']] = [
                'has_link' => ((int) $row['link_count']) > 0,
                'has_active' => ((int) $row['has_active']) === 1,
            ];
        }
        return $map;
    }
}
