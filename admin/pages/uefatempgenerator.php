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

// generation might take more time than usual
ignore_user_abort(TRUE);
set_time_limit(0);

//********** Startseite **********
if (!$show) {
    
?>
  
  <form action="<?php echo $_SERVER['PHP_SELF']; ?>" method="post" class="form-horizontal">
    <input type="hidden" name="show" value="generate">
	<input type="hidden" name="site" value="<?php echo $site; ?>">
	<legend><?php echo $i18n->getMessage("uefatempgenerator_label"); ?></legend>
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
    
    // get uefa places from _land table
	$uefa_places = UefaDataService::getUefaPlacesByLand($website, $db);
	
	/*
	if(count($uefa_places)==128) {

		echo createSuccessMessage($i18n->getMessage("generator_success"), "");
		echo"<br>";
		echo "<p>&raquo; <a href=\"?site=uefagenerategroupteams\">". $i18n->getMessage("continue_label") . "</a></p>\n";
		
	} else {
		
		echo createSuccessErrorMessage($i18n->getMessage("generator_error"), "");
	}
	*/
	echo createSuccessMessage($i18n->getMessage("generator_success"), "");
    echo "<p>&raquo; <a href=\"?site=". $site ."\">". $i18n->getMessage("back_label") . "</a></p>\n";
}

?>
