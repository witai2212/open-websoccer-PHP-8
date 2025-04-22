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

$mainTitle = $i18n->getMessage("category_transfers_navlabel");

echo "<h1>$mainTitle</h1>";
echo"<h3>Generate Computer transfers</h3>";

if (!$admin["r_admin"] && !$admin["r_demo"] && !$admin[$page["permissionrole"]]) {
	throw new Exception($i18n->getMessage("error_access_denied"));
}

// generation might take more time than usual
ignore_user_abort(TRUE);
set_time_limit(0);

//********** Startseite **********
if (!$show) {
    
?>
  
  <form action="" method="post" class="form-horizontal">
    <input type="hidden" name="show" value="generate">
	<input type="hidden" name="site" value="<?php echo $site; ?>">
	
	<fieldset>
    <legend><?php echo $i18n->getMessage("computertransfers_generator_label"); ?></legend>
    
	<?php 
	$formFields = array();
	
	$formFields["rounds"] = array("type" => "number", "value" => 2, "required" => "true");
	
	foreach ($formFields as $fieldId => $fieldInfo) {
		echo FormBuilder::createFormGroup($i18n, $fieldId, $fieldInfo, $fieldInfo["value"], "");
	}	
	?>
	</fieldset>
	
	
	<br>
	<div class="form-actions">
		<input type="submit" class="btn btn-primary" accesskey="s" title="Alt + s" value="<?php echo $i18n->getMessage("generator_button"); ?>">
	</div>    
  </form>

  <?php

}

//********** validate, generate **********
elseif ($show == "generate") {
    
    if (!isset($_POST['rounds']) || !is_numeric($_POST['rounds']) || $_POST['rounds'] <= 0) {
        echo createErrorMessage($i18n->getMessage("generator_error"), $i18n->getMessage("generator_invalid_rounds"));
    } else {
        $rounds = intval($_POST['rounds']);
        
        echo "<h4>Druchl&auml;le: $rounds </h4>";
        echo "<ul>";
        
        for ($i = 0; $i < $rounds; $i++) {
            echo "<li>Durchlauf: ". $i + 1;
            
            try {
                //TestDataService::executeComputerBids($website, $db);
                ComputerTransfersDataService::executeComputerBids($website, $db);
                echo " - " . $i18n->getMessage("generator_success") . "</li>";
            } catch (Exception $e) {
                echo " - " . $i18n->getMessage("generator_error") . ": " . $e->getMessage() . "</li>";
            }
        }
        
        echo "</ul>";
    }
    
    echo "<p>&raquo; <a href=\"?site=" . $site . "\">" . $i18n->getMessage("back_label") . "</a></p>\n";
}

?>
