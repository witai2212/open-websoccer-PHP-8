<?php
/******************************************************

  Stops a club partnership by one involved manager.

******************************************************/

class StopClubPartnershipController implements IActionController {
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
        ClubPartnershipDataService::stopByManager($this->_websoccer, $this->_db, $this->_i18n, (int) $user->id, (int) $parameters['id']);
        $this->_websoccer->addFrontMessage(new FrontMessage(MESSAGE_TYPE_SUCCESS, $this->_i18n->getMessage('clubpartnership_stopped'), ''));
        return 'clubpartnerships';
    }
}
?>
