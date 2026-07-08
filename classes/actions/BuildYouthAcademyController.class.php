<?php
/******************************************************

  This file is part of OpenWebSoccer-Sim.

******************************************************/

class BuildYouthAcademyController implements IActionController {
    private $_i18n;
    private $_websoccer;
    private $_db;

    public function __construct(I18n $i18n, WebSoccer $websoccer, DbConnection $db) {
        $this->_i18n = $i18n;
        $this->_websoccer = $websoccer;
        $this->_db = $db;
    }

    public function executeAction($parameters) {
        $teamId = $this->getUserTeamId();
        try {
            YouthAcademyDataService::buildAcademy($this->_websoccer, $this->_db, $teamId);
        } catch (Exception $e) {
            $this->throwTranslatedException($e);
        }

        $this->_websoccer->addFrontMessage(new FrontMessage(MESSAGE_TYPE_SUCCESS, $this->_i18n->getMessage('youthacademy_build_success'), ''));
        return 'youth-academy';
    }

    private function getUserTeamId() {
        $teamId = $this->_websoccer->getUser()->getClubId($this->_websoccer, $this->_db);
        if ($teamId < 1) {
            throw new Exception($this->_i18n->getMessage('feature_requires_team'));
        }
        return (int) $teamId;
    }

    private function throwTranslatedException(Exception $e) {
        throw new Exception($this->_i18n->getMessage($e->getMessage()));
    }
}
?>
