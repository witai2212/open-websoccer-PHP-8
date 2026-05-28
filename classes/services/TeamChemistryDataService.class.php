<?php
/******************************************************

  This file is part of OpenWebSoccer-Sim.

  OpenWebSoccer-Sim is free software: you can redistribute it 
  and/or modify it under the terms of the 
  GNU Lesser General Public License as published by the Free Software Foundation,
  either version 3 of the License, or any later version.

******************************************************/

/**
 * Computes and stores team chemistry.
 *
 * Chemistry is intentionally lightweight: it influences only small match details
 * and should never replace squad strength, tactics or form.
 */
class TeamChemistryDataService {

    private static $_schemaReady = null;
    private static $_logTableReady = null;
    private static $_trainingCampReportTableReady = null;
    private static $_trainingReportTableReady = null;
    private static $_matchEffectCache = array();

    public static function isEnabled(WebSoccer $websoccer) {
        return self::getOptionalBooleanConfig($websoccer, 'team_chemistry_enabled', TRUE);
    }

    /**
     * Data for the manager page.
     */
    public static function getPageData(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $teamId) {
        $data = self::refreshTeamChemistry($websoccer, $db, $teamId, 'page');
        $data['schema_ready'] = self::schemaReady($websoccer, $db);
        $data['log'] = self::getRecentLog($websoccer, $db, $i18n, $teamId, 20);
        $data['score_label'] = self::getScoreLabelKey($data['score']);
        $data['hint_key'] = self::getHintKey($data['score']);
        $data['effect_signed'] = self::formatSignedNumber($data['match_effect']);
        return $data;
    }

    /**
     * Called when a match is created for the first time.
     * Adds a tiny morale effect and stores runtime chemistry properties.
     */
    public static function applyInitialMatchEffects(WebSoccer $websoccer, DbConnection $db, SimulationMatch $match) {
        if (!self::isEnabled($websoccer) || !$match) {
            return;
        }

        self::setRuntimeMatchEffect($websoccer, $db, $match->homeTeam, 'match');
        self::setRuntimeMatchEffect($websoccer, $db, $match->guestTeam, 'match');

        $match->homeTeam->morale = min(100, max(0, (int) $match->homeTeam->morale + (int) $match->homeTeam->chemistryMatchEffect));
        $match->guestTeam->morale = min(100, max(0, (int) $match->guestTeam->morale + (int) $match->guestTeam->chemistryMatchEffect));
    }

    /**
     * Called also for resumed live simulations. Does not touch persisted morale.
     */
    public static function applyRuntimeMatchEffects(WebSoccer $websoccer, DbConnection $db, SimulationMatch $match) {
        if (!self::isEnabled($websoccer) || !$match) {
            return;
        }

        self::setRuntimeMatchEffect($websoccer, $db, $match->homeTeam, 'runtime');
        self::setRuntimeMatchEffect($websoccer, $db, $match->guestTeam, 'runtime');
    }

    /**
     * Refreshes both teams after a completed match so formation familiarity/history is current.
     */
    public static function processCompletedMatch(MatchCompletedEvent $event) {
        if (!self::isEnabled($event->websoccer) || !$event->match) {
            return;
        }

        self::refreshTeamChemistry($event->websoccer, $event->db, (int) $event->match->homeTeam->id, 'match_completed', (int) $event->match->id);
        self::refreshTeamChemistry($event->websoccer, $event->db, (int) $event->match->guestTeam->id, 'match_completed', (int) $event->match->id);
    }

    /**
     * Small pass success modifier in percentage points, e.g. -3..+3.
     */
    public static function getTeamMatchEffect(SimulationTeam $team) {
        if (!$team || !isset($team->chemistryMatchEffect)) {
            return 0;
        }
        return (int) $team->chemistryMatchEffect;
    }

    /**
     * Small action-stability modifier. A positive value helps a trailing team stay calm.
     */
    public static function getTeamStabilityEffect(SimulationTeam $team) {
        return self::getTeamMatchEffect($team);
    }

