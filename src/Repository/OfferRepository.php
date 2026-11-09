<?php
/**
 * OfferRepository
 *
 * Custom queries for Offer entity.
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Repository;

use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Inpost\VonHalsky\Entity\Offer;

class OfferRepository extends EntityRepository
{
    /**
     * Find non-draft offers with a PENDING async create command, oldest first
     * (so a backlog drains fairly).
     *
     * @param int|null $limit
     * @return Offer[]
     */
    public function findPendingCommands(?int $limit = null): array
    {
        $qb = $this->createQueryBuilder('o')
            ->where('o.commandStatus = :pending')
            ->andWhere('o.inpostCommandId IS NOT NULL')
            ->andWhere('o.isDraft = :notDraft')
            ->setParameter('pending', 'PENDING')
            ->setParameter('notDraft', 0)
            ->orderBy('o.updatedAt', 'ASC');

        if ($limit !== null && $limit > 0) {
            $qb->setMaxResults($limit);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Find offers whose command status is one of the given values (most recent
     * first). Used by the inpost:command:sync diagnostic listing.
     *
     * @param string[] $statuses
     * @return Offer[]
     */
    public function findByCommandStatuses(array $statuses): array
    {
        if (empty($statuses)) {
            return [];
        }

        return $this->createQueryBuilder('o')
            ->where('o.commandStatus IN (:statuses)')
            ->setParameter('statuses', $statuses)
            ->orderBy('o.updatedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find offer by InPost offer ID
     *
     * @param string $inpostOfferId
     * @return Offer|null
     */
    public function findByInpostOfferId(string $inpostOfferId): ?Offer
    {
        return $this->createQueryBuilder('o')
            ->where('o.inpostOfferId = :inpostOfferId')
            ->setParameter('inpostOfferId', $inpostOfferId)
            ->getQuery()
            ->getOneOrNullResult();
    }



    /**
     * Search offers by product name, SKU, or EAN
     *
     * @param int $shopId
     * @param string $search Search term
     * @param string|null $status Filter by status
     * @param int $limit
     * @param int $offset
     * @param string $orderBy Column to sort by
     * @param string $sortOrder Sort direction (ASC or DESC)
     * @param int|null $categoryMappingId Filter by InPost category (inpost_category_mapping.entity_id)
     * @return Offer[]
     */
    public function searchOffers(
        int $shopId,
        string $search = '',
        ?string $status = null,
        int $limit = 20,
        int $offset = 0,
        string $orderBy = 'updatedAt',
        string $sortOrder = 'DESC',
        ?int $categoryMappingId = null
    ): array {
        // Whitelist allowed sort columns
        $allowedColumns = ['offerId', 'updatedAt', 'createdAt', 'syncedAt'];
        if (!in_array($orderBy, $allowedColumns)) {
            $orderBy = 'updatedAt';
        }
        $sortOrder = strtoupper($sortOrder) === 'ASC' ? 'ASC' : 'DESC';

        $qb = $this->createQueryBuilder('o')
            ->leftJoin('o.product', 'p')
            ->where('o.shopId = :shopId')
            ->setParameter('shopId', $shopId)
            ->orderBy('o.' . $orderBy, $sortOrder)
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        $this->applySearchFilters($qb, $search, $status, $categoryMappingId);

        return $qb->getQuery()->getResult();
    }

    /**
     * Count search results
     *
     * @param int $shopId
     * @param string $search Search term
     * @param string|null $status Filter by status
     * @return int
     */
    public function countSearchOffers(int $shopId, string $search = '', ?string $status = null, ?int $categoryMappingId = null): int
    {
        $qb = $this->createQueryBuilder('o')
            ->select('COUNT(o.offerId)')
            ->leftJoin('o.product', 'p')
            ->where('o.shopId = :shopId')
            ->setParameter('shopId', $shopId);

        $this->applySearchFilters($qb, $search, $status, $categoryMappingId);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * InPost categories (inpost_category_mapping.entity_id) the offers of the shop are in
     *
     * @return int[]
     */
    public function findUsedCategoryMappingIds(int $shopId): array
    {
        $rows = $this->createQueryBuilder('o')
            ->select('DISTINCT p.categoryMappingId AS id')
            ->innerJoin('o.product', 'p')
            ->where('o.shopId = :shopId')
            ->andWhere('p.categoryMappingId IS NOT NULL')
            ->setParameter('shopId', $shopId)
            ->getQuery()
            ->getScalarResult();

        return array_map('intval', array_column($rows, 'id'));
    }

    private function applySearchFilters(QueryBuilder $qb, string $search, ?string $status, ?int $categoryMappingId): void
    {
        if ($status) {
            $qb->andWhere('o.status = :status')
               ->setParameter('status', $status);
        }

        if ($search !== '') {
            $qb->andWhere('(p.name LIKE :search OR p.sku LIKE :search OR p.ean LIKE :search)')
               ->setParameter('search', '%' . $search . '%');
        }

        if ($categoryMappingId) {
            $qb->andWhere('p.categoryMappingId = :categoryMappingId')
               ->setParameter('categoryMappingId', $categoryMappingId);
        }
    }
}
