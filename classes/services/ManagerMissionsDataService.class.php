<?php
/******************************************************

  This file is part of OpenWebSoccer-Sim.

  OpenWebSoccer-Sim is free software: you can redistribute it
  and/or modify it under the terms of the
  GNU Lesser General Public License as published by the Free Software Foundation,
  either version 3 of the License, or any later version.

******************************************************/

/**
 * Data service for manager missions / board objectives.
 */
class ManagerMissionsDataService {

    const STATUS_OPEN = 'open';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';
    const STATUS_CANCELLED = 'cancelled';

    const TYPE_LEAGUE_RANK = 'league_rank';
    const TYPE_HIGHSCORE = 'highscore';
    const TYPE_SALARY_REDUCE = 'salary_reduce';
    const TYPE_YOUTH_PROMOTION_PLAYED = 'youth_promotion_played';
    const TYPE_CUP_ROUND = 'cup_round';
    const TYPE_BOARD_SATISFACTION = 'board_satisfaction';

    /**
     * Returns whether the module is enabled. Missing config means enabled, so older installations stay usable.
     */
    public static function isEnabled(WebSoccer $websoccer) {
        return self::getConfigBoolean($websoccer, 'manager_missions_enabled', TRUE);
    }

    /**
     * Ensures that the currently active season has all six missions for this user/team.
     *
     * @return int active season ID, or 0 if no active season exists.
     */
    public static function ensureMissionsForCurrentSeason(WebSoccer $websoccer, DbConnection $db, $userId, $teamId) {
        if (!self::isEnabled($websoccer)) {
            return 0;
        }

        $team = self::getTeam($websoccer, $db, $teamId);
        if (!$team || (int) $team['user_id'] < 1 || (int) $team['user_id'] !== (int) $userId) {
            return 0;
        }

        $season = self::getActiveSeasonByLeagueId($websoccer, $db, (int) $team['liga_id']);
        if (!$season) {
            return 0;
        }

        self::createMissingMissions($websoccer, $db, $userId, $teamId, (int) $season['id'], $team);
        return (int) $season['id'];
    }

    /**
     * Ensures that a specific season has all missions for this user/team.
     *
     * This is used by season completion before teams are moved to another league
     * and before seasonal club statistics are reset.
     *
     * @return int season ID, or 0 if missions cannot be created for this team/season.
     */
    public static function ensureMissionsForSeason(WebSoccer $websoccer, DbConnection $db, $userId, $teamId, $seasonId) {
        if (!self::isEnabled($websoccer)) {
            return 0;
        }

        $team = self::getTeam($websoccer, $db, $teamId);
        if (!$team || (int) $team['user_id'] < 1 || (int) $team['user_id'] !== (int) $userId) {
            return 0;
        }

        $season = self::getSeasonById($websoccer, $db, $seasonId);
        if (!$season) {
            return 0;
        }

        self::createMissingMissions($websoccer, $db, $userId, $teamId, (int) $season['id'], $team);
        return (int) $season['id'];
    }

    /**
     * Updates all current-season missions and returns newly completed missions.
     */
    public static function updateMissionsForCurrentSeason(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $userId, $teamId) {
        $seasonId = self::ensureMissionsForCurrentSeason($websoccer, $db, $userId, $teamId);
        if ($seasonId < 1) {
            return array();
        }

        return self::updateMissionsForSeason($websoccer, $db, $i18n, $userId, $teamId, $seasonId);
    }

    /**
     * Returns all page data for the current manager/team.
     */
    public static function getMissionPageData(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $userId, $teamId) {
        $seasonId = self::ensureMissionsForCurrentSeason($websoccer, $db, $userId, $teamId);

        if ($seasonId > 0) {
            self::updateMissionsForSeason($websoccer, $db, $i18n, $userId, $teamId, $seasonId);
        }

        $team = self::getTeam($websoccer, $db, $teamId);
        $season = ($seasonId > 0) ? self::getSeasonById($websoccer, $db, $seasonId) : null;
        $missions = ($seasonId > 0) ? self::getMissions($websoccer, $db, $userId, $teamId, $seasonId) : array();

        $missionViews = array();
        $summary = array('completed' => 0, 'open' => 0, 'failed' => 0, 'cancelled' => 0, 'total' => 0);
        foreach ($missions as $mission) {
            $view = self::buildMissionView($websoccer, $db, $i18n, $mission, $team, $seasonId);
            $missionViews[] = $view;
            $summary['total']++;
            if (isset($summary[$mission['status']])) {
                $summary[$mission['status']]++;
            }
        }

        return array(
            'team_id' => $teamId,
            'team_name' => ($team && isset($team['name'])) ? $team['name'] : '',
            'season_id' => $seasonId,
            'season_name' => ($season && isset($season['name'])) ? $season['name'] : '',
            'missions' => $missionViews,
            'summary' => $summary,
            'board_satisfaction' => ($team && isset($team['board_satisfaction'])) ? (int) $team['board_satisfaction'] : 0,
            'highscore' => ($team && isset($team['highscore'])) ? (int) $team['highscore'] : 0,
            'reward_board' => self::getConfigNumber($websoccer, 'manager_missions_reward_board', 3),
            'reward_highscore' => self::getConfigNumber($websoccer, 'manager_missions_reward_highscore', 25),
            'penalty_board' => self::getConfigNumber($websoccer, 'manager_missions_penalty_board', 4)
        );
    }

