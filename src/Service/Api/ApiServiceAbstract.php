<?php
/**
 * API Service Abstract
 *
 * Abstract base class for InPost API services with standardized error handling,
 * logging, and authentication management.
 *
 * @author    Marcin Bach
 * @copyright 2025 Marcin Bach
 * @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Service\Api;

use Inpost\VonHalsky\Service\Util\ShopLog;
use Inpost\VonHalsky\Helper\Config;
use Inpost\VonHalsky\Service\Auth\OAuth2Service;
use InpostApi\ApiException;
use InpostApi\Configuration;
use Inpost\VonHalsky\Service\Util\DebugLog;

abstract class ApiServiceAbstract
{
    protected OAuth2Service $oAuth2Service;
    protected Config $configHelper;
    protected ApiErrorHandler $errorHandler;
    protected ?HttpClientFactory $httpClientFactory;

    /**
     * Constructor
     *
     * @param OAuth2Service $oAuth2Service
     * @param Config $configHelper
     * @param ApiErrorHandler $errorHandler
     * @param HttpClientFactory|null $httpClientFactory
     */
    public function __construct(
        OAuth2Service $oAuth2Service,
        Config $configHelper,
        ApiErrorHandler $errorHandler,
        ?HttpClientFactory $httpClientFactory = null
    ) {
        $this->oAuth2Service = $oAuth2Service;
        $this->configHelper = $configHelper;
        $this->errorHandler = $errorHandler;
        $this->httpClientFactory = $httpClientFactory;
    }

    /**
     * Get configured InPost API Configuration instance
     *
     * @return Configuration
     * @throws \Exception
     */
    protected function getApiConfiguration(): Configuration
    {
        // Get valid access token
        $accessToken = $this->oAuth2Service->getValidAccessToken();

        if (!$accessToken) {
            throw new \Exception('No valid access token available. Please connect to InPost first.');
        }

        // Configure InPost API client
        $config = new Configuration();
        // SDK adds /v1 to paths, so strip it from base URL if present
        $apiUrl = rtrim($this->configHelper->getApiUrl(), '/');
        if (str_ends_with($apiUrl, '/v1')) {
            $apiUrl = substr($apiUrl, 0, -3);
        }
        $config->setHost($apiUrl);
        $config->setAccessToken($accessToken);

        // Set custom user agent to identify our integration
        $userAgent = 'PrestaShop-InPost-Module/1.0.0';
        if ($this->configHelper->isTestModeEnabled()) {
            $userAgent .= ' (test-mode)';
        }
        $config->setUserAgent($userAgent);

        return $config;
    }

    /**
     * Get HTTP client with test mode support
     *
     * @return \GuzzleHttp\ClientInterface|null
     */
    protected function getHttpClient(): ?\GuzzleHttp\ClientInterface
    {
        if (!$this->httpClientFactory) {
            return null; // Use default client
        }

        return $this->httpClientFactory->createClient();
    }

    /**
     * Get organization ID from configuration
     *
     * @param int|null $shopId Optional shop ID to get configuration from
     * @return string
     * @throws \Exception
     */
    public function getOrganizationId(?int $shopId = null): string
    {
        // Get from configuration for specific shop
        $organizationId = $this->configHelper->getOrganizationId($shopId);

        // Check if InPost is explicitly disabled for this shop
        if ($organizationId === 'disabled') {
            throw new \Exception('InPost von Halsky is disabled for this shop.');
        }

        if (!$organizationId) {
            throw new \Exception('Organization ID not configured. Please check your InPost von Halsky configuration.');
        }

        return $organizationId;
    }

    /**
     * Handle API call execution with standardized error handling and logging
     *
     * @param string $operation Operation name for logging
     * @param array $logContext Additional context for logging
     * @param callable $apiCall The API call to execute
     * @return array Standardized response array
     */
    protected function executeApiCall(string $operation, array $logContext, callable $apiCall): array
    {
        try {
            // Expecting [$result, $statusCode, $headers] (SDK *WithHttpInfo methods)
            [$result, $statusCode, $headers] = $apiCall();
        } catch (ApiException $e) {
            return $this->errorHandler->handleApiException($e, $operation, $logContext);
        } catch (\Exception $e) {
            $this->log('error', "Service error during {$operation}", array_merge($logContext, [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'operation_success' => false,
            ]));

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }

        // The SDK may return 4xx/5xx without throwing
        if ($statusCode >= 400) {
            return $this->httpErrorResponse($operation, $logContext, $result, (int) $statusCode);
        }

        $this->log('info', "{$operation} completed successfully", array_merge($logContext, [
            'status_code' => $statusCode,
            'items_count' => $this->getResultCount($result),
        ]));

        return [
            'success' => true,
            'data' => $result,
            'status_code' => $statusCode,
            'headers' => $headers,
        ];
    }

    /**
     * Failure result for an HTTP error status returned by the SDK
     *
     * @param mixed $result SDK error model (or null)
     */
    private function httpErrorResponse(string $operation, array $logContext, $result, int $statusCode): array
    {
        $errorMsg = $this->extractErrorMessage($result);

        $this->log('error', "HTTP error during {$operation}", array_merge($logContext, [
            'status_code' => $statusCode,
            'error' => $errorMsg,
        ]));

        return [
            'success' => false,
            'error' => "[{$statusCode}] {$errorMsg}",
            'status_code' => $statusCode,
            'data' => $result,
        ];
    }

    /**
     * Error message of an SDK error model, with the per-field details the API sends
     * alongside generic messages (e.g. "Failed to read HTTP message") appended.
     *
     * @param mixed $result SDK error model (or null)
     */
    private function extractErrorMessage($result): string
    {
        $errorMsg = 'API returned error status code';
        if (!is_object($result)) {
            return $errorMsg;
        }

        if (method_exists($result, 'getErrorMessage')) {
            $errorMsg = $result->getErrorMessage() ?? $errorMsg;
        }

        $details = method_exists($result, 'getDetails') ? $this->formatErrorDetails((array) $result->getDetails()) : '';

        return $details !== '' ? $errorMsg . ' (' . $details . ')' : $errorMsg;
    }

    /**
     * "field: detail; field: detail" from SDK error detail models
     */
    private function formatErrorDetails(array $details): string
    {
        $parts = [];
        foreach ($details as $detail) {
            if (!is_object($detail) || !method_exists($detail, 'getDetail')) {
                continue;
            }
            $field = method_exists($detail, 'getField') ? $detail->getField() : null;
            $parts[] = ($field ? $field . ': ' : '') . $detail->getDetail();
        }

        return implode('; ', $parts);
    }

    /**
     * Build log context with organization ID if available
     *
     * @param array $additionalContext Additional context to include
     * @param bool $includeOrganizationId Whether to include organization ID (default: true)
     * @return array Complete log context
     */
    protected function buildLogContext(array $additionalContext = [], bool $includeOrganizationId = true): array
    {
        $context = $additionalContext;

        if ($includeOrganizationId) {
            $context['organization_id'] = $this->configHelper->getOrganizationId() ?? 'unknown';
        }

        return $context;
    }


    /**
     * Get count from API result (handles both SDK objects and arrays)
     *
     * @param mixed $result API response result
     * @return int|null Count of items or null if not countable
     */
    private function getResultCount($result): ?int
    {
        // SDK response object with getData() method
        if ($result && is_object($result) && method_exists($result, 'getData')) {
            $data = $result->getData();
            return is_array($data) || $data instanceof \Countable ? count($data) : null;
        }

        // Plain array result
        if (is_array($result)) {
            return count($result);
        }

        return null;
    }

    /**
     * Log message using PrestaShop logger
     *
     * @param string $level Log level (info, warning, error)
     * @param string $message Log message
     * @param array $context Additional context
     * @return void
     */
    protected function log(string $level, string $message, array $context = []): void
    {
        $contextString = !empty($context) ? ' | Context: ' . json_encode($context) : '';
        $fullMessage = "[InPost SA] {$message}{$contextString}";

        switch ($level) {
            case 'error':
                ShopLog::add($fullMessage, 3); // 3 = error
                break;
            case 'warning':
                ShopLog::add($fullMessage, 2); // 2 = warning
                break;
            case 'info':
            default:
                // Per-call info only in debug mode - every ps_log entry costs a full table scan
                DebugLog::info($fullMessage);
                break;
        }
    }
}
