<?php
/******************************************************

  This file is part of OpenWebSoccer-Sim.

******************************************************/

/**
 * Data service for the matchday "What changed?" dashboard.
 */
class WhatChangedDataService {

    const CONFIG_ENABLED = 'what_changed_enabled';

    /**
     * Returns whether the module is enabled. Missing config means enabled.
     */
    public static function isEnabled(WebSoccer $websoccer) {
        return self::getConfigBoolean($websoccer, self::CONFIG_ENABLED, TRUE);
    }

    /**
     * Creates one persistent summary for each human-managed club involved in a completed first-team match.
     */
    public static function processCompletedMatch(MatchCompletedEvent $event) {
        if (!$event->match || !self::isEnabled($event->websoccer)) {
            return;
        }

        if (!in_array($event->match->type, array('Ligaspiel', 'Pokalspiel', 'Freundschaft'))) {
            return;
        }

        self::ensureSchema($event->websoccer, $event->db);

        $teams = array();
        if ($event->match->homeTeam && !$event->match->homeTeam->isNationalTeam) {
            $teams[(int) $event->match->homeTeam->id] = TRUE;
        }
        if ($event->match->guestTeam && !$event->match->guestTeam->isNationalTeam) {
            $teams[(int) $event->match->guestTeam->id] = TRUE;
        }

        foreach (array_keys($teams) as $teamId) {
            $team = self::getTeam($event->websoccer, $event->db, $teamId);
            if (!$team || (int) $team['user_id'] < 1 || $team['status'] !== '1') {
                continue;
            }

            if (self::summaryExists($event->websoccer, $event->db, $teamId, (int) $event->match->id)) {
                continue;
            }

            try {
                self::createSummaryForTeam($event->websoccer, $event->db, $event->i18n, $event->match, $team);
            } catch (Exception $e) {
                // A dashboard summary must never break match simulation.
                continue;
            }
        }
    }

    /**
     * Returns compact data for the office overview block.
     */
    public static function getOfficeBlockData(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $userId, $teamId) {
        $enabled = self::isEnabled($websoccer);
        if (!$enabled || $teamId < 1) {
            return array(
                'enabled' => $enabled,
                'human_team' => FALSE,
                'summary' => null,
                'link' => $websoccer->getInternalUrl('what-changed')
            );
        }

        self::ensureSchema($websoccer, $db);

        $team = self::getTeam($websoccer, $db, $teamId);
        $humanTeam = ($team && (int) $team['user_id'] > 0 && (int) $team['user_id'] === (int) $userId);

        return array(
            'enabled' => $enabled,
            'human_team' => $humanTeam,
            'summary' => ($humanTeam) ? self::getLatestSummary($websoccer, $db, $teamId) : null,
            'link' => $websoccer->getInternalUrl('what-changed')
        );
    }

    /**
     * Returns data for the page.
     */
    public static function getPageData(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $userId, $teamId, $summaryId = 0, $matchId = 0) {
        self::ensureSchema($websoccer, $db);

        $team = self::getTeam($websoccer, $db, $teamId);
        $humanTeam = ($team && (int) $team['user_id'] > 0 && (int) $team['user_id'] === (int) $userId);

        $summary = null;
        if (self::isEnabled($websoccer) && $humanTeam) {
            if ($summaryId > 0) {
                $summary = self::getSummaryById($websoccer, $db, $teamId, $summaryId);
            } elseif ($matchId > 0) {
                $summary = self::getSummaryByMatchId($websoccer, $db, $teamId, $matchId);
            } else {
                $summary = self::getLatestSummary($websoccer, $db, $teamId);
            }
        }

        return array(
            'enabled' => self::isEnabled($websoccer),
            'human_team' => $humanTeam,
            'team' => $team,
            'summary' => $summary,
            'recent_summaries' => ($humanTeam) ? self::getRecentSummaries($websoccer, $db, $teamId, 10) : array(),
            'currency' => $websoccer->getConfig('game_currency')
        );
    }

