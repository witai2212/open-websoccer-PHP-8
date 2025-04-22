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
 * Data service for training data
 */
class TrainingDataService {
	
	public static function countTrainers(WebSoccer $websoccer, DbConnection $db) {
		$fromTable = $websoccer->getConfig("db_prefix") . "_trainer";
	
		// where
		$whereCondition = "1=1";
	
		// select
		$columns = "COUNT(*) AS hits";
	
		$result = $db->querySelect($columns, $fromTable, $whereCondition);
		$trainers = $result->fetch_array();
		$result->free();
	
		return $trainers["hits"];
	}
	
	public static function getTrainers(WebSoccer $websoccer, DbConnection $db, $startIndex, $entries_per_page) {
		$fromTable = $websoccer->getConfig("db_prefix") . "_trainer";
	
		// where
		$whereCondition = "1=1 ORDER BY salary DESC";
	
		// select
		$columns = "*";
		
		$limit = $startIndex .",". $entries_per_page;
	
		$trainers = array();
		$result = $db->querySelect($columns, $fromTable, $whereCondition, null, $limit);
		while ($trainer = $result->fetch_array()) {
			$trainers[] = $trainer;
		}
		$result->free();
		
		return $trainers;
	}
	
	public static function getTrainerById(WebSoccer $websoccer, DbConnection $db, $trainerId) {
		$fromTable = $websoccer->getConfig("db_prefix") . "_trainer";
	
		// where
		$whereCondition = "id = %d";
	
		// select
		$columns = "*";
	
		$result = $db->querySelect($columns, $fromTable, $whereCondition, $trainerId);
		$trainer = $result->fetch_array();
		$result->free();
	
		return $trainer;
	}
	
	public static function countRemainingTrainingUnits(WebSoccer $websoccer, DbConnection $db, $teamId) {
		$columns = "COUNT(*) AS hits";
		$fromTable = $websoccer->getConfig("db_prefix") . "_training_unit";
		$whereCondition = "team_id = %d AND date_executed = 0 OR date_executed IS NULL";
		$parameters = $teamId;
	
		$result = $db->querySelect($columns, $fromTable, $whereCondition, $parameters);
		$units = $result->fetch_array();
		$result->free();
		
		return $units["hits"];
	}
	
	public static function getLatestTrainingExecutionTime(WebSoccer $websoccer, DbConnection $db, $teamId) {
		$columns = "date_executed";
		$fromTable = $websoccer->getConfig("db_prefix") . "_training_unit";
		$whereCondition = "team_id = %d AND date_executed > 0 ORDER BY date_executed DESC";
		$parameters = $teamId;
		
		$result = $db->querySelect($columns, $fromTable, $whereCondition, $parameters, 1);
		$unit = $result->fetch_array();
		$result->free();
		
		if (isset($unit["date_executed"])) {
			return $unit["date_executed"];
		} else {
			return 0;
		}
	}
	
	public static function getValidTrainingUnit(WebSoccer $websoccer, DbConnection $db, $teamId) {
		$columns = "id,trainer_id";
		$fromTable = $websoccer->getConfig("db_prefix") . "_training_unit";
		$whereCondition = "team_id = %d AND date_executed = 0 OR date_executed IS NULL ORDER BY id ASC";
		$parameters = $teamId;
	
		$result = $db->querySelect($columns, $fromTable, $whereCondition, $parameters, 1);
		$unit = $result->fetch_array();
		$result->free();
		
		return $unit;
	}
	
	public static function getTrainingUnitById(WebSoccer $websoccer, DbConnection $db, $teamId, $unitId) {
		$columns = "*";
		$fromTable = $websoccer->getConfig("db_prefix") . "_training_unit";
		$whereCondition = "id = %d AND team_id = %d";
		$parameters = array($unitId, $teamId);
	
		$result = $db->querySelect($columns, $fromTable, $whereCondition, $parameters, 1);
		$unit = $result->fetch_array();
		$result->free();
	
		return $unit;
	}
	
