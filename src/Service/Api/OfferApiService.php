<?php
/**
 * OfferApiService
 *
 * Service for managing product offers through the InPost von Halsky API.
 * Handles offer CRUD operations (create, read, update, close).
 * Offers represent products listed on the InPost marketplace.
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Service\Api;

use Inpost\VonHalsky\Helper\Config;
use Inpost\VonHalsky\Service\Auth\OAuth2Service;
use Inpost\VonHalsky\Service\Offer\CommandErrorFormatter;
use Inpost\VonHalsky\Service\Util\InpostLoggerService;
use InpostApi\Api\OfferAttachmentsApi;
use InpostApi\Api\OffersApi;
use InpostApi\Model\AttachmentType;
use InpostApi\ApiException;

class OfferApiService extends ApiServiceAbstract
{
    private InpostLoggerService $inpostLogger;

    public function __construct(
        OAuth2Service $oAuth2Service,
        Config $configHelper,
        ApiErrorHandler $errorHandler,
        InpostLoggerService $inpostLogger,
        ?HttpClientFactory $httpClientFactory = null
    ) {
        parent::__construct($oAuth2Service, $configHelper, $errorHandler, $httpClientFactory);
        $this->inpostLogger = $inpostLogger;
    }

    /**
     * Get configured Offers API instance
     *
     * @return OffersApi
     * @throws \Exception
     */
    private function getOffersApi(): OffersApi
    {
        return new OffersApi($this->getHttpClient(), $this->getApiConfiguration());
    }

    /**
     * Create a new offer
     *
     * @param array<string, mixed> $data Offer data (product, price, stock, attributes, etc.)
     * @param string|null $language Accept-Language header (default: from config)
     * @return array<string, mixed> API response with created offer data and HTTP info
     * @throws \Exception
     */
    public function createOffer(array $data, ?string $language = null): array
    {
        $language ??= $this->configHelper->getDefaultLanguageCode();
        $orgId = $this->getOrganizationId();

        // Debug logging
        $this->inpostLogger->info('API Request - Create Offer', [
            'organization_id' => $orgId,
            'language' => $language,
            'product_name' => $data['product']['name'] ?? 'unknown',
            'payload' => json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        $logContext = $this->buildLogContext([
            'organization_id' => $orgId,
            'language' => $language,
            'product_name' => $data['product']['name'] ?? 'unknown',
        ]);

        $result = $this->executeApiCall(
            'create offer',
            $logContext,
            fn() => $this->getOffersApi()->postOffersV1WithHttpInfo(
                $orgId,
                $data,
                $language
            )
        );

        // Debug logging response
        if ($result['success']) {
            $this->inpostLogger->info('API Response - Create Offer', [
                'success' => true,
                'status_code' => $result['status_code'] ?? null,
            ]);
        }

        return $result;
    }

    /**
     * Update/patch an existing offer
     * Note: Uses direct HTTP due to Content-Type requirement (application/merge-patch+json)
     *
     * @param string $offerId Offer ID
     * @param array<string, mixed> $data Updated offer data
     * @param string|null $language Accept-Language header (default: from config)
     * @return array<string, mixed> API response with updated offer data and HTTP info
     * @throws \Exception
     */
    public function patchOffer(string $offerId, array $data, ?string $language = null): array
    {
        $language ??= $this->configHelper->getDefaultLanguageCode();
        $orgId = $this->getOrganizationId();

        // Debug logging
        $this->inpostLogger->info('API Request - Patch Offer', [
            'organization_id' => $orgId,
            'offer_id' => $offerId,
            'language' => $language,
            'payload' => json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        $logContext = $this->buildLogContext([
            'organization_id' => $orgId,
            'offer_id' => $offerId,
            'language' => $language,
        ]);

        // BYPASS SDK: Use direct Guzzle HTTP call with correct Content-Type
        // SDK doesn't support application/merge-patch+json content type properly
        $apiUrl = rtrim($this->configHelper->getApiUrl(), '/');
        $url = sprintf('%s/v1/organizations/%s/offers/%s', $apiUrl, $orgId, $offerId);

        $this->log('info', 'Starting patch offer', array_merge($logContext, [
            'url' => $url,
            'content_type' => 'application/merge-patch+json',
        ]));

        try {
            $client = new \GuzzleHttp\Client();
            $response = $client->request('PATCH', $url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->oAuth2Service->getValidAccessToken(),
                    'Content-Type' => 'application/merge-patch+json',
                    'Accept' => 'application/json',
                    'Accept-Language' => $language,
                ],
                'body' => json_encode($data),
            ]);

            $statusCode = $response->getStatusCode();
            $body = json_decode($response->getBody()->getContents(), true);

            $this->log('info', 'patch offer completed successfully', array_merge($logContext, [
                'status_code' => $statusCode,
                'operation_success' => true,
            ]));

            $this->inpostLogger->info('API Response - Patch Offer', [
                'success' => true,
                'status_code' => $statusCode,
            ]);

            return [
                'success' => true,
                'data' => $body,
                'status_code' => $statusCode,
                'headers' => $response->getHeaders(),
            ];
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            $response = $e->getResponse();
            $statusCode = $response ? $response->getStatusCode() : 0;
            $responseBody = $response ? $response->getBody()->getContents() : '';

            $this->log('error', 'InPost API error during patch offer (direct HTTP)', array_merge($logContext, [
                'status_code' => $statusCode,
                'error_message' => $e->getMessage(),
                'response_body' => $responseBody,
            ]));

            throw new ApiException(
                $e->getMessage(),
                $statusCode,
                $response ? $response->getHeaders() : [],
                $responseBody
            );
        }
    }

    /**
     * Get offers with optional filters
     *
     * @param string|null $language Accept-Language header (default: from config)
     * @param string|null $offerStatus Filter by offer status
     * @param int $limit Number of items to return (default: 10)
     * @param int $offset Offset for pagination (default: 0)
     * @param array|null $sort Sort parameters
     * @return array<string, mixed> API response with offers list and HTTP info
     * @throws \Exception
     */
    public function listOffers(
        ?string $language = null,
        ?string $offerStatus = null,
        int $limit = 10,
        int $offset = 0,
        ?array $sort = null
    ): array {
        $language ??= $this->configHelper->getDefaultLanguageCode();
        $orgId = $this->getOrganizationId();

        $logContext = $this->buildLogContext([
            'organization_id' => $orgId,
            'language' => $language,
            'offer_status' => $offerStatus,
            'limit' => $limit,
            'offset' => $offset,
            'sort' => $sort,
        ]);

        return $this->executeApiCall(
            'list offers',
            $logContext,
            fn() => $this->getOffersApi()->getOffersV1WithHttpInfo(
                $orgId,
                $language,
                $offerStatus,
                $limit,
                $offset,
                $sort
            )
        );
    }

    /**
     * Get a specific offer by ID
     *
     * @param string $offerId Offer ID
     * @param string|null $language Accept-Language header (default: from config)
     * @return array<string, mixed> API response with offer data and HTTP info
     * @throws \Exception
     */
    public function getOffer(string $offerId, ?string $language = null): array
    {
        $language ??= $this->configHelper->getDefaultLanguageCode();
        $orgId = $this->getOrganizationId();

        $logContext = $this->buildLogContext([
            'organization_id' => $orgId,
            'offer_id' => $offerId,
            'language' => $language,
        ]);

        return $this->executeApiCall(
            'get offer',
            $logContext,
            fn() => $this->getOffersApi()->getOffersByIdV1WithHttpInfo(
                $orgId,
                $offerId,
                $language
            )
        );
    }

    /**
     * Fetch product hints from InPost (/offers/hint) for productization.
     *
     * Note the SDK parameter order: accept-language is SECOND, unlike getOffer().
     *
     * @param string|null $ean
     * @param string|null $name
     * @param string|null $mpn
     * @param int $limit
     * @param int $offset
     * @param string|null $language
     * @return array<string, mixed> API response envelope (success/data/...)
     */
    public function getOfferHints(
        ?string $ean = null,
        ?string $name = null,
        ?string $mpn = null,
        int $limit = 10,
        int $offset = 0,
        ?string $language = null
    ): array {
        $language ??= $this->configHelper->getDefaultLanguageCode();
        $orgId = $this->getOrganizationId();

        $logContext = $this->buildLogContext([
            'organization_id' => $orgId,
            'ean' => $ean,
            'name' => $name,
            'mpn' => $mpn,
            'language' => $language,
        ]);

        return $this->executeApiCall(
            'get offer hints',
            $logContext,
            fn() => $this->getOffersApi()->getOffersHintV1WithHttpInfo(
                $orgId,
                $language,
                $limit,
                $offset,
                $ean,
                $mpn,
                $name
            )
        );
    }

    /**
     * Close an offer (soft delete)
     *
     * @param string $offerId Offer ID
     * @param string|null $language Accept-Language header (default: from config)
     * @return array<string, mixed> API response with closed offer data and HTTP info
     * @throws \Exception
     */
    public function closeOffer(string $offerId, ?string $language = null): array
    {
        $language ??= $this->configHelper->getDefaultLanguageCode();
        $orgId = $this->getOrganizationId();

        $logContext = $this->buildLogContext([
            'organization_id' => $orgId,
            'offer_id' => $offerId,
            'language' => $language,
        ]);

        $this->inpostLogger->info('API Request - Close Offer', [
            'organization_id' => $orgId,
            'offer_id' => $offerId,
            'language' => $language,
        ]);

        return $this->executeApiCall(
            'close offer',
            $logContext,
            fn() => $this->getOffersApi()->postOffersCloseByOfferIdV1WithHttpInfo(
                $orgId,
                $offerId,
                $language
            )
        );
    }

    /**
     * Reopen a closed offer
     *
     * @param string $offerId Offer ID
     * @param string|null $language Accept-Language header (default: from config)
     * @return array<string, mixed> API response with reopened offer data and HTTP info
     * @throws \Exception
     */
    public function reopenOffer(string $offerId, ?string $language = null): array
    {
        $language ??= $this->configHelper->getDefaultLanguageCode();
        $orgId = $this->getOrganizationId();

        $logContext = $this->buildLogContext([
            'organization_id' => $orgId,
            'offer_id' => $offerId,
            'language' => $language,
        ]);

        return $this->executeApiCall(
            'reopen offer',
            $logContext,
            fn() => $this->getOffersApi()->postOffersReopenByOfferIdV1WithHttpInfo(
                $orgId,
                $offerId,
                $language
            )
        );
    }

    /**
     * Get offer command status
     *
     * @param string $commandId Command ID to check status for
     * @param string|null $language Accept-Language header (default: from config)
     * @return array<string, mixed> API response with command status and HTTP info
     * @throws \Exception
     */
    public function getOfferCommandStatus(string $commandId, ?string $language = null): array
    {
        $language ??= $this->configHelper->getDefaultLanguageCode();
        $orgId = $this->getOrganizationId();

        $logContext = $this->buildLogContext([
            'organization_id' => $orgId,
            'command_id' => $commandId,
            'language' => $language,
        ]);

        $response = $this->executeApiCall(
            'get offer command status',
            $logContext,
            fn() => $this->getOffersApi()->getOffersCommandsByIdV1WithHttpInfo(
                $orgId,
                $commandId,
                $language
            )
        );

        // API 1.5.11+ returns an optional `errors` field on FAILURE commands
        // (OfferCommandStatusDetails). Expose it so callers can log / surface why
        // the offer was not created.
        if ($response['success'] ?? false) {
            $commandErrors = CommandErrorFormatter::toArray($response['data'] ?? null);
            if ($commandErrors !== []) {
                $response['command_errors'] = $commandErrors;
                $response['command_error_text'] = CommandErrorFormatter::format($commandErrors);
            }
        }

        return $response;
    }

    /**
     * Get configured Offer Attachments API instance
     */
    private function getOfferAttachmentsApi(): OfferAttachmentsApi
    {
        return new OfferAttachmentsApi($this->getHttpClient(), $this->getApiConfiguration());
    }

    /**
     * Upload a file attachment (document, label...) to an offer
     *
     * Processed asynchronously by the API: the response carries a command ID,
     * the attachment appears in the list once the command succeeds.
     *
     * @param string $offerId InPost offer ID (UUID)
     * @param string $path Absolute path of the file to send
     * @param string $fileName File name shown in InPost (files on disk may be stored under a hash)
     * @param string $mime File MIME type
     * @param string $attachmentType One of AttachmentType constants
     * @param int|null $priority Display order (1-1000), appended to the end when null
     * @param string|null $language Accept-Language (default: from config)
     * @return array ['success' => bool, 'data' => array{commandId?: string, status?: string}|null, 'error' => string|null, 'status_code' => int|null]
     */
    public function uploadOfferAttachment(
        string $offerId,
        string $path,
        string $fileName,
        string $mime,
        string $attachmentType,
        ?int $priority = null,
        ?string $language = null
    ): array {
        $language ??= $this->configHelper->getDefaultLanguageCode();
        $orgId = $this->getOrganizationId();

        $logContext = $this->buildLogContext([
            'organization_id' => $orgId,
            'offer_id' => $offerId,
            'filename' => $fileName,
            'attachment_type' => $attachmentType,
        ]);

        if (!in_array($attachmentType, AttachmentType::getAllowableEnumValues(), true)) {
            return ['success' => false, 'error' => 'Unknown attachment type: ' . $attachmentType];
        }

        $handle = @fopen($path, 'r');
        if ($handle === false) {
            $this->log('error', 'upload offer attachment: file not readable', array_merge($logContext, ['path' => $path]));

            return ['success' => false, 'error' => 'File not readable: ' . $fileName];
        }

        // BYPASS SDK: the SDK takes the multipart filename from the file object,
        // and PrestaShop stores attachments on disk under a hash without extension
        $query = ['attachmentType' => $attachmentType];
        if ($priority !== null) {
            $query['priority'] = $priority;
        }
        $apiUrl = rtrim($this->configHelper->getApiUrl(), '/');
        $url = sprintf(
            '%s/v1/organizations/%s/offers/%s/attachments?%s',
            $apiUrl,
            $orgId,
            $offerId,
            http_build_query($query)
        );

        $this->log('info', 'Starting upload offer attachment', array_merge($logContext, ['url' => $url]));

        try {
            $client = new \GuzzleHttp\Client(['timeout' => 120, 'connect_timeout' => 10]);
            $response = $client->request('POST', $url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->oAuth2Service->getValidAccessToken(),
                    'Accept' => 'application/json',
                    'Accept-Language' => $language,
                ],
                'multipart' => [
                    [
                        'name' => 'file',
                        'contents' => $handle,
                        'filename' => $fileName,
                        'headers' => ['Content-Type' => $mime],
                    ],
                    [
                        'name' => 'filename',
                        'contents' => $fileName,
                    ],
                ],
            ]);

            $statusCode = $response->getStatusCode();
            $body = json_decode($response->getBody()->getContents(), true);

            $this->log('info', 'upload offer attachment completed successfully', array_merge($logContext, [
                'status_code' => $statusCode,
                'operation_success' => true,
                'command_id' => $body['commandId'] ?? null,
                'command_status' => $body['status'] ?? null,
            ]));

            return [
                'success' => true,
                'data' => is_array($body) ? $body : null,
                'status_code' => $statusCode,
            ];
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            $statusCode = $e->hasResponse() ? $e->getResponse()->getStatusCode() : 0;
            $errorBody = $e->hasResponse() ? (string) $e->getResponse()->getBody() : '';

            $this->log('error', 'upload offer attachment failed', array_merge($logContext, [
                'status_code' => $statusCode,
                'error' => $e->getMessage(),
                'response_body' => $errorBody,
            ]));

            return [
                'success' => false,
                'error' => $this->extractProblemMessage($errorBody)
                    ?? ($e->hasResponse() ? '[' . $statusCode . '] ' . $e->getResponse()->getReasonPhrase() : $e->getMessage()),
                'status_code' => $statusCode,
            ];
        } catch (\Exception $e) {
            $this->log('error', 'upload offer attachment exception', array_merge($logContext, [
                'error' => $e->getMessage(),
            ]));

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
    }

    /**
     * Short message from an API error body (problem+json or {errorCode, errorMessage})
     */
    private function extractProblemMessage(string $body): ?string
    {
        $data = json_decode($body, true);
        if (!is_array($data)) {
            return null;
        }

        $message = $data['errorMessage'] ?? $data['detail'] ?? $data['title'] ?? null;
        if (!is_string($message) || $message === '') {
            return null;
        }

        $status = isset($data['status']) ? '[' . $data['status'] . '] ' : '';

        return $status . $message;
    }

    /**
     * List all attachments for an offer
     *
     * @param string $offerId InPost offer ID (UUID)
     * @param string|null $language Accept-Language (default: from config)
     * @param int $limit Max attachments to return (1-30, default: 30)
     * @param int $offset Offset of the first attachment (pagination)
     * @return array ['success' => bool, 'data' => Attachments|null, 'error' => string|null, 'status_code' => int|null]
     */
    public function listOfferAttachments(string $offerId, ?string $language = null, int $limit = 30, int $offset = 0): array
    {
        $language ??= $this->configHelper->getDefaultLanguageCode();
        $orgId = $this->getOrganizationId();

        $logContext = $this->buildLogContext([
            'organization_id' => $orgId,
            'offer_id' => $offerId,
        ]);

        return $this->executeApiCall(
            'list offer attachments',
            $logContext,
            fn() => $this->getOfferAttachmentsApi()->getOffersAttachmentsByOfferIdV1WithHttpInfo(
                $orgId,
                $offerId,
                $language,
                $limit,
                $offset
            )
        );
    }

    /**
     * All attachments of an offer, following pagination
     *
     * @param string $offerId InPost offer ID (UUID)
     * @return array ['success' => bool, 'data' => \InpostApi\Model\AttachmentInfo[], 'error' => string|null]
     */
    public function listAllOfferAttachments(string $offerId): array
    {
        $all = [];
        $limit = 30;
        // Hard stop well above any realistic attachment count
        for ($offset = 0; $offset < 1000; $offset += $limit) {
            $response = $this->listOfferAttachments($offerId, null, $limit, $offset);
            if (!($response['success'] ?? false)) {
                return ['success' => false, 'data' => $all, 'error' => $response['error'] ?? 'Unknown error'];
            }

            $page = $response['data'] && method_exists($response['data'], 'getData')
                ? ($response['data']->getData() ?? [])
                : [];
            array_push($all, ...$page);

            if (count($page) < $limit) {
                break;
            }
        }

        return ['success' => true, 'data' => $all, 'error' => null];
    }

    /**
     * Delete an attachment from an offer
     *
     * @param string $offerId InPost offer ID (UUID)
     * @param string $attachmentId Attachment ID (UUID)
     * @param string|null $language Accept-Language (default: from config)
     * @return array ['success' => bool, 'data' => array|null, 'error' => string|null, 'status_code' => int|null]
     */
    public function deleteOfferAttachment(string $offerId, string $attachmentId, ?string $language = null): array
    {
        $language ??= $this->configHelper->getDefaultLanguageCode();
        $orgId = $this->getOrganizationId();

        $logContext = $this->buildLogContext([
            'organization_id' => $orgId,
            'offer_id' => $offerId,
            'attachment_id' => $attachmentId,
        ]);

        // BYPASS SDK: the SDK sends "Accept: application/problem+json" only,
        // which the API answers with 406 Not Acceptable
        $apiUrl = rtrim($this->configHelper->getApiUrl(), '/');
        $url = sprintf(
            '%s/v1/organizations/%s/offers/%s/attachments/%s',
            $apiUrl,
            $orgId,
            $offerId,
            rawurlencode($attachmentId)
        );

        try {
            $client = new \GuzzleHttp\Client(['timeout' => 30, 'connect_timeout' => 10]);
            $response = $client->request('DELETE', $url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->oAuth2Service->getValidAccessToken(),
                    'Accept' => 'application/json, application/problem+json',
                    'Accept-Language' => $language,
                ],
            ]);

            $statusCode = $response->getStatusCode();
            $body = json_decode((string) $response->getBody(), true);

            $this->log('info', 'delete offer attachment completed successfully', array_merge($logContext, [
                'status_code' => $statusCode,
                'operation_success' => true,
            ]));

            return [
                'success' => true,
                'data' => is_array($body) ? $body : null,
                'status_code' => $statusCode,
            ];
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            $statusCode = $e->hasResponse() ? $e->getResponse()->getStatusCode() : 0;
            $errorBody = $e->hasResponse() ? (string) $e->getResponse()->getBody() : '';

            $this->log('error', 'delete offer attachment failed', array_merge($logContext, [
                'status_code' => $statusCode,
                'error' => $e->getMessage(),
                'response_body' => $errorBody,
            ]));

            return [
                'success' => false,
                'error' => $this->extractProblemMessage($errorBody)
                    ?? ($e->hasResponse() ? '[' . $statusCode . '] ' . $e->getResponse()->getReasonPhrase() : $e->getMessage()),
                'status_code' => $statusCode,
            ];
        } catch (\Exception $e) {
            $this->log('error', 'delete offer attachment exception', array_merge($logContext, [
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
    // These map old method signatures to new SDK methods
    // TODO: Remove after all callers are updated
    // ========================================================================



}
