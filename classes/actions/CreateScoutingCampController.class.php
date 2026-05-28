<?php
/******************************************************

  This file is part of OpenWebSoccer-Sim.

  OpenWebSoccer-Sim is free software: you can redistribute it
  and/or modify it under the terms of the
  GNU Lesser General Public License as published by the Free Software Foundation,
  either version 3 of the License, or any later version.

******************************************************/

/**
 * Creates a permanent scouting camp for the manager's club.
 */
class CreateScoutingCampController implements IActionController {
    private $_i18n;
    private $_websoccer;
    private $_db;

    public function __construct(I18n $i18n, WebSoccer $websoccer, DbConnection $db) {
        $this->_i18n = $i18n;
        $this->_websoccer = $websoccer;
        $this->_db = $db;
    }

    public function executeAction($parameters) {
        if (!$this->_websoccer->getConfig('scouting_enabled')) {
            return NULL;
        }

        $teamId = $this->getUserTeamId();

        $locationId = isset($parameters['location_id']) ? (int) $parameters['location_id'] : 0;
        $scoutId = isset($parameters['scout_id']) ? (int) $parameters['scout_id'] : 0;
        if ($locationId < 1 || $scoutId < 1) {
            throw new Exception('Illegal ID');
        }

        $position = isset($parameters['position']) ? trim((string) $parameters['position']) : '';
        $ageMin = isset($parameters['age_min']) ? (int) $parameters['age_min'] : 16;
        $ageMax = isset($parameters['age_max']) ? (int) $parameters['age_max'] : 35;
        $strengthMin = isset($parameters['strength_min']) ? (int) $parameters['strength_min'] : 1;
        $strengthMax = isset($parameters['strength_max']) ? (int) $parameters['strength_max'] : 99;
        $budgetMin = isset($parameters['budget_min']) ? (int) $parameters['budget_min'] : 0;
        $budgetMax = isset($parameters['budget_max']) ? (int) $parameters['budget_max'] : 0;

        try {
            ScoutingDataService::createCamp(
                $this->_websoccer,
                $this->_db,
                $teamId,
                $locationId,
                $scoutId,
                $position,
                $ageMin,
                $ageMax,
                $strengthMin,
                $strengthMax,
                $budgetMin,
                $budgetMax
            );
        } catch (Exception $e) {
            $this->throwTranslatedException($e);
        }

        $this->_websoccer->addFrontMessage(new FrontMessage(
            MESSAGE_TYPE_SUCCESS,
            $this->_i18n->getMessage('create_scouting_camp_message'),
            ''
        ));

        return 'scouting';
    }

    private function getUserTeamId() {
        $user = $this->_websoccer->getUser();
        $teamId = $user->getClubId($this->_websoccer, $this->_db);
        if ($teamId < 1) {
            throw new Exception($this->_i18n->getMessage('feature_requires_team'));
        }
        return (int) $teamId;
    }

    private function throwTranslatedException(Exception $e) {
        $messageKey = $e->getMessage();
        throw new Exception($this->_i18n->getMessage($messageKey));
    }
}

?>
