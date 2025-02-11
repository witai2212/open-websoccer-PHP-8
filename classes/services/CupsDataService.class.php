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
 * Data service for cup data
 */
class CupsDataService {
	
	/**
	 * Provides teams assigned to specified cup group in their standings order.
	 * 
	 * @param WebSoccer $websoccer application context.
	 * @param DbConnection $db DB connection.
	 * @param int $roundId Cup round ID.
	 * @param string $groupName Cup round group name.
	 * @return array Array of teams with standings related statistics.
	 */
	public static function getTeamsOfCupGroupInRankingOrder(WebSoccer $websoccer, DbConnection $db, $roundId, $groupName) {
		$fromTable = $websoccer->getConfig("db_prefix") . "_cup_round_group AS G";
		$fromTable .= " INNER JOIN " . $websoccer->getConfig("db_prefix") . "_verein AS T ON T.id = G.team_id";
		$fromTable .= " LEFT JOIN " . $websoccer->getConfig("db_prefix") . "_user AS U ON U.id = T.user_id";
		
		// where
		$whereCondition = "G.cup_round_id = %d AND G.name = '%s'";
		
		// order (do not use "Direktvergleich", but compare total score so far)
		$whereCondition .= "ORDER BY G.tab_points DESC, (G.tab_goals - G.tab_goalsreceived) DESC, G.tab_wins DESC, T.st_punkte DESC";
		
		$parameters = array($roundId, $groupName);
	
		// select
		$columns["T.id"] = "id";
		$columns["T.name"] = "name";
		$columns["T.user_id"] = "user_id";
		$columns["U.nick"] = "user_name";
		$columns["G.tab_points"] = "score";
		$columns["G.tab_goals"] = "goals";
		$columns["G.tab_goalsreceived"] = "goals_received";
		$columns["G.tab_wins"] = "wins";
		$columns["G.tab_draws"] = "draws";
		$columns["G.tab_losses"] = "defeats";
	
		$result = $db->querySelect($columns, $fromTable, $whereCondition, $parameters);
		$teams = array();
		while($team = $result->fetch_array()) {
			$teams[] = $team;
		}
		$result->free();
		
		return $teams;
	}
	
	/**
	 * Provides provides country & cup from teamId.
	 * 
	 * @param WebSoccer $websoccer application context.
	 * @param DbConnection $db DB connection.
	 * @param int $teamId TeamId.
	 * @return array Array cup data by teamId.
	 */
	public static function getCupDataByTeamId(WebSoccer $websoccer, DbConnection $db, $teamId) {
		
		$sqlStr = "SELECT C.*
					FROM " . $websoccer->getConfig("db_prefix") . "_cup AS C,
						" . $websoccer->getConfig("db_prefix") . "_verein AS T
					INNER JOIN " . $websoccer->getConfig("db_prefix") . "_liga AS L ON L.id = T.liga_id
					WHERE T.id='$teamId' AND C.name LIKE L.land";
	    $result = $db->executeQuery($sqlStr);
		$cup = $result->fetch_array();
	    $result->free();
		
		return $cup;
		
	}
	
	/**
	 * Provides provides list of all country cups.
	 * 
	 * @param WebSoccer $websoccer application context.
	 * @param DbConnection $db DB connection.
	 * @return array Array cups data.
	 */
	public static function getCups(WebSoccer $websoccer, DbConnection $db) {
		
		$cups = array();
		
		$sqlStr = "SELECT C.* 
					FROM " . $websoccer->getConfig("db_prefix") . "_cup AS C, 
						" . $websoccer->getConfig("db_prefix") . "_liga AS L
					WHERE C.name LIKE L.land 
					GROUP BY C.name 
					ORDER BY C.name";
	    $result = $db->executeQuery($sqlStr);
		while($cup = $result->fetch_array()) {
			$cups[] = $cup;
		}
		$result->free();
		
		return $cups;
		
	}
		
