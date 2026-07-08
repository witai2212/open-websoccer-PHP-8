<?php
/******************************************************

  This file is part of OpenWebSoccer-Sim.

******************************************************/

/**
 * Youth Academy 2.0 data service.
 *
 * Integrates academy levels, development focus, youth scouting, youth matches,
 * staff bonuses and financial advisor affordability checks.
 */
class YouthAcademyDataService {

    const FOCUS_TECHNIQUE = 'technique';
    const FOCUS_PHYSICAL = 'physical';
    const FOCUS_MENTAL = 'mental';
    const FOCUS_BALANCED = 'balanced';

    const LOG_DEVELOPMENT = 'development';
    const LOG_SCOUTING = 'scouting';
    const LOG_RISK = 'risk';
    const LOG_SYSTEM = 'system';

    private static $_schemaReady = false;

    public static function isEnabled(WebSoccer $websoccer) {
        $value = $websoccer->getConfig('youth_academy_enabled');
        return ($value === null || $value === '' || $value == '1' || $value === TRUE);
    }

    public static function getFocusOptions() {
        return array(
            self::FOCUS_BALANCED => 'youthacademy_focus_balanced',
            self::FOCUS_TECHNIQUE => 'youthacademy_focus_technique',
            self::FOCUS_PHYSICAL => 'youthacademy_focus_physical',
            self::FOCUS_MENTAL => 'youthacademy_focus_mental'
        );
    }

    public static function isValidFocus($focus) {
        return in_array((string) $focus, array(
            self::FOCUS_TECHNIQUE,
            self::FOCUS_PHYSICAL,
            self::FOCUS_MENTAL,
            self::FOCUS_BALANCED
        ), true);
    }

    public static function getPageData(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $teamId, $userId) {
        self::ensureSchema($websoccer, $db);
        $team = self::getTeam($websoccer, $db, $teamId);

        if (!$team || !isset($team['id']) || (int) $team['user_id'] < 1 || (int) $team['user_id'] !== (int) $userId) {
            return array(
                'enabled' => self::isEnabled($websoccer),
                'human_team' => false,
                'team' => array(),
                'academy' => array(),
                'levels' => array(),
                'players' => array(),
                'recent_logs' => array(),
                'report' => array()
            );
        }

        $academy = self::getAcademy($websoccer, $db, $teamId);
        $levels = self::getLevels($websoccer, $db);
        $nextLevel = array();
        $currentLevel = ($academy && isset($academy['level'])) ? (int) $academy['level'] : 0;
        if ($currentLevel > 0) {
            $nextLevel = self::getNextLevel($websoccer, $db, $currentLevel);
        } else {
            $nextLevel = self::getLevel($websoccer, $db, 1);
        }

        $buildCheck = ($nextLevel && isset($nextLevel['level']))
            ? self::getAffordabilityCheck($websoccer, $db, $teamId, $nextLevel, 0)
            : array();
        $upgradeCheck = ($academy && $nextLevel && isset($nextLevel['level']))
            ? self::getAffordabilityCheck($websoccer, $db, $teamId, $nextLevel, $currentLevel)
            : array();

        return array(
            'enabled' => self::isEnabled($websoccer),
            'human_team' => true,
            'team' => $team,
            'academy' => $academy,
            'levels' => $levels,
            'next_level' => $nextLevel,
            'build_check' => $buildCheck,
            'upgrade_check' => $upgradeCheck,
            'can_downgrade' => ($academy && (int) $academy['level'] > 1) ? 1 : 0,
            'focus_options' => self::getFocusOptions(),
            'players' => self::getYouthPlayerOverview($websoccer, $db, $teamId, $academy),
            'recent_logs' => self::getRecentLogs($websoccer, $db, $teamId, 12),
            'report' => self::getReportSummary($websoccer, $db, $teamId),
            'integration' => self::getIntegrationSummary($websoccer, $db, $teamId),
            'lineup_modes' => self::getYouthLineupModes(),
            'expected_income_per_matchday' => isset($buildCheck['expected_income']) ? (int) $buildCheck['expected_income'] : self::getExpectedRegularIncomePerMatchday($websoccer, $db, $teamId),
            'advisor_bonus' => self::getFinancialAdvisorBonus($websoccer, $db, $teamId),
            'advisor_tolerance_percent' => self::getFinancialAdvisorTolerancePercent($websoccer, $db, $teamId)
        );
    }

    public static function buildAcademy(WebSoccer $websoccer, DbConnection $db, $teamId) {
        self::ensureSchema($websoccer, $db);
        if (!self::isEnabled($websoccer)) {
            throw new Exception('youthacademy_err_disabled');
        }
        $existing = self::getAcademy($websoccer, $db, $teamId);
        if ($existing && isset($existing['team_id'])) {
            throw new Exception('youthacademy_err_exists');
        }

        $level = self::getLevel($websoccer, $db, 1);
        if (!$level || !isset($level['level'])) {
            throw new Exception('youthacademy_err_no_level');
        }

        $check = self::getAffordabilityCheck($websoccer, $db, $teamId, $level, 0);
        if (!$check['allowed']) {
            throw new Exception($check['error_key']);
        }

        $buildCost = (int) $level['build_cost'];
        if ($buildCost > 0) {
            self::debitAcademyAmount($websoccer, $db, $teamId, $buildCost, 'youthacademy_account_build_subject', 'youthacademy_account_sender');
        }

        $now = self::now($websoccer);
        $db->queryInsert(array(
            'team_id' => (int) $teamId,
            'level' => 1,
            'focus' => self::FOCUS_BALANCED,
            'reputation' => 50,
            'youth_captain_id' => 0,
            'missed_payments' => 0,
            'last_cost_match_id' => 0,
            'last_report_date' => $now,
            'created_date' => $now,
            'updated_date' => $now,
            'status' => '1'
        ), self::academyTable($websoccer));

        self::log($websoccer, $db, $teamId, 0, self::LOG_SYSTEM, 0, 0, 0, 'youthacademy_log_built', 0);
    }

