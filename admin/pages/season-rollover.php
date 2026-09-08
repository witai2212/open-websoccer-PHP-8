<?php
/******************************************************

  Season rollover wizard for OpenWebSoccer-Sim.
  CM23 Task 1026 | 08.09.2026 | Revision 3

******************************************************/

$mainTitle = $i18n->hasMessage('seasonrollover_navlabel')
    ? $i18n->getMessage('seasonrollover_navlabel')
    : 'Saisonwechsel-Assistent';

echo '<h1>' . escapeOutput($mainTitle) . '</h1>';

if (!$admin['r_admin'] && !$admin['r_demo'] && !$admin[$page['permissionrole']]) {
    throw new Exception($i18n->getMessage('error_access_denied'));
}

ignore_user_abort(true);
set_time_limit(0);

function seasonRolloverStatusLabel($status) {
    switch ($status) {
        case 'done':
        case 'completed':
            return '<span class="label label-success">✓ erledigt</span>';
        case 'running':
            return '<span class="label label-info">läuft</span>';
        case 'in_progress':
            return '<span class="label label-info">in Bearbeitung</span>';
        case 'error':
            return '<span class="label label-important">Fehler</span>';
        case 'restored':
            return '<span class="label">wiederhergestellt</span>';
        case 'ready':
            return '<span class="label label-warning">bereit</span>';
        default:
            return '<span class="label">noch offen</span>';
    }
}

function seasonRolloverFormatBytes($bytes) {
    $bytes = max(0, (int) $bytes);
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2, ',', '.') . ' GB';
    }
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2, ',', '.') . ' MB';
    }
    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 1, ',', '.') . ' KB';
    }
    return $bytes . ' B';
}

function seasonRolloverRenderPenaltyPreview($penaltyPreview) {
    if (!is_array($penaltyPreview) || !isset($penaltyPreview['penalty_pool'])) {
        return;
    }

    $pool = (int) $penaltyPreview['penalty_pool'];
    $teams = (int) $penaltyPreview['managed_teams'];
    $baseAmount = (int) $penaltyPreview['base_amount'];
    $remainder = (int) $penaltyPreview['remainder'];

    echo '<div class="alert alert-info"><strong>Transferstrafen-Ausschüttung:</strong> ';
    if ($pool <= 0) {
        echo 'Der Strafentopf ist aktuell leer.';
    } elseif ($teams <= 0) {
        echo 'Im Strafentopf liegen ' . number_format($pool, 0, ',', ' ') . ', aber es gibt aktuell keine user-geführten Vereine für eine Ausschüttung.';
    } else {
        echo number_format($pool, 0, ',', ' ') . ' werden beim Saisonwechsel an ' . $teams . ' user-geführte Vereine verteilt. ';
        echo 'Voraussichtlich ' . number_format($baseAmount, 0, ',', ' ') . ' pro Verein';
        if ($remainder > 0) {
            echo ', Rest ' . number_format($remainder, 0, ',', ' ') . ' wird auf die ersten Vereine verteilt';
        }
        echo '.';
    }
    echo '</div>';
}

