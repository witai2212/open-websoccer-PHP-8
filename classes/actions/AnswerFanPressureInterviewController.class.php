<?php
/******************************************************

  This file is part of OpenWebSoccer-Sim.

******************************************************/

if (!defined('MESSAGE_TYPE_INFO')) {
    define('MESSAGE_TYPE_INFO', 'info');
}
if (!defined('MESSAGE_TYPE_SUCCESS')) {
    define('MESSAGE_TYPE_SUCCESS', 'success');
}
if (!defined('MESSAGE_TYPE_ERROR')) {
    define('MESSAGE_TYPE_ERROR', 'error');
}

/**
 * Handles a manager answer to a fan/media interview question.
 */
class AnswerFanPressureInterviewController implements IActionController {
    private $_i18n;
    private $_websoccer;
    private $_db;

    public function __construct(I18n $i18n, WebSoccer $websoccer, DbConnection $db) {
        $this->_i18n = $i18n;
        $this->_websoccer = $websoccer;
        $this->_db = $db;
    }

    public function executeAction($parameters) {
        if (!$this->_websoccer->getConfig('fanpressure_enabled')) {
            return NULL;
        }

        $user = $this->_websoccer->getUser();
        $teamId = (int) $user->getClubId($this->_websoccer, $this->_db);
        if ($teamId < 1) {
            throw new Exception($this->_i18n->getMessage('feature_requires_team'));
        }

        $occurrenceId = isset($parameters['occurrence_id']) ? (int) $parameters['occurrence_id'] : 0;
        $answerKey = isset($parameters['answer']) ? $parameters['answer'] : '';

        $result = FanPressureDataService::answerInterview(
            $this->_websoccer,
            $this->_db,
            $this->_i18n,
            (int) $user->id,
            $teamId,
            $occurrenceId,
            $answerKey
        );

        $message = isset($result['message']) ? $result['message'] : $this->_i18n->getMessage('fanpressure_interview_answer_saved');
        $this->_websoccer->addFrontMessage(new FrontMessage(MESSAGE_TYPE_SUCCESS, $message, ''));

        return 'fanpressure';
    }
}

?>
