<?php
/******************************************************

  This file is part of OpenWebSoccer-Sim.

  OpenWebSoccer-Sim is free software: you can redistribute it 
  and/or modify it under the terms of the GNU Lesser General Public License 
  as published by the Free Software Foundation, either version 3 of
  the License, or any later version.

******************************************************/

/**
 * Provides historical spectator numbers and stadium attendance statistics.
 */
class StadiumAttendanceModel implements IModel {

    private $_db;
    private $_i18n;
    private $_websoccer;

    public function __construct($db, $i18n, $websoccer) {
        $this->_db = $db;
        $this->_i18n = $i18n;
        $this->_websoccer = $websoccer;
    }

    public function renderView() {
        return TRUE;
    }

    public function getTemplateParameters() {
        $teamId = $this->_websoccer->getUser()->getClubId($this->_websoccer, $this->_db);
        if ($teamId < 1) {
            throw new Exception($this->_i18n->getMessage('feature_requires_team'));
        }

        return array(
            'attendanceStats' => StadiumAttendanceDataService::getRecentAttendanceByTeam($this->_websoccer, $this->_db, $teamId, 10)
        );
    }
}

?>
