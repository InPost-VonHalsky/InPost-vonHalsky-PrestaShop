<?php
/**
 * CategoryAssistantService
 *
 * Data for the "shop -> InPost" category mapping assistant: the PrestaShop
 * category tree (depth-first order) with product counts, the current mapping
 * of each category and the InPost leaf suggestions for it.
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Service\Mapping\Suggest;

use Configuration;
use Db;
use Inpost\VonHalsky\Helper\Config;
use Inpost\VonHalsky\Service\Mapping\CategoryMappingService;

class CategoryAssistantService
{
    private const PRODUCT_NAMES_PER_CATEGORY = 50;

    private CategorySuggester $suggester;
    private CategoryMappingService $mappingService;
    private Config $config;

    public function __construct(CategorySuggester $suggester, CategoryMappingService $mappingService, Config $config)
    {
        $this->suggester = $suggester;
        $this->mappingService = $mappingService;
        $this->config = $config;
    }

    /**
     * @return array{categories: array<int,array<string,mixed>>, summary: array<string,int>}
     */
    public function getOverview(): array
    {
        $shopId = $this->config->getDefaultShopId();
        $langId = $this->config->getDefaultLanguageId();
        $prefix = _DB_PREFIX_;

        $rows = Db::getInstance()->executeS(
            'SELECT c.id_category, c.id_parent, c.level_depth, c.active, cl.name,
                (SELECT COUNT(*) FROM `' . $prefix . 'category_product` cp WHERE cp.id_category = c.id_category) AS products
            FROM `' . $prefix . 'category` c
            INNER JOIN `' . $prefix . 'category_shop` cs ON cs.id_category = c.id_category AND cs.id_shop = ' . (int) $shopId . '
            INNER JOIN `' . $prefix . 'category_lang` cl ON cl.id_category = c.id_category
                AND cl.id_lang = ' . (int) $langId . ' AND cl.id_shop = ' . (int) $shopId . '
            WHERE c.is_root_category = 0 AND c.id_parent > 0
            ORDER BY c.nleft'
        ) ?: [];

        $mapped = [];
        foreach ($this->mappingService->getAllMappings($shopId) as $link) {
            $mapped[(int) $link['prestashop_category_id']] = $link;
        }

        $homeId = (int) Configuration::get('PS_HOME_CATEGORY');
        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) $row['id_category']] = $row;
        }
        $productNames = $this->productNamesByCategory(array_keys($byId), $langId, $shopId);
        $childNames = [];
        foreach ($rows as $row) {
            $childNames[(int) $row['id_parent']][] = (string) $row['name'];
        }

        $categories = [];
        $summary = ['total' => 0, 'mapped' => 0, 'sure' => 0, 'check' => 0, 'none' => 0];
        foreach ($rows as $row) {
            $id = (int) $row['id_category'];
            $ancestors = [];
            $parent = (int) $row['id_parent'];
            while ($parent && $parent !== $homeId && isset($byId[$parent])) {
                array_unshift($ancestors, (string) $byId[$parent]['name']);
                $parent = (int) $byId[$parent]['id_parent'];
            }

            $suggestions = $this->suggester->suggest((string) $row['name'], $ancestors, $productNames[$id] ?? [], 3, $childNames[$id] ?? []);
            // A parent category without own products is rarely mapped itself - never "sure"
            if (!(int) $row['products'] && isset($childNames[$id])) {
                foreach ($suggestions as &$suggestion) {
                    if ($suggestion['level'] === 'sure') {
                        $suggestion['level'] = 'check';
                    }
                }
                unset($suggestion);
            }
            $current = null;
            if (isset($mapped[$id])) {
                $current = $this->suggester->describeEntity((int) $mapped[$id]['entity_id']) ?? [
                    'entity_id' => (int) $mapped[$id]['entity_id'],
                    'name' => (string) $mapped[$id]['name'],
                    'path' => (string) $mapped[$id]['name'],
                ];
                $current['link_id'] = (int) $mapped[$id]['link_id'];
                $current['inactive'] = !(bool) $mapped[$id]['is_active'];
            }

            $best = $suggestions[0]['level'] ?? 'none';
            ++$summary['total'];
            if ($current) {
                ++$summary['mapped'];
            } else {
                ++$summary[$best === 'weak' ? 'none' : $best];
            }

            $categories[] = [
                'id' => $id,
                'parent_id' => (int) $row['id_parent'] === $homeId ? 0 : (int) $row['id_parent'],
                'depth' => max(0, (int) $row['level_depth'] - 2),
                'name' => (string) $row['name'],
                'path' => implode(' › ', $ancestors),
                'active' => (bool) $row['active'],
                'products' => (int) $row['products'],
                'current' => $current,
                'suggestions' => array_values(array_filter(
                    $suggestions,
                    static fn (array $s): bool => $s['level'] !== 'weak'
                )),
            ];
        }

        return ['categories' => $categories, 'summary' => $summary];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function search(string $phrase): array
    {
        return $this->suggester->search($phrase, 10);
    }

    /**
     * @param array<int,int> $categoryIds
     * @return array<int,array<int,string>> id_category => product names
     */
    private function productNamesByCategory(array $categoryIds, int $langId, int $shopId): array
    {
        if (!$categoryIds) {
            return [];
        }

        $rows = Db::getInstance()->executeS(
            'SELECT cp.id_category, pl.name
            FROM `' . _DB_PREFIX_ . 'category_product` cp
            INNER JOIN `' . _DB_PREFIX_ . 'product_lang` pl ON pl.id_product = cp.id_product
                AND pl.id_lang = ' . (int) $langId . ' AND pl.id_shop = ' . (int) $shopId . '
            WHERE cp.id_category IN (' . implode(',', array_map('intval', $categoryIds)) . ')
            ORDER BY cp.id_category, cp.position'
        ) ?: [];

        $names = [];
        foreach ($rows as $row) {
            $id = (int) $row['id_category'];
            if (count($names[$id] ?? []) < self::PRODUCT_NAMES_PER_CATEGORY) {
                $names[$id][] = (string) $row['name'];
            }
        }

        return $names;
    }
}
