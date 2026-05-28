<?php
/******************************************************

  This file is part of OpenWebSoccer-Sim.

******************************************************/

/**
 * Lightweight club staff system.
 *
 * One staff member per role can be assigned to a human managed club.
 * Staff members give intentionally small bonuses and cost salary per matchday.
 */
class ClubStaffDataService {

    const ROLE_ASSISTANT_MANAGER = 'assistant_manager';
    const ROLE_GOALKEEPING_COACH = 'goalkeeping_coach';
    const ROLE_FITNESS_COACH = 'fitness_coach';
    const ROLE_YOUTH_COACH = 'youth_coach';
    const ROLE_PHYSIO = 'physio';
    const ROLE_MARKETING_MANAGER = 'marketing_manager';
    const ROLE_FINANCIAL_ADVISOR = 'financial_advisor';
    const CONFIG_MARKER_NAME = 'club_staff_matchday';

    private static $_schemaReady = false;
    private static $_staffCache = array();

    public static function isEnabled(WebSoccer $websoccer) {
        $value = $websoccer->getConfig('club_staff_enabled');
        return ($value === null || $value === '' || $value == '1' || $value === TRUE);
    }

    public static function getRoles() {
        return array(
            self::ROLE_ASSISTANT_MANAGER,
            self::ROLE_GOALKEEPING_COACH,
            self::ROLE_FITNESS_COACH,
            self::ROLE_YOUTH_COACH,
            self::ROLE_PHYSIO,
            self::ROLE_MARKETING_MANAGER,
            self::ROLE_FINANCIAL_ADVISOR
        );
    }

    public static function isValidRole($role) {
        return in_array((string) $role, self::getRoles(), true);
    }

    public static function getPageData(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $teamId, $userId) {
        self::ensureSchema($websoccer, $db);

        $team = self::getTeam($websoccer, $db, $teamId);
        if (!$team || (int) $team['user_id'] < 1 || (int) $team['user_id'] !== (int) $userId) {
            return array(
                'enabled' => self::isEnabled($websoccer),
                'human_team' => false,
                'team' => array(),
                'hired_staff' => array(),
                'available_staff' => array(),
                'effects' => array(),
                'total_salary' => 0
            );
        }

        $hired = self::getHiredStaff($websoccer, $db, $teamId);
        return array(
            'enabled' => self::isEnabled($websoccer),
            'human_team' => true,
            'team' => $team,
            'hired_staff' => $hired,
            'available_staff' => self::getAvailableStaffGrouped($websoccer, $db, $teamId),
            'effects' => self::getEffectsForTemplate($hired),
            'total_salary' => self::sumSalary($hired),
            'roles' => self::getRoles()
        );
    }

    public static function getStaffById(WebSoccer $websoccer, DbConnection $db, $staffId) {
        self::ensureSchema($websoccer, $db);
        $result = $db->querySelect('*', self::staffTable($websoccer), "id = %d", (int) $staffId, 1);
        $row = $result->fetch_array();
        $result->free();
        return $row ? self::normalizeStaff($row) : array();
    }

    public static function hireStaff(WebSoccer $websoccer, DbConnection $db, $teamId, $userId, $staffId) {
        self::ensureSchema($websoccer, $db);

        if (!self::isEnabled($websoccer)) {
            throw new Exception('clubstaff_error_disabled');
        }

        self::assertHumanTeam($websoccer, $db, $teamId, $userId);
        $staff = self::getStaffById($websoccer, $db, $staffId);
        if (!$staff || !isset($staff['id'])) {
            throw new Exception('clubstaff_error_staff_not_found');
        }
        if ($staff['active'] !== '1') {
            throw new Exception('clubstaff_error_staff_inactive');
        }
        if (!self::isValidRole($staff['role'])) {
            throw new Exception('clubstaff_error_invalid_role');
        }

        if (self::hasRole($websoccer, $db, $teamId, $staff['role'])) {
            throw new Exception('clubstaff_error_role_taken');
        }

        $db->queryInsert(array(
            'team_id' => (int) $teamId,
            'role' => $staff['role'],
            'staff_id' => (int) $staff['id'],
            'hired_date' => $websoccer->getNowAsTimestamp()
        ), self::assignmentTable($websoccer));

        self::clearCache();
    }

