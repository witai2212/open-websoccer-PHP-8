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
 * Adds bonuses of stadium environment buildings.
 * 
 * @author Ingo Hofmann
 */
class StadiumEnvironmentPlugin {

	/**
	 * Adds bonus to team training unit.
	 * 
	 * @param PlayerTrainedEvent $event event.
	 */
	public static function addTrainingBonus(PlayerTrainedEvent $event) {
		$bonus = self::getBonusSumFromBuildings($event->websoccer, $event->db, 'effect_training', $event->teamId);
		$event->effectSatisfaction += $bonus;
		$event->effectFreshness += $bonus;
	}
	
	/**
	 * Adds skill bonus to scouted player.
	 * 
	 * @param YouthPlayerScoutedEvent $event event.
	 */
	public static function addYouthPlayerSkillBonus(YouthPlayerScoutedEvent $event) {
		$bonus = self::getBonusSumFromBuildings($event->websoccer, $event->db, 'effect_youthscouting', $event->teamId);
		
		if ($bonus != 0) {
			$playerTable = $event->websoccer->getConfig('db_prefix') . '_youthplayer';
			$result = $event->db->querySelect('strength', $playerTable, 'id = %d', $event->playerId);
			$player = $result->fetch_array();
			$result->free();
			
			if ($player) {
				$minStrength = (int) $event->websoccer->getConfig('youth_scouting_min_strength');
				$maxStrength = (int) $event->websoccer->getConfig('youth_scouting_max_strength');
				
				$strength = max($minStrength, min($maxStrength, $player['strength'] + $bonus));
				if ($strength != $player['strength']) {
					$event->db->queryUpdate(array('strength' => $strength), $playerTable, 'id = %d', $event->playerId);
				}
				
			}
		}
		
	}
	
	/**
	 * Adds bonus to number of sold tickets.
	 * 
	 * @param TicketsComputedEvent $event event.
	 */
	public static function addTicketsBonus(TicketsComputedEvent $event) {
		$bonus = self::getBonusSumFromBuildings($event->websoccer, $event->db, 'effect_tickets', $event->match->homeTeam->id);
		if ($bonus == 0) {
			return;
		}
		
		$bonus = $bonus / 100;
		
		if ($event->rateSeats) {
			$event->rateSeats = max(0.0, min(1.0, $event->rateSeats + $bonus));
		}
		if ($event->rateStands) {
			$event->rateStands = max(0.0, min(1.0, $event->rateStands + $bonus));
		}
		if ($event->rateSeatsGrand) {
			$event->rateSeatsGrand = max(0.0, min(1.0, $event->rateSeatsGrand + $bonus));
		}
		if ($event->rateStandsGrand) {
			$event->rateStandsGrand = max(0.0, min(1.0, $event->rateStandsGrand + $bonus));
		}
		if ($event->rateVip) {
			$event->rateVip = max(0.0, min(1.0, $event->rateVip + $bonus));
		}
	}
	
	/**
	 * Process buildings which cost per home match or which bring income per match.
	 * 
	 * @param MatchCompletedEvent $event event.
	 */
	public static function creditAndDebitAfterHomeMatch(MatchCompletedEvent $event) {
		
		// do not consider friendlies
		if ($event->match->type == 'Freundschaft' || $event->match->homeTeam->isNationalTeam) {
			return;
		}
		
		$homeTeamId = $event->match->homeTeam->id;
		$sum = self::getBonusSumFromBuildings($event->websoccer, $event->db, 'effect_income', $homeTeamId);
		
		if ($sum > 0) {
			BankAccountDataService::creditAmount($event->websoccer, $event->db, $homeTeamId, $sum, 
				'stadiumenvironment_matchincome_subject', $event->websoccer->getConfig('projectname'));
		} elseif ($sum < 0) {
			BankAccountDataService::debitAmount($event->websoccer, $event->db, $homeTeamId, abs($sum),
				'stadiumenvironment_costs_per_match_subject', $event->websoccer->getConfig('projectname'));
		}
	}
	
	/**
	 * Applies one-time fan popularity effects only after construction finished.
	 * This is triggered after matches for both teams. The model also calls the
	 * public helper when a manager opens the stadium environment page.
	 *
	 * @param MatchCompletedEvent $event event.
	 */
	public static function applyFanPopularityAfterConstruction(MatchCompletedEvent $event) {
		
		if (!$event->match->homeTeam->isNationalTeam) {
			self::applyCompletedFanPopularityBuildings($event->websoccer, $event->db, $event->match->homeTeam->id);
		}
		
		if (!$event->match->guestTeam->isNationalTeam) {
			self::applyCompletedFanPopularityBuildings($event->websoccer, $event->db, $event->match->guestTeam->id);
		}
	}
	
