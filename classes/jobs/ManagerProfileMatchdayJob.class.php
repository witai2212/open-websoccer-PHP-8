<?php
/******************************************************

  This file is part of OpenWebSoccer-Sim.

******************************************************/

/**
 * Processes manager salary costs after newly completed first-team matches.
 */
class ManagerProfileMatchdayJob extends AbstractJob {

    function execute() {
        if (!class_exists('ManagerProfileDataService')) {
            echo "[ManagerProfileMatchdayJob] manager profile service missing.\n";
            return;
        }

        $result = ManagerProfileDataService::processMatchdaySalaries($this->_websoccer, $this->_db);
        if (isset($result['skipped']) && $result['skipped']) {
            echo "[ManagerProfileMatchdayJob] skipped.\n";
            return;
        }

        echo "[ManagerProfileMatchdayJob] processed teams: " . (int) $result['processed'] . ".\n";
        echo "[ManagerProfileMatchdayJob] total salaries: " . (int) $result['amount'] . ".\n";
    }
}
?>
