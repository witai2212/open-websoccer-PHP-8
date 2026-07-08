<?php
/******************************************************

  This file is part of OpenWebSoccer-Sim.

******************************************************/

/**
 * Provides data for the current user's manager career center.
 */
class ManagerCareerModel implements IModel {
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
        $userId = (int) $user->id;

        $career = ManagerCareerDataService::getCareerPageData(
            $this->_websoccer,
            $this->_db,
            $this->_i18n,
            $userId,
            $userId
        );

        return array('career' => $career, 'profile_user_id' => $userId);
    }
}
?>
