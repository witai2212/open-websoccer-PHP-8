<?php
/******************************************************

  This file is part of OpenWebSoccer-Sim.

  OpenWebSoccer-Sim is free software: you can redistribute it
  and/or modify it under the terms of the
  GNU Lesser General Public License as published by the Free Software Foundation,
  either version 3 of the License, or any later version.

******************************************************/

/**
 * Creates and updates manager missions for all human clubs.
 */
class ManagerMissionsJob extends AbstractJob {

    /**
     * @see AbstractJob::execute()
     */
    public function execute() {
        if (!ManagerMissionsDataService::isEnabled($this->_websoccer)) {
            echo "[ManagerMissionsJob] manager missions are disabled.\n";
            return;
        }

        $result = ManagerMissionsDataService::processAllHumanClubs($this->_websoccer, $this->_db, $this->_i18n);

        echo "[ManagerMissionsJob] human clubs processed: " . (int) $result['created_or_updated'] . ".\n";
        echo "[ManagerMissionsJob] ended-season missions finalized: " . (int) $result['finalized'] . ".\n";
    }
}

?>
