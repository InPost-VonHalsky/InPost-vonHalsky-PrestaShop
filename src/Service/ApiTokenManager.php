<?php
/**
 * ApiTokenManager
 *
 * Manages OAuth2 access tokens, refresh tokens, and PKCE parameters.
 * Uses PrestaShop Configuration for token storage and database for PKCE state.
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Service;

use Configuration;
use Db;
use DbQuery;

class ApiTokenManager
{
    /**
     * Configuration keys
     */
    private const CONFIG_ACCESS_TOKEN = 'INPOSTSA_ACCESS_TOKEN';
    private const CONFIG_ACCESS_TOKEN_EXPIRES_AT = 'INPOSTSA_ACCESS_TOKEN_EXPIRES_AT';
    private const CONFIG_REFRESH_TOKEN = 'INPOSTSA_REFRESH_TOKEN';

    /**
     * PKCE parameter storage table name
     */
    private const PKCE_TABLE_NAME = 'inpost_pkce_params';

    /**
     * PKCE parameter lifetime in seconds (10 minutes)
     */
    private const PKCE_LIFETIME = 600;

    /**
     * Save access token
     *
     * @param string $token
     * @param int $expiresAt Unix timestamp
     * @return void
     */
    public function saveAccessToken(string $token, int $expiresAt): void
    {
        Configuration::updateValue(self::CONFIG_ACCESS_TOKEN, $token);
        Configuration::updateValue(self::CONFIG_ACCESS_TOKEN_EXPIRES_AT, $expiresAt);
    }

    /**
     * Get access token
     *
     * @return string|null
     */
    public function getAccessToken(): ?string
    {
        $token = Configuration::get(self::CONFIG_ACCESS_TOKEN);

        return !empty($token) ? $token : null;
    }

    /**
     * Get access token expiration timestamp
     *
     * @return int|null
     */
    public function getAccessTokenExpiration(): ?int
    {
        $expiresAt = Configuration::get(self::CONFIG_ACCESS_TOKEN_EXPIRES_AT);

        return !empty($expiresAt) ? (int)$expiresAt : null;
    }

    /**
     * Save refresh token
     *
     * @param string $token
     * @return void
     */
    public function saveRefreshToken(string $token): void
    {
        Configuration::updateValue(self::CONFIG_REFRESH_TOKEN, $token);
    }

    /**
     * Get refresh token
     *
     * @return string|null
     */
    public function getRefreshToken(): ?string
    {
        $token = Configuration::get(self::CONFIG_REFRESH_TOKEN);

        return !empty($token) ? $token : null;
    }

    /**
     * Get access token directly from DB, bypassing static cache.
     * Use after acquiring lock to ensure fresh read.
     *
     * @return string|null
     */
    public function getAccessTokenFromDb(): ?string
    {
        $token = $this->readFromDb(self::CONFIG_ACCESS_TOKEN);

        return !empty($token) ? (string)$token : null;
    }

    /**
     * Get access token expiration directly from DB, bypassing static cache.
     * Use after acquiring lock to ensure fresh read.
     *
     * @return int|null
     */
    public function getAccessTokenExpirationFromDb(): ?int
    {
        $expires = $this->readFromDb(self::CONFIG_ACCESS_TOKEN_EXPIRES_AT);

        return !empty($expires) ? (int)$expires : null;
    }

    /**
     * Get refresh token directly from DB, bypassing static cache.
     * Use after acquiring lock to ensure fresh read.
     *
     * @return string|null
     */
    public function getRefreshTokenFromDb(): ?string
    {
        $token = $this->readFromDb(self::CONFIG_REFRESH_TOKEN);

        return !empty($token) ? (string)$token : null;
    }

    /**
     * Read a configuration value straight from the table, bypassing Configuration's
     * static cache (another process may have rotated the tokens meanwhile).
     *
     * @return string|false
     */
    private function readFromDb(string $key)
    {
        return Db::getInstance()->getValue(
            'SELECT value FROM ' . _DB_PREFIX_ . 'configuration WHERE name = \'' . pSQL($key) . '\''
        );
    }

    /**
     * Clear all tokens
     *
     * @return void
     */
    public function clearTokens(): void
    {
        Configuration::deleteByName(self::CONFIG_ACCESS_TOKEN);
        Configuration::deleteByName(self::CONFIG_ACCESS_TOKEN_EXPIRES_AT);
        Configuration::deleteByName(self::CONFIG_REFRESH_TOKEN);
    }

    /**
     * Save PKCE parameters (code_verifier) keyed by state
     *
     * @param string $state OAuth2 state parameter
     * @param string $codeVerifier PKCE code verifier
     * @param string|null $returnUrl URL to redirect after OAuth callback
     * @return void
     */
    public function savePkceParams(string $state, string $codeVerifier, ?string $returnUrl = null): void
    {
        // Clean up expired entries first
        $this->cleanupExpiredPkceParams();

        $expiresAt = time() + self::PKCE_LIFETIME;

        $data = [
            'state' => pSQL($state),
            'code_verifier' => pSQL($codeVerifier),
            'expires_at' => (int)$expiresAt,
            'created_at' => date('Y-m-d H:i:s')
        ];

        if ($returnUrl !== null) {
            $data['return_url'] = pSQL($returnUrl);
        }

        Db::getInstance()->insert(self::PKCE_TABLE_NAME, $data);
    }

    /**
     * Get PKCE code verifier by state
     *
     * @param string $state OAuth2 state parameter
     * @return string|null
     */
    public function getPkceCodeVerifier(string $state): ?string
    {
        $query = new DbQuery();
        $query->select('code_verifier')
            ->from(self::PKCE_TABLE_NAME)
            ->where('state = "' . pSQL($state) . '"')
            ->where('expires_at > ' . time());

        $result = Db::getInstance()->getValue($query);

        return $result ?: null;
    }

    /**
     * Get return URL by state
     *
     * @param string $state OAuth2 state parameter
     * @return string|null
     */
    public function getReturnUrl(string $state): ?string
    {
        $query = new DbQuery();
        $query->select('return_url')
            ->from(self::PKCE_TABLE_NAME)
            ->where('state = "' . pSQL($state) . '"')
            ->where('expires_at > ' . time());

        $result = Db::getInstance()->getValue($query);

        return $result ?: null;
    }

    /**
     * Remove PKCE parameters by state
     *
     * @param string $state OAuth2 state parameter
     * @return void
     */
    public function removePkceParams(string $state): void
    {
        Db::getInstance()->delete(
            self::PKCE_TABLE_NAME,
            'state = "' . pSQL($state) . '"'
        );
    }

    /**
     * Clean up expired PKCE parameters
     *
     * @return void
     */
    private function cleanupExpiredPkceParams(): void
    {
        Db::getInstance()->delete(
            self::PKCE_TABLE_NAME,
            'expires_at <= ' . time()
        );
    }

}
