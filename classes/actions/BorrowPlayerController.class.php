<?php
/******************************************************

  This file is part of OpenWebSoccer-Sim.

******************************************************/

/**
 * Hire lendable player.
 */
class BorrowPlayerController implements IActionController {
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
		if ($clubId == null) {
			throw new Exception($this->_i18n->getMessage('feature_requires_team'));
		}
		
		$player = PlayersDataService::getPlayerById($this->_websoccer, $this->_db, $parameters['id']);
		if ($clubId == $player['team_id']) {
			throw new Exception($this->_i18n->getMessage('lending_hire_err_ownplayer'));
		}
		
		if ($player['lending_owner_id'] > 0) {
			throw new Exception($this->_i18n->getMessage('lending_hire_err_borrowed_player'));
		}
		
		if ($player['lending_fee'] == 0) {
			throw new Exception($this->_i18n->getMessage('lending_hire_err_notoffered'));
		}
		
		if ($player['player_transfermarket'] > 0) {
			throw new Exception($this->_i18n->getMessage('lending_err_on_transfermarket'));
		}
		
		if ($parameters['matches'] < $this->_websoccer->getConfig('lending_matches_min')
				|| $parameters['matches'] > $this->_websoccer->getConfig('lending_matches_max')) {
			throw new Exception(sprintf($this->_i18n->getMessage('lending_hire_err_illegalduration'), 
					$this->_websoccer->getConfig('lending_matches_min'), $this->_websoccer->getConfig('lending_matches_max')));
		}
			
		if ($parameters['matches'] >= $player['player_contract_matches']) {
			throw new Exception($this->_i18n->getMessage('lending_hire_err_contractendingtoosoon', $player['player_contract_matches']));
		}

		$offer = LoanDataService::getOfferByPlayerId($this->_websoccer, $this->_db, $player['player_id']);
		$salaryShare = isset($offer['salary_share_percent']) ? LoanDataService::normalizeSalaryShare($offer['salary_share_percent']) : 100;
		$optionType = isset($offer['option_type']) ? LoanDataService::normalizeOptionType($offer['option_type']) : LoanDataService::OPTION_NONE;
		$buyFee = isset($offer['buy_fee']) ? (int) $offer['buy_fee'] : 0;
		
		$fee = $parameters['matches'] * $player['lending_fee'];
		$salaryPart = (int) round((int) $player['player_contract_salary'] * $salaryShare / 100);
		$minBudget = $fee + 5 * $salaryPart;
		if ($optionType == LoanDataService::OPTION_OBLIGATION) {
			$minBudget += $buyFee;
		}
		$team = TeamsDataService::getTeamSummaryById($this->_websoccer, $this->_db, $clubId);
		if ($team['team_budget'] < $minBudget) {
			throw new Exception($this->_i18n->getMessage('lending_hire_err_budget_too_low'));
		}
		
		BankAccountDataService::debitAmount($this->_websoccer, $this->_db, $clubId, $fee, 'lending_fee_subject', $player['team_name']);
		BankAccountDataService::creditAmount($this->_websoccer, $this->_db, $player['team_id'], $fee, 'lending_fee_subject', $team['team_name']);
		
		$this->updatePlayer($player['player_id'], $player['team_id'], $clubId, $parameters['matches']);
		LoanDataService::createLoan($this->_websoccer, $this->_db, $player['player_id'], $player['team_id'], $clubId, $parameters['matches'], $player['lending_fee'], $salaryShare, $optionType, $buyFee);
		LoanDataService::closeOffer($this->_websoccer, $this->_db, $player['player_id'], 'accepted');
		
		$playerName = (strlen($player['player_pseudonym'])) ? $player['player_pseudonym'] : $player['player_firstname'] . ' ' . $player['player_lastname'];
		if ($player['team_user_id']) {
			NotificationsDataService::createNotification($this->_websoccer, $this->_db, $player['team_user_id'], 'lending_notification_lent',
				array('player' => $playerName, 'matches' => $parameters['matches'], 'newteam' => $team['team_name']), 
				'lending_lent', 'loans', '');
		}
		
		$this->_websoccer->addFrontMessage(new FrontMessage(MESSAGE_TYPE_SUCCESS, 
				$this->_i18n->getMessage('lending_hire_success'),
				''));
		
		return 'loans';
	}
	
	private function updatePlayer($playerId, $ownerId, $clubId, $matches) {
		$columns = array('lending_matches' => (int) $matches, 'lending_owner_id' => (int) $ownerId, 'verein_id' => (int) $clubId);
		$this->_db->queryUpdate($columns, $this->_websoccer->getConfig('db_prefix') .'_spieler', 'id = %d', $playerId);
	}
}

?>
