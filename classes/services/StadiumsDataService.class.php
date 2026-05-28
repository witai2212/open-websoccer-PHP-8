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
 * Data service for stadiums
 */
class StadiumsDataService {

	private static $_namingRightsTablesReady = FALSE;

	/**
	 * Provides information about the team's stadium.
	 * 
	 * @param WebSoccer $websoccer Application context.
	 * @param DbConnection $db DB connection.
	 * @param int $clubId ID of team.
	 * @return array Assoc. array with information about stadium or NULL.
	 */
	public static function getStadiumByTeamId(WebSoccer $websoccer, DbConnection $db, $clubId) {
		if (!$clubId) {
			return NULL;
		}
		
		$columns["S.id"] = "stadium_id";
		$columns["S.name"] = "name";
		$columns["S.picture"] = "picture";
		$columns["S.p_steh"] = "places_stands";
		$columns["S.p_sitz"] = "places_seats";
		$columns["S.p_haupt_steh"] = "places_stands_grand";
		$columns["S.p_haupt_sitz"] = "places_seats_grand";
		$columns["S.p_vip"] = "places_vip";
		
		$columns["S.level_pitch"] = "level_pitch";
		$columns["S.level_videowall"] = "level_videowall";
		$columns["S.level_seatsquality"] = "level_seatsquality";
		$columns["S.level_vipquality"] = "level_vipquality";
		
		$fromTable = $websoccer->getConfig("db_prefix") . "_stadion AS S";
		$fromTable .= " INNER JOIN " . $websoccer->getConfig("db_prefix") . "_verein AS T ON T.stadion_id = S.id";
		$whereCondition = "T.id = %d";
		$result = $db->querySelect($columns, $fromTable, $whereCondition, $clubId, 1);
		$stadium = $result->fetch_array();
		$result->free();
		
		return $stadium;
	}
	
	/**
	 * Provides offers for a stadium extension for the specified team and number of new seats.
	 * 
	 * @param WebSoccer $websoccer Application context.
	 * @param DbConnection $db DB connection-
	 * @param int $clubId ID of team.
	 * @param int $newSideStanding number of new seats to construct. 0 for none.
	 * @param int $newSideSeats number of new seats to construct. 0 for none.
	 * @param int $newGrandStanding number of new seats to construct. 0 for none.
	 * @param int $newGrandSeats number of new seats to construct. 0 for none.
	 * @param int $newVips number of new seats to construct. 0 for none.
	 * @return array list of offers with key=builder ID, value= assoc array with offer details.
	 */
	public static function getBuilderOffersForExtension(WebSoccer $websoccer, DbConnection $db, $clubId,
			$newSideStanding = 0, $newSideSeats = 0, $newGrandStanding = 0, $newGrandSeats = 0, $newVips = 0) {
		
		$offers = array();
		
		$totalNew = $newSideStanding + $newSideSeats + $newGrandStanding + $newGrandSeats + $newVips;
		if (!$totalNew) {
			return $offers;
		}
		
		$stadium = self::getStadiumByTeamId($websoccer, $db, $clubId);
		$existingCapacity = $stadium["places_stands"] + $stadium["places_seats"] + $stadium["places_stands_grand"] + $stadium["places_seats_grand"] + $stadium["places_vip"];
		
		// query builders and calculate offers
		$result = $db->querySelect("*", $websoccer->getConfig("db_prefix") . "_stadium_builder", 
				"min_stadium_size <= %d AND (max_stadium_size = 0 OR max_stadium_size >= %d)", 
				array($existingCapacity, $existingCapacity));
		while ($builder = $result->fetch_array()) {
			
			$constructionTime = max($builder["construction_time_days_min"], 
					$builder["construction_time_days"] * ceil($totalNew / 5000));
			
			$costsPerSeat = $builder["cost_per_seat"];
			$costsSideStanding = $newSideStanding * ($websoccer->getConfig("stadium_cost_standing") + $costsPerSeat);
			$costsSideSeats = $newSideSeats * ($websoccer->getConfig("stadium_cost_seats") + $costsPerSeat);
			$costsGrandStanding = $newGrandStanding * ($websoccer->getConfig("stadium_cost_standing_grand") + $costsPerSeat);
			$costsGrandSeats = $newGrandSeats * ($websoccer->getConfig("stadium_cost_seats_grand") + $costsPerSeat);
			$costsVip = $newVips * ($websoccer->getConfig("stadium_cost_vip") + $costsPerSeat);
			
			$offer = array(
						"builder_id" => $builder["id"],
						"builder_name" => $builder["name"],
						"builder_picture" => $builder["picture"],
						"builder_premiumfee" => $builder["premiumfee"],
						"deadline" => $websoccer->getNowAsTimestamp() + $constructionTime * 24 * 3600,
						"deadline_days" => $constructionTime,
						"reliability" => $builder["reliability"],
						"fixedcosts" => $builder["fixedcosts"],
						"costsSideStanding" => $costsSideStanding,
						"costsSideSeats" => $costsSideSeats,
						"costsGrandStanding" => $costsGrandStanding,
						"costsGrandSeats" => $costsGrandSeats,
						"costsVip" => $costsVip,
						"totalCosts" => $builder["fixedcosts"] + $costsSideStanding + $costsSideSeats + $costsGrandStanding + $costsGrandSeats + $costsVip
					);
			
			$offers[$builder["id"]] = $offer;
		}
		$result->free();
		
		return $offers;
	}
	
