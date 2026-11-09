<?php

declare(strict_types=1);

namespace Inpost\VonHalsky\Controller\Admin\Ajax;

use Inpost\VonHalsky\Service\Offer\OfferService;
use Inpost\VonHalsky\Service\Util\InpostLoggerService;
use PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Offer Status AJAX Controller
 *
 * Handles AJAX requests for close/reopen offer actions
 */
class OfferStatusController extends FrameworkBundleAdminController
{
    private OfferService $offerService;
    private InpostLoggerService $logger;

    public function __construct(
        OfferService $offerService,
        InpostLoggerService $logger
    ) {
        $this->offerService = $offerService;
        $this->logger = $logger;
    }

    /**
     * Close a published offer
     */
    public function closeAction(Request $request): JsonResponse
    {
        return $this->changeStatus(
            $request,
            'close',
            fn (int $offerId) => $this->offerService->closeOffer($offerId),
            $this->trans('Offer closed successfully', 'Modules.Inpostvonhalsky.Admin')
        );
    }

    /**
     * Reopen a closed offer
     */
    public function reopenAction(Request $request): JsonResponse
    {
        return $this->changeStatus(
            $request,
            'reopen',
            fn (int $offerId) => $this->offerService->reopenOffer($offerId),
            $this->trans('Offer reopened successfully', 'Modules.Inpostvonhalsky.Admin')
        );
    }

    /**
     * Run a close/reopen operation for the offer_id posted in the request
     *
     * @param callable(int): array $operation OfferService call returning a result array
     */
    private function changeStatus(Request $request, string $action, callable $operation, string $successMessage): JsonResponse
    {
        try {
            $offerId = (int) $request->request->get('offer_id');

            if (!$offerId) {
                return $this->json([
                    'success' => false,
                    'error' => 'offer_id parameter is required',
                ], 400);
            }

            $result = $operation($offerId);

            if (!$result['success']) {
                return $this->json([
                    'success' => false,
                    'error' => $result['error'] ?? 'Failed to ' . $action . ' offer',
                ], 400);
            }

            return $this->json([
                'success' => true,
                'message' => $successMessage,
                'data' => $result['data'] ?? [],
            ]);
        } catch (\Exception $e) {
            $this->logger->error('OfferStatusController::' . $action . 'Action error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->json([
                'success' => false,
                'error' => 'Internal server error: ' . $e->getMessage(),
            ], 500);
        }
    }
}