	/**
	 * Provides provides country & cup from cupId.
	 * 
	 * @param WebSoccer $websoccer application context.
	 * @param DbConnection $db DB connection.
	 * @param int $teamId TeamId.
	 * @return array Array cup data by teamId.
	 */
	public static function getCupDataByCupId(WebSoccer $websoccer, DbConnection $db, $cupId) {
		
		$sqlStr = "SELECT C.* FROM " . $websoccer->getConfig("db_prefix") . "_cup AS C WHERE C.id='$cupId'";
	    $result = $db->executeQuery($sqlStr);
		$cup = $result->fetch_array();
	    $result->free();
		
		return $cup;
		
	}	/**
	 *  Get Matches by cup round ASC
	 *
	 * @param WebSoccer $websoccer Application context.
	 * @param pokalname = cup name
	 * @return array of matches.
	 * 
	 */
	public static function getMatchesByCupname(WebSoccer $websoccer, DbConnection $db, $cup_name) {

	    $matches = array();
	    
	    if(!isset($cup_group)) {
	        $cup_group_str = " AND M.pokalgruppe='$cup_group'";
	    } else {
	        $cup_group_str = "";
	    }
	    
	    $sqlStr = "SELECT M.*, HT.name AS home_team_name, M.datum AS date,
                        HT.liga_id AS home_team_ligaid, HT.bild AS home_team_picture,
						AT.name AS away_team_name, AT.liga_id AS away_team_ligaid, AT.bild AS guest_team_picture,
                        HL.land, AL.land
                    FROM " . $websoccer->getConfig("db_prefix") . "_spiel AS M
					INNER JOIN " . $websoccer->getConfig("db_prefix") . "_verein AS HT ON HT.id = M.home_verein
					INNER JOIN " . $websoccer->getConfig("db_prefix") . "_verein AS AT ON AT.id = M.gast_verein
					INNER JOIN " . $websoccer->getConfig("db_prefix") . "_liga AS HL ON HL.id = HT.liga_id
					INNER JOIN " . $websoccer->getConfig("db_prefix") . "_liga AS AL ON AL.id = AT.liga_id

                    WHERE M.spieltyp='Pokalspiel'
                        AND M.pokalname='$cup_name'
                    ORDER BY datum DESC";
	    $result = $db->executeQuery($sqlStr);
		while ($match = $result->fetch_array()) {
			$matches[] = $match;
		}
	    $result->free();
		
		return $matches;

	}

	/**
	 * get cup id by name
	 *
	 * @param WebSoccer $websoccer Application context.
	 * @param DbConnection $db DB connection
	 * @param int $clubId ID of team
	 * @param string $positionSort ASC|DESC - sort order of position.
	 * @param boolean $considerBlocksForCups if TRUE, then consider only blocked matches for cups, not for league matches.
	 * @return array Array with places for uefa cups by land.
	 */
	public static function getCupIdByName(WebSoccer $websoccer, DbConnection $db, $cup_name) {
	 
		$sqlStr = "SELECT C.id AS cup_id FROM " . $websoccer->getConfig("db_prefix") . "_cup AS C WHERE C.name='$cup_name' LIMIT 1";
		$result = $db->executeQuery($sqlStr);
		$cup = $result->fetch_array();
		$result->free();

		return $cup['cup_id'];
	 
	}

	/**
	 * get cup id by name
	 *
	 * @param WebSoccer $websoccer Application context.
	 * @param DbConnection $db DB connection
	 * @param int $cupId ID Cup
	 * @param string $name round name
	 */
	public static function getGroupIdByCupId(WebSoccer $websoccer, DbConnection $db, $cupId, $name) {
	 
		$sqlStr = "SELECT id FROM " . $websoccer->getConfig("db_prefix") . "_cup_round WHERE cup_id='$cupId' AND name='$name' LIMIT 1";
		$result = $db->executeQuery($sqlStr);
		$cup = $result->fetch_array();
		$result->free();

		return $cup['id'];
	 
	}
	
