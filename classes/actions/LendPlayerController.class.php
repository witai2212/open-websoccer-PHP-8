<?php
/******************************************************

  This file is part of OpenWebSoccer-Sim.

******************************************************/

/**
 * Marks player as ready for borrowing and stores loan terms.
 */
class LendPlayerController implements IActionController {
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
		
		$user = $this->_websoccer->getUser();
		$clubId = $user->getClubId($this->_websoccer, $this->_db);
		
		$player = PlayersDataService::getPlayerById($this->_websoccer, $this->_db, $parameters['id']);
		if ($clubId != $player['team_id']) {
			throw new Exception($this->_i18n->getMessage('lending_err_notownplayer'));
		}
		
		if ($player['lending_owner_id'] > 0) {
			throw new Exception($this->_i18n->getMessage('lending_err_borrowed_player'));
		}
		
		if ($player['lending_fee'] > 0) {
			throw new Exception($this->_i18n->getMessage('lending_err_alreadyoffered'));
		}
		
		if ($player['player_transfermarket'] > 0) {
			throw new Exception($this->_i18n->getMessage('lending_err_on_transfermarket'));
		}
		
		$teamSize = TeamsDataService::getTeamSize($this->_websoccer, $this->_db, $clubId);
		if ($teamSize <= $this->_websoccer->getConfig('transfermarket_min_teamsize')) {
			throw new Exception($this->_i18n->getMessage('lending_err_teamsize_too_small', $teamSize));
		}
		
		if ($player['player_contract_matches'] <= $this->_websoccer->getConfig('lending_matches_min')) {
			throw new Exception($this->_i18n->getMessage('lending_err_contract_too_short'));
		}

		$salaryShare = isset($parameters['salary_share_percent']) ? $parameters['salary_share_percent'] : 100;
		$optionType = isset($parameters['option_type']) ? $parameters['option_type'] : LoanDataService::OPTION_NONE;
		$buyFee = isset($parameters['buy_fee']) ? $parameters['buy_fee'] : 0;

		list($fee, $salaryShare, $optionType, $buyFee) = LoanDataService::validateOfferTerms(
			$this->_i18n,
			$player,
			$parameters['fee'],
			$salaryShare,
			$optionType,
			$buyFee
		);
		
		$this->updatePlayer($player['player_id'], $fee);
		LoanDataService::saveOffer($this->_websoccer, $this->_db, $player['player_id'], $clubId, $fee, $salaryShare, $optionType, $buyFee, false);
		
		$this->_websoccer->addFrontMessage(new FrontMessage(MESSAGE_TYPE_SUCCESS, 
				$this->_i18n->getMessage('lend_player_success'),
				''));
		
		return 'loans';
	}
	
	private function updatePlayer($playerId, $fee) {
		$this->_db->queryUpdate(array('lending_fee' => (int) $fee), $this->_websoccer->getConfig('db_prefix') .'_spieler', 'id = %d', $playerId);
	}
}

?>
