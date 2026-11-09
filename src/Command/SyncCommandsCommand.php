<?php
/**
 * SyncCommandsCommand
 *
 * Diagnostic CLI for asynchronous create-offer commands. Read-only by default
 * (--list); --run delegates to PendingCommandSyncJob::execute() so there is no
 * duplicated polling logic.
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Command;

use Inpost\VonHalsky\Entity\Offer;
use Inpost\VonHalsky\Repository\OfferRepository;
use Inpost\VonHalsky\Service\Api\OfferApiService;
use Inpost\VonHalsky\Service\Cron\CronJobConfigurator;
use Inpost\VonHalsky\Service\Cron\CronManager;
use Inpost\VonHalsky\Service\Cron\PendingCommandSyncJob;
use InpostApi\Model\CommandStatus;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class SyncCommandsCommand extends Command
{
    protected static $defaultName = 'inpost:command:sync';

    private const OPT_LIST = 'list';
    private const OPT_COMMAND_ID = 'command-id';
    private const OPT_OFFER_ID = 'offer-id';
    private const OPT_RUN = 'run';

    private OfferApiService $offerApiService;
    private OfferRepository $offerRepository;
    private PendingCommandSyncJob $pendingCommandSyncJob;
    private CronManager $cronManager;
    private CronJobConfigurator $jobConfigurator;

    public function __construct(
        OfferApiService $offerApiService,
        OfferRepository $offerRepository,
        PendingCommandSyncJob $pendingCommandSyncJob,
        CronManager $cronManager,
        CronJobConfigurator $jobConfigurator
    ) {
        parent::__construct();
        $this->offerApiService = $offerApiService;
        $this->offerRepository = $offerRepository;
        $this->pendingCommandSyncJob = $pendingCommandSyncJob;
        $this->cronManager = $cronManager;
        $this->jobConfigurator = $jobConfigurator;
    }

    protected function configure(): void
    {
        $this
            ->setName('inpost:command:sync')
            ->setDescription('Diagnose and poll asynchronous create-offer commands')
            ->setHelp(
                "Read-only by default: lists offers with PENDING/FAILURE command status.\n"
                . "  --command-id=<uuid>  Query a single command status from the API.\n"
                . "  --offer-id=<id>      Show an offer's stored command bookkeeping, then query the API.\n"
                . "  --run                MUTATING: poll and finalize all pending commands.\n"
                . "Note: --run delegates to the pending-command cron job and bypasses CronManager,"
                . " so it runs regardless of the job's enabled flag or interval."
            )
            ->addOption(self::OPT_LIST, null, InputOption::VALUE_NONE, 'List offers with PENDING/FAILURE command status (default)')
            ->addOption(self::OPT_COMMAND_ID, null, InputOption::VALUE_REQUIRED, 'Query a single command status by UUID')
            ->addOption(self::OPT_OFFER_ID, null, InputOption::VALUE_REQUIRED, 'Diagnose a single offer by local offer_id')
            ->addOption(self::OPT_RUN, null, InputOption::VALUE_NONE, 'Poll and finalize pending commands (mutating)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            if ($input->getOption(self::OPT_RUN)) {
                return $this->runPoller($io);
            }

            $commandId = $input->getOption(self::OPT_COMMAND_ID);
            if ($commandId !== null) {
                return $this->queryCommand($io, (string) $commandId);
            }

            $offerId = $input->getOption(self::OPT_OFFER_ID);
            if ($offerId !== null) {
                return $this->diagnoseOffer($io, (int) $offerId);
            }

            return $this->listPending($io);
        } catch (\Exception $e) {
            $io->error('Error: ' . $e->getMessage());
            if ($output->isVerbose()) {
                $io->section('Stack Trace:');
                $io->text($e->getTraceAsString());
            }
            return 1; // Command::FAILURE (Symfony 5.1+ constant; literal for PS8 compat)
        }
    }

    /**
     * Read-only listing of offers with an active/failed command.
     */
    private function listPending(SymfonyStyle $io): int
    {
        $io->title('InPost Async Commands');

        $offers = $this->offerRepository->findByCommandStatuses([
            CommandStatus::PENDING,
            CommandStatus::FAILURE,
        ]);

        if (empty($offers)) {
            $io->info('No offers in PENDING/FAILURE command status.');
            $io->text('Tip: use --run to poll, or --command-id=<uuid> to query a specific command.');
            return 0; // Command::SUCCESS (Symfony 5.1+ constant; literal for PS8 compat)
        }

        $rows = [];
        foreach ($offers as $offer) {
            $rows[] = [
                $offer->getOfferId(),
                $offer->getStatus(),
                $this->colorizeStatus((string) $offer->getCommandStatus()),
                $offer->getCommandRetryCount(),
                $offer->getInpostCommandId() ?? '-',
                $offer->getInpostOfferId() ?? '-',
            ];
        }

        $io->table(
            ['offer_id', 'offer status', 'command status', 'retry', 'inpost_command_id', 'inpost_offer_id'],
            $rows
        );
        $io->writeln('<comment>Read-only view. Use --run to poll and finalize these commands.</comment>');

        return 0; // Command::SUCCESS (Symfony 5.1+ constant; literal for PS8 compat)
    }

    /**
     * Query a single command status directly from the API.
     */
    private function queryCommand(SymfonyStyle $io, string $commandId): int
    {
        $io->title('InPost Command Status');
        $io->text('Command ID: <info>' . $commandId . '</info>');

        $response = $this->offerApiService->getOfferCommandStatus($commandId);

        if (!($response['success'] ?? false)) {
            $io->error('API call failed: ' . ($response['error'] ?? 'unknown'));
            return 1; // Command::FAILURE (Symfony 5.1+ constant; literal for PS8 compat)
        }

        $io->text('HTTP status: ' . ($response['status_code'] ?? 'n/a'));

        $data = $response['data'] ?? null;
        $status = (is_object($data) && method_exists($data, 'getStatus'))
            ? (string) $data->getStatus()
            : (string) ($data['status'] ?? '');

        $io->text('Command status: ' . $this->colorizeStatus($status));

        $commandErrors = $response['command_errors'] ?? [];
        if (!empty($commandErrors)) {
            $io->section('Command errors');
            $rows = [];
            foreach ($commandErrors as $error) {
                $rows[] = [
                    $error['field'] ?? '-',
                    $error['element_id'] ?? '-',
                    $error['message'] ?? '',
                ];
            }
            $io->table(['field', 'element_id', 'message'], $rows);
        }

        return 0; // Command::SUCCESS (Symfony 5.1+ constant; literal for PS8 compat)
    }

    /**
     * Show an offer's stored bookkeeping, then chain into the live API query.
     */
    private function diagnoseOffer(SymfonyStyle $io, int $offerId): int
    {
        $io->title('InPost Offer Command Diagnosis');

        /** @var Offer|null $offer */
        $offer = $this->offerRepository->find($offerId);
        if ($offer === null) {
            $io->error('Offer not found: offer_id=' . $offerId);
            return 1; // Command::FAILURE (Symfony 5.1+ constant; literal for PS8 compat)
        }

        $io->definitionList(
            ['offer_id' => $offer->getOfferId()],
            ['offer status' => $offer->getStatus()],
            ['inpost_offer_id' => $offer->getInpostOfferId() ?? '-'],
            ['inpost_command_id' => $offer->getInpostCommandId() ?? '-'],
            ['command status' => $this->colorizeStatus((string) $offer->getCommandStatus())],
            ['retry count' => $offer->getCommandRetryCount()]
        );

        $commandId = $offer->getInpostCommandId();
        if ($commandId === null || $commandId === '') {
            $io->writeln('<comment>No command ID stored — nothing to poll for this offer.</comment>');
            return 0; // Command::SUCCESS (Symfony 5.1+ constant; literal for PS8 compat)
        }

        return $this->queryCommand($io, $commandId);
    }

    /**
     * Delegate to the cron job (mutating) through CronManager, so the job lock
     * prevents a parallel cron run from re-submitting the same offer.
     */
    private function runPoller(SymfonyStyle $io): int
    {
        $io->title('InPost Command Poller');
        $io->warning('Running the poller: this will mutate offers (retry / finalize).');

        $this->jobConfigurator->configure($this->pendingCommandSyncJob);
        $run = $this->cronManager->runJobNow($this->pendingCommandSyncJob);
        if (!empty($run['skipped'])) {
            $io->warning('Skipped: ' . ($run['reason'] ?? 'unknown reason') . ' (the cron job is running right now).');
            return 0;
        }
        if (!isset($run['result'])) {
            $io->error('Poller failed: ' . ($run['error'] ?? 'unknown error'));
            return 1;
        }

        $result = $run['result'];
        $counters = $result['counters'] ?? [];

        $io->section('Result');
        $io->text($result['message'] ?? '');
        if (!empty($counters)) {
            $io->definitionList(
                ['success' => $counters['success'] ?? 0],
                ['pending' => $counters['pending'] ?? 0],
                ['retried' => $counters['retried'] ?? 0],
                ['exhausted' => $counters['exhausted'] ?? 0],
                ['errors' => $counters['errors'] ?? 0]
            );
        }

        return ($result['success'] ?? false) ? 0 : 1; // Command::SUCCESS / FAILURE (Symfony 5.1+ constants; literals for PS8 compat)
    }

    /**
     * Wrap a command status string in Symfony console color tags.
     */
    private function colorizeStatus(string $status): string
    {
        switch ($status) {
            case CommandStatus::SUCCESS:
                return '<info>' . $status . '</info>';
            case CommandStatus::FAILURE:
                return '<error>' . $status . '</error>';
            case CommandStatus::PENDING:
                return '<comment>' . $status . '</comment>';
            default:
                return $status !== '' ? $status : '-';
        }
    }
}
