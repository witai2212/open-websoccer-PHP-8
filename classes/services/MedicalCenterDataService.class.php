<?php
/******************************************************

  This file is part of OpenWebSoccer-Sim.

******************************************************/

/**
 * Medical center / injury management for human clubs.
 */
class MedicalCenterDataService {

    const TREATMENT_REST = 'rest';
    const TREATMENT_PHYSIOTHERAPY = 'physiotherapy';
    const TREATMENT_SPECIALIST = 'specialist';
    const TREATMENT_RISKY_CURE = 'risky_cure';

    private static $_schemaReady = FALSE;
    private static $_qualityCache = array();

    public static function isEnabled(WebSoccer $websoccer) {
        $value = $websoccer->getConfig('medcenter_enabled');
        return ($value === null || $value === '' || $value == '1' || $value === TRUE);
    }

    public static function getPageData(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $teamId, $userId) {
        self::ensureSchema($websoccer, $db);

        if (!self::isEnabled($websoccer)) {
            return self::emptyPageData(FALSE, TRUE);
        }

        $team = self::getTeam($websoccer, $db, $teamId);
        if (!$team || (int) $team['user_id'] < 1 || (int) $team['user_id'] !== (int) $userId) {
            return self::emptyPageData(TRUE, FALSE);
        }

        $nextMatch = self::getNextMatch($websoccer, $db, $teamId);
        $clearances = array();
        if ($nextMatch && isset($nextMatch['match_id'])) {
            $clearances = self::getClearanceMap($websoccer, $db, $teamId, (int) $nextMatch['match_id']);
        }

        return array(
            'enabled' => TRUE,
            'human_team' => TRUE,
            'team' => $team,
            'quality' => self::getMedicalQuality($websoccer, $db, $teamId),
            'next_match' => $nextMatch ? $nextMatch : array(),
            'injured_players' => self::getInjuredPlayers($websoccer, $db, $i18n, $teamId, $clearances),
            'risk_players' => self::getRiskPlayers($websoccer, $db, $i18n, $teamId),
            'treatments' => self::getTreatmentOptions($websoccer),
            'history' => self::getHistory($websoccer, $db, $teamId, 25)
        );
    }

    private static function emptyPageData($enabled, $humanTeam) {
        return array(
            'enabled' => $enabled,
            'human_team' => $humanTeam,
            'team' => array(),
            'quality' => array('physio' => 0, 'facility' => 0, 'total' => 0),
            'next_match' => array(),
            'injured_players' => array(),
            'risk_players' => array(),
            'treatments' => array(),
            'history' => array()
        );
    }

    public static function getTreatmentOptions(WebSoccer $websoccer) {
        return array(
            self::TREATMENT_REST => array('key' => self::TREATMENT_REST, 'cost' => 0, 'success_chance' => 0, 'reduction' => 0, 'caught_chance' => 0),
            self::TREATMENT_PHYSIOTHERAPY => array('key' => self::TREATMENT_PHYSIOTHERAPY, 'cost' => self::getConfigNumber($websoccer, 'medcenter_physio_fee', 25000), 'success_chance' => 45, 'reduction' => 1, 'caught_chance' => 0),
            self::TREATMENT_SPECIALIST => array('key' => self::TREATMENT_SPECIALIST, 'cost' => self::getConfigNumber($websoccer, 'medcenter_spec_fee', 100000), 'success_chance' => 70, 'reduction' => 2, 'caught_chance' => 0),
            self::TREATMENT_RISKY_CURE => array('key' => self::TREATMENT_RISKY_CURE, 'cost' => self::getConfigNumber($websoccer, 'medcenter_risk_fee', 250000), 'success_chance' => 85, 'reduction' => 99, 'caught_chance' => self::getConfigNumber($websoccer, 'medcenter_detect_pct', 15))
        );
    }

