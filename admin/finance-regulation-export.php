<?php
/******************************************************

  CSV export for Finance Regulation Center.

******************************************************/

define('BASE_FOLDER', __DIR__ . '/..');
include(BASE_FOLDER . '/admin/adminglobal.inc.php');

if (!$admin['r_admin'] && !$admin['r_demo']) {
    header('HTTP/1.1 403 Forbidden');
    echo 'Access denied';
    exit;
}

try {
    FinanceRegulationDataService::ensureSchema($website, $db);
    $seasonId = isset($_GET['season_id']) ? (int) $_GET['season_id'] : FinanceRegulationDataService::getDefaultSeasonId($website, $db);
    $dashboard = FinanceRegulationDataService::getDashboard($website, $db, $seasonId);
    $csv = FinanceRegulationDataService::buildCsvReport($dashboard);

    $filename = 'finance-regulation-' . date('Ymd-His') . '.csv';
    FinanceRegulationDataService::logExport($website, $db, (int) $admin['id'], $seasonId, $filename);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo "\xEF\xBB\xBF";
    echo $csv;
} catch (Exception $e) {
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Export fehlgeschlagen: ' . $e->getMessage();
}
?>