    private static function createSummaryForTeam(WebSoccer $websoccer, DbConnection $db, I18n $i18n, SimulationMatch $match, $team) {
        $teamId = (int) $team['id'];
        $userId = (int) $team['user_id'];
        $matchId = (int) $match->id;
        $matchRow = self::getMatchRow($websoccer, $db, $matchId);
        if (!$matchRow) {
            return;
        }

        $previousSummary = self::getPreviousSummary($websoccer, $db, $teamId, $matchId, (int) $matchRow['datum']);
        $previousMatch = self::getPreviousMatch($websoccer, $db, $teamId, $matchId, (int) $matchRow['datum']);
        $sinceTimestamp = ($previousSummary && (int) $previousSummary['created_date'] > 0)
            ? (int) $previousSummary['created_date']
            : (($previousMatch && (int) $previousMatch['datum'] > 0) ? (int) $previousMatch['datum'] : 0);

        if (class_exists('ManagerMissionsDataService')) {
            try {
                ManagerMissionsDataService::updateMissionsForCurrentSeason($websoccer, $db, $i18n, $userId, $teamId);
            } catch (Exception $e) {
                // Optional integration. Ignore if mission schema is not installed.
            }
        }

        $freshTeam = self::getTeam($websoccer, $db, $teamId);
        if ($freshTeam) {
            $team = $freshTeam;
        }

        $summaryData = array(
            'match' => self::buildMatchSection($websoccer, $matchRow, $teamId),
            'trait_highlights' => self::buildTraitHighlightsSection($websoccer, $db, $i18n, $matchId, $teamId),
            'previous_match' => self::buildPreviousMatchSection($previousMatch),
            'budget' => self::buildBudgetSection($websoccer, $db, $team, $sinceTimestamp),
            'training' => self::buildTrainingSection($websoccer, $db, $matchId, $teamId),
            'scouting' => self::buildScoutingSection($websoccer, $db, $teamId, $sinceTimestamp),
            'fan_mood' => self::buildFanMoodSection($websoccer, $db, $matchId, $team),
            'chemistry' => self::buildChemistrySection($websoccer, $db, $matchId, $team),
            'tactical_style' => self::buildTacticalStyleSection($match, $teamId),
            'board' => self::buildBoardSection($websoccer, $db, $matchId, $team, $previousSummary),
            'injuries' => self::buildInjuriesSection($websoccer, $db, $matchId, $teamId),
            'messages_news' => self::buildMessagesNewsSection($websoccer, $db, $userId, $sinceTimestamp),
            'missions' => self::buildMissionsSection($websoccer, $db, $i18n, $userId, $teamId)
        );

        $title = $summaryData['match']['home_name'] . ' ' . $summaryData['match']['home_goals'] . ':' . $summaryData['match']['guest_goals'] . ' ' . $summaryData['match']['guest_name'];
        $created = (int) $websoccer->getNowAsTimestamp();

        $db->queryInsert(
            array(
                'user_id' => $userId,
                'team_id' => $teamId,
                'match_id' => $matchId,
                'previous_match_id' => ($previousMatch) ? (int) $previousMatch['id'] : 0,
                'match_date' => (int) $matchRow['datum'],
                'matchday' => (isset($matchRow['spieltag'])) ? (int) $matchRow['spieltag'] : 0,
                'created_date' => $created,
                'summary_title' => $title,
                'summary_data' => self::jsonEncode($summaryData),
                'news_id' => 0
            ),
            $websoccer->getConfig('db_prefix') . '_what_changed'
        );

        $summaryId = (int) $db->getLastInsertedId();
        if ($summaryId < 1) {
            return;
        }

        self::createNotification($websoccer, $db, $userId, $teamId, $summaryId, $title);
        $newsId = self::createNews($websoccer, $db, $i18n, $team['name'], $summaryId, $title);
        if ($newsId > 0) {
            $db->queryUpdate(
                array('news_id' => $newsId),
                $websoccer->getConfig('db_prefix') . '_what_changed',
                'id = %d',
                $summaryId
            );
        }
    }

    private static function buildMatchSection(WebSoccer $websoccer, $match, $teamId) {
        $isHome = ((int) $match['home_verein'] === (int) $teamId);
        $ownGoals = $isHome ? (int) $match['home_tore'] : (int) $match['gast_tore'];
        $oppGoals = $isHome ? (int) $match['gast_tore'] : (int) $match['home_tore'];
        $outcome = 'draw';
        if ($ownGoals > $oppGoals) {
            $outcome = 'win';
        } elseif ($ownGoals < $oppGoals) {
            $outcome = 'loss';
        }

        return array(
            'id' => (int) $match['id'],
            'date' => (int) $match['datum'],
            'type' => $match['spieltyp'],
            'matchday' => (int) $match['spieltag'],
            'is_home' => $isHome,
            'home_team_id' => (int) $match['home_verein'],
            'guest_team_id' => (int) $match['gast_verein'],
            'home_name' => $match['home_name'],
            'guest_name' => $match['guest_name'],
            'home_goals' => (int) $match['home_tore'],
            'guest_goals' => (int) $match['gast_tore'],
            'outcome' => $outcome,
            'link' => $websoccer->getInternalUrl('match', 'id=' . (int) $match['id'])
        );
    }


