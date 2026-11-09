<?php
/**
 * OfferValidatorService
 *
 * Service for validating InPost offer payloads before API submission.
 * Checks required fields, category requirements, attribute validation,
 * dictionary compliance, image requirements, and pricing rules.
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Service\Business;

use Inpost\VonHalsky\Service\Mapping\AttributeMappingService;
use Inpost\VonHalsky\Service\Util\ConfigurationService;
use Inpost\VonHalsky\Service\Util\LoggerService;
use Inpost\VonHalsky\Service\Util\ShopTaxCurrencyInfo;
use Inpost\VonHalsky\Entity\Offer;
use Inpost\VonHalsky\Entity\OfferAttributes;
use Inpost\VonHalsky\Repository\CategoryMappingRepository;
use Inpost\VonHalsky\Repository\OfferGpsrRepository;
use Inpost\VonHalsky\Service\Util\ValidationUtils;

class OfferValidatorService
{
    private CategoryMappingRepository $categoryMappingRepository;
    private AttributeMappingService $attributeMappingService;
    private LoggerService $logger;
    private OfferGpsrRepository $offerGpsrRepository;
    private ShopTaxCurrencyInfo $shopTaxCurrencyInfo;

    // Validation severity levels
    private const SEVERITY_ERROR = 'error';      // Blocks submission
    private const SEVERITY_WARNING = 'warning';  // Allows submission but may cause issues
    private const SEVERITY_INFO = 'info';        // Informational only

    public function __construct(
        CategoryMappingRepository $categoryMappingRepository,
        AttributeMappingService $attributeMappingService,
        LoggerService $logger,
        OfferGpsrRepository $offerGpsrRepository,
        ShopTaxCurrencyInfo $shopTaxCurrencyInfo
    ) {
        $this->categoryMappingRepository = $categoryMappingRepository;
        $this->attributeMappingService = $attributeMappingService;
        $this->logger = $logger;
        $this->offerGpsrRepository = $offerGpsrRepository;
        $this->shopTaxCurrencyInfo = $shopTaxCurrencyInfo;
    }

    /**
     * Validate Offer entity
     *
     * @param Offer $offer Offer entity to validate
     * @param string $language Language code (default: 'pl')
     * @return array Validation result with errors, warnings, and info
     */
    public function validate(Offer $offer, string $language = 'pl'): array
    {
        $this->logger->info("Validating offer entity", ['offer_id' => $offer->getOfferId()]);

        $result = [
            'is_valid' => true,
            'can_submit' => true,
            'errors' => [],
            'warnings' => [],
            'info' => [],
        ];

        // Block draft offers from being sent (INFO, not error - draft is a valid state for editing)
        if ($offer->getIsDraft() === 1) {
            $this->addInfo($result, 'is_draft', 'Offer is in draft mode. Finalize to enable sending to InPost.');
            $result['can_submit'] = false;
        }

        // Validate required fields
        $this->validateRequired($offer, $result);

        // Validate category
        $categoryId = $offer->getInpostCategoryId();
        if ($categoryId) {
            $this->validateCategory($offer, $language, $result);
        }

        // Validate attributes
        $attributes = $offer->getAttributes();
        if ($attributes && count($attributes) > 0) {
            $this->validateAttributes($offer, $language, $result);
        }
        if ($categoryId) {
            $this->validateRequiredAttributes($offer, $language, $result);
        }

        // Validate pricing
        $price = $offer->getPrice();
        if ($price) {
            $this->validatePricing($offer, $result);
        }

        // Validate stock
        $stock = $offer->getStock();
        if ($stock) {
            $this->validateStock($offer, $result);
        }

        // Validate dimensions
        $product = $offer->getProduct();
        if ($product && ($product->getDimensionWidth() || $product->getDimensionHeight() || $product->getDimensionLength())) {
            $this->validateDimensions($offer, $result);
        }

        // Validate EAN checksum
        $this->validateEanFromOffer($offer, $result);

        // Validate GPSR manufacturer data
        $this->validateGpsrData($offer, $result);

        // Determine overall validity
        $result['is_valid'] = empty($result['errors']);
        // can_submit requires: valid AND not blocked by draft mode (or other blockers)
        $result['can_submit'] = $result['is_valid'] && $result['can_submit'];

        // Combine all messages for unified display
        $result['messages'] = array_merge($result['errors'], $result['warnings'], $result['info']);

        if (!$result['is_valid']) {
            $this->logger->warning("Offer validation failed", [
                'offer_id' => $offer->getOfferId(),
                'error_count' => count($result['errors']),
                'warning_count' => count($result['warnings']),
            ]);
        } else {
            $this->logger->info("Offer validation passed", [
                'offer_id' => $offer->getOfferId(),
                'warning_count' => count($result['warnings']),
                'info_count' => count($result['info']),
            ]);
        }

        return $result;
    }

    /**
     * Get validation status from validation result
     *
     * Determines the InPost validation status based on validation result:
     * - VALID: No errors
     * - INVALID: Has errors
     * - WARNING: No errors but has warnings
     *
     * @param array $validationResult Validation result from validate()
     * @return string Validation status: VALID, INVALID, or WARNING
     */
    public function getValidationStatus(array $validationResult): string
    {
        if (!empty($validationResult['errors'])) {
            return 'INVALID';
        }

        if (!empty($validationResult['warnings'])) {
            return 'WARNING';
        }

        return 'VALID';
    }

    // =========================================================================
    // PRIVATE VALIDATION METHODS
    // =========================================================================

    /**
     * Validate required fields
     *
     * @param Offer $offer Offer entity
     * @param array &$result Validation result (modified by reference)
     */
    private function validateRequired(Offer $offer, array &$result): void
    {
        // External ID (prestashop_offer_id)
        if (!$offer->getPrestashopOfferId()) {
            $this->addError($result, 'external_id', 'External ID is required');
        }

        // Product data
        $product = $offer->getProduct();
        if (!$product) {
            $this->addError($result, 'product', 'Product data is required');
        } else {
            // Product required fields
            if (!$product->getName() || trim($product->getName()) === '') {
                $this->addError($result, 'product.name', 'Product name is required');
            }
            if (!$product->getDescription() || trim($product->getDescription()) === '') {
                $this->addError($result, 'product.description', 'Product description is required');
            }
            if (!$product->getSku() || trim($product->getSku()) === '') {
                $this->addError($result, 'product.sku', 'SKU is required');
            }
            // EAN is required only when the product cannot be identified by
            // brand + manufacturer product number (GOKINT-932 / GOKINT-516).
            // Brand stays unconditionally required below, so in practice this
            // reduces to "MPN must be present" — that is intentional.
            if ((!$product->getEan() || trim($product->getEan()) === '')
                && !ValidationUtils::hasAlternativeIdentification(
                    $product->getBrand(),
                    $product->getManufacturerProductNumber()
                )
            ) {
                $this->addError($result, 'product.ean', ValidationUtils::EAN_MESSAGE_REQUIRED);
            }
            if (!$product->getBrand() || trim($product->getBrand()) === '') {
                $this->addError($result, 'product.brand', 'Brand is required');
            }
            if (!$product->getModel() || trim($product->getModel()) === '') {
                $this->addError($result, 'product.model', 'Model is required');
            }
        }

        // Category ID
        if (!$offer->getInpostCategoryId()) {
            $this->addError($result, 'category_id', 'Category ID is required');
        }

        // Stock information
        if (!$offer->getStock()) {
            $this->addError($result, 'stock', 'Stock information is required');
        }

        // Price information
        if (!$offer->getPrice()) {
            $this->addError($result, 'price', 'Price information is required');
        }
    }

    /**
     * Validate the offer category against the locally synced category tree.
     *
     * Deliberately no InPost API call: validation runs on every offer page render
     * and per product in bulk pre-validation, and the category sync already keeps
     * the leaf/active flags locally.
     *
     * @param Offer $offer Offer entity
     * @param string $language Language code
     * @param array &$result Validation result
     */
    private function validateCategory(Offer $offer, string $language, array &$result): void
    {
        $category = $this->categoryMappingRepository->findByInpostCategoryId((string) $offer->getInpostCategoryId(), $language);

        if ($category === null) {
            $this->addWarning(
                $result,
                'category_id',
                'Category not found in the synchronized InPost categories - run the category sync'
            );
            return;
        }

        if (!$category->getIsLeaf()) {
            $this->addError(
                $result,
                'category_id',
                'Category must be a leaf category (cannot have subcategories)'
            );
        }

        if (!$category->getIsActive()) {
            $this->addWarning(
                $result,
                'category_id',
                'Category is not active - offer may be rejected'
            );
        }
    }

    /**
     * Validate attributes against InPost requirements and dictionaries
     *
     * @param Offer $offer Offer entity
     * @param string $language Language code
     * @param array &$result Validation result
     */
    private function validateAttributes(Offer $offer, string $language, array &$result): void
    {
        $attributes = $offer->getAttributes();
        $categoryMappingId = $offer->getProduct() !== null ? (string) $offer->getProduct()->getCategoryMappingId() : '';

        foreach ($attributes as $offerAttribute) {
            // Left from a category the offer was in before - not sent to InPost
            if (!self::isOfCategory($offerAttribute, $categoryMappingId)) {
                continue;
            }

            $attributeId = $offerAttribute->getInpostAttributeId();
            if (!$attributeId) {
                $this->addError(
                    $result,
                    'product.attributes',
                    'Attribute must have an InPost attribute ID'
                );
                continue;
            }

            // Get values from JSON array stored in value column
            $jsonValue = $offerAttribute->getValue();
            $decoded = json_decode($jsonValue, true);

            // Parse values into array (fallback for legacy non-JSON)
            $values = is_array($decoded) ? $decoded : ($jsonValue ? [$jsonValue] : []);

            // Validate against dictionary if exists
            $dictionaryOptions = $this->attributeMappingService->getDictionaryOptions($attributeId, $language);

            if (!empty($dictionaryOptions)) {
                // Attribute has dictionary - validate values
                foreach ($values as $val) {
                    // JSON may decode to int/float/array - compare as string
                    $val = is_scalar($val) ? (string) $val : (string) json_encode($val);
                    if (!AttributeMappingService::isValueInDictionary($dictionaryOptions, $val)) {
                        $this->addError(
                            $result,
                            "product.attributes.{$attributeId}",
                            "Value '{$val}' is not valid for attribute {$attributeId}"
                        );
                    }
                }
            }

            // Check for empty values
            if (empty($values) || (count($values) === 1 && trim((string)$values[0]) === '')) {
                $this->addWarning(
                    $result,
                    "product.attributes.{$attributeId}",
                    "Attribute {$attributeId} has empty value"
                );
            }
        }
    }

    /**
     * Every attribute the InPost category requires (expectedValue ONE /
     * AT_LEAST_ONE) must have a value - InPost rejects the offer otherwise
     *
     * @param Offer $offer Offer entity
     * @param string $language Language code
     * @param array &$result Validation result
     */
    private function validateRequiredAttributes(Offer $offer, string $language, array &$result): void
    {
        $categoryMappingId = $offer->getProduct() !== null ? (int) $offer->getProduct()->getCategoryMappingId() : 0;
        if (!$categoryMappingId) {
            $category = $this->categoryMappingRepository->findByInpostCategoryId((string) $offer->getInpostCategoryId(), $language);
            $categoryMappingId = $category !== null ? (int) $category->getEntityId() : 0;
        }
        if (!$categoryMappingId) {
            return;
        }

        $filled = [];
        foreach ($offer->getAttributes() as $offerAttribute) {
            if (!self::isOfCategory($offerAttribute, (string) $categoryMappingId)) {
                continue;
            }
            $decoded = json_decode($offerAttribute->getValue(), true);
            $values = is_array($decoded) ? $decoded : [$offerAttribute->getValue()];
            foreach ($values as $value) {
                if (is_scalar($value) && trim((string) $value) !== '') {
                    $filled[(string) $offerAttribute->getInpostAttributeId()] = true;
                    break;
                }
            }
        }

        foreach ($this->attributeMappingService->getAttributesForCategoryMappingId($categoryMappingId, $language) as $attribute) {
            if (!empty($attribute['required']) && !isset($filled[(string) $attribute['code']])) {
                $this->addError(
                    $result,
                    "product.attributes.{$attribute['code']}",
                    'Required attribute "%name%" has no value',
                    ['%name%' => $attribute['label']]
                );
            }
        }
    }

    /**
     * Whether an offer attribute row belongs to the category (rows without a
     * category are legacy - taken as belonging)
     */
    private static function isOfCategory(OfferAttributes $offerAttribute, string $categoryMappingId): bool
    {
        $rowCategory = (string) $offerAttribute->getCategoryMappingId();

        return $categoryMappingId === '' || $categoryMappingId === '0' || $rowCategory === '' || $rowCategory === $categoryMappingId;
    }

    /**
     * Validate pricing
     *
     * @param Offer $offer Offer entity
     * @param array &$result Validation result
     */
    private function validatePricing(Offer $offer, array &$result): void
    {
        $price = $offer->getPrice();

        if (!$price) {
            $this->addError($result, 'price', 'Price is required');
            return;
        }

        // Validate price amount
        $priceAmount = $price->getPrice();
        if ($priceAmount === null || !is_numeric($priceAmount)) {
            $this->addError($result, 'price.amount', 'Price amount must be a number');
        } elseif ($priceAmount <= 0) {
            $this->addError($result, 'price.amount', 'Price must be greater than 0');
        } elseif ($priceAmount < 0.01) {
            $this->addWarning(
                $result,
                'price.amount',
                'Price is very low - may be rejected by InPost'
            );
        }

        // Validate currency - InPost von Halsky prices offers in PLN
        $currency = $price->getCurrency();
        if (!$currency || empty($currency)) {
            $this->addError($result, 'price.currency', 'Currency is required');
        } elseif ($currency !== ShopTaxCurrencyInfo::INPOST_CURRENCY) {
            $this->addWarning(
                $result,
                'price.currency',
                "Price is in {$currency}, InPost expects PLN - the amount is sent without conversion"
            );
        }

        // VAT: the offer declares the rate of the product's tax rule (0% without one)
        $idProduct = (int) $offer->getPrestashopOfferId();
        if ($idProduct > 0 && !$this->shopTaxCurrencyInfo->hasProductTaxRule($idProduct)) {
            $this->addWarning(
                $result,
                'price.tax',
                $this->shopTaxCurrencyInfo->areTaxesEnabled()
                    ? 'The product has no tax rule - the offer is sent to InPost with 0% VAT and orders are created without VAT. Assign a tax rule to the product (Catalog > Products > Pricing).'
                    : 'Taxes are disabled in the shop - the offer is sent to InPost with 0% VAT and orders are created without VAT (International > Taxes > Enable tax).'
            );
        }
    }

    /**
     * Validate stock information
     *
     * @param Offer $offer Offer entity
     * @param array &$result Validation result
     */
    private function validateStock(Offer $offer, array &$result): void
    {
        $stock = $offer->getStock();

        if (!$stock) {
            $this->addError($result, 'stock', 'Stock information is required');
            return;
        }

        // Validate quantity
        $quantity = $stock->getQuantity();
        if ($quantity === null) {
            $this->addError($result, 'stock.quantity', 'Stock quantity is required');
        } elseif (!is_numeric($quantity) || $quantity < 0) {
            $this->addError($result, 'stock.quantity', 'Stock quantity must be >= 0');
        } elseif ($quantity == 0) {
            $this->addWarning(
                $result,
                'stock.quantity',
                'Product has 0 stock - will not be available for purchase'
            );
        }

        // Validate unit
    }

    /**
     * Validate product dimensions
     *
     * @param Offer $offer Offer entity
     * @param array &$result Validation result
     */
    private function validateDimensions(Offer $offer, array &$result): void
    {
        $product = $offer->getProduct();

        if (!$product) {
            return;
        }

        // Get dimension values from product entity
        $width = $product->getDimensionWidth();
        $height = $product->getDimensionHeight();
        $length = $product->getDimensionLength();
        $weight = $product->getDimensionWeight();

        // Check if all dimensions are provided together (completeness)
        $hasWidth = $width !== null && $width > 0;
        $hasHeight = $height !== null && $height > 0;
        $hasLength = $length !== null && $length > 0;
        $hasWeight = $weight !== null && $weight > 0;

        $dimensionCount = (int)$hasWidth + (int)$hasHeight + (int)$hasLength;

        // If any dimension is provided, all should be provided
        if ($dimensionCount > 0 && $dimensionCount < 3) {
            $this->addWarning(
                $result,
                'product.dimensions',
                'Dimensions are incomplete - provide width, height, and length together'
            );
        }

        // Validate weight separately
        if (!$hasWeight && $dimensionCount > 0) {
            $this->addWarning(
                $result,
                'product.dimensions.weight',
                'Weight should be provided when dimensions are specified'
            );
        }

        // Validate dimension values are positive
        if ($width !== null && $width <= 0) {
            $this->addError($result, 'product.dimensions.width', 'Width must be greater than 0');
        }
        if ($height !== null && $height <= 0) {
            $this->addError($result, 'product.dimensions.height', 'Height must be greater than 0');
        }
        if ($length !== null && $length <= 0) {
            $this->addError($result, 'product.dimensions.length', 'Length must be greater than 0');
        }
        if ($weight !== null && $weight <= 0) {
            $this->addError($result, 'product.dimensions.weight', 'Weight must be greater than 0');
        }
    }

    /**
     * Add error to validation result
     *
     * @param array &$result Validation result
     * @param string $field Field name
     * @param string $message Error message
     */
    private function addError(array &$result, string $field, string $message, array $params = []): void
    {
        $result['errors'][] = [
            'field' => $field,
            'message' => $message,
            // Translation parameters of the message (%name% => value)
            'params' => $params,
            'severity' => self::SEVERITY_ERROR,
        ];
    }

    /**
     * Add warning to validation result
     *
     * @param array &$result Validation result
     * @param string $field Field name
     * @param string $message Warning message
     */
    private function addWarning(array &$result, string $field, string $message): void
    {
        $result['warnings'][] = [
            'field' => $field,
            'message' => $message,
            'severity' => self::SEVERITY_WARNING,
        ];
    }

    /**
     * Add info to validation result
     *
     * @param array &$result Validation result
     * @param string $field Field name
     * @param string $message Info message
     */
    private function addInfo(array &$result, string $field, string $message): void
    {
        $result['info'][] = [
            'field' => $field,
            'message' => $message,
            'severity' => self::SEVERITY_INFO,
        ];
    }


    /**
     * Validate EAN from offer product
     *
     * @param Offer $offer
     * @param array &$result
     */
    private function validateEanFromOffer(Offer $offer, array &$result): void
    {
        $product = $offer->getProduct();
        if (!$product) {
            return;
        }

        // Requiredness is handled by validateRequired(); here we only check the
        // format of a supplied EAN, and surface which specific check failed.
        $ean = $product->getEan();
        if (!empty($ean) && trim($ean) !== '') {
            $message = ValidationUtils::validateEanDetailed($ean, false);
            if ($message !== null) {
                $this->addError($result, 'product.ean', $message);
            }
        }
    }

    /**
     * Validate GPSR manufacturer data
     *
     * If not exempt (doesNotRequireInfo = false):
     * - name, email, countryCode are REQUIRED
     * - phone (optional, but must be E.164 if provided)
     * - address (optional, but all fields required if any field provided)
     *
     * @param Offer $offer
     * @param array &$result
     */
    private function validateGpsrData(Offer $offer, array &$result): void
    {
        // Check if GPSR is required (default: required unless explicitly exempt)
        // First try entity relationship, then fallback to direct repository query
        // (workaround for Doctrine OneToOne inverse-side lazy loading issues)
        $gpsr = $offer->getGpsr();

        if ($gpsr === null && $offer->getOfferId() !== null) {
            // Direct query as fallback - bypasses OneToOne inverse-side lazy-loading
            // issues. Query by the mapped association ('offer'), not the raw column.
            $gpsr = $this->offerGpsrRepository->findOneBy(['offer' => $offer]);
        }

        $this->logger->debug('GPSR validation check', [
            'offer_id' => $offer->getOfferId(),
            'gpsr_loaded' => $gpsr !== null,
            'gpsr_exempt_value' => $gpsr !== null ? $gpsr->getDoesNotRequireInfo() : null,
        ]);

        // No GPSR record at all → exempt by default (the module default for new
        // offers; see OfferController::initializeRelatedEntities). Bulk-created
        // offers have no GPSR row until edited.
        $isGpsrExempt = $gpsr === null || $gpsr->getDoesNotRequireInfo();
        if ($isGpsrExempt) {
            $this->addInfo($result, 'gpsr', 'GPSR validation skipped (product is exempt)');
            return;
        }

        // GPSR required: validate manufacturer data
        $manufacturer = $offer->getManufacturer();
        if (!$manufacturer) {
            $this->addError($result, 'manufacturer', 'Manufacturer data is required for GPSR compliance');
            return;
        }

        // Same offer-value-else-global-default resolution as the payload builder,
        // so "Valid" here means the payload InPost receives is complete.
        $manufacturerName = OfferBuilderService::resolveGpsrValue($manufacturer->getName(), ConfigurationService::MFR_NAME);
        if ($manufacturerName === '') {
            $this->addError($result, 'manufacturer.name', 'Manufacturer name is required for GPSR compliance');
        }

        $manufacturerEmail = OfferBuilderService::resolveGpsrValue($manufacturer->getEmail(), ConfigurationService::MFR_EMAIL);
        if ($manufacturerEmail === '') {
            $this->addError($result, 'manufacturer.email', 'Manufacturer email is required for GPSR compliance');
        } elseif (!filter_var($manufacturerEmail, FILTER_VALIDATE_EMAIL)) {
            $this->addError($result, 'manufacturer.email', 'Manufacturer email must be valid');
        }

        $address = OfferBuilderService::resolveManufacturerAddress($manufacturer);
        $countryCode = $address['countryCode'] ?? '';
        if ($countryCode === '') {
            $this->addError($result, 'manufacturer.country_code', 'Manufacturer country code is required for GPSR compliance');
        } elseif (strlen($countryCode) !== 2) {
            $this->addError($result, 'manufacturer.country_code', 'Manufacturer country code must be 2-letter ISO 3166-1 code');
        }

        // Phone validation (optional, but must be E.164 if provided)
        $phone = OfferBuilderService::resolveGpsrValue($manufacturer->getPhone(), ConfigurationService::MFR_PHONE);
        if ($phone !== '' && !ValidationUtils::validatePhone($phone)) {
            $this->addError($result, 'manufacturer.phone', 'Manufacturer phone must be in E.164 format (+{3-15 digits})');
        }

        // Address: all required fields or none (an incomplete address is not sent)
        $hasAddressDetails = array_diff_key($address, ['countryCode' => true]) !== [];
        $missing = OfferBuilderService::missingManufacturerAddressFields($address);
        if ($hasAddressDetails && $missing !== []) {
            $this->addError(
                $result,
                'manufacturer.address',
                'Manufacturer address is incomplete - missing: ' . implode(', ', $missing)
                . ' (fill them in the offer or in the module GPSR & Manufacturer defaults)'
            );
        }
    }
}
