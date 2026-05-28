<?php
/******************************************************

  This file is part of OpenWebSoccer-Sim.

******************************************************/

/**
 * Data service for per-player individual training.
 */
class IndividualTrainingDataService {

    private static $_schemaReady = false;

    public static function ensureSchema(WebSoccer $websoccer, DbConnection $db) {
        if (self::$_schemaReady) {
            return;
        }

        $prefix = $websoccer->getConfig('db_prefix');
        $db->executeQuery("CREATE TABLE IF NOT EXISTS " . $prefix . "_individual_training (
            id INT(10) NOT NULL AUTO_INCREMENT,
            team_id INT(10) NOT NULL,
            player_id INT(10) NOT NULL,
            attribute_key VARCHAR(32) NOT NULL,
            progress_points DECIMAL(7,2) NOT NULL DEFAULT 0.00,
            required_points DECIMAL(7,2) NOT NULL DEFAULT 100.00,
            progress_matches SMALLINT(5) NOT NULL DEFAULT 0,
            last_match_id INT(10) NOT NULL DEFAULT 0,
            started_date INT(11) NOT NULL DEFAULT 0,
            updated_date INT(11) NOT NULL DEFAULT 0,
            completed_date INT(11) NOT NULL DEFAULT 0,
            old_value DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            new_value DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            status ENUM('active','completed','cancelled') NOT NULL DEFAULT 'active',
            PRIMARY KEY (id),
            KEY idx_individual_training_team_status (team_id, status),
            KEY idx_individual_training_player_status (player_id, status),
            KEY idx_individual_training_last_match (last_match_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8");

        self::ensureColumn($db, $prefix . '_spieler', 'einzeltraining', "ALTER TABLE " . $prefix . "_spieler ADD COLUMN einzeltraining ENUM('1','0') NOT NULL DEFAULT '0'");

        self::$_schemaReady = true;
    }

    public static function getPageData(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $teamId) {
        self::ensureSchema($websoccer, $db);
        if (class_exists('TrainingDataService')) {
            TrainingDataService::ensureAdvancedTrainingSchema($websoccer, $db);
        }

        $players = PlayersDataService::getPlayersOfTeamById($websoccer, $db, $teamId);
        $activePlans = self::getActivePlansByPlayer($websoccer, $db, $teamId);
        $attributeLabels = self::getAttributeLabels();
        $currentTrainer = self::getCurrentTrainer($websoccer, $db, $teamId);
        $trainingUnits = class_exists('TrainingDataService') ? TrainingDataService::countRemainingTrainingUnits($websoccer, $db, $teamId) : 0;

        $rows = array();
        foreach ($players as $player) {
            $available = self::getTrainableAttributesForPlayer($player);
            $currentValues = self::getCurrentAttributeValues($player, $available);
            $weak = self::getWeakAttributeRecommendations($currentValues);
            $active = isset($activePlans[$player['id']]) ? $activePlans[$player['id']] : array();

            if (isset($active['id'])) {
                $active['progress_percent'] = self::calculateProgressPercent($active);
                $active['estimated_total_matches'] = max((int) $active['progress_matches'] + 1, self::estimateTotalMatches($player, $active['attribute_key'], $currentTrainer));
                $active['remaining_matches'] = max(1, $active['estimated_total_matches'] - (int) $active['progress_matches']);
            }

            $rows[] = array(
                'id' => (int) $player['id'],
                'name' => self::getPlayerName($player),
                'position' => isset($player['position']) ? $player['position'] : '',
                'position_main' => isset($player['position_main']) ? $player['position_main'] : '',
                'age' => isset($player['age']) ? (int) $player['age'] : 0,
                'available_attributes' => $available,
                'current_values' => $currentValues,
                'weak_attributes' => $weak,
                'active_training' => $active
            );
        }

        return array(
            'human_team' => true,
            'players' => $rows,
            'attributeLabels' => $attributeLabels,
            'trainingUnits' => $trainingUnits,
            'currentTrainer' => $currentTrainer,
            'completedTrainings' => self::getCompletedTrainings($websoccer, $db, $teamId, 10)
        );
    }

    public static function saveIndividualTraining(WebSoccer $websoccer, DbConnection $db, $teamId, $playerId, $attributeKey) {
        self::ensureSchema($websoccer, $db);

        $player = self::getPlayer($websoccer, $db, $teamId, $playerId);
        if (!isset($player['id'])) {
            throw new Exception('individual_training_error_player_not_found');
        }

        $attributeKey = self::normalizeAttributeKey($attributeKey);
        if ($attributeKey === '') {
            self::cancelActiveTraining($websoccer, $db, $teamId, $playerId);
            return 'cancelled';
        }

        $allowed = self::getTrainableAttributesForPlayer($player);
        if (!in_array($attributeKey, $allowed)) {
            throw new Exception('individual_training_error_invalid_attribute');
        }

        $active = self::getActivePlanForPlayer($websoccer, $db, $teamId, $playerId);
        if (isset($active['id']) && $active['attribute_key'] === $attributeKey) {
            return 'unchanged';
        }

        if (isset($active['id'])) {
            self::cancelActiveTraining($websoccer, $db, $teamId, $playerId);
        }

        $now = $websoccer->getNowAsTimestamp();
        $requiredPoints = self::calculateRequiredPoints($player, $attributeKey);
        $oldValue = self::getPlayerAttributeValue($player, $attributeKey);

        $db->queryInsert(array(
            'team_id' => (int) $teamId,
            'player_id' => (int) $playerId,
            'attribute_key' => $attributeKey,
            'progress_points' => 0,
            'required_points' => $requiredPoints,
            'progress_matches' => 0,
            'last_match_id' => 0,
            'started_date' => $now,
            'updated_date' => $now,
            'completed_date' => 0,
            'old_value' => $oldValue,
            'new_value' => 0,
            'status' => 'active'
        ), $websoccer->getConfig('db_prefix') . '_individual_training');

        $db->queryUpdate(array('einzeltraining' => '1'), $websoccer->getConfig('db_prefix') . '_spieler', 'id = %d', (int) $playerId);

        return 'saved';
    }

    public static function processForTeam(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $teamId, $trainer, $matchId, $matchday = 0) {
        self::ensureSchema($websoccer, $db);
        $matchId = (int) $matchId;
        if ($matchId < 1) {
            return array('processed' => 0, 'completed' => 0);
        }

        $prefix = $websoccer->getConfig('db_prefix');
        $fromTable = $prefix . '_individual_training AS IT INNER JOIN ' . $prefix . '_spieler AS P ON P.id = IT.player_id';
        $columns = 'IT.*, P.vorname, P.nachname, P.kunstname, P.verein_id, P.position, P.position_main, P.age, P.geburtstag, P.w_talent, P.personality, P.w_technik, P.w_kondition, P.w_passing, P.w_shooting, P.w_heading, P.w_tackling, P.w_freekick, P.w_pace, P.w_creativity, P.w_influence, P.w_flair, P.w_penalty, P.w_penalty_killing';
        $result = $db->querySelect(
            $columns,
            $fromTable,
            "IT.team_id = %d AND IT.status = 'active' AND IT.last_match_id < %d ORDER BY IT.id ASC",
            array((int) $teamId, $matchId)
        );

        $summary = array('processed' => 0, 'completed' => 0);
        while ($row = $result->fetch_array()) {
            $summary['processed']++;
            $gain = self::calculateProgressGain($row, $trainer);
            $newProgress = (float) $row['progress_points'] + $gain;
            $updates = array(
                'progress_points' => round($newProgress, 2),
                'progress_matches' => (int) $row['progress_matches'] + 1,
                'last_match_id' => $matchId,
                'updated_date' => $websoccer->getNowAsTimestamp()
            );

            if ($newProgress >= (float) $row['required_points']) {
                self::completeTraining($websoccer, $db, $i18n, $teamId, $row, $trainer, $matchId, $updates);
                $summary['completed']++;
            } else {
                $db->queryUpdate($updates, $prefix . '_individual_training', 'id = %d', (int) $row['id']);
            }
        }
        $result->free();

        return $summary;
    }

    public static function getAttributeLabels() {
        return array(
            'technique' => 'entity_player_w_technik',
            'stamina' => 'entity_player_w_kondition',
            'passing' => 'entity_player_w_passing',
            'shooting' => 'entity_player_w_shooting',
            'heading' => 'entity_player_w_heading',
            'tackling' => 'entity_player_w_tackling',
            'freekick' => 'entity_player_w_freekick',
            'pace' => 'entity_player_w_pace',
            'creativity' => 'entity_player_w_creativity',
            'influence' => 'entity_player_w_influence',
            'flair' => 'entity_player_w_flair',
            'penalty' => 'entity_player_w_penalty',
            'penalty_killing' => 'entity_player_w_penalty_killing'
        );
    }

    private static function getActivePlansByPlayer(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $plans = array();
        $result = $db->querySelect('*', $websoccer->getConfig('db_prefix') . '_individual_training', "team_id = %d AND status = 'active'", (int) $teamId);
        while ($row = $result->fetch_array()) {
            $plans[(int) $row['player_id']] = $row;
        }
        $result->free();
        return $plans;
    }

    private static function getActivePlanForPlayer(WebSoccer $websoccer, DbConnection $db, $teamId, $playerId) {
        $result = $db->querySelect('*', $websoccer->getConfig('db_prefix') . '_individual_training', "team_id = %d AND player_id = %d AND status = 'active' ORDER BY id DESC", array((int) $teamId, (int) $playerId), 1);
        $row = $result->fetch_array();
        $result->free();
        return $row ? $row : array();
    }

    private static function cancelActiveTraining(WebSoccer $websoccer, DbConnection $db, $teamId, $playerId) {
        $now = $websoccer->getNowAsTimestamp();
        $db->queryUpdate(array(
            'status' => 'cancelled',
            'updated_date' => $now
        ), $websoccer->getConfig('db_prefix') . '_individual_training', "team_id = %d AND player_id = %d AND status = 'active'", array((int) $teamId, (int) $playerId));
        $db->queryUpdate(array('einzeltraining' => '0'), $websoccer->getConfig('db_prefix') . '_spieler', 'id = %d', (int) $playerId);
    }

    private static function getCompletedTrainings(WebSoccer $websoccer, DbConnection $db, $teamId, $limit) {
        $prefix = $websoccer->getConfig('db_prefix');
        $fromTable = $prefix . '_individual_training AS IT LEFT JOIN ' . $prefix . '_spieler AS P ON P.id = IT.player_id';
        $columns = 'IT.*, P.vorname, P.nachname, P.kunstname';
        $result = $db->querySelect(
            $columns,
            $fromTable,
            "IT.team_id = %d AND IT.status = 'completed' ORDER BY IT.completed_date DESC, IT.id DESC",
            (int) $teamId,
            (int) $limit
        );

        $items = array();
        while ($row = $result->fetch_array()) {
            $row['name'] = self::getPlayerName($row);
            $items[] = $row;
        }
        $result->free();
        return $items;
    }

    private static function getCurrentTrainer(WebSoccer $websoccer, DbConnection $db, $teamId) {
        if (!class_exists('TrainingDataService')) {
            return array();
        }
        $unit = TrainingDataService::getValidTrainingUnit($websoccer, $db, $teamId);
        if (!isset($unit['trainer_id'])) {
            return array();
        }
        return TrainingDataService::getTrainerById($websoccer, $db, (int) $unit['trainer_id']);
    }

    private static function getPlayer(WebSoccer $websoccer, DbConnection $db, $teamId, $playerId) {
        $players = PlayersDataService::getPlayersOfTeamById($websoccer, $db, $teamId);
        if (isset($players[$playerId])) {
            return $players[$playerId];
        }
        return array();
    }

    private static function getTrainableAttributesForPlayer($player) {
        $main = isset($player['position_main']) ? $player['position_main'] : '';
        $group = isset($player['position']) ? $player['position'] : '';

        if ($main === 'T' || $group === 'Torwart' || $group === 'goaly') {
            return array('technique', 'stamina', 'passing', 'influence', 'penalty_killing');
        }

        if (in_array($main, array('IV', 'LV', 'RV')) || $group === 'Abwehr' || $group === 'defense') {
            return array('technique', 'stamina', 'passing', 'heading', 'tackling', 'freekick', 'pace', 'influence', 'penalty');
        }

        if (in_array($main, array('LM', 'DM', 'ZM', 'OM', 'RM')) || $group === 'Mittelfeld' || $group === 'midfield') {
            return array('technique', 'stamina', 'passing', 'shooting', 'tackling', 'freekick', 'pace', 'creativity', 'influence', 'flair', 'penalty');
        }

        return array('technique', 'stamina', 'passing', 'shooting', 'heading', 'freekick', 'pace', 'creativity', 'flair', 'penalty');
    }

    private static function getCurrentAttributeValues($player, $attributes) {
        $values = array();
        foreach ($attributes as $attribute) {
            $values[$attribute] = self::getPlayerAttributeValue($player, $attribute);
        }
        return $values;
    }

    private static function getWeakAttributeRecommendations($currentValues) {
        asort($currentValues, SORT_NUMERIC);
        return array_slice(array_keys($currentValues), 0, 3);
    }

    private static function getPlayerAttributeValue($player, $attributeKey) {
        $map = self::getPlayerFieldMap();
        if (!isset($map[$attributeKey])) {
            return 0;
        }
        $field = $map[$attributeKey]['player_key'];
        return isset($player[$field]) ? round((float) $player[$field], 2) : 0;
    }

    private static function getPlayerFieldMap() {
        return array(
            'technique' => array('player_key' => 'strength_technic', 'db_column' => 'w_technik'),
            'stamina' => array('player_key' => 'strength_stamina', 'db_column' => 'w_kondition'),
            'passing' => array('player_key' => 'strength_passing', 'db_column' => 'w_passing'),
            'shooting' => array('player_key' => 'strength_shooting', 'db_column' => 'w_shooting'),
            'heading' => array('player_key' => 'strength_heading', 'db_column' => 'w_heading'),
            'tackling' => array('player_key' => 'strength_tackling', 'db_column' => 'w_tackling'),
            'freekick' => array('player_key' => 'strength_freekick', 'db_column' => 'w_freekick'),
            'pace' => array('player_key' => 'strength_pace', 'db_column' => 'w_pace'),
            'creativity' => array('player_key' => 'strength_creativity', 'db_column' => 'w_creativity'),
            'influence' => array('player_key' => 'strength_influence', 'db_column' => 'w_influence'),
            'flair' => array('player_key' => 'strength_flair', 'db_column' => 'w_flair'),
            'penalty' => array('player_key' => 'strength_penalty', 'db_column' => 'w_penalty'),
            'penalty_killing' => array('player_key' => 'strength_penalty_killing', 'db_column' => 'w_penalty_killing')
        );
    }

    private static function normalizeAttributeKey($attributeKey) {
        $attributeKey = trim((string) $attributeKey);
        $labels = self::getAttributeLabels();
        return isset($labels[$attributeKey]) ? $attributeKey : '';
    }

    private static function calculateRequiredPoints($player, $attributeKey) {
        $current = self::getPlayerAttributeValue($player, $attributeKey);
        $age = isset($player['age']) ? (int) $player['age'] : 25;
        $talent = isset($player['strength_talent']) ? (int) $player['strength_talent'] : 3;

        $ageFactor = ($age <= 20) ? 0.82 : (($age <= 24) ? 0.92 : (($age <= 29) ? 1.00 : (($age <= 33) ? 1.18 : 1.38)));
        $talentFactor = max(0.72, 1.18 - ($talent * 0.08));
        $currentFactor = 0.80 + ($current / 100);
        $attributeFactor = self::getAttributeDifficultyFactor($attributeKey);

        return round(max(55, min(220, 95 * $ageFactor * $talentFactor * $currentFactor * $attributeFactor)), 2);
    }

    private static function calculateProgressGain($trainingRow, $trainer) {
        $attributeKey = self::normalizeAttributeKey($trainingRow['attribute_key']);
        $current = self::getRawTrainingRowValue($trainingRow, $attributeKey);
        $trainerFactor = self::getTrainerFactor($trainer, $attributeKey);
        $positionFactor = self::getPositionFitFactor($trainingRow, $attributeKey);
        $personalityFactor = self::getPersonalityFactor(isset($trainingRow['personality']) ? $trainingRow['personality'] : 'professional');
        $currentFactor = max(0.72, 1.18 - ($current / 160));

        return round(max(6.0, min(24.0, 11.0 * $trainerFactor * $positionFactor * $personalityFactor * $currentFactor)), 2);
    }

    private static function calculateImprovement($trainingRow, $trainer) {
        $attributeKey = self::normalizeAttributeKey($trainingRow['attribute_key']);
        $current = self::getRawTrainingRowValue($trainingRow, $attributeKey);
        $trainerFactor = self::getTrainerFactor($trainer, $attributeKey);
        $talent = isset($trainingRow['w_talent']) ? (int) $trainingRow['w_talent'] : 3;
        $age = isset($trainingRow['age']) ? (int) $trainingRow['age'] : 25;

        $ageBonus = ($age <= 21) ? 0.12 : (($age <= 25) ? 0.07 : (($age <= 30) ? 0.02 : -0.05));
        $valuePenalty = max(0, ($current - 70) / 250);
        $improvement = 0.28 + ($talent * 0.055) + (($trainerFactor - 1.0) * 0.22) + $ageBonus - $valuePenalty;

        return round(max(0.20, min(0.85, $improvement)), 2);
    }

    private static function completeTraining(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $teamId, $trainingRow, $trainer, $matchId, $updates) {
        $prefix = $websoccer->getConfig('db_prefix');
        $attributeKey = self::normalizeAttributeKey($trainingRow['attribute_key']);
        $map = self::getPlayerFieldMap();
        $dbColumn = $map[$attributeKey]['db_column'];
        $oldValue = self::getRawTrainingRowValue($trainingRow, $attributeKey);
        $improvement = self::calculateImprovement($trainingRow, $trainer);
        $newValue = self::normalizePlayerValue($oldValue + $improvement);

        $db->queryUpdate(array($dbColumn => $newValue, 'einzeltraining' => '0'), $prefix . '_spieler', 'id = %d', (int) $trainingRow['player_id']);

        $updates['progress_points'] = (float) $trainingRow['required_points'];
        $updates['completed_date'] = $websoccer->getNowAsTimestamp();
        $updates['old_value'] = $oldValue;
        $updates['new_value'] = $newValue;
        $updates['status'] = 'completed';
        $db->queryUpdate($updates, $prefix . '_individual_training', 'id = %d', (int) $trainingRow['id']);

        self::createCompletionNotification($websoccer, $db, $i18n, $teamId, $trainingRow, $attributeKey, $oldValue, $newValue);
    }

    private static function createCompletionNotification(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $teamId, $trainingRow, $attributeKey, $oldValue, $newValue) {
        $userId = self::getTeamUserId($websoccer, $db, $teamId);
        if ($userId < 1) {
            return;
        }

        $labels = self::getAttributeLabels();
        $attributeLabel = $i18n->getMessage($labels[$attributeKey]);
        $playerName = self::getPlayerName($trainingRow);

        if (class_exists('NotificationsDataService')) {
            NotificationsDataService::createNotification(
                $websoccer,
                $db,
                $userId,
                'individual_training_completed_notification',
                array('player' => $playerName, 'attribute' => $attributeLabel),
                'individual-training',
                'individual-training',
                null,
                (int) $teamId
            );
        }
    }

    private static function getTeamUserId(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $result = $db->querySelect('user_id', $websoccer->getConfig('db_prefix') . '_verein', 'id = %d', (int) $teamId, 1);
        $row = $result->fetch_array();
        $result->free();
        return ($row && isset($row['user_id'])) ? (int) $row['user_id'] : 0;
    }

    private static function estimateTotalMatches($player, $attributeKey, $trainer) {
        $required = self::calculateRequiredPoints($player, $attributeKey);
        $row = $player;
        $row['attribute_key'] = $attributeKey;
        $gain = self::calculateProgressGain($row, $trainer);
        return max(1, (int) ceil($required / max(1, $gain)));
    }

    private static function calculateProgressPercent($training) {
        if ((float) $training['required_points'] <= 0) {
            return 0;
        }
        return max(0, min(100, (int) round(((float) $training['progress_points'] / (float) $training['required_points']) * 100)));
    }

    private static function getRawTrainingRowValue($row, $attributeKey) {
        $dbMap = array(
            'technique' => 'w_technik',
            'stamina' => 'w_kondition',
            'passing' => 'w_passing',
            'shooting' => 'w_shooting',
            'heading' => 'w_heading',
            'tackling' => 'w_tackling',
            'freekick' => 'w_freekick',
            'pace' => 'w_pace',
            'creativity' => 'w_creativity',
            'influence' => 'w_influence',
            'flair' => 'w_flair',
            'penalty' => 'w_penalty',
            'penalty_killing' => 'w_penalty_killing'
        );
        $playerMap = self::getPlayerFieldMap();
        if (isset($dbMap[$attributeKey]) && isset($row[$dbMap[$attributeKey]])) {
            return round((float) $row[$dbMap[$attributeKey]], 2);
        }
        if (isset($playerMap[$attributeKey])) {
            $field = $playerMap[$attributeKey]['player_key'];
            if (isset($row[$field])) {
                return round((float) $row[$field], 2);
            }
        }
        return 0;
    }

    private static function getTrainerFactor($trainer, $attributeKey) {
        $expertise = isset($trainer['expertise']) ? (int) $trainer['expertise'] : 60;
        $skillGroup = self::getSkillGroupForAttribute($attributeKey);
        $skill = $expertise;

        if ($skillGroup === 'fitness') {
            $skill = isset($trainer['p_stamina']) ? (int) $trainer['p_stamina'] : $expertise;
        } elseif ($skillGroup === 'offense') {
            $skill = isset($trainer['p_offense']) ? (int) $trainer['p_offense'] : (isset($trainer['p_technique']) ? (int) $trainer['p_technique'] : $expertise);
        } elseif ($skillGroup === 'defense') {
            $skill = isset($trainer['p_defense']) ? (int) $trainer['p_defense'] : $expertise;
        } elseif ($skillGroup === 'setpieces') {
            $skill = round(((isset($trainer['p_technique']) ? (int) $trainer['p_technique'] : $expertise) + (isset($trainer['p_tactics']) ? (int) $trainer['p_tactics'] : $expertise)) / 2);
        } elseif ($skillGroup === 'goalkeeping') {
            $skill = isset($trainer['p_goalkeeping']) ? (int) $trainer['p_goalkeeping'] : $expertise;
        } elseif ($skillGroup === 'mental') {
            $skill = isset($trainer['p_mental']) ? (int) $trainer['p_mental'] : $expertise;
        } else {
            $skill = isset($trainer['p_technique']) ? (int) $trainer['p_technique'] : $expertise;
        }

        $factor = 0.70 + (max(1, min(100, $skill)) / 100) * 0.65;
        $specialization = isset($trainer['specialization']) ? $trainer['specialization'] : 'balanced';
        if ($specialization === $skillGroup || $specialization === $attributeKey) {
            $factor *= 1.12;
        } elseif ($specialization === 'balanced') {
            $factor *= 1.03;
        }

        return $factor;
    }

    private static function getSkillGroupForAttribute($attributeKey) {
        if (in_array($attributeKey, array('stamina', 'pace'))) {
            return 'fitness';
        }
        if (in_array($attributeKey, array('shooting', 'flair', 'penalty'))) {
            return 'offense';
        }
        if (in_array($attributeKey, array('heading', 'tackling'))) {
            return 'defense';
        }
        if ($attributeKey === 'freekick') {
            return 'setpieces';
        }
        if ($attributeKey === 'penalty_killing') {
            return 'goalkeeping';
        }
        if ($attributeKey === 'influence') {
            return 'mental';
        }
        return 'technique';
    }

    private static function getPositionFitFactor($player, $attributeKey) {
        $main = isset($player['position_main']) ? $player['position_main'] : '';
        if ($main === 'T') {
            return in_array($attributeKey, array('penalty_killing', 'technique', 'passing', 'influence')) ? 1.12 : 0.55;
        }
        if (in_array($main, array('IV', 'LV', 'RV')) && in_array($attributeKey, array('tackling', 'heading', 'stamina', 'influence'))) {
            return 1.12;
        }
        if (in_array($main, array('LM', 'DM', 'ZM', 'OM', 'RM')) && in_array($attributeKey, array('passing', 'creativity', 'technique', 'freekick'))) {
            return 1.12;
        }
        if (in_array($main, array('LS', 'MS', 'RS')) && in_array($attributeKey, array('shooting', 'heading', 'pace', 'flair', 'penalty'))) {
            return 1.12;
        }
        return 1.0;
    }

    private static function getPersonalityFactor($personality) {
        if ($personality === 'professional') {
            return 1.08;
        }
        if ($personality === 'ambitious') {
            return 1.06;
        }
        if ($personality === 'inconsistent') {
            return mt_rand(88, 112) / 100;
        }
        if ($personality === 'troublemaker') {
            return 0.92;
        }
        return 1.0;
    }

    private static function getAttributeDifficultyFactor($attributeKey) {
        if (in_array($attributeKey, array('creativity', 'influence', 'flair', 'penalty_killing'))) {
            return 1.12;
        }
        if (in_array($attributeKey, array('technique', 'passing', 'shooting', 'tackling'))) {
            return 1.00;
        }
        return 0.95;
    }

    private static function normalizePlayerValue($value) {
        return round(min(100, max(1, (float) $value)), 2);
    }

    private static function getPlayerName($player) {
        if (isset($player['pseudonym']) && strlen($player['pseudonym'])) {
            return $player['pseudonym'];
        }
        if (isset($player['kunstname']) && strlen($player['kunstname'])) {
            return $player['kunstname'];
        }
        $firstname = isset($player['firstname']) ? $player['firstname'] : (isset($player['vorname']) ? $player['vorname'] : '');
        $lastname = isset($player['lastname']) ? $player['lastname'] : (isset($player['nachname']) ? $player['nachname'] : '');
        return trim($firstname . ' ' . $lastname);
    }

    private static function ensureColumn(DbConnection $db, $table, $column, $alterSql) {
        if (!self::columnExists($db, $table, $column)) {
            try {
                $db->executeQuery($alterSql);
            } catch (Exception $e) {
                // Explicit SQL update file contains the same DDL.
            }
        }
    }

    private static function columnExists(DbConnection $db, $table, $column) {
        try {
            $result = $db->executeQuery("SHOW COLUMNS FROM " . $table . " LIKE '" . $db->connection->real_escape_string($column) . "'");
            $row = $result->fetch_array();
            $result->free();
            return $row ? true : false;
        } catch (Exception $e) {
            return false;
        }
    }
}
?>
