<?php

declare(strict_types=1);

namespace Inpost\VonHalsky\Controller\Admin;

use Inpost\VonHalsky\Entity\CategoryLink;
use Inpost\VonHalsky\Helper\Config;
use Inpost\VonHalsky\Repository\CategoryLinkRepository;
use Inpost\VonHalsky\Repository\CategoryMappingRepository;
use Inpost\VonHalsky\Service\Mapping\AttributeMappingService;
use Inpost\VonHalsky\Service\Mapping\AttributeSourceMappingService;
use Inpost\VonHalsky\Service\Mapping\CategoryMappingService;
use Inpost\VonHalsky\Service\Mapping\Suggest\CategoryAssistantService;
use Inpost\VonHalsky\Service\Mapping\Suggest\CategorySuggester;
use Inpost\VonHalsky\Service\Util\LoggerService;
use PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Category Mapping Controller
 *
 * Handles PrestaShop <-> InPost category mapping UI:
 * - List existing category mappings
 * - Create new mappings (dual-tree selector interface)
 * - Delete mappings
 * - Validate leaf category rule
 *
 * @package Inpost\VonHalsky\Controller\Admin
 */
class CategoryMappingController extends FrameworkBundleAdminController
{
    private CategoryLinkRepository $linkRepository;
    private CategoryMappingRepository $mappingRepository;
    private CategoryMappingService $mappingService;
    private LoggerService $logger;
    private AttributeMappingService $attributeMappingService;
    private Config $config;
    private AttributeSourceMappingService $attributeSourceMappingService;
    private CategoryAssistantService $categoryAssistant;

    public function __construct(
        CategoryLinkRepository $linkRepository,
        CategoryMappingRepository $mappingRepository,
        CategoryMappingService $mappingService,
        LoggerService $logger,
        AttributeMappingService $attributeMappingService,
        Config $config,
        AttributeSourceMappingService $attributeSourceMappingService,
        CategoryAssistantService $categoryAssistant
    ) {
        $this->linkRepository = $linkRepository;
        $this->mappingRepository = $mappingRepository;
        $this->mappingService = $mappingService;
        $this->logger = $logger;
        $this->attributeMappingService = $attributeMappingService;
        $this->config = $config;
        $this->attributeSourceMappingService = $attributeSourceMappingService;
        $this->categoryAssistant = $categoryAssistant;
    }

