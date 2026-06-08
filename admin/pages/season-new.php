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


/**
 * Returns all leagues.
 */
if (!function_exists('seasonNewGetAllLeagues')) {
    function seasonNewGetAllLeagues($db, $conf) {

        $columns = 'id, name, land';
        $fromTable = $conf['db_prefix'] . '_liga';
        $whereCondition = '1 = 1 ORDER BY land ASC, name ASC';

        $result = $db->querySelect(
            $columns,
            $fromTable,
            $whereCondition
        );

        $leagues = array();

        while ($league = $result->fetch_array()) {
            $leagues[] = $league;
        }

        $result->free();

        return $leagues;
    }
}


/**
 * Checks whether this league already has an unfinished season.
 */
if (!function_exists('seasonNewGetOpenSeasonForLeague')) {
    function seasonNewGetOpenSeasonForLeague($db, $conf, $leagueId) {

        $result = $db->querySelect(
            'id, name',
            $conf['db_prefix'] . '_saison',
            'liga_id = %d AND beendet = \'0\'',
            (int) $leagueId,
            1
        );

        $season = $result->fetch_array();
        $result->free();

        return $season;
    }
}


/**
 * Checks whether this league already has a season with the exact same name.
 */
if (!function_exists('seasonNewGetSeasonWithSameName')) {
    function seasonNewGetSeasonWithSameName($db, $conf, $leagueId, $seasonName) {

        $result = $db->querySelect(
            'id, name, beendet',
            $conf['db_prefix'] . '_saison',
            'liga_id = %d AND name = \'%s\'',
            array((int) $leagueId, $seasonName),
            1
        );

        $season = $result->fetch_array();
        $result->free();

        return $season;
    }
}


/**
 * Creates one new season for one league.
 */
if (!function_exists('seasonNewCreateSeasonForLeague')) {
    function seasonNewCreateSeasonForLeague($db, $conf, $leagueId, $seasonName) {

        $seasonColumns = array();
        $seasonColumns['name'] = $seasonName;
        $seasonColumns['liga_id'] = (int) $leagueId;
        $seasonColumns['platz_1_id'] = '0';
        $seasonColumns['platz_2_id'] = '0';
        $seasonColumns['platz_3_id'] = '0';
        $seasonColumns['platz_4_id'] = '0';
        $seasonColumns['platz_5_id'] = '0';
        $seasonColumns['beendet'] = '0';

        $db->queryInsert(
            $seasonColumns,
            $conf['db_prefix'] . '_saison'
        );

        return $db->getLastInsertedId();
    }
}


/**
 * Reads and validates the submitted season name.
 */
if (!function_exists('seasonNewReadSubmittedSeasonName')) {
    function seasonNewReadSubmittedSeasonName($i18n) {

        $errors = array();

        $seasonName = isset($_POST['season_name'])
            ? trim((string) $_POST['season_name'])
            : '';

        if ($seasonName === '') {
            $errors[] = $i18n->getMessage('validationerror_invalid_value');
        }

        // saison.name is VARCHAR(20)
        if (strlen($seasonName) > 20) {
            $errors[] = 'The season name must not be longer than 20 characters.';
        }

        return array(
            'errors' => $errors,
            'seasonName' => $seasonName
        );
    }
}


if (!function_exists('seasonNewGetTransferPenaltyPreview')) {
    function seasonNewGetTransferPenaltyPreview($website, $db) {
        if (!class_exists('TransferPenaltyDataService')) {
            return array();
        }

        return TransferPenaltyDataService::getDistributionPreview($website, $db);
    }
}

