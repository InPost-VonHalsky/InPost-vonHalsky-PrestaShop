<?php

declare(strict_types=1);

namespace Inpost\VonHalsky\Controller\Admin\Ajax;

use Inpost\VonHalsky\Repository\OfferRepository;
use Inpost\VonHalsky\Repository\OfferEventRepository;
use Inpost\VonHalsky\Service\Util\InpostLoggerService;
use PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Event AJAX Controller
 *
 * Handles AJAX requests for offer event listing from InPost API
 */
class EventController extends FrameworkBundleAdminController
{
    private OfferRepository $offerRepository;
    private OfferEventRepository $offerEventRepository;
    private InpostLoggerService $logger;

    public function __construct(
        OfferRepository $offerRepository,
        OfferEventRepository $offerEventRepository,
        InpostLoggerService $logger
    ) {
        $this->offerRepository = $offerRepository;
        $this->offerEventRepository = $offerEventRepository;
        $this->logger = $logger;
    }

    public function listAction(Request $request): JsonResponse
    {
        try {
            $offerId = (int) $request->query->get('offer_id');
            $page = (int) ($request->query->get('page') ?: 1);
            $limit = (int) ($request->query->get('limit') ?: 20);

            if (!$offerId) {
                return $this->json([
                    'success' => false,
                    'error' => 'offer_id parameter is required'
                ], 400);
            }

            // Get offer
            $offer = $this->offerRepository->find($offerId);
            if (!$offer) {
                return $this->json([
                    'success' => false,
                    'error' => 'Offer not found'
                ], 404);
            }

            // Check if offer has InPost ID
            $inpostOfferId = $offer->getInpostOfferId();
            if (!$inpostOfferId) {
                return $this->json([
                    'success' => true,
                    'data' => [],
                    'total' => 0,
                    'page' => $page,
                    'pages' => 0,
                    'message' => $this->trans('Offer not yet sent to InPost - no events available', 'Modules.Inpostvonhalsky.Admin')
                ]);
            }

            // Fetch events from database (synced by cron job)
            $events = $this->offerEventRepository->findByOfferPaginated($inpostOfferId, $page, $limit);
            $total = $this->offerEventRepository->countByOffer($inpostOfferId);
            $pages = (int) ceil($total / $limit);

            // Transform events to JSON format
            $data = array_map(function($event) {
                return [
                    'id' => $event->getEventId(),
                    'inpost_event_id' => $event->getInpostOfferEventId(),
                    'type' => $event->getEventType(),
                    'occurred_at' => $event->getOccuredAt()->format('Y-m-d H:i:s'),
                ];
            }, $events);

            return $this->json([
                'success' => true,
                'data' => $data,
                'total' => $total,
                'page' => $page,
                'pages' => $pages,
            ]);

        } catch (\Exception $e) {
            $this->logger->error('EventController::listAction error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return $this->json([
                'success' => false,
                'error' => 'Internal server error: ' . $e->getMessage()
            ], 500);
        }
    }
}