    public static function fireStaff(WebSoccer $websoccer, DbConnection $db, $teamId, $userId, $role) {
        self::ensureSchema($websoccer, $db);

        if (!self::isEnabled($websoccer)) {
            throw new Exception('clubstaff_error_disabled');
        }
        if (!self::isValidRole($role)) {
            throw new Exception('clubstaff_error_invalid_role');
        }

        self::assertHumanTeam($websoccer, $db, $teamId, $userId);
        if (!self::hasRole($websoccer, $db, $teamId, $role)) {
            throw new Exception('clubstaff_error_not_hired');
        }

        $db->queryDelete(self::assignmentTable($websoccer), 'team_id = %d AND role = \'%s\'', array((int) $teamId, $role));
        self::clearCache();
    }

    public static function getHiredStaff(WebSoccer $websoccer, DbConnection $db, $teamId) {
        self::ensureSchema($websoccer, $db);
        $prefix = $websoccer->getConfig('db_prefix');
        $columns = array(
            'A.team_id' => 'team_id',
            'A.role' => 'role',
            'A.staff_id' => 'staff_id',
            'A.hired_date' => 'hired_date',
            'S.name' => 'name',
            'S.level' => 'level',
            'S.salary' => 'salary',
            'S.bonus' => 'bonus',
            'S.description' => 'description',
            'S.active' => 'active'
        );
        $from = $prefix . '_club_staff_assignment AS A INNER JOIN ' . $prefix . '_club_staff AS S ON S.id = A.staff_id';
        $result = $db->querySelect($columns, $from, 'A.team_id = %d ORDER BY FIELD(A.role, \'assistant_manager\',\'goalkeeping_coach\',\'fitness_coach\',\'youth_coach\',\'physio\',\'marketing_manager\',\'financial_advisor\')', (int) $teamId);

        $rows = array();
        while ($row = $result->fetch_array()) {
            $rows[] = self::normalizeStaff($row);
        }
        $result->free();
        return $rows;
    }

    public static function getAvailableStaffGrouped(WebSoccer $websoccer, DbConnection $db, $teamId) {
        self::ensureSchema($websoccer, $db);
        $taken = array();
        foreach (self::getHiredStaff($websoccer, $db, $teamId) as $staff) {
            $taken[$staff['role']] = true;
        }

        $result = $db->querySelect('*', self::staffTable($websoccer), "active = '1' ORDER BY role ASC, salary ASC, level ASC, name ASC");
        $grouped = array();
        while ($row = $result->fetch_array()) {
            $staff = self::normalizeStaff($row);
            if (isset($taken[$staff['role']])) {
                continue;
            }
            if (!isset($grouped[$staff['role']])) {
                $grouped[$staff['role']] = array();
            }
            $grouped[$staff['role']][] = $staff;
        }
        $result->free();

        $ordered = array();
        foreach (self::getRoles() as $role) {
            if (isset($grouped[$role])) {
                $ordered[$role] = $grouped[$role];
            }
        }
        return $ordered;
    }

    public static function getRoleBonus(WebSoccer $websoccer, DbConnection $db, $teamId, $role) {
        if (!self::isEnabled($websoccer) || !self::isValidRole($role) || (int) $teamId < 1) {
            return 0;
        }

        $key = (int) $teamId . ':' . $role;
        if (isset(self::$_staffCache[$key])) {
            return self::$_staffCache[$key];
        }

        self::ensureSchema($websoccer, $db);
        $prefix = $websoccer->getConfig('db_prefix');
        $columns = 'COALESCE(S.bonus, 0) AS bonus';
        $from = $prefix . '_club_staff_assignment AS A INNER JOIN ' . $prefix . '_club_staff AS S ON S.id = A.staff_id';
        $result = $db->querySelect($columns, $from, "A.team_id = %d AND A.role = '%s' AND S.active = '1'", array((int) $teamId, $role), 1);
        $row = $result->fetch_array();
        $result->free();

        $bonus = ($row && isset($row['bonus'])) ? (int) $row['bonus'] : 0;
        self::$_staffCache[$key] = max(0, min(25, $bonus));
        return self::$_staffCache[$key];
    }

    public static function getMarketingAttendanceFactor(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $bonus = self::getRoleBonus($websoccer, $db, $teamId, self::ROLE_MARKETING_MANAGER);
        return 1 + (min(10, $bonus) / 200); // 5% at bonus 10.
    }