    /**
     * Called after a youth player is promoted to the professional team.
     */
    public static function recordYouthPromotion(WebSoccer $websoccer, DbConnection $db, $userId, $teamId, $youthPlayerId, $professionalPlayerId, $playerName) {
        try {
            if (!self::isEnabled($websoccer)) {
                return;
            }

            $team = self::getTeam($websoccer, $db, $teamId);
            if (!$team || (int) $team['user_id'] < 1) {
                return;
            }

            $season = self::getActiveSeasonByLeagueId($websoccer, $db, (int) $team['liga_id']);
            if (!$season) {
                return;
            }

            $table = $websoccer->getConfig('db_prefix') . '_manager_mission_youth_promotion';
            $playerName = $db->connection->real_escape_string(trim((string) $playerName));

            $sql = "INSERT INTO " . $table . " SET "
                . "user_id = " . (int) $userId . ", "
                . "team_id = " . (int) $teamId . ", "
                . "season_id = " . (int) $season['id'] . ", "
                . "youth_player_id = " . (int) $youthPlayerId . ", "
                . "professional_player_id = " . (int) $professionalPlayerId . ", "
                . "player_name = '" . $playerName . "', "
                . "promoted_date = " . (int) $websoccer->getNowAsTimestamp()
                . " ON DUPLICATE KEY UPDATE "
                . "user_id = VALUES(user_id), team_id = VALUES(team_id), season_id = VALUES(season_id), "
                . "player_name = VALUES(player_name), promoted_date = VALUES(promoted_date)";

            $db->executeQuery($sql);
        } catch (Exception $e) {
            // Do not break the actual youth promotion if the mission table has not been installed yet.
            return;
        }
    }

    /**
     * Returns current human manager user ID of a team.
     */
    public static function getTeamManagerUserId(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $team = self::getTeam($websoccer, $db, $teamId);
        if ($team && (int) $team['user_id'] > 0) {
            return (int) $team['user_id'];
        }
        return 0;
    }

    /**
     * Processes all human clubs. Useful as an optional job after season creation / matchday simulation.
     */
    public static function processAllHumanClubs(WebSoccer $websoccer, DbConnection $db, I18n $i18n) {
        if (!self::isEnabled($websoccer)) {
            return array('created_or_updated' => 0, 'finalized' => 0);
        }

        $prefix = $websoccer->getConfig('db_prefix');
        $result = $db->querySelect(
            'id, user_id',
            $prefix . '_verein',
            "user_id > 0 AND status = '1' ORDER BY id ASC",
            array()
        );

        $processed = 0;
        while ($row = $result->fetch_array()) {
            $userId = (int) $row['user_id'];
            $teamId = (int) $row['id'];
            self::ensureMissionsForCurrentSeason($websoccer, $db, $userId, $teamId);
            self::updateMissionsForCurrentSeason($websoccer, $db, $i18n, $userId, $teamId);
            $processed++;
        }
        $result->free();

        $finalized = self::finalizeEndedSeasonMissions($websoccer, $db, $i18n);
        return array('created_or_updated' => $processed, 'finalized' => $finalized);
    }

    /**
     * Marks still-open missions of already ended seasons as failed and applies penalties once.
     */
    public static function finalizeEndedSeasonMissions(WebSoccer $websoccer, DbConnection $db, I18n $i18n) {
        if (!self::isEnabled($websoccer)) {
            return 0;
        }

        $prefix = $websoccer->getConfig('db_prefix');
        $missionTable = $prefix . '_manager_mission';
        $seasonTable = $prefix . '_saison';

        $sql = "SELECT M.* FROM " . $missionTable . " AS M "
            . "INNER JOIN " . $seasonTable . " AS S ON S.id = M.season_id "
            . "WHERE S.beendet = '1' AND M.status = '" . self::STATUS_OPEN . "' "
            . "ORDER BY M.team_id ASC, M.season_id ASC, M.id ASC";

        return self::finalizeOpenMissionRows($websoccer, $db, $i18n, $sql);
    }

    /**
     * Finalizes all open missions for one team/season.
     *
     * This method is intentionally independent from season.beendet. The admin
     * season completion page calls it while the final table and season stats
     * are still available, before promotions/relegations and statistic resets
     * can distort final rank checks.
     */
    public static function finalizeTeamSeasonMissions(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $userId, $teamId, $seasonId) {
        if (!self::isEnabled($websoccer)) {
            return 0;
        }

        $seasonId = self::ensureMissionsForSeason($websoccer, $db, $userId, $teamId, $seasonId);
        if ($seasonId < 1) {
            return 0;
        }

        $prefix = $websoccer->getConfig('db_prefix');
        $missionTable = $prefix . '_manager_mission';

        $sql = "SELECT M.* FROM " . $missionTable . " AS M "
            . "WHERE M.user_id = " . (int) $userId . " "
            . "AND M.team_id = " . (int) $teamId . " "
            . "AND M.season_id = " . (int) $seasonId . " "
            . "AND M.status = '" . self::STATUS_OPEN . "' "
            . "ORDER BY M.id ASC";

        return self::finalizeOpenMissionRows($websoccer, $db, $i18n, $sql);
    }

    private static function finalizeOpenMissionRows(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $sql) {
        $result = $db->executeQuery($sql);
        $count = 0;

        while ($mission = $result->fetch_array()) {
            // Give the mission one final check before failing it.
            $progressValue = self::computeProgressValue($websoccer, $db, $mission, (int) $mission['team_id'], (int) $mission['season_id']);
            self::updateMissionProgress($websoccer, $db, (int) $mission['id'], $progressValue);
            $mission['progress_value'] = $progressValue;

            if (self::isMissionCompleted($mission, $progressValue)) {
                self::markMissionCompleted($websoccer, $db, $i18n, $mission, $progressValue);
            } else {
                self::markMissionFailed($websoccer, $db, $i18n, $mission);
            }
            $count++;
        }

        $result->free();
        return $count;
    }

