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
 * Data service for leagues
 */
class FormationDataService {
	
	/**
	 * Provides a previously saved formation of the specified team and match.
	 * 
	 * @param WebSoccer $websoccer Application context
	 * @param DbConnection $db DB connection
	 * @param int $teamId ID of team.
	 * @param int $matchId ID of match
	 * @return array previously set formation.
	 */
	public static function getFormationByTeamId(WebSoccer $websoccer, DbConnection $db, $teamId, $matchId) {
		$whereCondition = 'verein_id = %d AND match_id = %d';
		$parameters = array($teamId, $matchId);
		
		return self::_getFormationByCondition($websoccer, $db, $whereCondition, $parameters);
	}
	
	/**
	 * Provides a previously saved formation as template.
	 *
	 * @param WebSoccer $websoccer Application context
	 * @param DbConnection $db DB connection
	 * @param int $teamId ID of team.
	 * @param int $templateId ID of template (formation)
	 * @return array formation.
	 */
	public static function getFormationByTemplateId(WebSoccer $websoccer, DbConnection $db, $teamId, $templateId) {
		$whereCondition = 'id = %d AND verein_id = %d';
		$parameters = array($templateId, $teamId);
		return self::_getFormationByCondition($websoccer, $db, $whereCondition, $parameters);
	}
	
	private static function _getFormationByCondition(WebSoccer $websoccer, DbConnection $db, $whereCondition, $parameters) {
		$fromTable = $websoccer->getConfig('db_prefix') . '_aufstellung';
	
		// select
		$columns['id'] = 'id';
		$columns['offensive'] = 'offensive';
		$columns['setup'] = 'setup';
		$columns['longpasses'] = 'longpasses';
		$columns['counterattacks'] = 'counterattacks';
		$columns['freekickplayer'] = 'freekickplayer';
		$columns['cornerplayer'] = 'cornerplayer';
	
		for ($playerNo = 1; $playerNo <= 11; $playerNo++) {
			$columns['spieler' . $playerNo] = 'player' . $playerNo;
			$columns['spieler' . $playerNo . '_position'] = 'player' . $playerNo . '_pos';
		}
	
		for ($playerNo = 1; $playerNo <= 5; $playerNo++) {
			$columns['ersatz' . $playerNo] = 'bench' . $playerNo;
		}
	
		for ($subNo = 1; $subNo <= 3; $subNo++) {
			$columns['w'. $subNo . '_raus'] = 'sub' . $subNo .'_out';
			$columns['w'. $subNo . '_rein'] = 'sub' . $subNo .'_in';
			$columns['w'. $subNo . '_minute'] = 'sub' . $subNo .'_minute';
			$columns['w'. $subNo . '_condition'] = 'sub' . $subNo .'_condition';
			$columns['w'. $subNo . '_position'] = 'sub' . $subNo .'_position';
		}
	
		$result = $db->querySelect($columns, $fromTable, $whereCondition, $parameters, 1);
		$formation = $result->fetch_array();
		if (!$formation) {
			$formation = array();
		}
		$result->free();
	
		return $formation;
	}
	
