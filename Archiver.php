<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */
namespace Piwik\Plugins\CustomDimensions;

use Piwik\Config;
use Piwik\Metrics;
use Piwik\Plugins\Actions\Metrics as ActionsMetrics;
use Piwik\Plugins\CustomDimensions\Dao\Configuration;
use Piwik\Plugins\CustomDimensions\Dao\LogTable;
use Piwik\Tracker;
use Piwik\ArchiveProcessor;

/**
 * Archives reports for each active Custom Dimension of a website.
 */
class Archiver extends \Piwik\Plugin\Archiver
{
    const LABEL_CUSTOM_VALUE_NOT_DEFINED = "Value not defined";
    private $recordNames = array();

    /**
     * @var DataArray
     */
    protected $dataArray;
    protected $maximumRowsInDataTableLevelZero;
    protected $maximumRowsInSubDataTable;
    protected $newEmptyRow;

    /**
     * @var ArchiveProcessor
     */
    private $processor;

    function __construct($processor)
    {
        parent::__construct($processor);

        $this->processor = $processor;

        $this->maximumRowsInDataTableLevelZero = Config::getInstance()->General['datatable_archiving_maximum_rows_custom_variables'];
        $this->maximumRowsInSubDataTable = Config::getInstance()->General['datatable_archiving_maximum_rows_subtable_custom_variables'];
    }

    public static function buildRecordNameForCustomDimensionId($id)
    {
        return 'CustomDimensions_Dimension' . (int) $id;
    }

    private function getRecordNames()
    {
        if (!empty($this->recordNames)) {
            return $this->recordNames;
        }

        $dimensions = $this->getActiveCustomDimensions();

        foreach ($dimensions as $dimension) {
            $this->recordNames[] = self::buildRecordNameForCustomDimensionId($dimension['idcustomdimension']);
        }

        return $this->recordNames;
    }

    private function getActiveCustomDimensions()
    {
        $idSite = $this->processor->getParams()->getSite()->getId();

        $config = new Configuration();
        $dimensions = $config->getCustomDimensionsForSite($idSite);

        $active = array();
        foreach ($dimensions as $index => $dimension) {
            if ($dimension['active']) {
                $active[] = $dimension;
            }
        }

        return $active;
    }

    public function aggregateMultipleReports()
    {
        $columnsAggregationOperation = null;

        $this->getProcessor()->aggregateDataTableRecords(
            $this->getRecordNames(),
            $this->maximumRowsInDataTableLevelZero,
            $this->maximumRowsInSubDataTable,
            $columnToSort = Metrics::INDEX_NB_VISITS,
            $columnsAggregationOperation,
            $columnsToRenameAfterAggregation = null,
            $countRowsRecursive = array());
    }

    public function aggregateDayReport()
    {
        $dimensions = $this->getActiveCustomDimensions();
        foreach ($dimensions as $dimension) {
            $this->dataArray = new DataArray();

            $valueField = LogTable::buildCustomDimensionColumnName($dimension);
            $dimensions = array($valueField);
            $where      = "%s.$valueField != ''";

            if ($dimension['scope'] === CustomDimensions::SCOPE_VISIT) {
                $this->aggregateFromVisits($valueField, $dimensions, $where);
                $this->aggregateFromConversions($valueField, $dimensions, $where);
            } elseif ($dimension['scope'] === CustomDimensions::SCOPE_ACTION) {
                $this->aggregateFromActions($valueField);
            }

            $this->dataArray->enrichMetricsWithConversions();
            $table = $this->dataArray->asDataTable();

            $blob = $table->getSerialized(
                $this->maximumRowsInDataTableLevelZero, $this->maximumRowsInSubDataTable,
                $columnToSort = Metrics::INDEX_NB_VISITS
            );

            $recordName = self::buildRecordNameForCustomDimensionId($dimension['idcustomdimension']);
            $this->getProcessor()->insertBlobRecord($recordName, $blob);
        }
    }

