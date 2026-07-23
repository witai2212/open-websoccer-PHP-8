<?php
/******************************************************

  This file is part of OpenWebSoccer-Sim.

  OpenWebSoccer-Sim is free software: you can redistribute it 
  and/or modify it under the terms of the 
  GNU Lesser General Public License as published by the Free Software Foundation,
  either version 3 of the License, or any later version.

******************************************************/

/**
 * Data service for fan mood and media pressure.
 */
class FanPressureDataService {

    const SOURCE_MATCH = 'match';
    const SOURCE_DERBY = 'derby';
    const SOURCE_TICKET = 'ticket';
    const SOURCE_TRANSFER = 'transfer';
    const SOURCE_YOUTH = 'youth';
    const SOURCE_MISSION = 'mission';
    const SOURCE_BOARD = 'board';
    const SOURCE_INTERVIEW = 'interview';

    /**
     * Frontend messages are not loaded when match simulation runs from admin/job context.
     * FanPressure creates news/story rows during simulation, therefore it must load its
     * own frontend messages before composing user-visible text.
     */
    private static $_fanpressureMessagesLoaded = array();

    private static function ensureFanPressureMessagesLoaded(WebSoccer $websoccer, I18n $i18n) {
        global $msg;

        $language = $i18n->getCurrentLanguage();
        if (isset(self::$_fanpressureMessagesLoaded[$language])) {
            return;
        }
        self::$_fanpressureMessagesLoaded[$language] = TRUE;

        if (!isset($msg) || !is_array($msg)) {
            $msg = array();
        }

        // Load the normal frontend message cache as well. In admin context only
        // adminmessages/entitymessages are available by default.
        if (defined('CONFIGCACHE_MESSAGES')) {
            $cacheFile = sprintf(CONFIGCACHE_MESSAGES, $language);
            if (file_exists($cacheFile)) {
                include($cacheFile);
            }
        }

        // Robust fallback for older/stale caches: load this module's XML directly.
        if (defined('FOLDER_MODULES')) {
            $messageFile = FOLDER_MODULES . '/fanpressure/messages_' . $language . '.xml';
            if (!file_exists($messageFile) && $language !== 'de') {
                $messageFile = FOLDER_MODULES . '/fanpressure/messages_de.xml';
            }
            if (file_exists($messageFile)) {
                $xml = @simplexml_load_file($messageFile);
                if ($xml) {
                    foreach ($xml->message as $messageNode) {
                        $id = (string) $messageNode['id'];
                        if (strlen($id) && !isset($msg[$id])) {
                            $msg[$id] = (string) $messageNode;
                        }
                    }
                }
            }
        }
    }

    private static function getReasonLabelFallback($messageKey) {
        foreach (self::getDefaultStoryRules() as $rule) {
            if ($rule['event_key'] === $messageKey) {
                return $rule['label'];
            }
        }
        return '';
    }

    private static function getTranslatedMessage(I18n $i18n, $messageKey, $fallback = '') {
        if ($i18n->hasMessage($messageKey)) {
            return $i18n->getMessage($messageKey);
        }

        $reasonFallback = self::getReasonLabelFallback($messageKey);
        if (strlen($reasonFallback)) {
            return $reasonFallback;
        }

        if (strlen($fallback)) {
            return $fallback;
        }

        return $messageKey;
    }

    private static function decodeContextData($contextData) {
        if (!strlen((string) $contextData)) {
            return array();
        }
        $decoded = json_decode($contextData, TRUE);
        return is_array($decoded) ? $decoded : array();
    }

    private static function extractTeamNameFromStoryRow($row) {
        if (isset($row['team_name']) && strlen((string) $row['team_name'])) {
            return (string) $row['team_name'];
        }
        if (isset($row['title']) && preg_match('/^fanpressure_reason_[^:]+:\s*(.+)$/', (string) $row['title'], $match)) {
            return trim($match[1]);
        }
        if (isset($row['message']) && preg_match('/ bei ([^.]+)\./', (string) $row['message'], $match)) {
            return trim($match[1]);
        }
        return '';
    }

    /**
     * Rebuilds story title/message for display. This also fixes already stored
     * rows that were created while frontend messages were missing.
     */
    public static function normalizeStoryDisplayRow(WebSoccer $websoccer, I18n $i18n, $row) {
        self::ensureFanPressureMessagesLoaded($websoccer, $i18n);

        if (!isset($row['event_key']) || !strlen((string) $row['event_key'])) {
            return $row;
        }

        $context = isset($row['context']) && is_array($row['context'])
            ? $row['context']
            : (isset($row['context_data']) ? self::decodeContextData($row['context_data']) : array());

        $team = array(
            'id' => isset($row['team_id']) ? (int) $row['team_id'] : 0,
            'user_id' => isset($row['user_id']) ? (int) $row['user_id'] : 0,
            'name' => self::extractTeamNameFromStoryRow($row)
        );

        $row['context'] = $context;
        $row['title'] = self::buildStoryTitle($i18n, $team, $row['event_key'], $context);
        $row['message'] = self::buildStoryMessage(
            $i18n,
            $team,
            $row['event_key'],
            $context,
            isset($row['mood_change']) ? (int) $row['mood_change'] : 0,
            isset($row['pressure_change']) ? (int) $row['pressure_change'] : 0,
            isset($row['board_change']) ? (int) $row['board_change'] : 0,
            isset($row['chemistry_change']) ? (int) $row['chemistry_change'] : 0
        );

        return $row;
    }

    public static function isEnabled(WebSoccer $websoccer) {
        return self::getOptionalBooleanConfig($websoccer, 'fanpressure_enabled', TRUE);
    }

