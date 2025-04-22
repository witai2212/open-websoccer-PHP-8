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
class WithdrawBidController implements IActionController {
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
		
		$playerId = $parameters['id'];
		$clubId = $this->_websoccer->getUser()->getClubId($this->_websoccer, $this->_db);
		
		// get offer information
		$result = $this->_db->querySelect("*", $this->_websoccer->getConfig("db_prefix") . "_transfer_angebot", 
				"spieler_id = %d AND verein_id = %d", array($parameters["id"], $clubId));
		$offer = $result->fetch_array();
		$result->free();		
		
		if (!$offer) {
			throw new Exception($this->_i18n->getMessage("transferoffers_offer_cancellation_notfound"));
		}
		
		// get player name for notification
		$player = PlayersDataService::getPlayerById($this->_websoccer, $this->_db, $playerId);
		if ($player["player_pseudonym"]) {
			$playerName = $player["player_pseudonym"];
		} else {
			$playerName = $player["player_firstname"] . " " . $player["player_lastname"];
		}
		
		// check if clib has a userID --> 0,1 value
		$hasUser = TeamsDataService::clubHasUser($this->_websoccer, $this->_db, $player['team_id']);
		
		if($hasUser>0 && $offer['user_id']>0) {
		  // create notification
		  NotificationsDataService::createNotification($this->_websoccer, $this->_db, $offer["user_id"], 
		          "transferoffer_notification_withdrawn",
			     array("playername" => $playerName, "receivername" => $this->_websoccer->getUser()->username), 
		              "transferoffer", "transferoffers#sent");
		}
		
		//delete offer from db
		$delStr = "DELETE FROM ". $this->_websoccer->getConfig("db_prefix") ."_transfer_angebot
                        WHERE spieler_id='".$playerId."' AND verein_id='".$clubId."' AND id='".$offer['id']."'";
		$this->_db->executeQuery($delStr);
		
		// show success message
		$this->_websoccer->addFrontMessage(new FrontMessage(MESSAGE_TYPE_SUCCESS, $this->_i18n->getMessage("transferoffers_offer_withdraw_success"), ""));
		
		return "myoffers";
	}
	
}

?>