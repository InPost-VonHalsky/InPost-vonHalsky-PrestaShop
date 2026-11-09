<?php
/**
 * AttributeMappingService
 *
 * Service for managing attribute mappings between PrestaShop and InPost.
 * Handles attribute synchronization, mapping storage, and dictionary management.
 * Works with multiple tables:
 * - inpost_attribute: InPost attributes synced from API
 * - inpost_category_attribute: Category-Attribute relationships
 * - inpost_attribute_dictionary: Dictionary options synced from API
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Service\Mapping;

use Inpost\VonHalsky\Service\Api\AttributeApiService;
use Inpost\VonHalsky\Service\Util\LoggerService;
use Db;
use DbQuery;
use Context;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Exception\RequestException;

class AttributeMappingService
{
    private AttributeApiService $attributeApiService;
    private LoggerService $logger;
    private Db $db;
    private string $tablePrefix;

    public function __construct(
        AttributeApiService $attributeApiService,
        LoggerService $logger
    ) {
        $this->attributeApiService = $attributeApiService;
        $this->logger = $logger;
        $this->db = Db::getInstance();
        $this->tablePrefix = _DB_PREFIX_;
    }

    /**
     * Sync category attributes by fetching per-category attribute relationships from API
     *
     * Fetches category-specific attribute relationships from /categories/{id}/attributes endpoint.
     * Links attributes to their respective categories in inpost_category_attribute table.
     * Only processes leaf categories (categories that can be assigned to products).
     * Attributes are auto-created from the response if they don't exist yet.
     *
     * @param array $categories Categories from database (with is_leaf, id fields)
     * @param string $language Language code
     * @param callable|null $progressCallback Optional callback to report progress (receives current count)
     * @return array Sync result with statistics
     * @throws \Exception
     */
    public function syncCategoryAttributes(array $categories, string $language = 'pl', ?callable $progressCallback = null): array
    {
        $this->logger->info("Syncing category-attribute relationships for language: {$language}");

        $stats = [
            'processed' => 0,
            'linked' => 0,
            'unlinked' => 0,
            'dictionaries' => 0,
            'errors' => 0,
        ];

        try {
            // Filter leaf categories and prepare category data upfront
            $leafCategories = [];
            foreach ($categories as $category) {
                if (empty($category['is_leaf'])) {
                    continue;
                }
                
                $categoryEntityId = $this->getCategoryMappingId($category['id'], $language);
                if (!$categoryEntityId) {
                    $this->logger->warning("Category not found in database", [
                        'category_id' => $category['id'],
                    ]);
                    $stats['errors']++;
                    continue;
                }
                
                $leafCategories[$category['id']] = [
                    'category' => $category,
                    'entity_id' => $categoryEntityId,
                ];
            }

            $this->logger->info("Starting async attribute sync", [
                'total_leaf_categories' => count($leafCategories),
                'concurrency' => 50,
            ]);

            // Get HTTP client for direct access
            $httpClient = $this->attributeApiService->getGuzzleClient();

            // Build requests generator for Guzzle Pool
            $attributeApiService = $this->attributeApiService;
            $requests = function() use ($leafCategories, $language, $attributeApiService) {
                foreach ($leafCategories as $categoryId => $data) {
                    $url = $attributeApiService->buildUrl("/v1/categories/{$categoryId}/attributes");
                    $headers = $attributeApiService->buildAuthHeaders([
                        'Accept-Language' => $language,
                    ]);
                    
                    yield $categoryId => new Request('GET', $url, $headers);
                }
            };

            // Per-run caches: an attribute (and its dictionary) is shared by many
            // categories - refresh each definition / identical option set only once.
            $runCache = ['attributes' => [], 'dictionaries' => []];

            // Execute Pool with concurrency=50
            $pool = new Pool($httpClient, $requests(), [
                'concurrency' => 50,
                'fulfilled' => function ($response, $categoryId) use (&$stats, &$runCache, $leafCategories, $language, $progressCallback) {
                    $stats['processed']++;
                    if ($progressCallback) {
                        $progressCallback($stats['processed']);
                    }

                    try {
                        $this->processCategoryAttributesResponse(
                            $response,
                            (string) $categoryId,
                            (int) $leafCategories[$categoryId]['entity_id'],
                            $language,
                            $stats,
                            $runCache
                        );
                    } catch (\Exception $e) {
                        $this->logger->error("Failed to process attributes response", [
                            'category_id' => $categoryId,
                            'error' => $e->getMessage(),
                        ]);
                        $stats['errors']++;
                    }
                },
                'rejected' => function ($reason, $categoryId) use (&$stats, $progressCallback) {
                    $stats['processed']++;
                    $stats['errors']++;
                    if ($progressCallback) {
                        $progressCallback($stats['processed']);
                    }

                    $this->logger->error("Failed to fetch attributes for category", [
                        'category_id' => $categoryId,
                        'error' => $reason instanceof RequestException ? $reason->getMessage() : (string) $reason,
                    ]);
                },
            ]);

            // Wait for all requests to complete
            $promise = $pool->promise();
            $promise->wait();

            $result = [
                'success' => true,
                'processed' => $stats['processed'],
                'linked' => $stats['linked'],
                'unlinked' => $stats['unlinked'],
                'dictionaries' => $stats['dictionaries'],
                'errors' => $stats['errors'],
                'language' => $language,
            ];

            $this->logger->info("Category attributes sync complete", $result);

            return $result;
        } catch (\Exception $e) {
            $this->logger->error("Failed to sync category attributes", [
                'error' => $e->getMessage(),
                'language' => $language,
            ]);

            throw $e;
        }
    }

    /**
     * Upsert the attributes of one category from its /attributes response, link them
     * and drop links to attributes the category no longer has.
     *
     * @param \Psr\Http\Message\ResponseInterface $response
     * @param array $stats Run statistics (updated)
     * @param array $runCache Per-run caches 'attributes' / 'dictionaries' (updated)
     * @throws \Exception On an unreadable response
     */
    private function processCategoryAttributesResponse(
        $response,
        string $categoryId,
        int $categoryEntityId,
        string $language,
        array &$stats,
        array &$runCache
    ): void {
        $content = $response->getBody()->getContents();
        $attributesResponse = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception(sprintf(
                'Invalid JSON response: %s (HTTP %d, body: "%s")',
                json_last_error_msg(),
                $response->getStatusCode(),
                mb_substr(trim($content), 0, 200)
            ));
        }

        // Handle response structure: {data: [...]} or direct array
        $attributes = $attributesResponse['data'] ?? $attributesResponse ?? [];

        // Attribute entity IDs present in this response - links to anything
        // else were removed from the category in InPost and get dropped below.
        $currentAttributeEntityIds = [];
        $errorsBefore = $stats['errors'];

        foreach ($attributes as $attrRelation) {
            $attributeId = $attrRelation['attribute_id'] ?? $attrRelation['id'] ?? null;
            try {
                if (!$attributeId) {
                    $this->logger->error("Missing attribute_id in API response", [
                        'category_id' => $categoryId,
                        'response' => $attrRelation,
                    ]);
                    $stats['errors']++;
                    continue;
                }

                $attributeEntityId = $this->resolveAttributeEntityId($attrRelation, (string) $attributeId, $language, $runCache);
                if (!$attributeEntityId) {
                    $this->logger->error("Failed to create attribute", [
                        'attribute_id' => $attributeId,
                        'category_id' => $categoryId,
                    ]);
                    $stats['errors']++;
                    continue;
                }

                $this->linkCategoryAttribute($categoryEntityId, $attributeEntityId, (bool) ($attrRelation['required'] ?? false));
                $currentAttributeEntityIds[] = $attributeEntityId;
                $stats['linked']++;

                $stats['dictionaries'] += $this->syncDictionaryOnce(
                    $attributeEntityId,
                    $attrRelation['dictionary']['options'] ?? [],
                    $language,
                    $runCache
                );
            } catch (\Exception $e) {
                $this->logger->error("Failed to link attribute to category", [
                    'category_id' => $categoryId,
                    'attribute_id' => $attributeId ?? 'unknown',
                    'error' => $e->getMessage(),
                ]);
                $stats['errors']++;
            }
        }

        // Only prune when every attribute was processed - a failed one
        // would otherwise lose its (still valid) link
        if ($stats['errors'] === $errorsBefore) {
            $stats['unlinked'] += $this->removeStaleCategoryAttributeLinks($categoryEntityId, $currentAttributeEntityIds);
        }
    }

    /**
     * Entity id of an attribute; creates it or refreshes its definition (name, type,
     * expected value) on first sight in this run.
     *
     * @param array $runCache Per-run caches (updated)
     */
    private function resolveAttributeEntityId(array $attrRelation, string $attributeId, string $language, array &$runCache): ?int
    {
        if (isset($runCache['attributes'][$attributeId])) {
            $attributeEntityId = $this->getInPostAttributeEntityId($attributeId, $language);
            if ($attributeEntityId) {
                return $attributeEntityId;
            }
        }

        $expectedValue = $attrRelation['expectedValue'] ?? ($attrRelation['expected_value'] ?? null);
        $expectedNorm = $expectedValue !== null ? strtoupper((string) $expectedValue) : null;

        $this->upsertInPostAttribute([
            'id' => $attributeId,
            'name' => $attrRelation['name'] ?? $attributeId,
            'description' => $attrRelation['description'] ?? null,
            'type' => $attrRelation['type'] ?? 'TEXT',
            'expectedValue' => $expectedValue,
            'required' => in_array($expectedNorm, ['ONE', 'AT_LEAST_ONE'], true),
        ], $language);
        $runCache['attributes'][$attributeId] = true;

        return $this->getInPostAttributeEntityId($attributeId, $language);
    }

    /**
     * Upsert dictionary options unless this exact option set was already saved for
     * the attribute in this run (options are only ever added, never removed).
     *
     * @param array $runCache Per-run caches (updated)
     * @return int Number of synced options
     */
    private function syncDictionaryOnce(int $attributeEntityId, array $options, string $language, array &$runCache): int
    {
        if (empty($options)) {
            return 0;
        }

        $key = $attributeEntityId . ':' . hash('xxh3', (string) json_encode($options));
        if (isset($runCache['dictionaries'][$key])) {
            return 0;
        }
        $runCache['dictionaries'][$key] = true;

        return $this->syncDictionaryValuesForAttribute($attributeEntityId, $options, $language);
    }

    /**
     * Get all InPost attributes
     *
     * @param string $language Language code
     * @param bool $requiredOnly Filter to required attributes only
     * @return array Array of attributes
     */
    public function getAllInPostAttributes(string $language = 'pl', bool $requiredOnly = false): array
    {
        $query = new DbQuery();
        $query->select('*')
            ->from('inpost_attribute')
            ->where("lang = '" . pSQL($language) . "'");

        if ($requiredOnly) {
            $query->where('required = 1');
        }

        $query->orderBy('name ASC');

        $results = $this->db->executeS($query);

        return $results ?: [];
    }

    /**
     * Get attributes for a specific InPost category
     *
     * Legacy helper using InPost category UUID. Prefer getAttributesForCategoryMappingId
     * when you already know the mapping entity ID.
     *
     * @param string $inpostCategoryId InPost category UUID
     * @param string $language Language code
     * @return array Array of raw DB rows
     */
    public function getAttributesForCategory(string $inpostCategoryId, string $language = 'pl'): array
    {
        $categoryEntityId = $this->getCategoryMappingId($inpostCategoryId, $language);
        if (!$categoryEntityId) {
            $this->logger->warning('Category not found in database', [
                'category_id' => $inpostCategoryId,
                'language' => $language
            ]);
            return [];
        }

        return $this->getAttributesForCategoryMappingId($categoryEntityId, $language);
    }

    /**
     * Get attributes for a specific category mapping (by mapping entity ID).
     *
     * This is used by the offer form to display attributes for the selected mapping.
     *
     * @param int $categoryEntityId inpost_category_mapping.entity_id
     * @param string $language Language code
     * @return array Normalized attribute definitions
     */
    public function getAttributesForCategoryMappingId(int $categoryEntityId, string $language = 'pl'): array
    {
        $query = new DbQuery();

        // No language in the context in CLI (cron) - fall back to the shop default
        $langId = (int) (Context::getContext()->language->id ?? \Configuration::get('PS_LANG_DEFAULT'));
        $query->select('a.attribute_id, a.inpost_attribute_id, a.name, a.type, a.description, a.expected_value, ca.entity_id AS category_attribute_id, ca.ps_source_type, ca.ps_source_id, a.required, COALESCE(fl.name, agl.name) AS ps_source_name')
            ->from('inpost_attribute', 'a')
            ->innerJoin(
                'inpost_category_attribute',
                'ca',
                'a.attribute_id = ca.inpost_attribute_id'
            )
            ->leftJoin(
                'feature_lang',
                'fl',
                "ca.ps_source_type = '" . AttributeSource::FEATURE . "' AND fl.id_feature = ca.ps_source_id AND fl.id_lang = " . $langId
            )
            ->leftJoin(
                'attribute_group_lang',
                'agl',
                "ca.ps_source_type = '" . AttributeSource::ATTRIBUTE_GROUP . "' AND agl.id_attribute_group = ca.ps_source_id AND agl.id_lang = " . $langId
            )
            ->where('ca.inpost_category_mapping_id = ' . (int)$categoryEntityId)
            ->where("a.lang = '" . pSQL($language) . "'")
            ->orderBy('a.required DESC, a.name ASC');

        $rows = $this->db->executeS($query) ?: [];

        $attributes = [];
        foreach ($rows as $row) {
            $sourceName = $row['ps_source_name'] ?? null;
            if ($row['ps_source_type'] === AttributeSource::PRODUCT_FIELD) {
                $field = ProductField::byId((int) $row['ps_source_id']);
                $sourceName = $field !== null
                    ? Context::getContext()->getTranslator()->trans($field['label'], [], 'Modules.Inpostvonhalsky.Admin')
                    : null;
            }

            $dictionaryValues = [];
            if ($row['type'] === 'DICTIONARY') {
                $dictionaryValues = $this->getDictionaryOptions($row['inpost_attribute_id'], $language);
            }

            $attributes[] = [
                'id' => (int) $row['attribute_id'],
                'code' => $row['inpost_attribute_id'],
                'label' => $row['name'],
                'type' => $row['type'],
                'required' => (bool) $row['required'],
                'description' => $row['description'] ?? null,
                'category_attribute_id' => (int) $row['category_attribute_id'],
                'expected_value' => $row['expected_value'] ?? null,
                'multiple' => self::allowsMultipleValues($row['expected_value'] ?? null),
                // A source whose feature / attribute group was deleted in PS has no name - treat as unmapped
                'ps_source_type' => $sourceName !== null ? $row['ps_source_type'] : null,
                'ps_source_id' => $sourceName !== null ? (int) $row['ps_source_id'] : null,
                'ps_source_name' => $sourceName,
                'dictionary_values' => array_map(static function (array $option): array {
                    return [
                        'value' => $option['value_code'],
                        'label' => $option['value_label'],
                    ];
                }, $dictionaryValues),
            ];
        }

        return $attributes;
    }

    /**
     * Get dictionary options for an attribute
     *
     * @param string $inpostAttributeId InPost attribute ID
     * @param string $language Language code
     * @return array Array of dictionary options
     */
    public function getDictionaryOptions(
        string $inpostAttributeId,
        string $language = 'pl'
    ): array {
        $attributeEntityId = $this->getInPostAttributeEntityId($inpostAttributeId, $language);
        if (!$attributeEntityId) {
            return [];
        }

        // Options InPost deactivated are not valid for new or updated offers
        $query = new DbQuery();
        $query->select('*')
            ->from('inpost_attribute_dictionary')
            ->where('attribute_id = ' . (int)$attributeEntityId)
            ->where('active = 1');

        $query->orderBy('value_label ASC');

        $results = $this->db->executeS($query);

        return $results ?: [];
    }

    /**
     * Whether the attribute accepts several values (InPost expectedValue
     * AT_LEAST_ONE or ANY; NULL_OR_ONE / ONE allow one)
     */
    public static function allowsMultipleValues(?string $expectedValue): bool
    {
        return in_array(strtoupper((string) $expectedValue), ['AT_LEAST_ONE', 'ANY'], true);
    }

    /**
     * Check a value against already loaded dictionary options (by label or code)
     *
     * @param array $dictionaryOptions Rows returned by getDictionaryOptions()
     * @param string $value
     * @return bool
     */
    public static function isValueInDictionary(array $dictionaryOptions, string $value): bool
    {
        foreach ($dictionaryOptions as $option) {
            if ($option['value_label'] === $value || $option['value_code'] === $value) {
                return true;
            }
        }

        return false;
    }

    // =========================================================================
    // PRIVATE HELPER METHODS
    // =========================================================================

    /**
     * Upsert InPost attribute into database
     *
     * @param array $attribute Attribute data from API
     * @param string $language Language code
     * @return bool Success status
     */
    public function upsertInPostAttribute(array $attribute, string $language): bool
    {
        // Support both camelCase and snake_case for expectedValue
        $expectedValue = $attribute['expectedValue'] ?? ($attribute['expected_value'] ?? null);

        $data = [
            'inpost_attribute_id' => pSQL($attribute['id']),
            'name' => pSQL($attribute['name'] ?? ''),
            'type' => isset($attribute['type']) ? pSQL($attribute['type']) : null,
            'lang' => pSQL($language),
            'expected_value' => $expectedValue ? pSQL($expectedValue) : null,
            'description' => isset($attribute['description']) ? pSQL($attribute['description']) : null,
            'required' => (int)($attribute['required'] ?? 0),
            // Returned by the API, so it is live again even if previously deactivated
            'is_active' => 1,
            'synced_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        $exists = $this->inpostAttributeExists($attribute['id'], $language);

        if ($exists) {
            // Update existing
            return $this->db->update(
                'inpost_attribute',
                $data,
                sprintf(
                    "inpost_attribute_id = '%s' AND lang = '%s'",
                    pSQL($attribute['id']),
                    pSQL($language)
                )
            );
        } else {
            // Insert new
            $data['created_at'] = date('Y-m-d H:i:s');
            return $this->db->insert('inpost_attribute', $data);
        }
    }

    /**
     * Upsert dictionary option into database
     *
     * @param int $attributeEntityId InPost attribute entity ID
     * @param array $option Dictionary option data from API
     * @param string $language Language code
     * @return bool Success status
     */
    private function upsertDictionaryOption(int $attributeEntityId, array $option, string $language): bool
    {
        $data = [
            'attribute_id' => (int)$attributeEntityId,
            'value_code' => pSQL($option['id']),
            'value_label' => pSQL($option['value'] ?? ''),
            'active' => (int) (bool) ($option['active'] ?? true),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        $exists = $this->dictionaryOptionExists($attributeEntityId, $option['id'], $language);

        if ($exists) {
            // Update existing
            return $this->db->update(
                'inpost_attribute_dictionary',
                $data,
                sprintf(
                    "attribute_id = %d AND value_code = '%s'",
                    (int)$attributeEntityId,
                    pSQL($option['id'])
                )
            );
        } else {
            // Insert new
            $data['created_at'] = date('Y-m-d H:i:s');
            return $this->db->insert('inpost_attribute_dictionary', $data);
        }
    }

    /**
     * Check if InPost attribute exists in database
     *
     * @param string $inpostAttributeId InPost attribute ID
     * @param string $language Language code
     * @return bool
     */
    private function inpostAttributeExists(string $inpostAttributeId, string $language): bool
    {
        $query = new DbQuery();
        $query->select('COUNT(*)')
            ->from('inpost_attribute')
            ->where("inpost_attribute_id = '" . pSQL($inpostAttributeId) . "'")
            ->where("lang = '" . pSQL($language) . "'");

        return (bool)$this->db->getValue($query);
    }

    /**
     * Check if dictionary option exists
     *
     * @param int $attributeEntityId InPost attribute entity ID
     * @param string $optionId Dictionary option ID
     * @param string $language Language code
     * @return bool
     */
    private function dictionaryOptionExists(int $attributeEntityId, string $optionId, string $language): bool
    {
        $query = new DbQuery();
        $query->select('COUNT(*)')
            ->from('inpost_attribute_dictionary')
            ->where('attribute_id = ' . (int)$attributeEntityId)
            ->where("value_code = '" . pSQL($optionId) . "'");

        return (bool)$this->db->getValue($query);
    }

    /**
     * Get InPost attribute entity ID (attribute_id)
     *
     * @param string $inpostAttributeId InPost attribute ID (UUID)
     * @param string $language Language code
     * @return int|null Attribute ID (PK) or null
     */
    private function getInPostAttributeEntityId(string $inpostAttributeId, string $language = 'pl'): ?int
    {
        $query = new DbQuery();
        $query->select('attribute_id')
            ->from('inpost_attribute')
            ->where("inpost_attribute_id = '" . pSQL($inpostAttributeId) . "'")
            ->where("lang = '" . pSQL($language) . "'");

        $result = $this->db->getValue($query);

        return $result ? (int)$result : null;
    }

    /**
     * Get category mapping entity ID
     *
     * @param string $inpostCategoryId InPost category ID
     * @param string $language Language code
     * @return int|null Entity ID or null
     */
    private function getCategoryMappingId(string $inpostCategoryId, string $language = 'pl'): ?int
    {
        $query = new DbQuery();
        $query->select('entity_id')
            ->from('inpost_category_mapping')
            ->where("inpost_category_id = '" . pSQL($inpostCategoryId) . "'")
            ->where("language = '" . pSQL($language) . "'");

        $result = $this->db->getValue($query);

        return $result ? (int)$result : null;
    }

    /**
     * Link category to attribute in inpost_category_attribute table
     *
     * Creates or updates the relationship between a category and an attribute.
     * Uses INSERT ... ON DUPLICATE KEY UPDATE to handle existing relationships.
     *
     * @param int $categoryEntityId Category entity ID from inpost_category_mapping
     * @param int $attributeEntityId Attribute entity ID from inpost_attribute
     * @param bool $isRequired Whether attribute is required for this category
     * @return bool Success status
     */
    private function linkCategoryAttribute(
        int $categoryEntityId,
        int $attributeEntityId,
        bool $isRequired = false
    ): bool {
        // Check if link already exists
        $query = new \DbQuery();
        $query->select('entity_id')
            ->from('inpost_category_attribute')
            ->where('inpost_category_mapping_id = ' . (int)$categoryEntityId)
            ->where('inpost_attribute_id = ' . (int)$attributeEntityId);

        $existingId = $this->db->getValue($query);

        $data = [
            'inpost_category_mapping_id' => (int)$categoryEntityId,
            'inpost_attribute_id' => (int)$attributeEntityId,
            'required' => (int)$isRequired,
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        if ($existingId) {
            // Update existing link
            return $this->db->update(
                'inpost_category_attribute',
                $data,
                'entity_id = ' . (int)$existingId
            );
        } else {
            // Insert new link
            $data['created_at'] = date('Y-m-d H:i:s');
            return $this->db->insert('inpost_category_attribute', $data);
        }
    }

    /**
     * Remove category-attribute links the API no longer returns for a category
     *
     * @param int $categoryEntityId Category entity ID from inpost_category_mapping
     * @param int[] $keepAttributeEntityIds Attribute entity IDs returned by the API
     * @return int Number of removed links
     */
    private function removeStaleCategoryAttributeLinks(int $categoryEntityId, array $keepAttributeEntityIds): int
    {
        $where = 'inpost_category_mapping_id = ' . (int) $categoryEntityId;
        if (!empty($keepAttributeEntityIds)) {
            $where .= ' AND inpost_attribute_id NOT IN (' . implode(',', array_map('intval', $keepAttributeEntityIds)) . ')';
        }

        $this->db->delete('inpost_category_attribute', $where);

        return (int) $this->db->Affected_Rows();
    }

    /**
     * Sync dictionary values for an attribute
     *
     * @param int $attributeEntityId Attribute entity ID
     * @param array $dictionaryItems Dictionary items from API
     * @param string $language Language code
     * @return int Number of synced dictionary values
     */
    private function syncDictionaryValuesForAttribute(
        int $attributeEntityId,
        array $dictionaryItems,
        string $language
    ): int {
        $syncedCount = 0;

        foreach ($dictionaryItems as $item) {
            try {
                $this->upsertDictionaryOption($attributeEntityId, $item, $language);
                $syncedCount++;
            } catch (\Exception $e) {
                $this->logger->error("Failed to sync dictionary option", [
                    'attribute_entity_id' => $attributeEntityId,
                    'option_id' => $item['id'] ?? 'unknown',
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $syncedCount;
    }

    /**
     * Get category attribute by ID
     *
     * Fetches a single category attribute record by entity_id with all related data.
     *
     * @param int $id Category attribute entity_id
     * @return array|null Attribute data or null if not found
     */
    public function getCategoryAttributeById(int $id): ?array
    {
        try {
            $sql = 'SELECT 
                        ca.entity_id,
                        ca.inpost_category_mapping_id,
                        ca.inpost_attribute_id,
                        ca.ps_source_type,
                        ca.ps_source_id,
                        ia.required,
                        ia.inpost_attribute_id as attribute_uuid,
                        ia.lang as attribute_lang,
                        ia.expected_value,
                        ia.name as attribute_name,
                        ia.type as attribute_type,
                        ia.description as attribute_description,
                        cm.inpost_category_id,
                        cm.name as category_name
                    FROM ' . $this->tablePrefix . 'inpost_category_attribute ca
                    INNER JOIN ' . $this->tablePrefix . 'inpost_attribute ia
                        ON ca.inpost_attribute_id = ia.attribute_id
                    INNER JOIN ' . $this->tablePrefix . 'inpost_category_mapping cm
                        ON ca.inpost_category_mapping_id = cm.entity_id
                    WHERE ca.entity_id = ' . (int)$id;

            $result = $this->db->getRow($sql);

            return $result ?: null;
        } catch (\Exception $e) {
            $this->logger->error('Failed to get category attribute by ID', [
                'id' => $id,
                'exception' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Set (or clear) the PrestaShop value source of a category attribute
     *
     * @param int $id Category attribute entity_id
     * @param string|null $sourceType AttributeSource::* or null to clear
     * @param int|null $sourceId id_feature / id_attribute_group
     * @return bool True on success, false on failure
     */
    public function updateCategoryAttributeSource(int $id, ?string $sourceType, ?int $sourceId): bool
    {
        if (!AttributeSource::isValid($sourceType) || !$sourceId) {
            $sourceType = null;
            $sourceId = null;
        }

        try {
            $success = $this->db->update(
                'inpost_category_attribute',
                [
                    'ps_source_type' => $sourceType,
                    'ps_source_id' => $sourceId,
                    // Deprecated column kept consistent for a rollback to <= 1.1.40
                    'prestashop_attribute_code' => $sourceType === AttributeSource::ATTRIBUTE_GROUP ? (string) $sourceId : null,
                    'updated_at' => date('Y-m-d H:i:s'),
                ],
                sprintf('entity_id = %d', $id),
                0,
                true
            );

            $this->logger->info('Category attribute source updated', [
                'entity_id' => $id,
                'ps_source_type' => $sourceType,
                'ps_source_id' => $sourceId,
            ]);

            return (bool) $success;
        } catch (\Exception $e) {
            $this->logger->error('Failed to update category attribute source', [
                'id' => $id,
                'exception' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Give the same source to the not yet mapped category attributes that have
     * the same name (InPost repeats e.g. "Rozmiar" under many attribute UUIDs).
     *
     * @return int Number of category attributes updated
     */
    public function applySourceToSameNamedAttributes(int $categoryAttributeId, string $sourceType, int $sourceId): int
    {
        if (!AttributeSource::isValid($sourceType) || $sourceId <= 0) {
            return 0;
        }

        $current = $this->getCategoryAttributeById($categoryAttributeId);
        if (!$current) {
            return 0;
        }

        $this->db->execute(
            'UPDATE `' . $this->tablePrefix . 'inpost_category_attribute` ca
            INNER JOIN `' . $this->tablePrefix . "inpost_attribute` a ON a.attribute_id = ca.inpost_attribute_id
            SET ca.ps_source_type = '" . pSQL($sourceType) . "',
                ca.ps_source_id = " . (int) $sourceId . ",
                ca.prestashop_attribute_code = " . ($sourceType === AttributeSource::ATTRIBUTE_GROUP ? "'" . (int) $sourceId . "'" : 'NULL') . ",
                ca.updated_at = NOW()
            WHERE ca.ps_source_type IS NULL
              AND ca.entity_id <> " . (int) $categoryAttributeId . "
              AND a.lang = '" . pSQL((string) $current['attribute_lang']) . "'
              AND LOWER(TRIM(a.name)) = '" . pSQL(mb_strtolower(trim((string) $current['attribute_name']))) . "'"
        );

        return (int) $this->db->Affected_Rows();
    }

    /**
     * Not yet mapped attributes of other categories with the same name - what
     * applySourceToSameNamedAttributes() would update
     */
    public function countSameNamedUnmapped(int $categoryAttributeId): int
    {
        $current = $this->getCategoryAttributeById($categoryAttributeId);
        if (!$current) {
            return 0;
        }

        return (int) $this->db->getValue(
            'SELECT COUNT(*) FROM `' . $this->tablePrefix . 'inpost_category_attribute` ca
            INNER JOIN `' . $this->tablePrefix . "inpost_attribute` a ON a.attribute_id = ca.inpost_attribute_id
            WHERE ca.ps_source_type IS NULL
              AND ca.entity_id <> " . (int) $categoryAttributeId . "
              AND a.lang = '" . pSQL((string) $current['attribute_lang']) . "'
              AND LOWER(TRIM(a.name)) = '" . pSQL(mb_strtolower(trim((string) $current['attribute_name']))) . "'"
        );
    }

    /**
     * PrestaShop value -> dictionary option code map of an attribute
     *
     * @return array<int,string> ps_value_id => dictionary_value_code
     */
    public function getValueMap(int $attributeEntityId, string $sourceType): array
    {
        $rows = $this->db->executeS(
            'SELECT ps_value_id, dictionary_value_code FROM `' . $this->tablePrefix . 'inpost_attribute_value_map`
            WHERE attribute_id = ' . (int) $attributeEntityId . " AND ps_source_type = '" . pSQL($sourceType) . "'"
        ) ?: [];

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['ps_value_id']] = (string) $row['dictionary_value_code'];
        }

        return $map;
    }

    /**
     * Save value mappings of an attribute; an empty code removes the mapping
     * (the value is then matched by name again)
     *
     * @param array<int,string|null> $codes ps_value_id => dictionary option code
     */
    public function saveValueMap(int $attributeEntityId, string $sourceType, array $codes): void
    {
        if (!AttributeSource::isValid($sourceType)) {
            return;
        }

        $now = date('Y-m-d H:i:s');
        foreach ($codes as $psValueId => $code) {
            $psValueId = (int) $psValueId;
            if ($psValueId <= 0) {
                continue;
            }

            $where = 'attribute_id = ' . (int) $attributeEntityId
                . " AND ps_source_type = '" . pSQL($sourceType) . "'"
                . ' AND ps_value_id = ' . $psValueId;
            $code = trim((string) $code);

            if ($code === '') {
                $this->db->delete('inpost_attribute_value_map', $where);
                continue;
            }

            $this->db->execute(
                'INSERT INTO `' . $this->tablePrefix . 'inpost_attribute_value_map`
                    (attribute_id, ps_source_type, ps_value_id, dictionary_value_code, created_at, updated_at)
                VALUES (' . (int) $attributeEntityId . ", '" . pSQL($sourceType) . "', " . $psValueId . ", '" . pSQL($code) . "', '" . $now . "', '" . $now . "')
                ON DUPLICATE KEY UPDATE dictionary_value_code = VALUES(dictionary_value_code), updated_at = VALUES(updated_at)"
            );
        }
    }

    /**
     * Clean orphaned category-attribute links
     *
     * Removes category-attribute links where either the category or attribute no longer exists
     * or is inactive.
     *
     * @param string $language Language code
     * @return int Number of cleaned links
     */
    public function cleanOrphanedCategoryAttributeLinks(string $language): int
    {
        try {
            // Clean links where category is inactive or doesn't exist
            $sql = "
                DELETE ca FROM {$this->tablePrefix}inpost_category_attribute ca
                LEFT JOIN {$this->tablePrefix}inpost_category_mapping cm 
                    ON ca.inpost_category_mapping_id = cm.entity_id
                WHERE cm.entity_id IS NULL 
                   OR (cm.is_active = 0 AND cm.language = '" . pSQL($language) . "')
            ";

            $this->db->execute($sql);
            $deleted = $this->db->Affected_Rows();

            // Clean links where attribute is inactive or doesn't exist
            $sql = "
                DELETE ca FROM {$this->tablePrefix}inpost_category_attribute ca
                LEFT JOIN {$this->tablePrefix}inpost_attribute a 
                    ON ca.inpost_attribute_id = a.attribute_id
                WHERE a.attribute_id IS NULL 
                   OR (a.is_active = 0 AND a.lang = '" . pSQL($language) . "')
            ";

            $this->db->execute($sql);
            $deleted += $this->db->Affected_Rows();

            if ($deleted > 0) {
                $this->logger->info("Cleaned {$deleted} orphaned category-attribute links");
            }

            return $deleted;
        } catch (\Exception $e) {
            $this->logger->warning("Could not clean orphaned category-attribute links: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Clean orphaned dictionary options
     *
     * Removes dictionary options where the parent attribute is inactive or doesn't exist.
     *
     * @param string $language Language code
     * @return int Number of cleaned options
     */
    public function cleanOrphanedDictionaryOptions(string $language): int
    {
        try {
            $sql = "
                DELETE d FROM {$this->tablePrefix}inpost_attribute_dictionary d
                LEFT JOIN {$this->tablePrefix}inpost_attribute a
                    ON d.attribute_id = a.attribute_id
                WHERE a.attribute_id IS NULL
                   OR (a.is_active = 0 AND a.lang = '" . pSQL($language) . "')
            ";

            $this->db->execute($sql);
            $deleted = $this->db->Affected_Rows();

            if ($deleted > 0) {
                $this->logger->info("Cleaned {$deleted} orphaned dictionary options");
            }

            return $deleted;
        } catch (\Exception $e) {
            $this->logger->warning("Could not clean orphaned dictionary options: " . $e->getMessage());
            return 0;
        }
    }

}
