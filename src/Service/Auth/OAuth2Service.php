<?php
/**
 * OAuth2Service
 *
 * OAuth2 authentication service with PKCE (Proof Key for Code Exchange) support.
 * Handles authorization flow, token exchange, and automatic token refresh.
 *
 * @author    Marcin Bach
 * @copyright 2025 Marcin Bach
 * @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Service\Auth;

use Exception;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\GuzzleException;
use Inpost\VonHalsky\Service\ApiTokenManager;
use League\OAuth2\Client\Provider\GenericProvider;
use League\OAuth2\Client\Token\AccessToken;

class OAuth2Service
{
    /**
     * Access token lifetime in seconds (9 minutes)
     */
    private const ACCESS_TOKEN_LIFETIME = 540;

    /**
     * Token endpoint timeouts in seconds - a hung call must not hold the refresh lock forever
     */
    private const HTTP_TIMEOUT = 30;
    private const HTTP_CONNECT_TIMEOUT = 10;

    private ApiTokenManager $tokenManager;
    private string $clientId;
    private string $clientSecret;
    private string $authorizationServiceUrl;
    private string $redirectUri;
    private TokenRefreshLock $refreshLock;

    /**
     * Constructor
     *
     * @param ApiTokenManager $tokenManager
     * @param string $clientId
     * @param string $clientSecret
     * @param string $authorizationServiceUrl
     * @param string $redirectUri
     * @param TokenRefreshLock|null $refreshLock
     */
    public function __construct(
        ApiTokenManager $tokenManager,
        string $clientId,
        string $clientSecret,
        string $authorizationServiceUrl,
        string $redirectUri,
        ?TokenRefreshLock $refreshLock = null
    ) {
        $this->tokenManager = $tokenManager;
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        $this->authorizationServiceUrl = $authorizationServiceUrl;
        $this->redirectUri = $redirectUri;
        $this->refreshLock = $refreshLock ?? new TokenRefreshLock();
    }

    /**
     * Generate authorization URL with PKCE parameters
     *
     * @param string|null $returnUrl URL to redirect after OAuth callback
     * @return array ['authorizationUrl' => string, 'state' => string]
     */
    public function startAuthorization(?string $returnUrl = null): array
    {
        $provider = $this->createProvider();

        $authUrl = $provider->getAuthorizationUrl([
            'scope' => 'api:categories:read api:offers:read api:offers:write api:orders:read api:orders:write'
        ]);

        $codeVerifier = (string)$provider->getPkceCode();
        $state = $provider->getState();

        // Store code_verifier and return_url in database keyed by state
        $this->tokenManager->savePkceParams($state, $codeVerifier, $returnUrl);

        return [
            'authorizationUrl' => $authUrl,
            'state' => $state,
        ];
    }

    /**
     * Exchange authorization code for access token
     *
     * @param string $code Authorization code from callback
     * @param string $state State parameter for CSRF protection
     * @return AccessToken
     * @throws Exception
     */
    public function exchangeCode(string $code, string $state): AccessToken
    {
        // Retrieve code_verifier from database using state
        $codeVerifier = $this->tokenManager->getPkceCodeVerifier($state);

        if (!$codeVerifier) {
            throw new Exception('PKCE code_verifier not found or expired. Please try connecting again.');
        }

        $accessToken = $this->requestToken([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri,
            'code_verifier' => $codeVerifier,
        ], 'Token exchange');

        // Clean up PKCE parameters
        $this->tokenManager->removePkceParams($state);

        return $accessToken;
    }

    /**
     * Refresh expired access token
     *
     * @param string $refreshToken
     * @return AccessToken
     * @throws Exception
     */
    public function refreshToken(string $refreshToken): AccessToken
    {
        return $this->requestToken([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ], 'Token refresh');
    }

    /**
     * POST to the token endpoint (client credentials via Basic auth) and store the result
     *
     * @param array<string, string> $formParams
     * @param string $operation Used in error messages
     * @return AccessToken
     * @throws Exception
     */
    private function requestToken(array $formParams, string $operation): AccessToken
    {
        try {
            $response = (new HttpClient())->post($this->authorizationServiceUrl . '/oauth2/token', [
                'headers' => [
                    'Authorization' => 'Basic ' . base64_encode($this->clientId . ':' . $this->clientSecret),
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Accept' => 'application/json',
                ],
                'form_params' => $formParams,
                'timeout' => self::HTTP_TIMEOUT,
                'connect_timeout' => self::HTTP_CONNECT_TIMEOUT,
            ]);
        } catch (GuzzleException $e) {
            throw new Exception($operation . ' failed: ' . $e->getMessage(), 0, $e);
        }

        $responseData = json_decode($response->getBody()->getContents(), true);

        if (!isset($responseData['access_token'])) {
            throw new Exception('Invalid token response: access_token not found');
        }

        $accessToken = new AccessToken($responseData);
        $this->storeTokensInDatabase($accessToken);

        return $accessToken;
    }

    /**
     * Get valid access token (auto-refresh if needed with locking).
     *
     * Uses file-based locking to prevent race conditions when multiple processes
     * try to refresh tokens simultaneously. InPost OAuth2 uses refresh token rotation,
     * so only one process should perform the refresh at a time.
     *
     * IMPORTANT: After acquiring lock, we use direct DB reads to bypass PrestaShop's
     * static Configuration cache. Different PHP-FPM workers have separate caches,
     * so cached values may be stale after another worker refreshed the token.
     *
     * @return string|null
     */
    public function getValidAccessToken(): ?string
    {
        // First check: return if token is valid (cache is fine for fast path)
        $accessToken = $this->tokenManager->getAccessToken();
        if ($accessToken && !$this->isTokenExpired()) {
            return $accessToken;
        }

        // Token expired or missing - need to refresh with lock
        $refreshToken = $this->tokenManager->getRefreshToken();
        if (!$refreshToken) {
            return null;
        }

        // Try to acquire lock (wait up to 10 seconds)
        if (!$this->refreshLock->acquire(10)) {
            // Timeout - another process is refreshing
            // Use direct DB read to bypass stale cache
            $accessToken = $this->tokenManager->getAccessTokenFromDb();
            $expiresAt = $this->tokenManager->getAccessTokenExpirationFromDb();
            if ($accessToken && $expiresAt && time() < ($expiresAt - 30)) {
                return $accessToken;
            }
            return null;
        }

        try {
            // Double-check after acquiring lock using DIRECT DB READS
            // This bypasses PrestaShop's static Configuration cache which may be stale
            $accessToken = $this->tokenManager->getAccessTokenFromDb();
            $expiresAt = $this->tokenManager->getAccessTokenExpirationFromDb();
            if ($accessToken && $expiresAt && time() < ($expiresAt - 30)) {
                return $accessToken;
            }

            // Re-read refresh token from DB (may have been rotated by another process)
            $refreshToken = $this->tokenManager->getRefreshTokenFromDb();
            if (!$refreshToken) {
                return null;
            }

            // Perform the actual refresh
            $newAccessToken = $this->refreshToken($refreshToken);
            return $newAccessToken->getToken();

        } catch (Exception $e) {
            // Refresh failed - check if another process succeeded (direct DB read)
            $accessToken = $this->tokenManager->getAccessTokenFromDb();
            $expiresAt = $this->tokenManager->getAccessTokenExpirationFromDb();
            if ($accessToken && $expiresAt && time() < ($expiresAt - 30)) {
                return $accessToken;
            }

            error_log('[InPost] Token refresh failed: ' . $e->getMessage());
            return null;

        } finally {
            $this->refreshLock->release();
        }
    }

    /**
     * Store tokens in database
     *
     * @param AccessToken $accessToken
     * @return void
     */
    private function storeTokensInDatabase(AccessToken $accessToken): void
    {
        // Store access token with expiration
        $this->tokenManager->saveAccessToken(
            $accessToken->getToken(),
            $accessToken->getExpires() ?? (time() + self::ACCESS_TOKEN_LIFETIME)
        );

        // Store refresh token (30 days)
        if ($accessToken->getRefreshToken()) {
            $this->tokenManager->saveRefreshToken(
                $accessToken->getRefreshToken()
            );
        }
    }

    /**
     * Create OAuth2 provider with PKCE support
     *
     * @return GenericProvider
     */
    private function createProvider(): GenericProvider
    {
        return new GenericProvider([
            'clientId' => $this->clientId,
            'clientSecret' => '',
            'redirectUri' => $this->redirectUri,
            'urlAuthorize' => $this->authorizationServiceUrl . '/oauth2/authorize',
            'urlAccessToken' => $this->authorizationServiceUrl . '/oauth2/authorize',
            'urlResourceOwnerDetails' => $this->authorizationServiceUrl . '/oauth2/userinfo',
            'pkceMethod' => GenericProvider::PKCE_METHOD_S256
        ]);
    }

    /**
     * Check if the stored access token is expired
     *
     * @return bool
     */
    private function isTokenExpired(): bool
    {
        $expiresAt = $this->tokenManager->getAccessTokenExpiration();

        if (!$expiresAt) {
            return true;
        }

        // Consider token expired 30 seconds before actual expiration
        return time() >= ($expiresAt - 30);
    }
}
