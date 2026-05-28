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
 * Data service for random events.
 */
class RandomEventsDataService {
	
	/**
	 * Checks whether a new random event is due for the specified user and executes it.
	 * 
	 * @param WebSoccer $websoccer Application context.
	 * @param DbConnection $db DB connection.
	 * @param int $userId ID of user.
	 */
	public static function createEventIfRequired(WebSoccer $websoccer, DbConnection $db, $userId) {
		
		// user must manage at least one team
		$result = $db->querySelect('id', $websoccer->getConfig('db_prefix') . '_verein', 'user_id = %d AND status = \'1\'', $userId);
		$clubIds = array();
		while ($club = $result->fetch_array()) {
			$clubIds[] = (int) $club['id'];
		}
		$result->free();
		if (!count($clubIds)) {
			return;
		}
		
		// do not create an event within first 24 hours of registration
		$now = $websoccer->getNowAsTimestamp();
		$result = $db->querySelect('datum_anmeldung', $websoccer->getConfig('db_prefix') . '_user',
				'id = %d', $userId, 1);
		$user = $result->fetch_array();
		$result->free();
		if ($user && $user['datum_anmeldung'] >= ($now - 24 * 3600)) {
			return;
		}
		
		// expire pending chain events before trying to create a new one
		foreach ($clubIds as $managedClubId) {
			self::expireOpenChainEvents($websoccer, $db, $managedClubId);
		}
		
		// select randomly one of the user's teams
		$clubId = $clubIds[array_rand($clubIds)];
		
		// new chain events are additive. If one was created, do not create an instant event in the same request.
		if (self::_createChainEventIfRequired($websoccer, $db, $userId, $clubId)) {
			return;
		}
		
		// old instant random events remain unchanged and can be disabled with the existing interval setting.
		$eventsInterval = (int) self::_getConfigValue($websoccer, 'randomevents_interval_days', 10);
		if ($eventsInterval < 1) {
			return;
		}
		
		// is a new event due? check occurrence of latest event for user
		$result = $db->querySelect('occurrence_date', $websoccer->getConfig('db_prefix') . '_randomevent_occurrence',
				'user_id = %d ORDER BY occurrence_date DESC', $userId, 1);
		$latestEvent = $result->fetch_array();
		$result->free();
		if ($latestEvent && $latestEvent['occurrence_date'] >= ($now - 24 * 3600 * $eventsInterval)) {
			return;
		}
		
		// create and execute an event occurrence
		self::_createAndExecuteEvent($websoccer, $db, $userId, $clubId);
		
		// delete old occurrences. Delete those which are older than 10 intervals.
		// In general, only the latest 10 occurrences should remain.
		if ($latestEvent) {
			$deleteBoundary = $now - 24 * 3600 * 10 * $eventsInterval;
			$db->queryDelete($websoccer->getConfig('db_prefix') . '_randomevent_occurrence', 
					'user_id = %d AND occurrence_date < %d', array($userId, $deleteBoundary));
		}
	}

	/**
	 * Returns all open event-chain occurrences for the selected team.
	 *
	 * @param WebSoccer $websoccer Application context.
	 * @param DbConnection $db DB connection.
	 * @param I18n $i18n I18n context.
	 * @param int $userId User ID.
	 * @param int $teamId Team ID.
	 * @return array
	 */
	public static function getOpenChainEvents(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $userId, $teamId) {
		self::expireOpenChainEvents($websoccer, $db, $teamId);

		$columns = array(
			'O.id' => 'id',
			'O.chain_id' => 'chain_id',
			'O.player_id' => 'player_id',
			'O.created_youthplayer_id' => 'created_youthplayer_id',
			'O.selected_choice_id' => 'selected_choice_id',
			'O.created_date' => 'created_date',
			'O.created_matchday' => 'created_matchday',
			'O.expires_matchday' => 'expires_matchday',
			'O.status' => 'status',
			'O.context_data' => 'context_data',
			'C.event_key' => 'event_key',
			'C.event_type' => 'event_type',
			'C.title' => 'title',
			'C.message' => 'message',
			'SC.label' => 'selected_choice_label'
		);
		$fromTable = $websoccer->getConfig('db_prefix') . '_randomevent_chain_occurrence AS O';
		$fromTable .= ' INNER JOIN ' . $websoccer->getConfig('db_prefix') . '_randomevent_chain AS C ON C.id = O.chain_id';
		$fromTable .= ' LEFT JOIN ' . $websoccer->getConfig('db_prefix') . '_randomevent_chain_choice AS SC ON SC.id = O.selected_choice_id';

		$result = $db->querySelect($columns, $fromTable,
			'O.user_id = %d AND O.team_id = %d AND O.status = \'open\' ORDER BY O.created_date DESC',
			array($userId, $teamId));

		$events = array();
		while ($row = $result->fetch_array()) {
			$context = self::_decodeContext($row['context_data']);
			$row['context'] = $context;
			$row['title_text'] = self::_translateAndReplace($i18n, $row['title'], $context);
			$row['message_text'] = self::_translateAndReplace($i18n, $row['message'], $context);
			$row['selected_choice_text'] = self::_translateAndReplace($i18n, $row['selected_choice_label'], $context);
			$row['choices'] = array();

			if (!$row['selected_choice_id']) {
				$row['choices'] = self::getChoicesForOccurrence($websoccer, $db, $i18n, (int) $row['id']);
			}

			$events[] = $row;
		}
		$result->free();

		return $events;
	}

