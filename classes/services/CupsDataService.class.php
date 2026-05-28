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
    public static function getTeamsOfCupGroupInRankingOrder(
        WebSoccer $websoccer,
        DbConnection $db,
        $roundId,
        $groupName
        ) {
            
            $prefix = $websoccer->getConfig("db_prefix");
            
            $fromTable  = $prefix . "_cup_round_group AS G";
            $fromTable .= " INNER JOIN " . $prefix . "_verein AS T ON T.id = G.team_id";
            $fromTable .= " LEFT JOIN "  . $prefix . "_user AS U ON U.id = T.user_id";
            
            $whereCondition  = "G.cup_round_id = %d AND G.name = '%s' ";
            $whereCondition .= "ORDER BY G.tab_points DESC, ";
            $whereCondition .= "(G.tab_goals - G.tab_goalsreceived) DESC, ";
            $whereCondition .= "G.tab_wins DESC, ";
            $whereCondition .= "T.st_punkte DESC";
            
            $parameters = array((int) $roundId, (string) $groupName);
            
            $columns = array();
            $columns["T.id"]                = "id";
            $columns["T.name"]              = "name";
            $columns["T.user_id"]           = "user_id";
            $columns["U.nick"]              = "user_name";
            $columns["G.tab_points"]        = "score";
            $columns["G.tab_goals"]         = "goals";
            $columns["G.tab_goalsreceived"] = "goals_received";
            $columns["G.tab_wins"]          = "wins";
            $columns["G.tab_draws"]         = "draws";
            $columns["G.tab_losses"]        = "defeats";
            
            $result = $db->querySelect($columns, $fromTable, $whereCondition, $parameters);
            
            $teams = array();
            
            while ($team = $result->fetch_array()) {
                $teams[] = $team;
            }
            
            $result->free();
            
            return $teams;
    }
    
    /**
     * Provides country & cup data from teamId.
     *
     * @param WebSoccer $websoccer application context.
     * @param DbConnection $db DB connection.
     * @param int $teamId Team ID.
     * @return array|null Array of cup data by teamId.
     */
    public static function getCupDataByTeamId(
        WebSoccer $websoccer,
        DbConnection $db,
        $teamId
        ) {
            
            $prefix = $websoccer->getConfig("db_prefix");
            $teamId = (int) $teamId;
            
            $columns = "C.*";
            
            $fromTable  = $prefix . "_verein AS T";
            $fromTable .= " INNER JOIN " . $prefix . "_liga AS L ON L.id = T.liga_id";
            $fromTable .= " INNER JOIN " . $prefix . "_cup AS C ON C.name = L.land";
            
            $result = $db->querySelect(
                $columns,
                $fromTable,
                "T.id = %d",
                $teamId,
                1
                );
            
            $cup = $result->fetch_array();
            $result->free();
            
            return $cup ? $cup : null;
    }
    
    /**
     * Provides list of all country cups.
     *
     * @param WebSoccer $websoccer application context.
     * @param DbConnection $db DB connection.
     * @return array Array of cups data.
     */
    public static function getCups(
        WebSoccer $websoccer,
        DbConnection $db
        ) {
            
            $prefix = $websoccer->getConfig("db_prefix");
            
            $sqlStr = "
            SELECT C.*
            FROM {$prefix}_cup AS C
            INNER JOIN {$prefix}_liga AS L ON C.name = L.land
            GROUP BY C.id, C.name
            ORDER BY C.name
        ";
            
            $result = $db->executeQuery($sqlStr);
            
            $cups = array();
            
            while ($cup = $result->fetch_array()) {
                $cups[] = $cup;
            }
            
            $result->free();
            
            return $cups;
    }
    
    /**
     * Provides cup data by cup ID.
     *
     * @param WebSoccer $websoccer application context.
     * @param DbConnection $db DB connection.
     * @param int $cupId Cup ID.
     * @return array|null Array of cup data by cupId.
     */
    public static function getCupDataByCupId(
        WebSoccer $websoccer,
        DbConnection $db,
        $cupId
        ) {
            
            $prefix = $websoccer->getConfig("db_prefix");
            $cupId  = (int) $cupId;
            
            $result = $db->querySelect(
                "C.*",
                $prefix . "_cup AS C",
                "C.id = %d",
                $cupId,
                1
                );
            
            $cup = $result->fetch_array();
            $result->free();
            
            return $cup ? $cup : null;
    }
    
    /**
     * Get matches by cup name ordered by date DESC.
     * Optionally filter by cup group.
     *
     * @param WebSoccer $websoccer application context.
     * @param DbConnection $db DB connection.
     * @param string $cup_name Cup name.
     * @param string|null $cup_group Optional cup group name to filter by.
     * @return array Array of matches.
     */
    public static function getMatchesByCupname(WebSoccer $websoccer, DbConnection $db, $cup_name, $cup_group = null) {
            
            $prefix = $websoccer->getConfig("db_prefix");
            
            $fromTable  = $prefix . "_spiel AS M";
            $fromTable .= " INNER JOIN " . $prefix . "_verein AS HT ON HT.id = M.home_verein";
            $fromTable .= " INNER JOIN " . $prefix . "_verein AS AT ON AT.id = M.gast_verein";
            $fromTable .= " INNER JOIN " . $prefix . "_liga AS HL ON HL.id = HT.liga_id";
            $fromTable .= " INNER JOIN " . $prefix . "_liga AS AL ON AL.id = AT.liga_id";
            
            /*
             * IMPORTANT:
             * Keep this as a plain SQL column string.
             * Using an array with "M.*" => "*" would generate invalid SQL:
             * M.* AS *
             */
            $columns = "
            M.*,
            HT.name AS home_team_name,
            M.datum AS date,
            HT.liga_id AS home_team_ligaid,
            HT.bild AS home_team_picture,
            AT.name AS away_team_name,
            AT.liga_id AS away_team_ligaid,
            AT.bild AS guest_team_picture,
            HL.land AS home_land,
            AL.land AS away_land
        ";
            
            $whereCondition = "M.spieltyp = 'Pokalspiel' AND M.pokalname = '%s'";
            $parameters = array((string) $cup_name);
            
            if ($cup_group !== null && $cup_group !== '') {
                $whereCondition .= " AND M.pokalgruppe = '%s'";
                $parameters[] = (string) $cup_group;
            }
            
            $whereCondition .= " ORDER BY M.datum DESC";
            
            $result = $db->querySelect(
                $columns,
                $fromTable,
                $whereCondition,
                $parameters
                );
            
            $matches = array();
            
            while ($match = $result->fetch_array()) {
                $matches[] = $match;
            }
            
            $result->free();
            
            return $matches;
    }
    
    /**
     * Get cup ID by cup name.
     *
     * @param WebSoccer $websoccer application context.
     * @param DbConnection $db DB connection.
     * @param string $cup_name Cup name.
     * @return int|null Cup ID.
     */
    public static function getCupIdByName(
        WebSoccer $websoccer,
        DbConnection $db,
        $cup_name
        ) {
            
            $prefix = $websoccer->getConfig("db_prefix");
            
            $result = $db->querySelect(
                "id AS cup_id",
                $prefix . "_cup",
                "name = '%s'",
                (string) $cup_name,
                1
                );
            
            $cup = $result->fetch_array();
            $result->free();
            
            return $cup ? (int) $cup["cup_id"] : null;
    }
    
    /**
     * Get cup round ID by cup ID and round name.
     *
     * @param WebSoccer $websoccer application context.
     * @param DbConnection $db DB connection.
     * @param int $cupId Cup ID.
     * @param string $name Round name.
     * @return int|null Round ID.
     */
    public static function getGroupIdByCupId(
        WebSoccer $websoccer,
        DbConnection $db,
        $cupId,
        $name
        ) {
            
            $prefix = $websoccer->getConfig("db_prefix");
            $cupId  = (int) $cupId;
            
            $result = $db->querySelect(
                "id",
                $prefix . "_cup_round",
                "cup_id = %d AND name = '%s'",
                array($cupId, (string) $name),
                1
                );
            
            $cupRound = $result->fetch_array();
            $result->free();
            
            return $cupRound ? (int) $cupRound["id"] : null;
    }
    
    /**
     * Generates or refreshes a national cup with rounds for the given country.
     *
     * Behavior:
     * - Reuses existing cup row if present
     * - Keeps current winner_id untouched
     * - Reactivates archived cup row if needed
     * - Updates award values
     * - Deletes previous round structure
     * - Recreates fresh round structure
     *
     * @param WebSoccer $websoccer application context.
     * @param DbConnection $db DB connection.
     * @param string $land Country name.
     * @param int $rounds Number of rounds.
     * @param string $firstmatchday_date First match day date in format dd.mm.yyyy.
     * @param int $hour Hour of match time.
     * @param int $minute Minute of match time.
     */
    public static function generateNationalCup(
        WebSoccer $websoccer,
        DbConnection $db,
        $land,
        $rounds,
        $firstmatchday_date,
        $hour,
        $minute
        ) {
            
            $prefix = $websoccer->getConfig("db_prefix");
            
            $land   = trim((string) $land);
            $rounds = (int) $rounds;
            $hour   = (int) $hour;
            $minute = (int) $minute;
            
            if ($land === '') {
                throw new Exception("Cup generation failed: country name is empty.");
            }
            
            if ($rounds < 1) {
                throw new Exception("Cup generation failed for '{$land}': invalid number of rounds.");
            }
            
            if ($hour < 0 || $hour > 23) {
                throw new Exception("Cup generation failed for '{$land}': invalid hour.");
            }
            
            if ($minute < 0 || $minute > 59) {
                throw new Exception("Cup generation failed for '{$land}': invalid minute.");
            }
            
            /*
             * ------------------------------------------------------------
             * Get country UEFA coefficient.
             * ------------------------------------------------------------
             */
            $result = $db->querySelect(
                "uefa_coeff",
                $prefix . "_land",
                "name = '%s'",
                $land,
                1
                );
            
            $country = $result->fetch_array();
            $result->free();
            
            if (!$country || !isset($country["uefa_coeff"])) {
                throw new Exception("Cup generation failed for '{$land}': UEFA coefficient not found.");
            }
            
            $coeffParts = explode(".", (string) $country["uefa_coeff"]);
            $coeff      = isset($coeffParts[0]) ? (int) $coeffParts[0] : 0;
            
            $winner_award   = round(7500 * $coeff, 0);
            $second_award   = round(5000 * $coeff, 0);
            $perround_award = round(1250 * $coeff, 0);
            
            /*
             * ------------------------------------------------------------
             * Parse first match day date.
             * ------------------------------------------------------------
             */
            $firstmatchday_date = trim((string) $firstmatchday_date);
            $firstmatchday      = explode(".", $firstmatchday_date);
            
            if (count($firstmatchday) !== 3) {
                throw new Exception(
                    "Cup generation failed for '{$land}': invalid first matchday date '{$firstmatchday_date}'. Expected format: dd.mm.yyyy."
                );
            }
            
            $day   = (int) $firstmatchday[0];
            $month = (int) $firstmatchday[1];
            $year  = (int) $firstmatchday[2];
            
            if (!checkdate($month, $day, $year)) {
                throw new Exception(
                    "Cup generation failed for '{$land}': invalid first matchday date '{$firstmatchday_date}'."
                );
            }
            
            $second    = 0;
            $timestamp = mktime($hour, $minute, $second, $month, $day, $year);
            
            if ($timestamp === false || $timestamp <= 0) {
                throw new Exception("Cup generation failed for '{$land}': could not create matchday timestamp.");
            }
            
            /*
             * ------------------------------------------------------------
             * Determine weeks between rounds.
             * ------------------------------------------------------------
             *
             * Original intended pattern:
             * 64 teams  => 6 rounds => 6 weeks
             * 32 teams  => 5 rounds => 8 weeks
             * 16 teams  => 4 rounds => 10 weeks
             * 8 teams   => 3 rounds => 13 weeks
             *
             * For larger cups, keep the overall competition length roughly
             * in the same range instead of falling back to an arbitrary value.
             */
            if ($rounds == 6) {
                $matchbreak_weeks = 6;
            } elseif ($rounds == 5) {
                $matchbreak_weeks = 8;
            } elseif ($rounds == 4) {
                $matchbreak_weeks = 10;
            } elseif ($rounds == 3) {
                $matchbreak_weeks = 13;
            } elseif ($rounds >= 7) {
                $matchbreak_weeks = max(1, (int) floor(30 / ($rounds - 1)));
            } else {
                $matchbreak_weeks = 8;
            }
            
            /*
             * ------------------------------------------------------------
             * Reuse existing national cup row if present.
             *
             * Important:
             * cup.name is unique. Therefore we must not insert a second
             * cup row for the same country name.
             *
             * winner_id is intentionally not touched here. It was already
             * stored before generation by the national cup generator page.
             * ------------------------------------------------------------
             */
            $cupTable = $prefix . "_cup";
            
            $result = $db->querySelect(
                "id, winner_id, archived",
                $cupTable,
                "name = '%s'",
                $land,
                1
                );
            
            $existingCup = $result->fetch_array();
            $result->free();
            
            if ($existingCup && !empty($existingCup["id"])) {
                
                $cup_id = (int) $existingCup["id"];
                
                $db->queryUpdate(
                    array(
                        "winner_award"   => $winner_award,
                        "second_award"   => $second_award,
                        "perround_award" => $perround_award,
                        "archived"       => "0"
                    ),
                    $cupTable,
                    "id = %d",
                    $cup_id
                    );
                
                /*
                 * Remove the old round structure before creating the new one.
                 * The cup row itself remains untouched, including winner_id.
                 */
                self::deleteCupRoundStructure($websoccer, $db, $cup_id);
                
            } else {
                
                $db->queryInsert(
                    array(
                        "name"           => $land,
                        "winner_award"   => $winner_award,
                        "second_award"   => $second_award,
                        "perround_award" => $perround_award,
                        "archived"       => "0"
                    ),
                    $cupTable
                    );
                
                $cup_id = self::getLastInsertId($db);
                
                if (!$cup_id) {
                    throw new Exception("Cup generation failed for '{$land}': could not determine inserted cup ID.");
                }
            }
            
            /*
             * ------------------------------------------------------------
             * Create fresh cup rounds.
             * ------------------------------------------------------------
             */
            $previousRoundId = null;
            
            for ($j = 1; $j <= $rounds; $j++) {
                
                if ($j == 1) {
                    $timestamp2 = $timestamp;
                } else {
                    $timestamp2 = $timestamp + (86400 * 7 * $matchbreak_weeks * ($j - 1));
                }
                
                $final     = ($j == $rounds) ? "1" : "0";
                $roundName = "Round " . $j;
                
                $insertData = array(
                    "cup_id"                => (int) $cup_id,
                    "name"                  => $roundName,
                    "firstround_date"       => (int) $timestamp2,
                    "secondround_date"      => 0,
                    "finalround"            => $final,
                    "groupmatches"          => "0"
                );
                
                if ($previousRoundId !== null) {
                    $insertData["from_winners_round_id"] = (int) $previousRoundId;
                }
                
                $db->queryInsert(
                    $insertData,
                    $prefix . "_cup_round"
                    );
                
                $previousRoundId = self::getLastInsertId($db);
                
                if (!$previousRoundId) {
                    throw new Exception(
                        "Cup generation failed for '{$land}': could not determine inserted round ID for '{$roundName}'."
                    );
                }
            }
    }
    
    /**
     * Deletes the old structural configuration of a cup:
     * - pending round teams
     * - round groups
     * - round-group-next relations
     * - rounds themselves
     *
     * The cup row itself is preserved.
     *
     * @param WebSoccer $websoccer application context.
     * @param DbConnection $db DB connection.
     * @param int $cupId Cup ID.
     */
    private static function deleteCupRoundStructure(
        WebSoccer $websoccer,
        DbConnection $db,
        $cupId
        ) {
            
            $prefix = $websoccer->getConfig("db_prefix");
            $cupId  = (int) $cupId;
            
            if ($cupId <= 0) {
                return;
            }
            
            $cupRoundTable          = $prefix . "_cup_round";
            $cupRoundPendingTable   = $prefix . "_cup_round_pending";
            $cupRoundGroupTable     = $prefix . "_cup_round_group";
            $cupRoundGroupNextTable = $prefix . "_cup_round_group_next";
            
            $result = $db->querySelect(
                "id",
                $cupRoundTable,
                "cup_id = %d",
                $cupId
                );
            
            $roundIds = array();
            
            while ($round = $result->fetch_array()) {
                if (!empty($round["id"])) {
                    $roundIds[] = (int) $round["id"];
                }
            }
            
            $result->free();
            
            foreach ($roundIds as $roundId) {
                
                $db->queryDelete(
                    $cupRoundPendingTable,
                    "cup_round_id = %d",
                    $roundId
                    );
                
                $db->queryDelete(
                    $cupRoundGroupTable,
                    "cup_round_id = %d",
                    $roundId
                    );
                
                $db->queryDelete(
                    $cupRoundGroupNextTable,
                    "cup_round_id = %d",
                    $roundId
                    );
                
                $db->queryDelete(
                    $cupRoundGroupNextTable,
                    "target_cup_round_id = %d",
                    $roundId
                    );
            }
            
            $db->queryDelete(
                $cupRoundTable,
                "cup_id = %d",
                $cupId
                );
    }
    
    /**
     * Returns the last AUTO_INCREMENT ID generated on the current DB connection.
     *
     * @param DbConnection $db DB connection.
     * @return int|null
     */
    private static function getLastInsertId(DbConnection $db) {
        
        $sql = "SELECT LAST_INSERT_ID() AS id";
        
        $result = $db->executeQuery($sql);
        $row    = $result->fetch_array();
        $result->free();
        
        return ($row && isset($row["id"])) ? (int) $row["id"] : null;
    }
}
?>