    public static function refreshTeamChemistry(WebSoccer $websoccer, DbConnection $db, $teamId, $source = 'manual', $matchId = 0) {
        $teamId = (int) $teamId;
        $result = array(
            'team_id' => $teamId,
            'score' => 50,
            'match_effect' => 0,
            'factors' => array(),
            'updated' => FALSE
        );

        if (!self::isEnabled($websoccer) || $teamId < 1) {
            return $result;
        }

        $calculated = self::calculateTeamChemistry($websoccer, $db, $teamId);
        $score = self::normalizePercent($calculated['score']);
        $effect = self::scoreToMatchEffect($websoccer, $score);

        $result['score'] = $score;
        $result['match_effect'] = $effect;
        $result['factors'] = $calculated['factors'];

        if (!self::schemaReady($websoccer, $db)) {
            return $result;
        }

        $old = self::getStoredChemistry($websoccer, $db, $teamId);
        $oldScore = ($old && isset($old['team_chemistry']) && $old['team_chemistry'] !== '') ? (int) $old['team_chemistry'] : null;

        $db->queryUpdate(
            array(
                'team_chemistry' => $score,
                'team_chemistry_effect' => $effect,
                'team_chemistry_updated' => $websoccer->getNowAsTimestamp()
            ),
            $websoccer->getConfig('db_prefix') . '_verein',
            'id = %d',
            $teamId
        );

        $result['updated'] = TRUE;

        if ($oldScore === null || abs($oldScore - $score) >= 2 || (int) $matchId > 0) {
            self::insertLog($websoccer, $db, $teamId, $source, ($oldScore === null ? $score : $oldScore), $score, $effect, $calculated['factors'], $matchId);
        }

        self::$_matchEffectCache[$teamId] = array('score' => $score, 'effect' => $effect);
        return $result;
    }

    private static function setRuntimeMatchEffect(WebSoccer $websoccer, DbConnection $db, SimulationTeam $team, $source) {
        if (!$team || (int) $team->id < 1 || $team->isNationalTeam) {
            return;
        }

        $teamId = (int) $team->id;
        if (!isset(self::$_matchEffectCache[$teamId])) {
            $data = self::refreshTeamChemistry($websoccer, $db, $teamId, $source);
            self::$_matchEffectCache[$teamId] = array('score' => (int) $data['score'], 'effect' => (int) $data['match_effect']);
        }

        $team->chemistryScore = (int) self::$_matchEffectCache[$teamId]['score'];
        $team->chemistryMatchEffect = (int) self::$_matchEffectCache[$teamId]['effect'];
    }

    private static function calculateTeamChemistry(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $players = self::getSquadPlayers($websoccer, $db, $teamId);
        $startingIds = self::getLatestStartingPlayerIds($websoccer, $db, $teamId);
        $startingPlayers = self::filterPlayersByIds($players, $startingIds);

        if (!count($players)) {
            return array(
                'score' => 50,
                'factors' => self::emptyFactors()
            );
        }

        if (!count($startingPlayers)) {
            $startingPlayers = $players;
        }

        $factors = array();
        $factors['nationality'] = self::computeNationalityScore($startingPlayers, $players);
        $factors['settled'] = self::computeSettledScore($websoccer, $startingPlayers, $players);
        $factors['formation'] = self::computeFormationScore($websoccer, $db, $teamId);
        $factors['captain'] = self::computeCaptainScore($websoccer, $db, $teamId, $players, $startingIds);
        $factors['transfers'] = self::computeTransferScore($websoccer, $db, $teamId);
        $factors['happiness'] = self::computeHappinessScore($startingPlayers, $players);
        $factors['personality'] = self::computePersonalityScore($startingPlayers, $players);
        $factors['trainingcamp'] = self::computeTrainingCampScore($websoccer, $db, $teamId);
        $factors['training'] = self::computeTrainingScore($websoccer, $db, $teamId);
        $factors['staff'] = self::computeStaffScore($websoccer, $db, $teamId);

        $weights = array(
            'nationality' => 15,
            'settled' => 20,
            'formation' => 15,
            'captain' => 15,
            'transfers' => 15,
            'happiness' => 15,
            'personality' => 5,
            'trainingcamp' => 10,
            'training' => 10,
            'staff' => 5
        );

        $score = 0;
        $weightSum = 0;
        foreach ($weights as $key => $weight) {
            $score += self::normalizePercent($factors[$key]['score']) * $weight;
            $weightSum += $weight;
        }

        return array(
            'score' => ($weightSum > 0) ? (int) round($score / $weightSum) : 50,
            'factors' => $factors
        );
    }

