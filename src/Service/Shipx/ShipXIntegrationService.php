<?php
/**
 * InPost ShipX integration
 *
 * Bridges accepted InPost von Halsky orders to the InPost ShipX (`inpostshipping`)
 * shipping module: creates a shipment record (profiling recipient email / address /
 * phone) and — when auto-create is enabled — pushes the shipment to ShipX so a label
 * is generated fully automatically.
 *
 * The whole flow is opt-in (module Configuration → Shipping & Payment) and fail-open:
 * any problem is logged and never interrupts order import.
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Service\Shipx;

use Inpost\VonHalsky\Entity\InpostOrder;
use Inpost\VonHalsky\Service\Util\ConfigurationService;
use Inpost\VonHalsky\Service\Util\LoggerService;

/**
 * Creates ShipX shipments for accepted InPost orders via the installed
 * `inpostshipping` module (v2.16.2+). All calls into that module are guarded so the
 * service is a no-op when it is not installed / not configured / integration disabled.
 */
class ShipXIntegrationService
{
    private const SHIPPING_MODULE = 'inpostshipping';

    /** inpostshipping public service ids (see its config/*.yml). */
    private const SVC_SHIPX_CONFIG = 'inpost.shipping.configuration.shipx';
    private const SVC_SENDING_CONFIG = 'inpost.shipping.configuration.sending';
    private const SVC_CREATE_HANDLER = 'inpost.shipping.handler.shipment.create';

    /** ShipX sending methods (string values from InPost\Shipping\ShipX\Resource\SendingMethod). */
    private const SENDING_PARCEL_LOCKER = 'parcel_locker';
    private const SENDING_POP = 'pop';

    private const DEFAULT_TEMPLATE = 'small';

    /**
     * @var ConfigurationService
     */
    private $config;

    /**
     * @var LoggerService
     */
    private $logger;

    public function __construct(ConfigurationService $config, LoggerService $logger)
    {
        $this->config = $config;
        $this->logger = $logger;
    }

    /**
     * Whether the ShipX integration can run: the `inpostshipping` module is installed
     * and active, our integration toggle is on, and ShipX has API credentials.
     */
    public function isEnabled(): bool
    {
        if (!$this->config->getBool(ConfigurationService::SHIPX_INTEGRATION_ENABLED)) {
            return false;
        }

        if (!\Module::isInstalled(self::SHIPPING_MODULE) || !\Module::isEnabled(self::SHIPPING_MODULE)) {
            return false;
        }

        $shipXConfig = $this->getShippingService(self::SVC_SHIPX_CONFIG);
        if ($shipXConfig === null || !method_exists($shipXConfig, 'hasConfiguration')) {
            return false;
        }

        return (bool) $shipXConfig->hasConfiguration();
    }