	public static function generateTrainer(WebSoccer $websoccer, DbConnection $db) {
		
		// get user number - hits
		$users = UsersDataService::countActiveUsersWithHighscore($websoccer, $db);
		$trainers = self::countTrainers($websoccer, $db);
		
		$anzahl = ($users*3)-$trainers;
		//$anzahl = 4;
		
		if($anzahl>0) {
			
			for($i=0;$i<$anzahl;$i++) {
				
				// GENERATE RANDOM PLAYER COUNTRY
				$countryStr = "SELECT country FROM ". $websoccer->getConfig("db_prefix") . "_country ORDER BY RAND() LIMIT 1";
				$countryQuery = $db->executeQuery($countryStr)->fetch_array();
				$country1 = $countryQuery['country'];
				$vname = self::getName($country1);
				
				// GENERATE RANDOM PLAYER COUNTRY
				$countryStr = "SELECT country FROM ". $websoccer->getConfig("db_prefix") . "_country ORDER BY RAND() LIMIT 1";
				$countryQuery = $db->executeQuery($countryStr)->fetch_array();
				$country1 = $countryQuery['country'];
				$nname = self::getName($country1);
				
				$vname = str_replace("'", "", $vname);
				$nname = str_replace("'", "", $nname);
				
				$ratio = round(75000/90,0);
			
				mt_srand((double)microtime()*1000000);
				$expertise = mt_rand(60,90);
				
				mt_srand((double)microtime()*1000000);
				$p_technique = mt_rand(60,90);
				
				mt_srand((double)microtime()*1000000);
				$p_stamina = mt_rand(60,90);
				
				
				$x = ($expertise+$p_technique+$p_stamina)/3;
				$y = $x*$ratio;
				
				$salary = round($y,0);
				
				$name = $vname ." ". $nname;
				
				$insStr = "INSERT INTO ". $websoccer->getConfig("db_prefix") ."_trainer (name, salary, p_technique, p_stamina, expertise)
							VALUES ('$name', '$salary', '$p_technique', '$p_stamina', '$expertise')";
				$db->executeQuery($insStr);
				
			}
		}
	}
	
	private static function getName($country) {
		
		global $conf;
		$db = DbConnection::getInstance();
		$db->connect($conf["db_host"], $conf["db_user"], $conf["db_passwort"], $conf["db_name"]);
		
		mt_srand((double)microtime()*1000000);
		$rand = mt_rand(1,8);
		
		$country = ltrim($country);
		$krz = 0;

		if($country=="England") {
			$krz = "EN";
		} else
		
		if($country=="USA") {
			$krz = "EN";
		} else
		
		if($country=="Deutschland") {
			$krz = "DE";
		} else
		
		if($country=="Frankreich") {
			$krz = "FR";
		} else
		
		if($country=="Spanien") {
			$krz = "ES";
		} else
		
		if($country=="Niederlande") {
			$krz = "NL";
		} else
		
		if($country=="Italien") {
			$krz = "IT";
		} else {
		
			$contStr = "SELECT continent FROM ". $conf["db_prefix"] ."_country
								WHERE country LIKE '%$country%' LIMIT 1";
			$contQuery = $db->executeQuery($contStr)->fetch_array();
			if(isset($contQuery['continent'])) {
				$continent = $contQuery['continent'];
				
				if($continent=="AFR") {
					$krz = "AFR";
				}
				if($continent=="AME") {
					$krz = "AME";
				}
			} else {
				if($rand==1) {
					$krz = "AME";
				} else if($rand==2) {
					$krz = "AFR";
				} else if($rand==3) {
					$krz = "DE";
				} else if($rand==4) {
					$krz = "EN";
				} else if($rand==5) {
					$krz = "FR";
				} else if($rand==6) {
					$krz = "ES";
				} else if($rand==7) {
					$krz = "IT";
				} else if($rand==8) {
					$krz = "NL";
				}
			}
		}
		
		if($krz!=0) {
		
			$nameStr = "SELECT name FROM ". $conf["db_prefix"] ."_name
							WHERE continent='$krz'
							ORDER BY RAND() LIMIT 1";
			//echo $nameStr ."<br>";
			$nameQuery = $db->executeQuery($nameStr)->fetch_array();
			$name = $nameQuery['name'];
			
		} else {
			
			$nameStr = "SELECT name FROM ". $conf["db_prefix"] ."_name ORDER BY RAND() LIMIT 1";
			//echo $nameStr ."<br>";
			$nameQuery = $db->executeQuery($nameStr)->fetch_array();
			$name = $nameQuery['name'];
		}
		
		$country = "";
		
		return $name;
	}
}
?>