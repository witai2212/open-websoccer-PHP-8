<?php

if (!defined('MESSAGE_TYPE_SUCCESS')) {
    define('MESSAGE_TYPE_SUCCESS', 'success');
}

class SaveManagerNoteController implements IActionController {
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
        if (!$user || (int) $user->id < 1) {
            throw new Exception($this->_i18n->getMessage('error_access_denied'));
        }

        $note = isset($parameters['note']) ? $parameters['note'] : '';
        ManagerNotesDataService::saveNote($this->_websoccer, $this->_db, (int) $user->id, $note);

        $this->_websoccer->addFrontMessage(new FrontMessage(
            MESSAGE_TYPE_SUCCESS,
            $this->_i18n->getMessage('manager_notes_saved'),
            ''
        ));

        return 'manager-notes';
    }
}

?>
