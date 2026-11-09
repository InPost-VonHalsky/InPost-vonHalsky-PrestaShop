<?php

declare(strict_types=1);

namespace Inpost\VonHalsky\Controller\Admin;

use Configuration;
use Inpost\VonHalsky\Service\Util\ConfigurationService;
use PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Inpost\VonHalsky\Helper\Config;
use Inpost\VonHalsky\Helper\RequestIds;

/**
 * Bulk offer wizard controller (GOKINT-930).
 *
 * selectAction: receives the product-grid bulk selection, stashes it, redirects.
 * wizardAction: renders the wizard; the ids move into the DOM and the session key
 *   is consumed immediately to prevent double-submit.
 */
class BulkOfferController extends FrameworkBundleAdminController
{
    private const SESSION_KEY = 'inpostvonhalsky_bulk_product_ids';
    private const MAX_IDS = 1000;

    public function selectAction(Request $request): RedirectResponse
    {
        if (!Configuration::get(ConfigurationService::BULK_LIST_ENABLED)) {
            $this->addFlash('error', $this->trans('Bulk listing is disabled.', 'Modules.Inpostvonhalsky.Admin'));
            return $this->redirectToRoute('admin_product_catalog');
        }

        $ids = $request->request->get('product_bulk');
        if (!is_array($ids)) {
            $ids = $request->request->get('id_product', []);
        }
        $ids = RequestIds::positiveUnique($ids);

        if (empty($ids)) {
            $this->addFlash('warning', $this->trans('No products selected.', 'Modules.Inpostvonhalsky.Admin'));
            return $this->redirectToRoute('admin_product_catalog');
        }

        if (count($ids) > self::MAX_IDS) {
            $ids = array_slice($ids, 0, self::MAX_IDS);
            $this->addFlash('warning', $this->trans('Selection truncated to %count% products.', 'Modules.Inpostvonhalsky.Admin', ['%count%' => self::MAX_IDS]));
        }

        $request->getSession()->set(self::SESSION_KEY, $ids);

        return $this->redirectToRoute('inpost_bulk_offers_wizard');
    }

    public function wizardAction(Request $request): Response
    {
        $session = $request->getSession();
        $ids = $session->get(self::SESSION_KEY, []);
        // Consume immediately: from here the ids live in the DOM only.
        $session->remove(self::SESSION_KEY);

        return $this->render('@Modules/inpostvonhalsky/views/templates/admin/offer/bulk_wizard.html.twig', [
            'product_ids' => $ids,
            'chunk_size' => (int) (Configuration::get(ConfigurationService::BULK_CHUNK_SIZE) ?: 25),
            'module_js_path' => Config::getModuleJsUri(),
            'validate_url' => $this->generateUrl('inpost_ajax_bulk_offers_validate'),
            'create_url' => $this->generateUrl('inpost_ajax_bulk_offers_create'),
            'publish_url' => $this->generateUrl('inpost_ajax_bulk_offers_publish'),
            'offer_edit_url' => $this->generateUrl('inpost_offers_edit', ['id' => 999999]),
        ]);
    }
}