    private static function buildTraitHighlightsSection(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $matchId, $teamId) {
        if (!class_exists('PlayerTraitsDataService')) {
            return array('available' => FALSE, 'count' => 0, 'items' => array());
        }

        $items = PlayerTraitsDataService::getMatchTraitHighlights($websoccer, $db, $i18n, (int) $matchId, (int) $teamId, 3);
        foreach ($items as $index => $item) {
            $items[$index]['player_link'] = $websoccer->getInternalUrl('player', 'id=' . (int) $item['player_id']);
        }

        return array(
            'available' => count($items) > 0,
            'count' => count($items),
            'items' => $items
        );
    }

    private static function buildPreviousMatchSection($previousMatch) {
        if (!$previousMatch) {
            return array('available' => FALSE);
        }

        return array(
            'available' => TRUE,
            'id' => (int) $previousMatch['id'],
            'date' => (int) $previousMatch['datum'],
            'home_name' => $previousMatch['home_name'],
            'guest_name' => $previousMatch['guest_name'],
            'home_goals' => (int) $previousMatch['home_tore'],
            'guest_goals' => (int) $previousMatch['gast_tore']
        );
    }

    private static function buildBudgetSection(WebSoccer $websoccer, DbConnection $db, $team, $sinceTimestamp) {
        $prefix = $websoccer->getConfig('db_prefix');
        $budgetAfter = (int) $team['finanz_budget'];
        $entries = array();
        $sum = 0;

        try {
            $where = 'verein_id = %d';
            $params = array((int) $team['id']);
            if ($sinceTimestamp > 0) {
                $where .= ' AND datum > %d';
                $params[] = (int) $sinceTimestamp;
            }
            $where .= ' ORDER BY datum DESC, id DESC';
            $result = $db->querySelect('id, absender, betrag, datum, verwendung', $prefix . '_konto', $where, $params, 30);
            while ($row = $result->fetch_array()) {
                $amount = (int) $row['betrag'];
                $sum += $amount;
                $entries[] = array(
                    'id' => (int) $row['id'],
                    'sender' => $row['absender'],
                    'amount' => $amount,
                    'date' => (int) $row['datum'],
                    'subject' => $row['verwendung'],
                    'amount_signed' => self::formatSigned($amount)
                );
            }
            $result->free();
        } catch (Exception $e) {
            $entries = array();
            $sum = 0;
        }

        return array(
            'budget_before' => $budgetAfter - $sum,
            'budget_after' => $budgetAfter,
            'change' => $sum,
            'change_signed' => self::formatSigned($sum),
            'entries' => $entries,
            'entry_count' => count($entries)
        );
    }

    private static function buildTrainingSection(WebSoccer $websoccer, DbConnection $db, $matchId, $teamId) {
        $prefix = $websoccer->getConfig('db_prefix');
        try {
            $result = $db->querySelect(
                'R.*, T.name AS trainer_name, P.vorname, P.nachname, P.kunstname',
                $prefix . '_training_report AS R LEFT JOIN ' . $prefix . '_trainer AS T ON T.id = R.trainer_id LEFT JOIN ' . $prefix . '_spieler AS P ON P.id = R.best_player_id',
                'R.team_id = %d AND R.match_id = %d ORDER BY R.created_date DESC, R.id DESC',
                array((int) $teamId, (int) $matchId),
                1
            );
            $report = $result->fetch_array();
            $result->free();
            if (!$report) {
                return array('available' => FALSE);
            }

            $bestPlayer = self::formatPlayerName($report);
            $topEffects = self::getTrainingTopEffects($websoccer, $db, (int) $report['id']);
            return array(
                'available' => TRUE,
                'id' => (int) $report['id'],
                'training_type' => $report['training_type'],
                'intensity' => (int) $report['intensity'],
                'trainer_name' => $report['trainer_name'],
                'player_count' => (int) $report['player_count'],
                'best_player' => $bestPlayer,
                'injuries' => (int) $report['injuries'],
                'old_chemistry' => (int) $report['old_chemistry'],
                'new_chemistry' => (int) $report['new_chemistry'],
                'chemistry_change' => (int) $report['new_chemistry'] - (int) $report['old_chemistry'],
                'top_effects' => $topEffects
            );
        } catch (Exception $e) {
            return array('available' => FALSE);
        }
    }

