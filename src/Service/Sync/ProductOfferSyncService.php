<?php
declare(strict_types=1);

namespace Inpost\VonHalsky\Service\Sync;

use Doctrine\ORM\EntityManagerInterface;
use Inpost\VonHalsky\Entity\Offer;
use Inpost\VonHalsky\Entity\OfferAttributes;
use Inpost\VonHalsky\Entity\OfferPrice;
use Inpost\VonHalsky\Entity\OfferStock;
use Inpost\VonHalsky\Repository\OfferRepository;
use Inpost\VonHalsky\Service\Offer\HintApplyService;
use Inpost\VonHalsky\Service\Offer\OfferPriceResolver;
use Inpost\VonHalsky\Service\Offer\ProductAttributeValueResolver;
use Inpost\VonHalsky\Service\Util\ConfigurationService;
use Inpost\VonHalsky\Service\Util\LoggerService;
use Product;

/**
 * Real-time product to offer synchronization service
 * 
 * Syncs PrestaShop product changes to offer price/stock tables immediately
 * Replaces cron-based batch updates with event-driven hook updates
 * 
 * Pattern: Mirrors Magento ProductUpdateObserver behavior
 */
class ProductOfferSyncService
{
    private OfferRepository $offerRepository;
    private EntityManagerInterface $entityManager;
    private LoggerService $logger;
    private ConfigurationService $config;
    private ProductAttributeValueResolver $attributeValueResolver;
    private HintApplyService $attributeApplier;
    private PsPriceStockReader $priceStockReader;
    private OfferPriceResolver $priceResolver;

    public function __construct(
        OfferRepository $offerRepository,
        EntityManagerInterface $entityManager,
        LoggerService $logger,
        ConfigurationService $config,
        ProductAttributeValueResolver $attributeValueResolver,
        HintApplyService $attributeApplier,
        PsPriceStockReader $priceStockReader,
        OfferPriceResolver $priceResolver
    ) {
        $this->offerRepository = $offerRepository;
        $this->entityManager = $entityManager;
        $this->logger = $logger;
        $this->config = $config;
        $this->attributeValueResolver = $attributeValueResolver;
        $this->attributeApplier = $attributeApplier;
        $this->priceStockReader = $priceStockReader;
        $this->priceResolver = $priceResolver;
    }

    /**
     * Synchronize product updates to all associated offers
     *
     * Called from PrestaShop hooks: actionProductUpdate, actionUpdateQuantity,
     * actionObjectCombinationUpdateAfter. Each offer is refreshed for what it
     * sells (one combination or the whole product); changed offers get
     * require_api_sync.
     *
     * @param int $productId PrestaShop product ID
     * @param int|null $idProductAttribute null = every offer of the product; a combination
     *        id = its offers and the whole-product offer (total stock); 0 = only the
     *        whole-product offer (PrestaShop updated the product total)
     * @return void
     */
    public function syncProductUpdate(int $productId, ?int $idProductAttribute = null): void
    {
        try {
            $offers = $this->findProductOffers($productId, $idProductAttribute);

            if (empty($offers)) {
                $this->logger->debug(
                    'InPost Sync: No offers found for product',
                    ['product_id' => $productId, 'id_product_attribute' => $idProductAttribute]
                );
                return;
            }

            $this->logger->info(
                'InPost Sync: Starting sync for product',
                ['product_id' => $productId, 'id_product_attribute' => $idProductAttribute, 'offer_count' => count($offers)]
            );

            $updatedCount = 0;

            // Use Doctrine transaction for atomicity
            $this->entityManager->transactional(function () use ($offers, $productId, &$updatedCount) {
                foreach ($offers as $offer) {
                    if ($this->syncOffer($offer)) {
                        $offer->setRequireApiSync(true);
                        $offer->setUpdatedAt(new \DateTime());
                        $updatedCount++;

                        $this->logger->info(
                            'InPost Sync: Offer updated',
                            [
                                'product_id' => $productId,
                                'offer_id' => $offer->getInpostOfferId(),
                                'id_product_attribute' => $offer->getPrestashopProductAttributeId(),
                                'require_api_sync' => true
                            ]
                        );
                    }
                }
            });

            if ($updatedCount > 0) {
                $this->logger->info(
                    'InPost Sync: Product sync completed',
                    [
                        'product_id' => $productId,
                        'updated_offers' => $updatedCount
                    ]
                );
            } else {
                $this->logger->debug(
                    'InPost Sync: No changes detected for product',
                    ['product_id' => $productId]
                );
            }

        } catch (\Exception $e) {
            $this->logger->error(
                'InPost Sync: Error syncing product offers',
                [
                    'product_id' => $productId,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]
            );
            
            // Re-throw to allow hook to handle error
            throw $e;
        }
    }

