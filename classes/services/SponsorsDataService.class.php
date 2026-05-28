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
 * Data service for sponsors.
 */
class SponsorsDataService {

	const OFFER_TYPE_SAFE = 'safe';
	const OFFER_TYPE_RISKY = 'risky';
	const OFFER_TYPE_FAN = 'fan';
	const OFFER_TYPE_CUP = 'cup';

	const NEGOTIATION_STATUS_OPEN = 'open';
	const NEGOTIATION_STATUS_SIGNED = 'signed';
	const NEGOTIATION_STATUS_WITHDRAWN = 'withdrawn';

	const CONTRACT_STATUS_ACTIVE = 'active';
	const CONTRACT_STATUS_EXPIRED = 'expired';

	const MAX_NEGOTIATION_LEVEL = 3;
	const NEGOTIATION_MONEY_STEP_PERCENT = 10;
	const NEGOTIATION_ATTENDANCE_STEP_PERCENT = 5;

	/**
	 * Returns the current sponsor contract of a team.
	 *
	 * New contracts are stored as fixed snapshots in sponsor_contract.
	 * A legacy fallback is kept, so existing match-based contracts can still be displayed.
	 */
	public static function getSponsorinfoByTeamId(WebSoccer $websoccer, DbConnection $db, $clubId) {
		$prefix = $websoccer->getConfig('db_prefix');

		$columns = array(
			'C.id' => 'contract_id',
			'C.sponsor_id' => 'sponsor_id',
			'C.season_id' => 'season_id',
			'C.offer_type' => 'offer_type',
			'C.sponsor_name' => 'name',
			'C.sponsor_picture' => 'picture',
			'C.b_spiel' => 'amount_match',
			'C.b_heimzuschlag' => 'amount_home_bonus',
			'C.b_sieg' => 'amount_win',
			'C.b_meisterschaft' => 'amount_championship',
			'C.b_cup' => 'amount_cup',
			'C.b_attendance_percent' => 'amount_attendance_percent',
			'C.negotiation_level' => 'negotiation_level',
			'C.signed_date' => 'signed_date',
			'C.status' => 'contract_status'
		);

		$result = $db->querySelect(
			$columns,
			$prefix . '_sponsor_contract AS C',
			'C.team_id = %d AND C.status = \'active\' ORDER BY C.signed_date DESC',
			$clubId,
			1
		);
		$sponsor = $result->fetch_array();
		$result->free();

		if ($sponsor) {
			$sponsor['matchdays'] = 0;
			$sponsor['offer_type_message'] = self::getOfferTypeMessageKey($sponsor['offer_type']);
			return $sponsor;
		}

		// legacy fallback for old contracts which were signed before the dynamic sponsor module existed
		$columns = array();
		$columns['T.sponsor_spiele'] = 'matchdays';
		$columns['S.id'] = 'sponsor_id';
		$columns['S.name'] = 'name';
		$columns['S.b_spiel'] = 'amount_match';
		$columns['S.b_heimzuschlag'] = 'amount_home_bonus';
		$columns['S.b_sieg'] = 'amount_win';
		$columns['S.b_meisterschaft'] = 'amount_championship';
		$columns['COALESCE(S.b_cup, 0)'] = 'amount_cup';
		$columns['S.bild'] = 'picture';
		
		$fromTable = $prefix . '_sponsor AS S';
		$fromTable .= ' INNER JOIN ' . $prefix . '_verein AS T ON T.sponsor_id = S.id';
		$whereCondition = 'T.id = %d AND T.sponsor_spiele > 0';
		$result = $db->querySelect($columns, $fromTable, $whereCondition, $clubId, 1);
		$sponsor = $result->fetch_array();
		$result->free();

		if ($sponsor) {
			$sponsor['contract_id'] = 0;
			$sponsor['season_id'] = 0;
			$sponsor['offer_type'] = 'legacy';
			$sponsor['offer_type_message'] = 'sponsor_offer_type_legacy';
			$sponsor['amount_attendance_percent'] = 0;
			$sponsor['negotiation_level'] = 0;
			$sponsor['contract_status'] = 'legacy';
		}
		
		return $sponsor;
	}
	
