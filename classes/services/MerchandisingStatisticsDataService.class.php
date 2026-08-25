<?php
/******************************************************

  This file is part of OpenWebSoccer-Sim.

******************************************************/

// CM23 Task 1009 | 2026-08-25 | Revision 1
/**
 * Provides chronological merchandising statistics for graphical evaluation.
 */
class MerchandisingStatisticsDataService {

    /**
     * Returns the most recent sales days in chronological order.
     * Demand, sales and missed demand are taken directly from the recorded
     * merchandising sales rows and are never inferred from each other.
     */
    public static function getTrendStatistics(WebSoccer $websoccer, DbConnection $db, $teamId, $maxPeriods = 30) {
        $teamId = (int) $teamId;
        $maxPeriods = max(1, min(90, (int) $maxPeriods));
        if ($teamId < 1) {
            return array();
        }

        $table = $websoccer->getConfig('db_prefix') . '_merchandising_sales';
        $select = 'DATE(FROM_UNIXTIME(created_date)) AS period_key, '
            . 'MIN(created_date) AS period_timestamp, '
            . 'SUM(demand_units) AS demand_units, '
            . 'SUM(units_sold) AS units_sold, '
            . 'SUM(missed_units) AS missed_units, '
            . 'SUM(revenue) AS revenue, '
            . 'SUM(profit) AS profit';

        $result = $db->querySelect(
            $select,
            $table,
            'team_id = %d GROUP BY DATE(FROM_UNIXTIME(created_date)) ORDER BY period_timestamp DESC',
            $teamId,
            $maxPeriods
        );

        $rows = array();
        while ($row = $result->fetch_array()) {
            $demand = max(0, (int) $row['demand_units']);
            $sold = max(0, (int) $row['units_sold']);
            $missed = max(0, (int) $row['missed_units']);
            $timestamp = (int) $row['period_timestamp'];

            $rows[] = array(
                'period_key' => (string) $row['period_key'],
                'period_timestamp' => $timestamp,
                'period_label' => $timestamp > 0 ? date('d.m.Y', $timestamp) : (string) $row['period_key'],
                'demand_units' => $demand,
                'units_sold' => $sold,
                'missed_units' => $missed,
                'fulfilment_percent' => $demand > 0 ? round(($sold / $demand) * 100, 1) : NULL,
                'revenue' => (int) $row['revenue'],
                'profit' => (int) $row['profit']
            );
        }
        $result->free();

        return array_reverse($rows);
    }
}
?>
