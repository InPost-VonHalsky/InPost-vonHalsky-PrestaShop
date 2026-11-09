<?php
/**
 * OfferFieldMapping
 *
 * Which PrestaShop data fills each product field of a new offer (module
 * configuration > Offer Defaults > product field mapping). Each field has a
 * configuration key INPOSTSA_OFFER_ATTRIBUTE_MAPPING_<FIELD> holding a source
 * (ProductFieldReader: field key, "feature:<id>" or "none"); an empty value means
 * the default source.
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Service\Offer;

use Inpost\VonHalsky\Service\Mapping\ProductField;

final class OfferFieldMapping
{
    public const CONFIG_PREFIX = 'INPOSTSA_OFFER_ATTRIBUTE_MAPPING_';

    /**
     * field => label, default source, kind of data it takes, whether it can be left empty
     */
    private const FIELDS = [
        'NAME' => ['label' => 'Product name', 'default' => 'name', 'kind' => ProductField::KIND_TEXT, 'optional' => false],
        'DESCRIPTION' => ['label' => 'Description', 'default' => 'description', 'kind' => ProductField::KIND_TEXT, 'optional' => false],
        'BRAND' => ['label' => 'Brand', 'default' => 'manufacturer_name', 'kind' => ProductField::KIND_TEXT, 'optional' => true],
        'SKU' => ['label' => 'SKU', 'default' => 'reference', 'kind' => ProductField::KIND_TEXT, 'optional' => false],
        'EAN' => ['label' => 'EAN', 'default' => 'ean13', 'kind' => ProductField::KIND_TEXT, 'optional' => true],
        'MPN' => ['label' => 'Manufacturer product number (MPN)', 'default' => 'mpn', 'kind' => ProductField::KIND_TEXT, 'optional' => true],
        'MODEL' => ['label' => 'Model', 'default' => 'reference', 'kind' => ProductField::KIND_TEXT, 'optional' => true],
        'SUPER_MODEL' => ['label' => 'Super model', 'default' => ProductFieldReader::NONE, 'kind' => ProductField::KIND_TEXT, 'optional' => true],
        'WIDTH' => ['label' => 'Width', 'default' => 'width', 'kind' => ProductField::KIND_DIMENSION, 'optional' => true],
        'HEIGHT' => ['label' => 'Height', 'default' => 'height', 'kind' => ProductField::KIND_DIMENSION, 'optional' => true],
        'LENGTH' => ['label' => 'Length', 'default' => 'depth', 'kind' => ProductField::KIND_DIMENSION, 'optional' => true],
        'WEIGHT' => ['label' => 'Weight', 'default' => 'weight', 'kind' => ProductField::KIND_WEIGHT, 'optional' => true],
        'DELIVERY_DAYS' => ['label' => 'Delivery days', 'default' => ProductFieldReader::NONE, 'kind' => ProductField::KIND_DAYS, 'optional' => true],
    ];

    /**
     * @return array<string,array{label:string,default:string,kind:string,optional:bool}>
     */
    public static function all(): array
    {
        return self::FIELDS;
    }

    public static function configKey(string $field): string
    {
        return self::CONFIG_PREFIX . $field;
    }

    /**
     * Source in effect: the configured one, the default when not configured
     *
     * @param mixed $configured Configuration value
     */
    public static function effective(string $field, $configured): string
    {
        $configured = is_string($configured) ? trim($configured) : '';

        return $configured !== '' ? $configured : (self::FIELDS[$field]['default'] ?? ProductFieldReader::NONE);
    }

    /**
     * Whether a submitted source is allowed for the field: a built-in field of a
     * fitting kind, a feature, or "none" for optional fields
     */
    public static function isAllowed(string $field, string $source): bool
    {
        $definition = self::FIELDS[$field] ?? null;
        if ($definition === null) {
            return false;
        }
        if ($source === ProductFieldReader::NONE) {
            return $definition['optional'];
        }
        if (preg_match('/^' . preg_quote(ProductFieldReader::FEATURE_PREFIX, '/') . '\d+$/', $source)) {
            return true;
        }

        $productField = ProductField::byKey($source);

        return $productField !== null && self::kindFits($definition['kind'], $productField['kind']);
    }

    /**
     * Built-in fields offered for a field of a kind: text fields take text fields,
     * lengths take lengths, weight takes weight
     */
    public static function kindFits(string $fieldKind, string $sourceKind): bool
    {
        return $fieldKind === ProductField::KIND_TEXT
            ? $sourceKind === ProductField::KIND_TEXT
            : $sourceKind === $fieldKind;
    }

    private function __construct()
    {
    }
}