    /**
     * Bring one offer up to date with PrestaShop right before it is sent - the
     * hooks cannot always do it (front office checkout only flags the offer).
     *
     * @return bool True if anything changed (already flushed)
     */
    public function refreshOffer(Offer $offer): bool
    {
        if (!$offer->getPrestashopOfferId()) {
            return false;
        }

        $changed = $this->syncOffer($offer);
        if ($changed) {
            $this->entityManager->flush();
        }

        return $changed;
    }

    /**
     * A combination was deleted in PrestaShop: its offers have nothing left to
     * sell - zero stock, sent on the next sync
     *
     * @return int Number of offers updated
     */
    public function markCombinationDeleted(int $productId, int $idProductAttribute): int
    {
        if ($idProductAttribute <= 0) {
            return 0;
        }

        $count = 0;
        foreach ($this->offerRepository->findBy(['prestashopOfferId' => $productId, 'prestashopProductAttributeId' => $idProductAttribute]) as $offer) {
            $stock = $offer->getStock();
            if ($stock !== null && $stock->getQuantity() !== 0) {
                $stock->setQuantity(0);
                $stock->setAvailability('unavailable');
            }
            $offer->setRequireApiSync(true);
            $offer->setUpdatedAt(new \DateTime());
            $count++;
        }

        if ($count > 0) {
            $this->entityManager->flush();
            $this->logger->warning('InPost Sync: Combination deleted in PrestaShop - offer stock set to 0', [
                'product_id' => $productId,
                'id_product_attribute' => $idProductAttribute,
                'offers' => $count,
            ]);
        }

        return $count;
    }

    /**
     * @return Offer[]
     */
    private function findProductOffers(int $productId, ?int $idProductAttribute): array
    {
        $offers = $this->offerRepository->findBy(['prestashopOfferId' => $productId]);
        if ($idProductAttribute === null) {
            return $offers;
        }

        return array_values(array_filter($offers, static function (Offer $offer) use ($idProductAttribute): bool {
            $offerCombination = $offer->getPrestashopProductAttributeId();

            return $offerCombination === 0 || $offerCombination === $idProductAttribute;
        }));
    }

    /**
     * Price, stock and PrestaShop-sourced attribute values of one offer
     *
     * @return bool True if anything changed
     */
    private function syncOffer(Offer $offer): bool
    {
        $productId = (int) $offer->getPrestashopOfferId();
        $changed = $this->syncOfferPrice($offer, $productId);
        $changed = $this->syncOfferStock($offer, $productId) || $changed;

        return $this->syncOfferAttributes($offer, $productId) || $changed;
    }