    /**
     * Updates all missions of a specific season.
     */
    public static function updateMissionsForSeason(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $userId, $teamId, $seasonId) {
        $missions = self::getMissions($websoccer, $db, $userId, $teamId, $seasonId);
        $completed = array();

        foreach ($missions as $mission) {
            $progressValue = self::computeProgressValue($websoccer, $db, $mission, $teamId, $seasonId);
            self::updateMissionProgress($websoccer, $db, (int) $mission['id'], $progressValue);

            if ($mission['status'] === self::STATUS_OPEN && self::isMissionCompleted($mission, $progressValue)) {
                $mission['progress_value'] = $progressValue;
                self::markMissionCompleted($websoccer, $db, $i18n, $mission, $progressValue);
                $completed[] = $mission;
            }
        }

        return $completed;
    }

    private static function createMissingMissions(WebSoccer $websoccer, DbConnection $db, $userId, $teamId, $seasonId, $team) {
        foreach (self::getMissionTypes() as $type) {
            if (self::missionExists($websoccer, $db, $userId, $teamId, $seasonId, $type)) {
                continue;
            }

            $baseline = self::getBaselineValue($websoccer, $db, $type, $teamId, $seasonId, $team);
            $target = self::getTargetValue($websoccer, $db, $type, $teamId, $seasonId, $team, $baseline);

            $db->queryInsert(
                array(
                    'user_id' => (int) $userId,
                    'team_id' => (int) $teamId,
                    'season_id' => (int) $seasonId,
                    'mission_type' => $type,
                    'baseline_value' => (int) $baseline,
                    'target_value' => (int) $target,
                    'progress_value' => (int) $baseline,
                    'status' => self::STATUS_OPEN,
                    'rewarded' => '0',
                    'penalized' => '0',
                    'created_date' => $websoccer->getNowAsTimestamp(),
                    'completed_date' => 0,
                    'failed_date' => 0,
                    'checked_date' => 0
                ),
                $websoccer->getConfig('db_prefix') . '_manager_mission'
            );
        }
    }

    private static function getMissionTypes() {
        return array(
            self::TYPE_LEAGUE_RANK,
            self::TYPE_HIGHSCORE,
            self::TYPE_SALARY_REDUCE,
            self::TYPE_YOUTH_PROMOTION_PLAYED,
            self::TYPE_CUP_ROUND,
            self::TYPE_BOARD_SATISFACTION
        );
    }

    private static function missionExists(WebSoccer $websoccer, DbConnection $db, $userId, $teamId, $seasonId, $type) {
        $result = $db->querySelect(
            'id',
            $websoccer->getConfig('db_prefix') . '_manager_mission',
            'user_id = %d AND team_id = %d AND season_id = %d AND mission_type = \'%s\'',
            array((int) $userId, (int) $teamId, (int) $seasonId, $type),
            1
        );
        $row = $result->fetch_array();
        $result->free();

        return ($row && isset($row['id']));
    }

    private static function getMissions(WebSoccer $websoccer, DbConnection $db, $userId, $teamId, $seasonId) {
        $result = $db->querySelect(
            '*',
            $websoccer->getConfig('db_prefix') . '_manager_mission',
            'user_id = %d AND team_id = %d AND season_id = %d ORDER BY id ASC',
            array((int) $userId, (int) $teamId, (int) $seasonId)
        );

        $missions = array();
        while ($row = $result->fetch_array()) {
            $missions[] = $row;
        }
        $result->free();

        return $missions;
    }

    private static function getBaselineValue(WebSoccer $websoccer, DbConnection $db, $type, $teamId, $seasonId, $team) {
        switch ($type) {
            case self::TYPE_LEAGUE_RANK:
                return self::getLeagueRank($websoccer, $db, $teamId, $seasonId, $team);
            case self::TYPE_HIGHSCORE:
                return (int) $team['highscore'];
            case self::TYPE_SALARY_REDUCE:
                return self::getCurrentPlayerSalary($websoccer, $db, $teamId);
            case self::TYPE_YOUTH_PROMOTION_PLAYED:
                return self::getYouthPromotionPlayedProgress($websoccer, $db, $teamId, $seasonId);
            case self::TYPE_CUP_ROUND:
                return self::getCupRoundProgress($websoccer, $db, $teamId, $seasonId);
            case self::TYPE_BOARD_SATISFACTION:
                return (int) $team['board_satisfaction'];
        }

        return 0;
    }

    private static function getTargetValue(WebSoccer $websoccer, DbConnection $db, $type, $teamId, $seasonId, $team, $baseline) {
        switch ($type) {
            case self::TYPE_LEAGUE_RANK:
                if ((int) $team['min_target_rank'] > 0) {
                    return (int) $team['min_target_rank'];
                }
                if ((int) $baseline > 1) {
                    return max(1, (int) $baseline - 1);
                }
                return 8;

            case self::TYPE_HIGHSCORE:
                if (isset($team['min_target_highscore']) && (int) $team['min_target_highscore'] > (int) $baseline) {
                    return (int) $team['min_target_highscore'];
                }
                $increase = max(50, (int) ceil(max(1, (int) $baseline) * 0.05));
                return (int) $baseline + $increase;

            case self::TYPE_SALARY_REDUCE:
                $percent = self::getConfigNumber($websoccer, 'manager_missions_salary_reduce_percent', 5);
                $target = (int) floor((int) $baseline * (100 - $percent) / 100);
                return max(0, $target);

            case self::TYPE_YOUTH_PROMOTION_PLAYED:
                return 1;

            case self::TYPE_CUP_ROUND:
                $rankTarget = (int) $team['min_target_rank'];
                if ($rankTarget > 0 && $rankTarget <= 3) {
                    return 4;
                }
                if ($rankTarget > 0 && $rankTarget <= 8) {
                    return 3;
                }
                return 2;

            case self::TYPE_BOARD_SATISFACTION:
                return 60;
        }

        return 0;
    }