    public static function ensureSchema(WebSoccer $websoccer, DbConnection $db) {
        static $done = array();
        $prefix = $websoccer->getConfig('db_prefix');
        if (isset($done[$prefix])) {
            return;
        }
        $done[$prefix] = TRUE;

        $db->executeQuery("CREATE TABLE IF NOT EXISTS `" . $prefix . "_fanpressure_story_rule` (
            `event_key` varchar(128) NOT NULL,
            `label` varchar(160) NOT NULL,
            `source` varchar(32) NOT NULL DEFAULT 'match',
            `active` enum('1','0') NOT NULL DEFAULT '1',
            `mood_change` tinyint(4) NOT NULL DEFAULT 0,
            `pressure_change` tinyint(4) NOT NULL DEFAULT 0,
            `board_change` tinyint(4) NOT NULL DEFAULT 0,
            `chemistry_change` tinyint(4) NOT NULL DEFAULT 0,
            `create_notification` enum('1','0') NOT NULL DEFAULT '1',
            `create_news` enum('1','0') NOT NULL DEFAULT '0',
            `interview_chance` tinyint(3) NOT NULL DEFAULT 0,
            `updated_date` int(11) NOT NULL DEFAULT 0,
            PRIMARY KEY (`event_key`),
            KEY `idx_fanpressure_story_rule_active` (`active`,`source`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        $db->executeQuery("CREATE TABLE IF NOT EXISTS `" . $prefix . "_fanpressure_story_log` (
            `id` int(10) NOT NULL AUTO_INCREMENT,
            `team_id` int(10) NOT NULL,
            `user_id` int(10) NOT NULL DEFAULT 0,
            `event_key` varchar(128) NOT NULL,
            `reference_key` varchar(160) NOT NULL,
            `event_date` int(11) NOT NULL DEFAULT 0,
            `source` varchar(32) NOT NULL DEFAULT 'match',
            `title` varchar(160) NOT NULL,
            `message` text DEFAULT NULL,
            `mood_change` tinyint(4) NOT NULL DEFAULT 0,
            `pressure_change` tinyint(4) NOT NULL DEFAULT 0,
            `board_change` tinyint(4) NOT NULL DEFAULT 0,
            `chemistry_change` tinyint(4) NOT NULL DEFAULT 0,
            `new_mood` tinyint(3) NOT NULL DEFAULT 50,
            `new_pressure` tinyint(3) NOT NULL DEFAULT 30,
            `new_board_satisfaction` tinyint(3) NOT NULL DEFAULT 50,
            `new_chemistry` tinyint(3) NOT NULL DEFAULT 50,
            `match_id` int(10) NOT NULL DEFAULT 0,
            `news_id` int(10) NOT NULL DEFAULT 0,
            `notification_created` enum('1','0') NOT NULL DEFAULT '0',
            `context_data` text DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_fanpressure_story_reference` (`reference_key`),
            KEY `idx_fanpressure_story_team_date` (`team_id`,`event_date`),
            KEY `idx_fanpressure_story_event` (`event_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        $db->executeQuery("CREATE TABLE IF NOT EXISTS `" . $prefix . "_fanpressure_interview_question` (
            `id` int(10) NOT NULL AUTO_INCREMENT,
            `event_key` varchar(128) NOT NULL,
            `question` varchar(255) NOT NULL,
            `answer_a_label` varchar(160) NOT NULL,
            `answer_a_mood` tinyint(4) NOT NULL DEFAULT 0,
            `answer_a_pressure` tinyint(4) NOT NULL DEFAULT 0,
            `answer_a_board` tinyint(4) NOT NULL DEFAULT 0,
            `answer_a_chemistry` tinyint(4) NOT NULL DEFAULT 0,
            `answer_b_label` varchar(160) NOT NULL,
            `answer_b_mood` tinyint(4) NOT NULL DEFAULT 0,
            `answer_b_pressure` tinyint(4) NOT NULL DEFAULT 0,
            `answer_b_board` tinyint(4) NOT NULL DEFAULT 0,
            `answer_b_chemistry` tinyint(4) NOT NULL DEFAULT 0,
            `answer_c_label` varchar(160) NOT NULL,
            `answer_c_mood` tinyint(4) NOT NULL DEFAULT 0,
            `answer_c_pressure` tinyint(4) NOT NULL DEFAULT 0,
            `answer_c_board` tinyint(4) NOT NULL DEFAULT 0,
            `answer_c_chemistry` tinyint(4) NOT NULL DEFAULT 0,
            `active` enum('1','0') NOT NULL DEFAULT '1',
            `weight` tinyint(3) NOT NULL DEFAULT 1,
            PRIMARY KEY (`id`),
            KEY `idx_fanpressure_interview_event` (`event_key`,`active`,`weight`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        $db->executeQuery("CREATE TABLE IF NOT EXISTS `" . $prefix . "_fanpressure_interview_occurrence` (
            `id` int(10) NOT NULL AUTO_INCREMENT,
            `question_id` int(10) NOT NULL,
            `user_id` int(10) NOT NULL,
            `team_id` int(10) NOT NULL,
            `match_id` int(10) NOT NULL DEFAULT 0,
            `event_key` varchar(128) NOT NULL,
            `reference_key` varchar(160) NOT NULL,
            `created_date` int(11) NOT NULL DEFAULT 0,
            `expires_date` int(11) NOT NULL DEFAULT 0,
            `status` enum('open','answered','expired') NOT NULL DEFAULT 'open',
            `answer_key` char(1) DEFAULT NULL,
            `answered_date` int(11) NOT NULL DEFAULT 0,
            `context_data` text DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_fanpressure_interview_reference` (`reference_key`),
            KEY `idx_fanpressure_interview_user_status` (`user_id`,`team_id`,`status`),
            KEY `idx_fanpressure_interview_question` (`question_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        // Extend existing log source enum only once, so interview answers can be logged safely.
        $sourceColumn = $db->executeQuery("SHOW COLUMNS FROM `" . $prefix . "_fan_mood_log` LIKE 'source'");
        $sourceInfo = $sourceColumn->fetch_array();
        $sourceColumn->free();
        if ($sourceInfo && strpos($sourceInfo['Type'], 'interview') === FALSE) {
            $db->executeQuery("ALTER TABLE `" . $prefix . "_fan_mood_log` MODIFY `source` enum('match','derby','ticket','transfer','youth','mission','board','interview') NOT NULL DEFAULT 'match'");
        }

        self::seedDefaultStoryRules($websoccer, $db);
        self::seedDefaultInterviewQuestions($websoccer, $db);
    }

    private static function seedDefaultStoryRules(WebSoccer $websoccer, DbConnection $db) {
        $prefix = $websoccer->getConfig('db_prefix');
        foreach (self::getDefaultStoryRules() as $rule) {
            $sql = "INSERT IGNORE INTO `" . $prefix . "_fanpressure_story_rule` "
                . "(`event_key`,`label`,`source`,`active`,`mood_change`,`pressure_change`,`board_change`,`chemistry_change`,`create_notification`,`create_news`,`interview_chance`,`updated_date`) VALUES ("
                . "'" . $db->connection->real_escape_string($rule['event_key']) . "',"
                . "'" . $db->connection->real_escape_string($rule['label']) . "',"
                . "'" . $db->connection->real_escape_string($rule['source']) . "',"
                . "'1',"
                . (int) $rule['mood_change'] . ","
                . (int) $rule['pressure_change'] . ","
                . (int) $rule['board_change'] . ","
                . (int) $rule['chemistry_change'] . ","
                . "'" . ($rule['create_notification'] ? '1' : '0') . "',"
                . "'" . ($rule['create_news'] ? '1' : '0') . "',"
                . (int) $rule['interview_chance'] . ","
                . (int) $websoccer->getNowAsTimestamp() . ")";
            $db->executeQuery($sql);
        }
    }

    private static function seedDefaultInterviewQuestions(WebSoccer $websoccer, DbConnection $db) {
        $prefix = $websoccer->getConfig('db_prefix');
        $existing = $db->querySelect('COUNT(*) AS hits', $prefix . '_fanpressure_interview_question', '1=1');
        $row = $existing->fetch_array();
        $existing->free();
        if ($row && (int) $row['hits'] > 0) {
            return;
        }

        $questions = array(
            array('fanpressure_reason_big_loss', 'Die Medien sprechen von einem Warnsignal. Was sagen Sie der Mannschaft?', 'Wir analysieren klar und reagieren sofort.', -1, -2, 1, 2, 'Die Spieler müssen jetzt Verantwortung übernehmen.', -2, 2, 0, -2, 'Das Ergebnis war unglücklich, kein Grund zur Panik.', 0, 1, -1, 0),
            array('fanpressure_reason_derby_win', 'Nach dem Derbysieg feiern die Fans. Wie ordnen Sie den Erfolg ein?', 'Das war ein Sieg für den ganzen Verein.', 2, -1, 1, 1, 'Wir bleiben ruhig, es zählen die nächsten Spiele.', 0, -2, 1, 0, 'Jetzt sollen die Fans ruhig träumen dürfen.', 3, 1, 0, 0),
            array('fanpressure_reason_derby_loss', 'Die Derbyniederlage schmerzt. Was ist Ihre Botschaft?', 'Wir entschuldigen uns bei den Fans und liefern eine Reaktion.', 1, -1, 1, 1, 'So darf man ein Derby nicht verlieren.', -2, 3, 0, -2, 'Der Druck gehört dazu, wir nehmen ihn an.', 0, 1, 1, 0),
            array('fanpressure_reason_transfer_sale', 'Ein wichtiger Spieler wurde verkauft. Warum war dieser Schritt richtig?', 'Der Verein steht über jedem Einzelnen.', -1, 1, 2, 0, 'Wir reinvestieren und bleiben sportlich ambitioniert.', 1, -1, 1, 1, 'Der Markt hat diese Entscheidung erzwungen.', -2, 2, 0, -1),
            array('fanpressure_reason_mission_failed', 'Der Vorstand ist enttäuscht. Wie reagieren Sie?', 'Ich übernehme Verantwortung und stelle nach.', 0, -1, 2, 1, 'Die Zielsetzung war unter den Umständen unrealistisch.', -2, 3, -2, -1, 'Wir werden intern hart arbeiten.', 0, 0, 1, 2),
            array('fanpressure_reason_loss_streak_3', 'Drei Niederlagen in Folge: Wie stoppen Sie die Serie?', 'Wir ändern Details und halten zusammen.', 0, -1, 1, 2, 'Einige Spieler müssen jetzt liefern.', -2, 2, 0, -2, 'Wir blenden die Tabelle aus und arbeiten Schritt für Schritt.', 1, -1, 0, 1)
        );

        foreach ($questions as $q) {
            $columns = array(
                'event_key' => $q[0],
                'question' => $q[1],
                'answer_a_label' => $q[2], 'answer_a_mood' => $q[3], 'answer_a_pressure' => $q[4], 'answer_a_board' => $q[5], 'answer_a_chemistry' => $q[6],
                'answer_b_label' => $q[7], 'answer_b_mood' => $q[8], 'answer_b_pressure' => $q[9], 'answer_b_board' => $q[10], 'answer_b_chemistry' => $q[11],
                'answer_c_label' => $q[12], 'answer_c_mood' => $q[13], 'answer_c_pressure' => $q[14], 'answer_c_board' => $q[15], 'answer_c_chemistry' => $q[16],
                'active' => '1',
                'weight' => 1
            );
            $db->queryInsert($columns, $prefix . '_fanpressure_interview_question');
        }
    }

    private static function getDefaultStoryRules() {
        return array(
            array('event_key' => 'fanpressure_reason_match_win', 'label' => 'Sieg', 'source' => self::SOURCE_MATCH, 'mood_change' => 3, 'pressure_change' => -2, 'board_change' => 0, 'chemistry_change' => 1, 'create_notification' => TRUE, 'create_news' => FALSE, 'interview_chance' => 5),
            array('event_key' => 'fanpressure_reason_match_draw', 'label' => 'Unentschieden', 'source' => self::SOURCE_MATCH, 'mood_change' => 0, 'pressure_change' => 1, 'board_change' => 0, 'chemistry_change' => 0, 'create_notification' => FALSE, 'create_news' => FALSE, 'interview_chance' => 0),
            array('event_key' => 'fanpressure_reason_match_loss', 'label' => 'Niederlage', 'source' => self::SOURCE_MATCH, 'mood_change' => -3, 'pressure_change' => 4, 'board_change' => 0, 'chemistry_change' => -1, 'create_notification' => TRUE, 'create_news' => FALSE, 'interview_chance' => 10),
            array('event_key' => 'fanpressure_reason_big_win', 'label' => 'Deutlicher Sieg', 'source' => self::SOURCE_MATCH, 'mood_change' => 5, 'pressure_change' => -3, 'board_change' => 1, 'chemistry_change' => 2, 'create_notification' => TRUE, 'create_news' => TRUE, 'interview_chance' => 25),
            array('event_key' => 'fanpressure_reason_big_loss', 'label' => 'Deutliche Niederlage', 'source' => self::SOURCE_MATCH, 'mood_change' => -5, 'pressure_change' => 6, 'board_change' => -1, 'chemistry_change' => -2, 'create_notification' => TRUE, 'create_news' => TRUE, 'interview_chance' => 60),
            array('event_key' => 'fanpressure_reason_derby_win', 'label' => 'Derbysieg', 'source' => self::SOURCE_DERBY, 'mood_change' => 8, 'pressure_change' => -4, 'board_change' => 2, 'chemistry_change' => 2, 'create_notification' => TRUE, 'create_news' => TRUE, 'interview_chance' => 50),
            array('event_key' => 'fanpressure_reason_derby_draw', 'label' => 'Derby-Unentschieden', 'source' => self::SOURCE_DERBY, 'mood_change' => 1, 'pressure_change' => 1, 'board_change' => 0, 'chemistry_change' => 0, 'create_notification' => TRUE, 'create_news' => FALSE, 'interview_chance' => 10),
            array('event_key' => 'fanpressure_reason_derby_loss', 'label' => 'Derbyniederlage', 'source' => self::SOURCE_DERBY, 'mood_change' => -8, 'pressure_change' => 8, 'board_change' => -2, 'chemistry_change' => -2, 'create_notification' => TRUE, 'create_news' => TRUE, 'interview_chance' => 70),
            array('event_key' => 'fanpressure_reason_youth_used', 'label' => 'Junge Spieler eingesetzt', 'source' => self::SOURCE_YOUTH, 'mood_change' => 2, 'pressure_change' => 0, 'board_change' => 1, 'chemistry_change' => 1, 'create_notification' => TRUE, 'create_news' => FALSE, 'interview_chance' => 5),
            array('event_key' => 'fanpressure_reason_ticket_prices_high', 'label' => 'Hohe Eintrittspreise', 'source' => self::SOURCE_TICKET, 'mood_change' => -4, 'pressure_change' => 3, 'board_change' => 0, 'chemistry_change' => 0, 'create_notification' => TRUE, 'create_news' => FALSE, 'interview_chance' => 0),
            array('event_key' => 'fanpressure_reason_ticket_prices_low', 'label' => 'Fanfreundliche Eintrittspreise', 'source' => self::SOURCE_TICKET, 'mood_change' => 1, 'pressure_change' => -1, 'board_change' => 0, 'chemistry_change' => 0, 'create_notification' => FALSE, 'create_news' => FALSE, 'interview_chance' => 0),
            array('event_key' => 'fanpressure_reason_transfer_signing', 'label' => 'Neuzugang', 'source' => self::SOURCE_TRANSFER, 'mood_change' => 2, 'pressure_change' => 1, 'board_change' => 1, 'chemistry_change' => 0, 'create_notification' => TRUE, 'create_news' => FALSE, 'interview_chance' => 25),
            array('event_key' => 'fanpressure_reason_transfer_sale', 'label' => 'Spielerverkauf', 'source' => self::SOURCE_TRANSFER, 'mood_change' => -3, 'pressure_change' => 2, 'board_change' => 0, 'chemistry_change' => 0, 'create_notification' => TRUE, 'create_news' => FALSE, 'interview_chance' => 40),
            array('event_key' => 'fanpressure_reason_mission_completed', 'label' => 'Vorstandsziel erreicht', 'source' => self::SOURCE_MISSION, 'mood_change' => 2, 'pressure_change' => -1, 'board_change' => 3, 'chemistry_change' => 1, 'create_notification' => TRUE, 'create_news' => TRUE, 'interview_chance' => 30),
            array('event_key' => 'fanpressure_reason_mission_failed', 'label' => 'Vorstandsziel verfehlt', 'source' => self::SOURCE_MISSION, 'mood_change' => -3, 'pressure_change' => 2, 'board_change' => -4, 'chemistry_change' => -1, 'create_notification' => TRUE, 'create_news' => TRUE, 'interview_chance' => 50),
            array('event_key' => 'fanpressure_reason_board_mood_bonus', 'label' => 'Sehr gute Fanlage', 'source' => self::SOURCE_BOARD, 'mood_change' => 0, 'pressure_change' => 0, 'board_change' => 1, 'chemistry_change' => 0, 'create_notification' => FALSE, 'create_news' => FALSE, 'interview_chance' => 0),
            array('event_key' => 'fanpressure_reason_board_mood_penalty', 'label' => 'Kritische Fanlage', 'source' => self::SOURCE_BOARD, 'mood_change' => 0, 'pressure_change' => 0, 'board_change' => -1, 'chemistry_change' => 0, 'create_notification' => FALSE, 'create_news' => FALSE, 'interview_chance' => 0),
            array('event_key' => 'fanpressure_reason_win_streak_3', 'label' => 'Drei Siege in Folge', 'source' => self::SOURCE_MATCH, 'mood_change' => 4, 'pressure_change' => -4, 'board_change' => 2, 'chemistry_change' => 2, 'create_notification' => TRUE, 'create_news' => TRUE, 'interview_chance' => 25),
            array('event_key' => 'fanpressure_reason_loss_streak_3', 'label' => 'Drei Niederlagen in Folge', 'source' => self::SOURCE_MATCH, 'mood_change' => -4, 'pressure_change' => 6, 'board_change' => -2, 'chemistry_change' => -2, 'create_notification' => TRUE, 'create_news' => TRUE, 'interview_chance' => 55),
            array('event_key' => 'fanpressure_reason_loss_streak_5', 'label' => 'Fünf Niederlagen in Folge', 'source' => self::SOURCE_MATCH, 'mood_change' => -7, 'pressure_change' => 10, 'board_change' => -4, 'chemistry_change' => -3, 'create_notification' => TRUE, 'create_news' => TRUE, 'interview_chance' => 75),
            array('event_key' => 'fanpressure_reason_cup_upset_win', 'label' => 'Pokalüberraschung gegen stärkeren Gegner', 'source' => self::SOURCE_MATCH, 'mood_change' => 6, 'pressure_change' => -4, 'board_change' => 2, 'chemistry_change' => 2, 'create_notification' => TRUE, 'create_news' => TRUE, 'interview_chance' => 45),
            array('event_key' => 'fanpressure_reason_cup_elimination_weaker', 'label' => 'Pokal-Aus gegen schwächeren Gegner', 'source' => self::SOURCE_MATCH, 'mood_change' => -6, 'pressure_change' => 8, 'board_change' => -3, 'chemistry_change' => -2, 'create_notification' => TRUE, 'create_news' => TRUE, 'interview_chance' => 70),
            array('event_key' => 'fanpressure_reason_interview_answer', 'label' => 'Interview-Antwort', 'source' => self::SOURCE_INTERVIEW, 'mood_change' => 0, 'pressure_change' => 0, 'board_change' => 0, 'chemistry_change' => 0, 'create_notification' => FALSE, 'create_news' => FALSE, 'interview_chance' => 0),
            array('event_key' => 'fanpressure_reason_trait_star_performance', 'label' => 'Spezialist überzeugt', 'source' => self::SOURCE_MATCH, 'mood_change' => 2, 'pressure_change' => -1, 'board_change' => 1, 'chemistry_change' => 1, 'create_notification' => TRUE, 'create_news' => FALSE, 'interview_chance' => 10),
            array('event_key' => 'fanpressure_reason_trait_big_moment', 'label' => 'Spezialfähigkeit entscheidet Spiel', 'source' => self::SOURCE_MATCH, 'mood_change' => 3, 'pressure_change' => -2, 'board_change' => 1, 'chemistry_change' => 1, 'create_notification' => TRUE, 'create_news' => TRUE, 'interview_chance' => 20),
            array('event_key' => 'fanpressure_reason_board_crisis', 'label' => 'Vorstandskrise', 'source' => self::SOURCE_BOARD, 'mood_change' => 0, 'pressure_change' => 2, 'board_change' => 0, 'chemistry_change' => 0, 'create_notification' => TRUE, 'create_news' => TRUE, 'interview_chance' => 0),
            array('event_key' => 'fanpressure_reason_board_support', 'label' => 'Rückendeckung des Vorstands', 'source' => self::SOURCE_BOARD, 'mood_change' => 0, 'pressure_change' => -1, 'board_change' => 0, 'chemistry_change' => 0, 'create_notification' => TRUE, 'create_news' => FALSE, 'interview_chance' => 0)
        );
    }

    /**
     * Returns page data for the current manager team.
     */
    public static function getPageData(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $teamId) {
        self::ensureFanPressureMessagesLoaded($websoccer, $i18n);
        self::ensureSchema($websoccer, $db);
        self::ensureInitialState($websoccer, $db, $teamId);
        $team = self::getManagedTeam($websoccer, $db, $teamId);

        if (!$team) {
            return array(
                'team' => array(),
                'log' => array(),
                'attendance_effect' => 0,
                'sponsor_effect' => 0,
                'mood_label' => 'fanpressure_value_neutral',
                'hint_key' => 'fanpressure_neutral_hint',
                'story_log' => array(),
                'open_interviews' => array()
            );
        }

        $fanMood = self::normalizePercent($team['fan_mood']);
        $mediaPressure = self::normalizePercent($team['media_pressure']);

        $log = self::getRecentLog($websoccer, $db, $i18n, $teamId, 50);
        $storyLog = self::getRecentStoryLog($websoccer, $db, $i18n, $teamId, 50);

        return array(
            'team' => $team,
            'fan_mood' => $fanMood,
            'media_pressure' => $mediaPressure,
            'board_satisfaction' => self::normalizePercent($team['board_satisfaction']),
            'attendance_effect' => self::getAttendanceEffectPercent($websoccer, $fanMood),
            'sponsor_effect' => self::getSponsorEffectPercent($websoccer, $fanMood),
            'mood_label' => self::getMoodLabelKey($fanMood),
            'hint_key' => self::getMoodHintKey($fanMood),
            'log' => $log,
            'log_groups' => self::groupRowsByDate($log, 'event_date'),
            'story_log' => $storyLog,
            'story_log_groups' => self::groupRowsByDate($storyLog, 'event_date'),
            'open_interviews' => self::getOpenInterviews($websoccer, $db, $i18n, (int) $team['user_id'], $teamId)
        );
    }

    /**
     * Applies the attendance effect via TicketsComputedEvent.
     */
    public static function applyAttendanceEffect(TicketsComputedEvent $event) {
        if (!self::isEnabled($event->websoccer)) {
            return;
        }

        if (!$event->match || !$event->match->homeTeam || $event->match->homeTeam->isNationalTeam) {
            return;
        }

        $teamId = (int) $event->match->homeTeam->id;
        $team = self::getManagedTeam($event->websoccer, $event->db, $teamId);
        if (!$team) {
            return;
        }

        $effectPercent = self::getAttendanceEffectPercent($event->websoccer, (int) $team['fan_mood']);
        if ($effectPercent == 0) {
            return;
        }

        $factor = 1 + ($effectPercent / 100);
        $event->rateStands = min(1, max(0, $event->rateStands * $factor));
        $event->rateSeats = min(1, max(0, $event->rateSeats * $factor));
        $event->rateStandsGrand = min(1, max(0, $event->rateStandsGrand * $factor));
        $event->rateSeatsGrand = min(1, max(0, $event->rateSeatsGrand * $factor));
        $event->rateVip = min(1, max(0, $event->rateVip * $factor));
    }

    /**
     * Returns extra/penalty percentage for fan sponsor attendance bonus.
     */
    public static function getSponsorEffectPercent(WebSoccer $websoccer, $fanMood) {
        $fanMood = self::normalizePercent($fanMood);
        $maxEffect = (int) self::getOptionalConfig($websoccer, 'fanpressure_sponsor_max_effect', 10);
        if ($maxEffect < 0) {
            $maxEffect = 0;
        }

        if ($fanMood > 60) {
            return (int) round((($fanMood - 60) / 40) * $maxEffect);
        }
        if ($fanMood < 40) {
            return 0 - (int) round(((40 - $fanMood) / 40) * $maxEffect);
        }
        return 0;
    }

    /**
     * Adjusts fan-based sponsor attendance bonus. Non-fan sponsors pass bonusPercent=0 and are unaffected.
     */
    public static function adjustFanSponsorBonus(WebSoccer $websoccer, $baseBonus, $teamId) {
        if (!self::isEnabled($websoccer) || (int) $baseBonus <= 0 || (int) $teamId < 1) {
            return (int) $baseBonus;
        }

        $db = DbConnection::getInstance();
        $team = self::getManagedTeam($websoccer, $db, $teamId);
        if (!$team) {
            return (int) $baseBonus;
        }

        $effectPercent = self::getSponsorEffectPercent($websoccer, (int) $team['fan_mood']);
        if ($effectPercent == 0) {
            return (int) $baseBonus;
        }

        return max(0, (int) round((int) $baseBonus * (1 + ($effectPercent / 100))));
    }

    /**
     * Processes result, derby, youth and board ripple effects after a match.
     */
    public static function processCompletedMatch(MatchCompletedEvent $event) {
        if (!self::isEnabled($event->websoccer) || !$event->match) {
            return;
        }

        self::ensureSchema($event->websoccer, $event->db);

        $match = $event->match;
        if ($match->type !== 'Ligaspiel' && $match->type !== 'Pokalspiel') {
            return;
        }

        self::processTeamMatchResult($event, (int) $match->homeTeam->id, TRUE);
        self::processTeamMatchResult($event, (int) $match->guestTeam->id, FALSE);
    }

    public static function processTicketPriceChange(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $teamId, $newPrices) {
        if (!self::isEnabled($websoccer)) {
            return;
        }

        self::ensureSchema($websoccer, $db);

        $team = self::getManagedTeamWithLeaguePrices($websoccer, $db, $teamId);
        if (!$team) {
            return;
        }

        $ratio = self::getTicketPriceRatio($team, $newPrices);
        $change = 0;
        $mediaChange = 0;
        $reasonKey = '';

        if ($ratio >= 1.50) {
            $change = -4;
            $mediaChange = 3;
            $reasonKey = 'fanpressure_reason_ticket_prices_high';
        } else if ($ratio >= 1.25) {
            $change = -2;
            $mediaChange = 1;
            $reasonKey = 'fanpressure_reason_ticket_prices_high';
        } else if ($ratio <= 0.85) {
            $change = 1;
            $mediaChange = -1;
            $reasonKey = 'fanpressure_reason_ticket_prices_low';
        }

        if ($change != 0 || $mediaChange != 0) {
            self::changeMoodAndPressure(
                $websoccer,
                $db,
                $i18n,
                (int) $teamId,
                $change,
                $mediaChange,
                $reasonKey,
                self::SOURCE_TICKET,
                0,
                array('ratio' => round($ratio * 100))
            );
        }
    }

    /**
     * Processes a player signing/sale. Only human clubs are affected.
     */
    public static function processTransfer(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $playerId, $sellerTeamId, $buyerTeamId, $amount = 0) {
        if (!self::isEnabled($websoccer)) {
            return;
        }

        self::ensureSchema($websoccer, $db);

        $player = self::getPlayerInfo($websoccer, $db, $playerId);
        if (!$player) {
            return;
        }

        $playerName = self::getPlayerName($player);
        $playerStrength = self::getPlayerEffectiveStrength($player);

        if ((int) $sellerTeamId > 0 && (int) $sellerTeamId != (int) $buyerTeamId) {
            $seller = self::getManagedTeam($websoccer, $db, $sellerTeamId);
            if ($seller) {
                $avgStrength = self::getAveragePlayerStrength($websoccer, $db, $sellerTeamId);
                $change = self::getSaleMoodChange($playerStrength, $avgStrength);
                $mediaChange = ($change <= -4) ? 2 : 1;
                self::changeMoodAndPressure(
                    $websoccer,
                    $db,
                    $i18n,
                    (int) $sellerTeamId,
                    $change,
                    $mediaChange,
                    'fanpressure_reason_transfer_sale',
                    self::SOURCE_TRANSFER,
                    0,
                    array('player' => $playerName, 'amount' => (int) $amount)
                );
            }
        }

        if ((int) $buyerTeamId > 0 && (int) $sellerTeamId != (int) $buyerTeamId) {
            $buyer = self::getManagedTeam($websoccer, $db, $buyerTeamId);
            if ($buyer) {
                $avgStrength = self::getAveragePlayerStrength($websoccer, $db, $buyerTeamId);
                $change = self::getSigningMoodChange($playerStrength, $avgStrength);
                $mediaChange = ($change >= 3) ? 1 : 0;
                self::changeMoodAndPressure(
                    $websoccer,
                    $db,
                    $i18n,
                    (int) $buyerTeamId,
                    $change,
                    $mediaChange,
                    'fanpressure_reason_transfer_signing',
                    self::SOURCE_TRANSFER,
                    0,
                    array('player' => $playerName, 'amount' => (int) $amount)
                );
            }
        }
    }

    public static function processMissionCompleted(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $teamId, $missionTitle) {
        if (!self::isEnabled($websoccer)) {
            return;
        }

        self::ensureSchema($websoccer, $db);

        self::changeMoodAndPressure(
            $websoccer,
            $db,
            $i18n,
            (int) $teamId,
            2,
            -1,
            'fanpressure_reason_mission_completed',
            self::SOURCE_MISSION,
            0,
            array('mission' => $missionTitle)
        );
    }

    public static function processMissionFailed(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $teamId, $missionTitle) {
        if (!self::isEnabled($websoccer)) {
            return;
        }

        self::ensureSchema($websoccer, $db);

        self::changeMoodAndPressure(
            $websoccer,
            $db,
            $i18n,
            (int) $teamId,
            -3,
            2,
            'fanpressure_reason_mission_failed',
            self::SOURCE_MISSION,
            0,
            array('mission' => $missionTitle)
        );
    }

    private static function processTeamMatchResult(MatchCompletedEvent $event, $teamId, $isHome) {
        $team = self::getManagedTeam($event->websoccer, $event->db, $teamId);
        if (!$team) {
            return;
        }

        $match = $event->match;
        $homeGoals = (int) $match->homeTeam->getGoals();
        $guestGoals = (int) $match->guestTeam->getGoals();
        $ownGoals = $isHome ? $homeGoals : $guestGoals;
        $oppGoals = $isHome ? $guestGoals : $homeGoals;
        $goalDiff = $ownGoals - $oppGoals;

        $reasonKey = 'fanpressure_reason_match_draw';
        $change = 0;
        $mediaChange = 1;

        if ($goalDiff > 0) {
            $reasonKey = 'fanpressure_reason_match_win';
            $change = 3;
            $mediaChange = -2;
        } else if ($goalDiff < 0) {
            $reasonKey = 'fanpressure_reason_match_loss';
            $change = -3;
            $mediaChange = 4;
        }

        if (abs($goalDiff) >= 3) {
            if ($goalDiff > 0) {
                $change += 2;
                $reasonKey = 'fanpressure_reason_big_win';
            } else {
                $change -= 2;
                $reasonKey = 'fanpressure_reason_big_loss';
            }
        }

        $derbyInfo = self::getDerbyInfoSafe($event->websoccer, $event->db, $match);
        if ($derbyInfo) {
            if ($goalDiff > 0) {
                $change += 5;
                $mediaChange -= 2;
                $reasonKey = 'fanpressure_reason_derby_win';
            } else if ($goalDiff < 0) {
                $change -= 5;
                $mediaChange += 4;
                $reasonKey = 'fanpressure_reason_derby_loss';
            } else {
                $change += 1;
                $reasonKey = 'fanpressure_reason_derby_draw';
            }
        }

        $mediaPressure = self::normalizePercent($team['media_pressure']);
        if ($goalDiff != 0 && $mediaPressure >= 85) {
            $change += ($goalDiff > 0) ? 2 : -2;
        } else if ($goalDiff != 0 && $mediaPressure >= 70) {
            $change += ($goalDiff > 0) ? 1 : -1;
        }

        self::changeMoodAndPressure(
            $event->websoccer,
            $event->db,
            $event->i18n,
            $teamId,
            $change,
            $mediaChange,
            $reasonKey,
            $derbyInfo ? self::SOURCE_DERBY : self::SOURCE_MATCH,
            (int) $match->id,
            array('score' => $ownGoals . ':' . $oppGoals)
        );

        $youthUsed = self::countYoungPlayersUsed($event->websoccer, $event->db, (int) $match->id, $teamId);
        if ($youthUsed > 0) {
            self::changeMoodAndPressure(
                $event->websoccer,
                $event->db,
                $event->i18n,
                $teamId,
                min(2, $youthUsed),
                0,
                'fanpressure_reason_youth_used',
                self::SOURCE_YOUTH,
                (int) $match->id,
                array('players' => $youthUsed)
            );
        }

        self::processTraitPerformanceStories($event, $teamId);
        self::processCupStory($event, $teamId, $isHome, $goalDiff);
        self::processResultStreak($event, $teamId, $goalDiff);
        self::applyBoardRipple($event->websoccer, $event->db, $event->i18n, $teamId);
    }

    private static function applyBoardRipple(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $teamId) {
        $team = self::getManagedTeam($websoccer, $db, $teamId);
        if (!$team) {
            return;
        }

        $fanMood = self::normalizePercent($team['fan_mood']);
        $reasonKey = '';
        if ($fanMood < 30) {
            $reasonKey = 'fanpressure_reason_board_mood_penalty';
        } else if ($fanMood > 75) {
            $reasonKey = 'fanpressure_reason_board_mood_bonus';
        }

        if (!$reasonKey) {
            return;
        }

        self::changeMoodAndPressure(
            $websoccer,
            $db,
            $i18n,
            (int) $teamId,
            0,
            0,
            $reasonKey,
            self::SOURCE_BOARD,
            0,
            array('fan_mood' => $fanMood)
        );
    }

    private static function changeMoodAndPressure(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $teamId, $moodChange, $pressureChange, $reasonKey, $source, $matchId = 0, $context = array(), $boardChange = 0, $chemistryChange = 0) {
        self::ensureFanPressureMessagesLoaded($websoccer, $i18n);
        self::ensureSchema($websoccer, $db);

        $team = self::getManagedTeam($websoccer, $db, $teamId);
        if (!$team) {
            return;
        }

        $rule = self::getStoryRule($websoccer, $db, $reasonKey, $source, $moodChange, $pressureChange, $boardChange, $chemistryChange);
        if (!$rule || $rule['active'] != '1') {
            return;
        }

        if ($source != self::SOURCE_INTERVIEW || $reasonKey != 'fanpressure_reason_interview_answer') {
            $moodChange = (int) $rule['mood_change'];
            $pressureChange = (int) $rule['pressure_change'];
            $boardChange = (int) $rule['board_change'];
            $chemistryChange = (int) $rule['chemistry_change'];
        }

        if (class_exists('ManagerCharacterDataService')) {
            ManagerCharacterDataService::adjustFanPressureChanges(
                $websoccer,
                $db,
                $team,
                $source,
                $reasonKey,
                $moodChange,
                $pressureChange,
                $boardChange,
                $chemistryChange,
                $context
            );
        }

        $oldMood = self::normalizePercent($team['fan_mood']);
        $oldPressure = self::normalizePercent($team['media_pressure']);
        $oldBoard = self::normalizePercent($team['board_satisfaction']);
        $oldChemistry = isset($team['team_chemistry']) ? self::normalizePercent($team['team_chemistry']) : 0;

        $newMood = self::normalizePercent($oldMood + $moodChange);
        $newPressure = self::normalizePercent($oldPressure + $pressureChange);
        $newBoard = self::normalizePercent($oldBoard + $boardChange);
        $newChemistry = isset($team['team_chemistry']) ? self::normalizePercent($oldChemistry + $chemistryChange) : 0;

        if ($oldMood == $newMood && $oldPressure == $newPressure && $oldBoard == $newBoard && (!isset($team['team_chemistry']) || $oldChemistry == $newChemistry)) {
            return;
        }

        $columns = array(
            'fan_mood' => $newMood,
            'media_pressure' => $newPressure,
            'board_satisfaction' => $newBoard
        );
        if (isset($team['team_chemistry'])) {
            $columns['team_chemistry'] = $newChemistry;
            $columns['team_chemistry_updated'] = $websoccer->getNowAsTimestamp();
        }

        $db->queryUpdate(
            $columns,
            $websoccer->getConfig('db_prefix') . '_verein',
            'id = %d',
            (int) $teamId
        );

        if (isset($team['team_chemistry']) && $oldChemistry != $newChemistry) {
            self::insertTeamChemistryLog($websoccer, $db, $teamId, $source, $oldChemistry, $newChemistry, $matchId, array('reason_key' => $reasonKey));
        }

        self::insertLog(
            $websoccer,
            $db,
            (int) $teamId,
            (int) $team['user_id'],
            $source,
            $reasonKey,
            $moodChange,
            $oldMood,
            $newMood,
            $pressureChange,
            $oldPressure,
            $newPressure,
            $boardChange,
            $oldBoard,
            $newBoard,
            (int) $matchId,
            array_merge($context, array('chemistry_change' => $chemistryChange, 'old_chemistry' => $oldChemistry, 'new_chemistry' => $newChemistry))
        );

        $storyId = self::insertStoryLog(
            $websoccer,
            $db,
            $i18n,
            $team,
            $reasonKey,
            $source,
            $matchId,
            $moodChange,
            $pressureChange,
            $boardChange,
            $chemistryChange,
            $newMood,
            $newPressure,
            $newBoard,
            $newChemistry,
            $context
        );

        if ((int) $team['user_id'] > 0 && isset($rule['create_notification']) && $rule['create_notification'] == '1') {
            self::createStoryNotification($websoccer, $db, $i18n, (int) $team['user_id'], (int) $teamId, $reasonKey, $moodChange, $pressureChange, $boardChange, $chemistryChange, $newMood, $newPressure, $newBoard);
        }
        if (isset($rule['create_news']) && $rule['create_news'] == '1') {
            self::createStoryNews($websoccer, $db, $i18n, $team, $reasonKey, $context, (int) $storyId, $moodChange, $pressureChange, $boardChange, $chemistryChange);
        }

        self::maybeCreateExtremeNews($websoccer, $db, $i18n, $team['name'], $newMood);
        self::maybeCreateInterview($websoccer, $db, $i18n, $team, $rule, $reasonKey, $matchId, $context);
        self::maybeCreateBoardThresholdStory($websoccer, $db, $i18n, $team, $newBoard, $matchId);
    }

    private static function insertLog(WebSoccer $websoccer, DbConnection $db, $teamId, $userId, $source, $reasonKey, $moodChange, $oldMood, $newMood, $pressureChange, $oldPressure, $newPressure, $boardChange, $oldBoard, $newBoard, $matchId, $context) {
        $db->queryInsert(
            array(
                'team_id' => (int) $teamId,
                'user_id' => (int) $userId,
                'event_date' => $websoccer->getNowAsTimestamp(),
                'source' => $source,
                'reason_key' => $reasonKey,
                'mood_change' => (int) $moodChange,
                'old_mood' => self::normalizePercent($oldMood),
                'new_mood' => self::normalizePercent($newMood),
                'pressure_change' => (int) $pressureChange,
                'old_pressure' => self::normalizePercent($oldPressure),
                'new_pressure' => self::normalizePercent($newPressure),
                'board_change' => (int) $boardChange,
                'old_board_satisfaction' => self::normalizePercent($oldBoard),
                'new_board_satisfaction' => self::normalizePercent($newBoard),
                'match_id' => ((int) $matchId > 0) ? (int) $matchId : '',
                'context_data' => (is_array($context) && count($context)) ? json_encode($context) : ''
            ),
            $websoccer->getConfig('db_prefix') . '_fan_mood_log'
        );
    }


    private static function groupRowsByDate($rows, $timestampKey) {
        $groups = array();
        foreach ($rows as $row) {
            $timestamp = isset($row[$timestampKey]) ? (int) $row[$timestampKey] : 0;
            $key = $timestamp > 0 ? date('Y-m-d', $timestamp) : 'unknown';
            if (!isset($groups[$key])) {
                $groups[$key] = array(
                    'key' => str_replace('-', '', $key),
                    'timestamp' => $timestamp,
                    'items' => array(),
                    'is_open' => count($groups) === 0
                );
            }
            $groups[$key]['items'][] = $row;
        }
        return array_values($groups);
    }

    private static function getRecentLog(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $teamId, $limit) {
        $result = $db->querySelect(
            '*',
            $websoccer->getConfig('db_prefix') . '_fan_mood_log',
            'team_id = %d ORDER BY event_date DESC, id DESC',
            (int) $teamId,
            (int) $limit
        );

        $log = array();
        while ($row = $result->fetch_array()) {
            $row['reason_label'] = self::getTranslatedMessage($i18n, $row['reason_key']);
            $row['mood_change_signed'] = self::formatSignedNumber((int) $row['mood_change']);
            $row['pressure_change_signed'] = self::formatSignedNumber((int) $row['pressure_change']);
            $row['board_change_signed'] = self::formatSignedNumber((int) $row['board_change']);
            $row['context'] = array();
            if (strlen((string) $row['context_data'])) {
                $decoded = json_decode($row['context_data'], TRUE);
                if (is_array($decoded)) {
                    $row['context'] = $decoded;
                }
            }
            $log[] = $row;
        }
        $result->free();

        return $log;
    }

    private static function maybeNotify(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $userId, $teamId, $reasonKey, $moodChange, $newMood) {
        $threshold = (int) self::getOptionalConfig($websoccer, 'fanpressure_notification_threshold', 6);
        if ($threshold < 1) {
            $threshold = 6;
        }

        if (abs((int) $moodChange) < $threshold) {
            return;
        }

        $reason = self::getTranslatedMessage($i18n, $reasonKey);
        NotificationsDataService::createNotification(
            $websoccer,
            $db,
            (int) $userId,
            'fanpressure_notification_big_change',
            array(
                'reason' => $reason,
                'change' => self::formatSignedNumber((int) $moodChange),
                'mood' => (int) $newMood
            ),
            'fanpressure',
            'fanpressure',
            null,
            (int) $teamId
        );
    }

    private static function maybeCreateExtremeNews(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $teamName, $newMood) {
        if (!self::getOptionalBooleanConfig($websoccer, 'fanpressure_create_extreme_news', TRUE)) {
            return;
        }

        $newMood = self::normalizePercent($newMood);
        if ($newMood > 20 && $newMood < 90) {
            return;
        }

        $todayStart = strtotime(date('Y-m-d 00:00:00', $websoccer->getNowAsTimestamp()));
        $titleKey = ($newMood <= 20) ? 'fanpressure_news_low_title' : 'fanpressure_news_high_title';
        $messageKey = ($newMood <= 20) ? 'fanpressure_news_low_message' : 'fanpressure_news_high_message';
        $title = self::replaceMessagePlaceholders($i18n->getMessage($titleKey), array('team' => $teamName, 'mood' => $newMood));

        $existing = $db->querySelect(
            'id',
            $websoccer->getConfig('db_prefix') . '_news',
            'datum >= %d AND titel = \'%s\'',
            array((int) $todayStart, $title),
            1
        );
        $record = $existing->fetch_array();
        $existing->free();
        if ($record) {
            return;
        }

        $message = self::replaceMessagePlaceholders($i18n->getMessage($messageKey), array('team' => $teamName, 'mood' => $newMood));
        $db->queryInsert(
            array(
                'datum' => $websoccer->getNowAsTimestamp(),
                'autor_id' => 1,
                'titel' => $title,
                'nachricht' => $message,
                'c_br' => '1',
                'c_links' => '0',
                'c_smilies' => '0',
                'status' => '1'
            ),
            $websoccer->getConfig('db_prefix') . '_news'
        );
    }

    private static function countYoungPlayersUsed(WebSoccer $websoccer, DbConnection $db, $matchId, $teamId) {
        $prefix = $websoccer->getConfig('db_prefix');
        $sql = 'SELECT COUNT(DISTINCT SB.spieler_id) AS hits '
             . 'FROM ' . $prefix . '_spiel_berechnung AS SB '
             . 'LEFT JOIN ' . $prefix . '_manager_mission_youth_promotion AS YP ON YP.professional_player_id = SB.spieler_id '
             . 'WHERE SB.spiel_id = ' . (int) $matchId . ' '
             . 'AND SB.team_id = ' . (int) $teamId . ' '
             . 'AND SB.minuten_gespielt > 0 '
             . 'AND (SB.age <= 21 OR YP.professional_player_id IS NOT NULL)';
        $result = $db->executeQuery($sql);
        $row = $result->fetch_array();
        $result->free();
        return ($row && isset($row['hits'])) ? (int) $row['hits'] : 0;
    }

    private static function getDerbyInfoSafe(WebSoccer $websoccer, DbConnection $db, SimulationMatch $match) {
        if (!class_exists('RivalriesDataService')) {
            return null;
        }

        try {
            return RivalriesDataService::getDerbyInfoForMatch($websoccer, $db, $match);
        } catch (Exception $e) {
            return null;
        }
    }

    private static function getAttendanceEffectPercent(WebSoccer $websoccer, $fanMood) {
        $fanMood = self::normalizePercent($fanMood);
        $maxEffect = (int) self::getOptionalConfig($websoccer, 'fanpressure_attendance_max_effect', 8);
        if ($maxEffect < 0) {
            $maxEffect = 0;
        }

        if ($fanMood > 60) {
            return (int) round((($fanMood - 60) / 40) * $maxEffect);
        }
        if ($fanMood < 40) {
            return 0 - (int) round(((40 - $fanMood) / 40) * $maxEffect);
        }
        return 0;
    }

    private static function getManagedTeam(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $result = $db->querySelect(
            'id, name, user_id, fan_mood, media_pressure, board_satisfaction, team_chemistry',
            $websoccer->getConfig('db_prefix') . '_verein',
            "id = %d AND status = '1'",
            (int) $teamId,
            1
        );
        $team = $result->fetch_array();
        $result->free();
        return $team ? $team : null;
    }

    private static function getManagedTeamWithLeaguePrices(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $columns = array(
            'T.id' => 'id',
            'T.name' => 'name',
            'T.user_id' => 'user_id',
            'T.fan_mood' => 'fan_mood',
            'T.media_pressure' => 'media_pressure',
            'T.board_satisfaction' => 'board_satisfaction',
            'L.preis_steh' => 'avg_price_stands',
            'L.preis_sitz' => 'avg_price_seats',
            'L.preis_vip' => 'avg_price_vip'
        );
        $fromTable = $websoccer->getConfig('db_prefix') . '_verein AS T';
        $fromTable .= ' INNER JOIN ' . $websoccer->getConfig('db_prefix') . '_liga AS L ON L.id = T.liga_id';
        $result = $db->querySelect($columns, $fromTable, "T.id = %d AND T.user_id > 0 AND T.status = '1'", (int) $teamId, 1);
        $team = $result->fetch_array();
        $result->free();
        return $team ? $team : null;
    }

    private static function ensureInitialState(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $result = $db->querySelect(
            'fan_mood, media_pressure, board_satisfaction, team_chemistry',
            $websoccer->getConfig('db_prefix') . '_verein',
            'id = %d',
            (int) $teamId,
            1
        );
        $team = $result->fetch_array();
        $result->free();

        if (!$team) {
            return;
        }

        $columns = array();
        if ($team['fan_mood'] === null || $team['fan_mood'] === '') {
            $columns['fan_mood'] = 50;
        }
        if ($team['media_pressure'] === null || $team['media_pressure'] === '') {
            $columns['media_pressure'] = 30;
        }
        if ($team['board_satisfaction'] === null || $team['board_satisfaction'] === '') {
            $columns['board_satisfaction'] = 50;
        }
        if (array_key_exists('team_chemistry', $team) && ($team['team_chemistry'] === null || $team['team_chemistry'] === '')) {
            $columns['team_chemistry'] = 50;
        }

        if (count($columns)) {
            $db->queryUpdate($columns, $websoccer->getConfig('db_prefix') . '_verein', 'id = %d', (int) $teamId);
        }
    }

    private static function getStoryRule(WebSoccer $websoccer, DbConnection $db, $eventKey, $source, $moodChange, $pressureChange, $boardChange, $chemistryChange) {
        $result = $db->querySelect('*', $websoccer->getConfig('db_prefix') . '_fanpressure_story_rule', 'event_key = \'%s\'', $eventKey, 1);
        $rule = $result->fetch_array();
        $result->free();
        if ($rule) {
            return $rule;
        }

        return array(
            'event_key' => $eventKey,
            'label' => $eventKey,
            'source' => $source,
            'active' => '1',
            'mood_change' => (int) $moodChange,
            'pressure_change' => (int) $pressureChange,
            'board_change' => (int) $boardChange,
            'chemistry_change' => (int) $chemistryChange,
            'create_notification' => ((abs((int) $moodChange) >= 3 || abs((int) $pressureChange) >= 3 || abs((int) $boardChange) >= 2) ? '1' : '0'),
            'create_news' => '0',
            'interview_chance' => 0
        );
    }

    private static function insertTeamChemistryLog(WebSoccer $websoccer, DbConnection $db, $teamId, $source, $oldChemistry, $newChemistry, $matchId, $context) {
        $prefix = $websoccer->getConfig('db_prefix');
        try {
            $db->queryInsert(
                array(
                    'team_id' => (int) $teamId,
                    'event_date' => $websoccer->getNowAsTimestamp(),
                    'source' => $source,
                    'old_score' => self::normalizePercent($oldChemistry),
                    'new_score' => self::normalizePercent($newChemistry),
                    'match_effect' => self::normalizePercent($newChemistry) - self::normalizePercent($oldChemistry),
                    'match_id' => ((int) $matchId > 0) ? (int) $matchId : '',
                    'breakdown_data' => json_encode($context)
                ),
                $prefix . '_team_chemistry_log'
            );
        } catch (Exception $e) {
            // Team chemistry log is optional for older installations. Main fan/media values were already saved.
        }
    }

    private static function insertStoryLog(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $team, $eventKey, $source, $matchId, $moodChange, $pressureChange, $boardChange, $chemistryChange, $newMood, $newPressure, $newBoard, $newChemistry, $context) {
        $referenceKey = self::buildStoryReferenceKey($websoccer, (int) $team['id'], $eventKey, $matchId, $context);
        $title = self::buildStoryTitle($i18n, $team, $eventKey, $context);
        $message = self::buildStoryMessage($i18n, $team, $eventKey, $context, $moodChange, $pressureChange, $boardChange, $chemistryChange);

        $db->queryInsert(
            array(
                'team_id' => (int) $team['id'],
                'user_id' => (int) $team['user_id'],
                'event_key' => $eventKey,
                'reference_key' => $referenceKey,
                'event_date' => $websoccer->getNowAsTimestamp(),
                'source' => $source,
                'title' => $title,
                'message' => $message,
                'mood_change' => (int) $moodChange,
                'pressure_change' => (int) $pressureChange,
                'board_change' => (int) $boardChange,
                'chemistry_change' => (int) $chemistryChange,
                'new_mood' => self::normalizePercent($newMood),
                'new_pressure' => self::normalizePercent($newPressure),
                'new_board_satisfaction' => self::normalizePercent($newBoard),
                'new_chemistry' => self::normalizePercent($newChemistry),
                'match_id' => ((int) $matchId > 0) ? (int) $matchId : 0,
                'notification_created' => '0',
                'context_data' => (is_array($context) && count($context)) ? json_encode($context) : ''
            ),
            $websoccer->getConfig('db_prefix') . '_fanpressure_story_log'
        );

        return $db->getLastInsertedId();
    }

    private static function buildStoryReferenceKey(WebSoccer $websoccer, $teamId, $eventKey, $matchId, $context) {
        $base = $eventKey . '_' . (int) $teamId . '_' . ((int) $matchId > 0 ? (int) $matchId : $websoccer->getNowAsTimestamp());
        $hash = substr(md5(json_encode($context) . '_' . microtime(TRUE)), 0, 12);
        return substr($base . '_' . $hash, 0, 150);
    }

    private static function buildStoryTitle(I18n $i18n, $team, $eventKey, $context) {
        $suffix = str_replace('fanpressure_reason_', '', $eventKey);
        $key = 'fanpressure_story_title_' . $suffix;
        $teamName = isset($team['name']) ? $team['name'] : '';
        if ($i18n->hasMessage($key)) {
            return self::replaceMessagePlaceholders($i18n->getMessage($key), array_merge(array('team' => $teamName), $context));
        }
        $reason = self::getTranslatedMessage($i18n, $eventKey);
        return strlen($teamName) ? ($reason . ': ' . $teamName) : $reason;
    }

    private static function buildStoryMessage(I18n $i18n, $team, $eventKey, $context, $moodChange, $pressureChange, $boardChange, $chemistryChange) {
        $suffix = str_replace('fanpressure_reason_', '', $eventKey);
        $key = 'fanpressure_story_message_' . $suffix;
        $teamName = isset($team['name']) ? $team['name'] : '';
        if ($i18n->hasMessage($key)) {
            return self::replaceMessagePlaceholders($i18n->getMessage($key), array_merge(array('team' => $teamName), $context));
        }

        $reason = self::getTranslatedMessage($i18n, $eventKey);
        $detail = array();
        if (isset($context['score'])) {
            $detail[] = 'Ergebnis: ' . $context['score'];
        }
        if (isset($context['player'])) {
            $detail[] = 'Spieler: ' . $context['player'];
        }
        if (isset($context['trait'])) {
            $detail[] = 'Spezialfähigkeit: ' . $context['trait'];
        }
        if (isset($context['reason'])) {
            $detail[] = $context['reason'];
        }
        if (isset($context['mission'])) {
            $detail[] = 'Ziel: ' . $context['mission'];
        }
        if (isset($context['question'])) {
            $detail[] = 'Frage: ' . $context['question'];
        }

        $changes = 'Fans ' . self::formatSignedNumber($moodChange) . ', Medien ' . self::formatSignedNumber($pressureChange) . ', Vorstand ' . self::formatSignedNumber($boardChange);
        if ((int) $chemistryChange != 0) {
            $changes .= ', Team ' . self::formatSignedNumber($chemistryChange);
        }
        $teamPart = strlen($teamName) ? (' bei ' . $teamName) : '';
        return $reason . $teamPart . '. ' . (count($detail) ? implode(' · ', $detail) . '. ' : '') . $changes . '.';
    }

    private static function createStoryNotification(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $userId, $teamId, $reasonKey, $moodChange, $pressureChange, $boardChange, $chemistryChange, $newMood, $newPressure, $newBoard) {
        if ((int) $userId < 1) {
            return;
        }
        $reason = self::getTranslatedMessage($i18n, $reasonKey);
        NotificationsDataService::createNotification(
            $websoccer,
            $db,
            (int) $userId,
            'fanpressure_story_notification',
            array(
                'reason' => $reason,
                'moodchange' => self::formatSignedNumber($moodChange),
                'pressurechange' => self::formatSignedNumber($pressureChange),
                'boardchange' => self::formatSignedNumber($boardChange),
                'chemistrychange' => self::formatSignedNumber($chemistryChange),
                'mood' => (int) $newMood,
                'pressure' => (int) $newPressure,
                'board' => (int) $newBoard
            ),
            'fanpressure',
            'fanpressure',
            null,
            (int) $teamId
        );
    }

    private static function createStoryNews(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $team, $eventKey, $context, $storyId, $moodChange, $pressureChange, $boardChange, $chemistryChange) {
        $title = self::buildStoryTitle($i18n, $team, $eventKey, $context);
        $message = self::buildStoryMessage($i18n, $team, $eventKey, $context, $moodChange, $pressureChange, $boardChange, $chemistryChange);

        $existing = $db->querySelect('id', $websoccer->getConfig('db_prefix') . '_news', 'datum >= %d AND titel = \'%s\'', array($websoccer->getNowAsTimestamp() - 86400, $title), 1);
        $row = $existing->fetch_array();
        $existing->free();
        if ($row) {
            return;
        }

        $db->queryInsert(
            array(
                'datum' => $websoccer->getNowAsTimestamp(),
                'autor_id' => 1,
                'titel' => $title,
                'nachricht' => $message,
                'c_br' => '1',
                'c_links' => '0',
                'c_smilies' => '0',
                'status' => '1'
            ),
            $websoccer->getConfig('db_prefix') . '_news'
        );
        $newsId = $db->getLastInsertedId();
        if ((int) $storyId > 0) {
            $db->queryUpdate(array('news_id' => (int) $newsId), $websoccer->getConfig('db_prefix') . '_fanpressure_story_log', 'id = %d', (int) $storyId);
        }
    }

    private static function maybeCreateInterview(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $team, $rule, $eventKey, $matchId, $context) {
        $userId = (int) $team['user_id'];
        if ($userId < 1 || (int) $rule['interview_chance'] <= 0) {
            return;
        }
        if (mt_rand(1, 100) > (int) $rule['interview_chance']) {
            return;
        }

        $prefix = $websoccer->getConfig('db_prefix');
        $open = $db->querySelect('COUNT(*) AS hits', $prefix . '_fanpressure_interview_occurrence', "user_id = %d AND team_id = %d AND status = 'open'", array($userId, (int) $team['id']), 1);
        $openRow = $open->fetch_array();
        $open->free();
        if ($openRow && (int) $openRow['hits'] >= 3) {
            return;
        }

        $existingRef = 'interview_' . $eventKey . '_' . (int) $team['id'] . '_' . ((int) $matchId > 0 ? (int) $matchId : date('Ymd', $websoccer->getNowAsTimestamp()));
        $existing = $db->querySelect('id', $prefix . '_fanpressure_interview_occurrence', 'reference_key = \'%s\'', $existingRef, 1);
        $existingRow = $existing->fetch_array();
        $existing->free();
        if ($existingRow) {
            return;
        }

        $question = self::selectInterviewQuestion($websoccer, $db, $eventKey);
        if (!$question) {
            return;
        }

        $expiresDays = (int) self::getOptionalConfig($websoccer, 'fanpressure_interview_expiry_days', 7);
        if ($expiresDays < 1) {
            $expiresDays = 7;
        }
        $db->queryInsert(
            array(
                'question_id' => (int) $question['id'],
                'user_id' => $userId,
                'team_id' => (int) $team['id'],
                'match_id' => ((int) $matchId > 0) ? (int) $matchId : 0,
                'event_key' => $eventKey,
                'reference_key' => $existingRef,
                'created_date' => $websoccer->getNowAsTimestamp(),
                'expires_date' => $websoccer->getNowAsTimestamp() + ($expiresDays * 86400),
                'status' => 'open',
                'context_data' => (is_array($context) && count($context)) ? json_encode($context) : ''
            ),
            $prefix . '_fanpressure_interview_occurrence'
        );

        NotificationsDataService::createNotification(
            $websoccer,
            $db,
            $userId,
            'fanpressure_interview_notification',
            array('reason' => self::getTranslatedMessage($i18n, $eventKey)),
            'fanpressure',
            'fanpressure',
            null,
            (int) $team['id']
        );
    }

    private static function selectInterviewQuestion(WebSoccer $websoccer, DbConnection $db, $eventKey) {
        $prefix = $websoccer->getConfig('db_prefix');
        $result = $db->querySelect('*', $prefix . '_fanpressure_interview_question', "event_key = '%s' AND active = '1' ORDER BY weight DESC, id ASC", $eventKey, 20);
        $questions = array();
        while ($row = $result->fetch_array()) {
            $weight = max(1, (int) $row['weight']);
            for ($i = 0; $i < $weight; $i++) {
                $questions[] = $row;
            }
        }
        $result->free();
        if (!count($questions)) {
            return null;
        }
        return $questions[array_rand($questions)];
    }

    private static function maybeCreateBoardThresholdStory(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $team, $newBoard, $matchId) {
        self::ensureFanPressureMessagesLoaded($websoccer, $i18n);
        $newBoard = self::normalizePercent($newBoard);
        $eventKey = '';
        if ($newBoard <= 20) {
            $eventKey = 'fanpressure_reason_board_crisis';
        } else if ($newBoard >= 85) {
            $eventKey = 'fanpressure_reason_board_support';
        }
        if (!$eventKey) {
            return;
        }

        $referenceKey = $eventKey . '_' . (int) $team['id'] . '_' . date('Ymd', $websoccer->getNowAsTimestamp());
        $existing = $db->querySelect('id', $websoccer->getConfig('db_prefix') . '_fanpressure_story_log', 'reference_key = \'%s\'', $referenceKey, 1);
        $row = $existing->fetch_array();
        $existing->free();
        if ($row) {
            return;
        }

        $title = self::buildStoryTitle($i18n, $team, $eventKey, array('board' => $newBoard));
        $message = self::buildStoryMessage($i18n, $team, $eventKey, array('board' => $newBoard), 0, ($newBoard <= 20 ? 2 : -1), 0, 0);
        $db->queryInsert(
            array(
                'team_id' => (int) $team['id'],
                'user_id' => (int) $team['user_id'],
                'event_key' => $eventKey,
                'reference_key' => $referenceKey,
                'event_date' => $websoccer->getNowAsTimestamp(),
                'source' => self::SOURCE_BOARD,
                'title' => $title,
                'message' => $message,
                'mood_change' => 0,
                'pressure_change' => ($newBoard <= 20 ? 2 : -1),
                'board_change' => 0,
                'chemistry_change' => 0,
                'new_mood' => self::normalizePercent($team['fan_mood']),
                'new_pressure' => self::normalizePercent($team['media_pressure']),
                'new_board_satisfaction' => $newBoard,
                'new_chemistry' => isset($team['team_chemistry']) ? self::normalizePercent($team['team_chemistry']) : 50,
                'match_id' => ((int) $matchId > 0) ? (int) $matchId : 0,
                'notification_created' => '1',
                'context_data' => json_encode(array('board' => $newBoard))
            ),
            $websoccer->getConfig('db_prefix') . '_fanpressure_story_log'
        );

        if ((int) $team['user_id'] > 0) {
            NotificationsDataService::createNotification(
                $websoccer,
                $db,
                (int) $team['user_id'],
                ($newBoard <= 20 ? 'fanpressure_board_crisis_notification' : 'fanpressure_board_support_notification'),
                array('board' => $newBoard),
                'fanpressure',
                'fanpressure',
                null,
                (int) $team['id']
            );
        }
    }


    private static function processTraitPerformanceStories(MatchCompletedEvent $event, $teamId) {
        if (!class_exists('PlayerTraitsDataService')) {
            return;
        }

        $team = self::getManagedTeam($event->websoccer, $event->db, $teamId);
        if (!$team) {
            return;
        }

        $highlights = PlayerTraitsDataService::getMatchTraitHighlights(
            $event->websoccer,
            $event->db,
            $event->i18n,
            (int) $event->match->id,
            (int) $teamId,
            1
        );
        if (!count($highlights)) {
            return;
        }

        $highlight = $highlights[0];
        $eventKey = ((int) $highlight['score'] >= 45) ? 'fanpressure_reason_trait_big_moment' : 'fanpressure_reason_trait_star_performance';
        if (self::traitStoryAlreadyLogged($event->websoccer, $event->db, $teamId, (int) $event->match->id, (int) $highlight['player_id'], $eventKey)) {
            return;
        }

        self::changeMoodAndPressure(
            $event->websoccer,
            $event->db,
            $event->i18n,
            (int) $teamId,
            ((int) $highlight['score'] >= 45) ? 3 : 2,
            ((int) $highlight['score'] >= 45) ? -2 : -1,
            $eventKey,
            self::SOURCE_MATCH,
            (int) $event->match->id,
            array(
                'player_id' => (int) $highlight['player_id'],
                'player' => $highlight['player_name'],
                'trait_key' => $highlight['trait_key'],
                'trait' => $highlight['trait_label'],
                'trait_value' => (int) $highlight['trait_value'],
                'reason' => $highlight['reason'],
                'score' => (int) $highlight['score']
            )
        );
    }

    private static function traitStoryAlreadyLogged(WebSoccer $websoccer, DbConnection $db, $teamId, $matchId, $playerId, $eventKey) {
        $prefix = $websoccer->getConfig('db_prefix');
        $needle = '"player_id":' . (int) $playerId;
        $result = $db->querySelect(
            'id',
            $prefix . '_fanpressure_story_log',
            'team_id = %d AND match_id = %d AND event_key = \'%s\' AND context_data LIKE \'%%%s%%\'',
            array((int) $teamId, (int) $matchId, $eventKey, $needle),
            1
        );
        $row = $result->fetch_array();
        $result->free();
        return $row ? TRUE : FALSE;
    }

    private static function processCupStory(MatchCompletedEvent $event, $teamId, $isHome, $goalDiff) {
        if ($event->match->type != 'Pokalspiel' || $goalDiff == 0) {
            return;
        }
        $ownStrength = self::getTeamStrength($event->websoccer, $event->db, $teamId);
        $opponentId = $isHome ? (int) $event->match->guestTeam->id : (int) $event->match->homeTeam->id;
        $opponentStrength = self::getTeamStrength($event->websoccer, $event->db, $opponentId);
        if ($ownStrength < 1 || $opponentStrength < 1) {
            return;
        }
        if ($goalDiff > 0 && $ownStrength + 10 < $opponentStrength) {
            self::changeMoodAndPressure($event->websoccer, $event->db, $event->i18n, $teamId, 6, -4, 'fanpressure_reason_cup_upset_win', self::SOURCE_MATCH, (int) $event->match->id, array('opponent_strength' => $opponentStrength, 'own_strength' => $ownStrength));
        } else if ($goalDiff < 0 && $ownStrength > $opponentStrength + 10) {
            self::changeMoodAndPressure($event->websoccer, $event->db, $event->i18n, $teamId, -6, 8, 'fanpressure_reason_cup_elimination_weaker', self::SOURCE_MATCH, (int) $event->match->id, array('opponent_strength' => $opponentStrength, 'own_strength' => $ownStrength));
        }
    }

    private static function processResultStreak(MatchCompletedEvent $event, $teamId, $goalDiff) {
        if ($goalDiff == 0) {
            return;
        }
        $streak = self::getRecentResultStreak($event->websoccer, $event->db, $teamId);
        if ($goalDiff > 0 && $streak == 3) {
            self::changeMoodAndPressure($event->websoccer, $event->db, $event->i18n, $teamId, 4, -4, 'fanpressure_reason_win_streak_3', self::SOURCE_MATCH, (int) $event->match->id, array('streak' => 3));
        } else if ($goalDiff < 0 && $streak == -3) {
            self::changeMoodAndPressure($event->websoccer, $event->db, $event->i18n, $teamId, -4, 6, 'fanpressure_reason_loss_streak_3', self::SOURCE_MATCH, (int) $event->match->id, array('streak' => 3));
        } else if ($goalDiff < 0 && $streak == -5) {
            self::changeMoodAndPressure($event->websoccer, $event->db, $event->i18n, $teamId, -7, 10, 'fanpressure_reason_loss_streak_5', self::SOURCE_MATCH, (int) $event->match->id, array('streak' => 5));
        }
    }

    private static function getRecentResultStreak(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $prefix = $websoccer->getConfig('db_prefix');
        $sql = 'SELECT home_verein, gast_verein, home_tore, gast_tore FROM ' . $prefix . "_spiel "
             . "WHERE berechnet = '1' AND (home_verein = " . (int) $teamId . " OR gast_verein = " . (int) $teamId . ") "
             . "AND spieltyp IN ('Ligaspiel','Pokalspiel') ORDER BY datum DESC, id DESC LIMIT 5";
        $result = $db->executeQuery($sql);
        $direction = 0;
        $count = 0;
        while ($row = $result->fetch_array()) {
            $own = ((int) $row['home_verein'] == (int) $teamId) ? (int) $row['home_tore'] : (int) $row['gast_tore'];
            $opp = ((int) $row['home_verein'] == (int) $teamId) ? (int) $row['gast_tore'] : (int) $row['home_tore'];
            $current = ($own > $opp) ? 1 : (($own < $opp) ? -1 : 0);
            if ($current == 0) {
                break;
            }
            if ($direction == 0) {
                $direction = $current;
            }
            if ($current != $direction) {
                break;
            }
            $count++;
        }
        $result->free();
        return $direction * $count;
    }

    private static function getTeamStrength(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $result = $db->querySelect('strength', $websoccer->getConfig('db_prefix') . '_verein', 'id = %d', (int) $teamId, 1);
        $row = $result->fetch_array();
        $result->free();
        return ($row && isset($row['strength'])) ? (int) $row['strength'] : 0;
    }

    private static function getRecentStoryLog(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $teamId, $limit) {
        self::ensureSchema($websoccer, $db);
        $prefix = $websoccer->getConfig('db_prefix');
        $from = $prefix . '_fanpressure_story_log AS L LEFT JOIN ' . $prefix . '_verein AS T ON T.id = L.team_id';
        $columns = 'L.*, T.name AS team_name';
        $result = $db->querySelect($columns, $from, 'L.team_id = %d ORDER BY L.event_date DESC, L.id DESC', (int) $teamId, (int) $limit);
        $rows = array();
        while ($row = $result->fetch_array()) {
            $row['mood_change_signed'] = self::formatSignedNumber((int) $row['mood_change']);
            $row['pressure_change_signed'] = self::formatSignedNumber((int) $row['pressure_change']);
            $row['board_change_signed'] = self::formatSignedNumber((int) $row['board_change']);
            $row['chemistry_change_signed'] = self::formatSignedNumber((int) $row['chemistry_change']);
            $row['context'] = self::decodeContextData(isset($row['context_data']) ? $row['context_data'] : '');
            $row = self::normalizeStoryDisplayRow($websoccer, $i18n, $row);
            $rows[] = $row;
        }
        $result->free();
        return $rows;
    }

    private static function getOpenInterviews(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $userId, $teamId) {
        self::ensureSchema($websoccer, $db);
        $prefix = $websoccer->getConfig('db_prefix');
        $db->executeQuery('UPDATE ' . $prefix . "_fanpressure_interview_occurrence SET status = 'expired' WHERE status = 'open' AND expires_date > 0 AND expires_date < " . (int) $websoccer->getNowAsTimestamp());

        if ((int) $userId < 1) {
            return array();
        }

        $columns = array(
            'O.id' => 'id',
            'O.event_key' => 'event_key',
            'O.match_id' => 'match_id',
            'O.created_date' => 'created_date',
            'O.expires_date' => 'expires_date',
            'O.context_data' => 'context_data',
            'Q.question' => 'question',
            'Q.answer_a_label' => 'answer_a_label',
            'Q.answer_a_mood' => 'answer_a_mood',
            'Q.answer_a_pressure' => 'answer_a_pressure',
            'Q.answer_a_board' => 'answer_a_board',
            'Q.answer_a_chemistry' => 'answer_a_chemistry',
            'Q.answer_b_label' => 'answer_b_label',
            'Q.answer_b_mood' => 'answer_b_mood',
            'Q.answer_b_pressure' => 'answer_b_pressure',
            'Q.answer_b_board' => 'answer_b_board',
            'Q.answer_b_chemistry' => 'answer_b_chemistry',
            'Q.answer_c_label' => 'answer_c_label',
            'Q.answer_c_mood' => 'answer_c_mood',
            'Q.answer_c_pressure' => 'answer_c_pressure',
            'Q.answer_c_board' => 'answer_c_board',
            'Q.answer_c_chemistry' => 'answer_c_chemistry'
        );
        $from = $prefix . '_fanpressure_interview_occurrence AS O INNER JOIN ' . $prefix . '_fanpressure_interview_question AS Q ON Q.id = O.question_id';
        $result = $db->querySelect($columns, $from, "O.user_id = %d AND O.team_id = %d AND O.status = 'open' ORDER BY O.created_date DESC", array((int) $userId, (int) $teamId), 5);
        $items = array();
        while ($row = $result->fetch_array()) {
            $row['reason_label'] = self::getTranslatedMessage($i18n, $row['event_key']);
            $row['context'] = array();
            if (strlen((string) $row['context_data'])) {
                $decoded = json_decode($row['context_data'], TRUE);
                if (is_array($decoded)) {
                    $row['context'] = $decoded;
                }
            }
            $row['answers'] = array(
                array('key' => 'a', 'label' => $row['answer_a_label'], 'mood' => (int) $row['answer_a_mood'], 'pressure' => (int) $row['answer_a_pressure'], 'board' => (int) $row['answer_a_board'], 'chemistry' => (int) $row['answer_a_chemistry']),
                array('key' => 'b', 'label' => $row['answer_b_label'], 'mood' => (int) $row['answer_b_mood'], 'pressure' => (int) $row['answer_b_pressure'], 'board' => (int) $row['answer_b_board'], 'chemistry' => (int) $row['answer_b_chemistry']),
                array('key' => 'c', 'label' => $row['answer_c_label'], 'mood' => (int) $row['answer_c_mood'], 'pressure' => (int) $row['answer_c_pressure'], 'board' => (int) $row['answer_c_board'], 'chemistry' => (int) $row['answer_c_chemistry'])
            );
            $items[] = $row;
        }
        $result->free();
        return $items;
    }

    public static function answerInterview(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $userId, $teamId, $occurrenceId, $answerKey) {
        self::ensureSchema($websoccer, $db);
        $answerKey = strtolower(trim((string) $answerKey));
        if (!in_array($answerKey, array('a', 'b', 'c'))) {
            throw new Exception($i18n->getMessage('fanpressure_interview_invalid_answer'));
        }

        $prefix = $websoccer->getConfig('db_prefix');
        $columns = array(
            'O.id' => 'id',
            'O.question_id' => 'question_id',
            'O.user_id' => 'user_id',
            'O.team_id' => 'team_id',
            'O.match_id' => 'match_id',
            'O.event_key' => 'event_key',
            'O.expires_date' => 'expires_date',
            'O.context_data' => 'context_data',
            'Q.question' => 'question',
            'Q.answer_a_label' => 'answer_a_label', 'Q.answer_a_mood' => 'answer_a_mood', 'Q.answer_a_pressure' => 'answer_a_pressure', 'Q.answer_a_board' => 'answer_a_board', 'Q.answer_a_chemistry' => 'answer_a_chemistry',
            'Q.answer_b_label' => 'answer_b_label', 'Q.answer_b_mood' => 'answer_b_mood', 'Q.answer_b_pressure' => 'answer_b_pressure', 'Q.answer_b_board' => 'answer_b_board', 'Q.answer_b_chemistry' => 'answer_b_chemistry',
            'Q.answer_c_label' => 'answer_c_label', 'Q.answer_c_mood' => 'answer_c_mood', 'Q.answer_c_pressure' => 'answer_c_pressure', 'Q.answer_c_board' => 'answer_c_board', 'Q.answer_c_chemistry' => 'answer_c_chemistry'
        );
        $from = $prefix . '_fanpressure_interview_occurrence AS O INNER JOIN ' . $prefix . '_fanpressure_interview_question AS Q ON Q.id = O.question_id';
        $result = $db->querySelect($columns, $from, "O.id = %d AND O.user_id = %d AND O.team_id = %d AND O.status = 'open'", array((int) $occurrenceId, (int) $userId, (int) $teamId), 1);
        $row = $result->fetch_array();
        $result->free();
        if (!$row) {
            throw new Exception($i18n->getMessage('fanpressure_interview_not_found'));
        }
        if ((int) $row['expires_date'] > 0 && (int) $row['expires_date'] < (int) $websoccer->getNowAsTimestamp()) {
            $db->queryUpdate(array('status' => 'expired'), $prefix . '_fanpressure_interview_occurrence', 'id = %d', (int) $occurrenceId);
            throw new Exception($i18n->getMessage('fanpressure_interview_expired'));
        }

        $label = $row['answer_' . $answerKey . '_label'];
        $mood = (int) $row['answer_' . $answerKey . '_mood'];
        $pressure = (int) $row['answer_' . $answerKey . '_pressure'];
        $board = (int) $row['answer_' . $answerKey . '_board'];
        $chemistry = (int) $row['answer_' . $answerKey . '_chemistry'];
        $context = array('question' => $row['question'], 'answer' => $label, 'source_event' => $row['event_key']);
        if (strlen((string) $row['context_data'])) {
            $decoded = json_decode($row['context_data'], TRUE);
            if (is_array($decoded)) {
                $context = array_merge($decoded, $context);
            }
        }

        $db->queryUpdate(
            array(
                'status' => 'answered',
                'answer_key' => $answerKey,
                'answered_date' => $websoccer->getNowAsTimestamp()
            ),
            $prefix . '_fanpressure_interview_occurrence',
            'id = %d',
            (int) $occurrenceId
        );

        self::changeMoodAndPressure(
            $websoccer,
            $db,
            $i18n,
            (int) $teamId,
            $mood,
            $pressure,
            'fanpressure_reason_interview_answer',
            self::SOURCE_INTERVIEW,
            (int) $row['match_id'],
            $context,
            $board,
            $chemistry
        );

        return array('message' => $i18n->getMessage('fanpressure_interview_answer_saved'));
    }

    public static function getAdminData(WebSoccer $websoccer, DbConnection $db) {
        self::ensureSchema($websoccer, $db);
        return array(
            'rules' => self::getAdminRules($websoccer, $db),
            'questions' => self::getAdminQuestions($websoccer, $db),
            'logs' => self::getAdminStoryLogs($websoccer, $db, 80)
        );
    }

    public static function saveAdminRules(WebSoccer $websoccer, DbConnection $db, $postData) {
        self::ensureSchema($websoccer, $db);
        if (!isset($postData['rules']) || !is_array($postData['rules'])) {
            return 0;
        }
        $count = 0;
        foreach ($postData['rules'] as $eventKey => $values) {
            $db->queryUpdate(
                array(
                    'active' => isset($values['active']) ? '1' : '0',
                    'mood_change' => self::clampSigned(isset($values['mood_change']) ? $values['mood_change'] : 0, -30, 30),
                    'pressure_change' => self::clampSigned(isset($values['pressure_change']) ? $values['pressure_change'] : 0, -30, 30),
                    'board_change' => self::clampSigned(isset($values['board_change']) ? $values['board_change'] : 0, -30, 30),
                    'chemistry_change' => self::clampSigned(isset($values['chemistry_change']) ? $values['chemistry_change'] : 0, -30, 30),
                    'create_notification' => isset($values['create_notification']) ? '1' : '0',
                    'create_news' => isset($values['create_news']) ? '1' : '0',
                    'interview_chance' => self::clampSigned(isset($values['interview_chance']) ? $values['interview_chance'] : 0, 0, 100),
                    'updated_date' => $websoccer->getNowAsTimestamp()
                ),
                $websoccer->getConfig('db_prefix') . '_fanpressure_story_rule',
                'event_key = \'%s\'',
                $eventKey
            );
            $count++;
        }
        return $count;
    }

    public static function saveAdminQuestion(WebSoccer $websoccer, DbConnection $db, $postData) {
        self::ensureSchema($websoccer, $db);
        $id = isset($postData['question_id']) ? (int) $postData['question_id'] : 0;
        $columns = array(
            'event_key' => isset($postData['event_key']) ? trim($postData['event_key']) : 'fanpressure_reason_match_loss',
            'question' => isset($postData['question']) ? trim($postData['question']) : '',
            'answer_a_label' => isset($postData['answer_a_label']) ? trim($postData['answer_a_label']) : '',
            'answer_a_mood' => self::clampSigned(isset($postData['answer_a_mood']) ? $postData['answer_a_mood'] : 0, -30, 30),
            'answer_a_pressure' => self::clampSigned(isset($postData['answer_a_pressure']) ? $postData['answer_a_pressure'] : 0, -30, 30),
            'answer_a_board' => self::clampSigned(isset($postData['answer_a_board']) ? $postData['answer_a_board'] : 0, -30, 30),
            'answer_a_chemistry' => self::clampSigned(isset($postData['answer_a_chemistry']) ? $postData['answer_a_chemistry'] : 0, -30, 30),
            'answer_b_label' => isset($postData['answer_b_label']) ? trim($postData['answer_b_label']) : '',
            'answer_b_mood' => self::clampSigned(isset($postData['answer_b_mood']) ? $postData['answer_b_mood'] : 0, -30, 30),
            'answer_b_pressure' => self::clampSigned(isset($postData['answer_b_pressure']) ? $postData['answer_b_pressure'] : 0, -30, 30),
            'answer_b_board' => self::clampSigned(isset($postData['answer_b_board']) ? $postData['answer_b_board'] : 0, -30, 30),
            'answer_b_chemistry' => self::clampSigned(isset($postData['answer_b_chemistry']) ? $postData['answer_b_chemistry'] : 0, -30, 30),
            'answer_c_label' => isset($postData['answer_c_label']) ? trim($postData['answer_c_label']) : '',
            'answer_c_mood' => self::clampSigned(isset($postData['answer_c_mood']) ? $postData['answer_c_mood'] : 0, -30, 30),
            'answer_c_pressure' => self::clampSigned(isset($postData['answer_c_pressure']) ? $postData['answer_c_pressure'] : 0, -30, 30),
            'answer_c_board' => self::clampSigned(isset($postData['answer_c_board']) ? $postData['answer_c_board'] : 0, -30, 30),
            'answer_c_chemistry' => self::clampSigned(isset($postData['answer_c_chemistry']) ? $postData['answer_c_chemistry'] : 0, -30, 30),
            'active' => isset($postData['active']) ? '1' : '0',
            'weight' => self::clampSigned(isset($postData['weight']) ? $postData['weight'] : 1, 1, 20)
        );
        if (!strlen($columns['question']) || !strlen($columns['answer_a_label']) || !strlen($columns['answer_b_label']) || !strlen($columns['answer_c_label'])) {
            throw new Exception('Frage und alle drei Antworten müssen ausgefüllt sein.');
        }
        if ($id > 0) {
            $db->queryUpdate($columns, $websoccer->getConfig('db_prefix') . '_fanpressure_interview_question', 'id = %d', $id);
            return $id;
        }
        $db->queryInsert($columns, $websoccer->getConfig('db_prefix') . '_fanpressure_interview_question');
        return $db->getLastInsertedId();
    }

    public static function deleteAdminQuestion(WebSoccer $websoccer, DbConnection $db, $questionId) {
        self::ensureSchema($websoccer, $db);
        $db->queryDelete($websoccer->getConfig('db_prefix') . '_fanpressure_interview_question', 'id = %d', (int) $questionId);
    }

    private static function getAdminRules(WebSoccer $websoccer, DbConnection $db) {
        $result = $db->querySelect('*', $websoccer->getConfig('db_prefix') . '_fanpressure_story_rule', '1=1 ORDER BY source ASC, event_key ASC');
        $rows = array();
        while ($row = $result->fetch_array()) {
            $rows[] = $row;
        }
        $result->free();
        return $rows;
    }

    private static function getAdminQuestions(WebSoccer $websoccer, DbConnection $db) {
        $result = $db->querySelect('*', $websoccer->getConfig('db_prefix') . '_fanpressure_interview_question', '1=1 ORDER BY event_key ASC, id ASC');
        $rows = array();
        while ($row = $result->fetch_array()) {
            $rows[] = $row;
        }
        $result->free();
        return $rows;
    }

    private static function getAdminStoryLogs(WebSoccer $websoccer, DbConnection $db, $limit) {
        $columns = array(
            'L.id' => 'id',
            'L.event_date' => 'event_date',
            'L.event_key' => 'event_key',
            'L.title' => 'title',
            'L.mood_change' => 'mood_change',
            'L.pressure_change' => 'pressure_change',
            'L.board_change' => 'board_change',
            'L.chemistry_change' => 'chemistry_change',
            'L.match_id' => 'match_id',
            'T.name' => 'team_name',
            'U.nick' => 'user_name'
        );
        $from = $websoccer->getConfig('db_prefix') . '_fanpressure_story_log AS L LEFT JOIN ' . $websoccer->getConfig('db_prefix') . '_verein AS T ON T.id = L.team_id LEFT JOIN ' . $websoccer->getConfig('db_prefix') . '_user AS U ON U.id = L.user_id';
        $result = $db->querySelect($columns, $from, '1=1 ORDER BY L.event_date DESC, L.id DESC', null, (int) $limit);
        $rows = array();
        while ($row = $result->fetch_array()) {
            $rows[] = $row;
        }
        $result->free();
        return $rows;
    }

    private static function clampSigned($value, $min, $max) {
        return min((int) $max, max((int) $min, (int) $value));
    }

    private static function getTicketPriceRatio($team, $newPrices) {
        $avgStands = max(1, (float) $team['avg_price_stands']);
        $avgSeats = max(1, (float) $team['avg_price_seats']);
        $avgVip = max(1, (float) $team['avg_price_vip']);

        $actualStands = isset($newPrices['preis_stehen']) ? (float) $newPrices['preis_stehen'] : $avgStands;
        $actualSeats = isset($newPrices['preis_sitz']) ? (float) $newPrices['preis_sitz'] : $avgSeats;
        $actualGrandStands = isset($newPrices['preis_haupt_stehen']) ? (float) $newPrices['preis_haupt_stehen'] : ($avgStands * 1.2);
        $actualGrandSeats = isset($newPrices['preis_haupt_sitze']) ? (float) $newPrices['preis_haupt_sitze'] : ($avgSeats * 1.2);
        $actualVip = isset($newPrices['preis_vip']) ? (float) $newPrices['preis_vip'] : $avgVip;

        $ratio = 0;
        $ratio += $actualStands / $avgStands;
        $ratio += $actualSeats / $avgSeats;
        $ratio += $actualGrandStands / max(1, ($avgStands * 1.2));
        $ratio += $actualGrandSeats / max(1, ($avgSeats * 1.2));
        $ratio += $actualVip / $avgVip;

        return $ratio / 5;
    }

    private static function getPlayerInfo(WebSoccer $websoccer, DbConnection $db, $playerId) {
        $result = $db->querySelect(
            'id, vorname, nachname, kunstname, w_staerke, w_talent, marktwert',
            $websoccer->getConfig('db_prefix') . '_spieler',
            'id = %d',
            (int) $playerId,
            1
        );
        $player = $result->fetch_array();
        $result->free();
        return $player ? $player : null;
    }

    private static function getPlayerName($player) {
        if (isset($player['kunstname']) && strlen(trim((string) $player['kunstname']))) {
            return trim((string) $player['kunstname']);
        }
        return trim((string) $player['vorname'] . ' ' . (string) $player['nachname']);
    }

    private static function getPlayerEffectiveStrength($player) {
        $strength = (float) $player['w_staerke'];
        $talent = (int) $player['w_talent'];
        if ($talent < 1) {
            $talent = 1;
        }
        return $strength * ($talent / 5);
    }

    private static function getAveragePlayerStrength(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $result = $db->querySelect(
            'AVG(CAST(w_staerke AS DECIMAL(6,2)) * (w_talent / 5)) AS avg_strength',
            $websoccer->getConfig('db_prefix') . '_spieler',
            "verein_id = %d AND status = '1'",
            (int) $teamId,
            1
        );
        $row = $result->fetch_array();
        $result->free();
        return ($row && isset($row['avg_strength']) && (float) $row['avg_strength'] > 0) ? (float) $row['avg_strength'] : 0;
    }

    private static function getSaleMoodChange($playerStrength, $avgStrength) {
        if ($playerStrength >= 75 || ($avgStrength > 0 && $playerStrength >= $avgStrength + 12)) {
            return -5;
        }
        if ($avgStrength > 0 && $playerStrength >= $avgStrength + 6) {
            return -3;
        }
        return -1;
    }

    private static function getSigningMoodChange($playerStrength, $avgStrength) {
        if ($playerStrength >= 75 || ($avgStrength > 0 && $playerStrength >= $avgStrength + 12)) {
            return 4;
        }
        if ($avgStrength > 0 && $playerStrength >= $avgStrength + 6) {
            return 2;
        }
        return 1;
    }

    private static function getMoodLabelKey($fanMood) {
        $fanMood = self::normalizePercent($fanMood);
        if ($fanMood >= 65) {
            return 'fanpressure_value_good';
        }
        if ($fanMood <= 35) {
            return 'fanpressure_value_bad';
        }
        return 'fanpressure_value_neutral';
    }

    private static function getMoodHintKey($fanMood) {
        $fanMood = self::normalizePercent($fanMood);
        if ($fanMood >= 65) {
            return 'fanpressure_high_hint';
        }
        if ($fanMood <= 35) {
            return 'fanpressure_low_hint';
        }
        return 'fanpressure_neutral_hint';
    }

    private static function normalizePercent($value) {
        return min(100, max(0, (int) round((float) $value)));
    }

    private static function formatSignedNumber($number) {
        $number = (int) $number;
        return ($number > 0 ? '+' : '') . $number;
    }

    private static function getOptionalConfig(WebSoccer $websoccer, $key, $defaultValue) {
        try {
            $value = $websoccer->getConfig($key);
            if ($value === null || $value === '') {
                return $defaultValue;
            }
            return $value;
        } catch (Exception $e) {
            return $defaultValue;
        }
    }

    private static function getOptionalBooleanConfig(WebSoccer $websoccer, $key, $defaultValue) {
        $value = self::getOptionalConfig($websoccer, $key, $defaultValue ? '1' : '0');
        return ($value === TRUE || $value === 1 || $value === '1');
    }

    private static function replaceMessagePlaceholders($message, $values) {
        foreach ($values as $key => $value) {
            $message = str_replace('{' . $key . '}', $value, $message);
        }
        return $message;
    }
}

?>
