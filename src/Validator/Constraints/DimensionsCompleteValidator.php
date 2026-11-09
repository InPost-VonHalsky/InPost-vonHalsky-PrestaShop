<?php
/**
 * DimensionsCompleteValidator
 *
 * Validator for DimensionsComplete constraint.
 * Checks that product dimensions are either all filled or all empty.
 *
 * @package Inpost\VonHalsky\Validator\Constraints
 * @author SII Poland
 * @copyright 2025 SII Poland
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Validator\Constraints;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Validates that dimension fields are complete
 */
class DimensionsCompleteValidator extends ConstraintValidator
{
    /**
     * Validate dimensions completeness
     *
     * @param mixed $value The object being validated (OfferProduct entity)
     * @param Constraint $constraint The constraint
     * @return void
     */
    public function validate($value, Constraint $constraint): void
    {
        if (!$constraint instanceof DimensionsComplete) {
            throw new UnexpectedTypeException($constraint, DimensionsComplete::class);
        }

        if (null === $value) {
            return;
        }

        $fields = $constraint->fields;
        $filledCount = 0;
        $emptyCount = 0;
        $missingFields = [];

        // Check each dimension field
        foreach ($fields as $field) {
            $getter = 'get' . ucfirst($field);

            if (!method_exists($value, $getter)) {
                continue; // Skip if getter doesn't exist
            }

            $fieldValue = $value->$getter();

            if ($fieldValue === null || $fieldValue === '' || $fieldValue === 0) {
                $emptyCount++;
                $missingFields[] = $this->getFieldLabel($field);
            } else {
                $filledCount++;
            }
        }

        // If some fields are filled but not all, add violation
        if ($filledCount > 0 && $emptyCount > 0) {
            $this->context->buildViolation($constraint->message)
                ->setParameter('{{ missing_fields }}', implode(', ', $missingFields))
                ->addViolation();
        }
    }

    /**
     * Get human-readable field label
     *
     * @param string $field Field name
     * @return string
     */
    private function getFieldLabel(string $field): string
    {
        $labels = [
            'dimensionWidth' => 'Width',
            'dimensionHeight' => 'Height',
            'dimensionLength' => 'Length',
            'dimensionWeight' => 'Weight'
        ];

        return $labels[$field] ?? $field;
    }
}