	/**
	 * Returns available choices for an open occurrence.
	 *
	 * @param WebSoccer $websoccer Application context.
	 * @param DbConnection $db DB connection.
	 * @param I18n $i18n I18n context.
	 * @param int $occurrenceId Occurrence ID.
	 * @return array
	 */
	public static function getChoicesForOccurrence(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $occurrenceId) {
		$result = $db->querySelect('chain_id, context_data',
			$websoccer->getConfig('db_prefix') . '_randomevent_chain_occurrence',
			'id = %d AND status = \'open\'', (int) $occurrenceId, 1);
		$occurrence = $result->fetch_array();
		$result->free();
		if (!$occurrence) {
			return array();
		}

		$context = self::_decodeContext($occurrence['context_data']);
		$result = $db->querySelect('*',
			$websoccer->getConfig('db_prefix') . '_randomevent_chain_choice',
			'chain_id = %d ORDER BY sort_order ASC, id ASC', (int) $occurrence['chain_id']);

		$choices = array();
		while ($choice = $result->fetch_array()) {
			$choice['label_text'] = self::_translateAndReplace($i18n, $choice['label'], $context);
			$choice['description_text'] = self::_translateAndReplace($i18n, $choice['description'], $context);
			$choices[] = $choice;
		}
		$result->free();

		return $choices;
	}

	/**
	 * Applies a selected chain choice.
	 *
	 * @param WebSoccer $websoccer Application context.
	 * @param DbConnection $db DB connection.
	 * @param int $userId User ID.
	 * @param int $teamId Team ID.
	 * @param int $occurrenceId Occurrence ID.
	 * @param int $choiceId Choice ID.
	 * @param bool $expired Whether this is an automatic expiry resolution.
	 */
	public static function applyChainChoice(WebSoccer $websoccer, DbConnection $db, $userId, $teamId, $occurrenceId, $choiceId, $expired = FALSE) {
		$occurrence = self::_getOccurrence($websoccer, $db, $occurrenceId, $userId, $teamId);
		if (!$occurrence) {
			throw new Exception('error_page_not_found');
		}
		if ($occurrence['status'] !== 'open') {
			throw new Exception('randomevent_chain_already_resolved');
		}
		if (!$expired && $occurrence['selected_choice_id']) {
			throw new Exception('randomevent_chain_already_selected');
		}

		$choice = self::_getChoice($websoccer, $db, $choiceId, (int) $occurrence['chain_id']);
		if (!$choice) {
			throw new Exception('error_page_not_found');
		}

		$context = self::_decodeContext($occurrence['context_data']);
		self::_applyChoiceEffects($websoccer, $db, $occurrence, $choice, $context);

		$columns = array(
			'selected_choice_id' => (int) $choice['id']
		);

		if ($choice['keep_open'] === '1' && !$expired) {
			// Risk choice: keep the incident active until expiry so plug-ins can apply the temporary effect.
			$db->queryUpdate($columns,
				$websoccer->getConfig('db_prefix') . '_randomevent_chain_occurrence',
				'id = %d', (int) $occurrence['id']);
			return;
		}

		$columns['status'] = ($expired) ? 'expired' : 'resolved';
		$columns['resolved_date'] = $websoccer->getNowAsTimestamp();
		$db->queryUpdate($columns,
			$websoccer->getConfig('db_prefix') . '_randomevent_chain_occurrence',
			'id = %d', (int) $occurrence['id']);
	}

