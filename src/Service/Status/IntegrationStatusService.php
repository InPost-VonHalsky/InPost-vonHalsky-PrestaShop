<?php
/**
 * IntegrationStatusService
 *
 * Collects the health of the whole integration for the status panel:
 * environment compatibility, InPost connection, cron jobs, catalog
 * (categories/attributes), offers, offer events, orders and the error journal.
 *
 * Read-only and local: it never calls the InPost API, so the panel loads fast
 * and works even when the API is down (which is exactly when you need it).
 *
 * Every check is an array: label, value, status (ok|info|warning|error), hint.
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Service\Status;

use Configuration;
use Db;
use Inpost\VonHalsky\Repository\LogJournalRepository;
use Inpost\VonHalsky\Service\ApiTokenManager;
use Inpost\VonHalsky\Service\Cron\AbstractCronJob;
use Inpost\VonHalsky\Service\Cron\CronJobConfigurator;
use Inpost\VonHalsky\Service\Cron\CronManager;
use Inpost\VonHalsky\Service\Sync\OrderSyncService;
use Inpost\VonHalsky\Service\Util\ConfigurationService;
use Inpost\VonHalsky\Service\Util\ShopTaxCurrencyInfo;
use Module;
use Symfony\Contracts\Translation\TranslatorInterface;

class IntegrationStatusService
{
    public const OK = 'ok';
    public const INFO = 'info';
    public const WARNING = 'warning';
    public const ERROR = 'error';

    private const SEVERITY = [self::OK => 0, self::INFO => 1, self::WARNING => 2, self::ERROR => 3];

    /** Minimum PHP version required by the module (composer.json) */
    public const PHP_MIN_VERSION = '8.1.0';

    /** PHP branches the module is tested on (PrestaShop 8.x supports PHP up to 8.1) */
    public const PHP_TESTED_BRANCHES = ['8.1'];

    /** PHP extensions used by the module and the InPost SDK */
    private const REQUIRED_EXTENSIONS = ['curl', 'json', 'mbstring', 'openssl', 'pdo_mysql'];

    private const RECOMMENDED_MEMORY_LIMIT = 256 * 1024 * 1024;

    /** No cron activity for this long means the system cron is not running */
    private const CRON_HEARTBEAT_MAX_AGE = 1800;

    /** Panel area each section rolls up into */
    private const SECTION_AREA = [
        'environment' => 'connection',
        'connection' => 'connection',
        'cron' => 'sync',
        'catalog' => 'sync',
        'journal' => 'sync',
        'offers' => 'offers',
        'orders' => 'orders',
    ];

    /** InPost order statuses that never get a PrestaShop order (see OrderSyncService) */
    private const ORDER_STATUSES_WITHOUT_PS_ORDER = ['created', 'pending', 'refused', 'rejected', 'canceled', 'cancelled'];

    /** A PENDING create command older than this is considered stuck */
    private const STUCK_COMMAND_AGE = 3600;

    private const DOMAIN = 'Modules.Inpostvonhalsky.Admin';

    private CronManager $cronManager;
    private CronJobConfigurator $jobConfigurator;
    private LogJournalRepository $journal;
    private ApiTokenManager $tokenManager;
    private TranslatorInterface $translator;

    /** @var AbstractCronJob[] */
    private array $jobs;

    private string $prefix;

    private ShopTaxCurrencyInfo $shopTaxCurrencyInfo;

    private OrderWarnings $orderWarnings;

    /**
     * @param iterable<AbstractCronJob> $jobs
     */
    public function __construct(
        CronManager $cronManager,
        CronJobConfigurator $jobConfigurator,
        LogJournalRepository $journal,
        ApiTokenManager $tokenManager,
        TranslatorInterface $translator,
        ShopTaxCurrencyInfo $shopTaxCurrencyInfo,
        OrderWarnings $orderWarnings,
        iterable $jobs = []
    ) {
        $this->shopTaxCurrencyInfo = $shopTaxCurrencyInfo;
        $this->orderWarnings = $orderWarnings;
        $this->cronManager = $cronManager;
        $this->jobConfigurator = $jobConfigurator;
        $this->journal = $journal;
        $this->tokenManager = $tokenManager;
        $this->translator = $translator;
        $this->jobs = is_array($jobs) ? $jobs : iterator_to_array($jobs);
        $this->prefix = _DB_PREFIX_;
    }

    /**
     * Full status snapshot for the panel / JSON report.
     *
     * @return array<string,mixed>
     */
    public function getStatus(): array
    {
        $sections = [
            'environment' => $this->section('Environment', 'dns', $this->environmentChecks()),
            'connection' => $this->section('InPost connection', 'link', $this->connectionChecks()),
            'catalog' => $this->section('Categories and attributes', 'account_tree', $this->catalogChecks()),
            'offers' => $this->section('Offers', 'sell', $this->offerChecks()),
            'orders' => $this->section('Orders', 'shopping_cart', $this->orderChecks()),
            'journal' => $this->section('Errors and warnings', 'report_problem', $this->journalChecks()),
        ];

        $cron = $this->cronStatus();
        $sections['cron'] = $this->section('Scheduled tasks (cron)', 'schedule', $cron['checks']);

        // Everything that needs attention, worst first
        $problems = [];
        foreach ($sections as $key => $section) {
            foreach ($section['checks'] as $check) {
                if (in_array($check['status'], [self::WARNING, self::ERROR], true)) {
                    $problems[] = $check + [
                        'section' => $section['title'],
                        'section_key' => $key,
                        'area' => self::SECTION_AREA[$key] ?? 'sync',
                    ];
                }
            }
        }
        usort($problems, fn ($a, $b) => self::SEVERITY[$b['status']] <=> self::SEVERITY[$a['status']]);

        $overall = self::OK;
        foreach ($sections as $section) {
            $overall = $this->worst($overall, $section['status']);
        }

        $kpis = $this->kpis();

        return [
            'generated_at' => date('Y-m-d H:i:s'),
            'overall' => $overall,
            'problems' => $problems,
            'areas' => $this->areas($sections, $kpis, $cron['last_activity']),
            'kpis' => $kpis,
            'sections' => $sections,
            'cron_jobs' => $cron['jobs'],
            'offer_status_breakdown' => $this->offerStatusBreakdown(),
            'offers_attention' => $this->offersNeedingAttention(),
            'offer_events_by_type' => $this->offerEventsByType(),
            'order_status_breakdown' => $this->orderStatusBreakdown(),
            'journal_top' => $this->journal->topProblems(new \DateTimeImmutable('-7 days'), 10),
            'journal_recent' => $this->journal->recent(20),
        ];
    }

    // =========================================================================
    // SECTIONS
    // =========================================================================

    /**
     * @return array<int,array<string,mixed>>
     */
    private function environmentChecks(): array
    {
        $checks = [];

        // Module version: files vs what PrestaShop has registered as installed
        $module = Module::getInstanceByName('inpostvonhalsky');
        $fileVersion = $module ? (string) $module->version : '?';
        $dbVersion = (string) $this->value(
            "SELECT version FROM {$this->prefix}module WHERE name = 'inpostvonhalsky'"
        );
        $upgradePending = $dbVersion !== '' && version_compare($dbVersion, $fileVersion, '<');
        $checks[] = $this->check(
            'Module version',
            $upgradePending ? $fileVersion . ' (' . $this->t('installed: %v%', ['%v%' => $dbVersion]) . ')' : $fileVersion,
            $upgradePending ? self::WARNING : self::OK,
            $upgradePending ? $this->t('Module files are newer than the installed version. Run the module upgrade in Modules > Module Manager so upgrade scripts (database changes) are applied.') : null
        );

        // PHP version
        $phpBranch = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        if (version_compare(PHP_VERSION, self::PHP_MIN_VERSION, '<')) {
            $phpStatus = self::ERROR;
            $phpHint = $this->t('The module requires PHP %min% or newer.', ['%min%' => self::PHP_MIN_VERSION]);
        } elseif (!in_array($phpBranch, self::PHP_TESTED_BRANCHES, true)) {
            $phpStatus = self::WARNING;
            $phpHint = $this->t('PHP %branch% has not been tested with this module (tested: %tested%).', [
                '%branch%' => $phpBranch,
                '%tested%' => implode(', ', self::PHP_TESTED_BRANCHES),
            ]);
        } else {
            $phpStatus = self::OK;
            $phpHint = null;
        }
        $checks[] = $this->check('PHP version', PHP_VERSION, $phpStatus, $phpHint);

        // PrestaShop version vs module compliancy
        $compliancy = $module ? (array) $module->ps_versions_compliancy : [];
        $min = $compliancy['min'] ?? '8.0.0';
        $max = $compliancy['max'] ?? '8.99.99';
        $psOk = version_compare(_PS_VERSION_, $min, '>=') && version_compare(_PS_VERSION_, $max, '<=');
        $checks[] = $this->check(
            'PrestaShop version',
            _PS_VERSION_,
            $psOk ? self::OK : self::ERROR,
            $psOk ? null : $this->t('Supported PrestaShop versions: %min% - %max%.', ['%min%' => $min, '%max%' => $max])
        );

        // PHP extensions
        $missing = array_values(array_filter(self::REQUIRED_EXTENSIONS, fn ($ext) => !extension_loaded($ext)));
        $checks[] = $this->check(
            'PHP extensions',
            $missing ? $this->t('missing: %list%', ['%list%' => implode(', ', $missing)]) : implode(', ', self::REQUIRED_EXTENSIONS),
            $missing ? self::ERROR : self::OK,
            $missing ? $this->t('Install the missing PHP extensions on the server.') : null
        );

        // Memory limit
        $memoryLimit = (string) ini_get('memory_limit');
        $memoryBytes = $this->toBytes($memoryLimit);
        $lowMemory = $memoryBytes > 0 && $memoryBytes < self::RECOMMENDED_MEMORY_LIMIT;
        $checks[] = $this->check(
            'PHP memory limit',
            $memoryLimit,
            $lowMemory ? self::WARNING : self::OK,
            $lowMemory ? $this->t('At least 256M is recommended - category and attribute sync processes thousands of records.') : null
        );

        // InPost SDK (Composer dependencies)
        $sdk = class_exists('InpostApi\Configuration');
        $checks[] = $this->check(
            'InPost API SDK',
            $sdk ? $this->t('installed') : $this->t('missing'),
            $sdk ? self::OK : self::ERROR,
            $sdk ? null : $this->t('The module vendor directory is incomplete - reinstall the module package.')
        );

        // SSL - InPost only accepts https image URLs
        $ssl = (bool) Configuration::get('PS_SSL_ENABLED');
        $checks[] = $this->check(
            'SSL (https)',
            $ssl ? $this->t('enabled') : $this->t('disabled'),
            $ssl ? self::OK : self::ERROR,
            $ssl ? null : $this->t('InPost accepts only https image URLs - enable SSL in Shop Parameters > General.')
        );

        // PLN: InPost prices offers and orders in PLN
        $plnActive = $this->shopTaxCurrencyInfo->isInpostCurrencyActive();
        $checks[] = $this->check(
            'PLN currency',
            $plnActive ? $this->t('active') : $this->t('missing or disabled'),
            $plnActive ? self::OK : self::ERROR,
            $plnActive ? null : $this->t('InPost prices offers and orders in PLN. Without an active PLN currency, orders are created in the default shop currency (%currency%) and the amounts are taken over without conversion. Add or enable PLN in International > Localization > Currencies.', [
                '%currency%' => (string) (new \Currency((int) Configuration::get('PS_CURRENCY_DEFAULT')))->iso_code,
            ])
        );

        // VAT: offers declare the product tax rate, orders are created with it
        if (!$this->shopTaxCurrencyInfo->areTaxesEnabled()) {
            $checks[] = $this->check(
                'VAT tax rules',
                $this->t('taxes disabled'),
                self::ERROR,
                $this->t('Taxes are disabled - offers are sent to InPost with 0% VAT and orders are created without VAT. Enable taxes in International > Taxes.')
            );
        } else {
            $taxRules = $this->shopTaxCurrencyInfo->countTaxRulesWithRate();
            $checks[] = $this->check(
                'VAT tax rules',
                $taxRules > 0
                    ? $this->t('%count% tax rule(s) with VAT for the default country', ['%count%' => $taxRules])
                    : $this->t('no tax rule with VAT for the default country'),
                $taxRules > 0 ? self::OK : self::ERROR,
                $taxRules > 0 ? null : $this->t('Without tax rules every offer is sent to InPost with 0% VAT and orders are created without VAT. Add tax rules in International > Taxes > Tax Rules and assign them to products.')
            );
        }

        // Test/diagnostic switches that must not stay on in production
        if (Configuration::get(ConfigurationService::DEBUG_API_LOGGING)) {
            $checks[] = $this->check(
                'API debug logging',
                $this->t('enabled'),
                self::INFO,
                $this->t('Full API payloads are logged. Disable when no longer debugging.')
            );
        }

        $apiUrl = (string) Configuration::get(ConfigurationService::API_URL);
        $isStage = stripos($apiUrl, 'stage') !== false || stripos($apiUrl, 'sandbox') !== false;
        $checks[] = $this->check(
            'API environment',
            $apiUrl !== '' ? ($isStage ? $this->t('Test (stage)') : $this->t('Production')) : $this->t('not configured'),
            $apiUrl === '' ? self::ERROR : ($isStage ? self::INFO : self::OK),
            $apiUrl !== '' ? $apiUrl : null
        );

        return $checks;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function connectionChecks(): array
    {
        $checks = [];

        $clientId = (string) Configuration::get(ConfigurationService::CLIENT_ID);
        $clientSecret = (string) Configuration::get(ConfigurationService::CLIENT_SECRET);
        $credentials = $clientId !== '' && $clientSecret !== '';
        $checks[] = $this->check(
            'API credentials',
            $credentials ? $this->t('configured') : $this->t('missing'),
            $credentials ? self::OK : self::ERROR,
            $credentials ? null : $this->t('Enter Client ID and Client Secret in the module configuration.')
        );

        $refreshToken = $this->tokenManager->getRefreshToken();
        $checks[] = $this->check(
            'Authorization (OAuth2)',
            $refreshToken ? $this->t('connected') : $this->t('not connected'),
            $refreshToken ? self::OK : self::ERROR,
            $refreshToken ? null : $this->t('Connect the shop to InPost in the module configuration.')
        );

        if ($refreshToken) {
            $expiresAt = $this->tokenManager->getAccessTokenExpiration();
            $checks[] = $this->check(
                'Access token',
                $expiresAt && $expiresAt > time()
                    ? $this->t('valid until %date%', ['%date%' => date('Y-m-d H:i', $expiresAt)])
                    : $this->t('expired - refreshed automatically on the next API call'),
                self::OK
            );
        }

        $orgId = (string) Configuration::get(ConfigurationService::ORGANIZATION_ID);
        $orgName = $orgId !== ''
            ? (string) $this->value(
                "SELECT name FROM {$this->prefix}inpost_organization WHERE organization_id = '" . pSQL($orgId) . "'"
            )
            : '';
        $checks[] = $this->check(
            'Organization',
            $orgId !== '' ? ($orgName !== '' ? $orgName . ' (' . $orgId . ')' : $orgId) : $this->t('not selected'),
            $orgId !== '' ? self::OK : self::ERROR,
            $orgId !== '' ? null : $this->t('Select the InPost organization in the module configuration.')
        );

        return $checks;
    }

    /**
     * Cron jobs table + checks (system cron heartbeat, per-job failures/overdue).
     *
     * @return array{checks:array<int,array<string,mixed>>,jobs:array<int,array<string,mixed>>,last_activity:int|null}
     */
    private function cronStatus(): array
    {
        $checks = [];
        $jobs = [];
        $lastActivity = null;
        $anyEnabled = false;

        foreach ($this->jobs as $job) {
            $this->jobConfigurator->configure($job);
            $name = $job->getName();
            $enabled = $job->isEnabled();
            $interval = $job->getInterval();
            $lastRun = $this->cronManager->getLastRunTimestamp($name);
            $lastResult = $this->cronManager->getLastResult($name);
            $running = $this->cronManager->isJobRunning($name);
            $finishedAt = isset($lastResult['finished_at']) ? (int) $lastResult['finished_at'] : null;

            $anyEnabled = $anyEnabled || $enabled;
            $lastActivity = max($lastActivity ?? 0, $lastRun ?? 0, $finishedAt ?? 0) ?: $lastActivity;

            $failed = $lastResult !== null && empty($lastResult['success']);
            $overdue = $enabled && $lastRun !== null && $interval > 0
                && (time() - $lastRun) > (2 * $interval + 900);

            if (!$enabled) {
                $status = self::INFO;
            } elseif ($failed) {
                $status = self::ERROR;
            } elseif ($overdue || $lastRun === null) {
                $status = self::WARNING;
            } else {
                $status = self::OK;
            }

            $jobs[] = [
                'name' => $name,
                'label' => $this->jobLabel($name),
                'enabled' => $enabled,
                'interval' => $this->formatInterval($interval),
                'last_run' => $lastRun ? date('Y-m-d H:i:s', $lastRun) : null,
                'last_run_ago' => $lastRun ? $this->ago($lastRun) : null,
                'next_run' => $enabled && $lastRun ? date('Y-m-d H:i', $lastRun + $interval) : null,
                'running' => $running,
                'duration' => isset($lastResult['duration']) ? round((float) $lastResult['duration'], 1) : null,
                'message' => $lastResult['error'] ?? $lastResult['message'] ?? null,
                'status' => $status,
            ];

            if ($enabled && $failed) {
                $checks[] = $this->check(
                    $this->jobLabel($name),
                    $this->t('last run failed'),
                    self::ERROR,
                    (string) ($lastResult['error'] ?? '')
                );
            } elseif ($overdue) {
                $checks[] = $this->check(
                    $this->jobLabel($name),
                    $this->t('overdue - last run %ago%', ['%ago%' => $this->ago((int) $lastRun)]),
                    self::WARNING,
                    $this->t('The job did not run on schedule. Check the scheduled tasks log.')
                );
            }
        }

        if ($anyEnabled) {
            if ($lastActivity === null) {
                $heartbeat = $this->check(
                    'System cron',
                    $this->t('no activity recorded'),
                    self::ERROR,
                    $this->t('Add a system cron entry running "bin/console inpost:cron:run" every minute.')
                );
            } elseif (time() - $lastActivity > self::CRON_HEARTBEAT_MAX_AGE) {
                $heartbeat = $this->check(
                    'System cron',
                    $this->t('last activity %ago%', ['%ago%' => $this->ago($lastActivity)]),
                    self::ERROR,
                    $this->t('Scheduled tasks stopped running. Check the system crontab ("bin/console inpost:cron:run" every minute).')
                );
            } else {
                $heartbeat = $this->check(
                    'System cron',
                    $this->t('active - last activity %ago%', ['%ago%' => $this->ago($lastActivity)]),
                    self::OK
                );
            }
        } else {
            $heartbeat = $this->check(
                'System cron',
                $this->t('all jobs disabled'),
                self::WARNING,
                $this->t('Offers, orders and events are not synchronized automatically.')
            );
        }
        array_unshift($checks, $heartbeat);

        return ['checks' => $checks, 'jobs' => $jobs, 'last_activity' => $lastActivity];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function catalogChecks(): array
    {
        $checks = [];

        $cat = $this->row(
            "SELECT COUNT(*) AS total, COALESCE(SUM(is_active), 0) AS active,
                    COALESCE(SUM(is_active = 1 AND is_leaf = 1), 0) AS leaves, MAX(synced_at) AS last_sync
             FROM {$this->prefix}inpost_category_mapping"
        );
        $total = (int) ($cat['total'] ?? 0);
        $lastSync = $cat['last_sync'] ?? null;
        $syncAge = $lastSync ? time() - (int) strtotime((string) $lastSync) : null;

        if ($total === 0) {
            $status = self::ERROR;
            $hint = $this->t('Categories were never synchronized. Run the category sync (Category Mapping page or the category_sync job).');
        } elseif ($syncAge !== null && $syncAge > 3 * 86400) {
            $status = self::WARNING;
            $hint = $this->t('Last category sync is older than 3 days - the InPost category tree may be out of date.');
        } else {
            $status = self::OK;
            $hint = null;
        }
        $checks[] = $this->check(
            'InPost categories',
            $this->t('%active% active of %total% (%leaves% leaf)', [
                '%active%' => (int) ($cat['active'] ?? 0),
                '%total%' => $total,
                '%leaves%' => (int) ($cat['leaves'] ?? 0),
            ]),
            $status,
            $hint
        );
        $checks[] = $this->check(
            'Last category sync',
            $lastSync ? $lastSync . ' (' . $this->ago((int) strtotime((string) $lastSync)) . ')' : $this->t('never'),
            $lastSync ? self::OK : self::ERROR
        );

        $attr = $this->row(
            "SELECT COUNT(*) AS total, COALESCE(SUM(is_active), 0) AS active, MAX(synced_at) AS last_sync
             FROM {$this->prefix}inpost_attribute"
        );
        $links = (int) $this->value("SELECT COUNT(*) FROM {$this->prefix}inpost_category_attribute");
        $checks[] = $this->check(
            'InPost attributes',
            $this->t('%active% active of %total%, %links% category links', [
                '%active%' => (int) ($attr['active'] ?? 0),
                '%total%' => (int) ($attr['total'] ?? 0),
                '%links%' => $links,
            ]),
            (int) ($attr['total'] ?? 0) > 0 ? self::OK : ($total > 0 ? self::ERROR : self::WARNING),
            (int) ($attr['total'] ?? 0) > 0 ? null : $this->t('Attributes were never synchronized - offers cannot be validated against category requirements.')
        );

        $leavesWithoutAttributes = (int) $this->value(
            "SELECT COUNT(*) FROM {$this->prefix}inpost_category_mapping m
             WHERE m.is_active = 1 AND m.is_leaf = 1 AND NOT EXISTS (
                 SELECT 1 FROM {$this->prefix}inpost_category_attribute ca
                 WHERE ca.inpost_category_mapping_id = m.entity_id)"
        );
        if ($total > 0) {
            $leaves = max(1, (int) ($cat['leaves'] ?? 0));
            $share = $leavesWithoutAttributes / $leaves;
            $checks[] = $this->check(
                'Leaf categories without attributes',
                (string) $leavesWithoutAttributes,
                $share > 0.5 ? self::WARNING : self::INFO,
                $share > 0.5 ? $this->t('More than half of the leaf categories have no attributes - the attribute sync probably did not complete.') : null
            );
        }

        $dictionary = (int) $this->value("SELECT COUNT(*) FROM {$this->prefix}inpost_attribute_dictionary");
        $checks[] = $this->check('Attribute dictionary values', (string) $dictionary, self::INFO);

        $mappedPs = (int) $this->value(
            "SELECT COUNT(DISTINCT prestashop_category_id) FROM {$this->prefix}inpost_category_link"
        );
        $psCategories = (int) $this->value(
            "SELECT COUNT(*) FROM {$this->prefix}category WHERE active = 1 AND is_root_category = 0 AND id_parent > 0"
        );
        $checks[] = $this->check(
            'Mapped shop categories',
            $this->t('%mapped% of %total%', ['%mapped%' => $mappedPs, '%total%' => $psCategories]),
            $mappedPs > 0 ? self::OK : self::WARNING,
            $mappedPs > 0 ? null : $this->t('No shop category is mapped to an InPost category - map them on the Category Mapping page.')
        );

        return $checks;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function offerChecks(): array
    {
        $checks = [];
        $t = "{$this->prefix}inpost_offer";

        $o = $this->row(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(is_draft = 1), 0) AS local_drafts,
                    COALESCE(SUM(status = 'PUBLISHED'), 0) AS published,
                    COALESCE(SUM(require_api_sync = 1 AND is_draft = 0), 0) AS awaiting_sync,
                    COALESCE(SUM(command_status = 'PENDING'), 0) AS commands_pending,
                    COALESCE(SUM(command_status = 'PENDING' AND updated_at < '" . pSQL(date('Y-m-d H:i:s', time() - self::STUCK_COMMAND_AGE)) . "'), 0) AS commands_stuck,
                    COALESCE(SUM(command_status = 'FAILURE'), 0) AS commands_failed,
                    COALESCE(SUM(inpost_validation_status = 'INVALID'), 0) AS invalid,
                    COALESCE(SUM(status = 'REJECTED'), 0) AS rejected,
                    MAX(synced_at) AS last_sync
             FROM {$t}"
        ) + array_fill_keys(['total', 'local_drafts', 'published', 'awaiting_sync', 'commands_pending',
            'commands_stuck', 'commands_failed', 'invalid', 'rejected', 'last_sync'], null);

        $checks[] = $this->check(
            'Offers total',
            $this->t('%total% (%published% published, %drafts% local drafts)', [
                '%total%' => (int) $o['total'],
                '%published%' => (int) $o['published'],
                '%drafts%' => (int) $o['local_drafts'],
            ]),
            self::INFO
        );

        // Offers whose product has no tax rule are declared to InPost with 0% VAT
        $taxesEnabled = $this->shopTaxCurrencyInfo->areTaxesEnabled();
        $noVat = $this->rows(
            "SELECT o.offer_id FROM {$t} o
             JOIN {$this->prefix}product p ON p.id_product = o.prestashop_offer_id"
            . ($taxesEnabled ? ' WHERE p.id_tax_rules_group = 0' : '')
            . ' ORDER BY o.offer_id'
        );
        if ($noVat) {
            $ids = array_map(fn ($row) => '#' . $row['offer_id'], array_slice($noVat, 0, 5));
            $checks[] = $this->check(
                'Offers with 0% VAT',
                implode(', ', $ids) . (count($noVat) > 5 ? ' ' . $this->t('(+%n% more)', ['%n%' => count($noVat) - 5]) : ''),
                self::WARNING,
                $taxesEnabled
                    ? $this->t('The products of these offers have no tax rule, so InPost receives 0% VAT and their orders are created without VAT. Assign a tax rule to the products (Catalog > Products > Pricing) and send the offers again.')
                    : $this->t('Taxes are disabled in the shop, so every offer is sent with 0% VAT.')
            );
        }

        if (Configuration::get(ConfigurationService::IMAGE_TEST_MODE_ENABLED)) {
            $checks[] = $this->check(
                'Image test mode',
                $this->t('enabled'),
                self::WARNING,
                $this->t('Every offer image is sent to InPost as the test URL instead of the product photo. Disable it in the configuration before going live.')
            );
            // Fixed in the module configuration, not on the offers page
            $checks[count($checks) - 1]['fix'] = 'configuration';
        }

        $awaiting = (int) $o['awaiting_sync'];
        $checks[] = $this->check(
            'Waiting to be sent',
            (string) $awaiting,
            self::INFO,
            $awaiting > 0 ? $this->t('Sent by the offer_sync job on its next run.') : null
        );

        $checks[] = $this->check(
            'Create commands in progress',
            (int) $o['commands_stuck'] > 0
                ? $this->t('%pending% (%stuck% older than 1 h)', ['%pending%' => (int) $o['commands_pending'], '%stuck%' => (int) $o['commands_stuck']])
                : (string) (int) $o['commands_pending'],
            (int) $o['commands_stuck'] > 0 ? self::WARNING : self::OK,
            (int) $o['commands_stuck'] > 0 ? $this->t('InPost has not confirmed these offers for over an hour - check that the pending_command_sync job runs.') : null
        );

        $failed = (int) $o['commands_failed'];
        $failedRows = $failed > 0 ? $this->offerRefs("o.command_status = 'FAILURE'") : [];
        $checks[] = $this->check(
            'Rejected on creation',
            $failed > 0 ? $this->formatOfferRefs($failedRows, $failed) : '0',
            $failed > 0 ? self::ERROR : self::OK,
            $failed > 0 ? $this->t('InPost refused to create the offer: %reason%. Fix it and send again, or delete it if the product already has another offer.', [
                '%reason%' => $this->shortReason((string) ($failedRows[0]['command_error'] ?? '')),
            ]) : null
        );
        if ($failed === 1 && isset($failedRows[0]['offer_id'])) {
            // Single offer - the panel links straight to its edit form
            $checks[count($checks) - 1]['link'] = [
                'route' => 'inpost_offers_edit',
                'params' => ['id' => (int) $failedRows[0]['offer_id']],
            ];
        }

        $invalid = (int) $o['invalid'] + (int) $o['rejected'];
        $checks[] = $this->check(
            'Failing InPost validation',
            $invalid > 0
                ? $this->formatOfferRefs($this->offerRefs("o.inpost_validation_status = 'INVALID' OR o.status = 'REJECTED'"), $invalid)
                : '0',
            $invalid > 0 ? self::WARNING : self::OK,
            $invalid > 0 ? $this->t('The offers exist in InPost but will not be published until the data is fixed (open the offer to see validation errors).') : null
        );

        $checks[] = $this->check(
            'Last successful send',
            $o['last_sync'] ? $o['last_sync'] . ' (' . $this->ago((int) strtotime((string) $o['last_sync'])) . ')' : $this->t('never'),
            self::INFO
        );

        // Offer events (feedback from InPost)
        $e = $this->row(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(occured_at >= '" . pSQL(date('Y-m-d H:i:s', time() - 86400)) . "'), 0) AS last_day,
                    MAX(occured_at) AS last_event
             FROM {$this->prefix}inpost_offer_event"
        ) + ['total' => 0, 'last_day' => 0, 'last_event' => null];
        $checks[] = $this->check(
            'Offer events received',
            $this->t('%total% total, %day% in the last 24 h', ['%total%' => (int) $e['total'], '%day%' => (int) $e['last_day']]),
            self::INFO,
            $e['last_event'] ? $this->t('Last event: %date%', ['%date%' => $e['last_event']]) : null
        );

        $orgId = (string) Configuration::get(ConfigurationService::ORGANIZATION_ID);
        if ($orgId !== '') {
            $lastEventSync = $this->value(
                "SELECT last_offer_event_sync_at FROM {$this->prefix}inpost_organization
                 WHERE organization_id = '" . pSQL($orgId) . "'"
            );
            $checks[] = $this->check(
                'Last offer event sync',
                $lastEventSync ? $lastEventSync . ' (' . $this->ago((int) strtotime((string) $lastEventSync)) . ')' : $this->t('never'),
                $lastEventSync ? self::OK : ((int) $o['total'] > 0 ? self::WARNING : self::INFO),
                $lastEventSync ? null : $this->t('Offer statuses from InPost are not being pulled - check the offer_event_sync job.')
            );
        }

        return $checks;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function orderChecks(): array
    {
        $checks = [];
        $now = time();

        $o = $this->row(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(date_add >= '" . pSQL(date('Y-m-d H:i:s', $now - 86400)) . "'), 0) AS last_day,
                    COALESCE(SUM(date_add >= '" . pSQL(date('Y-m-d H:i:s', $now - 7 * 86400)) . "'), 0) AS last_week,
                    MAX(date_add) AS last_order
             FROM {$this->prefix}inpost_order"
        ) + ['total' => 0, 'last_day' => 0, 'last_week' => 0, 'last_order' => null];

        $checks[] = $this->check(
            'InPost orders',
            $this->t('%total% total, %week% in the last 7 days, %day% in the last 24 h', [
                '%total%' => (int) $o['total'],
                '%week%' => (int) $o['last_week'],
                '%day%' => (int) $o['last_day'],
            ]),
            self::INFO
        );
        $checks[] = $this->check(
            'Last order',
            $o['last_order'] ? $o['last_order'] . ' (' . $this->ago((int) strtotime((string) $o['last_order'])) . ')' : $this->t('none yet'),
            self::INFO
        );

        // When the order import cron job last ran and what it reported
        $lastSync = $this->cronManager->getLastRunTimestamp('order_sync');
        $lastSyncResult = $this->cronManager->getLastResult('order_sync');
        $lastSyncFailed = $lastSyncResult !== null && empty($lastSyncResult['success']);
        $checks[] = $this->check(
            'Last sync',
            $lastSync
                ? date('Y-m-d H:i:s', $lastSync) . ' (' . $this->ago($lastSync) . ') - '
                    . ($lastSyncFailed ? (string) ($lastSyncResult['error'] ?? '') : (string) ($lastSyncResult['message'] ?? ''))
                : $this->t('never'),
            $lastSync === null ? self::WARNING : ($lastSyncFailed ? self::ERROR : self::OK),
            $lastSyncFailed ? (string) ($lastSyncResult['error'] ?? '') : null
        );

        // Shop orders created with a non-PLN currency, without VAT or with unmatched products (icons in the orders list)
        $ordersWithWarnings = $this->orderWarnings->countOrdersWithWarnings();
        $checks[] = $this->check(
            'Orders with warnings',
            (string) $ordersWithWarnings,
            $ordersWithWarnings > 0 ? self::WARNING : self::OK,
            $ordersWithWarnings > 0
                ? $this->t('Some shop orders were created in a currency other than PLN, without VAT or with products not found in the shop - see the warning icons in InPost von Halsky > Orders.')
                : null
        );

        // Integrity: every order to be fulfilled must have a PrestaShop order. Orders
        // still awaiting acceptance or refused/rejected/cancelled never get one (by design).
        $missingWhere = "o.id_order IS NULL
               AND LOWER(COALESCE(io.status, '')) NOT IN ('" . implode("','", self::ORDER_STATUSES_WITHOUT_PS_ORDER) . "')";
        $withoutPsOrder = (int) $this->value(
            "SELECT COUNT(*) FROM {$this->prefix}inpost_order io
             LEFT JOIN {$this->prefix}orders o ON o.id_order = io.id_order
             WHERE {$missingWhere}"
        );
        $missingRows = $withoutPsOrder > 0 ? $this->rows(
            "SELECT io.id_inpost_order, io.inpost_order_id, io.date_add, io.total
             FROM {$this->prefix}inpost_order io
             LEFT JOIN {$this->prefix}orders o ON o.id_order = io.id_order
             WHERE {$missingWhere}
             ORDER BY io.date_add DESC LIMIT 3"
        ) : [];
        $refs = array_map(
            fn ($row) => $row['inpost_order_id'] . ' (' . date('d.m.Y H:i', (int) strtotime((string) $row['date_add']))
                . ', ' . number_format((float) $row['total'], 2, ',', ' ') . ')',
            $missingRows
        );
        $reason = $missingRows ? $this->lastOrderCreationError((string) $missingRows[0]['inpost_order_id']) : null;

        $checks[] = $this->check(
            'Orders not created in the shop',
            $withoutPsOrder > 0
                ? implode(', ', $refs) . ($withoutPsOrder > count($refs) ? ' ' . $this->t('(+%n% more)', ['%n%' => $withoutPsOrder - count($refs)]) : '')
                : '0',
            $withoutPsOrder > 0 ? self::ERROR : self::OK,
            $withoutPsOrder > 0
                ? ($reason !== null
                    ? $this->t('Accepted in InPost, but creating the shop order failed: %reason%', ['%reason%' => $reason])
                    : $this->t('Accepted in InPost, but creating the shop order failed - see Advanced Parameters > Logs, entries "[InPost Order Creator]".'))
                : null
        );
        if ($withoutPsOrder === 1) {
            $checks[count($checks) - 1]['link'] = [
                'route' => 'admin_inpost_orders_view',
                'params' => ['inpostOrderId' => (int) $missingRows[0]['id_inpost_order']],
            ];
        }

        $brokenRows = $this->rows(
            "SELECT o.id_order, o.reference, o.date_add, o.id_address_invoice, o.id_address_delivery
             FROM {$this->prefix}inpost_order io
             JOIN {$this->prefix}orders o ON o.id_order = io.id_order
             WHERE o.id_address_invoice = 0 OR o.id_address_delivery = 0
             ORDER BY o.id_order"
        );
        if ($brokenRows) {
            $refs = array_map(
                fn ($row) => '#' . $row['id_order'] . ' ' . $row['reference'],
                array_slice($brokenRows, 0, 5)
            );
            $dates = array_unique(array_map(fn ($row) => date('d.m.Y', (int) strtotime((string) $row['date_add'])), $brokenRows));
            $checks[] = $this->check(
                'Orders without an address',
                implode(', ', $refs) . (count($brokenRows) > 5 ? ' ' . $this->t('(+%n% more)', ['%n%' => count($brokenRows) - 5]) : ''),
                self::WARNING,
                $this->t('Created on %dates% without an invoice or delivery address, so PrestaShop cannot open their order page. New orders are not affected; these can be repaired by assigning an address in the database.', [
                    '%dates%' => implode(', ', $dates),
                ])
            );
        }

        return $checks;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function journalChecks(): array
    {
        if (!$this->journal->isAvailable()) {
            return [$this->check(
                'Error journal',
                $this->t('not installed'),
                self::WARNING,
                $this->t('Run the module upgrade to create the journal table.')
            )];
        }

        $day = $this->journal->countByLevelSince(new \DateTimeImmutable('-24 hours'));
        $week = $this->journal->countByLevelSince(new \DateTimeImmutable('-7 days'));

        $checks = [];
        [$errorText, $errorHint] = $this->journalSummary($day['error'], false);
        $checks[] = $this->check(
            'Errors (24 h)',
            $errorText,
            $day['error'] > 0 ? self::ERROR : self::OK,
            $errorHint
        );
        [$warningText, $warningHint] = $this->journalSummary($day['warning'], true);
        $checks[] = $this->check(
            'Warnings (24 h)',
            $warningText,
            $day['warning'] > 0 ? self::WARNING : self::OK,
            $warningHint
        );
        $checks[] = $this->check(
            'Errors / warnings (7 days)',
            $week['error'] . ' / ' . $week['warning'],
            self::INFO,
            $this->t('Journal keeps %days% days; oldest entry: %date%', [
                '%days%' => LogJournalRepository::RETENTION_DAYS,
                '%date%' => $this->journal->oldestEntryAt() ?? '-',
            ])
        );

        return $checks;
    }

    /**
     * "2 - Failed to send offer (offer #11)" + the underlying error of the latest
     * occurrence, so the problem is understandable without opening the log.
     *
     * @return array{0:string,1:string|null}
     */
    private function journalSummary(int $count, bool $warnings): array
    {
        if ($count === 0) {
            return ['0', null];
        }

        foreach ($this->journal->recent(100) as $entry) {
            if (($entry['level'] === 'warning') !== $warnings) {
                continue;
            }

            $context = json_decode((string) $entry['context'], true) ?: [];
            $subject = [];
            foreach (['offer_id' => 'offer #%s', 'order_id' => 'order %s', 'category_id' => 'category %s'] as $key => $format) {
                if (!empty($context[$key]) && is_scalar($context[$key])) {
                    $subject[] = sprintf($format, $context[$key]);
                }
            }

            $text = $count . ' - ' . $entry['message'] . ($subject ? ' (' . implode(', ', $subject) . ')' : '');
            $detail = $context['error'] ?? $context['error_message'] ?? null;
            $hint = is_string($detail) && $detail !== ''
                ? (mb_strlen($detail) > 220 ? mb_substr($detail, 0, 220) . '…' : $detail)
                : null;

            return [$text, $hint];
        }

        return [(string) $count, null];
    }

    /**
     * Last recorded reason why the shop order for this InPost order could not be
     * created (PrestaShop log written by PrestaShopOrderCreator).
     */
    private function lastOrderCreationError(string $inpostOrderId): ?string
    {
        // Recorded by the sync itself - no need to search the (possibly huge) ps_log
        $recorded = OrderSyncService::getLastCreateError();
        if ($recorded !== null) {
            $message = $recorded['message'];

            return mb_strlen($message) > 160 ? mb_substr($message, 0, 160) . '…' : $message;
        }

        // Older module versions: only the latest log entries (a LIKE over millions of
        // ps_log rows takes the whole shop down)
        $recentLogs = "id_log > (SELECT MAX(id_log) - 20000 FROM {$this->prefix}log)";
        $message = $this->value(
            "SELECT message FROM {$this->prefix}log
             WHERE {$recentLogs}
               AND message LIKE '[InPost Order Creator]%'
               AND message LIKE '%" . pSQL(addcslashes($inpostOrderId, '%_')) . "%'
               AND severity >= 3
             ORDER BY id_log DESC"
        );
        if (!$message) {
            // Older entries lack the order id: take the first error logged right after
            // the "createOrUpdateOrder called for <id>" entry of the latest attempt
            $attemptId = (int) $this->value(
                "SELECT id_log FROM {$this->prefix}log
                 WHERE {$recentLogs} AND message = '" . pSQL('[InPost Order Creator] createOrUpdateOrder called for InPost order: ' . $inpostOrderId) . "'
                 ORDER BY id_log DESC"
            );
            if ($attemptId > 0) {
                $message = $this->value(
                    "SELECT message FROM {$this->prefix}log
                     WHERE id_log > {$attemptId} AND id_log <= " . ($attemptId + 5) . "
                       AND severity >= 3 AND message LIKE '[InPost Order Creator]%'
                     ORDER BY id_log ASC"
                );
            }
        }
        if (!$message) {
            return null;
        }

        $message = trim((string) preg_replace('/^\[InPost Order Creator\][^:]*:\s*/', '', (string) $message));
        $message = (string) preg_replace('/\s*\(InPost order [^)]*\)$/', '', $message);

        return mb_strlen($message) > 160 ? mb_substr($message, 0, 160) . '…' : $message;
    }

    /**
     * Offers matching the condition, newest first (for naming them in a check).
     *
     * @return array<int,array<string,mixed>>
     */
    private function offerRefs(string $where, int $limit = 3): array
    {
        return $this->rows(
            "SELECT o.offer_id, p.name, o.command_error
             FROM {$this->prefix}inpost_offer o
             LEFT JOIN {$this->prefix}inpost_offer_product p ON p.offer_id = o.offer_id
             WHERE {$where}
             ORDER BY o.updated_at DESC
             LIMIT " . (int) $limit
        );
    }

    /**
     * "#11 T-shirt, #12 Notebook (+2 more)"
     *
     * @param array<int,array<string,mixed>> $rows
     */
    private function formatOfferRefs(array $rows, int $total): string
    {
        $refs = array_map(
            fn ($row) => '#' . $row['offer_id'] . ($row['name'] ? ' ' . $row['name'] : ''),
            $rows
        );
        $text = implode(', ', $refs);
        if ($total > count($rows)) {
            $text .= ' ' . $this->t('(+%n% more)', ['%n%' => $total - count($rows)]);
        }

        return $text;
    }

    /**
     * First API error, without the field prefix, trimmed for one line.
     */
    private function shortReason(string $error): string
    {
        $first = trim(explode(';', $error)[0]);
        $first = (string) preg_replace('/^[a-zA-Z_.\[\]0-9]+:\s*/', '', $first);
        if ($first === '') {
            return $this->t('no reason recorded');
        }

        return mb_strlen($first) > 140 ? mb_substr($first, 0, 140) . '…' : $first;
    }

    // =========================================================================
    // AREA SUMMARY (panel tiles)
    // =========================================================================

    /**
     * Four areas an operator thinks in - each with one headline figure and a status
     * that rolls up the underlying sections. Details stay in the sections.
     *
     * @param array<string,array<string,mixed>> $sections
     * @param array<string,int> $kpis
     * @return array<string,array<string,mixed>>
     */
    private function areas(array $sections, array $kpis, ?int $lastCronActivity): array
    {
        $orgName = '';
        foreach ($sections['connection']['checks'] as $check) {
            if ($check['label'] === $this->t('Organization') && $check['status'] === self::OK) {
                $orgName = preg_replace('/\s*\([0-9a-f-]{36}\)$/i', '', $check['value']);
            }
        }

        $lastOrder = $this->value("SELECT MAX(date_add) FROM {$this->prefix}inpost_order");

        return [
            'connection' => $this->area(
                'Setup',
                'link',
                [$sections['connection'], $sections['environment']],
                $orgName !== '' ? $orgName : $this->t('not connected'),
                $this->t('InPost organization')
            ),
            'sync' => $this->area(
                'Synchronization',
                'sync',
                [$sections['cron'], $sections['catalog'], $sections['journal']],
                $lastCronActivity ? $this->ago($lastCronActivity) : $this->t('never'),
                $this->t('last automatic sync')
            ),
            'offers' => $this->area(
                'Offers',
                'sell',
                [$sections['offers']],
                (string) $kpis['offers_published'],
                $this->t('published of %total%', ['%total%' => $kpis['offers_total']])
            ),
            'orders' => $this->area(
                'Orders',
                'shopping_cart',
                [$sections['orders']],
                (string) $kpis['orders_week'],
                $lastOrder
                    ? $this->t('in 7 days - last %ago%', ['%ago%' => $this->ago((int) strtotime((string) $lastOrder))])
                    : $this->t('in 7 days')
            ),
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $sections
     * @return array<string,mixed>
     */
    private function area(string $title, string $icon, array $sections, string $headline, string $caption): array
    {
        $status = self::OK;
        $issues = 0;
        foreach ($sections as $section) {
            $status = $this->worst($status, $section['status']);
            foreach ($section['checks'] as $check) {
                if (in_array($check['status'], [self::WARNING, self::ERROR], true)) {
                    $issues++;
                }
            }
        }

        return [
            'title' => $this->t($title),
            'icon' => $icon,
            'status' => $status,
            'issues' => $issues,
            'headline' => $headline,
            'caption' => $caption,
        ];
    }

    // =========================================================================
    // DETAIL LISTS
    // =========================================================================

    /**
     * @return array<string,int>
     */
    private function kpis(): array
    {
        $journal = $this->journal->countByLevelSince(new \DateTimeImmutable('-24 hours'));

        return [
            'offers_published' => (int) $this->value("SELECT COUNT(*) FROM {$this->prefix}inpost_offer WHERE status = 'PUBLISHED'"),
            'offers_total' => (int) $this->value("SELECT COUNT(*) FROM {$this->prefix}inpost_offer"),
            'orders_week' => (int) $this->value(
                "SELECT COUNT(*) FROM {$this->prefix}inpost_order WHERE date_add >= '" . pSQL(date('Y-m-d H:i:s', time() - 7 * 86400)) . "'"
            ),
            'orders_total' => (int) $this->value("SELECT COUNT(*) FROM {$this->prefix}inpost_order"),
            'errors_day' => $journal['error'],
            'warnings_day' => $journal['warning'],
            'categories_active' => (int) $this->value("SELECT COUNT(*) FROM {$this->prefix}inpost_category_mapping WHERE is_active = 1"),
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function offerStatusBreakdown(): array
    {
        return $this->rows(
            "SELECT COALESCE(status, '-') AS status, COUNT(*) AS cnt FROM {$this->prefix}inpost_offer
             GROUP BY status ORDER BY cnt DESC"
        );
    }

    /**
     * Offers that need operator action, with the reason.
     *
     * @return array<int,array<string,mixed>>
     */
    private function offersNeedingAttention(): array
    {
        return $this->rows(
            "SELECT o.offer_id, o.status, o.command_status, o.inpost_validation_status,
                    o.command_error, o.updated_at, p.name AS product_name, p.product_id
             FROM {$this->prefix}inpost_offer o
             LEFT JOIN {$this->prefix}inpost_offer_product p ON p.offer_id = o.offer_id
             WHERE o.command_status = 'FAILURE'
                OR o.inpost_validation_status = 'INVALID'
                OR o.status IN ('REJECTED', 'ERROR_NOT_CREATED')
             ORDER BY o.updated_at DESC
             LIMIT 15"
        );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function offerEventsByType(): array
    {
        return $this->rows(
            "SELECT offer_event_type AS type, COUNT(*) AS cnt, MAX(occured_at) AS last_at
             FROM {$this->prefix}inpost_offer_event
             WHERE occured_at >= '" . pSQL(date('Y-m-d H:i:s', time() - 7 * 86400)) . "'
             GROUP BY offer_event_type ORDER BY cnt DESC"
        );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function orderStatusBreakdown(): array
    {
        return $this->rows(
            "SELECT COALESCE(status, '-') AS status, COUNT(*) AS cnt FROM {$this->prefix}inpost_order
             GROUP BY status ORDER BY cnt DESC"
        );
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * @param array<int,array<string,mixed>> $checks
     * @return array<string,mixed>
     */
    private function section(string $title, string $icon, array $checks): array
    {
        $status = self::OK;
        foreach ($checks as $check) {
            $status = $this->worst($status, $check['status']);
        }

        return [
            'title' => $this->t($title),
            'icon' => $icon,
            'status' => $status,
            'checks' => $checks,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function check(string $label, string $value, string $status, ?string $hint = null): array
    {
        return [
            'label' => $this->t($label),
            'value' => $value,
            'status' => $status,
            'hint' => $hint !== '' ? $hint : null,
        ];
    }

    private function worst(string $a, string $b): string
    {
        // INFO never escalates a section - only warnings and errors do
        $b = $b === self::INFO ? self::OK : $b;

        return self::SEVERITY[$b] > self::SEVERITY[$a] ? $b : $a;
    }

    /**
     * @param array<string,mixed> $params
     */
    private function t(string $message, array $params = []): string
    {
        return $this->translator->trans($message, $params, self::DOMAIN);
    }

    private function jobLabel(string $name): string
    {
        return isset(JobMonitor::JOB_LABELS[$name]) ? $this->t(JobMonitor::JOB_LABELS[$name]) : $name;
    }

    private function ago(int $timestamp): string
    {
        $diff = time() - $timestamp;
        if ($diff < 0) {
            return $this->t('in the future');
        }
        if ($diff < 90) {
            return $this->t('just now');
        }
        if ($diff < 5400) {
            return $this->t('%n% min ago', ['%n%' => (int) round($diff / 60)]);
        }
        if ($diff < 172800) {
            return $this->t('%n% h ago', ['%n%' => (int) round($diff / 3600)]);
        }

        return $this->t('%n% days ago', ['%n%' => (int) floor($diff / 86400)]);
    }

    private function formatInterval(int $seconds): string
    {
        if ($seconds <= 0) {
            return $this->t('every run');
        }
        if ($seconds % 86400 === 0) {
            return $this->t('every %n% d', ['%n%' => $seconds / 86400]);
        }
        if ($seconds % 3600 === 0) {
            return $this->t('every %n% h', ['%n%' => $seconds / 3600]);
        }

        return $this->t('every %n% min', ['%n%' => (int) round($seconds / 60)]);
    }

    private function toBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return -1;
        }

        $number = (int) $value;
        switch (strtolower(substr($value, -1))) {
            case 'g':
                return $number * 1024 * 1024 * 1024;
            case 'm':
                return $number * 1024 * 1024;
            case 'k':
                return $number * 1024;
            default:
                return $number;
        }
    }

    /**
     * Single scalar; null when the query fails (e.g. table missing on a partial install).
     *
     * @return mixed
     */
    private function value(string $sql)
    {
        try {
            $value = Db::getInstance()->getValue($sql, false);

            return $value === false ? null : $value;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function row(string $sql): array
    {
        try {
            $row = Db::getInstance()->getRow($sql, false);

            return is_array($row) ? $row : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function rows(string $sql): array
    {
        try {
            $rows = Db::getInstance()->executeS($sql, true, false);

            return is_array($rows) ? $rows : [];
        } catch (\Throwable $e) {
            return [];
        }
    }
}