	/**
	 * Provides the current on-going stadium construction order of a team or NULL of no construction is on-going. 
	 * 
	 * @param WebSoccer $websoccer Application context.
	 * @param DbConnection $db DB connection.
	 * @param int $clubId ID of team.
	 * @return array|NULL the current on-going stadium construction order of a team or NULL of no construction is on-going
	 */
	public static function getCurrentConstructionOrderOfTeam(WebSoccer $websoccer, DbConnection $db, $clubId) {
		$fromTable = $websoccer->getConfig("db_prefix") . "_stadium_construction AS C";
		$fromTable .= " INNER JOIN " . $websoccer->getConfig("db_prefix") . "_stadium_builder AS B ON B.id = C.builder_id";
		
		$result = $db->querySelect("C.*, B.name AS builder_name, B.reliability AS builder_reliability", $fromTable, "C.team_id = %d", $clubId);
		$order = $result->fetch_array();
		$result->free();
		
		if ($order) {
			return $order;
		} else {
			return NULL;
		}
	}
	
	/**
	 * Provides stadium construction orders which are due (i.e. deadline is in the past).
	 * 
	 * @param WebSoccer $websoccer Application context.
	 * @param DbConnection $db DB connection.
	 * @return array list of construction orders incl. builder's reliability and user ID.
	 */
	public static function getDueConstructionOrders(WebSoccer $websoccer, DbConnection $db) {
		$fromTable = $websoccer->getConfig("db_prefix") . "_stadium_construction AS C";
		$fromTable .= " INNER JOIN " . $websoccer->getConfig("db_prefix") . "_stadium_builder AS B ON B.id = C.builder_id";
		$fromTable .= " INNER JOIN " . $websoccer->getConfig("db_prefix") . "_verein AS T ON T.id = C.team_id";
		
		$result = $db->querySelect("C.*, T.user_id AS user_id, B.reliability AS builder_reliability", $fromTable, "C.deadline <= %d", 
				$websoccer->getNowAsTimestamp());
		
		$orders = array();
		while ($order = $result->fetch_array()) {
			$orders[] = $order;
		}
		$result->free();
		
		return $orders;
	}
	
	/**
	 * Computes costs for upgrading to the next level of a maintenace item (grass, video wall, seats or VIP lounges quality).
	 * 
	 * @param WebSoccer $websoccer Application context.
	 * @param string $type pitch|videowall|seatsquality|vipquality
	 * @param array $stadium stadium data record.
	 * @return int costs for upgrading to the next level.
	 */
	public static function computeUpgradeCosts(WebSoccer $websoccer, $type, $stadium) {
		$existingLevel = $stadium["level_" . $type];
		
		if ($existingLevel >= 5) {
			return 0;
		}
		
		$baseCost = $websoccer->getConfig("stadium_". $type . "_price");
		
		// costs per seat
		if ($type == "seatsquality") {
			$baseCost = $baseCost * ($stadium["places_seats"] + $stadium["places_seats_grand"]);
		} elseif ($type == "vipquality") {
			$baseCost = $baseCost * $stadium["places_vip"];
		}
		
		// additional charge for levels > 1
		$additionFactor = $websoccer->getConfig("stadium_maintenance_priceincrease_per_level") * $existingLevel / 100;
		
		return round($baseCost + $baseCost * $additionFactor);
	}
	
