<?php
define('BASE_FOLDER', __DIR__ .'/..');
include(BASE_FOLDER . '/admin/config/global.inc.php');

if (!defined('JOBS_CONFIG_FILE')) {
    define('JOBS_CONFIG_FILE', BASE_FOLDER . '/admin/config/jobs.xml');
}

$sec = 5000;
$session_id = isset($_GET['session']) && !empty($_GET['session']) ? $_GET['session'] : null;

if (!$session_id) {
    echo "No session id defined\n";
    exit;
}

/**
 * Get cm23_config record.
 */
function getConfig($website, $db, $name) {
    
    $query = "SELECT * FROM ". $website->getConfig('db_prefix') ."_config WHERE name='".$name."'";
    $result = $db->executeQuery($query);
    $config = $result->fetch_assoc();
    $result->free();
    
    return $config;
}

/**
 * Update cm23_config timestamp.
 */
function updateConfigTimestamp($website, $db, $name, $now, $descr) {
    $query = "UPDATE ". $website->getConfig('db_prefix') ."_config SET zeitstempel='".$now."', descr='".$descr."' WHERE name='".$name."'";
    $db->executeQuery($query);
}

/**
 * Safe result helper.
 */
function getTrainingResultValue($result, $key) {
    return isset($result[$key]) ? (int) $result[$key] : 0;
}

/**
 * Execute one optional maintenance operation without breaking the whole reload script.
 */
function executeSafeOperation($label, $callback) {
    echo $label . "\n";

    try {
        $callback();
    } catch (Throwable $e) {
        echo "[" . $label . "] skipped: " . $e->getMessage() . "\n";
    }
}

/**
 * Execute an existing job class once from this combined reload script.
 * The last argument disables the AbstractJob single-run lock because this wrapper already owns the reload session lock.
 */
