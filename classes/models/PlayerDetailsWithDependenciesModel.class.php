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
// CM23 | 2026-09-07 | Revision 1 | Task 1022

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
		
		$userTeam = 0;
		if ($this->_websoccer->getUser()) {
			$userTeam = $this->_websoccer->getUser()->getClubId($this->_websoccer, $this->_db);
		}
		
		$player = PlayersDataService::getPlayerById($this->_websoccer, $this->_db, $playerId);
		$watchlist = PlayersDataService::whoIsWatchingPlayerId($this->_websoccer, $this->_db, $playerId);
		$onMyWhatchlist = WatchlistDataService::checkIfPlayerOnWatchlist($this->_websoccer, $this->_db, $playerId, $userTeam);
		
		if (!isset($player["player_id"])) {
			throw new Exception($this->_i18n->getMessage(MSG_KEY_ERROR_PAGENOTFOUND));
		}
		
		$grades = $this->_getGrades($playerId);
		$marketValueHistory = $this->_getMarketValueHistory($playerId);
		
		$transfers = TransfermarketDataService::getCompletedTransfersOfPlayer($this->_websoccer, $this->_db, $playerId);
		
		$talentVisibility = PlayerTalentVisibilityDataService::getVisibility($this->_websoccer, $this->_db, $player, $userTeam, $onMyWhatchlist);
		$scouting = PlayerTalentVisibilityDataService::isAccessVisible($talentVisibility) ? $talentVisibility : null;

		$showPersonality = PlayerPersonalityDataService::isVisibleForUser($this->_websoccer, $this->_db, $player['team_id'], $scouting);
		$showTraits = PlayerTraitsDataService::isVisibleForUser($this->_websoccer, $this->_db, $player['team_id'], $scouting);

		$precontractEligible = PlayerPrecontractDataService::isEligible($this->_websoccer, $this->_db, $playerId);
		$acceptedPrecontract = PlayerPrecontractDataService::getAcceptedByPlayer($this->_websoccer, $this->_db, $playerId);
		$myPrecontractOffer = ($userTeam > 0)
			? PlayerPrecontractDataService::getOfferByPlayerAndTeam($this->_websoccer, $this->_db, $playerId, $userTeam)
			: array();

		return array("player" => $player, "grades" => $grades, "marketValueHistory" => $marketValueHistory,
		              "completedtransfers" => $transfers, "watchlist" => $watchlist,
		              "onmywatchlist" => $onMyWhatchlist, "scouting" => $scouting, "show_personality" => $showPersonality,
		              "show_traits" => $showTraits, "talent_visibility" => $talentVisibility,
		              "precontract_eligible" => $precontractEligible, "accepted_precontract" => $acceptedPrecontract,
		              "my_precontract_offer" => $myPrecontractOffer);
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

	private function _getMarketValueHistory($playerId) {
		$history = array();
		$fromTable = $this->_websoccer->getConfig("db_prefix") . "_spieler_marktwert_historie";
		$columns = array(
			"snapshot_date" => "snapshot_date",
			"marktwert" => "marketvalue"
		);
		$whereCondition = "spieler_id = %d ORDER BY snapshot_date DESC";
		$result = $this->_db->querySelect($columns, $fromTable, $whereCondition, $playerId, 90);
		while ($row = $result->fetch_array()) {
			$history[] = array(
				"date" => $row["snapshot_date"],
				"value" => (int) $row["marketvalue"]
			);
		}
		$result->free();
		return array_reverse($history);
	}
	
}

?>