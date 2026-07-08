<?php
/******************************************************

  This file is part of OpenWebSoccer-Sim.

******************************************************/

/**
 * Data service for visible manager profiles, CPU manager avatars and manager competence.
 */
class ManagerProfileDataService {

    const STATUS_ACTIVE = 'active';
    const STATUS_FREE = 'free';
    const ASSIGNMENT_ACTIVE = 'active';
    const ASSIGNMENT_ENDED = 'ended';
    const CONFIG_MARKER_NAME = 'mgr_profile_md';
    const CONFIG_CPU_MARKER_NAME = 'mgr_profile_cpu';

    private static $_schemaReady = null;
    private static $_leagueRatingCache = array();

    public static function isSchemaReady(WebSoccer $websoccer, DbConnection $db) {
        if (self::$_schemaReady !== null) {
            return self::$_schemaReady;
        }

        $prefix = $websoccer->getConfig('db_prefix');
        self::$_schemaReady = self::tableExists($db, $prefix . '_manager_profile')
            && self::tableExists($db, $prefix . '_manager_assignment')
            && self::tableExists($db, $prefix . '_manager_competence_log')
            && self::columnExists($db, $prefix . '_user', 'manager_competence');

        return self::$_schemaReady;
    }

    public static function getVisibleManagerForTeam(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $team) {
        if (!self::isSchemaReady($websoccer, $db) || !isset($team['team_id'])) {
            return array('exists' => FALSE, 'schema_ready' => FALSE);
        }

        $teamId = (int) $team['team_id'];
        $userId = (isset($team['team_user_id'])) ? (int) $team['team_user_id'] : 0;

        if ($userId > 0) {
            $managerId = self::ensureHumanProfileForUser($websoccer, $db, $i18n, $userId, $teamId, 'team_view');
            self::assignManagerToTeam($websoccer, $db, $managerId, $teamId, 'active');
        } else {
            $managerId = self::ensureCpuManagerForTeam($websoccer, $db, $teamId, $team, 'team_view');
        }

        $profile = self::getActiveManagerByTeam($websoccer, $db, $teamId);
        if (!$profile) {
            return array('exists' => FALSE, 'schema_ready' => TRUE);
        }

        return self::decorateProfile($websoccer, $i18n, $profile);
    }

    public static function ensureHumanProfileForUser(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $userId, $teamId = 0, $reasonKey = 'recalculation') {
        if (!self::isSchemaReady($websoccer, $db) || (int) $userId < 1) {
            return 0;
        }

        $prefix = $websoccer->getConfig('db_prefix');
        $user = self::getUserRow($websoccer, $db, $userId);
        if (!$user) {
            return 0;
        }

        $existingProfile = self::getProfileByUserId($websoccer, $db, $userId);
        $calculated = self::calculateHumanCompetence($websoccer, $db, $userId);
        $newCompetence = (int) $calculated['competence'];
        $newScore = (int) $calculated['score'];
        $oldCompetence = isset($user['manager_competence']) ? (int) $user['manager_competence'] : 10;
        $oldScore = isset($user['manager_competence_score']) ? (int) $user['manager_competence_score'] : 100;
        $peak = max($newCompetence, isset($user['manager_competence_peak']) ? (int) $user['manager_competence_peak'] : $newCompetence);
        if ($existingProfile) {
            $peak = max($peak, (int) $existingProfile['competence_peak']);
        }
        $salary = self::calculateSalary($newCompetence, $newScore, FALSE);
        $character = strlen((string) $user['manager_character']) ? $user['manager_character'] : 'balanced';
        $avatarKey = 'user-' . (int) $userId . '-' . substr(sha1((string) $user['nick'] . '-' . (int) $userId), 0, 16);
        $displayName = strlen((string) $user['nick']) ? $user['nick'] : ('Manager ' . (int) $userId);

        $db->queryUpdate(array(
            'manager_competence' => (string) $newCompetence,
            'manager_competence_score' => (string) $newScore,
            'manager_competence_peak' => (string) $peak,
            'manager_salary_per_match' => (string) $salary
        ), $prefix . '_user', 'id = %d', (int) $userId);

        if ($existingProfile) {
            $managerId = (int) $existingProfile['id'];
            $db->queryUpdate(array(
                'display_name' => $displayName,
                'firstname' => $displayName,
                'lastname' => '',
                'competence' => (string) $newCompetence,
                'competence_score' => (string) $newScore,
                'competence_peak' => (string) $peak,
                'character_key' => $character,
                'salary_per_match' => (string) $salary,
                'avatar_key' => $avatarKey,
                'status' => self::STATUS_ACTIVE,
                'updated_date' => (string) $websoccer->getNowAsTimestamp()
            ), $prefix . '_manager_profile', 'id = %d', $managerId);
        } else {
            $db->queryInsert(array(
                'user_id' => (string) $userId,
                'is_cpu' => '0',
                'firstname' => $displayName,
                'lastname' => '',
                'display_name' => $displayName,
                'nation' => isset($user['land']) ? $user['land'] : '',
                'age' => '0',
                'competence' => (string) $newCompetence,
                'competence_score' => (string) $newScore,
                'competence_peak' => (string) $peak,
                'character_key' => $character,
                'salary_per_match' => (string) $salary,
                'avatar_key' => $avatarKey,
                'status' => self::STATUS_ACTIVE,
                'created_date' => (string) $websoccer->getNowAsTimestamp(),
                'updated_date' => (string) $websoccer->getNowAsTimestamp()
            ), $prefix . '_manager_profile');
            $managerId = (int) $db->getLastInsertedId();
        }

        if ($oldCompetence !== $newCompetence && $managerId > 0) {
            self::logCompetenceChange($websoccer, $db, $i18n, $managerId, $userId, $teamId, $oldCompetence, $newCompetence, $oldScore, $newScore, $reasonKey, $calculated);
        }

        return $managerId;
    }

    public static function assignHumanManagerToTeam(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $userId, $teamId, $reasonKey = 'joined') {
        if (!self::isSchemaReady($websoccer, $db)) {
            return 0;
        }
        $managerId = self::ensureHumanProfileForUser($websoccer, $db, $i18n, $userId, $teamId, $reasonKey);
        if ($managerId > 0) {
            self::assignManagerToTeam($websoccer, $db, $managerId, $teamId, $reasonKey);
        }
        return $managerId;
    }

    public static function handleUserLeftTeam(WebSoccer $websoccer, DbConnection $db, $teamId, $reasonKey = 'user_left') {
        if (!self::isSchemaReady($websoccer, $db) || (int) $teamId < 1) {
            return 0;
        }
        self::endActiveAssignmentForTeam($websoccer, $db, $teamId, $reasonKey);
        return self::ensureCpuManagerForTeam($websoccer, $db, $teamId, null, $reasonKey);
    }

