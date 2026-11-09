<?php
/**
 * AttributeSource
 *
 * Kinds of PrestaShop data an InPost category attribute can take its value from.
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Service\Mapping;

final class AttributeSource
{
    /** Product feature (Catalog > Attributes & Features > Features) - fixed product parameter */
    public const FEATURE = 'feature';

    /** Attribute group (Catalog > Attributes & Features > Attributes) - defines combinations */
    public const ATTRIBUTE_GROUP = 'attribute_group';

    /** Built-in product field (reference, MPN, EAN, weight... - ProductField id) */
    public const PRODUCT_FIELD = 'product_field';

    /** Source type => key of its list in PsAttributeSourceProvider::getSources() */
    public const LISTS = [
        self::FEATURE => 'features',
        self::ATTRIBUTE_GROUP => 'attribute_groups',
        self::PRODUCT_FIELD => 'product_fields',
    ];

    public static function isValid(?string $type): bool
    {
        return $type !== null && isset(self::LISTS[$type]);
    }

    private function __construct()
    {
    }
}
