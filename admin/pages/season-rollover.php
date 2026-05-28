<?php
/******************************************************

  Season rollover wizard for OpenWebSoccer-Sim.

******************************************************/

$mainTitle = $i18n->getMessage('seasonrollover_navlabel');
if (!$i18n->hasMessage('seasonrollover_navlabel')) {
    $mainTitle = 'Saisonwechsel-Assistent';
}

echo '<h1>' . escapeOutput($mainTitle) . '</h1>';

if (!$admin['r_admin'] && !$admin['r_demo'] && !$admin[$page['permissionrole']]) {
    throw new Exception($i18n->getMessage('error_access_denied'));
}

ignore_user_abort(true);
set_time_limit(0);

$show = isset($_REQUEST['show'])
    ? preg_replace('/[^a-zA-Z0-9_]/', '', (string) $_REQUEST['show'])
    : '';

$step = isset($_REQUEST['step'])
    ? preg_replace('/[^a-zA-Z0-9_]/', '', (string) $_REQUEST['step'])
    : '';

function seasonRolloverMsg($i18n, $key, $fallback) {
    return $i18n->hasMessage($key) ? $i18n->getMessage($key) : $fallback;
}

function seasonRolloverBoolLabel($value) {
    return $value ? '<span class="label label-success">OK</span>' : '<span class="label label-warning">Fehlt</span>';
}

function seasonRolloverRenderPenaltyPreview($penaltyPreview) {
    if (!is_array($penaltyPreview) || !isset($penaltyPreview['penalty_pool'])) {
        return;
    }

    $pool = (int) $penaltyPreview['penalty_pool'];
    $teams = (int) $penaltyPreview['managed_teams'];
    $baseAmount = (int) $penaltyPreview['base_amount'];
    $remainder = (int) $penaltyPreview['remainder'];

    echo '<div class="alert alert-info">';
    echo '<strong>Transferstrafen-Ausschüttung:</strong> ';

    if ($pool <= 0) {
        echo 'Der Strafentopf ist aktuell leer.';
    } elseif ($teams <= 0) {
        echo 'Im Strafentopf liegen ' . number_format($pool, 0, ',', ' ') . ', aber es gibt aktuell keine user-geführten Vereine für eine Ausschüttung.';
    } else {
        echo number_format($pool, 0, ',', ' ') . ' werden beim Saisonwechsel an ' . $teams . ' user-geführte Vereine verteilt.';
        echo ' Voraussichtlich ' . number_format($baseAmount, 0, ',', ' ') . ' pro Verein';
        if ($remainder > 0) {
            echo ', Rest ' . number_format($remainder, 0, ',', ' ') . ' wird auf die ersten Vereine verteilt';
        }
        echo '. Die Buchung erscheint anschließend in den Finanzen.';
    }

    echo '</div>';
}

