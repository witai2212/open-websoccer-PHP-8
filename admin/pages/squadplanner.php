<?php
/******************************************************

This file is part of OpenWebSoccer-Sim.

******************************************************/

if (!$admin['r_admin'] && !$admin['r_demo'] && !$admin[$page['permissionrole']]) {
    echo '<p>' . $i18n->getMessage('error_access_denied') . '</p>';
    exit;
}

function squadPlannerAdminMoney($amount, $website) {
    return number_format((int) $amount, 0, ',', ' ') . ' ' . $website->getConfig('game_currency');
}

function squadPlannerAdminPlayerName($player) {
    return escapeOutput($player['name']);
}

function squadPlannerAdminReasons($i18n, $reasons) {
    $html = '<ul class="unstyled">';
    foreach ($reasons as $reason) {
        $html .= '<li>' . escapeOutput($i18n->getMessage($reason)) . '</li>';
    }
    $html .= '</ul>';
    return $html;
}

function squadPlannerAdminActionForm($site, $clubId, $mode, $id, $label, $btnClass, $confirm) {
    return '<form method="post" action="index.php" style="margin:0" onsubmit="return confirm(\'' . escapeOutput($confirm) . '\');">'
        . '<input type="hidden" name="site" value="' . escapeOutput($site) . '">'
        . '<input type="hidden" name="show" value="squadplanner_action">'
        . '<input type="hidden" name="clubid" value="' . (int) $clubId . '">'
        . '<input type="hidden" name="mode" value="' . escapeOutput($mode) . '">'
        . '<input type="hidden" name="id" value="' . (int) $id . '">'
        . '<button type="submit" class="btn btn-small ' . escapeOutput($btnClass) . '">' . escapeOutput($label) . '</button>'
        . '</form>';
}

$clubId = isset($_REQUEST['clubid']) ? (int) $_REQUEST['clubid'] : 0;
$clubSearch = isset($_REQUEST['clubsearch']) ? trim($_REQUEST['clubsearch']) : '';
$actionMessage = '';
$actionError = '';

if (isset($show) && $show == 'squadplanner_action') {
    try {
        if ($admin['r_demo']) {
            throw new Exception($i18n->getMessage('validationerror_no_changes_as_demo'));
        }
        $mode = isset($_POST['mode']) ? $_POST['mode'] : '';
        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $result = SquadPlannerDataService::applyAction($website, $db, $i18n, $clubId, $mode, $id, 0);
        $actionMessage = (isset($result['messages']) && count($result['messages'])) ? implode(' ', $result['messages']) : $i18n->getMessage('button_save');
    } catch (Exception $e) {
        $actionError = $e->getMessage();
    }
}

$clubs = SquadPlannerDataService::getAdminClubs($website, $db, $clubSearch, 120);
if ($clubId < 1 && count($clubs)) {
    $clubId = (int) $clubs[0]['id'];
}

$analysis = null;
if ($clubId > 0) {
    try {
        $analysis = SquadPlannerDataService::getAnalysis($website, $db, $i18n, $clubId);
    } catch (Exception $e) {
        $actionError = $e->getMessage();
    }
}
?>

<h1><?php echo $i18n->getMessage('squadplanner_admin_title'); ?></h1>
<p><?php echo $i18n->getMessage('squadplanner_admin_intro'); ?></p>

<?php if ($actionMessage) { echo createSuccessMessage($i18n->getMessage('button_save'), escapeOutput($actionMessage)); } ?>
<?php if ($actionError) { echo createErrorMessage($i18n->getMessage('generator_error'), escapeOutput($actionError)); } ?>

<form class="form-inline" method="get" action="index.php">
    <input type="hidden" name="site" value="<?php echo escapeOutput($site); ?>">
    <label><?php echo $i18n->getMessage('squadplanner_admin_search'); ?></label>
    <input type="text" name="clubsearch" value="<?php echo escapeOutput($clubSearch); ?>" class="input-xlarge">
    <label><?php echo $i18n->getMessage('squadplanner_admin_select_club'); ?></label>
    <select name="clubid" class="input-xxlarge">
        <?php foreach ($clubs as $club) { ?>
            <option value="<?php echo (int) $club['id']; ?>"<?php if ((int) $club['id'] == $clubId) echo ' selected="selected"'; ?>>
                <?php echo escapeOutput($club['name']); ?><?php if ($club['league_name']) echo ' - ' . escapeOutput($club['league_name']); ?>
            </option>
        <?php } ?>
    </select>
    <button type="submit" class="btn btn-primary"><?php echo $i18n->getMessage('squadplanner_admin_execute'); ?></button>
