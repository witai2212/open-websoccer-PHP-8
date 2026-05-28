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

echo "<h1>" . htmlspecialchars($mainTitle, ENT_QUOTES, 'UTF-8') . "</h1>";

if (!$admin["r_admin"] && !$admin["r_demo"] && !$admin[$page["permissionrole"]]) {
    throw new Exception($i18n->getMessage("error_access_denied"));
}

// Safely read and sanitize action
$show = isset($_REQUEST['show'])
? preg_replace('/[^a-zA-Z0-9_]/', '', (string) $_REQUEST['show'])
: '';

$site = isset($site) ? (string) $site : '';

if ($show === "generate") {
    
    /*
     * Important:
     * CupScheduleDataService::createFirstCupMatch()
     * must internally use only active cups:
     *
     * cm23_cup.archived = '0'
     *
     * Cup rounds are NOT archived separately.
     */
    CupScheduleDataService::createFirstCupMatch($website, $db);
    
    echo createSuccessMessage($i18n->getMessage("generator_success"), "");
    
    echo "<p>&raquo; <a href=\"?site=manage&amp;entity=cup\">"
        . htmlspecialchars($i18n->getMessage("back_label"), ENT_QUOTES, 'UTF-8')
        . "</a></p>\n";
        
} else {
    ?>

    <form action="<?php echo htmlspecialchars($_SERVER['PHP_SELF'], ENT_QUOTES, 'UTF-8'); ?>" method="post" class="form-horizontal">
        <input type="hidden" name="show" value="generate">
        <input type="hidden" name="site" value="<?php echo htmlspecialchars($site, ENT_QUOTES, 'UTF-8'); ?>">

        <p>
            This will generate the first-round national cup matches for all active national cups.
        </p>

        <div class="form-actions">
            <input
                type="submit"
                class="btn btn-primary"
                accesskey="s"
                title="Alt + s"
                value="<?php echo htmlspecialchars($i18n->getMessage("generator_button"), ENT_QUOTES, 'UTF-8'); ?>"
            >
        </div>
    </form>

    <br>

    <p>&raquo; <a href="?site=manage&amp;entity=cup">
        <?php echo htmlspecialchars($i18n->getMessage("back_label"), ENT_QUOTES, 'UTF-8'); ?>
    </a></p>

<?php
}
?>