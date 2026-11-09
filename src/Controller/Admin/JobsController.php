<?php
/**
 * Background jobs panel
 *
 * Live progress of the scheduled jobs (order import, offer sync, ...): what is
 * running, how far it got, how long it takes, and a cooperative stop for the
 * order import. The page polls a light JSON endpoint.
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Controller\Admin;

use Inpost\VonHalsky\Service\Status\JobMonitor;
use PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class JobsController extends FrameworkBundleAdminController
{
    private JobMonitor $jobMonitor;

    public function __construct(JobMonitor $jobMonitor)
    {
        $this->jobMonitor = $jobMonitor;
    }

    public function indexAction(): Response
    {
        return $this->render('@Modules/inpostvonhalsky/views/templates/admin/jobs/index.html.twig', [
            'layoutTitle' => $this->trans('Background jobs', 'Modules.Inpostvonhalsky.Admin'),
            'layoutHeaderToolbarBtn' => [
                'status' => [
                    'href' => $this->generateUrl('inpost_status'),
                    'desc' => $this->trans('Integration status', 'Modules.Inpostvonhalsky.Admin'),
                    'icon' => 'dashboard',
                ],
            ],
            'enableSidebar' => true,
        ]);
    }

    public function dataAction(): JsonResponse
    {
        $response = new JsonResponse($this->jobMonitor->snapshot());
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    public function stopAction(Request $request): JsonResponse
    {
        $job = (string) $request->request->get('job', '');
        if (!$this->jobMonitor->isKnownJob($job) || !$this->jobMonitor->isStoppable($job)) {
            return new JsonResponse(['success' => false], 400);
        }

        $this->jobMonitor->requestStop($job);

        return new JsonResponse(['success' => true]);
    }
}