	/**
	 * Expires open chain events whose expiry matchday has been reached.
	 *
	 * @param WebSoccer $websoccer Application context.
	 * @param DbConnection $db DB connection.
	 * @param int $teamId Team ID.
	 */
	public static function expireOpenChainEvents(WebSoccer $websoccer, DbConnection $db, $teamId) {
		$currentMatchday = MatchesDataService::getMatchdayNumberOfTeam($websoccer, $db, $teamId);
		if ($currentMatchday < 1) {
			return;
		}

		$columns = array(
			'O.id' => 'id',
			'O.user_id' => 'user_id',
			'O.team_id' => 'team_id',
			'O.chain_id' => 'chain_id'
		);
		$fromTable = $websoccer->getConfig('db_prefix') . '_randomevent_chain_occurrence AS O';
		$result = $db->querySelect($columns, $fromTable,
			'O.team_id = %d AND O.status = \'open\' AND O.expires_matchday <= %d',
			array((int) $teamId, (int) $currentMatchday));

		$items = array();
		while ($item = $result->fetch_array()) {
			$items[] = $item;
		}
		$result->free();

		foreach ($items as $item) {
			$defaultChoice = self::_getDefaultChoice($websoccer, $db, (int) $item['chain_id']);
			if ($defaultChoice) {
				self::applyChainChoice($websoccer, $db, (int) $item['user_id'], (int) $item['team_id'],
					(int) $item['id'], (int) $defaultChoice['id'], TRUE);
			} else {
				$db->queryUpdate(array('status' => 'expired', 'resolved_date' => $websoccer->getNowAsTimestamp()),
					$websoccer->getConfig('db_prefix') . '_randomevent_chain_occurrence', 'id = %d', (int) $item['id']);
			}
		}
	}

	/**
	 * Returns the combined attendance penalty for unresolved stadium damage events.
	 * The result is a negative percent value, e.g. -25.
	 *
	 * @param WebSoccer $websoccer Application context.
	 * @param DbConnection $db DB connection.
	 * @param int $teamId Team ID.
	 * @return int
	 */
	public static function getOpenStadiumDamageAttendancePenalty(WebSoccer $websoccer, DbConnection $db, $teamId) {
		$fromTable = $websoccer->getConfig('db_prefix') . '_randomevent_chain_occurrence AS O';
		$fromTable .= ' INNER JOIN ' . $websoccer->getConfig('db_prefix') . '_randomevent_chain AS C ON C.id = O.chain_id';
		$fromTable .= ' INNER JOIN ' . $websoccer->getConfig('db_prefix') . '_randomevent_chain_choice AS CH ON CH.id = O.selected_choice_id';

		$result = $db->querySelect('SUM(CH.effect_stadium_attendance) AS penalty', $fromTable,
			'O.team_id = %d AND O.status = \'open\' AND C.event_type = \'stadium_damage\' AND CH.keep_open = \'1\'',
			(int) $teamId, 1);
		$row = $result->fetch_array();
		$result->free();

		if ($row && (int) $row['penalty'] < 0) {
			return max(-90, (int) $row['penalty']);
		}

		return 0;
	}

