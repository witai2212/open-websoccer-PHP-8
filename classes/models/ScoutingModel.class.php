<?php
/******************************************************

  This file is part of OpenWebSoccer-Sim.

  OpenWebSoccer-Sim is free software: you can redistribute it
  and/or modify it under the terms of the
  GNU Lesser General Public License as published by the Free Software Foundation,
  either version 3 of the License, or any later version.

******************************************************/

/**
 * Provides data for the scouting department page.
 */
class ScoutingModel implements IModel {
    private $_db;
    private $_i18n;
    private $_websoccer;

    public function __construct($db, $i18n, $websoccer) {
        $this->_db = $db;
        $this->_i18n = $i18n;
        $this->_websoccer = $websoccer;
    }

    public function renderView() {
        return TRUE;
    }

    public function getTemplateParameters() {
        $user = $this->_websoccer->getUser();
        $teamId = $user->getClubId($this->_websoccer, $this->_db);

        if ($teamId < 1) {
            return array(
                'team_id' => 0,
                'team_budget' => 0,
                'department' => array(),
                'next_department_level' => array(),
                'team_scouts' => array(),
                'free_scouts' => array(),
                'camp_locations' => array(),
                'team_camps' => array(),
                'open_proposals' => array(),
                'can_hire' => 0,
                'active_camps_count' => 0,
                'max_camps' => 0,
                'position_options' => self::getPositionOptions()
            );
        }

        $department = ScoutingDataService::getDepartment($this->_websoccer, $this->_db, $teamId);
        $nextLevel = array();
        if ($department && isset($department['level'])) {
            $nextLevel = ScoutingDataService::getNextDepartmentLevel($this->_websoccer, $this->_db, (int) $department['level']);
        } else {
            $nextLevel = ScoutingDataService::getDepartmentLevel($this->_websoccer, $this->_db, 1);
        }

        $teamScouts = ScoutingDataService::getTeamScouts($this->_websoccer, $this->_db, $teamId);
        $freeScouts = ScoutingDataService::getAvailableScouts($this->_websoccer, $this->_db);
        $coveredSpecialities = array();
        foreach ($teamScouts as $teamScout) {
            if (isset($teamScout['speciality']) && strlen($teamScout['speciality'])) {
                $coveredSpecialities[$teamScout['speciality']] = TRUE;
            }
        }
        foreach ($freeScouts as $freeScoutIndex => $freeScout) {
            $speciality = (isset($freeScout['speciality'])) ? $freeScout['speciality'] : '';
            $freeScouts[$freeScoutIndex]['speciality_taken'] = (isset($coveredSpecialities[$speciality])) ? 1 : 0;
        }
        $campLocations = ScoutingDataService::getAvailableCampLocations($this->_websoccer, $this->_db, $teamId);
        $teamCamps = ScoutingDataService::getTeamCamps($this->_websoccer, $this->_db, $teamId);
        $openProposals = ScoutingDataService::getTeamProposals($this->_websoccer, $this->_db, $teamId, 'open');

        return array(
            'team_id' => $teamId,
            'team_budget' => ScoutingDataService::getTeamBudget($this->_websoccer, $this->_db, $teamId),
            'department' => $department,
            'next_department_level' => $nextLevel,
            'team_scouts' => $teamScouts,
            'free_scouts' => $freeScouts,
            'camp_locations' => $campLocations,
            'team_camps' => $teamCamps,
            'open_proposals' => $openProposals,
            'can_hire' => ScoutingDataService::checkHiredScoutsByTeam($this->_websoccer, $this->_db, $teamId),
            'active_camps_count' => ScoutingDataService::countActiveTeamCamps($this->_websoccer, $this->_db, $teamId),
            'max_camps' => ScoutingDataService::getMaxCampsForTeam($this->_websoccer, $this->_db, $teamId),
            'position_options' => self::getPositionOptions()
        );
    }

    private static function getPositionOptions() {
        return array(
            '' => 'scouting_camp_any_position',
            'Torwart' => 'option_Torwart',
            'Abwehr' => 'option_Abwehr',
            'Mittelfeld' => 'option_Mittelfeld',
            'Sturm' => 'option_Sturm'
        );
    }
}

?>
