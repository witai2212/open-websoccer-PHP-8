<?php
/******************************************************

  This file is part of OpenWebSoccer-Sim.

******************************************************/

/**
 * Dashboard data for the office training schedule calendar.
 */
class TrainingCalendarModel implements IModel {
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
            throw new Exception($this->_i18n->getMessage("feature_requires_team"));
        }

        return TrainingDataService::getTrainingCalendarData($this->_websoccer, $this->_db, $this->_i18n, $teamId);
    }
}
?>
