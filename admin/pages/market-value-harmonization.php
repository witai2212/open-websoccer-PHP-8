<?php
/******************************************************

  Economy-aware market value harmonization admin page.

******************************************************/

if (!$admin['r_admin'] && !$admin['r_demo']) {
    echo '<p>' . $i18n->getMessage('error_access_denied') . '</p>';
    exit;
}

function mvhMoney($amount) {
    return number_format((int) round($amount), 0, ',', ' ') . ' EUR';
}

function mvhPercent($value) {
    return (($value > 0) ? '+' : '') . number_format((float) $value, 1, ',', ' ') . '%';
}

function mvhPlayerName($row) {
    if (isset($row['kunstname']) && strlen(trim((string) $row['kunstname']))) {
        return trim((string) $row['kunstname']);
    }
    return trim((isset($row['vorname']) ? $row['vorname'] : '') . ' ' . (isset($row['nachname']) ? $row['nachname'] : ''));
}

function mvhValue($name, $default = '') {
    if (isset($_REQUEST[$name])) return trim((string) $_REQUEST[$name]);
    return $default;
}

function mvhSelected($current, $value) {
    return ((string) $current === (string) $value) ? ' selected="selected"' : '';
}

function mvhDeltaClass($value) {
    if ($value < -10) return 'label-success';
    if ($value > 10) return 'label-important';
    return 'label-info';
}

function mvhFactorSummary($factors) {
    $labels = array(
        'age' => 'Alter', 'position' => 'Position', 'talent' => 'Talent', 'contract' => 'Vertrag',
        'health' => 'Gesundheit', 'form' => 'Form', 'performance' => 'Leistung',
        'personality' => 'Persönlichkeit', 'league' => 'Liga', 'club' => 'Verein',
        'international' => 'Nationalteam', 'traits' => 'Fähigkeiten'
    );
    $parts = array();
    foreach ($labels as $key => $label) {
        if (!isset($factors[$key])) continue;
        $parts[] = $label . ' ' . number_format((float) $factors[$key], 2, ',', ' ');
    }
    return implode(' · ', $parts);
}

$filters = array(
    'club_id' => mvhValue('club_id', 0),
    'league_id' => mvhValue('league_id', 0),
    'age_min' => mvhValue('age_min'),
    'age_max' => mvhValue('age_max'),
    'strength_min' => mvhValue('strength_min'),
    'strength_max' => mvhValue('strength_max'),
    'deviation_min' => mvhValue('deviation_min', 10)
);
$limit = max(10, min(500, (int) mvhValue('limit', 100)));
$runResult = null;
$errorMessage = '';

if (isset($_POST['market_value_action']) && $_POST['market_value_action'] === 'recalculate_all') {
    if ($admin['r_demo']) {
        $errorMessage = 'Im Demo-Modus sind Datenbankänderungen gesperrt.';
    } elseif (!isset($_POST['confirm_recalculation']) || $_POST['confirm_recalculation'] !== '1') {
        $errorMessage = 'Bitte die Sicherheitsbestätigung setzen.';
    } else {
        try {
            $runResult = PlayerMarketValueDataService::recalculateAll($website, $db, (int) $admin['id'], 'admin');
            logAdminAction($website, LOG_TYPE_EDIT, $admin['name'], 'market_value_harmonization', $runResult['affected'] . ' Spieler');
        } catch (Exception $e) {
            $errorMessage = $e->getMessage();
        }
    }
}

$economy = PlayerMarketValueDataService::getEconomySnapshot($website, $db);
$benchmarks = PlayerMarketValueDataService::getBenchmarks($website, $db);
$preview = PlayerMarketValueDataService::getPreview($website, $db, $filters, $limit);
$youthPreview = PlayerMarketValueDataService::getYouthPreview($website, $db, $filters, min(100, $limit));
$clubs = PlayerMarketValueDataService::getClubs($website, $db);
$leagues = PlayerMarketValueDataService::getLeagues($website, $db);
$history = PlayerMarketValueDataService::getRecentRuns($website, $db, 15);
?>

<h1>Marktwerte harmonisieren</h1>
<p>Die Vorschau berechnet Marktwerte aus der aktuellen Gesamtwirtschaft. Profis verwenden systemweit <code>spieler.marktwert</code>, Jugendspieler den automatischen Wert <code>youthplayer.market_value</code>. Die frei gewählte Ablösesumme eines Jugendspielers bleibt davon getrennt. Das Öffnen einer Spieler- oder Transferseite verändert keinen Marktwert mehr.</p>

<?php if (strlen($errorMessage)) { ?>
<div class="alert alert-error"><strong>Fehler:</strong> <?php echo escapeOutput($errorMessage); ?></div>
<?php } ?>

