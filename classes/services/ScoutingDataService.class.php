<?php
/******************************************************

This file is part of OpenWebSoccer-Sim.

OpenWebSoccer-Sim is free software: you can redistribute it
and/or modify it under the terms of the
GNU Lesser General Public License as published by the Free Software Foundation,
either version 3 of the License, or any later version.

******************************************************/

/**
 * Data service for the new scouting system.
 *
 * Responsibilities:
 * - scouting department levels and team department
 * - scout hire/fire with hidden expertise
 * - scouting camps
 * - temporary scouting proposals
 * - matchday processing: costs, scout duration, camp progress, proposal generation
 */
class ScoutingDataService {
    
    const SCOUT_PUBLIC_PROFILE_UNKNOWN = 'scouting_profile_unknown';
    
    public static function isEnabled(WebSoccer $websoccer) {
        $value = $websoccer->getConfig('scouting_enabled');
        return ($value === null || $value === '' || $value == '1' || $value === TRUE);
    }
    
    private static function tablePrefix(WebSoccer $websoccer) {
        return $websoccer->getConfig('db_prefix') . '_';
    }
    
    private static function now(WebSoccer $websoccer) {
        return $websoccer->getNowAsTimestamp();
    }
    
    private static function configInt(WebSoccer $websoccer, $key, $default) {
        $value = $websoccer->getConfig($key);
        if ($value === null || $value === '') {
            return (int) $default;
        }
        return (int) $value;
    }
    
    private static function clamp($value, $min, $max) {
        return max($min, min($max, $value));
    }
    
    private static function cleanScoutForManager($scout) {
        if (!$scout) {
            return $scout;
        }
        
        // Do not leak internal scout competence to managers.
        if (isset($scout['expertise'])) {
            unset($scout['expertise']);
        }
        
        // Deliberately vague. Managers learn scout quality by results.
        $scout['profile'] = self::SCOUT_PUBLIC_PROFILE_UNKNOWN;
        
        return $scout;
    }
    
    private static function getSingleRow(DbConnection $db, $columns, $fromTable, $whereCondition, $parameters = null) {
        $result = $db->querySelect($columns, $fromTable, $whereCondition, $parameters, 1);
        $row = $result->fetch_array();
        $result->free();
        return ($row) ? $row : array();
    }
    
    private static function getRows(DbConnection $db, $columns, $fromTable, $whereCondition, $parameters = null, $limit = null) {
        $result = $db->querySelect($columns, $fromTable, $whereCondition, $parameters, $limit);
        $rows = array();
        while ($row = $result->fetch_array()) {
            $rows[] = $row;
        }
        $result->free();
        return $rows;
    }
    
    public static function getTeamBudget(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $row = self::getSingleRow(
            $db,
            'finanz_budget AS team_budget',
            self::tablePrefix($websoccer) . 'verein',
            'id = %d',
            (int) $teamId
            );
        return (isset($row['team_budget'])) ? (int) $row['team_budget'] : 0;
    }
    
    private static function debitIfPossible(WebSoccer $websoccer, DbConnection $db, $teamId, $amount, $subject, $sender) {
        $amount = (int) $amount;
        if ($amount <= 0) {
            return true;
        }
        
        if (self::getTeamBudget($websoccer, $db, $teamId) < $amount) {
            return false;
        }
        
        BankAccountDataService::debitAmount($websoccer, $db, $teamId, $amount, $subject, $sender);
        return true;
    }
    
    public static function getScoutHireFeeMatches(WebSoccer $websoccer) {
        return max(0, self::configInt($websoccer, 'scout_hire_matches', 3));
    }
    
    public static function calculateScoutHiringFee(WebSoccer $websoccer, $scout) {
        if (!$scout || !isset($scout['fee'])) {
            return 0;
        }
        
        return max(0, (int) $scout['fee']) * self::getScoutHireFeeMatches($websoccer);
    }
    
    /**
     * Returns completed stadium environment effects for the new scouting system.
     * The existing effect_youthscouting column is intentionally re-used so that
     * Scouting Office and Youth Academy can support both old youth scouting and
     * the new scouting proposals without adding another building effect column.
     */
    private static function getCompletedStadiumBuildingEffect(WebSoccer $websoccer, DbConnection $db, $teamId, $attributeName) {
        $allowedAttributes = array('effect_youthscouting');
        if (!in_array($attributeName, $allowedAttributes, true)) {
            return 0;
        }
        
        $prefix = self::tablePrefix($websoccer);
        $fromTable = $prefix . 'buildings_of_team AS BT INNER JOIN ' . $prefix . 'stadiumbuilding AS B ON B.id = BT.building_id';
        $row = self::getSingleRow(
            $db,
            'SUM(B.' . $attributeName . ') AS attr_sum',
            $fromTable,
            'BT.team_id = %d AND BT.construction_deadline < %d',
            array((int) $teamId, self::now($websoccer))
        );
        
        return (isset($row['attr_sum'])) ? (int) $row['attr_sum'] : 0;
    }
    
    /* ---------------------------------------------------------------------
     * Department
     * ------------------------------------------------------------------ */
    
    public static function getDepartment(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $prefix = self::tablePrefix($websoccer);
        $fromTable = $prefix . 'scouting_department AS D LEFT JOIN ' . $prefix . 'scouting_department_level AS L ON L.level = D.level';
        
        $columns = array(
            'D.id' => 'id',
            'D.team_id' => 'team_id',
            'D.level' => 'level',
            'D.created_date' => 'created_date',
            'D.updated_date' => 'updated_date',
            'D.maintenance_fee' => 'maintenance_fee',
            'D.status' => 'status',
            'L.name' => 'level_name',
            'L.build_cost' => 'level_build_cost',
            'L.maintenance_fee' => 'level_maintenance_fee',
            'L.max_scouts' => 'max_scouts',
            'L.max_camps' => 'max_camps'
        );
        
        $department = self::getSingleRow($db, $columns, $fromTable, 'D.team_id = %d', (int) $teamId);
        
        if ($department && (!isset($department['maintenance_fee']) || (int) $department['maintenance_fee'] <= 0)) {
            $department['maintenance_fee'] = (isset($department['level_maintenance_fee']))
            ? (int) $department['level_maintenance_fee']
            : self::configInt($websoccer, 'scouting_default_department_maintenance_fee', 50000);
        }
        
        return $department;
    }
    
    public static function getDepartmentLevel(WebSoccer $websoccer, DbConnection $db, $level) {
        return self::getSingleRow(
            $db,
            '*',
            self::tablePrefix($websoccer) . 'scouting_department_level',
            'level = %d AND status = \'1\'',
            (int) $level
            );
    }
    
    public static function getNextDepartmentLevel(WebSoccer $websoccer, DbConnection $db, $currentLevel) {
        return self::getSingleRow(
            $db,
            '*',
            self::tablePrefix($websoccer) . 'scouting_department_level',
            'level > %d AND status = \'1\' ORDER BY level ASC',
            (int) $currentLevel
            );
    }
    
