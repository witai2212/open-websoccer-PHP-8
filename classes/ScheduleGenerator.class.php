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
define('DUMMY_TEAM_ID', -1);

/**
 * Generates round robin schedules.
 */
class ScheduleGenerator {
	private $_websoccer;
	private $_db;
	
	/**
	 * 
	 * @param WebSoccer $websoccer application context
	 * @param DbConnection $db DB connection
	 */
	function __construct(WebSoccer $websoccer, DbConnection $db) {
		$this->_websoccer = $websoccer;
		$this->_db = $db;
		$this->_teamsWithSoonEndingContracts = array();
	}	
	
	/**
	 * Generates a randomized tournament schedule. Odd number of teams supported.
	 * 
	 * @param array $teamIds array of team IDs
	 * @return array Array with key=matchday number (starting with 1), value= array of matches (each match is array with HomeId, GuestId).
	 */
	public static function createRoundRobinSchedule($teamIds) {
		
		// randomize
		shuffle($teamIds);
		
		$noOfTeams = count($teamIds);
		
		// support odd number of teams by adding a dummy team which will be filtered later
		if ($noOfTeams % 2 !== 0) {
			$teamIds[] = DUMMY_TEAM_ID;
			$noOfTeams++;
		}
		
		$noOfMatchDays = $noOfTeams - 1;
		
		// sort teams for every match day
		$sortedMatchdays = array();
		
		// fill first match day in the order of given teams
		foreach ($teamIds as $teamId) {
			$sortedMatchdays[1][] = $teamId;
		}
		
		for ($matchdayNo = 2; $matchdayNo <= $noOfMatchDays; $matchdayNo++) {
			
			// first half of row
			$rowCenterWithoutFixedEnd = $noOfTeams / 2 - 1;
			for ($teamIndex = 0; $teamIndex < $rowCenterWithoutFixedEnd; $teamIndex++) {
				$targetIndex = $teamIndex + $noOfTeams / 2;
				$sortedMatchdays[$matchdayNo][] = $sortedMatchdays[$matchdayNo - 1][$targetIndex];
			}
			
			// second half
			for ($teamIndex = $rowCenterWithoutFixedEnd; $teamIndex < $noOfTeams - 1; $teamIndex++) {
				$targetIndex = 0 + $teamIndex - $rowCenterWithoutFixedEnd;
				$sortedMatchdays[$matchdayNo][] = $sortedMatchdays[$matchdayNo - 1][$targetIndex];
			}
			
			// append fixed end
			$sortedMatchdays[$matchdayNo][] = $teamIds[count($teamIds) - 1];
		}
		
		// create combinations
		$schedule = array();
		$matchesNo = $noOfTeams / 2;
		for ($matchDayNo = 1; $matchDayNo <= $noOfMatchDays; $matchDayNo++) {
			
			$matches = array();
			for ($teamNo = 1; $teamNo <= $matchesNo; $teamNo++) {
				
				$homeTeam = $sortedMatchdays[$matchDayNo][$teamNo - 1];
				$guestTeam = $sortedMatchdays[$matchDayNo][count($teamIds) - $teamNo];
				
				if ($homeTeam == DUMMY_TEAM_ID || $guestTeam == DUMMY_TEAM_ID) {
					continue;
				}
				
				// alternate the first match (which contains the fixed end)
				if ($teamNo === 1 && $matchDayNo % 2 == 0) {
					$swapTemp = $homeTeam;
					$homeTeam = $guestTeam;
					$guestTeam = $swapTemp;
				}
				
				$match = array($homeTeam, $guestTeam);
				$matches[] = $match;
			}
			
			$schedule[$matchDayNo] = $matches;
		}

		return $schedule;
	}
	
