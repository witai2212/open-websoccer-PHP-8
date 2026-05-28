<?php
/******************************************************

  This file is part of OpenWebSoccer-Sim.

******************************************************/

/**
 * Saves the automatic normal training plan of the current human club.
 */
class SaveTrainingPlanController implements IActionController {
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
            throw new Exception($this->_i18n->getMessage("feature_requires_team"));
        }

        $slots = array();
        for ($slotNo = 1; $slotNo <= TrainingDataService::DEFAULT_PLAN_SLOTS; $slotNo++) {
            $typeKey = 'type_' . $slotNo;
            $intensityKey = 'intensity_' . $slotNo;
            $slots[$slotNo] = array(
                'training_type' => isset($parameters[$typeKey]) ? $parameters[$typeKey] : 'technique',
                'intensity' => isset($parameters[$intensityKey]) ? $parameters[$intensityKey] : 50
            );
        }

        TrainingDataService::saveTrainingPlan($this->_websoccer, $this->_db, $teamId, $slots);

        $this->_websoccer->addFrontMessage(new FrontMessage(
            MESSAGE_TYPE_SUCCESS,
            $this->_i18n->getMessage("training_plan_saved"),
            ""
        ));

        if (isset($parameters["returnpage"]) && $parameters["returnpage"] === "training-calendar") {
            return "training-calendar";
        }

        return "training";
    }
}
?>