    public static function buildDepartment(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $existing = self::getDepartment($websoccer, $db, $teamId);
        if ($existing && isset($existing['id'])) {
            throw new Exception('scouting_err_department_exists');
        }
        
        $level = self::getDepartmentLevel($websoccer, $db, 1);
        $buildCost = ($level && isset($level['build_cost']))
        ? (int) $level['build_cost']
        : self::configInt($websoccer, 'scouting_default_department_build_cost', 1000000);
        $maintenanceFee = ($level && isset($level['maintenance_fee']))
        ? (int) $level['maintenance_fee']
        : self::configInt($websoccer, 'scouting_default_department_maintenance_fee', 50000);
        
        if (!self::debitIfPossible($websoccer, $db, $teamId, $buildCost, 'scouting_department_build_cost', 'scouting_department')) {
            throw new Exception('scouting_err_not_enough_budget');
        }
        
        $db->queryInsert(array(
            'team_id' => (int) $teamId,
            'level' => 1,
            'created_date' => self::now($websoccer),
            'updated_date' => self::now($websoccer),
            'maintenance_fee' => $maintenanceFee,
            'status' => '1'
        ), self::tablePrefix($websoccer) . 'scouting_department');
    }
    
    public static function upgradeDepartment(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $department = self::getDepartment($websoccer, $db, $teamId);
        if (!$department || !isset($department['id'])) {
            throw new Exception('scouting_err_department_missing');
        }
        
        $nextLevel = self::getNextDepartmentLevel($websoccer, $db, (int) $department['level']);
        if (!$nextLevel || !isset($nextLevel['level'])) {
            throw new Exception('scouting_err_department_max_level');
        }
        
        $buildCost = (int) $nextLevel['build_cost'];
        if (!self::debitIfPossible($websoccer, $db, $teamId, $buildCost, 'scouting_department_upgrade_cost', 'scouting_department')) {
            throw new Exception('scouting_err_not_enough_budget');
        }
        
        $db->queryUpdate(array(
            'level' => (int) $nextLevel['level'],
            'maintenance_fee' => (int) $nextLevel['maintenance_fee'],
            'updated_date' => self::now($websoccer),
            'status' => '1'
        ), self::tablePrefix($websoccer) . 'scouting_department', 'team_id = %d', (int) $teamId);
    }
    
    /* ---------------------------------------------------------------------
     * Scouts
     * ------------------------------------------------------------------ */
    
    public static function getAvailableScouts(WebSoccer $websoccer, DbConnection $db) {
        $rows = self::getRows(
            $db,
            '*',
            self::tablePrefix($websoccer) . 'scout',
            'team_id = 0 ORDER BY fee ASC, name ASC'
            );
        
        $scouts = array();
        foreach ($rows as $row) {
            $scout = self::cleanScoutForManager($row);
            $scout['hire_fee'] = self::calculateScoutHiringFee($websoccer, $row);
            $scout['hire_fee_matches'] = self::getScoutHireFeeMatches($websoccer);
            $scouts[] = $scout;
        }
        return $scouts;
    }
    
    public static function getTeamScouts(WebSoccer $websoccer, DbConnection $db, $teamId) {
        // Release expired scouts first.
        $db->queryUpdate(
            array('team_id' => 0),
            self::tablePrefix($websoccer) . 'scout',
            'team_matches <= 0 AND team_id > 0',
            0
            );
        
        $rows = self::getRows(
            $db,
            '*',
            self::tablePrefix($websoccer) . 'scout',
            'team_id = %d AND team_matches > 0 ORDER BY fee ASC, name ASC',
            (int) $teamId
            );
        
        $scouts = array();
        foreach ($rows as $row) {
            $scouts[] = self::cleanScoutForManager($row);
        }
        return $scouts;
    }
    
    public static function getInternalScoutById(WebSoccer $websoccer, DbConnection $db, $scoutId) {
        return self::getSingleRow(
            $db,
            '*',
            self::tablePrefix($websoccer) . 'scout',
            'id = %d',
            (int) $scoutId
            );
    }
    
    /**
     * Kept for backwards compatibility with old code.
     */
    public static function getScoutById(WebSoccer $websoccer, DbConnection $db, $scoutId) {
        return self::getInternalScoutById($websoccer, $db, $scoutId);
    }
    
    public static function getScoutByTeamSpeciality(WebSoccer $websoccer, DbConnection $db, $teamId, $speciality) {
        return self::getSingleRow(
            $db,
            '*',
            self::tablePrefix($websoccer) . 'scout',
            'team_id = %d AND speciality = \'%s\' AND team_matches > 0 ORDER BY expertise DESC, fee DESC',
            array((int) $teamId, $speciality)
            );
    }
    
    public static function countTeamScouts(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $row = self::getSingleRow(
            $db,
            'COUNT(*) AS qty',
            self::tablePrefix($websoccer) . 'scout',
            'team_id = %d AND team_matches > 0',
            (int) $teamId
            );
        return (isset($row['qty'])) ? (int) $row['qty'] : 0;
    }
    
    public static function getMaxScoutsForTeam(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $department = self::getDepartment($websoccer, $db, $teamId);
        if (!$department || !isset($department['id']) || $department['status'] !== '1') {
            return 0;
        }
        
        $globalMax = self::configInt($websoccer, 'max_scouts_per_team', 4);
        $levelMax = (isset($department['max_scouts']) && (int) $department['max_scouts'] > 0) ? (int) $department['max_scouts'] : $globalMax;
        // The watchlist recommendation works by the four broad position groups.
        // Therefore an active scouting department should be able to cover up to
        // four scouts, one for Torwart, Abwehr, Mittelfeld and Sturm.
        return min($globalMax, max(4, $levelMax));
    }
    
    public static function checkHiredScoutsByTeam(WebSoccer $websoccer, DbConnection $db, $teamId) {
        return (self::countTeamScouts($websoccer, $db, $teamId) < self::getMaxScoutsForTeam($websoccer, $db, $teamId)) ? 1 : 0;
    }
    
    public static function hireScout(WebSoccer $websoccer, DbConnection $db, $scoutId, $teamId) {
        $department = self::getDepartment($websoccer, $db, $teamId);
        if (!$department || !isset($department['id']) || $department['status'] !== '1') {
            throw new Exception('scouting_err_department_missing');
        }
        
        $scout = self::getInternalScoutById($websoccer, $db, $scoutId);
        if (!$scout || !isset($scout['id']) || (int) $scout['team_id'] !== 0) {
            throw new Exception('scouting_err_scout_not_available');
        }
        
        $existingSpecialityScout = self::getScoutByTeamSpeciality($websoccer, $db, $teamId, $scout['speciality']);
        if ($existingSpecialityScout && isset($existingSpecialityScout['id'])) {
            throw new Exception('scouting_err_scout_speciality_exists');
        }
        
        if (!self::checkHiredScoutsByTeam($websoccer, $db, $teamId)) {
            throw new Exception('scouting_err_max_scouts_reached');
        }
        
        // Anti-exploit: hiring a scout immediately costs a non-refundable
        // signing fee. This prevents managers from hiring a scout, reading the
        // watchlist recommendations and firing him again before the next matchday.
        $hiringFee = self::calculateScoutHiringFee($websoccer, $scout);
        if (!self::debitIfPossible($websoccer, $db, $teamId, $hiringFee, 'scouting_scout_hire_fee', $scout['name'])) {
            throw new Exception('scouting_err_not_enough_budget');
        }
        
        // The normal scout fee is still charged per matchday.
        $matches = self::configInt($websoccer, 'scouts_matches', 20);
        
        $db->queryUpdate(array(
            'team_id' => (int) $teamId,
            'team_matches' => $matches
        ), self::tablePrefix($websoccer) . 'scout', 'id = %d', (int) $scoutId);
    }
    
