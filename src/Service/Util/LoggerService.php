<?php
/**
 * LoggerService
 *
 * Centralized logging service wrapper for PSR-3 LoggerInterface.
 * Provides context enrichment and consistent logging patterns across the module.
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Service\Util;

use Inpost\VonHalsky\Repository\LogJournalRepository;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

class LoggerService
{
    /** @var LoggerInterface @readonly */
    private $logger;

    /**
     * Module context prefix for all log entries
     */
    private const MODULE_CONTEXT = 'InPost von Halsky';

    /**
     * Levels copied into the status panel journal
     */
    private const JOURNAL_LEVELS = [
        LogLevel::WARNING,
        LogLevel::ERROR,
        LogLevel::CRITICAL,
        LogLevel::ALERT,
        LogLevel::EMERGENCY,
    ];

    /** @var LogJournalRepository|null */
    private $journal;

    /**
     * Constructor
     *
     * @param LoggerInterface $logger PSR-3 logger instance
     * @param LogJournalRepository|null $journal Status panel journal (warnings and errors)
     */
    public function __construct(LoggerInterface $logger, ?LogJournalRepository $journal = null)
    {
        $this->logger = $logger;
        $this->journal = $journal;
    }

    /**
     * System is unusable
     *
     * @param string $message Log message
     * @param array $context Additional context data
     * @return void
     */
    public function emergency(string $message, array $context = []): void
    {
        $this->log(LogLevel::EMERGENCY, $message, $context);
    }

    /**
     * Action must be taken immediately
     *
     * @param string $message Log message
     * @param array $context Additional context data
     * @return void
     */
    public function alert(string $message, array $context = []): void
    {
        $this->log(LogLevel::ALERT, $message, $context);
    }

    /**
     * Critical conditions
     *
     * @param string $message Log message
     * @param array $context Additional context data
     * @return void
     */
    public function critical(string $message, array $context = []): void
    {
        $this->log(LogLevel::CRITICAL, $message, $context);
    }

    /**
     * Runtime errors that do not require immediate action
     *
     * @param string $message Log message
     * @param array $context Additional context data
     * @return void
     */
    public function error(string $message, array $context = []): void
    {
        $this->log(LogLevel::ERROR, $message, $context);
    }

    /**
     * Exceptional occurrences that are not errors
     *
     * @param string $message Log message
     * @param array $context Additional context data
     * @return void
     */
    public function warning(string $message, array $context = []): void
    {
        $this->log(LogLevel::WARNING, $message, $context);
    }

    /**
     * Normal but significant events
     *
     * @param string $message Log message
     * @param array $context Additional context data
     * @return void
     */
    public function notice(string $message, array $context = []): void
    {
        $this->log(LogLevel::NOTICE, $message, $context);
    }

    /**
     * Interesting events
     *
     * @param string $message Log message
     * @param array $context Additional context data
     * @return void
     */
    public function info(string $message, array $context = []): void
    {
        $this->log(LogLevel::INFO, $message, $context);
    }

    /**
     * Detailed debug information
     *
     * @param string $message Log message
     * @param array $context Additional context data
     * @return void
     */
    public function debug(string $message, array $context = []): void
    {
        $this->log(LogLevel::DEBUG, $message, $context);
    }

    /**
     * Log with arbitrary level
     *
     * @param string $level Log level (use LogLevel constants)
     * @param string $message Log message
     * @param array $context Additional context data
     * @return void
     */
    public function log(string $level, string $message, array $context = []): void
    {
        // Routine messages only with "debug API logging" on: the PSR logger forwards
        // them to ps_log, where every insert scans the whole (unindexed) table for
        // duplicates - on a big ps_log that made each log call cost 0.1 s or more.
        if (!in_array($level, self::JOURNAL_LEVELS, true) && !DebugLog::isEnabled()) {
            return;
        }

        // Enrich context with module information
        $enrichedContext = $this->enrichContext($context);

        // Delegate to underlying PSR-3 logger
        $this->logger->log($level, $message, $enrichedContext);

        if ($this->journal !== null && in_array($level, self::JOURNAL_LEVELS, true)) {
            $this->journal->record($level, $message, $context);
        }
    }

    /**
     * Log exception with full context
     *
     * Logs complete exception details including stack trace to both
     * the exception channel and standard app log for searchability.
     *
     * @param \Throwable $exception Exception to log
     * @param array $context Additional context (controller, route, etc.)
     * @return void
     */
    public function logException(\Throwable $exception, array $context = []): void
    {
        // Build comprehensive exception context
        $exceptionContext = array_merge([
            'exception_class' => get_class($exception),
            'exception_message' => $exception->getMessage(),
            'exception_code' => $exception->getCode(),
            'exception_file' => $exception->getFile(),
            'exception_line' => $exception->getLine(),
            'exception_trace' => $exception->getTraceAsString(),
            'timestamp' => date('Y-m-d H:i:s')
        ], $context);

        // Add request context if available
        if (isset($_SERVER['REQUEST_METHOD'])) {
            $exceptionContext['request_method'] = $_SERVER['REQUEST_METHOD'];
        }
        if (isset($_SERVER['REQUEST_URI'])) {
            $exceptionContext['request_uri'] = $_SERVER['REQUEST_URI'];
        }

        // Log to error level (will be captured by exception channel)
        $this->error(
            sprintf(
                'Exception: %s - %s',
                get_class($exception),
                $exception->getMessage()
            ),
            $exceptionContext
        );
    }

    /**
     * Enrich context with module-level information
     *
     * @param array $context Original context
     * @return array Enriched context
     */
    private function enrichContext(array $context): array
    {
        return array_merge([
            'module' => self::MODULE_CONTEXT,
            'php_version' => PHP_VERSION,
            'memory_usage' => $this->formatBytes(memory_get_usage(true))
        ], $context);
    }

    /**
     * Format bytes to human-readable string
     *
     * @param int $bytes Number of bytes
     * @param int $precision Decimal precision
     * @return string Formatted string (e.g., "1.5 MB")
     */
    private function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, $precision) . ' ' . $units[$i];
    }
}