	/**
	 * Generates dynamic offer cards based on the classic sponsor records.
	 */
	public static function getSponsorOffers(WebSoccer $websoccer, DbConnection $db, $teamId) {
		$team = TeamsDataService::getTeamSummaryById($websoccer, $db, $teamId);
		$teamRank = TeamsDataService::getTableRankOfTeam($websoccer, $db, $teamId);
		$seasonId = self::getCurrentSeasonIdByLeagueId($websoccer, $db, $team['team_league_id']);
	
		$columns = array();
		$columns['S.id'] = 'sponsor_id';
		$columns['S.name'] = 'name';
		$columns['S.bild'] = 'picture';
		$columns['S.b_spiel'] = 'amount_match';
		$columns['S.b_heimzuschlag'] = 'amount_home_bonus';
		$columns['S.b_sieg'] = 'amount_win';
		$columns['S.b_meisterschaft'] = 'amount_championship';
		$columns['COALESCE(S.b_cup, 0)'] = 'amount_cup';
	
		$fromTable = $websoccer->getConfig('db_prefix') . '_sponsor AS S';
		$whereCondition = 'S.liga_id = %d AND (S.min_platz = 0 OR S.min_platz >= %d)'
							. ' AND (S.max_teams <= 0 OR S.max_teams > (SELECT COUNT(*) FROM ' . $websoccer->getConfig('db_prefix') . '_sponsor_contract AS C WHERE C.sponsor_id = S.id AND C.season_id = %d AND C.status = \'active\'))'
							. ' ORDER BY S.b_spiel DESC';
		$parameters = array($team['team_league_id'], $teamRank, $seasonId);

		$result = $db->querySelect($columns, $fromTable, $whereCondition, $parameters, 20);

		$offers = array();
		$offerTypes = array(self::OFFER_TYPE_SAFE, self::OFFER_TYPE_RISKY, self::OFFER_TYPE_FAN, self::OFFER_TYPE_CUP);

		while ($baseSponsor = $result->fetch_array()) {
			foreach ($offerTypes as $offerType) {
				$negotiation = self::getNegotiation($websoccer, $db, $teamId, $baseSponsor['sponsor_id'], $seasonId, $offerType);
				if ($negotiation && $negotiation['status'] == self::NEGOTIATION_STATUS_WITHDRAWN) {
					continue;
				}

				$level = ($negotiation && isset($negotiation['negotiation_level'])) ? (int) $negotiation['negotiation_level'] : 0;
				$offers[] = self::buildOfferFromBaseSponsor($baseSponsor, $offerType, $level, $seasonId);
			}
		}
		$result->free();
		
		return $offers;
	}

	/**
	 * Signs a dynamic sponsor offer and stores all values as immutable contract snapshot.
	 */
	public static function signSponsorOffer(WebSoccer $websoccer, DbConnection $db, $teamId, $sponsorId, $offerType) {
		$team = TeamsDataService::getTeamSummaryById($websoccer, $db, $teamId);
		$seasonId = self::getCurrentSeasonIdByLeagueId($websoccer, $db, $team['team_league_id']);
		$offer = self::getSponsorOfferByIdAndType($websoccer, $db, $teamId, $sponsorId, $offerType);

		if (!$offer) {
			return FALSE;
		}

		$contractTable = $websoccer->getConfig('db_prefix') . '_sponsor_contract';
		$db->queryInsert(array(
			'team_id' => (int) $teamId,
			'sponsor_id' => (int) $offer['sponsor_id'],
			'season_id' => (int) $seasonId,
			'offer_type' => $offer['offer_type'],
			'sponsor_name' => $offer['name'],
			'sponsor_picture' => $offer['picture'],
			'b_spiel' => (int) $offer['amount_match'],
			'b_heimzuschlag' => (int) $offer['amount_home_bonus'],
			'b_sieg' => (int) $offer['amount_win'],
			'b_meisterschaft' => (int) $offer['amount_championship'],
			'b_cup' => (int) $offer['amount_cup'],
			'b_attendance_percent' => (int) $offer['amount_attendance_percent'],
			'negotiation_level' => (int) $offer['negotiation_level'],
			'signed_date' => $websoccer->getNowAsTimestamp(),
			'ended_date' => 0,
			'status' => self::CONTRACT_STATUS_ACTIVE
		), $contractTable);

		// Keep old team sponsor_id populated so existing team/profile code still knows the sponsor.
		$db->queryUpdate(
			array('sponsor_id' => (int) $offer['sponsor_id'], 'sponsor_spiele' => 0),
			$websoccer->getConfig('db_prefix') . '_verein',
			'id = %d',
			$teamId
		);

		self::setNegotiationStatus($websoccer, $db, $teamId, $offer['sponsor_id'], $seasonId, $offerType, self::NEGOTIATION_STATUS_SIGNED, (int) $offer['negotiation_level']);

		return $offer;
	}

