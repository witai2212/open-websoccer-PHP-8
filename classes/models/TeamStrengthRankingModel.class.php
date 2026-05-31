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
 * Provides strongest clubs rankings.
 */
class TeamStrengthRankingModel implements IModel {
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
        $leagueId = (int) $this->_websoccer->getRequestParameter('id');
        if ($leagueId < 1) {
            $user = $this->_websoccer->getUser();
            if (isset($user->id)) {
                $team = TeamsDataService::getTeamByUserId($this->_websoccer, $this->_db, (int) $user->id);
                if (isset($team['team_league_id'])) {
                    $leagueId = (int) $team['team_league_id'];
                }
            }
        }
        
        return array(
            'teams_world' => DestatisDataService::getStrongestTeams($this->_websoccer, $this->_db, 0),
            'teams_league' => ($leagueId > 0) ? DestatisDataService::getStrongestTeams($this->_websoccer, $this->_db, $leagueId) : array(),
            'league_id' => $leagueId,
            'leagues' => LeagueDataService::getLeaguesSortedByCountry($this->_websoccer, $this->_db)
        );
    }
}

?>
