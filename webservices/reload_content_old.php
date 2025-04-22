<?php
define('BASE_FOLDER', __DIR__ .'/..');
include(BASE_FOLDER . '/admin/config/global.inc.php');

$sec = 5000;

// get cm23_config record
function getConfig($website, $db, $name) {
    
    $query = "SELECT * FROM ". $website->getConfig('db_prefix') ."_config WHERE name='".$name."'";
    $result = $db->executeQuery($query);
    $config = $result->fetch_assoc();
    $result->free();
    
    return $config;
}

// update timestamp
function updateConfigTimestamp($website, $db, $name, $now) {
    $query = "UPDATE ". $website->getConfig('db_prefix') ."_config SET zeitstempel='".$now."' WHERE name='".$name."'";
    $db->executeQuery($query);
}


$zeitstempel = getConfig($website, $db, $name='marketvalue');
$mw_zeitstempel = $zeitstempel['zeitstempel'];

$now = $website->getNowAsTimestamp();
$openMatches = MatchesDataService::countOpenMatches($website, $db);
$openYouthMatches = YouthMatchesDataService::getOpenYouthMatches($website, $db);

//simulateOpenYouthMatches
$active_sim = getConfig($website, $db, $name='match_simulation');

if($openMatches > 0) {
    
    echo "Executing m...<hr>\n";
    MatchSimulationExecutor::simulateOpenMatches($website, $db);
    
} else if($openYouthMatches > 0) {
    
    echo "Executing y-m...<hr>\n";
    YouthMatchSimulationExecutor::simulateOpenYouthMatches($website, $db, $maxMatchesToSimulate=5);
    
} else {
    
    echo "Executing other operations...<hr>\n";
    
    ComputerTransfersDataService::executeComputerBids($website, $db);
    
    PlayersStrengthDataService::updateAllPlayersMarketAndStrength($website, $db);
    
    // once a day
    if(($mw_zeitstempel+86400)<$now) {
        
        AcceptStadiumConstructionWorkController::class;
        TransfermarketDataService::movePlayersWithoutTeamToTransfermarket($website, $db);
        updateConfigTimestamp($website, $db, $name='marketvalue', $now);
        updateConfigTimestamp($website, $db, $name='match_simulation', $value='0');
        
    }
}
echo"<hr>". date('H:i:s') ."<br>";
?>