    private static function computeProgressValue(WebSoccer $websoccer, DbConnection $db, $mission, $teamId, $seasonId) {
        $team = self::getTeam($websoccer, $db, $teamId);
        if (!$team) {
            return 0;
        }

        switch ($mission['mission_type']) {
            case self::TYPE_LEAGUE_RANK:
                return self::getLeagueRank($websoccer, $db, $teamId, $seasonId, $team);
            case self::TYPE_HIGHSCORE:
                return (int) $team['highscore'];
            case self::TYPE_SALARY_REDUCE:
                return self::getCurrentPlayerSalary($websoccer, $db, $teamId);
            case self::TYPE_YOUTH_PROMOTION_PLAYED:
                return self::getYouthPromotionPlayedProgress($websoccer, $db, $teamId, $seasonId);
            case self::TYPE_CUP_ROUND:
                return self::getCupRoundProgress($websoccer, $db, $teamId, $seasonId);
            case self::TYPE_BOARD_SATISFACTION:
                return (int) $team['board_satisfaction'];
        }

        return 0;
    }

    private static function isMissionCompleted($mission, $progressValue) {
        $target = (int) $mission['target_value'];

        switch ($mission['mission_type']) {
            case self::TYPE_LEAGUE_RANK:
                return ((int) $progressValue > 0 && (int) $progressValue <= $target);
            case self::TYPE_SALARY_REDUCE:
                return ((int) $progressValue <= $target);
            default:
                return ((int) $progressValue >= $target);
        }
    }

    private static function updateMissionProgress(WebSoccer $websoccer, DbConnection $db, $missionId, $progressValue) {
        $db->queryUpdate(
            array(
                'progress_value' => (int) $progressValue,
                'checked_date' => $websoccer->getNowAsTimestamp()
            ),
            $websoccer->getConfig('db_prefix') . '_manager_mission',
            'id = %d',
            (int) $missionId
        );
    }

    private static function markMissionCompleted(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $mission, $progressValue) {
        $now = $websoccer->getNowAsTimestamp();
        $db->queryUpdate(
            array(
                'status' => self::STATUS_COMPLETED,
                'progress_value' => (int) $progressValue,
                'completed_date' => $now,
                'checked_date' => $now
            ),
            $websoccer->getConfig('db_prefix') . '_manager_mission',
            'id = %d AND status = \'%s\'',
            array((int) $mission['id'], self::STATUS_OPEN)
        );

        $mission['status'] = self::STATUS_COMPLETED;
        $mission['progress_value'] = $progressValue;
        self::applyCompletionReward($websoccer, $db, $i18n, $mission);
    }

    private static function markMissionFailed(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $mission) {
        $now = $websoccer->getNowAsTimestamp();
        $db->queryUpdate(
            array(
                'status' => self::STATUS_FAILED,
                'failed_date' => $now,
                'checked_date' => $now
            ),
            $websoccer->getConfig('db_prefix') . '_manager_mission',
            'id = %d AND status = \'%s\'',
            array((int) $mission['id'], self::STATUS_OPEN)
        );

        $mission['status'] = self::STATUS_FAILED;
        self::applyFailurePenalty($websoccer, $db, $i18n, $mission);
    }

    private static function applyCompletionReward(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $mission) {
        if (isset($mission['rewarded']) && $mission['rewarded'] === '1') {
            return;
        }

        $teamId = (int) $mission['team_id'];
        $team = self::getTeam($websoccer, $db, $teamId);
        if (!$team) {
            return;
        }

        $rewardBoard = self::getConfigNumber($websoccer, 'manager_missions_reward_board', 3);
        $rewardHighscore = self::getConfigNumber($websoccer, 'manager_missions_reward_highscore', 25);

        $newBoard = min(100, max(0, (int) $team['board_satisfaction'] + $rewardBoard));
        $newHighscore = max(0, (int) $team['highscore'] + $rewardHighscore);

        $db->queryUpdate(
            array(
                'board_satisfaction' => $newBoard,
                'highscore' => $newHighscore
            ),
            $websoccer->getConfig('db_prefix') . '_verein',
            'id = %d',
            $teamId
        );

        $db->queryUpdate(
            array('rewarded' => '1'),
            $websoccer->getConfig('db_prefix') . '_manager_mission',
            'id = %d',
            (int) $mission['id']
        );

        $missionView = self::buildMissionView($websoccer, $db, $i18n, $mission, $team, (int) $mission['season_id']);
        $missionTitle = $missionView['title'];

        NotificationsDataService::createNotification(
            $websoccer,
            $db,
            (int) $mission['user_id'],
            'manager_mission_completed_notification',
            array('mission' => $missionTitle),
            'manager-mission',
            'manager-missions',
            null,
            $teamId
        );

        self::createCompletionNews($websoccer, $db, $i18n, $team['name'], $missionTitle, (int) $mission['user_id']);

        if (class_exists('FanPressureDataService')) {
            FanPressureDataService::processMissionCompleted($websoccer, $db, $i18n, $teamId, $missionTitle);
        }
    }

