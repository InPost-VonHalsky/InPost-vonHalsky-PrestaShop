<?php
/**
 * StatusCommand
 *
 * Console command for health check and diagnostics.
 * Displays OAuth2 status, API connectivity, and system statistics.
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Command;

use Inpost\VonHalsky\Service\Auth\OAuth2Service;
use Inpost\VonHalsky\Service\Api\OrganizationApiService;
use Inpost\VonHalsky\Service\ApiTokenManager;
use Inpost\VonHalsky\Service\Util\ConfigurationService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class StatusCommand extends Command
{
    protected static $defaultName = 'inpost:status';

    private OAuth2Service $oauth2Service;
    private OrganizationApiService $organizationApiService;
    private ConfigurationService $configService;
    private ApiTokenManager $tokenManager;

    public function __construct(
        OAuth2Service $oauth2Service,
        OrganizationApiService $organizationApiService,
        ConfigurationService $configService,
        ApiTokenManager $tokenManager
    ) {
        parent::__construct();
        $this->oauth2Service = $oauth2Service;
        $this->organizationApiService = $organizationApiService;
        $this->configService = $configService;
        $this->tokenManager = $tokenManager;
    }

    protected function configure(): void
    {
        $this
            ->setName('inpost:status')
            ->setDescription('Check InPost integration status and health')
            ->setHelp('Displays OAuth2 connection status, API connectivity, and system statistics.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('InPost von Halsky - Status Check');

        $allHealthy = true;

        // 1. OAuth2 Status
        $oauth2Healthy = $this->checkOAuth2Status($io);
        $allHealthy = $allHealthy && $oauth2Healthy;

        // 2. API Connectivity
        $apiHealthy = $this->checkApiConnectivity($io);
        $allHealthy = $allHealthy && $apiHealthy;

        // 3. Database Statistics
        $this->displayStatistics($io);

        // 4. Configuration
        $this->displayConfiguration($io);

        // Overall Status
        $io->section('Overall Status');

        if ($allHealthy) {
            $io->success('✓ All systems operational');
            return 0;
        } else {
            $io->error('✗ Some systems have issues');
            return 1;
        }
    }

    /**
     * Check OAuth2 status
     *
     * @param SymfonyStyle $io
     * @return bool True if healthy
     */
    private function checkOAuth2Status(SymfonyStyle $io): bool
    {
        $io->section('OAuth2 Connection Status');

        try {
            $token = $this->tokenManager->getAccessToken();

            if (!$token) {
                // No access token - try to refresh using stored refresh token
                $refreshToken = $this->tokenManager->getRefreshToken();
                
                if (!$refreshToken) {
                    $io->error('✗ Not connected - No access token or refresh token found');
                    $io->note('Run OAuth2 connection from admin panel or use connect command');
                    return false;
                }

                $io->warning('⚠ No access token - Attempting refresh...');
                
                try {
                    $this->oauth2Service->refreshToken($refreshToken);
                    $token = $this->tokenManager->getAccessToken();
                    $io->success('✓ Token refreshed successfully');
                } catch (\Exception $e) {
                    $io->error('✗ Token refresh failed: ' . $e->getMessage());
                    $io->note('Run OAuth2 connection from admin panel or use connect command');
                    return false;
                }
            }

            // Check token expiry
            $expiresAt = $this->tokenManager->getAccessTokenExpiration();
            $now = time();

            if ($expiresAt && $expiresAt < $now) {
                $io->warning('⚠ Token expired - Attempting refresh...');

                $refreshToken = $this->tokenManager->getRefreshToken();
                if (!$refreshToken) {
                    $io->error('✗ Token refresh failed: No refresh token available');
                    return false;
                }

                try {
                    $this->oauth2Service->refreshToken($refreshToken);
                    $io->success('✓ Token refreshed successfully');
                } catch (\Exception $e) {
                    $io->error('✗ Token refresh failed: ' . $e->getMessage());
                    return false;
                }
            } else {
                $expiresInMinutes = $expiresAt ? floor(($expiresAt - $now) / 60) : 0;
                $io->success(sprintf('✓ Connected - Token expires in %d minutes', $expiresInMinutes));
            }

            return true;
        } catch (\Exception $e) {
            $io->error('✗ OAuth2 check failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Check API connectivity
     *
     * @param SymfonyStyle $io
     * @return bool True if healthy
     */
    private function checkApiConnectivity(SymfonyStyle $io): bool
    {
        $io->section('API Connectivity');

        // Guard: Check if API is configured
        if (empty($this->configService->get(ConfigurationService::API_URL)) || !$this->tokenManager->getAccessToken()) {
            $io->warning('⚠ API not configured - missing API URL or access token');
            $io->note('Please configure the module and connect via OAuth2 in the admin panel');
            return false;
        }

        try {
            // Try to fetch organizations as connectivity test
            $result = $this->organizationApiService->listOrganizations();
            if (!$result['success']) {
                throw new \RuntimeException($result['error'] ?? 'Failed to fetch organizations');
            }
            $data = $result['data'];
            $organizations = isset($data['items']) ? $data['items'] : (is_array($data) ? $data : []);

            if (empty($organizations)) {
                $io->warning('⚠ API accessible but no organizations found');
                return true; // Still healthy, just no data
            }

            $io->success(sprintf('✓ API accessible - Found %d organization(s)', count($organizations)));

            // Display organizations
            $orgData = [];
            foreach ($organizations as $org) {
                $orgData[] = [
                    $org['id'] ?? 'N/A',
                    $org['name'] ?? 'N/A',
                ];
            }

            $io->table(['Organization ID', 'Name'], $orgData);

            return true;
        } catch (\Exception $e) {
            $io->error('✗ API connectivity failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Display database statistics
     *
     * @param SymfonyStyle $io
     */
    private function displayStatistics(SymfonyStyle $io): void
    {
        $io->section('Database Statistics');

        $db = \Db::getInstance();

        $stats = [
            'Category Mappings' => $this->getCount($db, 'inpost_category_mapping'),
            'Categories Active' => $this->getCountSafe($db, 'inpost_category_mapping', "is_active = 1"),
            'Attributes' => $this->getCount($db, 'inpost_attribute'),
            'Attributes Active' => $this->getCountSafe($db, 'inpost_attribute', "is_active = 1"),
            'Category-Attribute Links' => $this->getCount($db, 'inpost_category_attribute'),
            'Dictionary Values' => $this->getCount($db, 'inpost_attribute_dictionary'),
            'Dictionary Values Active' => $this->getCountSafe($db, 'inpost_attribute_dictionary', "active = 1"),
            'Offers Total' => $this->getCount($db, 'inpost_offer'),
            'Offers Active' => $this->getCount($db, 'inpost_offer', "status = 'active'"),
            'Offers Inactive' => $this->getCount($db, 'inpost_offer', "status = 'inactive'"),
            'Offers Draft' => $this->getCount($db, 'inpost_offer', "status = 'draft'"),
            'Offer Events' => $this->getCount($db, 'inpost_offer_event'),
        ];

        $tableData = [];
        foreach ($stats as $metric => $count) {
            $tableData[] = [$metric, $count];
        }

        $io->table(['Metric', 'Count'], $tableData);
    }

    /**
     * Display configuration
     *
     * @param SymfonyStyle $io
     */
    private function displayConfiguration(SymfonyStyle $io): void
    {
        $io->section('Configuration');

        $config = [
            'API URL' => $this->configService->get(ConfigurationService::API_URL, null),
            'Auth Service URL' => $this->configService->get(ConfigurationService::AUTH_SERVICE_URL, null),
            'Organization ID' => $this->configService->get(ConfigurationService::ORGANIZATION_ID, null) ?: 'Not set',
            'Test Mode' => $this->configService->get(ConfigurationService::TEST_MODE) ? 'Yes' : 'No',
            'Debug Mode' => $this->configService->get('INPOSTSA_DEBUG', null) ? 'Yes' : 'No',
        ];

        $tableData = [];
        foreach ($config as $key => $value) {
            $tableData[] = [$key, $value];
        }

        $io->table(['Setting', 'Value'], $tableData);
    }

    /**
     * Get row count from table
     *
     * @param \Db $db Database instance
     * @param string $table Table name (without prefix)
     * @param string|null $where Optional WHERE clause
     * @return int Count
     */
    private function getCount(\Db $db, string $table, ?string $where = null): int
    {
        $sql = 'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . $table;

        if ($where) {
            $sql .= ' WHERE ' . $where;
        }

        return (int)$db->getValue($sql);
    }

    /**
     * Get row count from table with safe fallback if columns don't exist
     *
     * @param \Db $db Database instance
     * @param string $table Table name (without prefix)
     * @param string|null $where Optional WHERE clause
     * @return int Count (0 if column doesn't exist)
     */
    private function getCountSafe(\Db $db, string $table, ?string $where = null): int
    {
        try {
            return $this->getCount($db, $table, $where);
        } catch (\Exception $e) {
            // If column doesn't exist yet (e.g., is_active), return 0
            return 0;
        }
    }
}
