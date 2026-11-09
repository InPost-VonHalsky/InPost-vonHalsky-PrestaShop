<?php
/**
 * OrderEventSyncJob
 *
 * Cron job reacting to InPost order events. Reads the events that arrived since
 * the last run (events are listed newest first; older pages via untilId) and
 * syncs every order they mention right away - fetched one by one from InPost:
 * a new accepted order gets its shop order without waiting for the order import,
 * a cancellation cancels the shop order.
 *
 * Shop orders are created under the order_sync lock, so the event job and the
 * order import never create the same order twice. While the import runs, the
 * events only update the local order status (the import handles the rest).
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Service\Cron;

use Configuration;
use Inpost\VonHalsky\Service\Api\OrderEventsService;
use Inpost\VonHalsky\Repository\InpostOrderRepository;
use Inpost\VonHalsky\Service\Sync\OrderSyncService;
use Inpost\VonHalsky\Service\Util\LoggerService;
use InpostApi\Model\OrderEvent;
use InpostApi\Model\OrderEventType;

class OrderEventSyncJob extends AbstractCronJob
{
    /**
     * Job name
     */
    private const JOB_NAME = 'order_event_sync';

    /**
     * Default interval: 10 minutes
     */
    private const DEFAULT_INTERVAL = 600;

    /**
     * Seconds one run may spend on syncing orders; unfinished events are read again next run
     */
    private const TIME_BUDGET = 240;

    /**
     * Pages of events read per run when catching up (limit x pages events)
     */
    private const MAX_PAGES = 10;

    /**
     * Last processed event: JSON {organization, id, at}
     */
    public const CURSOR_KEY = 'INPOSTSA_ORDER_EVENTS_CURSOR';

    /**
     * "Create shop orders from order events" switch (configuration); unset = on
     */
    public const CREATE_ORDERS_KEY = 'INPOSTSA_ORDER_EVENTS_CREATE_ORDERS';

    /**
     * Lifecycle event types mapped to the local (lowercase) order status
     */
    private const EVENT_TYPE_TO_STATUS = [
        OrderEventType::CREATED => 'created',
        OrderEventType::ACCEPTED => 'accepted',
        OrderEventType::REFUSED => 'refused',
        OrderEventType::REJECTED => 'rejected',
        OrderEventType::CANCELLED => 'canceled',
    ];

    /**
     * @var OrderEventsService
     */
    private $orderEventsService;

    /**
     * @var InpostOrderRepository
     */
    private $orderRepository;

    /**
     * @var LoggerService
     */
    private $logger;

    private OrderSyncService $orderSyncService;

    private CronManager $cronManager;

    public function __construct(
        OrderEventsService $orderEventsService,
        InpostOrderRepository $orderRepository,
        LoggerService $logger,
        OrderSyncService $orderSyncService,
        CronManager $cronManager
    ) {
        $this->orderEventsService = $orderEventsService;
        $this->orderRepository = $orderRepository;
        $this->logger = $logger;
        $this->orderSyncService = $orderSyncService;
        $this->cronManager = $cronManager;
        $this->interval = self::DEFAULT_INTERVAL;
        $this->limit = 100;
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
        $deadline = microtime(true) + self::TIME_BUDGET;
        $organizationId = $this->orderEventsService->getOrganizationId();
        $cursor = $this->loadCursor($organizationId);

        [$events, $fetchError, $caughtUp] = $this->fetchNewEvents($cursor);
        if ($fetchError !== null) {
            $this->logger->warning('[InPost-OrderEventSyncJob] Fetching order events failed', ['error' => $fetchError]);

            return [
                'success' => false,
                'error' => 'Fetching order events from InPost failed: ' . $fetchError,
                'message' => $fetchError,
            ];
        }

        if ($events === []) {
            return ['success' => true, 'processed' => 0, 'message' => 'No new events'];
        }

        // Orders mentioned by the events, oldest event first
        $orderIds = [];
        foreach (array_reverse($events) as $event) {
            $orderId = $event->getOrder() !== null ? $event->getOrder()->getId() : null;
            if ($orderId !== null && $orderId !== '') {
                $orderIds[(string) $orderId] = true;
            }
        }
        $orderIds = array_keys($orderIds);

        // Switched off: events only refresh orders already imported (status, cancellation
        // of the shop order) - new orders wait for the order import
        $createOrders = self::isCreatingOrders();
        $mentioned = count($orderIds);
        if (!$createOrders) {
            $orderIds = $this->knownOrderIds($orderIds);
        }

        [$locked, $stats] = $this->cronManager->withLock(
            'order_sync',
            fn () => $this->orderSyncService->syncOrdersById($orderIds, $deadline, $createOrders)
        );

        if (!$locked) {
            // The order import is running and will create / cancel the shop orders itself
            $updated = 0;
            foreach (array_reverse($events) as $event) {
                try {
                    $updated += $this->processEvent($event) ? 1 : 0;
                } catch (\Throwable $e) {
                    $this->logger->warning('[InPost-OrderEventSyncJob] Failed to process event', [
                        'event_id' => $event->getId(),
                        'error' => $e->getMessage(),
                    ]);
                }
            }
            $this->saveCursor($organizationId, $events[0]);

            return [
                'success' => true,
                'processed' => count($events),
                'updated' => $updated,
                'message' => sprintf(
                    '%d new events, %d order statuses updated (order import running - it creates the shop orders)',
                    count($events),
                    $updated
                ),
            ];
        }

        // Unfinished (time limit / stop): the same events are read again next run
        if (empty($stats['incomplete'])) {
            $this->saveCursor($organizationId, $events[0]);
        }

        $failures = (int) $stats['shop_order_failures'];
        $message = $createOrders
            ? sprintf(
                '%d new events for %d orders: %d shop orders created, %d not created, %d errors',
                count($events),
                $mentioned,
                $stats['shop_orders_created'],
                $failures,
                $stats['errors']
            )
            : sprintf(
                '%d new events for %d orders: %d already imported orders refreshed, %d new orders left to the order import (creating orders from events is off), %d errors',
                count($events),
                $mentioned,
                count($orderIds),
                $mentioned - count($orderIds),
                $stats['errors']
            );
        if (!$caughtUp) {
            $message .= ' (more than ' . (self::MAX_PAGES * $this->limit) . ' new events - older ones are left to the order import)';
        }
        if (!empty($stats['stopped'])) {
            $message .= ' - stopped on request';
        } elseif (!empty($stats['incomplete'])) {
            $message .= ' - time limit reached, continues on the next run';
        }

        $this->logger->info('[InPost-OrderEventSyncJob] ' . $message);

        return [
            'success' => $failures === 0,
            'error' => $failures > 0
                ? sprintf('%d shop order(s) could not be created. Last error: %s', $failures, (string) $stats['last_shop_order_error'])
                : null,
            'stopped' => !empty($stats['stopped']),
            'processed' => count($events),
            'created' => $stats['shop_orders_created'],
            'errors' => $stats['errors'],
            'limit' => $this->limit,
            'message' => $message,
        ];
    }

    /**
     * Whether order events create shop orders for new orders right away (default: yes)
     */
    public static function isCreatingOrders(): bool
    {
        $value = Configuration::get(self::CREATE_ORDERS_KEY);

        return $value === false || $value === null || $value === '' || (bool) $value;
    }

    /**
     * The given InPost order ids that are already stored locally
     *
     * @param string[] $orderIds
     * @return string[]
     */
    private function knownOrderIds(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        $known = array_column(\Db::getInstance()->executeS(
            'SELECT inpost_order_id FROM ' . _DB_PREFIX_ . "inpost_order
             WHERE inpost_order_id IN ('" . implode("','", array_map('pSQL', $orderIds)) . "')"
        ) ?: [], 'inpost_order_id');

        // Keep the event order
        return array_values(array_intersect($orderIds, $known));
    }

    /**
     * Events newer than the cursor, newest first. Without a cursor (first run)
     * only the latest page is read - the order import covers the history.
     *
     * @param array{id: string, at: int}|null $cursor
     * @return array{0: OrderEvent[], 1: string|null, 2: bool} [events, fetch error, reached the cursor]
     */
    private function fetchNewEvents(?array $cursor): array
    {
        $events = [];
        $untilId = null;

        for ($page = 0; $page < self::MAX_PAGES; ++$page) {
            try {
                $response = $this->orderEventsService->listOrderEvents('pl', $untilId, null, $this->limit);
            } catch (\Throwable $e) {
                return [[], $e->getMessage(), false];
            }
            if (empty($response['success']) || !isset($response['data'])) {
                return [[], (string) ($response['error'] ?? 'API call failed'), false];
            }

            $pageEvents = $response['data']->getData() ?? [];
            foreach ($pageEvents as $event) {
                if ($cursor !== null) {
                    $occurredAt = $this->timestamp($event->getOccurredAt());
                    if ($event->getId() === $cursor['id'] || ($occurredAt !== null && $occurredAt < $cursor['at'])) {
                        return [$events, null, true];
                    }
                }
                $events[] = $event;
            }

            if ($cursor === null || count($pageEvents) < $this->limit) {
                return [$events, null, true];
            }

            $untilId = end($pageEvents)->getId();
        }

        return [$events, null, false];
    }

    /**
     * @return array{id: string, at: int}|null
     */
    private function loadCursor(string $organizationId): ?array
    {
        $data = json_decode((string) Configuration::getGlobalValue(self::CURSOR_KEY), true);
        if (!is_array($data) || empty($data['id']) || ($data['organization'] ?? null) !== $organizationId) {
            return null;
        }

        return ['id' => (string) $data['id'], 'at' => (int) ($data['at'] ?? 0)];
    }

    private function saveCursor(string $organizationId, OrderEvent $newest): void
    {
        Configuration::updateGlobalValue(self::CURSOR_KEY, (string) json_encode([
            'organization' => $organizationId,
            'id' => $newest->getId(),
            'at' => $this->timestamp($newest->getOccurredAt()) ?? time(),
        ]));
    }

    /**
     * @param \DateTimeInterface|string|null $value
     */
    private function timestamp($value): ?int
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->getTimestamp();
        }
        if (is_string($value) && $value !== '') {
            $time = strtotime($value);

            return $time !== false ? $time : null;
        }

        return null;
    }

    /**
     * Status-only update of a locally known order (fallback while the order import
     * holds the order_sync lock - the import itself then creates / cancels the shop orders)
     *
     * Only events that change the order lifecycle status update the local
     * order; informational events (PAID, SHIPPED, UPDATED, refunds) are ignored.
     *
     * @param OrderEvent $event Event model from the API
     * @return bool True if order was updated
     */
    private function processEvent(OrderEvent $event): bool
    {
        $orderId = $event->getOrder() !== null ? $event->getOrder()->getId() : null;
        // The SDK types enums as model classes, but deserializes them as plain strings
        /** @var string|null $rawEventType */
        $rawEventType = $event->getOrderEventType();
        $eventType = (string) $rawEventType;
        $newStatus = self::EVENT_TYPE_TO_STATUS[$eventType] ?? null;

        if ($orderId === null) {
            $this->logger->warning('[InPost-OrderEventSyncJob] Event missing order id', [
                'event_id' => $event->getId(),
            ]);
            return false;
        }

        // Find local order by InPost order ID
        $order = $this->orderRepository->findByInpostOrderId($orderId);

        if ($order === null) {
            $this->logger->debug('[InPost-OrderEventSyncJob] Order not found locally', [
                'inpost_order_id' => $orderId,
            ]);
            return false;
        }

        // Update order status if changed
        if ($newStatus !== null && $order->getStatus() !== $newStatus) {
            $oldStatus = $order->getStatus();
            $order->setStatus($newStatus);
            $order->setDateUpd(new \DateTime());
            $this->orderRepository->save($order);

            $this->logger->info('[InPost-OrderEventSyncJob] Order status updated', [
                'inpost_order_id' => $orderId,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'event_type' => $eventType,
            ]);

            return true;
        }

        return false;
    }
}
