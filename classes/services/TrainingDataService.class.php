<?php
/******************************************************

  This file is part of OpenWebSoccer-Sim.

  OpenWebSoccer-Sim is free software: you can redistribute it 
  and/or modify it under the terms of the 
  GNU Lesser General Public License 
  as published by the Free Software Foundation, either version 3 of
  the License, or any later version.

  OpenWebSoccer-Sim is distributed in the hope that it will be
  useful, but WITHOUT ANY WARRANTY; without even the implied
  warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. 
  See the GNU Lesser General Public License for more details.

  You should have received a copy of the GNU Lesser General Public 
  License along with OpenWebSoccer-Sim.  
  If not, see <http://www.gnu.org/licenses/>.

******************************************************/

/**
 * Data service for training data.
 */
class TrainingDataService {

    const DEFAULT_PLAN_SLOTS = 5;
    private static $_schemaReady = false;

    public static function countTrainers(WebSoccer $websoccer, DbConnection $db) {
        self::ensureAdvancedTrainingSchema($websoccer, $db);
        $fromTable = $websoccer->getConfig("db_prefix") . "_trainer";

        $result = $db->querySelect("COUNT(*) AS hits", $fromTable, "available = '1'");
        $trainers = $result->fetch_array();
        $result->free();

        return $trainers["hits"];
    }

    public static function getTrainers(WebSoccer $websoccer, DbConnection $db, $startIndex, $entries_per_page) {
        self::ensureAdvancedTrainingSchema($websoccer, $db);
        $fromTable = $websoccer->getConfig("db_prefix") . "_trainer";
        $limit = $startIndex . "," . $entries_per_page;

        $trainers = array();
        $result = $db->querySelect("*", $fromTable, "available = '1' ORDER BY reputation DESC, salary DESC", null, $limit);
        while ($trainer = $result->fetch_array()) {
            $trainers[] = self::normalizeTrainerRow($trainer);
        }
        $result->free();

        return $trainers;
    }

    public static function getTrainerById(WebSoccer $websoccer, DbConnection $db, $trainerId) {
        self::ensureAdvancedTrainingSchema($websoccer, $db);
        $fromTable = $websoccer->getConfig("db_prefix") . "_trainer";

        $result = $db->querySelect("*", $fromTable, "id = %d", $trainerId, 1);
        $trainer = $result->fetch_array();
        $result->free();

        return $trainer ? self::normalizeTrainerRow($trainer) : array();
    }

    public static function countRemainingTrainingUnits(WebSoccer $websoccer, DbConnection $db, $teamId) {
        self::ensureAdvancedTrainingSchema($websoccer, $db);
        $columns = "COUNT(*) AS hits";
        $fromTable = $websoccer->getConfig("db_prefix") . "_training_unit";
        $whereCondition = "team_id = %d AND (date_executed = 0 OR date_executed IS NULL)";

        $result = $db->querySelect($columns, $fromTable, $whereCondition, $teamId);
        $units = $result->fetch_array();
        $result->free();

        return $units["hits"];
    }

    public static function getLatestTrainingExecutionTime(WebSoccer $websoccer, DbConnection $db, $teamId) {
        self::ensureAdvancedTrainingSchema($websoccer, $db);
        $columns = "date_executed";
        $fromTable = $websoccer->getConfig("db_prefix") . "_training_unit";
        $whereCondition = "team_id = %d AND date_executed > 0 ORDER BY date_executed DESC";

        $result = $db->querySelect($columns, $fromTable, $whereCondition, $teamId, 1);
        $unit = $result->fetch_array();
        $result->free();

        return isset($unit["date_executed"]) ? (int) $unit["date_executed"] : 0;
    }

    public static function getValidTrainingUnit(WebSoccer $websoccer, DbConnection $db, $teamId) {
        self::ensureAdvancedTrainingSchema($websoccer, $db);
        $columns = "id, trainer_id, focus, intensity, date_executed";
        $fromTable = $websoccer->getConfig("db_prefix") . "_training_unit";
        $whereCondition = "team_id = %d AND (date_executed = 0 OR date_executed IS NULL) ORDER BY id ASC";

        $result = $db->querySelect($columns, $fromTable, $whereCondition, $teamId, 1);
        $unit = $result->fetch_array();
        $result->free();

        return $unit ? $unit : array();
    }

    public static function getTrainingUnitById(WebSoccer $websoccer, DbConnection $db, $teamId, $unitId) {
        self::ensureAdvancedTrainingSchema($websoccer, $db);
        $fromTable = $websoccer->getConfig("db_prefix") . "_training_unit";
        $result = $db->querySelect("*", $fromTable, "id = %d AND team_id = %d", array($unitId, $teamId), 1);
        $unit = $result->fetch_array();
        $result->free();

        return $unit ? $unit : array();
    }

    public static function getTrainingTypes() {
        return array(
            'regeneration' => array('label_key' => 'training_type_regeneration'),
            'technique' => array('label_key' => 'training_type_technique'),
            'passing' => array('label_key' => 'training_type_passing'),
            'finishing' => array('label_key' => 'training_type_finishing'),
            'setpieces' => array('label_key' => 'training_type_setpieces'),
            'defense' => array('label_key' => 'training_type_defense'),
            'athletics' => array('label_key' => 'training_type_athletics'),
            'teambuilding' => array('label_key' => 'training_type_teambuilding'),
            'matchprep' => array('label_key' => 'training_type_matchprep'),
            'goalkeeper' => array('label_key' => 'training_type_goalkeeper')
        );
    }

    public static function getTrainerSpecializations() {
        return array(
            'balanced' => 'trainer_specialization_balanced',
            'technique' => 'trainer_specialization_technique',
            'fitness' => 'trainer_specialization_fitness',
            'offense' => 'trainer_specialization_offense',
            'defense' => 'trainer_specialization_defense',
            'setpieces' => 'trainer_specialization_setpieces',
            'goalkeeper' => 'trainer_specialization_goalkeeper',
            'mental' => 'trainer_specialization_mental',
            'tactics' => 'trainer_specialization_tactics'
        );
    }

    public static function normalizeTrainingType($trainingType) {
        $trainingType = trim((string) $trainingType);

        // Backwards compatibility for the previous manual training focus values.
        if ($trainingType === 'TE') {
            return 'technique';
        } elseif ($trainingType === 'STA') {
            return 'athletics';
        } elseif ($trainingType === 'MOT') {
            return 'teambuilding';
        } elseif ($trainingType === 'FR') {
            return 'regeneration';
        }

        $types = self::getTrainingTypes();
        return isset($types[$trainingType]) ? $trainingType : 'technique';
    }

    public static function getDefaultPlanSlots() {
        return array(
            1 => array('slot_no' => 1, 'training_type' => 'regeneration', 'intensity' => 40),
            2 => array('slot_no' => 2, 'training_type' => 'passing', 'intensity' => 60),
            3 => array('slot_no' => 3, 'training_type' => 'technique', 'intensity' => 60),
            4 => array('slot_no' => 4, 'training_type' => 'setpieces', 'intensity' => 50),
            5 => array('slot_no' => 5, 'training_type' => 'matchprep', 'intensity' => 45)
        );
    }

    public static function getOrCreateTrainingPlan(WebSoccer $websoccer, DbConnection $db, $teamId) {
        self::ensureAdvancedTrainingSchema($websoccer, $db);
        $plan = self::getTrainingPlan($websoccer, $db, $teamId);
        if (isset($plan['id'])) {
            return $plan;
        }

        $table = $websoccer->getConfig('db_prefix') . '_training_plan';
        $now = $websoccer->getNowAsTimestamp();
        $db->queryInsert(array(
            'team_id' => (int) $teamId,
            'current_slot_no' => 1,
            'last_match_id' => 0,
            'created_date' => $now,
            'updated_date' => $now,
            'status' => '1'
        ), $table);

        $planId = $db->getLastInsertedId();
        foreach (self::getDefaultPlanSlots() as $slot) {
            $db->queryInsert(array(
                'plan_id' => $planId,
                'slot_no' => (int) $slot['slot_no'],
                'training_type' => $slot['training_type'],
                'intensity' => (int) $slot['intensity']
            ), $websoccer->getConfig('db_prefix') . '_training_plan_slot');
        }

        return self::getTrainingPlan($websoccer, $db, $teamId);
    }

