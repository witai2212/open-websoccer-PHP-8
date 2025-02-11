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
$sqlStr = "SELECT V.id, V.name, V.strength, L.land
                FROM ". $conf["db_prefix"] ."_verein AS V, ". $conf["db_prefix"] ."_liga AS L
                WHERE L.id=V.liga_id
                ORDER BY V.strength DESC";
$result = $db->executeQuery($sqlStr);
while ($club = $result->fetch_array()) {
    $clubs[] = $club;
}
$result->free();

$j = 0;
for($i=0;$i<=$total_club-1;$i++) {
    
    //mt_srand((double)microtime()*1000000);
    //$rand  = mt_rand(10,30);
    
    if($i%20==0) {
        $j++;
    }
    
    $min = 99-$j-15;
    $max = 99-($j);
    
    if(isset($clubs[$i]['name'])) {
        
        $val = 0;
        $p = 0;
        
        for($p=1;$p<=26;$p++) {
            
            // GENERATE AGE
            mt_srand((double)microtime()*1000000);
            $rand_month = mt_rand(1,12);
            
            mt_srand((double)microtime()*1000000);
            $rand_day = mt_rand(1,28);
            
            mt_srand((double)microtime()*1000000);
            $rand_year = mt_rand(1991,2006);
            
            //2024-01-02
            $geburtstag = $rand_year."-".$rand_month."-".$rand_day;
            

            // GENERATE RANDOM PLAYER COUNTRY
            mt_srand((double)microtime()*1000000);
            $rand_country = mt_rand(1,2);
            if($rand_country==1) {
                $country = $clubs[$i]['land'];
            } else {
                $countryStr = "SELECT country FROM ". $conf["db_prefix"] ."_country ORDER BY RAND() LIMIT 1";
                $countryQuery = $db->executeQuery($countryStr)->fetch_array();
                $country = $countryQuery['country'];
            }
            
            if($p<=2) {
                $main_position = "Torwart";
                
            } else if($p>=3 && $p<=10) {
                $main_position = "Abwehr";
                
            } else if($p>=11 && $p<=18) {
                $main_position = "Mittelfeld";
                
            } else if($p>=19 && $p<=24) {
                $main_position = "Sturm";
                
            } else if($p>=25) {
                
                // GENERATE RANDOM POSITION
                mt_srand((double)microtime()*1000000);
                $rand_main_position = mt_rand(1,4);
                
                if($rand_main_position==1) {
                    $main_position = "Torwart";
                    
                } else if($rand_main_position==2) {
                    $main_position = "Abwehr";
                    
                } else if($rand_main_position==3) {
                    $main_position = "Mittelfeld";
                    
                } else if($rand_main_position==4) {
                    $main_position = "Sturm";
                }
                
            }
            
            mt_srand((double)microtime()*1000000);
            $strength = mt_rand($min,$max);
            $val = $val+$strength;
            
            // GENERATE POSITIONS
            $main_pos = genMainPosition($main_position);
            $second_pos = genSecondPosition($main_pos);
            //echo"$p - $main_position -". $main_pos ." - ". $second_pos ."<br>";
            
            
            // GENERATE TALENT
            $talent = round($strength/10/2,0);
            $min_talent = $talent-1;
            $max_talent = $talent;
            mt_srand((double)microtime()*1000000);
            $talent = mt_rand($min_talent,$max_talent);
            
            // GENERATE SUPERTALENT
            mt_srand((double)microtime()*1000000);
            $r_supertalent = mt_rand(1,100);
            if($r_supertalent==1) {
                $talent = 6;
            }
            
            // GENERATE STRENGTH VALUES
            mt_srand((double)microtime()*1000000);
            $r_technik = mt_rand($strength-20,$strength);
            $technik = $r_technik;
            
            mt_srand((double)microtime()*1000000);
            $r_kondition = mt_rand($strength-20,$strength);
            $kondition = $r_kondition;
            
            mt_srand((double)microtime()*1000000);
            $r_frische = mt_rand($strength-10,$strength);
            $frische = $r_frische;
            
            mt_srand((double)microtime()*1000000);
            $r_zufriedenheit = mt_rand($strength-10,$strength);
            $zufriedenheit = $r_zufriedenheit;
            
            // GET NAME
            $vname = getName($country);
            $nname = getName($country);
            
            $vname = str_replace("'", "", $vname);
            $nname = str_replace("'", "", $nname);
            
            // GENERATE SALARY
            mt_srand((double)microtime()*1000000);
            $r_gehalt = mt_rand(2500,4000);
            
            $gehalt_st =  ($strength/100)*$r_gehalt;
            $gehalt_te =  ($technik/100)*$r_gehalt;
            $gehalt_ko = ($kondition/100)*$r_gehalt;
            $gehalt_fr = ($frische/100)*$r_gehalt;
            
            $gehalt = $gehalt_fr + $gehalt_ko + $gehalt_st + $gehalt_te;
            $gehalt = round($gehalt*$talent/5,0);
            
            if($main_position=="Sturm") {
                $vertrag_torpraemie = round($gehalt*0.1,0);
                
            } else if($main_position=="Mittelfeld") {
                $vertrag_torpraemie = round($gehalt*0.05,0);
                
            } else {
                $vertrag_torpraemie = 0;
            }
            
            $teamId = $clubs[$i]['id'];
            
            /*echo $i ." - ". $p ." - ". $clubs[$i]['name'] ." - ". $j . " s: ". $strength
            ." land: ". $country ." - ". $rand_country ." - ". $main_position ." - ". $main_pos ." - ". $second_pos 
            ." tal: ". $talent ." - ". $technik ." - ". $kondition ." - ". $frische ." - ". $zufriedenheit
            ." VName: ". $vname ." NName: ". $nname ." Salary: ". $gehalt ." - ". $vertrag_torpraemie ."<br>";*/
            
            $db->executeQuery("SET FOREIGN_KEY_CHECKS=0");
            
            $insStr = "INSERT INTO ". $conf["db_prefix"] ."_spieler (vorname, nachname, geburtstag, verein_id, position, position_main, position_second,
                nation, w_staerke, w_technik, w_kondition, w_frische, w_zufriedenheit, w_talent, vertrag_gehalt,
                vertrag_spiele, vertrag_torpraemie, status)
                VALUES ('$vname', '$nname', '$geburtstag', '$teamId', '$main_position', '$main_pos', '$second_pos', '$country', '$strength',
                        '$technik', '$kondition', '$frische', '$zufriedenheit', '$talent', '$gehalt', '60', '$vertrag_torpraemie', '1')";
            
            echo $insStr ."<br>";
            $db->executeQuery($insStr);
            
        }   //-- end for loop with p++
        echo $val ."<hr>";
    }
    
    /*
     * generatePlayers(WebSoccer $websoccer, 
     *                  DbConnection $db, $teamId, $age, $ageDeviation, 
     *                  $salary, $contractDuration, $strengths, $positions,
     *                  $maxDeviation, $nationality = NULL)
     */
}

