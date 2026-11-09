<?php
/**
 * ProductCombinationService
 *
 * PrestaShop combinations as offer subjects: labels ("Size: M, Color: Red"),
 * the combination list of a product with its existing offers, and the rule
 * that one stock is never sold by two offers at once.
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Service\Offer;

use Combination;
use Db;
use Inpost\VonHalsky\Entity\Offer;
use Inpost\VonHalsky\Helper\Config;
use Inpost\VonHalsky\Service\Sync\PsPriceStockReader;

class ProductCombinationService
{
    /** Another offer already sells this product / combination */
    public const CONFLICT_SAME = 'same';
    /** An offer of the whole product (all combinations) is still active */
    public const CONFLICT_WHOLE_PRODUCT = 'whole_product';
    /** Offers of single combinations are active - a whole-product offer would sell their stock twice */
    public const CONFLICT_COMBINATIONS = 'combinations';

    /** Offers in these statuses sell nothing and do not block others */
    private const INACTIVE_STATUSES = [Offer::STATUS_CLOSED, Offer::STATUS_ERROR_NOT_CREATED];

    private Config $config;
    private PsPriceStockReader $priceStockReader;
    private Db $db;
    private string $prefix;

    public function __construct(Config $config, PsPriceStockReader $priceStockReader)
    {
        $this->config = $config;
        $this->priceStockReader = $priceStockReader;
        $this->db = Db::getInstance();
        $this->prefix = _DB_PREFIX_;
    }

    public function hasCombinations(int $idProduct): bool
    {
        return Combination::isFeatureActive() && (bool) $this->db->getValue(
            'SELECT 1 FROM `' . $this->prefix . 'product_attribute` WHERE id_product = ' . (int) $idProduct
        );
    }

    /**
     * "Size: M, Color: Red" per combination, in the attribute group order
     *
     * @param int[] $idProductAttributes
     * @return array<int,string>
     */
    public function getLabels(array $idProductAttributes): array
    {
        $ids = array_values(array_filter(array_map('intval', $idProductAttributes)));
        if (empty($ids)) {
            return [];
        }

        $idLang = $this->config->getDefaultLanguageId();
        $rows = $this->db->executeS(
            'SELECT pac.id_product_attribute,
                GROUP_CONCAT(CONCAT(agl.name, \': \', al.name) ORDER BY ag.position, a.position SEPARATOR \', \') AS label
            FROM `' . $this->prefix . 'product_attribute_combination` pac
            INNER JOIN `' . $this->prefix . 'attribute` a ON a.id_attribute = pac.id_attribute
            INNER JOIN `' . $this->prefix . 'attribute_group` ag ON ag.id_attribute_group = a.id_attribute_group
            LEFT JOIN `' . $this->prefix . 'attribute_lang` al ON al.id_attribute = a.id_attribute AND al.id_lang = ' . (int) $idLang . '
            LEFT JOIN `' . $this->prefix . 'attribute_group_lang` agl ON agl.id_attribute_group = ag.id_attribute_group AND agl.id_lang = ' . (int) $idLang . '
            WHERE pac.id_product_attribute IN (' . implode(',', $ids) . ')
            GROUP BY pac.id_product_attribute'
        ) ?: [];

        $labels = [];
        foreach ($rows as $row) {
            $labels[(int) $row['id_product_attribute']] = (string) $row['label'];
        }

        return $labels;
    }

    public function getLabel(int $idProductAttribute): string
    {
        return $this->getLabels([$idProductAttribute])[$idProductAttribute] ?? '';
    }

    /**
     * Combinations of products with what an offer needs: label, reference, EAN,
     * stock, price and the existing offer (if any)
     *
     * @param int[] $productIds
     * @return array<int,array<int,array<string,mixed>>> id_product => list of combinations
     */
    public function getCombinations(array $productIds, int $shopId): array
    {
        $ids = array_values(array_filter(array_map('intval', $productIds)));
        if (empty($ids) || !Combination::isFeatureActive()) {
            return [];
        }

        $rows = $this->db->executeS(
            'SELECT pa.id_product, pa.id_product_attribute, pa.reference, pa.ean13, pa.mpn, pa.upc, pa.isbn, pa.supplier_reference,
                IFNULL(pas.default_on, 0) AS default_on
            FROM `' . $this->prefix . 'product_attribute` pa
            INNER JOIN `' . $this->prefix . 'product_attribute_shop` pas
                ON pas.id_product_attribute = pa.id_product_attribute AND pas.id_shop = ' . (int) $shopId . '
            WHERE pa.id_product IN (' . implode(',', $ids) . ')
            ORDER BY pa.id_product, pa.id_product_attribute'
        ) ?: [];
        if (empty($rows)) {
            return [];
        }

        $labels = $this->getLabels(array_column($rows, 'id_product_attribute'));
        $offers = $this->getOffersByCombination($ids, $shopId);

        $result = [];
        foreach ($rows as $row) {
            $idProduct = (int) $row['id_product'];
            $pa = (int) $row['id_product_attribute'];
            $result[$idProduct][] = [
                'id_product' => $idProduct,
                'id_product_attribute' => $pa,
                'label' => $labels[$pa] ?? ('#' . $pa),
                'reference' => (string) $row['reference'],
                'ean13' => (string) $row['ean13'],
                'mpn' => (string) $row['mpn'],
                'upc' => (string) $row['upc'],
                'isbn' => (string) $row['isbn'],
                'supplier_reference' => (string) $row['supplier_reference'],
                'default_on' => (bool) $row['default_on'],
                'quantity' => $this->priceStockReader->quantity($idProduct, $pa, $shopId),
                'price' => round($this->priceStockReader->price($idProduct, $pa), 2),
                'offer' => $offers[$idProduct][$pa] ?? null,
            ];
        }

        return $result;
    }

    /**
     * Offer that stops a new offer of the product / combination, or null.
     *
     * @return array{offer_id:int, id_product_attribute:int, status:string, reason:string}|null
     */
    public function findConflict(int $idProduct, int $idProductAttribute, int $shopId): ?array
    {
        $offers = $this->getOffersByCombination([$idProduct], $shopId)[$idProduct] ?? [];

        if (isset($offers[$idProductAttribute])) {
            return $offers[$idProductAttribute] + ['reason' => self::CONFLICT_SAME];
        }

        foreach ($offers as $pa => $offer) {
            if (!$offer['active']) {
                continue;
            }
            if ($idProductAttribute > 0 && $pa === 0) {
                return $offer + ['reason' => self::CONFLICT_WHOLE_PRODUCT];
            }
            if ($idProductAttribute === 0 && $pa > 0) {
                return $offer + ['reason' => self::CONFLICT_COMBINATIONS];
            }
        }

        return null;
    }

    /**
     * Latest offer per product and combination (0 = whole product)
     *
     * @param int[] $productIds
     * @return array<int,array<int,array{offer_id:int, id_product_attribute:int, status:string, active:bool}>>
     */
    public function getOffersByCombination(array $productIds, int $shopId): array
    {
        $ids = array_values(array_filter(array_map('intval', $productIds)));
        if (empty($ids)) {
            return [];
        }

        $rows = $this->db->executeS(
            'SELECT offer_id, prestashop_offer_id, prestashop_product_attribute_id, status
            FROM `' . $this->prefix . 'inpost_offer`
            WHERE prestashop_offer_id IN (' . implode(',', $ids) . ') AND shop_id = ' . (int) $shopId . '
            ORDER BY offer_id'
        ) ?: [];

        $map = [];
        foreach ($rows as $row) {
            $pa = (int) $row['prestashop_product_attribute_id'];
            $map[(int) $row['prestashop_offer_id']][$pa] = [
                'offer_id' => (int) $row['offer_id'],
                'id_product_attribute' => $pa,
                'status' => (string) $row['status'],
                'active' => !in_array($row['status'], self::INACTIVE_STATUSES, true),
            ];
        }

        return $map;
    }
}
