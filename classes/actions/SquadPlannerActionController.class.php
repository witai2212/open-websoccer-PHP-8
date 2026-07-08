<?php
/******************************************************

  This file is part of OpenWebSoccer-Sim.

******************************************************/

/**
 * Executes safe squad-planner actions for the user's own club.
 */
class SquadPlannerActionController implements IActionController {
    private $_i18n;
    private $_websoccer;
    private $_db;

    public function __construct(I18n $i18n, WebSoccer $websoccer, DbConnection $db) {
        $this->_i18n = $i18n;
        $this->_websoccer = $websoccer;
        $this->_db = $db;
    }

    public function executeAction($parameters) {
        if (!$this->_websoccer->getConfig('squadplanner_enabled')) {
            return NULL;
        }

        $user = $this->_websoccer->getUser();
        $teamId = $user->getClubId($this->_websoccer, $this->_db);
        if ($teamId < 1) {
            throw new Exception($this->_i18n->getMessage('feature_requires_team'));
        }

        $mode = isset($parameters['mode']) ? $parameters['mode'] : '';
        $id = isset($parameters['id']) ? (int) $parameters['id'] : 0;

        $result = SquadPlannerDataService::applyAction($this->_websoccer, $this->_db, $this->_i18n, (int) $teamId, $mode, $id, (int) $user->id);

        $message = '';
        if (isset($result['messages']) && count($result['messages'])) {
            $message = implode(' ', $result['messages']);
        }

        $type = ((isset($result['sell']) && $result['sell']) || (isset($result['loan']) && $result['loan']) || (isset($result['youth']) && $result['youth'])) ? MESSAGE_TYPE_SUCCESS : MESSAGE_TYPE_INFO;
        $this->_websoccer->addFrontMessage(new FrontMessage($type, $message, ''));

        return 'squadplanner';
    }
}

?>
