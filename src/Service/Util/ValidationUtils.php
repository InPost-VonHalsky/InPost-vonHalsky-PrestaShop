<?php
/**
 * ValidationUtils
 *
 * Utility class providing static validation methods for common data formats.
 * Single source of truth for validation logic used across the module.
 *
 * @package Inpost\VonHalsky\Service\Util
 * @author SII Poland
 * @copyright 2025 SII Poland
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Service\Util;

class ValidationUtils
{
    /**
     * Supported EAN/GTIN lengths. GTIN-14 is intentionally excluded: the
     * inpost_offer_product.ean column is VARCHAR(13) and cannot store it.
     */
    public const SUPPORTED_EAN_LENGTHS = [8, 12, 13];

    public const EAN_MESSAGE_REQUIRED = 'Fill in EAN or provide both Brand and Manufacturer Product Number.';
    public const EAN_MESSAGE_DIGITS = 'Invalid EAN/GTIN format: only digits allowed (0-9).';
    public const EAN_MESSAGE_LENGTH = 'Invalid EAN/GTIN length: supported lengths are 8 (EAN-8), 12 (UPC-A) or 13 (EAN-13).';
    public const EAN_MESSAGE_CHECKSUM = 'Invalid EAN/GTIN checksum. The check digit is incorrect.';

    /**
     * Validate an EAN and return the specific failure message, or null when valid.
     *
     * Requiredness and format are separate concerns: when $required is false an
     * empty value is treated as valid (the caller decides requiredness elsewhere).
     *
     * @param string|null $ean      EAN/GTIN code (may be null/empty)
     * @param bool        $required Whether an empty value is an error
     * @return string|null Null when valid, otherwise the failure message
     */
    public static function validateEanDetailed(?string $ean, bool $required): ?string
    {
        // Normalize: strip whitespace and hyphens
        $ean = preg_replace('/[\s\-]/', '', (string) $ean);

        if ($ean === '') {
            return $required ? self::EAN_MESSAGE_REQUIRED : null;
        }

        if (!ctype_digit($ean)) {
            return self::EAN_MESSAGE_DIGITS;
        }

        if (!in_array(strlen($ean), self::SUPPORTED_EAN_LENGTHS, true)) {
            return self::EAN_MESSAGE_LENGTH;
        }

        if (!self::isEanChecksumValid($ean)) {
            return self::EAN_MESSAGE_CHECKSUM;
        }

        return null;
    }

    /**
     * Modulo 10 check-digit validation. Walks the payload digits right to left
     * with a multiplier starting at 3 and toggling 3->1, which is correct for
     * every GTIN length (8/12/13/14) from a single rule.
     *
     * @param string $ean Digits-only EAN/GTIN
     * @return bool
     */
    private static function isEanChecksumValid(string $ean): bool
    {
        $digits = str_split($ean);
        $checkDigit = (int) array_pop($digits);

        $sum = 0;
        $multiplier = 3;
        foreach (array_reverse($digits) as $digit) {
            $sum += (int) $digit * $multiplier;
            $multiplier = $multiplier === 3 ? 1 : 3;
        }

        return $checkDigit === (10 - ($sum % 10)) % 10;
    }

    /**
     * Whether a product can be identified without an EAN, i.e. it has both a
     * brand and a manufacturer product number (brand + MPN).
     *
     * @param string|null $brand
     * @param string|null $mpn
     * @return bool
     */
    public static function hasAlternativeIdentification(?string $brand, ?string $mpn): bool
    {
        return trim($brand ?? '') !== '' && trim($mpn ?? '') !== '';
    }

    /**
     * Validate phone number in E.164 international format
     *
     * Format requirements:
     * - Starts with + followed by country code (1-3 digits, cannot start with 0)
     * - Total length: 2-15 digits after the +
     * - Examples: +48123456789, +1234567890, +441234567890
     *
     * @param string $phone Phone number to validate
     * @return bool True if valid E.164 format, false otherwise
     */
    public static function validatePhone(string $phone): bool
    {
        // Remove whitespace
        $phone = preg_replace('/\s+/', '', $phone);

        // E.164 format: + followed by 1-9 (country codes don't start with 0)
        // then 1-14 more digits (total 2-15 digits)
        return preg_match('/^\+[1-9]\d{1,14}$/', $phone) === 1;
    }
}
