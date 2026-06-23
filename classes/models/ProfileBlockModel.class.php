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
 * @author Ingo Hofmann
 */
class ProfileBlockModel implements IModel {
	private $_db;
	private $_i18n;
	private $_websoccer;
	
	public function __construct($db, $i18n, $websoccer) {
		$this->_db = $db;
		$this->_i18n = $i18n;
		$this->_websoccer = $websoccer;
	}
	
	public function renderView() {
		return (strlen($this->_websoccer->getUser()->username)) ? TRUE : FALSE;
	}
	
	public function getTemplateParameters() {
		$fromTable = $this->_websoccer->getConfig("db_prefix") . "_user";
		
		$user = $this->_websoccer->getUser();
		
		// select general information
		$columns = "fanbeliebtheit AS user_popularity, highscore AS user_highscore";
		$whereCondition = "id = %d";
		$result = $this->_db->querySelect($columns, $fromTable, $whereCondition, $user->id, 1);
		$userinfo = $result->fetch_array();
		$result->free();
		
		$clubId = $user->getClubId($this->_websoccer, $this->_db);
		
		// get team info
		$team = null;
		if ($clubId > 0) {
			$team = TeamsDataService::getTeamSummaryById($this->_websoccer, $this->_db, $clubId);
		}
		
		$continentalAssociation = array();
		$continentalAssociations = array();
		if ($clubId > 0 && class_exists('ContinentalAssociationDataService')) {
			try {
				$continentalAssociation = ContinentalAssociationDataService::getTeamAssociation($this->_websoccer, $this->_db, (int) $clubId);
				$continentalAssociations = ContinentalAssociationDataService::getUserManagedAssociations($this->_websoccer, $this->_db, (int) $user->id);
			} catch (Exception $e) {
				$continentalAssociation = array();
				$continentalAssociations = array();
			}
		}

		// unread messages
		$unseenMessages = MessagesDataService::countUnseenInboxMessages($this->_websoccer, $this->_db);
		
		// unseen notifications
		$unseenNotifications = NotificationsDataService::countUnseenNotifications($this->_websoccer, $this->_db, $user->id, $clubId);
		
		$boardSatisfaction = 0;
		$boardInfo = array("min_target_rank" => 0, "min_target_highscore" => 0, "highscore" => 0);
		$boardMissions = null;
		$managerReputation = null;

		// Keep this value aligned with Manager Career / free club eligibility.
		try {
			if (class_exists('ManagerCareerDataService') && ManagerCareerDataService::isEnabled($this->_websoccer)) {
				$managerReputation = ManagerCareerDataService::getManagerScoreForUser(
					$this->_websoccer,
					$this->_db,
					(int) $user->id
				);
			}
		} catch (Exception $e) {
			$managerReputation = null;
		}
		
		if ($clubId > 0) {
			// board satisfaction
			$boardSatisfaction = BoardDataService::getBoardSatisfactionByTeamId($this->_websoccer, $this->_db, $clubId);
			
			// legacy board info and season targets; used only as fallback when manager missions are unavailable.
			$boardInfo = BoardDataService::getBoardinfoByTeamId($this->_websoccer, $this->_db, $clubId);
			
			// Keep the sidebar in sync with the Vorstand / Saisonziele page.
			try {
				if (class_exists('ManagerMissionsDataService') && ManagerMissionsDataService::isEnabled($this->_websoccer)) {
					$boardMissions = ManagerMissionsDataService::getMissionPageData(
						$this->_websoccer,
						$this->_db,
						$this->_i18n,
						(int) $user->id,
						(int) $clubId
					);
				}
			} catch (Exception $e) {
				$boardMissions = null;
			}
		}
		
		return array("profile" => $userinfo, "userteam" => $team, "unseenMessages" => $unseenMessages,
				"unseenNotifications" => $unseenNotifications,
		        "boardsatisfaction" => $boardSatisfaction, "boardinfo" => $boardInfo, "boardmissions" => $boardMissions,
				"managerReputation" => $managerReputation,
				"continental_association" => $continentalAssociation,
				"continental_associations" => $continentalAssociations);
	}
	
	
}

?>