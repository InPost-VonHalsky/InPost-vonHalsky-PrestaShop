<?php
/**
 * InPost Orders Admin Controller
 *
 * Main controller for managing InPost Orders grid
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Controller\Admin;

use Inpost\VonHalsky\Service\Util\ShopLog;
use PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Inpost\VonHalsky\Service\Cron\CronManager;
use Inpost\VonHalsky\Service\Cron\OrderSyncJob;
use Inpost\VonHalsky\Service\Sync\OrderSyncService;
use Inpost\VonHalsky\Core\Search\Filters\InpostOrdersFilters;

/**
 * Class InpostOrdersController
 * Manages the InPost Orders listing and actions
 */
class InpostOrdersController extends FrameworkBundleAdminController
{
    /**
     * Seconds the "Sync orders" button may spend creating orders in one request
     * (each new shop order takes several seconds: PrestaShop renders the invoice PDF)
     */
    private const MANUAL_SYNC_TIME_BUDGET = 25;

    /**
     * Display InPost Orders grid
     *
     * @param Request $request
     * @param InpostOrdersFilters $filters
     *
     * @return Response
     */
    public function indexAction(Request $request, InpostOrdersFilters $filters): Response
    {

        // Filtrowanie pola 'actions' z filtrów (pole SearchAndResetType nie powinno być w filtrach)
        $currentFilters = $filters->getFilters() ?? [];
        if (is_array($currentFilters) && isset($currentFilters['actions'])) {
            unset($currentFilters['actions']);
            // Usuń również puste wartości
            $currentFilters = array_filter($currentFilters, function($value) {
                return $value !== null && $value !== '';
            });
            $filters->set('filters', $currentFilters);
        }


        $gridFactory = $this->get('inpost.grid.factory.inpost_orders');
        $grid = $gridFactory->getGrid($filters);

        return $this->render('@Modules/inpostvonhalsky/views/templates/admin/orders/index.html.twig', [
            'grid' => $this->presentGrid($grid),
            'layoutHeaderToolbarBtn' => $this->getToolbarButtons(),
            'layoutTitle' => $this->trans('InPost Orders', 'Modules.Inpostvonhalsky.Admin', []),
            'enableSidebar' => true,
            'help_link' => null,
        ]);
    }



    /**
     * View single InPost Order - redirects to the PrestaShop order page when one exists
     *
     * @param int $inpostOrderId
     *
     * @return Response
     */
    public function viewAction(int $inpostOrderId): Response
    {
        return $this->renderInpostOrder($inpostOrderId, true);
    }

    /**
     * View InPost Order details (always shows custom InPost view)
     *
     * @param int $inpostOrderId
     *
     * @return Response
     */
    public function viewInpostAction(int $inpostOrderId): Response
    {
        return $this->renderInpostOrder($inpostOrderId, false);
    }

    private function renderInpostOrder(int $inpostOrderId, bool $redirectToPrestaShopOrder): Response
    {
        try {
            $inpostOrder = $this->getInpostOrderData($inpostOrderId);

            $psOrderId = (int) ($inpostOrder['id_order'] ?? 0);
            if ($redirectToPrestaShopOrder && $psOrderId > 0) {
                return $this->redirect($this->generateUrl('admin_orders_view', ['orderId' => $psOrderId]));
            }

            // Parse shipment_data JSON if available
            $shipmentDetails = null;
            if (!empty($inpostOrder['shipment_data'])) {
                $shipmentData = json_decode($inpostOrder['shipment_data'], true);
                if (is_array($shipmentData) && isset($shipmentData['container'])) {
                    $shipmentDetails = $shipmentData['container'];
                }
            }

            return $this->render('@Modules/inpostvonhalsky/views/templates/admin/orders/view.html.twig', [
                'inpostOrder' => $inpostOrder,
                'shipmentDetails' => $shipmentDetails,
                'layoutTitle' => $this->trans('InPost Order Details', 'Modules.Inpostvonhalsky.Admin', []),
            ]);
        } catch (\Exception $e) {
            $this->addFlash('error', $this->trans(
                'Unable to load InPost Order.',
                'Modules.Inpostvonhalsky.Admin',
                []
            ));

            return $this->redirectToRoute('admin_inpost_orders_index');
        }
    }

    /**
     * Get InPost Order data from database
     *
     * @param int $inpostOrderId
     *
     * @return array
     * @throws \PrestaShopException
     */
    private function getInpostOrderData(int $inpostOrderId): array
    {
        $db = \Db::getInstance();
        $query = new \DbQuery();
        $query->select('io.*, o.reference as order_reference, CONCAT(c.firstname, " ", c.lastname) as customer_name')
            ->from('inpost_order', 'io')
            ->leftJoin('orders', 'o', 'io.id_order = o.id_order')
            ->leftJoin('customer', 'c', 'o.id_customer = c.id_customer')
            ->where('io.id_inpost_order = ' . (int) $inpostOrderId);

        $result = $db->getRow($query);

        if (!$result) {
            throw new \PrestaShopException('InPost Order not found');
        }

        return $result;
    }