    public static function getMerchandisingFactor(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $bonus = self::getRoleBonus($websoccer, $db, $teamId, self::ROLE_MARKETING_MANAGER);
        return 1 + (min(10, $bonus) / 100); // 10% at bonus 10.
    }

    public static function getCreditScoreBonus(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $bonus = self::getRoleBonus($websoccer, $db, $teamId, self::ROLE_FINANCIAL_ADVISOR);
        return (int) round(min(10, $bonus) * 0.8); // max +8 score.
    }

    public static function getLoanInterestDiscount(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $bonus = self::getRoleBonus($websoccer, $db, $teamId, self::ROLE_FINANCIAL_ADVISOR);
        return min(1.00, max(0, $bonus * 0.05)); // 0.25 points at bonus 5.
    }

    public static function getChemistryStaffScore(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $assistant = self::getRoleBonus($websoccer, $db, $teamId, self::ROLE_ASSISTANT_MANAGER);
        if ($assistant <= 0) {
            return array('score' => 50, 'detail' => '');
        }
        $score = max(50, min(100, 50 + ($assistant * 4)));
        return array('score' => $score, 'detail' => '+' . (int) $assistant . '%');
    }

    public static function applyTrainingEventBonuses(PlayerTrainedEvent $event) {
        if (!self::isEnabled($event->websoccer) || (int) $event->teamId < 1) {
            return;
        }

        $db = $event->db;
        $websoccer = $event->websoccer;
        $teamId = (int) $event->teamId;

        $fitness = self::getRoleBonus($websoccer, $db, $teamId, self::ROLE_FITNESS_COACH) / 100;
        if ($fitness > 0) {
            if ($event->effectStamina > 0) $event->effectStamina += $event->effectStamina * $fitness;
            if ($event->effectPace > 0) $event->effectPace += $event->effectPace * $fitness;
            if ($event->effectFreshness > 0) $event->effectFreshness += $event->effectFreshness * ($fitness / 2);
        }

        $goalkeeping = self::getRoleBonus($websoccer, $db, $teamId, self::ROLE_GOALKEEPING_COACH) / 100;
        if ($goalkeeping > 0) {
            if ($event->effectPenaltyKilling > 0) $event->effectPenaltyKilling += $event->effectPenaltyKilling * $goalkeeping;
        }

        $assistant = self::getRoleBonus($websoccer, $db, $teamId, self::ROLE_ASSISTANT_MANAGER) / 100;
        if ($assistant > 0) {
            if ($event->effectInfluence > 0) $event->effectInfluence += $event->effectInfluence * $assistant;
            if ($event->effectPassing > 0) $event->effectPassing += $event->effectPassing * ($assistant / 2);
            if ($event->effectTackling > 0) $event->effectTackling += $event->effectTackling * ($assistant / 2);
        }

        $physio = self::getRoleBonus($websoccer, $db, $teamId, self::ROLE_PHYSIO) / 100;
        if ($physio > 0 && $event->effectFreshness < 0) {
            $event->effectFreshness = $event->effectFreshness * (1 - min(0.25, $physio));
        }
    }

    public static function applyYouthPlayerPlayedBonus(YouthPlayerPlayedEvent $event) {
        if (!$event->player || (int) $event->player->id < 1) {
            return;
        }

        $teamId = self::getYouthPlayerTeamId($event->websoccer, $event->db, (int) $event->player->id);
        if ($teamId < 1) {
            return;
        }

        $bonus = self::getRoleBonus($event->websoccer, $event->db, $teamId, self::ROLE_YOUTH_COACH);
        if ($bonus <= 0 || $event->strengthChange <= 0) {
            return;
        }

        $chance = min(35, $bonus * 3);
        if (mt_rand(1, 100) <= $chance) {
            $event->strengthChange += 1;
        }
    }

