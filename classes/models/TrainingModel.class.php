<?php
/******************************************************

  This file is part of OpenWebSoccer-Sim.

******************************************************/

/**
 * Data for training page.
 */
class TrainingModel implements IModel {
    private $_db;
    private $_i18n;
    private $_websoccer;

    public function __construct($db, $i18n, $websoccer) {
        $this->_db = $db;
        $this->_i18n = $i18n;
        $this->_websoccer = $websoccer;
    }

    /**
     * @see IModel::renderView()
     */
    public function renderView() {
        return TRUE;
    }

    /**
     * @see IModel::getTemplateParameters()
     */
    public function getTemplateParameters() {
        $teamId = $this->_websoccer->getUser()->getClubId($this->_websoccer, $this->_db);
        if ($teamId < 1) {
            return array();
        }

        TrainingDataService::ensureAdvancedTrainingSchema($this->_websoccer, $this->_db);
        TrainingDataService::processAutomaticTrainingForTeam($this->_websoccer, $this->_db, $this->_i18n, $teamId);

        $lastExecution = TrainingDataService::getLatestTrainingExecutionTime($this->_websoccer, $this->_db, $teamId);
        $unitsCount = TrainingDataService::countRemainingTrainingUnits($this->_websoccer, $this->_db, $teamId);
        $paginator = null;
        $trainers = null;
        $trainerSpecializations = TrainingDataService::getTrainerSpecializations();
        $trainerSpecializationCounts = TrainingDataService::countTrainersBySpecialization($this->_websoccer, $this->_db);
        $selectedTrainerSpecialization = $this->_websoccer->getRequestParameter('specialization');
        if (!isset($trainerSpecializations[$selectedTrainerSpecialization])
                || empty($trainerSpecializationCounts[$selectedTrainerSpecialization])) {
            $selectedTrainerSpecialization = null;
        }

        $trainingUnit = TrainingDataService::getValidTrainingUnit($this->_websoccer, $this->_db, $teamId);
        if (isset($trainingUnit["id"])) {
            $trainingUnit["trainer"] = TrainingDataService::getTrainerById($this->_websoccer, $this->_db, $trainingUnit["trainer_id"]);
        }
        $trainerStaff = TrainingDataService::getActiveTrainerStaff($this->_websoccer, $this->_db, $teamId);
        $maxTrainerStaff = max(1, (int) $this->_websoccer->getConfig('training_max_trainers_per_team'));
        if (count($trainerStaff) < $maxTrainerStaff) {
            $count = TrainingDataService::countTrainers($this->_websoccer, $this->_db, $selectedTrainerSpecialization);
            $eps = $this->_websoccer->getConfig("entries_per_page");
            $paginator = new Paginator($count, $eps, $this->_websoccer);
            if ($selectedTrainerSpecialization !== null) {
                $paginator->addParameter('specialization', $selectedTrainerSpecialization);
            }
            if ($count > 0) {
                $trainers = TrainingDataService::getTrainers(
                    $this->_websoccer,
                    $this->_db,
                    $paginator->getFirstIndex(),
                    $eps,
                    $selectedTrainerSpecialization
                );
                $trainers = TrainingDataService::decorateTrainersForTeam($this->_websoccer, $this->_db, $trainers, $teamId);
            }
        }

        $trainingEffects = array();
        $contextParameters = $this->_websoccer->getContextParameters();
        if (isset($contextParameters["trainingEffects"])) {
            $trainingEffects = $contextParameters["trainingEffects"];
        }

        $plan = TrainingDataService::getOrCreateTrainingPlan($this->_websoccer, $this->_db, $teamId);
        $planSlots = TrainingDataService::getTrainingPlanSlots($this->_websoccer, $this->_db, $teamId);
        $latestReport = TrainingDataService::getLatestTrainingReport($this->_websoccer, $this->_db, $teamId);
        $latestReportPlayers = isset($latestReport['id']) ? TrainingDataService::getTrainingReportPlayers($this->_websoccer, $this->_db, $latestReport['id'], 20) : array();
        $reports = TrainingDataService::getTrainingReports($this->_websoccer, $this->_db, $teamId, 10);

        return array(
            "unitsCount" => $unitsCount,
            "lastExecution" => $lastExecution,
            "training_unit" => $trainingUnit,
            "trainerStaff" => $trainerStaff,
            "maxTrainerStaff" => $maxTrainerStaff,
            "trainers" => $trainers,
            "paginator" => $paginator,
            "trainingEffects" => $trainingEffects,
            "trainingPlan" => $plan,
            "trainingPlanSlots" => $planSlots,
            "trainingSlotNumbers" => array(1, 2, 3, 4, 5),
            "trainingTypes" => TrainingDataService::getTrainingTypes(),
            "trainerSpecializations" => $trainerSpecializations,
            "trainerSpecializationCounts" => $trainerSpecializationCounts,
            "selectedTrainerSpecialization" => $selectedTrainerSpecialization,
            "latestTrainingReport" => $latestReport,
            "latestTrainingReportPlayers" => $latestReportPlayers,
            "trainingReports" => $reports,
            "advancedTrainingEnabled" => TrainingDataService::isAdvancedTrainingEnabled($this->_websoccer)
        );
    }
}
?>
