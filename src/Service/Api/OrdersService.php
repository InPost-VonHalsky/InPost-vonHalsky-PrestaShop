<?php
/**
 * Orders Service
 *
 * Read access to the InPost Orders API (list and get orders). Orders arrive
 * already accepted - there is no merchant accept/refuse step.
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Service\Api;

use InpostApi\Api\OrdersApi;
use Inpost\VonHalsky\Helper\Config;
use Inpost\VonHalsky\Service\Auth\OAuth2Service;

class OrdersService extends ApiServiceAbstract
{
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
        $httpClientFactory = null
    ) {
        parent::__construct($oAuth2Service, $configHelper, $errorHandler, $httpClientFactory);
    }

    /**
     * Get configured Orders API instance
     *
     * @return OrdersApi
     * @throws \Exception
     */
    private function getOrdersApi(): OrdersApi
    {
        return new OrdersApi($this->getHttpClient(), $this->getApiConfiguration());
    }

    /**
     * List all orders from InPost API
     *
     * @param string $language Accept-Language header (default: 'pl')
     * @param string|null $orderStatus Filter by order status
     * @param int $limit Number of items to return (default: 10)
     * @param int $offset Offset for pagination (default: 0)
     * @param array|null $sort Sort parameters
     * @param \DateTime|null $updatedAtGte Only orders updated at or after this time
     * @return array API response with orders data and HTTP info
     * @throws \Exception
     */
    public function listOrders(
        string $language = 'pl',
        $orderStatus = null,
        int $limit = 10,
        int $offset = 0,
        $sort = null,
        ?\DateTime $updatedAtGte = null
    ): array {
        $logContext = $this->buildLogContext([
            'language' => $language,
            'order_status' => $orderStatus,
            'limit' => $limit,
            'offset' => $offset,
            'sort' => $sort,
            'updated_at_gte' => $updatedAtGte !== null ? $updatedAtGte->format(DATE_ATOM) : null,
        ]);

        // Respect API maximum page size of 30
        $effectiveLimit = max(1, min($limit, 30));

        $self = $this;
        return $this->executeApiCall(
            'list orders',
            $logContext,
            function () use ($self, $language, $orderStatus, $effectiveLimit, $offset, $sort, $updatedAtGte) {
                return $self->getOrdersApi()->getOrdersV1WithHttpInfo(
                    $self->getOrganizationId(),
                    $language,
                    $orderStatus,
                    null, // paymentStatus filter (added in InPost API 1.7.x)
                    $effectiveLimit,
                    $offset,
                    $sort,
                    // As an ISO 8601 UTC string: the generated SDK cannot put a \DateTime
                    // into the query string ("could not be converted to string")
                    $updatedAtGte !== null
                        ? (clone $updatedAtGte)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s\\Z')
                        : null
                );
            }
        );
    }

    /**
     * Get single order information from InPost API
     *
     * @param string $orderId Order ID to fetch
     * @param string $language Accept-Language header (default: 'pl')
     * @return array API response with order data and HTTP info
     * @throws \Exception
     */
    public function getOrder(string $orderId, string $language = 'pl'): array
    {
        $logContext = $this->buildLogContext([
            'order_id' => $orderId,
            'language' => $language
        ]);

        $self = $this;
        return $this->executeApiCall(
            'get order',
            $logContext,
            function () use ($self, $orderId, $language) {
                return $self->getOrdersApi()->getOrdersByIdV1WithHttpInfo(
                    $self->getOrganizationId(),
                    $orderId,
                    $language
                );
            }
        );
    }
}
