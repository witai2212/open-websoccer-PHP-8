<?php
/******************************************************

  This file is part of OpenWebSoccer-Sim.

******************************************************/

/**
 * Applies a medical treatment to one injured player.
 */
class ApplyMedicalTreatmentController implements IActionController {
    private $_i18n;
    private $_websoccer;
    private $_db;

    public function __construct(I18n $i18n, WebSoccer $websoccer, DbConnection $db) {
        $this->_i18n = $i18n;
        $this->_websoccer = $websoccer;
        $this->_db = $db;
    }

    public function executeAction($parameters) {
        $user = $this->_websoccer->getUser();
        $teamId = $user->getClubId($this->_websoccer, $this->_db);
        if ($teamId < 1) {
            throw new Exception($this->_i18n->getMessage('medicalcenter_error_no_team'));
        }

        try {
            $messageKey = MedicalCenterDataService::applyTreatment($this->_websoccer, $this->_db, $this->_i18n, $teamId, $user->id, (int) $parameters['id'], $parameters['treatment']);
        } catch (Exception $e) {
            throw new Exception($this->_i18n->getMessage($e->getMessage()));
        }

        $type = ($messageKey === 'medicalcenter_error_risky_cure_caught') ? 'warning' : 'success';
        $this->_websoccer->addFrontMessage(new FrontMessage($type, $this->_i18n->getMessage($messageKey), ''));

        return 'medical-center';
    }
}
?>
