<?php
/******************************************************

  This file is part of OpenWebSoccer-Sim.

  OpenWebSoccer-Sim is free software: you can redistribute it
  and/or modify it under the terms of the
  GNU Lesser General Public License as published by the Free Software Foundation,
  either version 3 of the License, or any later version.

******************************************************/

/**
 * Releases a scout from the manager's club.
 */
class FireScoutController implements IActionController {
    private $_i18n;
    private $_websoccer;
    private $_db;

    public function __construct(I18n $i18n, WebSoccer $websoccer, DbConnection $db) {
        $this->_i18n = $i18n;
        $this->_websoccer = $websoccer;
        $this->_db = $db;
    }

    public function executeAction($parameters) {
        if (!$this->_websoccer->getConfig('scouting_enabled')) {
            return NULL;
        }

        $teamId = $this->getUserTeamId();
        $scoutId = isset($parameters['id']) ? (int) $parameters['id'] : 0;
        if ($scoutId < 1) {
            throw new Exception('Illegal ID');
        }

        try {
            ScoutingDataService::fireScout($this->_websoccer, $this->_db, $scoutId, $teamId);
        } catch (Exception $e) {
            $this->throwTranslatedException($e);
        }

        $this->_websoccer->addFrontMessage(new FrontMessage(
            MESSAGE_TYPE_SUCCESS,
            $this->_i18n->getMessage('fire_scout_message'),
            ''
        ));

        return 'scouting';
    }

    private function getUserTeamId() {
        $user = $this->_websoccer->getUser();
        $teamId = $user->getClubId($this->_websoccer, $this->_db);
        if ($teamId < 1) {
            throw new Exception($this->_i18n->getMessage('feature_requires_team'));
        }
        return (int) $teamId;
    }

    private function throwTranslatedException(Exception $e) {
        $messageKey = $e->getMessage();
        throw new Exception($this->_i18n->getMessage($messageKey));
    }
}

?>
