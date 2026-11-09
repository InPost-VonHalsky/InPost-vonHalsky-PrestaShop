<?php
/**
 * DebugLog
 *
 * Info-level PrestaShop log entries (ps_log) written only when
 * INPOSTSA_DEBUG_API_LOGGING is enabled. Every ShopLog::add() runs
 * a duplicate check that scans the whole unindexed ps_log table, so routine
 * per-request / per-cron-run messages must not go there by default.
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Service\Util;

use Configuration;

final class DebugLog
{
    public static function isEnabled(): bool
    {
        return (bool) Configuration::get(ConfigurationService::DEBUG_API_LOGGING);
    }

    /**
     * Write an info entry to ps_log when debug logging is enabled
     */
    public static function info(string $message): void
    {
        if (self::isEnabled()) {
            ShopLog::add($message, 1);
        }
    }
}