    public static function fireScout(WebSoccer $websoccer, DbConnection $db, $scoutId, $teamId) {
        $scout = self::getInternalScoutById($websoccer, $db, $scoutId);
        if (!$scout || !isset($scout['id']) || (int) $scout['team_id'] !== (int) $teamId) {
            throw new Exception('scouting_err_scout_not_owned');
        }
        
        // Close camps using this scout.
        $db->queryUpdate(array('status' => '0'), self::tablePrefix($websoccer) . 'scouting_camp',
            'team_id = %d AND scout_id = %d AND status = \'1\'', array((int) $teamId, (int) $scoutId));
        
        $db->queryUpdate(array(
            'team_id' => 0,
            'team_matches' => self::configInt($websoccer, 'scouts_matches', 20)
        ), self::tablePrefix($websoccer) . 'scout', 'id = %d', (int) $scoutId);
    }
    
    public static function reduceTeamMatches(WebSoccer $websoccer, DbConnection $db) {
        // Backwards-compatible method. New code should call processMatchdayScouting().
        $db->executeQuery('UPDATE ' . self::tablePrefix($websoccer) . 'scout SET team_matches = team_matches - 1 WHERE team_matches > 0 AND team_id > 0');
        $db->executeQuery('UPDATE ' . self::tablePrefix($websoccer) . 'scout SET team_id = 0 WHERE team_matches <= 0 AND team_id > 0');
    }
    
    /* ---------------------------------------------------------------------
     * Camp locations and camps
     * ------------------------------------------------------------------ */
    
    public static function getAvailableCampLocations(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $department = self::getDepartment($websoccer, $db, $teamId);
        if (!$department || !isset($department['level'])) {
            return array();
        }
        
        return self::getRows(
            $db,
            '*',
            self::tablePrefix($websoccer) . 'scouting_camp_location',
            'status = \'1\' AND min_department_level <= %d ORDER BY min_department_level ASC, base_fee ASC, name ASC',
            (int) $department['level']
            );
    }
    
    public static function getCampLocationById(WebSoccer $websoccer, DbConnection $db, $locationId) {
        return self::getSingleRow(
            $db,
            '*',
            self::tablePrefix($websoccer) . 'scouting_camp_location',
            'id = %d AND status = \'1\'',
            (int) $locationId
            );
    }
    
    public static function getTeamCamps(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $prefix = self::tablePrefix($websoccer);
        $fromTable = $prefix . 'scouting_camp AS C';
        $fromTable .= ' LEFT JOIN ' . $prefix . 'scouting_camp_location AS L ON L.id = C.location_id';
        $fromTable .= ' LEFT JOIN ' . $prefix . 'scout AS S ON S.id = C.scout_id';
        
        $columns = array(
            'C.id' => 'id',
            'C.team_id' => 'team_id',
            'C.location_id' => 'location_id',
            'C.scout_id' => 'scout_id',
            'C.position' => 'position',
            'C.age_min' => 'age_min',
            'C.age_max' => 'age_max',
            'C.strength_min' => 'strength_min',
            'C.strength_max' => 'strength_max',
            'C.budget_min' => 'budget_min',
            'C.budget_max' => 'budget_max',
            'C.fee_per_matchday' => 'fee_per_matchday',
            'C.matches_until_next_proposal' => 'matches_until_next_proposal',
            'C.next_proposal_after_matches' => 'next_proposal_after_matches',
            'C.created_date' => 'created_date',
            'C.status' => 'status',
            'L.name' => 'location_name',
            'L.country' => 'location_country',
            'L.continent' => 'location_continent',
            'S.name' => 'scout_name',
            'S.nation' => 'scout_nation',
            'S.speciality' => 'scout_speciality'
        );
        
        return self::getRows($db, $columns, $fromTable, 'C.team_id = %d ORDER BY C.status DESC, L.name ASC', (int) $teamId);
    }
    
    public static function countActiveTeamCamps(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $row = self::getSingleRow($db, 'COUNT(*) AS qty', self::tablePrefix($websoccer) . 'scouting_camp',
            'team_id = %d AND status = \'1\'', (int) $teamId);
        return (isset($row['qty'])) ? (int) $row['qty'] : 0;
    }
    
    public static function getMaxCampsForTeam(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $department = self::getDepartment($websoccer, $db, $teamId);
        if (!$department || !isset($department['id']) || $department['status'] !== '1') {
            return 0;
        }
        
        return (isset($department['max_camps'])) ? (int) $department['max_camps'] : 0;
    }
    
    public static function calculateCampFee(WebSoccer $websoccer, DbConnection $db, $teamId, $location) {
        $baseFee = (isset($location['base_fee'])) ? (int) $location['base_fee'] : 0;
        $travelModifier = (isset($location['travel_cost_modifier'])) ? (int) $location['travel_cost_modifier'] : 0;
        
        $teamCountry = self::getTeamCountry($websoccer, $db, $teamId);
        $locationCountry = (isset($location['country'])) ? trim((string) $location['country']) : '';
        
        $countryFactor = 100;
        if (strlen($locationCountry) && strlen($teamCountry) && $locationCountry === $teamCountry) {
            $countryFactor = self::configInt($websoccer, 'scouting_same_country_cost_percent', 75);
        } elseif (strlen($locationCountry) || isset($location['continent'])) {
            $countryFactor = self::configInt($websoccer, 'scouting_abroad_cost_percent', 125);
        }
        
        $fee = round($baseFee * ($countryFactor / 100));
        $fee = round($fee * ((100 + $travelModifier) / 100));
        return max(0, (int) $fee);
    }
    