    public static function upgradeAcademy(WebSoccer $websoccer, DbConnection $db, $teamId) {
        self::ensureSchema($websoccer, $db);
        if (!self::isEnabled($websoccer)) {
            throw new Exception('youthacademy_err_disabled');
        }
        $academy = self::getAcademy($websoccer, $db, $teamId);
        if (!$academy || !isset($academy['team_id'])) {
            throw new Exception('youthacademy_err_missing');
        }

        $nextLevel = self::getNextLevel($websoccer, $db, (int) $academy['level']);
        if (!$nextLevel || !isset($nextLevel['level'])) {
            throw new Exception('youthacademy_err_max_level');
        }

        $check = self::getAffordabilityCheck($websoccer, $db, $teamId, $nextLevel, (int) $academy['level']);
        if (!$check['allowed']) {
            throw new Exception($check['error_key']);
        }

        $buildCost = (int) $nextLevel['build_cost'];
        if ($buildCost > 0) {
            self::debitAcademyAmount($websoccer, $db, $teamId, $buildCost, 'youthacademy_account_upgrade_subject', 'youthacademy_account_sender');
        }

        $db->queryUpdate(array(
            'level' => (int) $nextLevel['level'],
            'updated_date' => self::now($websoccer),
            'status' => '1'
        ), self::academyTable($websoccer), 'team_id = %d', (int) $teamId);

        self::log($websoccer, $db, $teamId, 0, self::LOG_SYSTEM, 0, 0, 0, 'youthacademy_log_upgraded', 0);
    }

    public static function downgradeAcademy(WebSoccer $websoccer, DbConnection $db, $teamId, $automatic = false) {
        self::ensureSchema($websoccer, $db);
        $academy = self::getAcademy($websoccer, $db, $teamId);
        if (!$academy || !isset($academy['team_id'])) {
            throw new Exception('youthacademy_err_missing');
        }
        if ((int) $academy['level'] <= 1) {
            throw new Exception('youthacademy_err_min_level');
        }

        $newLevel = max(1, ((int) $academy['level']) - 1);
        $db->queryUpdate(array(
            'level' => $newLevel,
            'updated_date' => self::now($websoccer),
            'status' => '1'
        ), self::academyTable($websoccer), 'team_id = %d', (int) $teamId);

        self::log($websoccer, $db, $teamId, 0, self::LOG_SYSTEM, 0, 0, 0, $automatic ? 'youthacademy_log_auto_downgraded' : 'youthacademy_log_downgraded', 0);
    }

    public static function saveSettings(WebSoccer $websoccer, DbConnection $db, $teamId, $focus, $captainId) {
        self::ensureSchema($websoccer, $db);
        $academy = self::getAcademy($websoccer, $db, $teamId);
        if (!$academy || !isset($academy['team_id'])) {
            throw new Exception('youthacademy_err_missing');
        }
        if (!self::isValidFocus($focus)) {
            throw new Exception('youthacademy_err_invalid_focus');
        }

        $captainId = (int) $captainId;
        if ($captainId > 0 && !self::youthPlayerBelongsToTeam($websoccer, $db, $captainId, $teamId)) {
            throw new Exception('youthacademy_err_invalid_captain');
        }

        $db->queryUpdate(array(
            'focus' => $focus,
            'youth_captain_id' => $captainId,
            'updated_date' => self::now($websoccer)
        ), self::academyTable($websoccer), 'team_id = %d', (int) $teamId);
    }

    public static function getAffordabilityCheck(WebSoccer $websoccer, DbConnection $db, $teamId, $targetLevel, $currentLevel = 0) {
        $team = self::getTeam($websoccer, $db, $teamId);
        $budget = ($team && isset($team['finanz_budget'])) ? (int) $team['finanz_budget'] : 0;
        $buildCost = isset($targetLevel['build_cost']) ? (int) $targetLevel['build_cost'] : 0;
        $maintenance = isset($targetLevel['maintenance_fee']) ? (int) $targetLevel['maintenance_fee'] : 0;
        $budgetAfter = $budget - $buildCost;
        $expectedIncome = self::getExpectedRegularIncomePerMatchday($websoccer, $db, $teamId);
        $advisorTolerance = self::getFinancialAdvisorTolerancePercent($websoccer, $db, $teamId);
        $effectiveIncome = (int) round($expectedIncome * (1 + ($advisorTolerance / 100)));

        $allowed = true;
        $errorKey = '';
        if ($budgetAfter < 0) {
            $allowed = false;
            $errorKey = 'youthacademy_err_not_enough_budget';
        } elseif ($maintenance > 0 && $effectiveIncome < $maintenance) {
            $allowed = false;
            $errorKey = 'youthacademy_err_income_too_low';
        }

        return array(
            'allowed' => $allowed ? 1 : 0,
            'error_key' => $errorKey,
            'budget' => $budget,
            'build_cost' => $buildCost,
            'budget_after' => $budgetAfter,
            'maintenance_fee' => $maintenance,
            'expected_income' => $expectedIncome,
            'effective_income' => $effectiveIncome,
            'advisor_tolerance_percent' => $advisorTolerance,
            'current_level' => (int) $currentLevel,
            'target_level' => isset($targetLevel['level']) ? (int) $targetLevel['level'] : 0
        );
    }

    public static function processMatchCompletedAcademy(MatchCompletedEvent $event) {
        if (!$event->match || !self::isEnabled($event->websoccer)) {
            return;
        }
        if (!in_array($event->match->type, array('Ligaspiel', 'Pokalspiel', 'Freundschaft'))) {
            return;
        }
        $matchId = (int) $event->match->id;
        if ($matchId < 1) {
            return;
        }

        $teamIds = array();
        if ($event->match->homeTeam && !$event->match->homeTeam->isNationalTeam) {
            $teamIds[] = (int) $event->match->homeTeam->id;
        }
        if ($event->match->guestTeam && !$event->match->guestTeam->isNationalTeam) {
            $teamIds[] = (int) $event->match->guestTeam->id;
        }

        foreach (array_unique($teamIds) as $teamId) {
            self::processAcademyMatchdayForTeam($event->websoccer, $event->db, $event->i18n, $teamId, $matchId);
        }
    }

