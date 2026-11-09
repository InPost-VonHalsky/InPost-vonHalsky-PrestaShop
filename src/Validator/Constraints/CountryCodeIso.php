<?php
/**
 * CountryCodeIso Constraint
 *
 * Validates ISO 3166-1 alpha-2 country code format.
 * Accepts 2-letter uppercase country codes (e.g., PL, DE, GB, FR).
 *
 * @package Inpost\VonHalsky\Validator\Constraints
 * @author SII Poland
 * @copyright 2025 SII Poland
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Validator\Constraints;

use Symfony\Component\Validator\Constraint;

/**
 * @Annotation
 * @Target({"PROPERTY", "METHOD", "ANNOTATION"})
 */
class CountryCodeIso extends Constraint
{
    /**
     * Error message for invalid country code
     */
    public $message = 'Invalid country code "{{ value }}". Must be a valid ISO 3166-1 alpha-2 code (e.g., PL, DE, GB).';

    /**
     * Get validator class name
     *
     * @return string
     */
    public function validatedBy(): string
    {
        return CountryCodeIsoValidator::class;
    }
}
