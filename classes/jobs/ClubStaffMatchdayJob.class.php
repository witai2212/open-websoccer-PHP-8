<?php
/******************************************************

  This file is part of OpenWebSoccer-Sim.

******************************************************/

/**
 * Processes staff salary costs after newly completed first-team matches.
 */
class ClubStaffMatchdayJob extends AbstractJob {

    function execute() {
        if (!ClubStaffDataService::isEnabled($this->_websoccer)) {
            echo "[ClubStaffMatchdayJob] club staff is disabled.\n";
            return;
        }

        $result = ClubStaffDataService::processMatchdaySalaries($this->_websoccer, $this->_db);
        if (isset($result['skipped']) && $result['skipped']) {
            echo "[ClubStaffMatchdayJob] no new completed matches.\n";
            return;
        }

        echo "[ClubStaffMatchdayJob] processed teams: " . (int) $result['processed'] . ".\n";
        echo "[ClubStaffMatchdayJob] total salaries: " . (int) $result['amount'] . ".\n";
    }
}
?>