    private static function applyFailurePenalty(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $mission) {
        if (isset($mission['penalized']) && $mission['penalized'] === '1') {
            return;
        }

        $teamId = (int) $mission['team_id'];
        $team = self::getTeam($websoccer, $db, $teamId);
        if (!$team) {
            return;
        }

        $penaltyBoard = self::getConfigNumber($websoccer, 'manager_missions_penalty_board', 4);
        $newBoard = min(100, max(0, (int) $team['board_satisfaction'] - $penaltyBoard));

        $db->queryUpdate(
            array('board_satisfaction' => $newBoard),
            $websoccer->getConfig('db_prefix') . '_verein',
            'id = %d',
            $teamId
        );

        $db->queryUpdate(
            array('penalized' => '1'),
            $websoccer->getConfig('db_prefix') . '_manager_mission',
            'id = %d',
            (int) $mission['id']
        );

        $missionView = self::buildMissionView($websoccer, $db, $i18n, $mission, $team, (int) $mission['season_id']);
        NotificationsDataService::createNotification(
            $websoccer,
            $db,
            (int) $mission['user_id'],
            'manager_mission_failed_notification',
            array('mission' => $missionView['title']),
            'manager-mission',
            'manager-missions',
            null,
            $teamId
        );

        if (class_exists('FanPressureDataService')) {
            FanPressureDataService::processMissionFailed($websoccer, $db, $i18n, $teamId, $missionView['title']);
        }
    }

    private static function createCompletionNews(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $teamName, $missionTitle, $userId) {
        $userName = self::getUserName($websoccer, $db, $userId);
        $title = self::message($i18n, 'manager_mission_news_completed_title', array('team' => $teamName));
        $message = self::message($i18n, 'manager_mission_news_completed_message', array(
            'team' => $teamName,
            'manager' => $userName,
            'mission' => $missionTitle
        ));

        $db->queryInsert(
            array(
                'datum' => $websoccer->getNowAsTimestamp(),
                'autor_id' => 1,
                'titel' => $title,
                'nachricht' => $message,
                'linktext1' => self::message($i18n, 'manager_mission_news_link', array()),
                'linkurl1' => $websoccer->getInternalUrl('manager-missions'),
                'c_br' => '1',
                'c_links' => '1',
                'c_smilies' => '0',
                'status' => '1'
            ),
            $websoccer->getConfig('db_prefix') . '_news'
        );
    }

    private static function buildMissionView(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $mission, $team, $seasonId) {
        $type = $mission['mission_type'];
        $baseline = (int) $mission['baseline_value'];
        $target = (int) $mission['target_value'];
        $progress = (int) $mission['progress_value'];

        $status = $mission['status'];
        $statusClass = 'label-info';
        if ($status === self::STATUS_COMPLETED) {
            $statusClass = 'label-success';
        } elseif ($status === self::STATUS_FAILED) {
            $statusClass = 'label-important';
        } elseif ($status === self::STATUS_CANCELLED) {
            $statusClass = 'label-warning';
        }

        return array(
            'id' => (int) $mission['id'],
            'type' => $type,
            'title' => self::getMissionTitle($i18n, $type, $target),
            'description' => self::getMissionDescription($i18n, $type, $target, $baseline),
            'baseline_label' => self::formatMissionValue($i18n, $type, $baseline, true),
            'target_label' => self::formatMissionValue($i18n, $type, $target, false),
            'progress_label' => self::formatMissionValue($i18n, $type, $progress, false),
            'progress_detail' => self::getProgressDetail($websoccer, $db, $i18n, $type, $team, $seasonId, $progress, $target),
            'progress_percent' => self::getProgressPercent($type, $baseline, $target, $progress),
            'status' => $status,
            'status_label' => self::message($i18n, 'manager_mission_status_' . $status, array()),
            'status_class' => $statusClass,
            'rewarded' => $mission['rewarded'],
            'created_date' => (int) $mission['created_date'],
            'completed_date' => (int) $mission['completed_date'],
            'failed_date' => (int) $mission['failed_date']
        );
    }

    private static function getMissionTitle(I18n $i18n, $type, $target) {
        switch ($type) {
            case self::TYPE_LEAGUE_RANK:
                return self::message($i18n, 'manager_mission_title_league_rank', array('rank' => $target));
            case self::TYPE_HIGHSCORE:
                return self::message($i18n, 'manager_mission_title_highscore', array('target' => $target));
            case self::TYPE_SALARY_REDUCE:
                return self::message($i18n, 'manager_mission_title_salary_reduce', array('target' => number_format($target, 0, ',', ' ')));
            case self::TYPE_YOUTH_PROMOTION_PLAYED:
                return self::message($i18n, 'manager_mission_title_youth_promotion_played', array());
            case self::TYPE_CUP_ROUND:
                return self::message($i18n, 'manager_mission_title_cup_round', array('round' => $target));
            case self::TYPE_BOARD_SATISFACTION:
                return self::message($i18n, 'manager_mission_title_board_satisfaction', array('target' => $target));
        }

        return $type;
    }

    private static function getMissionDescription(I18n $i18n, $type, $target, $baseline) {
        switch ($type) {
            case self::TYPE_LEAGUE_RANK:
                return self::message($i18n, 'manager_mission_desc_league_rank', array('rank' => $target));
            case self::TYPE_HIGHSCORE:
                return self::message($i18n, 'manager_mission_desc_highscore', array('baseline' => $baseline, 'target' => $target));
            case self::TYPE_SALARY_REDUCE:
                return self::message($i18n, 'manager_mission_desc_salary_reduce', array(
                    'baseline' => number_format($baseline, 0, ',', ' '),
                    'target' => number_format($target, 0, ',', ' ')
                ));
            case self::TYPE_YOUTH_PROMOTION_PLAYED:
                return self::message($i18n, 'manager_mission_desc_youth_promotion_played', array());
            case self::TYPE_CUP_ROUND:
                return self::message($i18n, 'manager_mission_desc_cup_round', array('round' => $target));
            case self::TYPE_BOARD_SATISFACTION:
                return self::message($i18n, 'manager_mission_desc_board_satisfaction', array('target' => $target));
        }

        return '';
    }

