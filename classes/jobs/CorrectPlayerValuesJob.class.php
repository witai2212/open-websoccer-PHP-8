<?php
class CorrectPlayerValuesJob extends AbstractJob {
    function execute() {
        PlayersDataService::playerStrengthCorrection($this->_websoccer, $this->_db);
        // Existing job remains the automatic engine. It processes the oldest 100 players per run.
        MarketValueMaintenanceService::run($this->_websoccer, $this->_db, 'all', 0, false, 100, 0);
    }
}
?>
