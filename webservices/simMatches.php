<?php
define('BASE_FOLDER', __DIR__ .'/..');
include(BASE_FOLDER . '/admin/config/global.inc.php');


$page = $_SERVER['PHP_SELF'];
$sec = "3";
?>
<html>
    <head>
    <meta http-equiv="refresh" content="<?php echo $sec?>;URL='<?php echo $page?>'">
    </head>
    <body>
    <?php
		echo $sec ." - ". date('h:i:s');
		echo"<br>";
        MatchSimulationExecutor::simulateOpenMatches($website, $db);
        //echo time();
    ?>
    </body>
</html>