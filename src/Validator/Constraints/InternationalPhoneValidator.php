<?php
/**
 * InternationalPhoneValidator
 *
 * Validator for InternationalPhone constraint.
 * Validates E.164 international phone format: +[country code][number]
 *
 * @package Inpost\VonHalsky\Validator\Constraints
 * @author SII Poland
 * @copyright 2025 SII Poland
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Validator\Constraints;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Inpost\VonHalsky\Service\Util\ValidationUtils;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

class InternationalPhoneValidator extends ConstraintValidator
{
    /**
     * Validate phone number
     *
     * @param mixed $value The phone number to validate
     * @param Constraint $constraint The constraint
     * @return void
     */
    public function validate($value, Constraint $constraint): void
    {
        if (!$constraint instanceof InternationalPhone) {
            throw new UnexpectedTypeException($constraint, InternationalPhone::class);
        }

        // Empty values are valid (use NotBlank constraint separately if required)
        if (null === $value || '' === $value) {
            return;
        }

        if (!is_string($value)) {
            throw new UnexpectedValueException($value, 'string');
        }

        // Validate phone format using shared utility
        if (!ValidationUtils::validatePhone($value)) {
            $this->context->buildViolation($constraint->message)
                ->setParameter('{{ value }}', $value)
                ->addViolation();
        }
    }
}
