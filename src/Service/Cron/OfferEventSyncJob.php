<?php
/**
 * OfferEventSyncJob
 *
 * Cron job for synchronizing offer events from InPost API.
 * Fetches events for synced offers and stores them locally.
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Service\Cron;

use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Inpost\VonHalsky\Entity\OfferEvent;
use Inpost\VonHalsky\Entity\Organization;
use Inpost\VonHalsky\Repository\OfferEventRepository;
use Inpost\VonHalsky\Repository\OrganizationRepository;
use Inpost\VonHalsky\Repository\OfferRepository;
use Inpost\VonHalsky\Service\Api\OfferEventsService;
use Inpost\VonHalsky\Service\Offer\OfferService;
use Inpost\VonHalsky\Service\Util\ConfigurationService;
use Inpost\VonHalsky\Service\Util\LoggerService;

class OfferEventSyncJob extends AbstractCronJob
{
    /**
     * Job name
     */
    private const JOB_NAME = 'offer_event_sync';

    /**
     * Default interval: 10 minutes
     */
    private const DEFAULT_INTERVAL = 600;

    /**
     * Maximum events to fetch per API call
     */
    private const MAX_EVENTS_PER_CALL = 100;

    /**
     * Maximum pagination iterations (safety limit)
     */
    private const MAX_ITERATIONS = 10;

    private OrganizationRepository $organizationRepository;
    private OfferRepository $offerRepository;
    private OfferEventRepository $offerEventRepository;
    private OfferEventsService $offerEventsService;
    private OfferService $offerService;
    private EntityManagerInterface $entityManager;
    private LoggerService $logger;

    public function __construct(
        OrganizationRepository $organizationRepository,
        OfferRepository $offerRepository,
        OfferEventRepository $offerEventRepository,
        OfferEventsService $offerEventsService,
        OfferService $offerService,
        EntityManagerInterface $entityManager,
        LoggerService $logger
    ) {
        $this->organizationRepository = $organizationRepository;
        $this->offerRepository = $offerRepository;
        $this->offerEventRepository = $offerEventRepository;
        $this->offerEventsService = $offerEventsService;
        $this->offerService = $offerService;
        $this->entityManager = $entityManager;
        $this->logger = $logger;
        $this->interval = self::DEFAULT_INTERVAL;
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return self::JOB_NAME;
    }

    /**
     * {@inheritdoc}
     */
    public function execute(): array
    {
        $this->logger->info('[InPost-OfferEventSyncJob] Starting offer events synchronization');

        $stats = [
            'organizations' => 0,
            'events_fetched' => 0,
            'events_saved' => 0,
            'errors' => 0,
        ];

        try {
            // Only the organization selected in the configuration: the events API is
            // always called for it (OfferEventsService uses the configured id), so
            // looping over every cached organization fetched the same events N times
            // and wrote the selected organization's cursor into every row.
            $organizationId = (string) \Configuration::get(ConfigurationService::ORGANIZATION_ID);
            if ($organizationId === '') {
                $this->logger->info('[InPost-OfferEventSyncJob] No organization selected');
                return [
                    'success' => true,
                    'message' => 'No organization selected',
                    ...$stats,
                ];
            }

            $organization = $this->organizationRepository->findOneBy(['organizationId' => $organizationId]);
            if ($organization === null) {
                // Not in the local organization cache yet - track the cursor in a new row
                $organization = (new Organization())
                    ->setOrganizationId($organizationId)
                    ->setName($organizationId)
                    ->setShopId((int) \Context::getContext()->shop->id);
            }

            try {
                $result = $this->syncOrganizationEvents($organization);
                $stats['organizations']++;
                $stats['events_fetched'] += $result['fetched'];
                $stats['events_saved'] += $result['saved'];
            } catch (Exception $e) {
                $stats['errors']++;
                $this->logger->error('[InPost-OfferEventSyncJob] Failed to sync events for organization', [
                    'organization_id' => $organizationId,
                    'error' => $e->getMessage(),
                ]);
            }

            $this->logger->info('[InPost-OfferEventSyncJob] Offer events synchronization completed', $stats);

            return [
                'success' => true,
                'message' => sprintf(
                    'Organization %s: fetched %d new events, saved %d, %d errors',
                    $organizationId,
                    $stats['events_fetched'],
                    $stats['events_saved'],
                    $stats['errors']
                ),
                ...$stats,
            ];
        } catch (Exception $e) {
            $this->logger->error('[InPost-OfferEventSyncJob] Offer events synchronization failed', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                ...$stats,
            ];
        }
    }

    /**
     * Sync events for a single organization using cursor-based pagination
     *
     * @param Organization $organization
     * @return array ['fetched' => int, 'saved' => int]
     */
    private function syncOrganizationEvents(Organization $organization): array
    {
        $organizationId = $organization->getOrganizationId();
        $lastEventId = $organization->getLastOfferEventId();
        $stats = ['fetched' => 0, 'saved' => 0];
        $newestEventId = null;
        $iteration = 0;

        $this->logger->debug('[InPost-OfferEventSyncJob] Syncing events for organization', [
            'organization_id' => $organizationId,
            'last_event_id' => $lastEventId,
        ]);

        // Fetch events (paginate if needed)
        $untilId = null;
        $apiFailed = false;
        do {
            $iteration++;
            if ($iteration > self::MAX_ITERATIONS) {
                $this->logger->warning('[InPost-OfferEventSyncJob] Max iterations reached', [
                    'organization_id' => $organizationId,
                ]);
                break;
            }

            // Call API
            $response = $this->offerEventsService->listOfferEvents(
                'pl',
                $untilId,
                null,
                self::MAX_EVENTS_PER_CALL
            );

            if (!$response['success'] || !isset($response['data'])) {
                $this->logger->warning('[InPost-OfferEventSyncJob] API call failed', [
                    'organization_id' => $organizationId,
                    'error' => $response['error'] ?? 'Unknown error',
                ]);
                $apiFailed = true;
                break;
            }

            // Extract events from SDK response object
            $events = $response['data']->getData() ?? [];
            $eventCount = count($events);

            if (empty($events)) {
                break;
            }

            // Track newest event ID (first event in first batch).
            // getData() returns typed model objects — read them via getters.
            if ($newestEventId === null) {
                $newestEventId = $this->readEventId($events[0]);
            }

            // Process events
            $reachedCursor = false;
            foreach ($events as $eventData) {
                $eventId = $this->readEventId($eventData);

                // If we've reached the last synced event, stop
                if ($lastEventId !== null && $eventId === $lastEventId) {
                    $reachedCursor = true;
                    break;
                }

                // Events newer than the cursor (older ones were handled on earlier runs)
                $stats['fetched']++;

                // Save event if not exists
                if ($this->saveEvent($eventData)) {
                    $stats['saved']++;
                }

                // Track oldest event ID for pagination
                $untilId = $eventId;
            }

            // Stop if we reached the cursor or no more events
            if ($reachedCursor || $eventCount < self::MAX_EVENTS_PER_CALL) {
                break;
            }
        } while (true);

        // Update cursor to newest event - not after an API failure, otherwise the
        // events between the last fetched page and the old cursor would be skipped
        // for good (already saved events are de-duplicated on the next run).
        if ($newestEventId !== null && !$apiFailed) {
            $organization->setLastOfferEventId($newestEventId);
            $organization->setLastOfferEventSyncAt(new DateTime());
            $this->entityManager->persist($organization);
        }

        $this->entityManager->flush();

        $this->logger->debug('[InPost-OfferEventSyncJob] Organization sync complete', [
            'organization_id' => $organizationId,
            'events_fetched' => $stats['fetched'],
            'events_saved' => $stats['saved'],
            'new_cursor' => $newestEventId,
        ]);

        return $stats;
    }

    /**
     * Read the event id from an SDK OfferEvent model.
     *
     * @param mixed $eventData
     * @return string|null
     */
    private function readEventId($eventData): ?string
    {
        return (is_object($eventData) && method_exists($eventData, 'getId')) ? $eventData->getId() : null;
    }

    /**
     * Save a single event to database
     *
     * @param mixed $eventData SDK OfferEvent model from the API
     * @return bool True if saved (new), false if skipped (duplicate)
     */
    private function saveEvent($eventData): bool
    {
        // The SDK returns typed model objects (InpostApi\Model\OfferEvent) — read
        // via getters, not array keys / property access.
        $inpostEventId = $this->readEventId($eventData);
        if (!$inpostEventId) {
            return false;
        }

        // Check if already exists (unique constraint will also prevent duplicates)
        $existing = $this->offerEventRepository->findOneBy([
            'inpostOfferEventId' => $inpostEventId,
        ]);

        if ($existing) {
            return false;
        }

        $offerData = (is_object($eventData) && method_exists($eventData, 'getOffer')) ? $eventData->getOffer() : null;
        $inpostOfferId = ($offerData !== null && method_exists($offerData, 'getId')) ? $offerData->getId() : null;
        $externalId = ($offerData !== null && method_exists($offerData, 'getExternalId')) ? $offerData->getExternalId() : null;
        $eventType = (is_object($eventData) && method_exists($eventData, 'getOfferEventType') ? $eventData->getOfferEventType() : null) ?: 'UNKNOWN';
        $occurredAt = (is_object($eventData) && method_exists($eventData, 'getOccurredAt')) ? $eventData->getOccurredAt() : null;

        // Create event entity
        $event = new OfferEvent();
        $event->setInpostOfferEventId($inpostEventId);
        $event->setEventType($eventType);
        $event->setOccuredAt($occurredAt instanceof DateTime ? $occurredAt : new DateTime());
        $event->setInpostOfferId($inpostOfferId);
        $event->setPrestashopOfferId($externalId !== null ? (string) $externalId : null);

        $this->entityManager->persist($event);

        // Refresh offer status from API (like Magento does)
        // This updates status and validation_errors from the authoritative source
        $this->refreshOfferAfterEvent($inpostOfferId, $eventType);

        return true;
    }

    /**
     * Refresh offer data from API after receiving an event
     *
     * Events are read-only triggers that cause the system to refresh
     * offer data from the API (single source of truth).
     *
     * @param string|null $inpostOfferId InPost offer ID
     * @param string $eventType Event type that triggered the refresh
     */
    private function refreshOfferAfterEvent(?string $inpostOfferId, string $eventType): void
    {
        if (!$inpostOfferId) {
            return;
        }

        try {
            // Find local offer by InPost ID
            $offer = $this->offerRepository->findByInpostOfferId($inpostOfferId);

            if (!$offer) {
                $this->logger->debug('[InPost-OfferEventSyncJob] Offer not found locally, skipping refresh', [
                    'inpost_offer_id' => $inpostOfferId,
                    'event_type' => $eventType,
                ]);
                return;
            }

            $this->logger->info('[InPost-OfferEventSyncJob] Refreshing offer from API after event', [
                'inpost_offer_id' => $inpostOfferId,
                'offer_id' => $offer->getOfferId(),
                'event_type' => $eventType,
            ]);

            // Refresh offer status + validation errors from API
            $result = $this->offerService->refreshOfferStatus($offer->getOfferId());

            if ($result['success'] ?? false) {
                $this->logger->info('[InPost-OfferEventSyncJob] Offer refreshed successfully', [
                    'inpost_offer_id' => $inpostOfferId,
                    'status_changed' => $result['status_changed'] ?? false,
                    'new_status' => $result['new_status'] ?? null,
                    'validation_error_count' => $result['validation_error_count'] ?? 0,
                ]);
            } else {
                $this->logger->warning('[InPost-OfferEventSyncJob] Offer refresh returned error', [
                    'inpost_offer_id' => $inpostOfferId,
                    'error' => $result['error'] ?? 'Unknown error',
                ]);
            }
        } catch (Exception $e) {
            // Don't throw - event was saved successfully, refresh failure shouldn't break sync
            $this->logger->error('[InPost-OfferEventSyncJob] Failed to refresh offer after event', [
                'inpost_offer_id' => $inpostOfferId,
                'event_type' => $eventType,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
