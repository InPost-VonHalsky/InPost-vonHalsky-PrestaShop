<?php
/**
 * DictionaryValueMatcher
 *
 * Matches a free-text value (PrestaShop feature/attribute value, hint value)
 * against the options of an InPost dictionary attribute: by label, then by
 * option code, ignoring case and repeated whitespace.
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Service\Mapping;

final class DictionaryValueMatcher
{
    /**
     * @param array<int,array{value:string,label:string}> $options Active dictionary options
     * @return string|null Option code
     */
    public static function match(array $options, string $value): ?string
    {
        $needle = self::normalize($value);
        if ($needle === '') {
            return null;
        }

        foreach ($options as $option) {
            if (self::normalize((string) $option['label']) === $needle) {
                return (string) $option['value'];
            }
        }
        foreach ($options as $option) {
            if (self::normalize((string) $option['value']) === $needle) {
                return (string) $option['value'];
            }
        }

        return null;
    }

    /**
     * @param array<int,array{value:string,label:string}> $options
     */
    public static function hasCode(array $options, string $code): bool
    {
        foreach ($options as $option) {
            if ((string) $option['value'] === $code) {
                return true;
            }
        }

        return false;
    }

    public static function normalize(string $value): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $value)));
    }

    private function __construct()
    {
    }
}
