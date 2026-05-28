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
 * Data service for rivalries and derby matches.
 */
class RivalriesDataService {

	private static $_derbyInfoCache = array();
	private static $_teamInfoCache = array();

	/**
	 * Returns derby information for the match, or NULL if the match is no derby.
	 * Manual rivalries have priority. Automatic rivalries require same country and
	 * a configured number of historical matches between both teams.
	 *
	 * @param WebSoccer $websoccer Application context.
	 * @param DbConnection $db DB connection.
	 * @param SimulationMatch $match Match model.
	 * @return array|null
	 */
	public static function getDerbyInfoForMatch(WebSoccer $websoccer, DbConnection $db, SimulationMatch $match) {

		$matchId = (int) $match->id;
		if (isset(self::$_derbyInfoCache[$matchId])) {
			return self::$_derbyInfoCache[$matchId];
		}

		self::$_derbyInfoCache[$matchId] = null;

		if (!self::isEnabled($websoccer)) {
			return null;
		}

		if ($match->type !== 'Ligaspiel' && $match->type !== 'Pokalspiel') {
			return null;
		}

		if ($match->homeTeam->isNationalTeam || $match->guestTeam->isNationalTeam) {
			return null;
		}

		$homeTeamId = (int) $match->homeTeam->id;
		$guestTeamId = (int) $match->guestTeam->id;

		if ($homeTeamId < 1 || $guestTeamId < 1 || $homeTeamId === $guestTeamId) {
			return null;
		}

		$dbPrefix = $websoccer->getConfig('db_prefix');

		$manualRivalry = self::getManualRivalry($websoccer, $db, $homeTeamId, $guestTeamId);
		if ($manualRivalry) {
			$info = array(
				'rivalry_id' => (int) $manualRivalry['id'],
				'home_team_id' => $homeTeamId,
				'guest_team_id' => $guestTeamId,
				'strength' => self::normalizeStrength($manualRivalry['strength']),
				'source' => 'manual',
				'history_matches' => self::countHistoricalMatches($websoccer, $db, $homeTeamId, $guestTeamId, $matchId)
			);
			self::ensureDerbyMatchRecord($websoccer, $db, $matchId, $info);
			self::$_derbyInfoCache[$matchId] = $info;
			return $info;
		}

		$homeInfo = self::getTeamInfo($websoccer, $db, $homeTeamId);
		$guestInfo = self::getTeamInfo($websoccer, $db, $guestTeamId);

		if (!$homeInfo || !$guestInfo) {
			return null;
		}

		if (!strlen((string) $homeInfo['country']) || !strlen((string) $guestInfo['country'])) {
			return null;
		}

		if ((string) $homeInfo['country'] !== (string) $guestInfo['country']) {
			return null;
		}

		$historyMatches = self::countHistoricalMatches($websoccer, $db, $homeTeamId, $guestTeamId, $matchId);
		$minHistoryMatches = (int) self::getOptionalConfig($websoccer, 'rivalries_auto_history_min_matches', 6);
		if ($minHistoryMatches < 1) {
			$minHistoryMatches = 6;
		}

		if ($historyMatches < $minHistoryMatches) {
			return null;
		}

		$strength = self::computeAutomaticStrength($historyMatches, $minHistoryMatches);
		$rivalryId = self::ensureAutomaticRivalry($websoccer, $db, $homeTeamId, $guestTeamId, $strength);

		$info = array(
			'rivalry_id' => $rivalryId,
			'home_team_id' => $homeTeamId,
			'guest_team_id' => $guestTeamId,
			'strength' => $strength,
			'source' => 'automatic',
			'history_matches' => $historyMatches
		);

		self::ensureDerbyMatchRecord($websoccer, $db, $matchId, $info);
		self::$_derbyInfoCache[$matchId] = $info;
		return $info;
	}

