<?php
/**
 * CM23 Task 1011
 * Date: 2026-08-26
 * Revision: 1
 */
class PlayerAttributeLimitsJob extends AbstractJob {

    function execute() {
        $this->limitPlayerAttributes();
        PlayersDataService::playerStrengthCorrection($this->_websoccer, $this->_db);

        // Process the oldest 100 player market values per daily run.
        MarketValueMaintenanceService::run($this->_websoccer, $this->_db, 'all', 0, false, 100, 0);
    }

    private function limitPlayerAttributes() {
        $tableName = $this->_websoccer->getConfig('db_prefix') . '_spieler';
        $result = $this->_db->executeQuery('SHOW COLUMNS FROM `' . $tableName . '`');

        $assignments = array();
        $conditions = array();

        while ($column = $result->fetch_array()) {
            $columnName = $column['Field'];
            if (!preg_match('/^w_[a-z0-9_]+$/i', $columnName)) {
                continue;
            }

            $quotedColumn = '`' . $columnName . '`';
            $numericColumn = 'CAST(' . $quotedColumn . ' AS DECIMAL(10,2))';

            $assignments[] = $quotedColumn . ' = CASE'
                . ' WHEN ' . $numericColumn . ' > 100 THEN 100'
                . ' WHEN ' . $numericColumn . ' < 0 THEN 0'
                . ' ELSE ' . $quotedColumn . ' END';
            $conditions[] = $numericColumn . ' > 100';
            $conditions[] = $numericColumn . ' < 0';
        }
        $result->free();

        if (count($assignments) === 0) {
            return;
        }

        $query = 'UPDATE `' . $tableName . '` SET ' . implode(', ', $assignments)
            . ' WHERE ' . implode(' OR ', $conditions);
        $this->_db->executeQuery($query);
    }
}
?>
