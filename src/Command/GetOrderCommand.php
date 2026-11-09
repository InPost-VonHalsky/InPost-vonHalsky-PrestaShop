<?php

declare(strict_types=1);

namespace Inpost\VonHalsky\Command;

use Inpost\VonHalsky\Service\Api\OrdersService;
use Inpost\VonHalsky\Service\ApiTokenManager;
use Inpost\VonHalsky\Service\Util\ConfigurationService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class GetOrderCommand extends Command
{
    protected static $defaultName = 'inpost:orders:get';

    private OrdersService $ordersService;
    private ConfigurationService $configService;
    private ApiTokenManager $tokenManager;

    public function __construct(
        OrdersService $ordersService,
        ConfigurationService $configService,
        ApiTokenManager $tokenManager
    ) {
        parent::__construct();
        $this->ordersService = $ordersService;
        $this->configService = $configService;
        $this->tokenManager = $tokenManager;
    }

    protected function configure(): void
    {
        $this
            ->setName('inpost:orders:get')
            ->setDescription('Get single order from InPost API (debug/test)')
            ->setHelp('Fetches a single order by ID and displays debug information')
            ->addArgument('order-id', InputArgument::REQUIRED, 'InPost Order ID (UUID)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $orderId = $input->getArgument('order-id');

        $io->title('InPost Order API Test');
        $io->writeln("Order ID: <info>{$orderId}</info>\n");

        // 1. Configuration
        $io->section('Configuration');
        $io->table(['Setting', 'Value'], [
            ['API URL', $this->configService->get(ConfigurationService::API_URL, 'Not set')],
            ['Organization ID', $this->configService->get(ConfigurationService::ORGANIZATION_ID, 'Not set')],
            ['Test Mode', $this->configService->get(ConfigurationService::TEST_MODE) ? 'Yes' : 'No'],
        ]);

        // 2. OAuth2 Token
        $io->section('OAuth2 Token');
        $token = $this->tokenManager->getAccessToken();
        if ($token) {
            $io->success('Token available');

            $expiresAt = $this->tokenManager->getAccessTokenExpiration();
            if ($expiresAt) {
                $expiresIn = $expiresAt - time();
                $io->writeln('Expires in: ' . ($expiresIn > 0 ? "{$expiresIn}s" : 'EXPIRED'));
            }
        } else {
            $io->error('No access token available!');
            return 1;
        }

        // 3. API Call
        $io->section('API Call');
        $io->writeln('Calling OrdersService::getOrder()...');

        try {
            $result = $this->ordersService->getOrder($orderId, 'pl');
        } catch (\Exception $e) {
            $io->error('Exception: ' . $e->getMessage());
            return 1;
        }

        // 4. Response
        $io->section('Response');
        $io->table(['Key', 'Value'], [
            ['Success', $result['success'] ? 'Yes' : 'No'],
            ['Status Code', $result['status_code'] ?? 'N/A'],
            ['Error', $result['error'] ?? 'None'],
        ]);

        // 5. Data
        $io->section('Response Data');
        $data = $result['data'] ?? null;

        if ($data === null) {
            $io->warning('Data is NULL');
        } elseif (is_object($data)) {
            $io->writeln('Type: <info>' . get_class($data) . '</info>');

            // Debug: show all available methods
            $methods = array_filter(get_class_methods($data), fn($m) => str_starts_with($m, 'get'));
            $io->writeln('Available getters: ' . implode(', ', $methods));

            // Try to serialize the whole object
            if ($data instanceof \JsonSerializable) {
                $io->newLine();
                $io->writeln('<comment>Full Object (jsonSerialize):</comment>');
                $io->writeln(json_encode($data->jsonSerialize(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            }

            // Also try getOrder
            if (method_exists($data, 'getOrder')) {
                $order = $data->getOrder();
                $io->newLine();
                $io->writeln('getOrder(): ' . ($order ? 'Present (' . get_class($order) . ')' : 'NULL'));

                if ($order && $order instanceof \JsonSerializable) {
                    $io->writeln('<comment>Order Data:</comment>');
                    $io->writeln(json_encode($order->jsonSerialize(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                }
            }
        } else {
            $io->writeln('Raw data (type: ' . gettype($data) . '):');
            $io->writeln(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        return $result['success'] ? 0 : 1;
    }
}
