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

    public static function fillMarkingsForAllCountries(WebSoccer $websoccer, DbConnection $db) {
        $columns = "name";
        $fromTable = $websoccer->getConfig("db_prefix") . "_land";

        $countries = $db->queryCachedSelect($columns, $fromTable, "1=1"); // Fetch all countries
        foreach ($countries as $country) {
            try {
                self::fillMarkingsForCountry($websoccer, $db, $country['name']);
            } catch (Exception $e) {
                // Log or handle errors gracefully
                error_log($e->getMessage());
            }
        }
    }

    private static function getCountryData(WebSoccer $websoccer, DbConnection $db, $countryName) {
        $columns = "*";
        $fromTable = $websoccer->getConfig("db_prefix") . "_land";
        $whereCondition = "name = '%s'";
        $result = $db->queryCachedSelect($columns, $fromTable, $whereCondition, $countryName);

        return !empty($result) ? $result[0] : null; // Return the first result
    }

    private static function getLeaguesByCountry(WebSoccer $websoccer, DbConnection $db, $countryName) {
        $columns = "*";
        $fromTable = $websoccer->getConfig("db_prefix") . "_liga";
        $whereCondition = "land = '%s' ORDER BY division ASC";
        $result = $db->queryCachedSelect($columns, $fromTable, $whereCondition, $countryName);

        return $result;
    }

    private static function processInternationalSpots(WebSoccer $websoccer, DbConnection $db, $countryData, $leagues) {
        $mainLeague = self::getMainLeague($leagues);

        if ($countryData['uefa_cl'] > 0) {
            self::insertMarking($websoccer, $db, $mainLeague['id'], "Champions League", "#FFD700", 1, $countryData['uefa_cl']);
        }
        if ($countryData['uefa_ul'] > 0) {
            $start = $countryData['uefa_cl'] + 1;
            $end = $start + $countryData['uefa_ul'] - 1;
            self::insertMarking($websoccer, $db, $mainLeague['id'], "Europa League", "#1E90FF", $start, $end);
        }
        if ($countryData['uefa_conf'] > 0) {
            $start = $countryData['uefa_cl'] + $countryData['uefa_ul'] + 1;
            $end = $start + $countryData['uefa_conf'] - 1;
            self::insertMarking($websoccer, $db, $mainLeague['id'], "Conference League", "#32CD32", $start, $end);
        }
    }

	private static function processRelegationAndPromotion(WebSoccer $websoccer, DbConnection $db, $leagues) {
		
		echo"<b>in processRelegationAndPromotion</b><br><pre>";
		print_r($leagues);
		echo"</pre><br><br>";
		
		self::handlePromotionAndRelegation($websoccer, $db, $leagues);
		
		foreach ($leagues as $i => $currentLeague) {
			$lowerLeague = $leagues[$i + 1] ?? null;

			//echo"currentLeague: ". $currentLeague['id'] . "<br>";

			$currentLeagueTeams = self::countTeamsInLeague($websoccer, $db, $currentLeague['id']);
			//#################################################
			//########### HIER KOMMTS NICHT AN!!!!!!!!!!!!!!!!!
			//#################################################
			//echo "Processing League: {$currentLeague['id']} with $currentLeagueTeams teams<br>";

			if ($currentLeagueTeams < 1) continue;

			$spots = self::determinePromotionRelegationSpots($currentLeagueTeams);
			//echo "Promotion spots: {$spots['promotion']}, Relegation spots: {$spots['relegation']}<br>";

			// Relegation logic
			if ($lowerLeague) {
				$relegationStart = max(1, $currentLeagueTeams - $spots['relegation'] + 1);
				$relegationEnd = $currentLeagueTeams;
				//echo "Relegation range: $relegationStart to $relegationEnd<br>";

				if ($relegationStart <= $relegationEnd) {
					self::insertMarking(
						$websoccer,
						$db,
						$currentLeague['id'],
						"Relegation",
						"#FF4500",
						$relegationStart,
						$relegationEnd,
						$lowerLeague['id']
					);
				}
			}

			// Promotion logic
			if ($lowerLeague) {
				$lowerLeagueTeams = self::countTeamsInLeague($websoccer, $db, $lowerLeague['id']);
				$promotionStart = 1;
				$promotionEnd = min($spots['promotion'], $lowerLeagueTeams);
				//echo "Promotion range: $promotionStart to $promotionEnd<br>";

				if ($promotionStart <= $promotionEnd) {
					self::insertMarking(
						$websoccer,
						$db,
						$lowerLeague['id'],
						"Promotion",
						"#87CEEB",
						$promotionStart,
						$promotionEnd,
						$currentLeague['id']
					);
				}
			}
		}
	}

    private static function determinePromotionRelegationSpots($teamCount) {
		//echo "Determining spots for team count: $teamCount<br>";
		if ($teamCount >= 21) {
			return ['promotion' => 4, 'relegation' => 4];
		} elseif ($teamCount >= 18) {
			return ['promotion' => 3, 'relegation' => 3];
		} elseif ($teamCount >= 14) {
			return ['promotion' => 2, 'relegation' => 2];
		} else {
			return ['promotion' => 1, 'relegation' => 1];
		}
	}

    private static function getMainLeague($leagues) {
        foreach ($leagues as $league) {
            if ($league['division'] == 1) {
                return $league;
            }
        }
        throw new Exception("Main league not found.");
    }

	private static function insertMarking(WebSoccer $websoccer, DbConnection $db, $leagueId, $description, $color, $fromPlace, $toPlace, $targetLeagueId = null) {
		//echo "Inserting marking for League $leagueId: $description from $fromPlace to $toPlace targeting $targetLeagueId<br>";
		$columns = [
			"liga_id" => $leagueId,
			"bezeichnung" => $description,
			"farbe" => $color,
			"platz_von" => $fromPlace,
			"platz_bis" => $toPlace,
			"target_league_id" => $targetLeagueId
		];

		$fromTable = $websoccer->getConfig("db_prefix") . "_tabelle_markierung";
		$db->queryInsert($columns, $fromTable);
	}
	
	private static function countTeamsInLeague(WebSoccer $websoccer, DbConnection $db, $leagueId) {
		$teamTable = $websoccer->getConfig("db_prefix") . "_team";
		$whereCondition = "liga_id = %d";

		$result = $db->querySelect(
			"COUNT(*) AS team_count",
			$teamTable,
			$whereCondition,
			$leagueId
		);

		$teamCount = (!empty($result) && isset($result[0]['team_count'])) ? (int) $result[0]['team_count'] : 0;
		//echo "League ID: $leagueId, Team Count: $teamCount<br>";
		return $teamCount;
	}
	
	private static function handlePromotionAndRelegation(WebSoccer $websoccer, DbConnection $db, $leagues) {
		
		echo"handlePromotionAndRelegation<br>";
		
		foreach ($leagues as $index => $currentLeague) {
			
			$upperLeague = $leagues[$index - 1] ?? null; // League above (if exists)
			$lowerLeague = $leagues[$index + 1] ?? null; // League below (if exists)

			$currentLeagueTeams = self::countTeamsInLeague($websoccer, $db, $currentLeague['id']);
			if ($currentLeagueTeams < 1) continue; // Skip leagues without teams

			$spots = self::determinePromotionRelegationSpots($currentLeagueTeams);

			// Handle relegation to lower league
			if ($lowerLeague) {
				$relegationStart = max(1, $currentLeagueTeams - $spots['relegation'] + 1);
				$relegationEnd = $currentLeagueTeams;
				if ($relegationStart <= $relegationEnd) {
			
					echo"l_". currentLeague['id'] ."<br>";
			
					self::insertMarking(
						$websoccer,
						$db,
						$currentLeague['id'],
						"Relegation",
						"#FF4500",
						$relegationStart,
						$relegationEnd,
						$lowerLeague['id']
					);
				}
			}

			// Handle promotion to upper league
			if ($upperLeague) {
				$upperLeagueTeams = self::countTeamsInLeague($websoccer, $db, $upperLeague['id']);
				$promotionStart = 1;
				$promotionEnd = min($spots['promotion'], $upperLeagueTeams);
				if ($promotionStart <= $promotionEnd) {
			
					echo"u_". currentLeague['id'] ."<br>";
			
					self::insertMarking(
						$websoccer,
						$db,
						$currentLeague['id'],
						"Promotion",
						"#87CEEB",
						$promotionStart,
						$promotionEnd,
						$upperLeague['id']
					);
				}
			}
		}
	}
	
}