	private static function _createChainEventIfRequired(WebSoccer $websoccer, DbConnection $db, $userId, $clubId) {
		if (!self::_isConfigEnabled($websoccer, 'randomevent_chain_enabled', TRUE)) {
			return FALSE;
		}

		// one active chain event per team
		$result = $db->querySelect('COUNT(*) AS hits',
			$websoccer->getConfig('db_prefix') . '_randomevent_chain_occurrence',
			'team_id = %d AND status = \'open\'', (int) $clubId, 1);
		$row = $result->fetch_array();
		$result->free();
		if ($row && (int) $row['hits'] > 0) {
			return FALSE;
		}

		$currentMatchday = MatchesDataService::getMatchdayNumberOfTeam($websoccer, $db, $clubId);
		if ($currentMatchday < 1) {
			return FALSE;
		}

		// only one chain roll per matchday and team, independent of whether the manager refreshes the office page.
		$result = $db->querySelect('COUNT(*) AS hits',
			$websoccer->getConfig('db_prefix') . '_randomevent_chain_roll',
			'team_id = %d AND matchday = %d', array((int) $clubId, (int) $currentMatchday), 1);
		$row = $result->fetch_array();
		$result->free();
		if ($row && (int) $row['hits'] > 0) {
			return FALSE;
		}

		$db->queryInsert(array(
			'team_id' => (int) $clubId,
			'matchday' => (int) $currentMatchday,
			'roll_date' => $websoccer->getNowAsTimestamp()
		), $websoccer->getConfig('db_prefix') . '_randomevent_chain_roll');

		$chance = (int) self::_getConfigValue($websoccer, 'randomevent_chain_chance_percent', 15);
		$chance = max(0, min(100, $chance));
		if ($chance < 1 || mt_rand(1, 100) > $chance) {
			return FALSE;
		}

		$event = self::_selectAvailableChainEvent($websoccer, $db, $clubId);
		if (!$event) {
			return FALSE;
		}

		$contextInfo = self::_buildEventContext($websoccer, $db, $event, $clubId);
		if (!$contextInfo) {
			return FALSE;
		}

		$expiresAfter = (int) self::_getConfigValue($websoccer, 'randomevent_chain_expire_matchdays', 2);
		$expiresAfter = max(1, $expiresAfter);

		$columns = array(
			'user_id' => (int) $userId,
			'team_id' => (int) $clubId,
			'chain_id' => (int) $event['id'],
			'player_id' => ($contextInfo['player_id']) ? (int) $contextInfo['player_id'] : null,
			'created_youthplayer_id' => null,
			'created_date' => $websoccer->getNowAsTimestamp(),
			'created_matchday' => (int) $currentMatchday,
			'expires_matchday' => (int) $currentMatchday + $expiresAfter,
			'status' => 'open',
			'context_data' => json_encode($contextInfo['context'])
		);

		$db->queryInsert($columns, $websoccer->getConfig('db_prefix') . '_randomevent_chain_occurrence');
		$occurrenceId = $db->getLastInsertedId();

		$db->queryUpdate(array('created_occurrence_id' => (int) $occurrenceId),
			$websoccer->getConfig('db_prefix') . '_randomevent_chain_roll',
			'team_id = %d AND matchday = %d', array((int) $clubId, (int) $currentMatchday));

		NotificationsDataService::createNotification($websoccer, $db, $userId,
			$event['title'], $contextInfo['context'],
			'randomevent_chain', 'random-events', 'id=' . $occurrenceId, $clubId);

		return TRUE;
	}

	private static function _selectAvailableChainEvent(WebSoccer $websoccer, DbConnection $db, $clubId) {
		$result = $db->querySelect('*', $websoccer->getConfig('db_prefix') . '_randomevent_chain',
			'active = \'1\' AND weight > 0 ORDER BY RAND()', null, 100);

		$events = array();
		while ($event = $result->fetch_array()) {
			for ($i = 1; $i <= (int) $event['weight']; $i++) {
				$events[] = $event;
			}
		}
		$result->free();

		if (!count($events)) {
			return null;
		}

		shuffle($events);
		foreach ($events as $event) {
			if (self::_canCreateEventType($websoccer, $db, $clubId, $event['event_type'])) {
				return $event;
			}
		}

		return null;
	}

	private static function _canCreateEventType(WebSoccer $websoccer, DbConnection $db, $clubId, $eventType) {
		switch ($eventType) {
			case 'player_unhappy':
				return (self::_selectPlayerForUnhappyEvent($websoccer, $db, $clubId) != null);

			case 'sponsor_scandal':
				return (self::_getSponsorNameForTeam($websoccer, $db, $clubId) != null);

			case 'youth_discovered':
				return TRUE;

			case 'stadium_damage':
				$result = $db->querySelect('stadion_id', $websoccer->getConfig('db_prefix') . '_verein', 'id = %d AND stadion_id IS NOT NULL', (int) $clubId, 1);
				$row = $result->fetch_array();
				$result->free();
				return ($row != null);
		}

		return FALSE;
	}

	private static function _buildEventContext(WebSoccer $websoccer, DbConnection $db, $event, $clubId) {
		$context = array();
		$playerId = null;

		$teamName = self::_getTeamName($websoccer, $db, $clubId);
		$context['teamname'] = $teamName;

		switch ($event['event_type']) {
			case 'player_unhappy':
				$player = self::_selectPlayerForUnhappyEvent($websoccer, $db, $clubId);
				if (!$player) {
					return null;
				}
				$playerId = (int) $player['id'];
				$context['playername'] = self::_getPlayerName($player);
				$context['personality'] = $player['personality'];
				break;

			case 'sponsor_scandal':
				$sponsorName = self::_getSponsorNameForTeam($websoccer, $db, $clubId);
				if (!$sponsorName) {
					return null;
				}
				$context['sponsorname'] = $sponsorName;
				break;

			case 'youth_discovered':
				$youth = self::_generateYouthCandidate($websoccer, $db, $clubId);
				$context = array_merge($context, $youth);
				break;

			case 'stadium_damage':
				break;
		}

		return array('player_id' => $playerId, 'context' => $context);
	}

