<?php

declare(strict_types=1);

namespace Inpost\VonHalsky\Service\Util;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Dedicated InPost logger service with file-based logging
 *
 * Writes to: var/logs/inpost.log
 * Levels: DEBUG, INFO, WARNING, ERROR
 */
class InpostLoggerService implements LoggerInterface
{
    private const LOG_FILE = 'inpost.log';

    private string $logPath;
    private bool $enabled;

    public function __construct()
    {
        // PrestaShop log directory
        $this->logPath = _PS_ROOT_DIR_ . '/var/logs/' . self::LOG_FILE;

        // Check if debug API logging is enabled in config
        $this->enabled = (bool) \Configuration::get('INPOSTSA_DEBUG_API_LOGGING');

        // Ensure log directory exists (even if disabled, for when it gets enabled)
        $logDir = dirname($this->logPath);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function emergency($message, array $context = []): void
    {
        $this->log(LogLevel::EMERGENCY, $message, $context);
    }

    /**
     * {@inheritdoc}
     */
    public function alert($message, array $context = []): void
    {
        $this->log(LogLevel::ALERT, $message, $context);
    }

    /**
     * {@inheritdoc}
     */
    public function critical($message, array $context = []): void
    {
        $this->log(LogLevel::CRITICAL, $message, $context);
    }

    /**
     * {@inheritdoc}
     */
    public function error($message, array $context = []): void
    {
        $this->log(LogLevel::ERROR, $message, $context);
    }

    /**
     * {@inheritdoc}
     */
    public function warning($message, array $context = []): void
    {
        $this->log(LogLevel::WARNING, $message, $context);
    }

    /**
     * {@inheritdoc}
     */
    public function notice($message, array $context = []): void
    {
        $this->log(LogLevel::NOTICE, $message, $context);
    }

    /**
     * {@inheritdoc}
     */
    public function info($message, array $context = []): void
    {
        $this->log(LogLevel::INFO, $message, $context);
    }

    /**
     * {@inheritdoc}
     */
    public function debug($message, array $context = []): void
    {
        $this->log(LogLevel::DEBUG, $message, $context);
    }

    /**
     * {@inheritdoc}
     */
    public function log($level, $message, array $context = []): void
    {
        if (!$this->enabled) {
            return;
        }

        $timestamp = date('Y-m-d H:i:s');
        $levelStr = strtoupper($level);

        // Format context as JSON for readability
        $contextStr = !empty($context) ? ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';

        // Format: [2025-01-22 14:30:45] INFO: Message {context}
        $logLine = sprintf(
            "[%s] %s: %s%s\n",
            $timestamp,
            $levelStr,
            $message,
            $contextStr
        );

        // Write to file (append mode)
        file_put_contents($this->logPath, $logLine, FILE_APPEND | LOCK_EX);
    }

    /**
     * Enable/disable logging
     */
    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

}
