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

$mainTitle = $i18n->getMessage("nationalcupgenerator_navlabel");

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

	$formFields["firstmatchday"] = array("type" => "timestamp", "value" => "");

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
	
	print_r($_POST);

	function isValidCupTournament($numTeams) {
		// A number is a power of 2 if there is only one bit set in its binary representation
		return ($numTeams > 0) && (($numTeams & ($numTeams - 1)) === 0);
	}

	function previousValidCupNumber($numTeams) {
		// Start with the highest power of 2 less than or equal to $numTeams
		$prevPowerOf2 = 1;
		while ($prevPowerOf2 * 2 <= $numTeams) {
			$prevPowerOf2 *= 2;
		}
		return $prevPowerOf2;
	}

	$countries = TeamsDataService::getNumberOfTeamsByCountry($website, $db);

	if (!is_array($countries) || empty($countries)) {
		echo "No teams data found.";
		return;
	}

	foreach ($countries as $country) {
		
		if (!isset($country['teams'], $country['name'])) {
			continue; // Skip invalid data
		}
		
		$land = $country['name'];

		if ($country['teams'] >= 16) {
			if (isValidCupTournament($country['teams'])) {
				//echo $country['name'] . " y " . $country['teams'] . "<br>";
				$teams = $country['teams'];
			} else {
				//echo $country['name'] . " n " . previousValidCupNumber($country['teams']) . "<br>";
				$teams = previousValidCupNumber($country['teams']);
			}
			
			echo $land ." - ". $teams ."<br>";
			//######### GENERATE CUP + MATCHES ##################
			/* BREAK BETWEN MATCHWES ***********
			 *	64 - 6
			 *	32 - 8
			 *	16 - 10
			 *	8  - 13
			***********************************/
			if($teams>=64) {
				$rounds = 6;
			} else if($teams>=32 && $teams<64) {
				$rounds = 5;
			} else if($teams>=16 && $teams<32) {
				$rounds = 4;
			} else if($teams>=8 && $teams<16) {
				$rounds = 3;
			}		
			
			CupsDataService::generateNationalCup($website, $db, $land, $rounds, $_POST['firstmatchday_date'], $_POST['hour'], $_POST['minute']);
			//###################################################
		}
	}

	echo createSuccessMessage($i18n->getMessage("generator_success"), "");
	echo "<p>&raquo; <a href=\"?site=" . $site . "\">" . $i18n->getMessage("back_label") . "</a></p>\n";

}

?>
