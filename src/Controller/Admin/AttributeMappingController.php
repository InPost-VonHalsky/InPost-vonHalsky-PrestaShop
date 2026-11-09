<?php

declare(strict_types=1);

namespace Inpost\VonHalsky\Controller\Admin;

use Inpost\VonHalsky\Helper\Config;
use Inpost\VonHalsky\Service\Mapping\AttributeMappingService;
use Inpost\VonHalsky\Service\Mapping\AttributeSourceMappingService;
use Inpost\VonHalsky\Service\Mapping\CategoryMappingService;
use Inpost\VonHalsky\Service\Mapping\Suggest\AttributeAssistantService;
use Inpost\VonHalsky\Service\Mapping\Suggest\CategorySuggester;
use Inpost\VonHalsky\Service\Util\LoggerService;
use PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Attribute Mapping Controller
 *
 * Handles InPost attribute management:
 * - Attribute mapping assistant (sources of attributes of mapped categories)
 * - Attribute source dialog of the category details panel
 *
 * Attributes are synced by the category sync cron job, or per category with
 * "Refresh" in the category details panel - never all categories in one request.
 *
 * @package Inpost\VonHalsky\Controller\Admin
 */
class AttributeMappingController extends FrameworkBundleAdminController
{
    private AttributeMappingService $mappingService;
    private LoggerService $logger;
    private Config $config;
    private CategoryMappingService $categoryMappingService;
    private AttributeSourceMappingService $sourceMappingService;
    private AttributeAssistantService $attributeAssistant;

    public function __construct(
        AttributeMappingService $mappingService,
        LoggerService $logger,
        Config $config,
        CategoryMappingService $categoryMappingService,
        AttributeSourceMappingService $sourceMappingService,
        AttributeAssistantService $attributeAssistant
    ) {
        $this->mappingService = $mappingService;
        $this->logger = $logger;
        $this->config = $config;
        $this->categoryMappingService = $categoryMappingService;
        $this->sourceMappingService = $sourceMappingService;
        $this->attributeAssistant = $attributeAssistant;
    }

    /**
     * Attribute mapping assistant (attributes of mapped categories, grouped by name;
     * data is loaded over AJAX)
     *
     * @return Response Rendered view
     */
    public function viewAction(Request $request): Response
    {
        try {
            return $this->render('@Modules/inpostvonhalsky/views/templates/admin/attribute_mapping/view.html.twig', [
                'category_filter' => (int) $request->query->get('category', 0),
                'assistant_thresholds' => ['sure' => CategorySuggester::SCORE_SURE, 'check' => CategorySuggester::SCORE_CHECK],
            ]);
        } catch (\Exception $e) {
            $this->logger->logException($e, [
                'controller' => 'AttributeMappingController',
                'action' => 'viewAction'
            ]);
            $this->logger->error('Attribute mapping view error', ['exception' => get_class($e)]);

            $this->addFlash('error', $this->trans('Failed to load attribute mappings: %error%', 'Modules.Inpostvonhalsky.Admin', ['%error%' => $e->getMessage()]));
            return $this->redirectToDefaultPage();
        }
    }

    /**
     * Former edit page: mapping is edited in a dialog of the category details
     * panel now - open that panel with the dialog of this attribute
     *
     * @param int $id Category attribute entity ID
     */
    public function editAction(int $id): RedirectResponse
    {
        $categoryAttribute = $this->mappingService->getCategoryAttributeById($id);
        if (!$categoryAttribute) {
            $this->addFlash('error', $this->trans(
                'Category attribute #%id% not found. It may have been deleted. Please refresh the category details page to see current data.',
                'Modules.Inpostvonhalsky.Admin',
                ['%id%' => $id]
            ));

            return $this->redirectToRoute('inpost_category_mapping_list');
        }

        return $this->redirectToRoute('inpost_category_mapping_list', [
            'category' => $categoryAttribute['inpost_category_id'],
            'attribute' => $id,
        ]);
    }

