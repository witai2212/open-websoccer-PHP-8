<?php
/******************************************************

  This file is part of OpenWebSoccer-Sim.

******************************************************/

/**
 * Model for Youth Academy 2.0 page.
 */
class YouthAcademyModel implements IModel {
    private $_db;
    private $_i18n;
    private $_websoccer;

    public function __construct($db, $i18n, $websoccer) {
        $this->_db = $db;
        $this->_i18n = $i18n;
        $this->_websoccer = $websoccer;
    }

    public function renderView() {
        return $this->_websoccer->getConfig('youth_enabled') && YouthAcademyDataService::isEnabled($this->_websoccer);
    }

    public function getTemplateParameters() {
        $user = $this->_websoccer->getUser();
        $teamId = $user->getClubId($this->_websoccer, $this->_db);

        return YouthAcademyDataService::getPageData($this->_websoccer, $this->_db, $this->_i18n, $teamId, $user->id);
    }
}
?>
