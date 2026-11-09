<?php
/**
 * CountryCodeIsoValidator
 *
 * Validator for CountryCodeIso constraint.
 * Validates ISO 3166-1 alpha-2 country codes.
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
use Symfony\Component\Validator\Exception\UnexpectedValueException;

/**
 * Validates ISO country code format
 */
class CountryCodeIsoValidator extends ConstraintValidator
{
    /**
     * Common ISO 3166-1 alpha-2 country codes
     * (Subset - full list would be 249 codes)
     */
    private const VALID_COUNTRY_CODES = [
        'PL', 'DE', 'GB', 'FR', 'IT', 'ES', 'NL', 'BE', 'AT', 'CH', 'CZ', 'SK',
        'HU', 'RO', 'BG', 'HR', 'SI', 'LT', 'LV', 'EE', 'DK', 'SE', 'NO', 'FI',
        'IE', 'PT', 'GR', 'US', 'CA', 'MX', 'BR', 'AR', 'CL', 'CO', 'PE', 'VE',
        'AU', 'NZ', 'JP', 'CN', 'IN', 'KR', 'TH', 'VN', 'PH', 'ID', 'MY', 'SG',
        'AE', 'SA', 'IL', 'TR', 'EG', 'ZA', 'NG', 'KE', 'GH', 'MA', 'TN', 'DZ',
        'UA', 'RU', 'BY', 'MD', 'RS', 'BA', 'ME', 'MK', 'AL', 'XK', 'LU', 'MT',
        'CY', 'IS', 'LI', 'MC', 'SM', 'VA', 'AD', 'GI', 'JE', 'GG', 'IM', 'FO'
    ];

    /**
     * Validate country code
     *
     * @param mixed $value The country code to validate
     * @param Constraint $constraint The constraint
     * @return void
     */
    public function validate($value, Constraint $constraint): void
    {
        if (!$constraint instanceof CountryCodeIso) {
            throw new UnexpectedTypeException($constraint, CountryCodeIso::class);
        }

        // Empty values are valid (use NotBlank constraint separately if required)
        if (null === $value || '' === $value) {
            return;
        }

        if (!is_string($value)) {
            throw new UnexpectedValueException($value, 'string');
        }

        // Convert to uppercase for comparison
        $upperValue = strtoupper($value);

        // Basic format check: exactly 2 uppercase letters
        if (!preg_match('/^[A-Z]{2}$/', $upperValue)) {
            $this->context->buildViolation($constraint->message)
                ->setParameter('{{ value }}', $value)
                ->addViolation();
            return;
        }

        // Validate against known country codes
        if (!in_array($upperValue, self::VALID_COUNTRY_CODES, true)) {
            $this->context->buildViolation($constraint->message)
                ->setParameter('{{ value }}', $value)
                ->addViolation();
        }
    }
}
