<?php
/**
 * BulkPriceEditService (GOKINT-1098)
 *
 * "Set price" of the offers list - for many offers at once:
 *   - manual:  the same manual price for all of them;
 *   - percent: a manual price = the shop price of each offer plus or minus a
 *              percentage, optionally rounded (fixed - it no longer follows the shop);
 *   - auto:    back to the automatic price (shop price with the price settings).
 *
 * The preview shows the price per offer; apply saves it. Offers whose InPost
 * price changed and that are not drafts are flagged for the API sync - the
 * offer_sync cron sends them, as after a save in the offer form.
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Service\Offer;

use Doctrine\ORM\EntityManagerInterface;
use Inpost\VonHalsky\Entity\Offer;
use Inpost\VonHalsky\Entity\OfferPrice;
use Inpost\VonHalsky\Helper\Config;
use Inpost\VonHalsky\Repository\OfferRepository;
use Inpost\VonHalsky\Service\Util\LoggerService;
use Inpost\VonHalsky\Service\Util\ShopLog;
use Product;

class BulkPriceEditService
{
    public const OP_MANUAL = 'manual';
    public const OP_PERCENT = 'percent';
    public const OP_AUTO = 'auto';
    public const OPERATIONS = [self::OP_MANUAL, self::OP_PERCENT, self::OP_AUTO];

    /** Offers in one request */
    public const MAX_OFFERS = 200;

    /** Highest price accepted (inpost_offer_price.price is DECIMAL(12,4)) */
    private const MAX_PRICE = 99999999.0;

    public const RESULT_CHANGE = 'change';
    public const RESULT_SAME = 'same';
    public const RESULT_SKIP = 'skip';

    private EntityManagerInterface $entityManager;
    private OfferRepository $offerRepository;
    private OfferPriceResolver $priceResolver;
    private Config $config;
    private LoggerService $logger;

    public function __construct(
        EntityManagerInterface $entityManager,
        OfferRepository $offerRepository,
        OfferPriceResolver $priceResolver,
        Config $config,
        LoggerService $logger
    ) {
        $this->entityManager = $entityManager;
        $this->offerRepository = $offerRepository;
        $this->priceResolver = $priceResolver;
        $this->config = $config;
        $this->logger = $logger;
    }

    /**
     * What the operation would do, per offer
     *
     * @param int[] $offerIds
     * @param array<string,mixed> $params operation, amount, percent, rounding
     * @return array<int,array<string,mixed>> Rows: offer_id, product, sku, current, current_mode, new, new_mode, result, reason
     */
    public function preview(array $offerIds, array $params): array
    {
        return array_map([self::class, 'publicRow'], $this->buildPlan($offerIds, $params));
    }

    /**
     * Save the rows of the plan with the result "change"
     *
     * @param int[] $offerIds
     * @param array<string,mixed> $params
     * @return array{changed:int, same:int, skipped:int, to_send:int}
     */
    public function apply(array $offerIds, array $params): array
    {
        $plan = $this->buildPlan($offerIds, $params);
        $changes = array_filter($plan, static function (array $row): bool {
            return $row['result'] === self::RESULT_CHANGE;
        });

        $toSend = 0;
        if (!empty($changes)) {
            $this->entityManager->transactional(function () use ($changes, &$toSend) {
                foreach ($changes as $row) {
                    /** @var Offer $offer */
                    $offer = $row['_offer'];
                    $offerPrice = $offer->getPrice();
                    $offerPrice->setPriceMode($row['new_mode']);
                    if ($row['new_mode'] === OfferPrice::MODE_MANUAL) {
                        $offerPrice->setManualPrice((string) $row['new']);
                    }
                    $priceChanged = $this->priceResolver->refresh(
                        $offerPrice,
                        (int) $offer->getPrestashopOfferId(),
                        $offer->getPrestashopProductAttributeId()
                    );
                    $offer->setUpdatedAt(new \DateTime());
                    if ($priceChanged && !$offer->getIsDraft()) {
                        $offer->setRequireApiSync(true);
                        $toSend++;
                    }
                }
            });
        }

        $count = static function (string $result) use ($plan): int {
            return count(array_filter($plan, static function (array $row) use ($result): bool {
                return $row['result'] === $result;
            }));
        };
        $summary = [
            'changed' => count($changes),
            'same' => $count(self::RESULT_SAME),
            'skipped' => $count(self::RESULT_SKIP),
            'to_send' => $toSend,
        ];

        if ($summary['changed'] > 0) {
            $this->log($changes, $params, $summary);
        }

        return $summary;
    }

    /**
     * @param int[] $offerIds
     * @param array<string,mixed> $params
     * @return array<int,array<string,mixed>>
     */
    private function buildPlan(array $offerIds, array $params): array
    {
        $operation = (string) ($params['operation'] ?? '');
        if (!in_array($operation, self::OPERATIONS, true)) {
            throw new \InvalidArgumentException('Choose what to do with the price.');
        }

        $amount = null;
        $percent = 0.0;
        $rounding = OfferPriceResolver::ROUNDING_NONE;
        if ($operation === self::OP_MANUAL) {
            $amount = self::parseAmount($params['amount'] ?? '');
            if ($amount === null) {
                throw new \InvalidArgumentException('Enter the price - an amount greater than 0, e.g. 99.99.');
            }
        } elseif ($operation === self::OP_PERCENT) {
            $percent = OfferPriceResolver::parsePercent($params['percent'] ?? '');
            if ($percent === null) {
                throw new \InvalidArgumentException('Enter the price change in percent, e.g. 5 or -3.5.');
            }
            $rounding = (string) ($params['rounding'] ?? OfferPriceResolver::ROUNDING_NONE);
            if (!in_array($rounding, OfferPriceResolver::ROUNDINGS, true)) {
                $rounding = OfferPriceResolver::ROUNDING_NONE;
            }
        }

        $rows = [];
        foreach ($this->loadOffers($offerIds) as $offer) {
            $offerPrice = $offer->getPrice();
            $productId = (int) $offer->getPrestashopOfferId();
            $row = $this->row($offer);

            if ($offerPrice === null || $productId <= 0 || !Product::existsInDatabase($productId, 'product')) {
                $rows[] = $row + ['new' => null, 'new_mode' => null, 'result' => self::RESULT_SKIP, 'reason' => 'The product no longer exists in PrestaShop.'];
                continue;
            }

            switch ($operation) {
                case self::OP_MANUAL:
                    $new = $amount;
                    break;
                case self::OP_PERCENT:
                    $shopPrice = $this->priceResolver->resolve($productId, $offer->getPrestashopProductAttributeId())['shop_price'];
                    $new = OfferPriceResolver::applyRule($shopPrice, $percent, $rounding);
                    // applyRule() leaves the price exact when there is nothing to change
                    $new = round($new, 2);
                    break;
                default:
                    $new = $this->priceResolver->resolve($productId, $offer->getPrestashopProductAttributeId())['price'];
            }
            $newMode = $operation === self::OP_AUTO ? OfferPrice::MODE_AUTO : OfferPrice::MODE_MANUAL;

            if ($new <= 0 || $new > self::MAX_PRICE) {
                $rows[] = $row + ['new' => $new, 'new_mode' => $newMode, 'result' => self::RESULT_SKIP, 'reason' => 'The new price must be greater than 0.'];
                continue;
            }

            $same = $offerPrice->getPriceMode() === $newMode
                && abs((float) $offerPrice->getPrice() - $new) < 0.005
                && ($newMode === OfferPrice::MODE_AUTO || abs((float) $offerPrice->getManualPrice() - $new) < 0.005);

            $rows[] = $row + ['new' => $new, 'new_mode' => $newMode, 'result' => $same ? self::RESULT_SAME : self::RESULT_CHANGE, 'reason' => null];
        }

        return $rows;
    }

    /**
     * Amount from a form ("99,99", "1 299.00"); null when not a price
     *
     * @param mixed $raw
     */
    public static function parseAmount($raw): ?float
    {
        $value = str_replace([',', ' ', "\u{00A0}"], ['.', '', ''], trim((string) $raw));
        if (!preg_match('/^\d+(\.\d{1,2})?$/', $value)) {
            return null;
        }
        $amount = (float) $value;

        return $amount > 0 && $amount <= self::MAX_PRICE ? $amount : null;
    }

    /**
     * @return array<string,mixed>
     */
    private function row(Offer $offer): array
    {
        $product = $offer->getProduct();
        $offerPrice = $offer->getPrice();

        return [
            'offer_id' => (int) $offer->getId(),
            'product' => $product !== null ? (string) $product->getName() : '',
            'sku' => $product !== null ? (string) $product->getSku() : '',
            'current' => $offerPrice !== null ? round((float) $offerPrice->getPrice(), 2) : null,
            'current_mode' => $offerPrice !== null ? $offerPrice->getPriceMode() : null,
            'currency' => $offerPrice !== null ? $offerPrice->getCurrency() : 'PLN',
            '_offer' => $offer,
        ];
    }

    /**
     * @param int[] $offerIds
     * @return Offer[]
     */
    private function loadOffers(array $offerIds): array
    {
        if (count($offerIds) > self::MAX_OFFERS) {
            throw new \InvalidArgumentException('Too many offers selected.');
        }

        $shopId = $this->config->getDefaultShopId();
        $offers = [];
        foreach ($offerIds as $offerId) {
            $offer = $this->offerRepository->find((int) $offerId);
            if ($offer instanceof Offer && $offer->getShopId() === $shopId) {
                $offers[(int) $offer->getId()] = $offer;
            }
        }

        return $offers;
    }

    /**
     * @param array<int,array<string,mixed>> $changes
     * @param array<string,mixed> $params
     * @param array<string,int> $summary
     */
    private function log(array $changes, array $params, array $summary): void
    {
        $offers = [];
        foreach ($changes as $row) {
            $offers[] = '#' . $row['offer_id'] . ' ' . $row['current'] . ' -> ' . $row['new'];
        }
        $operation = (string) $params['operation'];
        if ($operation === self::OP_MANUAL) {
            $operation .= ' ' . $params['amount'];
        } elseif ($operation === self::OP_PERCENT) {
            $operation .= ' ' . $params['percent'] . '% ' . ($params['rounding'] ?? '');
        }

        ShopLog::add(
            '[InPost] Bulk price edit (' . trim($operation) . ') - ' . implode(', ', $offers),
            1,
            null,
            'InpostOffer'
        );
        $this->logger->info('Bulk price edit', ['operation' => $operation] + $summary);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function publicRow(array $row): array
    {
        return array_filter($row, static function (string $field): bool {
            return $field[0] !== '_';
        }, ARRAY_FILTER_USE_KEY);
    }
}
