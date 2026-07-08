<?php
/******************************************************

This file is part of OpenWebSoccer-Sim.

******************************************************/

if (!$admin['r_admin'] && !$admin['r_demo'] && !$admin[$page['permissionrole']]) {
    echo '<p>' . $i18n->getMessage('error_access_denied') . '</p>';
    exit;
}

function tmiAdminMoney($amount, WebSoccer $website) {
    return number_format((int) round($amount), 0, ',', ' ') . ' ' . $website->getConfig('game_currency');
}

function tmiAdminPercent($ratio) {
    return number_format(((float) $ratio) * 100, 1, ',', ' ') . '%';
}

$analysis = TransferMarketIntelligenceDataService::getAdminAnalysis($website, $db);
$scope = $analysis['market_admin_scope'];
$filters = $analysis['market_admin_filters'];
$stats = $analysis['market_admin_stats'];
$baseUrl = 'index.php?site=' . urlencode($site) . '&adminscope=';
?>

<h1><?php echo $i18n->getMessage('transfermarket_intelligence_admin_title'); ?></h1>
<p><?php echo $i18n->getMessage('transfermarket_intelligence_admin_intro'); ?></p>

<ul class="nav nav-pills">
    <li<?php if ($scope == 'global') echo ' class="active"'; ?>><a href="<?php echo $baseUrl; ?>global"><?php echo $i18n->getMessage('transfermarket_intelligence_admin_scope_global'); ?></a></li>
    <li<?php if ($scope == 'league') echo ' class="active"'; ?>><a href="<?php echo $baseUrl; ?>league"><?php echo $i18n->getMessage('transfermarket_intelligence_admin_scope_league'); ?></a></li>
    <li<?php if ($scope == 'country') echo ' class="active"'; ?>><a href="<?php echo $baseUrl; ?>country"><?php echo $i18n->getMessage('transfermarket_intelligence_admin_scope_country'); ?></a></li>
    <li<?php if ($scope == 'club') echo ' class="active"'; ?>><a href="<?php echo $baseUrl; ?>club"><?php echo $i18n->getMessage('transfermarket_intelligence_admin_scope_club'); ?></a></li>
</ul>

<?php if ($scope != 'global') { ?>
<form class="form-inline" method="get" action="index.php">
    <input type="hidden" name="site" value="<?php echo escapeOutput($site); ?>">
    <input type="hidden" name="adminscope" value="<?php echo escapeOutput($scope); ?>">
    <label><?php echo $i18n->getMessage('transfermarket_intelligence_admin_filter'); ?></label>
    <?php if ($scope == 'league') { ?>
        <select name="league_id" class="input-xxlarge">
            <?php foreach ($filters['leagues'] as $league) { ?>
                <option value="<?php echo (int) $league['id']; ?>"<?php if ((int) $league['id'] == (int) $analysis['market_admin_league_id']) echo ' selected="selected"'; ?>>
                    <?php echo escapeOutput($league['country'] . ' - ' . $league['name']); ?>
                </option>
            <?php } ?>
        </select>
    <?php } elseif ($scope == 'country') { ?>
        <select name="country" class="input-xlarge">
            <?php foreach ($filters['countries'] as $countryRow) { ?>
                <option value="<?php echo escapeOutput($countryRow['country']); ?>"<?php if ($countryRow['country'] == $analysis['market_admin_country']) echo ' selected="selected"'; ?>><?php echo escapeOutput($countryRow['country']); ?></option>
            <?php } ?>
        </select>
    <?php } elseif ($scope == 'club') { ?>
        <div class="control-group" style="margin-bottom: 8px;">
            <label for="tmi-club-search" style="display: inline-block; margin-right: 8px;">
                <?php echo $i18n->getMessage('transfermarket_intelligence_admin_club_search'); ?>
            </label>
            <input type="text" id="tmi-club-search" class="input-xxlarge" autocomplete="off"
                   placeholder="<?php echo escapeOutput($i18n->getMessage('transfermarket_intelligence_admin_club_search_placeholder')); ?>">
            <span class="help-inline" id="tmi-club-search-count"></span>
        </div>
        <select name="club_id" id="tmi-club-select" class="input-xxlarge">
            <?php foreach ($filters['clubs'] as $club) { ?>
                <?php $clubLabel = $club['name'] . ($club['league_name'] ? ' (' . $club['league_name'] . ')' : ''); ?>
                <option value="<?php echo (int) $club['id']; ?>" data-search="<?php echo escapeOutput(strtolower($clubLabel)); ?>"<?php if ((int) $club['id'] == (int) $analysis['market_admin_club_id']) echo ' selected="selected"'; ?>>
                    <?php echo escapeOutput($clubLabel); ?>
                </option>
            <?php } ?>
        </select>
        <p class="help-block"><?php echo $i18n->getMessage('transfermarket_intelligence_admin_club_search_help'); ?></p>
    <?php } ?>
    <button type="submit" class="btn btn-primary"><?php echo $i18n->getMessage('transfermarket_intelligence_admin_apply'); ?></button>
</form>
<?php } ?>

