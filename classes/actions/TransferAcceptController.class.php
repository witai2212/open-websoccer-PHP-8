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
		
		//new team data
		$newTeam = TeamsDataService::getTeamById($this->_websoccer, $this->_db, $offer['verein_id']);

		if (!$offer) {
			throw new Exception($this->_i18n->getMessage("transferoffers_offer_cancellation_notfound"));
		}
		
		// get player name for notification
		$player = PlayersDataService::getPlayerById($this->_websoccer, $this->_db, $offer["spieler_id"]);
		if ($player["player_pseudonym"]) {
			$playerName = $player["player_pseudonym"];
		} else {
			$playerName = $player["player_firstname"] . " " . $player["player_lastname"];
		}
		
		if($hasUser>0 && $offer['user_id']>0) {
		    // create notification for selling user
    		  NotificationsDataService::createNotification($this->_websoccer, $this->_db, $oldTeam['team_user_id'], 
    		          "player_transfer_message",
    			     array("playername" => $playerName, "receivername" => $this->_websoccer->getUser()->username), 
    		              "transferoffer", "myoffers");

    		// creat notification for buyer user
    		NotificationsDataService::createNotification($this->_websoccer, $this->_db, $offer["user_id"], 
    			  "player_transfer_message",
    			 array("playername" => $playerName, "receivername" => $this->_websoccer->getUser()->username), 
    				  "transferoffer", "myteam");
		}
		
		//debit amount from buyer (take money)
		BankAccountDataService::debitAmount($this->_websoccer, $this->_db, $offer['verein_id'], $offer['abloese'], "player_transfer_message", $oldTeam['team_name']);
		
		//credit amount to selle (give money)
		BankAccountDataService::creditAmount($this->_websoccer, $this->_db, $oldTeam['team_id'], $offer['abloese'], "player_transfer_message", $newTeam['team_name']);
		
		// move player to new team with offer contractual values
		$columns["transfermarkt"] = 0;
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
		$trStr = "INSERT INTO " . $this->_websoccer->getConfig("db_prefix") . "_transfer (spieler_id, seller_club_id, buyer_club_id, datum, bid_id, directtransfer_amount)
					VALUES ('".$offer['spieler_id']."','".$oldTeam['team_id']."', 
							'".$offer['verein_id']."', '".$this->_websoccer->getNowAsTimestamp()."', '".$offer['id']."',
							'".$offer['abloese']."')";
		$this->_db->executeQuery($trStr);
			
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