    public static function processAcademyMatchdayForTeam(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $teamId, $matchId) {
        self::ensureSchema($websoccer, $db);
        $academy = self::getAcademy($websoccer, $db, $teamId);
        if (!$academy || !isset($academy['team_id']) || (int) $academy['status'] !== 1) {
            self::autoManageComputerAcademy($websoccer, $db, $teamId);
            return 0;
        }

        if ((int) $academy['last_cost_match_id'] >= (int) $matchId) {
            return 0;
        }

        $fee = isset($academy['maintenance_fee']) ? (int) $academy['maintenance_fee'] : 0;
        if ($fee > 0) {
            // Running costs may push the club negative. Only creation/upgrades are blocked by affordability.
            self::debitAcademyAmount($websoccer, $db, $teamId, $fee, 'youthacademy_account_maintenance_subject', 'youthacademy_account_sender');
        }

        $db->queryUpdate(array(
            'last_cost_match_id' => (int) $matchId,
            'updated_date' => self::now($websoccer)
        ), self::academyTable($websoccer), 'team_id = %d', (int) $teamId);

        self::processMonthlyReport($websoccer, $db, $i18n, $teamId);
        self::autoManageComputerAcademy($websoccer, $db, $teamId);

        return $fee;
    }

    public static function applyYouthPlayerPlayedBonus(YouthPlayerPlayedEvent $event) {
        if (!$event->player || (int) $event->player->id < 1 || !self::isEnabled($event->websoccer)) {
            return;
        }
        self::ensureSchema($event->websoccer, $event->db);
        $teamId = self::getYouthPlayerTeamId($event->websoccer, $event->db, (int) $event->player->id);
        if ($teamId < 1) {
            return;
        }
        $academy = self::getAcademy($event->websoccer, $event->db, $teamId);
        if (!$academy || !isset($academy['team_id']) || (int) $academy['status'] !== 1) {
            return;
        }

        $oldChange = (int) $event->strengthChange;
        if ($oldChange < 0) {
            $reductionChance = (int) $academy['stagnation_reduction'] + self::getYouthCoachBonus($event->websoccer, $event->db, $teamId);
            if ($academy['focus'] == self::FOCUS_MENTAL) {
                $reductionChance += 3;
            }
            if (class_exists('ManagerCharacterDataService')) {
                $reductionChance = ManagerCharacterDataService::adjustYouthStagnationReductionChance($event->websoccer, $event->db, $teamId, $reductionChance);
            }
            if (mt_rand(1, 100) <= min(45, $reductionChance * 2)) {
                $event->strengthChange = min(0, $oldChange + 1);
            }
        } else {
            $chance = (int) $academy['development_bonus'] * 2;
            $chance += (int) round(((int) $academy['reputation'] - 50) / 5);
            $chance += self::getYouthCoachBonus($event->websoccer, $event->db, $teamId);
            if ($academy['focus'] == self::FOCUS_TECHNIQUE || $academy['focus'] == self::FOCUS_PHYSICAL) {
                $chance += 2;
            } elseif ($academy['focus'] == self::FOCUS_BALANCED) {
                $chance += 1;
            }
            if ((int) $academy['youth_captain_id'] === (int) $event->player->id) {
                $chance += self::configInt($event->websoccer, 'youth_academy_captain_bonus', 3);
            }
            if (class_exists('ManagerCharacterDataService')) {
                $chance = ManagerCharacterDataService::adjustYouthDevelopmentChance($event->websoccer, $event->db, $teamId, $chance);
            }
            if (mt_rand(1, 100) <= max(0, min(40, $chance))) {
                $event->strengthChange += 1;
            }
        }

        if ((int) $event->strengthChange !== $oldChange) {
            self::log($event->websoccer, $event->db, $teamId, (int) $event->player->id, self::LOG_DEVELOPMENT, 0, 0, ((int) $event->strengthChange - $oldChange), 'youthacademy_log_development_bonus', 0);
            self::adjustReputation($event->websoccer, $event->db, $teamId, 1);
        }
    }

    public static function applyYouthPlayerScoutedBonus(YouthPlayerScoutedEvent $event) {
        if (!self::isEnabled($event->websoccer)) {
            return;
        }
        $teamId = (int) $event->teamId;
        $playerId = (int) $event->playerId;
        if ($teamId < 1 || $playerId < 1) {
            return;
        }
        $bonus = self::getYouthScoutingStrengthBonus($event->websoccer, $event->db, $teamId);
        $maxStrength = (int) $event->websoccer->getConfig('youth_scouting_max_strength');
        if ($maxStrength <= 0) {
            $maxStrength = 100;
        }
        $result = $event->db->querySelect('strength, position, age', $event->websoccer->getConfig('db_prefix') . '_youthplayer', 'id = %d AND team_id = %d', array($playerId, $teamId), 1);
        $player = $result->fetch_array();
        $result->free();
        if (!$player) {
            return;
        }
        $oldStrength = (int) $player['strength'];
        $newStrength = min($maxStrength, $oldStrength + max(0, (int) $bonus));
        if ($bonus > 0 && $newStrength > $oldStrength) {
            $event->db->queryUpdate(array('strength' => $newStrength, 'strength_last_change' => $newStrength - $oldStrength), $event->websoccer->getConfig('db_prefix') . '_youthplayer', 'id = %d', $playerId);
            self::log($event->websoccer, $event->db, $teamId, $playerId, self::LOG_SCOUTING, $oldStrength, $newStrength, $newStrength - $oldStrength, 'youthacademy_log_scouting_bonus', 0);
            $player['strength'] = $newStrength;
        }

        if (class_exists('PlayerTraitsDataService')) {
            $academy = self::getAcademy($event->websoccer, $event->db, $teamId);
            if ($academy && isset($academy['team_id']) && (int) $academy['status'] === 1) {
                $scoutExpertise = self::getYouthScoutExpertise($event->websoccer, $event->db, (int) $event->scoutId);
                $traits = PlayerTraitsDataService::generateTraitsForYouthPlayer($player['position'], (int) $player['age'], (int) $player['strength'], $academy, $scoutExpertise);
                if (count($traits)) {
                    PlayerTraitsDataService::assignTraitsToYouthPlayer($event->websoccer, $event->db, $playerId, $traits);
                    self::log($event->websoccer, $event->db, $teamId, $playerId, self::LOG_SCOUTING, (int) $player['strength'], (int) $player['strength'], 0, 'youthacademy_log_trait_discovered', 0);
                }
            }
        }
    }

