<?php
/**
 * InPost Orders Query Builder
 *
 * Handles database queries for the InPost Orders grid
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Grid\Query;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use PrestaShop\PrestaShop\Core\Grid\Query\AbstractDoctrineQueryBuilder;
use PrestaShop\PrestaShop\Core\Grid\Search\SearchCriteriaInterface;

/**
 * Class InpostOrdersQueryBuilder
 * Builds SQL queries for the InPost Orders grid
 */
final class InpostOrdersQueryBuilder extends AbstractDoctrineQueryBuilder
{
    /**
     * @var int
     */
    private $contextLangId;

    /**
     * @var int|null
     */
    private $contextShopId;

    /**
     * @param Connection $connection
     * @param string $dbPrefix
     * @param int $contextLangId
     * @param int|null $contextShopId
     */
    public function __construct(
        Connection $connection,
        string $dbPrefix,
        int $contextLangId,
        $contextShopId = null
    ) {
        parent::__construct($connection, $dbPrefix);

        $this->contextLangId = $contextLangId;
        $this->contextShopId = $contextShopId;
    }

    /**
     * {@inheritdoc}
     */
    public function getSearchQueryBuilder(SearchCriteriaInterface $searchCriteria): QueryBuilder
    {
        // Ensure filters is always an array (getFilters() can return null)
        $filters = $searchCriteria->getFilters() ?? [];
        
        $qb = $this->getBaseQuery($filters);
        $qb->select('io.id_inpost_order, io.id_order, io.status as inpost_status')
            ->addSelect('io.inpost_order_id, io.point_address, io.sender_point_name')
            ->addSelect('io.receiver_email, io.receiver_phone, io.date_add, io.date_upd')
            ->addSelect('o.reference as order_reference')
            ->addSelect('o.current_state')
            ->addSelect('io.total as total')
            ->addSelect('io.total_items_count as total_items_count')
            ->addSelect('o.id_currency')
            ->addSelect('CONCAT(c.firstname, \' \', c.lastname) as customer_name')
            ->addSelect('osl.name as prestashop_status');

        // Apply sorting
        $this->applySorting($qb, $searchCriteria);

        // Apply pagination
        $offset = $searchCriteria->getOffset() ?? 0;
        $limit = $searchCriteria->getLimit() ?? 50;
        $qb->setFirstResult($offset)
            ->setMaxResults($limit);

        return $qb;
    }

    /**
     * {@inheritdoc}
     */
    public function getCountQueryBuilder(SearchCriteriaInterface $searchCriteria): QueryBuilder
    {
        // Ensure filters is always an array (getFilters() can return null)
        $filters = $searchCriteria->getFilters() ?? [];
        
        $qb = $this->getBaseQuery($filters);
        $qb->select('COUNT(DISTINCT io.id_inpost_order)');

        return $qb;
    }

    /**
     * Get base query with joins and filters
     *
     * @param array $filters
     *
     * @return QueryBuilder
     */
    private function getBaseQuery(array $filters): QueryBuilder
    {
        // Additional safety check - ensure filters is always an array
        if (!is_array($filters)) {
            $filters = [];
        }
        
        $qb = $this->connection->createQueryBuilder();

        $qb->from($this->dbPrefix . 'inpost_order', 'io')
            ->leftJoin(
                'io',
                $this->dbPrefix . 'orders',
                'o',
                'io.id_order = o.id_order'
            )
            ->leftJoin(
                'o',
                $this->dbPrefix . 'order_state_lang',
                'osl',
                'o.current_state = osl.id_order_state AND osl.id_lang = ' . (int) $this->contextLangId
            )
            ->leftJoin(
                'o',
                $this->dbPrefix . 'customer',
                'c',
                'o.id_customer = c.id_customer'
            );

        // Apply shop context if multistore is enabled
        if ($this->contextShopId !== null) {
            $qb->andWhere('io.id_shop = :shopId')
                ->setParameter('shopId', $this->contextShopId);
        }

        // Apply filters
        $this->applyFilters($qb, $filters);

        return $qb;
    }

    /**
     * Apply filters to the query
     *
     * @param QueryBuilder $qb
     * @param array $filters
     *
     * @return void
     */
    private function applyFilters(QueryBuilder $qb, array $filters): void
    {
        $allowedFilters = [
            'id_inpost_order',
            'order_reference',
            'inpost_order_id',
            'status',
            'customer_name',
        ];

        foreach ($filters as $filterName => $filterValue) {
            if (!in_array($filterName, $allowedFilters, true)) {
                continue;
            }

            if (empty($filterValue)) {
                continue;
            }

            switch ($filterName) {
                case 'id_inpost_order':
                    $qb->andWhere('io.id_inpost_order = :id')
                        ->setParameter('id', (int) $filterValue);
                    break;

                case 'order_reference':
                    $qb->andWhere('o.reference LIKE :orderRef')
                        ->setParameter('orderRef', '%' . $filterValue . '%');
                    break;

                case 'inpost_order_id':
                    // Search in inpost_order_id column
                    $qb->andWhere('io.inpost_order_id LIKE :inpostOrderId')
                        ->setParameter('inpostOrderId', '%' . $filterValue . '%');
                    break;

                case 'status':
                    $qb->andWhere('io.status = :status')
                        ->setParameter('status', $filterValue);
                    break;

                case 'customer_name':
                    $qb->andWhere(
                        $qb->expr()->or(
                            $qb->expr()->like('c.firstname', ':customerName'),
                            $qb->expr()->like('c.lastname', ':customerName'),
                            $qb->expr()->like(
                                'CONCAT(c.firstname, \' \', c.lastname)',
                                ':customerName'
                            )
                        )
                    )
                    ->setParameter('customerName', '%' . $filterValue . '%');
                    break;
            }
        }
    }

    /**
     * Apply sorting to the query
     *
     * @param QueryBuilder $qb
     * @param SearchCriteriaInterface $searchCriteria
     *
     * @return void
     */
    private function applySorting(QueryBuilder $qb, SearchCriteriaInterface $searchCriteria): void
    {
        $orderBy = $searchCriteria->getOrderBy();
        // Passed verbatim into the SQL by DBAL - whitelist it
        $orderWay = strtoupper((string) $searchCriteria->getOrderWay()) === 'ASC' ? 'ASC' : 'DESC';

        // Map grid column names to actual database columns
        $columnMapping = [
            'id_inpost_order' => 'io.id_inpost_order',
            'order_reference' => 'o.reference',
            'inpost_order_id' => 'io.inpost_order_id',
            'inpost_status' => 'io.status',
            'prestashop_status' => 'osl.name',
            'total' => 'io.total',
            'total_items_count' => 'io.total_items_count',
            'customer_name' => 'CONCAT(c.firstname, \' \', c.lastname)',
            'date_add' => 'io.date_add',
            'date_upd' => 'io.date_upd',
        ];

        if (isset($columnMapping[$orderBy])) {
            $qb->orderBy($columnMapping[$orderBy], $orderWay);
        } else {
            // Default sorting
            $qb->orderBy('io.date_add', 'DESC');
        }
    }
}
