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
 * Provides bst players of the world
 */
class BestPlayersModel implements IModel {
	private $_db;
	private $_i18n;
	private $_websoccer;
	
	public function __construct($db, $i18n, $websoccer) {
		$this->_db = $db;
		$this->_i18n = $i18n;
		$this->_websoccer = $websoccer;
	}
	
	/**
	 * (non-PHPdoc)
	 * @see IModel::renderView()
	 */
	public function renderView() {
		return TRUE;
	}
	
	/**
	 * (non-PHPdoc)
	 * @see IModel::getTemplateParameters()
	 */
	public function getTemplateParameters() {
	    
	    $bestplayers = PlayersDataService::getBestPlayersByStrength($this->_websoccer, $this->_db);
	    
	    // Attach watchlist status for the currently logged-in manager's club.
	    // Transfer-list status is already part of the player data returned above.
	    $watchlistPlayerIds = array();
	    $teamId = $this->_websoccer->getUser()->getClubId($this->_websoccer, $this->_db);
	    
	    if ($teamId > 0) {
	        $result = $this->_db->querySelect(
	            'spieler_id',
	            $this->_websoccer->getConfig('db_prefix') . '_watchlist',
	            'verein_id = %d',
	            $teamId
	        );
	        while ($watchlistEntry = $result->fetch_array()) {
	            $watchlistPlayerIds[(int) $watchlistEntry['spieler_id']] = TRUE;
	        }
	        $result->free();
	    }
	    
	    foreach ($bestplayers as $index => $player) {
	        $playerId = isset($player['id']) ? (int) $player['id'] : 0;
	        $bestplayers[$index]['on_watchlist'] = ($playerId > 0 && isset($watchlistPlayerIds[$playerId])) ? 1 : 0;
	    }
	    
		return array("players" => $bestplayers);
	}
	
}

?>