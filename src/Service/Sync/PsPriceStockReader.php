<?php
/**
 * PsPriceStockReader
 *
 * Price and stock of what an offer sells - the whole product or one
 * combination. Single place for both the offer builder (creation) and the
 * product sync, so an offer never gets two different prices.
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Service\Sync;

use Address;
use Cache;
use Cart;
use Context;
use Currency;
use Product;
use StockAvailable;
use Tax;
use Validate;

class PsPriceStockReader
{
    /**
     * Gross price the shop sells at (catalogue price rules and specific prices
     * included), for no particular customer
     *
     * @param int $idProductAttribute Combination id, 0 = the product (its default combination)
     * @param int $idGroup Customer group to price for (its specific prices, catalog price
     *        rules and group reduction); 0 = the shop price as today
     */
    public function price(int $idProduct, int $idProductAttribute = 0, int $idGroup = 0): float
    {
        // PrestaShop refuses to price without a cart or an employee in the context
        // (cron / CLI) - an empty, unsaved cart is enough
        $context = Context::getContext();
        $temporaryCart = !is_object($context->cart) && !isset($context->employee);
        if ($temporaryCart) {
            $context->cart = new Cart();
        }

        try {
            return $idGroup > 0
                ? $this->groupPrice($idProduct, $idProductAttribute, $idGroup)
                : $this->priceInContext($idProduct, $idProductAttribute);
        } finally {
            if ($temporaryCart) {
                $context->cart = null;
            }
        }
    }

    private function priceInContext(int $idProduct, int $idProductAttribute): float
    {
        $specificPriceOutput = null;

        return (float) Product::getPriceStatic(
            $idProduct,
            true,
            $idProductAttribute > 0 ? $idProductAttribute : null,
            6,
            null,
            false,
            true,
            1,
            false,
            null,
            null,
            null,
            $specificPriceOutput,
            true,
            true,
            null,
            // Not the price of the customer whose checkout triggered the sync
            false
        );
    }

    /**
     * Gross price for a customer group, for no particular customer. getPriceStatic()
     * takes the group from the customer in the context only, so the calculation
     * is called the same way with the group given.
     */
    private function groupPrice(int $idProduct, int $idProductAttribute, int $idGroup): float
    {
        $context = Context::getContext();
        // Tax address as in getPriceStatic() without a customer: the shop default country
        $address = Address::initialize(null, true);
        $specificPriceOutput = null;

        return (float) Product::priceCalculation(
            (int) $context->shop->id,
            $idProduct,
            $idProductAttribute > 0 ? $idProductAttribute : null,
            (int) $address->id_country,
            (int) $address->id_state,
            (string) $address->postcode,
            Validate::isLoadedObject($context->currency) ? (int) $context->currency->id : (int) Currency::getDefaultCurrencyId(),
            $idGroup,
            1,
            !Tax::excludeTaxeOption(),
            6,
            false,
            true,
            true,
            $specificPriceOutput,
            true,
            0,
            false
        );
    }

    /**
     * Available quantity: of the combination, or the product total (all combinations)
     */
    public function quantity(int $idProduct, int $idProductAttribute = 0, ?int $shopId = null): int
    {
        // PrestaShop caches the quantity for the whole process - a cron sending many
        // offers must not reuse a value read before the stock changed
        Cache::clean('StockAvailable::getQuantityAvailableByProduct_' . $idProduct . '*');

        return max(0, (int) StockAvailable::getQuantityAvailableByProduct(
            $idProduct,
            $idProductAttribute > 0 ? $idProductAttribute : null,
            $shopId
        ));
    }
}
