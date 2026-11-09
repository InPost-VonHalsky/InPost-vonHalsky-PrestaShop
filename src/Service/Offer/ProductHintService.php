<?php
/**
 * ProductHintService
 *
 * Productization (GOKINT-927): fetches product hints from InPost /offers/hint by
 * EAN (falling back to name), maps them to offer fields, and caches results
 * (positive + negative) in the DB. Never throws — on any failure it returns
 * empty/null so callers fall back to plain behaviour.
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Service\Offer;

use Configuration;
use Inpost\VonHalsky\Repository\ProductHintCacheRepository;
use Inpost\VonHalsky\Service\Api\OfferApiService;
use Inpost\VonHalsky\Service\Util\ConfigurationService;
use Inpost\VonHalsky\Service\Util\LoggerService;

class ProductHintService
{
    private const DEFAULT_TTL = 86400;
    private const SEARCH_LIMIT = 10;

    private OfferApiService $offerApiService;
    private ProductHintCacheRepository $cacheRepository;
    private LoggerService $logger;

    public function __construct(
        OfferApiService $offerApiService,
        ProductHintCacheRepository $cacheRepository,
        LoggerService $logger
    ) {
        $this->offerApiService = $offerApiService;
        $this->cacheRepository = $cacheRepository;
        $this->logger = $logger;
    }

    /**
     * Search hints for a query. EAN first (numeric only), then name fallback.
     *
     * @return array<int,array<string,mixed>> Normalized candidate hints
     */
    public function search(string $query, string $language, int $limit = self::SEARCH_LIMIT): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        // Numeric query ⇒ try EAN lookup first.
        if (ctype_digit($query)) {
            $byEan = $this->fetchAndCache('ean', $query, $language, $limit, ['ean' => $query]);
            if (!empty($byEan)) {
                return $byEan;
            }
        }

        // Fall back to name search.
        return $this->fetchAndCache('name', $query, $language, $limit, ['name' => $query]);
    }

    /**
     * Re-run a search and return the hint with the given InPost product id, or null.
     * Used to resolve a hint selected in the UI server-side (never trust a client payload).
     *
     * @return array<string,mixed>|null
     */
    public function findByProductId(string $query, string $productId, string $language): ?array
    {
        foreach ($this->search($query, $language) as $candidate) {
            if ((string) ($candidate['product_id'] ?? '') === $productId) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Return the single best hint for an EAN, or null.
     *
     * @return array<string,mixed>|null
     */
    public function findByEan(string $ean, string $language): ?array
    {
        $ean = trim($ean);
        if ($ean === '' || !ctype_digit($ean)) {
            return null;
        }

        // Same limit as search(): the cache is keyed by (type, query, language) only,
        // so a 1-hit entry would later truncate search() results for this EAN
        $hits = $this->fetchAndCache('ean', $ean, $language, self::SEARCH_LIMIT, ['ean' => $ean]);
        return $hits[0] ?? null;
    }

    /**
     * Cache-aware fetch. Distinguishes "API said empty" (negative cache) from
     * "API failed" (not cached — must recover next time).
     *
     * @param array<string,string> $apiArgs
     * @return array<int,array<string,mixed>>
     */
    private function fetchAndCache(string $type, string $query, string $language, int $limit, array $apiArgs): array
    {
        $cached = $this->cacheRepository->get($type, $query, $language);
        if ($cached !== null) {
            if (isset($cached['negative'])) {
                return [];
            }
            return $cached['hits'] ?? [];
        }

        try {
            $response = $this->offerApiService->getOfferHints(
                $apiArgs['ean'] ?? null,
                $apiArgs['name'] ?? null,
                null,
                $limit,
                0,
                $language
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Hint API call threw: ' . $e->getMessage());
            return [];
        }

        $ttl = (int) Configuration::get(ConfigurationService::HINT_CACHE_TTL, null, null, null, self::DEFAULT_TTL);

        // 404 "No results found" is a definitive miss, not an error ⇒ negative cache.
        if (!($response['success'] ?? false) && (int) ($response['status_code'] ?? 0) === 404) {
            $this->cacheRepository->put($type, $query, $language, null, $ttl > 0 ? $ttl : self::DEFAULT_TTL);
            return [];
        }

        // Transient/API error: do NOT cache — must recover next attempt.
        if (!($response['success'] ?? false)) {
            $this->logger->warning('Hint API call failed (not cached)', [
                'type' => $type,
                'error' => $response['error'] ?? 'unknown',
            ]);
            return [];
        }

        $hits = $this->normalizeResponse($response['data'] ?? null);

        // Empty hits ⇒ negative cache; non-empty ⇒ positive cache.
        $this->cacheRepository->put($type, $query, $language, empty($hits) ? null : $hits, $ttl > 0 ? $ttl : self::DEFAULT_TTL);

        return $hits;
    }

    /**
     * Normalize a Hints SDK response into an array of mapped ProductHint arrays.
     *
     * @param mixed $data
     * @return array<int,array<string,mixed>>
     */
    private function normalizeResponse($data): array
    {
        $items = [];
        if (is_object($data) && method_exists($data, 'getData')) {
            $items = $data->getData() ?? [];
        } elseif (is_array($data)) {
            $items = $data['data'] ?? $data;
        }

        $result = [];
        foreach ($items as $hint) {
            $product = (is_object($hint) && method_exists($hint, 'getProduct')) ? $hint->getProduct() : null;
            if ($product === null) {
                continue;
            }
            $result[] = $this->mapProductHint($product);
        }

        return $result;
    }

    /**
     * Map an InPost ProductHint object to a normalized array.
     *
     * @param object $product ProductHint
     * @return array<string,mixed>
     */
    public function mapProductHint($product): array
    {
        $dimension = method_exists($product, 'getDimension') ? $product->getDimension() : null;

        return [
            'product_id' => $this->call($product, 'getProductId'),
            'name' => $this->call($product, 'getName'),
            'description' => strip_tags((string) $this->call($product, 'getDescription')),
            'brand' => $this->call($product, 'getBrand'),
            'model' => $this->call($product, 'getModel'),
            'super_model' => $this->call($product, 'getSuperModel'),
            'manufacturer_product_number' => $this->call($product, 'getManufacturerProductNumber'),
            'ean' => $this->call($product, 'getEan'),
            'category_id' => $this->call($product, 'getCategoryId'),
            'dimension' => [
                'width' => $dimension && method_exists($dimension, 'getWidth') ? $dimension->getWidth() : null,
                'height' => $dimension && method_exists($dimension, 'getHeight') ? $dimension->getHeight() : null,
                'length' => $dimension && method_exists($dimension, 'getLength') ? $dimension->getLength() : null,
                'weight' => $dimension && method_exists($dimension, 'getWeight') ? $dimension->getWeight() : null,
            ],
            'attributes' => $this->mapAttributes($product),
        ];
    }

    /**
     * Extract raw attribute values (id/lang/values) from a ProductHint.
     *
     * @param object $product
     * @return array<int,array<string,mixed>>
     */
    private function mapAttributes($product): array
    {
        if (!method_exists($product, 'getAttributes')) {
            return [];
        }
        $attributes = $product->getAttributes() ?? [];
        $result = [];
        foreach ($attributes as $attr) {
            if (is_object($attr) && method_exists($attr, 'getId')) {
                $result[] = [
                    'id' => $attr->getId(),
                    'lang' => method_exists($attr, 'getLang') ? $attr->getLang() : null,
                    'values' => method_exists($attr, 'getValues') ? ($attr->getValues() ?? []) : [],
                ];
            } elseif (is_array($attr)) {
                $result[] = [
                    'id' => $attr['id'] ?? null,
                    'lang' => $attr['lang'] ?? null,
                    'values' => $attr['values'] ?? [],
                ];
            }
        }
        return $result;
    }

    /**
     * @param object $obj
     * @return mixed
     */
    private function call($obj, string $method)
    {
        return method_exists($obj, $method) ? $obj->$method() : null;
    }
}
