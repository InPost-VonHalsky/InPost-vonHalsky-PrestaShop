<?php
/**
 * AttributeValueConverter
 *
 * Converts a PrestaShop value into what the InPost attribute type accepts:
 * dictionary option code, integer/decimal number, ISO date, URL or text.
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Service\Mapping;

final class AttributeValueConverter
{
    public const STATUS_OK = 'ok';
    /** Dictionary value with no matching option */
    public const STATUS_UNMATCHED = 'unmatched';
    /** Value not convertible to the attribute type (e.g. no number in the text) */
    public const STATUS_INVALID = 'invalid';

    /** InPost limit of a single attribute value */
    private const MAX_LENGTH = 1024;

    /**
     * @param array<string,mixed> $attribute Definition from AttributeMappingService::getAttributesForCategoryMappingId()
     * @param string $rawValue Value label in PrestaShop
     * @param string|null $mappedCode Dictionary option code mapped to this PS value (value map), if any
     * @return array{status:string, value:?string}
     */
    public static function convert(array $attribute, string $rawValue, ?string $mappedCode = null): array
    {
        $raw = trim($rawValue);

        switch ($attribute['type'] ?? '') {
            case 'DICTIONARY':
                $options = $attribute['dictionary_values'] ?? [];
                if ($mappedCode !== null && DictionaryValueMatcher::hasCode($options, $mappedCode)) {
                    return self::ok($mappedCode);
                }
                $code = DictionaryValueMatcher::match($options, $raw);

                return $code !== null ? self::ok($code) : self::fail(self::STATUS_UNMATCHED);

            case 'NUMERIC':
                // First integer in the text: "5 lat" -> 5, "ok. 3 kg" -> 3
                return preg_match('/-?\d+/', $raw, $m) ? self::ok((string) (int) $m[0]) : self::fail(self::STATUS_INVALID);

            case 'NUMERIC_FLOAT':
                if (!preg_match('/-?\d+(?:[.,]\d+)?/', $raw, $m)) {
                    return self::fail(self::STATUS_INVALID);
                }

                return self::ok(str_replace(',', '.', $m[0]));

            case 'DATE':
                $timestamp = strtotime($raw);

                return $timestamp !== false ? self::ok(date('Y-m-d', $timestamp)) : self::fail(self::STATUS_INVALID);

            case 'URL':
                return filter_var($raw, FILTER_VALIDATE_URL) ? self::ok($raw) : self::fail(self::STATUS_INVALID);

            default:
                return $raw !== '' ? self::ok(mb_substr($raw, 0, self::MAX_LENGTH)) : self::fail(self::STATUS_INVALID);
        }
    }

    private static function ok(string $value): array
    {
        return ['status' => self::STATUS_OK, 'value' => $value];
    }

    private static function fail(string $status): array
    {
        return ['status' => $status, 'value' => null];
    }

    private function __construct()
    {
    }
}
