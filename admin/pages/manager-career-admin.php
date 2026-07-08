<?php
/******************************************************

  This file is part of OpenWebSoccer-Sim.

******************************************************/

$mainTitle = $i18n->getMessage('managercareer_admin_title');

if (!$admin['r_admin'] && !$admin['r_demo'] && !$admin[$page['permissionrole']]) {
    throw new Exception($i18n->getMessage('error_access_denied'));
}

if (!$show) {
    echo '<h1>' . escapeOutput($mainTitle) . '</h1>';
    echo '<p>' . escapeOutput($i18n->getMessage('managercareer_admin_intro')) . '</p>';

    if (isset($action) && strlen($action) && !$admin['r_demo']) {
        try {
            $applicationId = isset($_REQUEST['id']) ? (int) $_REQUEST['id'] : 0;
            if ($action === 'accept-application' && $applicationId > 0) {
                ManagerCareerImprovementService::adminAcceptApplication($website, $db, $i18n, $applicationId);
                echo createSuccessMessage($i18n->getMessage('managercareer_admin_application_accepted'), '');
            } else if ($action === 'reject-application' && $applicationId > 0) {
                ManagerCareerImprovementService::adminRejectApplication($website, $db, $i18n, $applicationId);
                echo createSuccessMessage($i18n->getMessage('managercareer_admin_application_rejected'), '');
            }
        } catch (Exception $e) {
            echo createErrorMessage($i18n->getMessage('alert_error_title'), $e->getMessage());
        }
    }

    $data = ManagerCareerImprovementService::getAdminPageData($website, $db, $i18n);
    if (isset($data['error'])) {
        echo createErrorMessage($i18n->getMessage('alert_error_title'), $data['error']);
    }
    ?>

    <ul class="nav nav-tabs">
        <li class="active"><a href="#applications" data-toggle="tab"><?php echo $i18n->getMessage('managercareer_admin_tab_applications'); ?></a></li>
        <li><a href="#sackrisk" data-toggle="tab"><?php echo $i18n->getMessage('managercareer_admin_tab_sackrisk'); ?></a></li>
        <li><a href="#awards" data-toggle="tab"><?php echo $i18n->getMessage('managercareer_admin_tab_awards'); ?></a></li>
        <li><a href="#settings" data-toggle="tab"><?php echo $i18n->getMessage('managercareer_admin_tab_settings'); ?></a></li>
    </ul>

    <div class="tab-content">
        <div class="tab-pane active" id="applications">
            <h3><?php echo $i18n->getMessage('managercareer_admin_open_applications'); ?></h3>
            <?php if (count($data['open_applications'])) { ?>
                <table class="table table-striped table-condensed">
                    <thead>
                        <tr>
                            <th><?php echo $i18n->getMessage('entity_users'); ?></th>
                            <th><?php echo $i18n->getMessage('entity_club'); ?></th>
                            <th><?php echo $i18n->getMessage('entity_club_liga_id'); ?></th>
                            <th><?php echo $i18n->getMessage('managercareer_application_chance'); ?></th>
                            <th><?php echo $i18n->getMessage('managercareer_decision_date'); ?></th>
                            <th>&nbsp;</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($data['open_applications'] as $application) { ?>
                            <tr>
                                <td><?php echo escapeOutput($application['manager_name']); ?></td>
                                <td><?php echo escapeOutput($application['team_name']); ?></td>
                                <td><?php echo escapeOutput($application['league_name']); ?></td>
                                <td><?php echo (int) $application['acceptance_chance']; ?>%</td>
                                <td><?php echo $website->getFormattedDate($application['decision_date']); ?></td>
                                <td>
                                    <a href="?site=manager-career-admin&action=accept-application&id=<?php echo (int) $application['id']; ?>" class="btn btn-mini btn-success"><?php echo $i18n->getMessage('managercareer_button_accept'); ?></a>
                                    <a href="?site=manager-career-admin&action=reject-application&id=<?php echo (int) $application['id']; ?>" class="btn btn-mini"><?php echo $i18n->getMessage('managercareer_admin_reject'); ?></a>
                                </td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            <?php } else { ?>
                <div class="alert alert-info"><?php echo $i18n->getMessage('managercareer_admin_no_open_applications'); ?></div>
            <?php } ?>

            <h3><?php echo $i18n->getMessage('managercareer_admin_recent_applications'); ?></h3>
            <?php if (count($data['recent_applications'])) { ?>
                <table class="table table-striped table-condensed">
                    <thead>
                        <tr>
                            <th><?php echo $i18n->getMessage('entity_users'); ?></th>
                            <th><?php echo $i18n->getMessage('entity_club'); ?></th>
                            <th><?php echo $i18n->getMessage('managercareer_status'); ?></th>
                            <th><?php echo $i18n->getMessage('managercareer_date'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($data['recent_applications'] as $application) { ?>
                            <tr>
                                <td><?php echo escapeOutput($application['manager_name']); ?></td>
                                <td><?php echo escapeOutput($application['team_name']); ?></td>
                                <td><?php echo $i18n->getMessage('managercareer_application_status_' . $application['status']); ?></td>
                                <td><?php echo $application['answered_date'] ? $website->getFormattedDate($application['answered_date']) : $website->getFormattedDate($application['created_date']); ?></td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            <?php } else { ?>
                <p class="muted"><?php echo $i18n->getMessage('managercareer_admin_no_recent_applications'); ?></p>
            <?php } ?>
        </div>

        <div class="tab-pane" id="sackrisk">
            <h3><?php echo $i18n->getMessage('managercareer_admin_sackrisk_title'); ?></h3>
            <p><?php echo $i18n->getMessage('managercareer_admin_sackrisk_help'); ?></p>
            <?php if (count($data['sack_risks'])) { ?>
                <table class="table table-striped table-condensed">
                    <thead>
                        <tr>
                            <th><?php echo $i18n->getMessage('entity_users'); ?></th>
                            <th><?php echo $i18n->getMessage('entity_club'); ?></th>
                            <th><?php echo $i18n->getMessage('entity_club_liga_id'); ?></th>
                            <th><?php echo $i18n->getMessage('managercareer_board_satisfaction'); ?></th>
                            <th><?php echo $i18n->getMessage('managercareer_sack_risk'); ?></th>
                            <th><?php echo $i18n->getMessage('managercareer_low_board_checks'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($data['sack_risks'] as $risk) { ?>
                            <tr>
                                <td><?php echo escapeOutput($risk['manager_name']); ?></td>
                                <td><?php echo escapeOutput($risk['team_name']); ?></td>
                                <td><?php echo escapeOutput($risk['league_name']); ?></td>
                                <td><?php echo (int) $risk['board_satisfaction']; ?>%</td>
                                <td><span class="label <?php echo escapeOutput($risk['risk_class']); ?>"><?php echo escapeOutput($risk['risk_label']); ?></span></td>
                                <td><?php echo (int) $risk['low_board_checks']; ?></td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            <?php } else { ?>
                <div class="alert alert-info"><?php echo $i18n->getMessage('managercareer_admin_no_sackrisk'); ?></div>
            <?php } ?>
        </div>

        <div class="tab-pane" id="awards">
            <h3><?php echo $i18n->getMessage('managercareer_admin_awards_title'); ?></h3>
            <?php if (count($data['recent_awards'])) { ?>
                <table class="table table-striped table-condensed">
                    <thead>
                        <tr>
                            <th><?php echo $i18n->getMessage('managercareer_date'); ?></th>
                            <th><?php echo $i18n->getMessage('entity_users'); ?></th>
                            <th><?php echo $i18n->getMessage('managercareer_award'); ?></th>
                            <th><?php echo $i18n->getMessage('entity_club'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($data['recent_awards'] as $award) { ?>
                            <tr>
                                <td><?php echo $website->getFormattedDate($award['created_date']); ?></td>
                                <td><?php echo escapeOutput($award['manager_name']); ?></td>
                                <td><strong><?php echo escapeOutput($award['title']); ?></strong><br><small><?php echo escapeOutput($award['description']); ?></small></td>
                                <td><?php echo escapeOutput($award['team_name']); ?></td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            <?php } else { ?>
                <p class="muted"><?php echo $i18n->getMessage('managercareer_no_awards'); ?></p>
            <?php } ?>
        </div>

        <div class="tab-pane" id="settings">
            <h3><?php echo $i18n->getMessage('managercareer_admin_settings_title'); ?></h3>
            <p><?php echo $i18n->getMessage('managercareer_admin_settings_help'); ?></p>
            <table class="table table-striped table-condensed">
                <tbody>
                    <tr><th>mgr_applications_enabled</th><td><?php echo $data['settings']['mgr_applications_enabled'] ? '1' : '0'; ?></td></tr>
                    <tr><th>mgr_application_limit</th><td><?php echo (int) $data['settings']['mgr_application_limit']; ?></td></tr>
                    <tr><th>mgr_application_decision_min_days</th><td><?php echo (int) $data['settings']['mgr_application_decision_min_days']; ?></td></tr>
                    <tr><th>mgr_application_decision_max_days</th><td><?php echo (int) $data['settings']['mgr_application_decision_max_days']; ?></td></tr>
                    <tr><th>mgr_application_exp_days</th><td><?php echo (int) $data['settings']['mgr_application_exp_days']; ?></td></tr>
                    <tr><th>mgr_auto_sacking_enabled</th><td><?php echo $data['settings']['mgr_auto_sacking_enabled'] ? '1' : '0'; ?></td></tr>
                    <tr><th>mgr_contract_days</th><td><?php echo (int) $data['settings']['mgr_contract_days']; ?></td></tr>
                </tbody>
            </table>
            <p><a class="btn" href="?site=all_settings"><?php echo $i18n->getMessage('managercareer_admin_open_settings'); ?></a></p>
        </div>
    </div>

    <?php
}
?>
