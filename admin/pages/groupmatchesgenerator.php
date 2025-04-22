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

$mainTitle = $i18n->getMessage("schedulegenerator_navlabel");

echo "<h1>$mainTitle</h1>";

if (!$admin["r_admin"] && !$admin["r_demo"] && !$admin[$page["permissionrole"]]) {
	throw new Exception($i18n->getMessage("error_access_denied"));
}

// generation might take more time than usual
ignore_user_abort(TRUE);
set_time_limit(0);

//********** Startseite **********
if (!$show) {
    
?>
  
  <form action="<?php echo $_SERVER['PHP_SELF']; ?>" method="post" class="form-horizontal">
    <input type="hidden" name="show" value="generate">
	<input type="hidden" name="site" value="<?php echo $site; ?>">
	
	<fieldset>
    
<?php 
	$formFields = array();
	$seasonDefaultName = date("Y");
	echo $i18n->getMessage("groupmatchesgenerator_select_cups"); ?>
	
	<select name="cup" id="cups">
	   <option value="">--</option>
	   <option value="Champions League">Champions League</option>
	   <option value="UEFA Euro League">UEFA Euro League</option>
	</select>
	
<?php
	$formFields["firstmatchday"] = array("type" => "timestamp", "value" => "");
	$formFields["timebreak"] = array("type" => "number", "value" => 5);
	$formFields["matchesperteam"] = array("type" => "number", "value" => 15);
	
	foreach ($formFields as $fieldId => $fieldInfo) {
		echo FormBuilder::createFormGroup($i18n, $fieldId, $fieldInfo, $fieldInfo["value"], "groupmatchesgenerator_label_");
	}
?>
	
	</fieldset>
	<br><br>
	<div class="form-actions">
		<input type="submit" class="btn btn-primary" accesskey="s" title="Alt + s" value="<?php echo $i18n->getMessage("generator_button"); ?>"> 
		<input type="reset" class="btn" value="<?php echo $i18n->getMessage("button_reset"); ?>">
	</div>    
  </form>

  <?php

}

//********** validate, generate **********
elseif ($show == "generate") {
    
    // first date
    // e.g. 1714464300
    
    // get id, groups from cup
    // e.g. pokalrunde = Gruppe, pokalname = $_POST['cup'], pokalgruppe='A'
    
    // get teams from cup and group
    // array with ids
	
	// cup_id by CupName
	$cupId = CupsDataService::getCupIdByName($website, $db, $_POST['cup']);
	
	// get id of cup round group
	$roundId = CupsDataService::getGroupIdByCupId($website, $db, $cupId, $name='Gruppen');
	
	// get teams form uefa_temp by cupId
	$teams = UefaDataService::getUefaTeamsByCupId($website, $db, $cupId);
		
	// put teams in groups
	UefaDataService::putTempTeamsInGroups($website, $db, $roundId, $teams);
	

	// get teams for each group
	$cup_round = 'Gruppen';
	
	// total matches per team in group
	$totalMatchesPerTeam = $_POST['matchesperteam'];
	
	// generate matches Group A
	$group_name = 'A';
	$teams = UefaDataService::getUefaTeamsByGroup($website, $db, $group_name, $roundId);
	ScheduleGenerator::createUEFACupGroupSchedule($website, $db, $teams, $_POST['firstmatchday_date'], $_POST['hour'], $_POST['minute'], $_POST['timebreak'], $_POST['cup'], $totalMatchesPerTeam, $group_name, $cup_round);
	
	// generate matches Group B
	$group_name = 'B';
	$teams = UefaDataService::getUefaTeamsByGroup($website, $db, $group_name, $roundId);
	ScheduleGenerator::createUEFACupGroupSchedule($website, $db, $teams, $_POST['firstmatchday_date'], $_POST['hour'], $_POST['minute'], $_POST['timebreak'], $_POST['cup'], $totalMatchesPerTeam, $group_name, $cup_round);
	
	// generate matches Group C
	$group_name = 'C';
	$teams = UefaDataService::getUefaTeamsByGroup($website, $db, $group_name, $roundId);
	ScheduleGenerator::createUEFACupGroupSchedule($website, $db, $teams, $_POST['firstmatchday_date'], $_POST['hour'], $_POST['minute'], $_POST['timebreak'], $_POST['cup'], $totalMatchesPerTeam, $group_name, $cup_round);
	
	// generate matches Group D
	$group_name = 'D';
	$teams = UefaDataService::getUefaTeamsByGroup($website, $db, $group_name, $roundId);
	ScheduleGenerator::createUEFACupGroupSchedule($website, $db, $teams, $_POST['firstmatchday_date'], $_POST['hour'], $_POST['minute'], $_POST['timebreak'], $_POST['cup'], $totalMatchesPerTeam, $group_name, $cup_round);


	
    echo createSuccessMessage($i18n->getMessage("generator_success"), "");
    echo "<p>&raquo; <a href=\"?site=". $site ."\">". $i18n->getMessage("back_label") . "</a></p>\n";

}

?>