    private static function getTrainingTopEffects(WebSoccer $websoccer, DbConnection $db, $reportId) {
        $items = array();
        try {
            $prefix = $websoccer->getConfig('db_prefix');
            $result = $db->querySelect(
                'RP.total_effect, P.id AS player_id, P.vorname, P.nachname, P.kunstname',
                $prefix . '_training_report_player AS RP INNER JOIN ' . $prefix . '_spieler AS P ON P.id = RP.player_id',
                'RP.report_id = %d ORDER BY RP.total_effect DESC, RP.id ASC',
                (int) $reportId,
                5
            );
            while ($row = $result->fetch_array()) {
                $items[] = array(
                    'player_id' => (int) $row['player_id'],
                    'name' => self::formatPlayerName($row),
                    'effect' => (float) $row['total_effect']
                );
            }
            $result->free();
        } catch (Exception $e) {
            return array();
        }
        return $items;
    }

    private static function buildScoutingSection(WebSoccer $websoccer, DbConnection $db, $teamId, $sinceTimestamp) {
        $prefix = $websoccer->getConfig('db_prefix');
        $items = array();
        try {
            $where = "team_id = %d AND status = 'open'";
            $params = array((int) $teamId);
            if ($sinceTimestamp > 0) {
                $where .= ' AND created_date > %d';
                $params[] = (int) $sinceTimestamp;
            }
            $where .= ' ORDER BY created_date DESC, id DESC';
            $result = $db->querySelect(
                'id, firstname, lastname, age, nation, position, position_main, reported_strength, reported_potential, transfer_fee, salary, created_date, expires_date, expires_after_matches',
                $prefix . '_scouting_proposal',
                $where,
                $params,
                10
            );
            while ($row = $result->fetch_array()) {
                $items[] = array(
                    'id' => (int) $row['id'],
                    'name' => trim($row['firstname'] . ' ' . $row['lastname']),
                    'age' => (int) $row['age'],
                    'nation' => $row['nation'],
                    'position' => $row['position'],
                    'position_main' => $row['position_main'],
                    'reported_strength' => $row['reported_strength'],
                    'reported_potential' => $row['reported_potential'],
                    'transfer_fee' => (int) $row['transfer_fee'],
                    'salary' => (int) $row['salary'],
                    'created_date' => (int) $row['created_date'],
                    'expires_date' => (int) $row['expires_date'],
                    'expires_after_matches' => (int) $row['expires_after_matches']
                );
            }
            $result->free();
        } catch (Exception $e) {
            $items = array();
        }

        return array('count' => count($items), 'items' => $items);
    }

    private static function buildFanMoodSection(WebSoccer $websoccer, DbConnection $db, $matchId, $team) {
        $currentMood = isset($team['fan_mood']) ? (int) $team['fan_mood'] : (isset($team['fanbeliebtheit']) ? (int) $team['fanbeliebtheit'] : 0);
        $currentPressure = isset($team['media_pressure']) ? (int) $team['media_pressure'] : 0;
        try {
            $result = $db->querySelect(
                '*',
                $websoccer->getConfig('db_prefix') . '_fan_mood_log',
                'team_id = %d AND match_id = %d ORDER BY id DESC',
                array((int) $team['id'], (int) $matchId),
                1
            );
            $log = $result->fetch_array();
            $result->free();
            if ($log) {
                return array(
                    'available' => TRUE,
                    'old_mood' => (int) $log['old_mood'],
                    'new_mood' => (int) $log['new_mood'],
                    'mood_change' => (int) $log['mood_change'],
                    'mood_change_signed' => self::formatSigned((int) $log['mood_change']),
                    'old_pressure' => (int) $log['old_pressure'],
                    'new_pressure' => (int) $log['new_pressure'],
                    'pressure_change' => (int) $log['pressure_change'],
                    'pressure_change_signed' => self::formatSigned((int) $log['pressure_change']),
                    'reason_key' => $log['reason_key']
                );
            }
        } catch (Exception $e) {
            // continue with fallback
        }

        return array(
            'available' => FALSE,
            'old_mood' => null,
            'new_mood' => $currentMood,
            'mood_change' => 0,
            'mood_change_signed' => self::formatSigned(0),
            'old_pressure' => null,
            'new_pressure' => $currentPressure,
            'pressure_change' => 0,
            'pressure_change_signed' => self::formatSigned(0),
            'reason_key' => ''
        );
    }