<?php if ($runResult !== null) { ?>
<div class="alert alert-success">
    <strong>Neuberechnung abgeschlossen.</strong><br>
    <?php echo (int) $runResult['affected']; ?> Spieler wurden in einem vollständigen Lauf aktualisiert
    (<?php echo (int) $runResult['professional_players']; ?> Profis, <?php echo (int) $runResult['youth_players']; ?> Jugendspieler).
    Gesamtwert vorher: <?php echo mvhMoney($runResult['old_total']); ?>,
    nachher: <?php echo mvhMoney($runResult['new_total']); ?>.
    Gesunken: <?php echo (int) $runResult['decreased']; ?>,
    gestiegen: <?php echo (int) $runResult['increased']; ?>,
    unverändert: <?php echo (int) $runResult['unchanged']; ?>.
</div>
<?php } ?>

<div class="row-fluid">
    <div class="span3"><div class="well"><h4>Marktobergrenze</h4><p style="font-size:24px"><strong><?php echo mvhMoney($economy['market_ceiling']); ?></strong></p><small>Dynamisch, maximal 100 Mio. EUR</small></div></div>
    <div class="span3"><div class="well"><h4>90%-Vereinsbudget</h4><p><strong><?php echo mvhMoney($economy['p90_budget']); ?></strong></p><small><?php echo (int) $economy['club_count']; ?> aktive Vereine</small></div></div>
    <div class="span3"><div class="well"><h4>Median Vereinsbudget</h4><p><strong><?php echo mvhMoney($economy['median_budget']); ?></strong></p><small>Maximal: <?php echo mvhMoney($economy['max_budget']); ?></small></div></div>
    <div class="span3"><div class="well"><h4>Reale Ablösen</h4><p><strong><?php echo mvhMoney($economy['p75_transfer_fee']); ?></strong></p><small>75%-Wert aus <?php echo (int) $economy['transfer_count']; ?> Transfers der letzten 365 Tage</small></div></div>
</div>

<div class="alert alert-info">
    <strong>Automatische Faktoren:</strong> positionsbezogene Qualität, Alter, Talent und Potenzial, Restvertrag, Verletzungen, Form, Saisonleistung, Persönlichkeit, Spezialfähigkeiten, aktueller Nationalkader, absolvierte Länderspiele, Liga- und Vereinsstatus. Jugendwerte berücksichtigen die dort verfügbaren Daten Stärke, Alter, Leistung, Spezialfähigkeiten, Liga und Verein. Inaktive Profis erhalten 0 EUR. Einzelne manuelle Marktwerte oder manuelle Marktwertfaktoren werden nicht verwendet.
</div>

<h3>Benchmark-Spieler</h3>
<p>Die vereinbarten IDs 1847, 1331, 7057 und 1401 werden bei jeder Vorschau separat gezeigt.</p>
<?php if (count($benchmarks)) { ?>
<table class="table table-striped table-bordered table-condensed">
    <thead><tr><th>ID</th><th>Spieler</th><th>Position</th><th>Alter</th><th>Stärke</th><th>Verein / Liga</th><th>Aktuell</th><th>Vorschlag</th><th>Abweichung</th><th>Begründung</th></tr></thead>
    <tbody>
    <?php foreach ($benchmarks as $row) { ?>
        <tr>
            <td><?php echo (int) $row['id']; ?></td>
            <td><?php echo escapeOutput(mvhPlayerName($row)); ?></td>
            <td><?php echo escapeOutput($row['position']); ?></td>
            <td><?php echo (int) $row['age']; ?></td>
            <td><?php echo number_format((float) $row['w_staerke'], 1, ',', ' '); ?></td>
            <td><?php echo escapeOutput($row['club_name']); ?><br><small><?php echo escapeOutput($row['league_name']); ?></small></td>
            <td><?php echo mvhMoney($row['marktwert']); ?></td>
            <td><strong><?php echo mvhMoney($row['proposed_market_value']); ?></strong></td>
            <td><span class="label <?php echo mvhDeltaClass($row['deviation_percent']); ?>"><?php echo mvhPercent($row['deviation_percent']); ?></span></td>
            <td><small><?php echo escapeOutput(implode(' · ', $row['calculation']['reasons'])); ?></small></td>
        </tr>
    <?php } ?>
    </tbody>
</table>
<?php } else { ?>
<div class="alert">Die vier Benchmark-IDs wurden in der aktuellen Spielerdatenbank nicht gefunden.</div>
<?php } ?>

