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
 * Lists achievements of user.
 */
class UserHistoryModel implements IModel {
	private $_db;
	private $_i18n;
	private $_websoccer;
	private $_userId;
	
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
		$this->_userId = (int) $this->_websoccer->getRequestParameter("userid");
		return $this->_userId > 0;
	}
	
	/**
	 * (non-PHPdoc)
	 * @see IModel::getTemplateParameters()
	 */
	public function getTemplateParameters() {
		
		$columns = array(
				'TEAM.id' => 'team_id',
				'TEAM.name' => 'team_name',
				'L.name' => 'league_name',
				'SEASON.name' => 'season_name',
				'A.rank' => 'season_rank',
				'A.id' => 'achievement_id',
				'A.date_recorded' => 'achievement_date',
				'CUP.name' => 'cup_name',
				'CUPROUND.name' => 'cup_round_name'
				);
		$tablePrefix = $this->_websoccer->getConfig('db_prefix');
		
		$fromTable = $tablePrefix . '_achievement AS A';
		$fromTable .= ' INNER JOIN ' . $tablePrefix . '_verein AS TEAM ON TEAM.id = A.team_id';
		$fromTable .= ' LEFT JOIN ' . $tablePrefix . '_saison AS SEASON ON SEASON.id = A.season_id';
		$fromTable .= ' LEFT JOIN ' . $tablePrefix . '_liga AS L ON SEASON.liga_id = L.id';
		$fromTable .= ' LEFT JOIN ' . $tablePrefix . '_cup_round AS CUPROUND ON CUPROUND.id = A.cup_round_id';
		$fromTable .= ' LEFT JOIN ' . $tablePrefix . '_cup AS CUP ON CUP.id = CUPROUND.cup_id';
		
		$whereCondition = 'A.user_id = %d ORDER BY A.date_recorded DESC';
		
		$result = $this->_db->querySelect($columns, $fromTable, $whereCondition, $this->_userId);
		$leagues = array();
		$cups = array();
		while ($achievement = $result->fetch_array()) {
			
			if (strlen($achievement['league_name'])) {
				$leagues[$achievement['league_name']][] = $achievement;
			} else if (!isset($cups[$achievement['cup_name']])) {
				
				$cups[$achievement['cup_name']] = $achievement;
				
				// delete achievement, since it is an older cup round than already saved
			} else {
				$this->_db->queryDelete($tablePrefix . '_achievement', 'id = %d', $achievement['achievement_id']);
			}
			
		}
			$result->free();
			
			$seasonCount = 0;
			foreach ($leagues as $seasons) {
				$seasonCount += count($seasons);
			}
			
			return array(
				"leagues" => $leagues,
				"cups" => $cups,
				"career_history" => $this->getCareerHistory(),
				"user_history" => $this->getUserHistoryText(),
				"season_count" => $seasonCount,
				"cup_count" => count($cups)
			);
		}
		
		private function getCareerHistory() {
			$tablePrefix = $this->_websoccer->getConfig('db_prefix');
			$fromTable = $tablePrefix . '_manager_career_history AS H';
			$fromTable .= ' LEFT JOIN ' . $tablePrefix . '_verein AS OLDTEAM ON OLDTEAM.id = H.old_team_id';
			$fromTable .= ' LEFT JOIN ' . $tablePrefix . '_liga AS OLDLEAGUE ON OLDLEAGUE.id = OLDTEAM.liga_id';
			$fromTable .= ' LEFT JOIN ' . $tablePrefix . '_verein AS NEWTEAM ON NEWTEAM.id = H.new_team_id';
			$fromTable .= ' LEFT JOIN ' . $tablePrefix . '_liga AS NEWLEAGUE ON NEWLEAGUE.id = NEWTEAM.liga_id';

			$columns = array(
				'H.id' => 'id',
				'H.change_date' => 'change_date',
				'H.origin' => 'origin',
				'H.old_team_id' => 'old_team_id',
				'H.new_team_id' => 'new_team_id',
				'H.old_club_score' => 'old_club_score',
				'H.new_club_score' => 'new_club_score',
				'H.highscore_bonus' => 'highscore_bonus',
				'OLDTEAM.name' => 'old_team_name',
				'OLDTEAM.bild' => 'old_team_picture',
				'OLDLEAGUE.name' => 'old_league_name',
				'NEWTEAM.name' => 'new_team_name',
				'NEWTEAM.bild' => 'new_team_picture',
				'NEWLEAGUE.name' => 'new_league_name'
			);

			try {
				$result = $this->_db->querySelect(
					$columns,
					$fromTable,
					'H.user_id = %d ORDER BY H.change_date DESC, H.id DESC',
					$this->_userId,
					20
				);
			} catch (Exception $e) {
				return array();
			}

			$history = array();
			while ($item = $result->fetch_array()) {
				$history[] = $item;
			}
			$result->free();

			return $history;
		}

		private function getUserHistoryText() {
			$table = $this->_websoccer->getConfig('db_prefix') . '_user';
			$result = $this->_db->querySelect('history', $table, 'id = %d AND status = \'1\'', $this->_userId, 1);
			$row = $result->fetch_array();
			$result->free();
			return ($row && isset($row['history'])) ? trim((string) $row['history']) : '';
		}
		
	}

?>
