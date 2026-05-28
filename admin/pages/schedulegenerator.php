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

if (
    !$admin["r_admin"]
    && !$admin["r_demo"]
    && !$admin[$page["permissionrole"]]
    ) {
        throw new Exception($i18n->getMessage("error_access_denied"));
    }
    
    // Prevent undefined variable warning
    $show = isset($_REQUEST['show'])
    ? preg_replace('/[^a-zA-Z0-9_]/', '', $_REQUEST['show'])
    : '';
    
    // generation might take more time than usual
    ignore_user_abort(true);
    set_time_limit(0);
    
    
    //******************************************************
    // Start page / form
    //******************************************************
    if (!$show) {
        
        ?>

    <form action="<?php echo htmlspecialchars($_SERVER['PHP_SELF'], ENT_QUOTES, 'UTF-8'); ?>" method="post" class="form-horizontal">
        <input type="hidden" name="show" value="generate">
        <input type="hidden" name="site" value="<?php echo htmlspecialchars($site, ENT_QUOTES, 'UTF-8'); ?>">

        <fieldset>
            <legend><?php echo $i18n->getMessage("schedulegenerator_label"); ?></legend>

            <?php
            $formFields = array();
            $seasonDefaultName = date("Y");

            $formFields["league"] = array(
                "type" => "foreign_key",
                "labelcolumns" => "land,name",
                "jointable" => "liga",
                "entity" => "league",
                "value" => "",
                "required" => "true"
            );

            $formFields["seasonname"] = array(
                "type" => "text",
                "value" => $seasonDefaultName,
                "required" => "true"
            );

            $formFields["rounds"] = array(
                "type" => "number",
                "value" => 2,
                "required" => "true"
            );

            $formFields["firstmatchday"] = array(
                "type" => "timestamp",
                "value" => ""
            );

            $formFields["timebreak"] = array(
                "type" => "number",
                "value" => 5
            );

            $formFields["timebreak_rounds"] = array(
                "type" => "number",
                "value" => 0
            );

            foreach ($formFields as $fieldId => $fieldInfo) {
                echo FormBuilder::createFormGroup(
                    $i18n,
                    $fieldId,
                    $fieldInfo,
                    $fieldInfo["value"],
                    "schedulegenerator_label_"
                );
            }
            ?>

            <div class="control-group">
                <label class="control-label" for="generate_all_leagues">
                    Alle Ligen
                </label>
                <div class="controls">
                    <label class="checkbox">
                        <input type="checkbox" id="generate_all_leagues" name="generate_all_leagues" value="1">
                        Spielpläne für alle Ligen in einem Durchlauf erzeugen
                    </label>
                    <span class="help-block">
                        Wenn aktiviert, wird die oben ausgewählte Liga ignoriert.
                    </span>
                </div>
            </div>

        </fieldset>

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


//******************************************************
// Validate and generate
//******************************************************
elseif ($show == "generate") {

    $err = array();

    $generateAllLeagues = !empty($_POST['generate_all_leagues']);
    $selectedLeagueId   = isset($_POST['league']) ? (int) $_POST['league'] : 0;

    // Validate league selection, unless "all leagues" is selected
    if (!$generateAllLeagues && $selectedLeagueId <= 0) {
        $err[] = $i18n->getMessage("validationerror_required");
    }

    // Validate rounds
    if (
        !isset($_POST['rounds'])
        || !is_numeric($_POST['rounds'])
        || (int) $_POST['rounds'] <= 0
        || (int) $_POST['rounds'] > 10
    ) {
        $err[] = $i18n->getMessage("schedulegenerator_err_invalidrounds");
    }

    // Validate timebreak
    if (
        !isset($_POST['timebreak'])
        || !is_numeric($_POST['timebreak'])
        || (int) $_POST['timebreak'] <= 0
        || (int) $_POST['timebreak'] > 50
    ) {
        $err[] = $i18n->getMessage("schedulegenerator_err_invalidtimebreak");
    }

    // Validate timebreak between rounds
    if (
        !isset($_POST['timebreak_rounds'])
        || !is_numeric($_POST['timebreak_rounds'])
        || (int) $_POST['timebreak_rounds'] < 0
    ) {
        $err[] = $i18n->getMessage("schedulegenerator_err_invalidtimebreak");
    }

    // Validate season name
    $seasonName = trim($_POST['seasonname'] ?? '');

    if ($seasonName === '') {
        // If you have a dedicated translation key, replace this one.
        $err[] = $i18n->getMessage("validationerror_required");
    }

    // Validate matchday date/time
    $firstMatchdayDate = trim($_POST["firstmatchday_date"] ?? '');
    $firstMatchdayTime = trim($_POST["firstmatchday_time"] ?? '');

    $dateObj = null;

    if ($firstMatchdayDate === '' && $firstMatchdayTime === '') {
        // Both empty: fallback to current timestamp
        $dateObj = new DateTime();
    }
    elseif ($firstMatchdayDate === '' || $firstMatchdayTime === '') {
        // One field is missing
        $err[] = $i18n->getMessage("validationerror_required");
    }
    else {
        $dateFormat = $website->getConfig("date_format") . ", H:i";
        $dateString = $firstMatchdayDate . ", " . $firstMatchdayTime;

        $dateObj = DateTime::createFromFormat($dateFormat, $dateString);
        $dateErrors = DateTime::getLastErrors();

        $hasDateErrors =
            $dateObj === false
            || (
                is_array($dateErrors)
                && (
                    !empty($dateErrors['warning_count'])
                    || !empty($dateErrors['error_count'])
                )
            );

        if ($hasDateErrors) {
            $err[] = $i18n->getMessage("validationerror_invalidvalue");
        }
    }

    // Demo user cannot make changes
    if ($admin['r_demo']) {
        $err[] = $i18n->getMessage("validationerror_no_changes_as_demo");
    }

    // Load all leagues once
    $allLeagues = LeagueDataService::getLeaguesSortedByCountry($website, $db);

    // Determine leagues to process
    $leaguesToProcess = array();

    if ($generateAllLeagues) {
        $leaguesToProcess = $allLeagues;
    }
    else {
        foreach ($allLeagues as $league) {
            if ((int) $league['league_id'] === $selectedLeagueId) {
                $leaguesToProcess[] = $league;
                break;
            }
        }

        if (empty($leaguesToProcess)) {
            $err[] = $i18n->getMessage("validationerror_invalidvalue");
        }
    }

    // Output errors
    if (!empty($err)) {

        include("validationerror.inc.php");

    }
    else {

        $initialMatchTimestamp = $dateObj->getTimestamp();
        $timeBreakSeconds      = 3600 * 24 * (int) $_POST['timebreak'];
        $timeBreakRoundSeconds = 3600 * 24 * (int) $_POST['timebreak_rounds'];
        $rounds                = (int) $_POST['rounds'];

        $matchTable  = $website->getConfig("db_prefix") . "_spiel";
        $seasonTable = $website->getConfig("db_prefix") . "_saison";
        $teamTable   = $website->getConfig("db_prefix") . "_verein";

        $generatedSeasons = 0;
        $generatedMatches = 0;
        $skippedLeagues    = 0;

        foreach ($leaguesToProcess as $league) {

            $leagueId = (int) $league['league_id'];

            // Get teams of current league
            $result = $db->querySelect(
                "id",
                $teamTable,
                "liga_id = %d",
                $leagueId
            );

            $teams = array();

            while ($team = $result->fetch_array()) {
                $teams[] = (int) $team["id"];
            }

            $result->free();

            // Skip leagues that cannot produce a proper schedule
            if (count($teams) < 2) {
                $skippedLeagues++;
                continue;
            }

            // Generate base round-robin schedule
            $baseSchedule = ScheduleGenerator::createRoundRobinSchedule($teams);

            // Normalize possible 1-based or irregular array keys
            $baseSchedule = array_values($baseSchedule);

            $numberOfMatchDaysPerRound = count($baseSchedule);

            if ($numberOfMatchDaysPerRound === 0) {
                $skippedLeagues++;
                continue;
            }

            // Build complete schedule for requested number of rounds
            $fullSchedule = array();

            for ($round = 1; $round <= $rounds; $round++) {

                foreach ($baseSchedule as $matchesOfBaseMatchday) {

                    $matchesForThisMatchday = array();

                    foreach ($matchesOfBaseMatchday as $match) {

                        if (!isset($match[0], $match[1])) {
                            continue;
                        }

                        // Odd rounds: original home/away
                        // Even rounds: swapped home/away
                        if ($round % 2 === 1) {
                            $matchesForThisMatchday[] = array($match[0], $match[1]);
                        }
                        else {
                            $matchesForThisMatchday[] = array($match[1], $match[0]);
                        }
                    }

                    $fullSchedule[] = $matchesForThisMatchday;
                }
            }

            // Create season record for this league
            $seasonColumns = array();
            $seasonColumns["name"]    = $seasonName;
            $seasonColumns["liga_id"] = $leagueId;

            $db->queryInsert($seasonColumns, $seasonTable);
            $seasonId = $db->getLastInsertedId();

            $generatedSeasons++;

            // Every league starts with the same first match timestamp
            $matchTimestamp = $initialMatchTimestamp;

            // Create match records
            foreach ($fullSchedule as $matchdayIndex => $matches) {

                $matchdayNumber = $matchdayIndex + 1;

                foreach ($matches as $match) {

                    if (!isset($match[0], $match[1])) {
                        continue;
                    }

                    $homeTeam  = (int) $match[0];
                    $guestTeam = (int) $match[1];

                    $teamColumns = array();
                    $teamColumns["spieltyp"]    = "Ligaspiel";
                    $teamColumns["liga_id"]     = $leagueId;
                    $teamColumns["saison_id"]   = $seasonId;
                    $teamColumns["spieltag"]    = $matchdayNumber;
                    $teamColumns["home_verein"] = $homeTeam;
                    $teamColumns["gast_verein"] = $guestTeam;
                    $teamColumns["datum"]       = $matchTimestamp;

                    $db->queryInsert($teamColumns, $matchTable);
                    $generatedMatches++;
                }

                // Normal gap to next matchday
                $matchTimestamp += $timeBreakSeconds;

                // Extra gap after each completed round,
                // except after the very last overall matchday
                $isEndOfRound = ($matchdayNumber % $numberOfMatchDaysPerRound) === 0;
                $isLastMatchday = $matchdayNumber === count($fullSchedule);

                if ($isEndOfRound && !$isLastMatchday) {
                    $matchTimestamp += $timeBreakRoundSeconds;
                }
            }
        }

        echo createSuccessMessage($i18n->getMessage("generator_success"), "");

        echo "<p>";
        echo "Erzeugte Saisons: <strong>" . (int) $generatedSeasons . "</strong><br>";
        echo "Erzeugte Spiele: <strong>" . (int) $generatedMatches . "</strong><br>";

        if ($skippedLeagues > 0) {
            echo "Übersprungene Ligen ohne ausreichende Teams: <strong>" . (int) $skippedLeagues . "</strong>";
        }

        echo "</p>";

        echo "<p>&raquo; <a href=\"?site=" . htmlspecialchars($site, ENT_QUOTES, 'UTF-8') . "\">"
            . $i18n->getMessage("back_label")
            . "</a></p>\n";
    }
}
?>