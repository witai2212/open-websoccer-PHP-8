<?php
/******************************************************

  This file is part of OpenWebSoccer-Sim.

******************************************************/

/**
 * Provides squad analysis for the user's current club.
 */
class SquadPlannerModel implements IModel {
    private $_db;
    private $_i18n;
    private $_websoccer;

    public function __construct($db, $i18n, $websoccer) {
        $this->_db = $db;
        $this->_i18n = $i18n;
        $this->_websoccer = $websoccer;
    }

    public function renderView() {
        return $this->_websoccer->getConfig('squadplanner_enabled');
    }

    public function getTemplateParameters() {
        $teamId = $this->_websoccer->getUser()->getClubId($this->_websoccer, $this->_db);
        if ($teamId < 1) {
            throw new Exception($this->_i18n->getMessage('feature_requires_team'));
        }

        return SquadPlannerDataService::getAnalysis($this->_websoccer, $this->_db, $this->_i18n, (int) $teamId);
    }
}

?>
