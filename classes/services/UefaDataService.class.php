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
 * Data service for uefa.
 */
class UefaDataService {

	/**
	 * Provides players of a team, grouped by their positions.
	 * 
	 * @param WebSoccer $websoccer Application context.
	 * @param DbConnection $db DB connection
	 * @param int $clubId ID of team
	 * @param string $positionSort ASC|DESC - sort order of position.
	 * @param boolean $considerBlocksForCups if TRUE, then consider only blocked matches for cups, not for league matches.
	 * @return array array with key=converted position ID, value=array of players.
	 */
	public static function getPlayersOfTeamByPosition(WebSoccer $websoccer, DbConnection $db, $clubId, $positionSort = 'ASC', $considerBlocksForCups = FALSE, $considerBlocks = TRUE) {
		$columns = array(
				'id' => 'id', 
				'vorname' => 'firstname', 
				'nachname' => 'lastname', 
				'kunstname' => 'pseudonym', 
				'verletzt' => 'matches_injured', 
				'position' => 'position', 
				'position_main' => 'position_main', 
				'position_second' => 'position_second', 
				'w_staerke' => 'strength', 
				'w_technik' => 'strength_technique', 
				'w_kondition' => 'strength_stamina', 
				'w_frische' => 'strength_freshness', 
				'w_zufriedenheit' => 'strength_satisfaction', 
				'transfermarkt' => 'transfermarket', 
				'nation' => 'player_nationality', 
				'picture' => 'picture',
				'sa_tore' => 'st_goals',
				'sa_spiele' => 'st_matches',
				'sa_karten_gelb' => 'st_cards_yellow',
				'sa_karten_gelb_rot' => 'st_cards_yellow_red',
				'sa_karten_rot' => 'st_cards_red',
				'marktwert' => 'marketvalue'
				);
		
		if ($websoccer->getConfig('players_aging') == 'birthday') {
			$ageColumn = 'TIMESTAMPDIFF(YEAR,geburtstag,CURDATE())';
		} else {
			$ageColumn = 'age';
		}
		$columns[$ageColumn] = 'age';
		
		if ($considerBlocksForCups) {
			$columns['gesperrt_cups'] = 'matches_blocked';
		} else if ($considerBlocks) {
			$columns['gesperrt'] = 'matches_blocked';
		}
		
		$fromTable = $websoccer->getConfig('db_prefix') . '_spieler';
		$whereCondition = 'status = 1 AND verein_id = %d ORDER BY position '. $positionSort . ', position_main ASC, nachname ASC, vorname ASC';
		$result = $db->querySelect($columns, $fromTable, $whereCondition, $clubId, 50);
		
		$players = array();
		while ($player = $result->fetch_array()) {
			$player['position'] = self::_convertPosition($player['position']);
			$player['player_nationality_filename'] = self::getFlagFilename($player['player_nationality']);
			$player['marketvalue'] = PlayersDataService::getMarketValue($websoccer, $db, $player['id']);
			$players[$player['position']][] = $player;
		}
		$result->free();
		
		return $players;
	}

	/**
	 * Updates 'uefa' table and defines how many teams play in europeean cups for each country
	 *
	 * @param WebSoccer $websoccer Application context.
	 * @param DbConnection $db DB connection
	 * @param int $clubId ID of team
	 * @param string $positionSort ASC|DESC - sort order of position.
	 * @param boolean $considerBlocksForCups if TRUE, then consider only blocked matches for cups, not for league matches.
	 * @return array array with key=converted position ID, value=array of players.
	 */
	public static function updateCupTeamNumber($websoccer, $db, $leagues) {
	    
	    echo"<br>";
	    echo"<br>";
	    echo"count: ". count($leagues) ."<br>";
	    echo "<hr>";
	    
	    $max_teams_for_ecup = 64;
	    $max_teams_per_cup_per_league = 4;
	    $uc_teams = 64;
	    $cc_teams = 32;
	    
	    self::createEuropeeanCupTeamsPerLeague($leagues, $max_teams_for_ecup, $max_teams_per_cup_per_league);
	    
	    /*
	    $x=1;
	    $ligen = count($uefas);
	    if($ligen>22) {
	        $ligen = 22;
	    }
	    
	    //CL
	    $cl = range(0,count($uefas)-1);
	    for($i=0;$i<=$ligen-1;$i++) {
	        $cl[$i] = 0;
	    }
	    
	    for($i=0;$i<=150;$i++) {
	        
	        for($j=0;$j<=$ligen-1;$j++) {
	            if($x<=$cl_teams) {
	                $cl[$j] = $cl[$j]+1;
	                echo $i ." - ". $cl[$j] ." - ". $x ."<br>";
	                $x++;
	                
	            }
	        }
	    }
	    print_r($cl);
	    echo"<br>";

	    //UC
	    $uc = range(0,count($uefas)-1);
	    for($i=0;$i<=$ligen-1;$i++) {
	        $uc[$i] = 0;
	    }
	    
	    $x=1;
	    $ligen = count($uefas);
	    if($ligen>22) {
	        $ligen = 22;
	    }
	    for($i=0;$i<=150;$i++) {
	        
	        for($j=0;$j<=$ligen-1;$j++) {
	            if($x<=$uc_teams) {
	                $uc[$j] = $uc[$j]+1;
	                echo $i ." - ". $uc[$j] ." - ". $x ."<br>";
	                $x++;
	                
	            }
	        }
	    }
	    print_r($uc);
	    echo"<br>";
	    */
	    
	}
	
	static function createEuropeeanCupTeamsPerLeague($leagues, $max_teams_for_ecup, $max_teams_per_cup_per_league) {
	    
	    //UC
	    $cup = range(0,count($leagues)-1);
	    for($i=0;$i<=count($leagues)-1;$i++) {
	        $cup[$i] = 0;
	    }
	    
	    $x=1;
	    $leagues = count($leagues);
	    if($cup=="cl" && $leagues>22) {
	        $leagues = 22;
	    }
	    for($i=0;$i<=150;$i++) {
	        
	        for($j=0;$j<=$leagues-1;$j++) {
	            if($x<$max_teams_for_ecup && $cup[$j]<$max_teams_per_cup_per_league) {
	                $cup[$j] = $cup[$j]+1;
	                echo $i ." - ". $cup[$j] ." - ". $x ."<br>";
	                $x++;
	                
	            }
	        }
	    }
	    print_r($cup);
	    echo"<br>";
	}
	
}
?>