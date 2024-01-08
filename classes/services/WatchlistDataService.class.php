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
 * Data service for watchlist
 */
class WatchlistDataService {
	
	/**
	 * Provides list of players on mywatchlist
	 * 
	 * @param WebSoccer $websoccer Application context.
	 * @param DbConnection $db DB connection.
	 * @param int $teamId ID of team
	 * @return array of players on watchlist which belongs to the specified team.
	 */
    public static function getMyWatchlist(WebSoccer $websoccer, DbConnection $db, $teamId) {
        
        $queryString = "SELECT wl.*, s.*
                FROM ". $websoccer->getConfig('db_prefix') ."_watchlist AS wl,
                    ". $websoccer->getConfig('db_prefix') ."_spieler AS s
                WHERE wl.verein_id='$teamId' 
                    AND s.id=wl.spieler_id";
        $result = $db->executeQuery($queryString);
        
        $i=0;
        $players = array();
        while ($player = $result->fetch_array()) {
            
            $players[$i] = $player;
            
            //GET PLAYERS TEAM DATA
            $playerTeam = TeamsDataService::getTeamById($websoccer, $db, $player['verein_id']);
            $players[$i]['team_name'] = $playerTeam['team_name'];
            $i++;
        }
        $result->free();
        
        return $players;
    }
}
?>