    private static function formatMissionValue(I18n $i18n, $type, $value, $isBaseline) {
        $value = (int) $value;

        switch ($type) {
            case self::TYPE_LEAGUE_RANK:
                return ($value > 0) ? self::message($i18n, 'manager_mission_value_rank', array('rank' => $value)) : '-';
            case self::TYPE_HIGHSCORE:
                return number_format($value, 0, ',', ' ');
            case self::TYPE_SALARY_REDUCE:
                return number_format($value, 0, ',', ' ');
            case self::TYPE_YOUTH_PROMOTION_PLAYED:
                return ($value >= 1) ? self::message($i18n, 'manager_mission_value_done', array()) : self::message($i18n, 'manager_mission_value_open', array());
            case self::TYPE_CUP_ROUND:
                return self::message($i18n, 'manager_mission_value_cup_round', array('round' => $value));
            case self::TYPE_BOARD_SATISFACTION:
                return $value . '%';
        }

        return (string) $value;
    }

    private static function getProgressDetail(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $type, $team, $seasonId, $progress, $target) {
        if ($type === self::TYPE_YOUTH_PROMOTION_PLAYED) {
            $info = self::getYouthPromotionInfo($websoccer, $db, (int) $team['id'], $seasonId);
            if ($progress >= 1) {
                return self::message($i18n, 'manager_mission_youth_done_detail', array('player' => $info['played_player']));
            }
            if ($info['promoted_count'] > 0) {
                return self::message($i18n, 'manager_mission_youth_promoted_detail', array('player' => $info['last_promoted_player']));
            }
            return self::message($i18n, 'manager_mission_youth_open_detail', array());
        }

        if ($type === self::TYPE_SALARY_REDUCE && $progress > $target) {
            return self::message($i18n, 'manager_mission_salary_missing_detail', array('amount' => number_format($progress - $target, 0, ',', ' ')));
        }

        return '';
    }

    private static function getProgressPercent($type, $baseline, $target, $progress) {
        $baseline = (int) $baseline;
        $target = (int) $target;
        $progress = (int) $progress;

        if ($type === self::TYPE_LEAGUE_RANK) {
            if ($progress > 0 && $progress <= $target) {
                return 100;
            }
            if ($progress <= 0) {
                return 0;
            }
            return max(0, min(95, (int) round(($target / $progress) * 100)));
        }

        if ($type === self::TYPE_SALARY_REDUCE) {
            if ($progress <= $target) {
                return 100;
            }
            if ($baseline <= $target) {
                return 0;
            }
            return max(0, min(95, (int) round((($baseline - $progress) / ($baseline - $target)) * 100)));
        }

        if ($type === self::TYPE_HIGHSCORE) {
            if ($progress >= $target) {
                return 100;
            }
            if ($target <= $baseline) {
                return 0;
            }
            return max(0, min(95, (int) round((($progress - $baseline) / ($target - $baseline)) * 100)));
        }

        if ($target <= 0) {
            return 0;
        }

        if ($progress >= $target) {
            return 100;
        }

        return max(0, min(95, (int) round(($progress / $target) * 100)));
    }

    private static function getTeam(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $result = $db->querySelect(
            'id, name, liga_id, user_id, min_target_rank, min_target_highscore, highscore, board_satisfaction, platz',
            $websoccer->getConfig('db_prefix') . '_verein',
            'id = %d',
            (int) $teamId,
            1
        );
        $team = $result->fetch_array();
        $result->free();

        return $team ? $team : null;
    }

    private static function getUserName(WebSoccer $websoccer, DbConnection $db, $userId) {
        $result = $db->querySelect(
            'nick',
            $websoccer->getConfig('db_prefix') . '_user',
            'id = %d',
            (int) $userId,
            1
        );
        $row = $result->fetch_array();
        $result->free();

        return ($row && isset($row['nick']) && strlen($row['nick'])) ? $row['nick'] : 'Manager';
    }

    private static function getActiveSeasonByLeagueId(WebSoccer $websoccer, DbConnection $db, $leagueId) {
        $result = $db->querySelect(
            'id, name, liga_id, beendet',
            $websoccer->getConfig('db_prefix') . '_saison',
            "liga_id = %d AND beendet = '0' ORDER BY id DESC",
            (int) $leagueId,
            1
        );
        $season = $result->fetch_array();
        $result->free();

        return $season ? $season : null;
    }

    private static function getSeasonById(WebSoccer $websoccer, DbConnection $db, $seasonId) {
        $result = $db->querySelect(
            'id, name, liga_id, beendet',
            $websoccer->getConfig('db_prefix') . '_saison',
            'id = %d',
            (int) $seasonId,
            1
        );
        $season = $result->fetch_array();
        $result->free();

        return $season ? $season : null;
    }

    private static function getCurrentPlayerSalary(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $result = $db->querySelect(
            'SUM(vertrag_gehalt) AS salary_total',
            $websoccer->getConfig('db_prefix') . '_spieler',
            "verein_id = %d AND status = '1'",
            (int) $teamId,
            1
        );
        $row = $result->fetch_array();
        $result->free();

        return ($row && isset($row['salary_total'])) ? (int) $row['salary_total'] : 0;
    }