	/**
	 * Applies a morale bonus to both teams before kickoff.
	 *
	 * @param WebSoccer $websoccer Application context.
	 * @param DbConnection $db DB connection.
	 * @param SimulationMatch $match Match model.
	 */
	public static function applyPreMatchMoraleBonus(WebSoccer $websoccer, DbConnection $db, SimulationMatch $match) {

		$derbyInfo = self::getDerbyInfoForMatch($websoccer, $db, $match);
		if (!$derbyInfo) {
			return;
		}

		$bonus = self::getMoralePreMatchBonus($derbyInfo['strength']);
		$match->homeTeam->morale = min(100, max(0, (int) $match->homeTeam->morale + $bonus));
		$match->guestTeam->morale = min(100, max(0, (int) $match->guestTeam->morale + $bonus));
	}

	/**
	 * Returns the derby business factor. Example: strength 1 -> 1.10, strength 100 -> 1.50.
	 *
	 * @param WebSoccer $websoccer Application context.
	 * @param DbConnection $db DB connection.
	 * @param SimulationMatch $match Match model.
	 * @return float
	 */
	public static function getDerbyBusinessFactor(WebSoccer $websoccer, DbConnection $db, SimulationMatch $match) {
		$derbyInfo = self::getDerbyInfoForMatch($websoccer, $db, $match);
		if (!$derbyInfo) {
			return 1.00;
		}

		return 1 + (self::getBusinessBonusPercent($derbyInfo['strength']) / 100);
	}

	/**
	 * Returns the derby business bonus percentage.
	 *
	 * @param int $strength Rivalry strength.
	 * @return int
	 */
	public static function getBusinessBonusPercent($strength) {
		$strength = self::normalizeStrength($strength);
		return (int) round(10 + (($strength - 1) / 99) * 40);
	}

	/**
	 * Sets all ticket sales rates to 100%.
	 *
	 * @param TicketsComputedEvent $event Ticket computation event.
	 */
	public static function forceSoldOut(TicketsComputedEvent $event) {
		$event->rateStands = 1.00;
		$event->rateSeats = 1.00;
		$event->rateStandsGrand = 1.00;
		$event->rateSeatsGrand = 1.00;
		$event->rateVip = 1.00;
		$event->match->isSoldOut = TRUE;
	}

	/**
	 * Marks preview news as created for the match.
	 *
	 * @param WebSoccer $websoccer Application context.
	 * @param DbConnection $db DB connection.
	 * @param int $matchId Match ID.
	 */
	public static function markPreviewNewsCreated(WebSoccer $websoccer, DbConnection $db, $matchId) {
		$db->queryUpdate(
			array('pre_news_created' => '1'),
			$websoccer->getConfig('db_prefix') . '_derby_match',
			'match_id = %d',
			(int) $matchId
		);
	}