    public static function getTrainingPlan(WebSoccer $websoccer, DbConnection $db, $teamId) {
        self::ensureAdvancedTrainingSchema($websoccer, $db);
        $result = $db->querySelect(
            '*',
            $websoccer->getConfig('db_prefix') . '_training_plan',
            "team_id = %d AND status = '1' ORDER BY id DESC",
            (int) $teamId,
            1
        );
        $plan = $result->fetch_array();
        $result->free();

        return $plan ? $plan : array();
    }

    public static function getTrainingPlanSlots(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $plan = self::getOrCreateTrainingPlan($websoccer, $db, $teamId);
        $slots = array();
        $result = $db->querySelect(
            '*',
            $websoccer->getConfig('db_prefix') . '_training_plan_slot',
            'plan_id = %d ORDER BY slot_no ASC',
            (int) $plan['id']
        );
        while ($slot = $result->fetch_array()) {
            $slot['training_type'] = self::normalizeTrainingType($slot['training_type']);
            $slot['intensity'] = self::normalizeIntensity($slot['intensity']);
            $slots[(int) $slot['slot_no']] = $slot;
        }
        $result->free();

        $defaults = self::getDefaultPlanSlots();
        for ($slotNo = 1; $slotNo <= self::DEFAULT_PLAN_SLOTS; $slotNo++) {
            if (!isset($slots[$slotNo])) {
                $slots[$slotNo] = $defaults[$slotNo];
            }
        }

        ksort($slots);
        return $slots;
    }

    public static function saveTrainingPlan(WebSoccer $websoccer, DbConnection $db, $teamId, $slots) {
        self::ensureAdvancedTrainingSchema($websoccer, $db);
        $plan = self::getOrCreateTrainingPlan($websoccer, $db, $teamId);
        $planId = (int) $plan['id'];

        $slotTable = $websoccer->getConfig('db_prefix') . '_training_plan_slot';
        $db->queryDelete($slotTable, 'plan_id = %d', $planId);

        for ($slotNo = 1; $slotNo <= self::DEFAULT_PLAN_SLOTS; $slotNo++) {
            $slot = isset($slots[$slotNo]) ? $slots[$slotNo] : array();
            $trainingType = isset($slot['training_type']) ? self::normalizeTrainingType($slot['training_type']) : self::getDefaultPlanSlots()[$slotNo]['training_type'];
            $intensity = isset($slot['intensity']) ? self::normalizeIntensity($slot['intensity']) : self::getDefaultPlanSlots()[$slotNo]['intensity'];

            $db->queryInsert(array(
                'plan_id' => $planId,
                'slot_no' => $slotNo,
                'training_type' => $trainingType,
                'intensity' => $intensity
            ), $slotTable);
        }

        $db->queryUpdate(array(
            'updated_date' => $websoccer->getNowAsTimestamp()
        ), $websoccer->getConfig('db_prefix') . '_training_plan', 'id = %d', $planId);
    }

    public static function processAutomaticTrainingMatchday(WebSoccer $websoccer, DbConnection $db, I18n $i18n) {
        self::ensureAdvancedTrainingSchema($websoccer, $db);
        if (!self::isAdvancedTrainingEnabled($websoccer)) {
            return array('processed' => 0, 'skipped_no_match' => 0, 'skipped_no_units' => 0, 'skipped_camp' => 0);
        }

        $prefix = $websoccer->getConfig('db_prefix');
        $result = $db->querySelect(
            'id, user_id',
            $prefix . '_verein',
            "user_id > 0 AND status = '1' ORDER BY id ASC"
        );

        $summary = array('processed' => 0, 'skipped_no_match' => 0, 'skipped_no_units' => 0, 'skipped_camp' => 0);
        while ($team = $result->fetch_array()) {
            $teamResult = self::processAutomaticTrainingForTeam($websoccer, $db, $i18n, (int) $team['id']);
            if (isset($summary[$teamResult])) {
                $summary[$teamResult]++;
            }
        }
        $result->free();

        return $summary;
    }

    public static function processAutomaticTrainingForTeam(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $teamId) {
        self::ensureAdvancedTrainingSchema($websoccer, $db);
        $plan = self::getOrCreateTrainingPlan($websoccer, $db, $teamId);
        $latestMatch = self::getLatestCompletedMatchForTeam($websoccer, $db, $teamId);
        if (!isset($latestMatch['id']) || (int) $latestMatch['id'] <= (int) $plan['last_match_id']) {
            return 'skipped_no_match';
        }

        if (self::isTeamInTrainingCamp($websoccer, $db, $teamId)) {
            return 'skipped_camp';
        }

        $unit = self::getValidTrainingUnit($websoccer, $db, $teamId);
        if (!isset($unit['id'])) {
            $db->queryUpdate(array('last_match_id' => (int) $latestMatch['id']), $websoccer->getConfig('db_prefix') . '_training_plan', 'id = %d', (int) $plan['id']);
            return 'skipped_no_units';
        }

        $trainer = self::getTrainerById($websoccer, $db, $unit['trainer_id']);
        if (!isset($trainer['id'])) {
            return 'skipped_no_units';
        }

        $slots = self::getTrainingPlanSlots($websoccer, $db, $teamId);
        $slotNo = (int) $plan['current_slot_no'];
        if ($slotNo < 1 || $slotNo > self::DEFAULT_PLAN_SLOTS) {
            $slotNo = 1;
        }
        $slot = $slots[$slotNo];

        self::executeTrainingUnit($websoccer, $db, $i18n, $teamId, $unit, $trainer, $slot['training_type'], $slot['intensity'], (int) $latestMatch['spieltag'], (int) $latestMatch['id']);

        if (class_exists('IndividualTrainingDataService')) {
            IndividualTrainingDataService::processForTeam($websoccer, $db, $i18n, $teamId, $trainer, (int) $latestMatch['id'], (int) $latestMatch['spieltag']);
        }

        $nextSlotNo = ($slotNo >= self::DEFAULT_PLAN_SLOTS) ? 1 : $slotNo + 1;
        $db->queryUpdate(array(
            'current_slot_no' => $nextSlotNo,
            'last_match_id' => (int) $latestMatch['id'],
            'updated_date' => $websoccer->getNowAsTimestamp()
        ), $websoccer->getConfig('db_prefix') . '_training_plan', 'id = %d', (int) $plan['id']);

        return 'processed';
    }

    public static function executeTrainingUnit(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $teamId, $unit, $trainer, $trainingType, $intensity, $matchday = 0, $matchId = 0) {
        self::ensureAdvancedTrainingSchema($websoccer, $db);
        $trainingType = self::normalizeTrainingType($trainingType);
        $intensity = self::normalizeIntensity($intensity);
        $now = $websoccer->getNowAsTimestamp();
        $trainer['training_unit_id'] = isset($unit['id']) ? (int) $unit['id'] : 0;
        $effects = self::trainPlayers($websoccer, $db, $i18n, $teamId, $trainer, $trainingType, $intensity, $matchday, $matchId, $reportId);

        $db->queryUpdate(array(
            'focus' => $trainingType,
            'intensity' => $intensity,
            'date_executed' => $now
        ), $websoccer->getConfig('db_prefix') . '_training_unit', 'id = %d', (int) $unit['id']);

        return array('effects' => $effects, 'report_id' => $reportId);
    }

    public static function getLatestTrainingReport(WebSoccer $websoccer, DbConnection $db, $teamId) {
        self::ensureAdvancedTrainingSchema($websoccer, $db);
        $result = $db->querySelect(
            '*',
            $websoccer->getConfig('db_prefix') . '_training_report',
            'team_id = %d ORDER BY created_date DESC, id DESC',
            (int) $teamId,
            1
        );
        $report = $result->fetch_array();
        $result->free();
        return $report ? self::decorateReport($report) : array();
    }