    public static function applyTreatment(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $teamId, $userId, $playerId, $treatmentKey) {
        self::ensureSchema($websoccer, $db);

        if (!self::isEnabled($websoccer)) {
            throw new Exception('medicalcenter_error_disabled');
        }
        self::assertHumanTeam($websoccer, $db, $teamId, $userId);

        $treatments = self::getTreatmentOptions($websoccer);
        if (!isset($treatments[$treatmentKey])) {
            throw new Exception('medicalcenter_error_invalid_treatment');
        }
        $treatment = $treatments[$treatmentKey];

        $player = self::getPlayer($websoccer, $db, $teamId, $playerId);
        if (!$player) {
            throw new Exception('medicalcenter_error_player_not_found');
        }
        if ((int) $player['matches_injured'] <= 0) {
            throw new Exception('medicalcenter_error_player_not_injured');
        }

        $team = self::getTeam($websoccer, $db, $teamId);
        if ((int) $treatment['cost'] > 0 && (int) $team['finanz_budget'] < (int) $treatment['cost']) {
            throw new Exception('medicalcenter_error_not_enough_budget');
        }

        if ((int) $treatment['cost'] > 0) {
            BankAccountDataService::debitAmount($websoccer, $db, $teamId, (int) $treatment['cost'], 'medicalcenter_account_treatment_' . $treatmentKey, 'medicalcenter_account_sender');
        }

        if ($treatmentKey === self::TREATMENT_REST) {
            $db->queryUpdate(array('w_frische' => min(100, (float) $player['strength_freshness'] + 3)), self::playerTable($websoccer), 'id = %d', (int) $playerId);
            self::logTreatment($websoccer, $db, $teamId, $playerId, $treatmentKey, (int) $treatment['cost'], 0, 0, 'success');
            return 'medicalcenter_success_treatment_rest';
        }

        $quality = self::getMedicalQuality($websoccer, $db, $teamId);
        $successChance = min(95, (int) $treatment['success_chance'] + (int) floor($quality['total'] / 2));

        if ($treatmentKey === self::TREATMENT_RISKY_CURE) {
            $caughtChance = max(5, min(60, (int) $treatment['caught_chance'] - (int) floor($quality['physio'] / 4)));
            if (mt_rand(1, 100) <= $caughtChance) {
                self::applyRiskyCurePenalty($websoccer, $db, $teamId, $playerId, $player);
                self::logTreatment($websoccer, $db, $teamId, $playerId, $treatmentKey, (int) $treatment['cost'], 0, $caughtChance, 'caught');
                self::logInjury($websoccer, $db, $teamId, $playerId, 'treatment', 0, (int) $player['matches_injured'], 'Verbotene Wunderkur entdeckt', 'treatment-caught:' . $playerId . ':' . $websoccer->getNowAsTimestamp());
                return 'medicalcenter_error_risky_cure_caught';
            }
            $successChance = 100;
        }

        $oldInjury = (int) $player['matches_injured'];
        $newInjury = $oldInjury;
        if (mt_rand(1, 100) <= $successChance) {
            $newInjury = max(0, $oldInjury - (int) $treatment['reduction']);
            $update = array('verletzt' => $newInjury);
            if ($treatmentKey === self::TREATMENT_RISKY_CURE) {
                $update['w_frische'] = min(100, (float) $player['strength_freshness'] + 8);
            }
            $db->queryUpdate($update, self::playerTable($websoccer), 'id = %d', (int) $playerId);
            self::logTreatment($websoccer, $db, $teamId, $playerId, $treatmentKey, (int) $treatment['cost'], $oldInjury - $newInjury, 0, 'success');
            return ($treatmentKey === self::TREATMENT_RISKY_CURE) ? 'medicalcenter_success_risky_cure' : 'medicalcenter_success_treatment_helped';
        }

        self::logTreatment($websoccer, $db, $teamId, $playerId, $treatmentKey, (int) $treatment['cost'], 0, 0, 'no_effect');
        return 'medicalcenter_success_treatment_no_effect';
    }

