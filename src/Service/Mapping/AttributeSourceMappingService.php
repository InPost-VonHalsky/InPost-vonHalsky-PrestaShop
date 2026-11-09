<?php
/**
 * AttributeSourceMappingService
 *
 * Back end of the "PrestaShop source" dialog of Category mapping: which
 * feature / attribute group an InPost attribute takes its value from and, for
 * dictionary attributes, which InPost option each PrestaShop value becomes.
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Service\Mapping;

use Inpost\VonHalsky\Helper\Config;
use Inpost\VonHalsky\Service\Mapping\Suggest\AttributeSuggester;

class AttributeSourceMappingService
{
    public const VALUE_MAPPED = 'mapped';
    public const VALUE_AUTO = 'auto';
    public const VALUE_UNMATCHED = 'unmatched';
    public const VALUE_INVALID = 'invalid';

    private AttributeMappingService $attributeMappingService;
    private PsAttributeSourceProvider $sourceProvider;
    private Config $config;
    private AttributeSuggester $suggester;

    public function __construct(
        AttributeMappingService $attributeMappingService,
        PsAttributeSourceProvider $sourceProvider,
        Config $config,
        AttributeSuggester $suggester
    ) {
        $this->attributeMappingService = $attributeMappingService;
        $this->sourceProvider = $sourceProvider;
        $this->config = $config;
        $this->suggester = $suggester;
    }

    /**
     * Everything the dialog shows for one category attribute. $sourceType /
     * $sourceId preview another source than the saved one (select changed).
     *
     * @return array<string,mixed>|null Null when the category attribute does not exist
     */
    public function getDialogData(int $categoryAttributeId, ?string $sourceType = null, ?int $sourceId = null): ?array
    {
        $definition = $this->findDefinition($categoryAttributeId);
        if ($definition === null) {
            return null;
        }

        $sources = $this->sourceProvider->getSources();
        $suggestion = null;
        if ($definition['ps_source_type'] === null) {
            $suggestion = $this->sourceProvider->suggestSource((string) $definition['label'], $sources);
        }

        // Source shown: explicitly requested > saved > suggested
        if (!AttributeSource::isValid($sourceType) || !$sourceId) {
            $sourceType = $definition['ps_source_type'] ?? ($suggestion['type'] ?? null);
            $sourceId = $definition['ps_source_id'] ?? ($suggestion['id'] ?? null);
        }

        return [
            'attribute' => [
                'category_attribute_id' => $categoryAttributeId,
                'id' => $definition['id'],
                'code' => $definition['code'],
                'label' => $definition['label'],
                'type' => $definition['type'],
                'required' => $definition['required'],
                'multiple' => $definition['multiple'],
                'description' => $definition['description'],
                'options' => $definition['dictionary_values'],
            ],
            'sources' => $sources,
            'saved' => [
                'type' => $definition['ps_source_type'],
                'id' => $definition['ps_source_id'],
            ],
            'suggestion' => $suggestion,
            'suggestions' => $this->fuzzySuggestions($definition, $sources),
            'selected' => ['type' => $sourceType, 'id' => $sourceId ? (int) $sourceId : null],
            'values' => $sourceType && $sourceId ? $this->valueRows($definition, $sourceType, (int) $sourceId) : [],
            'same_named_unmapped' => $this->attributeMappingService->countSameNamedUnmapped($categoryAttributeId),
        ];
    }

    /**
     * Save the source (null type clears it), the dictionary value mapping and
     * optionally the same source for same-named attributes of other categories.
     *
     * @param array<int,string|null> $valueCodes ps_value_id => option code ('' = match by name)
     * @return array{saved:bool, applied_to_others:int}
     */
    public function save(int $categoryAttributeId, ?string $sourceType, ?int $sourceId, array $valueCodes, bool $applyToSameNamed): array
    {
        $definition = $this->findDefinition($categoryAttributeId);
        if ($definition === null) {
            return ['saved' => false, 'applied_to_others' => 0];
        }

        if (!AttributeSource::isValid($sourceType) || !$sourceId) {
            $sourceType = null;
            $sourceId = null;
        }

        $saved = $this->attributeMappingService->updateCategoryAttributeSource($categoryAttributeId, $sourceType, $sourceId);

        if ($saved && $sourceType !== null && $definition['type'] === 'DICTIONARY') {
            // Only real options; anything else falls back to matching by name
            $codes = [];
            foreach ($valueCodes as $psValueId => $code) {
                $code = (string) $code;
                $codes[(int) $psValueId] = DictionaryValueMatcher::hasCode($definition['dictionary_values'], $code) ? $code : '';
            }
            $this->attributeMappingService->saveValueMap((int) $definition['id'], $sourceType, $codes);
        }

        $applied = 0;
        if ($saved && $sourceType !== null && $applyToSameNamed) {
            $applied = $this->attributeMappingService->applySourceToSameNamedAttributes($categoryAttributeId, $sourceType, (int) $sourceId);
        }

        return ['saved' => $saved, 'applied_to_others' => $applied];
    }

    /**
     * Matched / total PrestaShop values of every mapped dictionary attribute,
     * for the attribute grid ("12/15 matched")
     *
     * @param array<int,array<string,mixed>> $attributes getAttributesForCategoryMappingId() entries
     * @return array<int,array{matched:int,total:int}> Keyed by category_attribute_id
     */
    public function getValueCoverage(array $attributes): array
    {
        $coverage = [];
        foreach ($attributes as $attribute) {
            if ($attribute['type'] !== 'DICTIONARY' || !AttributeSource::isValid($attribute['ps_source_type'] ?? null)) {
                continue;
            }

            $rows = $this->valueRows($attribute, (string) $attribute['ps_source_type'], (int) $attribute['ps_source_id']);
            $matched = count(array_filter($rows, static function (array $row): bool {
                return $row['status'] === self::VALUE_MAPPED || $row['status'] === self::VALUE_AUTO;
            }));
            $coverage[(int) $attribute['category_attribute_id']] = ['matched' => $matched, 'total' => count($rows)];
        }

        return $coverage;
    }

    /**
     * PrestaShop values of a source with what each becomes in InPost:
     * dictionary option (mapped by the merchant or matched by name) or the
     * converted value for other types
     *
     * @return array<int,array<string,mixed>>
     */
    private function valueRows(array $definition, string $sourceType, int $sourceId): array
    {
        $isDictionary = $definition['type'] === 'DICTIONARY';
        $options = $definition['dictionary_values'] ?? [];
        $valueMap = $isDictionary ? $this->attributeMappingService->getValueMap((int) $definition['id'], $sourceType) : [];

        $rows = [];
        foreach ($this->sourceProvider->getSourceValues($sourceType, $sourceId) as $value) {
            $row = ['id' => $value['id'], 'label' => $value['label'], 'code' => null, 'auto_code' => null, 'preview' => null];

            if ($isDictionary) {
                $mapped = $valueMap[$value['id']] ?? null;
                $row['auto_code'] = DictionaryValueMatcher::match($options, $value['label']);
                if ($mapped !== null && DictionaryValueMatcher::hasCode($options, $mapped)) {
                    $row['code'] = $mapped;
                    $row['status'] = self::VALUE_MAPPED;
                } else {
                    $row['status'] = $row['auto_code'] !== null ? self::VALUE_AUTO : self::VALUE_UNMATCHED;
                }
            } else {
                $converted = AttributeValueConverter::convert($definition, $value['label']);
                $row['preview'] = $converted['value'];
                $row['status'] = $converted['status'] === AttributeValueConverter::STATUS_OK ? self::VALUE_AUTO : self::VALUE_INVALID;
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Attribute definition (getAttributesForCategoryMappingId() shape) of a category attribute
     */
    private function findDefinition(int $categoryAttributeId): ?array
    {
        $row = $this->attributeMappingService->getCategoryAttributeById($categoryAttributeId);
        if (!$row) {
            return null;
        }

        $attributes = $this->attributeMappingService->getAttributesForCategoryMappingId(
            (int) $row['inpost_category_mapping_id'],
            (string) ($row['attribute_lang'] ?: $this->config->getDefaultLanguageCode())
        );
        foreach ($attributes as $attribute) {
            if ((int) $attribute['category_attribute_id'] === $categoryAttributeId) {
                return $attribute;
            }
        }

        return null;
    }

    /**
     * Ranked similar sources (mapping assistant matching) for the dialog picker
     *
     * @param array<string,mixed> $definition
     * @param array<string,array<int,array{id:int,name:string}>> $sources PsAttributeSourceProvider::getSources()
     * @return array<int,array{type:string,id:int,name:string,score:int,level:string}>
     */
    private function fuzzySuggestions(array $definition, array $sources): array
    {
        $isDictionary = $definition['type'] === 'DICTIONARY';
        $candidates = [];
        foreach (AttributeSource::LISTS as $type => $key) {
            foreach ($sources[$key] ?? [] as $source) {
                $candidates[] = [
                    'type' => $type,
                    'id' => (int) $source['id'],
                    'name' => (string) $source['name'],
                    // values only matter for the dictionary coverage part of the score
                    'values' => $isDictionary ? $this->sourceProvider->getSourceValues($type, (int) $source['id']) : [],
                ];
            }
        }
        $prepared = $this->suggester->prepareOptions($isDictionary ? $definition['dictionary_values'] : []);

        return array_map(static fn (array $s): array => [
            'type' => $s['type'], 'id' => $s['id'], 'name' => $s['name'], 'score' => $s['score'], 'level' => $s['level'],
        ], $this->suggester->suggestSources((string) $definition['label'], $isDictionary, $prepared, $candidates));
    }
}
