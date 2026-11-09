<?php
/**
 * AttributeAssistantService
 *
 * Data and actions of the attribute mapping assistant. Scope: attributes of the
 * InPost categories that are linked to the shop, grouped by name (InPost repeats
 * e.g. "Kolor" under many attribute UUIDs). Per group: the current source,
 * suggested PrestaShop sources and, for dictionaries, value suggestions.
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Service\Mapping\Suggest;

use Db;
use Inpost\VonHalsky\Helper\Config;
use Inpost\VonHalsky\Service\Mapping\AttributeMappingService;
use Inpost\VonHalsky\Service\Mapping\AttributeSource;
use Inpost\VonHalsky\Service\Mapping\DictionaryValueMatcher;
use Inpost\VonHalsky\Service\Mapping\PsAttributeSourceProvider;

class AttributeAssistantService
{
    private AttributeSuggester $suggester;
    private AttributeMappingService $attributeMappingService;
    private PsAttributeSourceProvider $sourceProvider;
    private Config $config;

    /** @var array<int,array{type:string,id:int,name:string,values:array<int,array{id:int,label:string}>}>|null */
    private ?array $sources = null;
    /** @var array<int,array<int,array{value:string,label:string}>> */
    private array $optionsCache = [];

    public function __construct(
        AttributeSuggester $suggester,
        AttributeMappingService $attributeMappingService,
        PsAttributeSourceProvider $sourceProvider,
        Config $config
    ) {
        $this->suggester = $suggester;
        $this->attributeMappingService = $attributeMappingService;
        $this->sourceProvider = $sourceProvider;
        $this->config = $config;
    }

    /**
     * @param int|null $categoryEntityId Limit to one InPost category (deep link from the category assistant)
     * @return array{attributes: array<int,array<string,mixed>>, sources: array<int,array<string,mixed>>, categories: array<int,array{id:int,name:string}>, summary: array<string,int>}
     */
    public function getOverview(?int $categoryEntityId = null, bool $requiredOnly = false): array
    {
        $groups = $this->groups($categoryEntityId);
        $sources = $this->sources();

        $attributes = [];
        $summary = ['total' => 0, 'mapped' => 0, 'required_unmapped' => 0, 'sure' => 0, 'check' => 0];
        foreach ($groups as $key => $group) {
            if ($requiredOnly && !$group['required_count']) {
                continue;
            }

            $isDictionary = in_array('DICTIONARY', $group['types'], true);
            $prepared = $this->suggester->prepareOptions($isDictionary ? $this->groupOptions($group) : []);
            $suggestions = $this->suggester->suggestSources($group['name'], $isDictionary, $prepared, $sources);
            $current = $this->currentSource($group);

            ++$summary['total'];
            if ($current && !$current['partial']) {
                ++$summary['mapped'];
            } else {
                if ($group['required_count']) {
                    ++$summary['required_unmapped'];
                }
                $level = $suggestions[0]['level'] ?? null;
                if ($level === 'sure' || $level === 'check') {
                    ++$summary[$level];
                }
            }

            $attributes[] = [
                'key' => $key,
                'name' => $group['name'],
                'type' => implode(' / ', $group['types']),
                'dictionary' => $isDictionary,
                'categories_count' => count($group['categories']),
                'categories' => array_values($group['categories']),
                'required_count' => $group['required_count'],
                'rows_count' => count($group['rows']),
                'current' => $current,
                'suggestions' => $suggestions,
            ];
        }

        // Required and not mapped first, then by number of categories
        usort($attributes, static function (array $a, array $b): int {
            $aTodo = $a['required_count'] > 0 && (!$a['current'] || $a['current']['partial']);
            $bTodo = $b['required_count'] > 0 && (!$b['current'] || $b['current']['partial']);

            return [$bTodo, $b['categories_count'], $a['name']] <=> [$aTodo, $a['categories_count'], $b['name']];
        });

        return [
            'attributes' => $attributes,
            'sources' => array_map(static fn (array $s): array => [
                'type' => $s['type'], 'id' => $s['id'], 'name' => $s['name'], 'values_count' => count($s['values']),
            ], $sources),
            'categories' => $this->linkedCategories(),
            'summary' => $summary,
        ];
    }

    /**
     * Value suggestions of one group for a chosen source, one section per InPost
     * attribute (value maps are stored per attribute UUID)
     *
     * @return array<int,array<string,mixed>>|null Null when the group does not exist
     */
    public function getValues(string $key, string $sourceType, int $sourceId): ?array
    {
        $groups = $this->groups(null);
        if (!isset($groups[$key]) || !AttributeSource::isValid($sourceType)) {
            return null;
        }

        $psValues = $this->sourceProvider->getSourceValues($sourceType, $sourceId);
        $sections = [];
        foreach ($groups[$key]['attributes'] as $attributeId => $categoryNames) {
            $options = $this->options($attributeId);
            $prepared = $this->suggester->prepareOptions($options);
            $saved = $this->attributeMappingService->getValueMap($attributeId, $sourceType);

            $rows = [];
            foreach ($psValues as $value) {
                $savedCode = $saved[$value['id']] ?? null;
                $savedOption = null;
                if ($savedCode !== null && DictionaryValueMatcher::hasCode($options, $savedCode)) {
                    foreach ($options as $option) {
                        if ($option['value'] === $savedCode) {
                            $savedOption = $option;
                        }
                    }
                }
                $auto = DictionaryValueMatcher::match($options, $value['label']);
                $suggestion = $savedOption || $auto ? null : $this->suggester->matchValue($value['label'], $prepared);

                $rows[] = [
                    'ps_value_id' => $value['id'],
                    'ps_label' => $value['label'],
                    'status' => $savedOption ? 'mapped' : ($auto ? 'auto' : ($suggestion ? 'suggested' : 'unmatched')),
                    'saved' => $savedOption,
                    'auto' => $auto,
                    'suggestion' => $suggestion,
                ];
            }

            $sections[] = [
                'attribute_id' => $attributeId,
                'categories' => array_values(array_unique($categoryNames)),
                'options' => $options,
                'rows' => $rows,
            ];
        }

        return $sections;
    }

    /**
     * Set the source on every category attribute of the group
     *
     * @return int Number of category attributes updated
     */
    public function applySource(string $key, ?string $sourceType, ?int $sourceId): int
    {
        return $this->applySources([$key => [$sourceType, $sourceId]]);
    }

    /**
     * Set sources of several groups at once (bulk accept)
     *
     * @param array<string,array{0:string|null,1:int|null}> $sources group key => [type, id]
     * @return int Number of category attributes updated
     */
    public function applySources(array $sources): int
    {
        $groups = $this->groups(null);
        $updated = 0;
        foreach ($sources as $key => [$sourceType, $sourceId]) {
            foreach ($groups[$key]['rows'] ?? [] as $row) {
                if ($this->attributeMappingService->updateCategoryAttributeSource((int) $row['ca_id'], $sourceType, $sourceId)) {
                    ++$updated;
                }
            }
        }

        return $updated;
    }

    /**
     * Save chosen value mappings of one attribute (only real dictionary codes)
     *
     * @param array<int,string> $codes ps_value_id => option code
     * @return int Number of saved mappings
     */
    public function saveValues(int $attributeId, string $sourceType, array $codes): int
    {
        if (!AttributeSource::isValid($sourceType)) {
            return 0;
        }

        $options = $this->options($attributeId);
        $valid = [];
        foreach ($codes as $psValueId => $code) {
            $code = (string) $code;
            if ($code !== '' && DictionaryValueMatcher::hasCode($options, $code)) {
                $valid[(int) $psValueId] = $code;
            }
        }
        $this->attributeMappingService->saveValueMap($attributeId, $sourceType, $valid);

        return count($valid);
    }

    /**
     * Category attributes of linked InPost categories, grouped by name. "Required"
     * comes from the attribute (expectedValue ONE / AT_LEAST_ONE) - the category
     * link flag is not sent by the API and stays 0. (InPost
     * sometimes has the same attribute as NUMERIC in one category and
     * NUMERIC_FLOAT in another - one source fits both)
     *
     * @return array<string,array{name:string,types:array<int,string>,rows:array<int,array<string,mixed>>,categories:array<int,array{id:int,name:string}>,attributes:array<int,array<int,string>>,required_count:int}>
     */
    private function groups(?int $categoryEntityId): array
    {
        $linked = array_column($this->linkedCategories(), 'id');
        if ($categoryEntityId) {
            $linked = array_values(array_intersect($linked, [$categoryEntityId]));
        }
        if (!$linked) {
            return [];
        }

        $rows = Db::getInstance()->executeS(
            'SELECT ca.entity_id AS ca_id, ca.inpost_category_mapping_id AS category_id, ca.ps_source_type, ca.ps_source_id,
                GREATEST(ca.required, a.required) AS required, a.attribute_id, a.name, a.type, cm.name AS category_name
            FROM `' . _DB_PREFIX_ . 'inpost_category_attribute` ca
            INNER JOIN `' . _DB_PREFIX_ . 'inpost_attribute` a ON a.attribute_id = ca.inpost_attribute_id AND a.is_active = 1
            INNER JOIN `' . _DB_PREFIX_ . 'inpost_category_mapping` cm ON cm.entity_id = ca.inpost_category_mapping_id
            WHERE ca.inpost_category_mapping_id IN (' . implode(',', array_map('intval', $linked)) . ')
            ORDER BY a.name'
        ) ?: [];

        $groups = [];
        foreach ($rows as $row) {
            $key = md5(mb_strtolower(trim((string) $row['name'])));
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'name' => trim((string) $row['name']),
                    'types' => [],
                    'rows' => [],
                    'categories' => [],
                    'attributes' => [],
                    'required_count' => 0,
                ];
            }
            $groups[$key]['rows'][] = $row;
            if (!in_array((string) $row['type'], $groups[$key]['types'], true)) {
                $groups[$key]['types'][] = (string) $row['type'];
            }
            $groups[$key]['categories'][(int) $row['category_id']] = ['id' => (int) $row['category_id'], 'name' => (string) $row['category_name']];
            $groups[$key]['attributes'][(int) $row['attribute_id']][] = (string) $row['category_name'];
            if ((int) $row['required']) {
                ++$groups[$key]['required_count'];
            }
        }

        return $groups;
    }

    /**
     * @return array<int,array{id:int,name:string}> InPost categories linked to the shop
     */
    private function linkedCategories(): array
    {
        $rows = Db::getInstance()->executeS(
            'SELECT DISTINCT cm.entity_id AS id, cm.name
            FROM `' . _DB_PREFIX_ . 'inpost_category_link` cl
            INNER JOIN `' . _DB_PREFIX_ . 'inpost_category_mapping` cm ON cm.entity_id = cl.mapping_id
            WHERE cl.shop_id = ' . (int) $this->config->getDefaultShopId() . ' OR cl.shop_id IS NULL
            ORDER BY cm.name'
        ) ?: [];

        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'name' => (string) $r['name']], $rows);
    }

    /**
     * Current source of a group: null when none, partial when only some rows have it,
     * mixed when rows use different sources
     *
     * @param array<string,mixed> $group
     * @return array{type:string|null,id:int|null,name:string,partial:bool,mixed:bool}|null
     */
    private function currentSource(array $group): ?array
    {
        $set = [];
        $missing = 0;
        foreach ($group['rows'] as $row) {
            if ($row['ps_source_type'] && $row['ps_source_id']) {
                $set[$row['ps_source_type'] . ':' . $row['ps_source_id']] = [(string) $row['ps_source_type'], (int) $row['ps_source_id']];
            } else {
                ++$missing;
            }
        }
        if (!$set) {
            return null;
        }
        if (count($set) > 1) {
            return ['type' => null, 'id' => null, 'name' => '', 'partial' => $missing > 0, 'mixed' => true];
        }

        [$type, $id] = reset($set);
        $name = '';
        foreach ($this->sources() as $source) {
            if ($source['type'] === $type && $source['id'] === $id) {
                $name = $source['name'];
            }
        }

        return ['type' => $type, 'id' => $id, 'name' => $name, 'partial' => $missing > 0, 'mixed' => false];
    }

    /**
     * Options of all dictionaries in a group (value suggestions use one dictionary at a time)
     *
     * @param array<string,mixed> $group
     * @return array<int,array{value:string,label:string}>
     */
    private function groupOptions(array $group): array
    {
        $options = [];
        foreach (array_keys($group['attributes']) as $attributeId) {
            foreach ($this->options((int) $attributeId) as $option) {
                $options[$option['value']] = $option;
            }
        }

        return array_values($options);
    }

    /**
     * @return array<int,array{value:string,label:string}>
     */
    private function options(int $attributeId): array
    {
        if (!isset($this->optionsCache[$attributeId])) {
            $rows = Db::getInstance()->executeS(
                'SELECT value_code, value_label FROM `' . _DB_PREFIX_ . 'inpost_attribute_dictionary`
                WHERE attribute_id = ' . (int) $attributeId . ' AND active = 1 ORDER BY value_label'
            ) ?: [];
            $this->optionsCache[$attributeId] = array_map(
                static fn (array $r): array => ['value' => (string) $r['value_code'], 'label' => (string) $r['value_label']],
                $rows
            );
        }

        return $this->optionsCache[$attributeId];
    }

    /**
     * @return array<int,array{type:string,id:int,name:string,values:array<int,array{id:int,label:string}>}>
     */
    private function sources(): array
    {
        if ($this->sources === null) {
            $this->sources = [];
            $all = $this->sourceProvider->getSources();
            foreach (AttributeSource::LISTS as $type => $key) {
                foreach ($all[$key] ?? [] as $source) {
                    $this->sources[] = [
                        'type' => $type,
                        'id' => (int) $source['id'],
                        'name' => (string) $source['name'],
                        'values' => $this->sourceProvider->getSourceValues($type, (int) $source['id']),
                    ];
                }
            }
        }

        return $this->sources;
    }
}
