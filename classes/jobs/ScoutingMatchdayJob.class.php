<?php
/******************************************************

  This file is part of OpenWebSoccer-Sim.

  OpenWebSoccer-Sim is free software: you can redistribute it
  and/or modify it under the terms of the
  GNU Lesser General Public License as published by the Free Software Foundation,
  either version 3 of the License, or any later version.

******************************************************/

/**
 * Processes scouting costs, scout contract duration, scouting camp progress
 * and generated scouting proposals.
 *
 * IMPORTANT:
 * This job is guarded and only runs the scouting logic if at least one new
 * completed match exists since the last scouting processing. This prevents
 * scouts/camps from being decremented on every cron tick.
 */
class ScoutingMatchdayJob extends AbstractJob {

    const CONFIG_MARKER_NAME = 'scouting_matchday';

    /**
     * @see AbstractJob::execute()
     */
    function execute() {
        if (!$this->_websoccer->getConfig('scouting_enabled')) {
            echo "[ScoutingMatchdayJob] scouting is disabled.\n";
            return;
        }

        $prefix = $this->_websoccer->getConfig('db_prefix');
        $lastProcessedMatchId = $this->getLastProcessedMatchId($prefix);
        $latestCompletedMatchId = $this->getLatestCompletedMatchId($prefix);

        if ($latestCompletedMatchId <= 0) {
            echo "[ScoutingMatchdayJob] no completed matches found.\n";
            return;
        }

        if ($latestCompletedMatchId <= $lastProcessedMatchId) {
            echo "[ScoutingMatchdayJob] no new completed matches. last=" . $lastProcessedMatchId . ", latest=" . $latestCompletedMatchId . "\n";
            return;
        }

        echo "[ScoutingMatchdayJob] processing scouting matchday. last=" . $lastProcessedMatchId . ", latest=" . $latestCompletedMatchId . "\n";

        ScoutingDataService::processMatchdayScouting($this->_websoccer, $this->_db);

        $this->setLastProcessedMatchId($prefix, $latestCompletedMatchId);

        echo "[ScoutingMatchdayJob] finished. marker updated to " . $latestCompletedMatchId . "\n";
    }

    private function getLatestCompletedMatchId($prefix) {
        $sql = "SELECT MAX(id) AS latest_completed_match_id
                FROM " . $prefix . "_spiel
                WHERE berechnet = '1'";
        $result = $this->_db->executeQuery($sql);
        $row = $result->fetch_assoc();
        $result->free();

        return (isset($row['latest_completed_match_id'])) ? (int) $row['latest_completed_match_id'] : 0;
    }

    private function getLastProcessedMatchId($prefix) {
        $this->ensureMarkerExists($prefix);

        $sql = "SELECT zeitstempel
                FROM " . $prefix . "_config
                WHERE name = '" . self::CONFIG_MARKER_NAME . "'
                LIMIT 1";
        $result = $this->_db->executeQuery($sql);
        $row = $result->fetch_assoc();
        $result->free();

        return (isset($row['zeitstempel'])) ? (int) $row['zeitstempel'] : 0;
    }

    private function setLastProcessedMatchId($prefix, $matchId) {
        $sql = "UPDATE " . $prefix . "_config
                SET zeitstempel = '" . (int) $matchId . "'
                WHERE name = '" . self::CONFIG_MARKER_NAME . "'";
        $this->_db->executeQuery($sql);
    }

    private function ensureMarkerExists($prefix) {
        $sql = "SELECT id
                FROM " . $prefix . "_config
                WHERE name = '" . self::CONFIG_MARKER_NAME . "'
                LIMIT 1";
        $result = $this->_db->executeQuery($sql);
        $row = $result->fetch_assoc();
        $result->free();

        if ($row && isset($row['id'])) {
            return;
        }

        $this->_db->queryInsert(array(
            'name' => self::CONFIG_MARKER_NAME,
            'zeitstempel' => 0,
            'descr' => 'Scouting matchday marker'
        ), $prefix . '_config');
    }
}

?>