<h3>Vorschau filtern</h3>
<form method="get" action="index.php" class="form-inline">
    <input type="hidden" name="site" value="market-value-harmonization">
    <select name="club_id" class="input-xlarge">
        <option value="0">Alle Vereine</option>
        <?php foreach ($clubs as $club) { ?><option value="<?php echo (int) $club['id']; ?>"<?php echo mvhSelected($filters['club_id'], $club['id']); ?>><?php echo escapeOutput($club['name']); ?></option><?php } ?>
    </select>
    <select name="league_id" class="input-xlarge">
        <option value="0">Alle Ligen</option>
        <?php foreach ($leagues as $league) { ?><option value="<?php echo (int) $league['id']; ?>"<?php echo mvhSelected($filters['league_id'], $league['id']); ?>><?php echo escapeOutput($league['name']); ?> (Liga <?php echo (int) $league['division']; ?>)</option><?php } ?>
    </select>
    <input type="text" class="input-mini" name="age_min" value="<?php echo escapeOutput($filters['age_min']); ?>" placeholder="Alter min">
    <input type="text" class="input-mini" name="age_max" value="<?php echo escapeOutput($filters['age_max']); ?>" placeholder="Alter max">
    <input type="text" class="input-mini" name="strength_min" value="<?php echo escapeOutput($filters['strength_min']); ?>" placeholder="Stärke min">
    <input type="text" class="input-mini" name="strength_max" value="<?php echo escapeOutput($filters['strength_max']); ?>" placeholder="Stärke max">
    <div class="input-append"><input type="text" class="input-mini" name="deviation_min" value="<?php echo escapeOutput($filters['deviation_min']); ?>"><span class="add-on">% min.</span></div>
    <select name="limit" class="input-small">
        <?php foreach (array(50, 100, 250, 500) as $option) { ?><option value="<?php echo $option; ?>"<?php echo mvhSelected($limit, $option); ?>><?php echo $option; ?></option><?php } ?>
    </select>
    <button class="btn btn-primary" type="submit">Vorschau aktualisieren</button>
</form>

<h3>Vorschau</h3>
<?php if (count($preview)) { ?>
<table class="table table-striped table-bordered table-condensed">
    <thead><tr><th>ID</th><th>Spieler</th><th>Verein / Liga</th><th>Pos.</th><th>Alter</th><th>Stärke</th><th>Vertrag</th><th>Aktuell</th><th>Vorschlag</th><th>Abweichung</th><th>Faktoren</th></tr></thead>
    <tbody>
    <?php foreach ($preview as $row) { ?>
        <tr>
            <td><?php echo (int) $row['id']; ?></td>
            <td><?php echo escapeOutput(mvhPlayerName($row)); ?></td>
            <td><?php echo escapeOutput($row['club_name']); ?><br><small><?php echo escapeOutput($row['league_name']); ?></small></td>
            <td><?php echo escapeOutput($row['position']); ?></td>
            <td><?php echo (int) $row['age']; ?></td>
            <td><?php echo number_format((float) $row['w_staerke'], 1, ',', ' '); ?></td>
            <td><?php echo (int) $row['vertrag_spiele']; ?> Spiele</td>
            <td><?php echo mvhMoney($row['marktwert']); ?></td>
            <td><strong><?php echo mvhMoney($row['proposed_market_value']); ?></strong></td>
            <td><span class="label <?php echo mvhDeltaClass($row['deviation_percent']); ?>"><?php echo mvhPercent($row['deviation_percent']); ?></span><br><small><?php echo mvhMoney($row['deviation_amount']); ?></small></td>
            <td><small>Qualität <?php echo number_format($row['calculation']['factors']['quality'], 1, ',', ' '); ?><br><?php echo escapeOutput(mvhFactorSummary($row['calculation']['factors'])); ?></small></td>
        </tr>
    <?php } ?>
    </tbody>
</table>
<?php } else { ?>
<div class="alert alert-success">Für die gewählten Filter wurden keine ausreichend großen Abweichungen gefunden.</div>
<?php } ?>