    private static function buildChemistrySection(WebSoccer $websoccer, DbConnection $db, $matchId, $team) {
        $currentChemistry = isset($team['team_chemistry']) ? (int) $team['team_chemistry'] : 0;
        $currentEffect = isset($team['team_chemistry_effect']) ? (int) $team['team_chemistry_effect'] : 0;
        try {
            $result = $db->querySelect(
                '*',
                $websoccer->getConfig('db_prefix') . '_team_chemistry_log',
                'team_id = %d AND match_id = %d ORDER BY id DESC',
                array((int) $team['id'], (int) $matchId),
                1
            );
            $log = $result->fetch_array();
            $result->free();
            if ($log) {
                $old = (int) $log['old_score'];
                $new = (int) $log['new_score'];
                return array(
                    'available' => TRUE,
                    'old_score' => $old,
                    'new_score' => $new,
                    'change' => $new - $old,
                    'change_signed' => self::formatSigned($new - $old),
                    'match_effect' => (int) $log['match_effect'],
                    'source' => $log['source']
                );
            }
        } catch (Exception $e) {
            // continue with fallback
        }

        return array(
            'available' => FALSE,
            'old_score' => null,
            'new_score' => $currentChemistry,
            'change' => 0,
            'change_signed' => self::formatSigned(0),
            'match_effect' => $currentEffect,
            'source' => ''
        );
    }

    private static function buildTacticalStyleSection(SimulationMatch $match, $teamId) {
        $team = null;
        if ($match->homeTeam && (int) $match->homeTeam->id === (int) $teamId) {
            $team = $match->homeTeam;
        }
        if (!$team && $match->guestTeam && (int) $match->guestTeam->id === (int) $teamId) {
            $team = $match->guestTeam;
        }
        if (!$team || !isset($team->tacticalStyle) || !strlen($team->tacticalStyle)) {
            return array('available' => FALSE);
        }

        return array(
            'available' => TRUE,
            'style' => $team->tacticalStyle,
            'fit' => (int) $team->tacticalStyleFit,
            'effect' => (int) $team->tacticalStyleEffect
        );
    }

    private static function buildBoardSection(WebSoccer $websoccer, DbConnection $db, $matchId, $team, $previousSummary) {
        $current = isset($team['board_satisfaction']) ? (int) $team['board_satisfaction'] : 0;
        try {
            $result = $db->querySelect(
                'old_board_satisfaction, new_board_satisfaction, board_change, reason_key',
                $websoccer->getConfig('db_prefix') . '_fan_mood_log',
                'team_id = %d AND match_id = %d AND board_change <> 0 ORDER BY id DESC',
                array((int) $team['id'], (int) $matchId),
                1
            );
            $log = $result->fetch_array();
            $result->free();
            if ($log) {
                return array(
                    'available' => TRUE,
                    'old' => (int) $log['old_board_satisfaction'],
                    'new' => (int) $log['new_board_satisfaction'],
                    'change' => (int) $log['board_change'],
                    'change_signed' => self::formatSigned((int) $log['board_change']),
                    'reason_key' => $log['reason_key']
                );
            }
        } catch (Exception $e) {
            // continue with fallback
        }

        $old = null;
        if ($previousSummary) {
            $previousData = self::decodeSummaryData($previousSummary['summary_data']);
            if (isset($previousData['board']['new'])) {
                $old = (int) $previousData['board']['new'];
            }
        }
        $change = ($old === null) ? 0 : ($current - $old);

        return array(
            'available' => ($old !== null && $change !== 0),
            'old' => $old,
            'new' => $current,
            'change' => $change,
            'change_signed' => self::formatSigned($change),
            'reason_key' => ''
        );
    }

    private static function buildInjuriesSection(WebSoccer $websoccer, DbConnection $db, $matchId, $teamId) {
        $items = array();
        try {
            $prefix = $websoccer->getConfig('db_prefix');
            $sql = 'SELECT SB.spieler_id, SB.verletzt AS match_injured, SB.gesperrt AS match_suspended, '
                . 'SB.karte_rot, SB.karte_gelb_rot, P.vorname, P.nachname, P.kunstname, P.verletzt AS current_injury, P.gesperrt AS current_suspension '
                . 'FROM ' . $prefix . '_spiel_berechnung AS SB '
                . 'INNER JOIN ' . $prefix . '_spieler AS P ON P.id = SB.spieler_id '
                . 'WHERE SB.spiel_id = ' . (int) $matchId . ' AND SB.team_id = ' . (int) $teamId . ' '
                . 'AND (SB.verletzt > 0 OR SB.gesperrt > 0 OR SB.karte_rot > 0 OR SB.karte_gelb_rot > 0 OR P.verletzt > 0 OR P.gesperrt > 0) '
                . 'ORDER BY P.nachname ASC, P.vorname ASC';
            $result = $db->executeQuery($sql);
            while ($row = $result->fetch_array()) {
                $items[] = array(
                    'player_id' => (int) $row['spieler_id'],
                    'name' => self::formatPlayerName($row),
                    'match_injured' => (int) $row['match_injured'],
                    'match_suspended' => (int) $row['match_suspended'],
                    'red_card' => ((int) $row['karte_rot'] > 0 || (int) $row['karte_gelb_rot'] > 0),
                    'current_injury' => (int) $row['current_injury'],
                    'current_suspension' => (int) $row['current_suspension']
                );
            }
            $result->free();
        } catch (Exception $e) {
            $items = array();
        }

        return array('count' => count($items), 'items' => $items);
    }