	/**
	 * Performs a simple negotiation step. Every successful step increases the offer.
	 * First negotiation step cannot fail; later attempts can make the sponsor withdraw.
	 */
	public static function negotiateSponsorOffer(WebSoccer $websoccer, DbConnection $db, $teamId, $sponsorId, $offerType) {
		$team = TeamsDataService::getTeamSummaryById($websoccer, $db, $teamId);
		$seasonId = self::getCurrentSeasonIdByLeagueId($websoccer, $db, $team['team_league_id']);
		$offer = self::getSponsorOfferByIdAndType($websoccer, $db, $teamId, $sponsorId, $offerType);

		if (!$offer) {
			return array('status' => 'invalid');
		}

		$currentLevel = (int) $offer['negotiation_level'];
		$withdrawChance = self::getWithdrawChance($currentLevel);

		if ($withdrawChance > 0 && random_int(1, 100) <= $withdrawChance) {
			self::setNegotiationStatus($websoccer, $db, $teamId, $sponsorId, $seasonId, $offerType, self::NEGOTIATION_STATUS_WITHDRAWN, $currentLevel);
			return array('status' => self::NEGOTIATION_STATUS_WITHDRAWN, 'offer' => $offer, 'withdraw_chance' => $withdrawChance);
		}

		$newLevel = min(self::MAX_NEGOTIATION_LEVEL, $currentLevel + 1);
		self::setNegotiationStatus($websoccer, $db, $teamId, $sponsorId, $seasonId, $offerType, self::NEGOTIATION_STATUS_OPEN, $newLevel);

		$newOffer = self::getSponsorOfferByIdAndType($websoccer, $db, $teamId, $sponsorId, $offerType);
		return array('status' => self::NEGOTIATION_STATUS_OPEN, 'offer' => $newOffer, 'withdraw_chance' => self::getWithdrawChance($newLevel));
	}

	public static function getSponsorOfferByIdAndType(WebSoccer $websoccer, DbConnection $db, $teamId, $sponsorId, $offerType) {
		$offers = self::getSponsorOffers($websoccer, $db, $teamId);
		foreach ($offers as $offer) {
			if ((int) $offer['sponsor_id'] == (int) $sponsorId && $offer['offer_type'] == $offerType) {
				return $offer;
			}
		}
		return null;
	}

	public static function getCurrentSeasonIdByLeagueId(WebSoccer $websoccer, DbConnection $db, $leagueId) {
		$result = $db->querySelect('id', $websoccer->getConfig('db_prefix') . '_saison', 'liga_id = %d AND beendet = \'0\' ORDER BY id DESC', $leagueId, 1);
		$season = $result->fetch_array();
		$result->free();

		return ($season && isset($season['id'])) ? (int) $season['id'] : 0;
	}

	public static function expireContractsForSeason(WebSoccer $websoccer, DbConnection $db, $seasonId) {
		$prefix = $websoccer->getConfig('db_prefix');
		$now = $websoccer->getNowAsTimestamp();

		$db->queryUpdate(
			array('status' => self::CONTRACT_STATUS_EXPIRED, 'ended_date' => $now),
			$prefix . '_sponsor_contract',
			'season_id = %d AND status = \'active\'',
			$seasonId
		);

		$sql = 'UPDATE ' . $prefix . '_verein AS T '
			. 'INNER JOIN ' . $prefix . '_sponsor_contract AS C ON C.team_id = T.id '
			. 'SET T.sponsor_id = NULL, T.sponsor_spiele = 0 '
			. 'WHERE C.season_id = ' . (int) $seasonId . ' AND C.status = \'expired\'';
		$db->executeQuery($sql);
	}

