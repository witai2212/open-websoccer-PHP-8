<?php

class TableMakingDataService {
    
    public static function fillMarkingsForCountry(WebSoccer $websoccer, DbConnection $db, $countryName) {
        $countryData = self::getCountryData($websoccer, $db, $countryName);
        
        if (!$countryData) {
            throw new Exception("Country '$countryName' not found.");
        }
        
        $leagues = self::getLeaguesByCountry($websoccer, $db, $countryName);
        
        if (empty($leagues)) {
            // Skip processing if there are no leagues for the country
            return;
        }
        
        self::processInternationalSpots($websoccer, $db, $countryData, $leagues);
        self::processRelegationAndPromotion($websoccer, $db, $leagues);
    }
    
    private static function deleteAllMarkings(WebSoccer $websoccer, DbConnection $db) {
        $table = $websoccer->getConfig("db_prefix") . "_tabelle_markierung";
        
        // Deletes all rows from the table
        $db->queryDelete($table, "1 = %d", 1);
    }
    
    public static function fillMarkingsForAllCountries(WebSoccer $websoccer, DbConnection $db) {
        
        // Delete all previously generated table markings first
        self::deleteAllMarkings($websoccer, $db);
        
        $columns = "name";
        $fromTable = $websoccer->getConfig("db_prefix") . "_land";
        
        $countries = $db->queryCachedSelect($columns, $fromTable, "1=1");
        
        foreach ($countries as $country) {
            try {
                self::fillMarkingsForCountry($websoccer, $db, $country['name']);
            } catch (Exception $e) {
                echo "<b>Error for country " . $country['name'] . ":</b> " . $e->getMessage() . "<br>";
                error_log($e->getMessage());
            }
        }
    }
    
    private static function getCountryData(WebSoccer $websoccer, DbConnection $db, $countryName) {
        $columns = "*";
        $fromTable = $websoccer->getConfig("db_prefix") . "_land";
        $whereCondition = "name = '%s'";
        
        $result = $db->queryCachedSelect(
            $columns,
            $fromTable,
            $whereCondition,
            $countryName
            );
        
        return !empty($result) ? $result[0] : null;
    }
    
    private static function getLeaguesByCountry(WebSoccer $websoccer, DbConnection $db, $countryName) {
        $columns = "*";
        $fromTable = $websoccer->getConfig("db_prefix") . "_liga";
        $whereCondition = "land = '%s' ORDER BY division ASC, id ASC";
        
        $result = $db->queryCachedSelect(
            $columns,
            $fromTable,
            $whereCondition,
            $countryName
            );
        
        return $result;
    }
    
    private static function processInternationalSpots(WebSoccer $websoccer, DbConnection $db, $countryData, $leagues) {
        $mainLeague = self::getMainLeague($leagues);
        
        $clSpots   = isset($countryData['uefa_cl']) ? (int) $countryData['uefa_cl'] : 0;
        $ulSpots   = isset($countryData['uefa_ul']) ? (int) $countryData['uefa_ul'] : 0;
        $confSpots = isset($countryData['uefa_conf']) ? (int) $countryData['uefa_conf'] : 0;
        
        if ($clSpots > 0) {
            self::insertMarking(
                $websoccer,
                $db,
                $mainLeague['id'],
                "Champions League",
                "#FFD700",
                1,
                $clSpots
                );
        }
        
        if ($ulSpots > 0) {
            $start = $clSpots + 1;
            $end = $start + $ulSpots - 1;
            
            self::insertMarking(
                $websoccer,
                $db,
                $mainLeague['id'],
                "Europa League",
                "#1E90FF",
                $start,
                $end
                );
        }
        
        if ($confSpots > 0) {
            $start = $clSpots + $ulSpots + 1;
            $end = $start + $confSpots - 1;
            
            self::insertMarking(
                $websoccer,
                $db,
                $mainLeague['id'],
                "Conference League",
                "#32CD32",
                $start,
                $end
                );
        }
    }
    
    private static function processRelegationAndPromotion(WebSoccer $websoccer, DbConnection $db, $leagues) {
        echo "<b>in processRelegationAndPromotion</b><br><pre>";
        print_r($leagues);
        echo "</pre><br><br>";
        
        self::handlePromotionAndRelegation($websoccer, $db, $leagues);
    }
    
