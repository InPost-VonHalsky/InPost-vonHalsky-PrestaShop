<?php
/**
 * API Error Handler
 *
 * Handles API exceptions and errors with standardized logging and error responses
 *
 * @author    Marcin Bach
 * @copyright 2025 Marcin Bach
 * @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Service\Api;

use Inpost\VonHalsky\Service\Util\ShopLog;
use InpostApi\ApiException;

class ApiErrorHandler
{
    /**
     * Handle API exception and extract relevant information
     *
     * @param ApiException $exception
     * @param string $operation
     * @param array $context
     * @return array Enhanced error response
     */
    public function handleApiException(ApiException $exception, string $operation, array $context = []): array
    {
        $statusCode = $exception->getCode();
        $responseBody = $exception->getResponseBody();
        $responseHeaders = $exception->getResponseHeaders() ?? [];

        // Extract rate limiting information
        $rateLimitInfo = $this->extractRateLimitInfo($responseHeaders);

        // Parse error response body
        $errorDetails = $this->parseErrorResponse($responseBody);

        // Build enhanced error context
        $errorContext = array_merge($context, [
            'status_code' => $statusCode,
            'error_message' => $exception->getMessage(),
            'response_body' => $responseBody,
            'operation_success' => false,
            'error_type' => $this->getErrorType($statusCode),
            'rate_limit_info' => $rateLimitInfo,
            'error_details' => $errorDetails
        ]);

        // Log with appropriate level based on error type
        $this->logError($operation, $statusCode, $errorContext);

        // Return standardized error response
        return [
            'success' => false,
            'error' => $exception->getMessage(),
            'status_code' => $statusCode,
            'error_type' => $this->getErrorType($statusCode),
            'response_body' => $responseBody,
            'rate_limit_info' => $rateLimitInfo,
            'error_details' => $errorDetails,
            'retryable' => $this->isRetryableError($statusCode),
            'retry_after' => $this->getRetryAfter($responseHeaders)
        ];
    }

    /**
     * Extract rate limiting information from response headers
     *
     * @param array $headers
     * @return array
     */
    private function extractRateLimitInfo(array $headers): array
    {
        $rateLimitInfo = [];

        // Extract rate limit headers (case-insensitive)
        $headerMappings = [
            'x-ratelimit-limit' => 'limit',
            'x-ratelimit-remaining' => 'remaining',
            'x-ratelimit-reset' => 'reset',
            'retry-after' => 'retry_after'
        ];

        foreach ($headers as $headerName => $headerValue) {
            $headerNameLower = strtolower($headerName);
            if (isset($headerMappings[$headerNameLower])) {
                $key = $headerMappings[$headerNameLower];
                $rateLimitInfo[$key] = is_array($headerValue) ? $headerValue[0] : $headerValue;
            }
        }

        return $rateLimitInfo;
    }

    /**
     * Parse error response body to extract detailed error information
     *
     * @param string|null $responseBody
     * @return array
     */
    private function parseErrorResponse(?string $responseBody): array
    {
        if (!$responseBody) {
            return [];
        }

        try {
            $decoded = json_decode($responseBody, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        } catch (\Exception $e) {
            // If JSON parsing fails, return raw body
        }

        return ['raw_response' => $responseBody];
    }

    /**
     * Get error type based on HTTP status code
     *
     * @param int $statusCode
     * @return string
     */
    private function getErrorType(int $statusCode): string
    {
        $errorTypes = [
            400 => 'BAD_REQUEST',
            401 => 'UNAUTHORIZED',
            403 => 'FORBIDDEN',
            404 => 'NOT_FOUND',
            409 => 'CONFLICT',
            422 => 'UNPROCESSABLE_ENTITY',
            429 => 'TOO_MANY_REQUESTS',
            500 => 'INTERNAL_SERVER_ERROR',
            502 => 'BAD_GATEWAY',
            503 => 'SERVICE_UNAVAILABLE',
            504 => 'GATEWAY_TIMEOUT'
        ];

        return $errorTypes[$statusCode] ?? 'UNKNOWN_ERROR';
    }

    /**
     * Determine if error is retryable
     *
     * @param int $statusCode
     * @return bool
     */
    private function isRetryableError(int $statusCode): bool
    {
        // Retryable errors: rate limiting, server errors, timeouts
        return in_array($statusCode, [429, 500, 502, 503, 504]);
    }

    /**
     * Get retry-after value from headers
     *
     * @param array $headers
     * @return int|null Seconds to wait before retry
     */
    private function getRetryAfter(array $headers): ?int
    {
        foreach ($headers as $headerName => $headerValue) {
            if (strtolower($headerName) === 'retry-after') {
                $value = is_array($headerValue) ? $headerValue[0] : $headerValue;
                return is_numeric($value) ? (int) $value : null;
            }
        }

        return null;
    }

    /**
     * Log error with appropriate level
     *
     * @param string $operation
     * @param int $statusCode
     * @param array $context
     * @return void
     */
    private function logError(string $operation, int $statusCode, array $context): void
    {
        $message = "[InPost SA] API error during {$operation}";
        $contextString = !empty($context) ? ' | ' . json_encode($context) : '';
        $fullMessage = $message . $contextString;

        // Use different log levels based on error type
        if ($statusCode >= 500) {
            // Server errors - error level (3)
            ShopLog::add($fullMessage, 3);
        } elseif ($statusCode === 429) {
            // Rate limiting - warning level (2)
            ShopLog::add($fullMessage, 2);
        } elseif (in_array($statusCode, [401, 403])) {
            // Auth errors - error level (3)
            ShopLog::add($fullMessage, 3);
        } else {
            // Client errors - info level (1)
            ShopLog::add($fullMessage, 1);
        }
    }

}
