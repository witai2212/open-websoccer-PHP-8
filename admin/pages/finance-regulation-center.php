<?php
/******************************************************

  Finance Regulation Center for CM23 / OpenWebSoccer-Sim.

******************************************************/

if (!$admin['r_admin'] && !$admin['r_demo']) {
    echo '<p>' . $i18n->getMessage('error_access_denied') . '</p>';
    exit;
}

function frcMsg($i18n, $key, $fallback) {
    return $i18n->hasMessage($key) ? $i18n->getMessage($key) : $fallback;
}

function frcCurrency($amount) {
    return number_format((int) round($amount), 0, ',', ' ') . ' EUR';
}

function frcPercent($ratio) {
    return number_format(((float) $ratio) * 100, 1, ',', ' ') . '%';
}

function frcNumber($value, $decimals = 1) {
    return number_format((float) $value, $decimals, ',', ' ');
}

function frcBadgeClass($severity) {
    if ($severity == 'danger') {
        return 'important';
    }
    if ($severity == 'warning') {
        return 'warning';
    }
    if ($severity == 'success') {
        return 'success';
    }
    return 'info';
}

function frcModeLabel($mode) {
    if ($mode == 'apply') return 'Angewendet';
    if ($mode == 'export') return 'Export';
    if ($mode == 'snapshot') return 'Snapshot';
    return 'Simulation';
}

function frcTabUrl($site, $tab, $seasonId) {
    return 'index.php?site=' . escapeOutput($site) . '&tab=' . escapeOutput($tab) . '&season_id=' . (int) $seasonId;
}

function frcCheckedTarget($params, $target, $defaultChecked = FALSE) {
    if (!isset($params['targets']) || !is_array($params['targets']) || !count($params['targets'])) {
        return $defaultChecked ? ' checked="checked"' : '';
    }
    return in_array($target, $params['targets']) ? ' checked="checked"' : '';
}

function frcRenderEffectTable($i18n, $result) {
    if (!$result || ((!isset($result['effects']) || !count($result['effects'])) && (!isset($result['updates']) || !count($result['updates'])))) {
        return;
    }
    $rows = isset($result['updates']) ? $result['updates'] : $result['effects'];
    ?>
    <table class="table table-striped table-bordered table-condensed">
        <thead>
            <tr>
                <th><?php echo frcMsg($i18n, 'finance_regulation_target', 'Ziel'); ?></th>
                <th><?php echo frcMsg($i18n, 'finance_regulation_table', 'Tabelle'); ?></th>
                <th><?php echo frcMsg($i18n, 'finance_regulation_old_total', 'Alt'); ?></th>
                <th><?php echo frcMsg($i18n, 'finance_regulation_new_total', 'Neu'); ?></th>
                <th><?php echo frcMsg($i18n, 'finance_regulation_delta', 'Differenz'); ?></th>
                <th><?php echo frcMsg($i18n, 'finance_regulation_affected_rows', 'Datensätze'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $row) { ?>
                <tr<?php if (!empty($row['skipped'])) echo ' class="muted"'; ?>>
                    <td><?php echo escapeOutput($row['label']); ?></td>
                    <td><?php echo escapeOutput($row['table']); ?><br><small><?php echo escapeOutput($row['columns']); ?></small></td>
                    <td><?php echo frcCurrency($row['old_total']); ?></td>
                    <td><?php echo frcCurrency($row['new_total']); ?></td>
                    <td><?php echo ($row['delta'] >= 0 ? '+' : '') . frcCurrency($row['delta']); ?></td>
                    <td><?php echo !empty($row['skipped']) ? '<span class="label">übersprungen</span>' : (int) $row['affected_rows']; ?></td>
                </tr>
            <?php } ?>
        </tbody>
    </table>
    <?php
}

