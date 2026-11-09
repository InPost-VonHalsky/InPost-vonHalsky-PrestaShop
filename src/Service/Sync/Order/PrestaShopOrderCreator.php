<?php
/**
 * PrestaShop Order Creator
 *
 * Responsible for creating and updating PrestaShop orders from InPost API data
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Service\Sync\Order;

use Inpost\VonHalsky\Service\Util\ShopLog;
use Configuration;
use Customer;
use Address;
use Product;
use Cart;
use Order;
use OrderHistory;
use OrderCarrier;
use OrderInvoice;
use Currency;
use Country;
use State;
use Validate;
use Context;
use Module;
use PaymentModule;
use Db;
use Inpost\VonHalsky\Service\Util\DebugLog;
use Exception;
use TaxManagerFactory;
use SpecificPrice;
use Inpost\VonHalsky\Repository\InpostOrderRepository;
use Inpost\VonHalsky\Factory\CartFactory;
use InpostApi\Model\OrderStatus;
use InpostApi\Model\PaymentType;

/**
 * Class responsible for creating PrestaShop orders from InPost API data
 */
class PrestaShopOrderCreator
{
    /**
     * Start of the private order note listing order lines that were not matched
     * to a shop product (OrderWarnings detects the orders by it)
     */
    public const UNMATCHED_PRODUCTS_NOTE = '[InPost] Products not found in the shop';

    /**
     * Order lines of the order being created that fell back to the fallback product
     *
     * @var array<int, array{sku: string, offerId: string, externalId: string, fallback: int|null}>
     */
    private array $unmatchedLines = [];

    /**
     * Why the last createOrUpdateOrder() call did not create the order (null on success)
     */
    private ?string $lastError = null;

    /**
     * InPost statuses that end the order - mapped to the PrestaShop "canceled" state
     */
    /**
     * InPost payment types shown on the order (translated in Modules.Inpostvonhalsky.Admin)
     */
    private const PAYMENT_TYPE_LABELS = [
        PaymentType::CARD => 'Card',
        PaymentType::CARD_TOKEN => 'Card',
        PaymentType::GOOGLE_PAY => 'Google Pay',
        PaymentType::APPLE_PAY => 'Apple Pay',
        PaymentType::BLIK_CODE => 'BLIK',
        PaymentType::BLIK_TOKEN => 'BLIK',
        PaymentType::PAY_BY_LINK => 'Pay by link',
        PaymentType::SHOPPING_LIMIT => 'Shopping limit',
        PaymentType::DEFERRED_PAYMENT => 'Deferred payment',
        PaymentType::CASH_ON_DELIVERY => 'Cash on delivery',
    ];

    private const CANCELLED_STATUSES = [
        OrderStatus::CANCELED,
        OrderStatus::REFUSED,
        OrderStatus::REJECTED,
    ];

    /**
     * @var InpostOrderRepository
     */
    private $inpostOrderRepository;

    /**
     * @var CartFactory
     */
    private $cartFactory;

    /**
     * Constructor
     *
     * @param InpostOrderRepository $inpostOrderRepository
     * @param CartFactory $cartFactory
     */
    public function __construct(
        InpostOrderRepository $inpostOrderRepository,
        CartFactory $cartFactory
    ) {
        $this->inpostOrderRepository = $inpostOrderRepository;
        $this->cartFactory = $cartFactory;
    }

    /**
     * Create or update PrestaShop order from InPost order data
     *
     * @param array $customerData Customer data extracted from API
     * @param array $products Products data extracted from API
     * @param array|null $deliveryAddress Delivery address data
     * @param array|null $finalPrice Final price data
     * @param string $inpostStatus InPost order status
     * @param string $inpostOrderId InPost order ID
     * @param int|null $existingOrderId Existing PrestaShop order ID (if updating)
     * @param float $shippingCost InPost delivery price (gross)
     * @param array{type: string|null, payments: array<int, array{id: string|null, type: string|null, date: int|null}>}|null $payment
     *        InPost payment details (paymentDetails of the order)
     * @param array|null $invoiceData Invoice details (company, NIP, address), see OrderSyncService::extractInvoiceData()
     * @return int|null PrestaShop order ID or null on error
     */
    public function createOrUpdateOrder(
        array $customerData,
        array $products,
        $deliveryAddress,
        $finalPrice,
        string $inpostStatus,
        string $inpostOrderId,
        $existingOrderId = null,
        float $shippingCost = 0.0,
        ?array $payment = null,
        ?array $invoiceData = null
    ): ?int {
        DebugLog::info('[InPost Order Creator] createOrUpdateOrder called for InPost order: ' . $inpostOrderId);
        $this->lastError = null;

        if ($existingOrderId && $existingOrderId > 0) {
            return $this->updateExistingOrder((int) $existingOrderId, $inpostStatus);
        }

        return $this->createNewOrder(
            $customerData,
            $products,
            $deliveryAddress,
            $finalPrice,
            $inpostStatus,
            $inpostOrderId,
            $shippingCost,
            $payment,
            $invoiceData
        );
    }

    /**
     * Why the last createOrUpdateOrder() call did not create the shop order, null when it did
     */
    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Log an order creation error and remember it as the reason of the failure
     */
    private function logError(string $message, ?string $inpostOrderId = null): void
    {
        $this->lastError = $message;
        ShopLog::add(
            '[InPost Order Creator] ' . ($inpostOrderId !== null ? '[' . $inpostOrderId . '] ' : '') . $message,
            3
        );
    }