	private static function shuffle_assoc($my_array) {
		
		// Get the keys of the associative array
		$keys = array_keys($my_array);

		// Shuffle the keys
		shuffle($keys);

		// Initialize an empty array to store the shuffled associative array
		$new = array();

		// Iterate through the shuffled keys
		foreach ($keys as $key) {
			// Assign each key-value pair to the new array in shuffled order
			$new[$key] = $my_array[$key];
		}

		// Update the original array with the shuffled result
		$my_array = $new;

		// Return the shuffled associative array
		return $my_array;
	}
			
	
	/**
	* Generates a randomized tournament schedule. Odd number of teams supported.
	*
	* @param array $teamIds array of team IDs
	* @return array Array with key=matchday number (starting with 1), value= array of matches (each match is array with HomeId, GuestId).
	* @param [firstmatchday_date] => 09.01.2025
	* @param [firstmatchday_time] => 13:06 [hour] => 13 [minute] => 06 [timebreak] => 5
	*/
	public static function createUEFACupGroupSchedule(
	    $website,
	    $db,
	    $teams,
	    $firstmatchday_date,
	    $hour,
	    $minute,
	    $timebreak,
	    $cup_name,
	    $totalMatchesPerTeam,
	    $group_name,
	    $cup_round
	    ) {
	        $teams = array_values(array_unique(array_filter(
	            array_map('intval', $teams),
	            function ($teamId) {
	                return $teamId > 0;
	            }
	            )));
	        
	        $hour = (int) $hour;
	        $minute = (int) $minute;
	        $timebreak = (int) $timebreak;
	        $totalMatchesPerTeam = (int) $totalMatchesPerTeam;
	        
	        if (count($teams) < 2) {
	            return;
	        }
	        
	        if ($timebreak < 1) {
	            throw new Exception("Time break must be at least 1.");
	        }
	        
	        if ($totalMatchesPerTeam < 1) {
	            throw new Exception("Matches per team must be at least 1.");
	        }
	        
	        /*
	         * ------------------------------------------------------------
	         * Build initial timestamp.
	         * Expected format: dd.mm.yyyy
	         * ------------------------------------------------------------
	         */
	        $dateParts = explode('.', (string) $firstmatchday_date);
	        
	        if (count($dateParts) !== 3) {
	            throw new Exception("Invalid first matchday date. Expected format: dd.mm.yyyy.");
	        }
	        
	        $day = (int) $dateParts[0];
	        $month = (int) $dateParts[1];
	        $year = (int) $dateParts[2];
	        
	        if (!checkdate($month, $day, $year)) {
	            throw new Exception("Invalid first matchday date.");
	        }
	        
	        $timestamp = mktime($hour, $minute, 0, $month, $day, $year);
	        
	        /*
	         * ------------------------------------------------------------
	         * Create proper round-robin matchdays.
	         *
	         * One generated round = one matchday.
	         * Therefore each team can only occur once per date.
	         * ------------------------------------------------------------
	         */
	        $matchdays = array();
	        
	        while (count($matchdays) < $totalMatchesPerTeam) {
	            $roundRobinSchedule = self::createRoundRobinSchedule($teams);
	            
	            foreach ($roundRobinSchedule as $matchesOfRound) {
	                if (count($matchdays) >= $totalMatchesPerTeam) {
	                    break;
	                }
	                
	                $matchdays[] = $matchesOfRound;
	            }
	        }
	        
	        $matchTable = $website->getConfig("db_prefix") . "_spiel";
	        $matchdayNumber = 1;
	        
	        foreach ($matchdays as $matchesOfMatchday) {
	            
	            /*
	             * ------------------------------------------------------------
	             * Safety check 1:
	             * Within this UEFA matchday no team may appear twice.
	             * ------------------------------------------------------------
	             */
	            $teamsOnThisMatchday = array();
	            
	            foreach ($matchesOfMatchday as $match) {
	                $homeTeamId = isset($match[0]) ? (int) $match[0] : 0;
	                $guestTeamId = isset($match[1]) ? (int) $match[1] : 0;
	                
	                if ($homeTeamId <= 0 || $guestTeamId <= 0) {
	                    continue;
	                }
	                
	                if (
	                    isset($teamsOnThisMatchday[$homeTeamId]) ||
	                    isset($teamsOnThisMatchday[$guestTeamId])
	                    ) {
	                        throw new Exception(
	                            "Invalid UEFA schedule: A team appears more than once on the same matchday "
	                            . "(group " . $group_name . ", matchday " . $matchdayNumber . ")."
	                            );
	                    }
	                    
	                    $teamsOnThisMatchday[$homeTeamId] = true;
	                    $teamsOnThisMatchday[$guestTeamId] = true;
	            }
	            
	            /*
	             * ------------------------------------------------------------
	             * Safety check 2:
	             * If one of these teams already has ANY match on that calendar day
	             * in *_spiel, move the complete UEFA matchday forward.
	             *
	             * This prevents:
	             * - league match + UEFA match on same day
	             * - another cup match + UEFA match on same day
	             * - already existing accidental duplicate bookings
	             * ------------------------------------------------------------
	             */
	            $shiftAttempts = 0;
	            
	            while (
	                self::uefaCupScheduleTeamsHaveMatchOnDate(
	                    $website,
	                    $db,
	                    array_keys($teamsOnThisMatchday),
	                    $timestamp
	                    )
	                ) {
	                    $timestamp = self::uefaCupScheduleAddCalendarDays(
	                        $timestamp,
	                        $timebreak,
	                        $hour,
	                        $minute
	                        );
	                    
	                    $shiftAttempts++;
	                    
	                    if ($shiftAttempts > 365) {
	                        throw new Exception(
	                            "Could not find a conflict-free date for UEFA group "
	                            . $group_name
	                            . ", matchday "
	                            . $matchdayNumber
	                            . "."
	                            );
	                    }
	                }
	                
	                /*
	                 * ------------------------------------------------------------
	                 * Insert all matches of this UEFA matchday.
	                 * ------------------------------------------------------------
	                 */
	                foreach ($matchesOfMatchday as $match) {
	                    $homeTeamId = isset($match[0]) ? (int) $match[0] : 0;
	                    $guestTeamId = isset($match[1]) ? (int) $match[1] : 0;
	                    
	                    if ($homeTeamId <= 0 || $guestTeamId <= 0) {
	                        continue;
	                    }
	                    
	                    $stadium = StadiumsDataService::getStadiumByTeamId(
	                        $website,
	                        $db,
	                        $homeTeamId
	                        );
	                    
	                    $teamcolumns = array();
	                    $teamcolumns["spieltyp"] = "Pokalspiel";
	                    $teamcolumns["elfmeter"] = "0";
	                    $teamcolumns["pokalname"] = $cup_name;
	                    $teamcolumns["pokalrunde"] = $cup_round;
	                    $teamcolumns["pokalgruppe"] = $group_name;
	                    $teamcolumns["datum"] = $timestamp;
	                    $teamcolumns["home_verein"] = $homeTeamId;
	                    $teamcolumns["gast_verein"] = $guestTeamId;
	                    $teamcolumns["stadion_id"] = $stadium["stadium_id"];
	                    
	                    $db->queryInsert($teamcolumns, $matchTable);
	                }
	                
	                /*
	                 * ------------------------------------------------------------
	                 * Next regular UEFA matchday.
	                 * ------------------------------------------------------------
	                 */
	                $timestamp = self::uefaCupScheduleAddCalendarDays(
	                    $timestamp,
	                    $timebreak,
	                    $hour,
	                    $minute
	                    );
	                
	                $matchdayNumber++;
	        }
	}
	