    /**
     * Get toolbar buttons
     *
     * @return array
     */
    private function getToolbarButtons(): array
    {
        return [
            'sync' => [
                'href' => $this->generateUrl('admin_inpost_orders_sync'),
                'desc' => $this->trans('Sync Orders', 'Modules.Inpostvonhalsky.Admin', []),
                'icon' => 'sync',
            ],
            'jobs' => [
                'href' => $this->generateUrl('inpost_jobs'),
                'desc' => $this->trans('Background jobs', 'Modules.Inpostvonhalsky.Admin', []),
                'icon' => 'hourglass_empty',
            ],
            'export' => [
                'href' => $this->generateUrl('admin_inpost_orders_export'),
                'desc' => 'Export',
                'icon' => 'cloud_download',
            ],
        ];
    }

    /**
     * Export InPost Orders to CSV
     *
     * @param Request $request
     * @param InpostOrdersFilters $filters
     *
     * @return Response
     */
    public function exportAction(Request $request, InpostOrdersFilters $filters): Response
    {
        $gridFactory = $this->get('inpost.grid.factory.inpost_orders');
        $grid = $gridFactory->getGrid($filters);

        // Simple CSV export
        $data = $this->exportGridToCsv($grid);

        return new Response(
            $data,
            200,
            [
                'Content-Type' => 'text/csv; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="inpost_orders_' . date('Y-m-d_His') . '.csv"',
            ]
        );
    }

    /**
     * Export grid data to CSV
     *
     * @param \PrestaShop\PrestaShop\Core\Grid\GridInterface $grid
     *
     * @return string
     */
    private function exportGridToCsv($grid): string
    {
        $data = $grid->getData();
        $records = $data->getRecords()->all();

        if (empty($records)) {
            return '';
        }

        $output = fopen('php://temp', 'r+');

        // Add BOM for UTF-8
        fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

        // Add headers
        $headers = array_keys($records[0]);
        fputcsv($output, $headers, ';');

        // Add rows
        foreach ($records as $record) {
            fputcsv($output, $record, ';');
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv;
    }

    /**
     * Manual synchronization of orders from InPost API
     *
     * @param Request $request
     *
     * @return Response
     */
    public function syncAction(Request $request): Response
    {
        try {
            /** @var OrderSyncService $orderSyncService */
            $orderSyncService = $this->get(OrderSyncService::class);

            // The sync can take minutes: release the session lock so the employee's
            // other admin requests are not blocked meanwhile (the flash message
            // below re-opens the session when the sync is done)
            if ($request->hasSession()) {
                $request->getSession()->save();
            }
            @set_time_limit(0);
            // A proxy timeout must not abort an order half-way through its creation
            ignore_user_abort(true);

            ShopLog::add(
                '[InPost Manual Sync] Starting manual order synchronization from admin panel',
                1
            );

            // Interactive run: stop after the time budget, the rest follows on the next run.
            // Under the order_sync job lock - never in parallel with the cron run, which
            // would create the same shop orders twice
            /** @var CronManager $cronManager */
            $cronManager = $this->get(CronManager::class);
            $stats = $cronManager->runExclusive(
                'order_sync',
                // "Last N days" import range applies here too; continuous mode reads all orders
                fn () => $orderSyncService->syncOrders(null, 30, true, self::MANUAL_SYNC_TIME_BUDGET, OrderSyncJob::fixedRangeStart())
            );

            if ($stats === null) {
                $this->addFlash('warning', $this->trans(
                    'The order import is already running in the background - follow its progress in InPost von Halsky > Status > Background jobs.',
                    'Modules.Inpostvonhalsky.Admin'
                ));

                return $this->redirectToRoute('inpost_jobs');
            }

            // Log results
            ShopLog::add(
                '[InPost Manual Sync] Synchronization completed. Stats: ' . json_encode($stats),
                1
            );

            // Prepare success message
            $message = $this->trans(
                'Synchronization completed successfully! Processed %total% order(s): %created% created, %updated% updated.',
                'Modules.Inpostvonhalsky.Admin',
                [
                    '%total%' => $stats['total_fetched'],
                    '%created%' => $stats['created'],
                    '%updated%' => $stats['updated'],
                ]
            );

            if (!empty($stats['incomplete'])) {
                $message .= ' ' . $this->trans(
                    'Not all orders were processed within the time limit - click "Sync orders" again or let the scheduled task finish the rest.',
                    'Modules.Inpostvonhalsky.Admin'
                );
            }

            if (!empty($stats['shop_order_failures'])) {
                $message .= ' ' . $this->trans(
                    '%count% shop order(s) could not be created: %error%',
                    'Modules.Inpostvonhalsky.Admin',
                    ['%count%' => $stats['shop_order_failures'], '%error%' => (string) $stats['last_shop_order_error']]
                );
                $stats['errors'] += (int) $stats['shop_order_failures'];
            }

            if ($stats['errors'] > 0) {
                $message .= ' ' . $this->trans(
                    '%errors% error(s) occurred. Check logs for details.',
                    'Modules.Inpostvonhalsky.Admin',
                    ['%errors%' => $stats['errors']]
                );
                $this->addFlash('warning', $message);
            } else {
                $this->addFlash('success', $message);
            }

        } catch (\Exception $e) {
            $errorMsg = $e->getMessage();
            $this->addFlash('error', $this->trans(
                'Synchronization failed: %error%',
                'Modules.Inpostvonhalsky.Admin',
                ['%error%' => $errorMsg]
            ));
            ShopLog::add(
                '[InPost Manual Sync] Exception: ' . $errorMsg . ' in ' . $e->getFile() . ':' . $e->getLine(),
                3
            );
        }

        return $this->redirectToRoute('admin_inpost_orders_index');
    }
}
