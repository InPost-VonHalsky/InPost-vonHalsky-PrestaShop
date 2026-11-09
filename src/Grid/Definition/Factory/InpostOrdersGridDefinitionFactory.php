<?php
/**
 * InPost Orders Grid Definition Factory
 *
 * Defines the structure and columns for the InPost Orders grid
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Grid\Definition\Factory;

use PrestaShop\PrestaShop\Core\Grid\Definition\Factory\AbstractFilterableGridDefinitionFactory;
use PrestaShop\PrestaShop\Core\Grid\Column\ColumnCollection;
use PrestaShop\PrestaShop\Core\Grid\Column\Type\Common\ActionColumn;
use PrestaShop\PrestaShop\Core\Grid\Column\Type\Common\BadgeColumn;
use PrestaShop\PrestaShop\Core\Grid\Column\Type\Common\DateTimeColumn;
use PrestaShop\PrestaShop\Core\Grid\Column\Type\Common\HtmlColumn;
use PrestaShop\PrestaShop\Core\Grid\Column\Type\Common\LinkColumn;
use PrestaShop\PrestaShop\Core\Grid\Column\Type\DataColumn;
use PrestaShop\PrestaShop\Core\Grid\Action\Row\RowActionCollection;
use PrestaShop\PrestaShop\Core\Grid\Action\Row\Type\LinkRowAction;
use PrestaShop\PrestaShop\Core\Grid\Filter\Filter;
use PrestaShop\PrestaShop\Core\Grid\Filter\FilterCollection;
use PrestaShopBundle\Form\Admin\Type\SearchAndResetType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;

/**
 * Class InpostOrdersGridDefinitionFactory
 * Builds the grid definition for InPost Orders
 */
final class InpostOrdersGridDefinitionFactory extends AbstractFilterableGridDefinitionFactory
{
    /**
     * {@inheritdoc}
     */
    protected function getId(): string
    {
        return 'inpost_orders';
    }

    /**
     * {@inheritdoc}
     */
    protected function getName(): string
    {
        return $this->trans('InPost Orders', [], 'Modules.Inpostvonhalsky.Admin');
    }

    /**
     * {@inheritdoc}
     */
    protected function getColumns()
    {
        return (new ColumnCollection())
            ->add(
                (new DataColumn('id_inpost_order'))
                    ->setName($this->trans('ID', [], 'Admin.Global'))
                    ->setOptions([
                        'field' => 'id_inpost_order',
                    ])
            )
            ->add(
                (new DataColumn('order_reference'))
                    ->setName($this->trans('Order Reference', [], 'Admin.Orderscustomers.Feature'))
                    ->setOptions([
                        'field' => 'order_reference',
                    ])
            )
            ->add(
                (new LinkColumn('inpost_order_id'))
                    ->setName($this->trans('InPost Order ID', [], 'Modules.Inpostvonhalsky.Admin'))
                    ->setOptions([
                        'field' => 'inpost_order_id',
                        'route' => 'admin_inpost_orders_view_inpost',
                        'route_param_name' => 'inpostOrderId',
                        'route_param_field' => 'id_inpost_order',
                    ])
            )
            ->add(
                (new HtmlColumn('inpost_status'))
                    ->setName($this->trans('InPost Order Status', [], 'Modules.Inpostvonhalsky.Admin'))
                    ->setOptions([
                        'field' => 'inpost_status',
                    ])
            )
            ->add(
                (new HtmlColumn('warnings'))
                    ->setName('')
                    ->setOptions([
                        'field' => 'warnings',
                        'sortable' => false,
                    ])
            )
            ->add(
                (new DataColumn('prestashop_status'))
                    ->setName($this->trans('PrestaShop Status', [], 'Modules.Inpostvonhalsky.Admin'))
                    ->setOptions([
                        'field' => 'prestashop_status',
                    ])
            )
            ->add(
                (new DataColumn('total'))
                    ->setName($this->trans('Total', [], 'Admin.Global'))
                    ->setOptions([
                        'field' => 'total',
                    ])
            )
            ->add(
                (new DataColumn('total_items_count'))
                    ->setName($this->trans('Items Count', [], 'Modules.Inpostvonhalsky.Admin'))
                    ->setOptions([
                        'field' => 'total_items_count',
                    ])
            )
            ->add(
                (new DataColumn('customer_name'))
                    ->setName($this->trans('Customer', [], 'Admin.Global'))
                    ->setOptions([
                        'field' => 'customer_name',
                    ])
            )
            ->add(
                (new DateTimeColumn('date_add'))
                    ->setName($this->trans('Date Created', [], 'Admin.Global'))
                    ->setOptions([
                        'field' => 'date_add',
                    ])
            )
            ->add(
                (new DateTimeColumn('date_upd'))
                    ->setName($this->trans('Date Updated', [], 'Admin.Global'))
                    ->setOptions([
                        'field' => 'date_upd',
                    ])
            )
            ->add(
                (new ActionColumn('actions'))
                    ->setName($this->trans('Actions', [], 'Admin.Global'))
                    ->setOptions([
                        'actions' => $this->getRowActions(),
                    ])
            );
    }

