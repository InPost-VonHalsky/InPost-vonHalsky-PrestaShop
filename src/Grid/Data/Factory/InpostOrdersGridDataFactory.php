<?php
/**
 * InPost Orders Grid Data Factory
 *
 * Custom data factory to format currency values
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Grid\Data\Factory;

use PrestaShop\PrestaShop\Core\Grid\Data\Factory\DoctrineGridDataFactory;
use PrestaShop\PrestaShop\Core\Grid\Data\Factory\GridDataFactoryInterface;
use PrestaShop\PrestaShop\Core\Grid\Data\GridData;
use PrestaShop\PrestaShop\Core\Grid\Data\GridDataInterface;
use PrestaShop\PrestaShop\Core\Grid\Query\DoctrineQueryBuilderInterface;
use PrestaShop\PrestaShop\Core\Grid\Search\SearchCriteriaInterface;
use PrestaShop\PrestaShop\Core\Hook\HookDispatcherInterface;
use PrestaShop\PrestaShop\Core\Grid\Query\QueryParserInterface;
use PrestaShop\PrestaShop\Core\Grid\Record\RecordCollection;
use Inpost\VonHalsky\Service\Status\OrderWarnings;
use Tools;
use Currency;
use Configuration;

/**
 * Class InpostOrdersGridDataFactory
 * Implements GridDataFactoryInterface to format currency values
 */
class InpostOrdersGridDataFactory implements GridDataFactoryInterface
{
    /**
     * @var DoctrineGridDataFactory
     */
    private $doctrineGridDataFactory;

    /**
     * @var OrderWarnings
     */
    private $orderWarnings;

    /**
     * @param DoctrineQueryBuilderInterface $gridQueryBuilder
     * @param HookDispatcherInterface $hookDispatcher
     * @param QueryParserInterface $queryParser
     * @param string $gridId
     * @param OrderWarnings $orderWarnings
     */
    public function __construct(
        DoctrineQueryBuilderInterface $gridQueryBuilder,
        HookDispatcherInterface $hookDispatcher,
        QueryParserInterface $queryParser,
        string $gridId,
        OrderWarnings $orderWarnings
    ) {
        $this->orderWarnings = $orderWarnings;
        $this->doctrineGridDataFactory = new DoctrineGridDataFactory(
            $gridQueryBuilder,
            $hookDispatcher,
            $queryParser,
            $gridId
        );
    }

    /**
     * {@inheritdoc}
     */
    public function getData(SearchCriteriaInterface $searchCriteria): GridDataInterface
    {
        $data = $this->doctrineGridDataFactory->getData($searchCriteria);

        // Format total column as price and total_items_count as integer
        $records = $data->getRecords()->all();
        $formattedRecords = [];
        $warningsByOrder = $this->orderWarnings->forOrders(array_column($records, 'id_inpost_order'));
        
        // Get default currency ID for cases where order doesn't have currency
        $defaultCurrencyId = (int) Currency::getDefaultCurrencyId();

        foreach ($records as $record) {
            // Format total as price with currency
            if (isset($record['total'])) {
                $totalAmount = (float) $record['total'];
                // Use currency from PrestaShop order if available, otherwise use default currency
                $idCurrency = isset($record['id_currency']) && (int) $record['id_currency'] > 0
                    ? (int) $record['id_currency']
                    : $defaultCurrencyId;
                $record['total'] = Tools::displayPrice($totalAmount, $idCurrency);
            }

            // Format total_items_count as integer
            if (isset($record['total_items_count'])) {
                $record['total_items_count'] = (int) $record['total_items_count'];
            }

            // Format InPost status as colored badge
            if (isset($record['inpost_status']) && !empty($record['inpost_status'])) {
                $status = $record['inpost_status'];
                $statusLower = strtolower($status);

                // Store raw status for accessibility checker
                $record['inpost_status_raw'] = $status;

                // Determine badge color class
                if ($statusLower === 'accepted') {
                    $badgeClass = 'success';
                } elseif (in_array($statusLower, ['refused', 'rejected'])) {
                    $badgeClass = 'danger';
                } elseif ($statusLower === 'completed') {
                    $badgeClass = 'info';
                } else {
                    $badgeClass = 'warning';
                }

                // Format as HTML badge
                $record['inpost_status'] = sprintf(
                    '<span class="badge rounded badge-%s">%s</span>',
                    $badgeClass,
                    htmlspecialchars($status, ENT_QUOTES, 'UTF-8')
                );
            }

            $record['warnings'] = $this->renderWarningIcons($warningsByOrder[(int) ($record['id_inpost_order'] ?? 0)] ?? []);

            $formattedRecords[] = $record;
        }

        // Create new GridData with formatted records
        return new GridData(
            new RecordCollection($formattedRecords),
            $data->getRecordsTotal(),
            $data->getQuery()
        );
    }

    /**
     * Small icons with the explanation in a CSS tooltip (.inpost-tip in views/css/back.css)
     *
     * @param array<int, array{level: string, message: string}> $warnings
     */
    private function renderWarningIcons(array $warnings): string
    {
        $html = '';
        foreach ($warnings as $warning) {
            $isWarning = $warning['level'] === OrderWarnings::LEVEL_WARNING;
            $message = htmlspecialchars($warning['message'], ENT_QUOTES, 'UTF-8');
            $html .= sprintf(
                '<span class="inpost-tip inpost-tip--%s" tabindex="0" aria-label="%s">'
                . '<i class="material-icons">%s</i><span class="inpost-tip__text" role="tooltip">%s</span></span>',
                $isWarning ? 'warning' : 'info',
                $message,
                $isWarning ? 'warning' : 'info',
                $message
            );
        }

        return $html;
    }
}
