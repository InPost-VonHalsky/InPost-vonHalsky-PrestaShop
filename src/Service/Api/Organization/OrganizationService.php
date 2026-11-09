<?php
/**
 * Organization Service
 *
 * Service for managing organization data with three-tier caching
 * (PrestaShop Cache -> Database -> API)
 *
 * @author    Marcin Bach
 * @copyright 2025 Marcin Bach
 * @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Service\Api\Organization;

use Cache;
use Db;
use Inpost\VonHalsky\Helper\Config;
use Inpost\VonHalsky\Service\Api\ApiErrorHandler;
use Inpost\VonHalsky\Service\Api\ApiServiceAbstract;
use Inpost\VonHalsky\Service\Auth\OAuth2Service;

class OrganizationService extends ApiServiceAbstract
{
    private const CACHE_KEY_PREFIX = 'inpost_organization_';
    public const CACHE_TAG_LIST = 'inpost_organizations';
    private const TABLE_NAME = 'inpost_organization';

    /**
     * Constructor
     *
     * @param OAuth2Service $oAuth2Service
     * @param Config $configHelper
     * @param ApiErrorHandler $errorHandler
     */
    public function __construct(
        OAuth2Service $oAuth2Service,
        Config $configHelper,
        ApiErrorHandler $errorHandler
    ) {
        parent::__construct($oAuth2Service, $configHelper, $errorHandler);
        $this->ensureTableExists();
    }

    /**
     * Ensure organization table exists
     *
     * @return void
     */
    private function ensureTableExists(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS `" . _DB_PREFIX_ . self::TABLE_NAME . "` (
            `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            `organization_id` VARCHAR(255) NOT NULL,
            `name` VARCHAR(255) NOT NULL,
            `type` VARCHAR(100) DEFAULT NULL,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `cached_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `organization_id` (`organization_id`),
            KEY `is_active` (`is_active`)
        ) ENGINE=" . _MYSQL_ENGINE_ . " DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

        try {
            Db::getInstance()->execute($sql);
        } catch (\Exception $e) {
            $this->log('error', 'Failed to create organization table', [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Get all active organizations from database
     * Triggers sync if no organizations found
     *
     * @return array Array of normalized organization data
     */
    public function getActiveOrganizations(): array
    {
        try {
            $sql = "SELECT * FROM `" . _DB_PREFIX_ . self::TABLE_NAME . "` 
                    WHERE `is_active` = 1 
                    ORDER BY `name` ASC";
            
            $rows = Db::getInstance()->executeS($sql);

            // If no organizations in database, try to sync from API
            if (empty($rows)) {
                $this->log('info', 'No organizations in database, attempting sync from API');
                
                $synced = $this->syncAllOrganizations();
                
                if ($synced > 0) {
                    // Fetch again after sync
                    $rows = Db::getInstance()->executeS($sql);
                }
            }

            // Normalize organization data
            $organizations = [];
            foreach ($rows as $row) {
                $organizations[] = [
                    'organization_id' => $row['organization_id'],
                    'name' => $row['name'],
                    'type' => $row['type'] ?? null,
                    'status' => $row['is_active'] ? 'active' : 'inactive'
                ];
            }

            $this->log('info', 'Retrieved active organizations', [
                'count' => count($organizations)
            ]);

            return $organizations;

        } catch (\Exception $e) {
            $this->log('error', 'Error fetching active organizations from database', [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Save organization to database cache
     *
     * @param array $organizationData
     * @return void
     */
    private function saveToDatabase(array $organizationData): void
    {
        try {
            // Map API status to is_active (1 for active/enabled, 0 for others)
            $status = $organizationData['status'] ?? 'active';
            $isActive = in_array(strtolower($status), ['active', 'enabled']) ? 1 : 0;

            $data = [
                'organization_id' => pSQL($organizationData['organization_id']),
                'name' => pSQL($organizationData['name']),
                'type' => isset($organizationData['type']) ? pSQL($organizationData['type']) : null,
                'is_active' => (int) $isActive,
                'updated_at' => date('Y-m-d H:i:s'),
                'cached_at' => date('Y-m-d H:i:s')
            ];

            // Check if exists
            $exists = Db::getInstance()->getValue(
                "SELECT COUNT(*) FROM `" . _DB_PREFIX_ . self::TABLE_NAME . "` 
                 WHERE `organization_id` = '" . pSQL($organizationData['organization_id']) . "'"
            );

            if ($exists) {
                // Update
                Db::getInstance()->update(
                    self::TABLE_NAME,
                    $data,
                    "`organization_id` = '" . pSQL($organizationData['organization_id']) . "'"
                );
            } else {
                // Insert
                Db::getInstance()->insert(self::TABLE_NAME, $data);
            }
        } catch (\Exception $e) {
            $this->log('error', 'Error saving organization to database', [
                'organization_id' => $organizationData['organization_id'] ?? 'unknown',
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Fetch all organizations from API
     *
     * @return array Array of organizations
     */
    private function fetchAllOrganizationsFromApi(): array
    {
        try {
            // Get access token
            $accessToken = $this->oAuth2Service->getValidAccessToken();
            if (!$accessToken) {
                $this->log('error', 'No valid access token for organizations fetch');
                return [];
            }

            // Use dedicated User Organizations Endpoint from config
            $url = $this->configHelper->getUserOrgsEndpoint();

            // Fallback to building URL from API base if endpoint not configured
            if (empty($url)) {
                $apiConfig = $this->getApiConfiguration();
                $baseUrl = $apiConfig->getHost();

                // Remove /inpsa/v1 or /inpsa suffix if present (organizations endpoint is at root level)
                $baseUrl = preg_replace('#/inpsa(/v\d+)?/?$#', '', $baseUrl);

                // Build full URL - this endpoint returns ALL user organizations
                $url = rtrim($baseUrl, '/') . '/organizations';
            }

            $this->log('info', 'Fetching organizations from URL', ['url' => $url]);

            // Make HTTP request using Guzzle
            $client = new \GuzzleHttp\Client();
            $response = $client->get($url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json'
                ],
                'timeout' => 10
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode !== 200) {
                $this->log('warning', 'Non-200 status code fetching organizations', [
                    'status_code' => $statusCode,
                    'url' => $url
                ]);
                return [];
            }

            $body = $response->getBody()->getContents();
            $data = json_decode($body, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->log('error', 'JSON decode error fetching organizations', [
                    'json_error' => json_last_error_msg()
                ]);
                return [];
            }

            $organizations = [];

            // Process organizations response - handle different response structures
            if (is_array($data)) {
                // Direct array of organizations
                if (isset($data[0])) {
                    $organizations = $data;
                }
                // Response with data wrapper
                elseif (isset($data['data']) && is_array($data['data'])) {
                    $organizations = $data['data'];
                }
                // Response with organizations wrapper
                elseif (isset($data['organizations']) && is_array($data['organizations'])) {
                    $organizations = $data['organizations'];
                }
                // Single organization object
                elseif (isset($data['id'])) {
                    $organizations = [$data];
                }
            }

            // Normalize organization data
            $normalized = [];
            foreach ($organizations as $org) {
                if (isset($org['id'])) {
                    $normalized[] = [
                        'organization_id' => $org['id'],
                        'name' => $org['name'] ?? 'Organization ' . $org['id'],
                        'type' => $org['type'] ?? null,
                        'status' => $org['status'] ?? 'active'
                    ];
                }
            }

            $this->log('info', 'Fetched organizations from API', [
                'count' => count($normalized),
                'url' => $url
            ]);

            return $normalized;

        } catch (\GuzzleHttp\Exception\GuzzleException $e) {
            $this->log('error', 'HTTP error fetching organizations from API', [
                'error' => $e->getMessage()
            ]);
        } catch (\Exception $e) {
            $this->log('error', 'Error fetching organizations from API', [
                'error' => $e->getMessage()
            ]);
        }

        return [];
    }

    /**
     * Sync all organizations from API to database
     *
     * @return int Number of organizations synced
     */
    public function syncAllOrganizations(): int
    {
        $organizations = $this->fetchAllOrganizationsFromApi();

        if (empty($organizations)) {
            $this->log('warning', 'No organizations fetched from API for sync');
            return 0;
        }

        $synced = 0;
        foreach ($organizations as $orgData) {
            try {
                $this->saveToDatabase($orgData);
                
                // Also update PrestaShop cache
                $cacheKey = self::CACHE_KEY_PREFIX . $orgData['organization_id'];
                Cache::store($cacheKey, json_encode($orgData));
                
                $synced++;
            } catch (\Exception $e) {
                $this->log('error', 'Error syncing organization', [
                    'organization_id' => $orgData['organization_id'] ?? 'unknown',
                    'error' => $e->getMessage()
                ]);
            }
        }

        $this->log('info', 'Organizations synced to database', [
            'total_fetched' => count($organizations),
            'successfully_synced' => $synced
        ]);

        return $synced;
    }

}