    private static function buildMessagesNewsSection(WebSoccer $websoccer, DbConnection $db, $userId, $sinceTimestamp) {
        $prefix = $websoccer->getConfig('db_prefix');
        $messages = array();
        $news = array();

        try {
            $where = 'empfaenger_id = %d';
            $params = array((int) $userId);
            if ($sinceTimestamp > 0) {
                $where .= ' AND datum > %d';
                $params[] = (int) $sinceTimestamp;
            }
            $where .= ' ORDER BY datum DESC, id DESC';
            $result = $db->querySelect('id, absender_name, datum, betreff, gelesen', $prefix . '_briefe', $where, $params, 5);
            while ($row = $result->fetch_array()) {
                $messages[] = array(
                    'id' => (int) $row['id'],
                    'sender' => $row['absender_name'],
                    'date' => (int) $row['datum'],
                    'subject' => $row['betreff'],
                    'read' => $row['gelesen']
                );
            }
            $result->free();
        } catch (Exception $e) {
            $messages = array();
        }

        try {
            $where = "status = '1'";
            $params = array();
            if ($sinceTimestamp > 0) {
                $where .= ' AND datum > %d';
                $params[] = (int) $sinceTimestamp;
            }
            $where .= ' ORDER BY datum DESC, id DESC';
            $result = $db->querySelect('id, datum, titel, linkurl1, linktext1', $prefix . '_news', $where, $params, 5);
            while ($row = $result->fetch_array()) {
                $news[] = array(
                    'id' => (int) $row['id'],
                    'date' => (int) $row['datum'],
                    'title' => $row['titel'],
                    'link' => $row['linkurl1'],
                    'link_text' => $row['linktext1']
                );
            }
            $result->free();
        } catch (Exception $e) {
            $news = array();
        }

        return array(
            'message_count' => count($messages),
            'news_count' => count($news),
            'messages' => $messages,
            'news' => $news
        );
    }

    private static function buildMissionsSection(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $userId, $teamId) {
        if (!class_exists('ManagerMissionsDataService')) {
            return array('available' => FALSE, 'summary' => array(), 'items' => array());
        }

        try {
            $pageData = ManagerMissionsDataService::getMissionPageData($websoccer, $db, $i18n, $userId, $teamId);
            $items = array();
            foreach ($pageData['missions'] as $mission) {
                $items[] = array(
                    'title' => $mission['title'],
                    'status_label' => $mission['status_label'],
                    'status_class' => $mission['status_class'],
                    'progress_percent' => (int) $mission['progress_percent'],
                    'progress_label' => $mission['progress_label'],
                    'target_label' => $mission['target_label']
                );
            }
            return array(
                'available' => TRUE,
                'summary' => $pageData['summary'],
                'items' => $items
            );
        } catch (Exception $e) {
            return array('available' => FALSE, 'summary' => array(), 'items' => array());
        }
    }

    private static function createNotification(WebSoccer $websoccer, DbConnection $db, $userId, $teamId, $summaryId, $title) {
        if (!class_exists('NotificationsDataService')) {
            return;
        }

        NotificationsDataService::createNotification(
            $websoccer,
            $db,
            (int) $userId,
            'whatchanged_notification',
            array('match' => $title),
            'what-changed',
            'what-changed',
            'id=' . (int) $summaryId,
            (int) $teamId
        );
    }

    private static function createNews(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $teamName, $summaryId, $matchTitle) {
        $title = self::message($i18n, 'whatchanged_news_title', array('team' => $teamName));
        $message = self::message($i18n, 'whatchanged_news_message', array('team' => $teamName, 'match' => $matchTitle));
        $linkText = self::message($i18n, 'whatchanged_news_link', array());

        $db->queryInsert(
            array(
                'datum' => $websoccer->getNowAsTimestamp(),
                'autor_id' => 1,
                'titel' => $title,
                'nachricht' => $message,
                'linktext1' => $linkText,
                'linkurl1' => $websoccer->getInternalUrl('what-changed', 'id=' . (int) $summaryId),
                'c_br' => '1',
                'c_links' => '1',
                'c_smilies' => '0',
                'status' => '1'
            ),
            $websoccer->getConfig('db_prefix') . '_news'
        );

        return (int) $db->getLastInsertedId();
    }