    private static function emptyFactors() {
        $keys = array('nationality', 'settled', 'formation', 'captain', 'transfers', 'happiness', 'personality', 'trainingcamp', 'training', 'staff');
        $factors = array();
        foreach ($keys as $key) {
            $factors[$key] = array('score' => 50, 'detail' => '');
        }
        return $factors;
    }

    private static function getSquadPlayers(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $result = $db->querySelect(
            'id, nation, w_zufriedenheit, st_spiele, last_transfer, w_influence, w_flair, personality',
            $websoccer->getConfig('db_prefix') . '_spieler',
            "verein_id = %d AND status = '1'",
            (int) $teamId
        );

        $players = array();
        while ($row = $result->fetch_array()) {
            $players[(int) $row['id']] = $row;
        }
        $result->free();
        return $players;
    }

    private static function getLatestStartingPlayerIds(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $result = $db->querySelect(
            '*',
            $websoccer->getConfig('db_prefix') . '_aufstellung',
            'verein_id = %d ORDER BY datum DESC, id DESC',
            (int) $teamId,
            1
        );
        $formation = $result->fetch_array();
        $result->free();

        $ids = array();
        if ($formation) {
            for ($i = 1; $i <= 11; $i++) {
                if (isset($formation['spieler' . $i]) && (int) $formation['spieler' . $i] > 0) {
                    $ids[] = (int) $formation['spieler' . $i];
                }
            }
        }
        return $ids;
    }

    private static function filterPlayersByIds($players, $ids) {
        $filtered = array();
        foreach ($ids as $id) {
            if (isset($players[(int) $id])) {
                $filtered[(int) $id] = $players[(int) $id];
            }
        }
        return $filtered;
    }

    private static function computeNationalityScore($startingPlayers, $players) {
        $startScore = self::dominantNationalityScore($startingPlayers);
        $squadScore = self::dominantNationalityScore($players);
        $score = (int) round($startScore * 0.70 + $squadScore * 0.30);
        return array(
            'score' => $score,
            'detail' => self::formatPercentDetail($score)
        );
    }

    private static function dominantNationalityScore($players) {
        $total = 0;
        $counts = array();
        foreach ($players as $player) {
            $nation = trim((string) $player['nation']);
            if (!strlen($nation)) {
                continue;
            }
            $counts[$nation] = isset($counts[$nation]) ? $counts[$nation] + 1 : 1;
            $total++;
        }
        if (!$total) {
            return 50;
        }
        $max = max($counts);
        return (int) round(max(35, ($max / $total) * 100));
    }

    private static function computeSettledScore(WebSoccer $websoccer, $startingPlayers, $players) {
        $score = self::weightedAveragePlayerScore($startingPlayers, $players, function($player) use ($websoccer) {
            $lastTransfer = isset($player['last_transfer']) ? (int) $player['last_transfer'] : 0;
            $matches = isset($player['st_spiele']) ? (int) $player['st_spiele'] : 0;
            if ($lastTransfer <= 0) {
                return min(100, 80 + min(20, $matches));
            }
            $days = max(0, floor(($websoccer->getNowAsTimestamp() - $lastTransfer) / 86400));
            return min(100, 25 + round(($days / 120) * 65) + min(10, floor($matches / 10)));
        });
        return array('score' => $score, 'detail' => self::formatPercentDetail($score));
    }

    private static function computeFormationScore(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $limit = (int) self::getOptionalConfig($websoccer, 'team_chemistry_formation_history_count', 8);
        if ($limit < 3) {
            $limit = 8;
        }

        $prefix = $websoccer->getConfig('db_prefix');
        $sql = 'SELECT setup FROM ' . $prefix . '_aufstellung '
             . 'WHERE verein_id = ' . (int) $teamId . ' AND setup IS NOT NULL AND setup != \'\' '
             . 'ORDER BY datum DESC, id DESC LIMIT ' . (int) $limit;
        $result = $db->executeQuery($sql);
        $setups = array();
        while ($row = $result->fetch_array()) {
            $setups[] = $row['setup'];
        }
        $result->free();

        if (!count($setups)) {
            return array('score' => 50, 'detail' => '');
        }

        $current = $setups[0];
        $countCurrent = 0;
        foreach ($setups as $setup) {
            if ($setup === $current) {
                $countCurrent++;
            }
        }
        $score = min(100, 30 + (int) round(($countCurrent / max(1, count($setups))) * 70));
        return array('score' => $score, 'detail' => $current . ' · ' . $countCurrent . '/' . count($setups));
    }