	private static function _applyChoiceEffects(WebSoccer $websoccer, DbConnection $db, $occurrence, $choice, $context) {
		$subject = self::_fallbackText($choice['label']);
		$sender = $websoccer->getConfig('projectname');

		$amount = (int) $choice['effect_money_amount'];
		if ($amount > 0) {
			BankAccountDataService::creditAmount($websoccer, $db, (int) $occurrence['team_id'], $amount, $subject, $sender);
		} elseif ($amount < 0) {
			BankAccountDataService::debitAmount($websoccer, $db, (int) $occurrence['team_id'], abs($amount), $subject, $sender);
		}

		if ((int) $occurrence['player_id'] > 0) {
			self::_applyPlayerChoiceEffects($websoccer, $db, (int) $occurrence['player_id'], $choice);
		}

		if ((int) $choice['effect_board_satisfaction'] != 0) {
			self::_changeTeamNumericValue($websoccer, $db, (int) $occurrence['team_id'], 'board_satisfaction', (int) $choice['effect_board_satisfaction']);
		}

		if ((int) $choice['effect_fanpopularity'] != 0) {
			self::_changeUserNumericValue($websoccer, $db, (int) $occurrence['user_id'], 'fanbeliebtheit', (int) $choice['effect_fanpopularity']);
		}

		if ($choice['create_youthplayer'] === '1' && isset($context['youth_firstname'])) {
			$createdPlayerId = self::_createYouthPlayerFromContext($websoccer, $db, (int) $occurrence['team_id'], $context);
			$db->queryUpdate(array('created_youthplayer_id' => $createdPlayerId),
				$websoccer->getConfig('db_prefix') . '_randomevent_chain_occurrence', 'id = %d', (int) $occurrence['id']);
		}
	}

	private static function _applyPlayerChoiceEffects(WebSoccer $websoccer, DbConnection $db, $playerId, $choice) {
		$result = $db->querySelect('id, w_zufriedenheit, w_frische, w_kondition, verletzt, gesperrt',
			$websoccer->getConfig('db_prefix') . '_spieler', 'id = %d', (int) $playerId, 1);
		$player = $result->fetch_array();
		$result->free();
		if (!$player) {
			return;
		}

		$columns = array();
		if ((int) $choice['effect_player_happiness'] != 0) {
			$columns['w_zufriedenheit'] = max(1, min(100, (int) $player['w_zufriedenheit'] + (int) $choice['effect_player_happiness']));
		}
		if ((int) $choice['effect_player_fitness'] != 0) {
			$columns['w_frische'] = max(1, min(100, (int) $player['w_frische'] + (int) $choice['effect_player_fitness']));
		}
		if ((int) $choice['effect_player_stamina'] != 0) {
			$columns['w_kondition'] = max(1, min(100, (int) $player['w_kondition'] + (int) $choice['effect_player_stamina']));
		}
		if ((int) $choice['effect_injured_matches'] > 0) {
			$columns['verletzt'] = max((int) $player['verletzt'], (int) $choice['effect_injured_matches']);
		}
		if ((int) $choice['effect_blocked_matches'] > 0) {
			$columns['gesperrt'] = max((int) $player['gesperrt'], (int) $choice['effect_blocked_matches']);
		}
		if ($choice['set_player_transfermarket'] === '1') {
			$columns['transfermarkt'] = '1';
			$columns['transfer_start'] = time();
		}

		if (count($columns)) {
			$db->queryUpdate($columns, $websoccer->getConfig('db_prefix') . '_spieler', 'id = %d', (int) $playerId);
		}
	}