if (!function_exists('seasonNewRenderTransferPenaltyPreview')) {
    function seasonNewRenderTransferPenaltyPreview($preview) {
        if (!is_array($preview) || !isset($preview['penalty_pool'])) {
            return;
        }

        $pool = (int) $preview['penalty_pool'];
        $teams = (int) $preview['managed_teams'];
        $baseAmount = (int) $preview['base_amount'];
        $remainder = (int) $preview['remainder'];

        echo '<div class="alert alert-info">';
        echo '<strong>Transferstrafen-Ausschüttung:</strong> ';

        if ($pool <= 0) {
            echo 'Der Strafentopf ist aktuell leer.';
        } elseif ($teams <= 0) {
            echo 'Im Strafentopf liegen ' . number_format($pool, 0, ',', ' ') . ', aber es gibt aktuell keine user-geführten Vereine für eine Ausschüttung.';
        } else {
            echo number_format($pool, 0, ',', ' ') . ' werden beim Erstellen neuer Saisons an ' . $teams . ' user-geführte Vereine verteilt.';
            echo ' Voraussichtlich ' . number_format($baseAmount, 0, ',', ' ') . ' pro Verein';
            if ($remainder > 0) {
                echo ', Rest ' . number_format($remainder, 0, ',', ' ') . ' wird auf die ersten Vereine verteilt';
            }
            echo '. Die Buchung erscheint anschließend in den Finanzen.';
        }

        echo '</div>';
    }
}

if (!function_exists('seasonNewRenderTransferPenaltyDistribution')) {
    function seasonNewRenderTransferPenaltyDistribution($distribution) {
        if (!is_array($distribution) || !isset($distribution['status'])) {
            return;
        }

        echo '<h3>Transferstrafen-Ausschüttung</h3>';

        if ($distribution['status'] === 'distributed') {
            echo '<p><strong>' . number_format((int) $distribution['distributed_total'], 0, ',', ' ') . '</strong> wurden an <strong>' . (int) $distribution['managed_teams'] . '</strong> user-geführte Vereine ausgeschüttet und im Kontoauszug gebucht. Der Strafentopf wurde auf 0 gesetzt.</p>';

            if (!empty($distribution['booked_teams'])) {
                echo '<table class="table table-striped">';
                echo '<thead><tr><th>Verein</th><th>User-ID</th><th>Betrag</th></tr></thead>';
                echo '<tbody>';
                foreach ($distribution['booked_teams'] as $team) {
                    echo '<tr>';
                    echo '<td>' . escapeOutput($team['team_name']) . '</td>';
                    echo '<td>' . (int) $team['user_id'] . '</td>';
                    echo '<td>' . number_format((int) $team['amount'], 0, ',', ' ') . '</td>';
                    echo '</tr>';
                }
                echo '</tbody>';
                echo '</table>';
            }
        } elseif ($distribution['status'] === 'no_user_managed_clubs') {
            echo '<p>Keine Ausschüttung: Es gibt aktuell keine user-geführten Vereine. Der Strafentopf bleibt unverändert.</p>';
        } else {
            echo '<p>Keine Ausschüttung: Der Strafentopf ist leer.</p>';
        }
    }
}



$mainTitle = 'Create new seasons';

$show = isset($_REQUEST['show'])
    ? preg_replace('/[^a-zA-Z0-9_]/', '', (string) $_REQUEST['show'])
    : '';

echo '<h1>' . escapeOutput($mainTitle) . '</h1>';

if (!$admin['r_admin'] && !$admin['r_demo'] && !$admin[$page['permissionrole']]) {
    throw new Exception($i18n->getMessage('error_access_denied'));
}