    protected function aggregateFromVisits($valueField, $dimensions, $where)
    {
        $query = $this->getLogAggregator()->queryVisitsByDimension($dimensions, $where);

        while ($row = $query->fetch()) {
            $value = $this->cleanCustomDimensionValue($row[$valueField]);

            $this->dataArray->sumMetricsVisits($value, $row);
        }
    }

    protected function aggregateFromConversions($valueField, $dimensions, $where)
    {
        $query = $this->getLogAggregator()->queryConversionsByDimension($dimensions, $where);

        while ($row = $query->fetch()) {
            $value = $this->cleanCustomDimensionValue($row[$valueField]);

            $this->dataArray->sumMetricsGoals($value, $row);
        }
    }

    protected function aggregateFromActions($valueField)
    {
        $metricsConfig = ActionsMetrics::getActionMetrics();

        $metricIds   = array_keys($metricsConfig);
        $metricIds[] = Metrics::INDEX_PAGE_SUM_TIME_SPENT;
        $metricIds[] = Metrics::INDEX_BOUNCE_COUNT;
        $metricIds[] = Metrics::INDEX_PAGE_EXIT_NB_VISITS;
        $this->dataArray->setActionMetricsIds($metricIds);

        $select = "log_link_visit_action.$valueField,
                  actionAlias.name as url,
                  sum(log_link_visit_action.time_spent) as `" . Metrics::INDEX_PAGE_SUM_TIME_SPENT . "`,
                  sum(case visitAlias.visit_total_actions when 1 then 1 when 0 then 1 else 0 end) as `" . Metrics::INDEX_BOUNCE_COUNT . "`,
                  sum(IF(visitAlias.last_idlink_va = log_link_visit_action.idlink_va, 1, 0)) as `" . Metrics::INDEX_PAGE_EXIT_NB_VISITS . "`";

        $select = $this->addMetricsToSelect($select, $metricsConfig);

        $from = array(
            "log_link_visit_action",
            array(
                "table"  => "log_visit",
                "tableAlias"  => "visitAlias",
                "joinOn" => "visitAlias.idvisit = log_link_visit_action.idvisit"
            ),
            array(
                "table"  => "log_action",
                "tableAlias"  => "actionAlias",
                "joinOn" => "log_link_visit_action.idaction_url = actionAlias.idaction"
            )
        );

        $where = "log_link_visit_action.server_time >= ?
                  AND log_link_visit_action.server_time <= ?
                  AND log_link_visit_action.idsite = ?
                  AND log_link_visit_action.$valueField IS NOT NULL";

        $groupBy = "log_link_visit_action.$valueField, url";
        $orderBy = "`" . Metrics::INDEX_PAGE_NB_HITS . "` DESC";

        // get query with segmentation
        $logAggregator = $this->getLogAggregator();
        $query     = $logAggregator->generateQuery($select, $from, $where, $groupBy, $orderBy);
        $db        = $logAggregator->getDb();
        $resultSet = $db->query($query['sql'], $query['bind']);

        while ($row = $resultSet->fetch()) {
            $label = $row[$valueField];
            $value = $this->cleanCustomDimensionValue($label);

            $this->dataArray->sumMetricsActions($value, $row);

            // make sure we always work with normalized URL no matter how the individual action stores it
            $normalized = Tracker\PageUrl::normalizeUrl($row['url']);
            $row['url'] = $normalized['url'];

            $subLabel = $row['url'];

            if (empty($subLabel)) {
                continue;
            }

            $this->dataArray->sumMetricsActionCustomDimensionsPivot($label, $subLabel, $row);
        }
    }

    private function addMetricsToSelect($select, $metricsConfig)
    {
        if (!empty($metricsConfig)) {
            foreach ($metricsConfig as $metric => $config) {
                $select .= ', ' . $config['query'] . " as `" . $metric . "`";
            }
        }

        return $select;
    }

    protected function cleanCustomDimensionValue($value)
    {
        if (isset($value) && strlen($value)) {
            return $value;
        }

        return self::LABEL_CUSTOM_VALUE_NOT_DEFINED;
    }

}