	private static function _createAndExecuteEvent(WebSoccer $websoccer, DbConnection $db, $userId, $clubId) {
		
		// get events which have not occurred lately for the same user.
		// Since admin might have created a lot of events, we pick any 100 random events (ignoring weights here).
		$result = $db->querySelect('*', $websoccer->getConfig('db_prefix') . '_randomevent', 
				'weight > 0 AND id NOT IN (SELECT event_id FROM ' . $websoccer->getConfig('db_prefix') . '_randomevent_occurrence WHERE user_id = %d) ORDER BY RAND()', $userId,
				100);
		$events = array();
		while ($event = $result->fetch_array()) {
			// add "weight" times in order to increase probability
			for ($i = 1; $i <= $event['weight']; $i++) {
				$events[] = $event;
			}
		}
		$result->free();
		
		if (!count($events)) {
			return;
		}
		
		// select and execute event
		$randomEvent = $events[array_rand($events)];
		self::_executeEvent($websoccer, $db, $userId, $clubId, $randomEvent);
		
		// create occurrence log
		$db->queryInsert(array(
				'user_id' => $userId,
				'team_id' => $clubId,
				'event_id' => $randomEvent['id'],
				'occurrence_date' => $websoccer->getNowAsTimestamp()
				), 
				$websoccer->getConfig('db_prefix') . '_randomevent_occurrence');
		
	}
	
	private static function _executeEvent(WebSoccer $websoccer, DbConnection $db, $userId, $clubId, $event) {
		
		$notificationType = 'randomevent';
		$subject = $event['message'];
		
		// debit or credit money
		if ($event['effect'] == 'money') {
			$amount = $event['effect_money_amount'];
			$sender = $websoccer->getConfig('projectname');
			
			if ($amount > 0) {
				BankAccountDataService::creditAmount($websoccer, $db, $clubId, $amount, $subject, $sender);
			} else {
				BankAccountDataService::debitAmount($websoccer, $db, $clubId, $amount * (0-1), $subject, $sender);
			}
			
			// notification
			NotificationsDataService::createNotification($websoccer, $db, $userId, $subject, null, 
				$notificationType, 'finance', null, $clubId);
			
			// execute on random player
		} else {
			
			// select random player from team
			$result = $db->querySelect('id, vorname, nachname, kunstname, w_frische, w_kondition, w_zufriedenheit', 
					$websoccer->getConfig('db_prefix') . '_spieler',
					'verein_id = %d AND gesperrt = 0 AND verletzt = 0 AND status = \'1\' ORDER BY RAND()', $clubId, 1);
			$player = $result->fetch_array();
			$result->free();
			if (!$player) {
				return;
			}
			
			// execute (get update column)
			switch ($event['effect']) {
				case 'player_injured':
					$columns = array('verletzt' => $event['effect_blocked_matches']);
					break;
					
				case 'player_blocked':
					$columns = array('gesperrt' => $event['effect_blocked_matches']);
					break;
					
				case 'player_happiness':
					$columns = array('w_zufriedenheit' => max(1, min(100, $player['w_zufriedenheit'] + $event['effect_skillchange'])));
					break;
					
				case 'player_fitness':
					$columns = array('w_frische' => max(1, min(100, $player['w_frische'] + $event['effect_skillchange'])));
					break;
					
				case 'player_stamina':
					$columns = array('w_kondition' => max(1, min(100, $player['w_kondition'] + $event['effect_skillchange'])));
					break;
			}
			
			// update player
			if (!isset($columns)) {
				return;
			}
			$db->queryUpdate($columns, $websoccer->getConfig('db_prefix') . '_spieler', 'id = %d', $player['id']);
			
			// create notification
			$playerName = (strlen($player['kunstname'])) ? $player['kunstname'] : $player['vorname'] . ' ' . $player['nachname'];
			NotificationsDataService::createNotification($websoccer, $db, $userId, $subject, array('playername' => $playerName), 
				$notificationType, 'player', 'id=' . $player['id'], $clubId);
		}
		
	}

	private static function _getOccurrence(WebSoccer $websoccer, DbConnection $db, $occurrenceId, $userId, $teamId) {
		$result = $db->querySelect('*', $websoccer->getConfig('db_prefix') . '_randomevent_chain_occurrence',
			'id = %d AND user_id = %d AND team_id = %d', array((int) $occurrenceId, (int) $userId, (int) $teamId), 1);
		$row = $result->fetch_array();
		$result->free();
		return $row;
	}

	private static function _getChoice(WebSoccer $websoccer, DbConnection $db, $choiceId, $chainId) {
		$result = $db->querySelect('*', $websoccer->getConfig('db_prefix') . '_randomevent_chain_choice',
			'id = %d AND chain_id = %d', array((int) $choiceId, (int) $chainId), 1);
		$row = $result->fetch_array();
		$result->free();
		return $row;
	}

