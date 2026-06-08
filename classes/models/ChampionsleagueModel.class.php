<?php
/******************************************************

This file is part of OpenWebSoccer-Sim.

OpenWebSoccer-Sim is free software: you can redistribute it
and/or modify it under the terms of the
GNU Lesser General Public License
as published by the Free Software Foundation, either version 3 of
the License, or any later version.

******************************************************/

/**
 * Provides Champions League overview data.
 */
class ChampionsleagueModel implements IModel {
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
        return EuropeanCupDataService::getCupOverview(
            $this->_websoccer,
            $this->_db,
            'Champions League',
            'championsleague',
            'championsleague'
        );
    }
}
?>
