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

//###########################################################################################

// GET ALL PLAYERS
$sqlStr = "SELECT id, nation
                FROM ". $conf["db_prefix"] ."_spieler
                ORDER BY id";
$result = $db->executeQuery($sqlStr);
while ($player = $result->fetch_array()) {
    
	if(ctype_space(substr($player['nation'],0,1)) || substr($player['nation'],0,1)==" " || preg_match("/\t/", $player['nation']) || (stristr($player['nation'],"\t")) ) {
		echo $player['nation'] ." true <br>";
	} else {
		echo $player['nation'] ." false <br>";
	}
	
}
$result->free();

?>