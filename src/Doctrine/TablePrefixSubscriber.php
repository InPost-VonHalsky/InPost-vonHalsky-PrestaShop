<?php
/**
 * Doctrine Table Prefix Subscriber
 *
 * Automatically prefixes all Doctrine entity table names with the PrestaShop database prefix.
 * This allows entities to use unprefixed names (e.g., "inpost_offer") while queries
 * target the actual prefixed tables in the database (e.g., "ps_inpost_offer").
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Doctrine;

use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Events;

/**
 * Doctrine event subscriber that prefixes table names with database prefix
 */
final class TablePrefixSubscriber implements EventSubscriber
{
    private string $prefix;

    public function __construct()
    {
        // Get PrestaShop database prefix at runtime
        $this->prefix = defined('_DB_PREFIX_') ? _DB_PREFIX_ : 'ps_';
    }

    public function getSubscribedEvents(): array
    {
        return [Events::loadClassMetadata];
    }

    public function loadClassMetadata(LoadClassMetadataEventArgs $args): void
    {
        $metadata = $args->getClassMetadata();

        // Only process our module's entities
        if (strpos($metadata->getName(), 'Inpost\VonHalsky\Entity\\') !== 0) {
            return;
        }

        // Primary table
        $tableName = $metadata->getTableName();
        if ($tableName !== null && strpos($tableName, $this->prefix) !== 0) {
            $metadata->setPrimaryTable(['name' => $this->prefix . $tableName]);
        }

        // Join tables (for ManyToMany, etc.)
        foreach ($metadata->associationMappings as $fieldName => $mapping) {
            if (isset($mapping['joinTable']['name'])) {
                $join = $mapping['joinTable']['name'];
                if (strpos($join, $this->prefix) !== 0) {
                    $metadata->associationMappings[$fieldName]['joinTable']['name'] = $this->prefix . $join;
                }
            }
        }
    }
}

