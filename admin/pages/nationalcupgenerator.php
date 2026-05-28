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

echo "<h1>" . htmlspecialchars($mainTitle, ENT_QUOTES, 'UTF-8') . "</h1>";

if (!$admin["r_admin"] && !$admin["r_demo"] && !$admin[$page["permissionrole"]]) {
    throw new Exception($i18n->getMessage("error_access_denied"));
}

// Keep the file robust even if $show is not pre-initialized by the admin framework.
$show = isset($_REQUEST['show'])
? preg_replace('/[^a-zA-Z0-9_]/', '', (string) $_REQUEST['show'])
: (isset($show) ? preg_replace('/[^a-zA-Z0-9_]/', '', (string) $show) : '');

$site = isset($site) ? (string) $site : '';

if (!function_exists('nationalCupGeneratorGetLargestPowerOfTwo')) {
    /**
     * Returns the largest power of two that is less than or equal to $number.
     */
    function nationalCupGeneratorGetLargestPowerOfTwo($number) {
        $number = (int) $number;
        
        if ($number < 1) {
            return 0;
        }
        
        $power = 1;
        
        while (($power * 2) <= $number) {
            $power *= 2;
        }
        
        return $power;
    }
}

if (!function_exists('nationalCupGeneratorGetRounds')) {
    /**
     * Returns the number of knockout rounds for a power-of-two team count.
     */
    function nationalCupGeneratorGetRounds($numberOfTeams) {
        $teams = (int) $numberOfTeams;
        $rounds = 0;
        
        while ($teams > 1) {
            $teams = (int) ($teams / 2);
            $rounds++;
        }
        
        return $rounds;
    }
}

if (!function_exists('nationalCupGeneratorStoreWinnerOfLastMatch')) {
    /**
     * Reads the last calculated national cup match and stores its winner
     * in *_cup.winner_id.
     *
     * National cups are identified by pokalname = cup/country name.
     */
    function nationalCupGeneratorStoreWinnerOfLastMatch($website, $db, $cupName) {
        $cupName = trim((string) $cupName);
        
        if ($cupName === '') {
            return false;
        }
        
        $prefix = $website->getConfig('db_prefix');
        $cupTable = $prefix . '_cup';
        $matchTable = $prefix . '_spiel';
        
        // Find active cup record.
        $result = $db->querySelect(
            'id',
            $cupTable,
            "name = '%s' AND archived = '0'",
            $cupName,
            1
            );
        
        $cup = $result->fetch_array();
        $result->free();
        
        if (!$cup || empty($cup['id'])) {
            return false;
        }
        
        // Find the last already calculated cup match.
        $columns = array();
        $columns['home_verein'] = 'home_verein';
        $columns['gast_verein'] = 'gast_verein';
        $columns['home_tore'] = 'home_tore';
        $columns['gast_tore'] = 'gast_tore';
        
        $whereCondition = "spieltyp = 'Pokalspiel'
            AND pokalname = '%s'
            AND berechnet = '1'
            ORDER BY datum DESC, id DESC";
        
        $result = $db->querySelect(
            $columns,
            $matchTable,
            $whereCondition,
            $cupName,
            1
            );
        
        $lastMatch = $result->fetch_array();
        $result->free();
        
        if (!$lastMatch) {
            return false;
        }
        
        $homeGoals = isset($lastMatch['home_tore']) ? (int) $lastMatch['home_tore'] : 0;
        $guestGoals = isset($lastMatch['gast_tore']) ? (int) $lastMatch['gast_tore'] : 0;
        
        $winnerId = 0;
        
        if ($homeGoals > $guestGoals) {
            $winnerId = (int) $lastMatch['home_verein'];
        } elseif ($guestGoals > $homeGoals) {
            $winnerId = (int) $lastMatch['gast_verein'];
        }
        
        // No winner detected, e.g. draw or incomplete/invalid score.
        if ($winnerId <= 0) {
            return false;
        }
        
        $db->queryUpdate(
            array('winner_id' => $winnerId),
            $cupTable,
            'id = %d',
            (int) $cup['id']
            );
        
        return true;
    }
}

if (!function_exists('nationalCupGeneratorDeleteNationalCupMatches')) {
    /**
     * Deletes all matches and dependent match data of one national cup.
     */
    function nationalCupGeneratorDeleteNationalCupMatches($website, $db, $cupName) {
        $cupName = trim((string) $cupName);
        
        if ($cupName === '') {
            return 0;
        }
        
        $prefix = $website->getConfig('db_prefix');
        
        $matchTable = $prefix . '_spiel';
        $matchReportTable = $prefix . '_matchreport';
        $matchCalculationTable = $prefix . '_spiel_berechnung';
        $formationTable = $prefix . '_aufstellung';
        
        $result = $db->querySelect(
            'id',
            $matchTable,
            "spieltyp = 'Pokalspiel' AND pokalname = '%s'",
            $cupName
            );
        
        $matchIds = array();
        
        while ($match = $result->fetch_array()) {
            if (!empty($match['id'])) {
                $matchIds[] = (int) $match['id'];
            }
        }
        
        $result->free();
        
        $deletedMatches = 0;
        
        foreach ($matchIds as $matchId) {
            // Clean dependent data first.
            $db->queryDelete($matchReportTable, 'match_id = %d', $matchId);
            $db->queryDelete($matchCalculationTable, 'spiel_id = %d', $matchId);
            $db->queryDelete($formationTable, 'match_id = %d', $matchId);
            
            // Delete match itself.
            $db->queryDelete($matchTable, 'id = %d', $matchId);
            $deletedMatches++;
        }
        
        return $deletedMatches;
    }
}

