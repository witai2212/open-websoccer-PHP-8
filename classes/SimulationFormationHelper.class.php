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

// CM23 | 2026-09-01 | Revision 1 | Task 1017

/**
 * Helper functions for setting formations for the match simulation.
 * 
 * @author Ingo Hofmann
 */
class SimulationFormationHelper {
	
	/**
	 * Generates a new formation for the specified team, which will be directly stored both in the database and in the internal model.
	 * 
	 * CPU clubs use their configured standard formation when possible.
	 * Human-controlled clubs and national teams keep the previous 4-4-2 fallback behaviour.
	 * The freshest available players are preferred.
	 * 
	 * @param WebSoccer $websoccer request context.
	 * @param DbConnection $db database connection.
	 * @param SimulationTeam $team Team that needs a new formation.
	 * @param int $matchId match id.
	 */
	public static function generateNewFormationForTeam(WebSoccer $websoccer, DbConnection $db, SimulationTeam $team, $matchId) {
		
		// get all players (prefer the freshest players)
		$columns['id'] = 'id';
		$columns['position'] = 'position';
		$columns['position_main'] = 'mainPosition';
		$columns['vorname'] = 'firstName';
		$columns['nachname'] = 'lastName';
		$columns['kunstname'] = 'pseudonym';
		$columns['w_staerke'] = 'strength';
		$columns['w_technik'] = 'technique';
		$columns['w_kondition'] = 'stamina';
		$columns['w_frische'] = 'freshness';
		$columns['w_zufriedenheit'] = 'satisfaction';
		
		$columns['w_passing'] = 'passing';
		$columns['w_shooting'] = 'shooting';
		$columns['w_heading'] = 'heading';
		$columns['w_tackling'] = 'tackling';
		$columns['w_pace'] = 'pace';
		$columns['w_freekick'] = 'freekick';
		$columns['w_influence'] = 'influence';
		$columns['w_creativity'] = 'creativity';
		$columns['w_flair'] = 'flair';
		$columns['w_penalty'] = 'penalty';
		$columns['w_penalty_killing'] = 'penalty_killing';
		
		if ($websoccer->getConfig('players_aging') == 'birthday') {
			$ageColumn = 'TIMESTAMPDIFF(YEAR,geburtstag,CURDATE())';
		} else {
			$ageColumn = 'age';
		}
		$columns[$ageColumn] = 'age';
		
		$formationQuotas = array(
			PLAYER_POSITION_GOALY => 1,
			PLAYER_POSITION_DEFENCE => 4,
			PLAYER_POSITION_MIDFIELD => 4,
			PLAYER_POSITION_STRIKER => 2
		);
		
		// get players from usual team
		if (!$team->isNationalTeam) {
			$fromTable = $websoccer->getConfig('db_prefix') . '_spieler';
			$whereCondition = 'verein_id = %d AND verletzt = 0 AND gesperrt = 0 AND status = 1 ORDER BY w_frische DESC';
			$parameters = $team->id;
			$result = $db->querySelect($columns, $fromTable, $whereCondition, $parameters);
		} else {
			// national team: take best players of nation
			$columnsStr = '';
			
			$firstColumn = TRUE;
			foreach($columns as $dbName => $aliasName) {
				if (!$firstColumn) {
					$columnsStr = $columnsStr .', ';
				} else {
					$firstColumn = FALSE;
				}
			
				$columnsStr = $columnsStr . $dbName. ' AS '. $aliasName;
			}
			
			$nation = $db->connection->escape_string($team->name);
			$dbPrefix = $websoccer->getConfig('db_prefix');
			$queryStr = '(SELECT ' . $columnsStr . ' FROM ' . $dbPrefix . '_spieler WHERE nation = \''. $nation .'\' AND position = \'Torwart\' ORDER BY w_staerke DESC, w_frische DESC LIMIT 1)';
			$queryStr .= ' UNION ALL (SELECT ' . $columnsStr . ' FROM ' . $dbPrefix . '_spieler WHERE nation = \''. $nation .'\' AND position = \'Abwehr\' ORDER BY w_staerke DESC, w_frische DESC LIMIT 4)';
			$queryStr .= ' UNION ALL (SELECT ' . $columnsStr . ' FROM ' . $dbPrefix . '_spieler WHERE nation = \''. $nation .'\' AND position = \'Mittelfeld\' ORDER BY w_staerke DESC, w_frische DESC LIMIT 4)';
			$queryStr .= ' UNION ALL (SELECT ' . $columnsStr . ' FROM ' . $dbPrefix . '_spieler WHERE nation = \''. $nation .'\' AND position = \'Sturm\' ORDER BY w_staerke DESC, w_frische DESC LIMIT 2)';
			$result = $db->executeQuery($queryStr);
		}
		
		$playerInfos = array();
		while ($playerinfo = $result->fetch_array()) {
			$playerInfos[] = $playerinfo;
		}
		$result->free();
		
		if (!$team->isNationalTeam) {
			$formationQuotas = self::getFormationQuotasForClub($websoccer, $db, $team->id, $playerInfos);
		}
		
		$lvExists = FALSE;
		$rvExists = FALSE;
		$lmExists = FALSE;
		$rmExists = FALSE;
		$ivPlayers = 0;
		$zmPlayers = 0;
		
		foreach ($playerInfos as $playerinfo) {
			$position = $playerinfo['position'];
			
			if (isset($formationQuotas[$position])
					&& isset($team->positionsAndPlayers[$position])
					&& count($team->positionsAndPlayers[$position]) >= $formationQuotas[$position]) {
				continue;
			}
			
			if (!isset($formationQuotas[$position]) || $formationQuotas[$position] <= 0) {
				continue;
			}
			
			$mainPosition = $playerinfo['mainPosition'];
			//prevent double LV/RV/LM/RM
			if ($mainPosition == 'LV') {
				if ($lvExists) {
					$mainPosition = 'IV';
					$ivPlayers++;
					if ($ivPlayers == 3) {
						$mainPosition = 'RV';
						$rvExists = TRUE;
					}
				} else {
					$lvExists = TRUE;
				}
			} elseif ($mainPosition == 'RV') {
				if ($rvExists) {
					$mainPosition = 'IV';
					$ivPlayers++;
					if ($ivPlayers == 3) {
						$mainPosition = 'LV';
						$lvExists = TRUE;
					}
				} else {
					$rvExists = TRUE;
				}
			} elseif ($mainPosition == 'IV') {
				$ivPlayers++;
				if ($ivPlayers == 3) {
					if (!$rvExists) {
						$mainPosition = 'RV';
						$rvExists = TRUE;
					} else {
						$mainPosition = 'LV';
						$lvExists = TRUE;
					}
				}
			} elseif ($mainPosition == 'LM') {
				if ($lmExists) {
					$mainPosition = 'ZM';
					$zmPlayers++;
				} else {
					$lmExists = TRUE;
				}
			} elseif ($mainPosition == 'RM') {
				if ($rmExists) {
					$mainPosition = 'ZM';
					$zmPlayers++;
				} else {
					$rmExists = TRUE;
				}
			} elseif ($mainPosition == 'LS' || $mainPosition == 'RS') {
				$mainPosition = 'MS';
			} elseif ($mainPosition == 'ZM') {
				$zmPlayers++;
				if ($zmPlayers > 2) {
					$mainPosition = 'DM';
				}
			}
			
			$player = new SimulationPlayer($playerinfo['id'], $team, $position, $mainPosition,
					3.0, $playerinfo['age'], $playerinfo['strength'], $playerinfo['technique'], $playerinfo['stamina'],
			    $playerinfo['freshness'], $playerinfo['satisfaction'], $playerinfo['passing'], $playerinfo['shooting'],
			    $playerinfo['tackling'], $playerinfo['heading'], $playerinfo['influence'], $playerinfo['creativity'],
			    $playerinfo['flair'], $playerinfo['pace'], $playerinfo['freekick'], $playerinfo['penalty'], $playerinfo['penalty_killing']);
			if (class_exists('PlayerTraitsDataService')) {
				PlayerTraitsDataService::applyTraitsToSimulationPlayer($websoccer, $db, $player);
			}
			
			if (strlen($playerinfo['pseudonym'])) {
				$player->name = $playerinfo['pseudonym'];
			} else {
				$player->name = $playerinfo['firstName'] . ' ' . $playerinfo['lastName'];
			}
			
			$team->positionsAndPlayers[$player->position][] = $player;
			SimulationStateHelper::createSimulationRecord($websoccer, $db, $matchId, $player);
		}
	}
	
