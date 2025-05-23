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
define('NUMBER_OF_PLAYERS', 20);

/**
 * Provides list of actual stock exchange market values.
 */
class MyPortfolioModel implements IModel {
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
        
        $userId = $this->_websoccer->getUser()->id;
        if ($userId < 1) {
            throw new Exception($this->_i18n->getMessage(MSG_KEY_ERROR_PAGENOTFOUND));
        }
        $team = TeamsDataService::getTeamByUserId($this->_websoccer, $this->_db, $userId);
        $teamId = $team['team_id'];
        
        $indexes = StockMarketDataService::getUserPortfolio($this->_websoccer, $this->_db, $teamId);
		
		//echo str_replace("world","Peter","Hello world!");
		//$indexes['v1'] = str_replace(',', '.', $indexes['v1']);
		//$indexes['price'] = str_replace(',', '.', $indexes['price']);
		
		/*echo"<pre>";
		print_r($indexes);
		echo"</pre>";*/
        
        return array("indexes" => $indexes);
    }
    
}

?>