</form>

<?php if ($analysis) { ?>

<p>
    <?php echo squadPlannerAdminActionForm($site, $clubId, 'auto', 0, $i18n->getMessage('squadplanner_auto_button'), 'btn-primary', $i18n->getMessage('squadplanner_auto_confirm')); ?>
</p>

<h3><?php echo $i18n->getMessage('squadplanner_summary'); ?></h3>
<table class="table table-bordered table-striped">
    <tbody>
        <tr>
            <th><?php echo $i18n->getMessage('squadplanner_team'); ?></th>
            <td><?php echo escapeOutput($analysis['team']['name']); ?> <?php if ($analysis['team']['league_name']) echo '<small class="muted">(' . escapeOutput($analysis['team']['league_name']) . ')</small>'; ?></td>
            <th><?php echo $i18n->getMessage('squadplanner_players'); ?></th>
            <td><?php echo (int) $analysis['summary']['player_count']; ?></td>
        </tr>
        <tr>
            <th><?php echo $i18n->getMessage('squadplanner_avg_age'); ?></th>
            <td><?php echo number_format($analysis['summary']['avg_age'], 1, ',', ' '); ?></td>
            <th><?php echo $i18n->getMessage('squadplanner_avg_strength'); ?></th>
            <td><?php echo number_format($analysis['summary']['avg_strength'], 1, ',', ' '); ?></td>
        </tr>
        <tr>
            <th><?php echo $i18n->getMessage('squadplanner_age_structure'); ?></th>
            <td><?php echo $i18n->getMessage($analysis['age_structure']['label_key']); ?></td>
            <th><?php echo $i18n->getMessage('squadplanner_contract_risk'); ?></th>
            <td><?php echo (int) $analysis['summary']['contract_risk_count']; ?></td>
        </tr>
    </tbody>
</table>

<h3><?php echo $i18n->getMessage('squadplanner_depth'); ?></h3>
<table class="table table-striped table-bordered">
    <thead>
        <tr>
            <th><?php echo $i18n->getMessage('playertable_head_position'); ?></th>
            <th><?php echo $i18n->getMessage('squadplanner_count'); ?></th>
            <th><?php echo $i18n->getMessage('squadplanner_target'); ?></th>
            <th><?php echo $i18n->getMessage('squadplanner_avg_strength'); ?></th>
            <th><?php echo $i18n->getMessage('squadplanner_status'); ?></th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($analysis['depth'] as $row) { ?>
            <tr>
                <td><?php echo $i18n->getMessage('player_position_' . $row['key']); ?></td>
                <td><?php echo (int) $row['count']; ?></td>
                <td><?php echo (int) $row['target']; ?></td>
                <td><?php echo number_format($row['avg_strength'], 1, ',', ' '); ?></td>
                <td>
                    <?php if ($row['status'] == 'shortage') { ?><span class="label label-important"><?php echo $i18n->getMessage('squadplanner_shortage'); ?></span><?php } ?>
                    <?php if ($row['status'] == 'surplus') { ?><span class="label label-warning"><?php echo $i18n->getMessage('squadplanner_surplus'); ?></span><?php } ?>
                    <?php if ($row['status'] == 'ok') { ?><span class="label label-success"><?php echo $i18n->getMessage('squadplanner_ok'); ?></span><?php } ?>
                </td>
            </tr>
        <?php } ?>
    </tbody>
</table>

<h3><?php echo $i18n->getMessage('squadplanner_weaknesses'); ?></h3>
<?php if (count($analysis['weaknesses'])) { ?>
    <ul><?php foreach ($analysis['weaknesses'] as $weakness) { ?><li><?php echo escapeOutput($weakness['message']); ?></li><?php } ?></ul>
<?php } else { ?>
    <p><?php echo $i18n->getMessage('squadplanner_no_weaknesses'); ?></p>
<?php } ?>

<h3><?php echo $i18n->getMessage('squadplanner_trait_needs'); ?></h3>
<?php if (isset($analysis['trait_needs']) && count($analysis['trait_needs'])) { ?>
<table class="table table-striped table-bordered">
    <thead><tr><th><?php echo $i18n->getMessage('squadplanner_trait'); ?></th><th><?php echo $i18n->getMessage('playertable_head_position'); ?></th><th><?php echo $i18n->getMessage('squadplanner_count'); ?></th><th><?php echo $i18n->getMessage('squadplanner_recommended'); ?></th><th><?php echo $i18n->getMessage('squadplanner_priority'); ?></th></tr></thead>
    <tbody>
    <?php foreach ($analysis['trait_needs'] as $need) { ?>
        <tr><td><?php echo $i18n->getMessage($need['label_key']); ?></td><td><?php echo $i18n->getMessage('player_position_' . $need['position_key']); ?></td><td><?php echo (int) $need['current_count']; ?></td><td><?php echo (int) $need['needed_count']; ?></td><td><?php echo (int) $need['priority']; ?></td></tr>
    <?php } ?>
    </tbody>
</table>
<?php } else { ?><p><?php echo $i18n->getMessage('squadplanner_no_trait_needs'); ?></p><?php } ?>

<h3><?php echo $i18n->getMessage('squadplanner_contract_risk'); ?></h3>
<?php if (count($analysis['contract_risks'])) { ?>
<table class="table table-striped table-bordered">
    <thead><tr><th><?php echo $i18n->getMessage('playertable_head_name'); ?></th><th><?php echo $i18n->getMessage('playertable_head_position'); ?></th><th><?php echo $i18n->getMessage('squadplanner_contract'); ?></th><th><?php echo $i18n->getMessage('squadplanner_strength'); ?></th></tr></thead>
    <tbody>
    <?php foreach ($analysis['contract_risks'] as $player) { ?>
        <tr><td><?php echo squadPlannerAdminPlayerName($player); ?></td><td><?php echo $i18n->getMessage('player_position_' . $player['position_key']); ?></td><td><?php echo (int) $player['contract_matches']; ?></td><td><?php echo number_format($player['strength'], 1, ',', ' '); ?></td></tr>
    <?php } ?>
    </tbody>
</table>
<?php } else { ?><p><?php echo $i18n->getMessage('squadplanner_no_candidates'); ?></p><?php } ?>

<h3><?php echo $i18n->getMessage('squadplanner_sell_candidates'); ?></h3>
<?php if (count($analysis['sell_candidates'])) { ?>
<table class="table table-striped table-bordered">
    <thead><tr><th><?php echo $i18n->getMessage('playertable_head_name'); ?></th><th><?php echo $i18n->getMessage('playertable_head_position'); ?></th><th><?php echo $i18n->getMessage('squadplanner_age'); ?></th><th><?php echo $i18n->getMessage('squadplanner_strength'); ?></th><th><?php echo $i18n->getMessage('squadplanner_marketvalue'); ?></th><th><?php echo $i18n->getMessage('squadplanner_reason'); ?></th><th><?php echo $i18n->getMessage('squadplanner_manual_actions'); ?></th></tr></thead>
    <tbody>
    <?php foreach ($analysis['sell_candidates'] as $player) { ?>
        <tr>
            <td><?php echo squadPlannerAdminPlayerName($player); ?></td>
            <td><?php echo $i18n->getMessage('player_position_' . $player['position_key']); ?></td>
            <td><?php echo (int) $player['age']; ?></td>
            <td><?php echo number_format($player['strength'], 1, ',', ' '); ?></td>
            <td><?php echo squadPlannerAdminMoney($player['marketvalue'], $website); ?></td>
            <td><?php echo squadPlannerAdminReasons($i18n, $player['reasons']); ?></td>
            <td><?php echo squadPlannerAdminActionForm($site, $clubId, 'sell', $player['id'], $i18n->getMessage('squadplanner_apply_sell'), 'btn-warning', $i18n->getMessage('squadplanner_apply_sell') . '?'); ?></td>
        </tr>
    <?php } ?>
    </tbody>
</table>
<?php } else { ?><p><?php echo $i18n->getMessage('squadplanner_no_candidates'); ?></p><?php } ?>

<h3><?php echo $i18n->getMessage('squadplanner_loan_candidates'); ?></h3>
<?php if (count($analysis['loan_candidates'])) { ?>
<table class="table table-striped table-bordered">
    <thead><tr><th><?php echo $i18n->getMessage('playertable_head_name'); ?></th><th><?php echo $i18n->getMessage('playertable_head_position'); ?></th><th><?php echo $i18n->getMessage('squadplanner_age'); ?></th><th><?php echo $i18n->getMessage('squadplanner_strength'); ?></th><th><?php echo $i18n->getMessage('squadplanner_talent'); ?></th><th><?php echo $i18n->getMessage('squadplanner_reason'); ?></th><th><?php echo $i18n->getMessage('squadplanner_manual_actions'); ?></th></tr></thead>
    <tbody>
    <?php foreach ($analysis['loan_candidates'] as $player) { ?>
        <tr>
            <td><?php echo squadPlannerAdminPlayerName($player); ?></td>
            <td><?php echo $i18n->getMessage('player_position_' . $player['position_key']); ?></td>
            <td><?php echo (int) $player['age']; ?></td>
            <td><?php echo number_format($player['strength'], 1, ',', ' '); ?></td>
            <td><?php echo (int) $player['talent']; ?></td>
            <td><?php echo squadPlannerAdminReasons($i18n, $player['reasons']); ?></td>
            <td><?php echo squadPlannerAdminActionForm($site, $clubId, 'loan', $player['id'], $i18n->getMessage('squadplanner_apply_loan'), 'btn-info', $i18n->getMessage('squadplanner_apply_loan') . '?'); ?></td>
        </tr>
    <?php } ?>
    </tbody>
</table>
<?php } else { ?><p><?php echo $i18n->getMessage('squadplanner_no_candidates'); ?></p><?php } ?>

<h3><?php echo $i18n->getMessage('squadplanner_youth_candidates'); ?></h3>
<?php if (count($analysis['youth_candidates'])) { ?>
<table class="table table-striped table-bordered">
    <thead><tr><th><?php echo $i18n->getMessage('playertable_head_name'); ?></th><th><?php echo $i18n->getMessage('playertable_head_position'); ?></th><th><?php echo $i18n->getMessage('squadplanner_age'); ?></th><th><?php echo $i18n->getMessage('squadplanner_strength'); ?></th><th><?php echo $i18n->getMessage('squadplanner_reason'); ?></th><th><?php echo $i18n->getMessage('squadplanner_manual_actions'); ?></th></tr></thead>
    <tbody>
    <?php foreach ($analysis['youth_candidates'] as $player) { ?>
        <tr>
            <td><?php echo squadPlannerAdminPlayerName($player); ?></td>
            <td><?php echo $i18n->getMessage('player_position_' . $player['position_key']); ?></td>
            <td><?php echo (int) $player['age']; ?></td>
            <td><?php echo number_format($player['strength'], 1, ',', ' '); ?></td>
            <td><?php echo squadPlannerAdminReasons($i18n, $player['reasons']); ?></td>
            <td>
                <?php if ($player['promotable']) { ?>
                    <?php echo squadPlannerAdminActionForm($site, $clubId, 'youth', $player['id'], $i18n->getMessage('squadplanner_apply_youth'), 'btn-success', $i18n->getMessage('squadplanner_apply_youth') . '?'); ?>
                <?php } else { ?>
                    <span class="muted"><?php echo $i18n->getMessage('squadplanner_not_promotable_yet'); ?></span>
                <?php } ?>
            </td>
        </tr>
    <?php } ?>
    </tbody>
</table>
<?php } else { ?><p><?php echo $i18n->getMessage('squadplanner_no_candidates'); ?></p><?php } ?>

<?php } ?>