	/**
	 * Returns the broad-position quotas for a club.
	 * Only CPU clubs use the configured club formation.
	 */
	private static function getFormationQuotasForClub(WebSoccer $websoccer, DbConnection $db, $clubId, $playerInfos) {
		$defaultQuotas = array(
			PLAYER_POSITION_GOALY => 1,
			PLAYER_POSITION_DEFENCE => 4,
			PLAYER_POSITION_MIDFIELD => 4,
			PLAYER_POSITION_STRIKER => 2
		);
		
		$columns = array(
			'formation' => 'formation',
			'user_id' => 'user_id'
		);
		$clubTable = $websoccer->getConfig('db_prefix') . '_verein';
		$result = $db->querySelect($columns, $clubTable, 'id = %d', $clubId);
		$club = $result->fetch_array();
		$result->free();
		
		// Human-controlled clubs keep the previous automatic 4-4-2 fallback.
		if (!$club || (isset($club['user_id']) && (int) $club['user_id'] > 0)) {
			return $defaultQuotas;
		}
		
		$available = array(
			PLAYER_POSITION_GOALY => 0,
			PLAYER_POSITION_DEFENCE => 0,
			PLAYER_POSITION_MIDFIELD => 0,
			PLAYER_POSITION_STRIKER => 0
		);
		foreach ($playerInfos as $playerinfo) {
			if (isset($available[$playerinfo['position']])) {
				$available[$playerinfo['position']]++;
			}
		}
		
		$candidates = array();
		if (isset($club['formation']) && strlen(trim($club['formation']))) {
			$candidates[] = trim($club['formation']);
		}
		
		// Supported fallback formations using the same six-part setup format as the formation page.
		$fallbackSetups = array(
			'4-0-4-0-2-0',
			'4-0-3-0-3-0',
			'3-0-4-0-3-0',
			'3-0-5-0-2-0',
			'5-0-3-0-2-0',
			'4-0-5-0-1-0'
		);
		
		foreach ($fallbackSetups as $fallbackSetup) {
			if (!in_array($fallbackSetup, $candidates, TRUE)) {
				$candidates[] = $fallbackSetup;
			}
		}
		
		foreach ($candidates as $setup) {
			$quotas = self::parseFormationSetup($setup);
			if ($quotas !== FALSE && self::isFormationPlayable($quotas, $available)) {
				return $quotas;
			}
		}
		
		return $defaultQuotas;
	}
	
