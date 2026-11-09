<?php

declare(strict_types=1);

namespace Inpost\VonHalsky\Controller\Admin\Ajax;

use Inpost\VonHalsky\Entity\Offer;
use Inpost\VonHalsky\Helper\Config;
use Inpost\VonHalsky\Repository\CategoryMappingRepository;
use Inpost\VonHalsky\Repository\OfferRepository;
use Inpost\VonHalsky\Service\Offer\OfferCategoryChangeService;
use Inpost\VonHalsky\Service\Util\InpostLoggerService;
use PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * "Change category" dialog of the offer edit page.
 *
 * search:  InPost categories an offer can be put in (active leaves) by name.
 * preview: what moving the offer to a category would do - attributes kept,
 *          lost and to fill, other variants, shop category link. Saves nothing;
 *          the change itself is made in the offer form.
 */
class OfferCategoryController extends FrameworkBundleAdminController
{
    private const DOMAIN = 'Modules.Inpostvonhalsky.Admin';

    private OfferCategoryChangeService $categoryChange;
    private OfferRepository $offerRepository;
    private CategoryMappingRepository $categoryMappingRepository;
    private Config $config;
    private InpostLoggerService $logger;

    public function __construct(
        OfferCategoryChangeService $categoryChange,
        OfferRepository $offerRepository,
        CategoryMappingRepository $categoryMappingRepository,
        Config $config,
        InpostLoggerService $logger
    ) {
        $this->categoryChange = $categoryChange;
        $this->offerRepository = $offerRepository;
        $this->categoryMappingRepository = $categoryMappingRepository;
        $this->config = $config;
        $this->logger = $logger;
    }

    /**
     * Active leaf categories whose name contains the phrase (?q=)
     */
    public function searchAction(Request $request): JsonResponse
    {
        $phrase = trim((string) $request->query->get('q', ''));
        if (mb_strlen($phrase) < 2) {
            return $this->json(['success' => true, 'results' => []]);
        }

        $results = [];
        foreach ($this->categoryMappingRepository->searchCategories($phrase, $this->config->getDefaultLanguageCode(), 100) as $category) {
            if (empty($category['is_leaf']) || empty($category['is_active'])) {
                continue;
            }
            $results[] = [
                'id' => (int) $category['entity_id'],
                'name' => (string) $category['name'],
                'path' => (string) $category['path'],
            ];
            if (count($results) >= 30) {
                break;
            }
        }

        return $this->json(['success' => true, 'results' => $results]);
    }

    /**
     * ?offer_id=&category_id=<inpost_category_mapping.entity_id>
     */
    public function previewAction(Request $request): JsonResponse
    {
        try {
            $offer = $this->offerRepository->find((int) $request->query->get('offer_id'));
            if (!$offer instanceof Offer) {
                return $this->json(['success' => false, 'error' => $this->trans('Offer not found', self::DOMAIN)], 404);
            }

            $blockReason = $this->categoryChange->getBlockReason($offer);
            if ($blockReason !== null) {
                return $this->json(['success' => false, 'error' => $this->trans($blockReason, self::DOMAIN)], 409);
            }

            try {
                $target = $this->categoryChange->resolveTarget((int) $request->query->get('category_id'));
            } catch (\InvalidArgumentException $e) {
                return $this->json(['success' => false, 'error' => $this->trans($e->getMessage(), self::DOMAIN)], 422);
            }

            $preview = $this->categoryChange->preview($offer, $target);
            $preview['form_url'] = $this->generateUrl('inpost_offers_edit', [
                'id' => $offer->getId(),
                'inpost_category_id' => $target->getEntityId(),
                'category_change' => 1,
            ]);

            return $this->json(['success' => true, 'preview' => $preview]);
        } catch (\Throwable $e) {
            $this->logger->error('OfferCategoryController::previewAction error', ['error' => $e->getMessage()]);

            return $this->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }
}