    public static function handleUserTakesOverTeam(WebSoccer $websoccer, DbConnection $db, $teamId) {
        if (!self::isSchemaReady($websoccer, $db) || (int) $teamId < 1) {
            return;
        }
        self::endActiveAssignmentForTeam($websoccer, $db, $teamId, 'user_taken_over');
    }

    public static function registerHumanSacking(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $userId, $teamId) {
        if (!self::isSchemaReady($websoccer, $db)) {
            return;
        }
        self::ensureHumanProfileForUser($websoccer, $db, $i18n, $userId, $teamId, 'sacked');
        self::endActiveAssignmentForTeam($websoccer, $db, $teamId, 'sacked');
        self::ensureCpuManagerForTeam($websoccer, $db, $teamId, null, 'sacked_replacement');
    }

    public static function ensureCpuManagerForTeam(WebSoccer $websoccer, DbConnection $db, $teamId, $team = null, $reasonKey = 'auto') {
        if (!self::isSchemaReady($websoccer, $db) || (int) $teamId < 1) {
            return 0;
        }

        $active = self::getActiveManagerByTeam($websoccer, $db, $teamId);
        if ($active && (string) $active['is_cpu'] === '1') {
            return (int) $active['manager_id'];
        }
        if ($active) {
            self::endActiveAssignmentForTeam($websoccer, $db, $teamId, 'cpu_replacement');
        }

        if ($team === null || !isset($team['team_id'])) {
            $team = self::getTeamRow($websoccer, $db, $teamId);
        }
        if (!$team) {
            return 0;
        }

        $prefix = $websoccer->getConfig('db_prefix');
        $now = $websoccer->getNowAsTimestamp();
        $name = self::generateManagerName(isset($team['team_country']) ? $team['team_country'] : '');
        $competence = self::generateCpuCompetenceForTeam($websoccer, $db, $team);
        $score = $competence * 10;
        $salary = self::calculateSalary($competence, $score, TRUE);
        $character = self::pickCpuCharacter();
        $avatarKey = 'cpu-' . (int) $teamId . '-' . substr(sha1((string) $now . '-' . mt_rand(1, 999999) . '-' . (string) $name['display_name']), 0, 24);

        $db->queryInsert(array(
            'user_id' => '',
            'is_cpu' => '1',
            'firstname' => $name['firstname'],
            'lastname' => $name['lastname'],
            'display_name' => $name['display_name'],
            'nation' => isset($team['team_country']) ? $team['team_country'] : '',
            'age' => (string) mt_rand(34, 64),
            'competence' => (string) $competence,
            'competence_score' => (string) $score,
            'competence_peak' => (string) $competence,
            'character_key' => $character,
            'salary_per_match' => (string) $salary,
            'avatar_key' => $avatarKey,
            'status' => self::STATUS_ACTIVE,
            'created_date' => (string) $now,
            'updated_date' => (string) $now
        ), $prefix . '_manager_profile');

        $managerId = (int) $db->getLastInsertedId();
        self::assignManagerToTeam($websoccer, $db, $managerId, $teamId, $reasonKey);
        return $managerId;
    }

    public static function endActiveAssignmentForTeam(WebSoccer $websoccer, DbConnection $db, $teamId, $reasonKey) {
        if (!self::isSchemaReady($websoccer, $db) || (int) $teamId < 1) {
            return;
        }
        $prefix = $websoccer->getConfig('db_prefix');
        $db->queryUpdate(array(
            'status' => self::ASSIGNMENT_ENDED,
            'end_date' => (string) $websoccer->getNowAsTimestamp(),
            'end_reason' => $reasonKey,
            'updated_date' => (string) $websoccer->getNowAsTimestamp()
        ), $prefix . '_manager_assignment', "team_id = %d AND status = 'active'", (int) $teamId);
    }

    public static function assignManagerToTeam(WebSoccer $websoccer, DbConnection $db, $managerId, $teamId, $reasonKey) {
        if (!self::isSchemaReady($websoccer, $db) || (int) $managerId < 1 || (int) $teamId < 1) {
            return;
        }
        $prefix = $websoccer->getConfig('db_prefix');
        $existing = $db->querySelect('id,manager_id', $prefix . '_manager_assignment', "team_id = %d AND status = 'active'", (int) $teamId, 1);
        $row = $existing->fetch_array();
        $existing->free();

        if ($row && (int) $row['manager_id'] === (int) $managerId) {
            return;
        }
        if ($row) {
            self::endActiveAssignmentForTeam($websoccer, $db, $teamId, $reasonKey . '_replaced');
        }

        $db->queryInsert(array(
            'manager_id' => (string) $managerId,
            'team_id' => (string) $teamId,
            'start_date' => (string) $websoccer->getNowAsTimestamp(),
            'end_date' => '0',
            'end_reason' => '',
            'status' => self::ASSIGNMENT_ACTIVE,
            'created_date' => (string) $websoccer->getNowAsTimestamp(),
            'updated_date' => (string) $websoccer->getNowAsTimestamp()
        ), $prefix . '_manager_assignment');
    }


    public static function getTrainingEffectForTeam(WebSoccer $websoccer, DbConnection $db, $teamId, $trainingType = '') {
        if (!self::isSchemaReady($websoccer, $db)) {
            return self::getNeutralTrainingEffect();
        }
        $profile = self::getActiveManagerByTeam($websoccer, $db, (int) $teamId);
        if (!$profile) {
            return self::getNeutralTrainingEffect();
        }

        $competence = max(1, min(20, (int) $profile['competence']));
        $character = strlen((string) $profile['character_key']) ? (string) $profile['character_key'] : 'balanced';
        $trainingType = trim((string) $trainingType);

        $developmentFactor = 1 + (($competence - 10) * 0.005);
        $satisfactionBonus = 0.0;
        $freshnessBonus = 0.0;
        $chemistryDeltaBonus = 0.0;
        $injuryMultiplier = 1 - (($competence - 10) * 0.006);

        if ($character === 'developer') {
            $developmentFactor += 0.015;
            $chemistryDeltaBonus += 0.15;
        } elseif ($character === 'tactician' && ($trainingType === 'matchprep' || $trainingType === 'setpieces')) {
            $developmentFactor += 0.012;
            $chemistryDeltaBonus += 0.20;
        } elseif ($character === 'motivator') {
            $satisfactionBonus += 0.08;
            $chemistryDeltaBonus += 0.35;
        } elseif ($character === 'disciplinarian') {
            if ($trainingType === 'athletics' || $trainingType === 'defense') {
                $developmentFactor += 0.012;
            }
            $satisfactionBonus -= 0.05;
            $injuryMultiplier += 0.03;
        } elseif ($character === 'media_friendly') {
            $chemistryDeltaBonus += 0.10;
        } elseif ($character === 'club_icon') {
            $satisfactionBonus += 0.04;
            $chemistryDeltaBonus += 0.25;
        } elseif ($character === 'risk_taker') {
            $developmentFactor += 0.008;
            $injuryMultiplier += 0.04;
            $chemistryDeltaBonus -= 0.10;
        }

        if ($trainingType === 'regeneration') {
            $freshnessBonus += max(0.0, ($competence - 10) * 0.010);
        }

        return array(
            'manager_id' => (int) $profile['manager_id'],
            'name' => strlen((string) $profile['display_name']) ? (string) $profile['display_name'] : trim((string) $profile['firstname'] . ' ' . (string) $profile['lastname']),
            'competence' => $competence,
            'character_key' => $character,
            'development_factor' => max(0.94, min(1.08, $developmentFactor)),
            'injury_multiplier' => max(0.88, min(1.15, $injuryMultiplier)),
            'satisfaction_bonus' => max(-0.12, min(0.15, $satisfactionBonus)),
            'freshness_bonus' => max(0.0, min(0.20, $freshnessBonus)),
            'chemistry_delta_bonus' => max(-0.50, min(0.70, $chemistryDeltaBonus))
        );
    }