	/**
	 * Provides a proposal for a formation, considering the specified formation setup and sort column.
	 * 
	 * @param WebSoccer $websoccer Application Conttext
	 * @param DbConnection $db DB connection
	 * @param int $teamId ID of team
	 * @param int $setupDefense number of players in defense
	 * @param int $setupDM number of players in defensive midfield
	 * @param int $setupMidfield number of players in midfield
	 * @param int $setupOM number of players in offensive midfield
	 * @param int $setupStriker number of players in forward area (center forward only)
	 * @param int $setupOutsideforward number of outside forwards
	 * @param int $sortColumn DB sort column name
	 * @param string $sortDirection ASC|DESC (sort direction)
	 * @param boolean $isNationalteam TRUE if team is a national team.
	 * @return array array of players. Each player is an array with keys {id, position}.
	 */
	// CM23 | 2026-08-31 | Revision 2 | Task 1016
	public static function getFormationProposalForTeamId(WebSoccer $websoccer, DbConnection $db, $teamId, $setupDefense, 
			$setupDM, $setupMidfield, $setupOM, $setupStriker, $setupOutsideforward, $sortColumn, $sortDirection = 'DESC', 
			$isNationalteam = FALSE, $isCupMatch = FALSE) {
		
		$selectionMode = $websoccer->getRequestParameter('preselect');
		$supportedModes = array('strongest', 'freshest', 'motivated', 'youngest', 'bestform', 'savefitness', 'developtalent', 'rotation');
		if (!in_array($selectionMode, $supportedModes, TRUE)) {
			if ($sortColumn == 'w_frische') {
				$selectionMode = 'freshest';
			} elseif ($sortColumn == 'geburtstag') {
				$selectionMode = 'youngest';
			} elseif ($sortColumn == 'w_zufriedenheit') {
				$selectionMode = 'motivated';
			} else {
				$selectionMode = 'strongest';
			}
		}
		
		if (!$isNationalteam) {
			$columns = 'id,position,position_main,position_second,w_staerke,w_frische,w_zufriedenheit,w_talent,TIMESTAMPDIFF(YEAR,geburtstag,CURDATE()) AS age_years,note_last,note_schnitt';
			$fromTable = $websoccer->getConfig('db_prefix') . '_spieler';
			$whereCondition = 'verein_id = %d AND gesperrt';
			if ($isCupMatch) {
				$whereCondition .= '_cups';
			}
			$whereCondition .= ' = 0 AND verletzt = 0 AND status = 1';
		} else {
			$columns = 'P.id AS id,P.position AS position,P.position_main AS position_main,P.position_second AS position_second,'
					. 'P.w_staerke AS w_staerke,P.w_frische AS w_frische,P.w_zufriedenheit AS w_zufriedenheit,'
					. 'P.w_talent AS w_talent,TIMESTAMPDIFF(YEAR,P.geburtstag,CURDATE()) AS age_years,P.note_last AS note_last,P.note_schnitt AS note_schnitt';
			$fromTable = $websoccer->getConfig('db_prefix') . '_spieler AS P';
			$fromTable .= ' INNER JOIN ' . $websoccer->getConfig('db_prefix') . '_nationalplayer AS NP ON NP.player_id = P.id';
			$whereCondition = 'NP.team_id = %d AND P.gesperrt_nationalteam = 0 AND P.verletzt = 0 AND P.status = 1';
		}
		
		$result = $db->querySelect($columns, $fromTable, $whereCondition, $teamId);
		$availablePlayers = array();
		while ($player = $result->fetch_array()) {
			$availablePlayers[] = $player;
		}
		$result->free();
		
		$recentMinutes = array();
		if ($selectionMode == 'savefitness' || $selectionMode == 'rotation') {
			$recentMinutes = self::_getRecentCompetitiveMinutes($websoccer, $db, $teamId);
		}
		
		usort($availablePlayers, function($playerA, $playerB) use ($selectionMode, $recentMinutes) {
			return self::_compareProposalPlayers($playerA, $playerB, $selectionMode, $recentMinutes);
		});
		
		// determine open positions
		$openPositions['T'] = 1;
		
		// defense positions
		if ($setupDefense < 4) {
			$openPositions['IV'] = $setupDefense;
			$openPositions['LV'] = 0;
			$openPositions['RV'] = 0;
		} else {
			$openPositions['LV'] = 1;
			$openPositions['RV'] = 1;
			$openPositions['IV'] = $setupDefense - 2;
		}
		
		// defensive/offensive midfield positions
		$openPositions['DM'] = $setupDM;
		$openPositions['OM'] = $setupOM;
		
		// midfield positions
		if ($setupMidfield == 1) {
			$openPositions['ZM'] = 1;
		} else if ($setupMidfield == 2) {
			$openPositions['LM'] = 1;
			$openPositions['RM'] = 1;
		} else if ($setupMidfield == 3) {
			$openPositions['LM'] = 1;
			$openPositions['ZM'] = 1;
			$openPositions['RM'] = 1;
		} else if ($setupMidfield >= 4) {
			$openPositions['LM'] = 1;
			$openPositions['ZM'] = $setupMidfield - 2;
			$openPositions['RM'] = 1;
		} else {
			$openPositions['LM'] = 0;
			$openPositions['ZM'] = 0;
			$openPositions['RM'] = 0;
		}
		
		$openPositions['MS'] = $setupStriker;
		if ($setupOutsideforward == 2) {
			$openPositions['LS'] = 1;
			$openPositions['RS'] = 1;
		} else {
			$openPositions['LS'] = 0;
			$openPositions['RS'] = 0;
		}
		
		$players = array();
		$unusedPlayers = array();
		foreach ($availablePlayers as $player) {
			$added = FALSE;
			
			// handle players without main position (all-rounder)
			if (!strlen($player['position_main'])) {
				$possiblePositions = self::_getGenericPositions($player['position']);
				foreach ($possiblePositions as $possiblePosition) {
					if (isset($openPositions[$possiblePosition]) && $openPositions[$possiblePosition]) {
						$openPositions[$possiblePosition]--;
						$players[] = array('id' => $player['id'], 'position' => $possiblePosition);
						$added = TRUE;
						break;
					}
				}
			} elseif (isset($openPositions[$player['position_main']]) && $openPositions[$player['position_main']]) {
				$openPositions[$player['position_main']]--;
				$players[] = array('id' => $player['id'], 'position' => $player['position_main']);
				$added = TRUE;
			}
			
			if (!$added) {
				$unusedPlayers[] = $player;
			}
		}
		
		// Use secondary positions before broader fallback positions.
		foreach ($openPositions as $position => $requiredPlayers) {
			for ($i = 0; $i < $requiredPlayers; $i++) {
				foreach ($unusedPlayers as $playerIndex => $unusedPlayer) {
					if ($unusedPlayer['position_second'] == $position) {
						$players[] = array('id' => $unusedPlayer['id'], 'position' => $position);
						unset($unusedPlayers[$playerIndex]);
						$openPositions[$position]--;
						break;
					}
				}
			}
		}
		
		// Fill remaining slots with the best available positional fit. This also guarantees a goalkeeper slot when enough players exist.
		foreach ($openPositions as $position => $requiredPlayers) {
			for ($i = 0; $i < $requiredPlayers; $i++) {
				$bestPlayerIndex = NULL;
				$bestFit = PHP_INT_MAX;
				foreach ($unusedPlayers as $playerIndex => $unusedPlayer) {
					$fit = self::_getPositionFit($unusedPlayer, $position);
					if ($fit < $bestFit) {
						$bestFit = $fit;
						$bestPlayerIndex = $playerIndex;
					}
				}
				if ($bestPlayerIndex === NULL) {
					break;
				}
				$players[] = array('id' => $unusedPlayers[$bestPlayerIndex]['id'], 'position' => $position);
				unset($unusedPlayers[$bestPlayerIndex]);
			}
		}
		
		return $players;
	}
	
