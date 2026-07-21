<?php
/******************************************************

  This file is part of OpenWebSoccer-Sim.

  OpenWebSoccer-Sim is free software: you can redistribute it 
  and/or modify it under the terms of the 
  GNU Lesser General Public License 
  as published by the Free Software Foundation, either version 3 of
  the License, or any later version.

  OpenWebSoccer-Sim is distributed in the hope that it will be
  useful, but WITHOUT ANY WARRANTY; without even the implied
  warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. 
  See the GNU Lesser General Public License for more details.

  You should have received a copy of the GNU Lesser General Public 
  License along with OpenWebSoccer-Sim.  
  If not, see <http://www.gnu.org/licenses/>.

******************************************************/

/**
 * Cancels user's own direct transfer.
 * 
 * @author Ingo Hofmann
 */
class TransferAcceptController implements IActionController {
	private $_i18n;
	private $_websoccer;
	private $_db;
	
	public function __construct(I18n $i18n, WebSoccer $websoccer, DbConnection $db) {
		$this->_i18n = $i18n;
		$this->_websoccer = $websoccer;
		$this->_db = $db;
	}
	
	/**
	 * (non-PHPdoc)
	 * @see IActionController::executeAction()
	 */
	public function executeAction($parameters) {
	    
		// check if feature is enabled
		if (!$this->_websoccer->getConfig("transfermarket_enabled")) {
			return NULL;
		}
		
		
		//old team data
		$clubId = $this->_websoccer->getUser()->getClubId($this->_websoccer, $this->_db);
		$oldTeam = TeamsDataService::getTeamById($this->_websoccer, $this->_db, $clubId);
		
		// check if clib has a userID --> 0,1 value
		$hasUser = TeamsDataService::clubHasUser($this->_websoccer, $this->_db, $clubId);
		
		// get offer information
		$result = $this->_db->querySelect("*", $this->_websoccer->getConfig("db_prefix") . "_transfer_angebot", 
				"id = %d", array($parameters["id"]));
		$offer = $result->fetch_array();
		$result->free();
		if (!$offer) {
			throw new Exception($this->_i18n->getMessage("transferoffers_offer_cancellation_notfound"));
		}

		// The offer may only be accepted by the player's current club. This also
		// prevents stale duplicate offers from creating payments to the same club.
		$player = PlayersDataService::getPlayerById($this->_websoccer, $this->_db, $offer["spieler_id"]);
		if (!$player || (int) $player["team_id"] !== (int) $clubId || (int) $offer["verein_id"] === (int) $clubId) {
			throw new Exception($this->_i18n->getMessage("transferoffers_offer_cancellation_notfound"));
		}

		if (class_exists('ClubPartnershipDataService')) {
			ClubPartnershipDataService::assertProfessionalTransferAllowed($this->_websoccer, $this->_db, $this->_i18n, $offer['spieler_id'], $offer['verein_id']);
		}

		// new team data
		$newTeam = TeamsDataService::getTeamById($this->_websoccer, $this->_db, $offer['verein_id']);

		// transfermarket watchdog, with explicit seller club before the player is moved
		TransfermarketDataService::transferWatchdog($this->_websoccer, $this->_db, $offer['id'], $oldTeam['team_id']);

		// get player name for notification
		// This controller accepts an offer for a player owned by the selling club.
		// Ignore legacy hand-money values because this is not a free transfer.
		$offer['handgeld'] = 0;
		if ($player["player_pseudonym"]) {
			$playerName = $player["player_pseudonym"];
		} else {
			$playerName = $player["player_firstname"] . " " . $player["player_lastname"];
		}
		
		//debit amount from buyer (take money)
		BankAccountDataService::debitAmount($this->_websoccer, $this->_db, $offer['verein_id'], $offer['abloese'], "player_transfer_message", $oldTeam['team_name']);
		
		//credit amount to selle (give money)
		BankAccountDataService::creditAmount($this->_websoccer, $this->_db, $oldTeam['team_id'], $offer['abloese'], "player_transfer_message", $newTeam['team_name']);
		
		// move player to new team with offer contractual values
		$columns["transfermarkt"] = 0;
		$columns["transfer_start"] = 0;
		$columns["transfer_ende"] = 0;
		$columns["last_transfer"] = $this->_websoccer->getNowAsTimestamp();
		$columns["vertrag_gehalt"] = $offer['vertrag_gehalt'];
		$columns["vertrag_spiele"] = $offer['vertrag_spiele'];
		$columns["vertrag_torpraemie"] = $offer['vertrag_torpraemie'];
		$columns["verein_id"] = $offer['verein_id'];
		
		$fromTable = $this->_websoccer->getConfig("db_prefix") ."_spieler";
		$whereCondition = "id = %d";
		$parameters = $offer['spieler_id'];
		
		$this->_db->queryUpdate($columns, $fromTable, $whereCondition, $parameters);
		
		//save in _transfer table
		//id spieler_id seller_user_id seller_club_id buyer_user_id buyer_club_id datum bid_id directtransfer_amount directtransfer_player1 directtransfer_player2
		$transferColumns = array(
			"spieler_id" => (int) $offer['spieler_id'],
			"seller_user_id" => !empty($oldTeam['team_user_id']) ? (int) $oldTeam['team_user_id'] : 0,
			"seller_club_id" => (int) $oldTeam['team_id'],
			"buyer_user_id" => !empty($offer['user_id']) ? (int) $offer['user_id'] : 0,
			"buyer_club_id" => (int) $offer['verein_id'],
			"datum" => $this->_websoccer->getNowAsTimestamp(),
			"bid_id" => (int) $offer['id'],
			"directtransfer_amount" => (int) $offer['abloese']
		);
		$this->_db->queryInsert($transferColumns, $this->_websoccer->getConfig("db_prefix") . "_transfer");
		$transferId = (int) $this->_db->getLastInsertedId();

		TransferMessagesDataService::createTransferCompleted(
			$this->_websoccer,
			$this->_db,
			$offer['spieler_id'],
			$oldTeam['team_id'],
			$offer['verein_id'],
			$offer['abloese'],
			!empty($oldTeam['team_user_id']) ? $oldTeam['team_user_id'] : 0,
			!empty($offer['user_id']) ? $offer['user_id'] : 0,
			array(
				'contract_matches' => (int) $offer['vertrag_spiele'],
				'contract_salary' => (int) $offer['vertrag_gehalt'],
				'contract_goal_bonus' => (int) $offer['vertrag_torpraemie'],
				'hand_money' => isset($offer['handgeld']) ? (int) $offer['handgeld'] : 0
			)
		);

		if (class_exists('BadgeAwardService') && !empty($oldTeam['team_user_id'])) {
			BadgeAwardService::processTransferSale(
				$this->_websoccer,
				$this->_db,
				(int) $oldTeam['team_user_id'],
				(int) $oldTeam['team_id'],
				(int) $offer['spieler_id'],
				(int) $offer['abloese'],
				$transferId
			);
		}

		if (class_exists('ClubPartnershipDataService')) {
			ClubPartnershipDataService::markProfessionalFirstOptionUsed($this->_websoccer, $this->_db, $offer['spieler_id'], $offer['verein_id']);
		}
		
		if (class_exists('FanPressureDataService')) {
			FanPressureDataService::processTransfer(
				$this->_websoccer,
				$this->_db,
				$this->_i18n,
				$offer['spieler_id'],
				$oldTeam['team_id'],
				$offer['verein_id'],
				$offer['abloese']
			);
		}
			
		//delete offers from db
		$fromTable = $this->_websoccer->getConfig("db_prefix") . "_transfer_angebot";
		$whereCondition = "spieler_id = %d";
		$this->_db->queryDelete($fromTable, $whereCondition, $offer['spieler_id']);
		
		// show success message
		$this->_websoccer->addFrontMessage(new FrontMessage(MESSAGE_TYPE_SUCCESS, $this->_i18n->getMessage("transferoffers_offer_accept_success"), ""));
		
		return "myoffers";
	}
	
}

?>