    /**
     * Dialog data: InPost attribute, PrestaShop sources and the values of the
     * selected (?type=&source_id=) or saved source with their InPost match
     *
     * @param int $id Category attribute entity ID
     */
    public function sourceAction(int $id, Request $request): JsonResponse
    {
        try {
            $sourceId = (int) $request->query->get('source_id', 0);
            $data = $this->sourceMappingService->getDialogData(
                $id,
                $request->query->get('type') ?: null,
                $sourceId > 0 ? $sourceId : null
            );

            if ($data === null) {
                return $this->json([
                    'success' => false,
                    'message' => $this->trans('Category attribute #%id% not found. It may have been deleted. Please refresh the category details page to see current data.', 'Modules.Inpostvonhalsky.Admin', ['%id%' => $id]),
                ], 404);
            }

            return $this->json(['success' => true, 'data' => $data]);
        } catch (\Throwable $e) {
            $this->logger->logException($e, ['controller' => 'AttributeMappingController', 'action' => 'sourceAction', 'id' => $id]);

            return $this->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Save the dialog: source (type + source_id, empty = no source), values[ps_value_id] = option code,
     * apply_same_named = 1 to give the source to same-named unmapped attributes of other categories
     *
     * @param int $id Category attribute entity ID
     */
    public function saveSourceAction(int $id, Request $request): JsonResponse
    {
        try {
            $type = (string) $request->request->get('type', '');
            $sourceId = (int) $request->request->get('source_id', 0);
            $values = $request->request->get('values', []);

            $result = $this->sourceMappingService->save(
                $id,
                $type !== '' ? $type : null,
                $sourceId > 0 ? $sourceId : null,
                is_array($values) ? $values : [],
                (bool) $request->request->get('apply_same_named', false)
            );

            if (!$result['saved']) {
                return $this->json([
                    'success' => false,
                    'message' => $this->trans('Failed to update attribute mapping.', 'Modules.Inpostvonhalsky.Admin'),
                ], 400);
            }

            $message = $this->trans('Attribute mapping updated successfully.', 'Modules.Inpostvonhalsky.Admin');
            if ($result['applied_to_others'] > 0) {
                $message .= ' ' . $this->trans(
                    'The same source was also set in %count% other categories.',
                    'Modules.Inpostvonhalsky.Admin',
                    ['%count%' => $result['applied_to_others']]
                );
            }

            return $this->json(['success' => true, 'message' => $message, 'data' => $result]);
        } catch (\Throwable $e) {
            $this->logger->logException($e, ['controller' => 'AttributeMappingController', 'action' => 'saveSourceAction', 'id' => $id]);

            return $this->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Assistant data: ?category=<InPost category entity id>&required=1
     */
    public function assistantDataAction(Request $request): JsonResponse
    {
        try {
            $categoryId = (int) $request->query->get('category', 0);

            return new JsonResponse(['success' => true] + $this->attributeAssistant->getOverview(
                $categoryId > 0 ? $categoryId : null,
                (bool) $request->query->get('required', false)
            ));
        } catch (\Exception $e) {
            $this->logger->logException($e, ['controller' => 'AttributeMappingController', 'action' => 'assistantDataAction']);

            return new JsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Value suggestions of an attribute group for a source: ?key=&type=&source_id=
     */
    public function assistantValuesAction(Request $request): JsonResponse
    {
        $sections = $this->attributeAssistant->getValues(
            (string) $request->query->get('key', ''),
            (string) $request->query->get('type', ''),
            (int) $request->query->get('source_id', 0)
        );

        return $sections === null
            ? new JsonResponse(['success' => false, 'error' => 'Attribute not found'], 404)
            : new JsonResponse(['success' => true, 'sections' => $sections]);
    }

    /**
     * Set (or clear with an empty type) the source of every attribute in a group
     * Body: key, type, source_id
     */
    public function assistantApplyAction(Request $request): JsonResponse
    {
        $type = (string) $request->request->get('type', '');
        $sourceId = (int) $request->request->get('source_id', 0);
        $updated = $this->attributeAssistant->applySource(
            (string) $request->request->get('key', ''),
            $type !== '' ? $type : null,
            $sourceId > 0 ? $sourceId : null
        );

        return new JsonResponse(['success' => $updated > 0, 'updated' => $updated]);
    }

    /**
     * Set sources of several attribute groups at once
     * Body: sources[<group key>] = "<type>:<source id>"
     */
    public function assistantApplyBulkAction(Request $request): JsonResponse
    {
        $sources = [];
        foreach ((array) ($request->request->all()['sources'] ?? []) as $key => $value) {
            [$type, $id] = array_pad(explode(':', (string) $value, 2), 2, '0');
            if ($type !== '' && (int) $id > 0) {
                $sources[(string) $key] = [$type, (int) $id];
            }
        }

        return new JsonResponse(['success' => true, 'updated' => $this->attributeAssistant->applySources($sources)]);
    }

    /**
     * Save chosen value mappings of one InPost attribute
     * Body: attribute_id, type, values[<ps_value_id>] = <option code>
     */
    public function assistantSaveValuesAction(Request $request): JsonResponse
    {
        $values = $request->request->all()['values'] ?? [];
        $saved = $this->attributeAssistant->saveValues(
            (int) $request->request->get('attribute_id', 0),
            (string) $request->request->get('type', ''),
            is_array($values) ? $values : []
        );

        return new JsonResponse(['success' => true, 'saved' => $saved]);
    }
}