	// CM23 | 2026-08-31 | Revision 2 | Task 1016
	private static function _compareProposalPlayers($playerA, $playerB, $selectionMode, $recentMinutes) {
		$compareValues = array();
		
		if ($selectionMode == 'bestform') {
			$aLast = (float) $playerA['note_last'];
			$bLast = (float) $playerB['note_last'];
			if (($aLast > 0) != ($bLast > 0)) {
				return ($aLast > 0) ? -1 : 1;
			}
			if ($aLast > 0 && $aLast != $bLast) {
				return ($aLast < $bLast) ? -1 : 1;
			}
			$aAverage = (float) $playerA['note_schnitt'];
			$bAverage = (float) $playerB['note_schnitt'];
			if (($aAverage > 0) != ($bAverage > 0)) {
				return ($aAverage > 0) ? -1 : 1;
			}
			if ($aAverage > 0 && $aAverage != $bAverage) {
				return ($aAverage < $bAverage) ? -1 : 1;
			}
			$compareValues[] = array((float) $playerA['w_staerke'], (float) $playerB['w_staerke'], 'DESC');
		} elseif ($selectionMode == 'savefitness') {
			$compareValues[] = array((float) $playerA['w_frische'], (float) $playerB['w_frische'], 'DESC');
			$compareValues[] = array((int) ($recentMinutes[$playerA['id']] ?? 0), (int) ($recentMinutes[$playerB['id']] ?? 0), 'ASC');
			$compareValues[] = array((float) $playerA['w_staerke'], (float) $playerB['w_staerke'], 'DESC');
		} elseif ($selectionMode == 'rotation') {
			$compareValues[] = array((int) ($recentMinutes[$playerA['id']] ?? 0), (int) ($recentMinutes[$playerB['id']] ?? 0), 'ASC');
			$compareValues[] = array((float) $playerA['w_frische'], (float) $playerB['w_frische'], 'DESC');
			$compareValues[] = array((float) $playerA['w_staerke'], (float) $playerB['w_staerke'], 'DESC');
		} elseif ($selectionMode == 'developtalent') {
			$compareValues[] = array((int) $playerA['w_talent'], (int) $playerB['w_talent'], 'DESC');
			$compareValues[] = array((int) $playerA['age_years'], (int) $playerB['age_years'], 'ASC');
			$compareValues[] = array((float) $playerA['w_staerke'], (float) $playerB['w_staerke'], 'DESC');
		} elseif ($selectionMode == 'youngest') {
			$compareValues[] = array((int) $playerA['age_years'], (int) $playerB['age_years'], 'ASC');
			$compareValues[] = array((float) $playerA['w_staerke'], (float) $playerB['w_staerke'], 'DESC');
		} elseif ($selectionMode == 'freshest') {
			$compareValues[] = array((float) $playerA['w_frische'], (float) $playerB['w_frische'], 'DESC');
			$compareValues[] = array((float) $playerA['w_staerke'], (float) $playerB['w_staerke'], 'DESC');
		} elseif ($selectionMode == 'motivated') {
			$compareValues[] = array((float) $playerA['w_zufriedenheit'], (float) $playerB['w_zufriedenheit'], 'DESC');
			$compareValues[] = array((float) $playerA['w_staerke'], (float) $playerB['w_staerke'], 'DESC');
		} else {
			$compareValues[] = array((float) $playerA['w_staerke'], (float) $playerB['w_staerke'], 'DESC');
		}
		
		foreach ($compareValues as $values) {
			if ($values[0] == $values[1]) {
				continue;
			}
			if ($values[2] == 'ASC') {
				return ($values[0] < $values[1]) ? -1 : 1;
			}
			return ($values[0] > $values[1]) ? -1 : 1;
		}
		
		return ((int) $playerA['id'] <=> (int) $playerB['id']);
	}
	