function executeConfiguredJobOnce($website, $db, $i18n, $jobId, $jobClass) {
    if (!class_exists($jobClass)) {
        echo "[" . $jobClass . "] skipped: class not found.\n";
        return;
    }

    $job = new $jobClass($website, $db, $i18n, $jobId, false);
    $job->execute();
    unset($job);
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
echo "Session: $session_id | Active Session: $active_session_id | M's: $openMatches\n";

if ($active_session_id >= 1 && $active_session_id != $session_id) {
    
    echo "other session active\n";
    
} else {
    
    // compute matches
    if ($openMatches > 0) {
        
        if ($active_session_bool == 0 || $active_session_id == $session_id) {
            
            echo "Executing m simulation...\n";
            updateConfigTimestamp($website, $db, 'match_simulation', '1', $session_id);
            MatchSimulationExecutor::simulateOpenMatches($website, $db);
            
        } else {
            echo $active_session_id ." - ". $session_id ." - Other session already simulating\n";
        }
        
    // compute youth matches
    } elseif ($openYouthMatches > 0) {
        
        echo "Executing ym simulation...\n";
        updateConfigTimestamp($website, $db, 'match_simulation', '1', $session_id);
        YouthMatchSimulationExecutor::simulateOpenYouthMatches($website, $db, 5);
        
    // compute other stuff: transfers, automatic training, market values, daily jobs
    } else {
        
        echo "Executing other operations...\n";
        
        $i18n = I18n::getInstance($website->getConfig('supported_languages'));
        $i18n->setCurrentLanguage('de');
        
        include_once(sprintf(CONFIGCACHE_MESSAGES, $i18n->getCurrentLanguage()));
        include_once(sprintf(CONFIGCACHE_ENTITYMESSAGES, $i18n->getCurrentLanguage()));
        
        executeSafeOperation('[ComputerBudgetProtectionDataService] Ensuring CPU budgets...', function() use ($website, $db) {
            $budgetResult = ComputerBudgetProtectionDataService::subsidizeAllComputerClubs($website, $db);
            echo "[CPU budget] clubs: " . (int) $budgetResult['clubs'] . ", amount: " . (int) $budgetResult['amount'] . "\n";
        });
        executeSafeOperation('[NewsDataService] Trimming news archive...', function() use ($website, $db) {
            echo "[News] deleted: " . (int) NewsDataService::trimToMaximum($website, $db) . "\n";
        });

        // Date-based jobs first: completed camps are removed before automatic training checks active camps.
        executeSafeOperation('[AcceptStadiumConstructionWorkJob] Executing stadium const. and training camp...', function() use ($website, $db, $i18n) {
            executeConfiguredJobOnce($website, $db, $i18n, 'stadium', 'AcceptStadiumConstructionWorkJob');
        });
        
        // Transfer jobs are independent from matchday processing, but should only run once no match simulation is active.
        executeSafeOperation('[TransfermarketDataService] Executing open transfers...', function() use ($website, $db) {
            TransfermarketDataService::executeOpenTransfers($website, $db);
        });
        
        executeSafeOperation('[ComputerTransfersDataService] Executing computer transfers and loans...', function() use ($website, $db) {
            ComputerTransfersDataService::executeComputerBids($website, $db, false);
        });
        
        // Loan reports/development are processed during match simulation,
        // when borrowed players appear in DataUpdateSimulatorObserver.
        
        // Matchday jobs. Their own markers prevent duplicate processing for the same completed match.
        executeSafeOperation('[ScoutingMatchdayJob] Executing full scouting matchday...', function() use ($website, $db, $i18n) {
            executeConfiguredJobOnce($website, $db, $i18n, 'scouting', 'ScoutingMatchdayJob');
        });
        
        executeSafeOperation('[ClubStaffMatchdayJob] Executing club staff salaries...', function() use ($website, $db, $i18n) {
            executeConfiguredJobOnce($website, $db, $i18n, 'clubstaff', 'ClubStaffMatchdayJob');
        });
        
        // Automatic normal training after completed matchday.
        // This must NOT be inside the once-a-day block.
        // The training plan's last_match_id prevents duplicate execution for the same match.
        executeSafeOperation('[TrainingDataService] Executing automatic training...', function() use ($website, $db, $i18n) {
            $trainingResult = TrainingDataService::processAutomaticTrainingMatchday($website, $db, $i18n);
            
            echo "[TrainingMatchdayJob] processed: " . getTrainingResultValue($trainingResult, 'processed') . "\n";
            echo "[TrainingMatchdayJob] skipped because interval is active: " . getTrainingResultValue($trainingResult, 'skipped_interval') . "\n";
            echo "[TrainingMatchdayJob] skipped without units: " . getTrainingResultValue($trainingResult, 'skipped_no_units') . "\n";
            echo "[TrainingMatchdayJob] skipped in training camp: " . getTrainingResultValue($trainingResult, 'skipped_camp') . "\n";
        });
        
        executeSafeOperation('[ClubPartnershipDataService] Creating CPU partnership offers...', function() use ($website, $db, $i18n) {
            $partnershipOfferResult = ClubPartnershipDataService::processComputerDirectOffers($website, $db, $i18n);
            echo "[ClubPartnershipOffers] checked: " . (int) $partnershipOfferResult['checked'] . ", created: " . (int) $partnershipOfferResult['created'] . "\n";
        });

        executeSafeOperation('[ManagerCareerDataService] Executing manager career job offers...', function() use ($website, $db, $i18n) {
            $careerResult = ManagerCareerDataService::processJobOffersMatchday($website, $db, $i18n);
            echo "[ManagerCareerJob] users processed: " . getTrainingResultValue($careerResult, 'processed') . "\n";
            echo "[ManagerCareerJob] offers created: " . getTrainingResultValue($careerResult, 'created') . "\n";
            echo "[ManagerCareerJob] applications processed: " . getTrainingResultValue($careerResult, 'applications_processed') . "\n";
            echo "[ManagerCareerJob] applications accepted: " . getTrainingResultValue($careerResult, 'applications_accepted') . "\n";
            echo "[ManagerCareerJob] applications rejected: " . getTrainingResultValue($careerResult, 'applications_rejected') . "\n";
            echo "[ManagerCareerJob] sack checks: " . getTrainingResultValue($careerResult, 'sack_checks') . "\n";
            echo "[ManagerCareerJob] sacked managers: " . getTrainingResultValue($careerResult, 'sacked') . "\n";
            echo "[ManagerCareerJob] awards created: " . getTrainingResultValue($careerResult, 'awards_created') . "\n";
        });
        
        executeSafeOperation('[CorrectPlayerValuesJob] Correcting player values and updating market values...', function() use ($website, $db, $i18n) {
            executeConfiguredJobOnce($website, $db, $i18n, 'correctplayers', 'CorrectPlayerValuesJob');
        });
        
        executeSafeOperation('[UpdateStatisticsJob] Updating league statistics...', function() use ($website, $db, $i18n) {
            executeConfiguredJobOnce($website, $db, $i18n, 'stats', 'UpdateStatisticsJob');
        });
        
        // once a day
        if (($mw_zeitstempel + 86400) < $now) {
            
            executeSafeOperation('[StockMarketDataService] Executing stock market update...', function() use ($website, $db) {
                StockMarketDataService::updateStockDataFromAlphavantage($website, $db);
            });
            executeSafeOperation('[TransfermarketDataService] Moving players without team to transfer market...', function() use ($website, $db) {
                TransfermarketDataService::movePlayersWithoutTeamToTransfermarket($website, $db);
            });
            executeSafeOperation('[ComputerYouthTeamsDataService] Executing computer youth teams...', function() use ($website, $db, $i18n) {
                ComputerYouthTeamsDataService::execute($website, $db, $i18n);
            });
            executeSafeOperation('[UserInactivityCheckJob] Updating user inactivity...', function() use ($website, $db, $i18n) {
                executeConfiguredJobOnce($website, $db, $i18n, 'usractv', 'UserInactivityCheckJob');
            });
            
            updateConfigTimestamp($website, $db, 'marketvalue', $now, '0');
            
        }
        
        executeSafeOperation('[ComputerBudgetProtectionDataService] Final CPU budget protection...', function() use ($website, $db) {
            ComputerBudgetProtectionDataService::subsidizeAllComputerClubs($website, $db);
        });

        updateConfigTimestamp($website, $db, 'match_simulation', '0', '0');
    }
}

echo "__________________________________\n";
?>