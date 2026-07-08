<?php
/******************************************************

  This file is part of OpenWebSoccer-Sim.

******************************************************/

class DeclineManagerJobController implements IActionController {
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

        ManagerCareerDataService::declineJobOffer($this->_websoccer, $this->_db, $this->_i18n, $user->id, $offerId);

        $this->_websoccer->addFrontMessage(new FrontMessage(MESSAGE_TYPE_SUCCESS,
            $this->_i18n->getMessage('managercareer_msg_decline_success'), ''));
        return 'managercareer';
    }
}
?>
