<?php

class CupScheduleDataService {
    
    public static function getCountryCups(WebSoccer $websoccer, DbConnection $db) {
        
        $prefix = $websoccer->getConfig("db_prefix");
        
        $cups = array();
        
        /*
         * IMPORTANT:
         * Only active cups may be considered here.
         *
         * At season end, only cm23_cup.archived is set to 1.
         * cm23_cup_round remains untouched and is linked by cup_id.
         */
        $sqlStr = "
			SELECT id, name
			FROM {$prefix}_cup
			WHERE archived = '0'
			AND name NOT LIKE '%League%'
			ORDER BY name
		";
        
        $result = $db->executeQuery($sqlStr);
        
        while ($row = $result->fetch_assoc()) {
            $cups[] = $row;
        }
        
        $result->free();
        
        return $cups;
    }
    
    public static function getCupRoundsByCupId(WebSoccer $websoccer, DbConnection $db, $cupId) {
        
        $prefix = $websoccer->getConfig("db_prefix");
        $cupId  = (int) $cupId;
        
        $sqlStr = "
			SELECT COUNT(id) AS rounds
			FROM {$prefix}_cup_round
			WHERE cup_id = '{$cupId}'
		";
        
        $result = $db->executeQuery($sqlStr);
        $row    = $result->fetch_assoc();
        
        $result->free();
        
        return $row ? (int) $row['rounds'] : 0;
    }
    
    public static function getFirstMatchDayOfCup(WebSoccer $websoccer, DbConnection $db, $cupId) {
        
        $prefix = $websoccer->getConfig("db_prefix");
        $cupId  = (int) $cupId;
        
        $sqlStr = "
			SELECT firstround_date
			FROM {$prefix}_cup_round
			WHERE cup_id = '{$cupId}'
			AND name = 'Round 1'
			LIMIT 1
		";
        
        $result = $db->executeQuery($sqlStr);
        $row    = $result->fetch_assoc();
        
        $result->free();
        
        return $row ? (int) $row['firstround_date'] : 0;
    }
    
    public static function getTeamsByCupName(WebSoccer $websoccer, DbConnection $db, $cupName, $limit) {
        
        $prefix = $websoccer->getConfig("db_prefix");
        
        $teams = array();
        $limit = (int) $limit;
        
        if ($limit <= 0) {
            return $teams;
        }
        
        $sqlStr = "
			SELECT C.id AS club_id
			FROM {$prefix}_liga AS L
			INNER JOIN {$prefix}_verein AS C ON C.liga_id = L.id
			WHERE L.land = '$cupName'
			ORDER BY
				L.division ASC,
				C.sa_siege DESC,
				C.sa_unentschieden DESC,
				C.sa_niederlagen ASC,
				C.sa_tore DESC,
				C.sa_gegentore ASC
			LIMIT 0, {$limit}
		";
        
        $result = $db->executeQuery($sqlStr);
        
        while ($row = $result->fetch_assoc()) {
            $teams[] = $row;
        }
        
        $result->free();
        
        return $teams;
    }
    
    /**
     * Checks whether cup matches have already been generated.
     * Prevents duplicate first-round match generation if the generator is clicked twice.
     */
    public static function cupMatchesAlreadyExist(WebSoccer $websoccer, DbConnection $db, $cupName) {
        
        $prefix = $websoccer->getConfig("db_prefix");
        
        $sqlStr = "
			SELECT COUNT(id) AS matches
			FROM {$prefix}_spiel
			WHERE spieltyp = 'Pokalspiel'
			AND pokalname = '$cupName'
		";
        
        $result = $db->executeQuery($sqlStr);
        $row    = $result->fetch_assoc();
        
        $result->free();
        
        return $row && (int) $row['matches'] > 0;
    }
    
    public static function createFirstCupMatch(WebSoccer $websoccer, DbConnection $db) {
        
        $cups = self::getCountryCups($websoccer, $db);
        
        foreach ($cups as $cup) {
            
            $cupId   = (int) $cup['id'];
            $cupName = $cup['name'];
            
            /*
             * Safety: do not generate matches twice.
             */
            if (self::cupMatchesAlreadyExist($websoccer, $db, $cupName)) {
                continue;
            }
            
            $cup_round_number = self::getCupRoundsByCupId($websoccer, $db, $cupId);
            
            if ($cup_round_number <= 0) {
                throw new Exception(
                    "Cannot generate first cup matches for '{$cupName}': no cup rounds found."
                );
            }
            
            /*
             * Example:
             * 4 rounds => 16 teams
             * 5 rounds => 32 teams
             * 6 rounds => 64 teams
             */
            $team_limit = (int) pow(2, $cup_round_number);
            
            $firstmatch_date = self::getFirstMatchDayOfCup($websoccer, $db, $cupId);
            
            if ($firstmatch_date <= 0) {
                throw new Exception(
                    "Cannot generate first cup matches for '{$cupName}': first match day for Round 1 is missing."
                );
            }
            
            $teams = self::getTeamsByCupName($websoccer, $db, $cupName, $team_limit);
            
            if (count($teams) < $team_limit) {
                throw new Exception(
                    "Cannot generate first cup matches for '{$cupName}': expected {$team_limit} teams, but only " . count($teams) . " were found."
                        );
            }
            
            shuffle($teams);
            
            ScheduleGenerator::generateCupMatchSchedule(
                $websoccer,
                $db,
                $teams,
                $firstmatch_date,
                $cupName
                );
        }
    }
}