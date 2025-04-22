<?php
define('BASE_FOLDER', __DIR__ .'/..');
include(BASE_FOLDER . '/admin/config/global.inc.php');

$sec = 5000;
$session_id = isset($_GET['session']) && !empty($_GET['session']) ? $_GET['session'] : null;

if (!$session_id) {
    echo "No session id defined\n";
    exit;
}

// get cm23_config record
function getConfig($website, $db, $name) {
    
    $query = "SELECT * FROM ". $website->getConfig('db_prefix') ."_config WHERE name='".$name."'";
    $result = $db->executeQuery($query);
    $config = $result->fetch_assoc();
    $result->free();
    
    return $config;
}

// update timestamp
function updateConfigTimestamp($website, $db, $name, $now, $descr) {
    $query = "UPDATE ". $website->getConfig('db_prefix') ."_config SET zeitstempel='".$now."', descr='".$descr."' WHERE name='".$name."'";
    $db->executeQuery($query);
}

// Get configurations
$zeitstempel = getConfig($website, $db, 'marketvalue');
$mw_zeitstempel = $zeitstempel['zeitstempel'];

$now = $website->getNowAsTimestamp();
$openMatches = MatchesDataService::countOpenMatches($website, $db);
$openYouthMatches = YouthMatchesDataService::getOpenYouthMatches($website, $db);

// Get match simulation session info
$active_session = getConfig($website, $db, 'match_simulation');
$active_session_bool = $active_session['zeitstempel'];
$active_session_id = $active_session['descr'];

echo date('H:i:s') . "\n";
echo "Session: $session_id | Active Session: $active_session_id | Matches: $openMatches\n";

if($active_session_id>=1 && $active_session_id!=$session_id) {
    
    echo"other session active\n";
    
} else {
    
    if ($openMatches > 0) {
        
        if ($active_session_bool == 0 || $active_session_id == $session_id) {
            
            echo "Executing m simulation...\n";
            updateConfigTimestamp($website, $db, 'match_simulation', '1', $session_id);
            MatchSimulationExecutor::simulateOpenMatches($website, $db);
            
        } else {
            echo $active_session_id ." - ". $session_id ." - Other session already simulating\n";
        }
        
    } elseif ($openYouthMatches > 0) {
        
        echo "Executing ym simulation...\n";
        YouthMatchSimulationExecutor::simulateOpenYouthMatches($website, $db, 5);
        updateConfigTimestamp($website, $db, 'match_simulation', '1', $session_id);
        
    } else {
        
        echo "Executing other operations...\n";
        ComputerTransfersDataService::executeComputerBids($website, $db);
        PlayersStrengthDataService::updateAllPlayersMarketAndStrength($website, $db);
        
        if (($mw_zeitstempel + 86400) < $now) {
            
            TransfermarketDataService::movePlayersWithoutTeamToTransfermarket($website, $db);
            updateConfigTimestamp($website, $db, 'marketvalue', $now, '0');
            
        }
        updateConfigTimestamp($website, $db, 'match_simulation', '0', '0');
    }
}
echo"__________________________________\n";
?>
