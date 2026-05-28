<?php
/******************************************************

  This file is part of OpenWebSoccer-Sim.

  OpenWebSoccer-Sim is free software: you can redistribute it 
  and/or modify it under the terms of the 
  GNU Lesser General Public License 
  as published by the Free Software Foundation, either version 3 of
  the License, or any later version.

******************************************************/

/**
 * Executes one normal training unit manually.
 *
 * The main flow is now automatic via saved training plan and TrainingMatchdayJob.
 * This controller is kept for backwards compatibility and admin/manual testing.
 */
class ExecuteTrainingController implements IActionController {
    private $_i18n;
    private $_websoccer;
    private $_db;

    public function __construct(I18n $i18n, WebSoccer $websoccer, DbConnection $db) {
        $this->_i18n = $i18n;
        $this->_websoccer = $websoccer;
        $this->_db = $db;
    }

    /**
     * @see IActionController::executeAction()
     */
    public function executeAction($parameters) {
        $user = $this->_websoccer->getUser();
        $teamId = $user->getClubId($this->_websoccer, $this->_db);
        if ($teamId < 1) {
            return null;
        }

        $unit = TrainingDataService::getTrainingUnitById($this->_websoccer, $this->_db, $teamId, $parameters["id"]);
        if (!isset($unit["id"])) {
            throw new Exception("invalid ID");
        }

        if ($unit["date_executed"]) {
            throw new Exception($this->_i18n->getMessage("training_execute_err_already_executed"));
        }

        $previousExecution = TrainingDataService::getLatestTrainingExecutionTime($this->_websoccer, $this->_db, $teamId);
        $earliestValidExecution = $previousExecution + 3600 * $this->_websoccer->getConfig("training_min_hours_between_execution");
        $now = $this->_websoccer->getNowAsTimestamp();
        if ($now < $earliestValidExecution) {
            throw new Exception($this->_i18n->getMessage("training_execute_err_too_early", $this->_websoccer->getFormattedDatetime($earliestValidExecution)));
        }

        $campBookings = TrainingcampsDataService::getCampBookingsByTeam($this->_websoccer, $this->_db, $teamId);
        foreach ($campBookings as $booking) {
            if ($booking["date_start"] <= $now && $booking["date_end"] >= $now) {
                throw new Exception($this->_i18n->getMessage("training_execute_err_team_in_training_camp"));
            }
        }

        $liveMatch = MatchesDataService::getLiveMatchByTeam($this->_websoccer, $this->_db, $teamId);
        if (isset($liveMatch["match_id"])) {
            throw new Exception($this->_i18n->getMessage("training_execute_err_match_simulating"));
        }

        $trainer = TrainingDataService::getTrainerById($this->_websoccer, $this->_db, $unit["trainer_id"]);
        $trainingType = isset($parameters["focus"]) ? TrainingDataService::normalizeTrainingType($parameters["focus"]) : 'technique';
        $intensity = isset($parameters["intensity"]) ? (int) $parameters["intensity"] : 50;

        $result = TrainingDataService::executeTrainingUnit($this->_websoccer, $this->_db, $this->_i18n, $teamId, $unit, $trainer, $trainingType, $intensity);
        $this->_websoccer->addContextParameter("trainingEffects", $result['effects']);

        $this->_websoccer->addFrontMessage(new FrontMessage(
            MESSAGE_TYPE_SUCCESS,
            $this->_i18n->getMessage("training_execute_success"),
            ""
        ));

        return null;
    }
}
?>
