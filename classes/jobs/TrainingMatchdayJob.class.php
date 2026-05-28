<?php
/******************************************************

  This file is part of OpenWebSoccer-Sim.

******************************************************/

/**
 * Executes one saved normal-training plan slot for each human club after its next completed match.
 */
class TrainingMatchdayJob extends AbstractJob {

    /**
     * @see AbstractJob::execute()
     */
    public function execute() {
        if (!TrainingDataService::isAdvancedTrainingEnabled($this->_websoccer)) {
            echo "[TrainingMatchdayJob] advanced training is disabled.\n";
            return;
        }

        $result = TrainingDataService::processAutomaticTrainingMatchday($this->_websoccer, $this->_db, $this->_i18n);

        echo "[TrainingMatchdayJob] processed: " . (int) $result['processed'] . ".\n";
        echo "[TrainingMatchdayJob] skipped without new match: " . (int) $result['skipped_no_match'] . ".\n";
        echo "[TrainingMatchdayJob] skipped without units: " . (int) $result['skipped_no_units'] . ".\n";
        echo "[TrainingMatchdayJob] skipped in training camp: " . (int) $result['skipped_camp'] . ".\n";
    }
}
?>