function seasonRolloverRenderOverview($overview, $openMatchSummary = array(), $penaltyPreview = array()) {
    echo '<h3>Vorprüfung</h3>';
    echo '<table class="table table-striped table-bordered">';
    echo '<tbody>';

    $rows = array(
        'Offene Saisons' => (int) $overview['open_seasons'],
        'Beendbare Saisons' => (int) $overview['eligible_seasons'],
        'Ligen gesamt' => (int) $overview['leagues_total'],
        'Ligen ohne offene Saison' => (int) $overview['leagues_without_open_season'],
        'Unberechnete Ligaspiele' => (int) $overview['uncalculated_league_matches'],
        'Unberechnete Pokalspiele' => (int) $overview['uncalculated_cup_matches'],
        'Unberechnete Pflichtspiele gesamt' => (int) $overview['uncalculated_competitive_matches'],
        'Unberechnete Spiele gesamt' => (int) $overview['uncalculated_matches_total'],
        'Doppelte Team-Terminbuchungen am selben Tag' => (int) $overview['duplicate_team_bookings'],
        'Länder mit Teams' => (int) $overview['national_countries'],
        'UEFA-Länder' => (int) $overview['uefa_countries'],
        'Teams in UEFA-Temp' => (int) $overview['uefa_temp_teams']
    );

    foreach ($rows as $label => $value) {
        echo '<tr><th>' . escapeOutput($label) . '</th><td>' . escapeOutput($value) . '</td></tr>';
    }

    echo '<tr><th>Champions League vorhanden</th><td>' . seasonRolloverBoolLabel($overview['champions_league_exists']) . '</td></tr>';
    echo '<tr><th>Champions League Gruppenrunde vorhanden</th><td>' . seasonRolloverBoolLabel($overview['champions_league_group_round']) . '</td></tr>';
    echo '<tr><th>UEFA Euro League vorhanden</th><td>' . seasonRolloverBoolLabel($overview['uefa_league_exists']) . '</td></tr>';
    echo '<tr><th>UEFA Euro League Gruppenrunde vorhanden</th><td>' . seasonRolloverBoolLabel($overview['uefa_league_group_round']) . '</td></tr>';

    echo '</tbody>';
    echo '</table>';

    seasonRolloverRenderPenaltyPreview($penaltyPreview);

    if ((int) $overview['uncalculated_competitive_matches'] > 0) {
        echo '<div class="alert alert-error">';
        echo '<strong>Saisonwechsel gesperrt:</strong> Es gibt noch unberechnete Pflichtspiele. Saisons können nicht beendet und neue Saisons nicht erstellt werden, bis alle Liga- und Pokalspiele berechnet sind.';

        if (!empty($openMatchSummary)) {
            echo '<ul>';
            foreach ($openMatchSummary as $summary) {
                echo '<li>' . escapeOutput($summary['spieltyp']) . ': ' . (int) $summary['matches'] . ' offen (' . escapeOutput($summary['first_match']) . ' - ' . escapeOutput($summary['last_match']) . ')</li>';
            }
            echo '</ul>';
        }

        echo '</div>';
    }
}

