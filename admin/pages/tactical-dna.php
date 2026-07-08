<?php
/******************************************************

  Club Identity / Tactical DNA admin page.

******************************************************/

if (!$admin['r_admin'] && !$admin['r_demo'] && !$admin[$page['permissionrole']]) {
    echo '<p>' . $i18n->getMessage('error_access_denied') . '</p>';
    exit;
}

function tdnaAdminMsg($i18n, $key, $fallback) {
    return $i18n->hasMessage($key) ? $i18n->getMessage($key) : $fallback;
}

function tdnaSigned($value) {
    $value = (int) $value;
    return ($value > 0 ? '+' : '') . $value;
}

function tdnaLabelClass($fit) {
    $fit = (int) $fit;
    if ($fit >= 75) return 'label-success';
    if ($fit <= 45) return 'label-important';
    return 'label-info';
}

if (class_exists('TacticalStyleDataService')) {
    TacticalStyleDataService::ensureSchema($website, $db);
}

$selectedClubId = isset($_REQUEST['club_id']) ? (int) $_REQUEST['club_id'] : 0;
$notice = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && !$admin['r_demo']) {
    $selectedClubId = isset($_POST['club_id']) ? (int) $_POST['club_id'] : 0;
    $adminAction = isset($_POST['admin_action']) ? $_POST['admin_action'] : '';
    try {
        if ($adminAction == 'set_style') {
            $style = isset($_POST['style']) ? $_POST['style'] : '';
            $result = TacticalStyleDataService::saveAdminStyle($website, $db, $i18n, $selectedClubId, $style);
            $notice = tdnaAdminMsg($i18n, 'tacticaldna_admin_saved', 'Taktische DNA gespeichert.');
        } elseif ($adminAction == 'recommend_style') {
            $result = TacticalStyleDataService::saveRecommendedStyle($website, $db, $i18n, $selectedClubId, 'admin_auto');
            $notice = tdnaAdminMsg($i18n, 'tacticaldna_admin_recommended_saved', 'Empfohlener Stil gespeichert.');
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$clubs = TacticalStyleDataService::getAdminClubOptions($website, $db);
if ($selectedClubId < 1 && count($clubs)) {
    $selectedClubId = (int) $clubs[0]['id'];
}
$teamData = ($selectedClubId > 0) ? TacticalStyleDataService::getTeamIdentityData($website, $db, $i18n, $selectedClubId, 0) : array('enabled' => FALSE);
$overview = TacticalStyleDataService::getAdminOverview($website, $db, $i18n, 200);
?>

<h1><?php echo $i18n->getMessage('tacticaldna_admin_title'); ?></h1>
<p><?php echo $i18n->getMessage('tacticaldna_admin_intro'); ?></p>

<?php if ($notice) { echo createSuccessMessage($i18n->getMessage('alert_success_title'), $notice); } ?>
<?php if ($error) { echo createErrorMessage($i18n->getMessage('alert_error_title'), $error); } ?>

<form class="form-inline" method="get" action="index.php">
    <input type="hidden" name="site" value="<?php echo escapeOutput($site); ?>">
    <div class="control-group" style="margin-bottom: 8px;">
        <label for="tdna-club-search" style="display: inline-block; margin-right: 8px;">
            <?php echo $i18n->getMessage('tacticaldna_admin_club_search'); ?>
        </label>
        <input type="text" id="tdna-club-search" class="input-xxlarge" autocomplete="off" placeholder="<?php echo escapeOutput($i18n->getMessage('tacticaldna_admin_club_search_placeholder')); ?>">
        <span class="help-inline" id="tdna-club-search-count"></span>
    </div>
    <select name="club_id" id="tdna-club-select" class="input-xxlarge">
        <?php foreach ($clubs as $club) { ?>
            <?php $clubLabel = $club['name'] . ($club['league_name'] ? ' (' . $club['league_name'] . ')' : ''); ?>
            <option value="<?php echo (int) $club['id']; ?>" data-search="<?php echo escapeOutput(strtolower($clubLabel)); ?>"<?php if ((int) $club['id'] == $selectedClubId) echo ' selected="selected"'; ?>>
                <?php echo escapeOutput($clubLabel); ?>
            </option>
        <?php } ?>
    </select>
    <button type="submit" class="btn btn-primary"><?php echo $i18n->getMessage('tacticaldna_admin_apply'); ?></button>
</form>

<script type="text/javascript">
(function() {
    var input = document.getElementById('tdna-club-search');
    var select = document.getElementById('tdna-club-select');
    var counter = document.getElementById('tdna-club-search-count');
    if (!input || !select) return;
    var originalOptions = [];
    for (var i = 0; i < select.options.length; i++) {
        originalOptions.push({
            value: select.options[i].value,
            text: select.options[i].text,
            search: select.options[i].getAttribute('data-search') || select.options[i].text.toLowerCase()
        });
    }
    function renderOptions() {
        var query = (input.value || '').toLowerCase();
        var previousValue = select.value;
        var matches = [];
        select.innerHTML = '';
        for (var i = 0; i < originalOptions.length; i++) {
            if (!query || originalOptions[i].search.indexOf(query) !== -1) {
                matches.push(originalOptions[i]);
                var option = document.createElement('option');
                option.value = originalOptions[i].value;
                option.text = originalOptions[i].text;
                option.setAttribute('data-search', originalOptions[i].search);
                select.appendChild(option);
            }
        }
        if (matches.length > 0) {
            select.value = previousValue;
            if (select.selectedIndex < 0) select.selectedIndex = 0;
        }
        if (counter) counter.innerHTML = matches.length + ' / ' + originalOptions.length;
    }
    input.onkeyup = renderOptions;
    input.onchange = renderOptions;
    renderOptions();
})();
</script>

<?php if ($teamData['enabled'] && isset($teamData['current']['key'])) { ?>
<div class="row-fluid">
    <div class="span5">
        <h3><?php echo $i18n->getMessage('tacticaldna_admin_selected_club'); ?></h3>
        <table class="table table-bordered table-striped">
            <tbody>
                <tr>
                    <th><?php echo $i18n->getMessage('tacticaldna_current_style'); ?></th>
                    <td><?php echo escapeOutput($teamData['current']['label']); ?></td>
                </tr>
                <tr>
                    <th><?php echo $i18n->getMessage('formation_tacticalstyle_fit'); ?></th>
                    <td><span class="label <?php echo escapeOutput($teamData['current']['fit_class']); ?>"><?php echo (int) $teamData['current']['fit']; ?>%</span></td>
                </tr>
                <tr>
                    <th><?php echo $i18n->getMessage('teamchemistry_match_effect'); ?></th>
                    <td><?php echo escapeOutput($teamData['current']['effect_signed']); ?></td>
                </tr>
                <tr>
                    <th><?php echo $i18n->getMessage('tacticaldna_transition_effect'); ?></th>
                    <td><?php echo escapeOutput($teamData['current']['change_effect_signed']); ?></td>
                </tr>
                <tr>
                    <th><?php echo $i18n->getMessage('tacticaldna_recommended'); ?></th>
                    <td><?php echo escapeOutput($teamData['recommendation']['label'] . ' (' . $teamData['recommendation']['fit'] . '%)'); ?></td>
                </tr>
            </tbody>
        </table>

        <?php if (!$admin['r_demo']) { ?>
        <form method="post" action="index.php?site=<?php echo urlencode($site); ?>&club_id=<?php echo (int) $selectedClubId; ?>" class="form-inline">
            <input type="hidden" name="club_id" value="<?php echo (int) $selectedClubId; ?>">
            <input type="hidden" name="admin_action" value="set_style">
            <select name="style" class="input-large">
                <?php foreach ($teamData['styles'] as $style) { ?>
                    <option value="<?php echo escapeOutput($style['key']); ?>"<?php if ($style['selected']) echo ' selected="selected"'; ?>>
                        <?php echo escapeOutput($style['label'] . ' (' . $style['fit'] . '%)'); ?>
                    </option>
                <?php } ?>
            </select>
            <button type="submit" class="btn btn-primary"><?php echo $i18n->getMessage('button_save'); ?></button>
        </form>
        <form method="post" action="index.php?site=<?php echo urlencode($site); ?>&club_id=<?php echo (int) $selectedClubId; ?>" style="margin-top: 8px;">
            <input type="hidden" name="club_id" value="<?php echo (int) $selectedClubId; ?>">
            <input type="hidden" name="admin_action" value="recommend_style">
            <button type="submit" class="btn"><?php echo $i18n->getMessage('tacticaldna_admin_save_recommended'); ?></button>
        </form>
        <?php } ?>
    </div>

    <div class="span7">
        <h3><?php echo $i18n->getMessage('tacticaldna_style_comparison'); ?></h3>
        <table class="table table-striped table-condensed">
            <thead>
                <tr>
                    <th><?php echo $i18n->getMessage('formation_tacticalstyle_select'); ?></th>
                    <th><?php echo $i18n->getMessage('formation_tacticalstyle_fit'); ?></th>
                    <th><?php echo $i18n->getMessage('teamchemistry_match_effect'); ?></th>
                    <th><?php echo $i18n->getMessage('tacticaldna_change_preview'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($teamData['styles'] as $style) { ?>
                    <tr<?php if ($style['selected']) echo ' class="success"'; ?>>
                        <td>
                            <strong><?php echo escapeOutput($style['label']); ?></strong>
                            <?php if ($style['recommended']) { ?><span class="label label-success"><?php echo $i18n->getMessage('tacticaldna_recommended'); ?></span><?php } ?>
                            <br><span class="muted"><?php echo escapeOutput($style['description']); ?></span>
                        </td>
                        <td><span class="label <?php echo escapeOutput($style['fit_class']); ?>"><?php echo (int) $style['fit']; ?>%</span></td>
                        <td><?php echo escapeOutput($style['effect_signed']); ?></td>
                        <td><?php echo $style['selected'] ? '-' : escapeOutput(tdnaSigned($style['change_effect_preview'])); ?></td>
                    </tr>
                <?php } ?>
            </tbody>
        </table>
    </div>
</div>
<?php } ?>

<h3><?php echo $i18n->getMessage('tacticaldna_admin_overview'); ?></h3>
<p class="muted"><?php echo $i18n->getMessage('tacticaldna_admin_overview_help'); ?></p>
<table class="table table-striped table-bordered table-condensed">
    <thead>
        <tr>
            <th><?php echo $i18n->getMessage('entity_club'); ?></th>
            <th><?php echo $i18n->getMessage('entity_club_liga_id'); ?></th>
            <th><?php echo $i18n->getMessage('team_details_manager'); ?></th>
            <th><?php echo $i18n->getMessage('tacticaldna_current_style'); ?></th>
            <th><?php echo $i18n->getMessage('formation_tacticalstyle_fit'); ?></th>
            <th><?php echo $i18n->getMessage('teamchemistry_score'); ?></th>
            <th><?php echo $i18n->getMessage('teamchemistry_match_effect'); ?></th>
            <th><?php echo $i18n->getMessage('tacticaldna_transition_effect'); ?></th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($overview as $row) { ?>
            <tr>
                <td><a href="index.php?site=<?php echo urlencode($site); ?>&club_id=<?php echo (int) $row['id']; ?>"><?php echo escapeOutput($row['name']); ?></a></td>
                <td><?php echo escapeOutput($row['league_name']); ?></td>
                <td><?php echo $row['manager_name'] ? escapeOutput($row['manager_name']) : '<span class="muted">CPU</span>'; ?></td>
                <td><?php echo escapeOutput($row['style_label']); ?></td>
                <td><span class="label <?php echo escapeOutput(tdnaLabelClass($row['tactical_style_fit'])); ?>"><?php echo (int) $row['tactical_style_fit']; ?>%</span></td>
                <td><?php echo isset($row['team_chemistry']) ? (int) $row['team_chemistry'] . '%' : '-'; ?></td>
                <td><?php echo escapeOutput($row['effect_signed']); ?></td>
                <td><?php echo escapeOutput($row['change_effect_signed']); ?></td>
            </tr>
        <?php } ?>
    </tbody>
</table>
