<?php
/**
 * SynonymDictionary
 *
 * Small hand-made word map (folded words, no diacritics): English and
 * colloquial names resolved to the Polish word used in the InPost taxonomy.
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Service\Mapping\Suggest;

final class SynonymDictionary
{
    private const WORDS = [
        // attributes
        'color' => 'kolor', 'colour' => 'kolor',
        'size' => 'rozmiar', 'wielkosc' => 'rozmiar',
        'sklad' => 'material', 'composition' => 'material', 'tworzywo' => 'material',
        'brand' => 'marka', 'producent' => 'marka', 'manufacturer' => 'marka',
        'weight' => 'waga', 'masa' => 'waga',
        'width' => 'szerokosc', 'height' => 'wysokosc', 'length' => 'dlugosc', 'depth' => 'glebokosc',
        'capacity' => 'pojemnosc', 'power' => 'moc', 'gender' => 'plec', 'type' => 'rodzaj', 'typ' => 'rodzaj',
        'memory' => 'pamiec', 'age' => 'wiek',
        // categories
        'electronics' => 'elektronika', 'headphones' => 'sluchawki', 'earphones' => 'sluchawki',
        'phones' => 'telefony', 'phone' => 'telefony', 'smartphone' => 'smartfony', 'smartphones' => 'smartfony',
        'laptops' => 'laptopy', 'notebooki' => 'laptopy', 'notebook' => 'laptopy',
        'shoes' => 'obuwie', 'buty' => 'obuwie', 'sneakersy' => 'obuwie',
        'clothes' => 'odziez', 'clothing' => 'odziez', 'ubrania' => 'odziez',
        'furniture' => 'meble', 'books' => 'ksiazki', 'toys' => 'zabawki', 'tires' => 'opony', 'tyres' => 'opony',
        'bikes' => 'rowery', 'bicycles' => 'rowery', 'perfume' => 'perfumy', 'lighting' => 'oswietlenie',
        'garden' => 'ogrod', 'home' => 'dom', 'accessories' => 'akcesoria', 'men' => 'meska', 'women' => 'damska',
        'mezczyzn' => 'meska', 'kobiet' => 'damska',
        // values
        'black' => 'czarny', 'white' => 'bialy', 'red' => 'czerwony', 'blue' => 'niebieski', 'green' => 'zielony',
        'yellow' => 'zolty', 'grey' => 'szary', 'gray' => 'szary', 'brown' => 'brazowy', 'pink' => 'rozowy',
        'orange' => 'pomaranczowy', 'purple' => 'fioletowy', 'violet' => 'fioletowy', 'navy' => 'granatowy',
        'beige' => 'bezowy', 'silver' => 'srebrny', 'gold' => 'zloty', 'golden' => 'zloty', 'multicolor' => 'wielokolorowy',
        'cotton' => 'bawelna', 'wool' => 'welna', 'leather' => 'skora', 'polyester' => 'poliester', 'wood' => 'drewno',
        'steel' => 'stal', 'glass' => 'szklo', 'silicone' => 'silikon', 'plastic' => 'plastik',
    ];

    public static function canonical(string $foldedWord): string
    {
        return self::WORDS[$foldedWord] ?? $foldedWord;
    }

    private function __construct()
    {
    }
}