    private static function computeCaptainScore(WebSoccer $websoccer, DbConnection $db, $teamId, $players, $startingIds) {
        $result = $db->querySelect(
            'captain_id',
            $websoccer->getConfig('db_prefix') . '_verein',
            'id = %d',
            (int) $teamId,
            1
        );
        $team = $result->fetch_array();
        $result->free();

        $captainId = ($team && isset($team['captain_id'])) ? (int) $team['captain_id'] : 0;
        if ($captainId < 1 || !isset($players[$captainId])) {
            return array('score' => 45, 'detail' => '');
        }

        $captain = $players[$captainId];
        $influence = (float) $captain['w_influence'];
        $flair = (float) $captain['w_flair'];
        $score = (int) round(($influence * 0.75) + ($flair * 0.25));
        $score += in_array($captainId, $startingIds) ? 10 : -5;

        $trait = isset($captain['personality']) ? $captain['personality'] : 'professional';
        if ($trait === 'leader') {
            $score += 15;
        } else if ($trait === 'professional' || $trait === 'loyal') {
            $score += 5;
        } else if ($trait === 'troublemaker') {
            $score -= 15;
        } else if ($trait === 'inconsistent') {
            $score -= 5;
        }

        $score = self::normalizePercent($score);
        return array('score' => $score, 'detail' => $trait);
    }

    private static function computeTransferScore(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $days = (int) self::getOptionalConfig($websoccer, 'team_chemistry_recent_transfer_days', 30);
        if ($days < 1) {
            $days = 30;
        }
        $since = $websoccer->getNowAsTimestamp() - ($days * 86400);
        $veryRecentSince = $websoccer->getNowAsTimestamp() - (7 * 86400);
        $prefix = $websoccer->getConfig('db_prefix');

        $sql = 'SELECT '
             . 'SUM(CASE WHEN datum >= ' . (int) $since . ' THEN 1 ELSE 0 END) AS incoming, '
             . 'SUM(CASE WHEN datum >= ' . (int) $veryRecentSince . ' THEN 1 ELSE 0 END) AS very_recent '
             . 'FROM ' . $prefix . '_transfer '
             . 'WHERE buyer_club_id = ' . (int) $teamId . ' AND datum >= ' . (int) $since;
        $result = $db->executeQuery($sql);
        $row = $result->fetch_array();
        $result->free();

        $incoming = ($row && isset($row['incoming'])) ? (int) $row['incoming'] : 0;
        $veryRecent = ($row && isset($row['very_recent'])) ? (int) $row['very_recent'] : 0;
        $score = max(20, 100 - ($incoming * 12) - ($veryRecent * 6));
        return array('score' => $score, 'detail' => (string) $incoming);
    }

    private static function computeHappinessScore($startingPlayers, $players) {
        $score = self::weightedAveragePlayerScore($startingPlayers, $players, function($player) {
            return isset($player['w_zufriedenheit']) ? (float) $player['w_zufriedenheit'] : 50;
        });
        return array('score' => $score, 'detail' => self::formatPercentDetail($score));
    }

    private static function computePersonalityScore($startingPlayers, $players) {
        $map = array(
            'leader' => 85,
            'professional' => 75,
            'loyal' => 80,
            'big_game_player' => 65,
            'ambitious' => 58,
            'injury_prone' => 50,
            'inconsistent' => 45,
            'troublemaker' => 30
        );
        $score = self::weightedAveragePlayerScore($startingPlayers, $players, function($player) use ($map) {
            $trait = isset($player['personality']) ? $player['personality'] : 'professional';
            return isset($map[$trait]) ? $map[$trait] : 60;
        });
        return array('score' => $score, 'detail' => self::formatPercentDetail($score));
    }

