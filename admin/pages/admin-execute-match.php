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

$mainTitle = $i18n->getMessage("admin-execute-match_navlabel");

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
	<div class="form-actions">
		<input type="submit" class="btn btn-primary" accesskey="s" title="Alt + s" value="Simulate"> 
	</div>    
  </form>

<?php

}

//********** validate, generate **********
elseif ($show == "generate") {
    
	MatchSimulationExecutor::simulateOpenMatches($website, $db);
	
	echo createSuccessMessage($i18n->getMessage("generator_success"), "");
    echo "<p>&raquo; <a href=\"?site=". $site ."\">". $i18n->getMessage("back_label") . "</a></p>\n";
}

?>
