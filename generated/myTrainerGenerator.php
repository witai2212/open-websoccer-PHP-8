<?php
error_reporting(E_ALL);
define("BASE_FOLDER", __DIR__ ."/..");

include(BASE_FOLDER . "/classes/DbConnection.class.php");
include(BASE_FOLDER . "/classes/SecurityUtil.class.php");

define("PHP_MIN_VERSION", "5.3.0");
define("WRITABLE_FOLDERS", "generated/,uploads/club/,uploads/cup/,uploads/player/,uploads/sponsor/,uploads/stadium/,uploads/stadiumbuilder/,uploads/stadiumbuilding/,uploads/users/,admin/config/jobs.xml,admin/config/termsandconditions.xml");
define("DEFAULT_DB_PREFIX", "ws3");
define("CONFIGFILE", BASE_FOLDER . "/generated/config.inc.php");
define("DDL_FULL", "ws3_ddl_full.sql");
define("DDL_MIGRATION", "ws3_ddl_upgrade.sql");
define("DDL_INDEX", "ws3_ddl_index.sql");

session_start();

ignore_user_abort(TRUE);
set_time_limit(0);

include(CONFIGFILE);

$db = DbConnection::getInstance();
$db->connect($conf["db_host"], $conf["db_user"], $conf["db_passwort"], $conf["db_name"]);

//###########################################################################################
include("../admin/config/companies.php");

	function getName($country) {
		
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

	// get user number - hits
	//$users = UsersDataService::countActiveUsersWithHighscore($website, $db);
	//$trainers = TraningDataService::countTrainers($website, $db);
	
	//$anzahl = ($users['hits']*2)-$trainers['hits'];
	$anzahl = 4;
	
	if($anzahl>0) {
		
		for($i=0;$i<$anzahl;$i++) {
			
			// GENERATE RANDOM PLAYER COUNTRY
            $countryStr = "SELECT country FROM ". $conf["db_prefix"] ."_country ORDER BY RAND() LIMIT 1";
            $countryQuery = $db->executeQuery($countryStr)->fetch_array();
            $country1 = $countryQuery['country'];
            $vname = getName($country1);
			
			// GENERATE RANDOM PLAYER COUNTRY
            $countryStr = "SELECT country FROM ". $conf["db_prefix"] ."_country ORDER BY RAND() LIMIT 1";
            $countryQuery = $db->executeQuery($countryStr)->fetch_array();
            $country1 = $countryQuery['country'];
            $nname = getName($country1);
            
            $vname = str_replace("'", "", $vname);
            $nname = str_replace("'", "", $nname);
			
			$ratio = round(75000/90,0);
			echo"r: ". $ratio ."<br>";
		
			mt_srand((double)microtime()*1000000);
			$expertise = mt_rand(60,90);
			
			mt_srand((double)microtime()*1000000);
			$p_technique = mt_rand(60,90);
			
			mt_srand((double)microtime()*1000000);
			$p_stamina = mt_rand(60,90);
			
			
			$x = ($expertise+$p_technique+$p_stamina)/3;
			echo"x: ". $x ."<br>";
			$y = $x*$ratio;
			echo"Y: ". $y ."<br>";
			
			$salary = round($y,0);
			
			$name = $vname ." ". $nname;
			
			$insStr = "INSERT INTO cm23_trainer (name, salary, p_technique, p_stamina, expertise)
						VALUES ('$name', '$salary', '$p_technique', '$p_stamina', '$expertise')";
            echo $insStr ."<br>";
            $db->executeQuery($insStr);
			
		}
	}
?>