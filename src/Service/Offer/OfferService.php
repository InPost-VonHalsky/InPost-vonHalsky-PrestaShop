<?php
/**
 * OfferService
 *
 * Unified service for synchronizing offers with InPost von Halsky API.
 * Consolidates OfferSyncService + SendOfferService functionality.
 * Always refreshes status from API after sync (API is authoritative source).
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Service\Offer;

use Inpost\VonHalsky\Service\Business\OfferBuilderService;
use Inpost\VonHalsky\Service\Business\OfferValidatorService;
use Inpost\VonHalsky\Service\Api\OfferApiService;
use Inpost\VonHalsky\Service\Util\ConfigurationService;
use Inpost\VonHalsky\Service\Util\LoggerService;
use Inpost\VonHalsky\Repository\OfferRepository;
use Inpost\VonHalsky\Service\Sync\ProductOfferSyncService;
use Inpost\VonHalsky\Entity\Offer;
use InpostApi\Model\CommandStatus;
use Db;
use DbQuery;

class OfferService
{
    private OfferBuilderService $offerBuilderService;
    private OfferValidatorService $offerValidatorService;
    private OfferApiService $offerApiService;
    private ConfigurationService $configurationService;
    private LoggerService $logger;
    private OfferRepository $offerRepository;
    private ProductOfferSyncService $productOfferSync;
    private Db $db;

    public function __construct(
        OfferBuilderService $offerBuilderService,
        OfferValidatorService $offerValidatorService,
        OfferApiService $offerApiService,
        ConfigurationService $configurationService,
        LoggerService $logger,
        OfferRepository $offerRepository,
        ProductOfferSyncService $productOfferSync
    ) {
        $this->offerBuilderService = $offerBuilderService;
        $this->offerValidatorService = $offerValidatorService;
        $this->offerApiService = $offerApiService;
        $this->configurationService = $configurationService;
        $this->logger = $logger;
        $this->offerRepository = $offerRepository;
        $this->productOfferSync = $productOfferSync;
        $this->db = Db::getInstance();
    }

    // =========================================================================
    // PUBLIC API
    // =========================================================================

    /**
     * Send offer to InPost API (create or update)
     *
     * Key behaviors:
     * - Validates is_draft=0 before sending
     * - No inpost_offer_id → POST (create new offer)
     * - Has inpost_offer_id → PUT (update existing offer)
     * - ALWAYS refreshes status from API after sync (API is authoritative)
     *
     * @param int $offerId Local offer_id from ps_inpost_offer
     * @param bool $force Force sync even if no changes detected
     * @return array Sync result with 'success', 'inpost_offer_id', 'status'
     */
    public function sendOffer(int $offerId, bool $force = false): array
    {
        $this->logger->info("Sending offer to InPost", ['offer_id' => $offerId, 'force' => $force]);

        $offer = $this->getOffer($offerId);
        if (!$offer) {
            return $this->errorResult("Offer not found: offer_id={$offerId}");
        }

        // Validate: is_draft must be 0
        if ((int)($offer['is_draft'] ?? 1) === 1) {
            return $this->errorResult("Offer is in draft mode. Set is_draft=0 to enable sending.");
        }

        $inpostOfferId = $offer['inpost_offer_id'] ?? null;
        $isCreate = empty($inpostOfferId);
        $organizationId = $offer['organization_id'] ?? null;

        if (!$organizationId) {
            return $this->errorResult("Organization ID is required");
        }

        try {
            $offerEntity = $this->offerRepository->find($offerId);
            if (!$offerEntity) {
                return $this->errorResult("Offer entity not found for ID: {$offerId}");
            }

            // Current price / stock / mapped attribute values from PrestaShop: the offer
            // may only have been flagged (front office checkout cannot run the sync)
            try {
                $this->productOfferSync->refreshOffer($offerEntity);
            } catch (\Throwable $e) {
                $this->logger->warning("Could not refresh offer from PrestaShop before sending", [
                    'offer_id' => $offerId,
                    'error' => $e->getMessage(),
                ]);
            }

            // Build payload from offer data
            $payload = $this->offerBuilderService->buildOfferPayload($offerId);

            $language = $this->configurationService->getDefaultLanguage();
            $validation = $this->offerValidatorService->validate($offerEntity, $language);
            $validationStatus = $this->offerValidatorService->getValidationStatus($validation);

            if (!$validation['can_submit']) {
                $this->updateSyncStatus($offerId, 'validation_failed', $validationStatus);
                return $this->errorResult("Validation failed", $validation['errors']);
            }

            // Send to InPost API (patchOffer() throws on HTTP errors)
            if ($isCreate) {
                $inpostOfferId = $this->submitCreate($offerId, $payload, $language);
            } else {
                $this->offerApiService->patchOffer($inpostOfferId, $payload, $language);

                $this->logger->info("Offer updated in InPost", [
                    'offer_id' => $offerId,
                    'inpost_offer_id' => $inpostOfferId,
                ]);
            }

            // On CREATE the offer is not materialised yet (async command PENDING);
            // the pending-command poller performs the authoritative refresh once
            // the command reaches SUCCESS. Refreshing here would 404 or clobber
            // the PENDING we just wrote. Only refresh on PATCH.
            if ($isCreate) {
                $apiStatus = Offer::STATUS_PENDING;
            } else {
                $refreshResult = $this->refreshOfferStatus($offerId);
                $apiStatus = $refreshResult['new_status'] ?? 'PENDING';
            }

            // Update sync status to synced
            // Don't pass $validationStatus - let refreshOfferStatus() API validation stand
            $this->updateSyncStatus($offerId, 'synced');

            return $this->successResult("Offer " . ($isCreate ? "created" : "updated") . " successfully", [
                'offer_id' => $offerId,
                'inpost_offer_id' => $inpostOfferId,
                'status' => $apiStatus,
                'action' => $isCreate ? 'created' : 'updated',
            ]);

        } catch (\Exception $e) {
            $this->logger->error("Failed to send offer", [
                'offer_id' => $offerId,
                'inpost_offer_id' => $inpostOfferId ?? null,
                'is_create' => $isCreate ?? null,
                'error' => $e->getMessage(),
            ]);

            $this->updateSyncStatus($offerId, 'error');

            // A permanent client error (bad payload, offer gone in InPost) fails the
            // same way on every cron run - stop auto-retrying until the offer is
            // edited or re-sent manually (both set require_api_sync = 1 again).
            $statusCode = (int) $e->getCode();
            if ($statusCode >= 400 && $statusCode < 500 && !in_array($statusCode, [401, 408, 409, 429], true)) {
                $this->db->update('inpost_offer', ['require_api_sync' => 0], self::offerCondition($offerId));
                $this->logger->warning("Offer auto-sync paused after permanent API error", [
                    'offer_id' => $offerId,
                    'status_code' => $statusCode,
                ]);
            }

            return $this->errorResult("Sync failed: {$e->getMessage()}");
        }
    }

    /**
     * POST a new offer. Creation is asynchronous: the returned offer + command ids
     * are stored with status PENDING; the pending-command poller finalizes it.
     *
     * @return string InPost offer id
     * @throws \Exception On API failure or an incomplete response
     */
    private function submitCreate(int $offerId, array $payload, string $language): string
    {
        $response = $this->offerApiService->createOffer($payload, $language);
        if (!$response['success']) {
            throw new \Exception($response['error'] ?? 'API call failed', (int) ($response['status_code'] ?? 0));
        }

        $data = $response['data'];
        $inpostOfferId = is_object($data) && method_exists($data, 'getOfferId')
            ? $data->getOfferId()
            : ($data['offerId'] ?? null);
        $commandId = is_object($data) && method_exists($data, 'getCommandId')
            ? $data->getCommandId()
            : ($data['commandId'] ?? null);

        if (!$inpostOfferId) {
            throw new \Exception("API response missing offer ID");
        }
        if (!$commandId) {
            throw new \Exception("API response missing command ID");
        }

        // Do NOT reset command_retry_count here: the pending-command poller
        // triggers this very re-create and relies on that count surviving
        // across attempts.
        $this->db->update('inpost_offer', [
            'inpost_offer_id' => pSQL($inpostOfferId),
            'inpost_command_id' => pSQL($commandId),
            'command_status' => pSQL(CommandStatus::PENDING),
            'status' => pSQL(Offer::STATUS_PENDING),
            'updated_at' => date('Y-m-d H:i:s'),
        ], self::offerCondition($offerId));

        $this->logger->info("Offer create command submitted to InPost", [
            'offer_id' => $offerId,
            'inpost_offer_id' => $inpostOfferId,
            'command_id' => $commandId,
        ]);

        return (string) $inpostOfferId;
    }

    /**
     * Batch send multiple offers
     *
     * @param array $offerIds Array of local offer_ids
     * @param bool $force Force sync
     * @return array Batch result with summary
     */
    public function sendOffers(array $offerIds, bool $force = false): array
    {
        $this->logger->info("Batch sending offers", ['count' => count($offerIds)]);

        $results = [
            'created' => [],
            'updated' => [],
            'errors' => [],
        ];

        foreach ($offerIds as $offerId) {
            $result = $this->sendOffer((int)$offerId, $force);

            if ($result['success']) {
                $action = $result['data']['action'] ?? 'updated';
                if ($action === 'created') {
                    $results['created'][] = $offerId;
                } else {
                    $results['updated'][] = $offerId;
                }
            } else {
                $results['errors'][$offerId] = $result['error'];
            }
        }

        $summary = [
            'total' => count($offerIds),
            'created' => count($results['created']),
            'updated' => count($results['updated']),
            'errors' => count($results['errors']),
        ];

        $this->logger->info("Batch send complete", $summary);

        return [
            'success' => true,
            'summary' => $summary,
            'results' => $results,
        ];
    }

    /**
     * Send all offers matching filters
     *
     * @param array $filters Optional filters (shop_id, status, require_api_sync, etc.)
     * @param bool $force Force sync
     * @return array Sync result
     */
    public function sendAllOffers(array $filters = [], bool $force = false): array
    {
        $this->logger->info("Sending all offers", ['filters' => $filters]);

        $offerIds = $this->getOfferIds($filters);

        if (empty($offerIds)) {
            return $this->successResult("No offers to send");
        }

        return $this->sendOffers($offerIds, $force);
    }

    public const CLOSABLE_STATUSES = [Offer::STATUS_PUBLISHED, Offer::STATUS_SOLDOUT];
    public const REOPENABLE_STATUSES = [Offer::STATUS_CLOSED];

    /**
     * Close a published or sold-out offer on InPost marketplace
     *
     * @param int $offerId Local offer_id
     * @return array Close result with 'success', 'message', and 'data'
     */
    public function closeOffer(int $offerId): array
    {
        return $this->changeOfferStatus($offerId, 'close', self::CLOSABLE_STATUSES);
    }

    /**
     * Reopen a closed offer on InPost marketplace
     *
     * @param int $offerId Local offer_id
     * @return array Reopen result with 'success', 'message', and 'data'
     */
    public function reopenOffer(int $offerId): array
    {
        return $this->changeOfferStatus($offerId, 'reopen', self::REOPENABLE_STATUSES);
    }

    /**
     * Close or reopen an offer on InPost, then refresh its status from the API
     *
     * @param string $action 'close' or 'reopen' (OfferApiService method prefix)
     * @param string[] $allowedStatuses Statuses the offer may currently have
     */
    private function changeOfferStatus(int $offerId, string $action, array $allowedStatuses): array
    {
        $label = ucfirst($action);
        $done = $action === 'close' ? 'closed' : 'reopened';
        $this->logger->info("{$label} offer on InPost", ['offer_id' => $offerId]);

        $offer = $this->getOffer($offerId);
        if (!$offer) {
            return $this->errorResult("Offer not found: offer_id={$offerId}");
        }

        $inpostOfferId = $offer['inpost_offer_id'] ?? null;
        if (empty($inpostOfferId)) {
            return $this->errorResult("Offer not synced to InPost - cannot {$action}");
        }

        $currentStatus = $offer['status'] ?? '';
        if (!in_array($currentStatus, $allowedStatuses, true)) {
            return $this->errorResult('Only ' . implode('/', $allowedStatuses) . " offers can be {$done} (current: {$currentStatus})");
        }

        try {
            $apiResult = $action === 'close'
                ? $this->offerApiService->closeOffer($inpostOfferId)
                : $this->offerApiService->reopenOffer($inpostOfferId);
            if (empty($apiResult['success'])) {
                return $this->errorResult("{$label} failed: " . ($apiResult['error'] ?? 'API error'));
            }

            // Refresh status from API (authoritative source)
            $this->refreshOfferStatus($offerId);

            $this->logger->info("Offer {$done} on InPost", ['offer_id' => $offerId]);

            return $this->successResult("Offer {$done} successfully", [
                'offer_id' => $offerId,
                'inpost_offer_id' => $inpostOfferId,
            ]);
        } catch (\Exception $e) {
            $this->logger->error("Failed to {$action} offer", [
                'offer_id' => $offerId,
                'error' => $e->getMessage(),
            ]);

            return $this->errorResult("{$label} failed: {$e->getMessage()}");
        }
    }

    /**
     * Refresh offer status from InPost API (authoritative source)
     *
     * Fetches current state from API and updates local DB.
     *
     * @param int $offerId Local offer_id
     * @return array Result with 'success', 'status_changed', 'new_status'
     */
    public function refreshOfferStatus(int $offerId): array
    {
        $offer = $this->getOffer($offerId);
        if (!$offer) {
            return $this->errorResult("Offer not found: offer_id={$offerId}");
        }

        $inpostOfferId = $offer['inpost_offer_id'] ?? null;
        if (empty($inpostOfferId)) {
            return $this->errorResult("Offer not synced to InPost (no inpost_offer_id)");
        }

        $organizationId = $offer['organization_id'] ?? null;
        if (!$organizationId) {
            return $this->errorResult("Missing organization_id");
        }

        try {
            $this->logger->info("Refreshing offer status from API", [
                'offer_id' => $offerId,
                'inpost_offer_id' => $inpostOfferId,
            ]);

            $response = $this->offerApiService->getOffer($inpostOfferId);

            if (!$response['success']) {
                throw new \Exception($response['error'] ?? 'API call failed');
            }

            // SDK returns OfferWithMetadata object - extract nested offer and metadata
            $data = $response['data'];

            // Extract inner offer from OfferWithMetadata wrapper
            $offerData = is_object($data) && method_exists($data, 'getOffer')
                ? $data->getOffer()
                : ($data['offer'] ?? null);

            // Get status from inner offer object
            $apiStatus = null;
            if ($offerData) {
                $apiStatus = is_object($offerData) && method_exists($offerData, 'getStatus')
                    ? $offerData->getStatus()
                    : ($offerData['status'] ?? null);
            }
            $oldStatus = $offer['status'] ?? null;
            $statusChanged = ($apiStatus && $oldStatus !== $apiStatus);

            // Extract validation errors (check multiple possible locations)
            $metadata = is_object($data) && method_exists($data, 'getMetadata')
                ? $data->getMetadata()
                : ($data['metadata'] ?? null);
            $validationErrors = [];
            if ($metadata) {
                $validationErrors = is_object($metadata) && method_exists($metadata, 'getValidationErrors')
                    ? ($metadata->getValidationErrors() ?? [])
                    : ($metadata['validation_errors'] ?? $metadata['validationErrors'] ?? []);
            }
            if (empty($validationErrors) && is_array($data)) {
                $validationErrors = $data['validationErrors'] ?? $data['validation_errors'] ?? [];
            }

            // Update local DB with authoritative data from API
            $updateData = [
                'synced_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ];

            if ($apiStatus) {
                $updateData['status'] = pSQL($apiStatus);
            }

            if (is_array($validationErrors)) {
                $updateData['inpost_validation'] = pSQL(json_encode($validationErrors));
                $updateData['inpost_validation_status'] = pSQL(
                    !empty($validationErrors) ? 'INVALID' : 'VALID'
                );
            }

            $this->db->update('inpost_offer', $updateData, self::offerCondition($offerId));

            $this->logger->info("Offer status refreshed from API", [
                'offer_id' => $offerId,
                'status_changed' => $statusChanged,
                'old_status' => $oldStatus,
                'new_status' => $apiStatus,
            ]);

            return [
                'success' => true,
                'offer_id' => $offerId,
                'inpost_offer_id' => $inpostOfferId,
                'status_changed' => $statusChanged,
                'old_status' => $oldStatus,
                'new_status' => $apiStatus,
                'validation_error_count' => is_array($validationErrors) ? count($validationErrors) : 0,
            ];
        } catch (\Exception $e) {
            $this->logger->error("Failed to refresh offer status from API", [
                'offer_id' => $offerId,
                'inpost_offer_id' => $inpostOfferId,
                'error' => $e->getMessage(),
            ]);

            return $this->errorResult("Refresh failed: {$e->getMessage()}");
        }
    }

    // =========================================================================
    // PRIVATE - DATABASE ACCESS
    // =========================================================================

    /**
     * Get offer by local offer_id
     */
    private function getOffer(int $offerId): ?array
    {
        $query = new DbQuery();
        $query->select('*')
            ->from('inpost_offer')
            ->where(self::offerCondition($offerId));

        $result = $this->db->getRow($query);
        return $result ?: null;
    }

    /**
     * Get offer IDs matching filters
     */
    private function getOfferIds(array $filters = []): array
    {
        $query = new DbQuery();
        $query->select('offer_id')->from('inpost_offer');

        if (!empty($filters['shop_id'])) {
            $query->where('shop_id = ' . (int)$filters['shop_id']);
        }

        if (!empty($filters['status'])) {
            $query->where("status = '" . pSQL($filters['status']) . "'");
        }

        if (isset($filters['require_api_sync']) && $filters['require_api_sync']) {
            $query->where('require_api_sync = 1');
        }

        if (isset($filters['has_inpost_id']) && $filters['has_inpost_id']) {
            $query->where("inpost_offer_id IS NOT NULL AND inpost_offer_id != ''");
        }

        if (isset($filters['no_inpost_id']) && $filters['no_inpost_id']) {
            $query->where("(inpost_offer_id IS NULL OR inpost_offer_id = '')");
        }

        if (isset($filters['is_draft'])) {
            $query->where('is_draft = ' . (int)$filters['is_draft']);
        }

        $results = $this->db->executeS($query);
        return array_column($results ?: [], 'offer_id');
    }

    /**
     * Touch the offer after a send attempt; a successful sync also clears the
     * require_api_sync flag. Error details live in the offer's command fields.
     */
    private function updateSyncStatus(int $offerId, string $syncStatus, ?string $validationStatus = null): void
    {
        $data = [
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        if ($syncStatus === 'synced') {
            $data['synced_at'] = date('Y-m-d H:i:s');
            $data['require_api_sync'] = 0;
        }

        if ($validationStatus !== null) {
            $data['inpost_validation_status'] = pSQL($validationStatus);
        }

        $this->db->update('inpost_offer', $data, self::offerCondition($offerId));
    }

    /**
     * WHERE condition selecting one inpost_offer row
     */
    private static function offerCondition(int $offerId): string
    {
        return 'offer_id = ' . $offerId;
    }

    // =========================================================================
    // PRIVATE - RESULT HELPERS
    // =========================================================================

    private function successResult(string $message, array $data = []): array
    {
        return ['success' => true, 'message' => $message, 'data' => $data];
    }

    private function errorResult(string $error, array $details = []): array
    {
        return ['success' => false, 'error' => $error, 'details' => $details];
    }
}