	/**
	 * Processes all after-match derby effects once.
	 *
	 * @param MatchCompletedEvent $event Match completed event.
	 */
	public static function processCompletedDerby(MatchCompletedEvent $event) {

		$match = $event->match;
		$derbyInfo = self::getDerbyInfoForMatch($event->websoccer, $event->db, $match);
		if (!$derbyInfo) {
			return;
		}

		$dbPrefix = $event->websoccer->getConfig('db_prefix');
		$result = $event->db->querySelect(
			'post_processed',
			$dbPrefix . '_derby_match',
			'match_id = %d',
			(int) $match->id,
			1
		);
		$record = $result->fetch_array();
		$result->free();

		if ($record && $record['post_processed'] === '1') {
			return;
		}

		$homeGoals = (int) $match->homeTeam->getGoals();
		$guestGoals = (int) $match->guestTeam->getGoals();
		$winnerTeamId = null;
		$loserTeamId = null;

		if ($homeGoals > $guestGoals) {
			$winnerTeamId = (int) $match->homeTeam->id;
			$loserTeamId = (int) $match->guestTeam->id;
		} elseif ($guestGoals > $homeGoals) {
			$winnerTeamId = (int) $match->guestTeam->id;
			$loserTeamId = (int) $match->homeTeam->id;
		}

		if ($winnerTeamId !== null) {
			self::applyManagedTeamPressure($event->websoccer, $event->db, $winnerTeamId, $derbyInfo['strength'], TRUE);
			self::applyManagedTeamPressure($event->websoccer, $event->db, $loserTeamId, $derbyInfo['strength'], FALSE);
			self::changeTeamPlayerSatisfaction($event->websoccer, $event->db, $winnerTeamId, self::getWinnerSatisfactionBonus($derbyInfo['strength']));
			self::changeTeamPlayerSatisfaction($event->websoccer, $event->db, $loserTeamId, 0 - self::getLoserSatisfactionPenalty($derbyInfo['strength']));
			self::awardDerbyWinBadge($event->websoccer, $event->db, $winnerTeamId);
		} else {
			self::applyManagedTeamDrawEffect($event->websoccer, $event->db, (int) $match->homeTeam->id, $derbyInfo['strength']);
			self::applyManagedTeamDrawEffect($event->websoccer, $event->db, (int) $match->guestTeam->id, $derbyInfo['strength']);
			self::changeTeamPlayerSatisfaction($event->websoccer, $event->db, (int) $match->homeTeam->id, self::getDrawSatisfactionBonus($derbyInfo['strength']));
			self::changeTeamPlayerSatisfaction($event->websoccer, $event->db, (int) $match->guestTeam->id, self::getDrawSatisfactionBonus($derbyInfo['strength']));
		}

		self::creditDerbyBuildingIncome($event->websoccer, $event->db, $match, $derbyInfo);

		$updateColumns = array(
			'post_processed' => '1',
			'winner_team_id' => ($winnerTeamId !== null) ? $winnerTeamId : '',
			'completed_date' => $event->websoccer->getNowAsTimestamp()
		);

		$event->db->queryUpdate(
			$updateColumns,
			$dbPrefix . '_derby_match',
			'match_id = %d',
			(int) $match->id
		);
	}

	/**
	 * Returns whether preview news has already been created.
	 *
	 * @param WebSoccer $websoccer Application context.
	 * @param DbConnection $db DB connection.
	 * @param int $matchId Match ID.
	 * @return bool
	 */
	public static function hasPreviewNewsCreated(WebSoccer $websoccer, DbConnection $db, $matchId) {
		$result = $db->querySelect(
			'pre_news_created',
			$websoccer->getConfig('db_prefix') . '_derby_match',
			'match_id = %d',
			(int) $matchId,
			1
		);
		$record = $result->fetch_array();
		$result->free();

		return ($record && $record['pre_news_created'] === '1');
	}

	private static function isEnabled(WebSoccer $websoccer) {
		$enabled = self::getOptionalConfig($websoccer, 'rivalries_enabled', '1');
		return ($enabled === null || $enabled === '' || $enabled === '1' || $enabled === 1 || $enabled === TRUE);
	}

	private static function getOptionalConfig(WebSoccer $websoccer, $name, $defaultValue) {
		try {
			return $websoccer->getConfig($name);
		} catch (Exception $e) {
			return $defaultValue;
		}
	}

	private static function getManualRivalry(WebSoccer $websoccer, DbConnection $db, $team1Id, $team2Id) {
		$dbPrefix = $websoccer->getConfig('db_prefix');
		$result = $db->querySelect(
			'id, strength',
			$dbPrefix . '_rivalry',
			"active = '1' AND manual = '1' AND ((team1_id = %d AND team2_id = %d) OR (team1_id = %d AND team2_id = %d)) ORDER BY strength DESC, id ASC",
			array($team1Id, $team2Id, $team2Id, $team1Id),
			1
		);
		$rivalry = $result->fetch_array();
		$result->free();

		return $rivalry ? $rivalry : null;
	}