	/**
	 * Gets largest Stadium for Cup Finals.
	 *
	 * @param WebSoccer $websoccer Application context.
	 * @param string $type pitch|videowall|seatsquality|vipquality
	 * @param array $stadium stadium data record.
	 * @return int costs for upgrading to the next level.
	 */
	public static function getLargestStadium(WebSoccer $websoccer, DbConnection $db) {
	    
		$stadiums = array();
		
		$sqlStr = "SELECT S.id, S.name, (S.p_sitz+S.p_steh+S.p_haupt_steh+S.p_haupt_sitz+S.p_vip) AS total_capacity, C.id AS club_id, C.bild AS club_bild, C.name AS club_name, L.land
					FROM ". $websoccer->getConfig("db_prefix") ."_stadion AS S 
						INNER JOIN " . $websoccer->getConfig("db_prefix") . "_verein AS C ON C.stadion_id = S.id
						INNER JOIN " . $websoccer->getConfig("db_prefix") . "_liga AS L ON L.id = C.liga_id		
					ORDER BY (S.p_sitz+S.p_steh+S.p_haupt_steh+S.p_haupt_sitz+S.p_vip) DESC, user_id DESC
					LIMIT 20";
	    $result = $db->executeQuery($sqlStr);
		while ($stadium = $result->fetch_array()) {
			$stadiums[] = $stadium;
		}
	    $result->free();
		
		return $stadiums;
		
	}	

	/**
	 * Rest stadium levels for new users
	 *
	 * @param WebSoccer $websoccer Application context.
	 */
	public static function resetStadiumLevels(WebSoccer $websoccer, DbConnection $db, $teamId) {
		
		//get stadiumId
		$stadium = self::getStadiumByTeamId($websoccer, $db, $teamId);
		$stadiumId = $stadium['stadium_id'];
		
		$updStr = "UPDATE ". $websoccer->getConfig("db_prefix") ."_stadion
					SET level_pitch='5', level_videowall='5', level_seatsquality='5', level_vipquality='5'
					WHERE id='$stadiumId'";
	    $db->executeQuery($updStr);
		
	}
	
	/**
	 * Gets the active stadium naming-right contract of a team.
	 *
	 * @param WebSoccer $websoccer Application context.
	 * @param DbConnection $db DB connection.
	 * @param int $teamId team ID.
	 * @return array|NULL active contract or NULL.
	 */
	public static function getActiveNamingContractByTeamId(WebSoccer $websoccer, DbConnection $db, $teamId) {
		$teamId = (int) $teamId;
		if ($teamId < 1 || !self::ensureNamingRightsTablesExist($websoccer, $db)) {
			return NULL;
		}

		$contractTable = self::getNamingContractTableName($websoccer);
		$payoutTable = self::getNamingPayoutTableName($websoccer);
		$columns = array(
			'C.id' => 'contract_id',
			'C.team_id' => 'team_id',
			'C.stadium_id' => 'stadium_id',
			'C.sponsor_id' => 'sponsor_id',
			'C.season_id' => 'season_id',
			'C.sponsor_name' => 'sponsor_name',
			'C.stadium_name' => 'stadium_name',
			'C.original_stadium_name' => 'original_stadium_name',
			'C.base_payout_per_match' => 'base_payout_per_match',
			'C.signed_date' => 'signed_date',
			'C.ended_date' => 'ended_date',
			'C.status' => 'status',
			'COALESCE(SUM(P.payout_amount), 0)' => 'total_earned',
			'COUNT(P.id)' => 'payout_count'
		);
		$fromTable = $contractTable . ' AS C LEFT JOIN ' . $payoutTable . ' AS P ON P.contract_id = C.id';
		$result = $db->querySelect($columns, $fromTable, 'C.team_id = %d AND C.status = \'active\' GROUP BY C.id ORDER BY C.signed_date DESC', $teamId, 1);
		$contract = $result->fetch_array();
		$result->free();

		return $contract ? $contract : NULL;
	}

