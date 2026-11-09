<?php
/**
 * HintAttributeMapper
 *
 * Maps /hints attribute values onto a category's attribute set (GOKINT-927).
 * Two-stage matching: by UUID, then (optional, config-gated) by normalized name,
 * because the InPost catalogue holds many identically-named attribute definitions
 * under different UUIDs and /hints often returns a UUID from a different catalogue
 * space. Dictionary values are resolved back to their option code.
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Service\Offer;

use Db;
use Inpost\VonHalsky\Service\Mapping\AttributeMappingService;
use Inpost\VonHalsky\Service\Mapping\DictionaryValueMatcher;
use Inpost\VonHalsky\Service\Util\ConfigurationService;
use Inpost\VonHalsky\Service\Util\LoggerService;

class HintAttributeMapper
{
    private AttributeMappingService $attributeMappingService;
    private ConfigurationService $configurationService;
    private LoggerService $logger;
    private Db $db;

    public function __construct(
        AttributeMappingService $attributeMappingService,
        ConfigurationService $configurationService,
        LoggerService $logger
    ) {
        $this->attributeMappingService = $attributeMappingService;
        $this->configurationService = $configurationService;
        $this->logger = $logger;
        $this->db = Db::getInstance();
    }

    /**
     * Map hint attributes to a category's attribute set.
     *
     * @param int $categoryMappingId Local inpost_category_mapping.entity_id
     * @param array<int,array<string,mixed>> $hintAttributes Raw hint attributes (id/lang/values)
     * @param string $language
     * @return array<int,array<string,mixed>> Each: attribute_id, inpost_attribute_id (category UUID), label, type, value, matched_by
     */
    public function map(int $categoryMappingId, array $hintAttributes, string $language): array
    {
        if (empty($hintAttributes)) {
            return [];
        }

        $categoryAttributes = $this->attributeMappingService->getAttributesForCategoryMappingId($categoryMappingId, $language);
        if (empty($categoryAttributes)) {
            return [];
        }

        // Index category attributes by UUID and by normalized name.
        $byUuid = [];
        $byName = [];
        foreach ($categoryAttributes as $attr) {
            $byUuid[$attr['code']] = $attr;
            $byName[$this->normalize($attr['label'])] = $attr;
        }

        $fallbackEnabled = $this->configurationService->getBool(
            ConfigurationService::HINT_NAME_FALLBACK_ENABLED,
            true
        );

        // Stage 2 name resolution runs only for the hint UUIDs that missed
        // stage 1, and only when the fallback is enabled.
        $unmatchedUuids = [];
        $pending = [];
        foreach ($hintAttributes as $hintAttr) {
            $hintUuid = (string) ($hintAttr['id'] ?? '');
            $values = $hintAttr['values'] ?? [];
            $value = is_array($values) ? ($values[0] ?? null) : $values;
            if ($hintUuid === '' || $value === null || $value === '') {
                continue;
            }

            if (isset($byUuid[$hintUuid])) {
                $pending[] = ['attr' => $byUuid[$hintUuid], 'value' => $value, 'matched_by' => 'uuid'];
            } elseif ($fallbackEnabled) {
                $unmatchedUuids[$hintUuid] = $value;
            }
        }

        if ($fallbackEnabled && !empty($unmatchedUuids)) {
            $nameByUuid = $this->resolveNamesForUuids(array_keys($unmatchedUuids), $language);
            foreach ($unmatchedUuids as $uuid => $value) {
                $name = $nameByUuid[$uuid] ?? null;
                if ($name === null) {
                    continue;
                }
                $normalized = $this->normalize($name);
                if (isset($byName[$normalized])) {
                    $pending[] = ['attr' => $byName[$normalized], 'value' => $value, 'matched_by' => 'name'];
                }
            }
        }

        $result = [];
        foreach ($pending as $entry) {
            $attr = $entry['attr'];
            $value = (string) $entry['value'];

            if ($attr['type'] === 'DICTIONARY') {
                $optionCode = $this->resolveDictionaryOption($attr, $value);
                if ($optionCode === null) {
                    $this->logger->info('Hint dictionary value not matched; skipping attribute', [
                        'attribute' => $attr['label'],
                        'value' => $value,
                    ]);
                    continue;
                }
                $value = $optionCode;
            } elseif ($attr['type'] === 'NUMERIC' || $attr['type'] === 'NUMERIC_FLOAT') {
                // Numeric fields reject non-numeric values (e.g. a name-fallback match
                // that returned free text like "1 sztuka") — skip rather than feed the
                // form transformer a value it cannot cast.
                if (!is_numeric($value)) {
                    $this->logger->info('Hint numeric value is not numeric; skipping attribute', [
                        'attribute' => $attr['label'],
                        'type' => $attr['type'],
                        'value' => $value,
                    ]);
                    continue;
                }
            }

            $result[] = [
                'attribute_id' => (int) $attr['id'],
                'inpost_attribute_id' => $attr['code'], // ALWAYS the category's UUID
                'label' => $attr['label'],
                'type' => $attr['type'],
                'value' => $value,
                'matched_by' => $entry['matched_by'],
            ];
        }

        return $result;
    }

    /**
     * Reverse dictionary lookup: match a raw hint value against an attribute's
     * (active) dictionary options and return the option code.
     *
     * @param array<string,mixed> $attr
     */
    private function resolveDictionaryOption(array $attr, string $value): ?string
    {
        return DictionaryValueMatcher::match($attr['dictionary_values'] ?? [], $value);
    }

    /**
     * Resolve InPost attribute UUIDs to their local names. Deliberately does NOT
     * filter on is_active — an inactive local snapshot still supplies a usable
     * name for the fallback match.
     *
     * @param string[] $uuids
     * @return array<string,string> uuid => name
     */
    private function resolveNamesForUuids(array $uuids, string $language): array
    {
        if (empty($uuids)) {
            return [];
        }

        $escaped = array_map(static function (string $u): string {
            return "'" . pSQL($u) . "'";
        }, $uuids);

        $sql = 'SELECT inpost_attribute_id, name FROM `' . _DB_PREFIX_ . 'inpost_attribute` '
            . 'WHERE inpost_attribute_id IN (' . implode(',', $escaped) . ") "
            . "AND lang = '" . pSQL($language) . "'";

        $rows = $this->db->executeS($sql) ?: [];

        $map = [];
        foreach ($rows as $row) {
            $map[$row['inpost_attribute_id']] = $row['name'];
        }
        return $map;
    }

    private function normalize(string $value): string
    {
        return DictionaryValueMatcher::normalize($value);
    }
}