function frcRenderCorrectionForm($i18n, $site, $tab, $seasonId, $params, $mode) {
    $isApply = ($mode == 'apply');
    ?>
    <form method="post" action="index.php" class="form-horizontal"<?php if ($isApply) { ?> onsubmit="return confirm('<?php echo escapeOutput(frcMsg($i18n, 'finance_regulation_apply_confirm', 'Diese Korrektur ändert echte Datenbankwerte. Vorher wird automatisch ein Snapshot erstellt. Fortfahren?')); ?>');"<?php } ?>>
        <input type="hidden" name="site" value="<?php echo escapeOutput($site); ?>">
        <input type="hidden" name="tab" value="<?php echo escapeOutput($tab); ?>">
        <input type="hidden" name="season_id" value="<?php echo (int) $seasonId; ?>">
        <input type="hidden" name="frc_action" value="<?php echo $isApply ? 'apply' : 'simulate'; ?>">

        <fieldset>
            <legend><?php echo frcMsg($i18n, 'finance_regulation_form_title', 'Korrekturwerte'); ?></legend>

            <div class="control-group">
                <label class="control-label"><input type="checkbox" name="targets[]" value="player_salaries"<?php echo frcCheckedTarget($params, 'player_salaries', TRUE); ?>> <?php echo frcMsg($i18n, 'finance_regulation_player_salaries', 'Spielergehälter'); ?></label>
                <div class="controls">
                    <div class="input-append"><input class="input-small" type="text" name="player_salary_percent" value="<?php echo escapeOutput($params['player_salary_percent']); ?>"><span class="add-on">%</span></div>
                    <span class="help-inline"><?php echo frcMsg($i18n, 'finance_regulation_player_salaries_help', 'Gilt für alle aktiven Spieler aller Vereine.'); ?></span>
                </div>
            </div>

            <div class="control-group">
                <label class="control-label"><input type="checkbox" name="targets[]" value="secondary_costs"<?php echo frcCheckedTarget($params, 'secondary_costs', FALSE); ?>> <?php echo frcMsg($i18n, 'finance_regulation_secondary_costs', 'Staff & Nebenkosten'); ?></label>
                <div class="controls">
                    <div class="input-append"><input class="input-small" type="text" name="secondary_cost_percent" value="<?php echo escapeOutput($params['secondary_cost_percent']); ?>"><span class="add-on">%</span></div>
                    <span class="help-inline"><?php echo frcMsg($i18n, 'finance_regulation_secondary_costs_help', 'Staff, Scouts, Scouting, Jugendakademie, Trainingslager, Stadionbau, Merchandising-Einkauf.'); ?></span>
                </div>
            </div>

            <div class="control-group">
                <label class="control-label"><input type="checkbox" name="targets[]" value="sponsors"<?php echo frcCheckedTarget($params, 'sponsors', FALSE); ?>> <?php echo frcMsg($i18n, 'finance_regulation_sponsors', 'Sponsoren'); ?></label>
                <div class="controls">
                    <div class="input-append"><input class="input-small" type="text" name="sponsor_percent" value="<?php echo escapeOutput($params['sponsor_percent']); ?>"><span class="add-on">%</span></div>
                    <select name="sponsor_scope" class="input-medium">
                        <option value="future"<?php if ($params['sponsor_scope'] == 'future') echo ' selected="selected"'; ?>><?php echo frcMsg($i18n, 'finance_regulation_sponsor_scope_future', 'nur künftige Angebote'); ?></option>
                        <option value="active"<?php if ($params['sponsor_scope'] == 'active') echo ' selected="selected"'; ?>><?php echo frcMsg($i18n, 'finance_regulation_sponsor_scope_active', 'nur aktive Verträge'); ?></option>
                        <option value="both"<?php if ($params['sponsor_scope'] == 'both') echo ' selected="selected"'; ?>><?php echo frcMsg($i18n, 'finance_regulation_sponsor_scope_both', 'beides'); ?></option>
                    </select>
                </div>
            </div>

            <div class="control-group">
                <label class="control-label"><input type="checkbox" name="targets[]" value="club_budgets"<?php echo frcCheckedTarget($params, 'club_budgets', FALSE); ?>> <?php echo frcMsg($i18n, 'finance_regulation_club_budgets', 'Vereinsbudgets'); ?></label>
                <div class="controls">
                    <div class="input-append"><input class="input-small" type="text" name="budget_percent" value="<?php echo escapeOutput($params['budget_percent']); ?>"><span class="add-on">%</span></div>
                    <select name="budget_scope" class="input-medium">
                        <option value="above_threshold"<?php if ($params['budget_scope'] == 'above_threshold') echo ' selected="selected"'; ?>><?php echo frcMsg($i18n, 'finance_regulation_budget_scope_above', 'nur ab Schwelle'); ?></option>
                        <option value="all"<?php if ($params['budget_scope'] == 'all') echo ' selected="selected"'; ?>><?php echo frcMsg($i18n, 'finance_regulation_budget_scope_all', 'alle'); ?></option>
                        <option value="human"<?php if ($params['budget_scope'] == 'human') echo ' selected="selected"'; ?>><?php echo frcMsg($i18n, 'finance_regulation_budget_scope_human', 'nur Human'); ?></option>
                        <option value="cpu"<?php if ($params['budget_scope'] == 'cpu') echo ' selected="selected"'; ?>><?php echo frcMsg($i18n, 'finance_regulation_budget_scope_cpu', 'nur CPU'); ?></option>
                    </select>
                    <span class="help-inline"><?php echo frcMsg($i18n, 'finance_regulation_budget_threshold', 'Schwelle'); ?></span>
                    <input class="input-medium" type="text" name="budget_threshold" value="<?php echo (int) $params['budget_threshold']; ?>">
                </div>
            </div>

            <div class="control-group">
                <label class="control-label"><input type="checkbox" name="targets[]" value="ticket_prices"<?php echo frcCheckedTarget($params, 'ticket_prices', FALSE); ?>> <?php echo frcMsg($i18n, 'finance_regulation_ticket_prices', 'Ticketpreise'); ?></label>
                <div class="controls">
                    <div class="input-append"><input class="input-small" type="text" name="ticket_percent" value="<?php echo escapeOutput($params['ticket_percent']); ?>"><span class="add-on">%</span></div>
                    <span class="help-inline"><?php echo frcMsg($i18n, 'finance_regulation_ticket_prices_help', 'Ändert die Preisbasis der Vereine, nicht rückwirkend alte Stadionlogs.'); ?></span>
                </div>
            </div>

            <div class="control-group">
                <label class="control-label"><input type="checkbox" name="targets[]" value="market_values"<?php echo frcCheckedTarget($params, 'market_values', FALSE); ?>> <?php echo frcMsg($i18n, 'finance_regulation_market_values', 'Marktwert-Formel'); ?></label>
                <div class="controls">
                    <div class="input-append"><input class="input-small" type="text" name="market_value_percent" value="<?php echo escapeOutput($params['market_value_percent']); ?>"><span class="add-on">%</span></div>
                    <span class="help-inline"><?php echo frcMsg($i18n, 'finance_regulation_market_values_help', 'Setzt einen globalen Formel-Faktor und skaliert bestehende Marktwerte.'); ?></span>
                </div>
            </div>

            <?php if ($isApply) { ?>
                <div class="control-group">
                    <div class="controls">
                        <label class="checkbox"><input type="checkbox" name="confirm_apply" value="1" required="required"> <?php echo frcMsg($i18n, 'finance_regulation_confirm_checkbox', 'Ich bestätige, dass echte DB-Werte geändert werden sollen.'); ?></label>
                    </div>
                </div>
            <?php } ?>

            <div class="form-actions">
                <button type="submit" class="btn <?php echo $isApply ? 'btn-danger' : 'btn-primary'; ?>"><?php echo $isApply ? frcMsg($i18n, 'finance_regulation_apply_button', 'Korrektur anwenden') : frcMsg($i18n, 'finance_regulation_simulate_button', 'Simulation starten'); ?></button>
            </div>
        </fieldset>
    </form>
    <?php
}