	/**
	 * Builds up to three stadium naming-right offers for the current season.
	 * Existing main sponsors are preferred, but regular league sponsors may also bid.
	 *
	 * @param WebSoccer $websoccer Application context.
	 * @param DbConnection $db DB connection.
	 * @param int $teamId team ID.
	 * @return array list of offer arrays.
	 */
	public static function getStadiumNamingOffers(WebSoccer $websoccer, DbConnection $db, $teamId) {
		$teamId = (int) $teamId;
		$offers = array();
		if ($teamId < 1 || self::getActiveNamingContractByTeamId($websoccer, $db, $teamId)) {
			return $offers;
		}

		$team = self::getNamingRightsTeamInfo($websoccer, $db, $teamId);
		$stadium = self::getStadiumByTeamId($websoccer, $db, $teamId);
		if (!$team || !$stadium) {
			return $offers;
		}

		$capacity = self::getCapacityFromStadium($stadium);
		$fanMood = self::getNamingRightsFanMood($team);
		$seasonId = SponsorsDataService::getCurrentSeasonIdByLeagueId($websoccer, $db, (int) $team['league_id']);
		$rank = TeamsDataService::getTableRankOfTeam($websoccer, $db, $teamId);
		$rank = ($rank > 0) ? (int) $rank : 99;

		$sponsors = array();
		$seenSponsorIds = array();
		$currentSponsorId = isset($team['current_sponsor_id']) ? (int) $team['current_sponsor_id'] : 0;
		if ($currentSponsorId > 0) {
			$currentSponsor = self::getNamingRightsSponsorById($websoccer, $db, $currentSponsorId);
			if ($currentSponsor) {
				$currentSponsor['is_current_sponsor'] = TRUE;
				$sponsors[] = $currentSponsor;
				$seenSponsorIds[$currentSponsorId] = TRUE;
			}
		}

		$columns = array();
		$columns['S.id'] = 'sponsor_id';
		$columns['S.name'] = 'name';
		$columns['S.bild'] = 'picture';
		$columns['S.b_spiel'] = 'amount_match';
		$columns['S.b_heimzuschlag'] = 'amount_home_bonus';
		$columns['S.b_sieg'] = 'amount_win';
		$columns['S.b_meisterschaft'] = 'amount_championship';
		$columns['COALESCE(S.b_cup, 0)'] = 'amount_cup';
		$result = $db->querySelect($columns, $websoccer->getConfig('db_prefix') . '_sponsor AS S',
			'S.liga_id = %d AND (S.min_platz = 0 OR S.min_platz >= %d) ORDER BY S.b_spiel DESC, S.b_heimzuschlag DESC',
			array((int) $team['league_id'], $rank), 10);
		while ($sponsor = $result->fetch_array()) {
			$sponsorId = (int) $sponsor['sponsor_id'];
			if (isset($seenSponsorIds[$sponsorId])) {
				continue;
			}
			$sponsor['is_current_sponsor'] = FALSE;
			$sponsors[] = $sponsor;
			$seenSponsorIds[$sponsorId] = TRUE;
		}
		$result->free();

		$maxOffers = (int) $websoccer->getConfig('stadium_naming_offer_count');
		if ($maxOffers < 1) {
			$maxOffers = 3;
		}
		$maxOffers = min(3, $maxOffers);
		foreach ($sponsors as $sponsor) {
			$offers[] = self::buildStadiumNamingOffer($websoccer, $sponsor, $stadium, $capacity, $fanMood, $seasonId);
			if (count($offers) >= $maxOffers) {
				break;
			}
		}

		return $offers;
	}

