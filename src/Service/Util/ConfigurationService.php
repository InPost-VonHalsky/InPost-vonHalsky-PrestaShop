<?php
/**
 * ConfigurationService
 *
 * Centralized configuration management for PrestaShop with multi-shop support.
 * OAuth2 tokens are ALWAYS stored globally (shared across all shops).
 * Other settings can be shop-specific or global.
 *
 * Usage Examples:
 *   // Get organization ID for current shop
 *   $orgId = $config->getOrganizationId();
 *
 *   // Set organization ID for specific shop
 *   $config->setOrganizationId(12345, 5);
 *
 *   // Set global configuration (all shops)
 *   $config->setGlobal(ConfigurationService::API_URL, 'https://api.example.com');
 *
 *   // Type-safe getters
 *   $enabled = $config->getBool(ConfigurationService::AUTO_SYNC_ENABLED);
 *   $interval = $config->getInt(ConfigurationService::SYNC_INTERVAL, 3600);
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Service\Util;

use Configuration;
use Context;

class ConfigurationService
{

    /**
     * OAuth2 Configuration Keys - ALWAYS GLOBAL (shared across all shops)
     */
    public const ACCESS_TOKEN = 'INPOSTSA_ACCESS_TOKEN';
    public const ACCESS_TOKEN_EXPIRES = 'INPOSTSA_ACCESS_TOKEN_EXPIRES';
    public const REFRESH_TOKEN = 'INPOSTSA_REFRESH_TOKEN';

    /**
     * Module Configuration Keys - Can be multi-shop specific
     */
    public const ORGANIZATION_ID = 'INPOSTSA_ORGANIZATION_ID';
    public const MODULE_ENABLED = 'INPOSTSA_MODULE_ENABLED';

    /**
     * API Configuration Keys - Typically global but can be overridden per shop
     */
    public const API_URL = 'INPOSTSA_API_URL';
    public const AUTH_SERVICE_URL = 'INPOSTSA_AUTH_URL';
    public const CLIENT_ID = 'INPOSTSA_CLIENT_ID';
    public const CLIENT_SECRET = 'INPOSTSA_CLIENT_SECRET';
    public const ORGANIZATIONS_ENDPOINT = 'INPOSTSA_ORGANIZATIONS_ENDPOINT';

    /**
     * Sync Configuration Keys - Can be multi-shop specific
     */
    public const AUTO_SYNC_ENABLED = 'INPOSTSA_AUTO_SYNC_ENABLED';
    public const SYNC_INTERVAL = 'INPOSTSA_SYNC_INTERVAL';
    public const LAST_SYNC_TIMESTAMP = 'INPOSTSA_LAST_SYNC_TIMESTAMP';

    /**
     * Debug Configuration Keys - Disabled by default
     */
    public const DEBUG_API_LOGGING = 'INPOSTSA_DEBUG_API_LOGGING';
    public const TEST_MODE = 'INPOSTSA_TEST_MODE';

    /**
     * Pending create-command sync (GOKINT-928) - cron + retry behaviour
     */
    public const CRON_SYNC_COMMANDS_ENABLED = 'INPOSTSA_CRON_SYNC_COMMANDS_ENABLED';
    public const CRON_SYNC_COMMANDS_SCHEDULE = 'INPOSTSA_CRON_SYNC_COMMANDS_SCHEDULE';
    public const CRON_SYNC_COMMANDS_LIMIT = 'INPOSTSA_CRON_SYNC_COMMANDS_LIMIT';
    public const CREATE_COMMAND_MAX_RETRIES = 'INPOSTSA_CREATE_COMMAND_MAX_RETRIES';

    /**
     * Category orphan cleanup (GOKINT-929) - kill switch for the sweep
     */
    public const CATEGORY_ORPHAN_CLEANUP_ENABLED = 'INPOSTSA_CATEGORY_ORPHAN_CLEANUP_ENABLED';

    /**
     * Productization / /hints (GOKINT-927)
     */
    public const PRODUCTIZATION_ENABLED = 'INPOSTSA_PRODUCTIZATION_ENABLED';
    public const HINT_NAME_FALLBACK_ENABLED = 'INPOSTSA_HINT_NAME_FALLBACK_ENABLED';
    public const HINT_CACHE_TTL = 'INPOSTSA_HINT_CACHE_TTL';

    /**
     * Bulk offer creation from the product grid (GOKINT-930)
     */
    public const BULK_LIST_ENABLED = 'INPOSTSA_BULK_LIST_ENABLED';
    public const BULK_CHUNK_SIZE = 'INPOSTSA_BULK_CHUNK_SIZE';

    /**
     * Dedicated InPost price (GOKINT-1098) — the offers are priced for a customer
     * group of the shop, then changed by a percentage and rounded.
     * PRICE_RECALC_PENDING: a setting, promotion or price rule changed, the
     * offer_sync cron recalculates every automatically priced offer.
     */
    public const PRICE_GROUP_ID = 'INPOSTSA_PRICE_GROUP_ID';
    public const PRICE_ADJUST_PERCENT = 'INPOSTSA_PRICE_ADJUST_PERCENT';
    public const PRICE_ROUNDING = 'INPOSTSA_PRICE_ROUNDING';
    public const PRICE_RECALC_PENDING = 'INPOSTSA_PRICE_RECALC_PENDING';

    /**
     * Image test override — when enabled, every offer image fileUrl is replaced
     * with a fixed external URL (for testing when the shop is not reachable by
     * InPost, e.g. behind a VPN).
     */
    public const IMAGE_TEST_MODE_ENABLED = 'INPOSTSA_IMAGE_TEST_MODE_ENABLED';
    public const IMAGE_TEST_URL = 'INPOSTSA_IMAGE_TEST_URL';

    /**
     * InPost ShipX (inpostshipping) integration — shipping/payment mapping and
     * automatic shipment creation on order acceptance.
     */
    public const SHIPX_INTEGRATION_ENABLED = 'INPOSTSA_SHIPX_INTEGRATION_ENABLED';
    public const SHIPX_AUTO_SHIPMENT_ENABLED = 'INPOSTSA_SHIPX_AUTO_SHIPMENT_ENABLED';
    public const SHIPX_CARRIER_LOCKER = 'INPOSTSA_SHIPX_CARRIER_LOCKER';
    public const SHIPX_CARRIER_COURIER = 'INPOSTSA_SHIPX_CARRIER_COURIER';
    public const SHIPX_DEFAULT_PARCEL_TEMPLATE = 'INPOSTSA_SHIPX_DEFAULT_PARCEL_TEMPLATE';

    /**
     * Global GPSR + Manufacturer defaults. Used to fill offer payload fields the
     * offer itself leaves empty (offer value wins, else these globals).
     */
    public const GPSR_DOES_NOT_REQUIRE = 'INPOSTSA_GPSR_DOES_NOT_REQUIRE';
    public const GPSR_BATCH_NUMBER = 'INPOSTSA_GPSR_BATCH_NUMBER';
    public const GPSR_CE_MARKING = 'INPOSTSA_GPSR_CE_MARKING';
    public const GPSR_SAFETY_INFORMATION = 'INPOSTSA_GPSR_SAFETY_INFORMATION';
    public const MFR_NAME = 'INPOSTSA_MFR_NAME';
    public const MFR_EMAIL = 'INPOSTSA_MFR_EMAIL';
    public const MFR_PHONE = 'INPOSTSA_MFR_PHONE';
    public const MFR_STREET = 'INPOSTSA_MFR_STREET';
    public const MFR_BUILDING_NUMBER = 'INPOSTSA_MFR_BUILDING_NUMBER';
    public const MFR_APARTMENT_NUMBER = 'INPOSTSA_MFR_APARTMENT_NUMBER';
    public const MFR_CITY = 'INPOSTSA_MFR_CITY';
    public const MFR_POSTAL_CODE = 'INPOSTSA_MFR_POSTAL_CODE';
    public const MFR_COUNTRY_CODE = 'INPOSTSA_MFR_COUNTRY_CODE';

    /**
     * Default post-sale policies used when the offer leaves them empty
     */
    public const OFFER_DEFAULT_RETURN_POLICY = 'INPOSTSA_OFFER_DEFAULT_RETURN_POLICY';
    public const OFFER_DEFAULT_COMPLAINT_POLICY = 'INPOSTSA_OFFER_DEFAULT_COMPLAINT_POLICY';
    public const OFFER_DEFAULT_DELIVERY_DAYS = 'INPOSTSA_OFFER_DEFAULT_DELIVERY_DAYS';

    /**
     * Get configuration value with optional shop context
     *
     * @param string $key Configuration key
     * @param mixed $default Default value if not found
     * @param int|null $shopId Optional shop ID (null = current shop)
     * @return mixed Configuration value or default
     */
    public function get(string $key, $default = null, ?int $shopId = null)
    {
        if ($shopId === null) {
            // Use current shop context
            $value = Configuration::get($key);
        } else {
            // Specific shop
            $value = Configuration::get($key, null, null, $shopId);
        }

        return $value !== false ? $value : $default;
    }

    /**
     * Get configuration value as integer
     *
     * @param string $key Configuration key
     * @param int $default Default value
     * @param int|null $shopId Optional shop ID (null = current shop)
     * @return int Configuration value as integer
     */
    public function getInt(string $key, int $default = 0, ?int $shopId = null): int
    {
        return (int)$this->get($key, $default, $shopId);
    }

    /**
     * Get configuration value as boolean
     *
     * @param string $key Configuration key
     * @param bool $default Default value
     * @param int|null $shopId Optional shop ID (null = current shop)
     * @return bool Configuration value as boolean
     */
    public function getBool(string $key, bool $default = false, ?int $shopId = null): bool
    {
        $value = $this->get($key, $default ? '1' : '0', $shopId);
        return in_array($value, ['1', 'true', true, 1], true);
    }

    /**
     * Get configuration value as string
     *
     * @param string $key Configuration key
     * @param string $default Default value
     * @param int|null $shopId Optional shop ID (null = current shop)
     * @return string Configuration value as string
     */
    public function getString(string $key, string $default = '', ?int $shopId = null): string
    {
        return (string)$this->get($key, $default, $shopId);
    }

    /**
     * Get default language for InPost API operations
     *
     * @return string Default language code (always 'pl')
     */
    public function getDefaultLanguage(): string
    {
        return 'pl';
    }
}