<?php if ($scope == 'club') { ?>
<script type="text/javascript">
(function() {
    var input = document.getElementById('tmi-club-search');
    var select = document.getElementById('tmi-club-select');
    var counter = document.getElementById('tmi-club-search-count');

    if (!input || !select) {
        return;
    }

    var originalOptions = [];
    for (var i = 0; i < select.options.length; i++) {
        originalOptions.push({
            value: select.options[i].value,
            text: select.options[i].text,
            search: select.options[i].getAttribute('data-search') || select.options[i].text.toLowerCase(),
            selected: select.options[i].selected
        });
    }

    function normalize(value) {
        return (value || '').toLowerCase();
    }

    function renderOptions() {
        var query = normalize(input.value);
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
            if (select.selectedIndex < 0) {
                select.selectedIndex = 0;
            }
        }

        if (counter) {
            counter.innerHTML = matches.length + ' / ' + originalOptions.length;
        }
    }

    input.onkeyup = renderOptions;
    input.onchange = renderOptions;
    renderOptions();
})();
</script>
<?php } ?>

<?php if ($stats['overheating']) { ?>
    <?php echo createErrorMessage($i18n->getMessage('transfermarket_intelligence_admin_overheating'), $i18n->getMessage('transfermarket_intelligence_admin_overheating_warning')); ?>
<?php } else { ?>
    <?php echo createSuccessMessage($i18n->getMessage('transfermarket_intelligence_admin_overheating'), $i18n->getMessage('transfermarket_intelligence_admin_overheating_ok')); ?>
<?php } ?>

<table class="table table-striped table-bordered">
    <tbody>
        <tr>
            <th><?php echo $i18n->getMessage('transfermarket_intelligence_admin_average_transfer_fee'); ?></th>
            <td><?php echo tmiAdminMoney($stats['average_transfer_fee'], $website); ?></td>
            <th><?php echo $i18n->getMessage('transfermarket_intelligence_admin_highest_fee'); ?></th>
            <td><?php echo tmiAdminMoney($stats['highest_fee'], $website); ?></td>
        </tr>
        <tr>
            <th><?php echo $i18n->getMessage('transfermarket_intelligence_admin_average_salary'); ?></th>
            <td><?php echo tmiAdminMoney($stats['average_salary'], $website); ?></td>
            <th><?php echo $i18n->getMessage('transfermarket_intelligence_admin_number_offers'); ?></th>
            <td><?php echo (int) $stats['number_offers']; ?></td>
        </tr>
        <tr>
            <th><?php echo $i18n->getMessage('transfermarket_intelligence_admin_listed_players'); ?></th>
            <td><?php echo (int) $stats['listed_players']; ?></td>
            <th><?php echo $i18n->getMessage('transfermarket_intelligence_admin_market_ratio'); ?></th>
            <td><?php echo tmiAdminPercent($stats['market_ratio']); ?></td>
        </tr>
        <tr>
            <th><?php echo $i18n->getMessage('transfermarket_intelligence_admin_cpu_realism'); ?></th>
            <td><?php echo number_format($stats['cpu_realism'], 1, ',', ' '); ?>%</td>
            <th><?php echo $i18n->getMessage('transfermarket_intelligence_admin_cpu_ratio'); ?></th>
            <td><?php echo tmiAdminPercent($stats['cpu_avg_ratio']); ?></td>
        </tr>
    </tbody>
</table>

<h3><?php echo $i18n->getMessage('transfermarket_intelligence_admin_cpu_realism'); ?></h3>
<table class="table table-striped table-bordered">
    <thead>
        <tr>
            <th><?php echo $i18n->getMessage('transfermarket_intelligence_admin_cpu_within_range'); ?></th>
            <th><?php echo $i18n->getMessage('transfermarket_intelligence_admin_cpu_below_range'); ?></th>
            <th><?php echo $i18n->getMessage('transfermarket_intelligence_admin_cpu_above_range'); ?></th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td><?php echo (int) $stats['cpu_within']; ?></td>
            <td><?php echo (int) $stats['cpu_below']; ?></td>
            <td><?php echo (int) $stats['cpu_above']; ?></td>
        </tr>
    </tbody>
</table>
