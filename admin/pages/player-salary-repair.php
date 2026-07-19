<?php
/******************************************************

  Player salary factor-100 repair admin page.

******************************************************/

if (!$admin['r_admin'] && !$admin['r_demo']) {
    echo '<p>' . $i18n->getMessage('error_access_denied') . '</p>';
    exit;
}

function psrMsg($i18n, $key, $fallback) {
    return $i18n->hasMessage($key) ? $i18n->getMessage($key) : $fallback;
}

function psrMoney($amount) {
    return number_format((int) round($amount), 0, ',', ' ') . ' EUR';
}

function psrPlayerName($row) {
    if (isset($row['kunstname']) && strlen(trim($row['kunstname']))) {
        return trim($row['kunstname']);
    }
    return trim((isset($row['vorname']) ? $row['vorname'] : '') . ' ' . (isset($row['nachname']) ? $row['nachname'] : ''));
}

$repairResult = null;
$errorMessage = '';

if (isset($_POST['salary_repair_action']) && $_POST['salary_repair_action'] == 'repair_factor_100') {
    if ($admin['r_demo']) {
        $errorMessage = psrMsg($i18n, 'player_salary_repair_demo_blocked', 'Im Demo-Modus sind Datenbankänderungen gesperrt.');
    } elseif (!isset($_POST['confirm_repair']) || $_POST['confirm_repair'] != '1') {
        $errorMessage = psrMsg($i18n, 'player_salary_repair_confirmation_required', 'Bitte die Sicherheitsbestätigung setzen.');
    } else {
        try {
            $repairResult = PlayerSalaryRepairService::repairFactor100Candidates($website, $db, $admin['id']);
            if ($repairResult['affected'] > 0) {
                logAdminAction($website, LOG_TYPE_EDIT, $admin['name'], 'player_salary_factor_100', $repairResult['affected'] . ' Spieler');
            }
        } catch (Exception $e) {
            $errorMessage = $e->getMessage();
        }
    }
}

$summary = PlayerSalaryRepairService::getCandidateSummary($website, $db);
$candidates = PlayerSalaryRepairService::getCandidates($website, $db, 500);
$recentRepairs = PlayerSalaryRepairService::getRecentRepairs($website, $db, 25);
?>

<h1><?php echo psrMsg($i18n, 'player_salary_repair_title', 'Spielergehälter prüfen'); ?></h1>
<p><?php echo psrMsg($i18n, 'player_salary_repair_intro', 'Prüft gezielt auf den bekannten Faktor-100-Fehler und korrigiert nur eindeutig passende Spielergehälter.'); ?></p>

<?php if (strlen($errorMessage)) { ?>
    <div class="alert alert-error"><strong><?php echo psrMsg($i18n, 'player_salary_repair_error', 'Fehler'); ?>:</strong> <?php echo escapeOutput($errorMessage); ?></div>
<?php } ?>

<?php if ($repairResult !== null) { ?>
    <?php if ($repairResult['affected'] > 0) { ?>
        <div class="alert alert-success">
            <strong><?php echo psrMsg($i18n, 'player_salary_repair_success', 'Korrektur abgeschlossen'); ?></strong><br>
            <?php echo sprintf(psrMsg($i18n, 'player_salary_repair_success_detail', '%d Spielergehälter wurden gesichert und durch 100 geteilt.'), (int) $repairResult['affected']); ?>
        </div>
    <?php } else { ?>
        <div class="alert alert-info"><?php echo psrMsg($i18n, 'player_salary_repair_nothing_to_do', 'Es wurden keine passenden Faktor-100-Datensätze gefunden.'); ?></div>
    <?php } ?>
<?php } ?>

<div class="row-fluid">
    <div class="span3">
        <div class="well">
            <h4><?php echo psrMsg($i18n, 'player_salary_repair_candidates', 'Verdächtige Datensätze'); ?></h4>
            <p style="font-size: 28px; line-height: 32px;"><strong><?php echo (int) $summary['candidate_count']; ?></strong></p>
        </div>
    </div>
    <div class="span3">
        <div class="well">
            <h4><?php echo psrMsg($i18n, 'player_salary_repair_old_total', 'Gehaltssumme vorher'); ?></h4>
            <p><strong><?php echo psrMoney($summary['old_total']); ?></strong></p>
        </div>
    </div>
    <div class="span3">
        <div class="well">
            <h4><?php echo psrMsg($i18n, 'player_salary_repair_new_total', 'Gehaltssumme nachher'); ?></h4>
            <p><strong><?php echo psrMoney($summary['new_total']); ?></strong></p>
        </div>
    </div>
    <div class="span3">
        <div class="well">
            <h4><?php echo psrMsg($i18n, 'player_salary_repair_rule', 'Prüfregel'); ?></h4>
            <p><?php echo psrMsg($i18n, 'player_salary_repair_rule_detail', 'Aktiv, Verein vorhanden, Marktwert positiv, Gehalt ab 100.000 EUR und Ergebnis nach Teilung zwischen 500 und 50.000 EUR.'); ?></p>
        </div>
    </div>
</div>

