<?php
/**
 * EanOrAlternativeIdentificationValidator
 *
 * Validator for the EanOrAlternativeIdentification class constraint. Reads the
 * offer's product and requires either an EAN or brand + manufacturer product
 * number. The violation is placed on the EAN field.
 *
 * @package Inpost\VonHalsky\Validator\Constraints
 * @author SII Poland
 * @copyright 2025 SII Poland
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Validator\Constraints;

use Inpost\VonHalsky\Entity\Offer;
use Inpost\VonHalsky\Service\Util\ValidationUtils;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

class EanOrAlternativeIdentificationValidator extends ConstraintValidator
{
    /**
     * @param mixed $value The Offer being validated
     * @param Constraint $constraint
     * @return void
     */
    public function validate($value, Constraint $constraint): void
    {
        if (!$constraint instanceof EanOrAlternativeIdentification) {
            throw new UnexpectedTypeException($constraint, EanOrAlternativeIdentification::class);
        }

        if (!$value instanceof Offer) {
            return;
        }

        $product = $value->getProduct();
        if ($product === null) {
            return;
        }

        $ean = trim((string) $product->getEan());
        if ($ean !== '') {
            return;
        }

        if (ValidationUtils::hasAlternativeIdentification(
            $product->getBrand(),
            $product->getManufacturerProductNumber()
        )) {
            return;
        }

        $this->context->buildViolation($constraint->message)
            ->atPath('product.ean')
            ->addViolation();
    }
}
