<?php
/**
 * OrganizationApiService
 *
 * Service for managing InPost organizations through the InPost von Halsky API.
 * Organizations represent business entities that can have multiple shops/points of sale.
 *
 * Note: SDK doesn't have OrganizationsApi, so this service uses direct HTTP calls
 * while following the ApiServiceAbstract pattern for consistency.
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Service\Api;

use Inpost\VonHalsky\Helper\Config;
use Inpost\VonHalsky\Service\Auth\OAuth2Service;
use InpostApi\ApiException;

class OrganizationApiService extends ApiServiceAbstract
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
     * Get all organizations accessible to the authenticated user
     *
     * @param string|null $language Accept-Language header. Default: from config
     * @return array<string, mixed> API response with organizations data and HTTP info
     * @throws \Exception
     */
    public function listOrganizations(?string $language = null): array
    {
        $language ??= $this->configHelper->getDefaultLanguageCode();

        $logContext = $this->buildLogContext([
            'language' => $language,
        ], false); // Organizations endpoint doesn't need org_id

        // Direct HTTP call since SDK doesn't have OrganizationsApi
        // Note: Organizations endpoint is at ROOT level, not under /inpsa/v1/
        $apiUrl = rtrim($this->configHelper->getApiUrl(), '/');
        // Remove /inpsa/v1 suffix - organizations endpoint is at root
        $apiUrl = preg_replace('#/inpsa/v1/?$#', '', $apiUrl);
        $apiUrl = preg_replace('#/inpsa/?$#', '', $apiUrl);
        $url = $apiUrl . '/organizations';

        return $this->executeDirectHttpCall(
            'list organizations',
            $logContext,
            'GET',
            $url,
            null,
            $language
        );
    }

    /**
     * Execute direct HTTP call for endpoints not covered by SDK
     *
     * @param string $operation Operation name for logging
     * @param array $logContext Log context
     * @param string $method HTTP method
     * @param string $url Full URL
     * @param array|null $body Request body (for POST/PATCH)
     * @param string $language Accept-Language header
     * @return array Standardized response array
     */
    private function executeDirectHttpCall(
        string $operation,
        array $logContext,
        string $method,
        string $url,
        ?array $body,
        string $language
    ): array {
        try {
            $this->log('info', "Starting {$operation}", array_merge($logContext, ['url' => $url]));

            $client = new \GuzzleHttp\Client();
            $options = [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->oAuth2Service->getValidAccessToken(),
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'Accept-Language' => $language,
                ],
            ];

            if ($body !== null) {
                $options['body'] = json_encode($body);
            }

            $response = $client->request($method, $url, $options);
            $statusCode = $response->getStatusCode();
            $responseBody = json_decode($response->getBody()->getContents(), true);

            $this->log('info', "{$operation} completed successfully", array_merge($logContext, [
                'status_code' => $statusCode,
                'operation_success' => true,
            ]));

            return [
                'success' => true,
                'data' => $responseBody,
                'status_code' => $statusCode,
                'headers' => $response->getHeaders(),
            ];

        } catch (\GuzzleHttp\Exception\RequestException $e) {
            $response = $e->getResponse();
            $statusCode = $response ? $response->getStatusCode() : 0;
            $responseBody = $response ? $response->getBody()->getContents() : '';

            $this->log('error', "InPost API error during {$operation}", array_merge($logContext, [
                'status_code' => $statusCode,
                'error_message' => $e->getMessage(),
                'response_body' => $responseBody,
            ]));

            return $this->errorHandler->handleApiException(
                new ApiException($e->getMessage(), $statusCode, $response ? $response->getHeaders() : [], $responseBody),
                $operation,
                $logContext
            );
        } catch (\Exception $e) {
            $this->log('error', "Service error during {$operation}", array_merge($logContext, [
                'error' => $e->getMessage(),
            ]));

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    // ========================================================================
    // LEGACY METHOD ALIASES (for backward compatibility during migration)
    // ========================================================================


}