    public static function createRiskClearance(WebSoccer $websoccer, DbConnection $db, $teamId, $userId, $playerId) {
        self::ensureSchema($websoccer, $db);

        if (!self::isEnabled($websoccer)) {
            throw new Exception('medicalcenter_error_disabled');
        }
        self::assertHumanTeam($websoccer, $db, $teamId, $userId);

        $player = self::getPlayer($websoccer, $db, $teamId, $playerId);
        if (!$player) {
            throw new Exception('medicalcenter_error_player_not_found');
        }
        if ((int) $player['matches_injured'] !== 1) {
            throw new Exception('medicalcenter_error_clearance_only_minor_injury');
        }

        $nextMatch = self::getNextMatch($websoccer, $db, $teamId);
        if (!$nextMatch || !isset($nextMatch['match_id'])) {
            throw new Exception('medicalcenter_error_no_next_match');
        }

        $matchId = (int) $nextMatch['match_id'];
        $table = self::clearanceTable($websoccer);
        $result = $db->querySelect('id', $table, "team_id = %d AND player_id = %d AND match_id = %d", array((int) $teamId, (int) $playerId, $matchId), 1);
        $existing = $result->fetch_array();
        $result->free();
        if ($existing && isset($existing['id'])) {
            $db->queryUpdate(array('status' => 'open', 'created_date' => $websoccer->getNowAsTimestamp()), $table, 'id = %d', (int) $existing['id']);
        } else {
            $db->queryInsert(array(
                'team_id' => (int) $teamId,
                'player_id' => (int) $playerId,
                'match_id' => $matchId,
                'created_date' => $websoccer->getNowAsTimestamp(),
                'status' => 'open'
            ), $table);
        }
        return TRUE;
    }

    public static function isPlayerClearedForMatch(WebSoccer $websoccer, DbConnection $db, $teamId, $playerId, $matchId) {
        self::ensureSchema($websoccer, $db);
        if ((int) $teamId < 1 || (int) $playerId < 1 || (int) $matchId < 1) {
            return FALSE;
        }
        $result = $db->querySelect('id', self::clearanceTable($websoccer), "team_id = %d AND player_id = %d AND match_id = %d AND status = 'open'", array((int) $teamId, (int) $playerId, (int) $matchId), 1);
        $row = $result->fetch_array();
        $result->free();
        return ($row && isset($row['id']));
    }

    public static function decorateFormationPlayers(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $teamId, $matchId, $players) {
        if (!self::isEnabled($websoccer) || (int) $teamId < 1 || !is_array($players)) {
            return $players;
        }
        self::ensureSchema($websoccer, $db);
        $clearances = self::getClearanceMap($websoccer, $db, $teamId, $matchId);

        foreach ($players as $position => $positionPlayers) {
            foreach ($positionPlayers as $index => $player) {
                $risk = self::calculateInjuryRisk($websoccer, $db, $teamId, $player);
                $players[$position][$index]['medical_risk'] = $risk['score'];
                $players[$position][$index]['medical_risk_level'] = $risk['level'];
                $players[$position][$index]['medical_risk_reasons'] = $risk['reasons'];
                $players[$position][$index]['medical_risk_text'] = self::formatReasons($i18n, $risk['reasons']);
                $players[$position][$index]['medical_cleared_to_play'] = (isset($clearances[(int) $player['id']]) && (int) $player['matches_injured'] === 1);
            }
        }
        return $players;
    }

    public static function getFormationWarnings(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $teamId, $matchId, $limit = 6) {
        if (!self::isEnabled($websoccer) || (int) $teamId < 1) {
            return array();
        }
        self::ensureSchema($websoccer, $db);
        $players = self::getPlayersForRisk($websoccer, $db, $teamId, FALSE);
        $warnings = array();
        foreach ($players as $player) {
            $risk = self::calculateInjuryRisk($websoccer, $db, $teamId, $player);
            if ($risk['score'] >= 40) {
                $warnings[] = array(
                    'id' => (int) $player['id'],
                    'name' => self::getPlayerName($player),
                    'risk' => $risk['score'],
                    'level' => $risk['level'],
                    'reasons' => self::formatReasons($i18n, $risk['reasons'])
                );
            }
        }
        usort($warnings, array('MedicalCenterDataService', 'sortWarningsByRisk'));
        return array_slice($warnings, 0, $limit);
    }

    public static function sortWarningsByRisk($a, $b) {
        return ((int) $a['risk'] < (int) $b['risk']) ? 1 : -1;
    }

