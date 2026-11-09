<?php
/**
 * AbstractCronJob
 *
 * Base abstract class for cron jobs.
 * All cron jobs must extend this class.
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Service\Cron;

abstract class AbstractCronJob
{
    /**
     * @var bool Whether the job is enabled
     */
    protected $enabled = true;

    /**
     * @var int Minimum interval between runs in seconds (0 = no restriction)
     */
    protected $interval = 0;

    /**
     * @var int Batch limit for processing items (0 = no limit)
     */
    protected int $limit = 0;

    /**
     * Get the unique name of this job
     *
     * @return string
     */
    abstract public function getName(): string;

    /**
     * Execute the job
     *
     * @return array Result data
     */
    abstract public function execute(): array;

    /**
     * Check if the job is enabled
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Set whether the job is enabled
     *
     * @param bool $enabled
     * @return void
     */
    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    /**
     * Get the minimum interval between runs in seconds
     *
     * @return int Interval in seconds (0 = no restriction)
     */
    public function getInterval(): int
    {
        return $this->interval;
    }

    /**
     * Set the minimum interval between runs
     *
     * @param int $interval Interval in seconds
     * @return void
     */
    public function setInterval(int $interval): void
    {
        $this->interval = $interval;
    }

    /**
     * Get the batch limit for processing items
     *
     * @return int Limit (0 = no limit)
     */
    public function getLimit(): int
    {
        return $this->limit;
    }

    /**
     * Set the batch limit for processing items
     *
     * @param int $limit Number of items to process per batch
     * @return void
     */
    public function setLimit(int $limit): void
    {
        $this->limit = $limit;
    }
}