    /**
     * Create new PrestaShop order
     *
     * @param array $customerData
     * @param array $products
     * @param array|null $deliveryAddress
     * @param array|null $finalPrice
     * @param string $inpostStatus
     * @param string $inpostOrderId
     * @return int|null
     */
    private function createNewOrder(
        array $customerData,
        array $products,
        $deliveryAddress,
        $finalPrice,
        string $inpostStatus,
        string $inpostOrderId,
        float $shippingCost = 0.0,
        ?array $payment = null,
        ?array $invoiceData = null
    ): ?int {
        try {
            if (empty($customerData['email'])) {
                $this->logError('Cannot create order: customer email is required', $inpostOrderId);
                return null;
            }

            $customerId = $this->findOrCreateCustomer($customerData);
            if (!$customerId) {
                $this->logError('Cannot create order: failed to find or create customer ' . $customerData['email'], $inpostOrderId);
                return null;
            }

            if (empty($products)) {
                $this->logError('Cannot create order: the InPost order has no products', $inpostOrderId);
                return null;
            }

            $context = Context::getContext();
            $idLang = $context->language->id;
            $idShop = $context->shop->id;
            $idCurrency = $this->getCurrencyId($finalPrice);

            // The cron (CLI) context has no currency, but cart/price computations read
            // Context::getComputingPrecision() -> $context->currency->precision
            // ("Attempt to read property "precision" on null").
            if (!Validate::isLoadedObject($context->currency)) {
                $context->currency = new Currency($idCurrency, null, (int) $idShop);
            }

            $cart = $this->createCart($customerId, $idLang, $idShop, $idCurrency);
            if (!$cart) {
                $this->logError('Cannot create order: the cart could not be saved', $inpostOrderId);
                return null;
            }

            // Addresses before the products: a cart line without a delivery address gets the
            // first address of the context customer - in a batch that is the previous
            // order's customer (validateOrder() sets it), whose address the order then shows
            $deliveryAddressId = $this->createDeliveryAddress($customerId, $customerData, $deliveryAddress);
            $buyerAddressId = $this->createBuyerAddress($customerId, $customerData);
            $invoiceAddressId = $invoiceData !== null
                ? $this->createInvoiceAddress($customerId, $customerData, $invoiceData)
                : null;
            // The invoice goes to the buyer: the invoice details, else the customer address -
            // never the delivery address (a parcel locker, a gift recipient)
            $invoiceAddressId = $invoiceAddressId ?? $buyerAddressId;

            if (!$deliveryAddressId || !$invoiceAddressId) {
                $this->logError(
                    !$deliveryAddressId
                        ? 'Cannot create order: the delivery address could not be created'
                        : 'Cannot create order: no invoice address - the InPost order has neither invoice details nor a customer address',
                    $inpostOrderId
                );
                $cart->delete();
                return null;
            }

            $cart->id_address_delivery = $deliveryAddressId;
            $cart->id_address_invoice = $invoiceAddressId;
            $cart->update();

            if (!$this->addProductsToCart($cart, $products)) {
                $this->logError((string) $this->lastError, $inpostOrderId);
                $cart->delete();
                return null;
            }

            // Set custom prices from InPost API before order creation
            $this->setCustomPricesForCart($cart, $products);

            // Force cart to recalculate prices with new SpecificPrice
            $cart->getPackageList();
            $cart->getDeliveryOptionList();

            // validateOrder() die()s on an inactive payment module - check first
            $paymentModule = $this->getPaymentModule();
            if (!$paymentModule) {
                $this->logError('Cannot create order: the InPost von Halsky module is disabled', $inpostOrderId);
                $cart->delete();
                return null;
            }

            $paymentType = $payment['type'] ?? null;
            $orderStateId = $this->mapInpostStatusToOrderState($inpostStatus, $paymentType);
            $transactionId = $this->firstPaymentValue($payment, 'id');

            $paymentModule->validateOrder(
                $cart->id,
                $orderStateId,
                $cart->getOrderTotal(true, Cart::BOTH),
                $this->paymentLabel($paymentType),
                'InPost Order: ' . $inpostOrderId,
                $transactionId !== null ? ['transaction_id' => $transactionId] : [],
                $idCurrency,
                false,
                $cart->secure_key,
                $context->shop
            );

            $orderId = (int) $paymentModule->currentOrder;

            if ($orderId > 0) {
                ShopLog::add(
                    '[InPost Order Creator] Created PrestaShop order ID: ' . $orderId,
                    1
                );
                $this->updateInpostOrderWithPrestaShopOrderId($inpostOrderId, $orderId);
                $this->noteUnmatchedProducts($orderId);
                $this->noteBuyerDetails($orderId, $invoiceData, $invoiceAddressId !== $buyerAddressId ? $buyerAddressId : null);
                $this->applyInpostShippingCost($orderId, $shippingCost);
                $this->applyInpostPaymentDate($orderId, $this->firstPaymentValue($payment, 'date'));

                // Clean up cart-specific prices after successful order creation
                $this->cleanupCartSpecificPrices((int) $cart->id);

                return $orderId;
            }

            $this->logError('Cannot create order: PrestaShop did not create the order from the cart (validateOrder)', $inpostOrderId);
            $this->cleanupCartSpecificPrices((int) $cart->id);
            $cart->delete();

            return null;
        } catch (\Throwable $e) {
            $this->logError(
                'Exception in createNewOrder: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')',
                $inpostOrderId
            );

            // validateOrder() can fail after the order row was written (e.g. in an order
            // status hook of another module). Link that order instead of creating another
            // one on the next attempt - a retry would duplicate it.
            $partialOrderId = isset($cart) && $cart->id > 0 ? (int) Order::getIdByCartId((int) $cart->id) : 0;
            if ($partialOrderId > 0) {
                ShopLog::add(
                    '[InPost Order Creator] [' . $inpostOrderId . '] Order ' . $partialOrderId . ' was created, but not completely ('
                    . $e->getMessage() . ') - linked to the InPost order, check its status and payment',
                    3
                );
                $this->updateInpostOrderWithPrestaShopOrderId($inpostOrderId, $partialOrderId);
                $this->cleanupCartSpecificPrices((int) $cart->id);
                $this->lastError = null;

                return $partialOrderId;
            }

            // Clean up even on error - otherwise every retry of a failing order leaves
            // another abandoned cart (Cart::delete() refuses carts that got an order)
            if (isset($cart) && $cart->id > 0) {
                $this->cleanupCartSpecificPrices((int) $cart->id);
                try {
                    $cart->delete();
                } catch (\Throwable $ignored) {
                }
            }

            return null;
        }
    }

    /**
     * Update existing PrestaShop order
     *
     * Only a cancellation on the InPost side is propagated - any other status
     * leaves the PrestaShop order state alone, so merchant-driven states
     * (shipped, delivered, ...) are never reset by a later sync.
     *
     * @param int $orderId PrestaShop order ID
     * @param string $inpostStatus InPost order status
     * @return int Order ID
     */
    private function updateExistingOrder(int $orderId, string $inpostStatus): int
    {
        if (!in_array(strtoupper($inpostStatus), self::CANCELLED_STATUSES, true)) {
            return $orderId;
        }

        try {
            $psOrder = new Order($orderId);
            if (!Validate::isLoadedObject($psOrder)) {
                ShopLog::add(
                    '[InPost Order Creator] PrestaShop order not found: ' . $orderId,
                    3
                );
                return 0;
            }

            $newOrderStateId = $this->mapInpostStatusToOrderState($inpostStatus);

            if ($psOrder->current_state != $newOrderStateId) {
                $orderHistory = new OrderHistory();
                $orderHistory->id_order = $orderId;
                $orderHistory->changeIdOrderState($newOrderStateId, $orderId);
                $orderHistory->addWithemail(false);

                DebugLog::info('[InPost Order Creator] Updated PrestaShop order ' . $orderId . ' status to ' . $newOrderStateId);
            }

            return $orderId;

        } catch (Exception $e) {
            ShopLog::add(
                '[InPost Order Creator] Exception updating PrestaShop order: ' . $e->getMessage(),
                3
            );
            return 0;
        }
    }

    /**
     * Find or create customer based on email
     *
     * @param array $customerData Customer data from API
     * @return int|null Customer ID or null on error
     */
    private function findOrCreateCustomer(array $customerData): ?int
    {
        if (empty($customerData['email'])) {
            return null;
        }

        $email = $customerData['email'];

        if (Customer::customerExists($email, false, true)) {
            $customer = new Customer();
            $customer->getByEmail($email);
            return (int) $customer->id;
        }

        try {
            $customer = new Customer();
            $customer->email = $email;
            $customer->firstname = isset($customerData['firstName']) ? $customerData['firstName'] : 'Customer';
            $customer->lastname = isset($customerData['lastName']) ? $customerData['lastName'] : 'InPost';
            $customer->passwd = \Tools::hash(\Tools::passwdGen(16));
            $customer->active = 1;
            $customer->is_guest = 0;
            $customer->id_default_group = (int) Configuration::get('PS_CUSTOMER_GROUP');
            $customer->id_shop = Context::getContext()->shop->id;
            $customer->id_lang = Context::getContext()->language->id;

            if ($customer->add()) {
                return (int) $customer->id;
            }
            return null;
        } catch (Exception $e) {
            ShopLog::add(
                '[InPost Order Creator] Exception creating customer: ' . $e->getMessage(),
                3
            );
            return null;
        }
    }

    /**
     * Delivery address of the order: the parcel locker / courier address, the
     * customer address when the delivery has none
     *
     * @param mixed $deliveryAddress Address object or array, null when the delivery has none
     * @return int|null Address ID
     */
    private function createDeliveryAddress(int $customerId, array $customerData, $deliveryAddress): ?int
    {
        $addressData = $deliveryAddress ?: ($customerData['address'] ?? null);
        if (!$addressData) {
            ShopLog::add('[InPost Order Creator] No delivery address data for customer ID: ' . $customerId, 2);

            return null;
        }

        return $this->saveAddress($this->newAddress($customerId, $customerData, 'InPost Delivery'), $addressData);
    }

    /**
     * Address of the buyer (customer.address of the InPost order) - the invoice
     * address when the buyer asked for no invoice, kept on the customer otherwise
     *
     * @return int|null Address ID, null when the InPost order has no customer address
     */
    private function createBuyerAddress(int $customerId, array $customerData): ?int
    {
        $addressData = $customerData['address'] ?? null;
        if (!$addressData) {
            return null;
        }

        return $this->saveAddress($this->newAddress($customerId, $customerData, 'InPost Customer'), $addressData);
    }

    /**
     * Invoice address of the order: company, NIP (vat_number) and the invoice address
     * the buyer gave in InPost. Its location falls back to the customer address when
     * the invoice details have none.
     *
     * @param array $invoiceData From OrderSyncService::extractInvoiceData()
     * @return int|null Address ID, null when it could not be created (the buyer address is used then)
     */
    private function createInvoiceAddress(int $customerId, array $customerData, array $invoiceData): ?int
    {
        $addressData = $invoiceData['address'] ?? null;
        if (!$addressData) {
            $addressData = $customerData['address'] ?? null;
        }
        if (!$addressData) {
            ShopLog::add('[InPost Order Creator] Invoice details without an address for customer ID: ' . $customerId, 2);

            return null;
        }

        $address = $this->newAddress($customerId, $customerData, 'InPost Invoice');
        // A company invoice has no person name - PrestaShop requires one, the buyer's stays
        if ($invoiceData['firstName'] !== '') {
            $address->firstname = $invoiceData['firstName'];
        }
        if ($invoiceData['lastName'] !== '') {
            $address->lastname = $invoiceData['lastName'];
        }
        $address->company = \Tools::substr($invoiceData['companyName'], 0, 255);
        $address->vat_number = \Tools::substr($invoiceData['taxId'], 0, 32);

        return $this->saveAddress($address, $addressData);
    }

    /**
     * New address of the customer with the buyer's name and phone
     */
    private function newAddress(int $customerId, array $customerData, string $alias): Address
    {
        $address = new Address();
        $address->id_customer = $customerId;
        $address->firstname = isset($customerData['firstName']) ? $customerData['firstName'] : 'Customer';
        $address->lastname = isset($customerData['lastName']) ? $customerData['lastName'] : 'InPost';
        $address->phone = isset($customerData['phoneNumber']) ? $customerData['phoneNumber'] : '';
        $address->alias = $alias;

        return $address;
    }

    /**
     * Fill the location of the address and save it (or reuse the same one)
     *
     * @param mixed $addressData InPost address object or array
     * @return int|null Address ID
     */
    private function saveAddress(Address $address, $addressData): ?int
    {
        try {
            if (!$this->fillAddressLocation($address, $addressData)) {
                return null;
            }

            $validation = $address->validateFields(false, true);
            if ($validation !== true) {
                ShopLog::add('[InPost Order Creator] Invalid ' . $address->alias . ' address: ' . $validation, 2);

                return null;
            }

            return $this->findOrAddAddress($address);
        } catch (Exception $e) {
            ShopLog::add(
                '[InPost Order Creator] Exception creating ' . $address->alias . ' address: ' . $e->getMessage(),
                3
            );
        }

        return null;
    }

    /**
     * Street, flat, city, post code, country and state of an InPost address
     *
     * @param mixed $addressData Address object or array
     * @return bool False when no country could be resolved
     */
    private function fillAddressLocation(Address $address, $addressData): bool
    {
        // Extract address fields using helper method that supports both camelCase and snake_case
        $street = $this->getAddressValue($addressData, ['street']);
        $building = $this->getAddressValue($addressData, ['building']);
        $address->address1 = trim($street . ' ' . $building);
        $address->address2 = trim((string) $this->getAddressValue($addressData, ['flat']));

        $address->city = $this->getAddressValue($addressData, ['city']);
        $address->postcode = $this->getAddressValue($addressData, ['postCode', 'post_code']);

        // Extract country code with fallback variants (camelCase and snake_case)
        $countryCode = $this->getAddressValue($addressData, ['countryCode', 'country_code'], 'PL');
        $address->id_country = $this->getCountryIdByCode($countryCode);

        // Validate country ID - must be > 0 for PrestaShop to work correctly
        if ($address->id_country == 0) {
            ShopLog::add(
                '[InPost Order Creator] Invalid country code: ' . $countryCode . ', falling back to PL',
                2
            );
            $address->id_country = $this->getCountryIdByCode('PL');

            // If even PL fails, log critical error
            if ($address->id_country == 0) {
                ShopLog::add(
                    '[InPost Order Creator] CRITICAL: Cannot find Poland country in database!',
                    3
                );
                return false;
            }
        }

        // Extract state
        $stateName = $this->getAddressValue($addressData, ['state']);
        $address->id_state = $this->getStateId($stateName, $address->id_country);

        DebugLog::info('[InPost Order Creator] Creating address: ' . $address->city . ', ' . $countryCode . ' (country_id: ' . $address->id_country . ')');

        return true;
    }

    /**
     * Reuse the same address of this customer (re-tried orders, returning customers), add it otherwise
     *
     * @return int|null Address ID
     */
    private function findOrAddAddress(Address $address): ?int
    {
        $existingId = (int) Db::getInstance()->getValue(
            'SELECT id_address FROM ' . _DB_PREFIX_ . 'address
             WHERE id_customer = ' . (int) $address->id_customer . " AND deleted = 0
               AND alias = '" . pSQL($address->alias) . "'
               AND firstname = '" . pSQL((string) $address->firstname) . "'
               AND lastname = '" . pSQL((string) $address->lastname) . "'
               AND company = '" . pSQL((string) $address->company) . "'
               AND vat_number = '" . pSQL((string) $address->vat_number) . "'
               AND address1 = '" . pSQL((string) $address->address1) . "'
               AND address2 = '" . pSQL((string) $address->address2) . "'
               AND postcode = '" . pSQL((string) $address->postcode) . "'
               AND city = '" . pSQL((string) $address->city) . "'
               AND id_country = " . (int) $address->id_country . '
             ORDER BY id_address DESC'
        );
        if ($existingId > 0) {
            return $existingId;
        }

        if ($address->add()) {
            return (int) $address->id;
        }

        ShopLog::add(
            '[InPost Order Creator] Failed to add address to database',
            3
        );

        return null;
    }

    /**
     * Get value from address data object or array
     *
     * Supports both camelCase and snake_case property names for SDK compatibility.
     *
     * @param mixed $addressData Address object or array
     * @param array $paths Property names to try (e.g., ['postCode', 'post_code'])
     * @param mixed $default Default value if not found
     * @return mixed Value or default
     */
    private function getAddressValue($addressData, array $paths, $default = '')
    {
        foreach ($paths as $path) {
            // Try object property access
            if (is_object($addressData)) {
                // Try direct property
                if (isset($addressData->$path)) {
                    return $addressData->$path;
                }
                // Try getter method (e.g., getPostCode for 'postCode')
                $getter = 'get' . ucfirst($path);
                if (method_exists($addressData, $getter)) {
                    $value = $addressData->$getter();
                    if ($value !== null) {
                        return $value;
                    }
                }
            }
            // Try array access
            if (is_array($addressData) && isset($addressData[$path])) {
                return $addressData[$path];
            }
        }
        return $default;
    }

    /**
     * Get country ID by country code
     *
     * @param string $countryCode
     * @return int
     */
    private function getCountryIdByCode(string $countryCode): int
    {
        $countryCode = strtoupper($countryCode);
        $countryId = (int) Country::getByIso($countryCode);
        return $countryId > 0 ? $countryId : (int) Country::getByIso('PL');
    }

    /**
     * Get state ID by name and country
     *
     * @param string|null $stateName
     * @param int $countryId
     * @return int
     */
    private function getStateId($stateName, int $countryId): int
    {
        if (!$stateName) {
            return 0;
        }

        $stateId = (int) State::getIdByName($stateName);
        if ($stateId > 0) {
            $state = new State($stateId);
            if (Validate::isLoadedObject($state) && $state->id_country == $countryId) {
                return $stateId;
            }
        }

        return 0;
    }

    /**
     * Create cart
     *
     * @param int $customerId
     * @param int $idLang
     * @param int $idShop
     * @param int $idCurrency
     * @return Cart|null
     */
    private function createCart(int $customerId, int $idLang, int $idShop, int $idCurrency): ?Cart
    {
        $cart = $this->cartFactory->createWithConfiguration(
            $customerId,
            $idLang,
            $idShop,
            $idCurrency
        );

        if ($cart->add()) {
            return $cart;
        }
        return null;
    }

    /**
     * Add products to cart
     *
     * @param Cart $cart
     * @param array $products
     * @return bool
     */
    private function addProductsToCart(Cart $cart, array $products): bool
    {
        $this->unmatchedLines = [];

        foreach ($products as $productData) {
            [$productId, $idProductAttribute] = $this->resolveProductRef($productData) ?? [null, 0];

            if (!$productId) {
                $productId = $this->getFallbackProductId();
                $this->unmatchedLines[] = [
                    'sku' => (string) ($productData['productId'] ?? ''),
                    'offerId' => (string) ($productData['offerId'] ?? ''),
                    'externalId' => (string) ($productData['externalId'] ?? ''),
                    'fallback' => $productId,
                ];
                ShopLog::add(
                    '[InPost Order Creator] Product not found: ' . $productData['productId'] . ', using fallback',
                    2
                );
            }

            if (!$productId) {
                $this->lastError = 'Cannot create order: product "' . ($productData['productId'] ?? '') . '" not found and no fallback product is available';
                return false;
            }

            $product = new Product($productId);
            if (!Validate::isLoadedObject($product)) {
                $this->lastError = 'Cannot create order: product ID ' . $productId . ' does not exist';
                return false;
            }

            // The order is already paid in InPost: record it even when the shop stock
            // is 0 (skipAvailabilityCheckOutOfStock) - otherwise updateQty() silently
            // returns false, the cart stays empty and validateOrder() creates nothing
            $added = $cart->updateQty(
                $productData['quantity'],
                $productId,
                $idProductAttribute > 0 ? $idProductAttribute : null,
                false,
                'up',
                0,
                null,
                true,
                true
            );
            if ($added === false || $added === -1 || (is_numeric($added) && (int) $added < 0)) {
                $this->lastError = sprintf(
                    'Cannot create order: product ID %d (%s) cannot be added to the cart%s',
                    $productId,
                    $product->reference ?: ($productData['productId'] ?? ''),
                    !$product->available_for_order
                        ? ' - it is not available for order (Catalog > Products > Options > "Available for order")'
                        : ($added === -1 ? ' - quantity below the product minimal quantity' : '')
                );
                return false;
            }
        }

        return true;
    }

    /**
     * Set custom prices for products in cart using SpecificPrice
     * This forces InPost API prices instead of PrestaShop catalog prices
     *
     * @param Cart $cart
     * @param array $products Products with InPost API prices
     * @return void
     */
    private function setCustomPricesForCart(Cart $cart, array $products): void
    {
        $context = Context::getContext();

        // InPost sends one order line per unit, so a product can appear several times;
        // PrestaShop allows one cart specific price per product and combination
        // -> one averaged unit price each
        $byProduct = [];
        foreach ($products as $productData) {
            [$productId, $idProductAttribute] = $this->resolveProductRef($productData) ?? [$this->getFallbackProductId(), 0];
            if (!$productId || !isset($productData['price'])) {
                continue;
            }
            $key = $productId . ':' . $idProductAttribute;
            $quantity = isset($productData['quantity']) && $productData['quantity'] > 0 ? (int) $productData['quantity'] : 1;
            $byProduct[$key]['id_product'] = $productId;
            $byProduct[$key]['id_product_attribute'] = $idProductAttribute;
            $byProduct[$key]['total'] = ($byProduct[$key]['total'] ?? 0.0) + (float) $productData['price'];
            $byProduct[$key]['quantity'] = ($byProduct[$key]['quantity'] ?? 0) + $quantity;
        }

        foreach ($byProduct as $line) {
            $productId = (int) $line['id_product'];
            $unitPriceApiTaxIncl = $line['total'] / $line['quantity']; // Unit price with tax

            // Get product to determine tax rate
            $product = new Product($productId);
            if (!Validate::isLoadedObject($product)) {
                continue;
            }

            // Get tax rate for this product
            $taxRulesGroupId = (int) Product::getIdTaxRulesGroupByIdProduct($productId, $context);
            $taxManager = TaxManagerFactory::getManager(
                new Address($cart->id_address_delivery ?: $cart->id_address_invoice),
                $taxRulesGroupId
            );
            $taxCalculator = $taxManager->getTaxCalculator();
            $taxRate = $taxCalculator->getTotalRate();

            // Calculate unit price without tax
            $unitPriceApiTaxExcl = $taxRate > 0
                ? $unitPriceApiTaxIncl / (1 + ($taxRate / 100))
                : $unitPriceApiTaxIncl;

            // Create SpecificPrice for this cart
            $specificPrice = new SpecificPrice();
            $specificPrice->id_product = $productId;
            $specificPrice->id_product_attribute = (int) $line['id_product_attribute'];
            $specificPrice->id_cart = $cart->id;
            $specificPrice->id_shop = $context->shop->id;
            $specificPrice->id_shop_group = 0;
            $specificPrice->id_currency = $cart->id_currency;
            $specificPrice->id_country = 0;
            $specificPrice->id_group = 0;
            $specificPrice->id_customer = $cart->id_customer;
            $specificPrice->id_specific_price_rule = 0;
            // Exact price from the API; SpecificPrice validates at most 9 decimals
            // (Validate::isNegativePrice), a net price derived from the gross one has more
            $specificPrice->price = round($unitPriceApiTaxExcl, 6);
            $specificPrice->from_quantity = 1;
            $specificPrice->reduction = 0;
            $specificPrice->reduction_tax = 1;
            $specificPrice->reduction_type = 'amount';
            $specificPrice->from = date('Y-m-d H:i:s', strtotime('-1 day'));
            $specificPrice->to = date('Y-m-d H:i:s', strtotime('+1 day'));

            try {
                if ($specificPrice->add()) {
                    DebugLog::info('[InPost Order Creator] Set custom price for product ' . $productId . ' in cart ' . $cart->id . ': ' . $unitPriceApiTaxExcl);
                }
            } catch (Exception $e) {
                ShopLog::add(
                    '[InPost Order Creator] Failed to set custom price: ' . $e->getMessage(),
                    2
                );
            }
        }
    }

    /**
     * Find product by reference
     *
     * @param string $productId
     * @return int|null
     */
    /**
     * Put the InPost delivery price on the shop order. The cart has no carrier
     * price of its own (PrestaShop picks the default carrier, usually free), so the
     * order is created without shipping and the amount is added afterwards to every
     * place PrestaShop keeps it: order totals, order carrier, invoice and payment.
     * The VAT of the shipping uses the carrier tax rate PrestaShop set on the order.
     *
     * @param float $shippingCost Gross delivery price from InPost
     */
    private function applyInpostShippingCost(int $orderId, float $shippingCost): void
    {
        if ($shippingCost <= 0) {
            return;
        }

        try {
            $order = new Order($orderId);
            if (!Validate::isLoadedObject($order)) {
                return;
            }

            $taxRate = (float) $order->carrier_tax_rate;
            $grossDelta = round($shippingCost - (float) $order->total_shipping_tax_incl, 6);
            $netShipping = round($shippingCost / (1 + $taxRate / 100), 6);
            $netDelta = round($netShipping - (float) $order->total_shipping_tax_excl, 6);
            if (abs($grossDelta) < 0.000001) {
                return;
            }

            $order->total_shipping = $shippingCost;
            $order->total_shipping_tax_incl = $shippingCost;
            $order->total_shipping_tax_excl = $netShipping;
            $order->total_paid = (float) $order->total_paid + $grossDelta;
            $order->total_paid_tax_incl = (float) $order->total_paid_tax_incl + $grossDelta;
            $order->total_paid_tax_excl = (float) $order->total_paid_tax_excl + $netDelta;
            $order->total_paid_real = (float) $order->total_paid_real + $grossDelta;
            $order->update();

            $idOrderCarrier = (int) $order->getIdOrderCarrier();
            if ($idOrderCarrier > 0) {
                $orderCarrier = new OrderCarrier($idOrderCarrier);
                $orderCarrier->shipping_cost_tax_incl = $shippingCost;
                $orderCarrier->shipping_cost_tax_excl = $netShipping;
                $orderCarrier->update();
            }

            foreach ($order->getInvoicesCollection() as $invoice) {
                /** @var OrderInvoice $invoice */
                $invoice->total_shipping_tax_incl = $shippingCost;
                $invoice->total_shipping_tax_excl = $netShipping;
                $invoice->total_paid_tax_incl = (float) $invoice->total_paid_tax_incl + $grossDelta;
                $invoice->total_paid_tax_excl = (float) $invoice->total_paid_tax_excl + $netDelta;
                $invoice->update();
                break; // a freshly created order has a single invoice
            }

            foreach ($order->getOrderPaymentCollection() as $payment) {
                $payment->amount = (float) $payment->amount + $grossDelta;
                $payment->update();
                break; // validateOrder records one payment for the order total
            }
        } catch (Exception $e) {
            ShopLog::add(
                '[InPost Order Creator] Could not add the InPost shipping cost to order ' . $orderId . ': ' . $e->getMessage(),
                2
            );
        }
    }

    /**
     * Record order lines that were not matched to a shop product in the order's
     * private note (visible on the PrestaShop order page, flagged in the InPost
     * orders list): their product and price come from the fallback product.
     */
    private function noteUnmatchedProducts(int $orderId): void
    {
        if ($this->unmatchedLines === []) {
            return;
        }

        $lines = array_map(
            static fn (array $line) => sprintf(
                '- SKU %s (offer %s%s) -> fallback product #%s',
                $line['sku'] !== '' ? $line['sku'] : '?',
                $line['offerId'] !== '' ? $line['offerId'] : '?',
                $line['externalId'] !== '' ? ', externalId ' . $line['externalId'] : '',
                $line['fallback'] !== null ? (string) $line['fallback'] : '?'
            ),
            $this->unmatchedLines
        );

        try {
            $order = new Order($orderId);
            $order->note = trim(
                (string) $order->note . "\n" . self::UNMATCHED_PRODUCTS_NOTE
                . ' (the InPost offer was not created from this shop or the product has no matching reference).'
                . ' Product and price of these lines are not the ones bought in InPost:' . "\n" . implode("\n", $lines)
            );
            $order->update();
        } catch (Exception $e) {
            ShopLog::add('[InPost Order Creator] Could not save the unmatched products note: ' . $e->getMessage(), 2);
        }
    }

    /**
     * Buyer details the order addresses do not show, in the private order note:
     * the invoice e-mail (the shop customer has the masked marketplace e-mail) and
     * the customer address when the invoice goes to another one
     *
     * @param int|null $buyerAddressId Customer address not used by the order, null when none
     */
    private function noteBuyerDetails(int $orderId, ?array $invoiceData, ?int $buyerAddressId): void
    {
        $lines = [];
        $email = (string) ($invoiceData['email'] ?? '');
        if ($email !== '') {
            $lines[] = 'InPost invoice e-mail: ' . $email;
        }
        if ($buyerAddressId !== null) {
            $address = new Address($buyerAddressId);
            $lines[] = 'InPost customer address: ' . trim($address->address1 . ' ' . $address->address2)
                . ', ' . $address->postcode . ' ' . $address->city . ', ' . Country::getIsoById((int) $address->id_country);
        }
        if ($lines === []) {
            return;
        }

        try {
            $order = new Order($orderId);
            $order->note = trim((string) $order->note . "\n" . implode("\n", $lines));
            $order->update();
        } catch (Exception $e) {
            ShopLog::add('[InPost Order Creator] Could not save the buyer details note: ' . $e->getMessage(), 2);
        }
    }

    /**
     * PrestaShop product and combination of an order line, most reliable match first:
     * 1. the module's own offer (inpost_offer.inpost_offer_id = order line offerId)
     * 2. externalId "ps_product_{id}" / "ps_product_{id}_{combination}" set by the module
     * 3. SKU = combination reference, then product reference
     *
     * @param array $productData Line from OrderSyncService::extractProducts()
     * @return array{0:int, 1:int}|null [id_product, id_product_attribute (0 = none)]
     */
    private function resolveProductRef(array $productData): ?array
    {
        $offerId = (string) ($productData['offerId'] ?? '');
        if ($offerId !== '') {
            $row = Db::getInstance()->getRow(
                'SELECT prestashop_offer_id, prestashop_product_attribute_id FROM ' . _DB_PREFIX_
                . "inpost_offer WHERE inpost_offer_id = '" . pSQL($offerId) . "'"
            );
            $ref = $row ? $this->validProductRef((int) $row['prestashop_offer_id'], (int) $row['prestashop_product_attribute_id']) : null;
            if ($ref !== null) {
                return $ref;
            }
        }

        if (preg_match('/^ps_product_(\d+)(?:_(\d+))?$/', (string) ($productData['externalId'] ?? ''), $matches)) {
            $ref = $this->validProductRef((int) $matches[1], (int) ($matches[2] ?? 0));
            if ($ref !== null) {
                return $ref;
            }
        }

        // A combination only when its reference is its own - combinations often share
        // the product reference, and picking one of them would sell a random variant
        $sku = (string) ($productData['productId'] ?? '');
        if ($sku !== '') {
            $rows = Db::getInstance()->executeS(
                'SELECT pa.id_product, pa.id_product_attribute, p.reference AS product_reference FROM ' . _DB_PREFIX_ . 'product_attribute pa
                INNER JOIN ' . _DB_PREFIX_ . "product p ON p.id_product = pa.id_product
                WHERE pa.reference = '" . pSQL($sku) . "' LIMIT 2"
            ) ?: [];
            if (count($rows) === 1 && (string) $rows[0]['product_reference'] !== $sku) {
                $ref = $this->validProductRef((int) $rows[0]['id_product'], (int) $rows[0]['id_product_attribute']);
                if ($ref !== null) {
                    return $ref;
                }
            }
        }

        $idProduct = $this->findProductByReference($sku);

        return $idProduct ? [$idProduct, 0] : null;
    }

    /**
     * [product, combination] when the product exists; a combination that was
     * deleted (or belongs to another product) is dropped, the product kept
     *
     * @return array{0:int, 1:int}|null
     */
    private function validProductRef(int $idProduct, int $idProductAttribute): ?array
    {
        if ($idProduct <= 0 || !Validate::isLoadedObject(new Product($idProduct))) {
            return null;
        }

        if ($idProductAttribute > 0) {
            $combination = new \Combination($idProductAttribute);
            if (!Validate::isLoadedObject($combination) || (int) $combination->id_product !== $idProduct) {
                ShopLog::add(
                    '[InPost Order Creator] Combination ' . $idProductAttribute . ' of product ' . $idProduct . ' not found, the order line uses the product',
                    2
                );
                $idProductAttribute = 0;
            }
        }

        return [$idProduct, $idProductAttribute];
    }

    private function findProductByReference(string $productId): ?int
    {
        $productIdByRef = Product::getIdByReference($productId);
        if ($productIdByRef > 0) {
            $product = new Product($productIdByRef);
            if (Validate::isLoadedObject($product)) {
                return (int) $productIdByRef;
            }
        }

        return null;
    }

    /**
     * Get fallback product ID
     *
     * @return int|null
     */
    private function getFallbackProductId(): ?int
    {
        $fallbackReference = Configuration::get('INPOSTSA_FALLBACK_PRODUCT_ID');
        if (!empty($fallbackReference)) {
            $productIdByRef = Product::getIdByReference($fallbackReference);
            if ($productIdByRef > 0) {
                $product = new Product($productIdByRef);
                if (Validate::isLoadedObject($product)) {
                    return (int) $productIdByRef;
                }
            }
        }

        $db = Db::getInstance();
        // getValue() appends LIMIT 1 itself (via getRow())
        $sql = 'SELECT id_product FROM ' . _DB_PREFIX_ . 'product WHERE active = 1';
        $result = $db->getValue($sql);
        return $result ? (int) $result : null;
    }

    /**
     * Get currency ID
     *
     * @param array|null $finalPrice
     * @return int
     */
    private function getCurrencyId($finalPrice): int
    {
        if ($finalPrice && isset($finalPrice['currency'])) {
            $idCurrency = Currency::getIdByIsoCode($finalPrice['currency']);
            if ($idCurrency) {
                return (int) $idCurrency;
            }
        }

        return (int) Configuration::get('PS_CURRENCY_DEFAULT');
    }

    /**
     * The module itself is the (hidden) "InPost" payment method of imported orders -
     * they never depend on a shop payment method the merchant may have disabled
     *
     * @return PaymentModule|null Null when the module is disabled
     */
    private function getPaymentModule()
    {
        $paymentModule = Module::getInstanceByName('inpostvonhalsky');

        return $paymentModule instanceof PaymentModule && $paymentModule->active ? $paymentModule : null;
    }

    /**
     * Payment name on the order, invoice and order payment: "InPost - BLIK", "InPost - Card", ...
     */
    private function paymentLabel(?string $paymentType): string
    {
        $type = isset(self::PAYMENT_TYPE_LABELS[strtoupper((string) $paymentType)])
            ? self::PAYMENT_TYPE_LABELS[strtoupper((string) $paymentType)]
            : null;
        if ($type === null) {
            return 'InPost';
        }

        $translator = Context::getContext()->getTranslator();

        return 'InPost - ' . $translator->trans($type, [], 'Modules.Inpostvonhalsky.Admin');
    }

    /**
     * Value of the first InPost payment (id / date)
     *
     * @param array<string, mixed>|null $payment
     * @return mixed
     */
    private function firstPaymentValue(?array $payment, string $key)
    {
        foreach ($payment['payments'] ?? [] as $entry) {
            if (!empty($entry[$key])) {
                return $entry[$key];
            }
        }

        return null;
    }

    /**
     * Record the real InPost payment date on the order payment (validateOrder uses "now")
     */
    private function applyInpostPaymentDate(int $orderId, $paymentDate): void
    {
        if (!is_int($paymentDate) || $paymentDate <= 0) {
            return;
        }

        try {
            $order = new Order($orderId);
            foreach ($order->getOrderPaymentCollection() as $orderPayment) {
                $orderPayment->date_add = date('Y-m-d H:i:s', $paymentDate);
                $orderPayment->update();
                break; // validateOrder records one payment for the order total
            }
        } catch (\Throwable $e) {
            ShopLog::add('[InPost Order Creator] Could not set the payment date of order ' . $orderId . ': ' . $e->getMessage(), 2);
        }
    }

    /**
     * Map InPost status to PrestaShop order state
     *
     * @param string $inpostStatus
     * @return int
     */
    private function mapInpostStatusToOrderState(string $inpostStatus, ?string $paymentType = null): int
    {
        if (in_array(strtoupper($inpostStatus), self::CANCELLED_STATUSES, true)) {
            return (int) Configuration::get('PS_OS_CANCELED');
        }

        // Cash on delivery is collected by the courier - not paid yet. Every other
        // InPost payment (incl. deferred payment / shopping limit) is paid to the shop.
        if (strtoupper((string) $paymentType) === PaymentType::CASH_ON_DELIVERY) {
            return (int) Configuration::get('PS_OS_COD_VALIDATION');
        }

        // Status chosen in the module configuration ("InPost payment"), if it still exists
        $paidState = (int) Configuration::get('INPOSTSA_ORDER_STATE_PAID');
        if ($paidState > 0) {
            $state = new \OrderState($paidState);
            if (Validate::isLoadedObject($state) && !$state->deleted) {
                return $paidState;
            }
        }

        return (int) Configuration::get('PS_OS_PAYMENT');
    }

    /**
     * Update ps_inpost_order with PrestaShop order ID
     *
     * @param string $inpostOrderId
     * @param int $psOrderId
     * @return void
     */
    private function updateInpostOrderWithPrestaShopOrderId(string $inpostOrderId, int $psOrderId): void
    {
        $orderEntity = $this->inpostOrderRepository->findByInpostOrderId($inpostOrderId);

        if ($orderEntity) {
            $context = Context::getContext();
            $idShop = (int) $context->shop->id;

            $orderEntity->setIdOrder($psOrderId);
            $orderEntity->setIdShop($idShop);

            $this->inpostOrderRepository->save($orderEntity);
        }
    }

    /**
     * Clean up cart-specific SpecificPrice entries after order creation
     *
     * @param int $cartId
     * @return void
     */
    private function cleanupCartSpecificPrices(int $cartId): void
    {
        try {
            $db = Db::getInstance();
            $result = $db->delete(
                'specific_price',
                'id_cart = ' . (int) $cartId
            );

            if ($result) {
                DebugLog::info('[InPost Order Creator] Cleaned up specific prices for cart: ' . $cartId);
            }
        } catch (Exception $e) {
            ShopLog::add(
                '[InPost Order Creator] Error cleaning specific prices: ' . $e->getMessage(),
                2
            );
        }
    }
}
