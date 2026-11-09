<?php
/**
 * AttributeApiService
 *
 * Service for fetching category-specific attributes from InPost von Halsky API.
 * Attributes are fetched per-category via /categories/{id}/attributes endpoint.
 * Dictionary options are embedded in the category attributes response.
 *
 * The SDK has no concurrent request support, so AttributeMappingService uses the
 * raw Guzzle client, base URL and auth headers provided here for a request pool.
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Service\Api;

use Inpost\VonHalsky\Helper\Config;
use Inpost\VonHalsky\Service\Auth\OAuth2Service;
use GuzzleHttp\ClientInterface;

class AttributeApiService extends ApiServiceAbstract
{
    public function __construct(
        OAuth2Service $oAuth2Service,
        Config $configHelper,
        ApiErrorHandler $errorHandler,
        ?HttpClientFactory $httpClientFactory = null
    ) {
        parent::__construct($oAuth2Service, $configHelper, $errorHandler, $httpClientFactory);
    }

    /**
     * Get Guzzle HTTP client for async operations (like Guzzle Pool)
     *
     * Required for async attribute sync in AttributeMappingService.
     *
     * @return ClientInterface Guzzle HTTP client
     */
    public function getGuzzleClient(): ClientInterface
    {
        $client = $this->getHttpClient();
        if (!$client) {
            // Create default client if factory not available
            return new \GuzzleHttp\Client([
                'timeout' => 30,
                'connect_timeout' => 10,
            ]);
        }
        return $client;
    }

    /**
     * Get API base URL for building async requests
     *
     * @return string API base URL (already includes /v1 from config)
     */
    public function getApiBaseUrl(): string
    {
        return rtrim($this->configHelper->getApiUrl(), '/');
    }

    /**
     * Get access token for async requests
     *
     * @return string Valid access token
     * @throws \Exception
     */
    public function getAccessToken(): string
    {
        $token = $this->oAuth2Service->getValidAccessToken();
        if ($token === null) {
            throw new \Exception('No valid access token available. Please connect to InPost first.');
        }
        return $token;
    }


    /**
     * Build full URL for API endpoint
     *
     * @param string $endpoint API endpoint path (e.g., "/categories/{id}/attributes")
     * @return string Full URL
     */
    public function buildUrl(string $endpoint): string
    {
        return $this->getApiBaseUrl() . $endpoint;
    }

    /**
     * Build headers with authorization token
     *
     * @param array $headers Additional headers to include
     * @return array Headers array with Authorization bearer token
     */
    public function buildAuthHeaders(array $headers = []): array
    {
        $headers['Authorization'] = 'Bearer ' . $this->getAccessToken();
        return $headers;
    }

    // ========================================================================
    // LEGACY METHOD ALIASES (for backward compatibility during migration)
    // ========================================================================


}