    public static function createCamp(WebSoccer $websoccer, DbConnection $db, $teamId, $locationId, $scoutId, $position, $ageMin, $ageMax, $strengthMin, $strengthMax, $budgetMin, $budgetMax) {
        $department = self::getDepartment($websoccer, $db, $teamId);
        if (!$department || !isset($department['id']) || $department['status'] !== '1') {
            throw new Exception('scouting_err_department_missing');
        }
        
        $maxCamps = self::getMaxCampsForTeam($websoccer, $db, $teamId);
        if ($maxCamps > 0 && self::countActiveTeamCamps($websoccer, $db, $teamId) >= $maxCamps) {
            throw new Exception('scouting_err_max_camps_reached');
        }
        
        $location = self::getCampLocationById($websoccer, $db, $locationId);
        if (!$location || !isset($location['id']) || (int) $location['min_department_level'] > (int) $department['level']) {
            throw new Exception('scouting_err_location_not_available');
        }
        
        $scout = self::getInternalScoutById($websoccer, $db, $scoutId);
        if (!$scout || !isset($scout['id']) || (int) $scout['team_id'] !== (int) $teamId || (int) $scout['team_matches'] <= 0) {
            throw new Exception('scouting_err_scout_not_owned');
        }
        
        $position = self::normalizePosition($position);
        $ageMin = self::clamp((int) $ageMin, 16, 45);
        $ageMax = self::clamp((int) $ageMax, 16, 45);
        if ($ageMax < $ageMin) {
            $tmp = $ageMin;
            $ageMin = $ageMax;
            $ageMax = $tmp;
        }
        
        $strengthMin = self::clamp((int) $strengthMin, 1, 99);
        $strengthMax = self::clamp((int) $strengthMax, 1, 99);
        if ($strengthMax < $strengthMin) {
            $tmp = $strengthMin;
            $strengthMin = $strengthMax;
            $strengthMax = $tmp;
        }
        
        $budgetMin = max(0, (int) $budgetMin);
        $budgetMax = max(0, (int) $budgetMax);
        if ($budgetMax > 0 && $budgetMax < $budgetMin) {
            $tmp = $budgetMin;
            $budgetMin = $budgetMax;
            $budgetMax = $tmp;
        }
        
        $minWait = self::configInt($websoccer, 'scouting_proposal_min_matches', 10);
        $maxWait = self::configInt($websoccer, 'scouting_proposal_max_matches', 20);
        if ($maxWait < $minWait) {
            $maxWait = $minWait;
        }
        $countdown = mt_rand($minWait, $maxWait);
        
        $db->queryInsert(array(
            'team_id' => (int) $teamId,
            'location_id' => (int) $locationId,
            'scout_id' => (int) $scoutId,
            'position' => ($position) ? $position : '',
            'age_min' => $ageMin,
            'age_max' => $ageMax,
            'strength_min' => $strengthMin,
            'strength_max' => $strengthMax,
            'budget_min' => $budgetMin,
            'budget_max' => $budgetMax,
            'fee_per_matchday' => self::calculateCampFee($websoccer, $db, $teamId, $location),
            'next_proposal_after_matches' => $countdown,
            'matches_until_next_proposal' => $countdown,
            'created_date' => self::now($websoccer),
            'last_execution' => 0,
            'status' => '1'
        ), self::tablePrefix($websoccer) . 'scouting_camp');
    }
    
    public static function closeCamp(WebSoccer $websoccer, DbConnection $db, $campId, $teamId) {
        $db->queryUpdate(array('status' => '0'), self::tablePrefix($websoccer) . 'scouting_camp',
            'id = %d AND team_id = %d', array((int) $campId, (int) $teamId));
    }
    
    /* ---------------------------------------------------------------------
     * Proposals
     * ------------------------------------------------------------------ */
    
    public static function getTeamProposals(WebSoccer $websoccer, DbConnection $db, $teamId, $status = 'open') {
        $prefix = self::tablePrefix($websoccer);
        $fromTable = $prefix . 'scouting_proposal AS P';
        $fromTable .= ' LEFT JOIN ' . $prefix . 'scouting_camp AS C ON C.id = P.camp_id';
        $fromTable .= ' LEFT JOIN ' . $prefix . 'scouting_camp_location AS L ON L.id = P.location_id';
        $fromTable .= ' LEFT JOIN ' . $prefix . 'scout AS S ON S.id = P.scout_id';
        
        $columns = array(
            'P.*' => 'proposal_all',
            'P.id' => 'id',
            'P.team_id' => 'team_id',
            'P.camp_id' => 'camp_id',
            'P.firstname' => 'firstname',
            'P.lastname' => 'lastname',
            'P.age' => 'age',
            'P.nation' => 'nation',
            'P.position' => 'position',
            'P.position_main' => 'position_main',
            'P.reported_strength' => 'reported_strength',
            'P.reported_talent' => 'reported_talent',
            'P.reported_potential' => 'reported_potential',
            'P.reported_summary' => 'reported_summary',
            'P.transfer_fee' => 'transfer_fee',
            'P.salary' => 'salary',
            'P.contract_matches' => 'contract_matches',
            'P.expires_after_matches' => 'expires_after_matches',
            'P.created_date' => 'created_date',
            'P.status' => 'status',
            'L.name' => 'location_name',
            'S.name' => 'scout_name'
        );
        
        // P.* AS alias is not valid for all MySQL modes, therefore use explicit fallback query here.
        $sql = 'SELECT P.*, L.name AS location_name, S.name AS scout_name '
            . 'FROM ' . $fromTable . ' '
                . 'WHERE P.team_id = ' . (int) $teamId;
                if ($status !== null && strlen($status)) {
                    $sql .= " AND P.status = '" . $db->connection->real_escape_string($status) . "'";
                }
                $sql .= ' ORDER BY P.status ASC, P.created_date DESC';
                
                $result = $db->executeQuery($sql);
                $rows = array();
                while ($row = $result->fetch_array()) {
                    if (class_exists('PlayerTraitsDataService')) {
                        $row['reported_traits_display'] = PlayerTraitsDataService::traitMapToDisplayRows(isset($row['reported_traits']) ? $row['reported_traits'] : '');
                        $row['real_traits_display'] = PlayerTraitsDataService::traitMapToDisplayRows(isset($row['real_traits']) ? $row['real_traits'] : '');
                    } else {
                        $row['reported_traits_display'] = array();
                        $row['real_traits_display'] = array();
                    }
                    $rows[] = $row;
                }
                $result->free();
                return $rows;
    }
    
    public static function countOpenProposals(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $row = self::getSingleRow($db, 'COUNT(*) AS qty', self::tablePrefix($websoccer) . 'scouting_proposal',
            'team_id = %d AND status = \'open\'', (int) $teamId);
        return (isset($row['qty'])) ? (int) $row['qty'] : 0;
    }
    
    public static function getProposalById(WebSoccer $websoccer, DbConnection $db, $proposalId, $teamId = null) {
        if ($teamId !== null) {
            return self::getSingleRow($db, '*', self::tablePrefix($websoccer) . 'scouting_proposal',
                'id = %d AND team_id = %d', array((int) $proposalId, (int) $teamId));
        }
        
        return self::getSingleRow($db, '*', self::tablePrefix($websoccer) . 'scouting_proposal', 'id = %d', (int) $proposalId);
    }
    