    private static function handlePromotionAndRelegation(WebSoccer $websoccer, DbConnection $db, $leagues) {
        echo "handlePromotionAndRelegation<br>";
        
        foreach ($leagues as $index => $currentLeague) {
            $upperLeague = $leagues[$index - 1] ?? null;
            $lowerLeague = $leagues[$index + 1] ?? null;
            
            $currentLeagueId = (int) $currentLeague['id'];
            $currentLeagueTeams = self::countTeamsInLeague($websoccer, $db, $currentLeagueId);
            
            echo "Processing league " . $currentLeagueId . " with " . $currentLeagueTeams . " teams<br>";
            
            if ($currentLeagueTeams < 1) {
                echo "Skipping league " . $currentLeagueId . " because it has no teams<br>";
                continue;
            }
            
            $spots = self::determinePromotionRelegationSpots($currentLeagueTeams);
            
            /*
             * Relegation:
             * Current league bottom places go down to lower league.
             */
            if ($lowerLeague) {
                $lowerLeagueId = (int) $lowerLeague['id'];
                
                $relegationStart = max(1, $currentLeagueTeams - $spots['relegation'] + 1);
                $relegationEnd = $currentLeagueTeams;
                
                if ($relegationStart <= $relegationEnd) {
                    echo "l_" . $currentLeagueId . " => Relegation places " . $relegationStart . " to " . $relegationEnd . " target league " . $lowerLeagueId . "<br>";
                    
                    self::insertMarking(
                        $websoccer,
                        $db,
                        $currentLeagueId,
                        "Relegation",
                        "#FF4500",
                        $relegationStart,
                        $relegationEnd,
                        $lowerLeagueId
                        );
                }
            }
            
            /*
             * Promotion:
             * Current league top places go up to upper league.
             */
            if ($upperLeague) {
                $upperLeagueId = (int) $upperLeague['id'];
                
                $promotionStart = 1;
                $promotionEnd = min($spots['promotion'], $currentLeagueTeams);
                
                if ($promotionStart <= $promotionEnd) {
                    echo "u_" . $currentLeagueId . " => Promotion places " . $promotionStart . " to " . $promotionEnd . " target league " . $upperLeagueId . "<br>";
                    
                    self::insertMarking(
                        $websoccer,
                        $db,
                        $currentLeagueId,
                        "Promotion",
                        "#87CEEB",
                        $promotionStart,
                        $promotionEnd,
                        $upperLeagueId
                        );
                }
            }
        }
    }
    
    private static function determinePromotionRelegationSpots($teamCount) {
        $teamCount = (int) $teamCount;
        
        if ($teamCount >= 21) {
            return [
                'promotion' => 4,
                'relegation' => 4
            ];
        } elseif ($teamCount >= 18) {
            return [
                'promotion' => 3,
                'relegation' => 3
            ];
        } elseif ($teamCount >= 14) {
            return [
                'promotion' => 2,
                'relegation' => 2
            ];
        } else {
            return [
                'promotion' => 1,
                'relegation' => 1
            ];
        }
    }
    
    private static function getMainLeague($leagues) {
        foreach ($leagues as $league) {
            if ((int) $league['division'] === 1) {
                return $league;
            }
        }
        
        throw new Exception("Main league not found.");
    }
    
    private static function insertMarking(
        WebSoccer $websoccer,
        DbConnection $db,
        $leagueId,
        $description,
        $color,
        $fromPlace,
        $toPlace,
        $targetLeagueId = null
        ) {
            echo "Inserting marking for league " . $leagueId . ": " . $description . " from " . $fromPlace . " to " . $toPlace . " target " . $targetLeagueId . "<br>";
            
            $columns = [
                "liga_id" => (int) $leagueId,
                "bezeichnung" => $description,
                "farbe" => $color,
                "platz_von" => (int) $fromPlace,
                "platz_bis" => (int) $toPlace,
                "target_league_id" => $targetLeagueId !== null ? (int) $targetLeagueId : null
            ];
            
            $fromTable = $websoccer->getConfig("db_prefix") . "_tabelle_markierung";
            
            $db->queryInsert($columns, $fromTable);
    }
    
    private static function countTeamsInLeague(WebSoccer $websoccer, DbConnection $db, $leagueId) {
        /*
         * Clubs are stored in ..._verein.
         */
        $teamTable = $websoccer->getConfig("db_prefix") . "_verein";
        $whereCondition = "liga_id = %d";
        
        $result = $db->querySelect(
            "COUNT(*) AS team_count",
            $teamTable,
            $whereCondition,
            (int) $leagueId
            );
        
        $teamCount = 0;
        
        if ($result) {
            $row = $result->fetch_array(MYSQLI_ASSOC);
            
            if ($row && isset($row['team_count'])) {
                $teamCount = (int) $row['team_count'];
            }
            
            $result->free();
        }
        
        echo "League ID: " . $leagueId . ", Team Count: " . $teamCount . "<br>";
        
        return $teamCount;
    }
}