    public static function logInjury(WebSoccer $websoccer, DbConnection $db, $teamId, $playerId, $source, $sourceId, $injuryMatches, $description = '', $referenceKey = '') {
        if ((int) $teamId < 1 || (int) $playerId < 1 || (int) $injuryMatches <= 0) {
            return;
        }
        self::ensureSchema($websoccer, $db);
        if ($referenceKey === '') {
            $referenceKey = $source . ':' . (int) $sourceId . ':' . (int) $playerId . ':' . (int) $injuryMatches;
        }
        $table = self::logTable($websoccer);
        $result = $db->querySelect('id', $table, "reference_key = '%s'", $referenceKey, 1);
        $row = $result->fetch_array();
        $result->free();
        if ($row && isset($row['id'])) {
            return;
        }
        $db->queryInsert(array(
            'team_id' => (int) $teamId,
            'player_id' => (int) $playerId,
            'source' => (string) $source,
            'source_id' => (int) $sourceId,
            'injury_matches' => (int) $injuryMatches,
            'event_date' => $websoccer->getNowAsTimestamp(),
            'description' => $description,
            'reference_key' => $referenceKey
        ), $table);
    }

    public static function logMatchInjuries(MatchCompletedEvent $event) {
        if (!$event->match || $event->match->homeTeam->isNationalTeam) {
            return;
        }
        self::ensureSchema($event->websoccer, $event->db);
        $prefix = $event->websoccer->getConfig('db_prefix');
        $result = $event->db->querySelect('spieler_id AS player_id, team_id, verletzt AS injury_matches', $prefix . '_spiel_berechnung', 'spiel_id = %d AND verletzt > 0', (int) $event->match->id);
        while ($row = $result->fetch_array()) {
            self::logInjury($event->websoccer, $event->db, (int) $row['team_id'], (int) $row['player_id'], 'match', (int) $event->match->id, (int) $row['injury_matches'], '', 'match:' . (int) $event->match->id . ':' . (int) $row['player_id']);
        }
        $result->free();
    }

    public static function processRiskClearancesAfterMatch(MatchCompletedEvent $event) {
        if (!$event->match || $event->match->homeTeam->isNationalTeam) {
            return;
        }
        self::ensureSchema($event->websoccer, $event->db);
        $matchId = (int) $event->match->id;
        $table = self::clearanceTable($event->websoccer);
        $result = $event->db->querySelect('*', $table, "match_id = %d AND status = 'open'", $matchId);
        while ($clearance = $result->fetch_array()) {
            $played = self::hasPlayerPlayed($event->websoccer, $event->db, $matchId, (int) $clearance['player_id']);
            if (!$played) {
                $event->db->queryUpdate(array('status' => 'expired', 'processed_date' => $event->websoccer->getNowAsTimestamp()), $table, 'id = %d', (int) $clearance['id']);
                continue;
            }

            $player = self::getPlayer($event->websoccer, $event->db, (int) $clearance['team_id'], (int) $clearance['player_id']);
            $risk = 45;
            if ($player && isset($player['personality']) && $player['personality'] === 'injury_prone') {
                $risk += 15;
            }
            $quality = self::getMedicalQuality($event->websoccer, $event->db, (int) $clearance['team_id']);
            $risk = max(20, min(80, $risk - (int) floor($quality['total'] / 3)));

            if (mt_rand(1, 100) <= $risk) {
                $injuryMatches = mt_rand(2, 4);
                $event->db->queryUpdate(array('verletzt' => $injuryMatches), self::playerTable($event->websoccer), 'id = %d', (int) $clearance['player_id']);
                self::logInjury($event->websoccer, $event->db, (int) $clearance['team_id'], (int) $clearance['player_id'], 'play_risk', $matchId, $injuryMatches, 'Rückfall nach riskanter Freigabe', 'play-risk:' . $matchId . ':' . (int) $clearance['player_id']);
            }
            $event->db->queryUpdate(array('status' => 'used', 'processed_date' => $event->websoccer->getNowAsTimestamp()), $table, 'id = %d', (int) $clearance['id']);
        }
        $result->free();
    }

    private static function hasPlayerPlayed(WebSoccer $websoccer, DbConnection $db, $matchId, $playerId) {
        $result = $db->querySelect('id', $websoccer->getConfig('db_prefix') . '_spiel_berechnung', 'spiel_id = %d AND spieler_id = %d AND minuten_gespielt > 0', array((int) $matchId, (int) $playerId), 1);
        $row = $result->fetch_array();
        $result->free();
        return ($row && isset($row['id']));
    }

