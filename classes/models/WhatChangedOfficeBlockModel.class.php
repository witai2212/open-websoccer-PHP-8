<?php
/******************************************************

  This file is part of OpenWebSoccer-Sim.

******************************************************/

/**
 * Provides compact latest "What changed?" data for the office overview.
 */
class WhatChangedOfficeBlockModel implements IModel {
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

        return array(
            'what_changed_block' => WhatChangedDataService::getOfficeBlockData(
                $this->_websoccer,
                $this->_db,
                $this->_i18n,
                (int) $user->id,
                (int) $teamId
            )
        );
    }
}

?>