    /**
     * Entry point: called once, right after a PrestaShop order has been created for an
     * accepted InPost order. Never throws.
     *
     * @param InpostOrder $inpostOrder The local InPost order entity (holds receiver + point data)
     * @param int         $psOrderId   The freshly created PrestaShop order id
     */
    public function handleAcceptedOrder(InpostOrder $inpostOrder, int $psOrderId): void
    {
        try {
            if ($psOrderId <= 0 || !$this->isEnabled()) {
                return;
            }

            $module = \Module::getInstanceByName(self::SHIPPING_MODULE);
            if (!$module) {
                return;
            }

            $shipXConfig = $this->getShippingService(self::SVC_SHIPX_CONFIG);
            $organizationId = (int) $shipXConfig->getOrganizationId();
            $sandbox = (bool) $shipXConfig->useSandboxMode();

            // Do not create a second shipment for the same order (single create handler
            // does not dedupe on its own).
            if ($this->shipmentAlreadyExists($psOrderId, $sandbox, $organizationId)) {
                $this->logger->info('[ShipX] Shipment already exists for order ' . $psOrderId . ', skipping');
                return;
            }

            // Locker (paczkomat) orders carry a pickup-point code; courier orders do not.
            $lockerPoint = trim((string) $inpostOrder->getPointName());
            $isLocker = $lockerPoint !== '';

            $carrierId = $isLocker
                ? $this->config->getInt(ConfigurationService::SHIPX_CARRIER_LOCKER)
                : $this->config->getInt(ConfigurationService::SHIPX_CARRIER_COURIER);

            if ($carrierId <= 0) {
                $this->logger->warning(
                    '[ShipX] No ShipX carrier mapped for ' . ($isLocker ? 'locker' : 'courier')
                    . ' delivery; skipping shipment for order ' . $psOrderId
                );
                return;
            }

            $carrierData = \InPostCarrierModel::getDataByCarrierId($carrierId);
            if (!$carrierData || empty($carrierData['service'])) {
                $this->logger->warning(
                    '[ShipX] Mapped carrier #' . $carrierId . ' is not an active InPost ShipX carrier'
                );
                return;
            }

            $email = $this->resolveEmail($inpostOrder, $psOrderId);
            $phone = $this->formatPhone((string) $inpostOrder->getReceiverPhone());
            $targetPoint = $isLocker ? $lockerPoint : '';
            $template = $this->config->getString(
                ConfigurationService::SHIPX_DEFAULT_PARCEL_TEMPLATE,
                self::DEFAULT_TEMPLATE
            );

            if ($this->config->getBool(ConfigurationService::SHIPX_AUTO_SHIPMENT_ENABLED)) {
                $this->createViaHandler(
                    $psOrderId,
                    (array) $carrierData,
                    $email,
                    $phone,
                    $targetPoint,
                    $template
                );
            } else {
                $this->createDraftRecord(
                    $psOrderId,
                    (string) $carrierData['service'],
                    $email,
                    $phone,
                    $targetPoint,
                    $template,
                    $organizationId,
                    $sandbox
                );
            }
        } catch (\Throwable $e) {
            $this->logger->error('[ShipX] Failed to create shipment for order ' . $psOrderId . ': ' . $e->getMessage());
        }
    }

    /**
     * Auto path: push the shipment to ShipX through the module's CreateShipmentHandler
     * (creates the record, calls the ShipX API and stores the tracking number).
     *
     * @param array  $carrierData Result of InPostCarrierModel::getDataByCarrierId()
     */
    private function createViaHandler(
        int $psOrderId,
        array $carrierData,
        string $email,
        string $phone,
        string $targetPoint,
        string $template
    ): void {
        $handler = $this->getShippingService(self::SVC_CREATE_HANDLER);
        if ($handler === null || !method_exists($handler, 'handle')) {
            $this->logger->warning('[ShipX] CreateShipmentHandler unavailable; cannot auto-create shipment');
            return;
        }

        [$sendingMethod, $dropoffLocker, $dropoffPop] = $this->resolveSenderDispatch();

        $request = [
            'id_order' => $psOrderId,
            'service' => (string) $carrierData['service'],
            'email' => $email,
            'phone' => $phone,
            'sending_method' => $sendingMethod,
            'dropoff_locker' => $dropoffLocker,
            'dropoff_pop' => $dropoffPop,
            'target_point' => $targetPoint,
            'commercial_product_identifier' => (string) ($carrierData['commercialProductIdentifier'] ?? ''),
            'weekend_delivery' => (bool) ($carrierData['weekendDelivery'] ?? false),
            'reference' => 'InPost #' . $psOrderId,
            'cod' => false,
            'cod_amount' => '0',
            'insurance' => false,
            'insurance_amount' => '0',
            'send_sms' => (bool) ($carrierData['sendSms'] ?? false),
            'send_email' => (bool) ($carrierData['sendEmail'] ?? false),
            'parcels' => [
                [
                    'use_template' => true,
                    'template' => $template,
                    'is_non_standard' => (bool) ($carrierData['isNonStandard'] ?? false),
                ],
            ],
        ];

        $shipment = $handler->handle($request);

        if ($shipment === false) {
            $errors = method_exists($handler, 'getErrors') ? $handler->getErrors() : [];
            $this->logger->error(
                '[ShipX] Auto shipment creation failed for order ' . $psOrderId . ': ' . json_encode($errors)
            );
            return;
        }

        $this->logger->info('[ShipX] Auto-created ShipX shipment for order ' . $psOrderId);
    }