    private static function applyRiskyCurePenalty(WebSoccer $websoccer, DbConnection $db, $teamId, $playerId, $player) {
        $db->queryUpdate(array(
            'gesperrt' => max(2, (int) $player['matches_blocked'] + 2),
            'w_zufriedenheit' => max(1, (float) $player['strength_satisfaction'] - 8)
        ), self::playerTable($websoccer), 'id = %d', (int) $playerId);

        $team = self::getTeam($websoccer, $db, $teamId);
        if ($team) {
            $db->queryUpdate(array(
                'board_satisfaction' => max(0, (int) $team['board_satisfaction'] - 8),
                'fan_mood' => max(0, (int) $team['fan_mood'] - 5),
                'media_pressure' => min(100, (int) $team['media_pressure'] + 10)
            ), $websoccer->getConfig('db_prefix') . '_verein', 'id = %d', (int) $teamId);
        }
    }

    private static function getInjuredPlayers(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $teamId, $clearances) {
        $players = self::getPlayersForRisk($websoccer, $db, $teamId, TRUE);
        $result = array();
        foreach ($players as $player) {
            if ((int) $player['matches_injured'] <= 0) {
                continue;
            }
            $risk = self::calculateInjuryRisk($websoccer, $db, $teamId, $player);
            $player['name'] = self::getPlayerName($player);
            $player['risk'] = $risk['score'];
            $player['risk_level'] = $risk['level'];
            $player['risk_reasons'] = self::formatReasons($i18n, $risk['reasons']);
            $player['cleared_to_play'] = isset($clearances[(int) $player['id']]);
            $result[] = $player;
        }
        return $result;
    }

    private static function getRiskPlayers(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $teamId) {
        $players = self::getPlayersForRisk($websoccer, $db, $teamId, FALSE);
        $result = array();
        foreach ($players as $player) {
            if ((int) $player['matches_injured'] > 0) {
                continue;
            }
            $risk = self::calculateInjuryRisk($websoccer, $db, $teamId, $player);
            if ($risk['score'] < 35) {
                continue;
            }
            $player['name'] = self::getPlayerName($player);
            $player['risk'] = $risk['score'];
            $player['risk_level'] = $risk['level'];
            $player['risk_reasons'] = self::formatReasons($i18n, $risk['reasons']);
            $result[] = $player;
        }
        usort($result, array('MedicalCenterDataService', 'sortPlayerRowsByRisk'));
        return array_slice($result, 0, 12);
    }

    public static function sortPlayerRowsByRisk($a, $b) {
        return ((int) $a['risk'] < (int) $b['risk']) ? 1 : -1;
    }

    private static function getPlayersForRisk(WebSoccer $websoccer, DbConnection $db, $teamId, $includeInjured) {
        $columns = array(
            'id' => 'id',
            'vorname' => 'firstname',
            'nachname' => 'lastname',
            'kunstname' => 'pseudonym',
            'position' => 'position',
            'position_main' => 'position_main',
            'verletzt' => 'matches_injured',
            'gesperrt' => 'matches_blocked',
            'w_frische' => 'strength_freshness',
            'w_kondition' => 'strength_stamina',
            'w_zufriedenheit' => 'strength_satisfaction',
            'personality' => 'personality'
        );
        if ($websoccer->getConfig('players_aging') == 'birthday') {
            $columns['TIMESTAMPDIFF(YEAR,geburtstag,CURDATE())'] = 'age';
        } else {
            $columns['age'] = 'age';
        }
        $where = "verein_id = %d AND status = '1'";
        if (!$includeInjured) {
            $where .= ' AND verletzt = 0';
        }
        $where .= ' ORDER BY verletzt DESC, nachname ASC, vorname ASC';
        $result = $db->querySelect($columns, self::playerTable($websoccer), $where, (int) $teamId);
        $players = array();
        while ($row = $result->fetch_array()) {
            $row['position'] = self::convertPosition($row['position']);
            $players[] = $row;
        }
        $result->free();
        return $players;
    }

