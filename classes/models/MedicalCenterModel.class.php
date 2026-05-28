<?php
/******************************************************

  This file is part of OpenWebSoccer-Sim.

******************************************************/

/**
 * Data for medical center page.
 */
class MedicalCenterModel implements IModel {
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
        $user = $this->_websoccer->getUser();
        $teamId = $user->getClubId($this->_websoccer, $this->_db);
        if ($teamId < 1) {
            return array(
                'enabled' => MedicalCenterDataService::isEnabled($this->_websoccer),
                'human_team' => FALSE,
                'team' => array(),
                'quality' => array('physio' => 0, 'facility' => 0, 'total' => 0),
                'next_match' => array(),
                'injured_players' => array(),
                'risk_players' => array(),
                'treatments' => array(),
                'history' => array()
            );
        }

        return MedicalCenterDataService::getPageData($this->_websoccer, $this->_db, $this->_i18n, $teamId, $user->id);
    }
}
?>