//********** Start page **********
if (!$show) {

    $leagues = seasonNewGetAllLeagues($db, $conf);

    ?>
    <p>
        This tool creates one new season for all leagues at once.
        You will enter the season name once, and the same name will be used for every league.
    </p>
    <?php

    seasonNewRenderTransferPenaltyPreview(seasonNewGetTransferPenaltyPreview($website, $db));

    if (empty($leagues)) {

        echo '<p><strong>No leagues found.</strong></p>';

    } else {

        ?>
        <table class="table table-striped">
            <thead>
                <tr>
                    <th>Country</th>
                    <th>League</th>
                </tr>
            </thead>
            <tbody>
            <?php
            foreach ($leagues as $league) {
                echo '<tr>';
                echo '<td>' . escapeOutput($league['land']) . '</td>';
                echo '<td>' . escapeOutput($league['name']) . '</td>';
                echo '</tr>';
            }
            ?>
            </tbody>
        </table>

        <p>
            <a class="btn btn-primary" href="?site=<?php echo escapeOutput($site); ?>&amp;show=select_all">
                Create new season for all leagues
            </a>
        </p>
        <?php
    }


//********** Confirm / input form **********
} elseif ($show == 'select_all') {

    $leagues = seasonNewGetAllLeagues($db, $conf);

    if (empty($leagues)) {

        echo '<p><strong>No leagues found.</strong></p>';
        echo '<p>&raquo; <a href="?site=' . escapeOutput($site) . '">' . $i18n->getMessage('back_label') . '</a></p>';

    } else {

        ?>
        <p>
            Enter the season name that shall be created for all leagues.
            Example: <strong>2026/27</strong>
        </p>

        <?php seasonNewRenderTransferPenaltyPreview(seasonNewGetTransferPenaltyPreview($website, $db)); ?>

        <form
            action="<?php echo htmlspecialchars($_SERVER['PHP_SELF'], ENT_QUOTES, 'UTF-8'); ?>"
            method="post"
            class="form-horizontal"
        >
            <input type="hidden" name="show" value="create_all">
            <input type="hidden" name="site" value="<?php echo escapeOutput($site); ?>">

            <fieldset>
                <legend>Create new seasons for all leagues</legend>

                <div class="control-group">
                    <label class="control-label" for="season_name">
                        Season name
                    </label>
                    <div class="controls">
                        <input
                            type="text"
                            id="season_name"
                            name="season_name"
                            maxlength="20"
                            required="required"
                            value=""
                        >
                        <p class="help-block">
                            Maximum 20 characters.
                        </p>
                    </div>
                </div>
            </fieldset>

            <div class="form-actions">
                <input
                    type="submit"
                    class="btn btn-primary"
                    accesskey="s"
                    title="Alt + s"
                    value="Create seasons"
                >
                <input
                    type="reset"
                    class="btn"
                    value="<?php echo $i18n->getMessage('button_reset'); ?>"
                >
            </div>
        </form>

        <h3>Leagues that will be checked</h3>

        <table class="table table-striped">
            <thead>
                <tr>
                    <th>Country</th>
                    <th>League</th>
                </tr>
            </thead>
            <tbody>
            <?php
            foreach ($leagues as $league) {
                echo '<tr>';
                echo '<td>' . escapeOutput($league['land']) . '</td>';
                echo '<td>' . escapeOutput($league['name']) . '</td>';
                echo '</tr>';
            }
            ?>
            </tbody>
        </table>
        <?php
    }


//********** Create seasons for all leagues **********
} elseif ($show == 'create_all') {

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method.');
    }

    $err = array();

    if ($admin['r_demo']) {
        $err[] = $i18n->getMessage('validationerror_no_changes_as_demo');
    }

    $input = seasonNewReadSubmittedSeasonName($i18n);

    if (!empty($input['errors'])) {
        $err = array_merge($err, $input['errors']);
    }

    if (!empty($err)) {

        include('validationerror.inc.php');

    } else {

        $seasonName = $input['seasonName'];
        if (class_exists('ParentClubDataService')) {
            ParentClubDataService::resolveDivisionConflicts($website, $db);
        }
        $leagues = seasonNewGetAllLeagues($db, $conf);

        if (empty($leagues)) {

            echo '<p><strong>No leagues found.</strong></p>';
            echo '<p>&raquo; <a href="?site=' . escapeOutput($site) . '">' . $i18n->getMessage('back_label') . '</a></p>';

        } else {

            $createdSeasons = array();
            $skippedLeagues = array();
            $insertErrors = array();
            $transferPenaltyDistribution = array();

            foreach ($leagues as $league) {

                $leagueId = (int) $league['id'];

                // 1. Do not create if the same season name already exists for this league
                $sameNameSeason = seasonNewGetSeasonWithSameName(
                    $db,
                    $conf,
                    $leagueId,
                    $seasonName
                );

                if ($sameNameSeason) {

                    $skippedLeagues[] = array(
                        'country' => $league['land'],
                        'league' => $league['name'],
                        'reason' => 'A season with this exact name already exists.'
                    );

                    continue;
                }

                // 2. Do not create if there is still an unfinished season for the league
                $openSeason = seasonNewGetOpenSeasonForLeague(
                    $db,
                    $conf,
                    $leagueId
                );

                if ($openSeason) {

                    $skippedLeagues[] = array(
                        'country' => $league['land'],
                        'league' => $league['name'],
                        'reason' => 'There is already an unfinished season: ' . $openSeason['name']
                    );

                    continue;
                }

                // 3. Create season
                try {

                    $newSeasonId = seasonNewCreateSeasonForLeague(
                        $db,
                        $conf,
                        $leagueId,
                        $seasonName
                    );

                    $createdSeasons[] = array(
                        'id' => $newSeasonId,
                        'country' => $league['land'],
                        'league' => $league['name'],
                        'season_name' => $seasonName
                    );

                } catch (Exception $e) {

                    $insertErrors[] =
                        escapeOutput($league['land'])
                        . ' / '
                        . escapeOutput($league['name'])
                        . ': '
                        . $e->getMessage();
                }
            }


            if (!empty($createdSeasons) && class_exists('TransferPenaltyDataService')) {
                try {
                    $transferPenaltyDistribution = TransferPenaltyDataService::distributePenalties($website, $db);
                } catch (Exception $e) {
                    $insertErrors[] = 'Transferstrafen-Ausschüttung: ' . $e->getMessage();
                }
            }

            // Success section
            if (!empty($createdSeasons)) {

                echo createSuccessMessage(
                    $i18n->getMessage('alert_save_success'),
                    ''
                );

                echo '<p><strong>' . count($createdSeasons) . ' new season(s) created.</strong></p>';

                echo '<table class="table table-striped">';
                echo '<thead>';
                echo '<tr>';
                echo '<th>Country</th>';
                echo '<th>League</th>';
                echo '<th>Season</th>';
                echo '</tr>';
                echo '</thead>';
                echo '<tbody>';

                foreach ($createdSeasons as $createdSeason) {
                    echo '<tr>';
                    echo '<td>' . escapeOutput($createdSeason['country']) . '</td>';
                    echo '<td>' . escapeOutput($createdSeason['league']) . '</td>';
                    echo '<td>' . escapeOutput($createdSeason['season_name']) . '</td>';
                    echo '</tr>';
                }

                echo '</tbody>';
                echo '</table>';

                seasonNewRenderTransferPenaltyDistribution($transferPenaltyDistribution);
            } else {

                echo '<p><strong>No new seasons were created.</strong></p>';
            }


            // Skipped section
            if (!empty($skippedLeagues)) {

                echo '<h3>Skipped leagues</h3>';

                echo '<table class="table table-striped">';
                echo '<thead>';
                echo '<tr>';
                echo '<th>Country</th>';
                echo '<th>League</th>';
                echo '<th>Reason</th>';
                echo '</tr>';
                echo '</thead>';
                echo '<tbody>';

                foreach ($skippedLeagues as $skippedLeague) {
                    echo '<tr>';
                    echo '<td>' . escapeOutput($skippedLeague['country']) . '</td>';
                    echo '<td>' . escapeOutput($skippedLeague['league']) . '</td>';
                    echo '<td>' . escapeOutput($skippedLeague['reason']) . '</td>';
                    echo '</tr>';
                }

                echo '</tbody>';
                echo '</table>';
            }


            // Error section
            if (!empty($insertErrors)) {

                echo createErrorMessage(
                    $i18n->getMessage('subpage_error_title'),
                    implode('<br>', $insertErrors)
                );
            }

            echo '<p>&raquo; <a href="?site=' . escapeOutput($site) . '">' . $i18n->getMessage('back_label') . '</a></p>';
        }
    }


} else {

    throw new Exception('Invalid request.');
}
?>