<?php
/**
 * CategorySuggester
 *
 * Proposes InPost leaf categories for a PrestaShop category. Score 0-100:
 * - 45% name of the PS category vs the leaf (80% credit when it matches the
 *   leaf's parent, 70% for a higher ancestor),
 * - 35% PS context words (ancestors, the category, its subcategories) found
 *   in the InPost path - tells apart same-named leaves, e.g. "Słuchawki" under
 *   electronics and under running,
 * - 20% leaf name words found in the names of the category's products,
 * plus a small bonus for an identical name.
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Service\Mapping\Suggest;

use Db;

class CategorySuggester
{
    public const SCORE_SURE = 85;
    public const SCORE_CHECK = 60;

    private const W_NAME = 0.45;
    private const W_PATH = 0.35;
    private const W_PRODUCTS = 0.20;
    private const EXACT_BONUS = 5;
    private const PRODUCT_STEMS = 10;
    private const PATH_SEPARATOR = ' › ';

    /** @var array<int,array{uuid:string,name:string,parent:int,leaf:bool,stems:array<int,string>}>|null */
    private ?array $nodes = null;
    /** @var array<string,array<int,int>> stem prefix => leaf entity ids */
    private array $index = [];
    /** @var array<int,array<int,string>> leaf entity id => stems of the whole path */
    private array $pathStems = [];

    /**
     * Top suggestions for one PrestaShop category
     *
     * @param string $name PS category name
     * @param array<int,string> $ancestorNames PS ancestor names (without Root/Home)
     * @param array<int,string> $productNames Names of products in the category
     * @param array<int,string> $childNames Names of PS subcategories (extra context)
     * @return array<int,array{entity_id:int,inpost_category_id:string,name:string,path:string,path_html:string,score:int,level:string}>
     */
    public function suggest(string $name, array $ancestorNames = [], array $productNames = [], int $limit = 3, array $childNames = []): array
    {
        $this->load();

        $nameStems = TextNormalizer::stems($name);
        if (!$nameStems) {
            return [];
        }
        $contextStems = $nameStems;
        foreach (array_merge($ancestorNames, $childNames) as $contextName) {
            $contextStems = array_merge($contextStems, TextNormalizer::stems($contextName));
        }
        $contextStems = array_values(array_unique($contextStems));
        $productStems = $this->topProductStems($productNames);
        $foldedName = TextNormalizer::fold($name);

        $scored = [];
        foreach ($this->candidates(array_merge($nameStems, $productStems)) as $id) {
            $nameScore = $this->nameScore($nameStems, $id);
            if ($nameScore <= 0) {
                continue;
            }

            $pathScore = Similarity::coverage($contextStems, $this->pathStems[$id]);
            if ($productStems) {
                $productScore = Similarity::coverage($this->nodes[$id]['stems'], $productStems);
                $score = self::W_NAME * $nameScore + self::W_PATH * $pathScore + self::W_PRODUCTS * $productScore;
            } else {
                $score = ($nameScore * self::W_NAME + $pathScore * self::W_PATH) / (self::W_NAME + self::W_PATH);
            }

            $score = (int) round(100 * $score);
            if (TextNormalizer::fold($this->nodes[$id]['name']) === $foldedName) {
                $score += self::EXACT_BONUS;
            }
            $scored[$id] = min(100, $score);
        }

        // Ties: shallower (more general, more likely intended) categories first
        uksort($scored, function (int $a, int $b) use ($scored): int {
            return [$scored[$b], $this->depth($a)] <=> [$scored[$a], $this->depth($b)];
        });
        $result = [];
        foreach (array_slice($scored, 0, $limit, true) as $id => $score) {
            $result[] = $this->describe($id, $score, array_merge($contextStems, $productStems));
        }

        return $result;
    }

    /**
     * How well the PS name fits the leaf: full credit for the leaf itself (all PS
     * words contained in a longer leaf name count almost fully), less for an ancestor
     *
     * @param array<int,string> $nameStems
     */
    private function nameScore(array $nameStems, int $leafId): float
    {
        $leafStems = $this->nodes[$leafId]['stems'];
        [$dice] = Similarity::sets($nameStems, $leafStems);
        $score = max($dice, 0.92 * Similarity::coverage($nameStems, $leafStems));

        $factor = 0.8;
        $id = $this->nodes[$leafId]['parent'];
        $guard = 0;
        while ($id && isset($this->nodes[$id]) && $guard++ < 20) {
            [$ancestorSim] = Similarity::sets($nameStems, $this->nodes[$id]['stems']);
            $score = max($score, $factor * $ancestorSim);
            $factor = 0.7;
            $id = $this->nodes[$id]['parent'];
        }

        return $score;
    }

    /**
     * Fuzzy search of leaves by a typed phrase (diacritics, inflection and typos tolerated)
     *
     * @return array<int,array<string,mixed>>
     */
    public function search(string $phrase, int $limit = 10): array
    {
        return $this->suggest($phrase, [], [], $limit);
    }

    /**
     * Path of a known InPost category, for showing the current mapping
     *
     * @return array{entity_id:int,inpost_category_id:string,name:string,path:string,path_html:string,score:int,level:string}|null
     */
    public function describeEntity(int $entityId): ?array
    {
        $this->load();

        return isset($this->nodes[$entityId]) ? $this->describe($entityId, 100, []) : null;
    }

    public static function level(int $score): string
    {
        if ($score >= self::SCORE_SURE) {
            return 'sure';
        }

        return $score >= self::SCORE_CHECK ? 'check' : 'weak';
    }

    /**
     * Leaves sharing a 4-char stem prefix with any of the query stems
     *
     * @param array<int,string> $stems
     * @return array<int,int>
     */
    private function candidates(array $stems): array
    {
        $ids = [];
        foreach ($stems as $stem) {
            foreach ($this->index[self::key($stem)] ?? [] as $id) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    /**
     * Most frequent stems of the product names (digits and short words skipped)
     *
     * @param array<int,string> $productNames
     * @return array<int,string>
     */
    private function topProductStems(array $productNames): array
    {
        $counts = [];
        foreach ($productNames as $productName) {
            foreach (TextNormalizer::stems($productName) as $stem) {
                if (strlen($stem) >= 4 && !ctype_digit($stem)) {
                    $counts[$stem] = ($counts[$stem] ?? 0) + 1;
                }
            }
        }
        arsort($counts);

        return array_map('strval', array_keys(array_slice($counts, 0, self::PRODUCT_STEMS, true)));
    }

    /**
     * @param array<int,string> $queryStems
     */
    private function describe(int $id, int $score, array $queryStems): array
    {
        $names = [];
        $html = [];
        foreach ($this->ancestry($id) as $nodeId) {
            $node = $this->nodes[$nodeId];
            $names[] = $node['name'];
            $html[] = self::highlight($node['name'], $queryStems, $nodeId === $id);
        }

        return [
            'entity_id' => $id,
            'inpost_category_id' => $this->nodes[$id]['uuid'],
            'name' => $this->nodes[$id]['name'],
            'path' => implode(self::PATH_SEPARATOR, $names),
            'path_html' => implode(' <span class="sep">›</span> ', $html),
            'score' => $score,
            'level' => self::level($score),
        ];
    }

    /**
     * HTML-escaped name with matching words wrapped in <mark>, bold when it is the leaf
     *
     * @param array<int,string> $queryStems
     */
    private static function highlight(string $name, array $queryStems, bool $isLeaf): string
    {
        $parts = preg_split('/(\s+)/u', $name, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$name];
        $out = '';
        foreach ($parts as $part) {
            $escaped = htmlspecialchars($part, ENT_QUOTES, 'UTF-8');
            $stems = TextNormalizer::stems($part);
            $hit = $stems && Similarity::coverage($stems, $queryStems) >= 0.75;
            $out .= $hit ? '<mark>' . $escaped . '</mark>' : $escaped;
        }

        return $isLeaf ? '<strong>' . $out . '</strong>' : $out;
    }

    /**
     * @return array<int,int> Entity ids from the root down to $id
     */
    private function ancestry(int $id): array
    {
        $chain = [];
        $guard = 0;
        while ($id && isset($this->nodes[$id]) && $guard++ < 20) {
            array_unshift($chain, $id);
            $id = $this->nodes[$id]['parent'];
        }

        return $chain;
    }

    private function depth(int $id): int
    {
        return count($this->ancestry($id));
    }

    private static function key(string $stem): string
    {
        return substr($stem, 0, 3);
    }

    private function load(): void
    {
        if ($this->nodes !== null) {
            return;
        }

        $rows = Db::getInstance()->executeS(
            'SELECT entity_id, inpost_category_id, parent_id, name, is_leaf FROM `' . _DB_PREFIX_ . "inpost_category_mapping`
            WHERE is_active = 1 AND language = 'pl'"
        ) ?: [];

        $this->nodes = [];
        foreach ($rows as $row) {
            $this->nodes[(int) $row['entity_id']] = [
                'uuid' => (string) $row['inpost_category_id'],
                'name' => (string) $row['name'],
                'parent' => (int) $row['parent_id'],
                'leaf' => (bool) $row['is_leaf'],
                'stems' => TextNormalizer::stems((string) $row['name']),
            ];
        }

        foreach ($this->nodes as $id => $node) {
            if (!$node['leaf']) {
                continue;
            }
            $path = [];
            foreach ($this->ancestry($id) as $nodeId) {
                $path = array_merge($path, $this->nodes[$nodeId]['stems']);
            }
            $this->pathStems[$id] = array_values(array_unique($path));

            foreach ($this->pathStems[$id] as $stem) {
                $this->index[self::key($stem)][$id] = $id;
            }
        }
    }
}
