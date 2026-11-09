<?php
/**
 * OrderWarnings
 *
 * Per-order warnings for InPost orders that were turned into PrestaShop orders
 * with questionable data, shown as icons in the orders grid and counted in the
 * status panel:
 * - currency: the shop order is not in PLN (InPost amounts were used without conversion)
 * - VAT: order lines created without a tax rule (0% VAT)
 * - products: order lines not matched to a shop product (fallback product used,
 *   e.g. the InPost offer comes from another shop / the SKU does not exist here)
 * - amount: the shop order total differs from the InPost order total (informative,
 *   e.g. shipping cost is not transferred to the shop order)
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Service\Status;

use Db;
use Inpost\VonHalsky\Service\Sync\Order\PrestaShopOrderCreator;
use Inpost\VonHalsky\Service\Util\ShopTaxCurrencyInfo;
use Symfony\Contracts\Translation\TranslatorInterface;

class OrderWarnings
{
    public const LEVEL_WARNING = 'warning';
    public const LEVEL_INFO = 'info';

    private const DOMAIN = 'Modules.Inpostvonhalsky.Admin';

    /**
     * Totals differing by more than this (PLN) are reported
     */
    private const AMOUNT_TOLERANCE = 0.01;

    private TranslatorInterface $translator;

    public function __construct(TranslatorInterface $translator)
    {
        $this->translator = $translator;
    }

    /**
     * @param int[] $idInpostOrders ps_inpost_order ids
     * @return array<int, array<int, array{level: string, message: string}>> Warnings per id_inpost_order
     */
    public function forOrders(array $idInpostOrders): array
    {
        $ids = array_values(array_filter(array_map('intval', $idInpostOrders)));
        if ($ids === []) {
            return [];
        }

        $warnings = [];
        foreach ($this->fetchFacts('io.id_inpost_order IN (' . implode(',', $ids) . ')') as $row) {
            $list = $this->warningsFor($row);
            if ($list !== []) {
                $warnings[(int) $row['id_inpost_order']] = $list;
            }
        }

        return $warnings;
    }

    /**
     * Number of shop orders with a currency or VAT warning (informative amount
     * differences are not counted)
     */
    public function countOrdersWithWarnings(): int
    {
        $count = 0;
        foreach ($this->fetchFacts('1') as $row) {
            foreach ($this->warningsFor($row) as $warning) {
                if ($warning['level'] === self::LEVEL_WARNING) {
                    $count++;
                    break;
                }
            }
        }

        return $count;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchFacts(string $where): array
    {
        $p = _DB_PREFIX_;

        return Db::getInstance()->executeS(
            "SELECT io.id_inpost_order, io.total AS inpost_total,
                    o.total_paid_tax_incl AS shop_total, c.iso_code AS currency,
                    (SELECT COUNT(*) FROM {$p}order_detail od
                      WHERE od.id_order = o.id_order AND od.id_tax_rules_group = 0) AS lines_without_tax_rule,
                    LOCATE('" . pSQL(PrestaShopOrderCreator::UNMATCHED_PRODUCTS_NOTE) . "', COALESCE(o.note, '')) > 0 AS unmatched_products
             FROM {$p}inpost_order io
             JOIN {$p}orders o ON o.id_order = io.id_order
             LEFT JOIN {$p}currency c ON c.id_currency = o.id_currency
             WHERE {$where}"
        ) ?: [];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<int, array{level: string, message: string}>
     */
    private function warningsFor(array $row): array
    {
        $warnings = [];

        $currency = (string) ($row['currency'] ?? '');
        if ($currency !== ShopTaxCurrencyInfo::INPOST_CURRENCY) {
            $warnings[] = [
                'level' => self::LEVEL_WARNING,
                'message' => $this->translator->trans(
                    'The shop order is in %currency%, InPost charged PLN - amounts were taken over without conversion. Enable the PLN currency (International > Localization > Currencies).',
                    ['%currency%' => $currency !== '' ? $currency : '?'],
                    self::DOMAIN
                ),
            ];
        }

        if ((int) $row['lines_without_tax_rule'] > 0) {
            $warnings[] = [
                'level' => self::LEVEL_WARNING,
                'message' => $this->translator->trans(
                    '%count% order line(s) without a tax rule - created with 0% VAT. Assign a tax rule to the product (Catalog > Products > Pricing) or enable taxes (International > Taxes).',
                    ['%count%' => (int) $row['lines_without_tax_rule']],
                    self::DOMAIN
                ),
            ];
        }

        if (!empty($row['unmatched_products'])) {
            $warnings[] = [
                'level' => self::LEVEL_WARNING,
                'message' => $this->translator->trans(
                    'Some order lines did not match any shop product (the InPost offer comes from another shop or the SKU / reference does not exist here) - a fallback product was used, so the product and price differ from InPost. Details in the private note of the shop order.',
                    [],
                    self::DOMAIN
                ),
            ];
        }

        $difference = round((float) $row['shop_total'] - (float) $row['inpost_total'], 2);
        if (abs($difference) > self::AMOUNT_TOLERANCE) {
            $warnings[] = [
                'level' => self::LEVEL_INFO,
                'message' => $this->translator->trans(
                    'Shop order total %shop% differs from the InPost total %inpost% (e.g. the shipping cost is not transferred to the shop order).',
                    [
                        '%shop%' => number_format((float) $row['shop_total'], 2, ',', ' '),
                        '%inpost%' => number_format((float) $row['inpost_total'], 2, ',', ' '),
                    ],
                    self::DOMAIN
                ),
            ];
        }

        return $warnings;
    }
}
