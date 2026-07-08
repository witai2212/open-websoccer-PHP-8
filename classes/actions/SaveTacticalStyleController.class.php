<?php
/******************************************************

  This file is part of OpenWebSoccer-Sim.

******************************************************/

if (!defined('MESSAGE_TYPE_INFO')) {
    define('MESSAGE_TYPE_INFO', 'info');
}
if (!defined('MESSAGE_TYPE_WARNING')) {
    define('MESSAGE_TYPE_WARNING', 'warning');
}
if (!defined('MESSAGE_TYPE_SUCCESS')) {
    define('MESSAGE_TYPE_SUCCESS', 'success');
}
if (!defined('MESSAGE_TYPE_ERROR')) {
    define('MESSAGE_TYPE_ERROR', 'error');
}

class SaveTacticalStyleController implements IActionController {
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
        $teamId = isset($parameters['teamid']) ? (int) $parameters['teamid'] : 0;
        $style = isset($parameters['style']) ? $parameters['style'] : '';

        if ($teamId < 1) {
            $teamId = (int) $user->getClubId($this->_websoccer, $this->_db);
        }
        if ($teamId < 1) {
            throw new Exception($this->_i18n->getMessage('feature_requires_team'));
        }

        $result = TacticalStyleDataService::saveHumanStyle($this->_websoccer, $this->_db, $this->_i18n, $teamId, $user->id, $style);
        $messageKey = isset($result['message_key']) ? $result['message_key'] : 'tacticaldna_saved_no_change';
        $message = $this->_i18n->hasMessage($messageKey) ? $this->_i18n->getMessage($messageKey) : $messageKey;

        if (isset($result['style'])) {
            $message = str_replace('{style}', $this->_i18n->getMessage('tacticalstyle_' . $result['style']), $message);
        }
        if (isset($result['fit'])) {
            $message = str_replace('{fit}', (int) $result['fit'], $message);
        }
        if (isset($result['chemistry_change'])) {
            $change = (int) $result['chemistry_change'];
            $message = str_replace('{chemistry}', ($change > 0 ? '+' : '') . $change, $message);
        }

        $type = (isset($result['changed']) && $result['changed']) ? MESSAGE_TYPE_SUCCESS : MESSAGE_TYPE_INFO;
        if (isset($result['chemistry_change']) && (int) $result['chemistry_change'] < 0) {
            $type = MESSAGE_TYPE_WARNING;
        }
        $this->_websoccer->addFrontMessage(new FrontMessage($type, $message, ''));

        return NULL;
    }
}
?>
