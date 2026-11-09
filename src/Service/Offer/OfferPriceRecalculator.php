<?php
/**
 * OfferPriceRecalculator (GOKINT-1098)
 *
 * Recalculates the InPost price of every offer after something that can change
 * all of them: the price settings (customer group, %, rounding), a catalog
 * price rule, or a specific price for all products. Such a change only asks
 * for a recalculation (INPOSTSA_PRICE_RECALC_PENDING); the offer_sync cron runs
 * it in batches, so a big catalogue never blocks a request.
 *
 * The pending value is the id of the last offer done ('' = nothing pending), so
 * the next batch continues after it; a new request starts over from the first
 * offer. Offers whose price changed are flagged for the API sync, exactly as
 * after a product change.
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Service\Offer;

use Configuration;
use Doctrine\ORM\EntityManagerInterface;
use Inpost\VonHalsky\Entity\Offer;
use Inpost\VonHalsky\Repository\OfferRepository;
use Inpost\VonHalsky\Service\Util\ConfigurationService;
use Inpost\VonHalsky\Service\Util\LoggerService;
use Product;

class OfferPriceRecalculator
{
    /** Offers per batch */
    public const BATCH_SIZE = 300;

    private EntityManagerInterface $entityManager;
    private OfferRepository $offerRepository;
    private OfferPriceResolver $priceResolver;
    private LoggerService $logger;

    public function __construct(
        EntityManagerInterface $entityManager,
        OfferRepository $offerRepository,
        OfferPriceResolver $priceResolver,
        LoggerService $logger
    ) {
        $this->entityManager = $entityManager;
        $this->offerRepository = $offerRepository;
        $this->priceResolver = $priceResolver;
        $this->logger = $logger;
    }

    /**
     * Ask for a recalculation of all offers (from the first one)
     */
    public static function request(): void
    {
        Configuration::updateGlobalValue(ConfigurationService::PRICE_RECALC_PENDING, '0');
    }

    public static function isPending(): bool
    {
        return self::cursor() !== null;
    }

    /**
     * Recalculate the next batch of offers, if a recalculation is pending
     *
     * @return array{checked:int, changed:int, done:bool}|null null = nothing pending
     */
    public function runPending(int $limit = self::BATCH_SIZE): ?array
    {
        $cursor = self::cursor();
        if ($cursor === null) {
            return null;
        }

        /** @var Offer[] $offers */
        $offers = $this->offerRepository->createQueryBuilder('o')
            ->where('o.offerId > :cursor')
            ->setParameter('cursor', $cursor)
            ->orderBy('o.offerId', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        $checked = 0;
        $changed = 0;
        $lastId = $cursor;
        // Settings, rules and specific prices may have changed earlier in this process
        Product::flushPriceCache();

        foreach ($offers as $offer) {
            $lastId = (int) $offer->getOfferId();
            $productId = (int) $offer->getPrestashopOfferId();
            $offerPrice = $offer->getPrice();
            if ($productId <= 0 || $offerPrice === null || !Product::existsInDatabase($productId, 'product')) {
                continue;
            }
            $checked++;

            try {
                if ($this->priceResolver->refresh($offerPrice, $productId, $offer->getPrestashopProductAttributeId())) {
                    $offer->setRequireApiSync(true);
                    $offer->setUpdatedAt(new \DateTime());
                    $changed++;
                }
            } catch (\Throwable $e) {
                $this->logger->warning('InPost price recalculation: offer skipped', [
                    'offer_id' => $offer->getOfferId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }
        $this->entityManager->flush();

        $done = count($offers) < $limit;
        // A new request made meanwhile (cursor back to 0) is kept
        if (self::cursor() === $cursor) {
            Configuration::updateGlobalValue(ConfigurationService::PRICE_RECALC_PENDING, $done ? '' : (string) $lastId);
        }

        $this->logger->info('InPost price recalculation batch', [
            'after_offer_id' => $cursor,
            'checked' => $checked,
            'changed' => $changed,
            'done' => $done,
        ]);

        return ['checked' => $checked, 'changed' => $changed, 'done' => $done];
    }

    private static function cursor(): ?int
    {
        // Read from the database: another process (configuration page, hook) may have set it
        $value = \Db::getInstance()->getValue(
            'SELECT `value` FROM `' . _DB_PREFIX_ . 'configuration` WHERE `name` = \'' . pSQL(ConfigurationService::PRICE_RECALC_PENDING) . '\''
            . ' AND `id_shop` IS NULL AND `id_shop_group` IS NULL'
        );

        return $value === false || $value === null || $value === '' ? null : (int) $value;
    }
}
