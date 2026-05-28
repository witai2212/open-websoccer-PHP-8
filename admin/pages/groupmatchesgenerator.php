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

// Keep robust even if $show is not initialized by the framework.
$show = isset($_REQUEST['show'])
? preg_replace('/[^a-zA-Z0-9_]/', '', (string) $_REQUEST['show'])
: (isset($show) ? preg_replace('/[^a-zA-Z0-9_]/', '', (string) $show) : '');

$site = isset($site) ? (string) $site : '';

// Generation might take more time than usual.
ignore_user_abort(true);
set_time_limit(0);

if (!function_exists('europeanCupGeneratorStoreWinnerOfLastFinal')) {
    /**
     * Finds the last played final match of the given European cup
     * and stores the winner in *_cup.winner_id.
     *
     * Only the last final match is considered.
     */
    function europeanCupGeneratorStoreWinnerOfLastFinal(WebSoccer $website, DbConnection $db, $cupName) {
        
        $cupName = trim((string) $cupName);
        
        if ($cupName === '') {
            return false;
        }
        
        $prefix = $website->getConfig("db_prefix");
        
        $columns = array();
        $columns["C.id"]          = "cup_id";
        $columns["M.home_verein"] = "home_verein";
        $columns["M.gast_verein"] = "gast_verein";
        $columns["M.home_tore"]   = "home_tore";
        $columns["M.gast_tore"]   = "gast_tore";
        
        $fromTable  = $prefix . "_spiel AS M";
        $fromTable .= " INNER JOIN " . $prefix . "_cup AS C ON C.name = M.pokalname";
        $fromTable .= " INNER JOIN " . $prefix . "_cup_round AS R ON R.cup_id = C.id AND R.name = M.pokalrunde";
        
        $whereCondition  = "C.name = '%s' ";
        $whereCondition .= "AND M.spieltyp = 'Pokalspiel' ";
        $whereCondition .= "AND M.berechnet = '1' ";
        $whereCondition .= "AND R.finalround = '1' ";
        $whereCondition .= "ORDER BY M.datum DESC, M.id DESC";
        
        $result = $db->querySelect(
            $columns,
            $fromTable,
            $whereCondition,
            $cupName,
            1
            );
        
        $finalMatch = $result->fetch_array();
        $result->free();
        
        if (!$finalMatch) {
            return false;
        }
        
        $homeGoals  = isset($finalMatch["home_tore"]) ? (int) $finalMatch["home_tore"] : 0;
        $guestGoals = isset($finalMatch["gast_tore"]) ? (int) $finalMatch["gast_tore"] : 0;
        
        $winnerId = 0;
        
        if ($homeGoals > $guestGoals) {
            $winnerId = (int) $finalMatch["home_verein"];
        } elseif ($guestGoals > $homeGoals) {
            $winnerId = (int) $finalMatch["gast_verein"];
        }
        
        // Do not overwrite winner_id if no winner can be determined.
        if ($winnerId <= 0) {
            return false;
        }
        
        $db->queryUpdate(
            array("winner_id" => $winnerId),
            $prefix . "_cup",
            "id = %d",
            (int) $finalMatch["cup_id"]
            );
        
        return true;
    }
}

if (!function_exists('europeanCupGeneratorDeleteAllCupMatches')) {
    /**
     * Deletes all matches of one European cup and their dependent match data.
     */
    function europeanCupGeneratorDeleteAllCupMatches(WebSoccer $website, DbConnection $db, $cupName) {
        
        $cupName = trim((string) $cupName);
        
        if ($cupName === '') {
            return 0;
        }
        
        $prefix = $website->getConfig("db_prefix");
        
        $matchTable            = $prefix . "_spiel";
        $matchReportTable      = $prefix . "_matchreport";
        $matchCalculationTable = $prefix . "_spiel_berechnung";
        $formationTable        = $prefix . "_aufstellung";
        
        $result = $db->querySelect(
            "id",
            $matchTable,
            "spieltyp = 'Pokalspiel' AND pokalname = '%s'",
            $cupName
            );
        
        $matchIds = array();
        
        while ($match = $result->fetch_array()) {
            if (!empty($match["id"])) {
                $matchIds[] = (int) $match["id"];
            }
        }
        
        $result->free();
        
        $deletedMatches = 0;
        
        foreach ($matchIds as $matchId) {
            
            // Delete dependent match data first.
            $db->queryDelete(
                $matchReportTable,
                "match_id = %d",
                $matchId
                );
            
            $db->queryDelete(
                $matchCalculationTable,
                "spiel_id = %d",
                $matchId
                );
            
            $db->queryDelete(
                $formationTable,
                "match_id = %d",
                $matchId
                );
            
            // Delete match itself.
            $db->queryDelete(
                $matchTable,
                "id = %d",
                $matchId
                );
            
            $deletedMatches++;
        }
        
        return $deletedMatches;
    }
}