    public static function applyYouthScoutingSuccessBonus(WebSoccer $websoccer, DbConnection $db, $teamId, $baseProbability) {
        $baseProbability = (int) $baseProbability;
        $bonus = self::getScoutingModifier($websoccer, $db, $teamId);
        return max(1, min(95, $baseProbability + (int) round($bonus / 2)));
    }

    public static function applyYouthScoutingStrengthRangeBonus(WebSoccer $websoccer, DbConnection $db, $teamId, $strength, $maxStrength) {
        $bonus = self::getYouthScoutingStrengthBonus($websoccer, $db, $teamId);
        if ($bonus <= 0) {
            return (int) $strength;
        }
        return min((int) $maxStrength, (int) $strength + (int) $bonus);
    }

    public static function getScoutingModifier(WebSoccer $websoccer, DbConnection $db, $teamId) {
        self::ensureSchema($websoccer, $db);
        $academy = self::getAcademy($websoccer, $db, $teamId);
        if (!$academy || !isset($academy['team_id']) || (int) $academy['status'] !== 1) {
            return 0;
        }
        $bonus = (int) $academy['scouting_bonus'];
        $bonus += (int) round(((int) $academy['reputation'] - 50) / 10);
        $bonus += self::getScoutingDepartmentBonus($websoccer, $db, $teamId);
        $bonus += self::getYouthCoachBonus($websoccer, $db, $teamId);
        return max(0, min(30, $bonus));
    }

    public static function getYouthScoutingStrengthBonus(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $modifier = self::getScoutingModifier($websoccer, $db, $teamId);
        if ($modifier <= 0) {
            return 0;
        }
        // A small, controlled bonus. This harmonizes youth-scouting, academy and staff without creating super talents too quickly.
        return max(0, min(4, (int) floor($modifier / 8)));
    }

    public static function getYouthLineupModes() {
        return array(
            array('id' => 'development', 'message_key' => 'youthformation_setup_development'),
            array('id' => 'balanced', 'message_key' => 'youthformation_setup_balanced'),
            array('id' => 'winning', 'message_key' => 'youthformation_setup_winning')
        );
    }

    public static function processMonthlyReport(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $teamId) {
        $academy = self::getAcademy($websoccer, $db, $teamId);
        if (!$academy || !isset($academy['team_id'])) {
            return;
        }
        $now = self::now($websoccer);
        $intervalDays = self::configInt($websoccer, 'youth_academy_report_days', 30);
        if ((int) $academy['last_report_date'] > 0 && (int) $academy['last_report_date'] + ($intervalDays * 86400) > $now) {
            return;
        }

        $report = self::getReportSummary($websoccer, $db, $teamId);
        $riskCount = count(self::getAtRiskYouthPlayers($websoccer, $db, $teamId));
        if ((int) $report['development_changes'] == 0 && (int) $report['scouting_hits'] == 0 && $riskCount == 0) {
            $db->queryUpdate(array('last_report_date' => $now), self::academyTable($websoccer), 'team_id = %d', (int) $teamId);
            return;
        }

        $team = self::getTeam($websoccer, $db, $teamId);
        if ($team && isset($team['user_id']) && (int) $team['user_id'] > 0) {
            self::sendInboxMessage($websoccer, $db, $i18n, (int) $team['user_id'], 'youthacademy_message_report_subject', 'youthacademy_message_report_body', array(
                'development' => (int) $report['development_changes'],
                'scouting' => (int) $report['scouting_hits'],
                'risk' => $riskCount,
                'url' => $websoccer->getInternalUrl('youth-academy')
            ));
        }
        $db->queryUpdate(array('last_report_date' => $now), self::academyTable($websoccer), 'team_id = %d', (int) $teamId);
    }

    public static function getAcademy(WebSoccer $websoccer, DbConnection $db, $teamId) {
        self::ensureSchema($websoccer, $db);
        $prefix = self::prefix($websoccer);
        $from = $prefix . 'youth_academy AS A LEFT JOIN ' . $prefix . 'youth_academy_level AS L ON L.level = A.level';
        $columns = array(
            'A.team_id' => 'team_id',
            'A.level' => 'level',
            'A.focus' => 'focus',
            'A.reputation' => 'reputation',
            'A.youth_captain_id' => 'youth_captain_id',
            'A.missed_payments' => 'missed_payments',
            'A.last_cost_match_id' => 'last_cost_match_id',
            'A.last_report_date' => 'last_report_date',
            'A.created_date' => 'created_date',
            'A.updated_date' => 'updated_date',
            'A.status' => 'status',
            'L.name' => 'level_name',
            'L.build_cost' => 'build_cost',
            'L.maintenance_fee' => 'maintenance_fee',
            'L.development_bonus' => 'development_bonus',
            'L.scouting_bonus' => 'scouting_bonus',
            'L.stagnation_reduction' => 'stagnation_reduction',
            'L.reputation_bonus' => 'reputation_bonus',
            'L.max_reputation' => 'max_reputation'
        );
        $result = $db->querySelect($columns, $from, 'A.team_id = %d', (int) $teamId, 1);
        $row = $result->fetch_array();
        $result->free();
        return $row ? $row : array();
    }