	/**
	 * Signs a stadium naming-right offer and renames the stadium until season end.
	 *
	 * @param WebSoccer $websoccer Application context.
	 * @param DbConnection $db DB connection.
	 * @param int $teamId team ID.
	 * @param int $sponsorId sponsor ID.
	 * @return array|FALSE signed offer or FALSE.
	 */
	public static function signStadiumNamingOffer(WebSoccer $websoccer, DbConnection $db, $teamId, $sponsorId) {
		$teamId = (int) $teamId;
		$sponsorId = (int) $sponsorId;
		if ($teamId < 1 || $sponsorId < 1 || !self::ensureNamingRightsTablesExist($websoccer, $db)) {
			return FALSE;
		}

		if (self::getActiveNamingContractByTeamId($websoccer, $db, $teamId)) {
			return FALSE;
		}

		$selectedOffer = NULL;
		$offers = self::getStadiumNamingOffers($websoccer, $db, $teamId);
		foreach ($offers as $offer) {
			if ((int) $offer['sponsor_id'] == $sponsorId) {
				$selectedOffer = $offer;
				break;
			}
		}

		$stadium = self::getStadiumByTeamId($websoccer, $db, $teamId);
		if (!$selectedOffer || !$stadium) {
			return FALSE;
		}

		$contractTable = self::getNamingContractTableName($websoccer);
		$db->queryInsert(array(
			'team_id' => $teamId,
			'stadium_id' => (int) $stadium['stadium_id'],
			'sponsor_id' => $sponsorId,
			'season_id' => (int) $selectedOffer['season_id'],
			'sponsor_name' => $selectedOffer['name'],
			'stadium_name' => $selectedOffer['stadium_name'],
			'original_stadium_name' => $stadium['name'],
			'base_payout_per_match' => (int) $selectedOffer['base_payout_per_match'],
			'signed_date' => $websoccer->getNowAsTimestamp(),
			'ended_date' => 0,
			'status' => 'active'
		), $contractTable);

		$contractId = $db->getLastInsertedId();
		$db->queryUpdate(array('name' => $selectedOffer['stadium_name']),
			$websoccer->getConfig('db_prefix') . '_stadion', 'id = %d', (int) $stadium['stadium_id']);

		$selectedOffer['contract_id'] = $contractId;
		$selectedOffer['original_stadium_name'] = $stadium['name'];
		return $selectedOffer;
	}

	/**
	 * Freely renames the stadium, unless active naming rights lock the name.
	 *
	 * @param WebSoccer $websoccer Application context.
	 * @param DbConnection $db DB connection.
	 * @param int $teamId team ID.
	 * @param string $newName new stadium name.
	 * @return string sanitized stadium name.
	 * @throws Exception if no stadium exists or naming rights are active.
	 */
	public static function renameStadium(WebSoccer $websoccer, DbConnection $db, $teamId, $newName) {
		$teamId = (int) $teamId;
		$stadium = self::getStadiumByTeamId($websoccer, $db, $teamId);
		if (!$stadium) {
			throw new Exception('stadium not found');
		}

		if (self::getActiveNamingContractByTeamId($websoccer, $db, $teamId)) {
			throw new Exception('stadium rename blocked by active naming contract');
		}

		$newName = self::sanitizeStadiumName($newName);
		$db->queryUpdate(array('name' => $newName), $websoccer->getConfig('db_prefix') . '_stadion', 'id = %d', (int) $stadium['stadium_id']);
		return $newName;
	}

	/**
	 * Processes the per-home-match payout for active naming rights.
	 * Payout is base amount multiplied by actual attendance percentage.
	 *
	 * @param MatchCompletedEvent $event Completed match event.
	 */
	public static function processNamingRightsPayout(MatchCompletedEvent $event) {
		if ($event->match->type == 'Freundschaft' || $event->match->homeTeam->isNationalTeam) {
			return;
		}

		$teamId = (int) $event->match->homeTeam->id;
		$matchId = (int) $event->match->id;
		if ($teamId < 1 || $matchId < 1 || !self::ensureNamingRightsTablesExist($event->websoccer, $event->db)) {
			return;
		}

		$contract = self::getActiveNamingContractByTeamId($event->websoccer, $event->db, $teamId);
		if (!$contract) {
			return;
		}

		$payoutTable = self::getNamingPayoutTableName($event->websoccer);
		$result = $event->db->querySelect('id', $payoutTable, 'contract_id = %d AND match_id = %d', array((int) $contract['contract_id'], $matchId), 1);
		$existing = $result->fetch_array();
		$result->free();
		if ($existing) {
			return;
		}

		$attendance = self::getAttendanceForNamingPayout($event->websoccer, $event->db, $matchId, $teamId);
		if (!$attendance || (int) $attendance['capacity'] < 1) {
			return;
		}

		$attendancePercent = max(0, min(100, ((float) $attendance['visitors']) / ((float) $attendance['capacity']) * 100));
		$payoutAmount = (int) round(((int) $contract['base_payout_per_match']) * ($attendancePercent / 100));

		$event->db->queryInsert(array(
			'contract_id' => (int) $contract['contract_id'],
			'match_id' => $matchId,
			'team_id' => $teamId,
			'base_payout' => (int) $contract['base_payout_per_match'],
			'attendance_percent' => round($attendancePercent, 2),
			'payout_amount' => $payoutAmount,
			'created_date' => $event->websoccer->getNowAsTimestamp()
		), $payoutTable);

		if ($payoutAmount > 0) {
			BankAccountDataService::creditAmount($event->websoccer, $event->db, $teamId, $payoutAmount,
				'stadium_naming_payout_subject', $contract['sponsor_name']);
		}
	}

