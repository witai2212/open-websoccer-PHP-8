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
 * Data service for stockmarket/teams-finances
 */
class SalaryStatisticsDataService {
    
    /**
     * getting salary avarages
     */
	public static function getAvgSalaries(WebSoccer $websoccer, DbConnection $db) {
		
		$sqlStr = "SELECT * FROM ". $websoccer->getConfig("db_prefix") ."_stats_salary ORDER BY season DESC LIMIT 7";
		$result = $db->executeQuery($sqlStr);
		
		$salaries = array();
		
		$i = 0;
		while ($salary = $result->fetch_array()) {
		    $salaries[$i] = $salary;
		    $i++;
		}
		$result->free();
		
		$salaries[0] = $salaries[0]['salary'];
		$salaries[1] = $salaries[1]['salary'];
		$salaries[2] = $salaries[2]['salary'];
		$salaries[3] = $salaries[3]['salary'];
		$salaries[4] = $salaries[4]['salary'];
		$salaries[5] = $salaries[5]['salary'];
		$salaries[6] = $salaries[6]['salary'];
		
		return $salaries;
	}
	
	public static function updateSalaryStats(WebSoccer $websoccer, DbConnection $db) {
	
		// get current avg salary
		$sqlStr = "SELECT AVG(vertrag_gehalt) AS avg_salary FROM ". $websoccer->getConfig("db_prefix") ."_spieler";
		$result = $db->executeQuery($sqlStr);
		$salary = $result->fetch_array();
		
		$avg_salary = round($salary['avg_salary'],0);
		
		// get last season
		$seasonStr = "SELECT season FROM ". $websoccer->getConfig("db_prefix") ."_stats_salary ORDER BY season DESC LIMIT 1";
		$result = $db->executeQuery($seasonStr);
		$season = $result->fetch_array();
		
		$insStr = "INSERT INTO ". $websoccer->getConfig("db_prefix") ."_stats_salary (season, salary) VALUES ('".$season['season']."', '$avg_salary')";
		$db->executeQuery($insStr);
		
	}
}
?>
