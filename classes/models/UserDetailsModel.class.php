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
 * Provides data of user with passed ID.
 */
class UserDetailsModel implements IModel {
	private $_db;
	private $_i18n;
	private $_websoccer;
	
	public function __construct($db, $i18n, $websoccer) {
		$this->_db = $db;
		$this->_i18n = $i18n;
		$this->_websoccer = $websoccer;
	}
	
	/**
	 * (non-PHPdoc)
	 * @see IModel::renderView()
	 */
	public function renderView() {
		return TRUE;
	}
	
	/**
	 * (non-PHPdoc)
	 * @see IModel::getTemplateParameters()
	 */
	public function getTemplateParameters() {
		
		$userId = (int) $this->_websoccer->getRequestParameter('id');
		if ($userId < 1) {
			$userId = $this->_websoccer->getUser()->id;
		}
		
		$user = UsersDataService::getUserById($this->_websoccer, $this->_db, $userId);
		
		if (!isset($user['id'])) {
			throw new Exception($this->_i18n->getMessage(MSG_KEY_ERROR_PAGENOTFOUND));
		}
		
		$user['reputation'] = isset($user['reputation']) ? (int) $user['reputation'] : 0;
		$user['membership_days'] = $this->getMembershipDays($user);
		$user['is_online'] = ((int) $user['lastonline'] >= ($this->_websoccer->getNowAsTimestamp() - 15 * 60)) ? TRUE : FALSE;
		$user['profile_fields_filled'] = $this->countFilledProfileFields($user);
		$user['profile_fields_total'] = 8;
		
		// get teams of user
		$fromTable = $this->_websoccer->getConfig('db_prefix') . '_verein';
		$whereCondition = 'user_id = %d AND status = \'1\' AND nationalteam != \'1\' ORDER BY name ASC';
		$result = $this->_db->querySelect('id,name,bild', $fromTable, $whereCondition, $userId);		
		
		$teams = array();
		while ($team = $result->fetch_array()) {
			$teams[] = $team;
		}
		$result->free();
		
		// get national team of user
		if ($this->_websoccer->getConfig('nationalteams_enabled')) {
			$columns = 'id,name';
			$fromTable = $this->_websoccer->getConfig('db_prefix') . '_verein';
			$whereCondition = 'user_id = %d AND nationalteam = \'1\'';
			$result = $this->_db->querySelect($columns, $fromTable, $whereCondition, $userId, 1);
			$nationalteam = $result->fetch_array();
			$result->free();
			if (isset($nationalteam['id'])) {
				$user['nationalteam'] = $nationalteam;
			}
		}
		
		// badges
		// Profile tab shall show all reachable badges with the user's current status,
		// not only already earned badges.
		$badgeTable = $this->_websoccer->getConfig('db_prefix') . '_badge';
		$badgeUserTable = $this->_websoccer->getConfig('db_prefix') . '_badge_user';
		$badgeEventLogTable = $this->_websoccer->getConfig('db_prefix') . '_badge_event_log';

		$teamIds = array();
		foreach ($teams as $team) {
			$teamIds[] = (int) $team['id'];
		}

		$badgeStatus = array(
			'membership_since_x_days' => $this->getMembershipDays($user),
			'win_with_x_goals_difference' => $this->getBiggestWinGoalDifference($teamIds),
			'completed_season_at_x' => $this->getBestSeasonRank($userId, $teamIds),
			'x_trades' => $this->getTransferCount($userId),
			'cupwinner' => $this->getCupWinnerCount($userId, $teamIds),
			'stadium_construction_by_x' => $this->getStadiumConstructionCount($teamIds),
			'derby_wins' => $this->getDerbyWinCount($teamIds)
		);

		$fromTable = $badgeTable . ' AS B '
			. 'LEFT JOIN ' . $badgeUserTable . ' AS BU ON BU.badge_id = B.id AND BU.user_id = ' . $userId . ' '
			. 'LEFT JOIN ' . $badgeEventLogTable . ' AS EL ON EL.event = B.event AND EL.user_id = ' . $userId;
		$result = $this->_db->querySelect(
			'B.id, B.name, B.description, B.level, B.event, B.event_benchmark, COUNT(DISTINCT BU.id) AS earned_count, MAX(BU.date_rewarded) AS last_rewarded, COUNT(DISTINCT EL.id) AS progress_count, COALESCE(MAX(EL.event_value), 0) AS progress_max',
			$fromTable,
			"1 GROUP BY B.id ORDER BY B.event ASC, B.event_benchmark ASC, FIELD(B.level, 'bronze', 'silver', 'gold') ASC"
		);
		$badges = array();
		while ($badge = $result->fetch_array()) {
			$event = (string) $badge['event'];

			if ($event === 'transfer_master') {
				$badge['current_status'] = (int) $badge['progress_max'];
			} else if (isset($badgeStatus[$event])) {
				$badge['current_status'] = (int) $badgeStatus[$event];
			} else {
				$badge['current_status'] = (int) $badge['progress_count'];
			}

			$badge['earned'] = ((int) $badge['earned_count'] > 0) ? TRUE : FALSE;
			$badges[] = $badge;
		}
		$result->free();
		
		$viewerUser = $this->_websoccer->getUser();
		$viewerUserId = ($viewerUser && $viewerUser->id) ? (int) $viewerUser->id : 0;
		$career = ManagerCareerDataService::getCareerPageData($this->_websoccer, $this->_db, $this->_i18n, $userId, $viewerUserId);
		
		return array('user' => $user, 'userteams' => $teams, 
				'absence' => AbsencesDataService::getCurrentAbsenceOfUser($this->_websoccer, $this->_db, $userId),
				'badges' => $badges,
				'career' => $career);
	}
	
