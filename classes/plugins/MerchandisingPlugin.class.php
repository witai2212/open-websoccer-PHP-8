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
 * Handles merchandising revenues after completed home matches.
 */
class MerchandisingPlugin {

	/**
	 * Computes and credits/debits merchandising profit after a completed home match.
	 *
	 * Considered factors:
	 * - number of spectators
	 * - product base demand
	 * - fan popularity
	 * - home match result
	 * - merchandising bonus from stadium buildings
	 * - selected price factor
	 *
	 * @param MatchCompletedEvent $event
	 */
	public static function creditMatchdayMerchandisingRevenue(MatchCompletedEvent $event) {

		$match = $event->match;

		/*
		 * Merchandising shall only be processed for regular first-team home matches.
		 * This excludes:
		 * - friendlies
		 * - youth matches
		 * - other possible custom match types
		 */
		if ($match->type !== 'Ligaspiel' && $match->type !== 'Pokalspiel') {
			return;
		}

		/*
		 * No merchandising for national teams.
		 */
		if ($match->homeTeam->isNationalTeam) {
			return;
		}

		/*
		 * If a match is played in a foreign/neutral stadium,
		 * we do not create home-team merchandising income.
		 */
		if ($match->isAtForeignStadium) {
			return;
		}

		$websoccer = $event->websoccer;
		$db = $event->db;
		$dbPrefix = $websoccer->getConfig('db_prefix');

		$homeTeamId = (int) $match->homeTeam->id;
		$matchId = (int) $match->id;

		/*
		 * Safety protection:
		 * Do not create merchandising sales twice for the same match/team.
		 */
		$result = $db->querySelect(
			'id',
			$dbPrefix . '_merchandising_sales',
			'match_id = %d AND team_id = %d',
			array($matchId, $homeTeamId),
			1
		);

		$existingSales = $result->fetch_array();
		$result->free();

		if ($existingSales) {
			return;
		}

		/*
		 * Load spectator count from match table.
		 * The value has already been calculated and stored before kickoff.
		 */
		$result = $db->querySelect(
			'zuschauer',
			$dbPrefix . '_spiel',
			'id = %d',
			$matchId,
			1
		);

		$matchRecord = $result->fetch_array();
		$result->free();

		if (!$matchRecord || (int) $matchRecord['zuschauer'] <= 0) {
			return;
		}

		$spectators = (int) $matchRecord['zuschauer'];

		/*
		 * Load fan popularity of the home team's manager.
		 * Fallback = 50, if no value exists.
		 */
		$columns = array(
			'T.id' => 'team_id',
			'COALESCE(U.fanbeliebtheit, 50)' => 'fan_popularity'
		);

		$fromTable = $dbPrefix . '_verein AS T';
		$fromTable .= ' LEFT JOIN ' . $dbPrefix . '_user AS U ON U.id = T.user_id';

		$result = $db->querySelect(
			$columns,
			$fromTable,
			'T.id = %d',
			$homeTeamId,
			1
		);

		$teamRecord = $result->fetch_array();
		$result->free();

		$fanPopularity = 50;
		if ($teamRecord && isset($teamRecord['fan_popularity'])) {
			$fanPopularity = (int) $teamRecord['fan_popularity'];
		}

		$fanPopularity = max(0, min(100, $fanPopularity));

		/*
		 * Fan popularity factor:
		 * 0   popularity -> 0.75
		 * 50  popularity -> 1.00
		 * 100 popularity -> 1.25
		 */
		$popularityFactor = 0.75 + ($fanPopularity / 200);

		/*
		 * Match result factor.
		 */
		$homeGoals = (int) $match->homeTeam->getGoals();
		$guestGoals = (int) $match->guestTeam->getGoals();

		if ($homeGoals > $guestGoals) {
			$resultFactor = 1.10;
		} elseif ($homeGoals < $guestGoals) {
			$resultFactor = 0.90;
		} else {
			$resultFactor = 1.00;
		}

		/*
		 * Merchandising building bonus.
		 * Example:
		 * sum = 20 -> factor 1.20
		 */
		$merchandisingBonus = self::getMerchandisingBonusFromBuildings(
			$websoccer,
			$db,
			$homeTeamId
		);

		$buildingFactor = max(0.00, 1 + ($merchandisingBonus / 100));

		/*
		 * Marketing manager from club staff adds a deliberately small
		 * merchandising demand boost.
		 */
		$staffFactor = 1.00;
		if (class_exists('ClubStaffDataService')) {
			$staffFactor = ClubStaffDataService::getMerchandisingFactor(
				$websoccer,
				$db,
				$homeTeamId
			);
		}

		/*
		 * Derby/rivalry matches create a special demand boost.
		 * Depending on rivalry strength this is 10% to 50%.
		 */
		$derbyFactor = RivalriesDataService::getDerbyBusinessFactor(
			$websoccer,
			$db,
			$match
		);

		/*
		 * Load active products and team-specific settings.
		 */
		$columns = array(
			'P.id' => 'product_id',
			'P.purchase_price' => 'purchase_price',
			'P.sales_price' => 'sales_price',
			'P.base_demand' => 'base_demand',
			'TP.enabled' => 'team_enabled',
			'TP.price_factor' => 'price_factor'
		);

		$fromTable = $dbPrefix . '_merchandising_product AS P';
		$fromTable .= ' LEFT JOIN ' . $dbPrefix . '_merchandising_team_product AS TP';
		$fromTable .= ' ON TP.product_id = P.id AND TP.team_id = ' . $homeTeamId;

		$result = $db->querySelect(
			$columns,
			$fromTable,
			"P.active = '1' ORDER BY P.id ASC"
		);

		$totalProfit = 0;
		$now = $websoccer->getNowAsTimestamp();

		while ($product = $result->fetch_array()) {

			/*
			 * Default behavior:
			 * If the team has no individual setting yet,
			 * the product is active.
			 */
			$isEnabled = true;

			if ($product['team_enabled'] !== NULL && $product['team_enabled'] !== '1') {
				$isEnabled = false;
			}

			if (!$isEnabled) {
				continue;
			}

			$productId = (int) $product['product_id'];
			$purchasePrice = (int) $product['purchase_price'];
			$baseSalesPrice = (int) $product['sales_price'];
			$baseDemand = (float) $product['base_demand'];

			if ($baseSalesPrice <= 0 || $baseDemand <= 0) {
				continue;
			}

			/*
			 * Allowed price factors only.
			 * If DB value is invalid, use normal factor 1.00.
			 */
			$priceFactor = isset($product['price_factor'])
				? (string) $product['price_factor']
				: '1.00';

			$allowedPriceFactors = array('0.90', '1.00', '1.10');

			if (!in_array($priceFactor, $allowedPriceFactors, true)) {
				$priceFactor = '1.00';
			}

			$numericPriceFactor = (float) $priceFactor;

			/*
			 * Demand effect of the selected price strategy:
			 * cheap  0.90 -> +10% demand
			 * normal 1.00 -> unchanged
			 * high   1.10 -> -10% demand
			 */
			if ($priceFactor === '0.90') {
				$priceDemandFactor = 1.10;
			} elseif ($priceFactor === '1.10') {
				$priceDemandFactor = 0.90;
			} else {
				$priceDemandFactor = 1.00;
			}

			$effectiveSalesPrice = (int) round($baseSalesPrice * $numericPriceFactor);

			/*
			 * Compute units sold.
			 */
			$unitsSold = (int) round(
				$spectators
				* $baseDemand
				* $popularityFactor
				* $resultFactor
				* $buildingFactor
				* $staffFactor
				* $derbyFactor
				* $priceDemandFactor
			);

			if ($unitsSold <= 0) {
				continue;
			}

			$revenue = $unitsSold * $effectiveSalesPrice;
			$costs = $unitsSold * $purchasePrice;
			$profit = $revenue - $costs;

			/*
			 * Store detailed merchandising sales row.
			 */
			$columnsInsert = array(
				'match_id' => $matchId,
				'team_id' => $homeTeamId,
				'product_id' => $productId,
				'units_sold' => $unitsSold,
				'revenue' => $revenue,
				'costs' => $costs,
				'profit' => $profit,
				'created_date' => $now
			);

			$db->queryInsert(
				$columnsInsert,
				$dbPrefix . '_merchandising_sales'
			);

			$totalProfit += $profit;
		}

		$result->free();

		/*
		 * Book final total profit/loss into club finances.
		 */
		if ($totalProfit > 0) {

			BankAccountDataService::creditAmount(
				$websoccer,
				$db,
				$homeTeamId,
				$totalProfit,
				'merchandising_matchday_profit_subject',
				$websoccer->getConfig('projectname')
			);

		} elseif ($totalProfit < 0) {

			BankAccountDataService::debitAmount(
				$websoccer,
				$db,
				$homeTeamId,
				abs($totalProfit),
				'merchandising_matchday_loss_subject',
				$websoccer->getConfig('projectname')
			);
		}
	}

	/**
	 * Returns the sum of all completed merchandising building bonuses of a team.
	 *
	 * @param WebSoccer $websoccer
	 * @param DbConnection $db
	 * @param int $teamId
	 * @return int
	 */
	private static function getMerchandisingBonusFromBuildings(
		WebSoccer $websoccer,
		DbConnection $db,
		$teamId
	) {

		$dbPrefix = $websoccer->getConfig('db_prefix');

		$fromTable = $dbPrefix . '_buildings_of_team';
		$fromTable .= ' INNER JOIN ' . $dbPrefix . '_stadiumbuilding';
		$fromTable .= ' ON id = building_id';

		$result = $db->querySelect(
			'SUM(effect_merchandising) AS bonus_sum',
			$fromTable,
			'team_id = %d AND construction_deadline < %d',
			array($teamId, $websoccer->getNowAsTimestamp())
		);

		$record = $result->fetch_array();
		$result->free();

		if ($record && isset($record['bonus_sum'])) {
			return (int) $record['bonus_sum'];
		}

		return 0;
	}

}

?>