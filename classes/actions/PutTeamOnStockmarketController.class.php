<?php
/******************************************************

This file is part of OpenWebSoccer-Sim.

OpenWebSoccer-Sim is free software: you can redistribute it
and/or modify it under the terms of the
GNU Lesser General Public License
as published by the Free Software Foundation, either version 3 of
the License, or any later version.

OpenWebSoccer-Sim is distributed in the hope that it will be
useful, but WITHOUT ANY WARRANTY; without even the implied
warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
See the GNU Lesser General Public License for more details.

You should have received a copy of the GNU Lesser General Public
License along with OpenWebSoccer-Sim.
If not, see <http://www.gnu.org/licenses/>.

******************************************************/

/**
 * Manager of a player can remove player from transfer market
 * as long as there is no bid for him.
 */
class PutTeamOnStockmarketController implements IActionController {
    private $_i18n;
    private $_websoccer;
    private $_db;
    
    public function __construct(I18n $i18n, WebSoccer $websoccer, DbConnection $db) {
        $this->_i18n = $i18n;
        $this->_websoccer = $websoccer;
        $this->_db = $db;
    }
    
    /**
     * (non-PHPdoc)
     * @see IActionController::executeAction()
     */
    //public function executeAction($parameters) {
    public function executeAction($parameters) {
        
        $user = $this->_websoccer->getUser();
        $teamId = $user->getClubId($this->_websoccer, $this->_db);
        
        //GET TEAM DATA
        $team = TeamsDataService::getTeamById($this->_websoccer, $this->_db, $teamId);
        $team_name = $team['team_name'];
        $team_abbrev = $team['team_short'];
        
        if(!isset($team_abbrev)) {
            $team_abbrev = substr($team_name, 0, 5);
        }

        //GET STOCK DATA
        $club_value = StockMarketDataService::clubValue($this->_websoccer, $this->_db, $teamId);
        $stock_qty = round($club_value/50,0);
        
        //PUT TEAM ON STOCKMARKET
        //echo $teamId." - ".$team_abbrev." - ".$team_name." - ".$stock_qty."<br>";
        StockMarketDataService::putTeamOnStockmarket($this->_websoccer, $this->_db, $teamId, $team_abbrev, $team_name, $stock_qty, '50');
          
        // credit / debit amount
        BankAccountDataService::creditAmount($this->_websoccer, $this->_db, $teamId, $club_value, "team_on_stockmarket_message", "sender_name");
        
        // success message
        $this->_websoccer->addFrontMessage(new FrontMessage(MESSAGE_TYPE_SUCCESS,
            $this->_i18n->getMessage("team_now_on_stockmarket"),
            ""));
            
        return "stockmarket";
    }
    
}

?>