	public static function calculateAttendanceBonusForMatch(WebSoccer $websoccer, DbConnection $db, $matchId, $teamId, $baseAmount, $bonusPercent) {
		$bonusPercent = (int) $bonusPercent;
		if ($bonusPercent <= 0 || $baseAmount <= 0) {
			return 0;
		}

		$prefix = $websoccer->getConfig('db_prefix');
		$columns = array(
			'M.zuschauer' => 'spectators',
			'(COALESCE(S.p_steh,0) + COALESCE(S.p_sitz,0) + COALESCE(S.p_haupt_steh,0) + COALESCE(S.p_haupt_sitz,0) + COALESCE(S.p_vip,0))' => 'capacity'
		);
		$fromTable = $prefix . '_spiel AS M';
		$fromTable .= ' INNER JOIN ' . $prefix . '_verein AS T ON T.id = M.home_verein';
		$fromTable .= ' INNER JOIN ' . $prefix . '_stadion AS S ON S.id = T.stadion_id';

		$result = $db->querySelect($columns, $fromTable, 'M.id = %d AND M.home_verein = %d', array($matchId, $teamId), 1);
		$row = $result->fetch_array();
		$result->free();

		if (!$row || (int) $row['capacity'] <= 0 || (int) $row['spectators'] <= 0) {
			return 0;
		}

		$occupancy = min(1, max(0, ((int) $row['spectators']) / ((int) $row['capacity'])));
		$bonus = (int) round($baseAmount * ($bonusPercent / 100) * $occupancy);

		if (class_exists('FanPressureDataService')) {
			$bonus = FanPressureDataService::adjustFanSponsorBonus($websoccer, $bonus, $teamId);
		}

		return $bonus;
	}

	private static function buildOfferFromBaseSponsor($baseSponsor, $offerType, $negotiationLevel, $seasonId) {
		$baseCup = (int) $baseSponsor['amount_cup'];
		if ($baseCup <= 0) {
			$baseCup = max((int) round($baseSponsor['amount_win'] * 0.8), (int) round($baseSponsor['amount_match'] * 2));
		}

		$offer = array(
			'sponsor_id' => (int) $baseSponsor['sponsor_id'],
			'name' => $baseSponsor['name'],
			'picture' => isset($baseSponsor['picture']) ? $baseSponsor['picture'] : '',
			'season_id' => (int) $seasonId,
			'offer_type' => $offerType,
			'negotiation_level' => (int) $negotiationLevel,
			'amount_attendance_percent' => 0,
			'withdraw_chance' => self::getWithdrawChance((int) $negotiationLevel),
			'offer_type_message' => self::getOfferTypeMessageKey($offerType),
			'offer_description_message' => self::getOfferDescriptionMessageKey($offerType)
		);

		if ($offerType == self::OFFER_TYPE_SAFE) {
			$offer['amount_match'] = self::money($baseSponsor['amount_match'], 1.40);
			$offer['amount_home_bonus'] = self::money($baseSponsor['amount_home_bonus'], 1.10);
			$offer['amount_win'] = self::money($baseSponsor['amount_win'], 0.50);
			$offer['amount_championship'] = self::money($baseSponsor['amount_championship'], 0.70);
			$offer['amount_cup'] = self::money($baseCup, 0.70);
		} elseif ($offerType == self::OFFER_TYPE_RISKY) {
			$offer['amount_match'] = self::money($baseSponsor['amount_match'], 0.60);
			$offer['amount_home_bonus'] = self::money($baseSponsor['amount_home_bonus'], 0.80);
			$offer['amount_win'] = self::money($baseSponsor['amount_win'], 1.80);
			$offer['amount_championship'] = self::money($baseSponsor['amount_championship'], 1.40);
			$offer['amount_cup'] = self::money($baseCup, 1.10);
		} elseif ($offerType == self::OFFER_TYPE_FAN) {
			$offer['amount_match'] = self::money($baseSponsor['amount_match'], 0.90);
			$offer['amount_home_bonus'] = self::money($baseSponsor['amount_home_bonus'], 0.90);
			$offer['amount_win'] = self::money($baseSponsor['amount_win'], 0.80);
			$offer['amount_championship'] = self::money($baseSponsor['amount_championship'], 0.80);
			$offer['amount_cup'] = self::money($baseCup, 0.80);
			$offer['amount_attendance_percent'] = 50;
		} else {
			$offer['amount_match'] = self::money($baseSponsor['amount_match'], 0.80);
			$offer['amount_home_bonus'] = self::money($baseSponsor['amount_home_bonus'], 0.80);
			$offer['amount_win'] = self::money($baseSponsor['amount_win'], 1.00);
			$offer['amount_championship'] = self::money($baseSponsor['amount_championship'], 0.80);
			$offer['amount_cup'] = self::money($baseCup, 1.80);
		}

		if ($negotiationLevel > 0) {
			$moneyFactor = 1 + (($negotiationLevel * self::NEGOTIATION_MONEY_STEP_PERCENT) / 100);
			$offer['amount_match'] = self::money($offer['amount_match'], $moneyFactor);
			$offer['amount_home_bonus'] = self::money($offer['amount_home_bonus'], $moneyFactor);
			$offer['amount_win'] = self::money($offer['amount_win'], $moneyFactor);
			$offer['amount_championship'] = self::money($offer['amount_championship'], $moneyFactor);
			$offer['amount_cup'] = self::money($offer['amount_cup'], $moneyFactor);

			if ($offer['amount_attendance_percent'] > 0) {
				$offer['amount_attendance_percent'] = min(100, $offer['amount_attendance_percent'] + ($negotiationLevel * self::NEGOTIATION_ATTENDANCE_STEP_PERCENT));
			}
		}

		return $offer;
	}