    public static function getLevel(WebSoccer $websoccer, DbConnection $db, $level) {
        self::ensureSchema($websoccer, $db);
        $result = $db->querySelect('*', self::levelTable($websoccer), "level = %d AND status = '1'", (int) $level, 1);
        $row = $result->fetch_array();
        $result->free();
        return $row ? $row : array();
    }

    public static function getNextLevel(WebSoccer $websoccer, DbConnection $db, $currentLevel) {
        self::ensureSchema($websoccer, $db);
        $result = $db->querySelect('*', self::levelTable($websoccer), "level > %d AND status = '1' ORDER BY level ASC", (int) $currentLevel, 1);
        $row = $result->fetch_array();
        $result->free();
        return $row ? $row : array();
    }

    public static function getLevels(WebSoccer $websoccer, DbConnection $db) {
        self::ensureSchema($websoccer, $db);
        $result = $db->querySelect('*', self::levelTable($websoccer), "status = '1' ORDER BY level ASC");
        $rows = array();
        while ($row = $result->fetch_array()) {
            $rows[] = $row;
        }
        $result->free();
        return $rows;
    }

    private static function getYouthPlayerOverview(WebSoccer $websoccer, DbConnection $db, $teamId, $academy) {
        $players = YouthPlayersDataService::getYouthPlayersOfTeam($websoccer, $db, $teamId);
        $captainId = ($academy && isset($academy['youth_captain_id'])) ? (int) $academy['youth_captain_id'] : 0;
        foreach ($players as $index => $player) {
            $risk = self::getTalentRiskForPlayer($websoccer, $db, $player, $academy);
            $players[$index]['risk_level'] = $risk['level'];
            $players[$index]['risk_message_key'] = $risk['message_key'];
            $players[$index]['loan_recommendation_key'] = self::getLoanRecommendationKey($player);
            $players[$index]['is_youth_captain'] = ((int) $player['id'] === $captainId) ? 1 : 0;
        }
        return $players;
    }

    private static function getTalentRiskForPlayer(WebSoccer $websoccer, DbConnection $db, $player, $academy) {
        $matches = isset($player['st_matches']) ? (int) $player['st_matches'] : 0;
        $age = isset($player['age']) ? (int) $player['age'] : 16;
        $strength = isset($player['strength']) ? (int) $player['strength'] : 0;
        $level = ($academy && isset($academy['level'])) ? (int) $academy['level'] : 0;
        $youthCoach = self::getYouthCoachBonus($websoccer, $db, isset($player['team_id']) ? (int) $player['team_id'] : 0);

        $riskScore = 0;
        if ($age >= 17 && $matches < 3) $riskScore += 2;
        if ($age >= 16 && $matches < 1) $riskScore += 1;
        if ($strength >= 45 && $matches < 5) $riskScore += 1;
        if ($level >= 3) $riskScore -= 1;
        if ($youthCoach >= 4) $riskScore -= 1;

        if ($riskScore >= 3) {
            return array('level' => 'high', 'message_key' => 'youthacademy_risk_high');
        }
        if ($riskScore >= 1) {
            return array('level' => 'medium', 'message_key' => 'youthacademy_risk_medium');
        }
        return array('level' => 'low', 'message_key' => 'youthacademy_risk_low');
    }

    private static function getAtRiskYouthPlayers(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $academy = self::getAcademy($websoccer, $db, $teamId);
        $players = YouthPlayersDataService::getYouthPlayersOfTeam($websoccer, $db, $teamId);
        $risk = array();
        foreach ($players as $player) {
            $playerRisk = self::getTalentRiskForPlayer($websoccer, $db, $player, $academy);
            if ($playerRisk['level'] == 'high') {
                $risk[] = $player;
            }
        }
        return $risk;
    }

    private static function getLoanRecommendationKey($player) {
        $strength = isset($player['strength']) ? (int) $player['strength'] : 0;
        $age = isset($player['age']) ? (int) $player['age'] : 16;
        $matches = isset($player['st_matches']) ? (int) $player['st_matches'] : 0;
        if ($age < 16) {
            return 'youthacademy_loan_none_too_young';
        }
        if ($strength >= 55 && $matches < 6) {
            return 'youthacademy_loan_competitive_lower_league';
        }
        if ($strength >= 40) {
            return 'youthacademy_loan_regular_minutes';
        }
        return 'youthacademy_loan_stay_academy';
    }

    private static function getReportSummary(WebSoccer $websoccer, DbConnection $db, $teamId) {
        self::ensureSchema($websoccer, $db);
        $since = self::now($websoccer) - (self::configInt($websoccer, 'youth_academy_report_days', 30) * 86400);
        $table = self::logTable($websoccer);

        $result = $db->querySelect('COUNT(*) AS cnt, COALESCE(SUM(change_amount),0) AS total_change', $table, "team_id = %d AND type = 'development' AND created_date >= %d", array((int) $teamId, $since), 1);
        $dev = $result->fetch_array();
        $result->free();

        $result = $db->querySelect('COUNT(*) AS cnt', $table, "team_id = %d AND type = 'scouting' AND created_date >= %d", array((int) $teamId, $since), 1);
        $scouting = $result->fetch_array();
        $result->free();

        return array(
            'development_changes' => ($dev && isset($dev['total_change'])) ? (int) $dev['total_change'] : 0,
            'development_events' => ($dev && isset($dev['cnt'])) ? (int) $dev['cnt'] : 0,
            'scouting_hits' => ($scouting && isset($scouting['cnt'])) ? (int) $scouting['cnt'] : 0,
            'period_days' => self::configInt($websoccer, 'youth_academy_report_days', 30)
        );
    }