<?php if ($summary['candidate_count'] > 0) { ?>
<form method="post" action="index.php" onsubmit="return confirm('<?php echo escapeOutput(psrMsg($i18n, 'player_salary_repair_confirm_dialog', 'Die angezeigten Gehälter werden gesichert und durch 100 geteilt. Fortfahren?')); ?>');">
    <input type="hidden" name="site" value="player-salary-repair">
    <input type="hidden" name="salary_repair_action" value="repair_factor_100">
    <label class="checkbox">
        <input type="checkbox" name="confirm_repair" value="1">
        <?php echo psrMsg($i18n, 'player_salary_repair_confirm_checkbox', 'Ich bestätige die Korrektur der angezeigten Faktor-100-Datensätze.'); ?>
    </label>
    <button type="submit" class="btn btn-danger"<?php if ($admin['r_demo']) echo ' disabled="disabled"'; ?>>
        <i class="icon-wrench icon-white"></i>
        <?php echo psrMsg($i18n, 'player_salary_repair_execute', 'Faktor-100-Gehälter automatisch korrigieren'); ?>
    </button>
</form>
<hr>
<?php } ?>

<h3><?php echo psrMsg($i18n, 'player_salary_repair_preview', 'Vorschau'); ?></h3>
<?php if (count($candidates)) { ?>
<table class="table table-striped table-bordered table-condensed">
    <thead>
        <tr>
            <th>ID</th>
            <th><?php echo psrMsg($i18n, 'player_salary_repair_player', 'Spieler'); ?></th>
            <th><?php echo psrMsg($i18n, 'player_salary_repair_club', 'Verein'); ?></th>
            <th><?php echo psrMsg($i18n, 'player_salary_repair_league', 'Liga'); ?></th>
            <th><?php echo psrMsg($i18n, 'player_salary_repair_position', 'Position'); ?></th>
            <th><?php echo psrMsg($i18n, 'player_salary_repair_strength', 'Stärke'); ?></th>
            <th><?php echo psrMsg($i18n, 'player_salary_repair_market_value', 'Marktwert'); ?></th>
            <th><?php echo psrMsg($i18n, 'player_salary_repair_old_salary', 'Gehalt vorher'); ?></th>
            <th><?php echo psrMsg($i18n, 'player_salary_repair_new_salary', 'Gehalt nachher'); ?></th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($candidates as $row) { ?>
        <tr>
            <td><?php echo (int) $row['id']; ?></td>
            <td><?php echo escapeOutput(psrPlayerName($row)); ?></td>
            <td><?php echo escapeOutput($row['club_name']); ?></td>
            <td><?php echo escapeOutput($row['league_name']); ?></td>
            <td><?php echo escapeOutput($row['position']); ?></td>
            <td><?php echo escapeOutput(number_format((float) $row['w_staerke'], 2, ',', ' ')); ?></td>
            <td><?php echo psrMoney($row['marktwert']); ?></td>
            <td><span class="label label-important"><?php echo psrMoney($row['old_salary']); ?></span></td>
            <td><span class="label label-success"><?php echo psrMoney($row['new_salary']); ?></span></td>
        </tr>
        <?php } ?>
    </tbody>
</table>
<?php } else { ?>
<div class="alert alert-success"><?php echo psrMsg($i18n, 'player_salary_repair_clean', 'Aktuell wurden keine Faktor-100-Gehälter gefunden.'); ?></div>
<?php } ?>

<h3><?php echo psrMsg($i18n, 'player_salary_repair_history', 'Letzte Korrekturen'); ?></h3>
<?php if (count($recentRepairs)) { ?>
<table class="table table-striped table-bordered table-condensed">
    <thead>
        <tr>
            <th><?php echo psrMsg($i18n, 'player_salary_repair_date', 'Datum'); ?></th>
            <th>ID</th>
            <th><?php echo psrMsg($i18n, 'player_salary_repair_player', 'Spieler'); ?></th>
            <th><?php echo psrMsg($i18n, 'player_salary_repair_club', 'Verein'); ?></th>
            <th><?php echo psrMsg($i18n, 'player_salary_repair_old_salary', 'Gehalt vorher'); ?></th>
            <th><?php echo psrMsg($i18n, 'player_salary_repair_new_salary', 'Gehalt nachher'); ?></th>
            <th>Admin-ID</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($recentRepairs as $row) { ?>
        <tr>
            <td><?php echo date('d.m.Y H:i', (int) $row['repaired_at']); ?></td>
            <td><?php echo (int) $row['player_id']; ?></td>
            <td><?php echo escapeOutput(psrPlayerName($row)); ?></td>
            <td><?php echo escapeOutput($row['club_name']); ?></td>
            <td><?php echo psrMoney($row['old_salary']); ?></td>
            <td><?php echo psrMoney($row['new_salary']); ?></td>
            <td><?php echo (int) $row['admin_id']; ?></td>
        </tr>
        <?php } ?>
    </tbody>
</table>
<?php } else { ?>
<p class="muted"><?php echo psrMsg($i18n, 'player_salary_repair_no_history', 'Noch keine Korrektur protokolliert.'); ?></p>
<?php } ?>
