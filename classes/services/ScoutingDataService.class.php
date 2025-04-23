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
 * Data service for scouting
 */
class ScoutingDataService {
   
    /**
     * Provides list available scouts
     *
     * @param WebSoccer $websoccer Application context.
     * @param DbConnection $db DB connection.
     * @param int $teamId ID of team
     * @return array of available scouts on market.
     */
    public static function getAvailableScouts(WebSoccer $websoccer, DbConnection $db) {
        
        $sqlStr = "SELECT * FROM ". $websoccer->getConfig("db_prefix") ."_scout WHERE team_id='0'";
        $result = $db->executeQuery($sqlStr);
        //$scouts = $return->fetch_array();
        
        //return $scouts;
        $scouts = array();
        
        $i = 0;
        while ($scout = $result->fetch_array()) {
            $scouts[$i] = $scout;
            $i++;
        }
        $result->free();
        
        return $scouts;
    }
    
    /**
     * Provides list of employed scouts
     *
     * @param WebSoccer $websoccer Application context.
     * @param DbConnection $db DB connection.
     * @param int $teamId ID of team
     * @return array of scouts who belong to the specified team.
     */
    public static function getTeamScouts(WebSoccer $websoccer, DbConnection $db, $teamId) {
        
		$scouts = array();
        $i = 0;
		
		// update if 0 matches, but team_id
		$updStr = "UPDATE ". $websoccer->getConfig("db_prefix") ."_scout SET team_id='0' WHERE team_matches='0' AND team_id>0";
		$db->executeQuery($updStr);
		
        $sqlStr = "SELECT * FROM ". $websoccer->getConfig("db_prefix") ."_scout
					WHERE team_id='$teamId' AND team_matches>0";
        $result = $db->executeQuery($sqlStr);
        while ($scout = $result->fetch_array()) {
            $scouts[$i] = $scout;
            $i++;
        }
        $result->free();
        
        return $scouts;
    }

    /**
     * Checking ho many scouts team already has hired
     *
     * @param WebSoccer $websoccer Application context.
     * @param DbConnection $db DB connection.
     * @param int $teamId ID of team
     * @return number scouts hired by specified team.
     */
    public static function checkHiredScoutsByTeam(WebSoccer $websoccer, DbConnection $db, $teamId) {
        
        $max_scouts = $websoccer->getConfig("max_scouts_per_team");
        
        $qty = 0;
        $can_hire = 0;
        
        $sqlStr = "SELECT COUNT(*) AS qty FROM ". $websoccer->getConfig("db_prefix") ."_scout
                    WHERE team_id='$teamId'";
        $result = $db->executeQuery($sqlStr);
        $scouts = $result->fetch_array();
        $result->free();
        
        $qty = $scouts['qty'];
        
        if($qty<$max_scouts) {
            $can_hire = 1;
        }
        
        return $can_hire;
    }
    
    /**
     * Get dat from scout by Id
     *
     * @param WebSoccer $websoccer Application context.
     * @param DbConnection $db DB connection.
     * @param int $scoutId ID of team
     * @return array of scout data by scoutId
     */
    public static function getScoutById(WebSoccer $websoccer, DbConnection $db, $scoutId) {
        
        $sqlStr = "SELECT * FROM ". $websoccer->getConfig("db_prefix") ."_scout
                    WHERE id='$scoutId'";
        $result = $db->executeQuery($sqlStr);
        $scout = $result->fetch_array();
        $result->free();
        
        return $scout;  
    }
    
    /**
     * Get dat from scout by teamId and scout speciality
     *
     * @param WebSoccer $websoccer Application context.
     * @param DbConnection $db DB connection.
     * @param int $teamId
     * @param int $speciality
     * @return array of scout data by teamId and speciality
     */
    public static function getScoutByTeamSpeciality(WebSoccer $websoccer, DbConnection $db, $teamId, $speciality) {
        
        $sqlStr = "SELECT * FROM ". $websoccer->getConfig("db_prefix") ."_scout
                    WHERE team_id='$teamId' AND speciality='$speciality'";
        $result = $db->executeQuery($sqlStr);
        $scout = $result->fetch_array();
        $result->free();
        
        return $scout;
    }
    
    /**
     * Hire scout and change team_id
     *
     * @param WebSoccer $websoccer Application context.
     * @param DbConnection $db DB connection.
     * @param int $scoutId ID of scout
     * @param int $teamId ID of team
     */
    public static function hireScout(WebSoccer $websoccer, DbConnection $db, $scoutId, $teamId) {
        
        // get data from scout
        $scout = self::getScoutById($websoccer, $db, $scoutId);
        
        $remaining_matches = $scout['team_matches'];
        $scout_fee = $scout['fee'];
        $total_amount = $remaining_matches*$scout_fee;
        
        // debit amount
        BankAccountDataService::debitAmount($websoccer, $db, $teamId, $total_amount, "scouting_message", "scouting_abteilung");

        /// set team_id in database
        $sqlStr = "UPDATE ". $websoccer->getConfig("db_prefix") ."_scout SET team_id='$teamId', team_matches='20' WHERE id='$scoutId'";
        $db->executeQuery($sqlStr);
    }
    
    /**
     * Fire scout and change team_id
     *
     * @param WebSoccer $websoccer Application context.
     * @param DbConnection $db DB connection.
     * @param int $scoutId ID of scout
     * @param int $teamId ID of team
     */
    public static function fireScout(WebSoccer $websoccer, DbConnection $db, $scoutId, $teamId) {
        
        $scout_matches = $websoccer->getConfig("scouts_matches");
        
        // re-set team_id in database = 0
        $sqlStr = "UPDATE ". $websoccer->getConfig("db_prefix") ."_scout SET team_id='0', team_matches='$scout_matches' WHERE id='$scoutId'";
        $db->executeQuery($sqlStr);
    }
    
    public static function getTalentEvaluation(WebSoccer $websoccer, DbConnection $db, $teamId, $playerId) {
        
        // Get Player data
        $player = PlayersDataService::getPlayerById($websoccer, $db, $playerId);
        $playerTalentLevel = $player['player_strength_talent'];
        
        // Get Scout data
        $scout = self::getScoutByTeamSpeciality($websoccer, $db, $teamId, $player['player_position_de']);
        
        if($scout['team_id']>0) {
            $scoutAccuracy = $scout['expertise'];
            
            mt_srand((double)microtime()*1000000);
            $scoutPossibility = mt_rand(20,100);
            
            if($scoutAccuracy>=$scoutPossibility) {
                $scout_result = $playerTalentLevel;
            } else {
                $scout_result = mt_rand(1,6);
            }
            
            return $scout_result;
            
        } else {
            
            return null;
        }
    }
	
    public static function reduceTeamMatches(WebSoccer $websoccer, DbConnection $db) {
		
		$updScout = "UPDATE ". $websoccer->getConfig("db_prefix") ."_scout SET team_matches=team_matches-1 WHERE team_matches>0";
		$db->executeQuery($updScout);
		
	}
}
?>