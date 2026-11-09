<?php
/**
 * ProductFieldReader
 *
 * Reads a value from a PrestaShop product (or combination) by source:
 *  - a built-in field key (ProductField: reference, mpn, ean13, weight, ...) -
 *    a combination's own value wins, the product's is the fallback;
 *  - "feature:<id_feature>" - the product's value(s) of a feature.
 * Lengths / weights can be read converted to millimetres / grams (InPost units).
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Service\Offer;

use Combination;
use Configuration;
use Inpost\VonHalsky\Helper\Config;
use Inpost\VonHalsky\Service\Mapping\ProductField;
use Inpost\VonHalsky\Service\Mapping\PsAttributeSourceProvider;
use Manufacturer;
use Product;

class ProductFieldReader
{
    /** Source meaning "do not fill this field" */
    public const NONE = 'none';
    /** Prefix of a feature source: "feature:12" */
    public const FEATURE_PREFIX = 'feature:';

    private const LENGTH_TO_MM = ['mm' => 1.0, 'cm' => 10.0, 'm' => 1000.0, 'in' => 25.4];
    private const WEIGHT_TO_G = ['g' => 1.0, 'kg' => 1000.0, 'lb' => 453.59237, 'oz' => 28.349523125];

    private PsAttributeSourceProvider $sourceProvider;
    private Config $config;

    public function __construct(PsAttributeSourceProvider $sourceProvider, Config $config)
    {
        $this->sourceProvider = $sourceProvider;
        $this->config = $config;
    }

    /**
     * Product (default language and shop) and the combination, null when missing.
     * Not a "full" product: that one computes prices and needs an employee or cart
     * in the context - missing in cron / CLI runs. The brand is filled separately.
     *
     * @return array{0:Product,1:?Combination}|null
     */
    public function load(int $idProduct, int $idProductAttribute = 0): ?array
    {
        $product = new Product($idProduct, false, $this->config->getDefaultLanguageId(), $this->config->getDefaultShopId());
        if (!$product->id) {
            return null;
        }
        if (empty($product->manufacturer_name) && (int) $product->id_manufacturer > 0) {
            $product->manufacturer_name = (string) Manufacturer::getNameById((int) $product->id_manufacturer);
        }

        $combination = null;
        if ($idProductAttribute > 0) {
            $combination = new Combination($idProductAttribute, null, $this->config->getDefaultShopId());
            if (!$combination->id || (int) $combination->id_product !== $idProduct) {
                $combination = null;
            }
        }

        return [$product, $combination];
    }

    /**
     * Trimmed value of a source, '' when empty / unknown / "none"
     */
    public function read(Product $product, ?Combination $combination, string $source): string
    {
        if ($source === '' || $source === self::NONE) {
            return '';
        }

        if (strpos($source, self::FEATURE_PREFIX) === 0) {
            $values = $this->sourceProvider->getProductFeatureValues((int) $product->id, (int) substr($source, strlen(self::FEATURE_PREFIX)));

            return implode(', ', array_column($values, 'label'));
        }

        $field = ProductField::byKey($source);
        if ($field === null || !property_exists($product, $source)) {
            return '';
        }

        $productValue = self::scalar($product->{$source});

        if ($combination !== null && $field['combination'] && property_exists($combination, $source)) {
            $own = self::scalar($combination->{$source});
            if ($field['kind'] === ProductField::KIND_WEIGHT) {
                // A combination's weight is an impact on the product weight
                $total = (float) str_replace(',', '.', $productValue) + (float) str_replace(',', '.', $own);

                return $total > 0 ? (string) $total : '';
            }
            if ($own !== '') {
                return $own;
            }
        }

        // PrestaShop stores missing numbers as 0
        if ($field['kind'] !== ProductField::KIND_TEXT && (float) str_replace(',', '.', $productValue) <= 0) {
            return '';
        }

        return $productValue;
    }

    /**
     * Length in millimetres, null when empty / not a positive number
     */
    public function readMillimetres(Product $product, ?Combination $combination, string $source): ?int
    {
        return self::convert($this->read($product, $combination, $source), self::LENGTH_TO_MM, (string) Configuration::get('PS_DIMENSION_UNIT'));
    }

    /**
     * Weight in grams, null when empty / not a positive number
     */
    public function readGrams(Product $product, ?Combination $combination, string $source): ?int
    {
        return self::convert($this->read($product, $combination, $source), self::WEIGHT_TO_G, (string) Configuration::get('PS_WEIGHT_UNIT'));
    }

    /**
     * "12,5", "12.5 cm", "250g" -> amount in the target unit; a unit in the value
     * wins over the shop unit
     *
     * @param array<string,float> $factors unit => factor to the target unit
     */
    private static function convert(string $raw, array $factors, string $shopUnit): ?int
    {
        if (!preg_match('/(\d+(?:[.,]\d+)?)\s*([a-z]+)?/i', $raw, $match)) {
            return null;
        }

        $amount = (float) str_replace(',', '.', $match[1]);
        $unit = strtolower($match[2] ?? '');
        if (!isset($factors[$unit])) {
            $unit = strtolower(trim($shopUnit));
        }
        $result = (int) round($amount * ($factors[$unit] ?? 1.0));

        return $result > 0 ? $result : null;
    }

    /**
     * @param mixed $value Product property (multilang ones are strings once loaded with a language)
     */
    private static function scalar($value): string
    {
        if (is_array($value)) {
            $value = reset($value);
        }

        return is_scalar($value) ? trim((string) $value) : '';
    }
}
