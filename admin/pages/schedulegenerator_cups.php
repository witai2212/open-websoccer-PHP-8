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
echo $_REQUEST['show'];

if ($_REQUEST['show'] == "generate") {
    CupScheduleDataService::createFirstCupMatch($website, $db);    
    echo createSuccessMessage($i18n->getMessage("generator_success"), "");    
    echo "<p>&raquo; <a href=\"?site=manage&entity=cup\">". $i18n->getMessage("back_label") . "</a></p>\n";
} else {        
    echo "<p>&raquo; <a href=\"?site=manage&entity=cup\">". $i18n->getMessage("back_label") . "</a></p>\n";
}
?>