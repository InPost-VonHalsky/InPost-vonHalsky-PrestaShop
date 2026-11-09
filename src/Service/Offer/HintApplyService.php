<?php
/**
 * HintApplyService
 *
 * Shared logic for applying a normalized product hint (GOKINT-927) onto an
 * OfferProduct entity. Used both by the edit-mode apply flow
 * (OfferHintController::applyAction) and the create-mode prefill
 * (OfferController::createAction) so the two paths stay consistent.
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Service\Offer;

use Doctrine\ORM\EntityManagerInterface;
use Inpost\VonHalsky\Entity\Attribute;
use Inpost\VonHalsky\Entity\Offer;
use Inpost\VonHalsky\Entity\OfferAttributes;
use Inpost\VonHalsky\Entity\OfferProduct;

class HintApplyService
{
    private EntityManagerInterface $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    /**
     * Put mapped attributes on the offer: new ones are added, existing ones
     * (same InPost attribute UUID) are only replaced when $overwrite.
     *
     * @param Offer $offer Offer whose product has a category mapping
     * @param array<int|string,array<string,mixed>> $mapped Entries from HintAttributeMapper::map()
     *        or ProductAttributeValueResolver::resolve() (uses 'values' when present)
     * @param bool $overwrite Replace values of attributes the offer already has
     * @param string $source OfferAttributes::SOURCE_* recorded on added/replaced values
     * @return int Number of attributes added or replaced
     */
    public function applyAttributes(Offer $offer, array $mapped, bool $overwrite, string $source = OfferAttributes::SOURCE_HINT): int
    {
        $categoryMappingId = $offer->getProduct() !== null ? (int) $offer->getProduct()->getCategoryMappingId() : 0;
        if (!$categoryMappingId || empty($mapped)) {
            return 0;
        }

        // Index existing offer attributes by inpost_attribute_id (category UUID).
        $existingByUuid = [];
        foreach ($offer->getAttributes()->toArray() as $existing) {
            $existingByUuid[(string) $existing->getInpostAttributeId()] = $existing;
        }

        $count = 0;
        foreach ($mapped as $entry) {
            $uuid = (string) $entry['inpost_attribute_id'];
            $values = !empty($entry['values']) ? array_values($entry['values']) : [$entry['value']];
            $jsonValue = (string) json_encode($values);

            if (isset($existingByUuid[$uuid])) {
                if ($overwrite) {
                    $existingByUuid[$uuid]->setValue($jsonValue);
                    $existingByUuid[$uuid]->setValueSource($source);
                    $count++;
                }
                continue;
            }

            $offerAttr = new OfferAttributes();
            $offerAttr->setOffer($offer);
            $offerAttr->setAttribute($this->entityManager->getReference(Attribute::class, $entry['attribute_id']));
            $offerAttr->setValue($jsonValue);
            $offerAttr->setValueSource($source);
            $offerAttr->setInpostAttributeId($uuid);
            $offerAttr->setCategoryMappingId((string) $categoryMappingId);
            $offer->addAttribute($offerAttr);
            $existingByUuid[$uuid] = $offerAttr;
            $count++;
        }

        return $count;
    }

    /**
     * Fill product fields from a hint. Only fills empty fields unless $overwrite.
     *
     * @param OfferProduct $product Target offer product
     * @param array<string,mixed> $hint Normalized hint (ProductHintService::mapProductHint)
     * @param bool $overwrite Overwrite non-empty fields when true
     * @return string[] Names of the fields that were set
     */
    public function applyProductFields(OfferProduct $product, array $hint, bool $overwrite): array
    {
        $applied = [];
        $map = [
            'name' => ['getName', 'setName'],
            'description' => ['getDescription', 'setDescription'],
            'brand' => ['getBrand', 'setBrand'],
            'model' => ['getModel', 'setModel'],
            'super_model' => ['getSuperModel', 'setSuperModel'],
            'manufacturer_product_number' => ['getManufacturerProductNumber', 'setManufacturerProductNumber'],
            'ean' => ['getEan', 'setEan'],
        ];

        foreach ($map as $key => [$getter, $setter]) {
            $value = $hint[$key] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            $current = trim((string) $product->$getter());
            if ($current !== '' && !$overwrite) {
                continue;
            }
            $product->$setter((string) $value);
            $applied[] = $key;
        }

        // Dimensions (integers)
        $dimension = $hint['dimension'] ?? [];
        $dimMap = [
            'width' => ['getDimensionWidth', 'setDimensionWidth'],
            'height' => ['getDimensionHeight', 'setDimensionHeight'],
            'length' => ['getDimensionLength', 'setDimensionLength'],
            'weight' => ['getDimensionWeight', 'setDimensionWeight'],
        ];
        foreach ($dimMap as $key => [$getter, $setter]) {
            $value = $dimension[$key] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            $current = $product->$getter();
            if (!empty($current) && !$overwrite) {
                continue;
            }
            $product->$setter((int) $value);
            $applied[] = 'dimension_' . $key;
        }

        return $applied;
    }
}
