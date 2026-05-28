<?php
/******************************************************

  This file is part of OpenWebSoccer-Sim.

******************************************************/

class SwitchFreeClubController implements IActionController {
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

        if (!$this->_websoccer->getConfig('assign_team_automatically')) {
            throw new Exception($this->_i18n->getMessage('freeclubs_msg_error'));
        }

        if ($user->getClubId($this->_websoccer, $this->_db) < 1) {
            throw new Exception($this->_i18n->getMessage('freeclubs_msg_error'));
        }

        $teamId = (int) $parameters['teamId'];
        ManagerCareerDataService::assignFreeClub($this->_websoccer, $this->_db, $this->_i18n, $user->id, $teamId, ManagerCareerDataService::ORIGIN_FREE_CLUB);
        $user->setClubId($teamId);

        $this->_websoccer->addFrontMessage(new FrontMessage(MESSAGE_TYPE_SUCCESS,
            $this->_i18n->getMessage('freeclubs_msg_switch_success'), ''));

        return 'office';
    }
}
?>
