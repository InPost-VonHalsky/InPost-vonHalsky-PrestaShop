<?php
/**
 * SyncCategoriesCommand
 *
 * Console command for synchronizing InPost categories from the API.
 * Supports language selection, clean mode, and dry-run for testing.
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Command;

use Inpost\VonHalsky\Service\Api\CategoryApiService;
use Inpost\VonHalsky\Service\Mapping\CategoryMappingService;
use Inpost\VonHalsky\Service\Mapping\AttributeMappingService;
use Inpost\VonHalsky\Service\Util\ConfigurationService;
use Inpost\VonHalsky\Service\Util\LoggerService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class SyncCategoriesCommand extends Command
{
    protected static $defaultName = 'inpost:categories:sync';

    private CategoryApiService $categoryApiService;
    private CategoryMappingService $categoryMappingService;
    private AttributeMappingService $attributeMappingService;
    private ConfigurationService $configurationService;
    private LoggerService $logger;

    public function __construct(
        CategoryApiService $categoryApiService,
        CategoryMappingService $categoryMappingService,
        AttributeMappingService $attributeMappingService,
        ConfigurationService $configurationService,
        LoggerService $logger
    ) {
        parent::__construct();
        $this->categoryApiService = $categoryApiService;
        $this->categoryMappingService = $categoryMappingService;
        $this->attributeMappingService = $attributeMappingService;
        $this->configurationService = $configurationService;
        $this->logger = $logger;
    }

    protected function configure(): void
    {
        $this
            ->setName('inpost:categories:sync')
            ->setDescription('Synchronize InPost categories and attributes from API with full recursive depth')
            ->setHelp('This command fetches categories and their attributes from InPost API with full recursive depth (depth=5) and stores them locally for mapping purposes.')
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Preview changes without saving to database'
            )
            ->addOption(
                'skip-attributes',
                null,
                InputOption::VALUE_NONE,
                'Skip attribute sync for leaf categories (categories only)'
            )
            ->addOption(
                'debug',
                null,
                InputOption::VALUE_NONE,
                'Enable verbose debug output with detailed progress information'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $language = $this->configurationService->getDefaultLanguage();
        $dryRun = $input->getOption('dry-run');
        $skipAttributes = $input->getOption('skip-attributes');
        $maxDepth = 5; // Always use max depth
        $debug = $input->getOption('debug');

        $io->title('InPost Categories & Attributes Synchronization');

        if ($dryRun) {
            $io->note('Running in DRY-RUN mode - no changes will be saved');
        }

        if ($skipAttributes) {
            $io->note('Attributes sync DISABLED - categories only');
        }

        if ($debug) {
            $io->note('DEBUG mode enabled - verbose output');
        }

        try {
            // Dry-run mode: fetch categories for hierarchy preview only
            if ($dryRun) {
                $io->text(sprintf('Fetching categories for language: <info>%s</info>', $language));
                $response = $this->categoryApiService->listCategories($language);
                if (!$response['success']) {
                    throw new \RuntimeException($response['error'] ?? 'Failed to fetch categories');
                }
                $data = $response['data'];
                $items = is_object($data) && method_exists($data, 'getData') ? ($data->getData() ?? []) : ($data['items'] ?? $data ?? []);
                $categories = $this->categoryApiService->convertToArrays($items);

                if (empty($categories)) {
                    $io->warning('No categories returned from API');
                    return 0; // Command::SUCCESS
                }

                $io->text(sprintf('Retrieved <info>%d</info> categories from API', count($categories)));
                $hierarchy = $this->buildHierarchy($categories);

                $io->section('Category Hierarchy Preview:');
                $this->displayHierarchy($io, $hierarchy);
                $io->success('Dry-run completed. Use without --dry-run to save changes.');
                return 0; // Command::SUCCESS
            }

            // Production mode: use recursive sync directly (avoids duplicate API calls)
            if ($debug) {
                $io->section('Phase 1: Category Synchronization (Recursive)');
                $io->text(sprintf('Language: <info>%s</info>', $language));
                $io->text(sprintf('Max depth: <info>%d</info> (full recursive fetch)', $maxDepth));
                $io->text('Starting recursive category sync...');
            } else {
                $io->text('Syncing categories with full recursive depth...');
            }

            $categoryResult = $this->categoryMappingService->syncInPostCategoriesRecursive($language, $maxDepth);

            $sweepSkipped = $categoryResult['orphan_sweep_skipped'] ?? false;
            $orphanedLabel = $sweepSkipped ? 'skipped' : (string) ($categoryResult['orphaned'] ?? 0);

            // GOKINT-929: drop attribute links/dictionary options orphaned by a
            // real category sweep.
            $orphanedLinks = 0;
            $orphanedDictionaryOptions = 0;
            if (!$sweepSkipped) {
                $orphanedLinks = $this->attributeMappingService->cleanOrphanedCategoryAttributeLinks($language);
                $orphanedDictionaryOptions = $this->attributeMappingService->cleanOrphanedDictionaryOptions($language);
            }

            if ($debug) {
                $io->listing([
                    sprintf('Total categories: %d', $categoryResult['total']),
                    sprintf('Newly inserted: %d', $categoryResult['inserted']),
                    sprintf('Updated existing: %d', $categoryResult['updated']),
                    sprintf('Deactivated orphans: %s', $orphanedLabel),
                    sprintf('Orphan attribute links removed: %d', $orphanedLinks),
                    sprintf('Orphan dictionary options removed: %d', $orphanedDictionaryOptions),
                    sprintf('Errors: %d', count($categoryResult['errors'])),
                ]);
            }

            $io->success(sprintf(
                'Categories synced: %d inserted, %d updated, %s orphaned, %d errors',
                $categoryResult['inserted'],
                $categoryResult['updated'],
                $orphanedLabel,
                count($categoryResult['errors'])
            ));

            // Sync attributes (unless --skip-attributes)
            // Note: Fetch leaf categories from database, then sync attributes via per-category API endpoint
            $attributeResult = null;
            if (!$skipAttributes) {
                if ($debug) {
                    $io->section('Phase 2: Attribute Synchronization (Per-Category)');
                }
                $io->text('Syncing category attributes...');

                // Load leaf categories from database (categories where is_leaf=1)
                // syncCategoryAttributes() will fetch attributes per category from API
                $categories = $this->categoryMappingService->getLeafCategories($language);

                if ($debug) {
                    $io->text(sprintf('Found <info>%d</info> leaf categories to process', count($categories)));
                }

                if (!empty($categories)) {
                    // Show progress bar for large operations
                    $progressBar = null;
                    $progressCallback = null;
                    
                    if (!$debug && count($categories) > 100) {
                        $progressBar = $io->createProgressBar(count($categories));
                        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory:6s%');
                        $progressBar->start();
                        
                        // Create callback to advance progress bar
                        $progressCallback = function($current) use ($progressBar) {
                            $progressBar->setProgress($current);
                        };
                    }

                    $attributeResult = $this->attributeMappingService->syncCategoryAttributes($categories, $language, $progressCallback);

                    if ($progressBar) {
                        $progressBar->finish();
                        $io->newLine(2);
                    }

                    if ($debug) {
                        $io->listing([
                            sprintf('Leaf categories processed: %d', $attributeResult['processed']),
                            sprintf('Attributes linked: %d', $attributeResult['linked']),
                            sprintf('Dictionary values synced: %d', $attributeResult['dictionaries'] ?? 0),
                            sprintf('Errors: %d', $attributeResult['errors']),
                        ]);
                    }

                    $io->success(sprintf(
                        'Attributes synced: %d categories, %d attributes, %d dictionaries, %d errors',
                        $attributeResult['processed'],
                        $attributeResult['linked'],
                        $attributeResult['dictionaries'] ?? 0,
                        $attributeResult['errors']
                    ));
                } else {
                    $io->warning('No leaf categories found for attribute sync');
                    $attributeResult = ['processed' => 0, 'linked' => 0, 'dictionaries' => 0, 'errors' => 0];
                }
            } else {
                $io->text('<comment>Skipped attribute sync (--skip-attributes)</comment>');
            }

            // Summary
            $io->section('Synchronization Summary:');
            $summaryData = [
                ['Categories fetched', $categoryResult['total']],
                ['Categories inserted', $categoryResult['inserted']],
                ['Categories updated', $categoryResult['updated']],
                ['Category errors', count($categoryResult['errors'])],
            ];

            if (!$skipAttributes && $attributeResult) {
                $summaryData[] = ['Attributes linked', $attributeResult['linked']];
                $summaryData[] = ['Dictionary values', $attributeResult['dictionaries'] ?? 0];
                $summaryData[] = ['Attribute errors', $attributeResult['errors']];
            }

            $io->table(['Metric', 'Count'], $summaryData);

            $this->logger->info('Categories & attributes synchronized successfully', [
                'language' => $language,
                'categories' => $categoryResult,
                'attributes' => $attributeResult,
            ]);

            return 0; // Command::SUCCESS (Symfony 5.1+ constant, using literal for compatibility)
        } catch (\Exception $e) {
            $io->error(sprintf('Synchronization failed: %s', $e->getMessage()));

            $this->logger->error('Categories synchronization failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return 1; // Command::FAILURE
        }
    }

    /**
     * Build hierarchical structure from flat category array
     *
     * @param array $categories Flat category array
     * @return array Hierarchical structure
     */
    private function buildHierarchy(array $categories): array
    {
        $tree = [];
        $lookup = [];

        // First pass: create lookup
        foreach ($categories as $category) {
            $lookup[$category['id']] = $category;
            $lookup[$category['id']]['children'] = [];
        }

        // Second pass: build tree
        foreach ($categories as $category) {
            if (isset($category['parent_id']) && isset($lookup[$category['parent_id']])) {
                $lookup[$category['parent_id']]['children'][] = &$lookup[$category['id']];
            } else {
                $tree[] = &$lookup[$category['id']];
            }
        }

        return $tree;
    }

    /**
     * Display category hierarchy
     *
     * @param SymfonyStyle $io
     * @param array $categories
     * @param int $level
     */
    private function displayHierarchy(SymfonyStyle $io, array $categories, int $level = 0): void
    {
        foreach ($categories as $category) {
            $indent = str_repeat('  ', $level);
            $leaf = empty($category['children']) ? ' <comment>[LEAF]</comment>' : '';
            $io->text(sprintf('%s- %s (ID: %s)%s', $indent, $category['name'], $category['id'], $leaf));

            if (!empty($category['children'])) {
                $this->displayHierarchy($io, $category['children'], $level + 1);
            }
        }
    }

}