	private static function money($amount, $factor) {
		return (int) round(((int) $amount) * $factor);
	}

	private static function getWithdrawChance($currentNegotiationLevel) {
		$currentNegotiationLevel = (int) $currentNegotiationLevel;
		if ($currentNegotiationLevel <= 0) {
			return 0;
		}
		if ($currentNegotiationLevel == 1) {
			return 25;
		}
		if ($currentNegotiationLevel == 2) {
			return 50;
		}
		return 80;
	}

	private static function getNegotiation(WebSoccer $websoccer, DbConnection $db, $teamId, $sponsorId, $seasonId, $offerType) {
		$result = $db->querySelect(
			'id, negotiation_level, status',
			$websoccer->getConfig('db_prefix') . '_sponsor_negotiation',
			'team_id = %d AND sponsor_id = %d AND season_id = %d AND offer_type = \'%s\'',
			array($teamId, $sponsorId, $seasonId, $offerType),
			1
		);
		$negotiation = $result->fetch_array();
		$result->free();
		return $negotiation;
	}

	private static function setNegotiationStatus(WebSoccer $websoccer, DbConnection $db, $teamId, $sponsorId, $seasonId, $offerType, $status, $level) {
		$table = $websoccer->getConfig('db_prefix') . '_sponsor_negotiation';
		$existing = self::getNegotiation($websoccer, $db, $teamId, $sponsorId, $seasonId, $offerType);
		$columns = array(
			'negotiation_level' => (int) $level,
			'status' => $status,
			'updated_date' => $websoccer->getNowAsTimestamp()
		);

		if ($existing) {
			$db->queryUpdate($columns, $table, 'id = %d', (int) $existing['id']);
		} else {
			$columns['team_id'] = (int) $teamId;
			$columns['sponsor_id'] = (int) $sponsorId;
			$columns['season_id'] = (int) $seasonId;
			$columns['offer_type'] = $offerType;
			$columns['created_date'] = $websoccer->getNowAsTimestamp();
			$db->queryInsert($columns, $table);
		}
	}

	private static function getOfferTypeMessageKey($offerType) {
		return 'sponsor_offer_type_' . $offerType;
	}

	private static function getOfferDescriptionMessageKey($offerType) {
		return 'sponsor_offer_description_' . $offerType;
	}
}
?>
