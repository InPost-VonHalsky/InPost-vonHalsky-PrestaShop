<?php
/**
 * InPost Order Repository
 *
 * Repository for InpostOrder entity with custom query methods
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Repository;

use Doctrine\ORM\EntityRepository;
use Inpost\VonHalsky\Entity\InpostOrder;

/**
 * Repository for InpostOrder entity
 */
class InpostOrderRepository extends EntityRepository
{
    /**
     * Find order by InPost order ID
     *
     * @param string $inpostOrderId
     *
     * @return InpostOrder|null
     */
    public function findByInpostOrderId(string $inpostOrderId): ?InpostOrder
    {
        return $this->findOneBy(['inpostOrderId' => $inpostOrderId]);
    }

    /**
     * Save or update order
     * Updates date_upd automatically
     *
     * @param InpostOrder $order
     * @param bool $flush
     *
     * @return void
     */
    public function save(InpostOrder $order, bool $flush = true): void
    {
        $order->setDateUpd(new \DateTime());

        $em = $this->getEntityManager();
        $em->persist($order);

        if ($flush) {
            $em->flush();
        }
    }

    /**
     * Remove order
     *
     * @param InpostOrder $order
     * @param bool $flush
     *
     * @return void
     */
    public function remove(InpostOrder $order, bool $flush = true): void
    {
        $em = $this->getEntityManager();
        $em->remove($order);

        if ($flush) {
            $em->flush();
        }
    }
}