    public static function acceptProposal(WebSoccer $websoccer, DbConnection $db, $proposalId, $teamId) {
        $proposal = self::getProposalById($websoccer, $db, $proposalId, $teamId);
        if (!$proposal || !isset($proposal['id']) || $proposal['status'] !== 'open') {
            throw new Exception('scouting_err_proposal_not_available');
        }
        
        $fee = (int) $proposal['transfer_fee'];
        if (!self::debitIfPossible($websoccer, $db, $teamId, $fee, 'scouting_proposal_transfer_fee', $proposal['firstname'] . ' ' . $proposal['lastname'])) {
            throw new Exception('scouting_err_not_enough_budget');
        }
        
        $db->connection->begin_transaction();
        try {
            $columns = array(
                'verein_id' => (int) $teamId,
                'vorname' => $proposal['firstname'],
                'nachname' => $proposal['lastname'],
                'geburtstag' => $proposal['birthday'],
                'age' => (int) $proposal['age'],
                'position' => $proposal['position'],
                'position_main' => $proposal['position_main'],
                'position_second' => (strlen((string) $proposal['position_second'])) ? $proposal['position_second'] : '',
                'nation' => $proposal['nation'],
                'transfermarkt' => '0',
                'w_staerke_calc' => (string) $proposal['real_w_staerke'],
                'w_staerke' => $proposal['real_w_staerke'],
                'w_staerke_max' => $proposal['real_w_staerke_max'],
                'w_technik' => $proposal['real_w_technik'],
                'w_kondition' => $proposal['real_w_kondition'],
                'w_frische' => $proposal['real_w_frische'],
                'w_zufriedenheit' => $proposal['real_w_zufriedenheit'],
                'w_talent' => (int) $proposal['real_w_talent'],
                'personality' => PlayerPersonalityDataService::getRandomTrait(),
                'w_passing' => $proposal['real_w_passing'],
                'w_shooting' => $proposal['real_w_shooting'],
                'w_heading' => $proposal['real_w_heading'],
                'w_tackling' => $proposal['real_w_tackling'],
                'w_freekick' => $proposal['real_w_freekick'],
                'w_pace' => $proposal['real_w_pace'],
                'w_creativity' => $proposal['real_w_creativity'],
                'w_influence' => $proposal['real_w_influence'],
                'w_flair' => $proposal['real_w_flair'],
                'w_penalty' => $proposal['real_w_penalty'],
                'w_penalty_killing' => $proposal['real_w_penalty_killing'],
                'vertrag_gehalt' => (int) $proposal['salary'],
                'vertrag_spiele' => (int) $proposal['contract_matches'],
                'vertrag_torpraemie' => 0,
                'marktwert' => (int) $proposal['transfer_fee'],
                'unsellable' => '0',
                'status' => '1',
                'on_update' => '0'
            );
            
            $db->queryInsert($columns, self::tablePrefix($websoccer) . 'spieler');
            $playerId = $db->getLastInsertedId();

            if (class_exists('PlayerTraitsDataService') && isset($proposal['real_traits'])) {
                $proposalTraits = PlayerTraitsDataService::decodeTraitMap($proposal['real_traits']);
                PlayerTraitsDataService::assignTraitsToPlayer($websoccer, $db, (int) $playerId, $proposalTraits);
            }
            
            $db->queryUpdate(array(
                'status' => 'accepted',
                'accepted_date' => self::now($websoccer),
                'created_player_id' => (int) $playerId
            ), self::tablePrefix($websoccer) . 'scouting_proposal', 'id = %d', (int) $proposalId);
            
            $db->connection->commit();
            return $playerId;
        } catch (Exception $e) {
            $db->connection->rollback();
            throw $e;
        }
    }
    
    public static function rejectProposal(WebSoccer $websoccer, DbConnection $db, $proposalId, $teamId) {
        $proposal = self::getProposalById($websoccer, $db, $proposalId, $teamId);
        if (!$proposal || !isset($proposal['id']) || $proposal['status'] !== 'open') {
            throw new Exception('scouting_err_proposal_not_available');
        }
        
        $db->queryUpdate(array(
            'status' => 'rejected',
            'rejected_date' => self::now($websoccer)
        ), self::tablePrefix($websoccer) . 'scouting_proposal', 'id = %d', (int) $proposalId);
    }
    
    /* ---------------------------------------------------------------------
     * Matchday processing
     * ------------------------------------------------------------------ */
    
    public static function processMatchdayScouting(WebSoccer $websoccer, DbConnection $db, $teamId = 0) {
        if (!self::isEnabled($websoccer)) {
            return;
        }
        
        self::expireOpenProposals($websoccer, $db, $teamId);
        self::processDepartmentCosts($websoccer, $db, $teamId);
        self::processScoutCostsAndDurations($websoccer, $db, $teamId);
        self::processCampCostsAndProposals($websoccer, $db, $teamId);
    }
    