    private static function calculateInjuryRisk(WebSoccer $websoccer, DbConnection $db, $teamId, $player) {
        $risk = 8;
        $reasons = array();
        $freshness = isset($player['strength_freshness']) ? (float) $player['strength_freshness'] : 50;
        $stamina = isset($player['strength_stamina']) ? (float) $player['strength_stamina'] : 50;
        $age = isset($player['age']) ? (int) $player['age'] : 24;

        if ($freshness < 50) {
            $risk += (int) round((50 - $freshness) * 0.7);
            $reasons[] = 'freshness';
        } elseif ($freshness < 65) {
            $risk += 6;
            $reasons[] = 'freshness';
        }
        if ($stamina < 55) {
            $risk += (int) round((55 - $stamina) * 0.45);
            $reasons[] = 'stamina';
        }
        if ($age >= 32) {
            $risk += min(14, ($age - 31) * 3);
            $reasons[] = 'age';
        }
        if (isset($player['personality']) && $player['personality'] === 'injury_prone') {
            $risk += 15;
            $reasons[] = 'injury_prone';
        }

        $recent = self::countRecentInjuries($websoccer, $db, (int) $player['id']);
        if ($recent > 0) {
            $risk += min(15, $recent * 5);
            $reasons[] = 'history';
        }

        $quality = self::getMedicalQuality($websoccer, $db, $teamId);
        $risk -= (int) floor($quality['total'] / 3);
        $risk = max(5, min(90, $risk));

        if ($risk >= 55) {
            $level = 'high';
        } elseif ($risk >= 35) {
            $level = 'medium';
        } else {
            $level = 'low';
        }
        return array('score' => $risk, 'level' => $level, 'reasons' => array_unique($reasons));
    }

    private static function countRecentInjuries(WebSoccer $websoccer, DbConnection $db, $playerId) {
        self::ensureSchema($websoccer, $db);
        $since = $websoccer->getNowAsTimestamp() - (120 * 24 * 60 * 60);
        $result = $db->querySelect('COUNT(*) AS hits', self::logTable($websoccer), 'player_id = %d AND event_date >= %d', array((int) $playerId, $since), 1);
        $row = $result->fetch_array();
        $result->free();
        return ($row && isset($row['hits'])) ? (int) $row['hits'] : 0;
    }

    private static function formatReasons(I18n $i18n, $reasons) {
        if (!is_array($reasons) || !count($reasons)) {
            return '';
        }
        $labels = array();
        foreach ($reasons as $reason) {
            $labels[] = $i18n->getMessage('medicalcenter_risk_reason_' . $reason);
        }
        return implode(', ', $labels);
    }

    public static function getMedicalQuality(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $key = (int) $teamId;
        if (isset(self::$_qualityCache[$key])) {
            return self::$_qualityCache[$key];
        }
        $physio = 0;
        if (class_exists('ClubStaffDataService')) {
            try {
                $physio = (int) ClubStaffDataService::getRoleBonus($websoccer, $db, $teamId, ClubStaffDataService::ROLE_PHYSIO);
            } catch (Exception $e) {
                $physio = 0;
            }
        }
        $facility = self::getFacilityInjuryBonus($websoccer, $db, $teamId);
        $total = max(0, $physio + ($facility * 8));
        self::$_qualityCache[$key] = array('physio' => $physio, 'facility' => $facility, 'total' => $total);
        return self::$_qualityCache[$key];
    }

    private static function getFacilityInjuryBonus(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $prefix = $websoccer->getConfig('db_prefix');
        try {
            $result = $db->querySelect('SUM(B.effect_injury) AS bonus', $prefix . '_buildings_of_team AS BT INNER JOIN ' . $prefix . '_stadiumbuilding AS B ON B.id = BT.building_id', 'BT.team_id = %d AND BT.construction_deadline < %d', array((int) $teamId, $websoccer->getNowAsTimestamp()), 1);
            $row = $result->fetch_array();
            $result->free();
            return ($row && isset($row['bonus'])) ? (int) $row['bonus'] : 0;
        } catch (Exception $e) {
            return 0;
        }
    }

    private static function getClearanceMap(WebSoccer $websoccer, DbConnection $db, $teamId, $matchId) {
        self::ensureSchema($websoccer, $db);
        $map = array();
        if ((int) $matchId < 1) {
            return $map;
        }
        $result = $db->querySelect('player_id', self::clearanceTable($websoccer), "team_id = %d AND match_id = %d AND status = 'open'", array((int) $teamId, (int) $matchId));
        while ($row = $result->fetch_array()) {
            $map[(int) $row['player_id']] = TRUE;
        }
        $result->free();
        return $map;
    }