function seasonRolloverRenderOptionsForm($site, $options, $step = 'validate') {
    $steps = array(
        'validate' => 'Nur prüfen',
        'end_seasons' => '1. Saisons beenden',
        'uefa_temp' => '2. UEFA-Plätze + Temp aktualisieren',
        'new_seasons' => '3. Neue Saisons erstellen',
        'national_cups' => '4. Nationale Pokale vorbereiten',
        'european_cups' => '5. Champions League / UEFA League vorbereiten',
        'league_schedules' => '6. Liga-Spielpläne erzeugen',
        'execute_all' => 'Alle Schritte ausführen'
    );

    echo '<form action="' . htmlspecialchars($_SERVER['PHP_SELF'], ENT_QUOTES, 'UTF-8') . '" method="post" class="form-horizontal">';
    echo '<input type="hidden" name="show" value="execute">';
    echo '<input type="hidden" name="site" value="' . escapeOutput($site) . '">';

    echo '<fieldset>';
    echo '<legend>Einstellungen</legend>';

    echo '<div class="control-group"><label class="control-label" for="season_year">Saisonjahr</label><div class="controls">';
    echo '<input type="number" id="season_year" name="season_year" value="' . (int) $options['season_year'] . '" min="1900" max="9999">';
    echo '<p class="help-block">Der Name wird automatisch als YYYY_01, YYYY_02, ... erzeugt.</p>';
    echo '</div></div>';

    echo '<div class="control-group"><label class="control-label" for="league_start_date">Liga-Start</label><div class="controls">';
    echo '<input type="text" id="league_start_date" name="league_start_date" value="' . escapeOutput($options['league_start_date']) . '">';
    echo '<p class="help-block">Freitag/Samstag/Sonntag, wöchentlicher Rhythmus.</p>';
    echo '</div></div>';

    echo '<div class="control-group"><label class="control-label" for="national_cup_start_date">Nationaler Pokal-Start</label><div class="controls">';
    echo '<input type="text" id="national_cup_start_date" name="national_cup_start_date" value="' . escapeOutput($options['national_cup_start_date']) . '">';
    echo '<p class="help-block">Dienstag.</p>';
    echo '</div></div>';

    echo '<div class="control-group"><label class="control-label" for="cl_start_date">Champions League-Start</label><div class="controls">';
    echo '<input type="text" id="cl_start_date" name="cl_start_date" value="' . escapeOutput($options['cl_start_date']) . '">';
    echo '<p class="help-block">Mittwoch.</p>';
    echo '</div></div>';

    echo '<div class="control-group"><label class="control-label" for="ul_start_date">UEFA League-Start</label><div class="controls">';
    echo '<input type="text" id="ul_start_date" name="ul_start_date" value="' . escapeOutput($options['ul_start_date']) . '">';
    echo '<p class="help-block">Donnerstag.</p>';
    echo '</div></div>';

    echo '<div class="control-group"><label class="control-label" for="league_rounds">Liga-Runden</label><div class="controls">';
    echo '<input type="number" id="league_rounds" name="league_rounds" value="' . (int) $options['league_rounds'] . '" min="1" max="4">';
    echo '</div></div>';

    echo '<h4>Saisonende-Optionen</h4>';

    echo '<div class="control-group"><label class="control-label" for="retirement_age">Karriereende ab Alter</label><div class="controls">';
    echo '<input type="number" id="retirement_age" name="retirement_age" value="' . (int) $options['retirement_age'] . '" min="0" max="99">';
    echo '</div></div>';

    echo '<div class="control-group"><label class="control-label" for="max_youth_age">Jugendspieler löschen ab Alter</label><div class="controls">';
    echo '<input type="number" id="max_youth_age" name="max_youth_age" value="' . (int) $options['max_youth_age'] . '" min="0" max="99">';
    echo '</div></div>';

    echo '<div class="control-group"><label class="control-label" for="popularity_reduction">Fanbeliebtheit-Abzug</label><div class="controls">';
    echo '<input type="number" id="popularity_reduction" name="popularity_reduction" value="' . (int) $options['popularity_reduction'] . '" min="0" max="100">';
    echo '</div></div>';

    echo '<div class="control-group"><label class="control-label" for="missed_penalty">Strafe bei Zielverfehlung</label><div class="controls">';
    echo '<input type="number" id="missed_penalty" name="missed_penalty" value="' . (int) $options['missed_penalty'] . '" min="0">';
    echo '</div></div>';

    echo '<div class="control-group"><label class="control-label" for="accomplish_reward">Prämie bei Zielerreichung</label><div class="controls">';
    echo '<input type="number" id="accomplish_reward" name="accomplish_reward" value="' . (int) $options['accomplish_reward'] . '" min="0">';
    echo '</div></div>';

    echo '<div class="control-group"><label class="control-label" for="fire_manager">Manager entlassen</label><div class="controls">';
    echo '<label class="checkbox"><input type="checkbox" id="fire_manager" name="fire_manager" value="1"' . (!empty($options['fire_manager']) ? ' checked="checked"' : '') . '> bei verfehltem Saisonziel</label>';
    echo '</div></div>';

    echo '</fieldset>';

    echo '<div class="form-actions">';
    echo '<select name="step">';
    foreach ($steps as $stepId => $label) {
        echo '<option value="' . escapeOutput($stepId) . '"' . ($step === $stepId ? ' selected="selected"' : '') . '>' . escapeOutput($label) . '</option>';
    }
    echo '</select> ';
    echo '<button type="submit" class="btn btn-primary">Ausführen</button>';
    echo '</div>';

    echo '</form>';
}

