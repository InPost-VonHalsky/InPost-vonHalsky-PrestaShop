<?php
/**
 * ShopTaxCurrencyInfo
 *
 * Shop setup facts InPost von Halsky depends on: the PLN currency (InPost orders
 * and offers are priced in PLN) and VAT tax rules (offers declare the product
 * VAT rate; a product without a tax rule is offered and sold with 0% VAT).
 *
 * Rates are resolved for the shop default country, the same way PrestaShop
 * computes the price of a product for a customer from that country.
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Service\Util;

use Address;
use Configuration;
use Currency;
use Db;
use TaxManagerFactory;

class ShopTaxCurrencyInfo
{
    /**
     * Currency InPost von Halsky prices offers and orders in
     */
    public const INPOST_CURRENCY = 'PLN';

    /** @var array<int, float> */
    private array $rateByTaxRulesGroup = [];

    /**
     * Whether the PLN currency is installed and active in the shop
     */
    public function isInpostCurrencyActive(): bool
    {
        $idCurrency = (int) Currency::getIdByIsoCode(self::INPOST_CURRENCY);
        if ($idCurrency <= 0) {
            return false;
        }

        $currency = new Currency($idCurrency);

        return (bool) $currency->active && !$currency->deleted;
    }

    /**
     * Whether taxes are enabled in the shop (Localization > Taxes > Enable tax)
     */
    public function areTaxesEnabled(): bool
    {
        return (bool) Configuration::get('PS_TAX');
    }

    /**
     * Number of active tax rules groups with a rate above 0% for the default country
     */
    public function countTaxRulesWithRate(): int
    {
        return (int) Db::getInstance()->getValue(
            'SELECT COUNT(DISTINCT g.id_tax_rules_group)
             FROM ' . _DB_PREFIX_ . 'tax_rules_group g
             JOIN ' . _DB_PREFIX_ . 'tax_rule r ON r.id_tax_rules_group = g.id_tax_rules_group
             JOIN ' . _DB_PREFIX_ . 'tax t ON t.id_tax = r.id_tax
             WHERE g.active = 1 AND g.deleted = 0 AND t.active = 1 AND t.deleted = 0 AND t.rate > 0
               AND r.id_country = ' . (int) Configuration::get('PS_COUNTRY_DEFAULT')
        );
    }

    /**
     * VAT rate (percent) PrestaShop applies to the product for the default country;
     * 0.0 when taxes are disabled or the product has no tax rule.
     */
    public function productTaxRate(int $idProduct): float
    {
        if (!$this->areTaxesEnabled()) {
            return 0.0;
        }

        $idTaxRulesGroup = (int) Db::getInstance()->getValue(
            'SELECT id_tax_rules_group FROM ' . _DB_PREFIX_ . 'product WHERE id_product = ' . $idProduct
        );

        return $this->taxRulesGroupRate($idTaxRulesGroup);
    }

    /**
     * Whether the product gets its VAT from a tax rule (taxes enabled and a rule
     * assigned). Without it PrestaShop and the InPost offer use 0% VAT - unlike a
     * deliberate 0% rule (e.g. exempted rate), this is a configuration gap.
     */
    public function hasProductTaxRule(int $idProduct): bool
    {
        return $this->areTaxesEnabled() && (int) Db::getInstance()->getValue(
            'SELECT id_tax_rules_group FROM ' . _DB_PREFIX_ . 'product WHERE id_product = ' . $idProduct
        ) > 0;
    }

    /**
     * "23%" style label for the offer payload (taxRateInfo)
     */
    public static function formatRate(float $rate): string
    {
        return rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.') . '%';
    }

    private function taxRulesGroupRate(int $idTaxRulesGroup): float
    {
        if ($idTaxRulesGroup <= 0) {
            return 0.0;
        }

        if (!isset($this->rateByTaxRulesGroup[$idTaxRulesGroup])) {
            $address = new Address();
            $address->id_country = (int) Configuration::get('PS_COUNTRY_DEFAULT');

            $this->rateByTaxRulesGroup[$idTaxRulesGroup] = (float) TaxManagerFactory::getManager($address, $idTaxRulesGroup)
                ->getTaxCalculator()
                ->getTotalRate();
        }

        return $this->rateByTaxRulesGroup[$idTaxRulesGroup];
    }
}