	// CM23 | 2026-08-31 | Revision 2 | Task 1016
	private static function _getRecentCompetitiveMinutes(WebSoccer $websoccer, DbConnection $db, $teamId) {
		$matchIds = array();
		$matches = $db->querySelect('id', $websoccer->getConfig('db_prefix') . '_spiel',
				"berechnet = '1' AND spieltyp <> 'Freundschaft' AND (home_verein = %d OR gast_verein = %d) ORDER BY datum DESC, id DESC",
				array($teamId, $teamId), 5);
		while ($match = $matches->fetch_array()) {
			$matchIds[] = (int) $match['id'];
		}
		$matches->free();
		
		if (!count($matchIds)) {
			return array();
		}
		
		$placeholders = implode(',', array_fill(0, count($matchIds), '%d'));
		$parameters = array_merge(array($teamId), $matchIds);
		$minutes = array();
		$result = $db->querySelect('spieler_id,SUM(minuten_gespielt) AS recent_minutes',
				$websoccer->getConfig('db_prefix') . '_spiel_berechnung',
				'team_id = %d AND spiel_id IN (' . $placeholders . ') GROUP BY spieler_id', $parameters);
		while ($playerMinutes = $result->fetch_array()) {
			$minutes[(int) $playerMinutes['spieler_id']] = (int) $playerMinutes['recent_minutes'];
		}
		$result->free();
		
		return $minutes;
	}
	
	// CM23 | 2026-08-31 | Revision 2 | Task 1016
	private static function _getGenericPositions($genericPosition) {
		if ($genericPosition == 'Torwart') {
			return array('T');
		}
		if ($genericPosition == 'Abwehr') {
			return array('LV', 'IV', 'RV');
		}
		if ($genericPosition == 'Mittelfeld') {
			return array('RM', 'ZM', 'LM', 'RM', 'DM', 'OM');
		}
		return array('LS', 'MS', 'RS');
	}
	
	// CM23 | 2026-08-31 | Revision 2 | Task 1016
	private static function _getPositionFit($player, $targetPosition) {
		if ($player['position_main'] == $targetPosition) {
			return 0;
		}
		if ($player['position_second'] == $targetPosition) {
			return 1;
		}
		
		$targetArea = self::_getPositionArea($targetPosition);
		if (!strlen($player['position_main']) && in_array($targetPosition, self::_getGenericPositions($player['position']), TRUE)) {
			return 2;
		}
		if (self::_getPositionArea($player['position_main']) == $targetArea || self::_getPositionArea($player['position_second']) == $targetArea) {
			return 3;
		}
		return 4;
	}
	
	// CM23 | 2026-08-31 | Revision 2 | Task 1016
	private static function _getPositionArea($position) {
		if ($position == 'T') {
			return 'goalkeeper';
		}
		if (in_array($position, array('LV', 'IV', 'RV'), TRUE)) {
			return 'defense';
		}
		if (in_array($position, array('LM', 'ZM', 'RM', 'DM', 'OM'), TRUE)) {
			return 'midfield';
		}
		if (in_array($position, array('LS', 'MS', 'RS'), TRUE)) {
			return 'attack';
		}
		return '';
	}
	
}
?>
