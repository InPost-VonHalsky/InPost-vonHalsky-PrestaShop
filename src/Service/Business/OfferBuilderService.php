<?php
/**
 * OfferBuilderService
 *
 * Service for building complete InPost Offer payloads from PrestaShop products.
 * Constructs API-ready offer structures with all required and optional fields.
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Service\Business;

use Inpost\VonHalsky\Entity\Offer;
use Inpost\VonHalsky\Entity\OfferAttributes;
use Inpost\VonHalsky\Entity\OfferProduct;
use Inpost\VonHalsky\Entity\OfferPrice;
use Inpost\VonHalsky\Entity\OfferStock;
use Inpost\VonHalsky\Helper\Config;
use Inpost\VonHalsky\Repository\OfferRepository;
use Inpost\VonHalsky\Service\Mapping\CategoryMappingService;
use Inpost\VonHalsky\Service\Mapping\AttributeMappingService;
use Inpost\VonHalsky\Service\Offer\HintApplyService;
use Inpost\VonHalsky\Service\Offer\ProductAttributeValueResolver;
use Inpost\VonHalsky\Service\Offer\ProductCombinationService;
use Inpost\VonHalsky\Service\Offer\OfferFieldMapping;
use Inpost\VonHalsky\Service\Offer\OfferPriceResolver;
use Inpost\VonHalsky\Service\Offer\ProductFieldReader;
use Inpost\VonHalsky\Service\Sync\PsPriceStockReader;
use Combination;
use Inpost\VonHalsky\Service\Util\ConfigurationService;
use Inpost\VonHalsky\Service\Util\LoggerService;
use Inpost\VonHalsky\Service\Util\ShopTaxCurrencyInfo;
use Product;
use Image;
use Currency;

class OfferBuilderService
{
    private CategoryMappingService $categoryMappingService;
    private AttributeMappingService $attributeMappingService;
    private ConfigurationService $configurationService;
    private Config $config;
    private LoggerService $logger;
    private OfferRepository $offerRepository;
    private ShopTaxCurrencyInfo $shopTaxCurrencyInfo;
    private ProductAttributeValueResolver $attributeValueResolver;
    private HintApplyService $attributeApplier;
    private PsPriceStockReader $priceStockReader;
    private ProductCombinationService $combinationService;
    private ProductFieldReader $productFieldReader;
    private OfferPriceResolver $priceResolver;

    /** InPost limits of product.name / product.superModel */
    private const NAME_MAX_LENGTH = 150;
    private const SUPER_MODEL_MAX_LENGTH = 100;

    public function __construct(
        CategoryMappingService $categoryMappingService,
        AttributeMappingService $attributeMappingService,
        ConfigurationService $configurationService,
        Config $config,
        LoggerService $logger,
        OfferRepository $offerRepository,
        ShopTaxCurrencyInfo $shopTaxCurrencyInfo,
        ProductAttributeValueResolver $attributeValueResolver,
        HintApplyService $attributeApplier,
        PsPriceStockReader $priceStockReader,
        ProductCombinationService $combinationService,
        ProductFieldReader $productFieldReader,
        OfferPriceResolver $priceResolver
    ) {
        $this->categoryMappingService = $categoryMappingService;
        $this->attributeMappingService = $attributeMappingService;
        $this->configurationService = $configurationService;
        $this->config = $config;
        $this->logger = $logger;
        $this->offerRepository = $offerRepository;
        $this->shopTaxCurrencyInfo = $shopTaxCurrencyInfo;
        $this->attributeValueResolver = $attributeValueResolver;
        $this->attributeApplier = $attributeApplier;
        $this->priceStockReader = $priceStockReader;
        $this->combinationService = $combinationService;
        $this->productFieldReader = $productFieldReader;
        $this->priceResolver = $priceResolver;
    }

    /**
     * Build Offer entity from PrestaShop product for form pre-filling
     *
     * @param int $idProduct Product ID
     * @param int|null $categoryMappingId InPost category (bulk flow); null = form choice / default mapping
     * @param int $idProductAttribute Combination the offer sells; 0 = the whole product
     * @return Offer Offer entity with populated nested entities
     * @throws \Exception
     */
    public function buildFromProduct(int $idProduct, ?int $categoryMappingId = null, int $idProductAttribute = 0): Offer
    {
        $langId = $this->config->getDefaultLanguageId();
        $shopId = $this->config->getDefaultShopId();

        $this->logger->info("Building Offer entity from product", [
            'id_product' => $idProduct,
            'id_product_attribute' => $idProductAttribute,
            'id_shop' => $shopId,
        ]);

        // Load PrestaShop product (full=true to load multilang fields)
        $product = new Product($idProduct, true, $langId, $shopId);
        if (!$product->id) {
            throw new \Exception("Product not found: {$idProduct}");
        }

        $combination = null;
        if ($idProductAttribute > 0) {
            $combination = new Combination($idProductAttribute, null, $shopId);
            if (!$combination->id || (int) $combination->id_product !== $idProduct) {
                throw new \Exception("Combination {$idProductAttribute} does not belong to product {$idProduct}");
            }
        }

        $offer = new Offer();
        $offer->setPrestashopOfferId($idProduct);
        $offer->setPrestashopProductAttributeId($idProductAttribute);
        $offer->setShopId($shopId);
        $offer->setStatus(Offer::STATUS_DRAFT);

        $offerProduct = $this->buildOfferProduct($product, $idProduct, $combination);
        $this->assignCategory($offer, $offerProduct, $product, $shopId, $categoryMappingId);
        $offer->setProduct($offerProduct);
        $offer->setPrice($this->buildOfferPrice($idProduct, $idProductAttribute));
        $offer->setStock($this->buildOfferStock($idProduct, $idProductAttribute, $shopId));
        $this->applyMappedAttributes($offer, $idProduct);

        $this->logger->info("Offer entity built successfully from product", [
            'id_product' => $idProduct,
            'id_product_attribute' => $idProductAttribute,
            'product_name' => $offerProduct->getName(),
        ]);

        return $offer;
    }

    /**
     * Category attribute values from the PrestaShop features / attribute groups
     * the attributes are mapped to (Category mapping > attribute source)
     */
    private function applyMappedAttributes(Offer $offer, int $idProduct): void
    {
        $categoryMappingId = (int) $offer->getProduct()->getCategoryMappingId();
        if (!$categoryMappingId) {
            return;
        }

        try {
            $resolved = $this->attributeValueResolver->resolve(
                $idProduct,
                $offer->getPrestashopProductAttributeId(),
                $categoryMappingId,
                $this->config->getDefaultLanguageCode()
            );
            $this->attributeApplier->applyAttributes(
                $offer,
                ProductAttributeValueResolver::applicable($resolved),
                true,
                OfferAttributes::SOURCE_PS
            );
        } catch (\Throwable $e) {
            // Attribute prefill is a convenience - never block building the offer
            $this->logger->warning('Could not prefill offer attributes from PrestaShop', [
                'id_product' => $idProduct,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Product fields from the PrestaShop product, using the configured field mapping
     * (module configuration > product field mapping, OfferFieldMapping).
     * For a combination: its own reference / EAN / MPN / weight, the combination in
     * the name and the product name as superModel (groups the combinations of one product).
     */
    private function buildOfferProduct(Product $product, int $idProduct, ?Combination $combination = null): OfferProduct
    {
        $offerProduct = new OfferProduct();
        $offerProduct->setProductId($idProduct);
        $productName = $this->fieldValue($product, null, 'NAME') ?: 'Unknown';
        $offerProduct->setName($productName);

        $description = $this->fieldValue($product, null, 'DESCRIPTION') ?: (string) ($product->description_short ?? '');
        $offerProduct->setDescription(strip_tags($description));
        $offerProduct->setSku($this->fieldValue($product, null, 'SKU') ?: 'SKU-' . $idProduct);

        // EAN is required by form validation on submit - the form still loads
        // without it so the user can add it manually
        $ean = $this->fieldValue($product, null, 'EAN');
        if ($ean !== '') {
            $offerProduct->setEan($ean);
        }

        $brand = $this->fieldValue($product, null, 'BRAND');
        if ($brand !== '') {
            $offerProduct->setBrand($brand);
        }

        // Model: the product name when the mapped field is empty, nothing when mapped to "none"
        if ($this->source('MODEL') !== ProductFieldReader::NONE) {
            $offerProduct->setModel($this->fieldValue($product, null, 'MODEL') ?: (string) ($product->name ?? ''));
        }

        // A combination's own MPN, the product's otherwise
        $mpn = $this->fieldValue($product, $combination, 'MPN');
        if ($mpn !== '') {
            $offerProduct->setManufacturerProductNumber($mpn);
        }

        $superModel = $this->fieldValue($product, null, 'SUPER_MODEL');
        if ($superModel !== '') {
            $offerProduct->setSuperModel(mb_substr($superModel, 0, self::SUPER_MODEL_MAX_LENGTH));
        }

        $this->applyDimensions($offerProduct, $product, $combination);

        if ($combination !== null) {
            $this->applyCombinationFields($offerProduct, $product, $combination, $productName);
        }

        return $offerProduct;
    }

    /**
     * Combination-specific product fields. EAN never falls back to the product:
     * InPost fixes an EAN once set, and every combination needs its own.
     */
    private function applyCombinationFields(OfferProduct $offerProduct, Product $product, Combination $combination, string $productName): void
    {
        $label = $this->combinationService->getLabel((int) $combination->id);
        if ($label !== '') {
            $offerProduct->setName(mb_substr($productName . ' - ' . $label, 0, self::NAME_MAX_LENGTH));
        }
        $offerProduct->setSuperModel(mb_substr($productName, 0, self::SUPER_MODEL_MAX_LENGTH));

        // Same configured field (reference, ean13, ...) read from the combination. Every
        // offer needs its own SKU: a combination without one, or with the product's
        // (PrestaShop copies it when combinations are generated), gets "-{combination}"
        $skuField = $this->source('SKU');
        $sku = property_exists($combination, $skuField) ? trim((string) $combination->{$skuField}) : '';
        $productSku = (string) $offerProduct->getSku();
        $offerProduct->setSku($sku !== '' && $sku !== $productSku ? $sku : $productSku . '-' . (int) $combination->id);

        $eanField = $this->source('EAN');
        $ean = property_exists($combination, $eanField) ? trim((string) $combination->{$eanField}) : '';
        $offerProduct->setEan($ean !== '' ? $ean : null);
    }

    /**
     * Dimensions (mm) and weight (g) from the mapped fields, converted from the shop
     * units. InPost takes the dimension block only complete, so the lengths are set
     * only when all three are known.
     */
    private function applyDimensions(OfferProduct $offerProduct, Product $product, ?Combination $combination): void
    {
        $width = $this->productFieldReader->readMillimetres($product, $combination, $this->source('WIDTH'));
        $height = $this->productFieldReader->readMillimetres($product, $combination, $this->source('HEIGHT'));
        $length = $this->productFieldReader->readMillimetres($product, $combination, $this->source('LENGTH'));
        if ($width === null || $height === null || $length === null) {
            return;
        }

        $offerProduct->setDimensionWidth($width);
        $offerProduct->setDimensionHeight($height);
        $offerProduct->setDimensionLength($length);
        $offerProduct->setDimensionWeight($this->productFieldReader->readGrams($product, $combination, $this->source('WEIGHT')));
    }

    /**
     * InPost category: explicit param (bulk flow), then the URL parameter (single
     * offer form, user changed the category), then the product's default category mapping.
     */
    private function assignCategory(Offer $offer, OfferProduct $offerProduct, Product $product, int $shopId, ?int $categoryMappingId): void
    {
        $categoryEntityId = $categoryMappingId ?: (int) ($_GET['inpost_category_id'] ?? 0);
        if (!$categoryEntityId) {
            $categoryEntityId = $this->categoryMappingService->getMappedInPostCategoryEntityId((int) $product->id_category_default, $shopId);
        }

        if (!$categoryEntityId) {
            return;
        }

        $categoryUuid = $this->categoryMappingService->getInpostCategoryUuidById((int) $categoryEntityId);
        if ($categoryUuid) {
            $offerProduct->setCategoryMappingId((int) $categoryEntityId);
            $offerProduct->setCategoryId($categoryUuid);
            $offer->setInpostCategoryId($categoryUuid);
        }
    }

    private function buildOfferPrice(int $idProduct, int $idProductAttribute): OfferPrice
    {
        $offerPrice = new OfferPrice();
        // InPost price: the configured customer group, % rule and rounding (GOKINT-1098)
        $this->priceResolver->refresh($offerPrice, $idProduct, $idProductAttribute);
        // No currency in the context outside the back office (CLI) - use the shop default
        $currencyId = \Context::getContext()->currency->id ?? (int) \Configuration::get('PS_CURRENCY_DEFAULT');
        $offerPrice->setCurrency((new Currency((int) $currencyId))->iso_code);

        return $offerPrice;
    }

    private function buildOfferStock(int $idProduct, int $idProductAttribute, int $shopId): OfferStock
    {
        $quantity = $this->priceStockReader->quantity($idProduct, $idProductAttribute, $shopId);

        $offerStock = new OfferStock();
        $offerStock->setQuantity($quantity);
        $offerStock->setAvailability($quantity > 0 ? 'available' : 'out_of_stock');

        return $offerStock;
    }

    /**
     * Build complete InPost offer payload from local offer
     *
     * Uses Doctrine to load the complete Offer entity with all relationships
     * (product, stock, price, manufacturer, gpsr, manuals, attributes).
     * Builds payload from saved offer form data, not from PrestaShop product.
     *
     * @param int $offerId Local offer_id from ps_inpost_offer
     * @return array Complete InPost offer payload (OpenAPI compliant)
     * @throws \Exception
     */
    public function buildOfferPayload(int $offerId): array
    {
        $this->logger->info("Building InPost offer payload from Doctrine entity", ['offer_id' => $offerId]);

        // Load Offer entity with all relationships via Doctrine
        $offer = $this->offerRepository->find($offerId);
        if (!$offer) {
            throw new \Exception("Offer not found: offer_id={$offerId}");
        }

        // Build payload from Offer entity (saved form data)
        $payload = $this->buildPayloadFromOffer($offer);

        // Add images from PrestaShop product (not stored in offer entity)
        $idProduct = $offer->getPrestashopOfferId();
        $idShop = $offer->getShopId();
        $langCode = $this->configurationService->getDefaultLanguage();
        $idLang = (int) \Language::getIdByIso($langCode);
        if ($idLang <= 0) {
            $idLang = (int) \Configuration::get('PS_LANG_DEFAULT');
        }
        $product = new Product($idProduct, true, $idLang, $idShop);
        if ($product->id) {
            // A combination offer shows the combination's images (all images when it has none)
            $this->addImages($payload, $product, $idLang, $offer->getPrestashopProductAttributeId() ?: null);
        }

        $this->logger->info("Offer payload built successfully from Doctrine entity", [
            'offer_id' => $offerId,
            'has_dimensions' => isset($payload['product']['dimension']),
            'attributes_count' => count($payload['product']['attributes'] ?? []),
            'manuals_count' => count($payload['gpsr']['manuals'] ?? []),
        ]);

        return $payload;
    }

    /**
     * Build API payload from Offer Doctrine entity
     *
     * @param Offer $offer Offer entity with loaded relationships
     * @return array OpenAPI-compliant payload
     */
    private function buildPayloadFromOffer(Offer $offer): array
    {
        $offerProduct = $offer->getProduct();
        $offerStock = $offer->getStock();
        $offerPrice = $offer->getPrice();

        if (!$offerProduct || !$offerStock || !$offerPrice) {
            throw new \Exception("Offer is incomplete: missing product, stock, or price data");
        }

        // Build base payload from Offer entities
        // Get category ID: prefer product's inpost_category_id, fallback to offer level
        $categoryId = $offerProduct->getInpostCategoryId() ?? $offer->getInpostCategoryId();

        $payload = [
            'externalId' => $this->generateExternalIdFromOffer($offer),
            'product' => [
                'name' => $offerProduct->getName() ?? '',
                'description' => $offerProduct->getDescription() ?? '',
                'brand' => $offerProduct->getBrand() ?? 'No Brand',
                'categoryId' => $categoryId,
            ],
            'stock' => [
                'quantity' => $offerStock->getQuantity() ?? 0,
                'unit' => 'UNIT',
            ],
            'price' => [
                'grossPrice' => [
                    'amount' => (float) $offerPrice->getPrice(),
                    'currency' => $offerPrice->getCurrency() ?? 'PLN',
                ],
                // VAT of the product's tax rule in PrestaShop (0% when it has none)
                'taxRateInfo' => $offerPrice->getTaxRate() !== null
                    ? ShopTaxCurrencyInfo::formatRate((float) $offerPrice->getTaxRate())
                    : ShopTaxCurrencyInfo::formatRate($this->shopTaxCurrencyInfo->productTaxRate((int) $offer->getPrestashopOfferId())),
            ],
        ];

        // Add optional product fields
        if ($offerProduct->getSku()) {
            $payload['product']['sku'] = $offerProduct->getSku();
        }
        if ($offerProduct->getModel()) {
            $payload['product']['model'] = $offerProduct->getModel();
        }
        if ($offerProduct->getEan()) {
            $payload['product']['ean'] = $offerProduct->getEan();
        }
        if ($offerProduct->getSuperModel()) {
            $payload['product']['superModel'] = $offerProduct->getSuperModel();
        }
        if ($offerProduct->getManufacturerProductNumber()) {
            $payload['product']['manufacturerProductNumber'] = $offerProduct->getManufacturerProductNumber();
        }

        // Add dimensions if provided (optional per OpenAPI 1.2.1)
        $this->addDimensionsFromOffer($payload, $offerProduct);

        // Add attributes with InPost UUIDs
        $this->addAttributesFromOffer($payload, $offer);

        // Add GPSR data
        $this->addGpsrFromOffer($payload, $offer);

        // Shipping time is optional in the API - send it only when it resolves to a value
        $deliveryDays = $this->resolveDeliveryDays($offer);
        if ($deliveryDays !== null) {
            $payload['shippingTime'] = ['daysToShip' => $deliveryDays];
        }

        // Add postSale from offer or config fallback
        $this->addPostSaleFromOffer($payload, $offer);

        // NOTE: 'language' field removed - NOT in OpenAPI spec

        return $payload;
    }

    /**
     * Generate external ID from Offer entity
     */
    private function generateExternalIdFromOffer(Offer $offer): string
    {
        return self::externalId((int) $offer->getPrestashopOfferId(), $offer->getPrestashopProductAttributeId());
    }

    /**
     * ps_product_{id} for the whole product, ps_product_{id}_{combination} for a
     * combination (parsed back by PrestaShopOrderCreator)
     */
    public static function externalId(int $idProduct, int $idProductAttribute = 0): string
    {
        return $idProductAttribute > 0
            ? "ps_product_{$idProduct}_{$idProductAttribute}"
            : "ps_product_{$idProduct}";
    }

    /**
     * Add dimensions to payload if product has dimension data
     *
     * OpenAPI 1.2.1 Change: dimension field is now OPTIONAL (was required in 1.2.0)
     * Only sends dimensions if at least one dimension value is set on the product.
     * When sending, all 4 fields are included (with defaults for missing) per OpenAPI schema.
     *
     * Historical Note: InPost API previously had a bug where CREATE without dimension
     * caused corrupted data. This appears to be fixed with OpenAPI 1.2.1 spec update.
     */
    private function addDimensionsFromOffer(array &$payload, OfferProduct $product): void
    {
        $width = $product->getDimensionWidth();
        $height = $product->getDimensionHeight();
        $length = $product->getDimensionLength();
        $weight = $product->getDimensionWeight();

        // Only send dimensions if at least one value is provided
        if ($width !== null || $height !== null || $length !== null || $weight !== null) {
            // When sending dimensions, all 4 fields are required per OpenAPI schema
            $payload['product']['dimension'] = [
                'width' => (int) ($width ?? 1),   // mm (default 1mm if missing)
                'height' => (int) ($height ?? 1), // mm (default 1mm if missing)
                'length' => (int) ($length ?? 1), // mm (default 1mm if missing)
                'weight' => (int) ($weight ?? 1), // g (default 1g if missing)
            ];
        }
        // If no dimension data, omit entirely (valid per OpenAPI 1.2.1)
    }

    /**
     * Add attributes from Offer entity using InPost UUIDs
     *
     * Only rows of the offer's current category: rows left from a category the
     * offer was in before must not reach InPost. An offer already on InPost always
     * gets the list (empty too) - the PATCH is a merge-patch, so a missing list
     * would keep the attributes InPost has, e.g. those of the previous category.
     */
    private function addAttributesFromOffer(array &$payload, Offer $offer): void
    {
        $isSent = !empty($offer->getInpostOfferId());
        $attributes = $offer->getAttributes();
        if ($attributes->isEmpty()) {
            if ($isSent) {
                $payload['product']['attributes'] = [];
            }
            return;
        }

        $categoryMappingId = $offer->getProduct() !== null ? (string) $offer->getProduct()->getCategoryMappingId() : '';

        $payload['product']['attributes'] = [];
        foreach ($attributes as $attr) {
            $inpostId = $attr->getInpostAttributeId();
            if (!$inpostId) {
                continue;
            }

            $rowCategory = (string) $attr->getCategoryMappingId();
            if ($categoryMappingId !== '' && $rowCategory !== '' && $rowCategory !== $categoryMappingId) {
                continue;
            }

            // Get value(s) from JSON array stored in value column
            $jsonValue = $attr->getValue();
            $values = json_decode($jsonValue, true);

            // Fallback for legacy non-JSON values
            if (!is_array($values)) {
                $values = $jsonValue ? [$jsonValue] : [];
            }

            if (!empty($values)) {
                $payload['product']['attributes'][] = [
                    'id' => $inpostId,
                    'values' => $values,
                ];
            }
        }

        // Remove empty array (kept for offers on InPost, see above)
        if (empty($payload['product']['attributes']) && !$isSent) {
            unset($payload['product']['attributes']);
        }
    }

    /**
     * Add GPSR data from Offer entity including manuals
     */
    private function addGpsrFromOffer(array &$payload, Offer $offer): void
    {
        $gpsr = [];

        // Manufacturer: per-field fallback — the offer's own value wins, otherwise
        // the global default from module configuration (GPSR & Manufacturer Details).
        $manufacturer = $offer->getManufacturer();
        $name = $this->resolveGpsrValue($manufacturer ? $manufacturer->getName() : null, ConfigurationService::MFR_NAME);
        if ($name !== '') {
            $mfgData = ['name' => $name];

            $email = $this->resolveGpsrValue($manufacturer ? $manufacturer->getEmail() : null, ConfigurationService::MFR_EMAIL);
            if ($email !== '') {
                $mfgData['email'] = $email;
            }
            $phone = $this->resolveGpsrValue($manufacturer ? $manufacturer->getPhone() : null, ConfigurationService::MFR_PHONE);
            if ($phone !== '') {
                $mfgData['phone'] = $phone;
            }
            $address = $this->buildManufacturerAddress($manufacturer);
            if (!empty($address)) {
                $mfgData['address'] = $address;
            }
            // Manufacturer-level country code, also sent when the address is omitted
            $countryCode = self::resolveManufacturerAddress($manufacturer)['countryCode'] ?? '';
            if ($countryCode !== '') {
                $mfgData['countryCode'] = $countryCode;
            }
            $gpsr['manufacturer'] = $mfgData;
        }

        // GPSR specific fields (same offer-wins-else-global fallback).
        $offerGpsr = $offer->getGpsr();

        $batch = $this->resolveGpsrValue($offerGpsr ? $offerGpsr->getBatchNumber() : null, ConfigurationService::GPSR_BATCH_NUMBER);
        if ($batch !== '') {
            $gpsr['batchNumber'] = $batch;
        }

        if ($offerGpsr && $offerGpsr->getCeMarking() !== null) {
            $gpsr['ceMarking'] = (bool) $offerGpsr->getCeMarking();
        } else {
            $gpsr['ceMarking'] = (bool) \Configuration::get(ConfigurationService::GPSR_CE_MARKING);
        }

        $safety = $this->resolveGpsrValue($offerGpsr ? $offerGpsr->getSafetyInformation() : null, ConfigurationService::GPSR_SAFETY_INFORMATION);
        if ($safety !== '') {
            $gpsr['safetyInformation'] = $safety;
        }

        // doesNotRequireGpsrInfo: the offer's own record wins; otherwise the global
        // default (defaults to exempt on install to keep the previous safe behaviour).
        if ($offerGpsr) {
            $gpsr['doesNotRequireGpsrInfo'] = (bool) $offerGpsr->getDoesNotRequireInfo();
        } else {
            $gpsr['doesNotRequireGpsrInfo'] = (bool) \Configuration::get(ConfigurationService::GPSR_DOES_NOT_REQUIRE);
        }

        // Add manuals
        $this->addManualsFromOffer($gpsr, $offer);

        if (!empty($gpsr)) {
            $payload['gpsr'] = $gpsr;
        }
    }

    /**
     * Resolve a GPSR/manufacturer value: the offer's own value if set, otherwise
     * the global default stored in module configuration.
     *
     * @param string|null $offerValue
     * @param string $configKey
     * @return string
     */
    public static function resolveGpsrValue($offerValue, string $configKey): string
    {
        $offerValue = trim((string) $offerValue);
        if ($offerValue !== '') {
            return $offerValue;
        }

        return trim((string) \Configuration::get($configKey));
    }

    /**
     * Address fields required by the InPost API Address model (JSON names)
     */
    public const MANUFACTURER_ADDRESS_REQUIRED = ['street', 'building', 'city', 'postCode', 'countryCode'];

    /**
     * Manufacturer address in InPost API field names, each field resolved
     * offer-value-else-global-default. May be partial - see buildManufacturerAddress().
     *
     * @param mixed $manufacturer OfferManufacturer|null
     * @return array<string, string>
     */
    public static function resolveManufacturerAddress($manufacturer): array
    {
        $map = [
            'street' => [$manufacturer ? $manufacturer->getAddressStreet() : null, ConfigurationService::MFR_STREET],
            'building' => [$manufacturer ? $manufacturer->getAddressBuildingNumber() : null, ConfigurationService::MFR_BUILDING_NUMBER],
            'flat' => [$manufacturer ? $manufacturer->getAddressApartmentNumber() : null, ConfigurationService::MFR_APARTMENT_NUMBER],
            'city' => [$manufacturer ? $manufacturer->getAddressCity() : null, ConfigurationService::MFR_CITY],
            'postCode' => [$manufacturer ? $manufacturer->getAddressPostalCode() : null, ConfigurationService::MFR_POSTAL_CODE],
            'countryCode' => [$manufacturer ? $manufacturer->getAddressCountryCode() : null, ConfigurationService::MFR_COUNTRY_CODE],
        ];

        $address = [];
        foreach ($map as $key => [$offerValue, $configKey]) {
            $value = trim((string) $offerValue);
            if ($value === '') {
                $value = trim((string) \Configuration::get($configKey));
            }
            if ($value !== '') {
                $address[$key] = $value;
            }
        }

        return $address;
    }

    /**
     * Missing required address fields (API names) of a resolved address
     *
     * @param array<string, string> $address Result of resolveManufacturerAddress()
     * @return string[]
     */
    public static function missingManufacturerAddressFields(array $address): array
    {
        return array_values(array_diff(self::MANUFACTURER_ADDRESS_REQUIRED, array_keys($address)));
    }

    /**
     * Manufacturer address for the payload - only when complete: the API rejects
     * the whole offer (HTTP 400) when a required Address field is missing.
     *
     * @param mixed $manufacturer OfferManufacturer|null
     * @return array<string, string> Empty when incomplete
     */
    private function buildManufacturerAddress($manufacturer): array
    {
        $address = self::resolveManufacturerAddress($manufacturer);

        return self::missingManufacturerAddressFields($address) === [] ? $address : [];
    }

    /**
     * Add manuals to GPSR payload from Offer entity
     */
    private function addManualsFromOffer(array &$gpsr, Offer $offer): void
    {
        $manuals = $offer->getManuals();
        if ($manuals->isEmpty()) {
            return;
        }

        $gpsr['manuals'] = [];
        foreach ($manuals as $manual) {
            if ($manual->getUrl()) {
                $manualData = [
                    'url' => $manual->getUrl(),
                ];
                if ($manual->getTitle()) {
                    $manualData['title'] = $manual->getTitle();
                }
                $gpsr['manuals'][] = $manualData;
            }
        }

        // Remove empty array
        if (empty($gpsr['manuals'])) {
            unset($gpsr['manuals']);
        }
    }

    /**
     * Delivery days: the value typed on the offer, else the product mapping
     * (configuration > product field mapping), else Offer Defaults; null when none
     */
    private function resolveDeliveryDays(Offer $offer): ?int
    {
        $own = $offer->getShippingTime();
        if ($own !== null && trim((string) $own) !== '') {
            return (int) $own;
        }

        $source = $this->source('DELIVERY_DAYS');
        if ($source !== ProductFieldReader::NONE) {
            $loaded = $this->productFieldReader->load((int) $offer->getPrestashopOfferId(), (int) $offer->getPrestashopProductAttributeId());
            if ($loaded !== null) {
                $days = self::parseDays($this->productFieldReader->read($loaded[0], $loaded[1], $source));
                if ($days !== null) {
                    return $days;
                }
            }
        }

        return self::parseDays((string) $this->configurationService->get(ConfigurationService::OFFER_DEFAULT_DELIVERY_DAYS, '', $offer->getShopId()));
    }

    /**
     * "3", "3 dni", "2-3 dni" -> 3 (the largest number, so the time is not understated); null without a number
     */
    private static function parseDays(string $value): ?int
    {
        if (!preg_match_all('/\d+/', $value, $matches)) {
            return null;
        }

        return max(array_map('intval', $matches[0]));
    }

    /**
     * Add postSale from Offer entity or config fallback
     */
    private function addPostSaleFromOffer(array &$payload, Offer $offer): void
    {
        $postSale = [];

        // Try offer values first, then config fallback
        $returnPolicy = $offer->getPostSaleReturnPolicy();
        if (!$returnPolicy) {
            $returnPolicy = $this->configurationService->get(ConfigurationService::OFFER_DEFAULT_RETURN_POLICY, null, $offer->getShopId());
        }
        if ($returnPolicy) {
            $postSale['returnPolicy'] = ['description' => (string) $returnPolicy];
        }

        $complaintPolicy = $offer->getPostSaleComplaintPolicy();
        if (!$complaintPolicy) {
            $complaintPolicy = $this->configurationService->get(ConfigurationService::OFFER_DEFAULT_COMPLAINT_POLICY, null, $offer->getShopId());
        }
        if ($complaintPolicy) {
            $postSale['complaintPolicy'] = ['description' => (string) $complaintPolicy];
        }

        if (!empty($postSale)) {
            $payload['postSale'] = $postSale;
        }
    }

    // =========================================================================
    // PRIVATE ENHANCEMENT METHODS
    // =========================================================================

    /**
     * Add images to offer payload
     *
     * @param array &$payload Offer payload (modified by reference)
     * @param Product $product PrestaShop product
     * @param int $idLang Language ID
     * @param int|null $idProductAttribute Product combination ID
     */
    private function addImages(array &$payload, Product $product, int $idLang, ?int $idProductAttribute): void
    {
        $images = Image::getImages($idLang, $product->id);
        if (empty($images)) {
            return;
        }

        // If combination specified, filter to combination-specific images
        if ($idProductAttribute) {
            $combinationImages = \Db::getInstance()->executeS(
                'SELECT id_image FROM ' . _DB_PREFIX_ . 'product_attribute_image
                WHERE id_product_attribute = ' . (int)$idProductAttribute
            );

            if (!empty($combinationImages)) {
                $combinationImageIds = array_column($combinationImages, 'id_image');
                $images = array_filter($images, function ($image) use ($combinationImageIds) {
                    return in_array($image['id_image'], $combinationImageIds);
                });
            }
        }

        $linkRewrite = is_array($product->link_rewrite)
            ? $product->link_rewrite[$idLang]
            : $product->link_rewrite;

        // Test override: when the shop is unreachable by InPost (e.g. behind a VPN),
        // replace every image fileUrl with a fixed external URL so InPost can still
        // fetch a valid image. fileName/priority are kept so each image stays unique.
        $testMode = (bool) \Configuration::get(ConfigurationService::IMAGE_TEST_MODE_ENABLED);
        $testUrl = trim((string) \Configuration::get(ConfigurationService::IMAGE_TEST_URL));
        $useTestUrl = $testMode && $testUrl !== '';

        // InPost only accepts https image URLs. The context Link picks the protocol
        // from the current request, which is plain http in CLI (cron) or behind a
        // TLS-terminating proxy - so force https whenever the shop has SSL enabled.
        $link = \Configuration::get('PS_SSL_ENABLED')
            ? new \Link('https://', 'https://')
            : \Context::getContext()->link;

        $payload['images'] = [];
        foreach ($images as $image) {
            $fileUrl = $link->getImageLink(
                $linkRewrite,
                $image['id_image'],
                'large_default'
            );

            $payload['images'][] = [
                'fileName' => basename((string) parse_url($fileUrl, PHP_URL_PATH)),
                'fileUrl'  => $useTestUrl ? $testUrl : $fileUrl,
                'priority' => (int) ($image['position'] ?? 0),
            ];
        }
    }

    /**
     * Source configured for an offer product field (OfferFieldMapping), the default when not set
     */
    private function source(string $field): string
    {
        return OfferFieldMapping::effective($field, $this->configurationService->get(OfferFieldMapping::configKey($field)));
    }

    /**
     * Value of an offer product field from the PrestaShop product ('' when empty)
     */
    private function fieldValue(Product $product, ?Combination $combination, string $field): string
    {
        return $this->productFieldReader->read($product, $combination, $this->source($field));
    }
}
