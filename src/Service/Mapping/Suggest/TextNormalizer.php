<?php
/**
 * TextNormalizer
 *
 * Turns category / attribute / value names into comparable tokens: lowercase,
 * Polish diacritics folded, punctuation dropped, stop words removed, a light
 * Polish (and English plural) suffix stemmer and synonym canonicalization.
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Service\Mapping\Suggest;

final class TextNormalizer
{
    private const FOLD = [
        'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
        'ä' => 'a', 'ö' => 'o', 'ü' => 'u', 'é' => 'e', 'è' => 'e', 'á' => 'a', 'í' => 'i', 'ú' => 'u', 'ß' => 'ss',
    ];

    private const STOP_WORDS = [
        'i', 'oraz', 'dla', 'do', 'z', 'ze', 'w', 'we', 'na', 'od', 'po', 'pod', 'bez', 'lub', 'a', 'o', 'u',
        'the', 'and', 'of', 'for', 'with', 'in', 'on', 'or',
    ];

    /** Longest first; stripped only when at least MIN_STEM chars remain */
    private const SUFFIXES = [
        'owych', 'owego', 'owemu', 'owymi', 'owej', 'owym', 'iego', 'ach', 'ami', 'ego', 'emu', 'ich', 'ych',
        'owe', 'owy', 'owa', 'om', 'ow', 'ie', 'es', 'a', 'e', 'i', 'o', 'u', 'y', 's',
    ];

    private const MIN_STEM = 4;

    /** @var array<string,array<int,string>> */
    private static array $stemCache = [];

    /**
     * Lowercase, fold diacritics, separate numbers from units, drop punctuation
     */
    public static function fold(string $text): string
    {
        $text = strtr(mb_strtolower($text), self::FOLD);
        $text = (string) preg_replace('/(\d)([a-z])/u', '$1 $2', $text);
        $text = (string) preg_replace('/[^a-z0-9]+/u', ' ', $text);

        return trim($text);
    }

    /**
     * Folded words without stop words
     *
     * @return array<int,string>
     */
    public static function words(string $text): array
    {
        $folded = self::fold($text);
        if ($folded === '') {
            return [];
        }

        return array_values(array_filter(
            explode(' ', $folded),
            static fn (string $w): bool => $w !== '' && !in_array($w, self::STOP_WORDS, true)
        ));
    }

    /**
     * Unique stems of a text (synonyms resolved to the Polish canonical word first)
     *
     * @return array<int,string>
     */
    public static function stems(string $text): array
    {
        if (isset(self::$stemCache[$text])) {
            return self::$stemCache[$text];
        }

        $stems = [];
        foreach (self::words($text) as $word) {
            $stems[] = self::stem(SynonymDictionary::canonical($word));
        }
        $stems = array_values(array_unique($stems));

        if (count(self::$stemCache) > 50000) {
            self::$stemCache = [];
        }

        return self::$stemCache[$text] = $stems;
    }

    public static function stem(string $word): string
    {
        if (ctype_digit($word)) {
            return $word;
        }

        foreach (self::SUFFIXES as $suffix) {
            $len = strlen($suffix);
            if (strlen($word) - $len >= self::MIN_STEM && substr($word, -$len) === $suffix) {
                return substr($word, 0, -$len);
            }
        }

        return $word;
    }

    private function __construct()
    {
    }
}
