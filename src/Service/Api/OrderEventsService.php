<?php
/**
 * Order Events Service
 *
 * Service for managing InPost Order Events API operations including listing order events
 * and checking command statuses.
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Service\Api;

use Inpost\VonHalsky\Helper\Config;
use Inpost\VonHalsky\Service\Auth\OAuth2Service;
use InpostApi\Api\OrdersApi;

class OrderEventsService extends ApiServiceAbstract
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
     * List order events from InPost API
     *
     * @param string $language Accept-Language header (default: 'pl')
     * @param string|null $untilId Event ID to fetch events until (exclusive)
     * @param string|null $eventType Filter by event type
     * @param int $limit Number of items to return (default: 100)
     * @return array API response with order events data and HTTP info
     * @throws \Exception
     */
    public function listOrderEvents(
        string $language = 'pl',
        $untilId = null,
        $eventType = null,
        int $limit = 100
    ): array {
        $logContext = $this->buildLogContext([
            'language' => $language,
            'until_id' => $untilId,
            'event_type' => $eventType,
            'limit' => $limit
        ]);

        $self = $this;
        return $this->executeApiCall(
            'list order events',
            $logContext,
            function () use ($self, $language, $untilId, $eventType, $limit) {
                return $self->getOrdersApi()->getOrdersEventsV1WithHttpInfo(
                    $self->getOrganizationId(),
                    $language,
                    $untilId,
                    $eventType,
                    $limit
                );
            }
        );
    }

}
