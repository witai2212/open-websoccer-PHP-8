<?php
/******************************************************

This file is part of OpenWebSoccer-Sim.

OpenWebSoccer-Sim is free software: you can redistribute it
and/or modify it under the terms of the
GNU Lesser General Public License
as published by the Free Software Foundation, either version 3 of
the License, or any later version.

OpenWebSoccer-Sim is distributed in the hope that it will be
useful, but WITHOUT ANY WARRANTY; without even the implied
warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
See the GNU Lesser General Public License for more details.

You should have received a copy of the GNU Lesser General Public
License along with OpenWebSoccer-Sim.
If not, see <http://www.gnu.org/licenses/>.

******************************************************/

$mainTitle = $i18n->getMessage("category_cup_navlabel");

echo "<h1>$mainTitle</h1>";

if (!$admin["r_admin"] && !$admin["r_demo"] && !$admin[$page["permissionrole"]]) {
    throw new Exception($i18n->getMessage("error_access_denied"));
}

// Generation might take more time than usual
ignore_user_abort(TRUE);
set_time_limit(0);

// Make sure $show is available
if (!isset($show)) {
    $show = isset($_POST['show']) ? $_POST['show'] : null;
}


// **************************************************************
// Start page
// **************************************************************
if (empty($show)) {
    
    ?>
  
	<form action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" method="post" class="form-horizontal">
		<input type="hidden" name="show" value="generate">
		<input type="hidden" name="site" value="<?php echo htmlspecialchars($site); ?>">

		<legend><?php echo $i18n->getMessage("uefatempgenerator_label"); ?></legend>

		<div class="form-actions">
			<input 
				type="submit" 
				class="btn btn-primary" 
				accesskey="s" 
				title="Alt + s" 
				value="<?php echo $i18n->getMessage("generator_button"); ?>"
			> 

			<input 
				type="reset" 
				class="btn" 
				value="<?php echo $i18n->getMessage("button_reset"); ?>"
			>
		</div>    
	</form>

<?php

}


// **************************************************************
// Generate UEFA temp table
// **************************************************************
elseif ($show == "generate") {
    
	// Recalculate UEFA allocation from coefficient, fill _uefa_temp and sync legacy temp tables.
	$summary = UefaDataService::rebuildQualificationAndTempTables($website, $db);
	$teamCount = isset($summary['team_count']) ? (int) $summary['team_count'] : 0;
	
	// Expected total:
	// 64 Champions League teams + 64 UEFA League teams = 128
	if ($teamCount == 128) {
		echo createSuccessMessage($i18n->getMessage("generator_success"), "");
	} else {
		echo createErrorMessage($i18n->getMessage("generator_error"), "");
	}
	
	echo "<p>Generated teams: ". $teamCount ." / expected: 128.</p>";
	
	if (isset($summary['allocation'])) {
		echo "<p>Countries updated: ". (int) $summary['allocation']['countries_updated']
			."<br>CL places: ". (int) $summary['allocation']['cl_total']
			."<br>UEFA League places: ". (int) $summary['allocation']['ul_total']
			."</p>";
	}
	
	if (isset($summary['legacy'])) {
		echo "<p>Legacy temp synced: CL ". (int) $summary['legacy']['cl_temp']
			.", UL ". (int) $summary['legacy']['ul_temp']
			.", EL ". (int) $summary['legacy']['el_temp']
			."</p>";
	}
	
	
	if (class_exists('ConmebolDataService')) {
		try {
			$conmebolSummary = ConmebolDataService::rebuildQualificationAndTempTables($website, $db);
			$conmebolTeams = isset($conmebolSummary['team_count']) ? (int) $conmebolSummary['team_count'] : 0;
			echo "<p>CONMEBOL generated teams: ". $conmebolTeams .".";
			if (isset($conmebolSummary['allocation'])) {
				echo "<br>Countries updated: ". (int) $conmebolSummary['allocation']['countries_updated'];
				echo "<br>Libertadores places: ". (int) $conmebolSummary['allocation']['libertadores_total'];
				echo "<br>Sudamericana places: ". (int) $conmebolSummary['allocation']['sudamericana_total'];
			}
			echo "</p>";
		} catch (Exception $e) {
			echo createErrorMessage('CONMEBOL: ' . $e->getMessage(), '');
		}
	}
	
	
	echo "<p>&raquo; <a href=\"?site=". htmlspecialchars($site) ."\">";
	echo $i18n->getMessage("back_label");
	echo "</a></p>\n";
}

?>