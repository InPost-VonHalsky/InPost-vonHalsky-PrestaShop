<?php
/**
 * ValidEan Constraint
 *
 * Custom Symfony validator constraint for EAN-8 and EAN-13 codes with checksum validation.
 *
 * @package Inpost\VonHalsky\Validator\Constraints
 * @author InPost von Halsky
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Validator\Constraints;

use Symfony\Component\Validator\Constraint;

/**
 * @Annotation
 * @Target({"PROPERTY", "METHOD", "ANNOTATION"})
 */
class ValidEan extends Constraint
{
    /**
     * Generic fallback message (kept for backward compatibility).
     */
    public $message = 'Invalid EAN code "{{ value }}". Checksum validation failed. Please verify the EAN is correct.';

    /**
     * Message when the value contains non-digit characters.
     */
    public $digitsMessage = 'Invalid EAN/GTIN format: only digits allowed (0-9).';

    /**
     * Message when the value has an unsupported length.
     */
    public $lengthMessage = 'Invalid EAN/GTIN length: supported lengths are 8 (EAN-8), 12 (UPC-A) or 13 (EAN-13).';

    /**
     * Message when the check digit is wrong.
     */
    public $checksumMessage = 'Invalid EAN/GTIN checksum. The check digit is incorrect.';

    /**
     * Get validator class name
     *
     * @return string
     */
    public function validatedBy(): string
    {
        return ValidEanValidator::class;
    }
}