    private static function getMatchRow(WebSoccer $websoccer, DbConnection $db, $matchId) {
        $prefix = $websoccer->getConfig('db_prefix');
        $result = $db->querySelect(
            'S.*, H.name AS home_name, G.name AS guest_name',
            $prefix . '_spiel AS S INNER JOIN ' . $prefix . '_verein AS H ON H.id = S.home_verein INNER JOIN ' . $prefix . '_verein AS G ON G.id = S.gast_verein',
            'S.id = %d',
            (int) $matchId,
            1
        );
        $row = $result->fetch_array();
        $result->free();
        return $row ? $row : null;
    }

    private static function getPreviousMatch(WebSoccer $websoccer, DbConnection $db, $teamId, $matchId, $matchDate) {
        $prefix = $websoccer->getConfig('db_prefix');
        $result = $db->querySelect(
            'S.id, S.datum, S.home_tore, S.gast_tore, H.name AS home_name, G.name AS guest_name',
            $prefix . '_spiel AS S INNER JOIN ' . $prefix . '_verein AS H ON H.id = S.home_verein INNER JOIN ' . $prefix . '_verein AS G ON G.id = S.gast_verein',
            "S.berechnet = '1' AND S.id <> %d AND (S.home_verein = %d OR S.gast_verein = %d) AND (S.datum < %d OR (S.datum = %d AND S.id < %d)) ORDER BY S.datum DESC, S.id DESC",
            array((int) $matchId, (int) $teamId, (int) $teamId, (int) $matchDate, (int) $matchDate, (int) $matchId),
            1
        );
        $row = $result->fetch_array();
        $result->free();
        return $row ? $row : null;
    }

    private static function getTeam(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $result = $db->querySelect('*', $websoccer->getConfig('db_prefix') . '_verein', 'id = %d', (int) $teamId, 1);
        $team = $result->fetch_array();
        $result->free();
        return $team ? $team : null;
    }

    private static function summaryExists(WebSoccer $websoccer, DbConnection $db, $teamId, $matchId) {
        $result = $db->querySelect('id', $websoccer->getConfig('db_prefix') . '_what_changed', 'team_id = %d AND match_id = %d', array((int) $teamId, (int) $matchId), 1);
        $row = $result->fetch_array();
        $result->free();
        return $row ? TRUE : FALSE;
    }

    private static function getPreviousSummary(WebSoccer $websoccer, DbConnection $db, $teamId, $matchId, $matchDate) {
        $result = $db->querySelect('*', $websoccer->getConfig('db_prefix') . '_what_changed', 'team_id = %d AND match_id <> %d AND (match_date < %d OR (match_date = %d AND match_id < %d)) ORDER BY match_date DESC, id DESC', array((int) $teamId, (int) $matchId, (int) $matchDate, (int) $matchDate, (int) $matchId), 1);
        $row = $result->fetch_array();
        $result->free();
        return $row ? $row : null;
    }

    private static function getSummaryById(WebSoccer $websoccer, DbConnection $db, $teamId, $summaryId) {
        $result = $db->querySelect('*', $websoccer->getConfig('db_prefix') . '_what_changed', 'team_id = %d AND id = %d', array((int) $teamId, (int) $summaryId), 1);
        $row = $result->fetch_array();
        $result->free();
        return self::prepareSummaryRecord($websoccer, $row);
    }

    private static function getSummaryByMatchId(WebSoccer $websoccer, DbConnection $db, $teamId, $matchId) {
        $result = $db->querySelect('*', $websoccer->getConfig('db_prefix') . '_what_changed', 'team_id = %d AND match_id = %d', array((int) $teamId, (int) $matchId), 1);
        $row = $result->fetch_array();
        $result->free();
        return self::prepareSummaryRecord($websoccer, $row);
    }

    private static function getLatestSummary(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $result = $db->querySelect('*', $websoccer->getConfig('db_prefix') . '_what_changed', 'team_id = %d ORDER BY match_date DESC, id DESC', (int) $teamId, 1);
        $row = $result->fetch_array();
        $result->free();
        return self::prepareSummaryRecord($websoccer, $row);
    }

    private static function getRecentSummaries(WebSoccer $websoccer, DbConnection $db, $teamId, $limit) {
        $items = array();
        $result = $db->querySelect('id, match_id, match_date, matchday, summary_title', $websoccer->getConfig('db_prefix') . '_what_changed', 'team_id = %d ORDER BY match_date DESC, id DESC', (int) $teamId, (int) $limit);
        while ($row = $result->fetch_array()) {
            $row['id'] = (int) $row['id'];
            $row['match_id'] = (int) $row['match_id'];
            $row['match_date'] = (int) $row['match_date'];
            $row['matchday'] = (int) $row['matchday'];
            $row['link'] = $websoccer->getInternalUrl('what-changed', 'id=' . (int) $row['id']);
            $items[] = $row;
        }
        $result->free();
        return $items;
    }