	/**
	 * Expires active stadium naming contracts of a completed season and restores the original stadium names.
	 *
	 * @param WebSoccer $websoccer Application context.
	 * @param DbConnection $db DB connection.
	 * @param int $seasonId season ID.
	 */
	public static function expireNamingContractsForSeason(WebSoccer $websoccer, DbConnection $db, $seasonId) {
		$seasonId = (int) $seasonId;
		if ($seasonId < 1 || !self::ensureNamingRightsTablesExist($websoccer, $db)) {
			return;
		}

		$contractTable = self::getNamingContractTableName($websoccer);
		$result = $db->querySelect('*', $contractTable, 'season_id = %d AND status = \'active\'', $seasonId);
		$contracts = array();
		while ($contract = $result->fetch_array()) {
			$contracts[] = $contract;
		}
		$result->free();

		foreach ($contracts as $contract) {
			if (strlen($contract['original_stadium_name'])) {
				$db->queryUpdate(array('name' => $contract['original_stadium_name']),
					$websoccer->getConfig('db_prefix') . '_stadion', 'id = %d', (int) $contract['stadium_id']);
			}
			$db->queryUpdate(array('status' => 'expired', 'ended_date' => $websoccer->getNowAsTimestamp()),
				$contractTable, 'id = %d', (int) $contract['id']);
		}
	}

	/**
	 * Sanitizes a stadium name for DB storage.
	 *
	 * @param string $name raw stadium name.
	 * @return string sanitized stadium name.
	 */
	public static function sanitizeStadiumName($name) {
		$name = html_entity_decode((string) $name, ENT_QUOTES, 'UTF-8');
		$name = trim(strip_tags($name));
		$name = preg_replace('/\s+/', ' ', $name);
		$name = substr($name, 0, 60);
		return $name;
	}

	private static function getNamingRightsTeamInfo(WebSoccer $websoccer, DbConnection $db, $teamId) {
		$columns = array(
			'T.id' => 'team_id',
			'T.liga_id' => 'league_id',
			'T.sponsor_id' => 'current_sponsor_id',
			'T.fan_mood' => 'fan_mood',
			'T.strength' => 'team_strength',
			'U.fanbeliebtheit' => 'user_fan_popularity'
		);
		$fromTable = $websoccer->getConfig('db_prefix') . '_verein AS T LEFT JOIN ' . $websoccer->getConfig('db_prefix') . '_user AS U ON U.id = T.user_id';
		$result = $db->querySelect($columns, $fromTable, 'T.id = %d', $teamId, 1);
		$team = $result->fetch_array();
		$result->free();
		return $team ? $team : NULL;
	}

	private static function getNamingRightsSponsorById(WebSoccer $websoccer, DbConnection $db, $sponsorId) {
		$columns = array();
		$columns['S.id'] = 'sponsor_id';
		$columns['S.name'] = 'name';
		$columns['S.bild'] = 'picture';
		$columns['S.b_spiel'] = 'amount_match';
		$columns['S.b_heimzuschlag'] = 'amount_home_bonus';
		$columns['S.b_sieg'] = 'amount_win';
		$columns['S.b_meisterschaft'] = 'amount_championship';
		$columns['COALESCE(S.b_cup, 0)'] = 'amount_cup';
		$result = $db->querySelect($columns, $websoccer->getConfig('db_prefix') . '_sponsor AS S', 'S.id = %d', $sponsorId, 1);
		$sponsor = $result->fetch_array();
		$result->free();
		return $sponsor ? $sponsor : NULL;
	}