	private static function _getDefaultChoice(WebSoccer $websoccer, DbConnection $db, $chainId) {
		$result = $db->querySelect('*', $websoccer->getConfig('db_prefix') . '_randomevent_chain_choice',
			'chain_id = %d AND is_default = \'1\' ORDER BY sort_order ASC', (int) $chainId, 1);
		$row = $result->fetch_array();
		$result->free();
		return $row;
	}

	private static function _selectPlayerForUnhappyEvent(WebSoccer $websoccer, DbConnection $db, $clubId) {
		$result = $db->querySelect('id, vorname, nachname, kunstname, w_zufriedenheit, personality',
			$websoccer->getConfig('db_prefix') . '_spieler',
			'verein_id = %d AND status = \'1\' AND verletzt = 0 AND gesperrt = 0 ORDER BY RAND()', (int) $clubId, 40);

		$players = array();
		while ($player = $result->fetch_array()) {
			$weight = 1;
			if ($player['personality'] === 'troublemaker') {
				$weight = 5;
			} elseif ($player['personality'] === 'ambitious') {
				$weight = 3;
			} elseif ($player['personality'] === 'inconsistent') {
				$weight = 2;
			} elseif ($player['personality'] === 'loyal' || $player['personality'] === 'professional') {
				$weight = 1;
			}

			if ((int) $player['w_zufriedenheit'] < 45) {
				$weight += 3;
			}

			for ($i = 1; $i <= $weight; $i++) {
				$players[] = $player;
			}
		}
		$result->free();

		if (!count($players)) {
			return null;
		}

		return $players[array_rand($players)];
	}

	private static function _getSponsorNameForTeam(WebSoccer $websoccer, DbConnection $db, $teamId) {
		$fromTable = $websoccer->getConfig('db_prefix') . '_verein AS T';
		$fromTable .= ' LEFT JOIN ' . $websoccer->getConfig('db_prefix') . '_sponsor_contract AS SC ON SC.team_id = T.id AND SC.status = \'active\'';
		$fromTable .= ' LEFT JOIN ' . $websoccer->getConfig('db_prefix') . '_sponsor AS S ON S.id = IF(SC.sponsor_id IS NOT NULL, SC.sponsor_id, T.sponsor_id)';
		$result = $db->querySelect('IF(SC.sponsor_name IS NOT NULL AND SC.sponsor_name != \'\', SC.sponsor_name, S.name) AS sponsor_name',
			$fromTable, 'T.id = %d', (int) $teamId, 1);
		$row = $result->fetch_array();
		$result->free();

		if ($row && strlen((string) $row['sponsor_name'])) {
			return $row['sponsor_name'];
		}

		return null;
	}

	private static function _getTeamName(WebSoccer $websoccer, DbConnection $db, $teamId) {
		$result = $db->querySelect('name', $websoccer->getConfig('db_prefix') . '_verein', 'id = %d', (int) $teamId, 1);
		$row = $result->fetch_array();
		$result->free();
		return ($row) ? $row['name'] : '';
	}

	private static function _generateYouthCandidate(WebSoccer $websoccer, DbConnection $db, $teamId) {
		$country = self::_getTeamCountry($websoccer, $db, $teamId);
		$firstname = self::_getRandomNameItem($country, 'firstnames.txt');
		$lastname = self::_getRandomNameItem($country, 'lastnames.txt');

		$positions = array('Torwart', 'Abwehr', 'Mittelfeld', 'Sturm');
		$position = $positions[array_rand($positions)];

		$minAge = (int) self::_getConfigValue($websoccer, 'youth_scouting_min_age', 14);
		$maxAge = (int) self::_getConfigValue($websoccer, 'youth_min_age_professional', 18);
		if ($maxAge < $minAge) {
			$maxAge = $minAge + 2;
		}
		$age = mt_rand($minAge, $maxAge);

		$minStrength = (int) self::_getConfigValue($websoccer, 'youth_scouting_min_strength', 5);
		$maxStrength = (int) self::_getConfigValue($websoccer, 'youth_scouting_max_strength', 30);
		if ($maxStrength < $minStrength) {
			$maxStrength = $minStrength + 10;
		}
		$strength = mt_rand($minStrength, $maxStrength);

		return array(
			'youth_firstname' => $firstname,
			'youth_lastname' => $lastname,
			'youthplayer' => $firstname . ' ' . $lastname,
			'youth_age' => $age,
			'youth_position' => $position,
			'youth_nation' => $country,
			'youth_strength' => $strength
		);
	}