    private static function getRecentLogs(WebSoccer $websoccer, DbConnection $db, $teamId, $limit) {
        self::ensureSchema($websoccer, $db);
        $prefix = self::prefix($websoccer);
        $from = $prefix . 'youth_academy_log AS L LEFT JOIN ' . $prefix . 'youthplayer AS P ON P.id = L.player_id';
        $columns = array(
            'L.id' => 'id',
            'L.type' => 'type',
            'L.old_strength' => 'old_strength',
            'L.new_strength' => 'new_strength',
            'L.change_amount' => 'change_amount',
            'L.message' => 'message',
            'L.created_date' => 'created_date',
            'P.firstname' => 'firstname',
            'P.lastname' => 'lastname'
        );
        $result = $db->querySelect($columns, $from, 'L.team_id = %d ORDER BY L.created_date DESC, L.id DESC', (int) $teamId, (int) $limit);
        $rows = array();
        while ($row = $result->fetch_array()) {
            $rows[] = $row;
        }
        $result->free();
        return $rows;
    }

    private static function getIntegrationSummary(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $academy = self::getAcademy($websoccer, $db, $teamId);
        $department = (class_exists('ScoutingDataService')) ? ScoutingDataService::getDepartment($websoccer, $db, $teamId) : array();
        return array(
            'academy_scouting_bonus' => ($academy && isset($academy['scouting_bonus'])) ? (int) $academy['scouting_bonus'] : 0,
            'academy_development_bonus' => ($academy && isset($academy['development_bonus'])) ? (int) $academy['development_bonus'] : 0,
            'youth_coach_bonus' => self::getYouthCoachBonus($websoccer, $db, $teamId),
            'financial_advisor_bonus' => self::getFinancialAdvisorBonus($websoccer, $db, $teamId),
            'scouting_department_level' => ($department && isset($department['level'])) ? (int) $department['level'] : 0,
            'scouting_department_bonus' => self::getScoutingDepartmentBonus($websoccer, $db, $teamId),
            'combined_scouting_modifier' => self::getScoutingModifier($websoccer, $db, $teamId)
        );
    }

    private static function getExpectedRegularIncomePerMatchday(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $teamId = (int) $teamId;
        $income = 0;

        if (class_exists('FinancialForecastDataService')) {
            try {
                $forecast = FinancialForecastDataService::getForecast($websoccer, $db, $teamId);
                if ($forecast && isset($forecast['matches_count']) && (int) $forecast['matches_count'] > 0) {
                    $regularIncome = max(0, (int) $forecast['sponsor_income_total']) + max(0, (int) $forecast['stadium_income_total']);
                    $income = (int) floor($regularIncome / max(1, (int) $forecast['matches_count']));
                }
            } catch (Exception $e) {
                $income = 0;
            }
        }

        if ($income > 0) {
            return $income;
        }

        // Conservative fallback if no forecastable fixtures exist.
        $prefix = self::prefix($websoccer);
        $result = $db->querySelect('finanz_budget', $prefix . 'verein', 'id = %d', $teamId, 1);
        $row = $result->fetch_array();
        $result->free();
        return ($row && isset($row['finanz_budget'])) ? max(0, (int) floor(((int) $row['finanz_budget']) / 20)) : 0;
    }

    private static function autoManageComputerAcademy(WebSoccer $websoccer, DbConnection $db, $teamId) {
        if (!self::configBool($websoccer, 'youth_academy_computer_manage_enabled', true)) {
            return;
        }
        $team = self::getTeam($websoccer, $db, $teamId);
        if (!$team || !isset($team['id']) || (int) $team['user_id'] > 0) {
            return;
        }

        $academy = self::getAcademy($websoccer, $db, $teamId);
        if (!$academy || !isset($academy['team_id'])) {
            $level = self::getLevel($websoccer, $db, 1);
            if ($level && isset($level['level'])) {
                $check = self::getAffordabilityCheck($websoccer, $db, $teamId, $level, 0);
                if ($check['allowed'] && (int) $team['finanz_budget'] > ((int) $level['build_cost'] * 2)) {
                    self::buildAcademy($websoccer, $db, $teamId);
                }
            }
            return;
        }

        $currentLevel = self::getLevel($websoccer, $db, (int) $academy['level']);
        $check = self::getAffordabilityCheck($websoccer, $db, $teamId, $currentLevel, (int) $academy['level']);
        if (!$check['allowed'] && (int) $academy['level'] > 1) {
            try {
                self::downgradeAcademy($websoccer, $db, $teamId, true);
            } catch (Exception $e) {
            }
            return;
        }

        if (mt_rand(1, 100) <= 8) {
            $nextLevel = self::getNextLevel($websoccer, $db, (int) $academy['level']);
            if ($nextLevel && isset($nextLevel['level'])) {
                $upgradeCheck = self::getAffordabilityCheck($websoccer, $db, $teamId, $nextLevel, (int) $academy['level']);
                if ($upgradeCheck['allowed'] && (int) $team['finanz_budget'] > ((int) $nextLevel['build_cost'] * 3)) {
                    try {
                        self::upgradeAcademy($websoccer, $db, $teamId);
                    } catch (Exception $e) {
                    }
                }
            }
        }
    }

