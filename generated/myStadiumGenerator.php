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
$max_steh = 14000;
$max_sitz = 5000;
$max_vip = 1000;
$max_haupt_steh = 0;
$max_haupt_sitz = 15000;

// GET TOTAL CLUB NUMER
$totalStr = "SELECT COUNT(id) AS total_clubs FROM ". $conf["db_prefix"] ."_verein";
$query = $db->executeQuery($totalStr)->fetch_array();

$total_club = $query['total_clubs'];
echo"mod: ". $total_club ." - ". $total_club % 10 ."<br>";

//###########################################################################################

// GET ALL CLUBS
$clubs = array();
$sqlStr = "SELECT id, name, strength
                FROM ". $conf["db_prefix"] ."_verein
                ORDER BY strength DESC";
$result = $db->executeQuery($sqlStr);
while ($club = $result->fetch_array()) {
    $clubs[] = $club;
}
$result->free();

$j = 0;
for($i=0;$i<=$total_club-1;$i++) {
    
    if($i%100==0 && $i>0) {
        $j = $j+10;

        $max_steh = round($max_steh*(100-$j)/100,0);
        $max_sitz = round($max_sitz*(100-$j)/100,0);
        $max_vip = round($max_vip*(100-$j)/100,0);
        $max_haupt_steh = round($max_haupt_steh*(100-$j)/100,0);
        $max_haupt_sitz = round($max_haupt_sitz*(100-$j)/100,0);
        
        $stadion_total = $max_haupt_sitz+$max_haupt_steh+$max_sitz+$max_steh+$max_vip;
        
    } else {
        
        $stadion_total = $max_haupt_sitz+$max_haupt_steh+$max_sitz+$max_steh+$max_vip;
    }
    
    if($stadion_total<2000) {
        $max_haupt_sitz = 500;
        $max_haupt_steh = 0;
        $max_sitz = 0;
        $max_steh = 1500;
        $max_vip = 0;
    }
    
    $stadion_total = round($stadion_total,0);
    
    $teamId = $clubs[$i]['id'];
    $teamName = $clubs[$i]['name'];
    $teamName = str_ireplace('\'', '', $teamName); // replaces `'` with ``
    
    $insStr = "INSERT INTO ". $conf["db_prefix"] ."_stadion (name, p_steh, p_sitz, p_haupt_steh, p_haupt_sitz, p_vip, level_pitch, level_seatsquality)
        VALUES ('".$teamName."','$max_steh', '$max_sitz', '$max_haupt_steh', '$max_haupt_sitz', '$max_vip', '3', '5')";
    //echo $insStr ."<br>";
    $db->executeQuery($insStr);
    
    $stadionStr = "SELECT id FROM ". $conf["db_prefix"] ."_stadion WHERE name='".$teamName."'";
    $result = $db->executeQuery($stadionStr);
    $stadion = $result->fetch_array();
    $stadionId = $stadion['id'];
    
    $updStr = "UPDATE ". $conf["db_prefix"] ."_verein SET stadion_id='$stadionId' WHERE id='".$teamId."'";
    $db->executeQuery($updStr);
    
    
}


?>