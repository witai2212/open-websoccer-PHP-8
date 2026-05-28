<?php
/******************************************************

  This file is part of OpenWebSoccer-Sim.

  OpenWebSoccer-Sim is free software: you can redistribute it
  and/or modify it under the terms of the
  GNU Lesser General Public License as published by the Free Software Foundation,
  either version 3 of the License, or any later version.

******************************************************/

/**
 * Resolves one active random event chain by applying the selected choice.
 */
class ResolveRandomEventChainController implements IActionController {
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
            throw new Exception($this->_i18n->getMessage('feature_requires_team'));
        }

        $occurrenceId = isset($parameters['id']) ? (int) $parameters['id'] : 0;
        $choiceId = isset($parameters['choice_id']) ? (int) $parameters['choice_id'] : 0;
        if ($occurrenceId < 1 || $choiceId < 1) {
            throw new Exception($this->_i18n->getMessage('error_page_not_found'));
        }

        try {
            RandomEventsDataService::applyChainChoice($this->_websoccer, $this->_db, $user->id, $teamId, $occurrenceId, $choiceId);
        } catch (Exception $e) {
            $message = $e->getMessage();
            if ($this->_i18n->hasMessage($message)) {
                $message = $this->_i18n->getMessage($message);
            }
            throw new Exception($message);
        }

        $this->_websoccer->addFrontMessage(new FrontMessage(
            MESSAGE_TYPE_SUCCESS,
            $this->_i18n->getMessage('randomevent_chain_resolved_success'),
            ''
        ));

        return 'random-events';
    }
}

?>