    /**
     * Mapping assistant data: PS category tree with current mappings and suggestions
     */
    public function assistantDataAction(): JsonResponse
    {
        try {
            return new JsonResponse(['success' => true] + $this->categoryAssistant->getOverview());
        } catch (\Exception $e) {
            $this->logger->logException($e, ['controller' => 'CategoryMappingController', 'action' => 'assistantDataAction']);

            return new JsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Fuzzy search of InPost leaf categories for the assistant's "Search..." picker
     */
    public function assistantSearchAction(Request $request): JsonResponse
    {
        $phrase = trim((string) $request->query->get('q', ''));

        return new JsonResponse([
            'success' => true,
            'results' => mb_strlen($phrase) >= 2 ? $this->categoryAssistant->search($phrase) : [],
        ]);
    }

    /**
     * Map several PS categories at once ("Accept all sure suggestions")
     * Body: mappings[<id_category>] = <InPost category UUID>
     */
    public function assistantBulkAction(Request $request): JsonResponse
    {
        $shopId = $this->config->getDefaultShopId();
        $mappings = $request->request->all()['mappings'] ?? [];
        $done = [];
        $errors = [];

        foreach (is_array($mappings) ? $mappings : [] as $psCategoryId => $inpostCategoryId) {
            try {
                if ($this->mappingService->mapCategory((int) $psCategoryId, (string) $inpostCategoryId, $shopId)) {
                    $done[] = (int) $psCategoryId;
                } else {
                    $errors[(int) $psCategoryId] = 'Failed to create mapping.';
                }
            } catch (\Exception $e) {
                $errors[(int) $psCategoryId] = $e->getMessage();
            }
        }

        return new JsonResponse(['success' => !$errors, 'mapped' => $done, 'errors' => $errors]);
    }

    /**
     * Remove the mapping of one PS category (assistant "Undo" / "Remove")
     */
    public function assistantUnmapAction(Request $request): JsonResponse
    {
        $psCategoryId = (int) $request->request->get('prestashop_category_id');
        if ($psCategoryId <= 0) {
            return new JsonResponse(['success' => false, 'error' => 'Missing category'], 422);
        }

        return new JsonResponse([
            'success' => $this->mappingService->removeMapping($psCategoryId, $this->config->getDefaultShopId()),
        ]);
    }

    /**
     * List existing category mappings
     *
     * @return Response Rendered list
     */
    public function listAction(): Response
    {
        try {
            $shopId = $this->config->getDefaultShopId();

            // Get all category links for current shop using the service
            // The service returns enriched data with category names via SQL joins
            $links = $this->mappingService->getAllMappings($shopId);

            // GOKINT-929: links whose InPost category is orphaned (deactivated)
            $orphanLinks = $this->mappingService->getLinksPointingAtInactiveCategories($shopId);

            return $this->render('@Modules/inpostvonhalsky/views/templates/admin/category_mapping/list.html.twig', [
                'assistant_thresholds' => ['sure' => CategorySuggester::SCORE_SURE, 'check' => CategorySuggester::SCORE_CHECK],
                'links' => $links,
                'orphan_links' => $orphanLinks,
                'shop_id' => $shopId
            ]);
        } catch (\Exception $e) {
            $this->logger->logException($e, [
                'controller' => 'CategoryMappingController',
                'action' => 'listAction'
            ]);
            $this->logger->error('Category mapping list error', ['exception' => get_class($e)]);

            $this->addFlash('error', $this->trans('Failed to load category mappings: %error%', 'Modules.Inpostvonhalsky.Admin', ['%error%' => $e->getMessage()]));
            return $this->redirectToDefaultPage();
        }
    }

    /**
     * Store new category mapping
     *
     * @param Request $request HTTP request
     * @return Response Redirect to list, or JSON for AJAX calls (category details panel)
     */
    public function storeAction(Request $request): Response
    {
        try {
            $shopId = $this->config->getDefaultShopId();
            $psCategId = (int) $request->request->get('prestashop_category_id');
            $inpostCategId = $request->request->get('inpost_category_id');

            if (!$psCategId || !$inpostCategId) {
                throw new \RuntimeException('Both PrestaShop and InPost categories must be selected');
            }

            // Use mapping service to create link (validates leaf category, returns bool)
            $result = $this->mappingService->mapCategory($psCategId, $inpostCategId, $shopId);

            if ($request->isXmlHttpRequest()) {
                return $result
                    ? new JsonResponse(['success' => true])
                    : new JsonResponse(['success' => false, 'error' => 'Failed to create mapping.'], 500);
            }

            if ($result) {
                $this->addFlash('success', $this->trans('Category mapping created successfully.', 'Modules.Inpostvonhalsky.Admin'));
                $this->logger->info('Category mapping created', [
                    'ps_category_id' => $psCategId,
                    'inpost_category_id' => $inpostCategId,
                    'shop_id' => $shopId
                ]);
            } else {
                $this->addFlash('error', $this->trans('Failed to create mapping.', 'Modules.Inpostvonhalsky.Admin'));
            }

            return $this->redirectToRoute('inpost_category_mapping_list');
        } catch (\Exception $e) {
            $this->logger->logException($e, [
                'controller' => 'CategoryMappingController',
                'action' => 'storeAction'
            ]);
            $this->logger->error('Category mapping store error', ['exception' => get_class($e)]);

            if ($request->isXmlHttpRequest()) {
                return new JsonResponse(['success' => false, 'error' => $e->getMessage()], 422);
            }

            $this->addFlash('error', $this->trans('Failed to create category mapping: %error%', 'Modules.Inpostvonhalsky.Admin', ['%error%' => $e->getMessage()]));
            return $this->redirectToRoute('inpost_category_mapping_list');
        }
    }

    /**
     * Get tree data for JSTree (with lazy loading support)
     *
     * @param Request $request HTTP request
     * @return JsonResponse Tree data in JSTree format
     */
    public function treeAction(Request $request): JsonResponse
    {
        try {
            $language = $this->getLanguageCode();

            // Server-side search across the whole tree (not just loaded nodes).
            $search = trim((string) $request->query->get('search', ''));
            if ($search !== '') {
                return new JsonResponse([
                    'search' => true,
                    'results' => $this->mappingRepository->searchCategories($search, $language),
                ]);
            }

            $parentId = $request->query->get('id', '#');

            // Handle both '#' and empty string as root indicators
            if ($parentId === '#' || $parentId === '' || empty($parentId)) {
                // Load root nodes only
                $treeData = $this->mappingRepository->getRootNodes($language);
            } else {
                // Load children of specific parent (lazy loading)
                $mappingId = (int) str_replace('mapping_', '', $parentId);
                $treeData = $this->mappingRepository->getChildNodes($mappingId, $language);
            }

            return new JsonResponse($treeData);
        } catch (\Exception $e) {
            $this->logger->logException($e, [
                'controller' => 'CategoryMappingController',
                'action' => 'treeAction'
            ]);

            return new JsonResponse(['error' => 'Failed to load tree'], 500);
        }
    }

    /**
     * Get category details for edit form
     *
     * @param Request $request HTTP request
     * @return Response Rendered details panel
     */
    public function detailsAction(Request $request): Response
    {
        try {
            $mappingId = (int) $request->query->get('mapping_id');
            $shopId = $this->config->getDefaultShopId();

            // Fetch category mapping
            $mapping = $this->mappingRepository->find($mappingId);

            if (!$mapping) {
                throw new \RuntimeException('Category not found');
            }

            // Get linked PrestaShop categories
            $linkedCategories = $this->mappingService->getLinkedPrestashopCategories(
                $mapping->getInpostCategoryId(),
                $shopId
            );

            // Get available PrestaShop categories for dropdown
            $availableCategories = $this->getAvailablePrestashopCategories();

            // Get category-specific attributes for the grid
            $language = $this->config->getDefaultLanguageCode();
            $attributes = $this->attributeMappingService
                ->getAttributesForCategory($mapping->getInpostCategoryId(), $language);

            // "12/15 matched" of the mapped dictionary attributes
            $coverage = $this->attributeSourceMappingService->getValueCoverage($attributes);
            $requiredWithoutSource = 0;
            foreach ($attributes as &$attribute) {
                $attribute['value_coverage'] = $coverage[$attribute['category_attribute_id']] ?? null;
                // Dictionary payload is not needed by the grid - keep the embedded JSON small
                unset($attribute['dictionary_values']);
                if ($attribute['required'] && $attribute['ps_source_type'] === null) {
                    $requiredWithoutSource++;
                }
            }
            unset($attribute);

            return $this->render('@Modules/inpostvonhalsky/views/templates/admin/category_mapping/details.html.twig', [
                'mapping' => $mapping,
                'linked_categories' => $linkedCategories,
                'available_categories' => $availableCategories,
                'shop_id' => $shopId,
                'attributes' => $attributes,
                'required_without_source' => $requiredWithoutSource,
            ]);
        } catch (\Exception $e) {
            $this->logger->logException($e, [
                'controller' => 'CategoryMappingController',
                'action' => 'detailsAction'
            ]);

            return new Response(
                '<div class="alert alert-danger">Error: ' . htmlspecialchars($e->getMessage()) . '</div>'
            );
        }
    }

    /**
     * Re-fetch attributes of one InPost leaf category from the API (details panel "Refresh")
     *
     * @param int $id Category mapping entity ID
     * @return JsonResponse {success, message, stats}
     */
    public function refreshAttributesAction(int $id): JsonResponse
    {
        try {
            $mapping = $this->mappingRepository->find($id);
            if (!$mapping) {
                return new JsonResponse(['success' => false, 'message' => 'Category not found'], 404);
            }

            if (!$mapping->getIsLeaf()) {
                return new JsonResponse([
                    'success' => false,
                    'message' => $this->trans('Attributes are defined only for leaf categories.', 'Modules.Inpostvonhalsky.Admin'),
                ], 422);
            }

            $result = $this->attributeMappingService->syncCategoryAttributes(
                [['id' => $mapping->getInpostCategoryId(), 'is_leaf' => 1]],
                $this->config->getDefaultLanguageCode()
            );

            if ($result['errors'] > 0) {
                return new JsonResponse([
                    'success' => false,
                    'message' => $this->trans(
                        'Failed to refresh attributes from InPost (%errors% errors). Check the module logs for details.',
                        'Modules.Inpostvonhalsky.Admin',
                        ['%errors%' => $result['errors']]
                    ),
                    'stats' => $result,
                ], 502);
            }

            return new JsonResponse([
                'success' => true,
                'message' => $this->trans(
                    'Attributes refreshed from InPost. Attributes: %linked%, removed: %unlinked%.',
                    'Modules.Inpostvonhalsky.Admin',
                    ['%linked%' => $result['linked'], '%unlinked%' => $result['unlinked']]
                ),
                'stats' => $result,
            ]);
        } catch (\Throwable $e) {
            $this->logger->logException($e, [
                'controller' => 'CategoryMappingController',
                'action' => 'refreshAttributesAction',
                'mapping_id' => $id,
            ]);

            return new JsonResponse([
                'success' => false,
                'message' => $this->trans('Failed to refresh attributes: %error%', 'Modules.Inpostvonhalsky.Admin', ['%error%' => $e->getMessage()]),
            ], 500);
        }
    }

    /**
     * Get current language code
     *
     * @return string Language code
     */
    private function getLanguageCode(): string
    {
        return 'pl';
    }

    /**
     * Get available PrestaShop categories for mapping dropdown
     *
     * @return array Available categories
     */
    private function getAvailablePrestashopCategories(): array
    {
        $languageId = $this->config->getDefaultLanguageId();

        // Get all categories for current shop
        $categories = \Category::getCategories($languageId, true, false);

        $formatted = [];
        foreach ($categories as $category) {
            // PrestaShop returns flat array, not nested with 'infos'
            // Skip Root category (id = 1)
            if (isset($category['id_category']) && isset($category['name'])
                && (int)$category['id_category'] !== 1) {
                $formatted[] = [
                    'id' => (int) $category['id_category'],
                    'name' => $category['name']
                ];
            }
        }

        return $formatted;
    }

    /**
     * Delete category mapping
     *
     * @param int $id Category link ID
     * @return RedirectResponse Redirect to list
     */
    public function deleteAction(int $id): RedirectResponse
    {
        try {
            $link = $this->linkRepository->find($id);

            if (!$link) {
                throw new \RuntimeException('Category mapping not found');
            }

            // Use mapping service to remove mapping (returns bool, not array)
            $result = $this->mappingService->removeMapping($link->getPrestashopCategoryId(), $link->getShopId());

            if ($result) {
                $this->addFlash('success', $this->trans('Category mapping deleted successfully.', 'Modules.Inpostvonhalsky.Admin'));
                $this->logger->info('Category mapping deleted', ['link_id' => $id]);
            } else {
                $this->addFlash('error', $this->trans('Failed to delete mapping.', 'Modules.Inpostvonhalsky.Admin'));
            }

            return $this->redirectToRoute('inpost_category_mapping_list');
        } catch (\Exception $e) {
            $this->logger->logException($e, [
                'controller' => 'CategoryMappingController',
                'action' => 'deleteAction',
                'link_id' => $id
            ]);
            $this->logger->error('Category mapping delete error', ['exception' => get_class($e)]);

            $this->addFlash('error', $this->trans('Failed to delete category mapping: %error%', 'Modules.Inpostvonhalsky.Admin', ['%error%' => $e->getMessage()]));
            return $this->redirectToRoute('inpost_category_mapping_list');
        }
    }
}