	private function countFilledProfileFields($user) {
		$fields = array('name', 'place', 'country', 'birthday', 'occupation', 'interests', 'favorite_club', 'homepage');
		$filled = 0;
		foreach ($fields as $field) {
			if (!isset($user[$field])) {
				continue;
			}
			$value = $user[$field];
			if ($field === 'birthday') {
				if ($value && $value !== '0000-00-00') {
					$filled++;
				}
			} else if (strlen(trim((string) $value))) {
				$filled++;
			}
		}
		return $filled;
	}

	private function getMembershipDays($user) {
		if (!isset($user['registration_date']) || (int) $user['registration_date'] < 1) {
			return 0;
		}

		return max(0, (int) floor(($this->_websoccer->getNowAsTimestamp() - (int) $user['registration_date']) / 86400));
	}

	private function getBiggestWinGoalDifference($teamIds) {
		if (!count($teamIds)) {
			return 0;
		}

		$ids = implode(',', array_map('intval', $teamIds));
		$table = $this->_websoccer->getConfig('db_prefix') . '_spiel';
		$result = $this->_db->querySelect(
			'GREATEST(COALESCE(MAX(CASE WHEN home_verein IN (' . $ids . ') AND home_tore > gast_tore THEN home_tore - gast_tore ELSE 0 END), 0), COALESCE(MAX(CASE WHEN gast_verein IN (' . $ids . ') AND gast_tore > home_tore THEN gast_tore - home_tore ELSE 0 END), 0)) AS value',
			$table,
			"berechnet = '1' AND (home_verein IN (" . $ids . ") OR gast_verein IN (" . $ids . "))",
			null,
			1
		);
		$row = $result->fetch_array();
		$result->free();

		return ($row && isset($row['value'])) ? (int) $row['value'] : 0;
	}

	private function getBestSeasonRank($userId, $teamIds) {
		$table = $this->_websoccer->getConfig('db_prefix') . '_achievement';
		$where = 'user_id = %d AND rank IS NOT NULL AND rank > 0';
		$params = array((int) $userId);

		if (count($teamIds)) {
			$where .= ' AND team_id IN (' . implode(',', array_map('intval', $teamIds)) . ')';
		}

		$result = $this->_db->querySelect('MIN(rank) AS value', $table, $where, $params, 1);
		$row = $result->fetch_array();
		$result->free();

		return ($row && isset($row['value'])) ? (int) $row['value'] : 0;
	}

	private function getTransferCount($userId) {
		$table = $this->_websoccer->getConfig('db_prefix') . '_transfer';
		$result = $this->_db->querySelect('COUNT(*) AS value', $table, 'seller_user_id = %d OR buyer_user_id = %d', array((int) $userId, (int) $userId), 1);
		$row = $result->fetch_array();
		$result->free();

		return ($row && isset($row['value'])) ? (int) $row['value'] : 0;
	}

	private function getCupWinnerCount($userId, $teamIds) {
		$table = $this->_websoccer->getConfig('db_prefix') . '_achievement';
		$where = 'user_id = %d AND cup_round_id IS NOT NULL AND cup_round_id > 0 AND rank = 1';
		$params = array((int) $userId);

		if (count($teamIds)) {
			$where .= ' AND team_id IN (' . implode(',', array_map('intval', $teamIds)) . ')';
		}

		$result = $this->_db->querySelect('COUNT(*) AS value', $table, $where, $params, 1);
		$row = $result->fetch_array();
		$result->free();

		return ($row && isset($row['value'])) ? (int) $row['value'] : 0;
	}

	private function getStadiumConstructionCount($teamIds) {
		if (!count($teamIds)) {
			return 0;
		}

		$table = $this->_websoccer->getConfig('db_prefix') . '_stadium_construction';
		$result = $this->_db->querySelect('COUNT(*) AS value', $table, 'team_id IN (' . implode(',', array_map('intval', $teamIds)) . ')', null, 1);
		$row = $result->fetch_array();
		$result->free();

		return ($row && isset($row['value'])) ? (int) $row['value'] : 0;
	}

	private function getDerbyWinCount($teamIds) {
		if (!count($teamIds)) {
			return 0;
		}

		$table = $this->_websoccer->getConfig('db_prefix') . '_derby_match';
		$result = $this->_db->querySelect('COUNT(*) AS value', $table, "post_processed = '1' AND winner_team_id IN (" . implode(',', array_map('intval', $teamIds)) . ")", null, 1);
		$row = $result->fetch_array();
		$result->free();

		return ($row && isset($row['value'])) ? (int) $row['value'] : 0;
	}

}

?>