function seasonRolloverRenderOverview($overview, $openMatchSummary, $penaltyPreview) {
    echo '<h3>Vorprüfung</h3>';
    echo '<table class="table table-striped table-bordered"><tbody>';
    $rows = array(
        'Offene Saisons' => (int) $overview['open_seasons'],
        'Beendbare Saisons' => (int) $overview['eligible_seasons'],
        'Ligen gesamt' => (int) $overview['leagues_total'],
        'Ligen ohne offene Saison' => (int) $overview['leagues_without_open_season'],
        'Unberechnete Ligaspiele' => (int) $overview['uncalculated_league_matches'],
        'Unberechnete Pokalspiele' => (int) $overview['uncalculated_cup_matches'],
        'Unberechnete Pflichtspiele gesamt' => (int) $overview['uncalculated_competitive_matches'],
        'Doppelte Team-Terminbuchungen am selben Tag' => (int) $overview['duplicate_team_bookings'],
        'Aktive Mutterverein-Divisionskonflikte' => (int) $overview['parent_club_division_conflicts']
    );
    foreach ($rows as $label => $value) {
        echo '<tr><th>' . escapeOutput($label) . '</th><td>' . escapeOutput($value) . '</td></tr>';
    }
    echo '</tbody></table>';

    seasonRolloverRenderPenaltyPreview($penaltyPreview);

    if ((int) $overview['uncalculated_competitive_matches'] > 0) {
        echo '<div class="alert alert-error"><strong>Saisonwechsel gesperrt:</strong> Es gibt noch unberechnete Pflichtspiele.';
        if (!empty($openMatchSummary)) {
            echo '<ul>';
            foreach ($openMatchSummary as $summary) {
                echo '<li>' . escapeOutput($summary['spieltyp']) . ': ' . (int) $summary['matches'] . ' offen (' . escapeOutput($summary['first_match']) . ' – ' . escapeOutput($summary['last_match']) . ')</li>';
            }
            echo '</ul>';
        }
        echo '</div>';
    }
}

function seasonRolloverRenderOptionsForm($site, array $options) {
    echo '<form action="' . htmlspecialchars($_SERVER['PHP_SELF'], ENT_QUOTES, 'UTF-8') . '" method="post" class="form-horizontal">';
    echo '<input type="hidden" name="site" value="' . escapeOutput($site) . '">';

    echo '<div class="alert alert-info">';
    echo '<strong>Kontrollierter Saisonwechsel:</strong> Beim Start wird zuerst automatisch ein Datenbank-Backup erstellt. Danach wird immer nur genau der nächste Schritt ausgeführt und muss vom Administrator einzeln bestätigt werden. Der letzte Schritt verarbeitet höchstens fünf Ligen pro Request.';
    echo '</div>';

    echo '<fieldset><legend>Einstellungen</legend>';
    echo '<div class="control-group"><label class="control-label" for="season_year">Saisonjahr</label><div class="controls"><input type="number" id="season_year" name="season_year" value="' . (int) $options['season_year'] . '" min="1900" max="9999"></div></div>';
    echo '<div class="control-group"><label class="control-label" for="league_start_date">Liga-Start</label><div class="controls"><input type="text" id="league_start_date" name="league_start_date" value="' . escapeOutput($options['league_start_date']) . '"></div></div>';
    echo '<div class="control-group"><label class="control-label" for="national_cup_start_date">Nationaler Pokal-Start</label><div class="controls"><input type="text" id="national_cup_start_date" name="national_cup_start_date" value="' . escapeOutput($options['national_cup_start_date']) . '"></div></div>';
    echo '<div class="control-group"><label class="control-label" for="cl_start_date">Champions League-Start</label><div class="controls"><input type="text" id="cl_start_date" name="cl_start_date" value="' . escapeOutput($options['cl_start_date']) . '"></div></div>';
    echo '<div class="control-group"><label class="control-label" for="ul_start_date">UEFA League-Start</label><div class="controls"><input type="text" id="ul_start_date" name="ul_start_date" value="' . escapeOutput($options['ul_start_date']) . '"></div></div>';
    echo '<div class="control-group"><label class="control-label" for="conmebol_lib_start_date">Copa Libertadores-Start</label><div class="controls"><input type="text" id="conmebol_lib_start_date" name="conmebol_lib_start_date" value="' . escapeOutput($options['conmebol_lib_start_date']) . '"></div></div>';
    echo '<div class="control-group"><label class="control-label" for="conmebol_sud_start_date">Copa Sudamericana-Start</label><div class="controls"><input type="text" id="conmebol_sud_start_date" name="conmebol_sud_start_date" value="' . escapeOutput($options['conmebol_sud_start_date']) . '"></div></div>';
    echo '<div class="control-group"><label class="control-label" for="concacaf_start_date">CONCACAF-Start</label><div class="controls"><input type="text" id="concacaf_start_date" name="concacaf_start_date" value="' . escapeOutput($options['concacaf_start_date']) . '"></div></div>';
    echo '<div class="control-group"><label class="control-label" for="league_rounds">Liga-Runden</label><div class="controls"><input type="number" id="league_rounds" name="league_rounds" value="' . (int) $options['league_rounds'] . '" min="1" max="4"></div></div>';

    echo '<h4>Saisonende-Optionen</h4>';
    echo '<div class="control-group"><label class="control-label" for="retirement_age">Karriereende ab Alter</label><div class="controls"><input type="number" id="retirement_age" name="retirement_age" value="' . (int) $options['retirement_age'] . '" min="0" max="99"></div></div>';
    echo '<div class="control-group"><label class="control-label" for="max_youth_age">Jugendspieler freigeben ab Alter</label><div class="controls"><input type="number" id="max_youth_age" name="max_youth_age" value="' . (int) $options['max_youth_age'] . '" min="0" max="99"></div></div>';
    echo '<div class="control-group"><label class="control-label" for="popularity_reduction">Fanbeliebtheit-Abzug</label><div class="controls"><input type="number" id="popularity_reduction" name="popularity_reduction" value="' . (int) $options['popularity_reduction'] . '" min="0" max="100"></div></div>';
    echo '<div class="control-group"><label class="control-label" for="missed_penalty">Strafe bei Zielverfehlung</label><div class="controls"><input type="number" id="missed_penalty" name="missed_penalty" value="' . (int) $options['missed_penalty'] . '" min="0"></div></div>';
    echo '<div class="control-group"><label class="control-label" for="accomplish_reward">Prämie bei Zielerreichung</label><div class="controls"><input type="number" id="accomplish_reward" name="accomplish_reward" value="' . (int) $options['accomplish_reward'] . '" min="0"></div></div>';
    echo '<div class="control-group"><label class="control-label" for="fire_manager">Manager entlassen</label><div class="controls"><label class="checkbox"><input type="checkbox" id="fire_manager" name="fire_manager" value="1"' . (!empty($options['fire_manager']) ? ' checked="checked"' : '') . '> bei verfehltem Saisonziel</label></div></div>';
    echo '</fieldset>';

    echo '<div class="form-actions">';
    echo '<button type="submit" name="action" value="validate" class="btn">Nur prüfen</button> ';
    echo '<button type="submit" name="action" value="start_run" class="btn btn-primary">Saisonwechsel starten + Backup erstellen</button>';
    echo '</div></form>';
}