    public static function getTrainingReports(WebSoccer $websoccer, DbConnection $db, $teamId, $limit = 10) {
        self::ensureAdvancedTrainingSchema($websoccer, $db);
        $reports = array();
        $result = $db->querySelect(
            '*',
            $websoccer->getConfig('db_prefix') . '_training_report',
            'team_id = %d ORDER BY created_date DESC, id DESC',
            (int) $teamId,
            (int) $limit
        );
        while ($report = $result->fetch_array()) {
            $reports[] = self::decorateReport($report);
        }
        $result->free();
        return $reports;
    }

    public static function getTrainingReportPlayers(WebSoccer $websoccer, DbConnection $db, $reportId, $limit = 25) {
        self::ensureAdvancedTrainingSchema($websoccer, $db);
        if ((int) $reportId < 1) {
            return array();
        }

        $prefix = $websoccer->getConfig('db_prefix');
        $fromTable = $prefix . '_training_report_player AS RP LEFT JOIN ' . $prefix . '_spieler AS P ON P.id = RP.player_id';
        $columns = 'RP.*, P.vorname, P.nachname, P.kunstname';
        $result = $db->querySelect($columns, $fromTable, 'RP.report_id = %d ORDER BY RP.total_effect DESC, RP.id ASC', (int) $reportId, (int) $limit);

        $players = array();
        while ($row = $result->fetch_array()) {
            $effects = json_decode($row['effect_data'], true);
            if (!is_array($effects)) {
                $effects = array();
            }
            $row['effects'] = $effects;
            $row['name'] = strlen($row['kunstname']) ? $row['kunstname'] : trim($row['vorname'] . ' ' . $row['nachname']);
            $players[] = $row;
        }
        $result->free();

        return $players;
    }

    public static function generateTrainer(WebSoccer $websoccer, DbConnection $db) {
        self::ensureAdvancedTrainingSchema($websoccer, $db);

        $users = UsersDataService::countActiveUsersWithHighscore($websoccer, $db);
        $trainers = self::countTrainers($websoccer, $db);
        $anzahl = ($users * 3) - $trainers;

        if ($anzahl > 0) {
            $specializations = array_keys(self::getTrainerSpecializations());
            for ($i = 0; $i < $anzahl; $i++) {
                $countryStr = "SELECT country FROM " . $websoccer->getConfig("db_prefix") . "_country ORDER BY RAND() LIMIT 1";
                $countryQuery = $db->executeQuery($countryStr)->fetch_array();
                $country1 = $countryQuery['country'];
                $vname = self::getName($country1);

                $countryStr = "SELECT country FROM " . $websoccer->getConfig("db_prefix") . "_country ORDER BY RAND() LIMIT 1";
                $countryQuery = $db->executeQuery($countryStr)->fetch_array();
                $country1 = $countryQuery['country'];
                $nname = self::getName($country1);

                $vname = str_replace("'", "", $vname);
                $nname = str_replace("'", "", $nname);

                $profile = self::generateTrainerProfile($specializations[mt_rand(0, count($specializations) - 1)]);
                $name = $vname . " " . $nname;

                $db->queryInsert(array_merge(array('name' => $name), $profile), $websoccer->getConfig("db_prefix") . "_trainer");
            }
        }
    }


    public static function getTrainerSuitabilityForTeam(WebSoccer $websoccer, DbConnection $db, $trainer, $teamId) {
        self::ensureAdvancedTrainingSchema($websoccer, $db);
        $teamInfo = self::getTrainerTeamContext($websoccer, $db, $teamId);
        $clubStrength = isset($teamInfo['club_strength']) ? (int) $teamInfo['club_strength'] : 0;
        $leagueRating = isset($teamInfo['league_rating']) ? (int) $teamInfo['league_rating'] : 50;
        $budget = isset($teamInfo['budget']) ? (int) $teamInfo['budget'] : 0;
        $salary = isset($trainer['salary']) ? (int) $trainer['salary'] : 0;
        $signingFee = isset($trainer['signing_fee']) ? (int) $trainer['signing_fee'] : 0;
        $minClubStrength = isset($trainer['min_club_strength']) ? (int) $trainer['min_club_strength'] : 0;
        $minLeagueRating = isset($trainer['min_league_rating']) ? (int) $trainer['min_league_rating'] : 0;

        $reasons = array();
        if ($minClubStrength > 0 && $clubStrength < $minClubStrength) {
            $reasons[] = 'club_strength';
        }
        if ($minLeagueRating > 0 && $leagueRating < $minLeagueRating) {
            $reasons[] = 'league_rating';
        }
        if ($budget > 0 && ($salary + $signingFee) > max(0, (int) floor($budget * 0.35))) {
            $reasons[] = 'budget';
        }

        return array(
            'can_hire' => empty($reasons),
            'club_strength' => $clubStrength,
            'league_rating' => $leagueRating,
            'required_club_strength' => $minClubStrength,
            'required_league_rating' => $minLeagueRating,
            'reasons' => $reasons
        );
    }

    public static function canTeamHireTrainer(WebSoccer $websoccer, DbConnection $db, $trainer, $teamId) {
        $suitability = self::getTrainerSuitabilityForTeam($websoccer, $db, $trainer, $teamId);
        return !empty($suitability['can_hire']);
    }

    public static function getTrainerHiringErrorMessage(I18n $i18n, $suitability) {
        if (!is_array($suitability) || !isset($suitability['reasons']) || empty($suitability['reasons'])) {
            return '';
        }

        $messages = array();
        foreach ($suitability['reasons'] as $reason) {
            if ($reason === 'club_strength') {
                $messages[] = $i18n->getMessage('training_trainer_err_club_too_weak');
            } elseif ($reason === 'league_rating') {
                $messages[] = $i18n->getMessage('training_trainer_err_league_too_weak');
            } elseif ($reason === 'budget') {
                $messages[] = $i18n->getMessage('training_trainer_err_budget_risk');
            }
        }
        return implode(' ', $messages);
    }

    public static function decorateTrainersForTeam(WebSoccer $websoccer, DbConnection $db, array $trainers, $teamId) {
        foreach ($trainers as $idx => $trainer) {
            $trainers[$idx]['suitability'] = self::getTrainerSuitabilityForTeam($websoccer, $db, $trainer, $teamId);
        }
        return $trainers;
    }


