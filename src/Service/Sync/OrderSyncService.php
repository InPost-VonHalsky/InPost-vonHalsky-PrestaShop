<?php
/**
 * Order Sync Service
 *
 * Service for synchronizing orders from InPost API to local database
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Service\Sync;

use Inpost\VonHalsky\Service\Util\ShopLog;
use Context;
use Db;
use Exception;
use Configuration;
use Inpost\VonHalsky\Entity\InpostOrder;
use Inpost\VonHalsky\Service\Cron\JobProgress;
use Inpost\VonHalsky\Repository\InpostOrderRepository;
use Inpost\VonHalsky\Service\Api\OrdersService;
use Inpost\VonHalsky\Service\Shipx\ShipXIntegrationService;
use Inpost\VonHalsky\Service\Sync\Order\PrestaShopOrderCreator;
use Inpost\VonHalsky\Service\Util\ConfigurationService;
use InpostApi\Model\OrderStatus;
use Inpost\VonHalsky\Service\Util\DebugLog;

class OrderSyncService
{
    /**
     * InPost statuses for which no PrestaShop order is created/updated
     */
    private const SKIP_PS_ORDER_STATUSES = [
        OrderStatus::CREATED,
        OrderStatus::REFUSED,
        OrderStatus::CANCELED,
        OrderStatus::REJECTED,
    ];

    /**
     * After this many shop orders in a row failed to be created, the rest of the run
     * only stores the InPost orders: such failures come from the shop setup (stock,
     * payment module, currency, ...), and retrying a few hundred full order
     * creations every run is what freezes the shop.
     */
    public const MAX_CONSECUTIVE_FAILURES = 5;

    /**
     * Last shop order creation failure: JSON {inpost_order_id, message, at}
     */
    public const LAST_CREATE_ERROR_KEY = 'INPOSTSA_ORDER_CREATE_LAST_ERROR';

    /**
     * Last id_inpost_order retried by findOrdersWithoutShopOrder()
     */
    private const RETRY_CURSOR_KEY = 'INPOSTSA_ORDERS_RETRY_CURSOR';

    private const SHOP_ORDER_CREATED = 'created';
    private const SHOP_ORDER_FAILED = 'failed';

    /**
     * @var OrdersService
     */
    private $ordersService;

    /**
     * @var PrestaShopOrderCreator
     */
    private $orderCreator;

    /**
     * @var InpostOrderRepository
     */
    private $inpostOrderRepository;

    /**
     * @var ConfigurationService
     */
    private $configService;

    /**
     * @var ShipXIntegrationService
     */
    private $shipXIntegration;

    private JobProgress $progress;

    /**
     * Whether shop orders are created in this run (false after too many failures in a row)
     */
    private bool $createShopOrders = true;

    private int $consecutiveFailures = 0;

    /**
     * Constructor
     *
     * @param OrdersService $ordersService
     * @param PrestaShopOrderCreator $orderCreator
     * @param InpostOrderRepository $inpostOrderRepository
     * @param ConfigurationService $configService
     * @param ShipXIntegrationService $shipXIntegration
     * @param JobProgress $progress
     */
    public function __construct(
        OrdersService $ordersService,
        PrestaShopOrderCreator $orderCreator,
        InpostOrderRepository $inpostOrderRepository,
        ConfigurationService $configService,
        ShipXIntegrationService $shipXIntegration,
        JobProgress $progress
    ) {
        $this->ordersService = $ordersService;
        $this->orderCreator = $orderCreator;
        $this->inpostOrderRepository = $inpostOrderRepository;
        $this->configService = $configService;
        $this->shipXIntegration = $shipXIntegration;
        $this->progress = $progress;
    }

    /**
     * Sync orders from InPost API to local database
     *
     * @param string|null $orderStatus Filter by order status (optional)
     * @param int $limit Number of orders per page (max 30)
     * @param bool $syncAllPages Whether to sync all pages (default: true)
     * @param int|null $timeBudgetSeconds Stop after this time; the rest follows on the next run
     * @param \DateTime|null $updatedSince Only orders updated at or after this time (incremental run)
     *
     * @return array Statistics about sync operation
     *
     * @throws Exception
     */
    public function syncOrders(
        $orderStatus = null,
        int $limit = 10,
        bool $syncAllPages = true,
        ?int $timeBudgetSeconds = null,
        ?\DateTime $updatedSince = null
    ): array {
        DebugLog::info('[InPost Sync] Starting syncOrders with status=' . ($orderStatus !== null ? $orderStatus : 'null') . ', limit=' . $limit
            . ($updatedSince !== null ? ', updated since ' . $updatedSince->format(DATE_ATOM) : ''));

        $stats = $this->beginRun();
        $deadline = $timeBudgetSeconds !== null ? microtime(true) + $timeBudgetSeconds : null;
        $this->progress->report(['step' => 'Fetching orders from InPost'], true);

        $offset = 0;
        $hasMore = true;

        while ($hasMore) {
            try {
                DebugLog::info('[InPost Sync] Fetching orders from API (offset: ' . $offset . ', limit: ' . $limit . ')');

                $response = $this->ordersService->listOrders(
                    'pl',
                    $orderStatus,
                    $limit,
                    $offset,
                    null,
                    $updatedSince
                );

                $success = isset($response['success']) ? $response['success'] : false;
                if (!$success) {
                    ++$stats['errors'];
                    $errorMessage = isset($response['error']) ? $response['error'] : 'Unknown error';
                    $stats['fetch_error'] = $errorMessage;
                    ShopLog::add(
                        '[InPost Sync] Failed to fetch orders from API. Error: ' . $errorMessage,
                        3
                    );
                    break;
                }

                $data = isset($response['data']) ? $response['data'] : null;
                $orders = [];

                if ($data && method_exists($data, 'getData')) {
                    $orders = $data->getData();
                    if ($orders === null) {
                        $orders = [];
                    }
                } elseif (is_array($data)) {
                    $orders = $data;
                } elseif ($data === null) {
                    $hasMore = false;
                    break;
                }

                if ($stats['total_available'] === null && is_object($data) && method_exists($data, 'getPage') && $data->getPage() !== null) {
                    $stats['total_available'] = (int) $data->getPage()->getTotal();
                }

                if (empty($orders)) {
                    $hasMore = false;
                    break;
                }

                ++$stats['pages'];

                foreach ($orders as $order) {
                    // Interactive runs stop after the time budget, any run on a stop
                    // request; orders without a shop order are picked up by the next sync
                    if ($this->shouldInterrupt($deadline, $stats)) {
                        $hasMore = false;
                        break;
                    }

                    $this->processOrder($order, $stats);
                }

                if ($stats['incomplete'] || !$syncAllPages || count($orders) < $limit) {
                    $hasMore = false;
                } else {
                    $offset += $limit;
                }
            } catch (\Throwable $e) {
                ++$stats['errors'];
                $stats['fetch_error'] = $e->getMessage();
                ShopLog::add(
                    '[InPost Sync] Exception during sync: ' . $e->getMessage(),
                    3
                );
                $hasMore = false;
            }
        }

        DebugLog::info('[InPost Sync] Sync completed. Stats: ' . json_encode($stats));

        return $stats;
    }

    /**
     * Fetch the given orders one by one from InPost and sync them like the list
     * import does (local record, shop order creation, cancellation). Used for
     * orders reported by order events and for retrying orders whose shop order
     * could not be created. The caller holds the order_sync lock.
     *
     * @param string[] $inpostOrderIds
     * @param float|null $deadline microtime(true) after which the rest is left for the next run
     * @param bool $createShopOrders False: only update orders (local record, cancellation of
     *                               an existing shop order) - no new shop order is created
     * @return array Same statistics as syncOrders()
     */
    public function syncOrdersById(array $inpostOrderIds, ?float $deadline = null, bool $createShopOrders = true): array
    {
        $stats = $this->beginRun();
        $this->createShopOrders = $createShopOrders;
        $stats['total_available'] = count($inpostOrderIds);

        foreach ($inpostOrderIds as $inpostOrderId) {
            if ($this->shouldInterrupt($deadline, $stats)) {
                break;
            }

            try {
                $response = $this->ordersService->getOrder((string) $inpostOrderId);
            } catch (\Throwable $e) {
                $response = ['success' => false, 'error' => $e->getMessage()];
            }

            if (empty($response['success']) || empty($response['data'])) {
                ++$stats['total_fetched'];
                ++$stats['errors'];
                ShopLog::add(
                    '[InPost Sync] Failed to fetch order ' . $inpostOrderId . ': ' . ($response['error'] ?? 'empty response'),
                    3
                );
                continue;
            }

            $this->processOrder($response['data'], $stats);
        }

        return $stats;
    }

    /**
     * InPost orders stored locally that should have a shop order but do not
     * (creation failed, or creation was paused). A rotating cursor makes sure an
     * order that keeps failing does not block the others.
     *
     * @return string[] InPost order ids
     */
    public function findOrdersWithoutShopOrder(int $max): array
    {
        $p = _DB_PREFIX_;
        $skip = "'" . implode("','", array_map('strtolower', self::SKIP_PS_ORDER_STATUSES)) . "','cancelled'";
        $base = "SELECT io.id_inpost_order, io.inpost_order_id
                 FROM {$p}inpost_order io
                 LEFT JOIN {$p}orders o ON o.id_order = io.id_order
                 WHERE o.id_order IS NULL AND LOWER(COALESCE(io.status, '')) NOT IN ({$skip})";

        $cursor = (int) Configuration::getGlobalValue(self::RETRY_CURSOR_KEY);
        $rows = Db::getInstance()->executeS($base . ' AND io.id_inpost_order > ' . $cursor . ' ORDER BY io.id_inpost_order LIMIT ' . (int) $max) ?: [];
        if (count($rows) < $max && $cursor > 0) {
            // Wrap around to the beginning
            $more = Db::getInstance()->executeS($base . ' AND io.id_inpost_order <= ' . $cursor . ' ORDER BY io.id_inpost_order LIMIT ' . (int) ($max - count($rows))) ?: [];
            $rows = array_merge($rows, $more);
        }

        $last = $rows !== [] ? (int) end($rows)['id_inpost_order'] : 0;
        Configuration::updateGlobalValue(self::RETRY_CURSOR_KEY, (string) $last);

        return array_values(array_unique(array_column($rows, 'inpost_order_id')));
    }

    /**
     * Fresh statistics and circuit breaker for a run
     *
     * @return array<string, mixed>
     */
    private function beginRun(): array
    {
        $this->createShopOrders = true;
        $this->consecutiveFailures = 0;

        return [
            'total_fetched' => 0,
            'created' => 0,
            'updated' => 0,
            'errors' => 0,
            'pages' => 0,
            'total_available' => null,
            'shop_orders_created' => 0,
            'shop_order_failures' => 0,
            'last_shop_order_error' => null,
            'creation_paused' => false,
            'incomplete' => false,
            'stopped' => false,
        ];
    }

    /**
     * Time budget exhausted or stop requested (marks the run incomplete)
     *
     * @param array<string, mixed> $stats
     */
    private function shouldInterrupt(?float $deadline, array &$stats): bool
    {
        if ($deadline !== null && microtime(true) > $deadline) {
            $stats['incomplete'] = true;

            return true;
        }
        if ($this->progress->shouldStop()) {
            $stats['stopped'] = true;
            $stats['incomplete'] = true;

            return true;
        }

        return false;
    }

    /**
     * Save one API order, count the outcome, apply the circuit breaker and report progress
     *
     * @param mixed $order Order data from API
     * @param array<string, mixed> $stats
     */
    private function processOrder($order, array &$stats): void
    {
        ++$stats['total_fetched'];

        try {
            $result = $this->saveOrder($order);
            if ($result['created']) {
                ++$stats['created'];
            } elseif ($result['updated']) {
                ++$stats['updated'];
            }

            if ($result['shop_order'] === self::SHOP_ORDER_CREATED) {
                ++$stats['shop_orders_created'];
                $this->consecutiveFailures = 0;
            } elseif ($result['shop_order'] === self::SHOP_ORDER_FAILED) {
                ++$stats['shop_order_failures'];
                $stats['last_shop_order_error'] = $result['shop_order_error'];
                if (++$this->consecutiveFailures >= self::MAX_CONSECUTIVE_FAILURES && $this->createShopOrders) {
                    $this->createShopOrders = false;
                    $stats['creation_paused'] = true;
                    ShopLog::add(
                        '[InPost Sync] ' . self::MAX_CONSECUTIVE_FAILURES . ' shop orders in a row could not be created - '
                        . 'creating shop orders is paused until the next run. Last error: ' . $result['shop_order_error'],
                        3
                    );
                }
            }
        } catch (\Throwable $e) {
            ++$stats['errors'];
            ShopLog::add(
                '[InPost Sync] Error saving order: ' . $e->getMessage(),
                3
            );
        }

        $this->progress->report([
            'done' => $stats['total_fetched'],
            'total' => $stats['total_available'],
            'created' => $stats['shop_orders_created'],
            'updated' => $stats['updated'],
            'errors' => $stats['errors'] + $stats['shop_order_failures'],
            'step' => $this->createShopOrders
                ? 'Order ' . (string) $this->extractOrderId($order)
                : 'Shop order creation paused after ' . self::MAX_CONSECUTIVE_FAILURES . ' failures in a row - storing InPost orders only',
            'last_error' => $stats['last_shop_order_error'],
        ]);
    }

    /**
     * Last shop order creation failure recorded by the sync
     *
     * @return array{inpost_order_id: string, message: string, at: int}|null
     */
    public static function getLastCreateError(): ?array
    {
        $data = json_decode((string) Configuration::getGlobalValue(self::LAST_CREATE_ERROR_KEY), true);

        return is_array($data) && isset($data['message']) ? $data : null;
    }

    private function rememberCreateError(string $inpostOrderId, string $message): void
    {
        try {
            Configuration::updateGlobalValue(self::LAST_CREATE_ERROR_KEY, (string) json_encode([
                'inpost_order_id' => $inpostOrderId,
                'message' => mb_substr($message, 0, 400),
                'at' => time(),
            ], JSON_UNESCAPED_UNICODE));
        } catch (\Throwable $e) {
            // bookkeeping only
        }
    }

    /**
     * Save or update order in database
     *
     * @param mixed $order Order data from API
     *
     * @return array Result with 'created' and 'updated' flags (local record) and 'shop_order'
     *               ('created', 'failed' or null) with 'shop_order_error'
     *
     * @throws Exception
     */
    public function saveOrder($order): array
    {
        $inpostOrderId = $this->extractOrderId($order);
        if (!$inpostOrderId) {
            throw new Exception('Order ID not found in API response');
        }

        $contextShopId = (int) Context::getContext()->shop->id;
        $orderEntity = $this->inpostOrderRepository->findByInpostOrderId($inpostOrderId);
        $isNew = $orderEntity === null;

        if ($isNew) {
            $orderEntity = new InpostOrder();
            $orderEntity->setInpostOrderId($inpostOrderId);
            $orderEntity->setIdOrder(0);
            $orderEntity->setIdShop($contextShopId);
            $orderEntity->setDateAdd(new \DateTime());
            $orderEntity->setDateUpd(new \DateTime());
        } elseif (empty($orderEntity->getIdShop())) {
            $orderEntity->setIdShop($contextShopId);
        }

        $this->hydrateOrderEntity($orderEntity, $order);
        $this->inpostOrderRepository->save($orderEntity);

        [$shopOrder, $shopOrderError] = $this->syncPrestaShopOrder($orderEntity, $order, $inpostOrderId, $contextShopId);

        return [
            'created' => $isNew,
            'updated' => !$isNew,
            'shop_order' => $shopOrder,
            'shop_order_error' => $shopOrderError,
        ];
    }

    /**
     * Copy the API order fields onto the local entity
     *
     * @param mixed $order Order data from API
     */
    private function hydrateOrderEntity(InpostOrder $orderEntity, $order): void
    {
        $orderEntity->setStatus($this->extractStatus($order));
        $orderEntity->setPointName($this->extractPointName($order));
        $orderEntity->setPointAddress($this->extractPointAddress($order));
        $orderEntity->setSenderPointName($this->extractSenderPointName($order));
        $orderEntity->setReceiverEmail($this->extractReceiverEmail($order));
        $orderEntity->setReceiverPhone($this->extractReceiverPhone($order));
        $orderEntity->setShipmentData($this->extractShipmentData($order));
        $orderEntity->setTotalItemsCount($this->extractTotalItemsCount($order));

        $finalPrice = $this->extractFinalPrice($order);
        $orderEntity->setTotal(($finalPrice && isset($finalPrice['amount'])) ? (float) $finalPrice['amount'] : 0.0);
    }

    /**
     * Create (first time) or update the PrestaShop order of an accepted InPost order.
     * Errors are logged and never interrupt the sync.
     *
     * @param mixed $order Order data from API
     * @return array{0: string|null, 1: string|null} [outcome 'created' / 'failed' / null, error]
     */
    private function syncPrestaShopOrder(InpostOrder $orderEntity, $order, string $inpostOrderId, int $contextShopId): array
    {
        $inpostStatus = $orderEntity->getStatus();

        $existingOrderId = $orderEntity->getIdOrder() > 0 ? $orderEntity->getIdOrder() : null;

        // No shop order is created for orders not (or no longer) accepted - but an
        // existing one is still updated, so a cancellation in InPost cancels it
        if ($existingOrderId === null
            && (!$this->createShopOrders || in_array(strtoupper((string) $inpostStatus), self::SKIP_PS_ORDER_STATUSES, true))
        ) {
            return [null, null];
        }

        try {

            $psOrderId = $this->orderCreator->createOrUpdateOrder(
                $this->extractCustomerData($order) ?? [],
                $this->extractProducts($order),
                $this->extractDeliveryAddress($order),
                $this->extractFinalPrice($order),
                (string) $inpostStatus,
                $inpostOrderId,
                $existingOrderId,
                (float) $this->extractValue($order, ['delivery.price.amount'], 0.0),
                $this->extractPayment($order),
                $this->extractInvoiceData($order)
            );

            if (!$psOrderId || $psOrderId <= 0) {
                if ($existingOrderId !== null) {
                    return [null, null];
                }
                $error = $this->orderCreator->getLastError() ?? 'unknown reason - see Advanced Parameters > Logs';
                $this->rememberCreateError($inpostOrderId, $error);

                return [self::SHOP_ORDER_FAILED, $error];
            }

            if ($orderEntity->getIdOrder() == 0) {
                $orderEntity->setIdOrder($psOrderId);
                $orderEntity->setIdShop($contextShopId);
                $this->inpostOrderRepository->save($orderEntity);
            }

            // ShipX integration: only for a first-time order creation (not status updates).
            if ($existingOrderId === null) {
                try {
                    $this->shipXIntegration->handleAcceptedOrder($orderEntity, (int) $psOrderId);
                } catch (\Throwable $e) {
                    // The shop order exists - a ShipX problem must not count as a failed creation
                    ShopLog::add('[InPost Sync] ShipX integration failed for order ' . $psOrderId . ': ' . $e->getMessage(), 3);
                }

                return [self::SHOP_ORDER_CREATED, null];
            }

            return [null, null];
        } catch (\Throwable $e) {
            ShopLog::add(
                '[InPost Sync] Error creating PrestaShop order: ' . $e->getMessage(),
                3
            );
            if ($existingOrderId !== null) {
                return [null, null];
            }
            $this->rememberCreateError($inpostOrderId, $e->getMessage());

            return [self::SHOP_ORDER_FAILED, $e->getMessage()];
        }
    }

    // =========================================================================
    // FIELD EXTRACTION HELPERS
    // =========================================================================

    /**
     * InPost payment of the order: payment type (BLIK_CODE, CARD, ...) and the payments made
     *
     * @param mixed $order Order data from API
     * @return array{type: string|null, payments: array<int, array{id: string|null, type: string|null, date: int|null}>}
     */
    private function extractPayment($order): array
    {
        $type = $this->extractValue($order, ['paymentDetails.selectedPaymentType', 'payment_details.selected_payment_type']);
        $payments = [];
        foreach ((array) ($this->extractValue($order, ['paymentDetails.payments', 'payment_details.payments']) ?? []) as $payment) {
            $date = $this->extractValue($payment, ['paymentDate', 'payment_date']);
            if ($date instanceof \DateTimeInterface) {
                $date = $date->getTimestamp();
            } elseif (is_string($date) && $date !== '') {
                $date = strtotime($date) ?: null;
            } else {
                $date = null;
            }
            $id = $this->extractValue($payment, ['paymentId', 'payment_id']);
            $paymentType = $this->extractValue($payment, ['paymentType', 'payment_type']);
            $payments[] = [
                'id' => $id !== null && $id !== '' ? (string) $id : null,
                'type' => $paymentType !== null ? (string) $paymentType : null,
                'date' => $date,
            ];
        }

        // The type of the payment actually made wins over the selected one: the buyer
        // can pick BLIK and then pay by link (e.g. stage order B944M1E)
        $paidType = $payments[0]['type'] ?? null;

        return [
            'type' => $paidType ?? ($type !== null && $type !== '' ? (string) $type : null),
            'payments' => $payments,
        ];
    }

    /**
     * Generic value extraction from API response (object or array)
     *
     * Tries multiple access patterns in order:
     * 1. Getter method (e.g., 'getId' for 'id')
     * 2. Direct property access (e.g., $order->id)
     * 3. Array access (e.g., $order['id'])
     * 4. Nested path (e.g., 'receiver.email' -> $order->receiver->email)
     *
     * @param mixed $order API response (object or array)
     * @param array $paths Array of paths to try (e.g., ['id', 'orderId', 'order.id'])
     * @param mixed $default Default value if not found
     *
     * @return mixed Extracted value or default
     */
    private function extractValue($order, array $paths, $default = null)
    {
        foreach ($paths as $path) {
            $value = $this->getNestedValue($order, $path);
            if ($value !== null) {
                return $value;
            }
        }

        return $default;
    }

    /**
     * Get nested value from object or array using dot notation path
     *
     * Enhanced to support both camelCase and snake_case property names,
     * which is necessary for new InPost API SDK compatibility.
     *
     * @param mixed $data Object or array
     * @param string $path Dot-separated path (e.g., 'receiver.email')
     *
     * @return mixed|null Value or null if not found
     */
    private function getNestedValue($data, string $path)
    {
        $parts = explode('.', $path);
        $current = $data;

        foreach ($parts as $part) {
            if ($current === null) {
                return null;
            }

            // Generate naming variants to try (camelCase, snake_case, PascalCase)
            $variants = array_unique([
                $part,                           // Original (e.g., 'finalPrice')
                $this->camelToSnake($part),      // Snake case (e.g., 'final_price')
                $this->snakeToCamel($part),      // Camel case (e.g., 'finalPrice')
                ucfirst($part),                  // Pascal case (e.g., 'FinalPrice')
            ]);

            $found = false;

            foreach ($variants as $variant) {
                // Try getter method (e.g., getFinalPrice for 'finalPrice')
                $getter = 'get' . ucfirst($variant);
                if (is_object($current) && method_exists($current, $getter)) {
                    $current = $current->$getter();
                    $found = true;
                    break;
                }

                // Try property access for objects
                if (is_object($current) && isset($current->$variant)) {
                    $current = $current->$variant;
                    $found = true;
                    break;
                }

                // Try array access
                if (is_array($current) && isset($current[$variant])) {
                    $current = $current[$variant];
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                return null;
            }
        }

        return $current;
    }

    /**
     * Convert camelCase to snake_case
     *
     * @param string $input Input string in camelCase
     *
     * @return string Output string in snake_case
     */
    private function camelToSnake(string $input): string
    {
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $input));
    }

    /**
     * Convert snake_case to camelCase
     *
     * @param string $input Input string in snake_case
     *
     * @return string Output string in camelCase
     */
    private function snakeToCamel(string $input): string
    {
        return lcfirst(str_replace('_', '', ucwords($input, '_')));
    }

    /**
     * Extract order ID from API response
     */
    private function extractOrderId($order): ?string
    {
        $value = $this->extractValue($order, ['id', 'orderId']);

        return $value !== null ? (string) $value : null;
    }

    /**
     * Extract status from API response with mapping
     */
    private function extractStatus($order): string
    {
        return strtolower((string) $this->extractValue($order, ['status'], 'pending'));
    }

    /**
     * Extract point name from API response
     */
    private function extractPointName($order): ?string
    {
        $value = $this->extractValue($order, ['pointName', 'point.name']);

        return $value !== null ? (string) $value : null;
    }

    /**
     * Extract point address from API response
     */
    private function extractPointAddress($order): ?string
    {
        $value = $this->extractValue($order, ['pointAddress', 'point.address']);

        return $value !== null ? (string) $value : null;
    }

    /**
     * Extract sender point name from API response
     */
    private function extractSenderPointName($order): ?string
    {
        $value = $this->extractValue($order, ['senderPointName']);

        return $value !== null ? (string) $value : null;
    }

    /**
     * Extract receiver email from API response
     */
    private function extractReceiverEmail($order): ?string
    {
        $value = $this->extractValue($order, ['receiverEmail', 'receiver.email']);

        return $value !== null ? (string) $value : null;
    }

    /**
     * Extract receiver phone from API response
     */
    private function extractReceiverPhone($order): ?string
    {
        $value = $this->extractValue($order, ['receiverPhone', 'receiver.phone']);

        return $value !== null ? (string) $value : null;
    }

    /**
     * Extract shipment data as JSON from API response
     *
     * @param mixed $order
     *
     * @return string|null
     */
    private function extractShipmentData($order): ?string
    {
        $data = [];

        if (is_object($order)) {
            $reflection = new \ReflectionClass($order);
            foreach ($reflection->getProperties() as $property) {
                $property->setAccessible(true);
                $value = $property->getValue($order);
                if ($value !== null) {
                    $data[$property->getName()] = $value;
                }
            }
        } elseif (is_array($order)) {
            $data = $order;
        }

        return !empty($data) ? json_encode($data) : null;
    }

    /**
     * Extract customer data from API response
     *
     * Uses extractValue() to consistently handle SDK objects and arrays.
     *
     * @param mixed $order
     *
     * @return array|null
     */
    private function extractCustomerData($order): ?array
    {
        // Use extractValue to get customer with fallback paths
        $customer = $this->extractValue($order, ['customer']);

        if (!$customer) {
            return null;
        }

        // Extract customer fields using extractValue for consistency
        $customerData = [
            'email' => $this->extractValue($customer, ['email'], null),
            'firstName' => $this->extractValue($customer, ['firstName', 'first_name'], null),
            'lastName' => $this->extractValue($customer, ['lastName', 'last_name'], null),
            'phoneNumber' => $this->extractValue($customer, ['phoneNumber', 'phone_number'], null),
            'address' => $this->extractValue($customer, ['address'], null),
        ];

        // Convert to strings where applicable
        if ($customerData['email'] !== null) {
            $customerData['email'] = (string) $customerData['email'];
        }
        if ($customerData['firstName'] !== null) {
            $customerData['firstName'] = (string) $customerData['firstName'];
        }
        if ($customerData['lastName'] !== null) {
            $customerData['lastName'] = (string) $customerData['lastName'];
        }
        if ($customerData['phoneNumber'] !== null) {
            $customerData['phoneNumber'] = (string) $customerData['phoneNumber'];
        }

        return $customerData;
    }

    /**
     * Invoice details of the order (the buyer asked for an invoice), null when there are none
     *
     * @param mixed $order
     *
     * @return array{legalForm: string, companyName: string, firstName: string, lastName: string,
     *               taxId: string, email: string, address: mixed}|null
     */
    private function extractInvoiceData($order): ?array
    {
        $invoice = $this->extractValue($order, ['invoice']);
        if (!$invoice) {
            return null;
        }

        $string = function (array $paths) use ($invoice): string {
            return trim((string) $this->extractValue($invoice, $paths, ''));
        };

        $invoiceData = [
            'legalForm' => $string(['legalForm', 'legal_form']),
            'companyName' => $string(['companyName', 'company_name']),
            'firstName' => $string(['firstName', 'first_name']),
            'lastName' => $string(['lastName', 'last_name']),
            // NIP with its country prefix when the buyer gave one ("PL" + "5252352907")
            'taxId' => $string(['taxIdPrefix', 'tax_id_prefix']) . $string(['taxId', 'tax_id']),
            'email' => $string(['email']),
            'address' => $this->extractValue($invoice, ['address']),
        ];

        // Nothing to put on an invoice address
        if ($invoiceData['address'] === null && $invoiceData['companyName'] === '' && $invoiceData['taxId'] === '') {
            return null;
        }

        return $invoiceData;
    }

    /**
     * Extract products from orderLines
     *
     * Uses extractValue() to consistently handle SDK objects, arrays, and nested structures.
     *
     * @param mixed $order
     *
     * @return array
     */
    private function extractProducts($order): array
    {
        // Use extractValue to get order lines with fallback paths
        $orderLines = $this->extractValue($order, ['orderLines', 'order_lines']);

        if (!$orderLines || !is_array($orderLines)) {
            return [];
        }

        $products = [];

        foreach ($orderLines as $orderLine) {
            // Extract offer from order line
            $offer = $this->extractValue($orderLine, ['offer']);

            if (!$offer) {
                continue;
            }

            // Extract product and price from offer
            $product = $this->extractValue($offer, ['product.sku']);
            $price = $this->extractValue($offer, ['price']);

            if (!$product) {
                continue;
            }

            // Extract price amount
            $priceAmount = $price ? $this->extractValue($price, ['amount'], 0.0) : 0.0;

            if ($product) {
                $products[] = [
                    'productId' => (string) $product,
                    'offerId' => (string) $this->extractValue($offer, ['offerId', 'offer_id'], ''),
                    'externalId' => (string) $this->extractValue($offer, ['externalId', 'external_id'], ''),
                    'price' => (float) $priceAmount,
                    'quantity' => 1, // In new API: 1 line = 1 product (no quantity field)
                ];
            }
        }

        return $products;
    }

    /**
     * Extract total items count from orderLines
     *
     * Note: In new InPost API SDK (v1.2.0+), OrderLineItem does not have a 'quantity' field.
     * Each order line represents one product, so we count the number of lines.
     *
     * @param mixed $order
     *
     * @return int
     */
    private function extractTotalItemsCount($order): int
    {
        // Use extractValue to support both object getters and array access
        $orderLines = $this->extractValue($order, ['orderLines', 'order_lines']);

        if (!$orderLines) {
            return 0;
        }

        if (!is_array($orderLines)) {
            return 0;
        }

        // In new API: each line = 1 product (no quantity field exists)
        // Simply return the count of order lines
        return count($orderLines);
    }

    /**
     * Extract delivery address from API response
     *
     * For parcel locker (Paczkomat) orders the address is in the 'delivery' section.
     * Falls back to the legacy 'deliveryAddress' field.
     *
     * @param mixed $order
     *
     * @return mixed|null Address data object or array, or null if not found
     */
    private function extractDeliveryAddress($order)
    {
        // Parcel locker address lives in the 'delivery' section
        $delivery = $this->extractValue($order, ['delivery']);
        if ($delivery !== null) {
            // No delivery address (e.g. courier order without one): null, so the
            // order creator falls back to the customer address - the delivery
            // object itself has no street and the shop address could not be created
            return $this->extractValue($delivery, ['address']);
        }

        // Legacy fallback
        if (is_object($order) && isset($order->deliveryAddress)) {
            return $order->deliveryAddress;
        }
        if (is_array($order) && isset($order['deliveryAddress'])) {
            return $order['deliveryAddress'];
        }

        return null;
    }

    /**
     * Extract final price from API response
     *
     * Note: In new InPost API SDK, properties use snake_case in container (final_price)
     * but getters are camelCase (getFinalPrice()). This method handles both.
     *
     * @param mixed $order
     *
     * @return array|null
     */
    private function extractFinalPrice($order): ?array
    {
        // Use extractValue with multiple path variants to support both SDK and array formats
        $finalPrice = $this->extractValue($order, ['finalPrice', 'final_price']);

        if (!$finalPrice) {
            return null;
        }

        // Extract amount and currency using extractValue for consistency
        $amount = $this->extractValue($finalPrice, ['amount'], 0.0);
        $currency = $this->extractValue($finalPrice, ['currency'], 'PLN');

        return [
            'amount' => (float) $amount,
            'currency' => (string) $currency,
        ];
    }
}