    public static function getTrainingInjuryMultiplierForTeam(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $effect = self::getTrainingEffectForTeam($websoccer, $db, (int) $teamId);
        return isset($effect['injury_multiplier']) ? (float) $effect['injury_multiplier'] : 1.0;
    }

    public static function getChemistryFactorForTeam(WebSoccer $websoccer, DbConnection $db, $teamId) {
        if (!self::isSchemaReady($websoccer, $db)) {
            return array('score' => 50, 'detail' => '');
        }
        $profile = self::getActiveManagerByTeam($websoccer, $db, (int) $teamId);
        if (!$profile) {
            return array('score' => 50, 'detail' => '');
        }

        $competence = max(1, min(20, (int) $profile['competence']));
        $character = strlen((string) $profile['character_key']) ? (string) $profile['character_key'] : 'balanced';
        $score = 50 + (($competence - 10) * 2);

        if ($character === 'motivator') {
            $score += 8;
        } elseif ($character === 'club_icon') {
            $score += 7;
        } elseif ($character === 'developer') {
            $score += 3;
        } elseif ($character === 'tactician') {
            $score += 2;
        } elseif ($character === 'media_friendly') {
            $score += 2;
        } elseif ($character === 'disciplinarian') {
            $score -= 3;
        } elseif ($character === 'risk_taker') {
            $score -= 4;
        }

        $name = strlen((string) $profile['display_name']) ? (string) $profile['display_name'] : trim((string) $profile['firstname'] . ' ' . (string) $profile['lastname']);
        return array(
            'score' => max(25, min(90, (int) round($score))),
            'detail' => trim($name . ' · ' . $competence . '/20')
        );
    }

    public static function getSalaryPerMatchForTeam(WebSoccer $websoccer, DbConnection $db, $teamId) {
        if (!self::isSchemaReady($websoccer, $db)) {
            return 0;
        }
        $profile = self::getActiveManagerByTeam($websoccer, $db, (int) $teamId);
        return ($profile && isset($profile['salary_per_match'])) ? (int) $profile['salary_per_match'] : 0;
    }

    public static function processMatchdaySalaries(WebSoccer $websoccer, DbConnection $db) {
        if (!self::isSchemaReady($websoccer, $db)) {
            return array('processed' => 0, 'amount' => 0, 'skipped' => true, 'reason' => 'schema');
        }
        if (!self::getOptionalBooleanConfig($websoccer, 'manager_profile_salaries_enabled', TRUE)) {
            return array('processed' => 0, 'amount' => 0, 'skipped' => true, 'reason' => 'disabled');
        }

        $lastProcessed = self::getLastProcessedMatchId($websoccer, $db);
        $latestCompleted = self::getLatestCompletedMatchId($websoccer, $db);
        if ($latestCompleted <= 0 || $latestCompleted <= $lastProcessed) {
            return array('processed' => 0, 'amount' => 0, 'skipped' => true, 'last' => $lastProcessed, 'latest' => $latestCompleted);
        }

        $prefix = $websoccer->getConfig('db_prefix');
        $sql = "SELECT A.team_id, P.salary_per_match, P.display_name "
            . "FROM " . $prefix . "_manager_assignment AS A "
            . "INNER JOIN " . $prefix . "_manager_profile AS P ON P.id = A.manager_id "
            . "INNER JOIN " . $prefix . "_verein AS T ON T.id = A.team_id "
            . "WHERE A.status = 'active' AND P.status = 'active' AND T.status = '1' AND P.salary_per_match > 0";
        $result = $db->executeQuery($sql);
        $processed = 0;
        $total = 0;
        while ($row = $result->fetch_array()) {
            $teamId = (int) $row['team_id'];
            $salary = (int) $row['salary_per_match'];
            if ($teamId < 1 || $salary <= 0) {
                continue;
            }
            BankAccountDataService::debitAmount($websoccer, $db, $teamId, $salary, 'managerprofile_account_salary_subject', 'managerprofile_account_sender');
            $processed++;
            $total += $salary;
        }
        $result->free();

        self::setLastProcessedMatchId($websoccer, $db, $latestCompleted);
        return array('processed' => $processed, 'amount' => $total, 'skipped' => false, 'last' => $lastProcessed, 'latest' => $latestCompleted);
    }

