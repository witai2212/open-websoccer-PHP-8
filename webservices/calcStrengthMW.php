<?php
ini_set('memory_limit', '8G');

define('BASE_FOLDER', __DIR__ . '/..');
include(BASE_FOLDER . '/admin/config/global.inc.php');

PlayersStrengthDataService::updateAllPlayersMarketAndStrength($website, $db);
echo "<p><strong>Last Updated:</strong> " . date("H:i:s") . "</p>";

?>