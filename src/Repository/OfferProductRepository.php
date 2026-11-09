<?php
/**
 * OfferProductRepository
 *
 * Custom queries for OfferProduct entity.
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Repository;

use Doctrine\ORM\EntityRepository;
use Inpost\VonHalsky\Entity\OfferProduct;

class OfferProductRepository extends EntityRepository
{
    /**
     * Find by EAN
     *
     * @param string $ean
     * @return OfferProduct|null
     */
    public function findByEan(string $ean): ?OfferProduct
    {
        return $this->createQueryBuilder('op')
            ->where('op.ean = :ean')
            ->setParameter('ean', $ean)
            ->getQuery()
            ->getOneOrNullResult();
    }

}