    public static function processCpuManagerReplacements(WebSoccer $websoccer, DbConnection $db, I18n $i18n) {
        if (!self::isSchemaReady($websoccer, $db)) {
            return array('processed' => 0, 'replaced' => 0, 'skipped' => true, 'reason' => 'schema');
        }
        if (!self::getOptionalBooleanConfig($websoccer, 'manager_profile_cpu_replacements_enabled', TRUE)) {
            return array('processed' => 0, 'replaced' => 0, 'skipped' => true, 'reason' => 'disabled');
        }

        $latestCompleted = self::getLatestCompletedMatchId($websoccer, $db);
        $lastProcessed = self::getLastCpuReplacementMatchId($websoccer, $db, $latestCompleted);
        if ($latestCompleted <= 0 || $latestCompleted <= $lastProcessed) {
            return array('processed' => 0, 'replaced' => 0, 'skipped' => true, 'last' => $lastProcessed, 'latest' => $latestCompleted);
        }

        $prefix = $websoccer->getConfig('db_prefix');
        $sql = "SELECT A.id AS assignment_id, A.team_id, A.start_date, "
            . "P.id AS manager_id, P.display_name, P.firstname, P.lastname, P.competence, P.character_key, P.salary_per_match, "
            . "T.name AS team_name, T.user_id, T.user_id_actual, T.board_satisfaction, T.media_pressure, T.team_chemistry, "
            . "T.sa_siege, T.sa_niederlagen, T.sa_unentschieden, T.superclub, T.highscore, T.strength, T.liga_id, "
            . "L.land AS team_country, L.division AS league_division "
            . "FROM " . $prefix . "_manager_assignment AS A "
            . "INNER JOIN " . $prefix . "_manager_profile AS P ON P.id = A.manager_id "
            . "INNER JOIN " . $prefix . "_verein AS T ON T.id = A.team_id "
            . "LEFT JOIN " . $prefix . "_liga AS L ON L.id = T.liga_id "
            . "WHERE A.status = 'active' AND P.status = 'active' AND P.is_cpu = '1' "
            . "AND T.status = '1' AND T.nationalteam != '1' "
            . "AND (T.user_id IS NULL OR T.user_id = 0) "
            . "AND (T.user_id_actual IS NULL OR T.user_id_actual = 0)";

        $result = $db->executeQuery($sql);
        $processed = 0;
        $replaced = 0;
        $news = 0;
        while ($row = $result->fetch_array()) {
            $evaluation = self::evaluateCpuManagerReplacement($websoccer, $db, $row, $lastProcessed);
            if (!$evaluation['checked']) {
                continue;
            }
            $processed++;
            if (!$evaluation['replace']) {
                continue;
            }

            $oldName = self::profileName($row);
            self::endActiveAssignmentForTeam($websoccer, $db, (int) $row['team_id'], $evaluation['reason_key']);
            self::setManagerProfileStatus($websoccer, $db, (int) $row['manager_id'], self::STATUS_FREE);
            $newManagerId = self::ensureCpuManagerForTeam($websoccer, $db, (int) $row['team_id'], self::teamRowFromCpuEvaluationRow($row), 'cpu_replacement');
            $newProfile = self::getActiveManagerByTeam($websoccer, $db, (int) $row['team_id']);
            if ($newManagerId > 0 && $newProfile) {
                $replaced++;
                self::createCpuReplacementNews($websoccer, $db, $i18n, $row, $oldName, $newProfile, $evaluation);
                $news++;
            }
        }
        $result->free();

        self::setLastCpuReplacementMatchId($websoccer, $db, $latestCompleted);
        return array('processed' => $processed, 'replaced' => $replaced, 'news' => $news, 'skipped' => false, 'last' => $lastProcessed, 'latest' => $latestCompleted);
    }

    private static function evaluateCpuManagerReplacement(WebSoccer $websoccer, DbConnection $db, $row, $lastProcessedMatchId) {
        $teamId = (int) $row['team_id'];
        $matches = self::getRecentOfficialMatchesForTeam($websoccer, $db, $teamId, 8);
        if (!count($matches)) {
            return array('checked' => false, 'replace' => false, 'reason_key' => 'managerprofile_cpu_reason_no_matches');
        }

        $latestTeamMatchId = (int) $matches[0]['id'];
        if ($latestTeamMatchId <= (int) $lastProcessedMatchId) {
            return array('checked' => false, 'replace' => false, 'reason_key' => 'managerprofile_cpu_reason_no_new_match');
        }

        $lossStreak = 0;
        $winlessStreak = 0;
        $wins = 0;
        $draws = 0;
        $losses = 0;
        $lossStreakActive = true;
        $winlessStreakActive = true;
        foreach ($matches as $match) {
            $outcome = self::getTeamOutcomeFromMatch($match, $teamId);
            if ($outcome === 'W') {
                $wins++;
                $lossStreakActive = false;
                $winlessStreakActive = false;
            } elseif ($outcome === 'D') {
                $draws++;
                $lossStreakActive = false;
                if ($winlessStreakActive) {
                    $winlessStreak++;
                }
            } elseif ($outcome === 'L') {
                $losses++;
                if ($lossStreakActive) {
                    $lossStreak++;
                }
                if ($winlessStreakActive) {
                    $winlessStreak++;
                }
            }
        }

        $board = max(0, min(100, (int) $row['board_satisfaction']));
        $media = max(0, min(100, (int) $row['media_pressure']));
        $competence = max(1, min(20, (int) $row['competence']));
        $superclub = (int) $row['superclub'];

        $risk = 0;
        if ($board <= 15) {
            $risk += 70;
        } elseif ($board <= 25) {
            $risk += 50;
        } elseif ($board <= 35) {
            $risk += 32;
        } elseif ($board <= 45) {
            $risk += 15;
        }
        $risk += min(45, $lossStreak * 11);
        $risk += min(25, $winlessStreak * 5);
        if ($media >= 80) {
            $risk += 18;
        } elseif ($media >= 65) {
            $risk += 9;
        }
        if ($superclub > 0 && ($board <= 50 || $lossStreak >= 3)) {
            $risk += 12;
        }
        if ($competence < 10) {
            $risk += (10 - $competence) * 2;
        } elseif ($competence >= 15) {
            $risk -= min(14, ($competence - 14) * 3);
        }

        $reasonKey = 'managerprofile_cpu_reason_poor_form';
        if ($board <= 20) {
            $reasonKey = 'managerprofile_cpu_reason_board_crisis';
        } elseif ($lossStreak >= 5) {
            $reasonKey = 'managerprofile_cpu_reason_loss_streak_5';
        } elseif ($lossStreak >= 3) {
            $reasonKey = 'managerprofile_cpu_reason_loss_streak_3';
        } elseif ($media >= 75) {
            $reasonKey = 'managerprofile_cpu_reason_media_pressure';
        }

        $mustReplace = ($board <= 10 && $winlessStreak >= 2)
            || ($board <= 20 && $lossStreak >= 3)
            || ($board <= 30 && $lossStreak >= 4)
            || ($board <= 40 && $lossStreak >= 5)
            || ($superclub > 0 && $board <= 35 && $lossStreak >= 3);

        $chance = max(0, min(85, $risk - 55));
        $replace = $mustReplace || ($chance > 0 && mt_rand(1, 100) <= $chance);

        return array(
            'checked' => true,
            'replace' => $replace,
            'risk' => (int) $risk,
            'chance' => (int) $chance,
            'reason_key' => $reasonKey,
            'loss_streak' => (int) $lossStreak,
            'winless_streak' => (int) $winlessStreak,
            'wins_recent' => (int) $wins,
            'draws_recent' => (int) $draws,
            'losses_recent' => (int) $losses,
            'latest_team_match_id' => (int) $latestTeamMatchId
        );
    }

