<?php

declare(strict_types=1);

namespace Inpost\VonHalsky\Controller\Admin;

use Inpost\VonHalsky\Entity\Offer;
use Inpost\VonHalsky\Form\Type\OfferType;
use Inpost\VonHalsky\Repository\OfferRepository;
use Inpost\VonHalsky\Repository\OrganizationRepository;
use Inpost\VonHalsky\Helper\Config;
use Inpost\VonHalsky\Service\Util\ConfigurationService;
use Inpost\VonHalsky\Service\Business\OfferBuilderService;
use Inpost\VonHalsky\Service\Business\OfferValidatorService;
use Inpost\VonHalsky\Service\Offer\OfferService;
use Inpost\VonHalsky\Service\Offer\ProductHintService;
use Inpost\VonHalsky\Service\Offer\HintAttributeMapper;
use Inpost\VonHalsky\Service\Offer\HintApplyService;
use Inpost\VonHalsky\Service\Offer\ProductAttributeValueResolver;
use Inpost\VonHalsky\Service\Offer\ProductCombinationService;
use Inpost\VonHalsky\Service\Offer\ProductPreValidationService;
use Inpost\VonHalsky\Service\Offer\BulkOfferCreationService;
use Inpost\VonHalsky\Service\Offer\HintProductizationService;
use Inpost\VonHalsky\Service\Offer\OfferCategoryChangeService;
use Inpost\VonHalsky\Service\Offer\OfferPriceResolver;
use Inpost\VonHalsky\Entity\OfferPrice;
use Inpost\VonHalsky\Entity\CategoryMapping;
use Inpost\VonHalsky\Service\Util\LoggerService;
use Inpost\VonHalsky\Service\Mapping\CategoryMappingService;
use Inpost\VonHalsky\Service\Mapping\AttributeMappingService;
use Inpost\VonHalsky\Repository\CategoryMappingRepository;
use Doctrine\ORM\EntityManagerInterface;
use PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Inpost\VonHalsky\Entity\OfferAttributes;
use Inpost\VonHalsky\Entity\Attribute;
use Inpost\VonHalsky\Helper\RequestIds;

/**
 * Offer Controller
 *
 * Handles offer management UI and operations:
 * - List offers with filters (status, organization, product)
 * - Create offers from PrestaShop products
 * - Edit offer details and attributes
 * - Send offers to InPost API
 * - Delete offers
 *
 * @package Inpost\VonHalsky\Controller\Admin
 */
class OfferController extends FrameworkBundleAdminController
{
    /** Rows per page of the offers list */
    private const LIST_PAGE_SIZES = [20, 50, 100];

    /** Query parameters of the offers list kept when a bulk action returns to it */
    private const LIST_QUERY_KEYS = ['status', 'search', 'inpost_category', 'per_page', 'orderBy', 'sortOrder', 'page'];

    private OfferRepository $offerRepository;
    private OrganizationRepository $organizationRepository;
    private Config $config;
    private OfferBuilderService $offerBuilder;
    private OfferValidatorService $offerValidator;
    private OfferService $offerService;
    private EntityManagerInterface $entityManager;
    private LoggerService $logger;
    private CategoryMappingService $categoryMappingService;
    private AttributeMappingService $attributeMappingService;
    private CategoryMappingRepository $categoryMappingRepository;
    private ProductHintService $productHintService;
    private HintAttributeMapper $hintAttributeMapper;
    private HintApplyService $hintApplyService;
    private ProductAttributeValueResolver $attributeValueResolver;
    private ProductCombinationService $combinationService;
    private ProductPreValidationService $preValidation;
    private BulkOfferCreationService $bulkCreation;
    private HintProductizationService $hintProductization;
    private OfferCategoryChangeService $categoryChange;
    private OfferPriceResolver $priceResolver;

    public function __construct(
        OfferRepository $offerRepository,
        OrganizationRepository $organizationRepository,
        Config $config,
        OfferBuilderService $offerBuilder,
        OfferValidatorService $offerValidator,
        OfferService $offerService,
        EntityManagerInterface $entityManager,
        LoggerService $logger,
        CategoryMappingService $categoryMappingService,
        AttributeMappingService $attributeMappingService,
        CategoryMappingRepository $categoryMappingRepository,
        ProductHintService $productHintService,
        HintAttributeMapper $hintAttributeMapper,
        HintApplyService $hintApplyService,
        ProductAttributeValueResolver $attributeValueResolver,
        ProductCombinationService $combinationService,
        ProductPreValidationService $preValidation,
        BulkOfferCreationService $bulkCreation,
        HintProductizationService $hintProductization,
        OfferCategoryChangeService $categoryChange,
        OfferPriceResolver $priceResolver
    ) {
        $this->offerRepository = $offerRepository;
        $this->organizationRepository = $organizationRepository;
        $this->config = $config;
        $this->offerBuilder = $offerBuilder;
        $this->offerValidator = $offerValidator;
        $this->offerService = $offerService;
        $this->entityManager = $entityManager;
        $this->logger = $logger;
        $this->categoryMappingService = $categoryMappingService;
        $this->attributeMappingService = $attributeMappingService;
        $this->categoryMappingRepository = $categoryMappingRepository;
        $this->productHintService = $productHintService;
        $this->hintAttributeMapper = $hintAttributeMapper;
        $this->hintApplyService = $hintApplyService;
        $this->attributeValueResolver = $attributeValueResolver;
        $this->combinationService = $combinationService;
        $this->preValidation = $preValidation;
        $this->bulkCreation = $bulkCreation;
        $this->hintProductization = $hintProductization;
        $this->categoryChange = $categoryChange;
        $this->priceResolver = $priceResolver;
    }