    public static function ensureAdvancedTrainingSchema(WebSoccer $websoccer, DbConnection $db) {
        if (self::$_schemaReady) {
            return;
        }

        $prefix = $websoccer->getConfig('db_prefix');

        self::ensureColumn($db, $prefix . '_trainer', 'specialization', "ALTER TABLE " . $prefix . "_trainer ADD COLUMN specialization ENUM('balanced','technique','fitness','offense','defense','setpieces','goalkeeper','mental','tactics') NOT NULL DEFAULT 'balanced'");
        self::ensureColumn($db, $prefix . '_trainer', 'p_offense', "ALTER TABLE " . $prefix . "_trainer ADD COLUMN p_offense TINYINT(3) NOT NULL DEFAULT 60");
        self::ensureColumn($db, $prefix . '_trainer', 'p_defense', "ALTER TABLE " . $prefix . "_trainer ADD COLUMN p_defense TINYINT(3) NOT NULL DEFAULT 60");
        self::ensureColumn($db, $prefix . '_trainer', 'p_tactics', "ALTER TABLE " . $prefix . "_trainer ADD COLUMN p_tactics TINYINT(3) NOT NULL DEFAULT 60");
        self::ensureColumn($db, $prefix . '_trainer', 'p_goalkeeping', "ALTER TABLE " . $prefix . "_trainer ADD COLUMN p_goalkeeping TINYINT(3) NOT NULL DEFAULT 60");
        self::ensureColumn($db, $prefix . '_trainer', 'p_mental', "ALTER TABLE " . $prefix . "_trainer ADD COLUMN p_mental TINYINT(3) NOT NULL DEFAULT 60");
        self::ensureColumn($db, $prefix . '_trainer', 'reputation', "ALTER TABLE " . $prefix . "_trainer ADD COLUMN reputation TINYINT(3) NOT NULL DEFAULT 50");
        self::ensureColumn($db, $prefix . '_trainer', 'min_club_strength', "ALTER TABLE " . $prefix . "_trainer ADD COLUMN min_club_strength TINYINT(3) NOT NULL DEFAULT 0");
        self::ensureColumn($db, $prefix . '_trainer', 'min_league_rating', "ALTER TABLE " . $prefix . "_trainer ADD COLUMN min_league_rating TINYINT(3) NOT NULL DEFAULT 0");
        self::ensureColumn($db, $prefix . '_trainer', 'signing_fee', "ALTER TABLE " . $prefix . "_trainer ADD COLUMN signing_fee INT(10) NOT NULL DEFAULT 0");
        self::ensureColumn($db, $prefix . '_trainer', 'available', "ALTER TABLE " . $prefix . "_trainer ADD COLUMN available ENUM('1','0') NOT NULL DEFAULT '1'");

        $focusType = self::columnType($db, $prefix . '_training_unit', 'focus');
        if (strlen($focusType) && strpos(strtolower($focusType), 'varchar') === false) {
            try {
                $db->executeQuery("ALTER TABLE " . $prefix . "_training_unit MODIFY focus VARCHAR(32) DEFAULT NULL");
            } catch (Exception $e) {
                // Ignore if the column has already been changed by a previous deployment.
            }
        }

        $db->executeQuery("CREATE TABLE IF NOT EXISTS " . $prefix . "_training_plan (
            id INT(10) NOT NULL AUTO_INCREMENT,
            team_id INT(10) NOT NULL,
            current_slot_no TINYINT(3) NOT NULL DEFAULT 1,
            last_match_id INT(10) NOT NULL DEFAULT 0,
            created_date INT(11) NOT NULL DEFAULT 0,
            updated_date INT(11) NOT NULL DEFAULT 0,
            status ENUM('1','0') NOT NULL DEFAULT '1',
            PRIMARY KEY (id),
            UNIQUE KEY uniq_training_plan_team (team_id),
            KEY idx_training_plan_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8");

        $db->executeQuery("CREATE TABLE IF NOT EXISTS " . $prefix . "_training_plan_slot (
            id INT(10) NOT NULL AUTO_INCREMENT,
            plan_id INT(10) NOT NULL,
            slot_no TINYINT(3) NOT NULL,
            training_type VARCHAR(32) NOT NULL,
            intensity TINYINT(3) NOT NULL DEFAULT 50,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_training_plan_slot (plan_id, slot_no),
            KEY idx_training_plan_slot_plan (plan_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8");

        $db->executeQuery("CREATE TABLE IF NOT EXISTS " . $prefix . "_training_report (
            id INT(10) NOT NULL AUTO_INCREMENT,
            team_id INT(10) NOT NULL,
            trainer_id INT(10) DEFAULT NULL,
            training_unit_id INT(10) DEFAULT NULL,
            training_type VARCHAR(32) NOT NULL,
            intensity TINYINT(3) NOT NULL DEFAULT 50,
            matchday TINYINT(3) NOT NULL DEFAULT 0,
            match_id INT(10) NOT NULL DEFAULT 0,
            created_date INT(11) NOT NULL DEFAULT 0,
            player_count INT(10) NOT NULL DEFAULT 0,
            best_player_id INT(10) DEFAULT NULL,
            injuries TINYINT(3) NOT NULL DEFAULT 0,
            old_chemistry TINYINT(3) NOT NULL DEFAULT 0,
            new_chemistry TINYINT(3) NOT NULL DEFAULT 0,
            summary_data TEXT DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_training_report_team_date (team_id, created_date),
            KEY idx_training_report_match (match_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8");

        $db->executeQuery("CREATE TABLE IF NOT EXISTS " . $prefix . "_training_report_player (
            id INT(10) NOT NULL AUTO_INCREMENT,
            report_id INT(10) NOT NULL,
            player_id INT(10) NOT NULL,
            total_effect DECIMAL(7,3) NOT NULL DEFAULT 0.000,
            effect_data TEXT DEFAULT NULL,
            injured ENUM('1','0') NOT NULL DEFAULT '0',
            PRIMARY KEY (id),
            KEY idx_training_report_player_report (report_id),
            KEY idx_training_report_player_player (player_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8");

        self::$_schemaReady = true;
    }

    private static function trainPlayers(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $teamId, $trainer, $trainingType, $intensity, $matchday, $matchId, &$reportId) {
        $players = PlayersDataService::getPlayersOfTeamById($websoccer, $db, $teamId);
        $definition = self::getTrainingDefinition($trainingType);
        $managerTraining = class_exists('ManagerProfileDataService') ? ManagerProfileDataService::getTrainingEffectForTeam($websoccer, $db, $teamId, $trainingType) : array();
        $now = $websoccer->getNowAsTimestamp();
        $oldChemistry = self::getStoredTeamChemistry($websoccer, $db, $teamId);

        $summary = self::emptyEffectSummary();
        $trainingEffects = array();
        $bestPlayerId = 0;
        $bestPlayerEffect = -999;
        $injuries = 0;
        $playerCount = 0;

        $reportTable = $websoccer->getConfig('db_prefix') . '_training_report';
        $db->queryInsert(array(
            'team_id' => (int) $teamId,
            'trainer_id' => isset($trainer['id']) ? (int) $trainer['id'] : '',
            'training_unit_id' => isset($trainer['training_unit_id']) ? (int) $trainer['training_unit_id'] : '',
            'training_type' => $trainingType,
            'intensity' => $intensity,
            'matchday' => (int) $matchday,
            'match_id' => (int) $matchId,
            'created_date' => $now,
            'player_count' => count($players),
            'injuries' => 0,
            'old_chemistry' => $oldChemistry,
            'new_chemistry' => $oldChemistry,
            'summary_data' => '{}'
        ), $reportTable);
        $reportId = $db->getLastInsertedId();

        foreach ($players as $player) {
            $playerCount++;
            $effects = self::computePlayerTrainingEffects($websoccer, $db, $teamId, $player, $trainer, $definition, $trainingType, $intensity, $managerTraining);
            self::dispatchPlayerTrainedEvent($websoccer, $db, $i18n, $player, $teamId, $trainer, $effects);
            $injured = self::rollTrainingInjury($websoccer, $db, $teamId, $player, $definition, $intensity);
            if ($injured) {
                $injuries++;
            }

            self::updatePlayerAfterTraining($websoccer, $db, $teamId, $player, $effects, $injured, $intensity);

            foreach ($summary as $key => $value) {
                if (isset($effects[$key])) {
                    $summary[$key] += (float) $effects[$key];
                }
            }

            $totalEffect = self::sumPositivePlayerDevelopment($effects);
            if ($totalEffect > $bestPlayerEffect) {
                $bestPlayerEffect = $totalEffect;
                $bestPlayerId = (int) $player['id'];
            }

            $name = ($player['pseudonym']) ? $player['pseudonym'] : $player['firstname'] . ' ' . $player['lastname'];
            $trainingEffects[$player['id']] = array_merge(array('name' => $name, 'injured' => $injured ? '1' : '0'), self::roundEffectsForDisplay($effects));

            $db->queryInsert(array(
                'report_id' => (int) $reportId,
                'player_id' => (int) $player['id'],
                'total_effect' => round($totalEffect, 3),
                'effect_data' => json_encode(self::roundEffectsForDisplay($effects)),
                'injured' => $injured ? '1' : '0'
            ), $websoccer->getConfig('db_prefix') . '_training_report_player');
        }

        $chemistryDelta = self::computeChemistryDelta($definition, $trainer, $intensity, $injuries, $managerTraining);
        $summaryRounded = self::roundEffectsForDisplay($summary);
        $summaryRounded['chemistry_delta'] = $chemistryDelta;
        $summaryRounded['trainer_specialization'] = isset($trainer['specialization']) ? $trainer['specialization'] : 'balanced';
        if (isset($managerTraining['manager_id']) && (int) $managerTraining['manager_id'] > 0) {
            $summaryRounded['manager_competence'] = (int) $managerTraining['competence'];
            $summaryRounded['manager_character'] = $managerTraining['character_key'];
            $summaryRounded['manager_training_factor'] = round((float) $managerTraining['development_factor'], 3);
        }

        // Store the chemistry delta before refreshing TeamChemistryDataService, because
        // the chemistry factor reads recent normal training reports.
        $db->queryUpdate(array(
            'player_count' => (int) $playerCount,
            'best_player_id' => ($bestPlayerId > 0) ? $bestPlayerId : '',
            'injuries' => (int) $injuries,
            'summary_data' => json_encode($summaryRounded)
        ), $reportTable, 'id = %d', (int) $reportId);

        $newChemistry = $oldChemistry;
        if (class_exists('TeamChemistryDataService')) {
            $refresh = TeamChemistryDataService::refreshTeamChemistry($websoccer, $db, $teamId, 'training', (int) $matchId);
            if (isset($refresh['score'])) {
                $newChemistry = (int) $refresh['score'];
            }
        } else {
            $newChemistry = self::applyFallbackChemistryChange($websoccer, $db, $teamId, $oldChemistry, $chemistryDelta, (int) $matchId);
        }

        $db->queryUpdate(array(
            'old_chemistry' => $oldChemistry,
            'new_chemistry' => $newChemistry,
            'summary_data' => json_encode($summaryRounded)
        ), $reportTable, 'id = %d', (int) $reportId);

        return $trainingEffects;
    }

    private static function computePlayerTrainingEffects(WebSoccer $websoccer, DbConnection $db, $teamId, $player, $trainer, $definition, $trainingType, $intensity, $managerTraining = array()) {
        $effects = self::emptyEffectSummary();

        if ((int) $player['matches_injured'] > 0) {
            $effects['freshness'] = 0.5;
            $effects['stamina'] = -0.15;
            return $effects;
        }

        $intensityFactor = 0.55 + (self::normalizeIntensity($intensity) / 100) * 0.65;
        $freshness = isset($player['strength_freshness']) ? (float) $player['strength_freshness'] : 50;
        $freshnessFactor = ($freshness < 35) ? 0.65 : (($freshness < 55) ? 0.85 : 1.0);
        $talent = isset($player['strength_talent']) ? (int) $player['strength_talent'] : 3;
        $talentFactor = max(0.75, min(1.35, 0.70 + ($talent * 0.13)));
        $age = isset($player['age']) ? (int) $player['age'] : 25;
        $ageFactor = ($age <= 21) ? 1.25 : (($age <= 25) ? 1.10 : (($age <= 29) ? 1.0 : (($age <= 33) ? 0.82 : 0.65)));
        $learningFactor = $intensityFactor * $freshnessFactor * $talentFactor * $ageFactor;

        foreach ($definition['effects'] as $effectKey => $baseValue) {
            if (!isset($effects[$effectKey])) {
                continue;
            }
            $skillGroup = self::getSkillGroupForEffect($effectKey, $trainingType);
            $trainerFactor = self::getTrainerSkillFactor($trainer, $skillGroup, $trainingType);
            $positionFactor = self::getPositionFactor($player, $trainingType, $effectKey);
            $effectScale = in_array($effectKey, array('freshness', 'satisfaction')) ? 1.0 : 0.22;
            $effects[$effectKey] += $baseValue * $learningFactor * $trainerFactor * $positionFactor * $effectScale;
        }

        $fatigue = (isset($definition['fatigue']) ? (float) $definition['fatigue'] : 0) * (self::normalizeIntensity($intensity) / 70);
        if ($fatigue > 0) {
            $effects['freshness'] -= $fatigue;
        }

        if (isset($definition['satisfaction'])) {
            $effects['satisfaction'] += (float) $definition['satisfaction'];
        }

        self::applyAdvancedPersonalityEffects($player, $trainingType, $intensity, $effects);
        if (class_exists('PlayerPersonalityDataService')) {
            PlayerPersonalityDataService::applyTrainingEffects(
                isset($player['personality']) ? $player['personality'] : 'professional',
                $effects['freshness'], $effects['technique'], $effects['stamina'], $effects['satisfaction'], $effects['passing'],
                $effects['shooting'], $effects['heading'], $effects['tackling'], $effects['freekick'], $effects['pace'], $effects['creativity'],
                $effects['influence'], $effects['flair'], $effects['penalty'], $effects['penalty_killing']
            );
        }
        if (class_exists('ManagerCharacterDataService')) {
            ManagerCharacterDataService::applyTrainingEffects($websoccer, $db, $teamId, $player, $trainingType, $effects);
        }
        self::applyManagerTrainingEffects($effects, $managerTraining);

        return $effects;
    }

    private static function dispatchPlayerTrainedEvent(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $player, $teamId, $trainer, &$effects) {
        if (!class_exists('PlayerTrainedEvent')) {
            return;
        }

        $effectFreshness = $effects['freshness'];
        $effectTechnique = $effects['technique'];
        $effectStamina = $effects['stamina'];
        $effectSatisfaction = $effects['satisfaction'];
        $effectPassing = $effects['passing'];
        $effectShooting = $effects['shooting'];
        $effectHeading = $effects['heading'];
        $effectTackling = $effects['tackling'];
        $effectFreekick = $effects['freekick'];
        $effectPace = $effects['pace'];
        $effectCreativity = $effects['creativity'];
        $effectInfluence = $effects['influence'];
        $effectFlair = $effects['flair'];
        $effectPenalty = $effects['penalty'];
        $effectPenaltyKilling = $effects['penalty_killing'];

        $event = new PlayerTrainedEvent(
            $websoccer, $db, $i18n,
            (int) $player['id'], (int) $teamId, isset($trainer['id']) ? (int) $trainer['id'] : 0,
            $effectFreshness, $effectTechnique, $effectStamina, $effectSatisfaction, $effectPassing,
            $effectShooting, $effectHeading, $effectTackling, $effectFreekick, $effectPace, $effectCreativity,
            $effectInfluence, $effectFlair, $effectPenalty, $effectPenaltyKilling
        );
        PluginMediator::dispatchEvent($event);

        $effects['freshness'] = $effectFreshness;
        $effects['technique'] = $effectTechnique;
        $effects['stamina'] = $effectStamina;
        $effects['satisfaction'] = $effectSatisfaction;
        $effects['passing'] = $effectPassing;
        $effects['shooting'] = $effectShooting;
        $effects['heading'] = $effectHeading;
        $effects['tackling'] = $effectTackling;
        $effects['freekick'] = $effectFreekick;
        $effects['pace'] = $effectPace;
        $effects['creativity'] = $effectCreativity;
        $effects['influence'] = $effectInfluence;
        $effects['flair'] = $effectFlair;
        $effects['penalty'] = $effectPenalty;
        $effects['penalty_killing'] = $effectPenaltyKilling;
    }

    private static function updatePlayerAfterTraining(WebSoccer $websoccer, DbConnection $db, $teamId, $player, $effects, $injured, $intensity) {
        $columns = array(
            'w_frische' => self::normalizePlayerValue((float) $player['strength_freshness'] + (float) $effects['freshness']),
            'w_technik' => self::normalizePlayerValue((float) $player['strength_technic'] + (float) $effects['technique']),
            'w_kondition' => self::normalizePlayerValue((float) $player['strength_stamina'] + (float) $effects['stamina']),
            'w_zufriedenheit' => self::normalizePlayerValue((float) $player['strength_satisfaction'] + (float) $effects['satisfaction']),
            'w_passing' => self::normalizePlayerValue((float) $player['strength_passing'] + (float) $effects['passing']),
            'w_shooting' => self::normalizePlayerValue((float) $player['strength_shooting'] + (float) $effects['shooting']),
            'w_heading' => self::normalizePlayerValue((float) $player['strength_heading'] + (float) $effects['heading']),
            'w_tackling' => self::normalizePlayerValue((float) $player['strength_tackling'] + (float) $effects['tackling']),
            'w_freekick' => self::normalizePlayerValue((float) $player['strength_freekick'] + (float) $effects['freekick']),
            'w_pace' => self::normalizePlayerValue((float) $player['strength_pace'] + (float) $effects['pace']),
            'w_creativity' => self::normalizePlayerValue((float) $player['strength_creativity'] + (float) $effects['creativity']),
            'w_influence' => self::normalizePlayerValue((float) $player['strength_influence'] + (float) $effects['influence']),
            'w_flair' => self::normalizePlayerValue((float) $player['strength_flair'] + (float) $effects['flair']),
            'w_penalty' => self::normalizePlayerValue((float) $player['strength_penalty'] + (float) $effects['penalty']),
            'w_penalty_killing' => self::normalizePlayerValue((float) $player['strength_penalty_killing'] + (float) $effects['penalty_killing'])
        );

        if ($injured) {
            $injuryMatches = ((int) $intensity >= 95) ? 2 : 1;
            $columns['verletzt'] = max($injuryMatches, (int) $player['matches_injured']);
            if (class_exists('MedicalCenterDataService')) {
                MedicalCenterDataService::logInjury($websoccer, $db, (int) $teamId, (int) $player['id'], 'training', 0, $columns['verletzt'], '', 'training:' . (int) $player['id'] . ':' . $websoccer->getNowAsTimestamp());
            }
        }

        $db->queryUpdate($columns, $websoccer->getConfig('db_prefix') . '_spieler', 'id = %d', (int) $player['id']);
    }

    private static function getTrainingDefinition($trainingType) {
        $trainingType = self::normalizeTrainingType($trainingType);
        $definitions = array(
            'regeneration' => array(
                'effects' => array('freshness' => 4.8, 'satisfaction' => 0.8, 'stamina' => -0.2),
                'fatigue' => 0,
                'satisfaction' => 0.3,
                'chemistry' => 0
            ),
            'technique' => array(
                'effects' => array('technique' => 0.85, 'passing' => 0.25, 'creativity' => 0.35, 'flair' => 0.20, 'stamina' => 0.05),
                'fatigue' => 1.6,
                'satisfaction' => 0,
                'chemistry' => 0
            ),
            'passing' => array(
                'effects' => array('passing' => 0.85, 'creativity' => 0.45, 'technique' => 0.20, 'influence' => 0.10),
                'fatigue' => 1.4,
                'satisfaction' => 0.1,
                'chemistry' => 1
            ),
            'finishing' => array(
                'effects' => array('shooting' => 0.85, 'penalty' => 0.35, 'flair' => 0.25, 'technique' => 0.10),
                'fatigue' => 1.8,
                'satisfaction' => 0,
                'chemistry' => 0
            ),
            'setpieces' => array(
                'effects' => array('freekick' => 0.80, 'penalty' => 0.45, 'heading' => 0.25, 'technique' => 0.10),
                'fatigue' => 1.0,
                'satisfaction' => 0.1,
                'chemistry' => 0
            ),
            'defense' => array(
                'effects' => array('tackling' => 0.80, 'heading' => 0.40, 'influence' => 0.25, 'stamina' => 0.10),
                'fatigue' => 2.0,
                'satisfaction' => -0.1,
                'chemistry' => 0
            ),
            'athletics' => array(
                'effects' => array('stamina' => 0.80, 'pace' => 0.60),
                'fatigue' => 2.6,
                'satisfaction' => -0.2,
                'chemistry' => 0
            ),
            'teambuilding' => array(
                'effects' => array('satisfaction' => 1.5, 'influence' => 0.35, 'freshness' => 0.3),
                'fatigue' => 0,
                'satisfaction' => 0.4,
                'chemistry' => 2
            ),
            'matchprep' => array(
                'effects' => array('influence' => 0.35, 'passing' => 0.15, 'tackling' => 0.15, 'satisfaction' => 0.2),
                'fatigue' => 0.6,
                'satisfaction' => 0.1,
                'chemistry' => 1
            ),
            'goalkeeper' => array(
                'effects' => array('penalty_killing' => 0.85, 'technique' => 0.15, 'influence' => 0.15),
                'fatigue' => 1.2,
                'satisfaction' => 0,
                'chemistry' => 0
            )
        );

        return $definitions[$trainingType];
    }

    private static function getSkillGroupForEffect($effectKey, $trainingType) {
        if ($trainingType === 'goalkeeper' || $effectKey === 'penalty_killing') {
            return 'goalkeeping';
        }
        if ($trainingType === 'athletics' || $effectKey === 'stamina' || $effectKey === 'pace') {
            return 'fitness';
        }
        if ($trainingType === 'defense' || $effectKey === 'tackling' || $effectKey === 'heading') {
            return 'defense';
        }
        if ($trainingType === 'finishing' || $effectKey === 'shooting' || $effectKey === 'penalty' || $effectKey === 'flair') {
            return 'offense';
        }
        if ($trainingType === 'setpieces' || $effectKey === 'freekick') {
            return 'setpieces';
        }
        if ($trainingType === 'teambuilding' || $effectKey === 'satisfaction' || $effectKey === 'influence') {
            return 'mental';
        }
        if ($trainingType === 'matchprep') {
            return 'tactics';
        }
        return 'technique';
    }

    private static function getTrainerSkillFactor($trainer, $skillGroup, $trainingType) {
        $expertise = isset($trainer['expertise']) ? (int) $trainer['expertise'] : 60;
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
        } elseif ($skillGroup === 'tactics') {
            $skill = isset($trainer['p_tactics']) ? (int) $trainer['p_tactics'] : $expertise;
        } else {
            $skill = isset($trainer['p_technique']) ? (int) $trainer['p_technique'] : $expertise;
        }

        $factor = 0.65 + (max(1, min(100, $skill)) / 100) * 0.70;
        $specialization = isset($trainer['specialization']) ? $trainer['specialization'] : 'balanced';
        if ($specialization === $skillGroup || $specialization === $trainingType) {
            $factor *= 1.16;
        } elseif ($specialization === 'balanced') {
            $factor *= 1.03;
        } elseif (self::isTrainerSpecializationWeakAgainst($specialization, $skillGroup)) {
            $factor *= 0.88;
        }

        return $factor;
    }


    private static function isTrainerSpecializationWeakAgainst($specialization, $skillGroup) {
        $weaknesses = array(
            'offense' => array('defense'),
            'defense' => array('offense'),
            'fitness' => array('mental'),
            'mental' => array('fitness'),
            'goalkeeper' => array('offense', 'defense'),
            'setpieces' => array('fitness'),
            'tactics' => array('fitness'),
            'technique' => array('fitness')
        );
        return isset($weaknesses[$specialization]) && in_array($skillGroup, $weaknesses[$specialization], true);
    }

    private static function getPositionFactor($player, $trainingType, $effectKey) {
        $position = isset($player['position_main']) ? $player['position_main'] : '';
        if ($trainingType === 'goalkeeper') {
            return ($position === 'T') ? 1.25 : 0.18;
        }
        if ($position === 'T' && in_array($trainingType, array('finishing', 'setpieces', 'defense'))) {
            return 0.45;
        }
        if (in_array($position, array('IV', 'LV', 'RV')) && in_array($effectKey, array('tackling', 'heading', 'influence'))) {
            return 1.15;
        }
        if (in_array($position, array('LM', 'DM', 'ZM', 'OM', 'RM')) && in_array($effectKey, array('passing', 'creativity', 'stamina'))) {
            return 1.12;
        }
        if (in_array($position, array('LS', 'MS', 'RS')) && in_array($effectKey, array('shooting', 'penalty', 'flair', 'pace'))) {
            return 1.15;
        }
        return 1.0;
    }


    private static function applyManagerTrainingEffects(&$effects, $managerTraining) {
        if (!is_array($managerTraining) || !isset($managerTraining['development_factor'])) {
            return;
        }

        $factor = (float) $managerTraining['development_factor'];
        $developmentKeys = array('technique', 'stamina', 'passing', 'shooting', 'heading', 'tackling', 'freekick', 'pace', 'creativity', 'influence', 'flair', 'penalty', 'penalty_killing');
        foreach ($developmentKeys as $key) {
            if (isset($effects[$key]) && $effects[$key] > 0) {
                $effects[$key] *= $factor;
            }
        }

        if (isset($managerTraining['satisfaction_bonus'])) {
            $effects['satisfaction'] += (float) $managerTraining['satisfaction_bonus'];
        }
        if (isset($managerTraining['freshness_bonus'])) {
            $effects['freshness'] += (float) $managerTraining['freshness_bonus'];
        }
    }

    private static function applyAdvancedPersonalityEffects($player, $trainingType, $intensity, &$effects) {
        $trait = isset($player['personality']) ? $player['personality'] : 'professional';
        if ($trait === 'leader') {
            $effects['influence'] += 0.08;
        } elseif ($trait === 'loyal' && $trainingType === 'teambuilding') {
            $effects['satisfaction'] += 0.15;
        } elseif ($trait === 'big_game_player' && $trainingType === 'matchprep') {
            $effects['influence'] += 0.15;
            $effects['satisfaction'] += 0.05;
        } elseif ($trait === 'ambitious' && (int) $intensity >= 75) {
            $effects['satisfaction'] += 0.08;
        } elseif ($trait === 'ambitious' && (int) $intensity <= 40) {
            $effects['satisfaction'] -= 0.08;
        } elseif ($trait === 'troublemaker') {
            $effects['satisfaction'] -= 0.08;
        } elseif ($trait === 'inconsistent') {
            $variance = mt_rand(-8, 8) / 100;
            foreach ($effects as $key => $value) {
                if ($value > 0) {
                    $effects[$key] = $value * (1 + $variance);
                }
            }
        }
    }

    private static function rollTrainingInjury(WebSoccer $websoccer, DbConnection $db, $teamId, $player, $definition, $intensity) {
        if ((int) $player['matches_injured'] > 0 || (int) $intensity < 70 || (float) $definition['fatigue'] <= 0) {
            return false;
        }

        $riskPercent = max(0, ((int) $intensity - 70) * 0.006); // 0.18% at intensity 100 before modifiers.
        $freshness = isset($player['strength_freshness']) ? (float) $player['strength_freshness'] : 50;
        if ($freshness < 35) {
            $riskPercent += 0.08;
        }
        if (isset($player['personality']) && $player['personality'] === 'injury_prone') {
            $riskPercent *= 1.6;
        } elseif (isset($player['personality']) && $player['personality'] === 'professional') {
            $riskPercent *= 0.85;
        }
        if (class_exists('ManagerCharacterDataService')) {
            $riskPercent *= ManagerCharacterDataService::getTrainingInjuryMultiplier($websoccer, $db, $teamId);
        }
        if (class_exists('ManagerProfileDataService')) {
            $riskPercent *= ManagerProfileDataService::getTrainingInjuryMultiplierForTeam($websoccer, $db, $teamId);
        }

        return mt_rand(1, 10000) <= (int) round($riskPercent * 100);
    }

    private static function computeChemistryDelta($definition, $trainer, $intensity, $injuries, $managerTraining = array()) {
        $base = isset($definition['chemistry']) ? (float) $definition['chemistry'] : 0;
        if ($base <= 0 && (int) $injuries <= 0) {
            return 0;
        }
        $trainerFactor = self::getTrainerSkillFactor($trainer, 'tactics', 'matchprep');
        $delta = $base * (0.6 + ((int) $intensity / 100) * 0.5) * $trainerFactor;
        if (isset($managerTraining['chemistry_delta_bonus'])) {
            $delta += (float) $managerTraining['chemistry_delta_bonus'];
        }
        if ((int) $injuries > 0) {
            $delta -= min(2, (int) $injuries);
        }
        return (int) round($delta);
    }

    private static function applyFallbackChemistryChange(WebSoccer $websoccer, DbConnection $db, $teamId, $oldChemistry, $chemistryDelta, $matchId) {
        if ($chemistryDelta == 0) {
            return $oldChemistry;
        }
        $newChemistry = max(1, min(100, (int) $oldChemistry + (int) $chemistryDelta));
        $maxEffect = self::getOptionalConfig($websoccer, 'team_chemistry_max_match_effect', 3);
        $effect = (int) round((($newChemistry - 50) / 50) * $maxEffect);

        $db->queryUpdate(array(
            'team_chemistry' => $newChemistry,
            'team_chemistry_effect' => $effect,
            'team_chemistry_updated' => $websoccer->getNowAsTimestamp()
        ), $websoccer->getConfig('db_prefix') . '_verein', 'id = %d', (int) $teamId);

        if (self::tableExists($db, $websoccer->getConfig('db_prefix') . '_team_chemistry_log')) {
            $db->queryInsert(array(
                'team_id' => (int) $teamId,
                'event_date' => $websoccer->getNowAsTimestamp(),
                'source' => 'training',
                'old_score' => (int) $oldChemistry,
                'new_score' => (int) $newChemistry,
                'match_effect' => $effect,
                'match_id' => ((int) $matchId > 0) ? (int) $matchId : '',
                'breakdown_data' => json_encode(array('training' => array('score' => $newChemistry)))
            ), $websoccer->getConfig('db_prefix') . '_team_chemistry_log');
        }

        return $newChemistry;
    }

    private static function getLatestCompletedMatchForTeam(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $prefix = $websoccer->getConfig('db_prefix');
        $result = $db->querySelect(
            'id, spieltag, datum',
            $prefix . '_spiel',
            "berechnet = '1' AND (home_verein = %d OR gast_verein = %d) ORDER BY id DESC",
            array((int) $teamId, (int) $teamId),
            1
        );
        $match = $result->fetch_array();
        $result->free();
        return $match ? $match : array();
    }

    private static function isTeamInTrainingCamp(WebSoccer $websoccer, DbConnection $db, $teamId) {
        if (!class_exists('TrainingcampsDataService')) {
            return false;
        }
        $now = $websoccer->getNowAsTimestamp();
        $campBookings = TrainingcampsDataService::getCampBookingsByTeam($websoccer, $db, $teamId);
        foreach ($campBookings as $booking) {
            if ($booking['date_start'] <= $now && $booking['date_end'] >= $now) {
                return true;
            }
        }
        return false;
    }


    private static function getTrainerTeamContext(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $prefix = $websoccer->getConfig('db_prefix');
        $columns = 'C.id, C.finanz_budget AS budget, C.strength AS club_strength, C.highscore, L.id AS league_id, COALESCE(ML.rating, 50) AS league_rating';
        $fromTable = $prefix . '_verein AS C LEFT JOIN ' . $prefix . '_liga AS L ON L.id = C.liga_id LEFT JOIN ' . $prefix . '_manager_league_rating AS ML ON ML.league_id = L.id';
        $result = $db->querySelect($columns, $fromTable, 'C.id = %d', (int) $teamId, 1);
        $team = $result->fetch_array();
        $result->free();
        return $team ? $team : array('budget' => 0, 'club_strength' => 0, 'league_rating' => 50);
    }

    private static function generateTrainerProfile($specialization) {
        $skills = array(
            'p_technique' => mt_rand(45, 75),
            'p_stamina' => mt_rand(45, 75),
            'p_offense' => mt_rand(45, 75),
            'p_defense' => mt_rand(45, 75),
            'p_tactics' => mt_rand(45, 75),
            'p_goalkeeping' => mt_rand(35, 70),
            'p_mental' => mt_rand(45, 75)
        );

        if ($specialization === 'technique') {
            $skills['p_technique'] = mt_rand(78, 96); $skills['p_stamina'] = mt_rand(35, 62);
        } elseif ($specialization === 'fitness') {
            $skills['p_stamina'] = mt_rand(80, 98); $skills['p_mental'] = mt_rand(35, 62);
        } elseif ($specialization === 'offense') {
            $skills['p_offense'] = mt_rand(82, 99); $skills['p_defense'] = mt_rand(30, 58);
        } elseif ($specialization === 'defense') {
            $skills['p_defense'] = mt_rand(82, 99); $skills['p_offense'] = mt_rand(30, 58);
        } elseif ($specialization === 'setpieces') {
            $skills['p_tactics'] = mt_rand(72, 92); $skills['p_technique'] = mt_rand(72, 92); $skills['p_stamina'] = mt_rand(35, 62);
        } elseif ($specialization === 'goalkeeper') {
            $skills['p_goalkeeping'] = mt_rand(84, 99); $skills['p_offense'] = mt_rand(30, 55);
        } elseif ($specialization === 'mental') {
            $skills['p_mental'] = mt_rand(82, 99); $skills['p_technique'] = mt_rand(35, 65);
        } elseif ($specialization === 'tactics') {
            $skills['p_tactics'] = mt_rand(82, 99); $skills['p_stamina'] = mt_rand(35, 62);
        } else {
            foreach ($skills as $key => $value) {
                $skills[$key] = mt_rand(58, 82);
            }
        }

        $expertise = (int) round(($skills['p_technique'] + $skills['p_stamina'] + $skills['p_offense'] + $skills['p_defense'] + $skills['p_tactics'] + $skills['p_goalkeeping'] + $skills['p_mental']) / 7);
        $reputation = max(20, min(100, $expertise + mt_rand(-8, 12)));
        $salary = max(25000, (int) round(($expertise * 850) + ($reputation * 450)));
        $signingFee = (int) round($salary * (mt_rand(2, 6) / 10));

        return array_merge(array(
            'salary' => $salary,
            'expertise' => $expertise,
            'premiumfee' => 0,
            'specialization' => $specialization,
            'reputation' => $reputation,
            'min_club_strength' => max(0, $reputation - 30),
            'min_league_rating' => max(0, $reputation - 35),
            'signing_fee' => $signingFee,
            'available' => '1'
        ), $skills);
    }


    private static function normalizeTrainerRow($trainer) {
        if (!$trainer) {
            return array();
        }
        $defaults = array(
            'specialization' => 'balanced',
            'p_offense' => isset($trainer['p_technique']) ? $trainer['p_technique'] : 60,
            'p_defense' => isset($trainer['expertise']) ? $trainer['expertise'] : 60,
            'p_tactics' => isset($trainer['expertise']) ? $trainer['expertise'] : 60,
            'p_goalkeeping' => isset($trainer['p_technique']) ? $trainer['p_technique'] : 60,
            'p_mental' => isset($trainer['expertise']) ? $trainer['expertise'] : 60,
            'reputation' => isset($trainer['expertise']) ? $trainer['expertise'] : 50,
            'min_club_strength' => 0,
            'min_league_rating' => 0,
            'signing_fee' => 0,
            'available' => '1'
        );
        foreach ($defaults as $key => $value) {
            if (!isset($trainer[$key]) || $trainer[$key] === '') {
                $trainer[$key] = $value;
            }
        }
        return $trainer;
    }

    private static function emptyEffectSummary() {
        return array(
            'freshness' => 0,
            'technique' => 0,
            'stamina' => 0,
            'satisfaction' => 0,
            'passing' => 0,
            'shooting' => 0,
            'heading' => 0,
            'tackling' => 0,
            'freekick' => 0,
            'pace' => 0,
            'creativity' => 0,
            'influence' => 0,
            'flair' => 0,
            'penalty' => 0,
            'penalty_killing' => 0
        );
    }

    private static function roundEffectsForDisplay($effects) {
        $rounded = array();
        foreach ($effects as $key => $value) {
            $rounded[$key] = round((float) $value, 2);
        }
        return $rounded;
    }

    private static function sumPositivePlayerDevelopment($effects) {
        $sum = 0;
        foreach ($effects as $key => $value) {
            if ($key === 'freshness' || $key === 'satisfaction') {
                continue;
            }
            if ($value > 0) {
                $sum += (float) $value;
            }
        }
        return $sum;
    }

    private static function normalizePlayerValue($value) {
        return round(min(100, max(1, (float) $value)), 2);
    }

    private static function normalizeIntensity($intensity) {
        return max(1, min(100, (int) $intensity));
    }

    private static function getStoredTeamChemistry(WebSoccer $websoccer, DbConnection $db, $teamId) {
        if (!self::columnExists($db, $websoccer->getConfig('db_prefix') . '_verein', 'team_chemistry')) {
            return 50;
        }
        $result = $db->querySelect('team_chemistry', $websoccer->getConfig('db_prefix') . '_verein', 'id = %d', (int) $teamId, 1);
        $row = $result->fetch_array();
        $result->free();
        return ($row && isset($row['team_chemistry'])) ? (int) $row['team_chemistry'] : 50;
    }

    private static function decorateReport($report) {
        $summary = json_decode($report['summary_data'], true);
        if (!is_array($summary)) {
            $summary = array();
        }
        $report['summary'] = $summary;
        $report['chemistry_change'] = (int) $report['new_chemistry'] - (int) $report['old_chemistry'];
        return $report;
    }

    public static function isAdvancedTrainingEnabled(WebSoccer $websoccer) {
        return (bool) self::getOptionalConfig($websoccer, 'advanced_training_enabled', 1);
    }

    private static function getOptionalConfig(WebSoccer $websoccer, $key, $default) {
        try {
            return $websoccer->getConfig($key);
        } catch (Exception $e) {
            return $default;
        }
    }

    private static function ensureColumn(DbConnection $db, $table, $column, $alterSql) {
        if (!self::columnExists($db, $table, $column)) {
            try {
                $db->executeQuery($alterSql);
            } catch (Exception $e) {
                // Ignore duplicate-column or permission issues here; the explicit SQL update file contains the same DDL.
            }
        }
    }

    private static function columnType(DbConnection $db, $table, $column) {
        try {
            $result = $db->executeQuery("SHOW COLUMNS FROM " . $table . " LIKE '" . $db->connection->real_escape_string($column) . "'");
            $row = $result->fetch_array();
            $result->free();
            return ($row && isset($row['Type'])) ? (string) $row['Type'] : '';
        } catch (Exception $e) {
            return '';
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

    private static function tableExists(DbConnection $db, $table) {
        try {
            $result = $db->executeQuery("SHOW TABLES LIKE '" . $db->connection->real_escape_string($table) . "'");
            $row = $result->fetch_array();
            $result->free();
            return $row ? true : false;
        } catch (Exception $e) {
            return false;
        }
    }

    private static function getName($country) {
        global $conf;
        $db = DbConnection::getInstance();
        $db->connect($conf["db_host"], $conf["db_user"], $conf["db_passwort"], $conf["db_name"]);

        $rand = mt_rand(1, 8);
        $country = ltrim($country);
        $krz = 0;

        if ($country == "England") {
            $krz = "EN";
        } else if ($country == "USA") {
            $krz = "EN";
        } else if ($country == "Deutschland") {
            $krz = "DE";
        } else if ($country == "Frankreich") {
            $krz = "FR";
        } else if ($country == "Spanien") {
            $krz = "ES";
        } else if ($country == "Niederlande") {
            $krz = "NL";
        } else if ($country == "Italien") {
            $krz = "IT";
        } else {
            $contStr = "SELECT continent FROM " . $conf["db_prefix"] . "_country WHERE country LIKE '%" . $db->connection->real_escape_string($country) . "%' LIMIT 1";
            $contQuery = $db->executeQuery($contStr)->fetch_array();
            if (isset($contQuery['continent'])) {
                $continent = $contQuery['continent'];
                if ($continent == "AFR") {
                    $krz = "AFR";
                }
                if ($continent == "AME") {
                    $krz = "AME";
                }
            } else {
                if ($rand == 1) {
                    $krz = "AME";
                } else if ($rand == 2) {
                    $krz = "AFR";
                } else if ($rand == 3) {
                    $krz = "DE";
                } else if ($rand == 4) {
                    $krz = "EN";
                } else if ($rand == 5) {
                    $krz = "FR";
                } else if ($rand == 6) {
                    $krz = "ES";
                } else if ($rand == 7) {
                    $krz = "IT";
                } else if ($rand == 8) {
                    $krz = "NL";
                }
            }
        }

        if ($krz != 0) {
            $nameStr = "SELECT name FROM " . $conf["db_prefix"] . "_name WHERE continent='" . $krz . "' ORDER BY RAND() LIMIT 1";
            $nameQuery = $db->executeQuery($nameStr)->fetch_array();
            $name = $nameQuery['name'];
        } else {
            $nameStr = "SELECT name FROM " . $conf["db_prefix"] . "_name ORDER BY RAND() LIMIT 1";
            $nameQuery = $db->executeQuery($nameStr)->fetch_array();
            $name = $nameQuery['name'];
        }

        return $name;
    }
}
?>