    public static function processMatchCompletedScouting(MatchCompletedEvent $event) {
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
            self::processMatchdayScoutingForTeam($event->websoccer, $event->db, $teamId, $matchId);
        }
    }
    
    public static function processMatchdayScoutingForTeam(WebSoccer $websoccer, DbConnection $db, $teamId, $matchId) {
        $teamId = (int) $teamId;
        $matchId = (int) $matchId;
        
        if ($teamId < 1 || $matchId < 1 || !self::isEnabled($websoccer)) {
            return false;
        }
        
        $markerName = self::getTeamScoutingMarkerName($teamId);
        if (self::getTeamScoutingMarker($websoccer, $db, $markerName) >= $matchId) {
            return false;
        }
        
        self::processMatchdayScouting($websoccer, $db, $teamId);
        self::setTeamScoutingMarker($websoccer, $db, $markerName, $matchId);
        
        return true;
    }
    
    private static function getTeamScoutingMarkerName($teamId) {
        return 'scoutmd_' . (int) $teamId;
    }
    
    private static function getTeamScoutingMarker(WebSoccer $websoccer, DbConnection $db, $markerName) {
        self::ensureTeamScoutingMarker($websoccer, $db, $markerName);
        
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
    
    private static function setTeamScoutingMarker(WebSoccer $websoccer, DbConnection $db, $markerName, $matchId) {
        self::ensureTeamScoutingMarker($websoccer, $db, $markerName);
        
        $db->queryUpdate(
            array('zeitstempel' => (int) $matchId),
            $websoccer->getConfig('db_prefix') . '_config',
            "name = '%s'",
            $markerName
        );
    }
    
    private static function ensureTeamScoutingMarker(WebSoccer $websoccer, DbConnection $db, $markerName) {
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
                'descr' => 'Scouting team marker'
            ),
            $websoccer->getConfig('db_prefix') . '_config'
        );
    }
    
    private static function expireOpenProposals(WebSoccer $websoccer, DbConnection $db, $teamId = 0) {
        $prefix = self::tablePrefix($websoccer);
        $teamSql = ((int) $teamId > 0) ? ' AND team_id = ' . (int) $teamId : '';
        
        $db->executeQuery(
            'UPDATE ' . $prefix . "scouting_proposal 
             SET expires_after_matches = expires_after_matches - 1 
             WHERE status = 'open' AND expires_after_matches > 0" . $teamSql
        );
        
        $db->executeQuery(
            'UPDATE ' . $prefix . "scouting_proposal 
             SET status = 'expired' 
             WHERE status = 'open' AND expires_after_matches <= 0" . $teamSql
        );
    }
    
    private static function processDepartmentCosts(WebSoccer $websoccer, DbConnection $db, $teamId = 0) {
        $where = "status = '1'";
        if ((int) $teamId > 0) {
            $where .= ' AND team_id = ' . (int) $teamId;
        }
        
        $departments = self::getRows($db, '*', self::tablePrefix($websoccer) . 'scouting_department', $where);
        
        foreach ($departments as $department) {
            $teamId = (int) $department['team_id'];
            $fee = (int) $department['maintenance_fee'];
            
            if (!self::debitIfPossible($websoccer, $db, $teamId, $fee, 'scouting_department_maintenance_fee', 'scouting_department')) {
                // No money: deactivate scouting infrastructure and release scouts.
                $db->queryUpdate(array('status' => '0'), self::tablePrefix($websoccer) . 'scouting_department', 'team_id = %d', $teamId);
                $db->queryUpdate(array('status' => '0'), self::tablePrefix($websoccer) . 'scouting_camp', 'team_id = %d AND status = \'1\'', $teamId);
                $db->queryUpdate(array('team_id' => 0), self::tablePrefix($websoccer) . 'scout', 'team_id = %d', $teamId);
            }
        }
    }
    
    private static function processScoutCostsAndDurations(WebSoccer $websoccer, DbConnection $db, $teamId = 0) {
        $where = 'team_id > 0 AND team_matches > 0';
        if ((int) $teamId > 0) {
            $where .= ' AND team_id = ' . (int) $teamId;
        }
        
        $scouts = self::getRows($db, '*', self::tablePrefix($websoccer) . 'scout', $where);
        
        foreach ($scouts as $scout) {
            $teamId = (int) $scout['team_id'];
            $fee = (int) $scout['fee'];
            
            if (!self::debitIfPossible($websoccer, $db, $teamId, $fee, 'scouting_scout_fee', $scout['name'])) {
                $db->queryUpdate(array('status' => '0'), self::tablePrefix($websoccer) . 'scouting_camp',
                    'team_id = %d AND scout_id = %d AND status = \'1\'', array($teamId, (int) $scout['id']));
                $db->queryUpdate(array('team_id' => 0), self::tablePrefix($websoccer) . 'scout', 'id = %d', (int) $scout['id']);
                continue;
            }
            
            $newMatches = max(0, ((int) $scout['team_matches']) - 1);
            if ($newMatches <= 0) {
                $db->queryUpdate(array('team_id' => 0, 'team_matches' => 0), self::tablePrefix($websoccer) . 'scout', 'id = %d', (int) $scout['id']);
                $db->queryUpdate(array('status' => '0'), self::tablePrefix($websoccer) . 'scouting_camp',
                    'team_id = %d AND scout_id = %d AND status = \'1\'', array($teamId, (int) $scout['id']));
            } else {
                $db->queryUpdate(array('team_matches' => $newMatches), self::tablePrefix($websoccer) . 'scout', 'id = %d', (int) $scout['id']);
            }
        }
    }
    
    private static function processCampCostsAndProposals(WebSoccer $websoccer, DbConnection $db, $teamId = 0) {
        $where = "status = '1'";
        if ((int) $teamId > 0) {
            $where .= ' AND team_id = ' . (int) $teamId;
        }
        
        $camps = self::getRows($db, '*', self::tablePrefix($websoccer) . 'scouting_camp', $where);
        $maxOpen = self::configInt($websoccer, 'scouting_max_open_proposals_per_team', 10);
        
        foreach ($camps as $camp) {
            $teamId = (int) $camp['team_id'];
            $fee = (int) $camp['fee_per_matchday'];
            
            if (!self::debitIfPossible($websoccer, $db, $teamId, $fee, 'scouting_camp_fee', 'scouting_camp')) {
                $db->queryUpdate(array('status' => '0'), self::tablePrefix($websoccer) . 'scouting_camp', 'id = %d', (int) $camp['id']);
                continue;
            }
            
            if ($maxOpen > 0 && self::countOpenProposals($websoccer, $db, $teamId) >= $maxOpen) {
                continue;
            }
            
            $remaining = max(0, ((int) $camp['matches_until_next_proposal']) - 1);
            if ($remaining <= 0) {
                self::createProposalForCamp($websoccer, $db, $camp);
                $minWait = self::configInt($websoccer, 'scouting_proposal_min_matches', 10);
                $maxWait = self::configInt($websoccer, 'scouting_proposal_max_matches', 20);
                if ($maxWait < $minWait) {
                    $maxWait = $minWait;
                }
                $next = mt_rand($minWait, $maxWait);
                $db->queryUpdate(array(
                    'matches_until_next_proposal' => $next,
                    'next_proposal_after_matches' => $next,
                    'last_execution' => self::now($websoccer)
                ), self::tablePrefix($websoccer) . 'scouting_camp', 'id = %d', (int) $camp['id']);
            } else {
                $db->queryUpdate(array(
                    'matches_until_next_proposal' => $remaining,
                    'last_execution' => self::now($websoccer)
                ), self::tablePrefix($websoccer) . 'scouting_camp', 'id = %d', (int) $camp['id']);
            }
        }
    }
    
    public static function createProposalForCamp(WebSoccer $websoccer, DbConnection $db, $camp) {
        $location = self::getCampLocationById($websoccer, $db, (int) $camp['location_id']);
        $scout = self::getInternalScoutById($websoccer, $db, (int) $camp['scout_id']);
        
        if (!$location || !$scout || !isset($scout['id'])) {
            return;
        }
        
        $country = self::chooseCountryForLocation($websoccer, $db, $location, (int) $camp['team_id']);
        $namesCountry = self::getNamesCountry($country);
        $firstname = self::getRandomNameItem($namesCountry, 'firstnames.txt');
        $lastname = self::getRandomNameItem($namesCountry, 'lastnames.txt');
        
        $position = self::normalizePosition($camp['position']);
        if (!$position) {
            $position = self::weightedPosition($scout['speciality']);
        }
        $positionMain = self::randomMainPosition($position);
        
        $ageMin = self::clamp((int) $camp['age_min'], 16, 45);
        $ageMax = self::clamp((int) $camp['age_max'], 16, 45);
        if ($ageMax < $ageMin) {
            $ageMax = $ageMin;
        }
        $age = mt_rand($ageMin, $ageMax);
        $birthday = date('Y-m-d', strtotime('-' . $age . ' years', self::now($websoccer)));
        
        $strengthMin = self::clamp((int) $camp['strength_min'], 1, 99);
        $strengthMax = self::clamp((int) $camp['strength_max'], 1, 99);
        if ($strengthMax < $strengthMin) {
            $strengthMax = $strengthMin;
        }
        
        $expertise = (int) $scout['expertise'];
        $qualityModifier = (isset($location['quality_modifier'])) ? (int) $location['quality_modifier'] : 0;
        $talentModifier = (isset($location['talent_modifier'])) ? (int) $location['talent_modifier'] : 0;
        
        $stadiumScoutingBonus = self::getCompletedStadiumBuildingEffect($websoccer, $db, (int) $camp['team_id'], 'effect_youthscouting');
        if ($stadiumScoutingBonus > 0) {
            // Each old youth-scouting building point adds a modest infrastructure boost
            // to the new scouting proposal generation and report quality.
            $qualityModifier += ($stadiumScoutingBonus * 4);
            $talentModifier += $stadiumScoutingBonus;
        }
        
        if (class_exists('YouthAcademyDataService')) {
            $academyScoutingBonus = YouthAcademyDataService::getScoutingModifier($websoccer, $db, (int) $camp['team_id']);
            if ($academyScoutingBonus > 0) {
                // Youth Academy 2.0 harmonizes the classic youth academy with the newer scouting department.
                // The effect is deliberately small and goes into quality/reliability, not guaranteed stars.
                $qualityModifier += $academyScoutingBonus;
                $talentModifier += (int) round($academyScoutingBonus / 3);
            }
        }

        if (class_exists('ClubPartnershipDataService')) {
            $partnershipScoutingBonus = ClubPartnershipDataService::getSharedScoutingBonus($websoccer, $db, (int) $camp['team_id']);
            if ($partnershipScoutingBonus > 0) {
                $qualityModifier += $partnershipScoutingBonus;
                $talentModifier += (int) max(1, round($partnershipScoutingBonus / 5));
            }
        }
        
        $effectiveQuality = self::clamp($expertise + $qualityModifier, 1, 100);
        $baseStrength = mt_rand($strengthMin, $strengthMax);
        $qualityBonus = round(($effectiveQuality - 50) / 20);
        $strength = self::clamp($baseStrength + $qualityBonus + mt_rand(-3, 3), 1, 99);
        
        $talent = self::generateTalent($effectiveQuality, $talentModifier, $age);
        $strengthMaxReal = self::clamp($strength + mt_rand(3, 12) + ($talent * mt_rand(2, 5)), $strength, 99);
        
        $skillDeviation = max(4, 18 - (int) round($effectiveQuality / 10));
        $technique = self::skillAround($strength, $skillDeviation);
        $passing = self::skillAround($strength, $skillDeviation);
        $shooting = self::skillAround($strength, $skillDeviation);
        $heading = self::skillAround($strength, $skillDeviation);
        $tackling = self::skillAround($strength, $skillDeviation);
        $freekick = self::skillAround($strength, $skillDeviation);
        $pace = self::skillAround($strength, $skillDeviation);
        $creativity = self::skillAround($strength, $skillDeviation);
        $influence = self::skillAround($strength, $skillDeviation);
        $flair = self::skillAround($strength, $skillDeviation);
        $penalty = self::skillAround($strength, $skillDeviation);
        $penaltyKilling = self::skillAround($strength, $skillDeviation);

        $realTraits = array();
        $reportedTraits = array();
        if (class_exists('PlayerTraitsDataService')) {
            $realTraits = PlayerTraitsDataService::generateTraitsForCandidate($position, $positionMain, $age, $talent, array(
                'technique' => $technique,
                'passing' => $passing,
                'shooting' => $shooting,
                'heading' => $heading,
                'tackling' => $tackling,
                'freekick' => $freekick,
                'pace' => $pace,
                'creativity' => $creativity,
                'influence' => $influence,
                'flair' => $flair,
                'penalty' => $penalty,
                'penalty_killing' => $penaltyKilling
            ), $effectiveQuality);
            $reportedTraits = PlayerTraitsDataService::buildReportedTraits($realTraits, $effectiveQuality);
        }
        
        $transferFee = self::calculateProposalTransferFee($strength, $strengthMaxReal, $talent, $age);
        $transferFee = (int) round($transferFee * self::getTraitFeeMultiplier($realTraits) / 10000) * 10000;
        if ((int) $camp['budget_min'] > 0) {
            $transferFee = max($transferFee, (int) $camp['budget_min']);
        }
        if ((int) $camp['budget_max'] > 0) {
            $transferFee = min($transferFee, (int) $camp['budget_max']);
        }
        
        $salary = self::calculateProposalSalary($strength, $talent, $age);
        $contractMatches = 60;
        $expireMatches = self::configInt($websoccer, 'scouting_proposal_expire_matches', 20);
        
        $reported = self::buildReport($strength, $strengthMaxReal, $talent, $effectiveQuality);
        
        $insertColumns = array(
            'team_id' => (int) $camp['team_id'],
            'camp_id' => (int) $camp['id'],
            'scout_id' => (int) $camp['scout_id'],
            'location_id' => (int) $camp['location_id'],
            'firstname' => $firstname,
            'lastname' => $lastname,
            'birthday' => $birthday,
            'age' => $age,
            'nation' => $country,
            'position' => $position,
            'position_main' => $positionMain,
            'position_second' => '',
            'real_w_staerke' => number_format($strength, 2, '.', ''),
            'real_w_staerke_max' => number_format($strengthMaxReal, 2, '.', ''),
            'real_w_talent' => $talent,
            'real_w_technik' => number_format($technique, 2, '.', ''),
            'real_w_kondition' => number_format(mt_rand(45, 95), 2, '.', ''),
            'real_w_frische' => number_format(mt_rand(55, 95), 2, '.', ''),
            'real_w_zufriedenheit' => number_format(mt_rand(45, 95), 2, '.', ''),
            'real_w_passing' => number_format($passing, 2, '.', ''),
            'real_w_shooting' => number_format($shooting, 2, '.', ''),
            'real_w_heading' => number_format($heading, 2, '.', ''),
            'real_w_tackling' => number_format($tackling, 2, '.', ''),
            'real_w_freekick' => number_format($freekick, 2, '.', ''),
            'real_w_pace' => number_format($pace, 2, '.', ''),
            'real_w_creativity' => number_format($creativity, 2, '.', ''),
            'real_w_influence' => number_format($influence, 2, '.', ''),
            'real_w_flair' => number_format($flair, 2, '.', ''),
            'real_w_penalty' => number_format($penalty, 2, '.', ''),
            'real_w_penalty_killing' => number_format($penaltyKilling, 2, '.', ''),
            'reported_strength' => $reported['strength'],
            'reported_talent' => $reported['talent'],
            'reported_potential' => $reported['potential'],
            'reported_summary' => $reported['summary'],
            'transfer_fee' => $transferFee,
            'salary' => $salary,
            'contract_matches' => $contractMatches,
            'created_date' => self::now($websoccer),
            'expires_date' => self::now($websoccer) + ($expireMatches * 86400),
            'expires_after_matches' => $expireMatches,
            'status' => 'open'
        );

        if (class_exists('PlayerTraitsDataService') && self::hasProposalTraitColumns($websoccer, $db)) {
            $insertColumns['real_traits'] = PlayerTraitsDataService::encodeTraitMap($realTraits);
            $insertColumns['reported_traits'] = PlayerTraitsDataService::encodeTraitMap($reportedTraits);
        }

        $db->queryInsert($insertColumns, self::tablePrefix($websoccer) . 'scouting_proposal');
    }
    
    /* ---------------------------------------------------------------------
     * Old talent evaluation compatibility
     * ------------------------------------------------------------------ */
    
    public static function getTalentEvaluation(WebSoccer $websoccer, DbConnection $db, $teamId, $playerId) {
        $player = PlayersDataService::getPlayerById($websoccer, $db, $playerId);
        if (!$player || !isset($player['player_strength_talent'])) {
            return null;
        }
        
        $scout = self::getScoutByTeamSpeciality($websoccer, $db, $teamId, $player['player_position_de']);
        if (!$scout || !isset($scout['team_id']) || (int) $scout['team_id'] <= 0) {
            return null;
        }
        
        $accuracy = (int) $scout['expertise'];
        if ($accuracy >= mt_rand(20, 100)) {
            return (int) $player['player_strength_talent'];
        }
        
        return mt_rand(1, 6);
    }
    
    /* ---------------------------------------------------------------------
     * Helper methods for generation
     * ------------------------------------------------------------------ */
    
    private static function normalizePosition($position) {
        $position = trim((string) $position);
        $allowed = array('Torwart', 'Abwehr', 'Mittelfeld', 'Sturm');
        return (in_array($position, $allowed)) ? $position : null;
    }
    
    private static function getTeamCountry(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $prefix = self::tablePrefix($websoccer);
        $fromTable = $prefix . 'verein AS T LEFT JOIN ' . $prefix . 'liga AS L ON L.id = T.liga_id';
        $row = self::getSingleRow($db, 'L.land AS country', $fromTable, 'T.id = %d', (int) $teamId);
        return (isset($row['country'])) ? (string) $row['country'] : '';
    }
    
    private static function chooseCountryForLocation(WebSoccer $websoccer, DbConnection $db, $location, $teamId) {
        if (isset($location['country']) && strlen(trim((string) $location['country']))) {
            return trim((string) $location['country']);
        }
        
        if (isset($location['continent']) && strlen(trim((string) $location['continent']))) {
            $rows = self::getRows($db, 'country', self::tablePrefix($websoccer) . 'country', 'continent = \'%s\' ORDER BY RAND()', trim((string) $location['continent']), 1);
            if (count($rows) && strlen($rows[0]['country'])) {
                return $rows[0]['country'];
            }
        }
        
        $teamCountry = self::getTeamCountry($websoccer, $db, $teamId);
        return (strlen($teamCountry)) ? $teamCountry : 'Deutschland';
    }
    
    private static function getNamesCountry($country) {
        $country = trim((string) $country);
        $folder = BASE_FOLDER . '/admin/config/names/' . $country;
        if (strlen($country) && file_exists($folder . '/firstnames.txt') && file_exists($folder . '/lastnames.txt')) {
            return $country;
        }
        
        return 'Deutschland';
    }
    
    private static function getRandomNameItem($country, $file) {
        $path = BASE_FOLDER . '/admin/config/names/' . $country . '/' . $file;
        $items = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!$items || !count($items)) {
            return ($file === 'firstnames.txt') ? 'Max' : 'Mustermann';
        }
        return $items[mt_rand(0, count($items) - 1)];
    }
    
    private static function weightedPosition($speciality) {
        $speciality = self::normalizePosition($speciality);
        $weights = array(
            'Torwart' => array('Torwart' => 55, 'Abwehr' => 20, 'Mittelfeld' => 15, 'Sturm' => 10),
            'Abwehr' => array('Torwart' => 10, 'Abwehr' => 55, 'Mittelfeld' => 25, 'Sturm' => 10),
            'Mittelfeld' => array('Torwart' => 8, 'Abwehr' => 22, 'Mittelfeld' => 55, 'Sturm' => 15),
            'Sturm' => array('Torwart' => 5, 'Abwehr' => 15, 'Mittelfeld' => 25, 'Sturm' => 55)
        );
        
        $set = ($speciality && isset($weights[$speciality])) ? $weights[$speciality] : array('Torwart' => 15, 'Abwehr' => 30, 'Mittelfeld' => 35, 'Sturm' => 20);
        $rand = mt_rand(1, array_sum($set));
        $sum = 0;
        foreach ($set as $position => $weight) {
            $sum += $weight;
            if ($rand <= $sum) {
                return $position;
            }
        }
        return 'Mittelfeld';
    }
    
    private static function randomMainPosition($position) {
        $map = array(
            'Torwart' => array('T'),
            'Abwehr' => array('LV', 'IV', 'RV'),
            'Mittelfeld' => array('LM', 'DM', 'ZM', 'OM', 'RM'),
            'Sturm' => array('LS', 'MS', 'RS')
        );
        
        $options = (isset($map[$position])) ? $map[$position] : $map['Mittelfeld'];
        return $options[mt_rand(0, count($options) - 1)];
    }
    
    private static function generateTalent($expertise, $talentModifier, $age) {
        $chance = mt_rand(1, 100) + (int) round($expertise / 10) + (int) $talentModifier;
        if ($age <= 20) {
            $chance += 8;
        } elseif ($age >= 30) {
            $chance -= 8;
        }
        
        if ($chance >= 112) return 6;
        if ($chance >= 95) return 5;
        if ($chance >= 72) return 4;
        if ($chance >= 45) return 3;
        if ($chance >= 22) return 2;
        return 1;
    }
    
    private static function skillAround($strength, $deviation) {
        return self::clamp($strength + mt_rand(0 - (int) $deviation, (int) $deviation), 1, 99);
    }
    
    private static function calculateProposalTransferFee($strength, $potential, $talent, $age) {
        $fee = ($strength * $strength * 900);
        $fee += max(0, $potential - $strength) * 45000;
        $fee += $talent * 125000;
        
        if ($age <= 20) {
            $fee *= 1.45;
        } elseif ($age <= 23) {
            $fee *= 1.25;
        } elseif ($age >= 32) {
            $fee *= 0.65;
        } elseif ($age >= 29) {
            $fee *= 0.85;
        }
        
        $fee *= (mt_rand(85, 120) / 100);
        return max(10000, (int) round($fee / 10000) * 10000);
    }
    
    private static function calculateProposalSalary($strength, $talent, $age) {
        $salary = ($strength * 180) + ($talent * 250);
        if ($age <= 21) {
            $salary *= 0.85;
        } elseif ($age >= 30) {
            $salary *= 1.1;
        }
        return max(500, (int) round($salary / 100) * 100);
    }
    
    private static function getTraitFeeMultiplier($traits) {
        if (!is_array($traits) || !count($traits)) {
            return 1.0;
        }
        $bonus = 0.0;
        foreach ($traits as $value) {
            $bonus += max(0, min(3, (int) $value)) * 0.03;
        }
        return 1.0 + min(0.15, $bonus);
    }

    private static function hasProposalTraitColumns(WebSoccer $websoccer, DbConnection $db) {
        static $hasColumns = null;
        if ($hasColumns !== null) {
            return $hasColumns;
        }
        try {
            $tableName = $websoccer->getConfig('db_prefix') . '_scouting_proposal';
            $result = $db->executeQuery("SHOW COLUMNS FROM " . $tableName . " LIKE 'real_traits'");
            $hasReal = ($result && $result->num_rows > 0);
            if ($result) {
                $result->free();
            }
            $result = $db->executeQuery("SHOW COLUMNS FROM " . $tableName . " LIKE 'reported_traits'");
            $hasReported = ($result && $result->num_rows > 0);
            if ($result) {
                $result->free();
            }
            $hasColumns = ($hasReal && $hasReported);
        } catch (Exception $e) {
            $hasColumns = false;
        }
        return $hasColumns;
    }

    private static function buildReport($strength, $potential, $talent, $expertise) {
        $expertise = (int) $expertise;
        
        if ($expertise < 31) {
            $deviation = 12;
            $talentText = self::talentText(self::clamp($talent + mt_rand(-2, 2), 1, 6));
            $summary = 'scouting_proposal_report_uncertain';
        } elseif ($expertise < 71) {
            $deviation = 7;
            $talentText = self::talentText(self::clamp($talent + mt_rand(-1, 1), 1, 6));
            $summary = 'scouting_proposal_report_uncertain';
        } else {
            $deviation = 3;
            $talentText = self::talentText($talent);
            $summary = 'scouting_proposal_report_reliable';
        }
        
        $reportedStrengthMin = self::clamp($strength - mt_rand(1, $deviation), 1, 99);
        $reportedStrengthMax = self::clamp($strength + mt_rand(1, $deviation), 1, 99);
        $reportedPotentialMin = self::clamp($potential - mt_rand(1, $deviation), 1, 99);
        $reportedPotentialMax = self::clamp($potential + mt_rand(1, $deviation), 1, 99);
        
        return array(
            'strength' => $reportedStrengthMin . '-' . $reportedStrengthMax,
            'potential' => $reportedPotentialMin . '-' . $reportedPotentialMax,
            'talent' => $talentText,
            'summary' => $summary
        );
    }
    
    private static function talentText($talent) {
        $talent = (int) $talent;
        if ($talent <= 1) return 'scouting_low';
        if ($talent <= 3) return 'scouting_medium';
        if ($talent == 4) return 'scouting_high';
        if ($talent == 5) return 'scouting_very_high';
        return 'scouting_exceptional';
    }
}
?>
