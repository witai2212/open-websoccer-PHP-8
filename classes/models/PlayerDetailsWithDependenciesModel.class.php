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
 * @author Ingo Hofmann
 */
class PlayerDetailsWithDependenciesModel implements IModel {
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
	    
	    $scouting = null;
	    $correctScout = null;
		
		$playerId = (int) $this->_websoccer->getRequestParameter("id");
		if ($playerId < 1) {
			throw new Exception($this->_i18n->getMessage(MSG_KEY_ERROR_PAGENOTFOUND));
		}
		
		$userTeam = $this->_websoccer->getUser()->getClubId($this->_websoccer, $this->_db);
		
		//strength + marketvalue update
		//PlayersStrengthDataService::updateAllPlayersMarketAndStrengthByPlayerId($this->_websoccer, $this->_db, $playerId);
		PlayersStrengthDataService::calculatePlayerStats($this->_websoccer, $this->_db, $playerId);
		
		$player = PlayersDataService::getPlayerById($this->_websoccer, $this->_db, $playerId);
		$watchlist = PlayersDataService::whoIsWatchingPlayerId($this->_websoccer, $this->_db, $playerId);
		$onMyWhatchlist = WatchlistDataService::checkIfPlayerOnWatchlist($this->_websoccer, $this->_db, $playerId, $userTeam);
		
		if (!isset($player["player_id"])) {
			throw new Exception($this->_i18n->getMessage(MSG_KEY_ERROR_PAGENOTFOUND));
		}
		
		$grades = $this->_getGrades($playerId);
		
		$transfers = TransfermarketDataService::getCompletedTransfersOfPlayer($this->_websoccer, $this->_db, $playerId);
		
		//check if player is on watchlist from this user
		$onWL = WatchlistDataService::checkIfPlayerOnWatchlist($this->_websoccer, $this->_db, $playerId, $userTeam);
		//check if scout has same spaciality as player's position
		$correctScout = ScoutingDataService::getScoutByTeamSpeciality($this->_websoccer, $this->_db, $userTeam, $player['player_position_de']);

		if($onWL>0 && $correctScout>0) {
		  
    		$scouting = ScoutingDataService::getTalentEvaluation($this->_websoccer, $this->_db, $userTeam, $playerId);
    		
    		if(!isset($_SESSION['scouting_result'])) {
    		    $_SESSION['scouting_result'] = $scouting;
    		} else {
    		    $scouting = $_SESSION['scouting_result'];
    		}
		}

		return array("player" => $player, "grades" => $grades, "completedtransfers" => $transfers, "watchlist" => $watchlist,
		              "onmywatchlist" => $onMyWhatchlist, "scouting" => $scouting);
	}
	
	private function _getGrades($playerId) {
		$grades = array();
		
		$fromTable = $this->_websoccer->getConfig("db_prefix") ."_spiel_berechnung";
		
		$columns = "note AS grade";
		
		$whereCondition = "spieler_id = %d AND minuten_gespielt > 0 ORDER BY id DESC";
		$parameters = $playerId;
		
		$result = $this->_db->querySelect($columns, $fromTable, $whereCondition, $parameters, 10);
		while ($grade = $result->fetch_array()) {
			$grades[] = $grade["grade"];
		}		
		
		$grades = array_reverse($grades);
		
		return $grades;
	}
	
}

?>