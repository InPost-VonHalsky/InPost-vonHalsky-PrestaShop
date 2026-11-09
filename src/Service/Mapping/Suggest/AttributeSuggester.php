<?php
/**
 * AttributeSuggester
 *
 * Fuzzy matching for attribute mapping:
 * - which PrestaShop feature / attribute group should feed an InPost attribute
 *   (50% name similarity, 50% share of the source's values found in the InPost
 *   dictionary; name only for non-dictionary attributes),
 * - which dictionary option a PrestaShop value corresponds to.
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Service\Mapping\Suggest;

use Inpost\VonHalsky\Service\Mapping\AttributeSource;

class AttributeSuggester
{
    private const MIN_SOURCE_SCORE = CategorySuggester::SCORE_CHECK;
    private const MIN_VALUE_SCORE = 60;
    /** Fuzzy (non-exact) value matching is skipped for huge dictionaries */
    private const FUZZY_OPTIONS_LIMIT = 3000;

    /**
     * Prepared dictionary: exact lookup by folded label/code plus stems for fuzzy matching
     *
     * @param array<int,array{value:string,label:string}> $options
     * @return array{exact: array<string,int>, items: array<int,array{value:string,label:string,stems:array<int,string>}>}
     */
    public function prepareOptions(array $options): array
    {
        $exact = [];
        $items = [];
        foreach (array_values($options) as $i => $option) {
            $label = (string) $option['label'];
            $items[$i] = ['value' => (string) $option['value'], 'label' => $label, 'stems' => TextNormalizer::stems($label)];
            $exact[TextNormalizer::fold($label)] = $exact[TextNormalizer::fold($label)] ?? $i;
            $exact[TextNormalizer::fold((string) $option['value'])] = $exact[TextNormalizer::fold((string) $option['value'])] ?? $i;
        }

        return ['exact' => $exact, 'items' => $items];
    }

    /**
     * Best dictionary option for a PrestaShop value
     *
     * @param array{exact: array<string,int>, items: array<int,array{value:string,label:string,stems:array<int,string>}>} $prepared
     * @return array{value:string,label:string,score:int,level:string}|null
     */
    public function matchValue(string $psValue, array $prepared): ?array
    {
        $folded = TextNormalizer::fold($psValue);
        if ($folded === '' || !$prepared['items']) {
            return null;
        }

        if (isset($prepared['exact'][$folded])) {
            return $this->valueResult($prepared['items'][$prepared['exact'][$folded]], 100);
        }

        $stems = TextNormalizer::stems($psValue);
        if (!$stems || count($prepared['items']) > self::FUZZY_OPTIONS_LIMIT) {
            return null;
        }

        $best = null;
        $bestScore = 0;
        foreach ($prepared['items'] as $item) {
            [$sim] = Similarity::sets($stems, $item['stems']);
            if ($sim <= 0) {
                continue;
            }
            // identical stems ("Czarne" ~ "czarny", "Black" ~ "Czarny") are near-certain
            $score = $sim >= 0.999 ? 95 : (int) round(90 * $sim);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $item;
            }
        }

        return $best && $bestScore >= self::MIN_VALUE_SCORE ? $this->valueResult($best, $bestScore) : null;
    }

    /**
     * Ranked PrestaShop sources for an InPost attribute
     *
     * @param array{exact: array<string,int>, items: array<int,mixed>} $prepared Dictionary (empty for non-dictionary attributes)
     * @param array<int,array{type:string,id:int,name:string,values:array<int,array{id:int,label:string}>}> $sources
     * @return array<int,array{type:string,id:int,name:string,label:string,score:int,level:string,reason:string,name_pct:int,by_values:bool,matched:int,total:int,exact:bool}>
     */
    public function suggestSources(string $attributeName, bool $isDictionary, array $prepared, array $sources, int $limit = 3): array
    {
        $result = [];
        foreach ($sources as $source) {
            $nameSim = $this->nameSimilarity($attributeName, $source['name']);
            $matched = 0;
            $total = count($source['values']);

            if ($isDictionary && $prepared['items'] && $total > 0) {
                foreach ($source['values'] as $value) {
                    $match = $this->matchValue($value['label'], $prepared);
                    if ($match && $match['score'] >= CategorySuggester::SCORE_SURE) {
                        ++$matched;
                    }
                }
                $coverage = $matched / $total;
                $score = (int) round(50 * $nameSim + 50 * $coverage);
                $reason = sprintf('name %d%% · %d/%d values', (int) round(100 * $nameSim), $matched, $total);
            } else {
                $score = (int) round(100 * $nameSim * ($isDictionary ? 0.9 : 1.0));
                $reason = sprintf('name %d%%', (int) round(100 * $nameSim));
            }

            if ($score < self::MIN_SOURCE_SCORE) {
                continue;
            }

            $result[] = [
                'type' => $source['type'],
                'id' => $source['id'],
                'name' => $source['name'],
                'label' => self::sourceLabel($source['type'], $source['name']),
                'score' => $score,
                'level' => CategorySuggester::level($score),
                'reason' => $reason,
                'name_pct' => (int) round(100 * $nameSim),
                'by_values' => $isDictionary && $prepared['items'] && $total > 0,
                'matched' => $matched,
                'total' => $total,
                'exact' => TextNormalizer::fold($attributeName) === TextNormalizer::fold($source['name']),
            ];
        }

        // Ties: the literally same name first ("Kolor" before "Color"), then the source with more values
        usort($result, static fn (array $a, array $b): int => [$b['score'], $b['exact'], $b['total']] <=> [$a['score'], $a['exact'], $a['total']]);

        return array_slice($result, 0, $limit);
    }

    /**
     * 1 for the same name (synonyms included). A shorter name that starts the
     * longer one ("Kolor" ~ "Kolor dominujący", "Moc" ~ "Moc znamionowa RMS") is
     * worth 0.75 - "check", not "sure"; a word found elsewhere in the longer name
     * ("Pamięć" ~ "Czytnik kart pamięci") does not count.
     */
    public function nameSimilarity(string $a, string $b): float
    {
        // Units / notes in brackets do not change what the attribute is: "Waga (g)" = "Waga"
        $a = self::withoutBrackets($a);
        $b = self::withoutBrackets($b);
        if (TextNormalizer::fold($a) === TextNormalizer::fold($b)) {
            return 1.0;
        }
        $stemsA = TextNormalizer::stems($a);
        $stemsB = TextNormalizer::stems($b);
        [$sim] = Similarity::sets($stemsA, $stemsB);
        $shorter = count($stemsA) <= count($stemsB) ? $stemsA : $stemsB;
        $longer = $shorter === $stemsA ? $stemsB : $stemsA;
        $prefix = Similarity::coverage($shorter, array_slice($longer, 0, count($shorter)));

        return max($sim, $prefix >= 0.999 ? 0.75 : 0.0);
    }

    private static function withoutBrackets(string $name): string
    {
        $stripped = trim((string) preg_replace('/\s*[(\[][^)\]]*[)\]]/u', '', $name));

        return $stripped !== '' ? $stripped : $name;
    }

    public static function sourceLabel(string $type, string $name): string
    {
        $kinds = [AttributeSource::FEATURE => 'Feature', AttributeSource::PRODUCT_FIELD => 'Product field'];

        return ($kinds[$type] ?? 'Attribute') . ': ' . $name;
    }

    /**
     * @param array{value:string,label:string} $item
     * @return array{value:string,label:string,score:int,level:string}
     */
    private function valueResult(array $item, int $score): array
    {
        return [
            'value' => $item['value'],
            'label' => $item['label'],
            'score' => $score,
            'level' => CategorySuggester::level($score),
        ];
    }
}
