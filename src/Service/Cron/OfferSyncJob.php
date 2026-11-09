<?php
/**
 * OfferSyncJob
 *
 * Cron job for synchronizing offers with require_api_sync=1 to InPost API.
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Service\Cron;

use Exception;
use Inpost\VonHalsky\Service\Offer\OfferPriceRecalculator;
use Inpost\VonHalsky\Service\Offer\OfferService;
use Inpost\VonHalsky\Service\Util\LoggerService;

class OfferSyncJob extends AbstractCronJob
{
    /**
     * Job name
     */
    private const JOB_NAME = 'offer_sync';

    /**
     * Default interval: 5 minutes
     */
    private const DEFAULT_INTERVAL = 300;

    /**
     * @var OfferService
     */
    private $offerService;

    /**
     * @var LoggerService
     */
    private $logger;

    private OfferPriceRecalculator $priceRecalculator;

    /**
     * @param OfferService $offerService
     * @param LoggerService $logger
     * @param OfferPriceRecalculator $priceRecalculator
     */
    public function __construct(
        OfferService $offerService,
        LoggerService $logger,
        OfferPriceRecalculator $priceRecalculator
    ) {
        $this->offerService = $offerService;
        $this->logger = $logger;
        $this->priceRecalculator = $priceRecalculator;
        $this->interval = self::DEFAULT_INTERVAL;
        $this->limit = 50;
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return self::JOB_NAME;
    }

    /**
     * Execute offer sync job
     *
     * Pushes local offers with require_api_sync=1 to InPost API.
     * Status refresh is handled by offer_event_sync cron (via events).
     */
    public function execute(): array
    {
        $this->logger->info('[InPost-OfferSyncJob] Starting offer synchronization');

        $result = [
            'success' => true,
            'sync' => [],
        ];

        // Pending price recalculation (price settings, catalog price rule) first:
        // the offers whose price changed are sent right below (GOKINT-1098)
        $recalc = null;
        try {
            $recalc = $this->priceRecalculator->runPending();
        } catch (Exception $e) {
            $this->logger->error('[InPost-OfferSyncJob] Price recalculation failed', [
                'error' => $e->getMessage(),
            ]);
        }

        try {
            $syncResult = $this->offerService->sendAllOffers(
                ['require_api_sync' => true],
                false
            );

            $synced = $syncResult['summary']['total'] ?? 0;
            $created = $syncResult['summary']['created'] ?? 0;
            $updated = $syncResult['summary']['updated'] ?? 0;
            $errors = $syncResult['summary']['errors'] ?? 0;

            $this->logger->info('[InPost-OfferSyncJob] Offer sync completed', [
                'synced' => $synced,
                'created' => $created,
                'updated' => $updated,
                'errors' => $errors,
            ]);

            $result['sync'] = [
                'synced' => $synced,
                'created' => $created,
                'updated' => $updated,
                'errors' => $errors,
            ];

            $result['message'] = sprintf(
                'Sync: %d offers (%d created, %d updated, %d errors)',
                $synced,
                $created,
                $updated,
                $errors
            );
            if ($recalc !== null) {
                $result['message'] .= sprintf(
                    '; prices: %d checked, %d changed%s',
                    $recalc['checked'],
                    $recalc['changed'],
                    $recalc['done'] ? '' : ', continues'
                );
            }
        } catch (Exception $e) {
            $this->logger->error('[InPost-OfferSyncJob] Offer sync failed', [
                'error' => $e->getMessage(),
            ]);
            $result['success'] = false;
            $result['sync'] = ['error' => $e->getMessage()];
            $result['message'] = 'Sync: error - ' . $e->getMessage();
        }

        return $result;
    }
}