	private static function uefaCupScheduleTeamsHaveMatchOnDate(
	    $website,
	    $db,
	    array $teamIds,
	    $timestamp
	    ) {
	        $teamIds = array_values(array_unique(array_filter(
	            array_map('intval', $teamIds),
	            function ($teamId) {
	                return $teamId > 0;
	            }
	            )));
	        
	        if (empty($teamIds)) {
	            return false;
	        }
	        
	        $day = (int) date('j', $timestamp);
	        $month = (int) date('n', $timestamp);
	        $year = (int) date('Y', $timestamp);
	        
	        $dayStart = mktime(0, 0, 0, $month, $day, $year);
	        $nextDayStart = mktime(0, 0, 0, $month, $day + 1, $year);
	        
	        $teamIdsSql = implode(',', $teamIds);
	        $matchTable = $website->getConfig("db_prefix") . "_spiel";
	        
	        $whereCondition =
	        "datum >= %d "
	            . "AND datum < %d "
	                . "AND ("
	                    . "home_verein IN (" . $teamIdsSql . ") "
	                        . "OR gast_verein IN (" . $teamIdsSql . ")"
	                            . ")";
	                            
	                            $result = $db->querySelect(
	                                "id",
	                                $matchTable,
	                                $whereCondition,
	                                array($dayStart, $nextDayStart),
	                                1
	                                );
	                            
	                            $match = $result->fetch_array();
	                            $result->free();
	                            
	                            return $match ? true : false;
	}
	