    private static function getLeagueRank(WebSoccer $websoccer, DbConnection $db, $teamId, $seasonId, $team) {
        $prefix = $websoccer->getConfig('db_prefix');

        $result = $db->querySelect(
            'MAX(matchday) AS max_matchday',
            $prefix . '_leaguehistory',
            'season_id = %d',
            (int) $seasonId,
            1
        );
        $history = $result->fetch_array();
        $result->free();

        if ($history && (int) $history['max_matchday'] > 0) {
            $result = $db->querySelect(
                'rank',
                $prefix . '_leaguehistory',
                'season_id = %d AND team_id = %d AND matchday = %d',
                array((int) $seasonId, (int) $teamId, (int) $history['max_matchday']),
                1
            );
            $rank = $result->fetch_array();
            $result->free();
            if ($rank && (int) $rank['rank'] > 0) {
                return (int) $rank['rank'];
            }
        }

        $season = self::getSeasonById($websoccer, $db, $seasonId);
        if ($season) {
            $sql = "SELECT id FROM " . $prefix . "_verein "
                . "WHERE liga_id = " . (int) $season['liga_id'] . " "
                . "ORDER BY sa_punkte DESC, (sa_tore - sa_gegentore) DESC, sa_siege DESC, sa_unentschieden DESC, sa_tore DESC, name ASC";
            $result = $db->executeQuery($sql);
            $rankNo = 1;
            while ($row = $result->fetch_array()) {
                if ((int) $row['id'] === (int) $teamId) {
                    $result->free();
                    return $rankNo;
                }
                $rankNo++;
            }
            $result->free();
        }

        return (isset($team['platz'])) ? (int) $team['platz'] : 0;
    }

    private static function getYouthPromotionPlayedProgress(WebSoccer $websoccer, DbConnection $db, $teamId, $seasonId) {
        $info = self::getYouthPromotionInfo($websoccer, $db, $teamId, $seasonId);
        return ($info['played_count'] > 0) ? 1 : 0;
    }

    private static function getYouthPromotionInfo(WebSoccer $websoccer, DbConnection $db, $teamId, $seasonId) {
        $prefix = $websoccer->getConfig('db_prefix');
        $promotionTable = $prefix . '_manager_mission_youth_promotion';

        $info = array(
            'promoted_count' => 0,
            'played_count' => 0,
            'last_promoted_player' => '',
            'played_player' => ''
        );

        $result = $db->querySelect(
            '*',
            $promotionTable,
            'team_id = %d AND season_id = %d ORDER BY promoted_date DESC',
            array((int) $teamId, (int) $seasonId)
        );

        while ($row = $result->fetch_array()) {
            $info['promoted_count']++;
            if (!strlen($info['last_promoted_player'])) {
                $info['last_promoted_player'] = $row['player_name'];
            }

            if (self::professionalPlayerPlayedInSeason($websoccer, $db, (int) $row['professional_player_id'], $teamId, $seasonId)) {
                $info['played_count']++;
                if (!strlen($info['played_player'])) {
                    $info['played_player'] = $row['player_name'];
                }
            }
        }
        $result->free();

        if (!strlen($info['last_promoted_player'])) {
            $info['last_promoted_player'] = '-';
        }
        if (!strlen($info['played_player'])) {
            $info['played_player'] = '-';
        }

        return $info;
    }

    private static function professionalPlayerPlayedInSeason(WebSoccer $websoccer, DbConnection $db, $playerId, $teamId, $seasonId) {
        if ($playerId < 1) {
            return FALSE;
        }

        $prefix = $websoccer->getConfig('db_prefix');
        $sql = "SELECT SB.spieler_id FROM " . $prefix . "_spiel_berechnung AS SB "
            . "INNER JOIN " . $prefix . "_spiel AS M ON M.id = SB.spiel_id "
            . "WHERE SB.spieler_id = " . (int) $playerId . " "
            . "AND SB.team_id = " . (int) $teamId . " "
            . "AND M.saison_id = " . (int) $seasonId . " "
            . "AND M.berechnet = '1' "
            . "AND SB.minuten_gespielt > 0 "
            . "LIMIT 1";

        $result = $db->executeQuery($sql);
        $row = $result->fetch_array();
        $result->free();

        return ($row && isset($row['spieler_id']));
    }

    private static function getCupRoundProgress(WebSoccer $websoccer, DbConnection $db, $teamId, $seasonId) {
        $prefix = $websoccer->getConfig('db_prefix');
        $range = self::getSeasonDateRange($websoccer, $db, $seasonId);
        $dateFilter = '';
        if ($range['start'] > 0 && $range['end'] > 0) {
            $dateFilter = ' AND datum >= ' . (int) ($range['start'] - 30 * 86400) . ' AND datum <= ' . (int) ($range['end'] + 60 * 86400);
        }

        $roundMap = array();
        $roundQuery = "SELECT pokalname, pokalrunde, MIN(datum) AS round_date "
            . "FROM " . $prefix . "_spiel "
            . "WHERE spieltyp = 'Pokalspiel' AND pokalname IS NOT NULL AND pokalrunde IS NOT NULL "
            . $dateFilter . " "
            . "GROUP BY pokalname, pokalrunde "
            . "ORDER BY pokalname ASC, round_date ASC";

        $result = $db->executeQuery($roundQuery);
        $currentCup = null;
        $roundNo = 0;
        while ($row = $result->fetch_array()) {
            if ($currentCup !== $row['pokalname']) {
                $currentCup = $row['pokalname'];
                $roundNo = 1;
            } else {
                $roundNo++;
            }
            $roundMap[$row['pokalname'] . '|' . $row['pokalrunde']] = $roundNo;
        }
        $result->free();

        if (!count($roundMap)) {
            return 0;
        }

        $teamQuery = "SELECT DISTINCT pokalname, pokalrunde "
            . "FROM " . $prefix . "_spiel "
            . "WHERE spieltyp = 'Pokalspiel' "
            . "AND (home_verein = " . (int) $teamId . " OR gast_verein = " . (int) $teamId . ") "
            . $dateFilter;

        $maxRound = 0;
        $result = $db->executeQuery($teamQuery);
        while ($row = $result->fetch_array()) {
            $key = $row['pokalname'] . '|' . $row['pokalrunde'];
            if (isset($roundMap[$key])) {
                $maxRound = max($maxRound, (int) $roundMap[$key]);
            }
        }
        $result->free();

        return $maxRound;
    }

