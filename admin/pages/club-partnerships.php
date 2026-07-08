<?php
/******************************************************

  Admin control page for Club Partnerships 2.0.

******************************************************/

$mainTitle = $i18n->getMessage('clubpartnership_admin_title');

if (!$admin['r_admin'] && !$admin['r_demo'] && !$admin[$page['permissionrole']]) {
    throw new Exception($i18n->getMessage('error_access_denied'));
}

function cpAdminStatusLabel($i18n, $status) {
    $key = 'clubpartnerships_status_' . $status;
    return $i18n->hasMessage($key) ? $i18n->getMessage($key) : $status;
}

function cpAdminManager($name) {
    return strlen((string) $name) ? escapeOutput($name) : 'Computer';
}

function cpAdminRenderTable($website, $i18n, $rows, $allowActions, $site) {
    if (!count($rows)) {
        echo '<div class="alert alert-info">' . escapeOutput($i18n->getMessage('clubpartnership_admin_no_items')) . '</div>';
        return;
    }
    echo '<table class="table table-striped table-condensed">';
    echo '<thead><tr>';
    echo '<th>ID</th><th>' . escapeOutput($i18n->getMessage('clubpartnerships_parent')) . '</th><th>' . escapeOutput($i18n->getMessage('clubpartnerships_partner')) . '</th><th>' . escapeOutput($i18n->getMessage('clubpartnerships_status')) . '</th><th>' . escapeOutput($i18n->getMessage('clubpartnerships_effects')) . '</th><th>' . escapeOutput($i18n->getMessage('clubpartnership_admin_actions')) . '</th>';
    echo '</tr></thead><tbody>';
    foreach ($rows as $row) {
        echo '<tr>';
        echo '<td>' . (int) $row['id'] . '</td>';
        echo '<td><strong>' . escapeOutput($row['parent_name']) . '</strong><br><small>' . escapeOutput($row['parent_league_name']) . ' · Score ' . (int) $row['parent_score'] . ' · ' . cpAdminManager($row['parent_manager_name']) . '</small></td>';
        echo '<td><strong>' . escapeOutput($row['partner_name']) . '</strong><br><small>' . escapeOutput($row['partner_league_name']) . ' · Score ' . (int) $row['partner_score'] . ' · ' . cpAdminManager($row['partner_manager_name']) . '</small></td>';
        echo '<td>' . escapeOutput(cpAdminStatusLabel($i18n, $row['status']));
        if ($row['has_division_conflict']) {
            echo '<br><span class="label label-important">' . escapeOutput($i18n->getMessage('clubpartnership_admin_conflict')) . '</span>';
        }
        if (strlen((string) $row['suspended_reason'])) {
            echo '<br><small>' . escapeOutput($row['suspended_reason']) . '</small>';
        }
        echo '</td>';
        echo '<td>' . (int) $row['development_bonus_percent'] . '%</td>';
        echo '<td style="white-space:nowrap;">';
        if ($allowActions) {
            if ($row['status'] === 'active') {
                echo '<a class="btn btn-mini" href="?site=' . urlencode($site) . '&action=suspend&id=' . (int) $row['id'] . '">' . escapeOutput($i18n->getMessage('clubpartnership_admin_suspend')) . '</a> ';
            }
            if ($row['status'] === 'suspended' && !$row['has_division_conflict']) {
                echo '<a class="btn btn-mini btn-success" href="?site=' . urlencode($site) . '&action=reactivate&id=' . (int) $row['id'] . '">' . escapeOutput($i18n->getMessage('clubpartnership_admin_reactivate')) . '</a> ';
            }
            if ($row['status'] !== 'stopped' && $row['status'] !== 'rejected') {
                echo '<a class="btn btn-mini btn-warning" href="?site=' . urlencode($site) . '&action=stop&id=' . (int) $row['id'] . '">' . escapeOutput($i18n->getMessage('clubpartnership_admin_stop')) . '</a> ';
            }
            echo '<a class="btn btn-mini btn-danger" href="?site=' . urlencode($site) . '&action=delete&id=' . (int) $row['id'] . '" onclick="return confirm(\'' . escapeOutput($i18n->getMessage('clubpartnership_admin_delete_confirm')) . '\');">' . escapeOutput($i18n->getMessage('clubpartnership_admin_delete')) . '</a>';
        }
        echo '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
}

if (!$show) {
    echo '<h1>' . escapeOutput($mainTitle) . '</h1>';
    echo '<p>' . escapeOutput($i18n->getMessage('clubpartnership_admin_intro')) . '</p>';

    if (isset($action) && strlen($action) && !$admin['r_demo']) {
        try {
            $id = isset($_REQUEST['id']) ? (int) $_REQUEST['id'] : 0;
            if ($action === 'run-check') {
                ClubPartnershipDataService::resolveAutomaticStopsAndConflicts($website, $db, $i18n);
                echo createSuccessMessage($i18n->getMessage('clubpartnership_admin_done'), '');
            } elseif ($action === 'suspend' && $id > 0) {
                ClubPartnershipDataService::adminSetStatus($website, $db, $i18n, (int) $admin['id'], $id, ClubPartnershipDataService::STATUS_SUSPENDED);
                echo createSuccessMessage($i18n->getMessage('clubpartnership_admin_done'), '');
            } elseif ($action === 'reactivate' && $id > 0) {
                ClubPartnershipDataService::adminSetStatus($website, $db, $i18n, (int) $admin['id'], $id, ClubPartnershipDataService::STATUS_ACTIVE);
                echo createSuccessMessage($i18n->getMessage('clubpartnership_admin_done'), '');
            } elseif ($action === 'stop' && $id > 0) {
                ClubPartnershipDataService::adminSetStatus($website, $db, $i18n, (int) $admin['id'], $id, ClubPartnershipDataService::STATUS_STOPPED);
                echo createSuccessMessage($i18n->getMessage('clubpartnership_admin_done'), '');
            } elseif ($action === 'delete' && $id > 0) {
                ClubPartnershipDataService::adminDelete($website, $db, $id);
                echo createSuccessMessage($i18n->getMessage('clubpartnership_admin_done'), '');
            }
        } catch (Exception $e) {
            echo createErrorMessage($i18n->getMessage('alert_error_title'), $e->getMessage());
        }
    }

    $data = ClubPartnershipDataService::getAdminPageData($website, $db, $i18n);
    ?>

    <p><a class="btn" href="?site=<?php echo urlencode($site); ?>&action=run-check"><i class="icon-refresh"></i> <?php echo escapeOutput($i18n->getMessage('clubpartnership_admin_run_check')); ?></a></p>

    <?php if (count($data['actions'])) { ?>
        <div class="alert alert-info">
            <?php foreach ($data['actions'] as $autoAction) { ?>
                <div><?php echo escapeOutput($autoAction['parent_name'] . ' / ' . $autoAction['partner_name'] . ': ' . $autoAction['action'] . ' ' . $autoAction['reason']); ?></div>
            <?php } ?>
        </div>
    <?php } ?>

    <ul class="nav nav-tabs">
        <li class="active"><a href="#open" data-toggle="tab"><?php echo escapeOutput($i18n->getMessage('clubpartnership_admin_open')); ?></a></li>
        <li><a href="#closed" data-toggle="tab"><?php echo escapeOutput($i18n->getMessage('clubpartnership_admin_closed')); ?></a></li>
        <li><a href="#logs" data-toggle="tab"><?php echo escapeOutput($i18n->getMessage('clubpartnership_admin_logs')); ?></a></li>
    </ul>

    <div class="tab-content">
        <div class="tab-pane active" id="open">
            <?php cpAdminRenderTable($website, $i18n, $data['open_partnerships'], !$admin['r_demo'], $site); ?>
        </div>
        <div class="tab-pane" id="closed">
            <?php cpAdminRenderTable($website, $i18n, $data['recent_closed'], !$admin['r_demo'], $site); ?>
        </div>
        <div class="tab-pane" id="logs">
            <?php if (count($data['logs'])) { ?>
                <table class="table table-striped table-condensed">
                    <thead><tr><th>Datum</th><th>Event</th><th><?php echo escapeOutput($i18n->getMessage('entity_club')); ?></th><th>User</th></tr></thead>
                    <tbody>
                    <?php foreach ($data['logs'] as $log) { ?>
                        <tr>
                            <td><?php echo $website->getFormattedDate($log['created_date']); ?></td>
                            <td><strong><?php echo escapeOutput($log['event_key']); ?></strong><br><small><?php echo escapeOutput($log['message']); ?></small></td>
                            <td><?php echo escapeOutput($log['parent_name'] . ' / ' . $log['partner_name']); ?></td>
                            <td><?php echo escapeOutput($log['user_name']); ?></td>
                        </tr>
                    <?php } ?>
                    </tbody>
                </table>
            <?php } else { ?>
                <div class="alert alert-info"><?php echo escapeOutput($i18n->getMessage('clubpartnership_admin_no_items')); ?></div>
            <?php } ?>
        </div>
    </div>

<?php
}
?>
