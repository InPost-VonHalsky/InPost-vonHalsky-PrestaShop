<?php
/**
 * OrganizationRepository
 *
 * Custom queries for Organization entity.
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Repository;

use Doctrine\ORM\EntityRepository;
use Inpost\VonHalsky\Entity\Organization;

class OrganizationRepository extends EntityRepository
{
    /**
     * Find organization by organization ID
     *
     * @param string $organizationId
     * @return Organization|null
     */
    public function findByOrganizationId(string $organizationId): ?Organization
    {
        return $this->createQueryBuilder('org')
            ->where('org.organizationId = :organizationId')
            ->setParameter('organizationId', $organizationId)
            ->getQuery()
            ->getOneOrNullResult();
    }

}
