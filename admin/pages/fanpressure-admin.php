<?php
/******************************************************

  Board, Fan & Media Story Engine admin page.

******************************************************/

if (!$admin['r_admin'] && !$admin['r_demo'] && !$admin[$page['permissionrole']]) {
    echo '<p>' . $i18n->getMessage('error_access_denied') . '</p>';
    exit;
}

function fpsAdminMsg($i18n, $key, $fallback) {
    return $i18n->hasMessage($key) ? $i18n->getMessage($key) : $fallback;
}

function fpsAdminSigned($value) {
    $value = (int) $value;
    return ($value > 0 ? '+' : '') . $value;
}

function fpsAdminInput($name, $value, $size = 4) {
    return '<input type="number" name="' . escapeOutput($name) . '" value="' . (int) $value . '" class="input-mini" style="width:' . (int) ($size * 14) . 'px;">';
}

$notice = '';
$error = '';
$editQuestionId = isset($_GET['edit_question_id']) ? (int) $_GET['edit_question_id'] : 0;

try {
    FanPressureDataService::ensureSchema($website, $db);

    if ($_SERVER['REQUEST_METHOD'] == 'POST' && !$admin['r_demo']) {
        $adminAction = isset($_POST['admin_action']) ? $_POST['admin_action'] : '';
        if ($adminAction == 'save_rules') {
            FanPressureDataService::saveAdminRules($website, $db, $_POST);
            $notice = fpsAdminMsg($i18n, 'fanpressure_admin_rules_saved', 'Ereignisregeln gespeichert.');
        } else if ($adminAction == 'save_question') {
            FanPressureDataService::saveAdminQuestion($website, $db, $_POST);
            $notice = fpsAdminMsg($i18n, 'fanpressure_admin_question_saved', 'Interviewfrage gespeichert.');
            $editQuestionId = 0;
        } else if ($adminAction == 'delete_question') {
            $questionId = isset($_POST['question_id']) ? (int) $_POST['question_id'] : 0;
            if ($questionId > 0) {
                FanPressureDataService::deleteAdminQuestion($website, $db, $questionId);
                $notice = fpsAdminMsg($i18n, 'fanpressure_admin_question_deleted', 'Interviewfrage gelöscht.');
            }
            $editQuestionId = 0;
        }
    }

    $data = FanPressureDataService::getAdminData($website, $db);
} catch (Exception $e) {
    $data = array('rules' => array(), 'questions' => array(), 'logs' => array());
    $error = $e->getMessage();
}

$editQuestion = null;
foreach ($data['questions'] as $question) {
    if ((int) $question['id'] == $editQuestionId) {
        $editQuestion = $question;
        break;
    }
}
if (!$editQuestion) {
    $editQuestion = array(
        'id' => 0,
        'event_key' => 'fanpressure_reason_match_loss',
        'question' => '',
        'answer_a_label' => '', 'answer_a_mood' => 0, 'answer_a_pressure' => 0, 'answer_a_board' => 0, 'answer_a_chemistry' => 0,
        'answer_b_label' => '', 'answer_b_mood' => 0, 'answer_b_pressure' => 0, 'answer_b_board' => 0, 'answer_b_chemistry' => 0,
        'answer_c_label' => '', 'answer_c_mood' => 0, 'answer_c_pressure' => 0, 'answer_c_board' => 0, 'answer_c_chemistry' => 0,
        'active' => '1',
        'weight' => 1
    );
}
?>

<h1><?php echo fpsAdminMsg($i18n, 'fanpressure_admin_title', 'Board, Fan & Media Story Engine'); ?></h1>
<p><?php echo fpsAdminMsg($i18n, 'fanpressure_admin_intro', 'Konfiguration der Event-Storys.'); ?></p>

<?php if ($notice) { echo createSuccessMessage(fpsAdminMsg($i18n, 'alert_success_title', 'Erfolg'), $notice); } ?>
<?php if ($error) { echo createErrorMessage(fpsAdminMsg($i18n, 'alert_error_title', 'Fehler'), $error); } ?>

<ul class="nav nav-tabs">
    <li class="active"><a href="#rules" data-toggle="tab"><?php echo fpsAdminMsg($i18n, 'fanpressure_admin_rules', 'Ereignisregeln'); ?></a></li>
    <li><a href="#questions" data-toggle="tab"><?php echo fpsAdminMsg($i18n, 'fanpressure_admin_questions', 'Interviewfragen'); ?></a></li>
    <li><a href="#logs" data-toggle="tab"><?php echo fpsAdminMsg($i18n, 'fanpressure_admin_recent_logs', 'Letzte Story-Ereignisse'); ?></a></li>
</ul>