    private static function getRecentOfficialMatchesForTeam(WebSoccer $websoccer, DbConnection $db, $teamId, $limit) {
        $prefix = $websoccer->getConfig('db_prefix');
        $sql = "SELECT id, datum, spieltyp, home_verein, gast_verein, home_tore, gast_tore "
            . "FROM " . $prefix . "_spiel "
            . "WHERE berechnet = '1' AND spieltyp != 'Freundschaft' "
            . "AND (home_verein = " . (int) $teamId . " OR gast_verein = " . (int) $teamId . ") "
            . "ORDER BY datum DESC, id DESC LIMIT " . (int) $limit;
        $result = $db->executeQuery($sql);
        $matches = array();
        while ($row = $result->fetch_array()) {
            $matches[] = $row;
        }
        $result->free();
        return $matches;
    }

    private static function getTeamOutcomeFromMatch($match, $teamId) {
        $isHome = ((int) $match['home_verein'] === (int) $teamId);
        $ownGoals = $isHome ? (int) $match['home_tore'] : (int) $match['gast_tore'];
        $otherGoals = $isHome ? (int) $match['gast_tore'] : (int) $match['home_tore'];
        if ($ownGoals > $otherGoals) {
            return 'W';
        }
        if ($ownGoals < $otherGoals) {
            return 'L';
        }
        return 'D';
    }

    private static function teamRowFromCpuEvaluationRow($row) {
        return array(
            'team_id' => (int) $row['team_id'],
            'team_name' => $row['team_name'],
            'team_user_id' => 0,
            'team_highscore' => (int) $row['highscore'],
            'team_strength' => (int) $row['strength'],
            'team_budget' => 0,
            'team_superclub' => (int) $row['superclub'],
            'team_league_id' => (int) $row['liga_id'],
            'team_country' => $row['team_country'],
            'league_division' => (int) $row['league_division']
        );
    }

    private static function setManagerProfileStatus(WebSoccer $websoccer, DbConnection $db, $managerId, $status) {
        if ((int) $managerId < 1) {
            return;
        }
        $db->queryUpdate(array(
            'status' => $status,
            'updated_date' => (string) $websoccer->getNowAsTimestamp()
        ), $websoccer->getConfig('db_prefix') . '_manager_profile', 'id = %d', (int) $managerId);
    }

    private static function createCpuReplacementNews(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $team, $oldName, $newProfile, $evaluation) {
        if (!self::getOptionalBooleanConfig($websoccer, 'manager_profile_cpu_news_enabled', TRUE)) {
            return;
        }

        $teamName = (string) $team['team_name'];
        $newName = self::profileName($newProfile);
        $reason = self::message($i18n, $evaluation['reason_key'], array());
        $characterLabel = self::getCharacterLabel($i18n, isset($newProfile['character_key']) ? $newProfile['character_key'] : 'balanced');
        $title = self::message($i18n, 'managerprofile_cpu_news_title', array('team' => $teamName));
        $message = self::message($i18n, 'managerprofile_cpu_news_message', array(
            'team' => $teamName,
            'old' => $oldName,
            'new' => $newName,
            'reason' => $reason,
            'competence' => (int) $newProfile['competence'],
            'character' => $characterLabel
        ));

        $db->queryInsert(array(
            'datum' => $websoccer->getNowAsTimestamp(),
            'autor_id' => 1,
            'titel' => $title,
            'nachricht' => $message,
            'linktext1' => self::message($i18n, 'managerprofile_cpu_news_link', array()),
            'linkurl1' => $websoccer->getInternalUrl('team', 'id=' . (int) $team['team_id']),
            'c_br' => '1',
            'c_links' => '1',
            'c_smilies' => '0',
            'status' => '1'
        ), $websoccer->getConfig('db_prefix') . '_news');
    }

    private static function profileName($profile) {
        if (isset($profile['display_name']) && strlen((string) $profile['display_name'])) {
            return (string) $profile['display_name'];
        }
        return trim((isset($profile['firstname']) ? (string) $profile['firstname'] : '') . ' ' . (isset($profile['lastname']) ? (string) $profile['lastname'] : ''));
    }

    private static function message(I18n $i18n, $key, $params) {
        if (method_exists($i18n, 'hasMessage') && !$i18n->hasMessage($key)) {
            return $key;
        }
        $message = $i18n->getMessage($key);
        foreach ($params as $paramKey => $paramValue) {
            $message = str_replace('{' . $paramKey . '}', $paramValue, $message);
        }
        return $message;
    }

    private static function getNeutralTrainingEffect() {
        return array(
            'manager_id' => 0,
            'name' => '',
            'competence' => 10,
            'character_key' => 'balanced',
            'development_factor' => 1.0,
            'injury_multiplier' => 1.0,
            'satisfaction_bonus' => 0.0,
            'freshness_bonus' => 0.0,
            'chemistry_delta_bonus' => 0.0
        );
    }

    private static function getActiveManagerByTeam(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $prefix = $websoccer->getConfig('db_prefix');
        $sql = "SELECT A.id AS assignment_id, A.team_id, A.start_date, A.end_date, A.end_reason, "
            . "P.id AS manager_id, P.user_id, P.is_cpu, P.firstname, P.lastname, P.display_name, P.nation, P.age, "
            . "P.competence, P.competence_score, P.competence_peak, P.character_key, P.salary_per_match, P.avatar_key, P.status "
            . "FROM " . $prefix . "_manager_assignment AS A "
            . "INNER JOIN " . $prefix . "_manager_profile AS P ON P.id = A.manager_id "
            . "WHERE A.team_id = " . (int) $teamId . " AND A.status = 'active' "
            . "ORDER BY A.id DESC LIMIT 1";
        $result = $db->executeQuery($sql);
        $row = $result->fetch_array();
        $result->free();
        return ($row) ? $row : null;
    }

