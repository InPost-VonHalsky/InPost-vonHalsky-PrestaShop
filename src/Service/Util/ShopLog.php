<?php
/**
 * ShopLog
 *
 * Writes to the PrestaShop log (Advanced Parameters > Logs) WITHOUT the duplicate
 * check: PrestaShopLogger::addLog() otherwise runs
 * SELECT COUNT(*) FROM ps_log WHERE message = "..." before every insert - a full
 * scan of the unindexed table, which takes seconds per entry on shops with
 * millions of log rows (an order import logs several entries per order).
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Service\Util;

use PrestaShopLogger;

final class ShopLog
{
    /**
     * Same arguments as PrestaShopLogger::addLog(), duplicates always allowed
     *
     * @param string $message
     * @param int $severity 1 informative, 2 warning, 3 error, 4 major issue
     * @param int|null $errorCode
     * @param string|null $objectType
     * @param int|null $objectId
     */
    public static function add($message, $severity = 1, $errorCode = null, $objectType = null, $objectId = null): bool
    {
        return (bool) PrestaShopLogger::addLog($message, $severity, $errorCode, $objectType, $objectId, true);
    }
}
