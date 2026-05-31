<?php
/******************************************************

This file is part of OpenWebSoccer-Sim.

******************************************************/

/**
 * Limited Destatis economy view. It is restricted to users whose account e-mail
 * also belongs to an admin account with r_admin = 1.
 */
class FinancialEconomyStatsModel implements IModel {
    private $_db;
    private $_i18n;
    private $_websoccer;

    public function __construct($db, $i18n, $websoccer) {
        $this->_db = $db;
        $this->_i18n = $i18n;
        $this->_websoccer = $websoccer;
    }

    public function renderView() {
        return $this->_websoccer->getUser()->isAdmin();
    }

    public function getTemplateParameters() {
        $seasonId = (int) $this->_websoccer->getRequestParameter('season_id');
        $matchday = (int) $this->_websoccer->getRequestParameter('matchday');

        $seasons = FinancialEconomyStatsDataService::getAvailableSeasons($this->_websoccer, $this->_db);
        $matchdays = FinancialEconomyStatsDataService::getAvailableMatchdays($this->_websoccer, $this->_db, $seasonId);

        $allStats = FinancialEconomyStatsDataService::getEconomyStats($this->_websoccer, $this->_db, $seasonId, $matchday, FALSE);
        $humanStats = FinancialEconomyStatsDataService::getEconomyStats($this->_websoccer, $this->_db, $seasonId, $matchday, TRUE);

        return array(
            'seasons' => $seasons,
            'matchdays' => $matchdays,
            'selected_season_id' => $seasonId,
            'selected_matchday' => $matchday,
            'all_stats' => $allStats,
            'human_stats' => $humanStats
        );
    }
}
?>
