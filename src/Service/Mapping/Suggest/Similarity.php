<?php
/**
 * Similarity
 *
 * Fuzzy comparison of stem lists: a pair of stems scores 1 when equal, high when
 * one is a prefix of the other, by normalized Levenshtein for typos; sets of
 * stems are compared with a soft Dice coefficient.
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Service\Mapping\Suggest;

final class Similarity
{
    /** Below this a stem pair counts as no match at all */
    private const MIN_PAIR = 0.75;

    public static function pair(string $a, string $b): float
    {
        if ($a === $b) {
            return 1.0;
        }
        $la = strlen($a);
        $lb = strlen($b);
        if ($la < 3 || $lb < 3 || ctype_digit($a) || ctype_digit($b)) {
            return 0.0;
        }
        if (min($la, $lb) >= 4 && (strpos($a, $b) === 0 || strpos($b, $a) === 0)) {
            return 0.9;
        }
        if (abs($la - $lb) > 3) {
            return 0.0;
        }

        $ratio = 1 - levenshtein($a, $b) / max($la, $lb);

        return $ratio >= self::MIN_PAIR ? $ratio : 0.0;
    }

    /**
     * Soft Dice of two stem lists
     *
     * @param array<int,string> $a
     * @param array<int,string> $b
     * @return array{0:float,1:array<int,string>} [score 0..1, stems of $b that matched]
     */
    public static function sets(array $a, array $b): array
    {
        if (!$a || !$b) {
            return [0.0, []];
        }

        $sumA = 0.0;
        foreach ($a as $x) {
            $best = 0.0;
            foreach ($b as $y) {
                $best = max($best, self::pair($x, $y));
                if ($best === 1.0) {
                    break;
                }
            }
            $sumA += $best;
        }

        $sumB = 0.0;
        $matched = [];
        foreach ($b as $y) {
            $best = 0.0;
            foreach ($a as $x) {
                $best = max($best, self::pair($x, $y));
                if ($best === 1.0) {
                    break;
                }
            }
            if ($best > 0) {
                $matched[] = $y;
            }
            $sumB += $best;
        }

        return [($sumA + $sumB) / (count($a) + count($b)), $matched];
    }

    /**
     * Share of $needles found (fuzzily) in $haystack, 0..1
     *
     * @param array<int,string> $needles
     * @param array<int,string> $haystack
     */
    public static function coverage(array $needles, array $haystack): float
    {
        if (!$needles || !$haystack) {
            return 0.0;
        }

        $sum = 0.0;
        foreach ($needles as $x) {
            $best = 0.0;
            foreach ($haystack as $y) {
                $best = max($best, self::pair($x, $y));
            }
            $sum += $best;
        }

        return $sum / count($needles);
    }

    private function __construct()
    {
    }
}
