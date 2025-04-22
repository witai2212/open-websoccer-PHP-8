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

global $conf;
$db = DbConnection::getInstance();
$db->connect($conf["db_host"], $conf["db_user"], $conf["db_passwort"], $conf["db_name"]);

PlayersDataService::updateMarketValueById($website, $db, $_GET['playerid']);
?>