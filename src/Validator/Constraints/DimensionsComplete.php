<?php
/**
 * DimensionsComplete Constraint
 *
 * Validates that product dimensions (width, height, length, weight) are either:
 * - All filled (complete dimensions)
 * - All empty (no dimensions)
 * This prevents partial dimension data.
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
 * @Target({"CLASS", "ANNOTATION"})
 */
class DimensionsComplete extends Constraint
{
    /**
     * Error message for incomplete dimensions
     */
    public $message = 'Product dimensions must be either all filled or all empty. Please provide: {{ missing_fields }}.';

    /**
     * Fields to validate together
     */
    public array $fields = ['dimensionWidth', 'dimensionHeight', 'dimensionLength', 'dimensionWeight'];

    /**
     * Get targets for this constraint
     *
     * @return string
     */
    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }

    /**
     * Get validator class name
     *
     * @return string
     */
    public function validatedBy(): string
    {
        return DimensionsCompleteValidator::class;
    }
}
