<?php
/**
 * CategorySyncJob
 *
 * Cron job for synchronizing InPost categories and attributes.
 * Fetches categories hierarchy and attributes from InPost API.
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Service\Cron;

use Exception;
use Inpost\VonHalsky\Service\Mapping\AttributeMappingService;
use Inpost\VonHalsky\Service\Mapping\CategoryMappingService;
use Inpost\VonHalsky\Service\Util\ConfigurationService;
use Inpost\VonHalsky\Service\Util\LoggerService;

class CategorySyncJob extends AbstractCronJob
{
    /**
     * Job name
     */
    private const JOB_NAME = 'category_sync';

    /**
     * Default interval: 24 hours (once daily)
     */
    private const DEFAULT_INTERVAL = 86400;

    /**
     * Max recursion depth for category hierarchy
     */
    private const MAX_DEPTH = 5;

    /**
     * @var CategoryMappingService
     */
    private $categoryMappingService;

    /**
     * @var AttributeMappingService
     */
    private $attributeMappingService;

    /**
     * @var ConfigurationService
     */
    private $configurationService;

    /**
     * @var LoggerService
     */
    private $logger;

    /**
     * @param CategoryMappingService $categoryMappingService
     * @param AttributeMappingService $attributeMappingService
     * @param ConfigurationService $configurationService
     * @param LoggerService $logger
     */
    public function __construct(
        CategoryMappingService $categoryMappingService,
        AttributeMappingService $attributeMappingService,
        ConfigurationService $configurationService,
        LoggerService $logger
    ) {
        $this->categoryMappingService = $categoryMappingService;
        $this->attributeMappingService = $attributeMappingService;
        $this->configurationService = $configurationService;
        $this->logger = $logger;
        $this->interval = self::DEFAULT_INTERVAL;
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
        $this->logger->info('[InPost-CategorySyncJob] Starting category & attributes synchronization');

        try {
            $language = $this->configurationService->getDefaultLanguage();

            // Phase 1: Sync categories recursively
            $this->logger->info('[InPost-CategorySyncJob] Syncing categories', ['language' => $language]);

            $categoryResult = $this->categoryMappingService->syncInPostCategoriesRecursive(
                $language,
                self::MAX_DEPTH
            );

            $this->logger->info('[InPost-CategorySyncJob] Categories synced', [
                'total' => $categoryResult['total'],
                'inserted' => $categoryResult['inserted'],
                'updated' => $categoryResult['updated'],
                'orphaned' => $categoryResult['orphaned'] ?? 0,
                'orphan_sweep_skipped' => $categoryResult['orphan_sweep_skipped'] ?? false,
                'errors' => count($categoryResult['errors']),
            ]);

            // GOKINT-929: after a real orphan sweep, drop attribute links and
            // dictionary options left behind by deactivated categories so they
            // are not picked up as attributes of a dead category.
            $orphanedLinks = 0;
            $orphanedDictionaryOptions = 0;
            if (!($categoryResult['orphan_sweep_skipped'] ?? false)) {
                $orphanedLinks = $this->attributeMappingService->cleanOrphanedCategoryAttributeLinks($language);
                $orphanedDictionaryOptions = $this->attributeMappingService->cleanOrphanedDictionaryOptions($language);
                $this->logger->info('[InPost-CategorySyncJob] Orphan attribute cleanup', [
                    'links_removed' => $orphanedLinks,
                    'dictionary_options_removed' => $orphanedDictionaryOptions,
                ]);
            }

            // Phase 2: Sync attributes for leaf categories
            $this->logger->info('[InPost-CategorySyncJob] Syncing attributes');

            $leafCategories = $this->categoryMappingService->getLeafCategories($language);

            $attributeResult = [
                'processed' => 0,
                'linked' => 0,
                'dictionaries' => 0,
                'errors' => 0,
            ];

            if (!empty($leafCategories)) {
                $attributeResult = $this->attributeMappingService->syncCategoryAttributes(
                    $leafCategories,
                    $language
                );

                $this->logger->info('[InPost-CategorySyncJob] Attributes synced', [
                    'processed' => $attributeResult['processed'],
                    'linked' => $attributeResult['linked'],
                    'dictionaries' => $attributeResult['dictionaries'] ?? 0,
                    'errors' => $attributeResult['errors'],
                ]);
            }

            $this->logger->info('[InPost-CategorySyncJob] Synchronization completed');

            return [
                'success' => true,
                'categories_total' => $categoryResult['total'],
                'categories_inserted' => $categoryResult['inserted'],
                'categories_updated' => $categoryResult['updated'],
                'categories_orphaned' => $categoryResult['orphaned'] ?? 0,
                'orphan_attribute_links_removed' => $orphanedLinks,
                'orphan_dictionary_options_removed' => $orphanedDictionaryOptions,
                'category_errors' => count($categoryResult['errors']),
                'attributes_processed' => $attributeResult['processed'],
                'attributes_linked' => $attributeResult['linked'],
                'dictionaries' => $attributeResult['dictionaries'] ?? 0,
                'attribute_errors' => $attributeResult['errors'],
                'message' => sprintf(
                    'Categories: %d total (%d inserted, %d updated). Attributes: %d linked from %d categories.',
                    $categoryResult['total'],
                    $categoryResult['inserted'],
                    $categoryResult['updated'],
                    $attributeResult['linked'],
                    $attributeResult['processed']
                ),
            ];
        } catch (Exception $e) {
            $this->logger->error('[InPost-CategorySyncJob] Synchronization failed', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}
