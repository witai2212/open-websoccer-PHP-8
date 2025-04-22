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
	
	/**
	 * Updates 'uefa' table of uefa places by _land table
	 *
	 * @param WebSoccer $websoccer Application context.
	 * @param DbConnection $db DB connection
	 * @param int $clubId ID of team
	 * @param string $positionSort ASC|DESC - sort order of position.
	 * @param boolean $considerBlocksForCups if TRUE, then consider only blocked matches for cups, not for league matches.
	 * @return array Array with places for uefa cups by land.
	 */
	public static function getUefaPlacesByLand(WebSoccer $websoccer, DbConnection $db) {
		
		$uefa_places = array();
		$teams = array();
		$j = 0;
		
		// get uefa place from land
	    $queryString1 = "SELECT name, uefa_cl, uefa_ul, uefa_conf
							FROM ". $websoccer->getConfig('db_prefix') ."_land
							ORDER BY uefa_cl DESC, uefa_ul DESC, uefa_conf DESC, uefa_coeff DESC, (uefa_s1+uefa_s2+uefa_s3+uefa_s4+uefa_s5) DESC, name ASC";
	    $result1 = $db->executeQuery($queryString1);
	    while ($place = $result1->fetch_array()) {
	        $uefa_places[] = $place;			
	    }
	    $result1->free();
		
		
		for($i=0;$i<=count($uefa_places);$i++) {
				
			$places = $uefa_places[$i]['uefa_cl']+$uefa_places[$i]['uefa_ul']+$uefa_places[$i]['uefa_conf'];
			
			$cl_min = 0;
			$cl_max = $uefa_places[$i]['uefa_cl']-1;
			$cl_max1 = $uefa_places[$i]['uefa_cl'];
			
			$ul_min = $uefa_places[$i]['uefa_cl'];
			$ul_max = $ul_min + $uefa_places[$i]['uefa_ul']-1;
			$ul_max1 = $uefa_places[$i]['uefa_ul'];
			
			$conf_min = $ul_max+1;
			$conf_max = $conf_min + $uefa_places[$i]['uefa_conf']-1;
			
			$land = $uefa_places[$i]['name'];
			
			//######### CORRECTION ############
			if($uefa_places[$i]['uefa_cl']=='1') {
				$cl_min = 0;
				$cl_max1 = 1;
			}
			if($uefa_places[$i]['uefa_ul']=='1') {
				$ul_min = 0;
				$ul_max = 1;
				$ul_max1 = 1;
			}
			
			// get CL teams
			if($cl_max>=0) {
				
				// get CL teams by places
				$queryString_cl = "SELECT C.id AS club_id, C.name AS club_name
								FROM ". $websoccer->getConfig('db_prefix') ."_verein AS C
									INNER JOIN ". $websoccer->getConfig('db_prefix') ."_land AS L ON  L.name = '$land'
									INNER JOIN ". $websoccer->getConfig('db_prefix') ."_liga AS LG ON LG.land=L.name
								WHERE C.liga_id=LG.id AND LG.division='1'
								ORDER BY C.sa_punkte DESC, C.sa_tore DESC, (C.sa_tore-C.sa_gegentore) DESC
								LIMIT $cl_min, $cl_max1";
				//echo $queryString_cl ."<br>";
				$result_cl = $db->executeQuery($queryString_cl);
				while ($team_cl = $result_cl->fetch_array()) {
					$teams[] = $team_cl;
					
					// save to uefa_temp table
					$club_id_cl = $team_cl['club_id'];
					$cup_id_cl = '2';
					
					$insString1 = "INSERT INTO ". $websoccer->getConfig('db_prefix') ."_uefa_temp (id, verein_id, cup_id) VALUES (NULL, '$club_id_cl', '$cup_id_cl');";
					$db->executeQuery($insString1);
					
				}
				$result_cl->free();
			}
			
			// get UL teams
			if($ul_max>=0) {
				
				// get CL teams by places
				$queryString_ul = "SELECT C.id AS club_id, C.name AS club_name
								FROM ". $websoccer->getConfig('db_prefix') ."_verein AS C
									INNER JOIN ". $websoccer->getConfig('db_prefix') ."_land AS L ON  L.name = '$land'
									INNER JOIN ". $websoccer->getConfig('db_prefix') ."_liga AS LG ON LG.land=L.name
								WHERE C.liga_id=LG.id AND LG.division='1'
								ORDER BY C.sa_punkte DESC, C.sa_tore DESC, (C.sa_tore-C.sa_gegentore) DESC
								LIMIT $ul_min, $ul_max1";
				//echo $queryString_cl ."<br>";
				$result_ul = $db->executeQuery($queryString_ul);
				while ($team_ul = $result_ul->fetch_array()) {
					$teams[] = $team_ul;
					
					// save to uefa_temp table
					$club_id_ul = $team_ul['club_id'];
					$cup_id_ul = '3';
					
					$insString2 = "INSERT INTO ". $websoccer->getConfig('db_prefix') ."_uefa_temp (id, verein_id, cup_id) VALUES (NULL, '$club_id_ul', '$cup_id_ul');";
					//echo $insString2 ."<br>";
					$db->executeQuery($insString2);
					//echo $j ." - ". $ul_min ." , ". $ul_max  ." - ". $land ."<br>";
					$j++;
					
				}
				$result_ul->free();
			}
		}	
	    
	    return $teams;
	}
	
	
	/**
	 * get teams from uefa temp table
	 *
	 * @param WebSoccer $websoccer Application context.
	 * @param DbConnection $db DB connection
	 * @param int $clubId ID of team
	 * @param string $positionSort ASC|DESC - sort order of position.
	 * @param boolean $considerBlocksForCups if TRUE, then consider only blocked matches for cups, not for league matches.
	 * @return array Array with teams in uefa temp by cup
	 */
	public static function getUefaTeamsByCupId(WebSoccer $websoccer, DbConnection $db, $cupId) {
		
		$teams = array();
		$string = null;
		
		// get uefa place from land
	    $queryString = "SELECT verein_id FROM ". $websoccer->getConfig('db_prefix') ."_uefa_temp WHERE cup_id='$cupId' ORDER BY RAND()";
	    $result = $db->executeQuery($queryString);
	    while ($team = $result->fetch_array()) {
	        $teams[] = $team;			
	    }
	    $result->free();
		
		for($i=0;$i<count($teams);$i++) {
			$string .= "\"". $teams[$i]['verein_id']. "\"";
		}
		
		$string = str_replace('""', '","', $string);
		$formattedString = '"' . $string . '"';	
		//echo $formattedString ."<br>";
		
		$cleanedString = trim($formattedString, '"');
		$new_teams = explode('","', $cleanedString);
		
		return $new_teams;
	}
	
	/**
	* put teams in groups by roundId
	*
	*/
	public static function putTempTeamsInGroups(WebSoccer $websoccer, DbConnection $db, $roundId, $teams) {
				
		//delete teams with cup_round_id
		$delString = "DELETE FROM ". $websoccer->getConfig('db_prefix') ."_cup_round_group WHERE cup_round_id='$roundId'";
	    $result = $db->executeQuery($delString);
		
		for($i=0;$i<count($teams);$i++) {
			
			if($i<=15) {
				
				$group = "A";
			} else if($i>=16 && $i<=31) {
				
				$group = "B";
			} else if($i>=32 && $i<=47) {
				
				$group = "C";
			} else if($i>=48 && $i<=63) {
				
				$group = "D";
			} else {
				$group = "ERROR";
			}
			
			$insString = "INSERT INTO ". $websoccer->getConfig('db_prefix') ."_cup_round_group (cup_round_id, team_id, name) VALUES('$roundId', '".$teams[$i]."', '$group')";
			//echo $insString ."<br>";
			$result = $db->executeQuery($insString);
		}
	}
	
		/**
	 * get teams by group from cup_round_group
	 *
	 * @return array Array with teams in uefa temp by cup
	 */
	public static function getUefaTeamsByGroup(WebSoccer $websoccer, DbConnection $db, $group_name, $cup_round_id) {
		
		$teams = array();
		$string = null;
		$i = 0;
		
		// get uefa place from land
	    $queryString = "SELECT team_id FROM ". $websoccer->getConfig('db_prefix') ."_cup_round_group
                        WHERE name='$group_name' AND cup_round_id='".$cup_round_id."'
                        ORDER BY RAND()";
	    $result = $db->executeQuery($queryString);
	    while ($team = $result->fetch_array()) {
	        $teams[$i] = $team['team_id'];
			$i++;			
	    }
	    $result->free();
		
		return $teams;
	}

}
?>