function seasonRolloverRenderRun($site, array $run) {
    $steps = SeasonRolloverWorkflowService::getSteps();
    $stepIds = array_keys($steps);
    $currentIndex = (int) $run['current_step_index'];
    $currentStepId = isset($stepIds[$currentIndex]) ? $stepIds[$currentIndex] : null;

    echo '<h3>Aktueller Saisonwechsel-Lauf</h3>';
    echo '<table class="table table-bordered"><tbody>';
    echo '<tr><th style="width:240px">Lauf-ID</th><td><code>' . escapeOutput($run['id']) . '</code></td></tr>';
    echo '<tr><th>Status</th><td>' . seasonRolloverStatusLabel($run['status']) . '</td></tr>';
    echo '<tr><th>Gestartet</th><td>' . escapeOutput(SeasonRolloverWorkflowService::formatTimestamp($run['created_at'])) . '</td></tr>';
    if (!empty($run['finished_at'])) {
        echo '<tr><th>Abgeschlossen</th><td>' . escapeOutput(SeasonRolloverWorkflowService::formatTimestamp($run['finished_at'])) . '</td></tr>';
    }
    if (!empty($run['backup'])) {
        echo '<tr><th>Backup</th><td><code>' . escapeOutput($run['backup']['id']) . '</code> · ' . escapeOutput(seasonRolloverFormatBytes($run['backup']['size'])) . ' · SHA-256 <code>' . escapeOutput(substr($run['backup']['sha256'], 0, 16)) . '…</code></td></tr>';
    }
    echo '</tbody></table>';

    echo '<table class="table table-striped table-bordered">';
    echo '<thead><tr><th>Schritt</th><th>Status</th><th>Fortschritt / Ergebnis</th><th>Fehler</th></tr></thead><tbody>';
    foreach ($steps as $stepId => $label) {
        $step = $run['steps'][$stepId];
        echo '<tr>';
        echo '<td>' . escapeOutput($label) . '</td>';
        echo '<td>' . seasonRolloverStatusLabel($step['status']) . '</td>';
        echo '<td>';
        if ($stepId === 'league_schedules' && !empty($step['progress'])) {
            $progress = $step['progress'];
            echo (int) $progress['processed'] . ' von ' . (int) $progress['total'] . ' Ligen verarbeitet';
            echo '<br><small>max. ' . (int) $progress['batch_size'] . ' je Request · bisher ' . (int) $progress['created_matches'] . ' Ligaspiele erstellt</small>';
        } elseif (!empty($step['finished_at'])) {
            echo 'beendet: ' . escapeOutput(SeasonRolloverWorkflowService::formatTimestamp($step['finished_at']));
        } else {
            echo '-';
        }
        echo '</td>';
        echo '<td>' . (!empty($step['error']) ? escapeOutput($step['error']) : '-') . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';

    if ($run['status'] === 'ready' && $currentStepId !== null) {
        $step = $run['steps'][$currentStepId];
        $buttonText = ($currentStepId === 'league_schedules' && $step['status'] === 'in_progress')
            ? 'Weiter – nächste max. 5 Ligen erzeugen'
            : 'Bestätigen und ausführen: ' . $steps[$currentStepId];

        echo '<div class="alert alert-warning"><strong>Nächster Schritt:</strong> ' . escapeOutput($steps[$currentStepId]) . '. Es wird ausschließlich dieser Schritt ausgeführt.</div>';
        echo '<form action="' . htmlspecialchars($_SERVER['PHP_SELF'], ENT_QUOTES, 'UTF-8') . '" method="post">';
        echo '<input type="hidden" name="site" value="' . escapeOutput($site) . '">';
        echo '<input type="hidden" name="action" value="execute_next">';
        echo '<input type="hidden" name="run_id" value="' . escapeOutput($run['id']) . '">';
        echo '<input type="hidden" name="confirm_step" value="1">';
        echo '<button type="submit" class="btn btn-primary">' . escapeOutput($buttonText) . '</button>';
        echo '</form>';
    } elseif ($run['status'] === 'error') {
        echo '<div class="alert alert-error"><strong>Der Ablauf wurde gestoppt.</strong> Es werden keine weiteren Schritte automatisch ausgeführt. Prüfe das Log; bei Bedarf kann das vor dem Lauf erzeugte Backup wiederhergestellt werden.</div>';
    } elseif ($run['status'] === 'completed') {
        echo '<div class="alert alert-success"><strong>Saisonwechsel abgeschlossen.</strong> Alle sechs Schritte wurden kontrolliert und protokolliert ausgeführt.</div>';
    } elseif ($run['status'] === 'running') {
        echo '<div class="alert alert-warning"><strong>Schritt als laufend gespeichert.</strong> Falls kein Request mehr aktiv ist, sollte der Zustand geprüft und im Zweifel das Backup wiederhergestellt werden.</div>';
    } elseif ($run['status'] === 'restored') {
        echo '<div class="alert"><strong>Backup wiederhergestellt.</strong> Dieser Lauf wird nicht weiter ausgeführt.</div>';
    }
}

function seasonRolloverRenderLog(array $run) {
    $entries = SeasonRolloverWorkflowService::getLogEntries($run['id'], 100);
    echo '<h3>Protokoll</h3>';
    if (empty($entries)) {
        echo '<p>Noch keine Protokolleinträge.</p>';
        return;
    }

    echo '<table class="table table-striped table-condensed table-bordered">';
    echo '<thead><tr><th>Zeitpunkt</th><th>Schritt</th><th>Status</th><th>Meldung</th><th>Details</th></tr></thead><tbody>';
    foreach (array_reverse($entries) as $entry) {
        $context = !empty($entry['context']) ? json_encode($entry['context'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '-';
        echo '<tr>';
        echo '<td>' . escapeOutput(SeasonRolloverWorkflowService::formatTimestamp($entry['timestamp'])) . '</td>';
        echo '<td>' . escapeOutput($entry['step']) . '</td>';
        echo '<td>' . escapeOutput($entry['status']) . '</td>';
        echo '<td>' . escapeOutput($entry['message']) . '</td>';
        echo '<td><small>' . escapeOutput($context) . '</small></td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
}

function seasonRolloverRenderBackups($site, array $backups) {
    echo '<h3>Datenbank-Backups</h3>';
    if (empty($backups)) {
        echo '<p>Noch kein Saisonwechsel-Backup vorhanden.</p>';
        return;
    }

    echo '<table class="table table-striped table-bordered"><thead><tr><th>Backup-ID</th><th>Erstellt</th><th>Größe</th><th>Tabellen</th><th>Aktion</th></tr></thead><tbody>';
    foreach ($backups as $backup) {
        echo '<tr>';
        echo '<td><code>' . escapeOutput($backup['id']) . '</code></td>';
        echo '<td>' . escapeOutput(SeasonRolloverWorkflowService::formatTimestamp($backup['created_at'])) . '</td>';
        echo '<td>' . escapeOutput(seasonRolloverFormatBytes($backup['size'])) . '</td>';
        echo '<td>' . (int) $backup['table_count'] . '</td>';
        echo '<td>';
        echo '<form action="' . htmlspecialchars($_SERVER['PHP_SELF'], ENT_QUOTES, 'UTF-8') . '" method="post" style="margin:0">';
        echo '<input type="hidden" name="site" value="' . escapeOutput($site) . '">';
        echo '<input type="hidden" name="action" value="prepare_restore">';
        echo '<input type="hidden" name="backup_id" value="' . escapeOutput($backup['id']) . '">';
        echo '<button type="submit" class="btn btn-danger btn-small">Wiederherstellen…</button>';
        echo '</form>';
        echo '</td></tr>';
    }
    echo '</tbody></table>';
}

function seasonRolloverRenderRestoreConfirmation($site, $backupId) {
    echo '<div class="alert alert-error">';
    echo '<h4>Backup wirklich wiederherstellen?</h4>';
    echo '<p>Die aktuelle Datenbank mit dem CM23-Präfix wird auf den Stand des Backups <code>' . escapeOutput($backupId) . '</code> zurückgesetzt. Vor der Wiederherstellung wird automatisch noch ein Sicherheits-Backup des aktuellen Zustands erstellt.</p>';
    echo '<form action="' . htmlspecialchars($_SERVER['PHP_SELF'], ENT_QUOTES, 'UTF-8') . '" method="post">';
    echo '<input type="hidden" name="site" value="' . escapeOutput($site) . '">';
    echo '<input type="hidden" name="action" value="restore_backup">';
    echo '<input type="hidden" name="backup_id" value="' . escapeOutput($backupId) . '">';
    echo '<label class="checkbox"><input type="checkbox" name="confirm_restore" value="1" required> Ja, ich möchte die Datenbank auf dieses Backup zurücksetzen.</label>';
    echo '<button type="submit" class="btn btn-danger">Backup jetzt wiederherstellen</button>';
    echo '</form></div>';
}

$action = isset($_REQUEST['action'])
    ? preg_replace('/[^a-z_]/', '', (string) $_REQUEST['action'])
    : '';
$options = SeasonRolloverDataService::readOptionsFromRequest($_REQUEST);
$restoreCandidate = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $admin['r_demo']) {
    echo createErrorMessage($i18n->getMessage('alert_error_title'), $i18n->getMessage('validationerror_no_changes_as_demo'));
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if ($action === 'validate') {
            $errors = SeasonRolloverDataService::validateOptions($options);
            $errors = array_merge($errors, SeasonRolloverValidationService::getBlockingErrorsForStep($website, $db, 'end_seasons'));
            if (!empty($errors)) {
                echo createErrorMessage('Prüfung abgeschlossen: Saisonwechsel gesperrt', implode('<br>', array_map('escapeOutput', $errors)));
            } else {
                echo createSuccessMessage('Prüfung abgeschlossen', 'Die Eingaben sind gültig und der Saisonwechsel kann gestartet werden. Beim Start wird zuerst ein Datenbank-Backup erstellt.');
            }
        } elseif ($action === 'start_run') {
            $run = SeasonRolloverWorkflowService::createRun(
                $website,
                $db,
                $options,
                isset($admin['id']) ? (int) $admin['id'] : 0
            );
            echo createSuccessMessage('Saisonwechsel vorbereitet', 'Backup ' . escapeOutput($run['backup']['id']) . ' wurde erstellt. Bitte nun Schritt 1 einzeln bestätigen.');
        } elseif ($action === 'execute_next') {
            $runId = isset($_POST['run_id']) ? (string) $_POST['run_id'] : '';
            $confirmed = !empty($_POST['confirm_step']);
            $run = SeasonRolloverWorkflowService::executeNextStep($website, $db, $i18n, $runId, $confirmed);
            if ($run['status'] === 'completed') {
                echo createSuccessMessage('Saisonwechsel abgeschlossen', 'Alle Schritte wurden erfolgreich ausgeführt.');
            } else {
                echo createSuccessMessage('Schritt abgeschlossen', 'Der aktuelle Fortschritt wurde gespeichert. Vor dem nächsten Schritt ist erneut eine Bestätigung erforderlich.');
            }
        } elseif ($action === 'prepare_restore') {
            $restoreCandidate = isset($_POST['backup_id']) ? (string) $_POST['backup_id'] : '';
        } elseif ($action === 'restore_backup') {
            if (empty($_POST['confirm_restore'])) {
                throw new Exception('Die Wiederherstellung wurde nicht bestätigt.');
            }
            $backupId = isset($_POST['backup_id']) ? (string) $_POST['backup_id'] : '';
            $restoreResult = SeasonRolloverWorkflowService::restoreBackup($website, $db, $backupId);
            echo createSuccessMessage(
                'Backup wiederhergestellt',
                'Datenbank auf Backup ' . escapeOutput($restoreResult['restored_backup']['id']) . ' zurückgesetzt. Sicherheits-Backup des vorherigen Zustands: ' . escapeOutput($restoreResult['safety_backup']['id']) . '.'
            );
        }
    } catch (Exception $e) {
        echo createErrorMessage($i18n->getMessage('alert_error_title'), escapeOutput($e->getMessage()));
    }
}

$overview = SeasonRolloverValidationService::getOverview($website, $db);
$openMatchSummary = SeasonRolloverValidationService::getOpenCompetitiveMatchesSummary($website, $db);
$transferPenaltyPreview = class_exists('TransferPenaltyDataService')
    ? TransferPenaltyDataService::getDistributionPreview($website, $db)
    : array();

seasonRolloverRenderOverview($overview, $openMatchSummary, $transferPenaltyPreview);

$requestedRunId = isset($_REQUEST['run_id']) ? (string) $_REQUEST['run_id'] : '';
$activeRun = $requestedRunId !== ''
    ? SeasonRolloverWorkflowService::loadRun($requestedRunId)
    : SeasonRolloverWorkflowService::getLatestRun();

if ($activeRun) {
    seasonRolloverRenderRun($site, $activeRun);
    seasonRolloverRenderLog($activeRun);
}

if (!$activeRun || in_array($activeRun['status'], array('completed', 'restored', 'error'), true)) {
    echo '<h3>Neuen Saisonwechsel vorbereiten</h3>';
    seasonRolloverRenderOptionsForm($site, $options);
}

$backups = SeasonRolloverWorkflowService::listBackups(10);
seasonRolloverRenderBackups($site, $backups);

if ($restoreCandidate !== null && $restoreCandidate !== '') {
    seasonRolloverRenderRestoreConfirmation($site, $restoreCandidate);
}
?>
