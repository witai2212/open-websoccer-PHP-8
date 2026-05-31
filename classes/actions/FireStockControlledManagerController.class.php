<?php
/******************************************************

  This file is part of OpenWebSoccer-Sim.

******************************************************/

/**
 * Allows a majority shareholder (>51%) to dismiss the manager of a listed club.
 */
class FireStockControlledManagerController implements IActionController {
    private $_i18n;
    private $_websoccer;
    private $_db;

    public function __construct(I18n $i18n, WebSoccer $websoccer, DbConnection $db) {
        $this->_i18n = $i18n;
        $this->_websoccer = $websoccer;
        $this->_db = $db;
    }

    public function executeAction($parameters) {
        $user = $this->_websoccer->getUser();
        $ownerTeamId = $user->getClubId($this->_websoccer, $this->_db);
        $stockId = (int) $parameters['stock_id'];

        StockMarketDataService::fireManagerByMajorityOwner($this->_websoccer, $this->_db, $this->_i18n, $stockId, $ownerTeamId, $user->id);

        $this->_websoccer->addFrontMessage(new FrontMessage(MESSAGE_TYPE_SUCCESS,
            $this->_i18n->getMessage('stockmarket_control_fire_success'),
            ''));

        return 'stockmarket';
    }
}
?>