<div class="tab-content">
    <div class="tab-pane active" id="rules">
        <form method="post" action="index.php?site=<?php echo urlencode($site); ?>">
            <input type="hidden" name="admin_action" value="save_rules">
            <table class="table table-striped table-condensed">
                <thead>
                    <tr>
                        <th><?php echo fpsAdminMsg($i18n, 'fanpressure_admin_event', 'Ereignis'); ?></th>
                        <th><?php echo fpsAdminMsg($i18n, 'fanpressure_admin_source', 'Quelle'); ?></th>
                        <th><?php echo fpsAdminMsg($i18n, 'fanpressure_admin_active', 'Aktiv'); ?></th>
                        <th><?php echo fpsAdminMsg($i18n, 'fanpressure_admin_fans', 'Fans'); ?></th>
                        <th><?php echo fpsAdminMsg($i18n, 'fanpressure_admin_media', 'Medien'); ?></th>
                        <th><?php echo fpsAdminMsg($i18n, 'fanpressure_admin_board', 'Vorstand'); ?></th>
                        <th><?php echo fpsAdminMsg($i18n, 'fanpressure_admin_team', 'Team'); ?></th>
                        <th><?php echo fpsAdminMsg($i18n, 'fanpressure_admin_notification', 'Mitteilung'); ?></th>
                        <th><?php echo fpsAdminMsg($i18n, 'fanpressure_admin_news', 'News'); ?></th>
                        <th><?php echo fpsAdminMsg($i18n, 'fanpressure_admin_interview_chance', 'Interview %'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($data['rules'] as $rule) { ?>
                        <tr>
                            <td>
                                <strong><?php echo escapeOutput($rule['label']); ?></strong><br>
                                <small class="muted"><?php echo escapeOutput($rule['event_key']); ?></small>
                            </td>
                            <td><?php echo escapeOutput($rule['source']); ?></td>
                            <td><input type="checkbox" name="rules[<?php echo escapeOutput($rule['event_key']); ?>][active]" value="1"<?php if ($rule['active'] == '1') echo ' checked="checked"'; ?>></td>
                            <td><?php echo fpsAdminInput('rules[' . $rule['event_key'] . '][mood_change]', $rule['mood_change']); ?></td>
                            <td><?php echo fpsAdminInput('rules[' . $rule['event_key'] . '][pressure_change]', $rule['pressure_change']); ?></td>
                            <td><?php echo fpsAdminInput('rules[' . $rule['event_key'] . '][board_change]', $rule['board_change']); ?></td>
                            <td><?php echo fpsAdminInput('rules[' . $rule['event_key'] . '][chemistry_change]', $rule['chemistry_change']); ?></td>
                            <td><input type="checkbox" name="rules[<?php echo escapeOutput($rule['event_key']); ?>][create_notification]" value="1"<?php if ($rule['create_notification'] == '1') echo ' checked="checked"'; ?>></td>
                            <td><input type="checkbox" name="rules[<?php echo escapeOutput($rule['event_key']); ?>][create_news]" value="1"<?php if ($rule['create_news'] == '1') echo ' checked="checked"'; ?>></td>
                            <td><?php echo fpsAdminInput('rules[' . $rule['event_key'] . '][interview_chance]', $rule['interview_chance']); ?></td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
            <?php if (!$admin['r_demo']) { ?>
                <button type="submit" class="btn btn-primary"><?php echo $i18n->getMessage('button_save'); ?></button>
            <?php } ?>
        </form>
    </div>

    <div class="tab-pane" id="questions">
        <div class="row-fluid">
            <div class="span5">
                <h3><?php echo ((int) $editQuestion['id'] > 0) ? fpsAdminMsg($i18n, 'button_edit', 'Bearbeiten') : fpsAdminMsg($i18n, 'fanpressure_admin_new_question', 'Neue Frage'); ?></h3>
                <form method="post" action="index.php?site=<?php echo urlencode($site); ?>">
                    <input type="hidden" name="admin_action" value="save_question">
                    <input type="hidden" name="question_id" value="<?php echo (int) $editQuestion['id']; ?>">
                    <label><?php echo fpsAdminMsg($i18n, 'fanpressure_admin_event', 'Ereignis'); ?></label>
                    <select name="event_key" class="input-xlarge">
                        <?php foreach ($data['rules'] as $rule) { ?>
                            <option value="<?php echo escapeOutput($rule['event_key']); ?>"<?php if ($editQuestion['event_key'] == $rule['event_key']) echo ' selected="selected"'; ?>><?php echo escapeOutput($rule['label']); ?></option>
                        <?php } ?>
                    </select>

                    <label><?php echo fpsAdminMsg($i18n, 'fanpressure_admin_question', 'Frage'); ?></label>
                    <textarea name="question" class="input-xxlarge" rows="3"><?php echo escapeOutput($editQuestion['question']); ?></textarea>

                    <?php foreach (array('a', 'b', 'c') as $letter) { ?>
                        <h4><?php echo fpsAdminMsg($i18n, 'fanpressure_admin_answer_' . $letter, 'Antwort ' . strtoupper($letter)); ?></h4>
                        <input type="text" name="answer_<?php echo $letter; ?>_label" value="<?php echo escapeOutput($editQuestion['answer_' . $letter . '_label']); ?>" class="input-xxlarge">
                        <p>
                            <?php echo fpsAdminMsg($i18n, 'fanpressure_admin_fans', 'Fans'); ?> <?php echo fpsAdminInput('answer_' . $letter . '_mood', $editQuestion['answer_' . $letter . '_mood']); ?>
                            <?php echo fpsAdminMsg($i18n, 'fanpressure_admin_media', 'Medien'); ?> <?php echo fpsAdminInput('answer_' . $letter . '_pressure', $editQuestion['answer_' . $letter . '_pressure']); ?>
                            <?php echo fpsAdminMsg($i18n, 'fanpressure_admin_board', 'Vorstand'); ?> <?php echo fpsAdminInput('answer_' . $letter . '_board', $editQuestion['answer_' . $letter . '_board']); ?>
                            <?php echo fpsAdminMsg($i18n, 'fanpressure_admin_team', 'Team'); ?> <?php echo fpsAdminInput('answer_' . $letter . '_chemistry', $editQuestion['answer_' . $letter . '_chemistry']); ?>
                        </p>
                    <?php } ?>

                    <label class="checkbox"><input type="checkbox" name="active" value="1"<?php if ($editQuestion['active'] == '1') echo ' checked="checked"'; ?>> <?php echo fpsAdminMsg($i18n, 'fanpressure_admin_active', 'Aktiv'); ?></label>
                    <label><?php echo fpsAdminMsg($i18n, 'fanpressure_admin_weight', 'Gewichtung'); ?></label>
                    <?php echo fpsAdminInput('weight', $editQuestion['weight']); ?>
                    <br><br>
                    <?php if (!$admin['r_demo']) { ?>
                        <button type="submit" class="btn btn-primary"><?php echo $i18n->getMessage('button_save'); ?></button>
                    <?php } ?>
                </form>
            </div>
            <div class="span7">
                <h3><?php echo fpsAdminMsg($i18n, 'fanpressure_admin_questions', 'Interviewfragen'); ?></h3>
                <table class="table table-striped table-condensed">
                    <thead><tr><th>ID</th><th><?php echo fpsAdminMsg($i18n, 'fanpressure_admin_event', 'Ereignis'); ?></th><th><?php echo fpsAdminMsg($i18n, 'fanpressure_admin_question', 'Frage'); ?></th><th></th></tr></thead>
                    <tbody>
                        <?php foreach ($data['questions'] as $question) { ?>
                            <tr>
                                <td><?php echo (int) $question['id']; ?></td>
                                <td><?php echo escapeOutput($question['event_key']); ?></td>
                                <td><?php echo escapeOutput($question['question']); ?></td>
                                <td style="white-space: nowrap;">
                                    <a class="btn btn-mini" href="index.php?site=<?php echo urlencode($site); ?>&edit_question_id=<?php echo (int) $question['id']; ?>#questions"><?php echo fpsAdminMsg($i18n, 'button_edit', 'Bearbeiten'); ?></a>
                                    <?php if (!$admin['r_demo']) { ?>
                                        <form method="post" action="index.php?site=<?php echo urlencode($site); ?>#questions" style="display:inline;">
                                            <input type="hidden" name="admin_action" value="delete_question">
                                            <input type="hidden" name="question_id" value="<?php echo (int) $question['id']; ?>">
                                            <button type="submit" class="btn btn-mini btn-danger">&times;</button>
                                        </form>
                                    <?php } ?>
                                </td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="tab-pane" id="logs">
        <table class="table table-striped table-condensed">
            <thead><tr><th><?php echo fpsAdminMsg($i18n, 'fanpressure_date', 'Datum'); ?></th><th><?php echo fpsAdminMsg($i18n, 'fanpressure_admin_event', 'Ereignis'); ?></th><th>Club</th><th>User</th><th><?php echo fpsAdminMsg($i18n, 'fanpressure_admin_fans', 'Fans'); ?></th><th><?php echo fpsAdminMsg($i18n, 'fanpressure_admin_media', 'Medien'); ?></th><th><?php echo fpsAdminMsg($i18n, 'fanpressure_admin_board', 'Vorstand'); ?></th><th><?php echo fpsAdminMsg($i18n, 'fanpressure_admin_team', 'Team'); ?></th></tr></thead>
            <tbody>
                <?php foreach ($data['logs'] as $log) { ?>
                    <tr>
                        <td><?php echo date($website->getConfig('date_format'), (int) $log['event_date']); ?></td>
                        <td><strong><?php echo escapeOutput($log['title']); ?></strong><br><small><?php echo escapeOutput($log['event_key']); ?></small></td>
                        <td><?php echo escapeOutput($log['team_name']); ?></td>
                        <td><?php echo escapeOutput($log['user_name']); ?></td>
                        <td><?php echo fpsAdminSigned($log['mood_change']); ?></td>
                        <td><?php echo fpsAdminSigned($log['pressure_change']); ?></td>
                        <td><?php echo fpsAdminSigned($log['board_change']); ?></td>
                        <td><?php echo fpsAdminSigned($log['chemistry_change']); ?></td>
                    </tr>
                <?php } ?>
            </tbody>
        </table>
    </div>
</div>

