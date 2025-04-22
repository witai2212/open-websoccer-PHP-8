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
	public static function createUEFACupGroupSchedule($website, $db, $teams, $firstmatchday_date, $hour, $minute, $timebreak, $cup_name, $totalMatchesPerTeam, $group_name, $cup_round) {		
	    
    /*
		//Example array
		$teams = [
	        "Team 1", "Team 2", "Team 3", "Team 4",
	        "Team 5", "Team 6", "Team 7", "Team 8",
	        "Team 9", "Team 10", "Team 11", "Team 12",
	        "Team 13", "Team 14", "Team 15", "Team 16"
			];
	
	    $matches = [];
	    $matchesPerTeam = 1; // Each team should play 8 matches
	    $totalMatches = count($teams) * $matchesPerTeam / 2; // Total matches needed
	    $matchesPlayed = array_fill(0, count($teams), 0); // Tracks matches played by each team
	
   
	    while (count($matches) < $totalMatches) {
	        // Shuffle teams to ensure randomness
	        shuffle($teams);
	        
	        for ($i = 0; $i < count($teams) - 1; $i++) {
	            for ($j = $i + 1; $j < count($teams); $j++) {
	                $team1 = $teams[$i];
	                $team2 = $teams[$j];
					
					echo $team1 ." - ". $team2 ."<br>";
	                
	                // Ensure neither team has exceeded the match limit and the match doesn't already exist
	                if ($matchesPlayed[$i] < $matchesPerTeam && $matchesPlayed[$j] < $matchesPerTeam) {
	                    if (!in_array([$team1, $team2], $matches) && !in_array([$team2, $team1], $matches)) {
	                        $matches[] = [$team1, $team2];
	                        $matchesPlayed[$i]++;
	                        $matchesPlayed[$j]++;
	                    }
	                }
	                
	                // Break if all matches are scheduled
	                if (count($matches) >= $totalMatches) {
	                    break 2;
	                }
	            }
	        }
	    }
	    
		$matches = self::shuffle_assoc($matches);
		echo"<pre>";
		print_r($matches);
		echo"</pre>";
	    // Display the matches
	
		foreach ($matches as $index => $match) {
	        echo "Match " . ($index + 1) . ": " . $match[0] . " vs " . $match[1] . "<br>";
	    }
	*/
		
		// round robin
		// Initialize an array to store matches
		$matches = [];

		// Create a count tracker for each team's matches
		$matchCount = array_fill_keys($teams, 0);

		// Total number of matches each team should play
		//$totalMatchesPerTeam;

		// Generate matches ensuring no team exceeds 8 matches
		while (true) {
			// Shuffle teams for randomness
			shuffle($teams);
			
			// Pair teams for matches
			for ($i = 0; $i < count($teams); $i += 2) {
				$team1 = $teams[$i];
				$team2 = $teams[$i + 1];
				
				// Check if both teams can still play more matches
				if ($matchCount[$team1] < $totalMatchesPerTeam && $matchCount[$team2] < $totalMatchesPerTeam) {
					$matches[] = [$team1, $team2];
					$matchCount[$team1]++;
					$matchCount[$team2]++;
				}
			}
			
			// Break the loop if all teams have played the required number of matches
			if (min($matchCount) >= $totalMatchesPerTeam) {
				break;
			}
		}
		
		// make timestamp
		$firstmatchday_date = explode('.', $firstmatchday_date);
		$day = $firstmatchday_date[0];
		$month = $firstmatchday_date[1];
		$year = $firstmatchday_date[2];
		$second = 0; // Optional, defaults to 0 if omitted

		// Create the timestamp
		$timestamp = mktime($hour, $minute, $second, $month, $day, $year);
		
		$j = 1;
		$p = 1;
		foreach ($matches as $match) {
			
			if($j % 16 == 0) {
				
				$timestamp = $timestamp+($timebreak*86400);
				$readable = date('d.m.Y H:m', $timestamp);
				$p++;
			}
			$j++;
			
			// get home team stadium
			$stadium = StadiumsDataService::getStadiumByTeamId($website, $db, $match[0]);
			
			/******************************************************************/
			$matchTable = $website->getConfig("db_prefix") . "_spiel";
			
			$teamcolumns = array();
			$teamcolumns["spieltyp"] = "Pokalspiel";
			$teamcolumns["elfmeter"] = "0";
			$teamcolumns["pokalname"] = $cup_name;
			$teamcolumns["pokalrunde"] = $cup_round;
			$teamcolumns["pokalgruppe"] = $group_name;
			$teamcolumns["datum"] = $timestamp;
			$teamcolumns["home_verein"] = $match[0];
			$teamcolumns["gast_verein"] = $match[1];
			$teamcolumns["stadion_id"] = $stadium['stadium_id'];
			
			//echo"TS: ". $timestamp ."<br>";
			$db->queryInsert($teamcolumns, $matchTable);
			/******************************************************************/
		}

		// Verify match counts
		//echo"Matches: ". count($matches) ."<br>";
		//echo "\nMatch Count per Team:\n";
		//print_r($matchCount);	
	    
	}
	
	public static function generateCupMatchSchedule($website, $db, $teams, $matchdate, $cupName) {
	    
	    // Step 1: Define the array of teams
	    //$teams = ['Team A', 'Team B', 'Team C', 'Team D', 'Team E', 'Team F', 'Team G', 'Team H'];
	    
	    // Step 2: Shuffle the array to randomize the order
	    shuffle($teams);
	    
	    // Step 3: Create the match pairs
	    $matches = [];
	    for ($i = 0; $i < count($teams); $i += 2) {
	        // Check if there are two teams to pair
	        if (isset($teams[$i+1])) {
	            $matches[] = $teams[$i]['club_id'] . '_vs_' . $teams[$i+1]['club_id'];
	        } else {
	            // Handle the odd number of teams
	            $matches[] = $teams[$i]['club_id'] . ' has no opponent (bye round)';
	        }
	    }
	    
	    // Step 4: Display the matches
	    //echo "Match Schedule:<br>";
	    foreach ($matches as $match) {
	        
	        $clubs = explode("_vs_", $match);
	        
	        // get home team stadium
	        $stadium = StadiumsDataService::getStadiumByTeamId($website, $db, $clubs[0]);
	        
	        /******************************************************************/
	        $matchTable = $website->getConfig("db_prefix") . "_spiel";
	        
	        $teamcolumns = array();
	        $teamcolumns["spieltyp"] = "Pokalspiel";
	        $teamcolumns["elfmeter"] = "1";
	        $teamcolumns["pokalname"] = $cupName;
	        $teamcolumns["pokalrunde"] = "Round 1";
	        $teamcolumns["datum"] = $matchdate;
	        $teamcolumns["home_verein"] = $clubs[0];
	        $teamcolumns["gast_verein"] = $clubs[1];
	        $teamcolumns["stadion_id"] = $stadium['stadium_id'];
	        
	        //echo"TS: ". $timestamp ."<br>";
	        $db->queryInsert($teamcolumns, $matchTable);
	        /******************************************************************/     
	        
	    }

	}
}

?>