<?php
/******************************************************

  This file is part of OpenWebSoccer-Sim.

******************************************************/

class AcceptManagerJobController implements IActionController {
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
        $offerId = (int) $parameters['offerId'];

        $result = ManagerCareerDataService::acceptJobOffer($this->_websoccer, $this->_db, $this->_i18n, $user->id, $offerId);
        $user->setClubId((int) $result['team_id']);

        $message = $this->_i18n->getMessage('managercareer_msg_accept_success');
        if ((int) $result['highscore_bonus'] > 0) {
            $message .= ' ' . str_replace('{bonus}', (int) $result['highscore_bonus'], $this->_i18n->getMessage('managercareer_msg_accept_bonus'));
        }

        $this->_websoccer->addFrontMessage(new FrontMessage(MESSAGE_TYPE_SUCCESS, $message, ''));
        return 'managercareer';
    }
}
?>
