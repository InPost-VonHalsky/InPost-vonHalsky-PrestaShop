<?php
/**
 * BulkAttributeEditService (GOKINT-1102)
 *
 * Changes several InPost category attributes on many offers at once. Every
 * attribute gets its own operation: set a value, clear it, restore it from the
 * PrestaShop mapping, or map it to a PrestaShop source (feature, attribute
 * group, product field).
 *
 * Offers may come from different InPost categories. InPost defines the same
 * attribute name (e.g. "Grubość blatu") separately in every category, under
 * its own UUID. So the attribute is picked by its normalized name and type,
 * and every offer gets the definition of its own category. Dictionary values
 * are matched by label in each category.
 *
 * Set and clear store the value as SOURCE_MANUAL, so the PrestaShop sync no
 * longer overwrites it. A cleared value is kept as an empty list rather than
 * deleted, because the sync would fill a missing value again. Restore removes
 * the value and refills it from the PrestaShop mapping. Map saves the source in
 * the category mapping of every category concerned (so it also serves the
 * other and future offers of those categories) and puts the value from the
 * product on the offers as SOURCE_PS.
 *
 * Offers that are not drafts are flagged for the API sync; the offer_sync
 * cron sends them, just as after a save in the offer form.
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
use Inpost\VonHalsky\Helper\Config;
use Inpost\VonHalsky\Repository\OfferRepository;
use Inpost\VonHalsky\Service\Mapping\AttributeMappingService;
use Inpost\VonHalsky\Service\Mapping\AttributeSource;
use Inpost\VonHalsky\Service\Mapping\AttributeValueConverter;
use Inpost\VonHalsky\Service\Mapping\CategoryMappingService;
use Inpost\VonHalsky\Service\Mapping\DictionaryValueMatcher;
use Inpost\VonHalsky\Service\Mapping\PsAttributeSourceProvider;
use Inpost\VonHalsky\Service\Sync\ProductOfferSyncService;
use Inpost\VonHalsky\Service\Util\LoggerService;
use Inpost\VonHalsky\Service\Util\ShopLog;

class BulkAttributeEditService
{
    public const OP_SET = 'set';
    public const OP_CLEAR = 'clear';
    public const OP_RESTORE = 'restore';
    public const OP_MAP = 'map';
    public const OPERATIONS = [self::OP_SET, self::OP_CLEAR, self::OP_RESTORE, self::OP_MAP];

    /** Offers in one request */
    public const MAX_OFFERS = 200;

    /** Attributes changed in one request */
    public const MAX_CHANGES = 20;

    public const RESULT_CHANGE = 'change';
    public const RESULT_SAME = 'same';
    public const RESULT_SKIP = 'skip';

    /** Keys of AttributeSource types in PsAttributeSourceProvider::getSources() */
    private const SOURCE_LISTS = [
        AttributeSource::FEATURE => 'features',
        AttributeSource::ATTRIBUTE_GROUP => 'attribute_groups',
        AttributeSource::PRODUCT_FIELD => 'product_fields',
    ];

    private EntityManagerInterface $entityManager;
    private OfferRepository $offerRepository;
    private OfferCategoryChangeService $categoryChange;
    private AttributeMappingService $attributeMappingService;
    private CategoryMappingService $categoryMappingService;
    private ProductAttributeValueResolver $attributeValueResolver;
    private ProductOfferSyncService $productOfferSync;
    private PsAttributeSourceProvider $sourceProvider;
    private Config $config;
    private LoggerService $logger;

    /** @var array<int,array<int,array<string,mixed>>> Attribute definitions by category mapping id */
    private array $definitions = [];

    /** @var array<int,string> Category names by category mapping id */
    private array $categoryNames = [];

    /** @var array<string,mixed>|null PrestaShop sources (PsAttributeSourceProvider::getSources()) */
    private ?array $sources = null;

    public function __construct(
        EntityManagerInterface $entityManager,
        OfferRepository $offerRepository,
        OfferCategoryChangeService $categoryChange,
        AttributeMappingService $attributeMappingService,
        CategoryMappingService $categoryMappingService,
        ProductAttributeValueResolver $attributeValueResolver,
        ProductOfferSyncService $productOfferSync,
        PsAttributeSourceProvider $sourceProvider,
        Config $config,
        LoggerService $logger
    ) {
        $this->entityManager = $entityManager;
        $this->offerRepository = $offerRepository;
        $this->categoryChange = $categoryChange;
        $this->attributeMappingService = $attributeMappingService;
        $this->categoryMappingService = $categoryMappingService;
        $this->attributeValueResolver = $attributeValueResolver;
        $this->productOfferSync = $productOfferSync;
        $this->sourceProvider = $sourceProvider;
        $this->config = $config;
        $this->logger = $logger;
    }

    /**
     * Attributes of the categories of the offers, grouped by name and type,
     * with the PrestaShop sources they can be mapped to
     *
     * @param int[] $offerIds
     * @return array{offers:int, attributes:array<int,array<string,mixed>>, sources:array<string,mixed>}
     */
    public function collectAttributes(array $offerIds): array
    {
        $offers = $this->loadOffers($offerIds);

        $groups = [];
        foreach ($offers as $offer) {
            $categoryId = $this->categoryChange->getCurrentCategoryMappingId($offer);
            if (!$categoryId) {
                continue;
            }
            foreach ($this->getDefinitions($categoryId) as $definition) {
                $key = self::key($definition);
                if (!isset($groups[$key])) {
                    $groups[$key] = [
                        'key' => $key,
                        'name' => (string) $definition['label'],
                        'type' => (string) $definition['type'],
                        'multiple' => false,
                        'required' => false,
                        'offers' => 0,
                        'categories' => [],
                        'options' => [],
                        'source_values' => [],
                    ];
                }
                $group = &$groups[$key];
                $group['offers']++;
                $group['categories'][$categoryId] = true;
                $group['multiple'] = $group['multiple'] || !empty($definition['multiple']);
                $group['required'] = $group['required'] || !empty($definition['required']);
                foreach ($definition['dictionary_values'] ?? [] as $option) {
                    $label = (string) $option['label'];
                    $group['options'][DictionaryValueMatcher::normalize($label)] = $label;
                }
                $group['source_values'][$categoryId] = self::sourceValue($definition['ps_source_type'] ?? null, $definition['ps_source_id'] ?? null);
                unset($group);
            }
        }

        $sources = $this->getSources();
        $attributes = [];
        foreach ($groups as $group) {
            $options = array_values($group['options']);
            natcasesort($options);
            $group['options'] = array_values($options);
            $group['categories'] = count($group['categories']);

            // Source shown in the dialog: the one all categories share, else a suggestion by name
            $saved = array_unique($group['source_values']);
            $group['source'] = count($saved) === 1 ? reset($saved) : null;
            if ($group['source'] === null || $group['source'] === '') {
                $suggestion = $this->sourceProvider->suggestSource($group['name'], $sources);
                $group['source'] = $suggestion !== null ? self::sourceValue($suggestion['type'], $suggestion['id']) : null;
            }
            unset($group['source_values']);
            $attributes[] = $group;
        }
        usort($attributes, static function (array $a, array $b): int {
            return [$b['offers'], $a['name']] <=> [$a['offers'], $b['name']];
        });

        return ['offers' => count($offers), 'attributes' => $attributes, 'sources' => $sources];
    }

    /**
     * What the changes would do to every offer and category mapping. Saves nothing.
     *
     * @param int[] $offerIds
     * @param array<int,array<string,mixed>> $changes [{key, operation, values[], source: "type:id"}]
     * @return array{rows:array<int,array<string,mixed>>, mappings:array<int,array<string,mixed>>}
     */
    public function plan(array $offerIds, array $changes): array
    {
        $plan = $this->buildPlan($offerIds, $changes);

        return [
            'rows' => array_map([self::class, 'publicRow'], $plan['rows']),
            'mappings' => array_map([self::class, 'publicRow'], $plan['mappings']),
        ];
    }

    /**
     * Make the changes of the plan with the result "change"
     *
     * @param int[] $offerIds
     * @param array<int,array<string,mixed>> $changes
     * @return array<string,mixed> Counts: changed (values), offers, same, skipped, to_send, mappings
     */
    public function apply(array $offerIds, array $changes): array
    {
        $plan = $this->buildPlan($offerIds, $changes);
        $isChange = static function (array $row): bool {
            return $row['result'] === self::RESULT_CHANGE;
        };
        $valueChanges = array_filter($plan['rows'], $isChange);
        $mappingChanges = array_filter($plan['mappings'], $isChange);

        // Category mappings first (plain Db, as in the category mapping dialog)
        foreach ($mappingChanges as $mapping) {
            $this->attributeMappingService->updateCategoryAttributeSource(
                (int) $mapping['_category_attribute_id'],
                (string) $mapping['_source_type'],
                (int) $mapping['_source_id']
            );
        }

        $offers = [];
        if (!empty($valueChanges)) {
            $this->entityManager->transactional(function () use ($valueChanges, &$offers) {
                foreach ($valueChanges as $row) {
                    /** @var Offer $offer */
                    $offer = $row['_offer'];
                    switch ($row['_operation']) {
                        case self::OP_RESTORE:
                            $this->removeValue($offer, (string) $row['_definition']['code']);
                            break;
                        case self::OP_MAP:
                            $this->writeValue($offer, $row['_definition'], (int) $row['_category'], $row['_values'], OfferAttributes::SOURCE_PS);
                            break;
                        default:
                            $this->writeValue($offer, $row['_definition'], (int) $row['_category'], $row['_values'], OfferAttributes::SOURCE_MANUAL);
                    }
                    $offer->setUpdatedAt(new \DateTime());
                    $offers[(int) $offer->getId()] = $offer;
                }
                foreach ($offers as $offer) {
                    if (!$offer->getIsDraft()) {
                        $offer->setRequireApiSync(true);
                    }
                }
            });

            // Restored values come back from the PrestaShop mapping right away
            foreach ($valueChanges as $row) {
                if ($row['_operation'] === self::OP_RESTORE) {
                    $this->productOfferSync->refreshOffer($row['_offer']);
                }
            }
        }

        $count = static function (array $rows, string $result): int {
            return count(array_filter($rows, static function (array $row) use ($result): bool {
                return $row['result'] === $result;
            }));
        };
        $summary = [
            'changed' => count($valueChanges),
            'offers' => count($offers),
            'same' => $count($plan['rows'], self::RESULT_SAME),
            'skipped' => $count($plan['rows'], self::RESULT_SKIP),
            'to_send' => count(array_filter($offers, static function (Offer $offer): bool {
                return !$offer->getIsDraft();
            })),
            'mappings' => count($mappingChanges),
        ];

        if ($summary['changed'] > 0 || $summary['mappings'] > 0) {
            $this->log($valueChanges, $mappingChanges, $summary);
        }

        return $summary;
    }

    /**
     * @param int[] $offerIds
     * @param array<int,array<string,mixed>> $changes
     * @return array{rows:array<int,array<string,mixed>>, mappings:array<int,array<string,mixed>>}
     */
    private function buildPlan(array $offerIds, array $changes): array
    {
        $changes = $this->normalizeChanges($changes);
        $offers = $this->loadOffers($offerIds);

        $rows = [];
        $mappings = [];
        foreach ($changes as $change) {
            $name = $this->attributeName($offers, $change['key']);
            foreach ($offerIds as $offerId) {
                $offer = $offers[(int) $offerId] ?? null;
                if ($offer === null) {
                    $rows[] = self::row((int) $offerId, $name) + self::skip('Offer not found');
                    continue;
                }
                $rows[] = $this->planOffer($offer, $name, $change);
            }
            if ($change['operation'] === self::OP_MAP) {
                $mappings = array_merge($mappings, $this->planMappings($offers, $name, $change));
            }
        }

        return ['rows' => $rows, 'mappings' => $mappings];
    }

    /**
     * Validate the changes: known operation, one change per attribute, a value
     * to set, an existing source to map
     *
     * @param array<int,array<string,mixed>> $changes
     * @return array<int,array{key:string, operation:string, values:string[], source_type:?string, source_id:?int}>
     */
    private function normalizeChanges(array $changes): array
    {
        if (empty($changes)) {
            throw new \InvalidArgumentException('Select at least one attribute.');
        }
        if (count($changes) > self::MAX_CHANGES) {
            throw new \InvalidArgumentException('Too many attributes selected.');
        }

        $normalized = [];
        foreach ($changes as $change) {
            $key = (string) ($change['key'] ?? '');
            $operation = (string) ($change['operation'] ?? '');
            if ($key === '' || !in_array($operation, self::OPERATIONS, true)) {
                throw new \InvalidArgumentException('Unknown operation.');
            }
            if (isset($normalized[$key])) {
                throw new \InvalidArgumentException('Each attribute can be changed only once at a time.');
            }

            $values = array_map('strval', (array) ($change['values'] ?? []));
            $values = array_values(array_unique(array_filter(array_map('trim', $values), static function (string $value): bool {
                return $value !== '';
            })));
            if ($operation === self::OP_SET && empty($values)) {
                throw new \InvalidArgumentException('Enter the value to set.');
            }

            $sourceType = null;
            $sourceId = null;
            if ($operation === self::OP_MAP) {
                [$sourceType, $sourceId] = array_pad(explode(':', (string) ($change['source'] ?? ''), 2), 2, '');
                $sourceId = (int) $sourceId;
                if ($this->getSourceName($sourceType, $sourceId) === null) {
                    throw new \InvalidArgumentException('Select the PrestaShop source to map.');
                }
            }

            $normalized[$key] = [
                'key' => $key,
                'operation' => $operation,
                'values' => $values,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
            ];
        }

        return array_values($normalized);
    }

    /**
     * @param array{key:string, operation:string, values:string[], source_type:?string, source_id:?int} $change
     * @return array<string,mixed>
     */
    private function planOffer(Offer $offer, string $name, array $change): array
    {
        $row = self::row((int) $offer->getId(), $name, $offer);

        if ($offer->getStatus() === Offer::STATUS_PENDING && !$this->categoryChange->hasInpostValidationErrors($offer)) {
            return $row + self::skip('The offer is being reviewed by InPost - it can be edited once the review ends.');
        }

        $categoryId = $this->categoryChange->getCurrentCategoryMappingId($offer);
        if (!$categoryId) {
            return $row + self::skip('The offer has no InPost category.');
        }
        $row['category'] = $this->getCategoryName($categoryId);

        $definition = $this->findDefinition($categoryId, $change['key']);
        if ($definition === null) {
            return $row + self::skip('The InPost category of this offer does not have this attribute.');
        }

        $stored = $this->findValue($offer, (string) $definition['code']);
        $current = $stored !== null ? self::decode($stored->getValue()) : [];
        $row['current'] = self::display($definition, $current);
        $row['current_source'] = $stored !== null ? $stored->getValueSource() : null;

        switch ($change['operation']) {
            case self::OP_SET:
                if (count($change['values']) > 1 && empty($definition['multiple'])) {
                    return $row + self::skip('This attribute takes only one value in the category of this offer.');
                }
                $values = [];
                foreach ($change['values'] as $rawValue) {
                    $converted = self::convert($definition, $rawValue);
                    if ($converted === null) {
                        return $row + self::skip(
                            $definition['type'] === 'DICTIONARY'
                                ? 'The value "%value%" is not on the list of this category.'
                                : 'The value "%value%" is not valid for this attribute.',
                            ['%value%' => $rawValue]
                        );
                    }
                    $values[] = $converted;
                }
                $values = array_values(array_unique($values));
                $same = $stored !== null && self::sameValues($current, $values);
                break;

            case self::OP_CLEAR:
                if (!empty($definition['required'])) {
                    return $row + self::skip('The attribute is required in the category of this offer - it cannot be cleared.');
                }
                $values = [];
                // An empty manual row is what keeps the sync from filling the value again
                $same = $stored !== null && empty($current) && $stored->getValueSource() === OfferAttributes::SOURCE_MANUAL;
                break;

            case self::OP_RESTORE:
                if (!AttributeSource::isValid($definition['ps_source_type'] ?? null) || empty($definition['ps_source_id'])) {
                    return $row + self::skip('No PrestaShop source is mapped for this attribute in the category of this offer.');
                }
                $values = $this->resolvePrestaShopValues($offer, $categoryId, $definition);
                // No value stored: the sync fills it from PrestaShop anyway
                $same = $stored === null || $stored->getValueSource() === OfferAttributes::SOURCE_PS;
                break;

            default: // OP_MAP
                $mapped = array_merge($definition, ['ps_source_type' => $change['source_type'], 'ps_source_id' => $change['source_id']]);
                $values = $this->resolvePrestaShopValues($offer, $categoryId, $mapped);
                if (empty($values)) {
                    return $row + self::skip('The product has no value in the selected PrestaShop source (or it is not on the list) - the offer value does not change.');
                }
                $same = $stored !== null && $stored->getValueSource() === OfferAttributes::SOURCE_PS && self::sameValues($current, $values);
                break;
        }

        $row['new'] = self::display($definition, $values);
        $row['result'] = $same ? self::RESULT_SAME : self::RESULT_CHANGE;
        $row['_offer'] = $offer;
        $row['_operation'] = $change['operation'];
        $row['_definition'] = $definition;
        $row['_category'] = $categoryId;
        $row['_values'] = $values;

        return $row;
    }

    /**
     * The source change of the attribute in every category of the offers that has it
     *
     * @param array<int,Offer> $offers
     * @param array{key:string, operation:string, values:string[], source_type:?string, source_id:?int} $change
     * @return array<int,array<string,mixed>>
     */
    private function planMappings(array $offers, string $name, array $change): array
    {
        $mappings = [];
        foreach ($offers as $offer) {
            $categoryId = $this->categoryChange->getCurrentCategoryMappingId($offer);
            if (!$categoryId || isset($mappings[$categoryId])) {
                continue;
            }
            $definition = $this->findDefinition($categoryId, $change['key']);
            if ($definition === null) {
                continue;
            }
            $savedType = $definition['ps_source_type'] ?? null;
            $savedId = isset($definition['ps_source_id']) ? (int) $definition['ps_source_id'] : null;
            $mappings[$categoryId] = [
                'attribute' => $name,
                'category' => $this->getCategoryName($categoryId),
                'from' => $savedType !== null ? (string) $this->getSourceName($savedType, (int) $savedId) : '',
                'from_type' => $savedType,
                'to' => (string) $this->getSourceName((string) $change['source_type'], (int) $change['source_id']),
                'to_type' => $change['source_type'],
                'result' => $savedType === $change['source_type'] && $savedId === $change['source_id'] ? self::RESULT_SAME : self::RESULT_CHANGE,
                '_category_attribute_id' => (int) $definition['category_attribute_id'],
                '_source_type' => $change['source_type'],
                '_source_id' => $change['source_id'],
            ];
        }

        return array_values($mappings);
    }

    /**
     * Strict check of the typed value, then the same conversion as for PrestaShop values.
     * Returns the stored value (dictionary option code), null when not valid.
     *
     * @param array<string,mixed> $definition
     */
    private static function convert(array $definition, string $rawValue): ?string
    {
        $number = str_replace(',', '.', $rawValue);
        if ($definition['type'] === 'NUMERIC' && !preg_match('/^-?\d+$/', $number)) {
            return null;
        }
        if ($definition['type'] === 'NUMERIC_FLOAT' && !is_numeric($number)) {
            return null;
        }

        $converted = AttributeValueConverter::convert($definition, $rawValue);

        return $converted['status'] === AttributeValueConverter::STATUS_OK ? (string) $converted['value'] : null;
    }

    /**
     * Value the PrestaShop source of the definition gives this offer (empty when the product has none)
     *
     * @param array<string,mixed> $definition
     * @return string[]
     */
    private function resolvePrestaShopValues(Offer $offer, int $categoryId, array $definition): array
    {
        $resolved = ProductAttributeValueResolver::applicable($this->attributeValueResolver->resolve(
            (int) $offer->getPrestashopOfferId(),
            (int) $offer->getPrestashopProductAttributeId(),
            $categoryId,
            $this->config->getDefaultLanguageCode(),
            [$definition]
        ));
        $entry = $resolved[(string) $definition['code']] ?? null;
        if ($entry === null) {
            return [];
        }

        return array_values(array_map('strval', !empty($entry['values']) ? $entry['values'] : [$entry['value']]));
    }

    /**
     * @param array<string,mixed> $definition
     * @param string[] $values
     */
    private function writeValue(Offer $offer, array $definition, int $categoryId, array $values, string $source): void
    {
        $json = (string) json_encode(array_values($values));
        $stored = $this->findValue($offer, (string) $definition['code']);
        if ($stored === null) {
            $stored = new OfferAttributes();
            $stored->setOffer($offer);
            $stored->setAttribute($this->entityManager->getReference(Attribute::class, (int) $definition['id']));
            $stored->setInpostAttributeId((string) $definition['code']);
            $stored->setCategoryMappingId((string) $categoryId);
            $offer->addAttribute($stored);
        }
        $stored->setValue($json);
        $stored->setValueSource($source);
    }

    private function removeValue(Offer $offer, string $uuid): void
    {
        foreach ($offer->getAttributes()->toArray() as $stored) {
            if ((string) $stored->getInpostAttributeId() === $uuid) {
                $offer->removeAttribute($stored);
                $this->entityManager->remove($stored);
            }
        }
    }

    private function findValue(Offer $offer, string $uuid): ?OfferAttributes
    {
        foreach ($offer->getAttributes() as $stored) {
            if ((string) $stored->getInpostAttributeId() === $uuid) {
                return $stored;
            }
        }

        return null;
    }

    /**
     * Offers of the shop by id
     *
     * @param int[] $offerIds
     * @return array<int,Offer>
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
     * @return array<int,array<string,mixed>>
     */
    private function getDefinitions(int $categoryId): array
    {
        if (!isset($this->definitions[$categoryId])) {
            $this->definitions[$categoryId] = $this->attributeMappingService->getAttributesForCategoryMappingId(
                $categoryId,
                $this->config->getDefaultLanguageCode()
            );
        }

        return $this->definitions[$categoryId];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function findDefinition(int $categoryId, string $key): ?array
    {
        foreach ($this->getDefinitions($categoryId) as $definition) {
            if (self::key($definition) === $key) {
                return $definition;
            }
        }

        return null;
    }

    /**
     * Name of the attribute as InPost spells it (the key is normalized)
     *
     * @param array<int,Offer> $offers
     */
    private function attributeName(array $offers, string $key): string
    {
        foreach ($offers as $offer) {
            $categoryId = $this->categoryChange->getCurrentCategoryMappingId($offer);
            $definition = $categoryId ? $this->findDefinition($categoryId, $key) : null;
            if ($definition !== null) {
                return (string) $definition['label'];
            }
        }

        return (string) strstr($key, '|', true);
    }

    private function getCategoryName(int $categoryId): string
    {
        if (!isset($this->categoryNames[$categoryId])) {
            $path = $this->categoryMappingService->getInpostCategoryPath($categoryId);
            $this->categoryNames[$categoryId] = (string) end($path);
        }

        return $this->categoryNames[$categoryId];
    }

    /**
     * @return array<string,mixed>
     */
    private function getSources(): array
    {
        if ($this->sources === null) {
            $this->sources = $this->sourceProvider->getSources();
        }

        return $this->sources;
    }

    /**
     * Name of an existing PrestaShop source, null when there is no such source
     */
    private function getSourceName(?string $type, int $id): ?string
    {
        $list = self::SOURCE_LISTS[(string) $type] ?? null;
        if ($list === null || $id <= 0) {
            return null;
        }
        foreach ($this->getSources()[$list] ?? [] as $source) {
            if ((int) $source['id'] === $id) {
                return (string) $source['name'];
            }
        }

        return null;
    }

    /**
     * @param array<int,array<string,mixed>> $valueChanges
     * @param array<int,array<string,mixed>> $mappingChanges
     * @param array<string,mixed> $summary
     */
    private function log(array $valueChanges, array $mappingChanges, array $summary): void
    {
        $byAttribute = [];
        foreach ($valueChanges as $row) {
            $byAttribute[$row['attribute'] . ' (' . $row['_operation'] . ')'][] = '#' . $row['offer_id'];
        }
        $parts = [];
        foreach ($byAttribute as $attribute => $offers) {
            $parts[] = $attribute . ': ' . implode(', ', $offers);
        }
        foreach ($mappingChanges as $mapping) {
            $parts[] = sprintf('source of "%s" in %s: %s', $mapping['attribute'], $mapping['category'], $mapping['to']);
        }

        ShopLog::add(
            '[InPost] Bulk attribute edit - ' . implode('; ', $parts),
            1,
            null,
            'InpostOffer'
        );
        $this->logger->info('Bulk attribute edit', [
            'values' => $byAttribute,
            'mappings' => count($mappingChanges),
            'same' => $summary['same'],
            'skipped' => $summary['skipped'],
        ]);
    }

    /**
     * Name and type: InPost has a separate attribute with the same name in every category
     *
     * @param array<string,mixed> $definition
     */
    private static function key(array $definition): string
    {
        return DictionaryValueMatcher::normalize((string) $definition['label']) . '|' . $definition['type'];
    }

    /**
     * "feature:12" as used by the dialog
     */
    private static function sourceValue(?string $type, $id): string
    {
        return AttributeSource::isValid($type) && (int) $id > 0 ? $type . ':' . (int) $id : '';
    }

    /**
     * @return array<string,mixed>
     */
    private static function row(int $offerId, string $attribute, ?Offer $offer = null): array
    {
        $product = $offer !== null ? $offer->getProduct() : null;

        return [
            'offer_id' => $offerId,
            'product' => $product !== null ? (string) $product->getName() : '',
            'sku' => $product !== null ? (string) $product->getSku() : '',
            'status' => $offer !== null ? $offer->getStatus() : '',
            'attribute' => $attribute,
            'category' => '',
            'current' => '',
            'current_source' => null,
            'new' => '',
        ];
    }

    /**
     * @param array<string,string> $params
     * @return array<string,mixed>
     */
    private static function skip(string $reason, array $params = []): array
    {
        return ['result' => self::RESULT_SKIP, 'reason' => $reason, 'reason_params' => $params];
    }

    /**
     * Row without the internal entries (prefixed with _)
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function publicRow(array $row): array
    {
        return array_filter($row, static function (string $field): bool {
            return $field[0] !== '_';
        }, ARRAY_FILTER_USE_KEY) + ['reason' => null, 'reason_params' => []];
    }

    /**
     * @return string[]
     */
    private static function decode(string $stored): array
    {
        $values = json_decode($stored, true);
        if (!is_array($values)) {
            return $stored !== '' ? [$stored] : [];
        }

        return array_values(array_map('strval', $values));
    }

    /**
     * Values as the merchant knows them - dictionary labels instead of codes
     *
     * @param array<string,mixed> $definition
     * @param string[] $values
     */
    private static function display(array $definition, array $values): string
    {
        $labels = [];
        foreach ($definition['dictionary_values'] ?? [] as $option) {
            $labels[(string) $option['value']] = (string) $option['label'];
        }

        return implode(', ', array_map(static function (string $value) use ($labels): string {
            return $labels[$value] ?? $value;
        }, $values));
    }

    /**
     * @param string[] $a
     * @param string[] $b
     */
    private static function sameValues(array $a, array $b): bool
    {
        sort($a);
        sort($b);

        return $a === $b;
    }
}
