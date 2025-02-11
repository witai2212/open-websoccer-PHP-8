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
class AvgGatesModel implements IModel {
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
	    
	    $avgGates = null;
		
		$user = $this->_websoccer->getUser();
	    $userId = $user->id;
	    
	    $team = TeamsDataService::getTeamByUserId($this->_websoccer, $this->_db, $userId);
		$leagueId = $team['team_league_id'];
	    
	    $avgGates = DestatisDataService::getAvgGatesByLeagueId($this->_websoccer, $this->_db, $leagueId);
		
		$most_visitors_clubs = DestatisDataService::highestClubStadiumVisitorsByClub($this->_websoccer, $this->_db);
		$most_visitors_leagues = DestatisDataService::highestClubStadiumVisitorsByLeague($this->_websoccer, $this->_db);
		
	    return array("avg_gates" => $avgGates, "most_visitors_clubs" => $most_visitors_clubs, "most_visitors_leagues" => $most_visitors_leagues);
		
	}
	
}

?>