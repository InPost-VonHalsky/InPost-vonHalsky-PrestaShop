<?php
/**
 * Integration status panel
 *
 * Read-only dashboard with the health of the InPost integration (environment,
 * connection, cron, catalog, offers, orders, error journal) plus a JSON report
 * for support.
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Controller\Admin;

use Inpost\VonHalsky\Service\Status\IntegrationStatusService;
use PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class StatusController extends FrameworkBundleAdminController
{
    private IntegrationStatusService $statusService;

    public function __construct(IntegrationStatusService $statusService)
    {
        $this->statusService = $statusService;
    }

    public function indexAction(): Response
    {
        $status = $this->statusService->getStatus();

        return $this->render('@Modules/inpostvonhalsky/views/templates/admin/status/index.html.twig', [
            'status' => $status,
            'configurationUrl' => $this->getAdminLink('AdminModules', ['configure' => 'inpostvonhalsky']),
            'layoutTitle' => $this->trans('Integration status', 'Modules.Inpostvonhalsky.Admin'),
            'layoutHeaderToolbarBtn' => [
                'jobs' => [
                    'href' => $this->generateUrl('inpost_jobs'),
                    'desc' => $this->trans('Background jobs', 'Modules.Inpostvonhalsky.Admin'),
                    'icon' => 'sync',
                ],
                'refresh' => [
                    'href' => $this->generateUrl('inpost_status'),
                    'desc' => $this->trans('Refresh', 'Modules.Inpostvonhalsky.Admin'),
                    'icon' => 'refresh',
                ],
                'report' => [
                    'href' => $this->generateUrl('inpost_status_report'),
                    'desc' => $this->trans('Download report', 'Modules.Inpostvonhalsky.Admin'),
                    'icon' => 'file_download',
                ],
            ],
            'enableSidebar' => true,
        ]);
    }

    /**
     * Status snapshot as a JSON file - to attach to a support ticket.
     * Contains no credentials or tokens.
     */
    public function reportAction(): JsonResponse
    {
        $status = $this->statusService->getStatus();
        $status['shop'] = [
            'domain' => \Configuration::get('PS_SHOP_DOMAIN_SSL') ?: \Configuration::get('PS_SHOP_DOMAIN'),
            'prestashop' => _PS_VERSION_,
            'php' => PHP_VERSION,
        ];

        $response = new JsonResponse($status, 200, [], false);
        $response->setEncodingOptions(JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $response->headers->set(
            'Content-Disposition',
            'attachment; filename="inpost-status-' . date('Ymd-His') . '.json"'
        );

        return $response;
    }
}
