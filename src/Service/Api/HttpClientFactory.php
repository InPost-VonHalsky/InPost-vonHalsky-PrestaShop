<?php
/**
 * HTTP Client Factory
 *
 * Factory for creating configured HTTP clients (Guzzle) with test mode support
 *
 * @author    Marcin Bach
 * @copyright 2025 Marcin Bach
 * @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Service\Api;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Inpost\VonHalsky\Helper\Config;

class HttpClientFactory
{
    private Config $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * Create HTTP client with appropriate configuration
     *
     * @return ClientInterface
     */
    public function createClient(): ClientInterface
    {
        $options = [
            'timeout' => 30,
            'connect_timeout' => 10,
            'http_errors' => false, // Don't throw exceptions on HTTP errors
            'verify' => !$this->config->isTestModeEnabled(), // Disable SSL verification in test mode
        ];

        // Add additional headers if needed
        $options['headers'] = [
            'User-Agent' => $this->getUserAgent(),
            'Accept' => 'application/json',
        ];

        return new Client($options);
    }

    /**
     * Get user agent string
     *
     * @return string
     */
    private function getUserAgent(): string
    {
        $userAgent = 'PrestaShop-InPost-Module/1.0.0';
        
        if ($this->config->isTestModeEnabled()) {
            $userAgent .= ' (test-mode)';
        }

        return $userAgent;
    }
}
