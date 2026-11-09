<?php
/**
 * EanOrAlternativeIdentification Constraint
 *
 * Class-level constraint on an Offer: requires either an EAN on the product or
 * a complete alternative identification (brand + manufacturer product number).
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
class EanOrAlternativeIdentification extends Constraint
{
    /**
     * Error message when neither an EAN nor brand + MPN is present.
     */
    public $message = 'Fill in EAN or provide both Brand and Manufacturer Product Number.';

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
        return EanOrAlternativeIdentificationValidator::class;
    }
}
