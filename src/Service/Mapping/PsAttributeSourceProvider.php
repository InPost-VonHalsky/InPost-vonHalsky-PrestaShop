<?php
/**
 * PsAttributeSourceProvider
 *
 * Read side of the PrestaShop catalogue for attribute mapping: the features and
 * attribute groups a merchant can map an InPost attribute to, their values, and
 * the values a given product (or combination) has.
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Service\Mapping;

use AttributeGroup;
use Combination;
use Db;
use Feature;
use FeatureValue;
use Inpost\VonHalsky\Helper\Config;

class PsAttributeSourceProvider
{
    private Config $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * All mappable sources, for the mapping dialog
     *
     * @return array{features: array<int,array{id:int,name:string}>, attribute_groups: array<int,array{id:int,name:string}>, product_fields: array<int,array{id:int,name:string}>}
     */
    public function getSources(): array
    {
        $idLang = $this->config->getDefaultLanguageId();

        $features = [];
        if (Feature::isFeatureActive()) {
            foreach (Feature::getFeatures($idLang) ?: [] as $row) {
                $features[] = ['id' => (int) $row['id_feature'], 'name' => (string) $row['name']];
            }
        }

        $groups = [];
        if (Combination::isFeatureActive()) {
            foreach (AttributeGroup::getAttributesGroups($idLang) ?: [] as $row) {
                $groups[] = ['id' => (int) $row['id_attribute_group'], 'name' => (string) $row['name']];
            }
        }

        $byName = static function (array $a, array $b): int {
            return strcasecmp($a['name'], $b['name']);
        };
        usort($features, $byName);
        usort($groups, $byName);

        return ['features' => $features, 'attribute_groups' => $groups, 'product_fields' => $this->getProductFieldSources()];
    }

    /**
     * Built-in product fields a category attribute can take its value from
     * (reference, MPN, EAN, weight...), in ProductField order
     *
     * @return array<int,array{id:int,name:string}>
     */
    public function getProductFieldSources(): array
    {
        $translator = \Context::getContext()->getTranslator();
        $fields = [];
        foreach (ProductField::all() as $id => $field) {
            if ($field['attribute']) {
                $fields[] = ['id' => $id, 'name' => $translator->trans($field['label'], [], 'Modules.Inpostvonhalsky.Admin')];
            }
        }

        return $fields;
    }

    /**
     * Predefined values of a source (custom feature values are product-specific
     * and not listed - they are matched by name when an offer is built)
     *
     * @return array<int,array{id:int,label:string}>
     */
    public function getSourceValues(string $type, int $id): array
    {
        $idLang = $this->config->getDefaultLanguageId();
        $values = [];

        if ($type === AttributeSource::FEATURE) {
            foreach (FeatureValue::getFeatureValuesWithLang($idLang, $id, false) ?: [] as $row) {
                $values[] = ['id' => (int) $row['id_feature_value'], 'label' => (string) $row['value']];
            }
        } elseif ($type === AttributeSource::ATTRIBUTE_GROUP) {
            foreach (AttributeGroup::getAttributes($idLang, $id) ?: [] as $row) {
                $values[] = ['id' => (int) $row['id_attribute'], 'label' => (string) $row['name']];
            }
        }

        return $values;
    }

    /**
     * Source whose name equals the InPost attribute name (case/space-insensitive).
     * Features first: for a single product a fixed parameter is the usual fit; then
     * attribute groups; then the built-in product field the attribute is usually
     * filled from ("Kod producenta" -> MPN).
     *
     * @return array{type:string,id:int,name:string}|null
     */
    public function suggestSource(string $inpostAttributeName, ?array $sources = null): ?array
    {
        $sources = $sources ?? $this->getSources();
        $needle = DictionaryValueMatcher::normalize($inpostAttributeName);

        foreach ([AttributeSource::FEATURE => 'features', AttributeSource::ATTRIBUTE_GROUP => 'attribute_groups'] as $type => $key) {
            foreach ($sources[$key] ?? [] as $source) {
                if (DictionaryValueMatcher::normalize($source['name']) === $needle) {
                    return ['type' => $type, 'id' => $source['id'], 'name' => $source['name']];
                }
            }
        }

        $names = array_column($sources['product_fields'] ?? $this->getProductFieldSources(), 'name', 'id');
        foreach (ProductField::all() as $id => $field) {
            if (!$field['attribute'] || !isset($names[$id])) {
                continue;
            }
            foreach ($field['aliases'] as $alias) {
                if (DictionaryValueMatcher::normalize($alias) === $needle) {
                    return ['type' => AttributeSource::PRODUCT_FIELD, 'id' => $id, 'name' => $names[$id]];
                }
            }
        }

        return null;
    }

    /**
     * Values of one product feature (several values per feature are allowed in PS 8).
     * Plain query instead of Product::getFeaturesStatic(): that one is cached for the
     * request and would return stale data inside a product-save hook.
     *
     * @return array<int,array{id:int,label:string}>
     */
    public function getProductFeatureValues(int $idProduct, int $idFeature): array
    {
        if (!Feature::isFeatureActive()) {
            return [];
        }

        $rows = Db::getInstance()->executeS(
            'SELECT fp.id_feature_value, fvl.value
            FROM `' . _DB_PREFIX_ . 'feature_product` fp
            LEFT JOIN `' . _DB_PREFIX_ . 'feature_value_lang` fvl
                ON fvl.id_feature_value = fp.id_feature_value AND fvl.id_lang = ' . (int) $this->config->getDefaultLanguageId() . '
            WHERE fp.id_product = ' . (int) $idProduct . ' AND fp.id_feature = ' . (int) $idFeature . '
            ORDER BY fp.id_feature_value'
        ) ?: [];

        $values = [];
        foreach ($rows as $row) {
            $label = trim((string) $row['value']);
            if ($label !== '') {
                $values[] = ['id' => (int) $row['id_feature_value'], 'label' => $label];
            }
        }

        return $values;
    }

    /**
     * Value of an attribute group in one combination
     *
     * @return array{id:int,label:string}|null
     */
    public function getCombinationAttributeValue(int $idProductAttribute, int $idAttributeGroup): ?array
    {
        if ($idProductAttribute <= 0 || !Combination::isFeatureActive()) {
            return null;
        }

        $row = Db::getInstance()->getRow(
            'SELECT a.id_attribute, al.name
            FROM `' . _DB_PREFIX_ . 'product_attribute_combination` pac
            INNER JOIN `' . _DB_PREFIX_ . 'attribute` a ON a.id_attribute = pac.id_attribute
            LEFT JOIN `' . _DB_PREFIX_ . 'attribute_lang` al
                ON al.id_attribute = a.id_attribute AND al.id_lang = ' . (int) $this->config->getDefaultLanguageId() . '
            WHERE pac.id_product_attribute = ' . (int) $idProductAttribute . '
              AND a.id_attribute_group = ' . (int) $idAttributeGroup
        );

        if (!$row || trim((string) $row['name']) === '') {
            return null;
        }

        return ['id' => (int) $row['id_attribute'], 'label' => trim((string) $row['name'])];
    }

    /**
     * Combination ids of a product (at most $limit)
     *
     * @return int[]
     */
    public function getCombinationIds(int $idProduct, int $limit = 0): array
    {
        $rows = Db::getInstance()->executeS(
            'SELECT id_product_attribute FROM `' . _DB_PREFIX_ . 'product_attribute`
            WHERE id_product = ' . (int) $idProduct . '
            ORDER BY id_product_attribute' . ($limit > 0 ? ' LIMIT ' . (int) $limit : '')
        ) ?: [];

        return array_map('intval', array_column($rows, 'id_product_attribute'));
    }
}