	/**
	 * get cup id by name
	 *
	 * @param WebSoccer $websoccer Application context.
	 * @param DbConnection $db DB connection
	 * @param int $cupId ID Cup
	 * @param string $name round name
	 */
	public static function generateNationalCup(WebSoccer $websoccer, DbConnection $db, $land, $rounds, $firstmatchday_date, $hour, $minute) {
		
		// get country coeff
		$sqlStr = "SELECT uefa_coeff FROM " . $websoccer->getConfig("db_prefix") . "_land WHERE name='$land' LIMIT 1";
		$result1 = $db->executeQuery($sqlStr);
		$country = $result1->fetch_array();
		$result1->free();
		
		$coeff = explode(".", $country['uefa_coeff']);
		$coeff = $coeff[0];
		
		$winner_award = round(7500*$coeff,0);
		$second_award = round(5000*$coeff,0);
		$perround_award = round(1250*$coeff,0);

		// save cup in db
		$insStr = "INSERT INTO " . $websoccer->getConfig("db_prefix") . "_cup (name, winner_award, second_award, perround_award)
					VALUES ('$land', '$winner_award', '$second_award', '$perround_award')";
		//echo $insStr ."<br>";
		$db->executeQuery($insStr);
		
		// get cupId
		$sqlStr2 = "SELECT id AS cup_id FROM " . $websoccer->getConfig("db_prefix") . "_cup WHERE name='$land' AND archived='0' LIMIT 1";
		$result2 = $db->executeQuery($sqlStr2);
		$cup = $result2->fetch_array();
		$result2->free();
		
		$cup_id = $cup['cup_id'];
		
		// make timestamp
		$firstmatchday = explode('.', $firstmatchday_date);
		$day = $firstmatchday[0];
		$month = $firstmatchday[1];
		$year = $firstmatchday[2];
		$second = 0; // Optional, defaults to 0 if omitted

		// Create the timestamp
		$timestamp = mktime($hour, $minute, $second, $month, $day, $year);
		
		/* BREAK BETWEN MATCHWES ***********
			 *	64 - 6
			 *	32 - 8
			 *	16 - 10
			 *	8  - 13
		***********************************/
		if($rounds==6) {
			$matchbreak_weeks = 6;
			
		} else if($rounds=5) {
			$matchbreak_weeks = 8;
			
		} else if($rounds=4) {
			$matchbreak_weeks = 10;
			
		} else if($rounds=3) {
			$matchbreak_weeks = 13;
		}
		
		$previousRoundId = null;
		
		for ($j = 1; $j <= $rounds; $j++) {
		    
		    if ($j == 1) {
		        $timestamp2 = $timestamp;
		    } else {
		        $timestamp2 = $timestamp + (86400 * 7 * $matchbreak_weeks * ($j - 1));
		    }
		    
		    if ($j == $rounds) {
		        $final = '1';
		    } else {
		        $final = '0';
		    }
		    
		    // Insert into cm23_cup_round
		    $roundName = "Round " . $j;
		    $insRoundStr = "
            INSERT INTO " . $websoccer->getConfig("db_prefix") . "_cup_round
            (cup_id, name, from_winners_round_id, firstround_date, secondround_date, finalround, groupmatches)
            VALUES ('$cup_id', '$roundName', " . ($previousRoundId ? "'$previousRoundId'" : "NULL") . ", '$timestamp2', '', '$final', '0')";
		    $db->executeQuery($insRoundStr);
		    
		    // Get the inserted round ID
		    $previousRoundId = self::getLastInsertedRoundId($db, $websoccer, $cup_id, $roundName);
		}
	}
	
	/**
	 * Private helper function to get the ID of the last inserted round
	 */
	private static function getLastInsertedRoundId(DbConnection $db, WebSoccer $websoccer, $cup_id, $roundName) {
	    $sqlRoundId = "SELECT id FROM " . $websoccer->getConfig("db_prefix") . "_cup_round
                    WHERE cup_id = '$cup_id' AND name = '$roundName' LIMIT 1";
	    $result = $db->executeQuery($sqlRoundId);
	    $round = $result->fetch_array();
	    $result->free();
	    return $round['id'];
	}
	

}
?>