try {
    FinanceRegulationDataService::ensureSchema($website, $db);

    $seasonId = isset($_REQUEST['season_id']) ? (int) $_REQUEST['season_id'] : FinanceRegulationDataService::getDefaultSeasonId($website, $db);
    if ($seasonId < 0) {
        $seasonId = 0;
    }

    $allowedTabs = array('overview', 'clubs', 'simulation', 'correction', 'export');
    $tab = isset($_REQUEST['tab']) ? preg_replace('/[^a-z]/', '', $_REQUEST['tab']) : 'overview';
    if (!in_array($tab, $allowedTabs)) {
        $tab = 'overview';
    }

    $params = FinanceRegulationDataService::parametersFromRequest($_REQUEST);
    if (!$params['season_id']) {
        $params['season_id'] = $seasonId;
    }

    $simulationResult = NULL;
    $applyResult = NULL;
    $snapshotResult = NULL;
    $errorMessage = '';

    if (isset($_POST['frc_action'])) {
        if ($_POST['frc_action'] == 'simulate') {
            $simulationResult = FinanceRegulationDataService::simulateCorrection($website, $db, $params, (int) $admin['id']);
            $tab = 'simulation';
        } elseif ($_POST['frc_action'] == 'apply') {
            if ($admin['r_demo']) {
                throw new Exception(frcMsg($i18n, 'validationerror_no_changes_as_demo', 'Demo-Admins dürfen keine Änderungen ausführen.'));
            }
            if (!isset($_POST['confirm_apply']) || $_POST['confirm_apply'] != '1') {
                throw new Exception(frcMsg($i18n, 'finance_regulation_missing_confirmation', 'Bitte die Bestätigung für echte DB-Änderungen setzen.'));
            }
            $applyResult = FinanceRegulationDataService::applyCorrection($website, $db, $params, (int) $admin['id']);
            $tab = 'correction';
        } elseif ($_POST['frc_action'] == 'snapshot') {
            $snapshotResult = FinanceRegulationDataService::createSnapshot($website, $db, frcMsg($i18n, 'finance_regulation_manual_snapshot', 'Manueller Finanz-Snapshot'), $seasonId, (int) $admin['id']);
            $tab = 'export';
        }
    }
} catch (Exception $e) {
    $errorMessage = $e->getMessage();
}