    private static function computeTrainingScore(WebSoccer $websoccer, DbConnection $db, $teamId) {
        if (!self::trainingReportTableReady($websoccer, $db)) {
            return array('score' => 50, 'detail' => '');
        }

        $result = $db->querySelect(
            'training_type, created_date, old_chemistry, new_chemistry, summary_data',
            $websoccer->getConfig('db_prefix') . '_training_report',
            'team_id = %d ORDER BY created_date DESC, id DESC',
            (int) $teamId,
            5
        );

        $weightedChange = 0;
        $weightSum = 0;
        $latestType = '';
        while ($report = $result->fetch_array()) {
            if (!strlen($latestType)) {
                $latestType = $report['training_type'];
            }
            $daysAgo = max(0, floor(($websoccer->getNowAsTimestamp() - (int) $report['created_date']) / 86400));
            if ($daysAgo > 30) {
                continue;
            }
            $decay = max(0, (30 - $daysAgo) / 30);
            $change = (int) $report['new_chemistry'] - (int) $report['old_chemistry'];
            if ($change === 0 && strlen($report['summary_data'])) {
                $summary = json_decode($report['summary_data'], true);
                if (is_array($summary) && isset($summary['chemistry_delta'])) {
                    $change = (int) $summary['chemistry_delta'];
                }
            }
            $weightedChange += $change * $decay;
            $weightSum += $decay;
        }
        $result->free();

        if ($weightSum <= 0) {
            return array('score' => 50, 'detail' => $latestType);
        }

        $averageChange = $weightedChange / $weightSum;
        $score = self::normalizePercent(50 + (int) round($averageChange * 8));
        return array('score' => $score, 'detail' => $latestType);
    }

    private static function computeStaffScore(WebSoccer $websoccer, DbConnection $db, $teamId) {
        if (!class_exists('ClubStaffDataService')) {
            return array('score' => 50, 'detail' => '');
        }
        return ClubStaffDataService::getChemistryStaffScore($websoccer, $db, $teamId);
    }

    private static function computeTrainingCampScore(WebSoccer $websoccer, DbConnection $db, $teamId) {
        if (!self::trainingCampReportTableReady($websoccer, $db)) {
            return array('score' => 50, 'detail' => '');
        }

        $result = $db->querySelect(
            'camp_name, completed_date, effect_chemistry_total',
            $websoccer->getConfig('db_prefix') . '_trainingslager_report',
            'team_id = %d ORDER BY completed_date DESC, id DESC',
            (int) $teamId,
            1
        );
        $report = $result->fetch_array();
        $result->free();

        if (!$report) {
            return array('score' => 50, 'detail' => '');
        }

        $daysAgo = max(0, floor(($websoccer->getNowAsTimestamp() - (int) $report['completed_date']) / 86400));
        if ($daysAgo > 60) {
            return array('score' => 50, 'detail' => $report['camp_name']);
        }

        $decay = max(0, (60 - $daysAgo) / 60);
        $change = (int) $report['effect_chemistry_total'];
        $score = self::normalizePercent(50 + (int) round($change * 5 * $decay));
        return array('score' => $score, 'detail' => $report['camp_name']);
    }

    private static function weightedAveragePlayerScore($startingPlayers, $players, $callback) {
        $startScore = self::averagePlayerScore($startingPlayers, $callback);
        $squadScore = self::averagePlayerScore($players, $callback);
        if ($startScore === null && $squadScore === null) {
            return 50;
        }
        if ($startScore === null) {
            return self::normalizePercent($squadScore);
        }
        if ($squadScore === null) {
            return self::normalizePercent($startScore);
        }
        return self::normalizePercent(($startScore * 0.70) + ($squadScore * 0.30));
    }

    private static function averagePlayerScore($players, $callback) {
        if (!count($players)) {
            return null;
        }
        $sum = 0;
        $count = 0;
        foreach ($players as $player) {
            $sum += (float) call_user_func($callback, $player);
            $count++;
        }
        return ($count > 0) ? ($sum / $count) : null;
    }

    private static function getStoredChemistry(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $result = $db->querySelect(
            'team_chemistry, team_chemistry_effect, team_chemistry_updated',
            $websoccer->getConfig('db_prefix') . '_verein',
            'id = %d',
            (int) $teamId,
            1
        );
        $row = $result->fetch_array();
        $result->free();
        return $row ? $row : null;
    }

    private static function insertLog(WebSoccer $websoccer, DbConnection $db, $teamId, $source, $oldScore, $newScore, $effect, $factors, $matchId = 0) {
        if (!self::logTableReady($websoccer, $db)) {
            return;
        }

        $db->queryInsert(
            array(
                'team_id' => (int) $teamId,
                'event_date' => $websoccer->getNowAsTimestamp(),
                'source' => (string) $source,
                'old_score' => self::normalizePercent($oldScore),
                'new_score' => self::normalizePercent($newScore),
                'match_effect' => (int) $effect,
                'match_id' => ((int) $matchId > 0) ? (int) $matchId : '',
                'breakdown_data' => json_encode($factors)
            ),
            $websoccer->getConfig('db_prefix') . '_team_chemistry_log'
        );
    }

