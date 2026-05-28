<?php

/**
 * Recalls a loaned player if the minimum duration is reached and playing time is too low.
 */
class RecallLoanController implements IActionController {
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
		$loan = LoanDataService::getLoanById($this->_websoccer, $this->_db, $parameters['id']);
		if (!isset($loan['id']) || (int) $loan['lender_team_id'] !== (int) $clubId) {
			throw new Exception($this->_i18n->getMessage('lending_recall_err_not_allowed'));
		}

		if (!LoanDataService::canRecallLoan($this->_websoccer, $this->_db, $loan)) {
			throw new Exception($this->_i18n->getMessage('lending_recall_err_too_early'));
		}

		LoanDataService::recallLoan($this->_websoccer, $this->_db, $loan);
		$this->_websoccer->addFrontMessage(new FrontMessage(MESSAGE_TYPE_SUCCESS, $this->_i18n->getMessage('lending_recall_success'), ''));

		return 'loans';
	}
}

?>