$seasons = FinanceRegulationDataService::getAvailableSeasons($website, $db);
$dashboard = FinanceRegulationDataService::getDashboard($website, $db, $seasonId);
$logs = FinanceRegulationDataService::getLatestLogs($website, $db, 10);
$snapshots = FinanceRegulationDataService::getLatestSnapshots($website, $db, 10);
?>

<h1><?php echo frcMsg($i18n, 'finance_regulation_center_title', 'Finance Regulation Center'); ?></h1>
<p><?php echo frcMsg($i18n, 'finance_regulation_center_intro', 'Admin-Werkzeug zur Prüfung, Simulation und vorsichtigen Korrektur der Spielwirtschaft. Simulationen sind read-only; echte Korrekturen erstellen automatisch einen Snapshot und einen Logeintrag.'); ?></p>

<?php if ($errorMessage) { ?>
    <?php echo createErrorMessage(frcMsg($i18n, 'alert_error_title', 'Fehler'), escapeOutput($errorMessage)); ?>
<?php } ?>
<?php if ($snapshotResult) { ?>
    <?php echo createSuccessMessage(frcMsg($i18n, 'finance_regulation_snapshot_created', 'Snapshot erstellt'), 'ID ' . (int) $snapshotResult['snapshot_id']); ?>
<?php } ?>
<?php if ($applyResult) { ?>
    <?php echo createSuccessMessage(frcMsg($i18n, 'finance_regulation_apply_success', 'Korrektur angewendet'), frcMsg($i18n, 'finance_regulation_apply_success_detail', 'Vor der Änderung wurde automatisch ein Snapshot erstellt.')); ?>
    <?php frcRenderEffectTable($i18n, $applyResult); ?>
<?php } ?>

<form class="form-inline" method="get" action="index.php">
    <input type="hidden" name="site" value="<?php echo escapeOutput($site); ?>">
    <input type="hidden" name="tab" value="<?php echo escapeOutput($tab); ?>">
    <label><?php echo frcMsg($i18n, 'finance_regulation_season', 'Saison'); ?></label>
    <select name="season_id" class="input-xlarge">
        <option value="0"<?php if ($seasonId == 0) echo ' selected="selected"'; ?>><?php echo frcMsg($i18n, 'finance_regulation_all_bookings', 'Alle Buchungen'); ?></option>
        <?php foreach ($seasons as $season) { ?>
            <option value="<?php echo (int) $season['id']; ?>"<?php if ($seasonId == (int) $season['id']) echo ' selected="selected"'; ?>><?php echo escapeOutput($season['name'] . ' - ' . $season['league_name']); ?></option>
        <?php } ?>
    </select>
    <button type="submit" class="btn btn-primary"><?php echo frcMsg($i18n, 'button_display', 'Anzeigen'); ?></button>
    <span class="muted"><?php echo escapeOutput($dashboard['filter']['label']); ?></span>
</form>

