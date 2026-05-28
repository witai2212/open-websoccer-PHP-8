<?php
/******************************************************

  This file is part of OpenWebSoccer-Sim.

******************************************************/

/**
 * Data for club staff page.
 */
class ClubStaffModel implements IModel {
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
                'enabled' => ClubStaffDataService::isEnabled($this->_websoccer),
                'human_team' => false,
                'team' => array(),
                'hired_staff' => array(),
                'available_staff' => array(),
                'effects' => array(),
                'total_salary' => 0
            );
        }

        return ClubStaffDataService::getPageData($this->_websoccer, $this->_db, $this->_i18n, $teamId, $user->id);
    }
}
?>
