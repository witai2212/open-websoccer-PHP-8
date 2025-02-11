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
    


function getName() {
    
    global $conf;
    $db = DbConnection::getInstance();
    $db->connect($conf["db_host"], $conf["db_user"], $conf["db_passwort"], $conf["db_name"]);
 
    $nameStr = "SELECT name FROM ". $conf["db_prefix"] ."_name ORDER BY RAND() LIMIT 1";
    //echo $nameStr ."<br>";
    $nameQuery = $db->executeQuery($nameStr)->fetch_array();
    $name = $nameQuery['name'];
    
    return $name;
}

function generateYouthScouts() {
    
    global $conf;
    $db = DbConnection::getInstance();
    $db->connect($conf["db_host"], $conf["db_user"], $conf["db_passwort"], $conf["db_name"]);
    
    for($i=1;$i<=5;$i++) {
        
        for($j=1;$j<=8;$j++) {
            
            if($i<=2) {
                
                mt_srand((double)microtime()*1000000);
                $kompetenz = mt_rand(40,49);
                
                mt_srand((double)microtime()*1000000);
                $kosten = mt_rand(15000,20000);
                
            } else if($i==3) {
                
                mt_srand((double)microtime()*1000000);
                $kompetenz = mt_rand(50,69);
                
                mt_srand((double)microtime()*1000000);
                $kosten = mt_rand(20000,25000);
                
            } else if($i==4) {
                
                mt_srand((double)microtime()*1000000);
                $kompetenz = mt_rand(70,89);
                
                mt_srand((double)microtime()*1000000);
                $kosten = mt_rand(25000,40000);
                
            } else if($i==5) {
                
                mt_srand((double)microtime()*1000000);
                $kompetenz = mt_rand(90,95);
                
                mt_srand((double)microtime()*1000000);
                $kosten = mt_rand(35000,65000);
                
            }
            
            if($j<=2) {
                $position = "Torwart";
            } else if ($j>=3 && $j <=4) {
                $position = "Abwehr";
            } else if ($j>=5 && $j <=6) {
                $position = "Mittelfeld";
            } else {
                $position = "Sturm";
            }
            
            $vname = getName();
            $nname = getName();
            
            $name = $vname ." ". $nname;
            
            $kosten=$kosten*2;
            
            echo $name ." - ". $kompetenz ." - ". $kosten*2 ." - ". $position ."<br>";
            $insStr = "INSERT INTO ". $conf["db_prefix"] ."_youthscout (name, expertise, fee, speciality) VALUES ('$name', '$kompetenz', '$kosten', '$position')";
            $db->executeQuery($insStr);
            
        }
        
    }

}

generateYouthScouts();
?>