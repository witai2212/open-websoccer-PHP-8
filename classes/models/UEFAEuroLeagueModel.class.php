<?php
/******************************************************

This file is part of OpenWebSoccer-Sim.

******************************************************/

require_once __DIR__ . '/../services/InternationalCupPhaseHelper.class.php';

/**
 * Provides UEFA Euro League overview data.
 */
class UEFAEuroLeagueModel implements IModel {
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
        InternationalCupPhaseHelper::selectCurrentPhase(
            $this->_websoccer,
            $this->_db,
            'UEFA Euro League'
        );

        return EuropeanCupDataService::getCupOverview(
            $this->_websoccer,
            $this->_db,
            'UEFA Euro League',
            'uefaeuroleague',
            'uefaeuroleague'
        );
    }
}
?>