if (!function_exists('europeanCupGeneratorClearCurrentGroupAssignments')) {
    /**
     * Clears existing team assignments of the selected cup's group round.
     *
     * The teams will then be freshly rebuilt from *_uefa_temp.
     */
    function europeanCupGeneratorClearCurrentGroupAssignments(WebSoccer $website, DbConnection $db, $roundId) {
        
        $roundId = (int) $roundId;
        
        if ($roundId <= 0) {
            return;
        }
        
        $prefix = $website->getConfig("db_prefix");
        
        $db->queryDelete(
            $prefix . "_cup_round_group",
            "cup_round_id = %d",
            $roundId
            );
    }
}

//********** Start page **********
if (!$show) {
    ?>

    <form action="<?php echo htmlspecialchars($_SERVER['PHP_SELF'], ENT_QUOTES, 'UTF-8'); ?>" method="post" class="form-horizontal">
        <input type="hidden" name="show" value="generate">
        <input type="hidden" name="site" value="<?php echo htmlspecialchars($site, ENT_QUOTES, 'UTF-8'); ?>">

        <fieldset>

<?php
        echo htmlspecialchars($i18n->getMessage("groupmatchesgenerator_select_cups"), ENT_QUOTES, 'UTF-8');
?>

        <select name="cup" id="cups">
            <option value="">--</option>
            <option value="Champions League">Champions League</option>
            <option value="UEFA Euro League">UEFA Euro League</option>
        </select>

<?php
        $formFields = array();

        $formFields["firstmatchday"] = array(
            "type" => "timestamp",
            "value" => ""
        );

        $formFields["timebreak"] = array(
            "type" => "number",
            "value" => 5
        );

        $formFields["matchesperteam"] = array(
            "type" => "number",
            "value" => 15
        );

        foreach ($formFields as $fieldId => $fieldInfo) {
            echo FormBuilder::createFormGroup(
                $i18n,
                $fieldId,
                $fieldInfo,
                $fieldInfo["value"],
                "groupmatchesgenerator_label_"
            );
        }
?>

        </fieldset>

        <br><br>

        <div class="form-actions">
            <input
                type="submit"
                class="btn btn-primary"
                accesskey="s"
                title="Alt + s"
                value="<?php echo htmlspecialchars($i18n->getMessage("generator_button"), ENT_QUOTES, 'UTF-8'); ?>"
            >

            <input
                type="reset"
                class="btn"
                value="<?php echo htmlspecialchars($i18n->getMessage("button_reset"), ENT_QUOTES, 'UTF-8'); ?>"
            >
        </div>
    </form>

<?php
}

