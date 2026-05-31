<?php
/******************************************************

This file is part of OpenWebSoccer-Sim.

******************************************************/

if (!$admin['r_admin'] && !$admin['r_demo']) {
    echo '<p>' . $i18n->getMessage('error_access_denied') . '</p>';
    exit;
}

function economyStatsFormatCurrency($amount) {
    return number_format((int) round($amount), 0, ',', ' ') . ' EUR';
}

function economyStatsBadge($amount) {
    $amount = (int) $amount;
    if ($amount > 0) {
        return '<span class="label label-success">+' . economyStatsFormatCurrency($amount) . '</span>';
    }
    if ($amount < 0) {
        return '<span class="label label-important">' . economyStatsFormatCurrency($amount) . '</span>';
    }
    return '<span class="label">0 EUR</span>';
}

$seasonId = isset($_REQUEST['season_id']) ? (int) $_REQUEST['season_id'] : 0;
$matchday = isset($_REQUEST['matchday']) ? (int) $_REQUEST['matchday'] : 0;

$regulationResult = NULL;
$regulationError = '';
if ($show == 'regulate') {
    try {
        if ($admin['r_demo']) {
            throw new Exception($i18n->getMessage('validationerror_no_changes_as_demo'));
        }
        $actionKey = isset($_POST['regulation_action']) ? trim($_POST['regulation_action']) : '';
        $regulationResult = FinancialEconomyStatsDataService::applyMarketRegulation($website, $db, $actionKey);
    } catch (Exception $e) {
        $regulationError = $e->getMessage();
    }
}

$seasons = FinancialEconomyStatsDataService::getAvailableSeasons($website, $db);
$matchdays = FinancialEconomyStatsDataService::getAvailableMatchdays($website, $db, $seasonId);
$allStats = FinancialEconomyStatsDataService::getEconomyStats($website, $db, $seasonId, $matchday, FALSE);
$humanStats = FinancialEconomyStatsDataService::getEconomyStats($website, $db, $seasonId, $matchday, TRUE);
$recommendations = FinancialEconomyStatsDataService::getMarketRecommendations($humanStats, $allStats);

?>

<h1><?php echo $i18n->getMessage('financial_economy_stats_admin_title'); ?></h1>
<p><?php echo $i18n->getMessage('financial_economy_stats_admin_intro'); ?></p>