    /**
     * Non-auto path: persist a draft shipment record (no API call) so it shows up in the
     * inpostshipping shipment list for the merchant to complete and send manually.
     */
    private function createDraftRecord(
        int $psOrderId,
        string $service,
        string $email,
        string $phone,
        string $targetPoint,
        string $template,
        int $organizationId,
        bool $sandbox
    ): void {
        $shipment = new \InPostShipmentModel();
        $shipment->setOrder(new \Order($psOrderId));
        $shipment->organization_id = $organizationId;
        $shipment->sandbox = $sandbox;
        $shipment->email = $email;
        $shipment->phone = $phone;
        $shipment->service = $service;
        if ($targetPoint !== '') {
            $shipment->target_point = $targetPoint;
        }
        $shipment->status = 'created';

        $parcel = new \InPostParcelModel();
        $parcel->template = $template;

        $shipment->addParcel($parcel);
        $shipment->add();

        $this->logger->info('[ShipX] Created draft ShipX shipment record for order ' . $psOrderId);
    }

    /**
     * Resolve the merchant's default sender dispatch (sending method + drop-off point)
     * from the inpostshipping SendingConfiguration.
     *
     * @return array{0:string,1:string,2:string} [sending_method, dropoff_locker, dropoff_pop]
     */
    private function resolveSenderDispatch(): array
    {
        $sending = $this->getShippingService(self::SVC_SENDING_CONFIG);
        if ($sending === null) {
            return ['', '', ''];
        }

        $sendingMethod = method_exists($sending, 'getDefaultSendingMethod')
            ? (string) $sending->getDefaultSendingMethod()
            : '';

        $dropoffLocker = '';
        $dropoffPop = '';
        if ($sendingMethod === self::SENDING_PARCEL_LOCKER && method_exists($sending, 'getDefaultLocker')) {
            $dropoffLocker = (string) $sending->getDefaultLocker();
        } elseif ($sendingMethod === self::SENDING_POP && method_exists($sending, 'getDefaultPop')) {
            $dropoffPop = (string) $sending->getDefaultPop();
        }

        return [$sendingMethod, $dropoffLocker, $dropoffPop];
    }

    /**
     * Check whether the order already has a ShipX shipment (same org + sandbox scope).
     */
    private function shipmentAlreadyExists(int $psOrderId, bool $sandbox, int $organizationId): bool
    {
        if (!method_exists('\InPostShipmentModel', 'getShipmentIdsByOrderIds')) {
            return false;
        }

        $ids = \InPostShipmentModel::getShipmentIdsByOrderIds([$psOrderId], $sandbox, $organizationId);

        return !empty($ids);
    }

    /**
     * Receiver email — prefer the InPost order email, fall back to the PrestaShop order customer.
     */
    private function resolveEmail(InpostOrder $inpostOrder, int $psOrderId): string
    {
        $email = trim((string) $inpostOrder->getReceiverEmail());
        if ($email !== '') {
            return $email;
        }

        $order = new \Order($psOrderId);
        if (\Validate::isLoadedObject($order)) {
            $customer = new \Customer((int) $order->id_customer);
            if (\Validate::isLoadedObject($customer)) {
                return (string) $customer->email;
            }
        }

        return '';
    }

    /**
     * Normalize a phone number for ShipX (uses the module helper when available).
     */
    private function formatPhone(string $phone): string
    {
        if (class_exists('\InPostCartChoiceModel') && method_exists('\InPostCartChoiceModel', 'formatPhone')) {
            return (string) \InPostCartChoiceModel::formatPhone($phone);
        }

        return preg_replace('/\D+/', '', $phone) ?? '';
    }

    /**
     * Fetch a public service from the inpostshipping module container. Null-safe.
     *
     * @return mixed|null
     */
    private function getShippingService(string $serviceId)
    {
        try {
            $module = \Module::getInstanceByName(self::SHIPPING_MODULE);
            if (!$module || !method_exists($module, 'getService')) {
                return null;
            }

            return $module->getService($serviceId);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
