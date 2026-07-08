<?php
/******************************************************

  This file is part of OpenWebSoccer-Sim.

******************************************************/

/**
 * Checks CPU managers after completed matches and replaces them when the board crisis is severe.
 */
class ManagerProfileReplacementJob extends AbstractJob {

    function execute() {
        if (!class_exists('ManagerProfileDataService')) {
            echo "[ManagerProfileReplacementJob] manager profile service missing.\n";
            return;
        }

        $result = ManagerProfileDataService::processCpuManagerReplacements($this->_websoccer, $this->_db, $this->_i18n);
        if (isset($result['skipped']) && $result['skipped']) {
            echo "[ManagerProfileReplacementJob] skipped.\n";
            return;
        }

        echo "[ManagerProfileReplacementJob] CPU clubs checked: " . (int) $result['processed'] . ".\n";
        echo "[ManagerProfileReplacementJob] CPU managers replaced: " . (int) $result['replaced'] . ".\n";
    }
}
?>
