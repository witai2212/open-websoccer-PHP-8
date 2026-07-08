<?php
/******************************************************

  This file is part of OpenWebSoccer-Sim.

******************************************************/

class ApplyManagerJobController implements IActionController {
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
        $teamId = (int) $parameters['teamId'];

        $result = ManagerCareerImprovementService::applyToClub($this->_websoccer, $this->_db, $this->_i18n, $user->id, $teamId);

        $message = str_replace(
            array('{team}', '{days}', '{chance}'),
            array($result['team_name'], (int) $result['decision_days'], (int) $result['chance']),
            $this->_i18n->getMessage('managercareer_application_msg_success')
        );
        $this->_websoccer->addFrontMessage(new FrontMessage(MESSAGE_TYPE_SUCCESS, $message, ''));
        return 'managercareer';
    }
}
?>