	/**
	 * Applies completed, unprocessed one-time fan popularity building effects.
	 *
	 * @param WebSoccer $websoccer application context.
	 * @param DbConnection $db DB connection.
	 * @param int $teamId team ID.
	 */
	public static function applyCompletedFanPopularityBuildings(WebSoccer $websoccer, DbConnection $db, $teamId) {
		
		$teamId = (int) $teamId;
		if ($teamId < 1) {
			return;
		}
		
		$dbPrefix = $websoccer->getConfig('db_prefix');
		$teamTable = $dbPrefix . '_verein';
		$userTable = $dbPrefix . '_user';
		$buildingsOfTeamTable = $dbPrefix . '_buildings_of_team';
		$buildingTable = $dbPrefix . '_stadiumbuilding';
		
		$result = $db->querySelect('user_id', $teamTable, 'id = %d', $teamId, 1);
		$team = $result->fetch_array();
		$result->free();
		
		if (!$team || (int) $team['user_id'] < 1) {
			return;
		}
		
		$userId = (int) $team['user_id'];
		$fromTable = $buildingsOfTeamTable . ' AS BT INNER JOIN ' . $buildingTable . ' AS B ON B.id = BT.building_id';
		$result = $db->querySelect(
			'BT.building_id, B.effect_fanpopularity',
			$fromTable,
			'BT.team_id = %d AND BT.construction_deadline < %d AND BT.fanpopularity_applied = 0 AND B.effect_fanpopularity != 0',
			array($teamId, $websoccer->getNowAsTimestamp())
		);
		
		$buildingIds = array();
		$sum = 0;
		while ($building = $result->fetch_array()) {
			$buildingIds[] = (int) $building['building_id'];
			$sum += (int) $building['effect_fanpopularity'];
		}
		$result->free();
		
		if (!count($buildingIds)) {
			return;
		}
		
		$result = $db->querySelect('fanbeliebtheit', $userTable, 'id = %d', $userId, 1);
		$userinfo = $result->fetch_array();
		$result->free();
		
		if ($userinfo && isset($userinfo['fanbeliebtheit'])) {
			$popularity = min(100, max(1, (int) $userinfo['fanbeliebtheit'] + $sum));
			$db->queryUpdate(array('fanbeliebtheit' => $popularity), $userTable, 'id = %d', $userId);
		}
		
		foreach ($buildingIds as $buildingId) {
			$db->queryUpdate(array('fanpopularity_applied' => 1), $buildingsOfTeamTable, 'team_id = %d AND building_id = %d', array($teamId, $buildingId));
		}
	}
	
	/**
	 * Process buildings which influence injuries.
	 * 
	 * @param MatchCompletedEvent $event event.
	 */
	public static function handleInjuriesAfterMatch(MatchCompletedEvent $event) {
	
		// do not consider friendlies
		if ($event->match->type == 'Freundschaft' || $event->match->homeTeam->isNationalTeam) {
			return;
		}
	
		$homeTeamId = $event->match->homeTeam->id;
		$sumHome = self::getBonusSumFromBuildings($event->websoccer, $event->db, 'effect_injury', $homeTeamId);
		
		$guestTeamId = $event->match->guestTeam->id;
		$sumGuest = self::getBonusSumFromBuildings($event->websoccer, $event->db, 'effect_injury', $guestTeamId);
	
		if ($sumHome > 0 || $sumGuest > 0) {
			
			// get injured players
			$playerTable = $event->websoccer->getConfig('db_prefix') . '_spieler';
			$result = $event->db->querySelect('id,verein_id AS team_id,verletzt AS injured', $playerTable, 
					'(verein_id = %d OR verein_id = %d) AND verletzt > 0', array($homeTeamId, $guestTeamId));
			while ($player = $result->fetch_array()) {
				
				$reduction = 0;
				if ($sumHome > 0 && $player['team_id'] == $homeTeamId) {
					$reduction = $sumHome;
				} elseif ($sumGuest > 0 && $player['team_id'] == $guestTeamId) {
					$reduction = $sumGuest;
				}
				
				// update player
				if ($reduction > 0) {
					$injured = max(0, $player['injured'] - $reduction);
					$event->db->queryUpdate(array('verletzt' => $injured), $playerTable, 'id = %d', $player['id']);
				}
			}
			$result->free();
			
		}
	}
	
	private static function getBonusSumFromBuildings(WebSoccer $websoccer, DbConnection $db, $attributeName, $teamId) {
		
		$allowedAttributes = array('effect_training', 'effect_youthscouting', 'effect_tickets', 'effect_injury', 'effect_income', 'effect_merchandising');
		if (!in_array($attributeName, $allowedAttributes, true)) {
			return 0;
		}
		
		$dbPrefix = $websoccer->getConfig('db_prefix');
		$result = $db->querySelect('SUM(' . $attributeName . ') AS attrSum', $dbPrefix . '_buildings_of_team INNER JOIN '. $dbPrefix . '_stadiumbuilding ON id = building_id', 
				'team_id = %d AND construction_deadline < %d', array($teamId, $websoccer->getNowAsTimestamp()));
		$resArray = $result->fetch_array();
		$result->free();
		
		if ($resArray) {
			return $resArray['attrSum'];
		}
		
		return 0;
	}
	
}
?>