    /**
     * Debits academy costs from a club and writes an account statement.
     *
     * This intentionally does not use BankAccountDataService::debitAmount(), because
     * that service respects the global no_transactions_for_teams_without_user flag.
     * Academy 2.0 must also affect computer clubs, otherwise CPU academies could be
     * built/operated without real financial impact.
     */
    private static function debitAcademyAmount(WebSoccer $websoccer, DbConnection $db, $teamId, $amount, $subject, $sender) {
        $amount = (int) $amount;
        if ($amount === 0) {
            return;
        }
        if ($amount < 0) {
            throw new Exception('amount illegal: ' . $amount);
        }

        $team = self::getTeam($websoccer, $db, $teamId);
        if (!$team || !isset($team['id'])) {
            throw new Exception('team not found: ' . $teamId);
        }

        $negativeAmount = 0 - $amount;
        $db->queryInsert(array(
            'verein_id' => (int) $teamId,
            'absender' => $sender,
            'betrag' => $negativeAmount,
            'datum' => self::now($websoccer),
            'verwendung' => $subject
        ), self::prefix($websoccer) . 'konto');

        $db->queryUpdate(array(
            'finanz_budget' => ((int) $team['finanz_budget']) + $negativeAmount
        ), self::prefix($websoccer) . 'verein', 'id = %d', (int) $teamId);
    }

    private static function sendInboxMessage(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $userId, $subjectKey, $bodyKey, $vars = array()) {
        if ((int) $userId < 1) {
            return;
        }
        $subject = self::message($i18n, $subjectKey, $vars);
        if (strlen($subject) > 50) {
            $subject = substr($subject, 0, 47) . '...';
        }
        $body = self::message($i18n, $bodyKey, $vars);
        $db->queryInsert(array(
            'empfaenger_id' => (int) $userId,
            'absender_id' => 0,
            'absender_name' => self::message($i18n, 'youthacademy_message_sender'),
            'datum' => self::now($websoccer),
            'betreff' => $subject,
            'nachricht' => $body,
            'gelesen' => '0',
            'typ' => 'eingang'
        ), $websoccer->getConfig('db_prefix') . '_briefe');
    }

    private static function message(I18n $i18n, $key, $vars = array()) {
        $text = $i18n->getMessage($key);
        foreach ($vars as $name => $value) {
            $text = str_replace('{' . $name . '}', $value, $text);
        }
        return $text;
    }

    private static function adjustReputation(WebSoccer $websoccer, DbConnection $db, $teamId, $delta) {
        $academy = self::getAcademy($websoccer, $db, $teamId);
        if (!$academy || !isset($academy['team_id'])) {
            return;
        }
        $max = isset($academy['max_reputation']) ? (int) $academy['max_reputation'] : 100;
        $new = max(0, min($max, (int) $academy['reputation'] + (int) $delta));
        if ($new !== (int) $academy['reputation']) {
            $db->queryUpdate(array('reputation' => $new, 'updated_date' => self::now($websoccer)), self::academyTable($websoccer), 'team_id = %d', (int) $teamId);
        }
    }

    private static function log(WebSoccer $websoccer, DbConnection $db, $teamId, $playerId, $type, $oldStrength, $newStrength, $changeAmount, $message, $matchId = 0) {
        self::ensureSchema($websoccer, $db);
        $db->queryInsert(array(
            'team_id' => (int) $teamId,
            'player_id' => (int) $playerId,
            'type' => $type,
            'old_strength' => (int) $oldStrength,
            'new_strength' => (int) $newStrength,
            'change_amount' => (int) $changeAmount,
            'message' => $message,
            'created_date' => self::now($websoccer),
            'match_id' => (int) $matchId
        ), self::logTable($websoccer));
    }

    private static function getYouthScoutExpertise(WebSoccer $websoccer, DbConnection $db, $scoutId) {
        if ((int) $scoutId < 1) {
            return 50;
        }
        $result = $db->querySelect('expertise', $websoccer->getConfig('db_prefix') . '_youthscout', 'id = %d', (int) $scoutId, 1);
        $row = $result->fetch_array();
        $result->free();
        return ($row && isset($row['expertise'])) ? (int) $row['expertise'] : 50;
    }

    private static function getYouthCoachBonus(WebSoccer $websoccer, DbConnection $db, $teamId) {
        if ((int) $teamId < 1 || !class_exists('ClubStaffDataService')) {
            return 0;
        }
        return (int) ClubStaffDataService::getRoleBonus($websoccer, $db, $teamId, ClubStaffDataService::ROLE_YOUTH_COACH);
    }

    private static function getFinancialAdvisorBonus(WebSoccer $websoccer, DbConnection $db, $teamId) {
        if ((int) $teamId < 1 || !class_exists('ClubStaffDataService')) {
            return 0;
        }
        return (int) ClubStaffDataService::getRoleBonus($websoccer, $db, $teamId, ClubStaffDataService::ROLE_FINANCIAL_ADVISOR);
    }

    private static function getFinancialAdvisorTolerancePercent(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $bonus = self::getFinancialAdvisorBonus($websoccer, $db, $teamId);
        return min(10, max(0, $bonus));
    }

    private static function getScoutingDepartmentBonus(WebSoccer $websoccer, DbConnection $db, $teamId) {
        if ((int) $teamId < 1 || !class_exists('ScoutingDataService')) {
            return 0;
        }
        try {
            $department = ScoutingDataService::getDepartment($websoccer, $db, $teamId);
            if (!$department || !isset($department['level'])) {
                return 0;
            }
            return max(0, min(12, ((int) $department['level']) * 2));
        } catch (Exception $e) {
            return 0;
        }
    }

    private static function youthPlayerBelongsToTeam(WebSoccer $websoccer, DbConnection $db, $playerId, $teamId) {
        $result = $db->querySelect('id', $websoccer->getConfig('db_prefix') . '_youthplayer', 'id = %d AND team_id = %d', array((int) $playerId, (int) $teamId), 1);
        $row = $result->fetch_array();
        $result->free();
        return ($row && isset($row['id']));
    }

    private static function getYouthPlayerTeamId(WebSoccer $websoccer, DbConnection $db, $playerId) {
        $result = $db->querySelect('team_id', $websoccer->getConfig('db_prefix') . '_youthplayer', 'id = %d', (int) $playerId, 1);
        $row = $result->fetch_array();
        $result->free();
        return ($row && isset($row['team_id'])) ? (int) $row['team_id'] : 0;
    }

