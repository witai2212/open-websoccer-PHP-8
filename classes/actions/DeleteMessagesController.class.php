<?php
/******************************************************

  This file is part of OpenWebSoccer-Sim.

******************************************************/

/**
 * Permanently deletes multiple selected inbox or outbox messages. Ownership is
 * checked again in the database, so users cannot delete messages of others by
 * manipulating the submitted ID list.
 */
class DeleteMessagesController implements IActionController {
    private $_i18n;
    private $_websoccer;
    private $_db;

    public function __construct(I18n $i18n, WebSoccer $websoccer, DbConnection $db) {
        $this->_i18n = $i18n;
        $this->_websoccer = $websoccer;
        $this->_db = $db;
    }

    public function executeAction($parameters) {
        $folder = ($parameters['folder'] === 'outbox') ? 'outbox' : 'inbox';
        $ids = $this->parseIds($parameters['ids']);

        if (!count($ids)) {
            throw new Exception($this->_i18n->getMessage('messages_delete_multiple_empty'));
        }

        $deleted = MessagesDataService::deleteMessages(
            $this->_websoccer,
            $this->_db,
            $ids,
            $folder
        );

        if ($deleted < 1) {
            throw new Exception($this->_i18n->getMessage('messages_delete_invalidid'));
        }

        $this->_websoccer->addFrontMessage(new FrontMessage(
            MESSAGE_TYPE_SUCCESS,
            $this->_i18n->getMessage('messages_delete_multiple_success', $deleted),
            ''
        ));

        return null;
    }

    private function parseIds($idsValue) {
        $ids = array();
        foreach (explode(',', (string) $idsValue) as $id) {
            $id = (int) trim($id);
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        // A page normally contains far fewer rows; this limit also prevents an
        // unnecessarily large manipulated SQL statement.
        return array_slice(array_values($ids), 0, 200);
    }
}

?>
