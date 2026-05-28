<?php
/******************************************************

  This file is part of OpenWebSoccer-Sim.

******************************************************/

class TakeBankLoanController implements IActionController {
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

        $offerKey = isset($parameters['offer_key']) ? $parameters['offer_key'] : '';
        BankLoansDataService::takeLoan($this->_websoccer, $this->_db, $this->_i18n, (int) $teamId, (int) $user->id, $offerKey);

        $this->_websoccer->addFrontMessage(new FrontMessage(
            MESSAGE_TYPE_SUCCESS,
            $this->_i18n->getMessage('bankloans_success_taken'),
            ''
        ));

        return 'bank-loans';
    }
}
?>
