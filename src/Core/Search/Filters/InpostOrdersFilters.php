<?php
/**
 * InPost Orders Filters
 *
 * Stores filters for InPost Orders grid
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Core\Search\Filters;

use PrestaShop\PrestaShop\Core\Search\Filters;

/**
 * Stores filters for InPost Orders grid
 */
final class InpostOrdersFilters extends Filters
{
    /** @var string */
    protected $filterId = 'inpost_orders';

    /**
     * {@inheritdoc}
     */
    public static function getDefaults()
    {
        return [
            'limit' => 50,
            'offset' => 0,
            'orderBy' => 'id_inpost_order',
            'sortOrder' => 'DESC',
            'filters' => [],
        ];
    }
}


