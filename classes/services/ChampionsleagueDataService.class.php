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
 * Data service for leagues.
 */
class ChampionsleagueDataService {
	
	/**
	 * Gets Champions League Data.
	 *
	 * @param WebSoccer $websoccer Application context.
	 * @return return array of Champions league data.
	 */
	public static function getCLData(WebSoccer $websoccer, DbConnection $db) {
		
		$sqlStr = "SELECT C.* FROM ". $websoccer->getConfig("db_prefix") ."_cup AS C WHERE C.name = 'Champions League'";
	    $result = $db->executeQuery($sqlStr);
		$champions_league = $result->fetch_array();
	    $result->free();
		
		return $champions_league;
		
	}
	
	/**
	 * Gets Champions League Group Data.
	 *
	 * @param WebSoccer $websoccer Application context.
	 * @return return array of Champions league data.
	 */
	public static function getCLGroupDataByGroup(WebSoccer $websoccer, DbConnection $db, $cup_round_id, $group_name) {
		
		//$cup_round_id = '18';
		//$group_name = 'A';
	    
		$group = array();
		
		$sqlStr = "SELECT CR.*, CG.*, V.id AS club_id, V.name AS club_name, L.land
					FROM ". $websoccer->getConfig("db_prefix") ."_cup_round AS CR					
						INNER JOIN " . $websoccer->getConfig("db_prefix") . "_cup_round_group AS CG ON cup_round_id = CR.id	
						INNER JOIN " . $websoccer->getConfig("db_prefix") . "_verein AS V ON V.id = CG.team_id
						INNER JOIN " . $websoccer->getConfig("db_prefix") . "_liga AS L ON L.id = V.liga_id
					WHERE CR.id = '$cup_round_id'
					AND CG.name = '$group_name'
					ORDER BY CG.tab_points DESC, CG.tab_wins DESC, CG.tab_goals DESC, CG.tab_draws ASC, CG.tab_losses ASC, CG.tab_goalsreceived ASC";
	    $result = $db->executeQuery($sqlStr);
		while ($gr = $result->fetch_array()) {
			$group[] = $gr;
		}
	    $result->free();
		
		return $group;
		
	}
	
	public static function getClGroupId(WebSoccer $websoccer, DbConnection $db, $clId) {
		
		$sqlStr = "SELECT CR.id FROM ". $websoccer->getConfig("db_prefix") ."_cup_round AS CR
					WHERE CR.name='Gruppen' AND CR.cup_id='$clId'";
	    $result = $db->executeQuery($sqlStr);
		$id = $result->fetch_array();
	    $result->free();
		
		return $id['id'];
		
	}
	
	public static function getClGroupIdByName(WebSoccer $websoccer, DbConnection $db, $grId, $grName) {
	    
	    $group_data = array();
	    
	    $sqlStr = "SELECT CG.* FROM ". $websoccer->getConfig("db_prefix") ."_cup_round_group AS CG
					WHERE CG.cup_round_id='$grId' AND CG.name='$grName'";
	    $result = $db->executeQuery($sqlStr);
	    while ($group = $result->fetch_array()) {
	        $group_data[] = $group;
	    }
	    $result->free();
	    
	    return $group_data;
	    
	}
	
	/**
	 * Gets Champions League Group Data.
	 *
	 * @param WebSoccer $websoccer Application context.
	 * @return return array of Champions league data.
	 */
	public static function getCLGroups(WebSoccer $websoccer, DbConnection $db, $cup_round_id) {
	    
		$groups = array();
		
		$sqlStr = "SELECT CG.cup_round_id, CG.name AS group_name
					FROM ". $websoccer->getConfig("db_prefix") ."_cup_round_group AS CG
					WHERE CG.cup_round_id = '$cup_round_id'
					GROUP BY CG.name
					ORDER BY CG.name";
	    $result = $db->executeQuery($sqlStr);
		while ($group = $result->fetch_array()) {
			$groups[] = $group;
		}
	    $result->free();
		
		return $groups;
		
	}
	
	/**
	 *  Get Matches by round
	 *
	 * @param WebSoccer $websoccer Application context.
	 * @param pokalname = "Champions League"
	 * @param pokalrunde = "Runde 1"
	 * @param pokalgruppe = "A"
	 * @return array of matches.
	 * 
	 */
	public static function getCLMatchesByRound(WebSoccer $websoccer, DbConnection $db, $cup_name, $cup_round, $cup_group) {
	    
	    $matches = array();
	    
	    if(isset($cup_group)) {
	        
	        $group = explode(" ", $cup_group);      
	        $cup_group_str = " AND M.pokalgruppe='$cup_group'";
	        
	    } else {
	        $cup_group_str = "";
	        $group[1] = "A";
	    }
	    
	    $sqlStr = "SELECT M.*, HT.name AS home_team_name, HT.bild AS home_team_logo, HT.liga_id AS home_team_ligaid, 
                        AT.name AS away_team_name, AT.bild AS away_team_logo, AT.liga_id AS away_team_ligaid,
                        HL.land, AL.land
                    FROM " . $websoccer->getConfig("db_prefix") . "_spiel AS M
					INNER JOIN " . $websoccer->getConfig("db_prefix") . "_verein AS HT ON HT.id = M.home_verein
					INNER JOIN " . $websoccer->getConfig("db_prefix") . "_verein AS AT ON AT.id = M.gast_verein
					INNER JOIN " . $websoccer->getConfig("db_prefix") . "_liga AS HL ON HL.id = HT.liga_id
					INNER JOIN " . $websoccer->getConfig("db_prefix") . "_liga AS AL ON AL.id = AT.liga_id

                    WHERE M.spieltyp='Pokalspiel'
                        AND M.pokalname='$cup_name' AND M.pokalrunde='".$cup_round."' AND M.pokalgruppe='".$group[1]."'
                    ORDER BY datum ASC";
	    $result = $db->executeQuery($sqlStr);
		while ($match = $result->fetch_array()) {
			$matches[] = $match;
		}
	    $result->free();
		
		return $matches;

	}
	
}
?>