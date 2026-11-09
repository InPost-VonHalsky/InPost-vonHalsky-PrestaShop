<?php
/**
 * OfferEventsService
 *
 * Service for retrieving offer events from the InPost von Halsky API.
 * Events represent state changes and important occurrences for offers.
 * Events are useful for synchronization and audit trails.
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Service\Api;

use Inpost\VonHalsky\Helper\Config;
use Inpost\VonHalsky\Service\Auth\OAuth2Service;
use InpostApi\Api\OffersApi;

class OfferEventsService extends ApiServiceAbstract
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
     * List offer events from InPost API
     *
     * @param string $language Accept-Language header (default: 'pl')
     * @param string|null $untilId Event ID to fetch events until (exclusive, for pagination)
     * @param array|null $eventTypes Filter by event types (e.g., ['CREATED', 'PUBLISHED'])
     * @param int $limit Number of items to return (default: 100, max: 1000)
     * @return array API response with offer events data and HTTP info
     * @throws \Exception
     */
    public function listOfferEvents(
        string $language = 'pl',
        ?string $untilId = null,
        ?array $eventTypes = null,
        int $limit = 100
    ): array {
        $logContext = $this->buildLogContext([
            'language' => $language,
            'until_id' => $untilId,
            'event_types' => $eventTypes,
            'limit' => $limit,
        ]);

        $self = $this;
        return $this->executeApiCall(
            'list offer events',
            $logContext,
            function () use ($self, $language, $untilId, $eventTypes, $limit) {
                return $self->getOffersApi()->getOffersEventsV1WithHttpInfo(
                    $self->getOrganizationId(),
                    $language,
                    $untilId,
                    $eventTypes,
                    $limit
                );
            }
        );
    }
}
