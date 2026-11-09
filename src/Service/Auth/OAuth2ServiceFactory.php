<?php
/**
 * OAuth2ServiceFactory
 *
 * Factory class for creating OAuth2Service instances with proper configuration
 */

namespace Inpost\VonHalsky\Service\Auth;

use Configuration;
use Inpost\VonHalsky\Service\ApiTokenManager;
use Tools;

class OAuth2ServiceFactory
{
    /**
     * Creates OAuth2Service instance with PrestaShop configuration
     *
     * @return OAuth2Service
     */
    public static function create(): OAuth2Service
    {
        $tokenManager = new ApiTokenManager();
        $clientId = Configuration::get('INPOSTSA_CLIENT_ID');
        $clientSecret = Configuration::get('INPOSTSA_CLIENT_SECRET');
        $authServiceUrl = Configuration::get('INPOSTSA_AUTH_URL');
        $redirectUri = self::buildRedirectUri();

        return new OAuth2Service(
            $tokenManager,
            $clientId,
            $clientSecret,
            $authServiceUrl,
            $redirectUri
        );
    }

    /**
     * Builds the redirect URI for OAuth callback
     *
     * @return string
     */
    private static function buildRedirectUri(): string
    {
        $protocol = Tools::getHttpHost(true);
        $basePath = rtrim(__PS_BASE_URI__, '/');
        return $protocol . $basePath . '/module/inpostvonhalsky/callback';
    }

    /**
     * Gets the redirect URI without creating a service instance
     *
     * @return string
     */
    public static function getRedirectUri(): string
    {
        return self::buildRedirectUri();
    }
}
