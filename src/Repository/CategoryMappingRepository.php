<?php
/**
 * CategoryMappingRepository
 *
 * Custom queries for CategoryMapping entity.
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Repository;

use Doctrine\ORM\EntityRepository;
use Inpost\VonHalsky\Entity\CategoryMapping;

class CategoryMappingRepository extends EntityRepository
{
    /**
     * Find by InPost category ID
     *
     * @param string $inpostCategoryId
     * @param string|null $language
     * @return CategoryMapping|null
     */
    public function findByInpostCategoryId(string $inpostCategoryId, ?string $language = 'pl'): ?CategoryMapping
    {
        return $this->createQueryBuilder('cm')
            ->where('cm.inpostCategoryId = :categoryId')
            ->andWhere('cm.language = :lang')
            ->setParameter('categoryId', $inpostCategoryId)
            ->setParameter('lang', $language)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find root categories
     *
     * @param string|null $language
     * @return CategoryMapping[]
     */
    public function findRootCategories(?string $language = 'pl'): array
    {
        return $this->createQueryBuilder('cm')
            ->where('cm.inpostParentId IS NULL OR cm.inpostParentId = :empty')
            ->andWhere('cm.language = :lang')
            ->andWhere('cm.isActive = :active')
            ->setParameter('empty', '')
            ->setParameter('lang', $language)
            ->setParameter('active', true)
            ->orderBy('cm.sortOrder', 'ASC')
            ->addOrderBy('cm.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find child categories
     *
     * @param string $parentCategoryId
     * @param string|null $language
     * @return CategoryMapping[]
     */
    public function findByParent(string $parentCategoryId, ?string $language = 'pl'): array
    {
        return $this->createQueryBuilder('cm')
            ->where('cm.inpostParentId = :parentId')
            ->andWhere('cm.language = :lang')
            ->andWhere('cm.isActive = :active')
            ->setParameter('parentId', $parentCategoryId)
            ->setParameter('lang', $language)
            ->setParameter('active', true)
            ->orderBy('cm.sortOrder', 'ASC')
            ->addOrderBy('cm.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get root nodes for JSTree (lazy loading - level 0 only)
     *
     * @param string|null $language
     * @return array JSTree format
     */
    public function getRootNodes(?string $language = 'pl'): array
    {
        $categories = $this->findRootCategories($language);
        return $this->transformToJsTreeFormat($categories, $language);
    }

    /**
     * Search categories by name (server-side; works across the whole tree, not
     * just loaded nodes). Returns a flat result list.
     *
     * @param string $query
     * @param string|null $language
     * @param int $limit
     * @return array<int,array<string,mixed>>
     */
    public function searchCategories(string $query, ?string $language = 'pl', int $limit = 50): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $qb = $this->createQueryBuilder('cm')
            ->where('cm.name LIKE :q')
            ->andWhere('cm.language = :lang')
            ->setParameter('q', '%' . $query . '%')
            ->setParameter('lang', $language)
            ->orderBy('cm.name', 'ASC')
            ->setMaxResults($limit);

        $matches = $qb->getQuery()->getResult();
        if (empty($matches)) {
            return [];
        }

        // Resolve each match's ancestor chain so identically-named leaves (e.g.
        // "Koszule" under several parents) are distinguishable.
        $parentUuids = [];
        foreach ($matches as $category) {
            if ($category->getInpostParentId()) {
                $parentUuids[$category->getInpostParentId()] = true;
            }
        }
        $lookup = $this->buildAncestorLookup(array_keys($parentUuids), $language);

        $results = [];
        foreach ($matches as $category) {
            $results[] = [
                'entity_id' => $category->getEntityId(),
                'category_id' => $category->getInpostCategoryId(),
                'name' => $category->getName(),
                'level' => $category->getLevel(),
                'is_leaf' => $category->getIsLeaf(),
                'is_active' => $category->getIsActive(),
                'path' => $this->buildPath($category->getInpostParentId(), $lookup),
            ];
        }

        return $results;
    }

    /**
     * Iteratively load the [uuid => ['name', 'parent']] map for a set of seed
     * ancestor UUIDs and their ancestors (batched per level; capped depth).
     *
     * @param string[] $seedUuids
     * @param string|null $language
     * @return array<string,array{name:string,parent:?string}>
     */
    private function buildAncestorLookup(array $seedUuids, ?string $language): array
    {
        $lookup = [];
        $needed = array_values(array_filter($seedUuids));
        $depth = 0;

        while (!empty($needed) && $depth < 30) {
            $rows = $this->createQueryBuilder('cm')
                ->select('cm.inpostCategoryId AS id, cm.name AS name, cm.inpostParentId AS parent')
                ->where('cm.inpostCategoryId IN (:ids)')
                ->andWhere('cm.language = :lang')
                ->setParameter('ids', $needed)
                ->setParameter('lang', $language)
                ->getQuery()
                ->getArrayResult();

            $next = [];
            foreach ($rows as $row) {
                $lookup[$row['id']] = ['name' => $row['name'], 'parent' => $row['parent']];
                if ($row['parent'] && !isset($lookup[$row['parent']])) {
                    $next[$row['parent']] = true;
                }
            }

            $needed = array_keys($next);
            $depth++;
        }

        return $lookup;
    }

    /**
     * Build a root-first path string from an ancestor UUID up the chain.
     *
     * @param string|null $parentUuid
     * @param array<string,array{name:string,parent:?string}> $lookup
     * @return string
     */
    private function buildPath(?string $parentUuid, array $lookup): string
    {
        $names = [];
        $current = $parentUuid;
        $guard = 0;

        while ($current !== null && isset($lookup[$current]) && $guard < 30) {
            $names[] = $lookup[$current]['name'];
            $current = $lookup[$current]['parent'];
            $guard++;
        }

        return implode(' › ', array_reverse($names));
    }

    /**
     * Get child nodes for specific parent (lazy loading)
     *
     * @param int $parentEntityId
     * @param string|null $language
     * @return array JSTree format
     */
    public function getChildNodes(int $parentEntityId, ?string $language = 'pl'): array
    {
        // If parent ID is 0, get root nodes
        if ($parentEntityId === 0) {
            return $this->getRootNodes($language);
        }

        // First get the parent to get its inpost_category_id
        $parent = $this->find($parentEntityId);

        if (!$parent) {
            return [];
        }

        $categories = $this->findByParent($parent->getInpostCategoryId(), $language);
        return $this->transformToJsTreeFormat($categories, $language);
    }

    /**
     * Transform categories to JSTree format
     *
     * @param array $categories CategoryMapping entities
     * @param string|null $language Language code
     * @return array JSTree format
     */
    private function transformToJsTreeFormat(array $categories, ?string $language = 'pl'): array
    {
        $treeData = [];

        foreach ($categories as $category) {
            $hasChildren = $this->hasChildren($category->getEntityId(), $language);

            $node = [
                'id' => 'mapping_' . $category->getEntityId(),
                'text' => $category->getName(),
                'children' => $hasChildren,
                'li_attr' => [
                    'data-category-id' => $category->getInpostCategoryId(),
                    'data-entity-id' => $category->getEntityId(),
                    'data-level' => $category->getLevel(),
                    'data-is-leaf' => $category->getIsLeaf() ? '1' : '0'
                ]
            ];

            // If no children, add file icon
            if (!$hasChildren || $category->getIsLeaf()) {
                $node['icon'] = 'jstree-file';
            }

            $treeData[] = $node;
        }

        return $treeData;
    }

    /**
     * Check if category has children (for lazy loading indicator)
     *
     * @param int $parentEntityId Parent category entity ID
     * @param string|null $language Language code
     * @return bool True if has children
     */
    private function hasChildren(int $parentEntityId, ?string $language = 'pl'): bool
    {
        // Look up parent's InPost UUID to query by inpostParentId (consistent with findByParent)
        $parent = $this->find($parentEntityId);
        
        if (!$parent) {
            return false;
        }
        
        $qb = $this->createQueryBuilder('cm');
        $qb->select('COUNT(cm.entityId)')
            ->where('cm.inpostParentId = :parentId')
            ->andWhere('cm.language = :lang')
            ->andWhere('cm.isActive = :active')
            ->setParameter('parentId', $parent->getInpostCategoryId())
            ->setParameter('lang', $language)
            ->setParameter('active', true);

        $count = (int) $qb->getQuery()->getSingleScalarResult();

        return $count > 0;
    }
}
