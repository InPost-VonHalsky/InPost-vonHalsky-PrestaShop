<?php
/**
 * OfferEventRepository
 *
 * Custom queries for OfferEvent entity.
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Repository;

use Doctrine\ORM\EntityRepository;
use Inpost\VonHalsky\Entity\OfferEvent;

class OfferEventRepository extends EntityRepository
{
    /**
     * Find events by InPost offer ID
     *
     * @param string $inpostOfferId
     * @param int|null $limit
     * @return OfferEvent[]
     */
    public function findByInpostOfferId(string $inpostOfferId, ?int $limit = null): array
    {
        $qb = $this->createQueryBuilder('oe')
            ->where('oe.inpostOfferId = :inpostOfferId')
            ->setParameter('inpostOfferId', $inpostOfferId)
            ->orderBy('oe.occuredAt', 'DESC');

        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        return $qb->getQuery()->getResult();
    }


    /**
     * Find events by InPost offer ID with pagination
     *
     * @param string $inpostOfferId
     * @param int $page Page number (1-based)
     * @param int $limit Items per page
     * @return OfferEvent[]
     */
    public function findByOfferPaginated(string $inpostOfferId, int $page, int $limit): array
    {
        return $this->createQueryBuilder('oe')
            ->where('oe.inpostOfferId = :inpostOfferId')
            ->setParameter('inpostOfferId', $inpostOfferId)
            ->orderBy('oe.occuredAt', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Count events by InPost offer ID
     *
     * @param string $inpostOfferId
     * @return int
     */
    public function countByOffer(string $inpostOfferId): int
    {
        return (int) $this->createQueryBuilder('oe')
            ->select('COUNT(oe.eventId)')
            ->where('oe.inpostOfferId = :inpostOfferId')
            ->setParameter('inpostOfferId', $inpostOfferId)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
