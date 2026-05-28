<?php
/******************************************************

  This file is part of OpenWebSoccer-Sim.

******************************************************/

/**
 * Data for individual training page.
 */
class IndividualTrainingModel implements IModel {
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
                'human_team' => false,
                'players' => array(),
                'attributeLabels' => IndividualTrainingDataService::getAttributeLabels(),
                'trainingUnits' => 0,
                'currentTrainer' => array(),
                'completedTrainings' => array()
            );
        }

        return IndividualTrainingDataService::getPageData($this->_websoccer, $this->_db, $this->_i18n, $teamId);
    }
}
?>