	private static function uefaCupScheduleAddCalendarDays(
	    $timestamp,
	    $days,
	    $hour,
	    $minute
	    ) {
	        $timestamp = (int) $timestamp;
	        $days = (int) $days;
	        $hour = (int) $hour;
	        $minute = (int) $minute;
	        
	        return mktime(
	            $hour,
	            $minute,
	            0,
	            (int) date('n', $timestamp),
	            (int) date('j', $timestamp) + $days,
	            (int) date('Y', $timestamp)
	            );
	}
	
	public static function generateCupMatchSchedule($website, $db, $teams, $matchdate, $cupName) {
	    
	    // Shuffle teams to randomize pairings.
	    shuffle($teams);
	    
	    $matches = array();
	    for ($i = 0; $i < count($teams); $i += 2) {
	        if (isset($teams[$i + 1])) {
	            $matches[] = array(
	                (int) $teams[$i]['club_id'],
	                (int) $teams[$i + 1]['club_id']
	            );
	        }
	    }
	    
	    $matchTable = $website->getConfig("db_prefix") . "_spiel";
	    $baseTimestamp = (int) $matchdate;
	    $slotMinutes = 10;
	    
	    foreach ($matches as $matchIndex => $clubs) {
	        $homeTeamId = (int) $clubs[0];
	        $guestTeamId = (int) $clubs[1];
	        
	        // Spread kick-off times slightly to avoid creating every first-round cup match at the exact same timestamp.
	        $matchTimestamp = $baseTimestamp + ($matchIndex * $slotMinutes * 60);
	        
	        if (class_exists('SeasonRolloverScheduleService')
	            && SeasonRolloverScheduleService::teamsHaveMatchOnDay($website, $db, array($homeTeamId, $guestTeamId), $matchTimestamp)) {
	            $matchTimestamp = SeasonRolloverScheduleService::findAvailableTimestampForTeams(
	                $website,
	                $db,
	                array($homeTeamId, $guestTeamId),
	                $baseTimestamp,
	                array(2),
	                array(array((int) date('G', $baseTimestamp), (int) date('i', $baseTimestamp))),
	                42
	            );
	        }
	        
	        $stadium = StadiumsDataService::getStadiumByTeamId($website, $db, $homeTeamId);
	        
	        $teamcolumns = array();
	        $teamcolumns["spieltyp"] = "Pokalspiel";
	        $teamcolumns["elfmeter"] = "1";
	        $teamcolumns["pokalname"] = $cupName;
	        $teamcolumns["pokalrunde"] = "Round 1";
	        $teamcolumns["datum"] = $matchTimestamp;
	        $teamcolumns["home_verein"] = $homeTeamId;
	        $teamcolumns["gast_verein"] = $guestTeamId;
	        if (isset($stadium['stadium_id'])) {
	            $teamcolumns["stadion_id"] = (int) $stadium['stadium_id'];
	        }
	        
	        $db->queryInsert($teamcolumns, $matchTable);
	    }
	}

}

?>