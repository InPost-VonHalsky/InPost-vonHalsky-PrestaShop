<?php
/**
 * CategoryMappingService
 *
 * Service for managing bidirectional category mappings between PrestaShop and InPost.
 * Handles category synchronization, mapping storage, and automatic suggestion.
 * Works with two tables:
 * - inpost_category_mapping: InPost categories synced from API
 * - inpost_category_link: PrestaShop to InPost category mappings
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Service\Mapping;

use Inpost\VonHalsky\Helper\Config;
use Inpost\VonHalsky\Service\Api\CategoryApiService;
use Inpost\VonHalsky\Service\Util\ConfigurationService;
use Inpost\VonHalsky\Service\Util\LoggerService;
use Db;
use DbQuery;

class CategoryMappingService
{
    /**
     * Rows per INSERT ... ON DUPLICATE KEY UPDATE statement in bulkSaveCategories()
     */
    private const BULK_UPSERT_CHUNK_SIZE = 500;

    /**
     * WHERE condition on the mapping language column
     */
    private static function languageCondition(string $language): string
    {
        return "language = '" . pSQL($language) . "'";
    }

    private CategoryApiService $categoryApiService;
    private ConfigurationService $configurationService;
    private LoggerService $logger;
    private Config $config;
    private Db $db;
    private string $tablePrefix;

    public function __construct(
        CategoryApiService $categoryApiService,
        ConfigurationService $configurationService,
        LoggerService $logger,
        Config $config
    ) {
        $this->categoryApiService = $categoryApiService;
        $this->configurationService = $configurationService;
        $this->logger = $logger;
        $this->config = $config;
        $this->db = Db::getInstance();
        $this->tablePrefix = _DB_PREFIX_;
    }

    /**
     * Create or update mapping between PrestaShop category and InPost category
     *
     * @param int $psCategoryId PrestaShop category ID
     * @param string $inpostCategoryId InPost category ID
     * @param int $shopId Shop ID (optional, null for all shops)
     * @return bool Success status
     * @throws \Exception
     */
    public function mapCategory(int $psCategoryId, string $inpostCategoryId, int $shopId = null): bool
    {
        $this->logger->info("Mapping PrestaShop category to InPost", [
            'ps_category_id' => $psCategoryId,
            'inpost_category_id' => $inpostCategoryId,
            'shop_id' => $shopId,
        ]);

        // Verify InPost category exists in our database
        $mappingId = $this->getInPostCategoryMappingId($inpostCategoryId);
        if (!$mappingId) {
            throw new \Exception("InPost category not found: {$inpostCategoryId}. Please sync categories first.");
        }

        // Offers can only be placed in leaf categories, so only those may be mapped
        if (!$this->isInPostCategoryLeaf($mappingId)) {
            throw new \Exception("InPost category {$inpostCategoryId} is not a leaf category. Only leaf (lowest-level) categories can be mapped.");
        }

        // Verify PrestaShop category exists
        if (!$this->prestashopCategoryExists($psCategoryId)) {
            throw new \Exception("PrestaShop category not found: {$psCategoryId}");
        }

        // Check if mapping already exists
        $existingLink = $this->getCategoryLink($psCategoryId, $shopId);

        if ($existingLink) {
            // Update existing mapping
            $this->logger->info("Updating existing mapping", [
                'link_id' => $existingLink['link_id'] ?? 'unknown',
                'old_mapping_id' => $existingLink['mapping_id'] ?? 'unknown',
                'new_mapping_id' => $mappingId
            ]);

            $result = $this->db->update(
                'inpost_category_link',
                [
                    'mapping_id' => (int)$mappingId,
                ],
                sprintf(
                    'prestashop_category_id = %d AND %s',
                    (int)$psCategoryId,
                    $shopId !== null ? "shop_id = {$shopId}" : 'shop_id IS NULL'
                )
            );

            if (!$result) {
                $dbError = $this->db->getMsgError();
                $this->logger->error("Database UPDATE failed", [
                    'mysql_error' => $dbError,
                    'ps_category_id' => $psCategoryId,
                    'mapping_id' => $mappingId,
                    'shop_id' => $shopId
                ]);
                throw new \RuntimeException("Database error during UPDATE: {$dbError}");
            }
        } else {
            // Insert new mapping
            $insertData = [
                'mapping_id' => (int)$mappingId,
                'prestashop_category_id' => (int)$psCategoryId,
                'shop_id' => $shopId,
            ];

            $this->logger->info("Inserting new mapping", [
                'data' => $insertData,
                'table' => $this->tablePrefix . 'inpost_category_link'
            ]);

            $result = $this->db->insert(
                'inpost_category_link',
                $insertData
            );

            if (!$result) {
                $dbError = $this->db->getMsgError();
                $affectedRows = $this->db->Affected_Rows();

                $this->logger->error("Database INSERT failed", [
                    'mysql_error' => $dbError,
                    'affected_rows' => $affectedRows,
                    'insert_data' => $insertData,
                    'table' => $this->tablePrefix . 'inpost_category_link'
                ]);

                throw new \RuntimeException("Database error during INSERT: {$dbError}");
            }

            $insertId = $this->db->Insert_ID();
            $this->logger->info("Category mapping inserted successfully", [
                'link_id' => $insertId,
                'affected_rows' => $this->db->Affected_Rows()
            ]);
        }

        $this->logger->info("Category mapping operation completed successfully");
        return true;
    }

    /**
     * Get InPost category UUID by entity ID
     *
     * @param int $entityId Category mapping entity ID
     *
     * @return string|null InPost category UUID or null if not found
     */
    public function getInpostCategoryUuidById(int $entityId): ?string
    {
        $query = new DbQuery();
        $query->select('inpost_category_id')
            ->from('inpost_category_mapping')
            ->where('entity_id = ' . (int) $entityId);

        $result = $this->db->getValue($query);

        return $result ?: null;
    }

    /**
     * Whether an InPost category UUID is already synced locally (so mapCategory()
     * would succeed). Read-only; mirrors the resolution mapCategory() uses.
     *
     * @param string $inpostCategoryId InPost category UUID
     * @param string $language Language code (defaults to 'pl', as mapCategory does)
     * @return bool
     */
    public function isInpostCategorySynced(string $inpostCategoryId, string $language = 'pl'): bool
    {
        return $this->getInPostCategoryMappingId($inpostCategoryId, $language) !== null;
    }

    /**
     * Get mapped InPost category entity ID for a PrestaShop category
     *
     * @param int $psCategoryId PrestaShop category ID
     * @param int|null $shopId Shop ID (null for any shop)
     *
     * @return int|null Category mapping entity ID or null if not mapped
     */
    public function getMappedInPostCategoryEntityId(int $psCategoryId, ?int $shopId = null): ?int
    {
        $query = new DbQuery();
        $query->select('cm.entity_id')
            ->from('inpost_category_link', 'cl')
            ->leftJoin('inpost_category_mapping', 'cm', 'cl.mapping_id = cm.entity_id')
            ->where('cl.prestashop_category_id = ' . (int) $psCategoryId);

        if ($shopId !== null) {
            $query->where('cl.shop_id = ' . (int) $shopId . ' OR cl.shop_id IS NULL');
        }

        $query->orderBy('cl.shop_id DESC'); // Prefer shop-specific mappings

        $result = $this->db->getValue($query);

        return $result ? (int) $result : null;
    }

    /**
     * Get full mapping details for a PrestaShop category
     *
     * @param int $psCategoryId PrestaShop category ID
     * @param int|null $shopId Shop ID
     * @return array|null Mapping details or null
     */
    public function getCategoryMapping(int $psCategoryId, int $shopId = null): ?array
    {
        $query = new DbQuery();
        $query->select('cm.*, cl.link_id, cl.shop_id as mapped_shop_id')
            ->from('inpost_category_link', 'cl')
            ->leftJoin('inpost_category_mapping', 'cm', 'cl.mapping_id = cm.entity_id')
            ->where('cl.prestashop_category_id = ' . (int)$psCategoryId);

        if ($shopId !== null) {
            $query->where('cl.shop_id = ' . (int)$shopId . ' OR cl.shop_id IS NULL');
        }

        $query->orderBy('cl.shop_id DESC');

        $result = $this->db->getRow($query);

        return $result ?: null;
    }

    /**
     * Names from the InPost root down to the category, e.g. ['Uroda', 'Perfumy i wody', 'Perfumy']
     *
     * @return string[]
     */
    public function getInpostCategoryPath(int $entityId): array
    {
        $names = [];
        $guard = 0;
        while ($entityId > 0 && $guard++ < 20) {
            $row = $this->db->getRow(
                'SELECT name, parent_id FROM `' . $this->tablePrefix . 'inpost_category_mapping` WHERE entity_id = ' . (int) $entityId
            );
            if (!$row) {
                break;
            }
            array_unshift($names, (string) $row['name']);
            $entityId = (int) $row['parent_id'];
        }

        return $names;
    }

    /**
     * Get all category mappings
     *
     * @param int|null $shopId Filter by shop ID (null for all shops)
     * @return array Array of mappings
     */
    public function getAllMappings(int $shopId = null): array
    {
        $langId = (int) $this->config->getDefaultLanguageId();
        $shopForLang = (int) ($shopId ?? $this->config->getDefaultShopId());

        $query = new DbQuery();
        $query->select('cm.*, cl.link_id, cl.prestashop_category_id, cl.shop_id as mapped_shop_id, catl.name as ps_category_name')
            ->from('inpost_category_link', 'cl')
            ->leftJoin('inpost_category_mapping', 'cm', 'cl.mapping_id = cm.entity_id')
            ->leftJoin(
                'category_lang',
                'catl',
                'catl.id_category = cl.prestashop_category_id AND catl.id_lang = ' . $langId
                . ' AND catl.id_shop = ' . $shopForLang
            );

        if ($shopId !== null) {
            $query->where('cl.shop_id = ' . (int)$shopId . ' OR cl.shop_id IS NULL');
        }

        $query->groupBy('cl.link_id');
        $query->orderBy('cm.name ASC');

        $results = $this->db->executeS($query);

        return $results ?: [];
    }

    /**
     * Remove category mapping
     *
     * @param int $psCategoryId PrestaShop category ID
     * @param int|null $shopId Shop ID (null to remove all mappings for this category)
     * @return bool Success status
     */
    public function removeMapping(int $psCategoryId, int $shopId = null): bool
    {
        $this->logger->info("Removing category mapping", [
            'ps_category_id' => $psCategoryId,
            'shop_id' => $shopId,
        ]);

        $where = sprintf('prestashop_category_id = %d', (int)$psCategoryId);
        if ($shopId !== null) {
            $where .= sprintf(' AND shop_id = %d', (int)$shopId);
        }

        $result = $this->db->delete('inpost_category_link', $where);

        if ($result) {
            $this->logger->info("Category mapping removed successfully");
        }

        return (bool)$result;
    }

    /**
     * Get PrestaShop categories linked to an InPost category
     *
     * @param string $inpostCategoryId InPost category ID
     * @param int $shopId Shop ID
     * @return array Array of linked PrestaShop categories
     */
    public function getLinkedPrestashopCategories(string $inpostCategoryId, int $shopId): array
    {
        $query = new DbQuery();
        $query->select('cl.link_id, cl.prestashop_category_id, cl.shop_id, cat.id_category, catl.name as ps_category_name, cm.is_active')
            ->from('inpost_category_link', 'cl')
            ->leftJoin('inpost_category_mapping', 'cm', 'cl.mapping_id = cm.entity_id')
            ->leftJoin('category', 'cat', 'cl.prestashop_category_id = cat.id_category')
            ->leftJoin('category_lang', 'catl', 'cat.id_category = catl.id_category AND catl.id_lang = ' . $this->config->getDefaultLanguageId())
            ->where("cm.inpost_category_id = '" . pSQL($inpostCategoryId) . "'")
            ->where('cl.shop_id = ' . (int)$shopId . ' OR cl.shop_id IS NULL')
            ->orderBy('catl.name ASC');

        $results = $this->db->executeS($query);

        return $results ?: [];
    }

    /**
     * Get all mapped InPost categories for a PrestaShop product
     *
     * Retrieves InPost categories mapped from ALL PrestaShop categories
     * that the product belongs to, sorted by default category first.
     *
     * @param int $productId PrestaShop product ID
     * @param int|null $shopId Shop ID (defaults to current context shop)
     *
     * @return array Array of mapped categories with structure:
     *               [
     *                 ['mapping_id' => int, 'inpost_category_id' => string, 'inpost_category_name' => string,
     *                  'ps_category_id' => int, 'ps_category_name' => string, 'is_default' => bool],
     *                 ...
     *               ]
     *               Sorted by: default category first, then alphabetically by InPost name
     */
    public function getMappedInPostCategoriesForProduct(int $productId, ?int $shopId = null): array
    {
        // Get product's default category for sorting
        $product = new \Product($productId);
        if (!$product->id) {
            return [];
        }

        $defaultCategoryId = (int) $product->id_category_default;

        // Use default shop if not specified
        if ($shopId === null) {
            $shopId = $this->config->getDefaultShopId();
        }

        $query = new DbQuery();
        $query->select('
            cl.mapping_id,
            cm.inpost_category_id,
            cm.name as inpost_category_name,
            cl.prestashop_category_id as ps_category_id,
            catl.name as ps_category_name,
            cm.is_active,
            (cl.prestashop_category_id = ' . $defaultCategoryId . ') as is_default
        ')
            ->from('inpost_category_link', 'cl')
            ->innerJoin('inpost_category_mapping', 'cm', 'cl.mapping_id = cm.entity_id')
            ->innerJoin('category', 'cat', 'cl.prestashop_category_id = cat.id_category')
            ->innerJoin('category_lang', 'catl', 'cat.id_category = catl.id_category AND catl.id_lang = ' . $this->config->getDefaultLanguageId())
            ->where('cl.prestashop_category_id IN (
                SELECT id_category
                FROM ' . $this->tablePrefix . 'category_product
                WHERE id_product = ' . (int) $productId . '
            )')
            ->where('cl.shop_id = ' . (int) $shopId . ' OR cl.shop_id IS NULL')
            ->orderBy('is_default DESC, cm.name ASC');

        $results = $this->db->executeS($query);

        return $results ?: [];
    }

    // =========================================================================
    // PRIVATE HELPER METHODS
    // =========================================================================

    /**
     * Upsert InPost category into database (Magento-style: pass parent entity_id as parameter)
     *
     * @param array $category Category data from API
     * @param string $language Language code
     * @param int|null $parentEntityId Parent's database entity_id (not InPost UUID)
     * @return int|false The entity_id of the saved category, or false on failure
     */
    private function upsertInPostCategory(array $category, string $language, ?int $parentEntityId = null)
    {

        $data = [
            'inpost_category_id' => pSQL($category['id']),
            // Support both snake_case (parent_id) and camelCase (parentId) from API
            'inpost_parent_id' => isset($category['parent_id']) ? pSQL($category['parent_id']) :
                                 (isset($category['parentId']) ? pSQL($category['parentId']) : null),
            'name' => pSQL($category['name'] ?? ''),
            'description' => isset($category['description']) ? pSQL($category['description']) : null,
            'level' => (int)($category['level'] ?? 0),
            'is_leaf' => (int)($category['is_leaf'] ?? 0),
            'is_active' => (int)($category['is_active'] ?? 1),
            'sort_order' => (int)($category['sort_order'] ?? 0),
            'language' => pSQL($language),
            'synced_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        // Only include parent_id if it's not NULL (PrestaShop Db::insert() doesn't handle NULL properly)
        if ($parentEntityId !== null) {
            $data['parent_id'] = $parentEntityId;
        }

        // Check if category already exists
        $exists = $this->inpostCategoryExists($category['id'], $language);

        if ($exists) {
            // Update existing
            $success = $this->db->update(
                'inpost_category_mapping',
                $data,
                sprintf(
                    "inpost_category_id = '%s' AND language = '%s'",
                    pSQL($category['id']),
                    pSQL($language)
                )
            );
            // Return existing entity_id
            return $success ? $this->getInPostCategoryMappingId($category['id'], $language) : false;
        } else {
            // Insert new
            $data['created_at'] = date('Y-m-d H:i:s');
            $success = $this->db->insert('inpost_category_mapping', $data);
            // Return new entity_id
            return $success ? (int)$this->db->Insert_ID() : false;
        }
    }

    /**
     * Check if InPost category exists in database
     *
     * @param string $inpostCategoryId InPost category ID
     * @param string $language Language code
     * @return bool
     */
    private function inpostCategoryExists(string $inpostCategoryId, string $language): bool
    {
        $query = new DbQuery();
        $query->select('COUNT(*)')
            ->from('inpost_category_mapping')
            ->where("inpost_category_id = '" . pSQL($inpostCategoryId) . "'")
            ->where(self::languageCondition($language));

        return (bool)$this->db->getValue($query);
    }

    /**
     * Get InPost category mapping entity ID
     *
     * @param string $inpostCategoryId InPost category ID
     * @param string $language Language code
     * @return int|null Entity ID or null
     */
    private function getInPostCategoryMappingId(string $inpostCategoryId, string $language = 'pl'): ?int
    {
        $query = new DbQuery();
        $query->select('entity_id')
            ->from('inpost_category_mapping')
            ->where("inpost_category_id = '" . pSQL($inpostCategoryId) . "'")
            ->where(self::languageCondition($language));

        $result = $this->db->getValue($query);

        return $result ? (int)$result : null;
    }

    /**
     * Check if a synced InPost category is a leaf (has no subcategories)
     *
     * @param int $mappingId Entity ID from inpost_category_mapping
     * @return bool
     */
    private function isInPostCategoryLeaf(int $mappingId): bool
    {
        $query = new DbQuery();
        $query->select('is_leaf')
            ->from('inpost_category_mapping')
            ->where('entity_id = ' . (int) $mappingId);

        return (bool) $this->db->getValue($query);
    }

    /**
     * Check if PrestaShop category exists
     *
     * @param int $psCategoryId PrestaShop category ID
     * @return bool
     */
    private function prestashopCategoryExists(int $psCategoryId): bool
    {
        $query = new DbQuery();
        $query->select('COUNT(*)')
            ->from('category')
            ->where('id_category = ' . (int)$psCategoryId);

        return (bool)$this->db->getValue($query);
    }

    /**
     * Get existing category link
     *
     * @param int $psCategoryId PrestaShop category ID
     * @param int|null $shopId Shop ID
     * @return array|null Link data or null
     */
    private function getCategoryLink(int $psCategoryId, int $shopId = null): ?array
    {
        $query = new DbQuery();
        $query->select('*')
            ->from('inpost_category_link')
            ->where('prestashop_category_id = ' . (int)$psCategoryId);

        if ($shopId !== null) {
            $query->where('shop_id = ' . (int)$shopId);
        } else {
            $query->where('shop_id IS NULL');
        }

        $result = $this->db->getRow($query);

        return $result ?: null;
    }

    /**
     * Sync InPost categories with recursive depth fetching (unlimited depth)
     *
     * @param string $language Language code
     * @param int $maxDepth DEPRECATED - No longer used, kept for backward compatibility
     * @return array Sync result with counts
     * @throws \Exception
     */
    public function syncInPostCategoriesRecursive(string $language = 'pl', int $maxDepth = 5, bool $fullSync = true): array
    {
        $syncStartTime = new \DateTime();
        $phaseStartTime = microtime(true);
        $apiDepth = 3; // API maximum per request
        $this->logger->info("Starting recursive category sync with unlimited depth: language={$language}");

        // PHASE 1: Initial fetch with depth=3
        $response = $this->categoryApiService->listCategories($language, $apiDepth);
        if (!$response['success']) {
            $this->logger->error("Failed to fetch categories from API");
            return ['success' => false, 'total' => 0, 'inserted' => 0, 'updated' => 0, 'errors' => ['API fetch failed'], 'orphaned' => 0];
        }
        $data = $response['data'];
        $items = is_object($data) && method_exists($data, 'getData') ? ($data->getData() ?? []) : ($data['items'] ?? $data ?? []);
        $categories = $this->categoryApiService->convertToArrays($items);

        if (empty($categories)) {
            $this->logger->warning("No categories returned from API");
            return ['success' => true, 'total' => 0, 'inserted' => 0, 'updated' => 0, 'errors' => [], 'orphaned' => 0];
        }

        $phase1Time = microtime(true) - $phaseStartTime;
        $this->logger->info(sprintf("✓ PHASE 1: Received %d root categories from API [%.2fs]", count($categories), $phase1Time));

        // Collect all categories first for bulk save
        $allCategories = [];
        $leafCategories = [];

        // Collect categories from initial tree
        foreach ($categories as $category) {
            $this->collectCategoriesFromTree($category, $allCategories, $leafCategories, null, 0);
        }

        // PHASE 2: Bulk save all categories from initial fetch
        $phaseStartTime = microtime(true);
        $bulkResult = $this->bulkSaveCategories($allCategories, $language);
        $inserted = $bulkResult['inserted'];
        $updated = $bulkResult['updated'];
        $errors = $bulkResult['errors'];
        $phase2Time = microtime(true) - $phaseStartTime;
        $this->logger->info(sprintf("✓ PHASE 2: Bulk saved %d categories (%d inserted, %d updated) [%.2fs]",
            count($allCategories), $inserted, $updated, $phase2Time));

        // PHASE 3: Update parent references
        $phaseStartTime = microtime(true);
        $this->updateParentReferences($language);
        $phase3Time = microtime(true) - $phaseStartTime;
        $this->logger->info(sprintf("✓ PHASE 3: Updated parent references [%.2fs]", $phase3Time));

        // PHASE 4: Recursive fetching beyond initial depth (unlimited)
        $phaseStartTime = microtime(true);
        $additionalFetched = $this->fetchIncompleteCategoriesRecursively(
            $language,
            $apiDepth,
            $inserted + $updated,  // Pass initial count for cumulative tracking
            $syncStartTime  // Pass sync start time to detect stale children
        );

        $inserted += $additionalFetched['inserted'];
        $updated += $additionalFetched['updated'];
        $errors = array_merge($errors, $additionalFetched['errors']);
        $phase4Time = microtime(true) - $phaseStartTime;
        $this->logger->info(sprintf("✓ PHASE 4: Recursive fetch completed (%d additional inserted, %d updated) [%.2fs]",
            $additionalFetched['inserted'], $additionalFetched['updated'], $phase4Time));

        // PHASE 5: Deactivate orphaned categories (not seen in this sync run).
        // Skipped when: this is not a full sync, the config kill-switch is off,
        // or Phase 4 reported errors — a partial tree must never drive a sweep,
        // or it would deactivate everything the sync never reached.
        $phaseStartTime = microtime(true);
        $phase5Time = 0.0;
        $sweepSkipped = false;
        $cleanupEnabled = (bool) $this->configurationService->getBool(
            ConfigurationService::CATEGORY_ORPHAN_CLEANUP_ENABLED,
            true
        );

        if (!$fullSync || !$cleanupEnabled || !empty($errors)) {
            $sweepSkipped = true;
            $orphanedCount = -1;
            // Only an error-driven skip needs attention; a disabled kill-switch or
            // a partial sync is an expected, configured outcome.
            $this->logger->{empty($errors) ? 'info' : 'warning'}('PHASE 5: Orphan sweep skipped', [
                'full_sync' => $fullSync,
                'cleanup_enabled' => $cleanupEnabled,
                'error_count' => count($errors),
            ]);
        } else {
            $orphanedCount = $this->deactivateOrphanCategories($language, $syncStartTime);
            $phase5Time = microtime(true) - $phaseStartTime;
            $this->logger->info(sprintf("✓ PHASE 5: Deactivated %d orphaned categories [%.2fs]", $orphanedCount, $phase5Time));
        }

        $totalTime = microtime(true) - $syncStartTime->getTimestamp() - $syncStartTime->format('u') / 1000000;
        $this->logger->info(sprintf("✓ TOTAL SYNC TIME: %.2fs (Phase1: %.2fs, Phase2: %.2fs, Phase3: %.2fs, Phase4: %.2fs, Phase5: %.2fs)",
            $totalTime, $phase1Time, $phase2Time, $phase3Time, $phase4Time, $phase5Time));

        return [
            'success' => true,
            'total' => $inserted + $updated,
            'inserted' => $inserted,
            'updated' => $updated,
            'orphaned' => $orphanedCount,
            'orphan_sweep_skipped' => $sweepSkipped,
            'errors' => $errors,
            'language' => $language,
        ];
    }

    /**
     * Bulk save categories for better performance
     * Saves all categories in one transaction
     *
     * @param array $categories Array of category data
     * @param string $language Language code
     * @return array Result with counts
     */
    private function bulkSaveCategories(array $categories, string $language): array
    {
        if (empty($categories)) {
            return ['inserted' => 0, 'updated' => 0, 'errors' => []];
        }

        $inserted = 0;
        $updated = 0;
        $errors = [];

        // Start transaction for atomic operation
        $this->db->execute('START TRANSACTION');

        try {
            // Prepare bulk insert/update data
            $insertData = [];
            $updateIds = [];

            foreach ($categories as $category) {
                // Check if exists
                $existingId = $this->getInPostCategoryMappingId($category['id'], $language);

                // Raw values - escaped once in performBulkUpsert()
                $data = [
                    'inpost_category_id' => (string) $category['id'],
                    // Support both snake_case (parent_id) and camelCase (parentId) from API
                    'inpost_parent_id' => $category['parent_id'] ?? $category['parentId'] ?? null,
                    'name' => (string) ($category['name'] ?? ''),
                    'description' => $category['description'] ?? null,
                    'level' => (int)($category['level'] ?? 0),
                    'is_leaf' => (int)($category['leaf'] ?? 0),
                    'is_active' => 1,
                    'sort_order' => (int)($category['sort_order'] ?? 0),
                    'language' => $language,
                    'synced_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ];

                if ($existingId) {
                    // Update existing
                    $updateIds[] = $existingId;
                    $data['entity_id'] = $existingId;
                    $insertData[] = $data;
                    $updated++;
                } else {
                    // Insert new
                    $data['created_at'] = date('Y-m-d H:i:s');
                    $insertData[] = $data;
                    $inserted++;
                }
            }

            // Perform bulk upsert using INSERT ... ON DUPLICATE KEY UPDATE, in chunks
            // so a large tree does not exceed max_allowed_packet
            foreach (array_chunk($insertData, self::BULK_UPSERT_CHUNK_SIZE) as $chunk) {
                if (!$this->performBulkUpsert($chunk)) {
                    throw new \RuntimeException('Bulk category upsert failed: ' . $this->db->getMsgError());
                }
            }

            $this->db->execute('COMMIT');

            $this->logger->info("Bulk saved {$inserted} new and {$updated} existing categories");

        } catch (\Exception $e) {
            $this->db->execute('ROLLBACK');
            $this->logger->error("Bulk save failed: " . $e->getMessage());
            throw $e;
        }

        return [
            'inserted' => $inserted,
            'updated' => $updated,
            'errors' => $errors
        ];
    }

    /**
     * Perform bulk upsert operation
     *
     * @param array $data Data to insert/update
     * @return bool
     */
    private function performBulkUpsert(array $data): bool
    {
        if (empty($data)) {
            return true;
        }

        // Build bulk insert query with ON DUPLICATE KEY UPDATE
        $values = [];
        foreach ($data as $row) {
            $valueSet = [];
            $valueSet[] = isset($row['entity_id']) ? (int)$row['entity_id'] : 'NULL';
            $valueSet[] = "'" . pSQL($row['inpost_category_id']) . "'";
            $valueSet[] = isset($row['inpost_parent_id']) && $row['inpost_parent_id'] ? "'" . pSQL($row['inpost_parent_id']) . "'" : 'NULL';
            $valueSet[] = isset($row['parent_id']) ? (int)$row['parent_id'] : 'NULL';
            $valueSet[] = "'" . pSQL($row['name']) . "'";
            $valueSet[] = isset($row['description']) && $row['description'] ? "'" . pSQL($row['description']) . "'" : 'NULL';
            $valueSet[] = (int)$row['level'];
            $valueSet[] = (int)$row['is_leaf'];
            $valueSet[] = (int)$row['is_active'];
            $valueSet[] = (int)$row['sort_order'];
            $valueSet[] = "'" . pSQL($row['language']) . "'";
            $valueSet[] = "'" . $row['synced_at'] . "'";
            $valueSet[] = "'" . $row['updated_at'] . "'";
            $valueSet[] = isset($row['created_at']) ? "'" . $row['created_at'] . "'" : "'" . date('Y-m-d H:i:s') . "'";

            $values[] = '(' . implode(',', $valueSet) . ')';
        }

        $sql = "INSERT INTO {$this->tablePrefix}inpost_category_mapping
                (entity_id, inpost_category_id, inpost_parent_id, parent_id, name, description,
                 level, is_leaf, is_active, sort_order, language, synced_at, updated_at, created_at)
                VALUES " . implode(',', $values) . "
                ON DUPLICATE KEY UPDATE
                    inpost_parent_id = VALUES(inpost_parent_id),
                    parent_id = VALUES(parent_id),
                    name = VALUES(name),
                    description = VALUES(description),
                    level = VALUES(level),
                    is_leaf = VALUES(is_leaf),
                    is_active = VALUES(is_active),
                    sort_order = VALUES(sort_order),
                    synced_at = VALUES(synced_at),
                    updated_at = VALUES(updated_at)";

        return $this->db->execute($sql);
    }

    /**
     * Update all parent references based on InPost parent IDs
     * This fixes parent-child relationships after bulk save
     *
     * @param string $language Language code
     * @return int Number of updated references
     */
    private function updateParentReferences(string $language): int
    {
        $sql = "
            UPDATE {$this->tablePrefix}inpost_category_mapping child
            INNER JOIN {$this->tablePrefix}inpost_category_mapping parent
                ON child.inpost_parent_id = parent.inpost_category_id
                AND child.language = parent.language
            SET child.parent_id = parent.entity_id
            WHERE child.language = '" . pSQL($language) . "'
              AND child.inpost_parent_id IS NOT NULL
              AND (child.parent_id IS NULL OR child.parent_id != parent.entity_id)
        ";

        $this->db->execute($sql);
        $affected = $this->db->Affected_Rows();

        $this->logger->info("Updated {$affected} parent references");

        return $affected;
    }

    /**
     * Collect categories from tree structure (without saving)
     *
     * @param array $category Category data
     * @param array &$allCategories All collected categories
     * @param array &$leafCategories Leaf categories
     * @param string|null $parentId Parent InPost ID
     * @param int $level Current level
     */
    private function collectCategoriesFromTree(
        array $category,
        array &$allCategories,
        array &$leafCategories,
        ?string $parentId,
        int $level
    ): void {
        // Add parent_id to category data
        if ($parentId !== null) {
            $category['parent_id'] = $parentId;
        }

        $category['level'] = $level;
        $allCategories[] = $category;

        // Check if leaf
        $isLeaf = $category['leaf'] ?? false;
        if ($isLeaf) {
            $leafCategories[] = $category['id'];
        }

        // Process children
        if (!empty($category['children'])) {
            foreach ($category['children'] as $child) {
                $this->collectCategoriesFromTree($child, $allCategories, $leafCategories, $category['id'], $level + 1);
            }
        }
    }

    /**
     * Deactivate categories not present in current sync (orphan detection)
     *
     * @param string $language Language code
     * @param \DateTime $syncStartTime Sync start time
     * @return int Number of deactivated categories
     */
    private function deactivateOrphanCategories(string $language, \DateTime $syncStartTime): int
    {
        $syncTime = $syncStartTime->format('Y-m-d H:i:s');

        // A row not touched by this sync run is an orphan. Include NULL
        // watermarks (rows created before synced_at was populated), otherwise
        // `synced_at < ...` evaluates to NULL and they are never swept.
        $sql = "
            UPDATE {$this->tablePrefix}inpost_category_mapping
            SET is_active = 0, updated_at = NOW()
            WHERE language = '" . pSQL($language) . "'
              AND (synced_at < '" . pSQL($syncTime) . "' OR synced_at IS NULL)
              AND is_active = 1
        ";

        try {
            $this->db->execute($sql);
            $affected = $this->db->Affected_Rows();
        } catch (\Exception $e) {
            // A schema drift must not abort the whole sync after phases 1-4
            // already committed. Mirror the attribute-side resilience.
            $this->logger->warning('Orphan category deactivation failed (schema may need update): ' . $e->getMessage());
            return 0;
        }

        if ($affected > 0) {
            $this->logger->info("Deactivated {$affected} orphaned categories", [
                'language' => $language,
                'watermark' => $syncTime,
            ]);
        }

        return $affected;
    }

    /**
     * Category links whose target mapping is inactive (orphaned in InPost).
     * Used by the admin Category Mapping list to warn the merchant.
     *
     * @return array<int,array<string,mixed>>
     */
    public function getLinksPointingAtInactiveCategories(int $shopId): array
    {
        $sql = "
            SELECT cl.link_id, cl.prestashop_category_id, cm.inpost_category_id, cm.name, cm.language
            FROM {$this->tablePrefix}inpost_category_link cl
            INNER JOIN {$this->tablePrefix}inpost_category_mapping cm ON cl.mapping_id = cm.entity_id
            WHERE cm.is_active = 0
              AND (cl.shop_id = " . (int) $shopId . " OR cl.shop_id IS NULL)
        ";

        return $this->db->executeS($sql) ?: [];
    }

    /**
     * Recursively fetch categories beyond initial API depth (unlimited)
     *
     * @param string $language
     * @param int $currentDepth Current depth level (e.g., 3)
     * @param int $initialCount Initial count for cumulative tracking
     * @param \DateTime $syncStartTime Sync start time to detect stale children
     * @return array Counts of inserted/updated/errors and cumulative total
     */
    private function fetchIncompleteCategoriesRecursively(
        string $language,
        int $currentDepth,
        int $initialCount,
        \DateTime $syncStartTime
    ): array {
        $this->logger->info("Starting unlimited recursive fetch from depth {$currentDepth}");

        $inserted = 0;
        $updated = 0;
        $errors = [];
        $level = $currentDepth;
        $cumulativeCount = $initialCount;

        // Iterate through levels until no more incomplete categories
        while (true) {
            // Find categories at this level that might have children (or stale children)
            $incompleteCategories = $this->findIncompleteCategoriesByLevel($language, $level, $syncStartTime);

            if (empty($incompleteCategories)) {
                $this->logger->info(sprintf(
                    'No incomplete categories found at level %d, stopping recursion (processed %d levels total)',
                    $level,
                    $level - $currentDepth
                ));
                break;
            }

            $this->logger->info("Found " . count($incompleteCategories) . " incomplete categories at level {$level}");

            $levelFetched = 0;
            $levelUpdated = 0;

            // Fetch children for each incomplete category
            foreach ($incompleteCategories as $category) {
                try {
                    // KEY: Fetch this specific category with depth=3 from this point
                    $response = $this->categoryApiService->getCategory(
                        $category['inpost_category_id'],
                        $language,
                        3 // depth from this category
                    );

                    // Check API response success
                    if (!$response['success']) {
                        throw new \Exception('API returned error: ' . ($response['error'] ?? 'unknown'));
                    }

                    // Extract category data and convert SDK object to array
                    $categoryObject = $response['data'];
                    $categoryData = is_object($categoryObject) 
                        ? json_decode(json_encode($categoryObject), true) 
                        : $categoryObject;

                    // Extract children from the category data
                    $children = $categoryData['children'] ?? [];
                    if (empty($children)) {
                        // InPost API inconsistency: is_leaf=0 but no children returned
                        $this->logger->warning(sprintf(
                            'InPost API data inconsistency detected: Category "%s" (ID: %s) at level %d has is_leaf=0 (indicating children) but API returned no children. Marking as true leaf.',
                            $category['name'],
                            $category['inpost_category_id'],
                            $category['level']
                        ));

                        // Update category as true leaf
                        $this->updateCategoryAsLeaf((int)$category['entity_id']);
                        $levelUpdated++;
                    } else {
                        $childResults = $this->saveChildCategories(
                            $children,
                            $language,
                            $category['inpost_category_id'], // parent InPost ID
                            $level + 1
                        );

                        $inserted += $childResults['inserted'];
                        $updated += $childResults['updated'];
                        $levelFetched++;

                        $this->logger->info("Fetched " . count($children) . " children for category " . $category['name']);
                    }
                } catch (\Exception $e) {
                    $errors[] = [
                        'category_id' => $category['inpost_category_id'],
                        'error' => $e->getMessage()
                    ];
                    $this->logger->error("Failed to fetch children for category {$category['inpost_category_id']}: " . $e->getMessage());
                }
            }

            $cumulativeCount += count($incompleteCategories);

            $this->logger->info(sprintf(
                'Level %d processing complete: %d categories with children fetched, %d marked as true leaf (total processed: %d, cumulative: %d)',
                $level,
                $levelFetched,
                $levelUpdated,
                count($incompleteCategories),
                $cumulativeCount
            ));

            // Move to next level
            $level++;
        }

        return [
            'inserted' => $inserted,
            'updated' => $updated,
            'errors' => $errors,
            'cumulative_count' => $cumulativeCount
        ];
    }

    /**
     * Find categories at specific level that have is_leaf=false but no/stale children in DB
     *
     * @param string $language
     * @param int $level
     * @param \DateTime $syncStartTime Sync start time to detect stale children
     * @return array Categories that need children fetched
     */
    private function findIncompleteCategoriesByLevel(string $language, int $level, \DateTime $syncStartTime): array
    {
        $syncTime = $syncStartTime->format('Y-m-d H:i:s');

        $sql = "
            SELECT cm.entity_id, cm.inpost_category_id, cm.name, cm.level
            FROM {$this->tablePrefix}inpost_category_mapping cm
            WHERE cm.language = '" . pSQL($language) . "'
              AND cm.level = " . (int)$level . "
              AND cm.is_leaf = 0
              AND (
                  -- No children exist at all
                  NOT EXISTS (
                      SELECT 1
                      FROM {$this->tablePrefix}inpost_category_mapping child
                      WHERE child.parent_id = cm.entity_id
                  )
                  -- OR children exist but are stale (synced before current sync started)
                  OR EXISTS (
                      SELECT 1
                      FROM {$this->tablePrefix}inpost_category_mapping child
                      WHERE child.parent_id = cm.entity_id
                        AND child.synced_at < '" . pSQL($syncTime) . "'
                  )
              )
        ";

        return $this->db->executeS($sql) ?: [];
    }

    /**
     * Recursively collect and save categories from API tree response (Magento-style)
     * Saves each category immediately before recursing to children
     *
     * @param array $categoryData Category with potential children
     * @param string $language
     * @param array &$leafCategories Output: only leaf categories (for attribute sync later)
     * @param int|null $parentEntityId Parent's database entity_id (not InPost UUID)
     * @param int $level Current tree level
     * @return array ['inserted' => int, 'updated' => int, 'errors' => array]
     */
    private function collectAndSaveCategoriesFromTree(
        array $categoryData,
        string $language,
        array &$leafCategories,
        ?int $parentEntityId = null,
        ?string $parentInpostId = null,
        int $level = 0
    ): array {
        $inserted = 0;
        $updated = 0;
        $errors = [];

        // Determine if this category is a leaf
        $isLeaf = empty($categoryData['children']);

        // Prepare category record (keep parent_id for inpost_parent_id mapping)
        $categoryRecord = [
            'id' => $categoryData['id'],
            'name' => $categoryData['name'] ?? '',
            'description' => $categoryData['description'] ?? null,
            'parent_id' => $parentInpostId, // Use passed parent UUID
            'level' => $level,
            'is_leaf' => $isLeaf ? 1 : 0,
            'language' => $language,
        ];

        // Track leaf categories for attribute sync
        if ($isLeaf) {
            $leafCategories[] = $categoryRecord;
        }

        // Save this category BEFORE recursing to children (ensures parent exists first)
        $currentEntityId = false;
        try {
            $existed = $this->inpostCategoryExists($categoryData['id'], $language);
            // Pass parent's entity_id and get back this category's entity_id
            $currentEntityId = $this->upsertInPostCategory($categoryRecord, $language, $parentEntityId);

            if ($currentEntityId === false) {
                throw new \Exception("Failed to save category");
            }

            if ($existed) {
                $updated++;
            } else {
                $inserted++;
            }
        } catch (\Exception $e) {
            $errors[] = ['category_id' => $categoryData['id'], 'error' => $e->getMessage()];
            $this->logger->error("Failed to sync category {$categoryData['id']}: " . $e->getMessage());
        }

        // Recursively process children (parent is now saved, pass its entity_id to children)
        if (!empty($categoryData['children']) && $currentEntityId !== false) {
            foreach ($categoryData['children'] as $child) {
                $childResult = $this->collectAndSaveCategoriesFromTree(
                    $child,
                    $language,
                    $leafCategories,
                    $currentEntityId, // Pass parent's database entity_id
                    $categoryData['id'], // Pass parent's InPost UUID
                    $level + 1
                );

                $inserted += $childResult['inserted'];
                $updated += $childResult['updated'];
                $errors = array_merge($errors, $childResult['errors']);
            }
        }

        return ['inserted' => $inserted, 'updated' => $updated, 'errors' => $errors];
    }

    /**
     * Save children categories recursively (reuses collectAndSaveCategoriesFromTree)
     *
     * @param array $children Array of child category data
     * @param string $language
     * @param string $parentInpostId Parent InPost category ID (UUID)
     * @param int $level Tree level
     * @return array Counts
     */
    private function saveChildCategories(
        array $children,
        string $language,
        string $parentInpostId,
        int $level
    ): array {
        $inserted = 0;
        $updated = 0;
        $leafCategories = []; // Not used here, but required by the method

        // Look up parent's entity_id from its InPost UUID
        $parentEntityId = $this->getInPostCategoryMappingId($parentInpostId, $language);

        foreach ($children as $child) {
            $result = $this->collectAndSaveCategoriesFromTree(
                $child,
                $language,
                $leafCategories,
                $parentEntityId, // Pass parent's database entity_id
                $parentInpostId, // Pass parent's InPost UUID
                $level
            );

            $inserted += $result['inserted'];
            $updated += $result['updated'];
        }

        return ['inserted' => $inserted, 'updated' => $updated];
    }

    /**
     * Mark category as true leaf (no children)
     *
     * @param int $entityId Category database entity_id
     */
    private function updateCategoryAsLeaf(int $entityId): void
    {
        $this->db->update(
            'inpost_category_mapping',
            ['is_leaf' => 1],
            'entity_id = ' . (int)$entityId
        );

        $this->logger->info("Updated category {$entityId} as true leaf");
    }

    /**
     * Get all leaf categories from database
     *
     * Retrieves categories where is_leaf=1 (categories that can have products/attributes)
     * Used by attribute sync to fetch attributes per category from API
     *
     * @param string $language Language code
     * @return array Array of leaf category records with id, entity_id, is_leaf fields
     */
    public function getLeafCategories(string $language = 'pl'): array
    {
        $query = new \DbQuery();
        $query->select('entity_id, inpost_category_id as id, is_leaf')
            ->from('inpost_category_mapping')
            ->where('is_leaf = 1')
            ->where('is_active = 1')
            ->where(self::languageCondition($language));

        $results = $this->db->executeS($query) ?: [];

        $this->logger->info("Loaded leaf categories from database", [
            'count' => count($results),
            'language' => $language,
        ]);

        return $results;
    }
}
