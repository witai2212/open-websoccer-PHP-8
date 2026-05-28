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
 * Data service for UEFA cup handling.
 */
class UefaDataService {
    
    const UEFA_CL_CUP_ID = 2;
    const UEFA_UL_CUP_ID = 3;
    
    const MAX_TEAMS_PER_EUROPEAN_CUP = 64;
    const MAX_TEAMS_PER_COUNTRY_PER_CUP = 4;
    
    
    /**
     * Returns UEFA ranking ordered by the total 5-season coefficient.
     *
     * Important:
     * COALESCE is used because uefa_s1 ... uefa_s5 are nullable in the DB schema.
     * Without COALESCE, a single NULL value would make the whole total NULL.
     *
     * @param WebSoccer $websoccer
     * @param DbConnection $db
     * @return array
     */
    public static function getUefaRanking(WebSoccer $websoccer, DbConnection $db) {
        
        $prefix = $websoccer->getConfig("db_prefix");
        
        $columns = "
            L.*,
            (
                COALESCE(L.uefa_s1, 0)
                + COALESCE(L.uefa_s2, 0)
                + COALESCE(L.uefa_s3, 0)
                + COALESCE(L.uefa_s4, 0)
                + COALESCE(L.uefa_s5, 0)
            ) AS total
        ";
        
        $fromTable = $prefix . "_land AS L";
        
        $whereCondition = "
            1 = 1
            ORDER BY
                total DESC,
                L.name ASC
        ";
        
        $result = $db->querySelect(
            $columns,
            $fromTable,
            $whereCondition
            );
        
        $uefas = array();
        
        while ($uefa = $result->fetch_array()) {
            $uefas[] = $uefa;
        }
        
        $result->free();
        
        return $uefas;
    }
    
    
    /**
     * Recalculates:
     * - uefa_coeff
     * - uefa_cl
     * - uefa_ul
     * - uefa_conf
     *
     * Place allocation is based on the UEFA ranking, calculated from:
     * uefa_s1 + uefa_s2 + uefa_s3 + uefa_s4 + uefa_s5
     *
     * The allocation logic follows the old debug distribution pattern:
     * - 64 teams per cup
     * - max. 4 places per country and per cup
     *
     * @param WebSoccer $websoccer
     * @param DbConnection $db
     * @return array Summary of updated totals
     */
    public static function updateUefaQualificationPlacesByRanking(WebSoccer $websoccer, DbConnection $db) {
        
        $prefix = $websoccer->getConfig("db_prefix");
        $ranking = self::getUefaRanking($websoccer, $db);
        
        $countryCount = count($ranking);
        
        $clDistribution = self::createChampionsLeagueDistribution($countryCount);
        
        $ulDistribution = self::createEuropeanCupDistribution(
            $countryCount,
            self::MAX_TEAMS_PER_EUROPEAN_CUP,
            self::MAX_TEAMS_PER_COUNTRY_PER_CUP
            );
        
        $confDistribution = self::createEuropeanCupDistribution(
            $countryCount,
            self::MAX_TEAMS_PER_EUROPEAN_CUP,
            self::MAX_TEAMS_PER_COUNTRY_PER_CUP
            );
        
        foreach ($ranking as $index => $country) {
            
            $countryId = (int) $country['id'];
            
            $total = isset($country['total'])
            ? number_format((float) $country['total'], 3, '.', '')
            : '0.000';
            
            $db->queryUpdate(
                array(
                    'uefa_coeff' => $total,
                    'uefa_cl'    => (int) $clDistribution[$index],
                    'uefa_ul'    => (int) $ulDistribution[$index],
                    'uefa_conf'  => (int) $confDistribution[$index]
                ),
                $prefix . "_land",
                "id = %d",
                $countryId
                );
        }
        
        return array(
            'countries_updated' => $countryCount,
            'cl_total'          => array_sum($clDistribution),
            'ul_total'          => array_sum($ulDistribution),
            'conf_total'        => array_sum($confDistribution)
        );
    }
    
    
    /**
     * Creates a round-robin distribution pattern.
     *
     * Example:
     * - 64 total places
     * - max 4 places per country
     *
     * Ranking order matters:
     * the first ranked countries receive additional places first.
     *
     * @param int $numberOfCountries
     * @param int $maxTeamsForCup
     * @param int $maxTeamsPerCountry
     * @return array
     */
    private static function createEuropeanCupDistribution(
        $numberOfCountries,
        $maxTeamsForCup,
        $maxTeamsPerCountry
        ) {
            
            $numberOfCountries = (int) $numberOfCountries;
            $maxTeamsForCup = (int) $maxTeamsForCup;
            $maxTeamsPerCountry = (int) $maxTeamsPerCountry;
            
            if ($numberOfCountries <= 0) {
                return array();
            }
            
            $distribution = array_fill(0, $numberOfCountries, 0);
            $allocatedTeams = 0;
            
            while ($allocatedTeams < $maxTeamsForCup) {
                
                $assignedInThisRound = false;
                
                for ($i = 0; $i < $numberOfCountries; $i++) {
                    
                    if ($allocatedTeams >= $maxTeamsForCup) {
                        break;
                    }
                    
                    if ($distribution[$i] < $maxTeamsPerCountry) {
                        $distribution[$i]++;
                        $allocatedTeams++;
                        $assignedInThisRound = true;
                    }
                }
                
                /*
                 * Safety stop:
                 * If all countries already reached their maximum,
                 * the loop must stop even if maxTeamsForCup was not reached.
                 */
                if (!$assignedInThisRound) {
                    break;
                }
            }
            
            return $distribution;
    }
    
    
    /**
     * Backward-compatible public helper.
     *
     * The original class contained this method under the misspelled name
     * createEuropeeanCupTeamsPerLeague(). It previously only echoed debug output.
     *
     * It now returns the distribution array.
     *
     * @param array $leagues
     * @param int $maxTeamsForCup
     * @param int $maxTeamsPerCupPerLeague
     * @return array
     */
    public static function createEuropeeanCupTeamsPerLeague(
        $leagues,
        $maxTeamsForCup,
        $maxTeamsPerCupPerLeague
        ) {
            
            return self::createEuropeanCupDistribution(
                count($leagues),
                $maxTeamsForCup,
                $maxTeamsPerCupPerLeague
                );
    }
    
    
    /**
     * Reads UEFA qualification places from _land and fills _uefa_temp.
     *
     * Current logic:
     * - Champions League teams are written with cup_id = 2
     * - UEFA Euro League teams are written with cup_id = 3
     *
     * Conference places are currently only used for table markings,
     * because the existing temp-generator only expects 64 CL + 64 UL = 128 teams.
     *
     * @param WebSoccer $websoccer
     * @param DbConnection $db
     * @return array Teams inserted into _uefa_temp.
     */
    public static function getUefaPlacesByLand(WebSoccer $websoccer, DbConnection $db) {
        
        $teams = array();
        $prefix = $websoccer->getConfig('db_prefix');
        
        // Always clear temporary UEFA team table before regenerating
        $db->executeQuery("DELETE FROM " . $prefix . "_uefa_temp");
        
        /*
         * Use the same central UEFA ranking source.
         * It already contains uefa_cl, uefa_ul, uefa_conf.
         */
        $uefaPlaces = self::getUefaRanking($websoccer, $db);
        
        foreach ($uefaPlaces as $place) {
            
            $land = $place['name'];
            
            $clPlaces = isset($place['uefa_cl']) ? (int) $place['uefa_cl'] : 0;
            $ulPlaces = isset($place['uefa_ul']) ? (int) $place['uefa_ul'] : 0;
            
            
            // ---------------------------------------------------------
            // Champions League teams
            // ---------------------------------------------------------
            if ($clPlaces > 0) {
                
                $clTeams = self::getTopDivisionTeamsByCountry(
                    $websoccer,
                    $db,
                    $land,
                    0,
                    $clPlaces
                    );
                
                foreach ($clTeams as $teamCl) {
                    
                    $teams[] = $teamCl;
                    
                    $db->queryInsert(
                        array(
                            'verein_id' => (int) $teamCl['club_id'],
                            'cup_id'    => self::UEFA_CL_CUP_ID
                        ),
                        $prefix . "_uefa_temp"
                        );
                }
            }
            
            
            // ---------------------------------------------------------
            // UEFA Euro League teams
            // Starts directly after CL places
            // ---------------------------------------------------------
            if ($ulPlaces > 0) {
                
                $ulTeams = self::getTopDivisionTeamsByCountry(
                    $websoccer,
                    $db,
                    $land,
                    $clPlaces,
                    $ulPlaces
                    );
                
                foreach ($ulTeams as $teamUl) {
                    
                    $teams[] = $teamUl;
                    
                    $db->queryInsert(
                        array(
                            'verein_id' => (int) $teamUl['club_id'],
                            'cup_id'    => self::UEFA_UL_CUP_ID
                        ),
                        $prefix . "_uefa_temp"
                        );
                }
            }
        }
        
        return $teams;
    }
    
    
    /**
     * Returns top division teams of one country in current league table order.
     *
     * @param WebSoccer $websoccer
     * @param DbConnection $db
     * @param string $land
     * @param int $start
     * @param int $limit
     * @return array
     */
    private static function getTopDivisionTeamsByCountry(
        WebSoccer $websoccer,
        DbConnection $db,
        $land,
        $start,
        $limit
        ) {
            
            $prefix = $websoccer->getConfig('db_prefix');
            
            $start = max(0, (int) $start);
            $limit = max(0, (int) $limit);
            
            if ($limit <= 0) {
                return array();
            }
            
            $columns = "
            C.id AS club_id,
            C.name AS club_name
        ";
            
            $fromTable = "
            " . $prefix . "_verein AS C
            INNER JOIN " . $prefix . "_liga AS LG
                ON C.liga_id = LG.id
        ";
            
            $whereCondition = "
            LG.land = '%s'
            AND LG.division = '1'
            ORDER BY
                C.sa_punkte DESC,
                C.sa_tore DESC,
                (C.sa_tore - C.sa_gegentore) DESC
            LIMIT " . $start . ", " . $limit . "
        ";
            
            $result = $db->querySelect(
                $columns,
                $fromTable,
                $whereCondition,
                $land
                );
            
            $teams = array();
            
            while ($team = $result->fetch_array()) {
                $teams[] = $team;
            }
            
            $result->free();
            
            return $teams;
    }
    
    
    /**
     * Gets teams from _uefa_temp by cup ID, randomized.
     *
     * @param WebSoccer $websoccer
     * @param DbConnection $db
     * @param int $cupId
     * @return array Team IDs.
     */
    public static function getUefaTeamsByCupId(WebSoccer $websoccer, DbConnection $db, $cupId) {
        
        $teams = array();
        $cupId = (int) $cupId;
        
        $queryString = "
            SELECT verein_id
            FROM " . $websoccer->getConfig('db_prefix') . "_uefa_temp
            WHERE cup_id = '" . $cupId . "'
            ORDER BY RAND()
        ";
        
        $result = $db->executeQuery($queryString);
        
        while ($team = $result->fetch_array()) {
            $teams[] = $team['verein_id'];
        }
        
        $result->free();
        
        return $teams;
    }
    
    
    /**
     * Puts temporary UEFA teams into four groups A-D.
     *
     * Group distribution:
     * - 0-15   = A
     * - 16-31  = B
     * - 32-47  = C
     * - 48-63  = D
     *
     * @param WebSoccer $websoccer
     * @param DbConnection $db
     * @param int $roundId
     * @param array $teams
     */
    public static function putTempTeamsInGroups(
        WebSoccer $websoccer,
        DbConnection $db,
        $roundId,
        $teams
        ) {
            
            $roundId = (int) $roundId;
            $prefix = $websoccer->getConfig('db_prefix');
            
            // Delete existing group entries for this round
            $db->executeQuery("
            DELETE FROM " . $prefix . "_cup_round_group
            WHERE cup_round_id = '" . $roundId . "'
        ");
            
            for ($i = 0; $i < count($teams); $i++) {
                
                if ($i <= 15) {
                    $group = "A";
                    
                } elseif ($i <= 31) {
                    $group = "B";
                    
                } elseif ($i <= 47) {
                    $group = "C";
                    
                } elseif ($i <= 63) {
                    $group = "D";
                    
                } else {
                    $group = "ERROR";
                }
                
                $teamId = (int) $teams[$i];
                
                $db->queryInsert(
                    array(
                        'cup_round_id' => $roundId,
                        'team_id'      => $teamId,
                        'name'         => $group
                    ),
                    $prefix . "_cup_round_group"
                    );
            }
    }
    
    
    /**
     * Gets randomized teams from a defined cup group.
     *
     * @param WebSoccer $websoccer
     * @param DbConnection $db
     * @param string $groupName
     * @param int $cupRoundId
     * @return array Team IDs.
     */
    public static function getUefaTeamsByGroup(
        WebSoccer $websoccer,
        DbConnection $db,
        $groupName,
        $cupRoundId
        ) {
            
            $teams = array();
            $cupRoundId = (int) $cupRoundId;
            
            $columns = "team_id";
            $fromTable = $websoccer->getConfig('db_prefix') . "_cup_round_group";
            
            $whereCondition = "
            name = '%s'
            AND cup_round_id = %d
            ORDER BY RAND()
        ";
            
            $result = $db->querySelect(
                $columns,
                $fromTable,
                $whereCondition,
                array($groupName, $cupRoundId)
                );
            
            while ($team = $result->fetch_array()) {
                $teams[] = $team['team_id'];
            }
            
            $result->free();
            
            return $teams;
    }
    
    /**
     * Creates Champions League place distribution.
     *
     * Special CL rule:
     * - the last 10 countries in the UEFA ranking receive 0 CL places
     * - the 10 removed places are redistributed to the first 5 countries
     * - therefore ranks 1-5 receive +2 CL places each
     *
     * @param int $numberOfCountries
     * @return array
     */

    /**
     * Synchronizes the legacy temp tables from the central _uefa_temp table.
     *
     * Older admin generators still read _cl_temp / _el_temp / _ul_temp directly.
     * The season rollover wizard uses _uefa_temp as the source of truth but also
     * keeps these legacy tables in sync for backwards compatibility.
     *
     * @param WebSoccer $websoccer application context.
     * @param DbConnection $db DB connection.
     * @return array number of synced teams per legacy table.
     */
    public static function syncLegacyTempTablesFromUefaTemp(WebSoccer $websoccer, DbConnection $db) {
        $prefix = $websoccer->getConfig('db_prefix');

        $db->executeQuery('DELETE FROM ' . $prefix . '_cl_temp');
        $db->executeQuery('DELETE FROM ' . $prefix . '_ul_temp');
        $db->executeQuery('DELETE FROM ' . $prefix . '_el_temp');

        $counts = array(
            'cl_temp' => 0,
            'ul_temp' => 0,
            'el_temp' => 0
        );

        $result = $db->querySelect(
            'verein_id, cup_id',
            $prefix . '_uefa_temp',
            '1 = 1 ORDER BY cup_id ASC, id ASC'
        );

        while ($team = $result->fetch_array()) {
            $teamId = isset($team['verein_id']) ? (int) $team['verein_id'] : 0;
            $cupId = isset($team['cup_id']) ? (int) $team['cup_id'] : 0;

            if ($teamId <= 0) {
                continue;
            }

            if ($cupId === self::UEFA_CL_CUP_ID) {
                $db->queryInsert(
                    array('verein_id' => $teamId),
                    $prefix . '_cl_temp'
                );
                $counts['cl_temp']++;
            } elseif ($cupId === self::UEFA_UL_CUP_ID) {
                $db->queryInsert(
                    array('verein_id' => $teamId),
                    $prefix . '_ul_temp'
                );
                $db->queryInsert(
                    array('verein_id' => $teamId),
                    $prefix . '_el_temp'
                );
                $counts['ul_temp']++;
                $counts['el_temp']++;
            }
        }

        $result->free();

        return $counts;
    }

    /**
     * Complete UEFA rebuild used by the season rollover wizard:
     * update allocation by coefficient, fill _uefa_temp, then sync legacy temp tables.
     *
     * @param WebSoccer $websoccer application context.
     * @param DbConnection $db DB connection.
     * @return array summary.
     */
    public static function rebuildQualificationAndTempTables(WebSoccer $websoccer, DbConnection $db) {
        $allocation = self::updateUefaQualificationPlacesByRanking($websoccer, $db);
        $teams = self::getUefaPlacesByLand($websoccer, $db);
        $legacy = self::syncLegacyTempTablesFromUefaTemp($websoccer, $db);

        return array(
            'allocation' => $allocation,
            'team_count' => count($teams),
            'legacy' => $legacy
        );
    }

    private static function createChampionsLeagueDistribution($numberOfCountries) {
        
        $numberOfCountries = (int) $numberOfCountries;
        
        if ($numberOfCountries <= 0) {
            return array();
        }
        
        /*
         * Step 1:
         * Start with the normal 64-place CL distribution.
         */
        $distribution = self::createEuropeanCupDistribution(
            $numberOfCountries,
            self::MAX_TEAMS_PER_EUROPEAN_CUP,
            self::MAX_TEAMS_PER_COUNTRY_PER_CUP
            );
        
        /*
         * Step 2:
         * Remove exactly one CL place from each of the last 10 ranked countries.
         *
         * This follows your intended rule:
         * the last 10 countries shall not have a CL place.
         */
        $startIndexLastTen = max(0, $numberOfCountries - 10);
        
        for ($i = $startIndexLastTen; $i < $numberOfCountries; $i++) {
            $distribution[$i] = 0;
        }
        
        /*
         * Step 3:
         * Redistribute the 10 CL places to the top 5 countries:
         * +2 CL places each.
         */
        $topCountriesToBoost = min(5, $numberOfCountries);
        
        for ($i = 0; $i < $topCountriesToBoost; $i++) {
            $distribution[$i] += 2;
        }
        
        return $distribution;
    }
}

?>