    private static function getTeam(WebSoccer $websoccer, DbConnection $db, $teamId) {
        if ((int) $teamId < 1) {
            return array();
        }
        $result = $db->querySelect('id, name, user_id, finanz_budget, status', $websoccer->getConfig('db_prefix') . '_verein', 'id = %d', (int) $teamId, 1);
        $row = $result->fetch_array();
        $result->free();
        return $row ? $row : array();
    }

    private static function configInt(WebSoccer $websoccer, $key, $default) {
        $value = $websoccer->getConfig($key);
        if ($value === null || $value === '') {
            return (int) $default;
        }
        return (int) $value;
    }

    private static function configBool(WebSoccer $websoccer, $key, $default) {
        $value = $websoccer->getConfig($key);
        if ($value === null || $value === '') {
            return (bool) $default;
        }
        return ($value == '1' || $value === TRUE);
    }

    private static function now(WebSoccer $websoccer) {
        return $websoccer->getNowAsTimestamp();
    }

    private static function prefix(WebSoccer $websoccer) {
        return $websoccer->getConfig('db_prefix') . '_';
    }

    private static function academyTable(WebSoccer $websoccer) {
        return self::prefix($websoccer) . 'youth_academy';
    }

    private static function levelTable(WebSoccer $websoccer) {
        return self::prefix($websoccer) . 'youth_academy_level';
    }

    private static function logTable(WebSoccer $websoccer) {
        return self::prefix($websoccer) . 'youth_academy_log';
    }

    public static function ensureSchema(WebSoccer $websoccer, DbConnection $db) {
        if (self::$_schemaReady) {
            return;
        }

        $db->executeQuery("CREATE TABLE IF NOT EXISTS " . self::levelTable($websoccer) . " (
            level TINYINT(3) NOT NULL,
            name VARCHAR(100) NOT NULL,
            build_cost INT(10) NOT NULL DEFAULT 0,
            maintenance_fee INT(10) NOT NULL DEFAULT 0,
            development_bonus TINYINT(3) NOT NULL DEFAULT 0,
            scouting_bonus TINYINT(3) NOT NULL DEFAULT 0,
            stagnation_reduction TINYINT(3) NOT NULL DEFAULT 0,
            reputation_bonus TINYINT(3) NOT NULL DEFAULT 0,
            max_reputation TINYINT(3) NOT NULL DEFAULT 100,
            status ENUM('1','0') NOT NULL DEFAULT '1',
            PRIMARY KEY (level)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        $db->executeQuery("CREATE TABLE IF NOT EXISTS " . self::academyTable($websoccer) . " (
            team_id INT(10) NOT NULL,
            level TINYINT(3) NOT NULL DEFAULT 1,
            focus ENUM('technique','physical','mental','balanced') NOT NULL DEFAULT 'balanced',
            reputation TINYINT(3) NOT NULL DEFAULT 50,
            youth_captain_id INT(10) NOT NULL DEFAULT 0,
            missed_payments TINYINT(3) NOT NULL DEFAULT 0,
            last_cost_match_id INT(10) NOT NULL DEFAULT 0,
            last_report_date INT(11) NOT NULL DEFAULT 0,
            created_date INT(11) NOT NULL DEFAULT 0,
            updated_date INT(11) NOT NULL DEFAULT 0,
            status ENUM('1','0') NOT NULL DEFAULT '1',
            PRIMARY KEY (team_id),
            KEY idx_youth_academy_level (level),
            KEY idx_youth_academy_captain (youth_captain_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        $db->executeQuery("CREATE TABLE IF NOT EXISTS " . self::logTable($websoccer) . " (
            id INT(10) NOT NULL AUTO_INCREMENT,
            team_id INT(10) NOT NULL,
            player_id INT(10) NOT NULL DEFAULT 0,
            type ENUM('development','scouting','risk','system') NOT NULL DEFAULT 'system',
            old_strength SMALLINT(5) NOT NULL DEFAULT 0,
            new_strength SMALLINT(5) NOT NULL DEFAULT 0,
            change_amount SMALLINT(5) NOT NULL DEFAULT 0,
            message VARCHAR(100) NOT NULL,
            created_date INT(11) NOT NULL DEFAULT 0,
            match_id INT(10) NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY idx_youth_academy_log_team_date (team_id, created_date),
            KEY idx_youth_academy_log_player (player_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        self::ensureDefaultLevels($websoccer, $db);
        self::$_schemaReady = true;
    }

    private static function ensureDefaultLevels(WebSoccer $websoccer, DbConnection $db) {
        $result = $db->querySelect('COUNT(*) AS qty', self::levelTable($websoccer), '1=1');
        $row = $result->fetch_array();
        $result->free();
        if ($row && (int) $row['qty'] > 0) {
            return;
        }

        $defaults = array(
            array(1, 'Basis-Akademie', 250000, 25000, 1, 1, 1, 1, 65),
            array(2, 'Regionale Akademie', 750000, 60000, 2, 3, 2, 1, 72),
            array(3, 'Leistungszentrum', 2000000, 120000, 4, 5, 4, 2, 80),
            array(4, 'Elite-Akademie', 5000000, 250000, 6, 8, 6, 2, 90),
            array(5, 'Weltklasse-Akademie', 12000000, 500000, 9, 12, 9, 3, 100)
        );

        foreach ($defaults as $level) {
            $db->queryInsert(array(
                'level' => $level[0],
                'name' => $level[1],
                'build_cost' => $level[2],
                'maintenance_fee' => $level[3],
                'development_bonus' => $level[4],
                'scouting_bonus' => $level[5],
                'stagnation_reduction' => $level[6],
                'reputation_bonus' => $level[7],
                'max_reputation' => $level[8],
                'status' => '1'
            ), self::levelTable($websoccer));
        }
    }
}
?>