<ul class="nav nav-tabs">
    <li<?php if ($tab == 'overview') echo ' class="active"'; ?>><a href="<?php echo frcTabUrl($site, 'overview', $seasonId); ?>"><?php echo frcMsg($i18n, 'finance_regulation_tab_overview', 'Übersicht'); ?></a></li>
    <li<?php if ($tab == 'clubs') echo ' class="active"'; ?>><a href="<?php echo frcTabUrl($site, 'clubs', $seasonId); ?>"><?php echo frcMsg($i18n, 'finance_regulation_tab_clubs', 'Vereine'); ?></a></li>
    <li<?php if ($tab == 'simulation') echo ' class="active"'; ?>><a href="<?php echo frcTabUrl($site, 'simulation', $seasonId); ?>"><?php echo frcMsg($i18n, 'finance_regulation_tab_simulation', 'Simulation'); ?></a></li>
    <li<?php if ($tab == 'correction') echo ' class="active"'; ?>><a href="<?php echo frcTabUrl($site, 'correction', $seasonId); ?>"><?php echo frcMsg($i18n, 'finance_regulation_tab_correction', 'Korrektur anwenden'); ?></a></li>
    <li<?php if ($tab == 'export') echo ' class="active"'; ?>><a href="<?php echo frcTabUrl($site, 'export', $seasonId); ?>"><?php echo frcMsg($i18n, 'finance_regulation_tab_export', 'Bericht / Export'); ?></a></li>
</ul>

