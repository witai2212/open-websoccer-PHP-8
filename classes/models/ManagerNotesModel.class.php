<?php

class ManagerNotesModel implements IModel {
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
        if (!$user || (int) $user->id < 1) {
            throw new Exception($this->_i18n->getMessage('error_access_denied'));
        }
        return array('manager_note' => ManagerNotesDataService::getNote($this->_websoccer, $this->_db, (int) $user->id));
    }
}

?>