function seasonRolloverRenderResult($data, $level = 0) {
    if (!is_array($data)) {
        echo '<p>' . escapeOutput((string) $data) . '</p>';
        return;
    }

    echo '<table class="table table-striped table-bordered">';
    echo '<tbody>';

    foreach ($data as $key => $value) {
        echo '<tr>';
        echo '<th style="width: 260px;">' . escapeOutput((string) $key) . '</th>';
        echo '<td>';

        if (is_array($value)) {
            if (empty($value)) {
                echo '-';
            } elseif ($level < 2) {
                seasonRolloverRenderResult($value, $level + 1);
            } else {
                echo '<pre>' . escapeOutput(print_r($value, true)) . '</pre>';
            }
        } else {
            if (is_bool($value)) {
                echo $value ? 'ja' : 'nein';
            } else {
                echo escapeOutput((string) $value);
            }
        }

        echo '</td>';
        echo '</tr>';
    }

    echo '</tbody>';
    echo '</table>';
}

$options = SeasonRolloverDataService::readOptionsFromRequest($_REQUEST);
$overview = SeasonRolloverValidationService::getOverview($website, $db);
$openMatchSummary = SeasonRolloverValidationService::getOpenCompetitiveMatchesSummary($website, $db);
$transferPenaltyPreview = class_exists('TransferPenaltyDataService')
    ? TransferPenaltyDataService::getDistributionPreview($website, $db)
    : array();

if (!$show) {
    echo '<p>Dieser Assistent führt den kompletten Saisonwechsel kontrolliert und schrittweise aus. Bestehende Einzel-Tools bleiben erhalten, aber dieser Ablauf ist die sichere Standard-Variante.</p>';
    seasonRolloverRenderOverview($overview, $openMatchSummary, $transferPenaltyPreview);
    seasonRolloverRenderOptionsForm($site, $options);

} elseif ($show === 'execute') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method.');
    }

    if ($admin['r_demo']) {
        echo createErrorMessage($i18n->getMessage('alert_error_title'), $i18n->getMessage('validationerror_no_changes_as_demo'));
    } else {
        $errors = SeasonRolloverDataService::validateOptions($options);

        if (!empty($errors)) {
            echo createErrorMessage($i18n->getMessage('alert_error_title'), implode('<br>', array_map('escapeOutput', $errors)));
        } elseif ($step === 'validate') {
            $blockingErrors = SeasonRolloverValidationService::getBlockingErrorsForStep($website, $db, 'end_seasons');
            if (!empty($blockingErrors)) {
                echo createErrorMessage('Prüfung abgeschlossen: Saisonwechsel gesperrt', implode('<br>', array_map('escapeOutput', $blockingErrors)));
            } else {
                echo createSuccessMessage('Prüfung abgeschlossen', 'Es wurden keine ungültigen Eingaben gefunden. Der Saisonwechsel ist aus Sicht offener Pflichtspiele freigegeben.');
            }
        } else {
            try {
                if ($step === 'execute_all') {
                    $result = SeasonRolloverDataService::executeAll($website, $db, $i18n, $options);
                } else {
                    $result = SeasonRolloverDataService::executeStep($website, $db, $i18n, $step, $options);
                }

                echo createSuccessMessage($i18n->getMessage('alert_save_success'), '');
                seasonRolloverRenderResult($result);
            } catch (Exception $e) {
                echo createErrorMessage($i18n->getMessage('alert_error_title'), $e->getMessage());
            }
        }
    }

    $overview = SeasonRolloverValidationService::getOverview($website, $db);
    $openMatchSummary = SeasonRolloverValidationService::getOpenCompetitiveMatchesSummary($website, $db);
    $transferPenaltyPreview = class_exists('TransferPenaltyDataService')
        ? TransferPenaltyDataService::getDistributionPreview($website, $db)
        : array();
    seasonRolloverRenderOverview($overview, $openMatchSummary, $transferPenaltyPreview);
    seasonRolloverRenderOptionsForm($site, $options, $step);

} else {
    throw new Exception('Invalid request.');
}
?>
