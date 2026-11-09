<?php
/**
 * ValidEan Validator
 *
 * Validates EAN-8 and EAN-13 codes using the Modulo 10 checksum algorithm.
 *
 * Algorithm:
 * 1. Extract all digits except the last (check digit)
 * 2. Sum digits with alternating multipliers (1,3,1,3...)
 * 3. Calculate check digit: (10 - (sum % 10)) % 10
 * 4. Compare with actual last digit
 *
 * @package Inpost\VonHalsky\Validator\Constraints
 * @author InPost von Halsky
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Validator\Constraints;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Inpost\VonHalsky\Service\Util\ValidationUtils;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

class ValidEanValidator extends ConstraintValidator
{
    /**
     * Validate EAN code with checksum verification
     *
     * @param mixed $value EAN code to validate
     * @param Constraint $constraint ValidEan constraint
     * @return void
     */
    public function validate($value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidEan) {
            throw new UnexpectedTypeException($constraint, ValidEan::class);
        }

        // Skip validation if empty; requiredness is owned by the class-level
        // EanOrAlternativeIdentification constraint / NotBlank on the form.
        if (null === $value || '' === $value) {
            return;
        }

        $ean = (string) $value;

        // Validate format + checksum via the shared utility and surface the
        // specific failure reason.
        $reason = ValidationUtils::validateEanDetailed($ean, false);
        if ($reason === null) {
            return;
        }

        $message = $constraint->message;
        if ($reason === ValidationUtils::EAN_MESSAGE_DIGITS) {
            $message = $constraint->digitsMessage;
        } elseif ($reason === ValidationUtils::EAN_MESSAGE_LENGTH) {
            $message = $constraint->lengthMessage;
        } elseif ($reason === ValidationUtils::EAN_MESSAGE_CHECKSUM) {
            $message = $constraint->checksumMessage;
        }

        $this->context->buildViolation($message)
            ->setParameter('{{ value }}', $ean)
            ->addViolation();
    }
}