	/**
	 * Converts the existing six-part formation setup into broad-position quotas.
	 * Format: defence-defensive midfield-midfield-offensive midfield-striker-outside forward.
	 */
	private static function parseFormationSetup($setup) {
		$parts = explode('-', $setup);
		if (count($parts) == 5) {
			$parts[] = 0;
		}
		
		if (count($parts) != 6) {
			return FALSE;
		}
		
		$values = array();
		foreach ($parts as $part) {
			if ($part === '' || !ctype_digit((string) $part)) {
				return FALSE;
			}
			$value = (int) $part;
			if ($value < 0) {
				return FALSE;
			}
			$values[] = $value;
		}
		
		if (array_sum($values) != 10) {
			return FALSE;
		}
		
		return array(
			PLAYER_POSITION_GOALY => 1,
			PLAYER_POSITION_DEFENCE => $values[0],
			PLAYER_POSITION_MIDFIELD => $values[1] + $values[2] + $values[3],
			PLAYER_POSITION_STRIKER => $values[4] + $values[5]
		);
	}
	
	private static function isFormationPlayable($quotas, $available) {
		foreach ($quotas as $position => $required) {
			if (!isset($available[$position]) || $available[$position] < $required) {
				return FALSE;
			}
		}
		
		return TRUE;
	}
	
}
?>