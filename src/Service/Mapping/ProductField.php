<?php
/**
 * ProductField
 *
 * Built-in PrestaShop product fields (reference, MPN, EAN, weight...) usable as
 * a value source: for the offer product fields (module configuration > product
 * field mapping) and for InPost category attributes ("Kod producenta" <- MPN).
 *
 * The numeric id is what inpost_category_attribute.ps_source_id stores for
 * ps_source_type = AttributeSource::PRODUCT_FIELD - never renumber.
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Service\Mapping;

final class ProductField
{
    /** Plain text value */
    public const KIND_TEXT = 'text';
    /** Length in the shop dimension unit (PS_DIMENSION_UNIT) */
    public const KIND_DIMENSION = 'dimension';
    /** Weight in the shop weight unit (PS_WEIGHT_UNIT) */
    public const KIND_WEIGHT = 'weight';
    /** Whole number of days - no built-in field, only features */
    public const KIND_DAYS = 'days';

    /**
     * id => key (Product property), label, kind, whether a combination has its own
     * value, whether it can feed a category attribute, lowercase names of InPost
     * attributes it is the obvious source for
     */
    private const FIELDS = [
        1 => ['key' => 'reference', 'label' => 'Reference (product code)', 'kind' => self::KIND_TEXT, 'combination' => true, 'attribute' => true,
            'aliases' => ['indeks', 'symbol', 'kod produktu', 'numer katalogowy', 'sku', 'reference']],
        2 => ['key' => 'mpn', 'label' => 'Manufacturer code (MPN)', 'kind' => self::KIND_TEXT, 'combination' => true, 'attribute' => true,
            'aliases' => ['kod producenta', 'mpn', 'numer producenta', 'kod katalogowy producenta', 'part number']],
        3 => ['key' => 'ean13', 'label' => 'EAN-13 barcode', 'kind' => self::KIND_TEXT, 'combination' => true, 'attribute' => true,
            'aliases' => ['ean', 'gtin', 'kod ean', 'ean13']],
        4 => ['key' => 'upc', 'label' => 'UPC barcode', 'kind' => self::KIND_TEXT, 'combination' => true, 'attribute' => true,
            'aliases' => ['upc', 'kod upc']],
        5 => ['key' => 'isbn', 'label' => 'ISBN', 'kind' => self::KIND_TEXT, 'combination' => true, 'attribute' => true,
            'aliases' => ['isbn']],
        6 => ['key' => 'supplier_reference', 'label' => 'Supplier reference', 'kind' => self::KIND_TEXT, 'combination' => true, 'attribute' => true,
            'aliases' => ['kod dostawcy']],
        7 => ['key' => 'manufacturer_name', 'label' => 'Brand (manufacturer)', 'kind' => self::KIND_TEXT, 'combination' => false, 'attribute' => true,
            'aliases' => ['marka', 'producent', 'brand']],
        8 => ['key' => 'name', 'label' => 'Product name', 'kind' => self::KIND_TEXT, 'combination' => false, 'attribute' => true,
            'aliases' => []],
        9 => ['key' => 'weight', 'label' => 'Weight', 'kind' => self::KIND_WEIGHT, 'combination' => true, 'attribute' => true,
            'aliases' => ['waga', 'waga produktu', 'masa']],
        10 => ['key' => 'width', 'label' => 'Width', 'kind' => self::KIND_DIMENSION, 'combination' => false, 'attribute' => true,
            'aliases' => ['szerokość', 'szerokość produktu']],
        11 => ['key' => 'height', 'label' => 'Height', 'kind' => self::KIND_DIMENSION, 'combination' => false, 'attribute' => true,
            'aliases' => ['wysokość', 'wysokość produktu']],
        12 => ['key' => 'depth', 'label' => 'Depth', 'kind' => self::KIND_DIMENSION, 'combination' => false, 'attribute' => true,
            'aliases' => ['głębokość', 'głębokość produktu', 'długość', 'długość produktu']],
        13 => ['key' => 'description', 'label' => 'Description', 'kind' => self::KIND_TEXT, 'combination' => false, 'attribute' => false,
            'aliases' => []],
        14 => ['key' => 'description_short', 'label' => 'Summary (short description)', 'kind' => self::KIND_TEXT, 'combination' => false, 'attribute' => false,
            'aliases' => []],
    ];

    /**
     * @return array<int,array{key:string,label:string,kind:string,combination:bool,attribute:bool,aliases:string[]}>
     */
    public static function all(): array
    {
        return self::FIELDS;
    }

    /**
     * @return array{key:string,label:string,kind:string,combination:bool,attribute:bool,aliases:string[]}|null
     */
    public static function byId(int $id): ?array
    {
        return self::FIELDS[$id] ?? null;
    }

    /**
     * @return array{key:string,label:string,kind:string,combination:bool,attribute:bool,aliases:string[]}|null
     */
    public static function byKey(string $key): ?array
    {
        foreach (self::FIELDS as $field) {
            if ($field['key'] === $key) {
                return $field;
            }
        }

        return null;
    }

    public static function idOf(string $key): ?int
    {
        foreach (self::FIELDS as $id => $field) {
            if ($field['key'] === $key) {
                return $id;
            }
        }

        return null;
    }

    private function __construct()
    {
    }
}
