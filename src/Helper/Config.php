<?php
/**
 * Config Helper
 *
 * Helper class for accessing InPost module configuration
 *
 * @author    Marcin Bach
 * @copyright 2025 Marcin Bach
 * @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Helper;

use Configuration;

class Config
{
    /**
     * Public URI of the module's JS directory (respects shops installed in a sub-directory)
     */
    public static function getModuleJsUri(): string
    {
        return __PS_BASE_URI__ . 'modules/inpostvonhalsky/views/js/';
    }

    /**
     * Get API URL
     *
     * @return string
     */
    public function getApiUrl(): string
    {
        return (string) Configuration::get('INPOSTSA_API_URL');
    }

    /**
     * Get Organization ID
     *
     * @param int|null $shopId Optional shop ID (multi-shop support)
     * @return string|null
     */
    public function getOrganizationId(?int $shopId = null): ?string
    {
        $value = Configuration::get('INPOSTSA_ORGANIZATION_ID', null, null, $shopId);
        return $value ? (string) $value : null;
    }

    /**
     * Check if test mode is enabled
     *
     * @return bool
     */
    public function isTestModeEnabled(): bool
    {
        return (bool) Configuration::get('INPOSTSA_TEST_MODE');
    }

    /**
     * Get User Organizations Endpoint
     *
     * @return string
     */
    public function getUserOrgsEndpoint(): string
    {
        return (string) Configuration::get('INPOSTSA_USER_ORGS_ENDPOINT');
    }

    /**
     * Get default language code (Polish)
     *
     * @return string
     */
    public function getDefaultLanguageCode(): string
    {
        return 'pl';
    }

    /**
     * Get language ID used for PrestaShop DB lookups (product/category names)
     *
     * Prefers the language matching the API language code; falls back to the
     * shop's default language when that language is not installed (otherwise
     * id_lang = 0 and every *_lang join returns nothing).
     *
     * @return int
     */
    public function getDefaultLanguageId(): int
    {
        $langId = (int) \Language::getIdByIso($this->getDefaultLanguageCode());

        return $langId > 0 ? $langId : (int) \Configuration::get('PS_LANG_DEFAULT');
    }

    /**
     * Get default shop ID
     *
     * @return int
     */
    public function getDefaultShopId(): int
    {
        return 1;
    }
}
