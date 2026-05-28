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

$mainTitle = $i18n->getMessage("tablemaker_navlabel");

echo "<h1>" . htmlspecialchars($mainTitle, ENT_QUOTES, 'UTF-8') . "</h1>";

if (!$admin["r_admin"] && !$admin["r_demo"] && !$admin[$page["permissionrole"]]) {
    throw new Exception($i18n->getMessage("error_access_denied"));
}

// Generation might take more time than usual
ignore_user_abort(true);
set_time_limit(0);

// Use POST value directly for this page action
$action = isset($_POST['show']) ? $_POST['show'] : '';


// ************************************************************
// Start page: show button
// ************************************************************
if ($action !== "generate") {
    ?>

    <p>
        Use this button to automatically update UEFA qualification places
        and generate table markings for all countries and all leagues.
    </p>

    <form action="<?php echo htmlspecialchars($_SERVER['PHP_SELF'], ENT_QUOTES, 'UTF-8'); ?>" method="post" class="form-horizontal">
        <input type="hidden" name="show" value="generate">
        <input type="hidden" name="site" value="<?php echo htmlspecialchars($site, ENT_QUOTES, 'UTF-8'); ?>">

        <br><br>

        <div class="form-actions">
            <input
                type="submit"
                class="btn btn-primary"
                accesskey="s"
                title="Alt + s"
                value="Generate table markings for all leagues"
            >
        </div>
    </form>

    <?php
}


// ************************************************************
// Generate markings
// ************************************************************
else {

    echo "<p><strong>Updating UEFA qualification places...</strong></p>";

    $uefaUpdate = UefaDataService::updateUefaQualificationPlacesByRanking($website, $db);

    echo "<p>";
    echo "UEFA places updated for " . (int) $uefaUpdate['countries_updated'] . " countries. ";
    echo "CL places: " . (int) $uefaUpdate['cl_total'] . ", ";
    echo "UL places: " . (int) $uefaUpdate['ul_total'] . ", ";
    echo "Conference places: " . (int) $uefaUpdate['conf_total'] . ".";
    echo "</p>";

    echo "<p><strong>Generating table markings...</strong></p>";

    TableMakingDataService::fillMarkingsForAllCountries($website, $db);

    echo createSuccessMessage(
        "UEFA qualification places have been updated and table markings have been generated for all countries and leagues.",
        ""
    );

    echo "<p>&raquo; <a href=\"?site=" . urlencode($site) . "\">"
        . $i18n->getMessage("back_label")
        . "</a></p>\n";
}

?>