	private static function getTeamInfo(WebSoccer $websoccer, DbConnection $db, $teamId) {
		$teamId = (int) $teamId;
		if (isset(self::$_teamInfoCache[$teamId])) {
			return self::$_teamInfoCache[$teamId];
		}

		$dbPrefix = $websoccer->getConfig('db_prefix');
		$fromTable = $dbPrefix . '_verein AS T';
		$fromTable .= ' LEFT JOIN ' . $dbPrefix . '_liga AS L ON L.id = T.liga_id';

		$columns = array(
			'T.id' => 'team_id',
			'T.name' => 'name',
			'T.user_id' => 'user_id',
			'T.board_satisfaction' => 'board_satisfaction',
			'L.land' => 'country'
		);

		$result = $db->querySelect($columns, $fromTable, "T.id = %d AND T.status = '1'", $teamId, 1);
		$team = $result->fetch_array();
		$result->free();

		self::$_teamInfoCache[$teamId] = $team ? $team : null;
		return self::$_teamInfoCache[$teamId];
	}

	private static function countHistoricalMatches(WebSoccer $websoccer, DbConnection $db, $team1Id, $team2Id, $excludeMatchId = 0) {
		$dbPrefix = $websoccer->getConfig('db_prefix');
		$whereCondition = "berechnet = '1' AND spieltyp IN ('Ligaspiel','Pokalspiel')";
		$whereCondition .= ' AND ((home_verein = %d AND gast_verein = %d) OR (home_verein = %d AND gast_verein = %d))';
		$parameters = array($team1Id, $team2Id, $team2Id, $team1Id);

		if ((int) $excludeMatchId > 0) {
			$whereCondition .= ' AND id != %d';
			$parameters[] = (int) $excludeMatchId;
		}

		$result = $db->querySelect('COUNT(*) AS matches', $dbPrefix . '_spiel', $whereCondition, $parameters, 1);
		$row = $result->fetch_array();
		$result->free();

		return ($row && isset($row['matches'])) ? (int) $row['matches'] : 0;
	}

	private static function computeAutomaticStrength($historyMatches, $minHistoryMatches) {
		$historyMatches = max(0, (int) $historyMatches);
		$minHistoryMatches = max(1, (int) $minHistoryMatches);

		// Reaching the threshold starts as a noticeable rivalry. Four times the
		// threshold reaches maximum strength.
		$strength = (int) round(($historyMatches / ($minHistoryMatches * 4)) * 100);
		return self::normalizeStrength(max(25, $strength));
	}

	private static function ensureAutomaticRivalry(WebSoccer $websoccer, DbConnection $db, $team1Id, $team2Id, $strength) {
		$dbPrefix = $websoccer->getConfig('db_prefix');
		$result = $db->querySelect(
			'id, manual, strength',
			$dbPrefix . '_rivalry',
			"((team1_id = %d AND team2_id = %d) OR (team1_id = %d AND team2_id = %d)) ORDER BY manual DESC, strength DESC, id ASC",
			array($team1Id, $team2Id, $team2Id, $team1Id),
			1
		);
		$rivalry = $result->fetch_array();
		$result->free();

		if ($rivalry) {
			if ($rivalry['manual'] !== '1' && (int) $rivalry['strength'] !== (int) $strength) {
				$db->queryUpdate(
					array('strength' => $strength, 'updated_date' => $websoccer->getNowAsTimestamp(), 'active' => '1'),
					$dbPrefix . '_rivalry',
					'id = %d',
					(int) $rivalry['id']
				);
			}
			return (int) $rivalry['id'];
		}

		$db->queryInsert(
			array(
				'team1_id' => $team1Id,
				'team2_id' => $team2Id,
				'strength' => self::normalizeStrength($strength),
				'manual' => '0',
				'active' => '1',
				'created_date' => $websoccer->getNowAsTimestamp(),
				'updated_date' => $websoccer->getNowAsTimestamp()
			),
			$dbPrefix . '_rivalry'
		);

		return (int) $db->getLastInsertedId();
	}