function genMainPosition($main_position) {
    
    if($main_position=="Torwart") {
        $position_main = "T";
        
    } else 
    if($main_position=="Abwehr") {
        
        mt_srand((double)microtime()*1000000);
        $rand = mt_rand(1,4);
        if($rand==1) {
            $position_main = "LV";
        } else if($rand==2) {
            $position_main = "RV";
        } else {
            $position_main = "IV";
        }
        
    } else 
    if($main_position=="Mittelfeld") {
        
        mt_srand((double)microtime()*1000000);
        $rand = mt_rand(1,6);
        if($rand==1) {
            $position_main = "LM";
        } else if($rand==2) {
            $position_main = "RM";
        } else if($rand==3) {
            $position_main = "DM";
        } else if($rand==4) {
            $position_main = "OM";
        } else {
            $position_main = "ZM";
        }
        
    } else 
    if($main_position=="Sturm") {
        
        mt_srand((double)microtime()*1000000);
        $rand = mt_rand(1,4);
        if($rand==1) {
            $position_main = "LS";
        } else if($rand==2) {
            $position_main = "RS";
        } else {
            $position_main = "MS";
        }
    }
    
    //echo "pos_main: ". $position_main ."<br>";
    return $position_main;
    
}

function genSecondPosition($position_main) {
    
    if($position_main=="T") {
        $second_position = "T";
        
    } else if($position_main=="LV") {
        
        mt_srand((double)microtime()*1000000);
        $rand = mt_rand(1,5);
        if($rand==1) {
            $second_position = "RV";
        } else if($rand==2) {
            $second_position = "IV";
        } else if($rand==3) {
            $second_position = "LM";
        } else {
            $second_position = "";
        }
        
    } else if($position_main=="IV") {
        
        mt_srand((double)microtime()*1000000);
        $rand = mt_rand(1,5);
        if($rand==1) {
            $second_position = "LV";
        } else if($rand==2) {
            $second_position = "RV";
        } else if($rand==3) {
            $second_position = "DM";
        } else {
            $second_position = "";
        }
        
    } else if($position_main=="RV") {
        
        mt_srand((double)microtime()*1000000);
        $rand = mt_rand(1,5);
        if($rand==1) {
            $second_position = "LV";
        } else if($rand==2) {
            $second_position = "RM";
        } else if($rand==3) {
            $second_position = "IV";
        } else {
            $second_position = "";
        }
        
    } else if($position_main=="RM") {
        
        mt_srand((double)microtime()*1000000);
        $rand = mt_rand(1,8);
        if($rand==1) {
            $second_position = "RV";
        } else if($rand==2) {
            $second_position = "RS";
        } else if($rand==3) {
            $second_position = "ZM";
        } else if($rand==4) {
            $second_position = "DM";
        } else if($rand==5) {
            $second_position = "OM";
        } else if($rand==6) {
            $second_position = "LM";
        } else {
            $second_position = "";
        }
        
    } else if($position_main=="LM") {
        
        mt_srand((double)microtime()*1000000);
        $rand = mt_rand(1,8);
        if($rand==1) {
            $second_position = "LV";
        } else if($rand==2) {
            $second_position = "LS";
        } else if($rand==3) {
            $second_position = "ZM";
        } else if($rand==4) {
            $second_position = "DM";
        } else if($rand==5) {
            $second_position = "OM";
        } else if($rand==6) {
            $second_position = "RM";
        } else {
            $second_position = "";
        }
        
    } else if($position_main=="DM") {
        
        mt_srand((double)microtime()*1000000);
        $rand = mt_rand(1,7);
        if($rand==1) {
            $second_position = "ZM";
        } else if($rand==2) {
            $second_position = "OM";
        } else if($rand==3) {
            $second_position = "LM";
        } else if($rand==4) {
            $second_position = "RM";
        } else if($rand==5) {
            $second_position = "IV";
        } else {
            $second_position = "";
        }
        
    } else if($position_main=="ZM") {
        
        mt_srand((double)microtime()*1000000);
        $rand = mt_rand(1,7);
        if($rand==1) {
            $second_position = "DM";
        } else if($rand==2) {
            $second_position = "OM";
        } else if($rand==3) {
            $second_position = "LM";
        } else if($rand==4) {
            $second_position = "RM";
        } else if($rand==5) {
            $second_position = "IV";
        } else {
            $second_position = "";
        }
        
    } else if($position_main=="OM") {
        
        mt_srand((double)microtime()*1000000);
        $rand = mt_rand(1,7);
        if($rand==1) {
            $second_position = "DM";
        } else if($rand==2) {
            $second_position = "ZM";
        } else if($rand==3) {
            $second_position = "LM";
        } else if($rand==4) {
            $second_position = "RM";
        } else if($rand==5) {
            $second_position = "LV";
        } else {
            $second_position = "";
        }
        
    } else if($position_main=="RS") {
        
        mt_srand((double)microtime()*1000000);
        $rand = mt_rand(1,6);
        if($rand==1) {
            $second_position = "LS";
        } else if($rand==2) {
            $second_position = "MS";
        } else if($rand==3) {
            $second_position = "RM";
        } else if($rand==4) {
            $second_position = "OM";
        } else {
            $second_position = "";
        }
        
    } else if($position_main=="LS") {
        
        mt_srand((double)microtime()*1000000);
        $rand = mt_rand(1,6);
        if($rand==1) {
            $second_position = "RS";
        } else if($rand==2) {
            $second_position = "MS";
        } else if($rand==3) {
            $second_position = "LM";
        } else if($rand==4) {
            $second_position = "OM";
        } else {
            $second_position = "";
        }
        
    } else if($position_main=="MS") {
        
        mt_srand((double)microtime()*1000000);
        $rand = mt_rand(1,4);
        if($rand==1) {
            $second_position = "RS";
        } else if($rand==2) {
            $second_position = "LS";
        } else if($rand==3) {
            $second_position = "OM";
        } else {
            $second_position = "";
        }
        
    }
    if(!isset($second_position)) {
        echo"err---------------------------: ". $position_main ."<br>";
    }
    return $second_position;
}

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

function nBetween($varToCheck, $high, $low) {
    if($varToCheck < $low) return false;
    if($varToCheck > $high) return false;
    return true;
}
?>