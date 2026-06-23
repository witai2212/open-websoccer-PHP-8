<?php
/******************************************************

This file is part of OpenWebSoccer-Sim.

******************************************************/

/**
 * Provides CONMEBOL ranking.
 */
class CONMEBOLRankingModel implements IModel {
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
        return array(
            'conmebols' => ConmebolDataService::getConmebolRanking($this->_websoccer, $this->_db)
        );
    }
}
?>
