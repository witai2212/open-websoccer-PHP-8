<?php
/******************************************************

  This file is part of OpenWebSoccer-Sim.

******************************************************/

class WithdrawManagerApplicationController implements IActionController {
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
        $applicationId = (int) $parameters['applicationId'];

        ManagerCareerImprovementService::withdrawApplication($this->_websoccer, $this->_db, $this->_i18n, $user->id, $applicationId);

        $this->_websoccer->addFrontMessage(new FrontMessage(MESSAGE_TYPE_SUCCESS,
            $this->_i18n->getMessage('managercareer_application_msg_withdrawn'), ''));
        return 'managercareer';
    }
}
?>
