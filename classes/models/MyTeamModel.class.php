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
 * Provides players of own team.
 */
class MyTeamModel implements IModel {
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
		
		$teamId = $this->_websoccer->getUser()->getClubId($this->_websoccer, $this->_db);
		$captain_id = TeamsDataService::getTeamCaptainIdOfTeam($this->_websoccer, $this->_db, $teamId);
		
		$players = array();
		if ($teamId > 0) {
			$players = PlayersDataService::getPlayersOfTeamById($this->_websoccer, $this->_db, $teamId);
		}
		
        foreach ($players as &$player) {
            $player["accepted_precontract"] = PlayerPrecontractDataService::getAcceptedByPlayer($this->_websoccer, $this->_db, $player["id"]);
        }
        unset($player);
        $incomingPlayers = ($teamId > 0) ? PlayerPrecontractDataService::getIncoming($this->_websoccer, $this->_db, $teamId) : array();
        return array("players" => $players, "incoming_players" => $incomingPlayers, "captain_id" => $captain_id, "show_traits" => TRUE);
	}
	
}

?>