    private static function getHistory(WebSoccer $websoccer, DbConnection $db, $teamId, $limit) {
        self::ensureSchema($websoccer, $db);
        $prefix = $websoccer->getConfig('db_prefix');
        $columns = array(
            'L.id' => 'id',
            'L.player_id' => 'player_id',
            'L.source' => 'source',
            'L.source_id' => 'source_id',
            'L.injury_matches' => 'injury_matches',
            'L.event_date' => 'event_date',
            'L.description' => 'description',
            'P.vorname' => 'firstname',
            'P.nachname' => 'lastname',
            'P.kunstname' => 'pseudonym'
        );
        $from = self::logTable($websoccer) . ' AS L LEFT JOIN ' . $prefix . '_spieler AS P ON P.id = L.player_id';
        $result = $db->querySelect($columns, $from, 'L.team_id = %d ORDER BY L.event_date DESC, L.id DESC', (int) $teamId, (int) $limit);
        $rows = array();
        while ($row = $result->fetch_array()) {
            $row['player_name'] = self::getPlayerName($row);
            $rows[] = $row;
        }
        $result->free();
        return $rows;
    }

    private static function logTreatment(WebSoccer $websoccer, DbConnection $db, $teamId, $playerId, $treatmentKey, $costs, $reduction, $caughtChance, $outcome) {
        self::ensureSchema($websoccer, $db);
        $db->queryInsert(array(
            'team_id' => (int) $teamId,
            'player_id' => (int) $playerId,
            'treatment' => $treatmentKey,
            'costs' => (int) $costs,
            'injury_reduction' => (int) $reduction,
            'caught_chance' => (int) $caughtChance,
            'outcome' => $outcome,
            'created_date' => $websoccer->getNowAsTimestamp()
        ), self::treatmentTable($websoccer));
    }

    private static function getPlayer(WebSoccer $websoccer, DbConnection $db, $teamId, $playerId) {
        $columns = array(
            'id' => 'id',
            'verein_id' => 'team_id',
            'vorname' => 'firstname',
            'nachname' => 'lastname',
            'kunstname' => 'pseudonym',
            'position' => 'position',
            'position_main' => 'position_main',
            'verletzt' => 'matches_injured',
            'gesperrt' => 'matches_blocked',
            'w_frische' => 'strength_freshness',
            'w_kondition' => 'strength_stamina',
            'w_zufriedenheit' => 'strength_satisfaction',
            'personality' => 'personality'
        );
        if ($websoccer->getConfig('players_aging') == 'birthday') {
            $columns['TIMESTAMPDIFF(YEAR,geburtstag,CURDATE())'] = 'age';
        } else {
            $columns['age'] = 'age';
        }
        $result = $db->querySelect($columns, self::playerTable($websoccer), 'id = %d AND verein_id = %d AND status = \'1\'', array((int) $playerId, (int) $teamId), 1);
        $row = $result->fetch_array();
        $result->free();
        if ($row && isset($row['position'])) {
            $row['position'] = self::convertPosition($row['position']);
        }
        return $row ? $row : array();
    }

    private static function convertPosition($position) {
        if ($position === 'Torwart') {
            return 'goaly';
        }
        if ($position === 'Abwehr') {
            return 'defense';
        }
        if ($position === 'Mittelfeld') {
            return 'midfield';
        }
        if ($position === 'Sturm') {
            return 'striker';
        }
        return $position;
    }

    private static function getPlayerName($player) {
        if (isset($player['pseudonym']) && strlen($player['pseudonym'])) {
            return $player['pseudonym'];
        }
        $first = isset($player['firstname']) ? $player['firstname'] : '';
        $last = isset($player['lastname']) ? $player['lastname'] : '';
        return trim($first . ' ' . $last);
    }

    private static function getTeam(WebSoccer $websoccer, DbConnection $db, $teamId) {
        if ((int) $teamId < 1) {
            return array();
        }
        $columns = 'id, name, user_id, finanz_budget, board_satisfaction, fan_mood, media_pressure, status';
        $result = $db->querySelect($columns, $websoccer->getConfig('db_prefix') . '_verein', 'id = %d', (int) $teamId, 1);
        $row = $result->fetch_array();
        $result->free();
        return $row ? $row : array();
    }

