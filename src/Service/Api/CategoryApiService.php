<?php
/**
 * CategoryApiService
 *
 * Service for managing InPost product categories through the InPost von Halsky API.
 * Categories are hierarchical and language-specific.
 * Only leaf categories (those without children) can be assigned to products.
 *
 * Uses SDK-based CategoriesApi for consistent API communication.
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Service\Api;

use Inpost\VonHalsky\Helper\Config;
use Inpost\VonHalsky\Service\Auth\OAuth2Service;
use InpostApi\Api\CategoriesApi;

class CategoryApiService extends ApiServiceAbstract
{
    public function __construct(
        OAuth2Service $oAuth2Service,
        Config $configHelper,
        ApiErrorHandler $errorHandler,
        ?HttpClientFactory $httpClientFactory = null
    ) {
        parent::__construct($oAuth2Service, $configHelper, $errorHandler, $httpClientFactory);
    }

    /**
     * Get configured Categories API instance
     *
     * @return CategoriesApi
     * @throws \Exception
     */
    private function getCategoriesApi(): CategoriesApi
    {
        return new CategoriesApi($this->getHttpClient(), $this->getApiConfiguration());
    }

    /**
     * Get all categories for specified language
     *
     * Note: Categories API doesn't require organization ID.
     *
     * @param string|null $language Language code (e.g., 'pl', 'en'). Default: from config
     * @param int $depth Maximum depth of category tree (default: 3, API supports up to 3)
     * @return array<string, mixed> API response with categories data and HTTP info
     * @throws \Exception
     */
    public function listCategories(?string $language = null, int $depth = 3): array
    {
        $language ??= $this->configHelper->getDefaultLanguageCode();

        $logContext = $this->buildLogContext([
            'language' => $language,
            'depth' => $depth,
        ], false); // Don't include org_id - categories API doesn't need it

        return $this->executeApiCall(
            'list categories',
            $logContext,
            fn() => $this->getCategoriesApi()->getCategoriesV1WithHttpInfo(
                $language,
                null, // category_id filter (null = all root categories)
                $depth
            )
        );
    }

    /**
     * Get a specific category by ID
     *
     * @param string $categoryId Category ID (UUID)
     * @param string|null $language Language code (e.g., 'pl', 'en'). Default: from config
     * @param int $depth Maximum depth of children to fetch (default: 3)
     * @return array<string, mixed> API response with category data and HTTP info
     * @throws \Exception
     */
    public function getCategory(string $categoryId, ?string $language = null, int $depth = 3): array
    {
        $language ??= $this->configHelper->getDefaultLanguageCode();

        $logContext = $this->buildLogContext([
            'category_id' => $categoryId,
            'language' => $language,
            'depth' => $depth,
        ], false);

        return $this->executeApiCall(
            'get category',
            $logContext,
            fn() => $this->getCategoriesApi()->getCategoriesByIdV1WithHttpInfo(
                $categoryId,
                $language,
                $depth
            )
        );
    }

    // ========================================================================
    // LEGACY METHOD ALIASES (for backward compatibility during migration)
    // ========================================================================


    /**
     * Convert SDK model objects to arrays recursively
     *
     * @param mixed $data SDK data (array of models or single value)
     * @return array Converted array data
     */
    public function convertToArrays($data): array
    {
        if (!is_array($data)) {
            return [];
        }

        $result = [];
        foreach ($data as $item) {
            if (is_object($item)) {
                // Use JSON serialization to convert SDK model to array
                $json = json_encode($item);
                $array = json_decode($json, true) ?? [];
                // Recursively convert children if present
                if (isset($array['children']) && is_array($array['children'])) {
                    $array['children'] = $this->convertToArrays($array['children']);
                }
                $result[] = $array;
            } elseif (is_array($item)) {
                // Already an array, but may have object children
                if (isset($item['children']) && is_array($item['children'])) {
                    $item['children'] = $this->convertToArrays($item['children']);
                }
                $result[] = $item;
            }
        }

        return $result;
    }
}
