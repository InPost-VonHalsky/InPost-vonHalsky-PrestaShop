<?php
/**
 * OfferPriceResolver (GOKINT-1098)
 *
 * The price an offer is sent to InPost with, the first that applies:
 *   1. the manual price typed in on the offer (manual mode);
 *   2. the gross price for the customer group chosen in the configuration (its
 *      specific prices, catalog price rules and group reduction), or the shop
 *      price when no group is chosen,
 *   3. changed by the configured percentage (+5 = 5% more expensive than the
 *      shop, -5 = 5% cheaper) and rounded.
 *
 * With no group, 0% and no rounding the price is exactly the shop price, as
 * before this setting existed. The shop price (no group, no rule) is always
 * kept next to it, so the merchant sees both.
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Service\Offer;

use Group;
use Inpost\VonHalsky\Entity\OfferPrice;
use Inpost\VonHalsky\Service\Sync\PsPriceStockReader;
use Inpost\VonHalsky\Service\Util\ConfigurationService;

class OfferPriceResolver
{
    public const ROUNDING_NONE = 'none';
    /** Up to ,99 - e.g. 104.20 -> 104.99, 105.00 -> 105.99 */
    public const ROUNDING_X99 = 'x99';
    /** To the whole zloty - e.g. 104.49 -> 104.00 */
    public const ROUNDING_INTEGER = 'integer';
    public const ROUNDINGS = [self::ROUNDING_NONE, self::ROUNDING_X99, self::ROUNDING_INTEGER];

    /** Accepted range of the percentage */
    public const MIN_PERCENT = -90.0;
    public const MAX_PERCENT = 500.0;

    private PsPriceStockReader $priceStockReader;
    private ConfigurationService $config;

    /** @var array<int,bool> Whether a customer group exists, by id */
    private array $existingGroups = [];

    public function __construct(PsPriceStockReader $priceStockReader, ConfigurationService $config)
    {
        $this->priceStockReader = $priceStockReader;
        $this->config = $config;
    }

    /**
     * InPost price and shop price of what an offer sells
     *
     * @param int $idProductAttribute Combination id, 0 = the whole product
     * @param OfferPrice|null $current Price of the offer - its manual price wins
     * @return array{price: float, shop_price: float}
     */
    public function resolve(int $idProduct, int $idProductAttribute = 0, ?OfferPrice $current = null): array
    {
        $shopPrice = $this->priceStockReader->price($idProduct, $idProductAttribute);

        if ($current !== null && $current->isManual() && $current->getManualPrice() !== null) {
            return ['price' => (float) $current->getManualPrice(), 'shop_price' => $shopPrice];
        }

        $groupId = $this->groupId();
        $base = $groupId > 0 ? $this->priceStockReader->price($idProduct, $idProductAttribute, $groupId) : $shopPrice;

        return ['price' => self::applyRule($base, $this->percent(), $this->rounding()), 'shop_price' => $shopPrice];
    }

    /**
     * Bring the price of an offer up to date (manual price, configuration, shop)
     *
     * @return bool True if the price sent to InPost changed
     */
    public function refresh(OfferPrice $offerPrice, int $idProduct, int $idProductAttribute = 0): bool
    {
        $resolved = $this->resolve($idProduct, $idProductAttribute, $offerPrice);
        $offerPrice->setShopPrice((string) $resolved['shop_price']);

        if (abs((float) $offerPrice->getPrice() - $resolved['price']) <= 0.0001) {
            return false;
        }
        $offerPrice->setPrice((string) $resolved['price']);

        return true;
    }

    /**
     * Percentage and rounding on a gross price
     */
    public static function applyRule(float $price, float $percent, string $rounding): float
    {
        if (abs($percent) < 0.0001 && $rounding === self::ROUNDING_NONE) {
            return $price;
        }

        $adjusted = round($price * (1 + $percent / 100), 2);
        switch ($rounding) {
            case self::ROUNDING_X99:
                $rounded = floor($adjusted) + 0.99;
                break;
            case self::ROUNDING_INTEGER:
                $rounded = round($adjusted);
                break;
            default:
                return $adjusted;
        }

        // A rounding never makes a price of a few grosze free
        return $rounded > 0 ? round($rounded, 2) : $adjusted;
    }

    /**
     * Percentage from a form ("5", "-3,5"); null when not a number or out of range
     *
     * @param mixed $raw
     */
    public static function parsePercent($raw): ?float
    {
        $value = str_replace([',', ' ', '%'], ['.', '', ''], trim((string) $raw));
        if ($value === '' || $value === '+') {
            return 0.0;
        }
        if (!preg_match('/^[+-]?\d+(\.\d{1,2})?$/', $value)) {
            return null;
        }
        $percent = (float) $value;

        return $percent >= self::MIN_PERCENT && $percent <= self::MAX_PERCENT ? $percent : null;
    }

    /**
     * Customer group of the InPost prices, 0 = none (or the group was deleted)
     */
    public function groupId(): int
    {
        $groupId = (int) $this->config->get(ConfigurationService::PRICE_GROUP_ID, 0);
        if ($groupId <= 0 || !Group::isFeatureActive()) {
            return 0;
        }
        if (!isset($this->existingGroups[$groupId])) {
            $this->existingGroups[$groupId] = \Validate::isLoadedObject(new Group($groupId));
        }

        return $this->existingGroups[$groupId] ? $groupId : 0;
    }

    public function percent(): float
    {
        return self::parsePercent($this->config->get(ConfigurationService::PRICE_ADJUST_PERCENT, '0')) ?? 0.0;
    }

    public function rounding(): string
    {
        $rounding = (string) $this->config->get(ConfigurationService::PRICE_ROUNDING, self::ROUNDING_NONE);

        return in_array($rounding, self::ROUNDINGS, true) ? $rounding : self::ROUNDING_NONE;
    }
}
