<?php
/******************************************************

  This file is part of OpenWebSoccer-Sim.

  OpenWebSoccer-Sim is free software: you can redistribute it
  and/or modify it under the terms of the
  GNU Lesser General Public License as published by the Free Software Foundation,
  either version 3 of the License, or any later version.

******************************************************/

/**
 * Provides active random event chains for the current manager/team.
 */
class RandomEventChainsModel implements IModel {
    private $_db;
    private $_i18n;
    private $_websoccer;

    public function __construct($db, $i18n, $websoccer) {
        $this->_db = $db;
        $this->_i18n = $i18n;
        $this->_websoccer = $websoccer;
    }

    public function renderView() {
        return TRUE;
    }

    public function getTemplateParameters() {
        $user = $this->_websoccer->getUser();
        $teamId = $user->getClubId($this->_websoccer, $this->_db);
        if ($teamId < 1) {
            throw new Exception($this->_i18n->getMessage('feature_requires_team'));
        }

        RandomEventsDataService::createEventIfRequired($this->_websoccer, $this->_db, $user->id);

        return array(
            'team_id' => $teamId,
            'events' => RandomEventsDataService::getOpenChainEvents($this->_websoccer, $this->_db, $this->_i18n, $user->id, $teamId)
        );
    }
}

?>