    private static function getRecentLog(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $teamId, $limit) {
        if (!self::logTableReady($websoccer, $db)) {
            return array();
        }

        $result = $db->querySelect(
            '*',
            $websoccer->getConfig('db_prefix') . '_team_chemistry_log',
            'team_id = %d ORDER BY event_date DESC, id DESC',
            (int) $teamId,
            (int) $limit
        );

        $log = array();
        while ($row = $result->fetch_array()) {
            $row['change'] = (int) $row['new_score'] - (int) $row['old_score'];
            $row['change_signed'] = self::formatSignedNumber($row['change']);
            $row['effect_signed'] = self::formatSignedNumber((int) $row['match_effect']);
            $row['source_label'] = $i18n->hasMessage('teamchemistry_source_' . $row['source']) ? $i18n->getMessage('teamchemistry_source_' . $row['source']) : $row['source'];
            $log[] = $row;
        }
        $result->free();

        return $log;
    }

    private static function scoreToMatchEffect(WebSoccer $websoccer, $score) {
        $maxEffect = (int) self::getOptionalConfig($websoccer, 'team_chemistry_max_match_effect', 3);
        if ($maxEffect < 0) {
            $maxEffect = 0;
        }
        if ($maxEffect > 5) {
            $maxEffect = 5;
        }
        $effect = (int) round(((self::normalizePercent($score) - 50) / 50) * $maxEffect);
        return min($maxEffect, max(0 - $maxEffect, $effect));
    }

    private static function schemaReady(WebSoccer $websoccer, DbConnection $db) {
        if (self::$_schemaReady !== null) {
            return self::$_schemaReady;
        }
        $table = $websoccer->getConfig('db_prefix') . '_verein';
        $result = $db->executeQuery("SHOW COLUMNS FROM " . $table . " LIKE 'team_chemistry'");
        $row = $result->fetch_array();
        $result->free();
        self::$_schemaReady = ($row) ? TRUE : FALSE;
        return self::$_schemaReady;
    }

    private static function logTableReady(WebSoccer $websoccer, DbConnection $db) {
        if (self::$_logTableReady !== null) {
            return self::$_logTableReady;
        }
        $table = $websoccer->getConfig('db_prefix') . '_team_chemistry_log';
        $result = $db->executeQuery("SHOW TABLES LIKE '" . $table . "'");
        $row = $result->fetch_array();
        $result->free();
        self::$_logTableReady = ($row) ? TRUE : FALSE;
        return self::$_logTableReady;
    }

    private static function trainingCampReportTableReady(WebSoccer $websoccer, DbConnection $db) {
        if (self::$_trainingCampReportTableReady !== null) {
            return self::$_trainingCampReportTableReady;
        }
        $table = $websoccer->getConfig('db_prefix') . '_trainingslager_report';
        $result = $db->executeQuery("SHOW TABLES LIKE '" . $table . "'");
        $row = $result->fetch_array();
        $result->free();
        self::$_trainingCampReportTableReady = ($row) ? TRUE : FALSE;
        return self::$_trainingCampReportTableReady;
    }

    private static function trainingReportTableReady(WebSoccer $websoccer, DbConnection $db) {
        if (self::$_trainingReportTableReady !== null) {
            return self::$_trainingReportTableReady;
        }
        $table = $websoccer->getConfig('db_prefix') . '_training_report';
        $result = $db->executeQuery("SHOW TABLES LIKE '" . $table . "'");
        $row = $result->fetch_array();
        $result->free();
        self::$_trainingReportTableReady = ($row) ? TRUE : FALSE;
        return self::$_trainingReportTableReady;
    }

    private static function getScoreLabelKey($score) {
        $score = self::normalizePercent($score);
        if ($score >= 70) {
            return 'teamchemistry_value_good';
        }
        if ($score <= 40) {
            return 'teamchemistry_value_bad';
        }
        return 'teamchemistry_value_neutral';
    }

    private static function getHintKey($score) {
        $score = self::normalizePercent($score);
        if ($score >= 70) {
            return 'teamchemistry_hint_good';
        }
        if ($score <= 40) {
            return 'teamchemistry_hint_bad';
        }
        return 'teamchemistry_hint_neutral';
    }

    private static function formatPercentDetail($value) {
        return self::normalizePercent($value) . '%';
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
}

?>
