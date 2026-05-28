<?php
/******************************************************

  This file is part of OpenWebSoccer-Sim.

******************************************************/

/**
 * Gives a one-match risky medical clearance for a lightly injured player.
 */
class ClearPlayerRiskController implements IActionController {
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
            MedicalCenterDataService::createRiskClearance($this->_websoccer, $this->_db, $teamId, $user->id, (int) $parameters['id']);
        } catch (Exception $e) {
            throw new Exception($this->_i18n->getMessage($e->getMessage()));
        }

        $this->_websoccer->addFrontMessage(new FrontMessage('success', $this->_i18n->getMessage('medicalcenter_success_clearance'), ''));
        return 'medical-center';
    }
}
?>