    private static function prepareSummaryRecord(WebSoccer $websoccer, $row) {
        if (!$row) {
            return null;
        }
        $row['id'] = (int) $row['id'];
        $row['user_id'] = (int) $row['user_id'];
        $row['team_id'] = (int) $row['team_id'];
        $row['match_id'] = (int) $row['match_id'];
        $row['previous_match_id'] = (int) $row['previous_match_id'];
        $row['match_date'] = (int) $row['match_date'];
        $row['matchday'] = (int) $row['matchday'];
        $row['created_date'] = (int) $row['created_date'];
        $row['news_id'] = (int) $row['news_id'];
        $row['data'] = self::decodeSummaryData($row['summary_data']);
        $row['link'] = $websoccer->getInternalUrl('what-changed', 'id=' . (int) $row['id']);
        return $row;
    }

    private static function decodeSummaryData($json) {
        $data = json_decode($json, TRUE);
        return is_array($data) ? $data : array();
    }

    private static function ensureSchema(WebSoccer $websoccer, DbConnection $db) {
        $table = $websoccer->getConfig('db_prefix') . '_what_changed';
        $sql = "CREATE TABLE IF NOT EXISTS `" . $table . "` ("
            . "`id` int(10) NOT NULL AUTO_INCREMENT,"
            . "`user_id` int(10) NOT NULL,"
            . "`team_id` int(10) NOT NULL,"
            . "`match_id` int(10) NOT NULL,"
            . "`previous_match_id` int(10) NOT NULL DEFAULT 0,"
            . "`match_date` int(11) NOT NULL DEFAULT 0,"
            . "`matchday` tinyint(3) NOT NULL DEFAULT 0,"
            . "`created_date` int(11) NOT NULL DEFAULT 0,"
            . "`summary_title` varchar(128) NOT NULL,"
            . "`summary_data` mediumtext NOT NULL,"
            . "`news_id` int(10) NOT NULL DEFAULT 0,"
            . "PRIMARY KEY (`id`),"
            . "UNIQUE KEY `uniq_what_changed_team_match` (`team_id`,`match_id`),"
            . "KEY `idx_what_changed_user_team` (`user_id`,`team_id`),"
            . "KEY `idx_what_changed_match_date` (`team_id`,`match_date`)"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
        $db->executeQuery($sql);
    }

    private static function getConfigBoolean(WebSoccer $websoccer, $key, $default) {
        try {
            $value = $websoccer->getConfig($key);
        } catch (Exception $e) {
            return $default;
        }

        if ($value === null || $value === '') {
            return $default;
        }

        return ($value === TRUE || $value === 1 || $value === '1' || $value === 'true');
    }

    private static function message(I18n $i18n, $key, $placeholders) {
        global $msg;

        if (!$i18n->hasMessage($key) && defined('CONFIGCACHE_MESSAGES')) {
            $messagesFile = sprintf(CONFIGCACHE_MESSAGES, $i18n->getCurrentLanguage());
            if (file_exists($messagesFile)) {
                include_once($messagesFile);
            }
        }

        $fallbacks = array(
            'whatchanged_news_title' => 'Was hat sich bei {team} verändert?',
            'whatchanged_news_message' => 'Nach dem Spiel {match} gibt es eine neue Zusammenfassung für {team}: Ergebnis, Budget, Training, Scouting, Stimmung, Teamchemie, taktische Identität, Vorstand, Verletzungen und Saisonziele wurden aktualisiert.',
            'whatchanged_news_link' => 'Zur Zusammenfassung'
        );

        if ($i18n->hasMessage($key)) {
            $message = $i18n->getMessage($key);
        } elseif (isset($fallbacks[$key])) {
            $message = $fallbacks[$key];
        } else {
            $message = $key;
        }

        foreach ($placeholders as $name => $value) {
            $message = str_replace('{' . $name . '}', $value, $message);
        }
        return $message;
    }

    private static function jsonEncode($data) {
        if (defined('JSON_UNESCAPED_UNICODE')) {
            return json_encode($data, JSON_UNESCAPED_UNICODE);
        }
        return json_encode($data);
    }

    private static function formatSigned($number) {
        $number = (int) $number;
        return ($number > 0 ? '+' : '') . $number;
    }

    private static function formatPlayerName($row) {
        if (isset($row['kunstname']) && strlen(trim($row['kunstname']))) {
            return trim($row['kunstname']);
        }
        return trim((isset($row['vorname']) ? $row['vorname'] : '') . ' ' . (isset($row['nachname']) ? $row['nachname'] : ''));
    }
}

?>