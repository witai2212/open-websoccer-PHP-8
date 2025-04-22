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
class TransferRejectController implements IActionController {
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
		
		
		$clubId = $this->_websoccer->getUser()->getClubId($this->_websoccer, $this->_db);
		
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
		
		// get player name for notification
		$player = PlayersDataService::getPlayerById($this->_websoccer, $this->_db, $offer["spieler_id"]);
		if ($player["player_pseudonym"]) {
			$playerName = $player["player_pseudonym"];
		} else {
			$playerName = $player["player_firstname"] . " " . $player["player_lastname"];
		}
		
		if($hasUser>0 && $offer['user_id']>0) {
		  // create notification
		  NotificationsDataService::createNotification($this->_websoccer, $this->_db, $offer["user_id"], 
		          "transferoffer_notification_rejected",
			     array("playername" => $playerName, "receivername" => $this->_websoccer->getUser()->username), 
		              "transferoffer", "transferoffers#sent");
		}
		
		//delete offer from db
		$fromTable = $this->_websoccer->getConfig("db_prefix") . "_transfer_angebot";
		$whereCondition = "id = %d";
		$this->_db->queryDelete($fromTable, $whereCondition, $offer['id']);
		
		// show success message
		$this->_websoccer->addFrontMessage(new FrontMessage(MESSAGE_TYPE_SUCCESS, $this->_i18n->getMessage("transferoffers_offer_reject_success"), ""));
		
		return "myoffers";
	}
	
}

?>