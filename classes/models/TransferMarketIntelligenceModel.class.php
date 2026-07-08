<?php
/******************************************************

  This file is part of OpenWebSoccer-Sim.

******************************************************/

class TransferMarketIntelligenceModel implements IModel {
    private $_db;
    private $_i18n;
    private $_websoccer;

    public function __construct($db, $i18n, $websoccer) {
        $this->_db = $db;
        $this->_i18n = $i18n;
        $this->_websoccer = $websoccer;
    }

    public function renderView() {
        return ($this->_websoccer->getConfig('transfermarket_intelligence_enabled') == 1);
    }

    public function getTemplateParameters() {
        $teamId = $this->_websoccer->getUser()->getClubId($this->_websoccer, $this->_db);
        if ($teamId < 1) {
            throw new Exception($this->_i18n->getMessage('feature_requires_team'));
        }

        $parameters = TransferMarketIntelligenceDataService::getManagerAnalysis(
            $this->_websoccer,
            $this->_db,
            $this->_i18n,
            (int) $teamId
        );

        $parameters['market_intelligence_is_admin'] = $this->_websoccer->getUser()->isAdmin();
        if ($parameters['market_intelligence_is_admin']) {
            $parameters = array_merge(
                $parameters,
                TransferMarketIntelligenceDataService::getAdminAnalysis($this->_websoccer, $this->_db)
            );
        }

        return $parameters;
    }
}

?>