    /**
     * Refresh attribute values that come from PrestaShop (value_source 'ps') and
     * add values of newly mapped attributes. Values from hints or typed in by
     * the merchant are never touched.
     *
     * @return bool True if any value changed
     */
    private function syncOfferAttributes(Offer $offer, int $productId): bool
    {
        $categoryMappingId = $offer->getProduct() !== null ? (int) $offer->getProduct()->getCategoryMappingId() : 0;
        if (!$categoryMappingId) {
            return false;
        }

        try {
            $resolved = ProductAttributeValueResolver::applicable($this->attributeValueResolver->resolve(
                $productId,
                $offer->getPrestashopProductAttributeId(),
                $categoryMappingId,
                $this->config->getDefaultLanguage()
            ));
        } catch (\Throwable $e) {
            $this->logger->warning('InPost Sync: Could not read attribute values', [
                'product_id' => $productId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        $existing = [];
        foreach ($offer->getAttributes() as $offerAttribute) {
            $existing[(string) $offerAttribute->getInpostAttributeId()] = $offerAttribute;
        }

        $changes = [];
        foreach ($resolved as $uuid => $entry) {
            $current = $existing[$uuid] ?? null;
            if ($current === null) {
                $changes[$uuid] = $entry;
                continue;
            }
            if ($current->getValueSource() !== OfferAttributes::SOURCE_PS) {
                continue;
            }
            $stored = json_decode($current->getValue(), true);
            if (!is_array($stored) || array_map('strval', $stored) !== array_map('strval', $entry['values'])) {
                $changes[$uuid] = $entry;
            }
        }

        if (empty($changes)) {
            return false;
        }

        // New values are persisted through the Offer::attributes cascade
        $this->attributeApplier->applyAttributes($offer, $changes, true, OfferAttributes::SOURCE_PS);

        $this->logger->info('InPost Sync: Offer attributes updated from PrestaShop', [
            'product_id' => $productId,
            'offer_id' => $offer->getOfferId(),
            'attributes' => array_keys($changes),
        ]);

        return true;
    }

    /**
     * Synchronize the offer price (OfferPriceResolver: manual price, or the shop
     * price for the configured group with the % rule) to the offer price table
     *
     * Updates only if the price sent to InPost changed, to avoid unnecessary API
     * sync triggers; the shop price shown next to it is refreshed silently.
     * Creates OfferPrice entity if doesn't exist
     *
     * @param Offer $offer The offer to update
     * @param int $productId PrestaShop product ID
     * @return bool True if price changed and updated
     */
    private function syncOfferPrice(Offer $offer, int $productId): bool
    {
        try {
            if (!\Validate::isLoadedObject(new Product($productId, false))) {
                $this->logger->warning(
                    'InPost Sync: Product not found',
                    ['product_id' => $productId]
                );
                return false;
            }

            // Get currency from config or default to PLN
            $currency = $this->config->get('INPOSTSA_DEFAULT_CURRENCY') ?? 'PLN';

            // Get or create OfferPrice entity
            $offerPrice = $offer->getPrice();

            if (!$offerPrice) {
                // Create new price record
                $offerPrice = new OfferPrice();
                $offerPrice->setOffer($offer);
                $offer->setPrice($offerPrice);
                $this->entityManager->persist($offerPrice);

                $this->priceResolver->refresh($offerPrice, $productId, $offer->getPrestashopProductAttributeId());
                $offerPrice->setCurrency($currency);

                $this->logger->info(
                    'InPost Sync: Created new offer price',
                    [
                        'offer_id' => $offer->getInpostOfferId(),
                        'price' => $offerPrice->getPrice(),
                        'currency' => $currency
                    ]
                );

                return true; // New record = change
            }

            $currentPrice = (float) $offerPrice->getPrice();
            // Price of what the offer sells (its combination, or the product)
            $priceChanged = $this->priceResolver->refresh($offerPrice, $productId, $offer->getPrestashopProductAttributeId());

            if ($priceChanged || $offerPrice->getCurrency() !== $currency) {
                $offerPrice->setCurrency($currency);

                $this->logger->info(
                    'InPost Sync: Updated offer price',
                    [
                        'offer_id' => $offer->getInpostOfferId(),
                        'old_price' => $currentPrice,
                        'new_price' => $offerPrice->getPrice(),
                        'price_mode' => $offerPrice->getPriceMode(),
                        'currency' => $currency
                    ]
                );

                return true; // Price changed
            }

            return false; // No change

        } catch (\Exception $e) {
            $this->logger->error(
                'InPost Sync: Error syncing offer price',
                [
                    'offer_id' => $offer->getInpostOfferId(),
                    'product_id' => $productId,
                    'error' => $e->getMessage()
                ]
            );

            return false;
        }
    }

    /**
     * Synchronize product stock to offer stock table
     * 
     * Compares current product stock with offer_stock table
     * Updates only if quantity changed to avoid unnecessary API sync triggers
     * Creates OfferStock entity if doesn't exist
     * Sets availability based on quantity (available/unavailable)
     * 
     * @param Offer $offer The offer to update
     * @param int $productId PrestaShop product ID
     * @return bool True if stock changed and updated
     */
    private function syncOfferStock(Offer $offer, int $productId): bool
    {
        try {
            // Stock of the offer's combination, or the product total (all combinations)
            $newQuantity = $this->priceStockReader->quantity($productId, $offer->getPrestashopProductAttributeId(), $offer->getShopId());
            
            // Get unit from config or default to UNIT
            $unit = $this->config->get('INPOSTSA_DEFAULT_STOCK_UNIT') ?? 'UNIT';
            
            // Determine availability based on quantity
            $availability = $newQuantity > 0 ? 'available' : 'unavailable';

            // Get or create OfferStock entity
            $offerStock = $offer->getStock();
            
            if (!$offerStock) {
                // Create new stock record
                $offerStock = new OfferStock();
                $offerStock->setOffer($offer);
                $offer->setStock($offerStock);
                $this->entityManager->persist($offerStock);
                
                $offerStock->setQuantity($newQuantity);
                $offerStock->setUnit($unit);
                $offerStock->setAvailability($availability);
                
                $this->logger->info(
                    'InPost Sync: Created new offer stock',
                    [
                        'offer_id' => $offer->getInpostOfferId(),
                        'quantity' => $newQuantity,
                        'unit' => $unit,
                        'availability' => $availability
                    ]
                );
                
                return true; // New record = change
            }

            // Check if quantity changed
            $currentQuantity = $offerStock->getQuantity();
            $quantityChanged = $currentQuantity !== $newQuantity;

            if ($quantityChanged || $offerStock->getUnit() !== $unit) {
                $offerStock->setQuantity($newQuantity);
                $offerStock->setUnit($unit);
                $offerStock->setAvailability($availability);
                
                $this->logger->info(
                    'InPost Sync: Updated offer stock',
                    [
                        'offer_id' => $offer->getInpostOfferId(),
                        'old_quantity' => $currentQuantity,
                        'new_quantity' => $newQuantity,
                        'unit' => $unit,
                        'availability' => $availability
                    ]
                );
                
                return true; // Stock changed
            }

            return false; // No change

        } catch (\Exception $e) {
            $this->logger->error(
                'InPost Sync: Error syncing offer stock',
                [
                    'offer_id' => $offer->getInpostOfferId(),
                    'product_id' => $productId,
                    'error' => $e->getMessage()
                ]
            );
            
            return false;
        }
    }
}
