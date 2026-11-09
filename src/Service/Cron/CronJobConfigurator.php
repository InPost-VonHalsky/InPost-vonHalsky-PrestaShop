<?php
/**
 * CronJobConfigurator
 *
 * Applies the back-office cron settings (enabled flag, schedule, batch limit) to
 * a cron job. Shared by the cron runner and the status panel, so the panel
 * reports exactly the schedule the runner uses.
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Service\Cron;

use Configuration;

class CronJobConfigurator
{
    /**
     * Configuration keys per job: [enabled, cron expression, limit]
     */
    private const JOB_CONFIG = [
        'order_sync' => [
            'INPOSTSA_ORDERS_CRON_ENABLED',
            'INPOSTSA_ORDERS_CRON_EXPRESSION',
            'INPOSTSA_ORDERS_CRON_LIMIT',
        ],
        'order_event_sync' => [
            'INPOSTSA_ORDER_EVENTS_CRON_ENABLED',
            'INPOSTSA_ORDER_EVENTS_CRON_EXPRESSION',
            'INPOSTSA_ORDER_EVENTS_CRON_LIMIT',
        ],
        'category_sync' => [
            'INPOSTSA_CRON_SYNC_CATEGORIES_ENABLED',
            'INPOSTSA_CRON_SYNC_CATEGORIES_SCHEDULE',
            'INPOSTSA_CRON_SYNC_CATEGORIES_LIMIT',
        ],
        'offer_sync' => [
            'INPOSTSA_CRON_SYNC_OFFERS_ENABLED',
            'INPOSTSA_CRON_SYNC_OFFERS_SCHEDULE',
            'INPOSTSA_CRON_SYNC_OFFERS_LIMIT',
        ],
        'offer_event_sync' => [
            'INPOSTSA_OFFER_EVENTS_CRON_ENABLED',
            'INPOSTSA_OFFER_EVENTS_CRON_EXPRESSION',
            'INPOSTSA_OFFER_EVENTS_CRON_LIMIT',
        ],
        'pending_command_sync' => [
            'INPOSTSA_CRON_SYNC_COMMANDS_ENABLED',
            'INPOSTSA_CRON_SYNC_COMMANDS_SCHEDULE',
            'INPOSTSA_CRON_SYNC_COMMANDS_LIMIT',
        ],
    ];

    /**
     * Apply configuration to the job. Unknown jobs keep their defaults.
     *
     * @param AbstractCronJob $job
     * @return bool Whether the job is known (had configuration applied)
     */
    public function configure(AbstractCronJob $job): bool
    {
        $keys = self::JOB_CONFIG[$job->getName()] ?? null;
        if ($keys === null) {
            return false;
        }

        [$enabledKey, $expressionKey, $limitKey] = $keys;

        $job->setEnabled((bool) Configuration::get($enabledKey));
        $job->setInterval($this->getIntervalFromCronExpression(
            (string) Configuration::get($expressionKey),
            $job->getInterval()
        ));

        $limit = (int) Configuration::get($limitKey);
        if ($limit > 0) {
            $job->setLimit($limit);
        }

        return true;
    }

    /**
     * Convert simple cron expressions into a best-effort interval in seconds.
     *
     * Supports cron expressions like every N minutes/hours/days.
     * Falls back to default interval if unrecognized.
     *
     * @param string|null $cronExpression
     * @param int $defaultInterval
     * @return int
     */
    public function getIntervalFromCronExpression(?string $cronExpression, int $defaultInterval): int
    {
        $expr = trim((string) $cronExpression);

        if ($expr === '') {
            return $defaultInterval;
        }

        // */N * * * *  -> every N minutes
        if (preg_match('#^\*/(\d+)\s+\*\s+\*\s+\*\s+\*$#', $expr, $matches)) {
            return max(60, (int) $matches[1] * 60);
        }

        // 0 */N * * *  -> every N hours
        if (preg_match('#^0\s+\*/(\d+)\s+\*\s+\*\s+\*$#', $expr, $matches)) {
            return max(3600, (int) $matches[1] * 3600);
        }

        // 0 0 */N * *  -> every N days
        if (preg_match('#^0\s+0\s+\*/?(\d+)\s+\*\s+\*$#', $expr, $matches)) {
            return max(86400, (int) $matches[1] * 86400);
        }

        return $defaultInterval;
    }
}
