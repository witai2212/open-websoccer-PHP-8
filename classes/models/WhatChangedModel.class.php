<?php
/******************************************************

  This file is part of OpenWebSoccer-Sim.

******************************************************/

/**
 * Provides data for the "What changed?" dashboard.
 */
class WhatChangedModel implements IModel {
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
            throw new Exception($this->_i18n->getMessage('feature_requires_team'));
        }

        $summaryId = (int) $this->_websoccer->getRequestParameter('id');
        $matchId = (int) $this->_websoccer->getRequestParameter('match_id');

        return WhatChangedDataService::getPageData(
            $this->_websoccer,
            $this->_db,
            $this->_i18n,
            (int) $user->id,
            (int) $teamId,
            $summaryId,
            $matchId
        );
    }
}

?>