//********** Validate, cleanup, generate **********
elseif ($show === "generate") {

    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        throw new Exception("Invalid request method.");
    }

    /*
     * ------------------------------------------------------------
     * Validate cup selection.
     * ------------------------------------------------------------
     */
    $allowedCups = array(
        "Champions League",
        "UEFA Euro League"
    );

    $cupName = isset($_POST["cup"])
        ? trim((string) $_POST["cup"])
        : "";

    if ($cupName === '' || !in_array($cupName, $allowedCups, true)) {
        throw new Exception("Please select a valid European cup.");
    }

    /*
     * ------------------------------------------------------------
     * Validate first matchday fields.
     * ------------------------------------------------------------
     */
    $firstMatchdayDate = isset($_POST["firstmatchday_date"])
        ? trim((string) $_POST["firstmatchday_date"])
        : "";

    $hourRaw = isset($_POST["hour"])
        ? trim((string) $_POST["hour"])
        : "";

    $minuteRaw = isset($_POST["minute"])
        ? trim((string) $_POST["minute"])
        : "";

    if ($firstMatchdayDate === '') {
        throw new Exception("First matchday is required.");
    }

    if ($hourRaw === '' || !ctype_digit($hourRaw)) {
        throw new Exception("Invalid hour.");
    }

    if ($minuteRaw === '' || !ctype_digit($minuteRaw)) {
        throw new Exception("Invalid minute.");
    }

    $hour   = (int) $hourRaw;
    $minute = (int) $minuteRaw;

    if ($hour < 0 || $hour > 23) {
        throw new Exception("Invalid hour.");
    }

    if ($minute < 0 || $minute > 59) {
        throw new Exception("Invalid minute.");
    }

    /*
     * Validate first matchday date format dd.mm.yyyy.
     */
    $firstMatchdayParts = explode(".", $firstMatchdayDate);

    if (count($firstMatchdayParts) !== 3) {
        throw new Exception("Invalid first matchday date. Expected format: dd.mm.yyyy.");
    }

    $day   = (int) $firstMatchdayParts[0];
    $month = (int) $firstMatchdayParts[1];
    $year  = (int) $firstMatchdayParts[2];

    if (!checkdate($month, $day, $year)) {
        throw new Exception("Invalid first matchday date.");
    }

    /*
     * ------------------------------------------------------------
     * Validate schedule settings.
     * ------------------------------------------------------------
     */
    $timebreakRaw = isset($_POST["timebreak"])
        ? trim((string) $_POST["timebreak"])
        : "";

    $matchesPerTeamRaw = isset($_POST["matchesperteam"])
        ? trim((string) $_POST["matchesperteam"])
        : "";

    if ($timebreakRaw === '' || !ctype_digit($timebreakRaw)) {
        throw new Exception("Invalid time break.");
    }

    if ($matchesPerTeamRaw === '' || !ctype_digit($matchesPerTeamRaw)) {
        throw new Exception("Invalid matches-per-team value.");
    }

    $timebreak       = (int) $timebreakRaw;
    $matchesPerTeam  = (int) $matchesPerTeamRaw;

    if ($timebreak < 1) {
        throw new Exception("Time break must be at least 1.");
    }

    if ($matchesPerTeam < 1) {
        throw new Exception("Matches per team must be at least 1.");
    }

    /*
     * ------------------------------------------------------------
     * Resolve cup and group round.
     * ------------------------------------------------------------
     */
    $cupId = CupsDataService::getCupIdByName($website, $db, $cupName);

    if (!$cupId) {
        throw new Exception("European cup not found: " . $cupName);
    }

    $cupRoundName = "Gruppen";

    $roundId = CupsDataService::getGroupIdByCupId(
        $website,
        $db,
        $cupId,
        $cupRoundName
    );

    if (!$roundId) {
        throw new Exception("Group round '{$cupRoundName}' not found for cup: " . $cupName);
    }

    /*
     * ------------------------------------------------------------
     * STEP 1:
     * Store the winner of the last played final.
     * ------------------------------------------------------------
     */
    europeanCupGeneratorStoreWinnerOfLastFinal(
        $website,
        $db,
        $cupName
    );

    /*
     * ------------------------------------------------------------
     * STEP 2:
     * Delete all existing matches of the selected European cup.
     * ------------------------------------------------------------
     */
    europeanCupGeneratorDeleteAllCupMatches(
        $website,
        $db,
        $cupName
    );

    /*
     * ------------------------------------------------------------
     * STEP 3:
     * Clear current group assignments for "Gruppen".
     *
     * The teams are then repopulated from *_uefa_temp,
     * which reflects the previous season's positions.
     * ------------------------------------------------------------
     */
    europeanCupGeneratorClearCurrentGroupAssignments(
        $website,
        $db,
        $roundId
    );

    /*
     * ------------------------------------------------------------
     * STEP 4:
     * Get teams from UEFA temp table.
     * ------------------------------------------------------------
     */
    $tempTeams = UefaDataService::getUefaTeamsByCupId(
        $website,
        $db,
        $cupId
    );

    if (!is_array($tempTeams) || empty($tempTeams)) {
        throw new Exception("No UEFA temp teams found for cup: " . $cupName);
    }

    /*
     * ------------------------------------------------------------
     * STEP 5:
     * Put UEFA temp teams into groups.
     * ------------------------------------------------------------
     */
    UefaDataService::putTempTeamsInGroups(
        $website,
        $db,
        $roundId,
        $tempTeams
    );

    /*
     * ------------------------------------------------------------
     * STEP 6:
     * Generate group-stage schedules for groups A-D.
     * ------------------------------------------------------------
     */
    $groups = array("A", "B", "C", "D");

    foreach ($groups as $groupName) {

        $groupTeams = UefaDataService::getUefaTeamsByGroup(
            $website,
            $db,
            $groupName,
            $roundId
        );

        if (!is_array($groupTeams) || empty($groupTeams)) {
            continue;
        }

        ScheduleGenerator::createUEFACupGroupSchedule(
            $website,
            $db,
            $groupTeams,
            $firstMatchdayDate,
            $hour,
            $minute,
            $timebreak,
            $cupName,
            $matchesPerTeam,
            $groupName,
            $cupRoundName
        );
    }

    echo createSuccessMessage($i18n->getMessage("generator_success"), "");

    echo "<p>&raquo; <a href=\"?site=" . rawurlencode($site) . "\">"
        . htmlspecialchars($i18n->getMessage("back_label"), ENT_QUOTES, 'UTF-8')
        . "</a></p>\n";
}

?>