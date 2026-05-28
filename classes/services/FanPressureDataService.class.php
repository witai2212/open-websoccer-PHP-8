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

    public static function isEnabled(WebSoccer $websoccer) {
        return self::getOptionalBooleanConfig($websoccer, 'fanpressure_enabled', TRUE);
    }

    /**
     * Returns page data for the current manager team.
     */
    public static function getPageData(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $teamId) {
        self::ensureInitialState($websoccer, $db, $teamId);
        $team = self::getManagedTeam($websoccer, $db, $teamId);

        if (!$team) {
            return array(
                'team' => array(),
                'log' => array(),
                'attendance_effect' => 0,
                'sponsor_effect' => 0,
                'mood_label' => 'fanpressure_value_neutral',
                'hint_key' => 'fanpressure_neutral_hint'
            );
        }

        $fanMood = self::normalizePercent($team['fan_mood']);
        $mediaPressure = self::normalizePercent($team['media_pressure']);

        return array(
            'team' => $team,
            'fan_mood' => $fanMood,
            'media_pressure' => $mediaPressure,
            'board_satisfaction' => self::normalizePercent($team['board_satisfaction']),
            'attendance_effect' => self::getAttendanceEffectPercent($websoccer, $fanMood),
            'sponsor_effect' => self::getSponsorEffectPercent($websoccer, $fanMood),
            'mood_label' => self::getMoodLabelKey($fanMood),
            'hint_key' => self::getMoodHintKey($fanMood),
            'log' => self::getRecentLog($websoccer, $db, $i18n, $teamId, 25)
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

        self::applyBoardRipple($event->websoccer, $event->db, $event->i18n, $teamId);
    }

    private static function applyBoardRipple(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $teamId) {
        $team = self::getManagedTeam($websoccer, $db, $teamId);
        if (!$team) {
            return;
        }

        $fanMood = self::normalizePercent($team['fan_mood']);
        $boardChange = 0;
        $reasonKey = '';

        if ($fanMood < 30) {
            $boardChange = -1;
            $reasonKey = 'fanpressure_reason_board_mood_penalty';
        } else if ($fanMood > 75) {
            $boardChange = 1;
            $reasonKey = 'fanpressure_reason_board_mood_bonus';
        }

        if ($boardChange == 0) {
            return;
        }

        $oldBoard = self::normalizePercent($team['board_satisfaction']);
        $newBoard = self::normalizePercent($oldBoard + $boardChange);
        if ($oldBoard == $newBoard) {
            return;
        }

        $db->queryUpdate(
            array('board_satisfaction' => $newBoard),
            $websoccer->getConfig('db_prefix') . '_verein',
            'id = %d',
            (int) $teamId
        );

        self::insertLog(
            $websoccer,
            $db,
            (int) $teamId,
            (int) $team['user_id'],
            self::SOURCE_BOARD,
            $reasonKey,
            0,
            $fanMood,
            $fanMood,
            0,
            self::normalizePercent($team['media_pressure']),
            self::normalizePercent($team['media_pressure']),
            $boardChange,
            $oldBoard,
            $newBoard,
            0,
            array()
        );
    }

    private static function changeMoodAndPressure(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $teamId, $moodChange, $pressureChange, $reasonKey, $source, $matchId = 0, $context = array()) {
        $team = self::getManagedTeam($websoccer, $db, $teamId);
        if (!$team) {
            return;
        }

        $oldMood = self::normalizePercent($team['fan_mood']);
        $oldPressure = self::normalizePercent($team['media_pressure']);
        $oldBoard = self::normalizePercent($team['board_satisfaction']);

        $newMood = self::normalizePercent($oldMood + (int) $moodChange);
        $newPressure = self::normalizePercent($oldPressure + (int) $pressureChange);

        if ($oldMood == $newMood && $oldPressure == $newPressure) {
            return;
        }

        $db->queryUpdate(
            array(
                'fan_mood' => $newMood,
                'media_pressure' => $newPressure
            ),
            $websoccer->getConfig('db_prefix') . '_verein',
            'id = %d',
            (int) $teamId
        );

        self::insertLog(
            $websoccer,
            $db,
            (int) $teamId,
            (int) $team['user_id'],
            $source,
            $reasonKey,
            (int) $moodChange,
            $oldMood,
            $newMood,
            (int) $pressureChange,
            $oldPressure,
            $newPressure,
            0,
            $oldBoard,
            $oldBoard,
            (int) $matchId,
            $context
        );

        self::maybeNotify($websoccer, $db, $i18n, (int) $team['user_id'], (int) $teamId, $reasonKey, (int) $moodChange, $newMood);
        self::maybeCreateExtremeNews($websoccer, $db, $i18n, $team['name'], $newMood);
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
            $row['reason_label'] = $i18n->hasMessage($row['reason_key']) ? $i18n->getMessage($row['reason_key']) : $row['reason_key'];
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

        $reason = $i18n->hasMessage($reasonKey) ? $i18n->getMessage($reasonKey) : $reasonKey;
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
            'id, name, user_id, fan_mood, media_pressure, board_satisfaction',
            $websoccer->getConfig('db_prefix') . '_verein',
            "id = %d AND user_id > 0 AND status = '1'",
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
            'fan_mood, media_pressure',
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

        if (count($columns)) {
            $db->queryUpdate($columns, $websoccer->getConfig('db_prefix') . '_verein', 'id = %d', (int) $teamId);
        }
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
