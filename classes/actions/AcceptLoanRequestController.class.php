<?php

/**
 * Accepts an incoming loan request and executes the loan.
 */
class AcceptLoanRequestController implements IActionController {
    private $_i18n;
    private $_websoccer;
    private $_db;

    public function __construct(I18n $i18n, WebSoccer $websoccer, DbConnection $db) {
        $this->_i18n = $i18n;
        $this->_websoccer = $websoccer;
        $this->_db = $db;
    }

    public function executeAction($parameters) {
        if (!$this->_websoccer->getConfig('lending_enabled')) {
            return NULL;
        }

        $clubId = $this->_websoccer->getUser()->getClubId($this->_websoccer, $this->_db);
        if (!$clubId) {
            throw new Exception($this->_i18n->getMessage('feature_requires_team'));
        }

        LoanRequestDataService::acceptRequest($this->_websoccer, $this->_db, $this->_i18n, $parameters['id'], $clubId);

        $this->_websoccer->addFrontMessage(new FrontMessage(MESSAGE_TYPE_SUCCESS, $this->_i18n->getMessage('lending_request_accept_success'), ''));

        return 'loans';
    }
}

?>