    private static function decorateProfile(WebSoccer $websoccer, I18n $i18n, $profile) {
        $name = strlen((string) $profile['display_name']) ? $profile['display_name'] : trim($profile['firstname'] . ' ' . $profile['lastname']);
        $initials = self::getInitials($name);
        $character = strlen((string) $profile['character_key']) ? $profile['character_key'] : 'balanced';
        $isCpu = ((string) $profile['is_cpu'] === '1');
        $avatarUrl = ($isCpu)
            ? $websoccer->getConfig('context_root') . '/manager-avatar.php?key=' . rawurlencode($profile['avatar_key']) . '&initials=' . rawurlencode($initials)
            : '';

        return array(
            'exists' => TRUE,
            'schema_ready' => TRUE,
            'manager_id' => (int) $profile['manager_id'],
            'assignment_id' => (int) $profile['assignment_id'],
            'user_id' => (int) $profile['user_id'],
            'is_cpu' => $isCpu,
            'name' => $name,
            'firstname' => $profile['firstname'],
            'lastname' => $profile['lastname'],
            'nation' => $profile['nation'],
            'age' => (int) $profile['age'],
            'competence' => (int) $profile['competence'],
            'competence_score' => (int) $profile['competence_score'],
            'competence_peak' => (int) $profile['competence_peak'],
            'character_key' => $character,
            'character_label' => self::getCharacterLabel($i18n, $character),
            'salary_per_match' => (int) $profile['salary_per_match'],
            'avatar_key' => $profile['avatar_key'],
            'avatar_url' => $avatarUrl,
            'start_date' => (int) $profile['start_date']
        );
    }

    private static function logCompetenceChange(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $managerId, $userId, $teamId, $oldCompetence, $newCompetence, $oldScore, $newScore, $reasonKey, $calculated) {
        $prefix = $websoccer->getConfig('db_prefix');
        $message = self::buildCompetenceMessage($i18n, $oldCompetence, $newCompetence, $reasonKey);
        $db->queryInsert(array(
            'manager_id' => (string) $managerId,
            'user_id' => (string) $userId,
            'team_id' => (string) $teamId,
            'old_competence' => (string) $oldCompetence,
            'new_competence' => (string) $newCompetence,
            'old_score' => (string) $oldScore,
            'new_score' => (string) $newScore,
            'reason_key' => $reasonKey,
            'message' => $message,
            'created_date' => (string) $websoccer->getNowAsTimestamp(),
            'context_data' => json_encode($calculated)
        ), $prefix . '_manager_competence_log');

        NotificationsDataService::createNotification(
            $websoccer,
            $db,
            $userId,
            'managerprofile_notification_competence_changed',
            array('old' => (int) $oldCompetence, 'new' => (int) $newCompetence, 'reason' => self::getReasonLabel($i18n, $reasonKey)),
            'managerprofile',
            'user',
            'id=' . (int) $userId,
            ($teamId > 0 ? (int) $teamId : null)
        );
    }

    private static function calculateHumanCompetence(WebSoccer $websoccer, DbConnection $db, $userId) {
        $user = self::getUserRow($websoccer, $db, $userId);
        if (!$user) {
            return array('score' => 80, 'competence' => 8);
        }

        $prefix = $websoccer->getConfig('db_prefix');
        $score = 90;
        $score += min(85, (int) floor(((int) $user['highscore']) * 0.70));
        $score += (int) floor(((int) $user['fanbeliebtheit'] - 50) / 2);

        $teamsResult = $db->querySelect('id,liga_id,board_satisfaction,sa_siege,sa_niederlagen,sa_unentschieden,superclub', $prefix . '_verein', "user_id = %d AND status = '1' AND nationalteam != '1'", (int) $userId);
        $teamCount = 0;
        $boardSum = 0;
        $formScore = 0;
        while ($team = $teamsResult->fetch_array()) {
            $teamCount++;
            $boardSum += (int) $team['board_satisfaction'];
            $rating = self::getLeagueRating($websoccer, $db, (int) $team['liga_id']);
            $formBalance = (int) $team['sa_siege'] - (int) $team['sa_niederlagen'];
            $formScore += (int) round($formBalance * (0.15 + ($rating / 250)));
            if ((int) $team['superclub'] > 0 && (int) $team['board_satisfaction'] > 75) {
                $formScore += 3;
            }
        }
        $teamsResult->free();

        if ($teamCount > 0) {
            $avgBoard = (int) round($boardSum / $teamCount);
            $score += max(-30, min(30, (int) floor(($avgBoard - 50) / 1.5)));
            $score += max(-25, min(35, $formScore));
        } else {
            $score += 5;
        }

        $achievementScore = 0;
        $sql = "SELECT A.rank, A.team_id, A.season_id, A.cup_round_id, S.liga_id "
            . "FROM " . $prefix . "_achievement AS A "
            . "LEFT JOIN " . $prefix . "_saison AS S ON S.id = A.season_id "
            . "WHERE A.user_id = " . (int) $userId . " ORDER BY A.date_recorded DESC LIMIT 200";
        $achievements = $db->executeQuery($sql);
        while ($achievement = $achievements->fetch_array()) {
            $rating = ((int) $achievement['liga_id'] > 0) ? self::getLeagueRating($websoccer, $db, (int) $achievement['liga_id']) : 50;
            $factor = max(0.35, $rating / 100);
            if ((int) $achievement['rank'] === 1) {
                $achievementScore += (int) round(18 * $factor);
            } else if ((int) $achievement['rank'] === 2) {
                $achievementScore += (int) round(8 * $factor);
            } else if ((int) $achievement['rank'] === 3) {
                $achievementScore += (int) round(4 * $factor);
            }
            if ((int) $achievement['cup_round_id'] > 0 && (int) $achievement['rank'] === 1) {
                $achievementScore += (int) round(10 * $factor);
            }
        }
        $achievements->free();
        $score += min(55, $achievementScore);

        $sackCount = 0;
        $sackResult = $db->executeQuery("SELECT COUNT(*) AS sack_count FROM " . $prefix . "_manager_career_history WHERE user_id = " . (int) $userId . " AND origin = 'sacked'");
        $sackRow = $sackResult->fetch_array();
        $sackResult->free();
        if ($sackRow) {
            $sackCount += (int) $sackRow['sack_count'];
        }
        $contractSackResult = $db->executeQuery("SELECT COUNT(*) AS sack_count FROM " . $prefix . "_manager_contract WHERE user_id = " . (int) $userId . " AND status = 'sacked'");
        $contractSackRow = $contractSackResult->fetch_array();
        $contractSackResult->free();
        if ($contractSackRow) {
            $sackCount = max($sackCount, (int) $contractSackRow['sack_count']);
        }
        $score -= min(60, $sackCount * 22);

        $score = max(10, min(220, (int) $score));
        $competence = max(1, min(20, (int) round($score / 10)));
        return array('score' => $score, 'competence' => $competence, 'sack_count' => $sackCount, 'achievement_score' => $achievementScore, 'team_count' => $teamCount);
    }