    private static function assertHumanTeam(WebSoccer $websoccer, DbConnection $db, $teamId, $userId) {
        $team = self::getTeam($websoccer, $db, $teamId);
        if (!$team) {
            throw new Exception('medicalcenter_error_no_team');
        }
        if ((int) $team['user_id'] < 1 || (int) $team['user_id'] !== (int) $userId) {
            throw new Exception('medicalcenter_error_human_only');
        }
    }

    private static function getNextMatch(WebSoccer $websoccer, DbConnection $db, $teamId) {
        try {
            $matches = MatchesDataService::getNextMatches($websoccer, $db, $teamId, 1);
            return count($matches) ? $matches[0] : array();
        } catch (Exception $e) {
            return array();
        }
    }

    private static function getConfigNumber(WebSoccer $websoccer, $key, $default) {
        $value = $websoccer->getConfig($key);
        if ($value === null || $value === '') {
            return (int) $default;
        }
        return (int) $value;
    }

    public static function ensureSchema(WebSoccer $websoccer, DbConnection $db) {
        if (self::$_schemaReady) {
            return;
        }
        $prefix = $websoccer->getConfig('db_prefix');
        $logTable = self::logTable($websoccer);
        $treatmentTable = self::treatmentTable($websoccer);
        $clearanceTable = self::clearanceTable($websoccer);

        $db->executeQuery("CREATE TABLE IF NOT EXISTS " . $logTable . " (
            id INT(10) NOT NULL AUTO_INCREMENT,
            team_id INT(10) NOT NULL,
            player_id INT(10) NOT NULL,
            source VARCHAR(32) NOT NULL DEFAULT 'match',
            source_id INT(10) NOT NULL DEFAULT 0,
            injury_matches TINYINT(3) NOT NULL DEFAULT 0,
            event_date INT(11) NOT NULL DEFAULT 0,
            description VARCHAR(255) DEFAULT NULL,
            reference_key VARCHAR(128) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_injury_log_reference (reference_key),
            KEY idx_injury_log_team_date (team_id, event_date),
            KEY idx_injury_log_player (player_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        $db->executeQuery("CREATE TABLE IF NOT EXISTS " . $treatmentTable . " (
            id INT(10) NOT NULL AUTO_INCREMENT,
            team_id INT(10) NOT NULL,
            player_id INT(10) NOT NULL,
            treatment VARCHAR(32) NOT NULL,
            costs INT(10) NOT NULL DEFAULT 0,
            injury_reduction TINYINT(3) NOT NULL DEFAULT 0,
            caught_chance TINYINT(3) NOT NULL DEFAULT 0,
            outcome VARCHAR(32) NOT NULL DEFAULT 'success',
            created_date INT(11) NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY idx_injury_treatment_team_date (team_id, created_date),
            KEY idx_injury_treatment_player (player_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        $db->executeQuery("CREATE TABLE IF NOT EXISTS " . $clearanceTable . " (
            id INT(10) NOT NULL AUTO_INCREMENT,
            team_id INT(10) NOT NULL,
            player_id INT(10) NOT NULL,
            match_id INT(10) NOT NULL,
            created_date INT(11) NOT NULL DEFAULT 0,
            processed_date INT(11) NOT NULL DEFAULT 0,
            status ENUM('open','used','expired') NOT NULL DEFAULT 'open',
            PRIMARY KEY (id),
            UNIQUE KEY uniq_injury_clearance_player_match (player_id, match_id),
            KEY idx_injury_clearance_match_status (match_id, status),
            KEY idx_injury_clearance_team (team_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        self::$_schemaReady = TRUE;
    }

    private static function playerTable(WebSoccer $websoccer) {
        return $websoccer->getConfig('db_prefix') . '_spieler';
    }

    private static function logTable(WebSoccer $websoccer) {
        return $websoccer->getConfig('db_prefix') . '_injury_log';
    }

    private static function treatmentTable(WebSoccer $websoccer) {
        return $websoccer->getConfig('db_prefix') . '_injury_treatment';
    }

    private static function clearanceTable(WebSoccer $websoccer) {
        return $websoccer->getConfig('db_prefix') . '_injury_clearance';
    }
}
?>