<?php if ($regulationResult) { ?>
    <?php echo createSuccessMessage($i18n->getMessage('financial_economy_stats_regulation_success'), escapeOutput($regulationResult['label'])); ?>
    <table class="table table-condensed table-bordered">
        <thead>
            <tr>
                <th><?php echo $i18n->getMessage('financial_economy_stats_table'); ?></th>
                <th><?php echo $i18n->getMessage('financial_economy_stats_columns'); ?></th>
                <th><?php echo $i18n->getMessage('financial_economy_stats_affected_rows'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($regulationResult['updates'] as $update) { ?>
                <tr>
                    <td><?php echo escapeOutput($update['table']); ?><?php if ($update['skipped']) echo ' <span class="label">übersprungen</span>'; ?></td>
                    <td><?php echo escapeOutput($update['columns']); ?></td>
                    <td><?php echo (int) $update['affected_rows']; ?></td>
                </tr>
            <?php } ?>
        </tbody>
    </table>
<?php } ?>
<?php if ($regulationError) { ?>
    <?php echo createErrorMessage($i18n->getMessage('generator_error'), escapeOutput($regulationError)); ?>
<?php } ?>

<form class="form-inline" method="get" action="index.php">
    <input type="hidden" name="site" value="<?php echo escapeOutput($site); ?>">
    <label><?php echo $i18n->getMessage('financial_economy_stats_filter_season'); ?></label>
    <select name="season_id" class="input-xlarge">
        <option value="0"><?php echo $i18n->getMessage('financial_economy_stats_filter_all'); ?></option>
        <?php foreach ($seasons as $season) { ?>
            <option value="<?php echo (int) $season['id']; ?>"<?php if ($seasonId == (int) $season['id']) echo ' selected="selected"'; ?>>
                <?php echo escapeOutput($season['name'] . ' - ' . $season['league_name']); ?>
            </option>
        <?php } ?>
    </select>
    <label><?php echo $i18n->getMessage('financial_economy_stats_filter_matchday'); ?></label>
    <select name="matchday" class="input-small">
        <option value="0"><?php echo $i18n->getMessage('financial_economy_stats_filter_all'); ?></option>
        <?php foreach ($matchdays as $md) { ?>
            <option value="<?php echo (int) $md; ?>"<?php if ($matchday == (int) $md) echo ' selected="selected"'; ?>><?php echo (int) $md; ?></option>
        <?php } ?>
    </select>
    <button type="submit" class="btn btn-primary"><?php echo $i18n->getMessage('button_display'); ?></button>
</form>

<h3><?php echo $i18n->getMessage('financial_economy_stats_humans'); ?></h3>
<table class="table table-bordered table-striped">
    <tbody>
        <tr>
            <th><?php echo $i18n->getMessage('financial_economy_stats_clubs'); ?></th>
            <td><?php echo (int) $humanStats['summary']['team_count']; ?></td>
            <th><?php echo $i18n->getMessage('financial_economy_stats_bookings'); ?></th>
            <td><?php echo (int) $humanStats['summary']['booking_count']; ?></td>
        </tr>
        <tr>
            <th><?php echo $i18n->getMessage('financial_economy_stats_income'); ?></th>
            <td><?php echo economyStatsFormatCurrency($humanStats['summary']['income_total']); ?></td>
            <th><?php echo $i18n->getMessage('financial_economy_stats_avg_income'); ?></th>
            <td><?php echo economyStatsFormatCurrency($humanStats['summary']['avg_income_per_club']); ?></td>
        </tr>
        <tr>
            <th><?php echo $i18n->getMessage('financial_economy_stats_expenses'); ?></th>
            <td><?php echo economyStatsFormatCurrency($humanStats['summary']['expense_total']); ?></td>
            <th><?php echo $i18n->getMessage('financial_economy_stats_avg_expenses'); ?></th>
            <td><?php echo economyStatsFormatCurrency($humanStats['summary']['avg_expense_per_club']); ?></td>
        </tr>
        <tr>
            <th><?php echo $i18n->getMessage('financial_economy_stats_net'); ?></th>
            <td><?php echo economyStatsBadge($humanStats['summary']['net_total']); ?></td>
            <th><?php echo $i18n->getMessage('financial_economy_stats_avg_net'); ?></th>
            <td><?php echo economyStatsBadge($humanStats['summary']['avg_net_per_club']); ?></td>
        </tr>
    </tbody>
</table>

<h3><?php echo $i18n->getMessage('financial_economy_stats_all_clubs'); ?></h3>
<table class="table table-bordered table-striped">
    <tbody>
        <tr>
            <th><?php echo $i18n->getMessage('financial_economy_stats_clubs'); ?></th>
            <td><?php echo (int) $allStats['summary']['team_count']; ?></td>
            <th><?php echo $i18n->getMessage('financial_economy_stats_bookings'); ?></th>
            <td><?php echo (int) $allStats['summary']['booking_count']; ?></td>
        </tr>
        <tr>
            <th><?php echo $i18n->getMessage('financial_economy_stats_income'); ?></th>
            <td><?php echo economyStatsFormatCurrency($allStats['summary']['income_total']); ?></td>
            <th><?php echo $i18n->getMessage('financial_economy_stats_avg_income'); ?></th>
            <td><?php echo economyStatsFormatCurrency($allStats['summary']['avg_income_per_club']); ?></td>
        </tr>
        <tr>
            <th><?php echo $i18n->getMessage('financial_economy_stats_expenses'); ?></th>
            <td><?php echo economyStatsFormatCurrency($allStats['summary']['expense_total']); ?></td>
            <th><?php echo $i18n->getMessage('financial_economy_stats_avg_expenses'); ?></th>
            <td><?php echo economyStatsFormatCurrency($allStats['summary']['avg_expense_per_club']); ?></td>
        </tr>
        <tr>
            <th><?php echo $i18n->getMessage('financial_economy_stats_net'); ?></th>
            <td><?php echo economyStatsBadge($allStats['summary']['net_total']); ?></td>
            <th><?php echo $i18n->getMessage('financial_economy_stats_avg_net'); ?></th>
            <td><?php echo economyStatsBadge($allStats['summary']['avg_net_per_club']); ?></td>
        </tr>
    </tbody>
</table>


<h3><?php echo $i18n->getMessage('financial_economy_stats_recommendations'); ?></h3>
<p><?php echo $i18n->getMessage('financial_economy_stats_recommendations_intro'); ?></p>
<table class="table table-striped table-bordered">
    <thead>
        <tr>
            <th><?php echo $i18n->getMessage('financial_economy_stats_status'); ?></th>
            <th><?php echo $i18n->getMessage('financial_economy_stats_recommendation'); ?></th>
            <th><?php echo $i18n->getMessage('financial_economy_stats_effect'); ?></th>
            <th><?php echo $i18n->getMessage('financial_economy_stats_action'); ?></th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($recommendations as $recommendation) { ?>
            <tr>
                <td><span class="label label-<?php echo escapeOutput($recommendation['severity']); ?>"><?php echo escapeOutput($recommendation['severity']); ?></span></td>
                <td>
                    <strong><?php echo escapeOutput($recommendation['title']); ?></strong><br>
                    <?php echo escapeOutput($recommendation['message']); ?>
                </td>
                <td><?php echo escapeOutput($recommendation['effect']); ?></td>
                <td>
                    <?php if (!empty($recommendation['action_key'])) { ?>
                        <form method="post" action="index.php" style="margin:0" onsubmit="return confirm('<?php echo escapeOutput($i18n->getMessage('financial_economy_stats_regulation_confirm')); ?>');">
                            <input type="hidden" name="site" value="<?php echo escapeOutput($site); ?>">
                            <input type="hidden" name="show" value="regulate">
                            <input type="hidden" name="season_id" value="<?php echo (int) $seasonId; ?>">
                            <input type="hidden" name="matchday" value="<?php echo (int) $matchday; ?>">
                            <input type="hidden" name="regulation_action" value="<?php echo escapeOutput($recommendation['action_key']); ?>">
                            <button type="submit" class="btn btn-small btn-warning"><?php echo escapeOutput($recommendation['action_label']); ?></button>
                        </form>
                    <?php } else { ?>
                        <span class="muted">-</span>
                    <?php } ?>
                </td>
            </tr>
        <?php } ?>
    </tbody>
</table>

<h3><?php echo $i18n->getMessage('financial_economy_stats_categories'); ?> - <?php echo $i18n->getMessage('financial_economy_stats_humans'); ?></h3>
<table class="table table-striped table-bordered">
    <thead>
        <tr>
            <th><?php echo $i18n->getMessage('financial_economy_stats_category'); ?></th>
            <th><?php echo $i18n->getMessage('financial_economy_stats_income'); ?></th>
            <th><?php echo $i18n->getMessage('financial_economy_stats_expenses'); ?></th>
            <th><?php echo $i18n->getMessage('financial_economy_stats_net'); ?></th>
            <th><?php echo $i18n->getMessage('financial_economy_stats_avg_net'); ?></th>
            <th><?php echo $i18n->getMessage('financial_economy_stats_bookings'); ?></th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($humanStats['categories'] as $category) { ?>
            <tr>
                <td><?php echo escapeOutput($category['category']); ?></td>
                <td><?php echo economyStatsFormatCurrency($category['income_total']); ?></td>
                <td><?php echo economyStatsFormatCurrency($category['expense_total']); ?></td>
                <td><?php echo economyStatsBadge($category['net_total']); ?></td>
                <td><?php echo economyStatsBadge($category['avg_net_per_club']); ?></td>
                <td><?php echo (int) $category['booking_count']; ?></td>
            </tr>
        <?php } ?>
    </tbody>
</table>

<h3><?php echo $i18n->getMessage('financial_economy_stats_cross_checks'); ?></h3>
<p><?php echo $i18n->getMessage('financial_economy_stats_cross_checks_intro'); ?></p>
<table class="table table-striped table-bordered">
    <thead>
        <tr>
            <th><?php echo $i18n->getMessage('financial_economy_stats_feature'); ?></th>
            <th><?php echo $i18n->getMessage('financial_economy_stats_feature_amount'); ?></th>
            <th><?php echo $i18n->getMessage('financial_economy_stats_booked_amount'); ?></th>
            <th><?php echo $i18n->getMessage('financial_economy_stats_difference'); ?></th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($humanStats['cross_checks'] as $row) { ?>
            <tr>
                <td><?php echo escapeOutput($row['label']); ?></td>
                <td><?php echo economyStatsFormatCurrency($row['feature_amount']); ?></td>
                <td><?php echo economyStatsFormatCurrency($row['booked_amount']); ?></td>
                <td><?php echo economyStatsBadge(0 - $row['difference']); ?></td>
            </tr>
        <?php } ?>
    </tbody>
</table>

<h3><?php echo $i18n->getMessage('financial_economy_stats_current_liabilities'); ?></h3>
<table class="table table-striped table-bordered">
    <thead>
        <tr>
            <th><?php echo $i18n->getMessage('financial_economy_stats_feature'); ?></th>
            <th><?php echo $i18n->getMessage('financial_economy_stats_amount_per_matchday'); ?></th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($humanStats['liabilities'] as $row) { ?>
            <tr>
                <td><?php echo escapeOutput($row['label']); ?></td>
                <td><?php echo economyStatsFormatCurrency($row['amount']); ?></td>
            </tr>
        <?php } ?>
    </tbody>
</table>

<div class="row-fluid">
    <div class="span6">
        <h3><?php echo $i18n->getMessage('financial_economy_stats_biggest_losses'); ?></h3>
        <table class="table table-striped table-bordered">
            <thead><tr><th>Verein</th><th>Manager</th><th><?php echo $i18n->getMessage('financial_economy_stats_net'); ?></th><th>Budget</th></tr></thead>
            <tbody>
                <?php foreach ($humanStats['club_ranking']['deficits'] as $club) { ?>
                    <tr>
                        <td><a href="../?page=team&id=<?php echo (int) $club['id']; ?>" target="_blank"><?php echo escapeOutput($club['name']); ?></a></td>
                        <td><?php echo escapeOutput($club['manager_name']); ?></td>
                        <td><?php echo economyStatsBadge($club['net_total']); ?></td>
                        <td><?php echo economyStatsFormatCurrency($club['finanz_budget']); ?></td>
                    </tr>
                <?php } ?>
            </tbody>
        </table>
    </div>
    <div class="span6">
        <h3><?php echo $i18n->getMessage('financial_economy_stats_biggest_profits'); ?></h3>
        <table class="table table-striped table-bordered">
            <thead><tr><th>Verein</th><th>Manager</th><th><?php echo $i18n->getMessage('financial_economy_stats_net'); ?></th><th>Budget</th></tr></thead>
            <tbody>
                <?php foreach ($humanStats['club_ranking']['surpluses'] as $club) { ?>
                    <tr>
                        <td><a href="../?page=team&id=<?php echo (int) $club['id']; ?>" target="_blank"><?php echo escapeOutput($club['name']); ?></a></td>
                        <td><?php echo escapeOutput($club['manager_name']); ?></td>
                        <td><?php echo economyStatsBadge($club['net_total']); ?></td>
                        <td><?php echo economyStatsFormatCurrency($club['finanz_budget']); ?></td>
                    </tr>
                <?php } ?>
            </tbody>
        </table>
    </div>
</div>
