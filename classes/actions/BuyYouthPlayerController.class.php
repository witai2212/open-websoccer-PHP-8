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
 * Buys a transferable youth player from another team.
 */
class BuyYouthPlayerController implements IActionController {
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
		if (!$this->_websoccer->getConfig("youth_enabled")) {
			return NULL;
		}
		
		$user = $this->_websoccer->getUser();
		
		$clubId = $user->getClubId($this->_websoccer, $this->_db);
		if ($clubId < 1) {
			throw new Exception($this->_i18n->getMessage("feature_requires_team"));
		}
		
		// check if it is already own player
		$player = YouthPlayersDataService::getYouthPlayerById($this->_websoccer, $this->_db, $this->_i18n, $parameters["id"]);
		$transferFee = (int) $player["transfer_fee"];
		if ($transferFee <= 0) {
			throw new Exception($this->_i18n->getMessage("youthteam_buy_err_notonmarket"));
		}
		if ($clubId == $player["team_id"]) {
			throw new Exception($this->_i18n->getMessage("youthteam_buy_err_ownplayer"));
		}
		
		// player must not be tranfered from one of user's other teams
		$result = $this->_db->querySelect("user_id", $this->_websoccer->getConfig("db_prefix") . "_verein", "id = %d", $player["team_id"]);
		$playerteam = $result->fetch_array();
		$result->free_result();
		if ($playerteam["user_id"] == $user->id) {
			throw new Exception($this->_i18n->getMessage("youthteam_buy_err_ownplayer_otherteam"));
		}
		
		if (class_exists('ClubPartnershipDataService')) {
			ClubPartnershipDataService::assertYouthTransferAllowed($this->_websoccer, $this->_db, $this->_i18n, $parameters["id"], $clubId);
		}
		
		// check if enough budget
		$team = TeamsDataService::getTeamSummaryById($this->_websoccer, $this->_db, $clubId);
		if ($team["team_budget"] <= $transferFee) {
			throw new Exception($this->_i18n->getMessage("youthteam_buy_err_notenoughbudget"));
		}
		
		// credit / debit amount and run transfer watchdog atomically
		$prevTeam = TeamsDataService::getTeamSummaryById($this->_websoccer, $this->_db, $player["team_id"]);
		$watchdogResult = array("charged_total" => 0);
		
		$this->_db->connection->begin_transaction();
		try {
			BankAccountDataService::debitAmount($this->_websoccer, $this->_db, $clubId, $transferFee, "youthteam_transferfee_subject", 
				$prevTeam["team_name"]);
			BankAccountDataService::creditAmount($this->_websoccer, $this->_db, $player["team_id"], $transferFee, "youthteam_transferfee_subject",
				$team["team_name"]);
			
			$watchdogResult = YouthTransferWatchdogDataService::watchTransfer(
				$this->_websoccer,
				$this->_db,
				$player,
				$clubId,
				$player["team_id"],
				$transferFee
			);
			
			// update player
			$this->_db->queryUpdate(array(
					"team_id" => $clubId,
					"transfer_fee" => 0,
					"transfer_start" => 0,
					"transfer_ende" => 0,
					"transfer_listed_by_cpu" => "0"
				),
					$this->_websoccer->getConfig("db_prefix") . "_youthplayer", "id = %d", $parameters["id"]);
			
			if (class_exists('YouthTransferOfferDataService')) {
				YouthTransferOfferDataService::closeOpenOffersForPlayer($this->_websoccer, $this->_db, $parameters["id"]);
			}

			if (class_exists('ClubPartnershipDataService')) {
				ClubPartnershipDataService::markYouthFirstOptionUsed($this->_websoccer, $this->_db, $parameters["id"], $clubId);
			}
			
			// create notification
			NotificationsDataService::createNotification($this->_websoccer, $this->_db, $prevTeam["user_id"], "youthteam_transfer_notification",
				array("player" => $player["firstname"] . " " . $player["lastname"],
					"newteam" => $team["team_name"]), "youth_transfer", "team", "id=" . $clubId);
			
			$this->_db->connection->commit();
		} catch (Exception $e) {
			$this->_db->connection->rollback();
			throw $e;
		}
		
		// success message
		$this->_websoccer->addFrontMessage(new FrontMessage(MESSAGE_TYPE_SUCCESS, 
				$this->_i18n->getMessage("youthteam_buy_success"),
				""));
		
		if (isset($watchdogResult["charged_total"]) && (int) $watchdogResult["charged_total"] > 0) {
			$formattedPenalty = number_format((int) $watchdogResult["charged_total"], 0, ",", ".") . " EUR";
			$this->_websoccer->addFrontMessage(new FrontMessage(MESSAGE_TYPE_WARNING,
					$this->_i18n->getMessage("youth_transfer_violation_front", $formattedPenalty),
					""));
		}
		
		return "youth-team";
	}
	
}

?>