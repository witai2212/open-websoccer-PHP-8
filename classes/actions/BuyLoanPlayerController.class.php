<?php

/**
 * Executes an agreed buy option or buy obligation for a borrowed player.
 */
class BuyLoanPlayerController implements IActionController {
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
		if (!isset($loan['id']) || (int) $loan['borrower_team_id'] !== (int) $clubId) {
			throw new Exception($this->_i18n->getMessage('lending_buy_err_not_allowed'));
		}
		if ($loan['option_type'] != LoanDataService::OPTION_BUY && $loan['option_type'] != LoanDataService::OPTION_OBLIGATION) {
			throw new Exception($this->_i18n->getMessage('lending_buy_err_no_option'));
		}

		try {
			LoanDataService::buyLoanPlayer($this->_websoccer, $this->_db, $loan);
		} catch (Exception $e) {
			throw new Exception($this->_i18n->getMessage('lending_buy_err_budget'));
		}

		$this->_websoccer->addFrontMessage(new FrontMessage(MESSAGE_TYPE_SUCCESS, $this->_i18n->getMessage('lending_buy_success'), ''));
		return 'loans';
	}
}

?>