//********** Startseite **********
if (!$show) {
    ?>

    <form action="<?php echo htmlspecialchars($_SERVER['PHP_SELF'], ENT_QUOTES, 'UTF-8'); ?>" method="post" class="form-horizontal">
        <input type="hidden" name="show" value="generate">
        <input type="hidden" name="site" value="<?php echo htmlspecialchars($site, ENT_QUOTES, 'UTF-8'); ?>">

        <fieldset>

<?php

        $formFields = array();
        $formFields["firstmatchday"] = array(
            "type" => "timestamp",
            "value" => ""
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

//********** validate, generate **********
elseif ($show === "generate") {

    // Generation might take more time than usual.
    ignore_user_abort(true);
    set_time_limit(0);

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method.');
    }

    $firstMatchdayDate = isset($_POST['firstmatchday_date'])
        ? trim((string) $_POST['firstmatchday_date'])
        : '';

    $hourRaw = isset($_POST['hour'])
        ? trim((string) $_POST['hour'])
        : '';

    $minuteRaw = isset($_POST['minute'])
        ? trim((string) $_POST['minute'])
        : '';

    if ($firstMatchdayDate === '') {
        throw new Exception('First matchday is required.');
    }

    if ($hourRaw === '' || !ctype_digit($hourRaw)) {
        throw new Exception('Invalid hour.');
    }

    if ($minuteRaw === '' || !ctype_digit($minuteRaw)) {
        throw new Exception('Invalid minute.');
    }

    $hour = (int) $hourRaw;
    $minute = (int) $minuteRaw;

    if ($hour < 0 || $hour > 23) {
        throw new Exception('Invalid hour.');
    }

    if ($minute < 0 || $minute > 59) {
        throw new Exception('Invalid minute.');
    }

    $countries = TeamsDataService::getNumberOfTeamsByCountry($website, $db);

    if (!is_array($countries) || empty($countries)) {
        echo "No teams data found.";
        return;
    }

    /*
     * ------------------------------------------------------------
     * PHASE 1:
     * Store previous winner of each existing national cup.
     * ------------------------------------------------------------
     */
    foreach ($countries as $country) {
        if (!isset($country['name'])) {
            continue;
        }

        $land = trim((string) $country['name']);

        if ($land === '') {
            continue;
        }

        nationalCupGeneratorStoreWinnerOfLastMatch($website, $db, $land);
    }

    /*
     * ------------------------------------------------------------
     * PHASE 2:
     * Delete all old national cup matches before creating new ones.
     * ------------------------------------------------------------
     */
    foreach ($countries as $country) {
        if (!isset($country['name'])) {
            continue;
        }

        $land = trim((string) $country['name']);

        if ($land === '') {
            continue;
        }

        nationalCupGeneratorDeleteNationalCupMatches($website, $db, $land);
    }

    /*
     * ------------------------------------------------------------
     * PHASE 3:
     * Generate fresh national cups.
     * ------------------------------------------------------------
     */
    foreach ($countries as $country) {

        if (!isset($country['teams'], $country['name'])) {
            continue;
        }

        $land = trim((string) $country['name']);
        $numberOfTeams = (int) $country['teams'];

        // National cups are only generated for countries with at least 16 teams.
        if ($land === '' || $numberOfTeams < 16) {
            continue;
        }

        /*
         * Use the largest valid knockout cup size that does not exceed
         * the number of available teams.
         *
         * Examples:
         * 18 teams  => 16-team cup
         * 35 teams  => 32-team cup
         * 70 teams  => 64-team cup
         * 130 teams => 128-team cup
         */
        $teams = nationalCupGeneratorGetLargestPowerOfTwo($numberOfTeams);

        if ($teams < 16) {
            continue;
        }

        $rounds = nationalCupGeneratorGetRounds($teams);

        echo htmlspecialchars($land, ENT_QUOTES, 'UTF-8')
            . " - "
            . (int) $teams
            . "<br>";

        //######### GENERATE CUP + MATCHES ##################
        CupsDataService::generateNationalCup(
            $website,
            $db,
            $land,
            $rounds,
            $firstMatchdayDate,
            $hour,
            $minute
        );
        //###################################################
    }

    echo createSuccessMessage($i18n->getMessage("generator_success"), "");
    echo "<p>&raquo; <a href=\"?site=" . rawurlencode($site) . "\">"
        . htmlspecialchars($i18n->getMessage("back_label"), ENT_QUOTES, 'UTF-8')
        . "</a></p>\n";
}

?>