<h3>Vorschau Jugendspieler</h3>
<p>Der automatische Marktwert ist nicht mit der frei festgelegten Ablösesumme auf dem Jugendmarktplatz identisch.</p>
<?php if (count($youthPreview)) { ?>
<table class="table table-striped table-bordered table-condensed">
    <thead><tr><th>ID</th><th>Spieler</th><th>Verein / Liga</th><th>Pos.</th><th>Alter</th><th>Stärke</th><th>Aktuell</th><th>Vorschlag</th><th>Ablösesumme</th><th>Abweichung</th><th>Faktoren</th></tr></thead>
    <tbody>
    <?php foreach ($youthPreview as $row) { ?>
        <tr>
            <td><?php echo (int) $row['id']; ?></td>
            <td><?php echo escapeOutput(trim($row['firstname'] . ' ' . $row['lastname'])); ?></td>
            <td><?php echo escapeOutput($row['club_name']); ?><br><small><?php echo escapeOutput($row['league_name']); ?></small></td>
            <td><?php echo escapeOutput($row['position']); ?></td>
            <td><?php echo (int) $row['age']; ?></td>
            <td><?php echo number_format((float) $row['strength'], 1, ',', ' '); ?></td>
            <td><?php echo mvhMoney($row['market_value']); ?></td>
            <td><strong><?php echo mvhMoney($row['proposed_market_value']); ?></strong></td>
            <td><?php echo ((int) $row['transfer_fee'] > 0) ? mvhMoney($row['transfer_fee']) : '-'; ?></td>
            <td><span class="label <?php echo mvhDeltaClass($row['deviation_percent']); ?>"><?php echo mvhPercent($row['deviation_percent']); ?></span></td>
            <td><small>Stärke <?php echo number_format($row['calculation']['factors']['quality'], 1, ',', ' '); ?><br>Alter <?php echo number_format($row['calculation']['factors']['age'], 2, ',', ' '); ?> · Liga <?php echo number_format($row['calculation']['factors']['league'], 2, ',', ' '); ?> · Verein <?php echo number_format($row['calculation']['factors']['club'], 2, ',', ' '); ?> · Fähigkeiten <?php echo number_format($row['calculation']['factors']['traits'], 2, ',', ' '); ?></small></td>
        </tr>
    <?php } ?>
    </tbody>
</table>
<?php } else { ?>
<div class="alert alert-success">Für Jugendspieler wurden mit den gewählten Filtern keine ausreichend großen Abweichungen gefunden.</div>
<?php } ?>

<hr>
<h3>Alle Marktwerte einmalig neu berechnen</h3>
<p>Dieser Lauf aktualisiert sämtliche Profis und Jugendspieler einschließlich Human- und CPU-Vereinen sowie vereinslose und inaktive Profis. Bestehende Jugend-Ablösesummen bleiben unverändert. Die Änderung erfolgt vollständig in einem Lauf und wird protokolliert.</p>
<form method="post" action="index.php" onsubmit="return confirm('Alle gespeicherten Marktwerte werden anhand der Vorschau neu berechnet. Fortfahren?');">
    <input type="hidden" name="site" value="market-value-harmonization">
    <input type="hidden" name="market_value_action" value="recalculate_all">
    <label class="checkbox"><input type="checkbox" name="confirm_recalculation" value="1"> Ich bestätige die vollständige automatische Neuberechnung aller Profi- und Jugendspieler.</label>
    <button type="submit" class="btn btn-danger"<?php if ($admin['r_demo']) echo ' disabled="disabled"'; ?>><i class="icon-refresh icon-white"></i> Alle Marktwerte neu berechnen</button>
</form>

<h3>Letzte Läufe</h3>
<?php if (count($history)) { ?>
<table class="table table-striped table-bordered table-condensed">
    <thead><tr><th>Datum</th><th>Quelle</th><th>Gesamt</th><th>Profis</th><th>Jugend</th><th>Vorher</th><th>Nachher</th><th>Gesunken</th><th>Gestiegen</th><th>Über 100 Mio. vorher / nachher</th><th>Obergrenze</th><th>Admin-ID</th></tr></thead>
    <tbody><?php foreach ($history as $row) { ?><tr>
        <td><?php echo date('d.m.Y H:i', (int) $row['created_date']); ?></td>
        <td><?php echo escapeOutput($row['source']); ?></td>
        <td><?php echo (int) $row['affected_players']; ?></td>
        <td><?php echo isset($row['affected_professional_players']) ? (int) $row['affected_professional_players'] : (int) $row['affected_players']; ?></td>
        <td><?php echo isset($row['affected_youth_players']) ? (int) $row['affected_youth_players'] : 0; ?></td>
        <td><?php echo mvhMoney($row['old_total']); ?></td>
        <td><?php echo mvhMoney($row['new_total']); ?></td>
        <td><?php echo (int) $row['decreased']; ?></td>
        <td><?php echo (int) $row['increased']; ?></td>
        <td><?php echo (int) $row['above_100m_before']; ?> / <?php echo (int) $row['above_100m_after']; ?></td>
        <td><?php echo mvhMoney($row['market_ceiling']); ?></td>
        <td><?php echo (int) $row['admin_id']; ?></td>
    </tr><?php } ?></tbody>
</table>
<?php } else { ?>
<p class="muted">Noch kein Neuberechnungslauf protokolliert.</p>
<?php } ?>
