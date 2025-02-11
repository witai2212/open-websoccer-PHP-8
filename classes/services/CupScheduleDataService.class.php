<?php
class CupScheduleDataService {

    /****************************************
     *
     * CUP SCHEDULING CLASSES
     *
     ****************************************/
    public static function getCountryCups(WebSoccer $websoccer, DbConnection $db) {
        
        $cups = array();
        
        $sqlStr = "SELECT id, name FROM " . $websoccer->getConfig("db_prefix") . "_cup
				   WHERE name NOT LIKE '%League%'
				   ORDER BY name";
        /*$sqlStr = "SELECT id, name FROM " . $websoccer->getConfig("db_prefix") . "_cup
				   WHERE name LIKE '%Belgien%'
				   ORDER BY name";*/
        $result = $db->executeQuery($sqlStr);
        while($row = $result->fetch_assoc()) {
            $cups[] = $row;
        }
        $result->free();
        
        return $cups;
    }
    
    public static function getCupRoundsByCupId(WebSoccer $websoccer, DbConnection $db, $cupId) {
        
        $sqlStr = "SELECT COUNT(id) AS rounds FROM " . $websoccer->getConfig("db_prefix") . "_cup_round
				   WHERE cup_id='$cupId'";
        $result = $db->executeQuery($sqlStr);
        $round = $result->fetch_assoc();
        $result->free();
        
        return $round['rounds'];
        
    }
    
    public static function getFirstMatchDayOfCup(WebSoccer $websoccer, DbConnection $db, $cupId) {
        
        $sqlStr = "SELECT firstround_date FROM " . $websoccer->getConfig("db_prefix") . "_cup_round
				   WHERE cup_id='$cupId' AND name='Round 1'";
        $result = $db->executeQuery($sqlStr);
        $round = $result->fetch_assoc();
        $result->free();
        
        return $round['firstround_date'];
        
    }
    
    public static function getTeamsByCupName(WebSoccer $websoccer, DbConnection $db, $cupName, $limit) {
        
        $teams = array();
        
        $sqlStr = "SELECT C.id AS club_id
			FROM cm23_liga AS L
			INNER JOIN cm23_verein AS C ON C.liga_id=L.id
			WHERE L.land='$cupName'
			ORDER BY L.division ASC, C.sa_siege ASC, C.sa_unentschieden ASC, C.sa_niederlagen DESC, sa_tore ASC, sa_gegentore DESC
			LIMIT 0,$limit";
        $result = $db->executeQuery($sqlStr);
        while($row = $result->fetch_assoc()) {
            $teams[] = $row;
        }
        $result->free();
        return $teams;
        
    }
    
    public static function createFirstCupMatch(WebSoccer $websoccer, DbConnection $db) {
        
        $cups = self::getCountryCups($websoccer, $db);
        
        foreach ($cups as $cup) {
            
            $cupId = $cup['id'];
            $cupName = $cup['name'];
            
            $cup_round_number = self::getCupRoundsByCupId($websoccer, $db, $cupId);
            $team_limit = pow(2, $cup_round_number);
            $firstmatch_date = self::getFirstMatchDayOfCup($websoccer, $db, $cupId);
            $teams = self::getTeamsByCupName($websoccer, $db, $cupName, $team_limit);
            
            shuffle($teams);

            $schedule = ScheduleGenerator::generateCupMatchSchedule($websoccer, $db, $teams, $firstmatch_date, $cupName);
            
        
        } // foreach
        
    }
    
}

