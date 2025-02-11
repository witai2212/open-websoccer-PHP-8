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
            $players[$i]['team_country'] = $playerTeam['team_country'];
			
			//GET CHECK if on Player has an offer
			$hasOffer = self:: checkIfPLayerHasOffer($websoccer, $db, $player['spieler_id'], $teamId);
			$players[$i]['hasoffer'] = $hasOffer;
			
            $i++;
        }
        $result->free();
        
        return $players;
    }
    
    /**
     * Check if playerId is on watchlist
     *
     * @param WebSoccer $websoccer Application context.
     * @param DbConnection $db DB connection.
     * @param int $playerId ID of player and teamId of team
     * @return boolean if player in watchlist.
     */
    public static function checkIfPlayerOnWatchlist(WebSoccer $websoccer, DbConnection $db, $playerId, $teamId) {
        
        $onList = 0;
        
        $queryString = "SELECT * FROM ". $websoccer->getConfig('db_prefix') ."_watchlist
                            WHERE verein_id='$teamId' AND spieler_id='$playerId'";
        $result = $db->executeQuery($queryString);
        $wl = $result->fetch_array();
        $result->free();
        
        if($wl['id']>0) {
            $onList = 1;
        }
        return $onList;
    }
	
	public static function checkIfPLayerHasOffer(WebSoccer $websoccer, DbConnection $db, $playerId, $teamId) {
		
		$bid = 0;
        
        $queryString = "SELECT * FROM ". $websoccer->getConfig('db_prefix') ."_transfer_angebot
                            WHERE verein_id='$teamId' AND spieler_id='$playerId'";
        $result = $db->executeQuery($queryString);
        $tl = $result->fetch_array();
        $result->free();
        
        if($tl['id']>0) {
            $bid = 1;
        }
        return $bid;
		
		
	}

}
?>