	private static function ensureDerbyMatchRecord(WebSoccer $websoccer, DbConnection $db, $matchId, $derbyInfo) {
		$dbPrefix = $websoccer->getConfig('db_prefix');
		$result = $db->querySelect('match_id', $dbPrefix . '_derby_match', 'match_id = %d', (int) $matchId, 1);
		$existing = $result->fetch_array();
		$result->free();

		$bonusPercent = self::getBusinessBonusPercent($derbyInfo['strength']);
		if ($existing) {
			$db->queryUpdate(
				array(
					'rivalry_id' => (isset($derbyInfo['rivalry_id']) && $derbyInfo['rivalry_id']) ? (int) $derbyInfo['rivalry_id'] : '',
					'home_team_id' => (int) $derbyInfo['home_team_id'],
					'guest_team_id' => (int) $derbyInfo['guest_team_id'],
					'strength' => self::normalizeStrength($derbyInfo['strength']),
					'detection_source' => $derbyInfo['source'],
					'business_bonus_percent' => $bonusPercent
				),
				$dbPrefix . '_derby_match',
				'match_id = %d',
				(int) $matchId
			);
			return;
		}

		$db->queryInsert(
			array(
				'match_id' => (int) $matchId,
				'rivalry_id' => (isset($derbyInfo['rivalry_id']) && $derbyInfo['rivalry_id']) ? (int) $derbyInfo['rivalry_id'] : '',
				'home_team_id' => (int) $derbyInfo['home_team_id'],
				'guest_team_id' => (int) $derbyInfo['guest_team_id'],
				'strength' => self::normalizeStrength($derbyInfo['strength']),
				'detection_source' => $derbyInfo['source'],
				'business_bonus_percent' => $bonusPercent,
				'created_date' => $websoccer->getNowAsTimestamp()
			),
			$dbPrefix . '_derby_match'
		);
	}

	private static function normalizeStrength($strength) {
		return min(100, max(1, (int) $strength));
	}

	private static function getMoralePreMatchBonus($strength) {
		return (int) round(2 + (self::getBusinessBonusPercent($strength) / 10)); // 3..7
	}

	private static function getWinnerSatisfactionBonus($strength) {
		return (int) round(1 + (self::getBusinessBonusPercent($strength) / 10)); // 2..6
	}

	private static function getLoserSatisfactionPenalty($strength) {
		return (int) round(1 + (self::getBusinessBonusPercent($strength) / 15)); // 2..4
	}

	private static function getDrawSatisfactionBonus($strength) {
		return (self::getBusinessBonusPercent($strength) >= 35) ? 2 : 1;
	}

	private static function changeTeamPlayerSatisfaction(WebSoccer $websoccer, DbConnection $db, $teamId, $change) {
		$teamId = (int) $teamId;
		$change = (int) $change;

		if ($teamId < 1 || $change === 0) {
			return;
		}

		$dbPrefix = $websoccer->getConfig('db_prefix');
		$db->executeQuery(
			'UPDATE ' . $dbPrefix . '_spieler '
			. 'SET w_zufriedenheit = LEAST(100, GREATEST(1, CAST(w_zufriedenheit AS DECIMAL(6,2)) + ' . $change . ')) '
			. 'WHERE verein_id = ' . $teamId . " AND status = '1'"
		);
	}

	private static function applyManagedTeamPressure(WebSoccer $websoccer, DbConnection $db, $teamId, $strength, $isWinner) {
		$team = self::getTeamInfo($websoccer, $db, $teamId);
		if (!$team || (int) $team['user_id'] < 1) {
			return;
		}

		$bonusPercent = self::getBusinessBonusPercent($strength);
		$fanChange = (int) round(1 + ($bonusPercent / ($isWinner ? 10 : 15)));
		$boardChange = (int) round(2 + ($bonusPercent / ($isWinner ? 8 : 10)));

		if (!$isWinner) {
			$fanChange = 0 - $fanChange;
			$boardChange = 0 - $boardChange;
		}

		self::changeUserFanPopularity($websoccer, $db, (int) $team['user_id'], $fanChange);
		self::changeBoardSatisfaction($websoccer, $db, $teamId, $boardChange);
	}

	private static function applyManagedTeamDrawEffect(WebSoccer $websoccer, DbConnection $db, $teamId, $strength) {
		$team = self::getTeamInfo($websoccer, $db, $teamId);
		if (!$team || (int) $team['user_id'] < 1) {
			return;
		}

		$change = (self::getBusinessBonusPercent($strength) >= 35) ? 2 : 1;
		self::changeUserFanPopularity($websoccer, $db, (int) $team['user_id'], $change);
		self::changeBoardSatisfaction($websoccer, $db, $teamId, $change);
	}