	private static function _createYouthPlayerFromContext(WebSoccer $websoccer, DbConnection $db, $teamId, $context) {
		$db->queryInsert(array(
			'team_id' => (int) $teamId,
			'firstname' => $context['youth_firstname'],
			'lastname' => $context['youth_lastname'],
			'age' => (int) $context['youth_age'],
			'position' => $context['youth_position'],
			'nation' => $context['youth_nation'],
			'strength' => (int) $context['youth_strength'],
			'transfer_fee' => 0
		), $websoccer->getConfig('db_prefix') . '_youthplayer');

		return (int) $db->getLastInsertedId();
	}

	private static function _getTeamCountry(WebSoccer $websoccer, DbConnection $db, $teamId) {
		$fromTable = $websoccer->getConfig('db_prefix') . '_verein AS T';
		$fromTable .= ' LEFT JOIN ' . $websoccer->getConfig('db_prefix') . '_liga AS L ON L.id = T.liga_id';
		$result = $db->querySelect('L.land AS country', $fromTable, 'T.id = %d', (int) $teamId, 1);
		$row = $result->fetch_array();
		$result->free();

		if ($row && strlen((string) $row['country'])) {
			return $row['country'];
		}

		return 'Deutschland';
	}

	private static function _getRandomNameItem($country, $filename) {
		$file = BASE_FOLDER . '/admin/config/names/' . $country . '/' . $filename;
		if (!is_readable($file)) {
			$file = BASE_FOLDER . '/admin/config/names/Deutschland/' . $filename;
		}

		$items = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
		if (!$items || !count($items)) {
			return ($filename === 'firstnames.txt') ? 'Max' : 'Mustermann';
		}

		return $items[mt_rand(0, count($items) - 1)];
	}

	private static function _getPlayerName($player) {
		return (strlen((string) $player['kunstname'])) ? $player['kunstname'] : trim($player['vorname'] . ' ' . $player['nachname']);
	}

	private static function _changeTeamNumericValue(WebSoccer $websoccer, DbConnection $db, $teamId, $column, $change) {
		$result = $db->querySelect($column, $websoccer->getConfig('db_prefix') . '_verein', 'id = %d', (int) $teamId, 1);
		$row = $result->fetch_array();
		$result->free();
		if (!$row) {
			return;
		}

		$db->queryUpdate(array($column => max(1, min(100, (int) $row[$column] + (int) $change))),
			$websoccer->getConfig('db_prefix') . '_verein', 'id = %d', (int) $teamId);
	}

	private static function _changeUserNumericValue(WebSoccer $websoccer, DbConnection $db, $userId, $column, $change) {
		$result = $db->querySelect($column, $websoccer->getConfig('db_prefix') . '_user', 'id = %d', (int) $userId, 1);
		$row = $result->fetch_array();
		$result->free();
		if (!$row) {
			return;
		}

		$db->queryUpdate(array($column => max(1, min(100, (int) $row[$column] + (int) $change))),
			$websoccer->getConfig('db_prefix') . '_user', 'id = %d', (int) $userId);
	}

	private static function _decodeContext($contextData) {
		if (!strlen((string) $contextData)) {
			return array();
		}

		$context = json_decode($contextData, TRUE);
		return (is_array($context)) ? $context : array();
	}

	private static function _translateAndReplace(I18n $i18n, $text, $context) {
		$text = (string) $text;
		if ($i18n->hasMessage($text)) {
			$text = $i18n->getMessage($text);
		}

		foreach ($context as $key => $value) {
			$text = str_replace('{' . $key . '}', htmlspecialchars((string) $value, ENT_COMPAT, 'UTF-8'), $text);
		}

		return $text;
	}

	private static function _fallbackText($text) {
		$text = (string) $text;
		return (strlen($text)) ? $text : 'Random event';
	}

	private static function _getConfigValue(WebSoccer $websoccer, $key, $defaultValue) {
		try {
			$value = $websoccer->getConfig($key);
			return ($value === null || $value === '') ? $defaultValue : $value;
		} catch (Exception $e) {
			return $defaultValue;
		}
	}

	private static function _isConfigEnabled(WebSoccer $websoccer, $key, $defaultValue) {
		$value = self::_getConfigValue($websoccer, $key, ($defaultValue) ? '1' : '0');
		return ($value === TRUE || $value === 1 || $value === '1');
	}
}
?>
