<?php
/**
 * InternationalPhone Constraint
 *
 * Validates international phone number format.
 * Accepts E.164 format: +[country code][number]
 * Example: +48123456789, +1234567890
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
class InternationalPhone extends Constraint
{
    /**
     * Error message for invalid phone format
     */
    public $message = 'Invalid international phone number "{{ value }}". Format: +[country code][number] (e.g., +48123456789).';

    /**
     * Get validator class name
     *
     * @return string
     */
    public function validatedBy(): string
    {
        return InternationalPhoneValidator::class;
    }
}
