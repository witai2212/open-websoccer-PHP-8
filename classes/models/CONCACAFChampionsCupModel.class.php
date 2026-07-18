<?php
/******************************************************

This file is part of OpenWebSoccer-Sim.

******************************************************/

/**
 * Provides CONCACAF Champions Cup overview data.
 */
class CONCACAFChampionsCupModel implements IModel {
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
        $cupName = class_exists('ConcacafDataService')
            ? ConcacafDataService::CONCACAF_CHAMPIONS_CUP
            : 'CONCACAF Champions Cup';

        return EuropeanCupDataService::getCupOverview(
            $this->_websoccer,
            $this->_db,
            $cupName,
            'concacafchampionscup',
            'concacafchampionscup'
        );
    }
}
?>