<?php if ($tab == 'overview') { ?>
    <h3><?php echo frcMsg($i18n, 'finance_regulation_core_metrics', 'Kernkennzahlen'); ?></h3>
    <table class="table table-bordered table-striped">
        <thead>
            <tr>
                <th><?php echo frcMsg($i18n, 'finance_regulation_metric', 'Kennzahl'); ?></th>
                <th><?php echo frcMsg($i18n, 'finance_regulation_all', 'Alle'); ?></th>
                <th><?php echo frcMsg($i18n, 'finance_regulation_human', 'Human'); ?></th>
                <th><?php echo frcMsg($i18n, 'finance_regulation_cpu', 'CPU'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php $scopeLabels = array('all', 'human', 'cpu'); ?>
            <tr><th><?php echo frcMsg($i18n, 'finance_regulation_club_count', 'Vereine'); ?></th><?php foreach ($scopeLabels as $s) { ?><td><?php echo (int) $dashboard['scopes'][$s]['team_count']; ?></td><?php } ?></tr>
            <tr><th><?php echo frcMsg($i18n, 'finance_regulation_avg_budget', 'Ø Vereinsbudget'); ?></th><?php foreach ($scopeLabels as $s) { ?><td><?php echo frcCurrency($dashboard['scopes'][$s]['avg_budget']); ?></td><?php } ?></tr>
            <tr><th><?php echo frcMsg($i18n, 'finance_regulation_avg_income_matchday', 'Ø Einnahmen pro Spieltag'); ?></th><?php foreach ($scopeLabels as $s) { ?><td><?php echo frcCurrency($dashboard['scopes'][$s]['avg_income_per_matchday']); ?></td><?php } ?></tr>
            <tr><th><?php echo frcMsg($i18n, 'finance_regulation_avg_player_salary', 'Ø Spielergehalt'); ?></th><?php foreach ($scopeLabels as $s) { ?><td><?php echo frcCurrency($dashboard['scopes'][$s]['avg_player_salary']); ?></td><?php } ?></tr>
            <tr><th><?php echo frcMsg($i18n, 'finance_regulation_salary_income_ratio', 'Gehalt/Einnahmen'); ?></th><?php foreach ($scopeLabels as $s) { ?><td><?php echo frcPercent($dashboard['scopes'][$s]['salary_income_ratio']); ?></td><?php } ?></tr>
            <tr><th><?php echo frcMsg($i18n, 'finance_regulation_recurring_income_ratio', 'Fixkosten/Einnahmen'); ?></th><?php foreach ($scopeLabels as $s) { ?><td><?php echo frcPercent($dashboard['scopes'][$s]['recurring_income_ratio']); ?></td><?php } ?></tr>
            <tr><th><?php echo frcMsg($i18n, 'finance_regulation_transfer_spending', 'Transferausgaben'); ?></th><?php foreach ($scopeLabels as $s) { ?><td><?php echo frcCurrency($dashboard['scopes'][$s]['transfer_spending']); ?></td><?php } ?></tr>
        </tbody>
    </table>

    <h3><?php echo frcMsg($i18n, 'finance_regulation_recommendations', 'Empfohlene Korrektur'); ?></h3>
    <table class="table table-striped table-bordered">
        <thead><tr><th>Status</th><th><?php echo frcMsg($i18n, 'finance_regulation_recommendation', 'Empfehlung'); ?></th><th><?php echo frcMsg($i18n, 'finance_regulation_suggestion', 'Vorschlag'); ?></th></tr></thead>
        <tbody>
            <?php foreach ($dashboard['recommendations'] as $row) { ?>
                <tr>
                    <td><span class="label label-<?php echo frcBadgeClass($row['severity']); ?>"><?php echo escapeOutput($row['severity']); ?></span></td>
                    <td><strong><?php echo escapeOutput($row['title']); ?></strong><br><?php echo escapeOutput($row['message']); ?></td>
                    <td><?php echo escapeOutput($row['suggestion']); ?></td>
                </tr>
            <?php } ?>
        </tbody>
    </table>

    <h3><?php echo frcMsg($i18n, 'finance_regulation_secondary_costs_breakdown', 'Nebenkosten pro Spieltag'); ?></h3>
    <table class="table table-condensed table-bordered">
        <thead><tr><th><?php echo frcMsg($i18n, 'finance_regulation_cost_type', 'Kostenart'); ?></th><th><?php echo frcMsg($i18n, 'finance_regulation_amount_all', 'Alle'); ?></th><th><?php echo frcMsg($i18n, 'finance_regulation_amount_human', 'Human'); ?></th><th><?php echo frcMsg($i18n, 'finance_regulation_amount_cpu', 'CPU'); ?></th></tr></thead>
        <tbody>
            <?php
            $costLabels = array();
            foreach ($scopeLabels as $s) {
                foreach ($dashboard['scopes'][$s]['secondary_cost_items'] as $item) {
                    $costLabels[$item['label']] = TRUE;
                }
            }
            foreach (array_keys($costLabels) as $label) { ?>
                <tr>
                    <td><?php echo escapeOutput($label); ?></td>
                    <?php foreach ($scopeLabels as $s) { $amount = 0; foreach ($dashboard['scopes'][$s]['secondary_cost_items'] as $item) { if ($item['label'] == $label) $amount = $item['amount']; } ?>
                        <td><?php echo frcCurrency($amount); ?></td>
                    <?php } ?>
                </tr>
            <?php } ?>
        </tbody>
    </table>

<?php } elseif ($tab == 'clubs') { ?>
    <div class="row-fluid">
        <div class="span6">
            <h3><?php echo frcMsg($i18n, 'finance_regulation_richest_clubs', 'Reichste Vereine'); ?></h3>
            <table class="table table-striped table-bordered">
                <thead><tr><th>Verein</th><th>Manager</th><th>Budget</th><th>Liga</th></tr></thead>
                <tbody><?php foreach ($dashboard['rankings']['richest'] as $club) { ?><tr><td><?php echo escapeOutput($club['name']); ?></td><td><?php echo escapeOutput($club['manager_name']); ?></td><td><?php echo frcCurrency($club['finanz_budget']); ?></td><td><?php echo escapeOutput($club['league_name']); ?></td></tr><?php } ?></tbody>
            </table>
        </div>
        <div class="span6">
            <h3><?php echo frcMsg($i18n, 'finance_regulation_poorest_clubs', 'Ärmste Vereine'); ?></h3>
            <table class="table table-striped table-bordered">
                <thead><tr><th>Verein</th><th>Manager</th><th>Budget</th><th>Liga</th></tr></thead>
                <tbody><?php foreach ($dashboard['rankings']['poorest'] as $club) { ?><tr><td><?php echo escapeOutput($club['name']); ?></td><td><?php echo escapeOutput($club['manager_name']); ?></td><td><?php echo frcCurrency($club['finanz_budget']); ?></td><td><?php echo escapeOutput($club['league_name']); ?></td></tr><?php } ?></tbody>
            </table>
        </div>
    </div>

    <h3><?php echo frcMsg($i18n, 'finance_regulation_salary_pressure', 'Salary-to-Income Druck'); ?></h3>
    <table class="table table-striped table-bordered">
        <thead><tr><th>Verein</th><th>Manager</th><th>Gehälter/Spieltag</th><th>Einnahmen/Spieltag</th><th>Quote</th><th>Budget</th></tr></thead>
        <tbody>
            <?php foreach ($dashboard['rankings']['salary_pressure'] as $club) { ?>
                <tr>
                    <td><?php echo escapeOutput($club['name']); ?></td>
                    <td><?php echo escapeOutput($club['manager_name']); ?></td>
                    <td><?php echo frcCurrency($club['salary_total']); ?></td>
                    <td><?php echo frcCurrency($club['income_per_matchday']); ?></td>
                    <td><?php echo frcPercent($club['salary_ratio']); ?></td>
                    <td><?php echo frcCurrency($club['finanz_budget']); ?></td>
                </tr>
            <?php } ?>
        </tbody>
    </table>

<?php } elseif ($tab == 'simulation') { ?>
    <p class="alert alert-info"><?php echo frcMsg($i18n, 'finance_regulation_simulation_intro', 'Simulationen ändern keine Datenbankwerte. Sie zeigen nur die rechnerische Auswirkung der gewählten Faktoren.'); ?></p>
    <?php if ($simulationResult) { ?>
        <h3><?php echo frcMsg($i18n, 'finance_regulation_simulation_result', 'Simulationsergebnis'); ?></h3>
        <?php frcRenderEffectTable($i18n, $simulationResult); ?>
    <?php } ?>
    <?php frcRenderCorrectionForm($i18n, $site, 'simulation', $seasonId, $params, 'simulate'); ?>

<?php } elseif ($tab == 'correction') { ?>
    <p class="alert alert-warning"><?php echo frcMsg($i18n, 'finance_regulation_correction_intro', 'Diese Funktion ändert echte Datenbankwerte. Vor jeder Anwendung wird automatisch ein Snapshot erstellt und die Aktion im Log gespeichert.'); ?></p>
    <?php frcRenderCorrectionForm($i18n, $site, 'correction', $seasonId, $params, 'apply'); ?>

<?php } else { ?>
    <h3><?php echo frcMsg($i18n, 'finance_regulation_export', 'Bericht / Export'); ?></h3>
    <p><?php echo frcMsg($i18n, 'finance_regulation_export_intro', 'Erstelle Snapshots für spätere Vergleiche oder exportiere die aktuelle Auswertung als CSV.'); ?></p>
    <form method="post" action="index.php" class="form-inline">
        <input type="hidden" name="site" value="<?php echo escapeOutput($site); ?>">
        <input type="hidden" name="tab" value="export">
        <input type="hidden" name="season_id" value="<?php echo (int) $seasonId; ?>">
        <input type="hidden" name="frc_action" value="snapshot">
        <button type="submit" class="btn btn-primary"><?php echo frcMsg($i18n, 'finance_regulation_create_snapshot', 'Snapshot speichern'); ?></button>
        <a class="btn" href="finance-regulation-export.php?season_id=<?php echo (int) $seasonId; ?>"><?php echo frcMsg($i18n, 'finance_regulation_download_csv', 'CSV exportieren'); ?></a>
    </form>

    <h4><?php echo frcMsg($i18n, 'finance_regulation_latest_snapshots', 'Letzte Snapshots'); ?></h4>
    <table class="table table-striped table-bordered table-condensed">
        <thead><tr><th>ID</th><th>Datum</th><th>Titel</th><th>Saison</th></tr></thead>
        <tbody><?php foreach ($snapshots as $row) { ?><tr><td><?php echo (int) $row['id']; ?></td><td><?php echo date('d.m.Y H:i', (int) $row['created_date']); ?></td><td><?php echo escapeOutput($row['title']); ?></td><td><?php echo (int) $row['season_id']; ?></td></tr><?php } ?></tbody>
    </table>

    <h4><?php echo frcMsg($i18n, 'finance_regulation_latest_logs', 'Letzte Aktionen'); ?></h4>
    <table class="table table-striped table-bordered table-condensed">
        <thead><tr><th>Datum</th><th>Modus</th><th>Aktion</th></tr></thead>
        <tbody><?php foreach ($logs as $row) { ?><tr><td><?php echo date('d.m.Y H:i', (int) $row['created_date']); ?></td><td><?php echo frcModeLabel($row['mode']); ?></td><td><?php echo escapeOutput($row['action_label']); ?></td></tr><?php } ?></tbody>
    </table>
<?php } ?>
