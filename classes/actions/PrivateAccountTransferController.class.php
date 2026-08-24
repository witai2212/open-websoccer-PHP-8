<?php
// CM23 Task 1004 | 2026-08-24 | Revision 1
/******************************************************

  This file is part of OpenWebSoccer-Sim.

******************************************************/

/**
 * Transfers money between the selected club and the manager's private account.
 */
class PrivateAccountTransferController implements IActionController {
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

        $direction = isset($parameters['direction']) ? (string) $parameters['direction'] : '';
        $amount = isset($parameters['amount']) ? (int) $parameters['amount'] : 0;

        try {
            PrivateAccountDataService::transfer(
                $this->_websoccer,
                $this->_db,
                (int) $teamId,
                (int) $user->id,
                $direction,
                $amount
            );
        } catch (Exception $e) {
            throw new Exception($this->_i18n->getMessage($e->getMessage()));
        }

        $messageKey = ($direction === 'club_to_private')
            ? 'private_account_success_withdraw'
            : 'private_account_success_deposit';

        $this->_websoccer->addFrontMessage(new FrontMessage(
            MESSAGE_TYPE_SUCCESS,
            $this->_i18n->getMessage('saved_message_title'),
            $this->_i18n->getMessage($messageKey)
        ));

        return 'finance';
    }
}
?>