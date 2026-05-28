<?php

/**
 * Provides player details plus loan offer limits for lending/borrowing forms.
 */
class LoanPlayerDetailsModel implements IModel {
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
		$playerId = (int) $this->_websoccer->getRequestParameter('id');
		if ($playerId < 1) {
			throw new Exception($this->_i18n->getMessage(MSG_KEY_ERROR_PAGENOTFOUND));
		}

		$player = PlayersDataService::getPlayerById($this->_websoccer, $this->_db, $playerId);
		if (!isset($player['player_id'])) {
			throw new Exception($this->_i18n->getMessage(MSG_KEY_ERROR_PAGENOTFOUND));
		}

		$offer = LoanDataService::getOfferByPlayerId($this->_websoccer, $this->_db, $playerId);
		if (!isset($offer['id'])) {
			$offer = array(
				'salary_share_percent' => 100,
				'option_type' => LoanDataService::OPTION_NONE,
				'buy_fee' => 0
			);
		}

		return array(
			'player' => $player,
			'loan_offer' => $offer,
			'loan_max_fee' => LoanDataService::getMaxLoanFee($player),
			'loan_min_buy_fee' => LoanDataService::getMinBuyFee($player),
			'loan_max_buy_fee' => LoanDataService::getMaxBuyFee($player)
		);
	}
}

?>
