<?php
/**
 * ProductAttributeValueResolver
 *
 * Values of an InPost category's attributes for one PrestaShop product (or one
 * combination), read from the feature / attribute group each attribute is
 * mapped to and converted to the attribute type.
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Service\Offer;

use Inpost\VonHalsky\Service\Mapping\AttributeMappingService;
use Inpost\VonHalsky\Service\Mapping\AttributeSource;
use Inpost\VonHalsky\Service\Mapping\AttributeValueConverter;
use Inpost\VonHalsky\Service\Mapping\ProductField;
use Inpost\VonHalsky\Service\Mapping\PsAttributeSourceProvider;

class ProductAttributeValueResolver
{
    /** Value found and converted */
    public const STATUS_OK = 'ok';
    /** The product has no value for the mapped feature / attribute group */
    public const STATUS_MISSING = 'missing';
    /** PS value does not match any option of the InPost dictionary */
    public const STATUS_UNMATCHED = AttributeValueConverter::STATUS_UNMATCHED;
    /** PS value not convertible to the attribute type */
    public const STATUS_INVALID = AttributeValueConverter::STATUS_INVALID;
    /** Attribute group mapped, but the product has several combinations and the offer covers all of them */
    public const STATUS_HAS_COMBINATIONS = 'has_combinations';

    private AttributeMappingService $attributeMappingService;
    private PsAttributeSourceProvider $sourceProvider;
    private ProductFieldReader $fieldReader;

    public function __construct(
        AttributeMappingService $attributeMappingService,
        PsAttributeSourceProvider $sourceProvider,
        ProductFieldReader $fieldReader
    ) {
        $this->attributeMappingService = $attributeMappingService;
        $this->sourceProvider = $sourceProvider;
        $this->fieldReader = $fieldReader;
    }

    /**
     * Resolve every mapped attribute of the category for the product.
     *
     * Entries use the HintAttributeMapper::map() shape (attribute_id,
     * inpost_attribute_id, label, type, value) plus: values (all values, for
     * multi-value attributes), status (STATUS_*), raw_values (PS labels),
     * source_type and source_name. Unmapped attributes are not returned.
     *
     * @param int $idProductAttribute Combination id, 0 for the product itself
     * @param array|null $categoryAttributes Definitions already loaded by the caller
     * @return array<string,array<string,mixed>> Keyed by InPost attribute UUID
     */
    public function resolve(int $idProduct, int $idProductAttribute, int $categoryMappingId, string $language, ?array $categoryAttributes = null): array
    {
        $categoryAttributes = $categoryAttributes ?? $this->attributeMappingService->getAttributesForCategoryMappingId($categoryMappingId, $language);

        $combinationId = null;
        $loadedProduct = null;
        $result = [];
        foreach ($categoryAttributes as $attribute) {
            $sourceType = $attribute['ps_source_type'] ?? null;
            $sourceId = (int) ($attribute['ps_source_id'] ?? 0);
            if (!AttributeSource::isValid($sourceType) || $sourceId <= 0) {
                continue;
            }

            $entry = [
                'attribute_id' => (int) $attribute['id'],
                'inpost_attribute_id' => (string) $attribute['code'],
                'label' => $attribute['label'],
                'type' => $attribute['type'],
                'value' => null,
                'values' => [],
                'status' => self::STATUS_MISSING,
                'raw_values' => [],
                'source_type' => $sourceType,
                'source_name' => $attribute['ps_source_name'] ?? null,
            ];

            if ($sourceType === AttributeSource::FEATURE) {
                $psValues = $this->sourceProvider->getProductFeatureValues($idProduct, $sourceId);
            } elseif ($sourceType === AttributeSource::PRODUCT_FIELD) {
                // Built-in field: the combination's own value (reference, MPN, EAN...), the product's otherwise
                $field = ProductField::byId($sourceId);
                $loadedProduct = $loadedProduct ?? ($this->fieldReader->load($idProduct, $idProductAttribute) ?? false);
                $value = $field !== null && $loadedProduct ? $this->fieldReader->read($loadedProduct[0], $loadedProduct[1], $field['key']) : '';
                $psValues = $value !== '' ? [['id' => 0, 'label' => $value]] : [];
            } else {
                $combinationId = $combinationId ?? $this->resolveCombinationId($idProduct, $idProductAttribute);
                if ($combinationId === null) {
                    $entry['status'] = self::STATUS_HAS_COMBINATIONS;
                    $result[$entry['inpost_attribute_id']] = $entry;
                    continue;
                }
                $value = $combinationId > 0 ? $this->sourceProvider->getCombinationAttributeValue($combinationId, $sourceId) : null;
                $psValues = $value !== null ? [$value] : [];
            }

            $result[$entry['inpost_attribute_id']] = $this->convertValues($entry, $attribute, $psValues);
        }

        return $result;
    }

    /**
     * Only entries that can be put on an offer
     *
     * @param array<string,array<string,mixed>> $resolved Result of resolve()
     * @return array<string,array<string,mixed>>
     */
    public static function applicable(array $resolved): array
    {
        return array_filter($resolved, static function (array $entry): bool {
            return $entry['status'] === self::STATUS_OK;
        });
    }

    /**
     * @param array<int,array{id:int,label:string}> $psValues
     */
    private function convertValues(array $entry, array $attribute, array $psValues): array
    {
        if (empty($psValues)) {
            return $entry;
        }

        // Several values only where InPost accepts them - otherwise the first one
        if (empty($attribute['multiple'])) {
            $psValues = array_slice($psValues, 0, 1);
        }

        $valueMap = $attribute['type'] === 'DICTIONARY'
            ? $this->attributeMappingService->getValueMap((int) $attribute['id'], (string) $entry['source_type'])
            : [];

        $status = self::STATUS_OK;
        foreach ($psValues as $psValue) {
            $entry['raw_values'][] = $psValue['label'];
            $converted = AttributeValueConverter::convert($attribute, $psValue['label'], $valueMap[$psValue['id']] ?? null);
            if ($converted['status'] !== AttributeValueConverter::STATUS_OK) {
                $status = $converted['status'];
                continue;
            }
            if (!in_array($converted['value'], $entry['values'], true)) {
                $entry['values'][] = $converted['value'];
            }
        }

        // One unconvertible value marks the whole attribute, so the merchant sees it
        // instead of silently losing that value
        $entry['status'] = $status;
        if ($status === self::STATUS_OK) {
            $entry['value'] = $entry['values'][0] ?? null;
        }

        return $entry;
    }

    /**
     * Combination to read attribute values from: the given one, or the only
     * combination of the product; 0 when the product has none. Null when it has
     * several (the offer then covers all of them and has no single value).
     */
    private function resolveCombinationId(int $idProduct, int $idProductAttribute): ?int
    {
        if ($idProductAttribute > 0) {
            return $idProductAttribute;
        }

        $ids = $this->sourceProvider->getCombinationIds($idProduct, 2);

        if (count($ids) > 1) {
            return null;
        }

        return $ids[0] ?? 0;
    }
}