    private static function getSeasonDateRange(WebSoccer $websoccer, DbConnection $db, $seasonId) {
        $result = $db->querySelect(
            'MIN(datum) AS min_date, MAX(datum) AS max_date',
            $websoccer->getConfig('db_prefix') . '_spiel',
            'saison_id = %d',
            (int) $seasonId,
            1
        );
        $row = $result->fetch_array();
        $result->free();

        return array(
            'start' => ($row && isset($row['min_date'])) ? (int) $row['min_date'] : 0,
            'end' => ($row && isset($row['max_date'])) ? (int) $row['max_date'] : 0
        );
    }

    private static function getConfigNumber(WebSoccer $websoccer, $key, $default) {
        try {
            $value = $websoccer->getConfig($key);
            if ($value === null || $value === '') {
                return (int) $default;
            }
            return (int) $value;
        } catch (Exception $e) {
            return (int) $default;
        }
    }

    private static function getConfigBoolean(WebSoccer $websoccer, $key, $default) {
        try {
            $value = $websoccer->getConfig($key);
            if ($value === null || $value === '') {
                return (bool) $default;
            }
            return ($value === TRUE || $value === 1 || $value === '1');
        } catch (Exception $e) {
            return (bool) $default;
        }
    }

    private static function message(I18n $i18n, $key, $data) {
        if ($i18n->hasMessage($key)) {
            $message = $i18n->getMessage($key);
        } else {
            $fallbackMessages = self::getFallbackMessages();
            $message = isset($fallbackMessages[$key]) ? $fallbackMessages[$key] : $key;
        }

        foreach ($data as $placeholder => $value) {
            $message = str_replace('{' . $placeholder . '}', (string) $value, $message);
        }

        return $message;
    }

    /**
     * German fallback labels for contexts where frontend message files are not loaded yet,
     * for example match simulation plug-ins or admin jobs.
     */
    private static function getFallbackMessages() {
        return array(
            'manager_mission_no_active_season' => 'Für deinen Verein gibt es aktuell keine aktive Saison. Sobald eine Saison aktiv ist, werden die Ziele automatisch angelegt.',
            'manager_mission_no_missions' => 'Für diese Saison wurden noch keine Ziele angelegt.',
            'manager_mission_completed_short' => 'erfüllt',
            'manager_mission_status_open' => 'Offen',
            'manager_mission_status_completed' => 'Erfüllt',
            'manager_mission_status_failed' => 'Verfehlt',
            'manager_mission_title_league_rank' => 'Tabellenplatz {rank} erreichen',
            'manager_mission_desc_league_rank' => 'Der Vorstand erwartet, dass dein Verein die Saison mindestens auf Platz {rank} abschließt.',
            'manager_mission_title_highscore' => 'Highscore auf {target} steigern',
            'manager_mission_desc_highscore' => 'Verbessere den Vereins-Highscore von {baseline} auf mindestens {target} Punkte.',
            'manager_mission_title_salary_reduce' => 'Spielergehälter auf {target} senken',
            'manager_mission_desc_salary_reduce' => 'Reduziere die Summe der Spielergehälter von {baseline} auf maximal {target}.',
            'manager_mission_salary_missing_detail' => 'Noch {amount} Gehalt einsparen.',
            'manager_mission_title_youth_promotion_played' => 'Jugendspieler hochziehen und einsetzen',
            'manager_mission_desc_youth_promotion_played' => 'Befördere einen Jugendspieler in den Profikader und setze ihn anschließend mindestens einmal in einem Pflichtspiel ein.',
            'manager_mission_youth_open_detail' => 'Noch kein Jugendspieler wurde für dieses Ziel hochgezogen.',
            'manager_mission_youth_promoted_detail' => '{player} wurde hochgezogen. Es fehlt noch ein Pflichtspieleinsatz.',
            'manager_mission_youth_done_detail' => '{player} wurde hochgezogen und eingesetzt.',
            'manager_mission_title_cup_round' => 'Pokalrunde {round} erreichen',
            'manager_mission_desc_cup_round' => 'Erreiche in irgendeinem Pokalwettbewerb mindestens die {round}. Runde.',
            'manager_mission_title_board_satisfaction' => 'Vorstand über {target}% halten',
            'manager_mission_desc_board_satisfaction' => 'Halte die Vorstandszufriedenheit während der Saison bei mindestens {target}%.',
            'manager_mission_value_rank' => 'Platz {rank}',
            'manager_mission_value_cup_round' => 'Runde {round}',
            'manager_mission_value_done' => 'Erledigt',
            'manager_mission_value_open' => 'Offen',
            'manager_mission_completed_notification' => 'Saisonziel erfüllt: {mission}',
            'manager_mission_failed_notification' => 'Saisonziel verfehlt: {mission}',
            'manager_mission_news_completed_title' => 'Saisonziel erfüllt bei {team}',
            'manager_mission_news_completed_message' => '{manager} hat mit {team} ein Vorstandsziel erfüllt: {mission}. Der Vorstand zeigt sich zufrieden und belohnt die Entwicklung des Vereins.',
            'manager_mission_news_link' => 'Zu den Saisonzielen'
        );
    }
}

?>