	private static function buildStadiumNamingOffer(WebSoccer $websoccer, $sponsor, $stadium, $capacity, $fanMood, $seasonId) {
		$baseFactorPercent = (int) $websoccer->getConfig('stadium_naming_base_factor_percent');
		$capacityBonusPerSeat = (int) $websoccer->getConfig('stadium_naming_capacity_bonus_per_seat');
		$fanMaxBonusPercent = (int) $websoccer->getConfig('stadium_naming_fan_mood_max_bonus_percent');
		$minPayout = (int) $websoccer->getConfig('stadium_naming_min_payout');

		if ($baseFactorPercent <= 0) {
			$baseFactorPercent = 100;
		}
		if ($capacityBonusPerSeat < 0) {
			$capacityBonusPerSeat = 0;
		}
		if ($fanMaxBonusPercent < 0) {
			$fanMaxBonusPercent = 0;
		}
		if ($minPayout < 1) {
			$minPayout = 10000;
		}

		$baseAmount = max($minPayout, (int) round(((int) $sponsor['amount_match']) * ($baseFactorPercent / 100)));
		$capacityBonus = max(0, (int) round($capacity * $capacityBonusPerSeat));
		$amountBeforeFan = $baseAmount + $capacityBonus;
		$fanMood = max(0, min(100, (int) $fanMood));
		$fanPercent = (($fanMood - 50) / 50) * $fanMaxBonusPercent;
		$fanMoodBonus = (int) round($amountBeforeFan * ($fanPercent / 100));
		$basePayout = self::roundNamingPayout(max($minPayout, $amountBeforeFan + $fanMoodBonus));

		$stadiumName = self::buildSponsoredStadiumName($sponsor['name'], (int) $sponsor['sponsor_id']);

		return array(
			'sponsor_id' => (int) $sponsor['sponsor_id'],
			'name' => $sponsor['name'],
			'picture' => isset($sponsor['picture']) ? $sponsor['picture'] : '',
			'season_id' => (int) $seasonId,
			'stadium_name' => $stadiumName,
			'base_amount' => $baseAmount,
			'capacity_bonus' => $capacityBonus,
			'fan_mood_bonus' => $fanMoodBonus,
			'fan_mood' => $fanMood,
			'base_payout_per_match' => $basePayout,
			'is_current_sponsor' => isset($sponsor['is_current_sponsor']) ? (bool) $sponsor['is_current_sponsor'] : FALSE,
			'current_stadium_name' => $stadium['name']
		);
	}

	private static function getNamingRightsFanMood($team) {
		if (isset($team['fan_mood']) && (int) $team['fan_mood'] > 0) {
			return (int) $team['fan_mood'];
		}
		if (isset($team['user_fan_popularity']) && (int) $team['user_fan_popularity'] > 0) {
			return (int) $team['user_fan_popularity'];
		}
		return 50;
	}

	private static function getCapacityFromStadium($stadium) {
		return (int) $stadium['places_stands'] + (int) $stadium['places_seats'] + (int) $stadium['places_stands_grand']
			+ (int) $stadium['places_seats_grand'] + (int) $stadium['places_vip'];
	}

	private static function buildSponsoredStadiumName($sponsorName, $sponsorId) {
		$suffixes = array('Arena', 'Park', 'Stadion');
		$suffix = $suffixes[((int) $sponsorId) % count($suffixes)];
		$name = self::sanitizeStadiumName($sponsorName . ' ' . $suffix);
		if (strlen($name) > 60) {
			$name = substr($name, 0, 57) . '...';
		}
		return $name;
	}

	private static function roundNamingPayout($amount) {
		$amount = (int) round($amount);
		if ($amount >= 10000) {
			return (int) round($amount / 1000) * 1000;
		}
		return $amount;
	}