    private static function getLeagueRating(WebSoccer $websoccer, DbConnection $db, $leagueId) {
        $leagueId = (int) $leagueId;
        if ($leagueId < 1) {
            return 40;
        }
        if (isset(self::$_leagueRatingCache[$leagueId])) {
            return self::$_leagueRatingCache[$leagueId];
        }
        $prefix = $websoccer->getConfig('db_prefix');
        if (self::tableExists($db, $prefix . '_manager_league_rating')) {
            $result = $db->querySelect('rating', $prefix . '_manager_league_rating', 'league_id = %d', $leagueId, 1);
            $row = $result->fetch_array();
            $result->free();
            if ($row) {
                self::$_leagueRatingCache[$leagueId] = max(1, min(100, (int) $row['rating']));
                return self::$_leagueRatingCache[$leagueId];
            }
        }

        $sql = "SELECT L.division, COUNT(C.id) AS club_count, COALESCE(AVG(C.highscore), 0) AS avg_highscore, COALESCE(MAX(C.superclub), 0) AS has_superclub "
            . "FROM " . $prefix . "_liga AS L LEFT JOIN " . $prefix . "_verein AS C ON C.liga_id = L.id "
            . "WHERE L.id = " . $leagueId . " GROUP BY L.id, L.division";
        $result = $db->executeQuery($sql);
        $row = $result->fetch_array();
        $result->free();
        if (!$row) {
            self::$_leagueRatingCache[$leagueId] = 40;
            return 40;
        }
        $division = max(1, (int) $row['division']);
        $rating = 78 - (($division - 1) * 15) + (int) floor(((float) $row['avg_highscore']) / 5);
        if ((int) $row['has_superclub'] > 0) {
            $rating += 10;
        }
        self::$_leagueRatingCache[$leagueId] = max(10, min(100, $rating));
        return self::$_leagueRatingCache[$leagueId];
    }

    private static function generateCpuCompetenceForTeam(WebSoccer $websoccer, DbConnection $db, $team) {
        $rating = self::getLeagueRating($websoccer, $db, isset($team['team_league_id']) ? (int) $team['team_league_id'] : (isset($team['liga_id']) ? (int) $team['liga_id'] : 0));
        $superclub = isset($team['team_superclub']) ? (int) $team['team_superclub'] : (isset($team['superclub']) ? (int) $team['superclub'] : 0);
        $highscore = isset($team['team_highscore']) ? (int) $team['team_highscore'] : (isset($team['highscore']) ? (int) $team['highscore'] : 0);

        if ($superclub > 0) {
            return mt_rand(15, 20);
        }
        if ($rating >= 90 || $highscore >= 160) {
            return mt_rand(13, 18);
        }
        if ($rating >= 75 || $highscore >= 110) {
            return mt_rand(11, 16);
        }
        if ($rating >= 60 || $highscore >= 70) {
            return mt_rand(9, 14);
        }
        if ($rating >= 45 || $highscore >= 35) {
            return mt_rand(7, 12);
        }
        return mt_rand(4, 10);
    }

    private static function calculateSalary($competence, $score, $isCpu) {
		$salary = (int) round((($competence * $competence * 1500) + ($score * 250)) / 10);
		if ($isCpu) {
			$salary = (int) round($salary * 0.9);
		}
		return max(1000, min(90000, $salary));
	}

    private static function getProfileByUserId(WebSoccer $websoccer, DbConnection $db, $userId) {
        $prefix = $websoccer->getConfig('db_prefix');
        $result = $db->querySelect('*', $prefix . '_manager_profile', 'user_id = %d', (int) $userId, 1);
        $row = $result->fetch_array();
        $result->free();
        return ($row) ? $row : null;
    }

    private static function getUserRow(WebSoccer $websoccer, DbConnection $db, $userId) {
        $prefix = $websoccer->getConfig('db_prefix');
        $result = $db->querySelect('id,nick,land,highscore,fanbeliebtheit,manager_character,manager_competence,manager_competence_score,manager_competence_peak,manager_salary_per_match', $prefix . '_user', 'id = %d', (int) $userId, 1);
        $row = $result->fetch_array();
        $result->free();
        return ($row) ? $row : null;
    }

    private static function getTeamRow(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $prefix = $websoccer->getConfig('db_prefix');
        $sql = "SELECT C.id AS team_id, C.name AS team_name, C.user_id AS team_user_id, C.highscore AS team_highscore, C.strength AS team_strength, "
            . "C.finanz_budget AS team_budget, C.superclub AS team_superclub, C.liga_id AS team_league_id, L.land AS team_country, L.division AS league_division "
            . "FROM " . $prefix . "_verein AS C LEFT JOIN " . $prefix . "_liga AS L ON L.id = C.liga_id "
            . "WHERE C.id = " . (int) $teamId . " AND C.status = '1' LIMIT 1";
        $result = $db->executeQuery($sql);
        $row = $result->fetch_array();
        $result->free();
        return ($row) ? $row : null;
    }

    private static function generateManagerName($country) {
        $germanFirst = array('Daniel', 'Markus', 'Stefan', 'Thomas', 'Michael', 'Julian', 'Andreas', 'Sebastian', 'Martin', 'Christian');
        $germanLast = array('Vogt', 'Keller', 'Schneider', 'Weber', 'Fischer', 'Krüger', 'Bauer', 'Hoffmann', 'Richter', 'Schulz');
        $englishFirst = array('James', 'Daniel', 'Michael', 'David', 'Steven', 'Robert', 'Paul', 'Martin', 'Graham', 'Scott');
        $englishLast = array('Taylor', 'Walker', 'Cooper', 'Bennett', 'Wilson', 'Hughes', 'Parker', 'Turner', 'Morgan', 'Bailey');
        $latinFirst = array('Marco', 'Luca', 'Paolo', 'Rafael', 'Miguel', 'Carlos', 'Diego', 'Sergio', 'Javier', 'Antonio');
        $latinLast = array('Rossi', 'Bianchi', 'Garcia', 'Martinez', 'Lopez', 'Fernandez', 'Romano', 'Costa', 'Santos', 'Silva');

        $countryLower = strtolower((string) $country);
        if (strpos($countryLower, 'england') !== FALSE || strpos($countryLower, 'schottland') !== FALSE || strpos($countryLower, 'wales') !== FALSE) {
            $first = $englishFirst[array_rand($englishFirst)];
            $last = $englishLast[array_rand($englishLast)];
        } else if (strpos($countryLower, 'ital') !== FALSE || strpos($countryLower, 'span') !== FALSE || strpos($countryLower, 'port') !== FALSE || strpos($countryLower, 'argen') !== FALSE || strpos($countryLower, 'brasil') !== FALSE) {
            $first = $latinFirst[array_rand($latinFirst)];
            $last = $latinLast[array_rand($latinLast)];
        } else {
            $first = $germanFirst[array_rand($germanFirst)];
            $last = $germanLast[array_rand($germanLast)];
        }

        return array('firstname' => $first, 'lastname' => $last, 'display_name' => trim($first . ' ' . $last));
    }