	private static function changeUserFanPopularity(WebSoccer $websoccer, DbConnection $db, $userId, $change) {
		$result = $db->querySelect('fanbeliebtheit', $websoccer->getConfig('db_prefix') . '_user', 'id = %d', (int) $userId, 1);
		$user = $result->fetch_array();
		$result->free();

		if (!$user) {
			return;
		}

		$newValue = min(100, max(1, (int) $user['fanbeliebtheit'] + (int) $change));
		$db->queryUpdate(array('fanbeliebtheit' => $newValue), $websoccer->getConfig('db_prefix') . '_user', 'id = %d', (int) $userId);
	}

	private static function changeBoardSatisfaction(WebSoccer $websoccer, DbConnection $db, $teamId, $change) {
		$result = $db->querySelect('board_satisfaction', $websoccer->getConfig('db_prefix') . '_verein', 'id = %d', (int) $teamId, 1);
		$team = $result->fetch_array();
		$result->free();

		if (!$team) {
			return;
		}

		$newValue = min(100, max(0, (int) $team['board_satisfaction'] + (int) $change));
		$db->queryUpdate(array('board_satisfaction' => $newValue), $websoccer->getConfig('db_prefix') . '_verein', 'id = %d', (int) $teamId);
		self::$_teamInfoCache[(int) $teamId] = null;
	}

	private static function awardDerbyWinBadge(WebSoccer $websoccer, DbConnection $db, $winnerTeamId) {
		$team = self::getTeamInfo($websoccer, $db, $winnerTeamId);
		if (!$team || (int) $team['user_id'] < 1) {
			return;
		}

		$result = $db->querySelect(
			'COUNT(*) AS wins',
			$websoccer->getConfig('db_prefix') . '_derby_match',
			"post_processed = '1' AND winner_team_id = %d",
			(int) $winnerTeamId,
			1
		);
		$row = $result->fetch_array();
		$result->free();

		$wins = ($row && isset($row['wins'])) ? (int) $row['wins'] : 0;

		// The current match is not yet marked as post_processed at this point.
		$wins++;
		BadgesDataService::awardBadgeIfApplicable(
			$websoccer,
			$db,
			(int) $team['user_id'],
			'derby_wins',
			$wins,
			(int) $winnerTeamId,
			null,
			array('wins' => $wins),
			TRUE
		);
	}

	private static function creditDerbyBuildingIncome(WebSoccer $websoccer, DbConnection $db, SimulationMatch $match, $derbyInfo) {

		if ($match->homeTeam->isNationalTeam || $match->isAtForeignStadium) {
			return;
		}

		$homeTeamId = (int) $match->homeTeam->id;
		$dbPrefix = $websoccer->getConfig('db_prefix');
		$fromTable = $dbPrefix . '_buildings_of_team AS BT';
		$fromTable .= ' INNER JOIN ' . $dbPrefix . '_stadiumbuilding AS B ON B.id = BT.building_id';

		$result = $db->querySelect(
			'SUM(B.effect_income) AS income_sum',
			$fromTable,
			'BT.team_id = %d AND BT.construction_deadline < %d',
			array($homeTeamId, $websoccer->getNowAsTimestamp()),
			1
		);
		$row = $result->fetch_array();
		$result->free();

		$buildingIncome = ($row && isset($row['income_sum'])) ? (int) $row['income_sum'] : 0;
		if ($buildingIncome <= 0) {
			return;
		}

		$extraIncome = (int) round($buildingIncome * (self::getBusinessBonusPercent($derbyInfo['strength']) / 100));
		if ($extraIncome <= 0) {
			return;
		}

		BankAccountDataService::creditAmount(
			$websoccer,
			$db,
			$homeTeamId,
			$extraIncome,
			'rivalries_derby_building_income_subject',
			$websoccer->getConfig('projectname')
		);
	}
}
?>