	private static function getAttendanceForNamingPayout(WebSoccer $websoccer, DbConnection $db, $matchId, $teamId) {
		$prefix = $websoccer->getConfig('db_prefix');
		$attendanceTable = self::getNamingAttendanceTableName($websoccer);
		try {
			$result = $db->querySelect('total_visitors AS visitors, total_capacity AS capacity', $attendanceTable, 'match_id = %d AND team_id = %d', array($matchId, $teamId), 1);
			$row = $result->fetch_array();
			$result->free();
			if ($row && (int) $row['capacity'] > 0) {
				return $row;
			}
		} catch (Exception $e) {
			// fall back to match + current stadium capacity below
		}

		$columns = array(
			'M.zuschauer' => 'visitors',
			'(COALESCE(S.p_steh,0) + COALESCE(S.p_sitz,0) + COALESCE(S.p_haupt_steh,0) + COALESCE(S.p_haupt_sitz,0) + COALESCE(S.p_vip,0))' => 'capacity'
		);
		$fromTable = $prefix . '_spiel AS M INNER JOIN ' . $prefix . '_verein AS T ON T.id = M.home_verein INNER JOIN ' . $prefix . '_stadion AS S ON S.id = T.stadion_id';
		$result = $db->querySelect($columns, $fromTable, 'M.id = %d AND M.home_verein = %d', array($matchId, $teamId), 1);
		$row = $result->fetch_array();
		$result->free();
		return $row ? $row : NULL;
	}

	private static function getNamingContractTableName(WebSoccer $websoccer) {
		return $websoccer->getConfig('db_prefix') . '_stadium_naming_contract';
	}

	private static function getNamingPayoutTableName(WebSoccer $websoccer) {
		return $websoccer->getConfig('db_prefix') . '_stadium_naming_payout';
	}

	private static function getNamingAttendanceTableName(WebSoccer $websoccer) {
		return $websoccer->getConfig('db_prefix') . '_stadium_attendance_log';
	}

	private static function ensureNamingRightsTablesExist(WebSoccer $websoccer, DbConnection $db) {
		if (self::$_namingRightsTablesReady) {
			return TRUE;
		}

		$contractTable = self::getNamingContractTableName($websoccer);
		$payoutTable = self::getNamingPayoutTableName($websoccer);

		try {
			$db->executeQuery('CREATE TABLE IF NOT EXISTS `' . $contractTable . '` ('
				. '`id` int(10) NOT NULL AUTO_INCREMENT,'
				. '`team_id` int(10) NOT NULL,'
				. '`stadium_id` int(10) NOT NULL,'
				. '`sponsor_id` int(10) NOT NULL DEFAULT 0,'
				. '`season_id` int(10) NOT NULL DEFAULT 0,'
				. '`sponsor_name` varchar(30) NOT NULL,'
				. '`stadium_name` varchar(60) NOT NULL,'
				. '`original_stadium_name` varchar(60) NOT NULL,'
				. '`base_payout_per_match` int(10) NOT NULL DEFAULT 0,'
				. '`signed_date` int(11) NOT NULL DEFAULT 0,'
				. '`ended_date` int(11) NOT NULL DEFAULT 0,'
				. '`status` enum(\'active\',\'expired\',\'cancelled\') NOT NULL DEFAULT \'active\','
				. 'PRIMARY KEY (`id`),'
				. 'KEY `idx_stadium_naming_team_status` (`team_id`,`status`),'
				. 'KEY `idx_stadium_naming_season_status` (`season_id`,`status`),'
				. 'KEY `idx_stadium_naming_stadium` (`stadium_id`)'
				. ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci');

			$db->executeQuery('CREATE TABLE IF NOT EXISTS `' . $payoutTable . '` ('
				. '`id` int(10) NOT NULL AUTO_INCREMENT,'
				. '`contract_id` int(10) NOT NULL,'
				. '`match_id` int(10) NOT NULL,'
				. '`team_id` int(10) NOT NULL,'
				. '`base_payout` int(10) NOT NULL DEFAULT 0,'
				. '`attendance_percent` decimal(5,2) NOT NULL DEFAULT 0.00,'
				. '`payout_amount` int(10) NOT NULL DEFAULT 0,'
				. '`created_date` int(11) NOT NULL DEFAULT 0,'
				. 'PRIMARY KEY (`id`),'
				. 'UNIQUE KEY `uniq_stadium_naming_payout_match` (`contract_id`,`match_id`),'
				. 'KEY `idx_stadium_naming_payout_team_date` (`team_id`,`created_date`),'
				. 'KEY `idx_stadium_naming_payout_match` (`match_id`)'
				. ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci');
			self::$_namingRightsTablesReady = TRUE;
			return TRUE;
		} catch (Exception $e) {
			return FALSE;
		}
	}


}
?>