    public static function applyYouthPlayerScoutedBonus(YouthPlayerScoutedEvent $event) {
        $teamId = (int) $event->teamId;
        $playerId = (int) $event->playerId;
        if ($teamId < 1 || $playerId < 1) {
            return;
        }

        $bonus = self::getRoleBonus($event->websoccer, $event->db, $teamId, self::ROLE_YOUTH_COACH);
        if ($bonus <= 0) {
            return;
        }

        $chance = min(30, $bonus * 2);
        if (mt_rand(1, 100) > $chance) {
            return;
        }

        $prefix = $event->websoccer->getConfig('db_prefix');
        $result = $event->db->querySelect('strength', $prefix . '_youthplayer', 'id = %d AND team_id = %d', array($playerId, $teamId), 1);
        $player = $result->fetch_array();
        $result->free();
        if (!$player) {
            return;
        }

        $maxStrength = (int) $event->websoccer->getConfig('youth_scouting_max_strength');
        if ($maxStrength <= 0) {
            $maxStrength = 100;
        }
        $newStrength = min($maxStrength, ((int) $player['strength']) + 1);
        if ($newStrength !== (int) $player['strength']) {
            $event->db->queryUpdate(array('strength' => $newStrength, 'strength_last_change' => 1), $prefix . '_youthplayer', 'id = %d', $playerId);
        }
    }

    public static function applyMatchCompletedRecovery(MatchCompletedEvent $event) {
        if (!$event->match) {
            return;
        }
        $teamIds = array();
        if ($event->match->homeTeam && !$event->match->homeTeam->isNationalTeam) {
            $teamIds[] = (int) $event->match->homeTeam->id;
        }
        if ($event->match->guestTeam && !$event->match->guestTeam->isNationalTeam) {
            $teamIds[] = (int) $event->match->guestTeam->id;
        }
        $teamIds = array_unique($teamIds);
        foreach ($teamIds as $teamId) {
            self::applyPhysioRecovery($event->websoccer, $event->db, $teamId);
        }
    }

    public static function applyPhysioRecovery(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $bonus = self::getRoleBonus($websoccer, $db, $teamId, self::ROLE_PHYSIO);
        if ($bonus <= 0) {
            return;
        }

        $chance = min(30, $bonus * 2);
        $result = $db->querySelect('id, verletzt', $websoccer->getConfig('db_prefix') . '_spieler', "verein_id = %d AND status = '1' AND verletzt > 0", (int) $teamId);
        while ($player = $result->fetch_array()) {
            if (mt_rand(1, 100) <= $chance) {
                $db->queryUpdate(array('verletzt' => max(0, ((int) $player['verletzt']) - 1)), $websoccer->getConfig('db_prefix') . '_spieler', 'id = %d', (int) $player['id']);
            }
        }
        $result->free();
    }

    public static function processMatchdaySalaries(WebSoccer $websoccer, DbConnection $db) {
        self::ensureSchema($websoccer, $db);
        if (!self::isEnabled($websoccer)) {
            return array('processed' => 0, 'amount' => 0, 'skipped' => true);
        }

        $prefix = $websoccer->getConfig('db_prefix');
        $lastProcessed = self::getLastProcessedMatchId($websoccer, $db);
        $latestCompleted = self::getLatestCompletedMatchId($websoccer, $db);
        if ($latestCompleted <= 0 || $latestCompleted <= $lastProcessed) {
            return array('processed' => 0, 'amount' => 0, 'skipped' => true, 'last' => $lastProcessed, 'latest' => $latestCompleted);
        }

        $columns = array('T.id' => 'team_id', 'SUM(S.salary)' => 'salary_sum');
        $from = $prefix . '_verein AS T INNER JOIN ' . $prefix . '_club_staff_assignment AS A ON A.team_id = T.id INNER JOIN ' . $prefix . '_club_staff AS S ON S.id = A.staff_id';
        $result = $db->querySelect($columns, $from, "T.user_id > 0 AND T.status = '1' AND S.active = '1' GROUP BY T.id");
        $processed = 0;
        $total = 0;
        while ($row = $result->fetch_array()) {
            $teamId = (int) $row['team_id'];
            $salary = (int) $row['salary_sum'];
            if ($teamId < 1 || $salary <= 0) {
                continue;
            }
            BankAccountDataService::debitAmount($websoccer, $db, $teamId, $salary, 'clubstaff_account_salary_subject', 'clubstaff_account_sender');
            $processed++;
            $total += $salary;
        }
        $result->free();

        self::setLastProcessedMatchId($websoccer, $db, $latestCompleted);
        return array('processed' => $processed, 'amount' => $total, 'skipped' => false, 'last' => $lastProcessed, 'latest' => $latestCompleted);
    }

