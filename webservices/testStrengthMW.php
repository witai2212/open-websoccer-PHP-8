<?php
ini_set('memory_limit', '8G');

define('BASE_FOLDER', __DIR__ . '/..');
include(BASE_FOLDER . '/admin/config/global.inc.php');

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Auto-Refreshing Web Service</title>
    <meta http-equiv="refresh" content="5"> <!-- Refreshes every 5 seconds -->
    <style>
        body {
            font-family: Arial, sans-serif;
            text-align: center;
            margin-top: 50px;
        }
    </style>
</head>
<body>

    <h2>Web Service Running</h2>
    
    <?php
    PlayersStrengthDataService::updateAllPlayersMarketAndStrength($website, $db);
    echo "<p><strong>Last Updated:</strong> " . date("H:i:s") . "</p>";
    ?>

    <p>This page refreshes every 5 seconds.</p>

</body>
</html>
