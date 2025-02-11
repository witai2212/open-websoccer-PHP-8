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

// GET NUMBER OF TEAMS PER LEAGUE
$qTeams = "SELECT L.id AS league_id, L.name AS league_name, L.division, L.p_steh, COUNT(C.liga_id) total_clubs
            FROM ". $conf["db_prefix"] ."_liga AS L,
                ". $conf["db_prefix"] ."_verein AS C
            WHERE L.id = C.liga_id
            GROUP BY L.id
            ORDER BY L.id";

$tResult = $db->executeQuery($qTeams);

while ($league = $tResult->fetch_array()) {
    
    $mod = round($league['total_clubs'] / 4,0);
    
    if($mod>=2) {
    
        for($i=1;$i<=$mod;$i++) {
            
            $league_id = $league['league_id'];
            $division = $league['division'];
            
            // GET AVG LEAGE SALARY
            $salStr = "SELECT AVG(S.vertrag_gehalt) AS avg_salary
                        FROM ". $conf["db_prefix"] ."_spieler AS S, ". $conf["db_prefix"] ."_verein AS C 
                        WHERE C.liga_id='$league_id' AND S.verein_id=C.id; ";
            $sResult = $db->executeQuery($salStr);
            $salary = $sResult->fetch_array();
            $sResult->free();
            
            $avg_salary = round($salary['avg_salary'],0);
            
            $b_spiel = $avg_salary*24;
            $max_teams = $mod-1;
            $min_platz = $i*($mod-1);
            
            mt_srand((double)microtime()*1000000);
            $r_b_heimspiel = mt_rand(10,20);
            $b_heimspiel = round($b_spiel*((100-$r_b_heimspiel)/100),0);
            
            mt_srand((double)microtime()*1000000);
            $r_b_sieg = mt_rand(10,20);
            $b_sieg = $b_spiel*((100-$r_b_sieg)/100);
            
            $b_meisetrschaft = 1000000*($league['p_steh']/100);
            $b_meisetrschaft = $b_meisetrschaft*((10-($mod-($i-1)))/10)*(11-$division);
            
            $b_cup = 750000*($league['p_steh']/100);
            $b_cup = $b_cup*((10-($mod-($i-1)))/10)*(11-$division);
            
            $b_spiel = $avg_salary*(($league['total_clubs']-$min_platz)+$mod);
            if($b_spiel<=0) {
                $b_spiel = $b_sieg;
            }
            
            $sponsor = $sponsors[array_rand($sponsors)];
            
            //echo $league['league_name'] ." - ". $league['total_clubs'] ." - ". $avg_salary ." - ". $b_spiel ." - ". $b_meisetrschaft ." - ". $b_cup . " - ". $division ." - ". $sponsor ." - ". $min_platz ."<br>";
            $insStr = "INSERT INTO cm23_sponsor (name, liga_id, b_spiel, b_heimzuschlag, b_sieg, b_meisterschaft, b_cup, max_teams, min_platz)
                VALUES ('$sponsor', '$league_id', '$b_spiel', '$b_heimspiel', '$b_sieg', '$b_meisetrschaft', '$b_cup', '$max_teams', '$min_platz')";
            echo $insStr ."<br>";
            
            $db->executeQuery($insStr);
        }
        echo"<br>";
    }
}
$tResult->free();
?>