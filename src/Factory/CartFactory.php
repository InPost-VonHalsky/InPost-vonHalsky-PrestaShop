<?php
/**
 * Cart Factory
 *
 * Factory for creating Cart objects - improves testability and separation of concerns
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Factory;

use Cart;

/**
 * Factory for creating Cart instances
 */
class CartFactory
{
    /**
     * Create a new empty Cart
     *
     * @return Cart
     */
    public function create(): Cart
    {
        return new Cart();
    }

    /**
     * Create a Cart with basic configuration
     *
     * @param int $customerId
     * @param int $idLang
     * @param int $idShop
     * @param int $idCurrency
     * @return Cart
     */
    public function createWithConfiguration(
        int $customerId,
        int $idLang,
        int $idShop,
        int $idCurrency
    ): Cart {
        $cart = $this->create();
        $cart->id_customer = $customerId;
        $cart->id_lang = $idLang;
        $cart->id_shop = $idShop;
        $cart->id_currency = $idCurrency;
        $cart->secure_key = bin2hex(random_bytes(16));

        return $cart;
    }
}
