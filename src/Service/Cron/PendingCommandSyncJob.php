<?php
/**
 * PendingCommandSyncJob
 *
 * Polls asynchronous create-offer commands (command_status = PENDING) and
 * finalizes them: SUCCESS refreshes the offer from the API, FAILURE retries up
 * to a configurable limit and then marks the offer ERROR_NOT_CREATED.
 *
 * Shared unit: the inpost:command:sync CLI command's --run mode calls this
 * job's execute() directly, so there is no duplicated polling logic.
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Service\Cron;

use Configuration;
use Db;
use Exception;
use Inpost\VonHalsky\Entity\Offer;
use Inpost\VonHalsky\Repository\OfferRepository;
use Inpost\VonHalsky\Service\Api\OfferApiService;
use Inpost\VonHalsky\Service\Offer\OfferAttachmentService;
use Inpost\VonHalsky\Service\Offer\OfferService;
use Inpost\VonHalsky\Service\Util\ConfigurationService;
use Inpost\VonHalsky\Service\Util\LoggerService;
use InpostApi\Model\CommandStatus;

class PendingCommandSyncJob extends AbstractCronJob
{
    private const JOB_NAME = 'pending_command_sync';

    /**
     * Default interval: 3 minutes (matches the recommended every-3-minutes schedule).
     */
    private const DEFAULT_INTERVAL = 180;

    private const DEFAULT_MAX_RETRIES = 3;

    private OfferRepository $offerRepository;
    private OfferApiService $offerApiService;
    private OfferService $offerService;
    private LoggerService $logger;
    private ?OfferAttachmentService $attachmentService;
    private Db $db;

    public function __construct(
        OfferRepository $offerRepository,
        OfferApiService $offerApiService,
        OfferService $offerService,
        LoggerService $logger,
        ?OfferAttachmentService $attachmentService = null
    ) {
        $this->offerRepository = $offerRepository;
        $this->offerApiService = $offerApiService;
        $this->offerService = $offerService;
        $this->logger = $logger;
        $this->attachmentService = $attachmentService;
        $this->db = Db::getInstance();
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
     * {@inheritdoc}
     */
    public function execute(): array
    {
        $this->logger->info('[InPost-PendingCommandSyncJob] Starting async command polling');

        $maxRetries = (int) Configuration::get(
            ConfigurationService::CREATE_COMMAND_MAX_RETRIES,
            null,
            null,
            null,
            self::DEFAULT_MAX_RETRIES
        );
        if ($maxRetries < 0) {
            $maxRetries = self::DEFAULT_MAX_RETRIES;
        }

        $counters = [
            'success' => 0,
            'pending' => 0,
            'retried' => 0,
            'exhausted' => 0,
            'errors' => 0,
        ];

        $offers = $this->offerRepository->findPendingCommands($this->getLimit());

        foreach ($offers as $offer) {
            try {
                $this->processOffer($offer, $maxRetries, $counters);
            } catch (Exception $e) {
                $counters['errors']++;
                $this->logger->error('[InPost-PendingCommandSyncJob] Failed to process offer', [
                    'offer_id' => $offer->getOfferId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $message = sprintf(
            'Commands: %d success, %d pending, %d retried, %d exhausted, %d errors',
            $counters['success'],
            $counters['pending'],
            $counters['retried'],
            $counters['exhausted'],
            $counters['errors']
        );

        $this->logger->info('[InPost-PendingCommandSyncJob] ' . $message, $counters);

        return [
            'success' => true,
            'message' => $message,
            'counters' => $counters,
        ];
    }

    /**
     * Poll a single offer's create command and act on the result.
     *
     * @param Offer $offer
     * @param int $maxRetries
     * @param array<string,int> $counters
     */
    private function processOffer(Offer $offer, int $maxRetries, array &$counters): void
    {
        $offerId = (int) $offer->getOfferId();
        $commandId = (string) $offer->getInpostCommandId();

        $response = $this->offerApiService->getOfferCommandStatus($commandId);

        // Transport/HTTP failure: leave the row PENDING and do NOT increment the
        // retry counter. Transient API errors must not burn the retry budget.
        if (!($response['success'] ?? false)) {
            $counters['errors']++;
            $this->logger->warning('[InPost-PendingCommandSyncJob] Command status call failed; will retry next run', [
                'offer_id' => $offerId,
                'command_id' => $commandId,
                'error' => $response['error'] ?? 'unknown',
            ]);
            return;
        }

        $data = $response['data'] ?? null;
        $status = (is_object($data) && method_exists($data, 'getStatus'))
            ? (string) $data->getStatus()
            : (string) ($data['status'] ?? '');

        switch ($status) {
            case CommandStatus::SUCCESS:
                $this->updateCommandFields($offerId, [
                    'command_status' => CommandStatus::SUCCESS,
                    'command_retry_count' => 0,
                    'command_error' => null,
                ]);
                // Pull the authoritative status (PUBLISHED / REJECTED / ...).
                $this->offerService->refreshOfferStatus($offerId);
                // Attachments added while the offer was a draft can go now
                if ($this->attachmentService !== null) {
                    $this->attachmentService->processOffer($offerId);
                }
                $counters['success']++;
                $this->logger->info('[InPost-PendingCommandSyncJob] Create command succeeded', [
                    'offer_id' => $offerId,
                    'command_id' => $commandId,
                ]);
                break;

            case CommandStatus::PENDING:
                $counters['pending']++;
                $this->logger->info('[InPost-PendingCommandSyncJob] Command still pending', [
                    'offer_id' => $offerId,
                    'command_id' => $commandId,
                ]);
                break;

            case CommandStatus::FAILURE:
                $errorText = (string) ($response['command_error_text'] ?? '');
                if ($errorText !== '') {
                    $this->logger->warning('[InPost-PendingCommandSyncJob] Create command failed with API errors', [
                        'offer_id' => $offerId,
                        'command_id' => $commandId,
                        'command_errors' => $response['command_errors'] ?? [],
                        'command_error_text' => $errorText,
                    ]);
                }
                $this->handleFailure($offer, $maxRetries, $counters, $errorText);
                break;

            default:
                $counters['errors']++;
                $this->logger->warning('[InPost-PendingCommandSyncJob] Unexpected command status', [
                    'offer_id' => $offerId,
                    'command_id' => $commandId,
                    'status' => $status,
                ]);
                break;
        }
    }

    /**
     * Handle a FAILURE command status: clear the failed association, bump the
     * retry counter, and either re-send (fresh create) or mark ERROR_NOT_CREATED
     * once the retry limit is exceeded.
     *
     * @param Offer $offer
     * @param int $maxRetries
     * @param array<string,int> $counters
     * @param string $errorText Human-readable API failure reason(s), if any.
     */
    private function handleFailure(Offer $offer, int $maxRetries, array &$counters, string $errorText = ''): void
    {
        $offerId = (int) $offer->getOfferId();
        $retry = (int) $offer->getCommandRetryCount() + 1;

        // Clearing inpost_offer_id forces the create branch on the next send.
        $fields = [
            'inpost_offer_id' => null,
            'inpost_command_id' => null,
            'command_status' => CommandStatus::FAILURE,
            'command_retry_count' => $retry,
            'command_error' => $errorText !== '' ? $errorText : null,
        ];

        if ($retry > $maxRetries) {
            $fields['status'] = Offer::STATUS_ERROR_NOT_CREATED;
            $this->updateCommandFields($offerId, $fields);
            $counters['exhausted']++;
            $this->logger->error('[InPost-PendingCommandSyncJob] Create command failed past retry limit', [
                'offer_id' => $offerId,
                'command_id' => $offer->getInpostCommandId(),
                'retry_count' => $retry,
                'max_retries' => $maxRetries,
                'command_error_text' => $errorText,
            ]);
            return;
        }

        $this->updateCommandFields($offerId, $fields);
        $this->logger->warning('[InPost-PendingCommandSyncJob] Create command failed; re-submitting offer', [
            'offer_id' => $offerId,
            'retry_count' => $retry,
        ]);

        $result = $this->offerService->sendOffer($offerId);
        if ($result['success'] ?? false) {
            $counters['retried']++;
        } else {
            $counters['errors']++;
            $this->logger->error('[InPost-PendingCommandSyncJob] Re-submit failed', [
                'offer_id' => $offerId,
                'error' => $result['error'] ?? 'unknown',
            ]);
        }
    }

    /**
     * Write command bookkeeping with raw Db. OfferService also writes with raw
     * Db, so this job keeps Doctrine read-only and never flushes — mixing the
     * two would let a flush clobber the raw-SQL writes.
     *
     * @param int $offerId
     * @param array<string,mixed> $fields
     */
    private function updateCommandFields(int $offerId, array $fields): void
    {
        $data = [];
        foreach ($fields as $column => $value) {
            $data[$column] = $value === null ? null : pSQL((string) $value);
        }
        $data['updated_at'] = date('Y-m-d H:i:s');

        $this->db->update('inpost_offer', $data, 'offer_id = ' . (int) $offerId, 0, true);
    }
}
