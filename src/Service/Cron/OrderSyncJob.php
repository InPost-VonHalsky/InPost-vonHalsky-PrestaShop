<?php
/**
 * OrderSyncJob
 *
 * Cron job for synchronizing orders from InPost API.
 * Fetches pending orders and creates corresponding PrestaShop orders.
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Service\Cron;

use Inpost\VonHalsky\Service\Sync\OrderSyncService;
use Inpost\VonHalsky\Service\Util\LoggerService;
use Configuration;
use Exception;

class OrderSyncJob extends AbstractCronJob
{
    /**
     * Job name
     */
    private const JOB_NAME = 'order_sync';

    /**
     * Default interval: 10 minutes
     */
    private const DEFAULT_INTERVAL = 600;

    /**
     * Seconds one cron run may spend; the rest of the orders follows on the next
     * run, so a large backlog (e.g. the first import of a few hundred orders) is
     * spread over several runs instead of keeping the server busy for an hour
     */
    public const TIME_BUDGET = 240;

    /**
     * The whole order history is read once a day (safety net), otherwise only changes
     */
    private const FULL_SYNC_EVERY = 86400;

    /**
     * Seconds re-read before the last complete run (clock differences, slow commits)
     */
    private const INCREMENTAL_OVERLAP = 900;

    /**
     * Orders without a shop order re-fetched and retried per run
     */
    private const MAX_RETRIES_PER_RUN = 10;

    /**
     * Start of the last run that read all its orders / the last complete full run
     */
    public const LAST_COMPLETE_KEY = 'INPOSTSA_ORDERS_SYNC_LAST_COMPLETE';
    public const LAST_FULL_KEY = 'INPOSTSA_ORDERS_SYNC_LAST_FULL';

    /**
     * Import range (configuration): "continuous" - changes since the last complete run,
     * whole history once a day; "days" - always the orders changed in the last N days
     */
    public const RANGE_KEY = 'INPOSTSA_ORDERS_SYNC_RANGE';
    public const RANGE_DAYS_KEY = 'INPOSTSA_ORDERS_SYNC_RANGE_DAYS';
    public const RANGE_CONTINUOUS = 'continuous';
    public const RANGE_DAYS = 'days';
    public const DEFAULT_RANGE_DAYS = 30;

    /**
     * @var OrderSyncService
     */
    private $orderSyncService;

    /**
     * @var LoggerService
     */
    private $logger;

    /**
     * @param OrderSyncService $orderSyncService
     * @param LoggerService $logger
     */
    public function __construct(
        OrderSyncService $orderSyncService,
        LoggerService $logger
    ) {
        $this->orderSyncService = $orderSyncService;
        $this->logger = $logger;
        $this->interval = self::DEFAULT_INTERVAL;
        $this->limit = 10;
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return self::JOB_NAME;
    }

    /**
     * Start of the fixed import window ("last N days" range), null in continuous mode
     */
    public static function fixedRangeStart(): ?\DateTime
    {
        if (Configuration::get(self::RANGE_KEY) !== self::RANGE_DAYS) {
            return null;
        }

        return new \DateTime('@' . (time() - self::rangeDays() * 86400));
    }

    public static function rangeDays(): int
    {
        $days = (int) Configuration::get(self::RANGE_DAYS_KEY);

        return $days > 0 ? min($days, 3650) : self::DEFAULT_RANGE_DAYS;
    }

    /**
     * {@inheritdoc}
     *
     * Incremental by default: only orders updated in InPost since the last complete
     * run (with an overlap), plus a retry of orders still without a shop order.
     * Once a day - and until the first complete run - the whole order history is
     * read, as a safety net. New orders normally arrive earlier through the order
     * event job.
     */
    public function execute(): array
    {
        $this->logger->info('[InPost-OrderSyncJob] Starting order synchronization');

        try {
            $runStart = time();
            $deadline = microtime(true) + self::TIME_BUDGET;
            $lastComplete = (int) Configuration::getGlobalValue(self::LAST_COMPLETE_KEY);
            $lastFull = (int) Configuration::getGlobalValue(self::LAST_FULL_KEY);
            $fixedRangeStart = self::fixedRangeStart();
            if ($fixedRangeStart !== null) {
                // Fixed window: never older orders, no full-history run
                $full = false;
                $updatedSince = $fixedRangeStart;
            } else {
                $full = $lastComplete <= 0 || $runStart - $lastFull >= self::FULL_SYNC_EVERY;
                $updatedSince = $full ? null : (new \DateTime('@' . ($lastComplete - self::INCREMENTAL_OVERLAP)));
            }

            $result = $this->orderSyncService->syncOrders(null, $this->limit, true, self::TIME_BUDGET, $updatedSince);

            $fetchError = $result['fetch_error'] ?? null;
            if ($fetchError === null && empty($result['incomplete'])) {
                Configuration::updateGlobalValue(self::LAST_COMPLETE_KEY, (string) $runStart);
                if ($full) {
                    Configuration::updateGlobalValue(self::LAST_FULL_KEY, (string) $runStart);
                }
            }

            // Orders whose shop order could not be created earlier and did not change in InPost since
            $retry = null;
            if (empty($result['stopped']) && empty($result['creation_paused']) && microtime(true) < $deadline) {
                $pending = $this->orderSyncService->findOrdersWithoutShopOrder(self::MAX_RETRIES_PER_RUN);
                if ($pending !== []) {
                    $retry = $this->orderSyncService->syncOrdersById($pending, $deadline);
                }
            }

            $this->logger->info('[InPost-OrderSyncJob] Order synchronization completed', [
                'mode' => $full ? 'full' : 'incremental',
                'fetched' => $result['total_fetched'] ?? 0,
                'created' => $result['created'] ?? 0,
                'updated' => $result['updated'] ?? 0,
                'errors' => $result['errors'] ?? 0,
                'retried' => $retry['total_fetched'] ?? 0,
                'limit' => $this->limit,
            ]);

            $created = (int) ($result['shop_orders_created'] ?? 0) + (int) ($retry['shop_orders_created'] ?? 0);
            $failures = (int) ($result['shop_order_failures'] ?? 0) + (int) ($retry['shop_order_failures'] ?? 0);
            $errors = (int) ($result['errors'] ?? 0) + (int) ($retry['errors'] ?? 0);
            $lastError = $retry['last_shop_order_error'] ?? $result['last_shop_order_error'] ?? null;
            $stopped = !empty($result['stopped']) || !empty($retry['stopped']);

            $message = sprintf(
                '%s: processed %d of %s orders%s: %d shop orders created, %d not created, %d errors',
                $full ? 'Full sync' : 'Changes since ' . date('Y-m-d H:i', $updatedSince->getTimestamp())
                    . ($fixedRangeStart !== null ? ' (last ' . self::rangeDays() . ' days)' : ''),
                $result['total_fetched'] ?? 0,
                isset($result['total_available']) ? (string) $result['total_available'] : '?',
                $retry !== null ? sprintf(', retried %d without a shop order', $retry['total_fetched']) : '',
                $created,
                $failures,
                $errors
            );
            if ($stopped) {
                $message .= ' - stopped on request';
            } elseif (!empty($result['incomplete']) || !empty($retry['incomplete'])) {
                $message .= ' - time limit reached, continues on the next run';
            }

            $error = null;
            if ($fetchError !== null) {
                $error = 'Fetching orders from InPost failed: ' . $fetchError;
            } elseif ($failures > 0) {
                $error = sprintf(
                    '%d shop order(s) could not be created%s. Last error: %s',
                    $failures,
                    !empty($result['creation_paused']) || !empty($retry['creation_paused']) ? ' (creation paused after failures in a row)' : '',
                    (string) $lastError
                );
            }

            return [
                'success' => $error === null,
                'error' => $error,
                'stopped' => $stopped,
                'fetched' => $result['total_fetched'] ?? 0,
                'created' => $result['created'] ?? 0,
                'updated' => $result['updated'] ?? 0,
                'errors' => $errors,
                'limit' => $this->limit,
                'message' => $message,
            ];
        } catch (Exception $e) {
            $this->logger->error('[InPost-OrderSyncJob] Order synchronization failed', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}