    private static function assertHumanTeam(WebSoccer $websoccer, DbConnection $db, $teamId, $userId) {
        $team = self::getTeam($websoccer, $db, $teamId);
        if (!$team) {
            throw new Exception('clubstaff_error_no_team');
        }
        if ((int) $team['user_id'] < 1 || (int) $team['user_id'] !== (int) $userId) {
            throw new Exception('clubstaff_error_human_only');
        }
    }

    private static function hasRole(WebSoccer $websoccer, DbConnection $db, $teamId, $role) {
        $result = $db->querySelect('staff_id', self::assignmentTable($websoccer), "team_id = %d AND role = '%s'", array((int) $teamId, $role), 1);
        $row = $result->fetch_array();
        $result->free();
        return ($row && isset($row['staff_id']));
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

    private static function getYouthPlayerTeamId(WebSoccer $websoccer, DbConnection $db, $playerId) {
        $result = $db->querySelect('team_id', $websoccer->getConfig('db_prefix') . '_youthplayer', 'id = %d', (int) $playerId, 1);
        $row = $result->fetch_array();
        $result->free();
        return ($row && isset($row['team_id'])) ? (int) $row['team_id'] : 0;
    }

    private static function normalizeStaff($row) {
        $row['id'] = isset($row['id']) ? (int) $row['id'] : (isset($row['staff_id']) ? (int) $row['staff_id'] : 0);
        $row['staff_id'] = isset($row['staff_id']) ? (int) $row['staff_id'] : $row['id'];
        $row['salary'] = isset($row['salary']) ? (int) $row['salary'] : 0;
        $row['bonus'] = isset($row['bonus']) ? (int) $row['bonus'] : 0;
        $row['level'] = isset($row['level']) ? (int) $row['level'] : 1;
        $row['description'] = isset($row['description']) ? $row['description'] : '';
        $row['active'] = isset($row['active']) ? $row['active'] : '1';
        return $row;
    }

    private static function getEffectsForTemplate($hired) {
        $effects = array();
        foreach ($hired as $staff) {
            $effects[] = array(
                'role' => $staff['role'],
                'bonus' => (int) $staff['bonus'],
                'message_key' => 'clubstaff_effect_' . $staff['role']
            );
        }
        return $effects;
    }

    private static function sumSalary($hired) {
        $sum = 0;
        foreach ($hired as $staff) {
            $sum += (int) $staff['salary'];
        }
        return $sum;
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
            'descr' => 'Club staff matchday marker'
        ), $websoccer->getConfig('db_prefix') . '_config');
    }

    public static function ensureSchema(WebSoccer $websoccer, DbConnection $db) {
        if (self::$_schemaReady) {
            return;
        }

        $prefix = $websoccer->getConfig('db_prefix');
        $staffTable = $prefix . '_club_staff';
        $assignmentTable = $prefix . '_club_staff_assignment';

        $db->executeQuery("CREATE TABLE IF NOT EXISTS " . $staffTable . " (
            id INT(10) NOT NULL AUTO_INCREMENT,
            name VARCHAR(100) NOT NULL,
            role ENUM('assistant_manager','goalkeeping_coach','fitness_coach','youth_coach','physio','marketing_manager','financial_advisor') NOT NULL,
            level TINYINT(3) NOT NULL DEFAULT 1,
            salary INT(10) NOT NULL DEFAULT 0,
            bonus TINYINT(3) NOT NULL DEFAULT 2,
            description VARCHAR(255) DEFAULT NULL,
            active ENUM('1','0') NOT NULL DEFAULT '1',
            PRIMARY KEY (id),
            KEY idx_club_staff_role_active (role, active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        $db->executeQuery("CREATE TABLE IF NOT EXISTS " . $assignmentTable . " (
            team_id INT(10) NOT NULL,
            role ENUM('assistant_manager','goalkeeping_coach','fitness_coach','youth_coach','physio','marketing_manager','financial_advisor') NOT NULL,
            staff_id INT(10) NOT NULL,
            hired_date INT(11) NOT NULL DEFAULT 0,
            PRIMARY KEY (team_id, role),
            KEY idx_club_staff_assignment_staff (staff_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        self::ensureMarkerExists($websoccer, $db);
        self::$_schemaReady = true;
    }

    private static function staffTable(WebSoccer $websoccer) {
        return $websoccer->getConfig('db_prefix') . '_club_staff';
    }

    private static function assignmentTable(WebSoccer $websoccer) {
        return $websoccer->getConfig('db_prefix') . '_club_staff_assignment';
    }

    private static function clearCache() {
        self::$_staffCache = array();
    }
    
    public static function processMatchCompletedSalaries(MatchCompletedEvent $event) {
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
    
        foreach (self::getMatchTeamIdsFromEvent($event) as $teamId) {
            self::processMatchdaySalaryForTeam($event->websoccer, $event->db, $teamId, $matchId);
        }
    }
    
    private static function getMatchTeamIdsFromEvent(MatchCompletedEvent $event) {
        $teamIds = array();
    
        if ($event->match->homeTeam && !$event->match->homeTeam->isNationalTeam) {
            $teamIds[] = (int) $event->match->homeTeam->id;
        }
    
        if ($event->match->guestTeam && !$event->match->guestTeam->isNationalTeam) {
            $teamIds[] = (int) $event->match->guestTeam->id;
        }
    
        return array_unique($teamIds);
    }
    
    public static function processMatchdaySalaryForTeam(WebSoccer $websoccer, DbConnection $db, $teamId, $matchId) {
        self::ensureSchema($websoccer, $db);
    
        $teamId = (int) $teamId;
        $matchId = (int) $matchId;
    
        if ($teamId < 1 || $matchId < 1 || !self::isEnabled($websoccer)) {
            return 0;
        }
    
        $markerName = self::getTeamSalaryMarkerName($teamId);
        if (self::getTeamSalaryMarker($websoccer, $db, $markerName) >= $matchId) {
            return 0;
        }
    
        $prefix = $websoccer->getConfig('db_prefix');
    
        $from = $prefix . '_verein AS T'
            . ' INNER JOIN ' . $prefix . '_club_staff_assignment AS A ON A.team_id = T.id'
            . ' INNER JOIN ' . $prefix . '_club_staff AS S ON S.id = A.staff_id';
    
        $result = $db->querySelect(
            'SUM(S.salary) AS salary_sum',
            $from,
            "T.id = %d AND T.user_id > 0 AND T.status = '1' AND S.active = '1'",
            $teamId,
            1
        );
    
        $row = $result->fetch_array();
        $result->free();
    
        $salary = ($row && isset($row['salary_sum'])) ? (int) $row['salary_sum'] : 0;
    
        if ($salary > 0) {
            BankAccountDataService::debitAmount(
                $websoccer,
                $db,
                $teamId,
                $salary,
                'clubstaff_account_salary_subject',
                'clubstaff_account_sender'
            );
        }
    
        self::setTeamSalaryMarker($websoccer, $db, $markerName, $matchId);
    
        return $salary;
    }
    
    private static function getTeamSalaryMarkerName($teamId) {
        return 'staffmd_' . (int) $teamId;
    }
    
    private static function getTeamSalaryMarker(WebSoccer $websoccer, DbConnection $db, $markerName) {
        self::ensureTeamSalaryMarker($websoccer, $db, $markerName);
    
        $result = $db->querySelect(
            'zeitstempel',
            $websoccer->getConfig('db_prefix') . '_config',
            "name = '%s'",
            $markerName,
            1
        );
    
        $row = $result->fetch_array();
        $result->free();
    
        return ($row && isset($row['zeitstempel'])) ? (int) $row['zeitstempel'] : 0;
    }
    
    private static function setTeamSalaryMarker(WebSoccer $websoccer, DbConnection $db, $markerName, $matchId) {
        self::ensureTeamSalaryMarker($websoccer, $db, $markerName);
    
        $db->queryUpdate(
            array('zeitstempel' => (int) $matchId),
            $websoccer->getConfig('db_prefix') . '_config',
            "name = '%s'",
            $markerName
        );
    }
    
    private static function ensureTeamSalaryMarker(WebSoccer $websoccer, DbConnection $db, $markerName) {
        $result = $db->querySelect(
            'id',
            $websoccer->getConfig('db_prefix') . '_config',
            "name = '%s'",
            $markerName,
            1
        );
    
        $row = $result->fetch_array();
        $result->free();
    
        if ($row && isset($row['id'])) {
            return;
        }
    
        $db->queryInsert(
            array(
                'name' => $markerName,
                'zeitstempel' => 0,
                'descr' => 'Staff team marker'
            ),
            $websoccer->getConfig('db_prefix') . '_config'
        );
    }
}
?>
