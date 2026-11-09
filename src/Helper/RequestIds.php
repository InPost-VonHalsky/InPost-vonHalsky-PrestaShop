<?php
/**
 * RequestIds Helper
 *
 * Normalizes lists of numeric ids coming from requests (bulk actions, AJAX).
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Helper;

final class RequestIds
{
    /**
     * Cast to int, drop non-positive values and duplicates, re-index.
     *
     * @param mixed $ids Scalar or array from the request
     * @return int[]
     */
    public static function positiveUnique($ids): array
    {
        return array_values(array_unique(array_filter(
            array_map('intval', (array) $ids),
            static fn (int $id): bool => $id > 0
        )));
    }
}
