<?php

declare(strict_types=1);

namespace Inpost\VonHalsky\Controller\Admin\Ajax;

use Inpost\VonHalsky\Helper\Config;
use Inpost\VonHalsky\Service\Offer\ProductCombinationService;
use Inpost\VonHalsky\Service\Util\LoggerService;
use PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Product Autocomplete AJAX Controller
 *
 * Provides JSON product search for offer creation:
 * - Search products by name, SKU or EAN; combinations by their SKU or EAN
 * - Return product ID (+ combination), name, SKU, price
 *
 * @package Inpost\VonHalsky\Controller\Admin\Ajax
 */
class ProductAutocompleteController extends FrameworkBundleAdminController
{
    private LoggerService $logger;
    private Config $config;
    private ProductCombinationService $combinationService;

    public function __construct(LoggerService $logger, Config $config, ProductCombinationService $combinationService)
    {
        $this->logger = $logger;
        $this->config = $config;
        $this->combinationService = $combinationService;
    }

    /**
     * Search products
     *
     * @param Request $request HTTP request
     * @return JsonResponse JSON products list
     */
    public function searchAction(Request $request): JsonResponse
    {
        try {
            // Accept both 'q' and 'query' parameters
            $query = $request->query->get('q', $request->query->get('query', ''));

            $shopId = $this->config->getDefaultShopId();
            $langId = $this->config->getDefaultLanguageId();

            if (strlen($query) < 2) {
                return $this->json([
                    'success' => false,
                    'message' => 'Search query too short (minimum 2 characters)'
                ]);
            }

            // Combinations matched by their own SKU / EAN first (typing a variant EAN
            // leads straight to that variant), then products
            $products = array_merge(
                $this->searchCombinations($query, $shopId, $langId),
                $this->searchProducts($query, $shopId, $langId)
            );

            return $this->json([
                'success' => true,
                'products' => $products
            ]);
        } catch (\Exception $e) {
            $this->logger->logException($e, [
                'controller' => 'ProductAutocompleteController',
                'action' => 'searchAction',
                'query' => $query ?? ''
            ]);
            $this->logger->error('Product autocomplete error', ['exception' => get_class($e)]);

            return $this->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Search products in PrestaShop
     *
     * @param string $query Search query
     * @param int $shopId Shop ID
     * @param int $langId Language ID
     * @return array Products array
     */
    private function searchProducts(string $query, int $shopId, int $langId): array
    {
        $db = \Db::getInstance();

        // Search by name, reference (SKU), or EAN
        $sql = 'SELECT p.id_product, pl.name, p.reference, p.price, p.ean13
                FROM ' . _DB_PREFIX_ . 'product p
                INNER JOIN ' . _DB_PREFIX_ . 'product_lang pl
                    ON p.id_product = pl.id_product
                    AND pl.id_lang = ' . (int) $langId . '
                INNER JOIN ' . _DB_PREFIX_ . 'product_shop ps
                    ON p.id_product = ps.id_product
                    AND ps.id_shop = ' . (int) $shopId . '
                WHERE (pl.name LIKE "%' . pSQL($query) . '%"
                    OR p.reference LIKE "%' . pSQL($query) . '%"
                    OR p.ean13 LIKE "%' . pSQL($query) . '%")
                    AND p.active = 1
                ORDER BY pl.name ASC
                LIMIT 20';

        $results = $db->executeS($sql);

        if (!$results) {
            return [];
        }

        $withCombinations = $this->productsWithCombinations(array_column($results, 'id_product'));

        // Format results
        $products = [];
        foreach ($results as $row) {
            $products[] = [
                'id' => (int) $row['id_product'],
                'id_product_attribute' => 0,
                'combination' => '',
                'has_combinations' => isset($withCombinations[(int) $row['id_product']]),
                'name' => $row['name'],
                'sku' => $row['reference'],
                'ean' => $row['ean13'] ?? '',
                'price' => (float) $row['price']
            ];
        }

        return $products;
    }

    /**
     * Combinations whose own reference or EAN matches (not a reference copied from the product)
     *
     * @return array<int,array<string,mixed>>
     */
    private function searchCombinations(string $query, int $shopId, int $langId): array
    {
        $rows = \Db::getInstance()->executeS(
            'SELECT pa.id_product, pa.id_product_attribute, pa.reference, pa.ean13, pl.name
            FROM ' . _DB_PREFIX_ . 'product_attribute pa
            INNER JOIN ' . _DB_PREFIX_ . 'product_attribute_shop pas
                ON pas.id_product_attribute = pa.id_product_attribute AND pas.id_shop = ' . (int) $shopId . '
            INNER JOIN ' . _DB_PREFIX_ . 'product p ON p.id_product = pa.id_product AND p.active = 1
            INNER JOIN ' . _DB_PREFIX_ . 'product_lang pl
                ON pl.id_product = pa.id_product AND pl.id_lang = ' . (int) $langId . ' AND pl.id_shop = ' . (int) $shopId . '
            WHERE (pa.reference LIKE "%' . pSQL($query) . '%" AND pa.reference <> p.reference)
                OR pa.ean13 LIKE "%' . pSQL($query) . '%"
            ORDER BY pl.name ASC, pa.id_product_attribute ASC
            LIMIT 20'
        ) ?: [];

        $labels = $this->combinationService->getLabels(array_column($rows, 'id_product_attribute'));

        $combinations = [];
        foreach ($rows as $row) {
            $pa = (int) $row['id_product_attribute'];
            $combinations[] = [
                'id' => (int) $row['id_product'],
                'id_product_attribute' => $pa,
                'combination' => $labels[$pa] ?? '',
                'has_combinations' => true,
                'name' => $row['name'],
                'sku' => $row['reference'],
                'ean' => $row['ean13'] ?? '',
                'price' => 0.0,
            ];
        }

        return $combinations;
    }

    /**
     * @param array<int|string> $productIds
     * @return array<int,true>
     */
    private function productsWithCombinations(array $productIds): array
    {
        $ids = array_filter(array_map('intval', $productIds));
        if (empty($ids)) {
            return [];
        }

        $rows = \Db::getInstance()->executeS(
            'SELECT DISTINCT id_product FROM ' . _DB_PREFIX_ . 'product_attribute WHERE id_product IN (' . implode(',', $ids) . ')'
        ) ?: [];

        return array_fill_keys(array_map('intval', array_column($rows, 'id_product')), true);
    }
}