    private static function pickCpuCharacter() {
        $characters = array('balanced', 'motivator', 'tactician', 'disciplinarian', 'developer', 'media_friendly', 'financial_strategist', 'club_icon', 'risk_taker');
        return $characters[array_rand($characters)];
    }

    private static function getCharacterLabel(I18n $i18n, $character) {
        $messageKey = 'manager_character_' . $character;
        if (method_exists($i18n, 'hasMessage') && $i18n->hasMessage($messageKey)) {
            return $i18n->getMessage($messageKey);
        }
        return ucfirst(str_replace('_', ' ', $character));
    }

    private static function getReasonLabel(I18n $i18n, $reasonKey) {
        $messageKey = 'managerprofile_reason_' . $reasonKey;
        if (method_exists($i18n, 'hasMessage') && $i18n->hasMessage($messageKey)) {
            return $i18n->getMessage($messageKey);
        }
        return str_replace('_', ' ', $reasonKey);
    }

    private static function buildCompetenceMessage(I18n $i18n, $oldCompetence, $newCompetence, $reasonKey) {
        $reason = self::getReasonLabel($i18n, $reasonKey);
        if (method_exists($i18n, 'hasMessage') && $i18n->hasMessage('managerprofile_competence_log_message')) {
            $template = $i18n->getMessage('managerprofile_competence_log_message');
            return str_replace(
                array('{old}', '{new}', '{reason}'),
                array((int) $oldCompetence, (int) $newCompetence, $reason),
                $template
            );
        }
        return 'Managerkompetenz: ' . (int) $oldCompetence . ' -> ' . (int) $newCompetence . ' (' . $reason . ')';
    }

    private static function getInitials($name) {
        $name = trim((string) $name);
        if ($name === '') {
            return 'M';
        }
        $parts = preg_split('/\s+/', $name);
        $initials = '';
        foreach ($parts as $part) {
            if ($part !== '') {
                $initials .= function_exists('mb_substr') ? mb_substr($part, 0, 1, 'UTF-8') : substr($part, 0, 1);
            }
            $len = function_exists('mb_strlen') ? mb_strlen($initials, 'UTF-8') : strlen($initials);
            if ($len >= 2) {
                break;
            }
        }
        return function_exists('mb_strtoupper') ? mb_strtoupper($initials, 'UTF-8') : strtoupper($initials);
    }


    private static function getLatestCompletedMatchId(WebSoccer $websoccer, DbConnection $db) {
        $result = $db->querySelect('MAX(id) AS latest_id', $websoccer->getConfig('db_prefix') . '_spiel', "berechnet = '1'");
        $row = $result->fetch_array();
        $result->free();
        return ($row && isset($row['latest_id'])) ? (int) $row['latest_id'] : 0;
    }

    private static function getLastProcessedMatchId(WebSoccer $websoccer, DbConnection $db) {
        self::ensureMarkerExists($websoccer, $db);
        $result = $db->querySelect('zeitstempel', $websoccer->getConfig('db_prefix') . '_config', "name = '%s'", self::CONFIG_MARKER_NAME, 1);
        $row = $result->fetch_array();
        $result->free();
        return ($row && isset($row['zeitstempel'])) ? (int) $row['zeitstempel'] : 0;
    }

    private static function setLastProcessedMatchId(WebSoccer $websoccer, DbConnection $db, $matchId) {
        self::ensureMarkerExists($websoccer, $db);
        $db->queryUpdate(array('zeitstempel' => (int) $matchId), $websoccer->getConfig('db_prefix') . '_config', "name = '%s'", self::CONFIG_MARKER_NAME);
    }

    private static function ensureMarkerExists(WebSoccer $websoccer, DbConnection $db) {
        $result = $db->querySelect('id', $websoccer->getConfig('db_prefix') . '_config', "name = '%s'", self::CONFIG_MARKER_NAME, 1);
        $row = $result->fetch_array();
        $result->free();
        if ($row && isset($row['id'])) {
            return;
        }
        $db->queryInsert(array(
            'name' => self::CONFIG_MARKER_NAME,
            'zeitstempel' => 0,
            'descr' => 'Manager salary matchday marker'
        ), $websoccer->getConfig('db_prefix') . '_config');
    }

    private static function getLastCpuReplacementMatchId(WebSoccer $websoccer, DbConnection $db, $initialMatchId) {
        self::ensureCpuReplacementMarkerExists($websoccer, $db, $initialMatchId);
        $result = $db->querySelect('zeitstempel', $websoccer->getConfig('db_prefix') . '_config', "name = '%s'", self::CONFIG_CPU_MARKER_NAME, 1);
        $row = $result->fetch_array();
        $result->free();
        return ($row && isset($row['zeitstempel'])) ? (int) $row['zeitstempel'] : (int) $initialMatchId;
    }

    private static function setLastCpuReplacementMatchId(WebSoccer $websoccer, DbConnection $db, $matchId) {
        self::ensureCpuReplacementMarkerExists($websoccer, $db, $matchId);
        $db->queryUpdate(array('zeitstempel' => (int) $matchId), $websoccer->getConfig('db_prefix') . '_config', "name = '%s'", self::CONFIG_CPU_MARKER_NAME);
    }

    private static function ensureCpuReplacementMarkerExists(WebSoccer $websoccer, DbConnection $db, $initialMatchId) {
        $result = $db->querySelect('id', $websoccer->getConfig('db_prefix') . '_config', "name = '%s'", self::CONFIG_CPU_MARKER_NAME, 1);
        $row = $result->fetch_array();
        $result->free();
        if ($row && isset($row['id'])) {
            return;
        }
        $db->queryInsert(array(
            'name' => self::CONFIG_CPU_MARKER_NAME,
            'zeitstempel' => (int) $initialMatchId,
            'descr' => 'CPU manager firing marker'
        ), $websoccer->getConfig('db_prefix') . '_config');
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

    private static function tableExists(DbConnection $db, $tableName) {
        $safe = $db->connection->real_escape_string($tableName);
        $result = $db->executeQuery("SHOW TABLES LIKE '" . $safe . "'");
        $exists = ($result && $result->num_rows > 0);
        if ($result) {
            $result->free();
        }
        return $exists;
    }

    private static function columnExists(DbConnection $db, $tableName, $columnName) {
        $safeTable = $db->connection->real_escape_string($tableName);
        $safeColumn = $db->connection->real_escape_string($columnName);
        $result = $db->executeQuery("SHOW COLUMNS FROM `" . $safeTable . "` LIKE '" . $safeColumn . "'");
        $exists = ($result && $result->num_rows > 0);
        if ($result) {
            $result->free();
        }
        return $exists;
    }
}
?>