    /**
     * {@inheritdoc}
     */
    protected function getFilters()
    {
        return (new FilterCollection())
            ->add(
                (new Filter('id_inpost_order', TextType::class))
                    ->setTypeOptions([
                        'required' => false,
                        'attr' => [
                            'placeholder' => $this->trans('Search ID', [], 'Admin.Actions'),
                        ],
                    ])
                    ->setAssociatedColumn('id_inpost_order')
            )
            ->add(
                (new Filter('order_reference', TextType::class))
                    ->setTypeOptions([
                        'required' => false,
                        'attr' => [
                            'placeholder' => $this->trans('Search Order Reference', [], 'Admin.Actions'),
                        ],
                    ])
                    ->setAssociatedColumn('order_reference')
            )
            ->add(
                (new Filter('inpost_order_id', TextType::class))
                    ->setTypeOptions([
                        'required' => false,
                        'attr' => [
                            'placeholder' => $this->trans('Search InPost Order ID', [], 'Admin.Actions'),
                        ],
                    ])
                    ->setAssociatedColumn('inpost_order_id')
            )
            ->add(
                (new Filter('status', ChoiceType::class))
                    ->setTypeOptions([
                        'required' => false,
                        'choices' => [
                            $this->trans('All', [], 'Admin.Global') => '',
                            $this->trans('Accepted', [], 'Modules.Inpostvonhalsky.Admin') => 'accepted',
                            $this->trans('Created', [], 'Modules.Inpostvonhalsky.Admin') => 'created',
                            $this->trans('Refused', [], 'Modules.Inpostvonhalsky.Admin') => 'refused',
                            $this->trans('Rejected', [], 'Modules.Inpostvonhalsky.Admin') => 'rejected',
                        ],
                    ])
                    ->setAssociatedColumn('inpost_status')
            )
            ->add(
                (new Filter('customer_name', TextType::class))
                    ->setTypeOptions([
                        'required' => false,
                        'attr' => [
                            'placeholder' => $this->trans('Search Customer', [], 'Admin.Actions'),
                        ],
                    ])
                    ->setAssociatedColumn('customer_name')
            )
            ->add(
                (new Filter('actions', SearchAndResetType::class))
                    ->setTypeOptions([
                        'reset_route' => 'admin_common_reset_search_by_filter_id',
                        'reset_route_params' => [
                            'filterId' => 'inpost_orders',
                        ],
                        'redirect_route' => 'admin_inpost_orders_index',
                    ])
                    ->setAssociatedColumn('actions')
            );
    }

    /**
     * Get row actions for the grid
     *
     * @return RowActionCollection
     */
    private function getRowActions(): RowActionCollection
    {
        return (new RowActionCollection())
            ->add(
                (new LinkRowAction('view'))
                    ->setName($this->trans('View', [], 'Admin.Actions'))
                    ->setIcon('zoom_in')
                    ->setOptions([
                        'route' => 'admin_inpost_orders_view',
                        'route_param_name' => 'inpostOrderId',
                        'route_param_field' => 'id_inpost_order',
                    ])
            );
    }
}