    /**
     * List offers with filters
     *
     * @param Request $request HTTP request
     * @return Response Rendered offer list
     */
    public function listAction(Request $request): Response
    {
        try {
            $shopId = $this->config->getDefaultShopId();
            $page = max(1, (int) $request->query->get('page', 1));
            $limit = (int) $request->query->get('per_page', 20);
            if (!in_array($limit, self::LIST_PAGE_SIZES, true)) {
                $limit = 20;
            }
            $offset = ($page - 1) * $limit;

            // Get filters
            $status = $request->query->get('status');
            $search = trim($request->query->get('search', ''));
            $categoryMappingId = (int) $request->query->get('inpost_category', 0) ?: null;

            // Get sorting params
            $allowedSortColumns = ['offerId', 'updatedAt', 'createdAt', 'syncedAt'];
            $orderBy = $request->query->get('orderBy', 'updatedAt');
            $sortOrder = strtoupper($request->query->get('sortOrder', 'DESC'));

            // Validate sorting
            if (!in_array($orderBy, $allowedSortColumns)) {
                $orderBy = 'updatedAt';
            }
            if (!in_array($sortOrder, ['ASC', 'DESC'])) {
                $sortOrder = 'DESC';
            }

            // Use repository search method
            $offers = $this->offerRepository->searchOffers(
                $shopId,
                $search,
                $status,
                $limit,
                $offset,
                $orderBy,
                $sortOrder,
                $categoryMappingId
            );
            $totalOffers = $this->offerRepository->countSearchOffers($shopId, $search, $status, $categoryMappingId);
            $totalPages = (int) ceil($totalOffers / $limit);

            // "Size: M" of combination offers; a combination deleted in PrestaShop has no label
            $combinationIds = [];
            foreach ($offers as $listedOffer) {
                if ($listedOffer->isCombinationOffer()) {
                    $combinationIds[] = $listedOffer->getPrestashopProductAttributeId();
                }
            }
            $combinationLabels = $this->combinationService->getLabels($combinationIds);

            // InPost categories of the shop's offers, for the category filter
            $categoryChoices = [];
            foreach ($this->offerRepository->findUsedCategoryMappingIds($shopId) as $usedCategoryId) {
                $categoryChoices[$usedCategoryId] = implode(' > ', $this->categoryMappingService->getInpostCategoryPath($usedCategoryId));
            }
            natcasesort($categoryChoices);

            return $this->render('@Modules/inpostvonhalsky/views/templates/admin/offer/list.html.twig', [
                'offers' => $offers,
                'combination_labels' => $combinationLabels,
                'category_choices' => $categoryChoices,
                'page_sizes' => self::LIST_PAGE_SIZES,
                'module_js_path' => Config::getModuleJsUri(),
                'filters' => [
                    'shopId' => $shopId,
                    'status' => $status,
                    'search' => $search,
                    'inpost_category' => $categoryMappingId,
                    'per_page' => $limit,
                    'orderBy' => $orderBy,
                    'sortOrder' => $sortOrder,
                ],
                'sorting' => [
                    'order_by' => $orderBy,
                    'sort_order' => $sortOrder,
                ],
                'pagination' => [
                    'current_page' => $page,
                    'total_pages' => $totalPages,
                    'total_items' => $totalOffers,
                    'items_per_page' => $limit
                ]
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Offer list error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            $this->addFlash('error', $this->trans('Failed to load offers: %error%', 'Modules.Inpostvonhalsky.Admin', ['%error%' => $e->getMessage()]));
            return $this->redirectToDefaultPage();
        }
    }

    /**
     * Display create offer form
     *
     * @param Request $request HTTP request
     * @return Response Rendered form
     */
    public function createAction(Request $request): Response
    {
        try {
            $productId = (int) $request->query->get('product_id');
            $productAttributeId = max(0, (int) $request->query->get('product_attribute_id', 0));
            $shopId = $this->config->getDefaultShopId();

            // A product with combinations: which one to sell (or the whole product) first
            if ($productId && !$request->query->has('product_attribute_id') && $this->combinationService->hasCombinations($productId)) {
                return $this->renderCombinationChoice($productId, $shopId, $request);
            }

            if ($productId) {
                $conflict = $this->combinationService->findConflict($productId, $productAttributeId, $shopId);
                if ($conflict !== null) {
                    $this->addFlash('error', $this->conflictMessage($conflict));

                    return $conflict['reason'] === ProductCombinationService::CONFLICT_SAME
                        ? $this->redirectToRoute('inpost_offers_edit', ['id' => $conflict['offer_id']])
                        : $this->redirectToRoute('inpost_offers_create', ['product_id' => $productId]);
                }
            }

            // Get mapped categories for dropdown choices
            $mappedCategories = [];
            if ($productId) {
                // Auto-populate from product (and the chosen combination)
                $offer = $this->offerBuilder->buildFromProduct($productId, null, $productAttributeId);

                // Retrieve all mapped categories for this product
                $mappedCategories = $this->categoryMappingService->getMappedInPostCategoriesForProduct(
                    $productId,
                    $shopId
                );
            } else {
                // Empty form
                $offer = new Offer();
            }

            // Always ensure related entities are initialized before form creation
            // This is critical for property_path mappings to work correctly
            $this->initializeRelatedEntities($offer);

            // Set default language for new offers
            $offer->setLanguage($this->config->getDefaultLanguageCode());

            // Get organization data from config
            $organizationId = $this->config->getOrganizationId($shopId);
            $organizationName = $this->getOrganizationName($organizationId);
            if ($organizationId) {
                $offer->setOrganizationId($organizationId);
            }

            $selectedCategoryMappingId = $this->resolveCategoryMappingId($request, $offer);
            $categoryAttributes = $this->loadCategoryAttributes($selectedCategoryMappingId);
            // Values from the mapped PS features / attribute groups are already on the
            // offer (OfferBuilderService); hints below only fill what is still empty
            $attributeValues = $this->getAttributeValueMap($offer);
            $valueSources = $this->getAttributeValueSources($offer);

            // GOKINT-927: prefill product fields + attribute values from a chosen hint
            $this->applyHintPrefill($request, $offer, $selectedCategoryMappingId, $attributeValues, $valueSources);

            $psAttributes = $this->resolvePsAttributes($productId, $selectedCategoryMappingId, $categoryAttributes, $productAttributeId);

            $form = $this->createForm(OfferType::class, $offer, [
                'inpost_category_choices' => $this->buildCategoryChoices($mappedCategories),
                'inpost_category_attributes' => $categoryAttributes,
                'inpost_attribute_values' => $attributeValues,
                'inpost_attribute_meta' => $this->buildAttributeMeta($psAttributes, $valueSources),
                'product_id' => $productId,
                'product_attribute_id' => $productAttributeId,
                'organization_name' => $organizationName,
                'organization_id' => $organizationId ?? ''
            ]);
            $form->handleRequest($request);
            $this->validateManualPrice($form);

            if ($form->isSubmitted() && $form->isValid()) {
                $this->persistOfferAttributes($form, $offer, $categoryAttributes, $valueSources, $psAttributes);
                return $this->storeOffer($offer);
            }

            return $this->render('@Modules/inpostvonhalsky/views/templates/admin/offer/form.html.twig', [
                'form' => $form->createView(),
                'price_settings' => $this->priceSettings(),
                'offer' => $offer,
                'mode' => 'create',
                'product_id' => $productId,
                'category_info' => $this->buildCategoryInfo($selectedCategoryMappingId, $mappedCategories),
                'combination_label' => $productAttributeId ? $this->combinationService->getLabel($productAttributeId) : null,
                'product_image_url' => $productId ? $this->getProductImageUrl($productId) : null,
                'module_js_path' => Config::getModuleJsUri(),
                'hint_enabled' => (bool) \Configuration::get(ConfigurationService::PRODUCTIZATION_ENABLED),
                'attribute_mapping_url' => $this->getAttributeMappingUrl($selectedCategoryMappingId),
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Offer create form error', [
                'error' => $e->getMessage(),
                'product_id' => $productId ?? null,
                'trace' => $e->getTraceAsString(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);

            $this->addFlash('error', $this->trans('Failed to load offer form: %error%', 'Modules.Inpostvonhalsky.Admin', ['%error%' => $e->getMessage()]));
            return $this->redirectToRoute('inpost_offers_list');
        }
    }

    /**
     * Store new offer
     *
     * @param Offer $offer Offer entity from form
     * @return RedirectResponse Redirect to list or edit
     */
    private function storeOffer(Offer $offer): RedirectResponse
    {
        try {
            $shopId = $this->config->getDefaultShopId();
            $offer->setShopId($shopId);

            // Ensure organization is set from config (not from client data)
            $organizationId = $this->config->getOrganizationId($shopId);
            if (!$organizationId) {
                throw new \RuntimeException('Organization ID is not configured. Please configure the module first.');
            }
            
            // Fetch and set the Organization entity (required for Doctrine relationship)
            $organization = $this->organizationRepository->findByOrganizationId($organizationId);
            if (!$organization) {
                throw new \RuntimeException('Organization not found. Please sync organizations first.');
            }
            $offer->setOrganization($organization);
            $offer->setOrganizationId($organizationId);

            // Checked again on save: another offer may have been created meanwhile
            $conflict = $this->combinationService->findConflict((int) $offer->getPrestashopOfferId(), $offer->getPrestashopProductAttributeId(), $shopId);
            if ($conflict !== null) {
                $this->addFlash('error', $this->conflictMessage($conflict));
                return $this->redirectToRoute('inpost_offers_edit', ['id' => $conflict['offer_id']]);
            }

            $offer->setStatus(Offer::STATUS_DRAFT);
            $offer->setUpdatedAt(new \DateTime());

            // Map form data to related entities
            $this->mapFormDataToEntities($offer);

            $this->entityManager->persist($offer);
            $this->entityManager->flush();

            $this->addFlash('success', $this->trans('Offer created successfully.', 'Modules.Inpostvonhalsky.Admin'));
            $this->logger->info('Offer created', ['offer_id' => $offer->getId()]);

            return $this->redirectToRoute('inpost_offers_edit', ['id' => $offer->getId()]);
        } catch (\Exception $e) {
            $this->logger->error('Offer store error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            $this->addFlash('error', $this->trans('Failed to create offer: %error%', 'Modules.Inpostvonhalsky.Admin', ['%error%' => $e->getMessage()]));
            return $this->redirectToRoute('inpost_offers_list');
        }
    }

    /**
     * Display edit offer form
     *
     * @param int $id Offer ID
     * @param Request $request HTTP request
     * @return Response Rendered form
     */
    public function editAction(int $id, Request $request): Response
    {
        try {
            $offer = $this->offerRepository->find($id);

            if (!$offer) {
                throw new \RuntimeException('Offer not found');
            }

            // Initialize related entities (in case GPSR doesn't exist for old offers)
            $this->initializeRelatedEntities($offer);

            $shopId = $this->config->getDefaultShopId();
            $organizationId = $this->config->getOrganizationId($shopId);
            $organizationName = $this->getOrganizationName($organizationId);

            // Category before any change, and the one the merchant moves the offer to
            // (?inpost_category_id=<entity_id>&category_change=1 from the "Change category" dialog)
            $originalCategoryMappingId = $this->categoryChange->getCurrentCategoryMappingId($offer);
            $categoryChangeBlock = $this->categoryChange->getBlockReason($offer);
            $changeTarget = null;
            if ($request->query->getBoolean('category_change')) {
                try {
                    if ($categoryChangeBlock !== null) {
                        throw new \InvalidArgumentException($categoryChangeBlock);
                    }
                    $changeTarget = $this->categoryChange->resolveTarget((int) $request->query->get('inpost_category_id', 0));
                } catch (\InvalidArgumentException $e) {
                    $this->addFlash('error', $this->trans($e->getMessage(), 'Modules.Inpostvonhalsky.Admin'));

                    return $this->redirectToRoute('inpost_offers_edit', ['id' => $offer->getId()]);
                }
                if ((int) $changeTarget->getEntityId() === $originalCategoryMappingId) {
                    $this->addFlash('info', $this->trans('The offer is already in this category.', 'Modules.Inpostvonhalsky.Admin'));

                    return $this->redirectToRoute('inpost_offers_edit', ['id' => $offer->getId()]);
                }
            }

            // Synced offers: category resolved from the stored UUID (locked).
            // Others: mapped categories of the product feed the dropdown.
            $isSyncedOffer = !empty($offer->getInpostOfferId());
            $mappedCategories = [];
            if ($isSyncedOffer) {
                $inpostCategoryName = $this->resolveSyncedCategoryName($offer);
            } else {
                $productId = $offer->getProduct() !== null ? $offer->getProduct()->getProductId() : null;
                if ($productId) {
                    $mappedCategories = $this->categoryMappingService->getMappedInPostCategoriesForProduct($productId, $shopId);
                }
                $inpostCategoryName = $this->resolveMappedCategoryName($offer, $mappedCategories);
            }

            if ($changeTarget !== null && $offer->getProduct() !== null) {
                $offer->getProduct()->setCategoryMappingId((int) $changeTarget->getEntityId());
            }
            // A saved offer changes its category only through the "Change category" dialog
            $selectedCategoryMappingId = $this->resolveCategoryMappingId($request, $offer, false);
            $categoryAttributes = $this->loadCategoryAttributes($selectedCategoryMappingId);
            $attributeValues = $this->getAttributeValueMap($offer);
            $valueSources = $this->getAttributeValueSources($offer);
            $psAttributes = $this->resolvePsAttributes((int) $offer->getPrestashopOfferId(), $selectedCategoryMappingId, $categoryAttributes, $offer->getPrestashopProductAttributeId());

            // Moving to another category: attributes the new category also has keep their
            // values (by UUID), empty ones are prefilled from PrestaShop, then from the
            // InPost catalogue hint. Saved only with the form.
            if ($changeTarget !== null) {
                foreach (ProductAttributeValueResolver::applicable($psAttributes) as $uuid => $entry) {
                    if (empty($attributeValues[$uuid])) {
                        $attributeValues[$uuid] = $entry['values'];
                        $valueSources[$uuid] = ['values' => $entry['values'], 'source' => OfferAttributes::SOURCE_PS];
                    }
                }
                foreach ($this->categoryChange->getHintValues($offer, (int) $selectedCategoryMappingId) as $uuid => $values) {
                    if (empty($attributeValues[$uuid])) {
                        $attributeValues[$uuid] = $values;
                        $valueSources[$uuid] = ['values' => $values, 'source' => OfferAttributes::SOURCE_HINT];
                    }
                }
            }

            // "Read again from PrestaShop": refill the form (not saved until the
            // merchant saves) - values typed in by hand are left alone
            $refreshedAttributes = 0;
            if ($request->query->getBoolean('refresh_attributes') && !$request->isMethod('POST')) {
                foreach (ProductAttributeValueResolver::applicable($psAttributes) as $uuid => $entry) {
                    if (($valueSources[$uuid]['source'] ?? null) === OfferAttributes::SOURCE_MANUAL) {
                        continue;
                    }
                    if (!self::sameValues($attributeValues[$uuid] ?? [], $entry['values'])) {
                        $attributeValues[$uuid] = $entry['values'];
                        $refreshedAttributes++;
                    }
                }
            }

            $form = $this->createForm(OfferType::class, $offer, [
                'organization_name' => $organizationName,
                'organization_id' => $organizationId ?? '',
                'inpost_category_choices' => $this->buildCategoryChoices($mappedCategories),
                'inpost_category_attributes' => $categoryAttributes,
                'inpost_attribute_values' => $attributeValues,
                'inpost_attribute_meta' => $this->buildAttributeMeta($psAttributes, $valueSources),
            ]);
            $form->handleRequest($request);
            $this->validateManualPrice($form);

            // The posted hidden category field never decides the category
            if ($offer->getProduct() !== null) {
                $offer->getProduct()->setCategoryMappingId($selectedCategoryMappingId);
            }

            if ($form->isSubmitted() && $form->isValid()) {
                if ($changeTarget !== null) {
                    return $this->saveCategoryChange($request, $form, $offer, $changeTarget, $originalCategoryMappingId, $categoryAttributes, $valueSources, $psAttributes);
                }
                $this->persistOfferAttributes($form, $offer, $categoryAttributes, $valueSources, $psAttributes);
                return $this->updateOffer($offer);
            }

            // Get validation results
            $validation = $this->offerValidator->validate($offer);

            // The stored inpost_validation_status can be a stale snapshot — e.g. a
            // transient category-API failure during bulk creation leaves it INVALID
            // even though the offer is fine. Until the offer is actually submitted,
            // reflect the live local validation so the banner/badge match the
            // "ready to send" result. In-memory only (not persisted); the durable
            // status is set by the send / status-refresh flow.
            if (empty($offer->getInpostOfferId())) {
                $offer->setInpostValidationStatus($this->offerValidator->getValidationStatus($validation));
            }

            return $this->render('@Modules/inpostvonhalsky/views/templates/admin/offer/form.html.twig', [
                'form' => $form->createView(),
                'price_settings' => $this->priceSettings(),
                'shop_price' => $this->currentShopPrice($offer),
                'offer' => $offer,
                'mode' => 'edit',
                'validation' => $validation,
                'combination_label' => $offer->isCombinationOffer() ? $this->combinationService->getLabel($offer->getPrestashopProductAttributeId()) : null,
                'is_synced_offer' => $isSyncedOffer,
                'inpost_category_name' => $inpostCategoryName,
                'category_info' => $this->buildCategoryInfo($selectedCategoryMappingId, $mappedCategories),
                'product_image_url' => $offer->getPrestashopOfferId() ? $this->getProductImageUrl($offer->getPrestashopOfferId()) : null,
                'module_js_path' => Config::getModuleJsUri(),
                'hint_enabled' => (bool) \Configuration::get(ConfigurationService::PRODUCTIZATION_ENABLED),
                'attribute_mapping_url' => $this->getAttributeMappingUrl($selectedCategoryMappingId),
                'has_mapped_attributes' => !empty($psAttributes),
                'refreshed_attributes' => $request->query->getBoolean('refresh_attributes') ? $refreshedAttributes : null,
                'category_change' => $changeTarget !== null ? $this->categoryChange->preview($offer, $changeTarget, $originalCategoryMappingId) : null,
                'category_change_block' => $categoryChangeBlock,
                'category_quick_choices' => $changeTarget === null && $categoryChangeBlock === null ? $this->buildCategoryQuickChoices($offer, $originalCategoryMappingId) : [],
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Offer edit form error', [
                'error' => $e->getMessage(),
                'offer_id' => $id
            ]);

            $this->addFlash('error', $this->trans('Failed to load offer: %error%', 'Modules.Inpostvonhalsky.Admin', ['%error%' => $e->getMessage()]));
            return $this->redirectToRoute('inpost_offers_list');
        }
    }

    /**
     * Save of the edit form in "change category" mode: the offer's attributes are
     * rebuilt for the new category; optionally the other variants of the product are
     * moved too and the product's default shop category is linked to the new
     * category (only offers created later use the link).
     *
     * @param array<string,array{values:string[],source:string}> $valueSources
     * @param array<string,array<string,mixed>> $psAttributes
     */
    private function saveCategoryChange(
        Request $request,
        FormInterface $form,
        Offer $offer,
        CategoryMapping $target,
        ?int $fromCategoryId,
        array $categoryAttributes,
        array $valueSources,
        array $psAttributes
    ): RedirectResponse {
        $domain = 'Modules.Inpostvonhalsky.Admin';
        $targetId = (int) $target->getEntityId();
        $variantsMoved = [];
        $variantsIncomplete = [];
        $variantsSkipped = 0;

        $this->entityManager->beginTransaction();
        try {
            $this->persistOfferAttributes($form, $offer, $categoryAttributes, $valueSources, $psAttributes);
            $this->categoryChange->applyCategory($offer, $target);
            $this->mapFormDataToEntities($offer);
            $offer->setUpdatedAt(new \DateTime());
            if ($offer->getIsDraft() === 0) {
                $offer->setRequireApiSync(true);
            }

            if ($request->request->getBoolean('category_change_variants')) {
                foreach ($this->categoryChange->getSiblings($offer) as $sibling) {
                    if ($this->categoryChange->getBlockReason($sibling) !== null
                        || $this->categoryChange->getCurrentCategoryMappingId($sibling) === $targetId) {
                        $variantsSkipped++;
                        continue;
                    }
                    $missing = $this->categoryChange->changeSibling($sibling, $target);
                    $variantsMoved[] = (int) $sibling->getId();
                    if (!empty($missing)) {
                        $variantsIncomplete[(int) $sibling->getId()] = $missing;
                    }
                }
            }

            $this->entityManager->flush();
            $this->entityManager->commit();
        } catch (\Throwable $e) {
            $this->entityManager->rollback();
            $this->logger->error('Offer category change failed', [
                'offer_id' => $offer->getId(),
                'target' => $targetId,
                'error' => $e->getMessage(),
            ]);
            $this->addFlash('error', $this->trans('Failed to change the category: %error%', $domain, ['%error%' => $e->getMessage()]));

            return $this->redirectToRoute('inpost_offers_edit', ['id' => $offer->getId()]);
        }

        // Legacy Db connection - outside the Doctrine transaction, after the offer is saved
        $linkedShopCategory = null;
        if ($request->request->getBoolean('category_change_link')) {
            try {
                $linkedShopCategory = $this->categoryChange->linkShopCategory($offer, $target);
            } catch (\Exception $e) {
                $this->addFlash('warning', $this->trans('The offer was moved, but the shop category could not be linked: %error%', $domain, ['%error%' => $e->getMessage()]));
            }
        }

        $this->categoryChange->logChange($offer, $fromCategoryId, $target, [
            'variants_moved' => $variantsMoved,
            'variants_incomplete' => array_keys($variantsIncomplete),
            'variants_skipped' => $variantsSkipped,
            'shop_category_linked' => $linkedShopCategory,
        ]);

        $this->addFlash('success', $this->trans('The offer was moved to the InPost category "%category%".', $domain, [
            '%category%' => implode(' › ', $this->categoryMappingService->getInpostCategoryPath($targetId)),
        ]));
        if ($offer->getIsDraft() === 0) {
            $this->addFlash('info', $this->trans('Offer marked for sync. Will be sent to InPost shortly.', $domain));
        }
        if (!empty($variantsMoved)) {
            $this->addFlash('success', $this->trans('Other variants moved too: %count%.', $domain, ['%count%' => count($variantsMoved)]));
        }
        foreach ($variantsIncomplete as $variantId => $labels) {
            $this->addFlash('warning', $this->trans('Variant offer #%id% has no value for the required attributes: %attributes%. Complete it - it will not be sent to InPost until then.', $domain, [
                '%id%' => $variantId,
                '%attributes%' => implode(', ', $labels),
            ]));
        }
        if ($variantsSkipped > 0) {
            $this->addFlash('info', $this->trans('Variants skipped (under InPost review or already in this category): %count%.', $domain, ['%count%' => $variantsSkipped]));
        }
        if ($linkedShopCategory !== null) {
            $this->addFlash('success', $this->trans('The shop category of the product is now linked to the new InPost category - offers created from now on get it.', $domain));
        }

        return $this->redirectToRoute('inpost_offers_edit', ['id' => $offer->getId()]);
    }

    /**
     * Quick picks of the "Change category" dialog: InPost categories linked to the
     * shop categories of the product, other than the offer's one
     *
     * @return array<int,array{id:int,label:string}>
     */
    private function buildCategoryQuickChoices(Offer $offer, ?int $currentCategoryMappingId): array
    {
        $productId = (int) $offer->getPrestashopOfferId();
        if ($productId <= 0) {
            return [];
        }

        $choices = [];
        foreach ($this->categoryMappingService->getMappedInPostCategoriesForProduct($productId, $this->config->getDefaultShopId()) as $category) {
            $mappingId = (int) $category['mapping_id'];
            if ($mappingId === $currentCategoryMappingId || isset($choices[$mappingId]) || empty($category['is_active'])) {
                continue;
            }
            $choices[$mappingId] = ['id' => $mappingId, 'label' => $this->formatCategoryLabel($category)];
        }

        return array_values($choices);
    }

    /**
     * Update existing offer
     *
     * @param Offer $offer Offer entity from form
     * @return RedirectResponse Redirect back to edit form
     */
    private function updateOffer(Offer $offer): RedirectResponse
    {
        try {
            $offer->setUpdatedAt(new \DateTime());

            // Map form data to related entities
            $this->mapFormDataToEntities($offer);

                        $this->entityManager->flush();

            $this->addFlash('success', $this->trans('Offer updated successfully.', 'Modules.Inpostvonhalsky.Admin'));
            $this->logger->info('Offer updated', ['offer_id' => $offer->getId()]);

            // Mark for cron sync if not draft
            if ($offer->getIsDraft() === 0) {
                $offer->setRequireApiSync(true);
                $this->entityManager->flush();
                $this->addFlash('info', $this->trans('Offer marked for sync. Will be sent to InPost shortly.', 'Modules.Inpostvonhalsky.Admin'));
                $this->logger->info('Offer marked for API sync', ['offer_id' => $offer->getId()]);
            }

            return $this->redirectToRoute('inpost_offers_edit', ['id' => $offer->getId()]);
        } catch (\Exception $e) {
            $this->logger->error('Offer update error', [
                'error' => $e->getMessage(),
                'offer_id' => $offer->getId()
            ]);

            $this->addFlash('error', $this->trans('Failed to update offer: %error%', 'Modules.Inpostvonhalsky.Admin', ['%error%' => $e->getMessage()]));
            return $this->redirectToRoute('inpost_offers_edit', ['id' => $offer->getId()]);
        }
    }

    /**
     * Delete offer
     *
     * Only CLOSED offers can be deleted, so an offer never disappears from the
     * module while it is still actively selling on InPost. One that exists in
     * InPost (the API has no deletion) stays there untouched after deletion - it
     * is already closed, but is no longer updated - so the request must confirm
     * it (confirm_inpost=1).
     *
     * @param int $id Offer ID
     * @return RedirectResponse Redirect to list
     */
    public function deleteAction(int $id, Request $request): RedirectResponse
    {
        try {
            if (!$this->isCsrfTokenValid('inpost_offers_delete', (string) $request->request->get('_token'))) {
                $this->addFlash('error', $this->trans('Invalid security token. Please try again.', 'Modules.Inpostvonhalsky.Admin'));
                return $this->redirectToRoute('inpost_offers_list');
            }

            $offer = $this->offerRepository->find($id);

            if (!$offer) {
                throw new \RuntimeException('Offer not found');
            }

            if ($offer->getStatus() !== Offer::STATUS_CLOSED) {
                $this->addFlash('error', $this->trans('Only closed offers can be deleted. Close the offer first.', 'Modules.Inpostvonhalsky.Admin'));
                return $this->redirectToRoute('inpost_offers_list');
            }

            $onInpost = $offer->existsOnInpost();
            if ($onInpost && !$request->request->getBoolean('confirm_inpost')) {
                $this->addFlash('error', $this->trans('This offer exists in InPost - confirm that it should be deleted from the module only.', 'Modules.Inpostvonhalsky.Admin'));
                return $this->redirectToRoute('inpost_offers_list');
            }

            $status = $offer->getStatus();
            $inpostOfferId = $offer->getInpostOfferId();
            $this->entityManager->remove($offer);
            $this->entityManager->flush();

            if ($onInpost) {
                $this->logOnInpostDeletion([$id => [$inpostOfferId, $status]]);
                $this->addFlash('warning', $this->trans(
                    'Offer deleted from the module. In InPost it still exists (status: %status%) and is no longer updated.',
                    'Modules.Inpostvonhalsky.Admin',
                    ['%status%' => $status]
                ));
            } else {
                $this->addFlash('success', $this->trans('Offer deleted successfully.', 'Modules.Inpostvonhalsky.Admin'));
            }
            $this->logger->info('Offer deleted', ['offer_id' => $id, 'on_inpost' => $onInpost]);

            return $this->redirectToRoute('inpost_offers_list');
        } catch (\Exception $e) {
            $this->logger->error('Offer delete error', [
                'error' => $e->getMessage(),
                'offer_id' => $id
            ]);

            $this->addFlash('error', $this->trans('Failed to delete offer: %error%', 'Modules.Inpostvonhalsky.Admin', ['%error%' => $e->getMessage()]));
            return $this->redirectToRoute('inpost_offers_list');
        }
    }

    /**
     * Back to the offers list with the filters, sorting and page it was left on
     * (sent by the bulk forms as return_query)
     */
    private function redirectToList(Request $request): RedirectResponse
    {
        parse_str((string) $request->request->get('return_query', ''), $query);

        return $this->redirectToRoute('inpost_offers_list', array_intersect_key($query, array_flip(self::LIST_QUERY_KEYS)));
    }

    /**
     * Bulk-delete offers.
     *
     * Only CLOSED offers are deleted - any other status is skipped. Offers that
     * exist in InPost are deleted from the module only, and only when the request
     * confirms it (confirm_inpost=1); otherwise they are skipped too. Each removal
     * is flushed independently so one failure never blocks the rest.
     *
     * @param Request $request HTTP request (expects offer_ids[] + CSRF _token)
     * @return RedirectResponse
     */
    public function bulkDeleteAction(Request $request): RedirectResponse
    {
        try {
            if (!$this->isCsrfTokenValid('inpost_offers_bulk_delete', (string) $request->request->get('_token'))) {
                $this->addFlash('error', $this->trans('Invalid security token. Please try again.', 'Modules.Inpostvonhalsky.Admin'));
                return $this->redirectToList($request);
            }

            $ids = RequestIds::positiveUnique($request->request->get('offer_ids', []));

            if (empty($ids)) {
                $this->addFlash('warning', $this->trans('No offers selected.', 'Modules.Inpostvonhalsky.Admin'));
                return $this->redirectToList($request);
            }

            $confirmInpost = $request->request->getBoolean('confirm_inpost');
            $deleted = 0;
            $skipped = 0;
            $deletedOnInpost = [];
            foreach ($ids as $id) {
                $offer = $this->offerRepository->find($id);
                if ($offer === null || $offer->getStatus() !== Offer::STATUS_CLOSED || ($offer->existsOnInpost() && !$confirmInpost)) {
                    $skipped++;
                    continue;
                }

                try {
                    $onInpost = $offer->existsOnInpost();
                    $inpostData = [$offer->getInpostOfferId(), $offer->getStatus()];
                    $this->entityManager->remove($offer);
                    $this->entityManager->flush();
                    $deleted++;
                    if ($onInpost) {
                        $deletedOnInpost[$id] = $inpostData;
                    }
                } catch (\Throwable $e) {
                    $this->logger->warning('Bulk delete: failed to remove offer', [
                        'offer_id' => $id,
                        'error' => $e->getMessage(),
                    ]);
                    $skipped++;
                }
            }

            $this->logOnInpostDeletion($deletedOnInpost);
            $this->logger->info('Bulk offer delete', ['deleted' => $deleted, 'skipped' => $skipped, 'on_inpost' => count($deletedOnInpost)]);
            $this->addFlash('success', $this->trans('Deleted %count% offer(s).', 'Modules.Inpostvonhalsky.Admin', ['%count%' => $deleted]));
            if (!empty($deletedOnInpost)) {
                $this->addFlash('warning', $this->trans(
                    '%count% of them exist in InPost and were deleted from the module only - they are not closed in InPost and are no longer updated.',
                    'Modules.Inpostvonhalsky.Admin',
                    ['%count%' => count($deletedOnInpost)]
                ));
            }
            if ($skipped > 0) {
                $this->addFlash('warning', $this->trans('%count% offer(s) were skipped (not deletable).', 'Modules.Inpostvonhalsky.Admin', ['%count%' => $skipped]));
            }

            return $this->redirectToList($request);
        } catch (\Exception $e) {
            $this->logger->error('Bulk offer delete error', ['error' => $e->getMessage()]);
            $this->addFlash('error', $this->trans('Failed to delete offers: %error%', 'Modules.Inpostvonhalsky.Admin', ['%error%' => $e->getMessage()]));
            return $this->redirectToList($request);
        }
    }

    /**
     * Bulk-close offers.
     *
     * Only PUBLISHED or SOLDOUT offers can be closed - any other status is skipped. Each
     * offer is closed independently via OfferService::closeOffer(), which also
     * enforces the status requirement and refreshes the status from the API.
     *
     * @param Request $request HTTP request (expects offer_ids[] + CSRF _token)
     * @return RedirectResponse
     */
    public function bulkCloseAction(Request $request): RedirectResponse
    {
        try {
            if (!$this->isCsrfTokenValid('inpost_offers_bulk_close', (string) $request->request->get('_token'))) {
                $this->addFlash('error', $this->trans('Invalid security token. Please try again.', 'Modules.Inpostvonhalsky.Admin'));
                return $this->redirectToList($request);
            }

            $ids = RequestIds::positiveUnique($request->request->get('offer_ids', []));

            if (empty($ids)) {
                $this->addFlash('warning', $this->trans('No offers selected.', 'Modules.Inpostvonhalsky.Admin'));
                return $this->redirectToList($request);
            }

            $closed = 0;
            $skipped = 0;
            foreach ($ids as $id) {
                $result = $this->offerService->closeOffer($id);
                if (!empty($result['success'])) {
                    $closed++;
                } else {
                    $skipped++;
                }
            }

            $this->logger->info('Bulk offer close', ['closed' => $closed, 'skipped' => $skipped]);
            $this->addFlash('success', $this->trans('Closed %count% offer(s).', 'Modules.Inpostvonhalsky.Admin', ['%count%' => $closed]));
            if ($skipped > 0) {
                $this->addFlash('warning', $this->trans('%count% offer(s) were skipped (only published or sold-out offers can be closed).', 'Modules.Inpostvonhalsky.Admin', ['%count%' => $skipped]));
            }

            return $this->redirectToList($request);
        } catch (\Exception $e) {
            $this->logger->error('Bulk offer close error', ['error' => $e->getMessage()]);
            $this->addFlash('error', $this->trans('Failed to close offers: %error%', 'Modules.Inpostvonhalsky.Admin', ['%error%' => $e->getMessage()]));
            return $this->redirectToList($request);
        }
    }

    /**
     * Bulk-reopen offers.
     *
     * Only CLOSED offers can be reopened - any other status is skipped. Each
     * offer is reopened independently via OfferService::reopenOffer(), which
     * also enforces the status requirement and refreshes the status from the API.
     *
     * @param Request $request HTTP request (expects offer_ids[] + CSRF _token)
     * @return RedirectResponse
     */
    public function bulkReopenAction(Request $request): RedirectResponse
    {
        try {
            if (!$this->isCsrfTokenValid('inpost_offers_bulk_reopen', (string) $request->request->get('_token'))) {
                $this->addFlash('error', $this->trans('Invalid security token. Please try again.', 'Modules.Inpostvonhalsky.Admin'));
                return $this->redirectToList($request);
            }

            $ids = RequestIds::positiveUnique($request->request->get('offer_ids', []));

            if (empty($ids)) {
                $this->addFlash('warning', $this->trans('No offers selected.', 'Modules.Inpostvonhalsky.Admin'));
                return $this->redirectToList($request);
            }

            $reopened = 0;
            $skipped = 0;
            foreach ($ids as $id) {
                $result = $this->offerService->reopenOffer($id);
                if (!empty($result['success'])) {
                    $reopened++;
                } else {
                    $skipped++;
                }
            }

            $this->logger->info('Bulk offer reopen', ['reopened' => $reopened, 'skipped' => $skipped]);
            $this->addFlash('success', $this->trans('Reopened %count% offer(s).', 'Modules.Inpostvonhalsky.Admin', ['%count%' => $reopened]));
            if ($skipped > 0) {
                $this->addFlash('warning', $this->trans('%count% offer(s) were skipped (only closed offers can be reopened).', 'Modules.Inpostvonhalsky.Admin', ['%count%' => $skipped]));
            }

            return $this->redirectToList($request);
        } catch (\Exception $e) {
            $this->logger->error('Bulk offer reopen error', ['error' => $e->getMessage()]);
            $this->addFlash('error', $this->trans('Failed to reopen offers: %error%', 'Modules.Inpostvonhalsky.Admin', ['%error%' => $e->getMessage()]));
            return $this->redirectToList($request);
        }
    }

    /**
     * Trace of offers removed from the module while still existing in InPost
     * (needed when an InPost offer later turns out to be "lost")
     *
     * @param array<int,array{0:?string,1:string}> $offers local offer id => [InPost offer id, status]
     */
    private function logOnInpostDeletion(array $offers): void
    {
        foreach ($offers as $offerId => [$inpostOfferId, $status]) {
            $this->logger->warning('Offer deleted from the module only - it still exists in InPost and is no longer updated', [
                'offer_id' => $offerId,
                'inpost_offer_id' => $inpostOfferId,
                'status' => $status,
            ]);
        }
    }

    /**
     * Send offer to InPost
     *
     * @param int $id Offer ID
     * @param Request $request HTTP request
     * @return Response JSON response or redirect
     */
    public function sendAction(int $id, Request $request): Response
    {
        try {
            $offer = $this->offerRepository->find($id);

            if (!$offer) {
                throw new \RuntimeException('Offer not found');
            }

            // Validate CSRF token
            $token = $request->request->get('_token');
            if (!$this->isCsrfTokenValid('inpost_offer_send', $token)) {
                $this->addFlash('error', $this->trans('Invalid security token. Please try again.', 'Modules.Inpostvonhalsky.Admin'));
                return $this->redirectToRoute('inpost_offers_edit', ['id' => $id]);
            }

            // Validate before sending
            $validation = $this->offerValidator->validate($offer);

            if (!$validation['can_submit']) {
                $errors = array_filter($validation['messages'], fn($msg) => $msg['severity'] === 'error');
                $errorMessages = array_map(fn($msg) => $this->trans($msg['message'], 'Modules.Inpostvonhalsky.Admin', $msg['params'] ?? []), $errors);

                $this->addFlash('error', $this->trans('Offer validation failed: %errors%', 'Modules.Inpostvonhalsky.Admin', ['%errors%' => implode(', ', $errorMessages)]));

                return $this->redirectToRoute('inpost_offers_edit', ['id' => $id]);
            }

            // Send to InPost
            $result = $this->offerService->sendOffer($id, true);

            if ($result['success']) {
                $this->addFlash('success', $this->trans('Offer sent to InPost successfully.', 'Modules.Inpostvonhalsky.Admin'));

                if (!empty($validation['messages'])) {
                    $warnings = array_filter($validation['messages'], fn($msg) => $msg['severity'] === 'warning');
                    foreach ($warnings as $warning) {
                        $this->addFlash('warning', $warning['message']);
                    }
                }
            } else {
                $this->addFlash('error', $this->trans('Failed to send offer: %error%', 'Modules.Inpostvonhalsky.Admin', ['%error%' => ($result['error'] ?? 'Unknown error')]));
            }

            return $this->redirectToRoute('inpost_offers_edit', ['id' => $id]);
        } catch (\Exception $e) {
            $this->logger->error('Offer send error', [
                'error' => $e->getMessage(),
                'offer_id' => $id,
                'trace' => $e->getTraceAsString()
            ]);

            $this->addFlash('error', $this->trans('Failed to send offer: %error%', 'Modules.Inpostvonhalsky.Admin', ['%error%' => $e->getMessage()]));
            return $this->redirectToRoute('inpost_offers_edit', ['id' => $id]);
        }
    }

    /**
     * Variants of a product with combinations, on one page: each with its check
     * (status, warnings) and a checkbox - "Create drafts for the selected" makes
     * several offers at once, "Create offer" opens the form for one variant.
     */
    private function renderCombinationChoice(int $productId, int $shopId, Request $request): Response
    {
        $langId = $this->config->getDefaultLanguageId();
        $product = new \Product($productId, false, $langId, $shopId);
        if (!\Validate::isLoadedObject($product)) {
            throw new \RuntimeException('Product not found: ' . $productId);
        }

        $combinations = $this->combinationService->getCombinations([$productId], $shopId)[$productId] ?? [];
        $offers = $this->combinationService->getOffersByCombination([$productId], $shopId)[$productId] ?? [];
        $wholeProductOffer = $offers[0] ?? null;
        $activeCombinationOffer = null;
        foreach ($offers as $pa => $offerRow) {
            if ($pa > 0 && $offerRow['active']) {
                $activeCombinationOffer = $offerRow;
                break;
            }
        }

        // Same checks as the bulk listing: product level (category, images) and per variant
        $hintMappable = $this->hintProductization->previewMappableFromHints([$productId], $shopId);
        $check = $this->preValidation->validateBatch([$productId], $shopId, $hintMappable)[0] ?? null;
        $productBlocked = $check === null || $check['status'] === ProductPreValidationService::STATUS_BLOCKED;
        $checks = [];
        foreach ($check['combinations'] ?? [] as $combinationCheck) {
            $combinationCheck['messages'] = $this->translateCheckMessages($combinationCheck['messages'], $combinationCheck['missing_attributes']);
            $checks[(int) $combinationCheck['id_product_attribute']] = $combinationCheck;
        }
        foreach ($combinations as &$combination) {
            $combinationCheck = $checks[$combination['id_product_attribute']] ?? null;
            $combination['status'] = $productBlocked ? ProductPreValidationService::STATUS_BLOCKED : ($combinationCheck['status'] ?? ProductPreValidationService::STATUS_BLOCKED);
            $combination['messages'] = $combinationCheck['messages'] ?? [];
            $combination['selectable'] = $combination['status'] !== ProductPreValidationService::STATUS_BLOCKED;
            $combination['selected'] = $combination['selectable'] && !empty($combinationCheck['selected']);
        }
        unset($combination);

        // Drafts just created on this page: offered for sending right away
        $createdIds = RequestIds::positiveUnique(explode(',', (string) $request->query->get('created', '')));
        $createdDrafts = [];
        foreach ($combinations as $combination) {
            $offerRow = $combination['offer'];
            if ($offerRow !== null && in_array($offerRow['offer_id'], $createdIds, true) && $offerRow['status'] === Offer::STATUS_DRAFT) {
                $createdDrafts[] = ['offer_id' => $offerRow['offer_id'], 'label' => $combination['label']];
            }
        }

        return $this->render('@Modules/inpostvonhalsky/views/templates/admin/offer/choose_combination.html.twig', [
            'product_id' => $productId,
            'created_drafts' => $createdDrafts,
            'product_name' => is_array($product->name) ? ($product->name[$langId] ?? '') : $product->name,
            'product_reference' => $product->reference,
            'product_image_url' => $this->getProductImageUrl($productId),
            'combinations' => $combinations,
            'product_status' => $check['status'] ?? ProductPreValidationService::STATUS_BLOCKED,
            'product_messages' => $this->translateCheckMessages($check['messages'] ?? [], []),
            'whole_product' => [
                'quantity' => array_sum(array_column($combinations, 'quantity')),
                'offer' => $wholeProductOffer,
                // Whole-product offer active -> combinations blocked, and the other way round
                'blocked_by' => $activeCombinationOffer,
            ],
            'whole_product_active' => $wholeProductOffer !== null && $wholeProductOffer['active'] ? $wholeProductOffer : null,
        ]);
    }

    /**
     * "Create drafts for the selected" of the variant page: one DRAFT offer per
     * selected combination (same service and checks as the bulk listing)
     */
    public function createCombinationsAction(int $productId, Request $request): RedirectResponse
    {
        $back = $this->redirectToRoute('inpost_offers_create', ['product_id' => $productId]);

        if (!$this->isCsrfTokenValid('inpost_offers_create_combinations', (string) $request->request->get('_token'))) {
            $this->addFlash('error', $this->trans('Invalid security token. Please try again.', 'Modules.Inpostvonhalsky.Admin'));
            return $back;
        }

        $combinationIds = RequestIds::positiveUnique($request->request->get('combinations', []));
        if (empty($combinationIds)) {
            $this->addFlash('warning', $this->trans('No variants selected.', 'Modules.Inpostvonhalsky.Admin'));
            return $back;
        }

        try {
            $shopId = $this->config->getDefaultShopId();
            // Category from an InPost hint when the product category is not mapped yet (as in bulk)
            $this->hintProductization->autoMapFromHints([$productId], $shopId);

            $results = $this->bulkCreation->createBatch(
                array_map(static fn (int $pa): array => [$productId, $pa], $combinationIds),
                $shopId
            );
        } catch (\Throwable $e) {
            $this->logger->error('Creating combination offers failed', ['id_product' => $productId, 'error' => $e->getMessage()]);
            $this->addFlash('error', $this->trans('Failed to create offer: %error%', 'Modules.Inpostvonhalsky.Admin', ['%error%' => $e->getMessage()]));
            return $back;
        }

        $createdIds = array_column(array_filter($results, static fn (array $r): bool => $r['status'] === BulkOfferCreationService::RESULT_CREATED), 'offer_id');
        $notCreated = count($results) - count($createdIds);
        if ($notCreated > 0) {
            $errors = array_unique(array_filter(array_column($results, 'message')));
            $this->addFlash('error', $this->trans('%count% variant(s) were not created: %errors%', 'Modules.Inpostvonhalsky.Admin', [
                '%count%' => $notCreated,
                '%errors%' => implode('; ', array_map(fn (string $m): string => $this->trans($m, 'Modules.Inpostvonhalsky.Admin'), $errors)),
            ]));
        }

        // Back on the page with the "created" panel (send to InPost / close)
        return empty($createdIds)
            ? $back
            : $this->redirectToRoute('inpost_offers_create', ['product_id' => $productId, 'created' => implode(',', $createdIds)]);
    }

    /**
     * "Send to InPost" of the drafts just created on the variant page. Each offer is
     * sent on its own so the reasons of a failure can be shown; an offer that fails
     * goes back to draft so it can be fixed and sent again.
     */
    public function publishCombinationsAction(int $productId, Request $request): RedirectResponse
    {
        $back = $this->redirectToRoute('inpost_offers_create', ['product_id' => $productId]);

        if (!$this->isCsrfTokenValid('inpost_offers_publish_combinations', (string) $request->request->get('_token'))) {
            $this->addFlash('error', $this->trans('Invalid security token. Please try again.', 'Modules.Inpostvonhalsky.Admin'));
            return $back;
        }

        $sent = 0;
        $failures = [];
        foreach (RequestIds::positiveUnique($request->request->get('offer_ids', [])) as $offerId) {
            $offer = $this->offerRepository->find($offerId);
            // Only drafts of this product that were never sent
            if ($offer === null || (int) $offer->getPrestashopOfferId() !== $productId || $offer->existsOnInpost()) {
                continue;
            }

            $offer->setIsDraft(0);
            $offer->setRequireApiSync(true);
            $this->entityManager->flush();

            $result = $this->offerService->sendOffer($offerId, true);
            if (!empty($result['success'])) {
                $sent++;
                continue;
            }

            $offer = $this->offerRepository->find($offerId);
            if ($offer !== null && !$offer->existsOnInpost()) {
                $offer->setIsDraft(1);
                $offer->setRequireApiSync(false);
                $this->entityManager->flush();
            }
            $failures[$offerId] = $this->sendFailureReasons($result);
        }

        if ($sent > 0) {
            $this->addFlash('success', $this->trans('%count% offer(s) sent to InPost - InPost is processing them (status PENDING).', 'Modules.Inpostvonhalsky.Admin', ['%count%' => $sent]));
        }
        foreach ($failures as $offerId => $reasons) {
            $this->addFlash('error', $this->trans('Offer #%id% was not sent and stays a draft: %reasons%', 'Modules.Inpostvonhalsky.Admin', [
                '%id%' => $offerId,
                '%reasons%' => $reasons,
            ]));
        }

        return $back;
    }

    /**
     * Readable reasons of a failed OfferService::sendOffer() (validation messages when present)
     */
    private function sendFailureReasons(array $result): string
    {
        $messages = [];
        foreach ($result['details'] ?? [] as $detail) {
            if (is_array($detail) && isset($detail['message'])) {
                $messages[] = $this->trans($detail['message'], 'Modules.Inpostvonhalsky.Admin', $detail['params'] ?? []);
            }
        }

        return $messages !== [] ? implode('; ', $messages) : (string) ($result['error'] ?? '');
    }

    /**
     * @param string[] $messages Pre-validation messages (English source strings)
     * @param string[] $missingAttributes Names of required attributes without a value
     * @return string[]
     */
    private function translateCheckMessages(array $messages, array $missingAttributes): array
    {
        $translated = array_map(fn (string $m): string => $this->trans($m, 'Modules.Inpostvonhalsky.Admin'), $messages);
        if (!empty($missingAttributes)) {
            $translated[] = $this->trans(
                'Required InPost attributes without a value from PrestaShop: %names%. Fill them in the draft or map them to features in Category mapping.',
                'Modules.Inpostvonhalsky.Admin',
                ['%names%' => implode(', ', $missingAttributes)]
            );
        }

        return $translated;
    }

    /**
     * Why an offer for the product / combination cannot be created
     *
     * @param array{offer_id:int, reason:string} $conflict ProductCombinationService::findConflict()
     */
    private function conflictMessage(array $conflict): string
    {
        $params = ['%id%' => $conflict['offer_id']];
        switch ($conflict['reason']) {
            case ProductCombinationService::CONFLICT_WHOLE_PRODUCT:
                return $this->trans('The product has offer #%id% covering all its combinations - close it to list the combinations separately.', 'Modules.Inpostvonhalsky.Admin', $params);
            case ProductCombinationService::CONFLICT_COMBINATIONS:
                return $this->trans('Combinations of this product already have offers (e.g. #%id%) - an offer for the whole product would sell their stock twice.', 'Modules.Inpostvonhalsky.Admin', $params);
            default:
                return $this->trans('An offer for this product already exists (#%id%).', 'Modules.Inpostvonhalsky.Admin', $params);
        }
    }

    /**
     * Manual price mode needs a price above 0 (GOKINT-1098)
     */
    private function validateManualPrice(FormInterface $form): void
    {
        if (!$form->isSubmitted() || !$form->has('price_mode')
            || $form->get('price_mode')->getData() !== OfferPrice::MODE_MANUAL
            || !$form->get('manual_price')->isValid()) {
            return;
        }

        $manualPrice = $form->get('manual_price')->getData();
        if ($manualPrice === null || $manualPrice === '' || (float) $manualPrice <= 0) {
            $form->get('manual_price')->addError(new FormError(
                $this->trans('Enter the manual price - an amount greater than 0.', 'Modules.Inpostvonhalsky.Admin')
            ));
        }
    }

    /**
     * Shop price of what the offer sells, read now (the stored one is only
     * refreshed by the sync); null when the product is gone
     */
    private function currentShopPrice(Offer $offer): ?float
    {
        $productId = (int) $offer->getPrestashopOfferId();
        if ($productId <= 0 || !\Product::existsInDatabase($productId, 'product')) {
            return null;
        }

        try {
            return $this->priceResolver->resolve($productId, $offer->getPrestashopProductAttributeId())['shop_price'];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Price settings shown next to the automatic price of the offer form
     *
     * @return array{group: string, percent: float, rounding: string}
     */
    private function priceSettings(): array
    {
        $groupId = $this->priceResolver->groupId();
        $groupName = '';
        if ($groupId > 0) {
            $group = new \Group($groupId, $this->config->getDefaultLanguageId());
            $groupName = is_string($group->name) ? $group->name : '';
        }

        return [
            'group' => $groupName,
            'percent' => $this->priceResolver->percent(),
            'rounding' => $this->priceResolver->rounding(),
        ];
    }

    /**
     * Initialize related entities for new offer
     *
     * Creates empty related entity objects so form fields can map via property_path
     *
     * @param Offer $offer Offer entity
     * @return void
     */
    private function initializeRelatedEntities(Offer $offer): void
    {
        // Initialize OfferProduct if not exists
        if ($offer->getProduct() === null) {
            $product = new \Inpost\VonHalsky\Entity\OfferProduct();
            $product->setOffer($offer);
            $offer->setProduct($product);
        }

        // Initialize OfferManufacturer if not exists
        if ($offer->getManufacturer() === null) {
            $manufacturer = new \Inpost\VonHalsky\Entity\OfferManufacturer();
            $manufacturer->setOffer($offer);
            $offer->setManufacturer($manufacturer);
        }

        // Initialize OfferStock if not exists
        if ($offer->getStock() === null) {
            $stock = new \Inpost\VonHalsky\Entity\OfferStock();
            $stock->setOffer($offer);
            $offer->setStock($stock);
        }

        // Initialize OfferPrice if not exists
        if ($offer->getPrice() === null) {
            $price = new \Inpost\VonHalsky\Entity\OfferPrice();
            $price->setOffer($offer);
            $offer->setPrice($price);
        }

        // Initialize OfferGpsr if not exists
        if ($offer->getGpsr() === null) {
            $gpsr = new \Inpost\VonHalsky\Entity\OfferGpsr();
            $gpsr->setOffer($offer);
            $gpsr->setDoesNotRequireInfo(true); // Default to exempt for new offers
            $offer->setGpsr($gpsr);
        }

        // Note: OfferManual collection and OfferAttributes are handled by CollectionType
        // and don't need initialization here
    }

    /**
     * Get product cover image URL
     *
     * @param int $productId PrestaShop product ID
     * @return string|null Image URL or null if no image
     */
    private function getProductImageUrl(int $productId): ?string
    {
        $langId = $this->config->getDefaultLanguageId();
        $product = new \Product($productId, false, $langId);

        if (!$product->id) {
            return null;
        }

        $images = \Image::getImages($langId, $productId);
        if (empty($images)) {
            return null;
        }

        $imageId = $images[0]['id_image'];
        $linkRewrite = is_array($product->link_rewrite)
            ? $product->link_rewrite[$langId]
            : $product->link_rewrite;

        return \Context::getContext()->link->getImageLink(
            $linkRewrite,
            $imageId,
            'home_default'
        );
    }

    /**
     * Map form data to related entities (OfferProduct, OfferManufacturer, OfferStock, OfferManual)
     *
     * Note: With property_path configured in OfferType.php, Symfony automatically maps
     * form fields to nested entity properties. This method serves as a hook for any
     * additional custom mapping logic if needed.
     *
     * Required property_path mappings in OfferType.php:
     * - dimension_width → product.dimensionWidth
     * - dimension_height → product.dimensionHeight
     * - dimension_length → product.dimensionLength
     * - dimension_weight → product.dimensionWeight
     * - super_model → product.superModel
     * - manufacturer_product_number → product.manufacturerProductNumber
     * - manufacturer_address_street → manufacturer.addressStreet
     * - manufacturer_address_building_number → manufacturer.addressBuildingNumber
     * - manufacturer_address_apartment_number → manufacturer.addressApartmentNumber
     * - manufacturer_address_city → manufacturer.addressCity
     * - manufacturer_address_postal_code → manufacturer.addressPostalCode
     * - manufacturer_address_country_code → manufacturer.addressCountryCode
     * - manufacturer_website → manufacturer.website
     * - manufacturer_email → manufacturer.email
     * - manufacturer_phone → manufacturer.phone
     *
     * @param Offer $offer Offer entity with form data
     * @return void
     */
    private function mapFormDataToEntities(Offer $offer): void
    {
        // Ensure related entities are initialized (shouldn't be null after form processing)
        $this->initializeRelatedEntities($offer);

        // InPost price after a change of the mode / manual price (GOKINT-1098)
        if ($offer->getPrestashopOfferId() && \Product::existsInDatabase((int) $offer->getPrestashopOfferId(), 'product')) {
            $this->priceResolver->refresh($offer->getPrice(), (int) $offer->getPrestashopOfferId(), $offer->getPrestashopProductAttributeId());
        }

        $product = $offer->getProduct();
        if ($product !== null) {
            $mappingId = $product->getCategoryMappingId();

            if ($mappingId) {
                $categoryUuid = $this->categoryMappingService->getInpostCategoryUuidById((int) $mappingId);

                if ($categoryUuid) {
                    // Keep offer and product entities in sync with the mapping selection
                    $product->setCategoryId($categoryUuid);
                    $product->setInpostCategoryId($categoryUuid);
                    $offer->setInpostCategoryId($categoryUuid);
                } else {
                    $product->setCategoryId(null);
                    $product->setInpostCategoryId(null);
                    $offer->setInpostCategoryId(null);
                }
            } else {
                $product->setCategoryId(null);
                $product->setInpostCategoryId(null);
                $offer->setInpostCategoryId(null);
            }
        }
    }

    /**
     * Name of the configured organization (empty when unknown)
     */
    private function getOrganizationName(?string $organizationId): string
    {
        if (!$organizationId) {
            return '';
        }
        $organization = $this->organizationRepository->findByOrganizationId($organizationId);

        return $organization ? (string) $organization->getName() : '';
    }

    /**
     * Category name of an offer that exists on InPost, from its stored category UUID.
     * Also fills the product's category mapping id in memory (not persisted) so the
     * category attributes can be loaded.
     */
    private function resolveSyncedCategoryName(Offer $offer): ?string
    {
        $product = $offer->getProduct();
        $inpostCategoryUuid = ($product !== null ? $product->getInpostCategoryId() : null) ?? $offer->getInpostCategoryId();
        if (!$inpostCategoryUuid) {
            return null;
        }

        $categoryMapping = $this->categoryMappingRepository->findByInpostCategoryId($inpostCategoryUuid);
        if (!$categoryMapping) {
            return "Unknown category (UUID: {$inpostCategoryUuid})";
        }

        if ($product && !$product->getCategoryMappingId()) {
            $product->setCategoryMappingId($categoryMapping->getEntityId());
        }

        return $categoryMapping->getName();
    }

    /**
     * Display label of the offer's (locked) category among the product's mapped categories
     */
    private function resolveMappedCategoryName(Offer $offer, array $mappedCategories): ?string
    {
        $categoryMappingId = $offer->getProduct() !== null ? $offer->getProduct()->getCategoryMappingId() : null;
        if (!$categoryMappingId) {
            return null;
        }

        foreach ($mappedCategories as $category) {
            if ((int) $category['mapping_id'] === $categoryMappingId) {
                return $this->formatCategoryLabel($category);
            }
        }

        return null;
    }

    /**
     * "InPost: <InPost name> · Shop: <PrestaShop name> (default)" - says which side each name is
     */
    private function formatCategoryLabel(array $category): string
    {
        $domain = 'Modules.Inpostvonhalsky.Admin';

        return sprintf(
            '%s: %s  ·  %s: %s%s',
            'InPost',
            $category['inpost_category_name'],
            $this->trans('Shop', $domain),
            $category['ps_category_name'],
            !empty($category['is_default']) ? ' (' . $this->trans('default', $domain) . ')' : ''
        );
    }

    /**
     * The offer's category for display: the shop categories of the product linked
     * to it and the full InPost path
     *
     * @return array{inpost_name:string,inpost_path:string[],ps_categories:array<int,array{name:string,is_default:bool}>}|null
     */
    private function buildCategoryInfo(?int $categoryMappingId, array $mappedCategories): ?array
    {
        if (!$categoryMappingId) {
            return null;
        }

        $path = $this->categoryMappingService->getInpostCategoryPath($categoryMappingId);
        if (!$path) {
            return null;
        }

        $psCategories = [];
        foreach ($mappedCategories as $category) {
            if ((int) $category['mapping_id'] === $categoryMappingId) {
                $psCategories[] = ['name' => (string) $category['ps_category_name'], 'is_default' => !empty($category['is_default'])];
            }
        }

        return ['inpost_name' => (string) end($path), 'inpost_path' => $path, 'ps_categories' => $psCategories];
    }

    /**
     * Build category choices array for form dropdown
     *
     * @param array $mappedCategories Mapped categories from CategoryMappingService
     *
     * @return array Choices array in format ['Label' => 'value']
     */
    private function buildCategoryChoices(array $mappedCategories): array
    {
        $choices = [];
        $seenMappingIds = [];

        foreach ($mappedCategories as $category) {
            $mappingId = (string) $category['mapping_id'];

            if (isset($seenMappingIds[$mappingId])) {
                // Avoid duplicate option entries for the same mapping ID
                continue;
            }

            $choices[$this->formatCategoryLabel($category)] = $mappingId;
            $seenMappingIds[$mappingId] = true;
        }

        return $choices;
    }

    /**
     * Category of the form: ?inpost_category_id= when $fromQuery (create form),
     * otherwise the one of the offer product
     */
    private function resolveCategoryMappingId(Request $request, Offer $offer, bool $fromQuery = true): ?int
    {
        $mappingId = $fromQuery ? (int) $request->query->get('inpost_category_id', 0) : 0;
        if ($mappingId > 0) {
            $product = $offer->getProduct();
            if ($product !== null) {
                $product->setCategoryMappingId($mappingId);
            }

            return $mappingId;
        }

        $product = $offer->getProduct();
        if ($product !== null && $product->getCategoryMappingId()) {
            return (int) $product->getCategoryMappingId();
        }

        return null;
    }

    /**
     * GOKINT-927: prefill an in-memory create-offer form from a chosen product hint.
     *
     * Fills empty product fields and seeds attribute default values (keyed by the
     * category attribute UUID, matching OfferType's `inpost_attribute_values`).
     * Persists nothing — the offer is saved only when the user submits the form.
     *
     * @param array<string,string[]> $attributeValues Attribute value map (by UUID), mutated in place
     * @param array<string,array{values:string[],source:string}> $valueSources Value origins (by UUID), mutated in place
     */
    private function applyHintPrefill(
        Request $request,
        Offer $offer,
        ?int $selectedCategoryMappingId,
        array &$attributeValues,
        array &$valueSources
    ): void {
        $hintProductId = trim((string) $request->query->get('hint_product_id', ''));
        $hintQuery = trim((string) $request->query->get('hint_query', ''));

        if ($hintProductId === '' || $hintQuery === '') {
            return;
        }
        if (!(bool) \Configuration::get(ConfigurationService::PRODUCTIZATION_ENABLED)) {
            return;
        }
        if ($offer->getProduct() === null) {
            return;
        }

        $language = $this->config->getDefaultLanguageCode();

        $hint = $this->productHintService->findByProductId($hintQuery, $hintProductId, $language);
        if ($hint === null) {
            return;
        }

        // Fill empty product fields (do not overwrite values from the PS product).
        $this->hintApplyService->applyProductFields($offer->getProduct(), $hint, false);

        // Seed attribute defaults from the hint, without overriding existing values.
        if ($selectedCategoryMappingId) {
            $mapped = $this->hintAttributeMapper->map(
                $selectedCategoryMappingId,
                $hint['attributes'] ?? [],
                $language
            );
            foreach ($mapped as $entry) {
                $code = (string) $entry['inpost_attribute_id'];
                if (empty($attributeValues[$code])) {
                    $attributeValues[$code] = [(string) $entry['value']];
                    $valueSources[$code] = ['values' => [(string) $entry['value']], 'source' => OfferAttributes::SOURCE_HINT];
                }
            }
        }
    }

    private function loadCategoryAttributes(?int $categoryMappingId): array
    {
        if (!$categoryMappingId) {
            return [];
        }

        try {
            return $this->attributeMappingService->getAttributesForCategoryMappingId($categoryMappingId);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to load category attributes for mapping', [
                'category_mapping_id' => $categoryMappingId,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Process and persist inpost_attributes form data
     *
     * Each value keeps track of where it came from: equal to what the mapped PS
     * feature / attribute group gives -> 'ps' (follows later product changes),
     * unchanged prefill -> its original source, anything else -> 'manual'.
     *
     * @param FormInterface $form Submitted form
     * @param Offer $offer Target offer entity
     * @param array $categoryAttributes Attribute definitions from loadCategoryAttributes()
     * @param array<string,array{values:string[],source:string}> $valueSources Origins of the values shown in the form
     * @param array<string,array<string,mixed>> $psAttributes ProductAttributeValueResolver::resolve() result
     */
    private function persistOfferAttributes(
        FormInterface $form,
        Offer $offer,
        array $categoryAttributes,
        array $valueSources = [],
        array $psAttributes = []
    ): void {
        // Skip if no inpost_attributes field
        if (!$form->has('inpost_attributes')) {
            return;
        }

        // Build ID → code (InPost UUID) lookup map
        $idToCodeMap = [];
        foreach ($categoryAttributes as $attr) {
            $idToCodeMap[$attr['id']] = $attr['code'];
        }

        // Build ID → Attribute entity reference map
        $idToAttributeMap = [];
        foreach ($categoryAttributes as $attr) {
            $idToAttributeMap[$attr['id']] = $this->entityManager->getReference(Attribute::class, $attr['id']);
        }

        // Get category mapping ID from product
        $categoryMappingId = null;
        if ($offer->getProduct() !== null) {
            $categoryMappingId = $offer->getProduct()->getCategoryMappingId();
        }

        // Clear existing attributes for this offer
        foreach ($offer->getAttributes()->toArray() as $existingAttr) {
            $offer->removeAttribute($existingAttr);
            $this->entityManager->remove($existingAttr);
        }
        $this->entityManager->flush(); // Commit deletes before inserts

        $psValues = [];
        foreach (ProductAttributeValueResolver::applicable($psAttributes) as $uuid => $entry) {
            $psValues[$uuid] = $entry['values'];
        }

        // Process submitted attribute values
        $attributesForm = $form->get('inpost_attributes');
        foreach ($attributesForm->all() as $fieldName => $field) {
            $values = array_values(array_filter(
                array_map('strval', (array) $field->getData()),
                static function (string $v): bool {
                    return trim($v) !== '';
                }
            ));

            // Skip empty values
            if (empty($values)) {
                continue;
            }

            // Extract attribute ID from field name (attr_53 → 53)
            if (!preg_match('/^attr_(\d+)$/', $fieldName, $matches)) {
                continue;
            }
            $attributeId = (int) $matches[1];

            // Get InPost attribute UUID and Attribute entity
            $inpostAttributeId = $idToCodeMap[$attributeId] ?? null;
            $attributeEntity = $idToAttributeMap[$attributeId] ?? null;
            if (!$inpostAttributeId || !$attributeEntity) {
                continue;
            }

            // Several stored values shown comma-separated in a single field and left as they were
            $prior = $valueSources[$inpostAttributeId]['values'] ?? [];
            if (count($prior) > 1 && count($values) === 1 && $values[0] === implode(', ', $prior)) {
                $values = $prior;
            }

            if (isset($psValues[$inpostAttributeId]) && self::sameValues($values, $psValues[$inpostAttributeId])) {
                $source = OfferAttributes::SOURCE_PS;
            } elseif (isset($valueSources[$inpostAttributeId]) && self::sameValues($values, $valueSources[$inpostAttributeId]['values'])) {
                $source = $valueSources[$inpostAttributeId]['source'];
            } else {
                $source = OfferAttributes::SOURCE_MANUAL;
            }

            // Create new OfferAttributes entity
            $offerAttr = new OfferAttributes();
            $offerAttr->setOffer($offer);
            $offerAttr->setAttribute($attributeEntity);
            // Store as JSON array (e.g., ["val1"] or ["val1","val2"])
            $offerAttr->setValue((string) json_encode($values));
            $offerAttr->setValueSource($source);
            $offerAttr->setInpostAttributeId($inpostAttributeId);
            if ($categoryMappingId) {
                $offerAttr->setCategoryMappingId((string) $categoryMappingId);
            }

            $offer->addAttribute($offerAttr);
        }
    }

    /**
     * Values of the mapped PS features / attribute groups for the product
     *
     * @return array<string,array<string,mixed>> ProductAttributeValueResolver::resolve() result
     */
    private function resolvePsAttributes(int $productId, ?int $categoryMappingId, array $categoryAttributes, int $productAttributeId = 0): array
    {
        if ($productId <= 0 || !$categoryMappingId || empty($categoryAttributes)) {
            return [];
        }

        try {
            return $this->attributeValueResolver->resolve(
                $productId,
                $productAttributeId,
                $categoryMappingId,
                $this->config->getDefaultLanguageCode(),
                $categoryAttributes
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Could not read attribute values from PrestaShop', [
                'id_product' => $productId,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Per-attribute info for the form help texts: current value origin and what
     * PrestaShop gives for it
     *
     * @return array<string,array{source:?string, ps:?array}>
     */
    private function buildAttributeMeta(array $psAttributes, array $valueSources): array
    {
        $meta = [];
        foreach (array_unique(array_merge(array_keys($psAttributes), array_keys($valueSources))) as $uuid) {
            $meta[$uuid] = [
                'source' => $valueSources[$uuid]['source'] ?? null,
                'ps' => $psAttributes[$uuid] ?? null,
            ];
        }

        return $meta;
    }

    /**
     * Category mapping page with the InPost category of the offer opened
     */
    private function getAttributeMappingUrl(?int $categoryMappingId): ?string
    {
        if (!$categoryMappingId) {
            return null;
        }

        $categoryUuid = $this->categoryMappingService->getInpostCategoryUuidById($categoryMappingId);

        return $categoryUuid ? $this->generateUrl('inpost_category_mapping_list', ['category' => $categoryUuid]) : null;
    }

    /**
     * Same values regardless of order
     *
     * @param string[] $a
     * @param string[] $b
     */
    private static function sameValues(array $a, array $b): bool
    {
        $a = array_map('strval', $a);
        $b = array_map('strval', $b);
        sort($a);
        sort($b);

        return $a === $b;
    }

    /**
     * Stored attribute values and their origin, by InPost attribute UUID
     *
     * @return array<string,array{values:string[],source:string}>
     */
    private function getAttributeValueSources(Offer $offer): array
    {
        $sources = [];
        foreach ($this->getAttributeValueMap($offer) as $code => $values) {
            $sources[$code] = ['values' => $values, 'source' => OfferAttributes::SOURCE_MANUAL];
        }
        foreach ($offer->getAttributes() as $attribute) {
            $code = $attribute->getInpostAttributeId();
            if ($code && isset($sources[$code])) {
                $sources[$code]['source'] = $attribute->getValueSource();
            }
        }

        return $sources;
    }

    /**
     * Stored attribute values by InPost attribute UUID
     *
     * @return array<string,string[]>
     */
    private function getAttributeValueMap(Offer $offer): array
    {
        $values = [];

        foreach ($offer->getAttributes() as $attribute) {
            $code = $attribute->getInpostAttributeId();
            if (!$code) {
                continue;
            }
            $jsonValue = $attribute->getValue();
            $decoded = json_decode($jsonValue, true);
            // Fallback for legacy non-JSON values
            $values[$code] = is_array($decoded)
                ? array_values(array_map('strval', $decoded))
                : ($jsonValue !== '' ? [$jsonValue] : []);
        }

        return $values;
    }

}
