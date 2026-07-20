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
 * Data service for team's bank account
 */
class BankAccountDataService {
    
    /**
     * Provides number of statements linked to specified team.
     *
     * @param WebSoccer $websoccer Application context.
     * @param DbConnection $db DB connection.
     * @param int $teamId ID of team
     * @return int number of statements which belong to the specified team.
     */
    public static function countAccountStatementsOfTeam(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $columns = "COUNT(*) AS hits";
        
        $fromTable = $websoccer->getConfig("db_prefix") . "_konto";
        
        $whereCondition = "verein_id = %d";
        $parameters = $teamId;
        
        $result = $db->querySelect($columns, $fromTable, $whereCondition, $parameters);
        $statements = $result->fetch_array();
        $result->free();
        
        if (isset($statements["hits"])) {
            return $statements["hits"];
        }
        
        return 0;
    }
    
    /**
     * Provides account statements of team.
     *
     * @param WebSoccer $websoccer Application context.
     * @param DbConnection $db DB connection.
     * @param int $teamId ID of team
     * @param int $startIndex fetch start index.
     * @param int $entries_per_page number of items to fetch.
     * @return array list of account statements.
     */
    public static function getAccountStatementsOfTeam(WebSoccer $websoccer, DbConnection $db, $teamId, $startIndex, $entries_per_page) {
        
        $columns["absender"] = "sender";
        $columns["betrag"] = "amount";
        $columns["datum"] = "date";
        $columns["verwendung"] = "subject";
        
        $limit = $startIndex .",". $entries_per_page;
        
        $fromTable = $websoccer->getConfig("db_prefix") . "_konto";
        
        $whereCondition = "verein_id = %d ORDER BY datum DESC";
        $parameters = $teamId;
        
        $result = $db->querySelect($columns, $fromTable, $whereCondition, $parameters, $limit);
        
        $statements = array();
        while ($statement = $result->fetch_array()) {
            $statements[] = $statement;
        }
        $result->free();
        
        return $statements;
    }
    
    /**
     * Credits specified amount to team's account (GIVING money).
     *
     * @param WebSoccer $websoccer Application context.
     * @param DbConnection $db DB connection.
     * @param int $teamId ID of team
     * @param int $amount Amount to credit. If 0, no statement will be created.
     * @param string $subject message key or untranslated message to display to user.
     * @param string $sender Name of sender.
     * @throws Exception if amount is negative or team could not be found.
     */
    public static function creditAmount(WebSoccer $websoccer, DbConnection $db, $teamId, $amount, $subject, $sender) {
        if ($amount == 0) {
            return;
        }
        
        $team = TeamsDataService::getTeamSummaryById($websoccer, $db, $teamId);
        if (!isset($team["team_id"])) {
            throw new Exception("team not found: " . $teamId);
        }
        
        if ($amount < 0) {
            throw new Exception("amount illegal: " . $amount);
        } else {
            self::createTransaction($websoccer, $db, $team, $teamId, $amount, $subject, $sender);
        }
        
    }
    
    /**
     * Debits specified amount from team's account (TAKING money).
     *
     * @param WebSoccer $websoccer Application context.
     * @param DbConnection $db DB connection.
     * @param int $teamId ID of team
     * @param int $amount Positive amount to debit. If 0, no statement will be created.
     * @param string $subject message key or untranslated message to display to user.
     * @param string $sender Name of sender.
     * @throws Exception if amount is negative or team could not be found.
     */
    public static function debitAmount(WebSoccer $websoccer, DbConnection $db, $teamId, $amount, $subject, $sender) {
        if ($amount == 0) {
            return;
        }
        
        $team = TeamsDataService::getTeamSummaryById($websoccer, $db, $teamId);
        if (!isset($team["team_id"])) {
            throw new Exception("team not found: " . $teamId);
        }
        
        if ($amount < 0) {
            throw new Exception("amount illegal: " . $amount);
        }
        
        $amount = 0 - $amount;
        
        self::createTransaction($websoccer, $db, $team, $teamId, $amount, $subject, $sender);
    }
    
    private static function createTransaction(WebSoccer $websoccer, DbConnection $db, $team, $teamId, $amount, $subject, $sender) {
        
        // ignore transaction if team is without user and option is enabled
        if (!$team["user_id"] && $websoccer->getConfig("no_transactions_for_teams_without_user")) {
            return;
        }
        
        // create transaction
        $fromTable = $websoccer->getConfig("db_prefix") ."_konto";
        $columns["verein_id"] = $teamId;
        $columns["absender"] = $sender;
        $columns["betrag"] = $amount;
        $columns["datum"] = $websoccer->getNowAsTimestamp();
        $columns["verwendung"] = $subject;
        $db->queryInsert($columns, $fromTable);
        
        // update team budget
        $newBudget = $team["team_budget"] + $amount;
        $updateColumns["finanz_budget"] = $newBudget;
        $fromTable = $websoccer->getConfig("db_prefix") ."_verein";
        $whereCondition = "id = %d";
        $parameters = $teamId;
        $db->queryUpdate($updateColumns, $fromTable, $whereCondition, $parameters);
    }
    
    public static function onMatchCompletedPayments(WebSoccer $websoccer, DbConnection $db, $teamId) {
        
    }
	
	public static function getAccountBalance(WebSoccer $websoccer, DbConnection $db, $teamId) {

		/*
			id
			verein_id
			absender
			betrag
			datum
			verwendung
		*/
		$columns = "SUM(betrag) AS balance";
        
        $fromTable = $websoccer->getConfig("db_prefix") . "_konto";
        
        $whereCondition = "verein_id = %d";
        $parameters = $teamId;
        
        $result = $db->querySelect($columns, $fromTable, $whereCondition, $parameters);
        $balance = $result->fetch_array();
        $result->free();
        
        if (isset($balance["balance"])) {
            return $balance["balance"];
        }
        
        return 0;
	}
	
	public static function getRevenuesBalance(WebSoccer $websoccer, DbConnection $db, $teamId) {

		$columns = "SUM(betrag) AS balance";
        
        $fromTable = $websoccer->getConfig("db_prefix") . "_konto";
        
        $whereCondition = "verein_id = %d AND betrag>=0";
        $parameters = $teamId;
        
        $result = $db->querySelect($columns, $fromTable, $whereCondition, $parameters);
        $balance = $result->fetch_array();
        $result->free();
        
        if (isset($balance["balance"])) {
            return $balance["balance"];
        }
        
        return 0;
	}
	
	public static function getRevenuesByTeamId(WebSoccer $websoccer, DbConnection $db, $teamId) {

		$columns = "verein_id, betrag, verwendung";
        
        $fromTable = $websoccer->getConfig("db_prefix") . "_konto";
        
        $whereCondition = "verein_id = %d AND betrag>=0 GROUP BY verwendung";
        $parameters = $teamId;
        
        $result = $db->querySelect($columns, $fromTable, $whereCondition, $parameters);
		while ($income = $result->fetch_array()) {
			$revenues[] = $income;
		}
		$result->free();
		
		return $revenues;
	}
	
	public static function getExpensesBalance(WebSoccer $websoccer, DbConnection $db, $teamId) {

		$columns = "SUM(betrag) AS balance";
        
        $fromTable = $websoccer->getConfig("db_prefix") . "_konto";
        
        $whereCondition = "verein_id = %d AND betrag<0";
        $parameters = $teamId;
        
        $result = $db->querySelect($columns, $fromTable, $whereCondition, $parameters);
        $balance = $result->fetch_array();
        $result->free();
        
        if (isset($balance["balance"])) {
            return $balance["balance"];
        }
        
        return 0;
	}
	
	public static function getExpensesByTeamId(WebSoccer $websoccer, DbConnection $db, $teamId) {

		$columns = "verein_id, betrag, verwendung";
        
        $fromTable = $websoccer->getConfig("db_prefix") . "_konto";
        
        $whereCondition = "verein_id = %d AND betrag<0 GROUP BY verwendung";
        $parameters = $teamId;
        
        $result = $db->querySelect($columns, $fromTable, $whereCondition, $parameters);
		while ($cost = $result->fetch_array()) {
			$expenses[] = $cost;
		}
		$result->free();
		
		return $expenses;
	}
	
	public static function groupedFinanceByTeamId(WebSoccer $websoccer, DbConnection $db, $teamId) {
		$teamId = (int) $teamId;
		$groups = self::getFinanceGroupDefinitions();
		$totalExpenseAmount = 0;

		$result = $db->querySelect(
			"verwendung, SUM(betrag) AS betrag",
			$websoccer->getConfig("db_prefix") . "_konto",
			"verein_id = %d GROUP BY verwendung",
			$teamId
		);

		while ($statement = $result->fetch_array()) {
			$subject = isset($statement['verwendung']) ? trim((string) $statement['verwendung']) : '';
			$amount = isset($statement['betrag']) ? (int) round($statement['betrag'], 0) : 0;

			if ($subject === '' || $amount === 0) {
				continue;
			}

			$groupKey = self::classifyFinanceSubject($subject);
			if (!isset($groups[$groupKey])) {
				$groupKey = 'other';
			}

			$groups[$groupKey]['entries'][] = array(
				'verwendung' => $subject,
				'betrag' => $amount
			);
			$groups[$groupKey]['balance'] += $amount;

			if ($amount > 0) {
				$groups[$groupKey]['revenues'] += $amount;
			} else {
				$groups[$groupKey]['expenses'] += $amount;
				$totalExpenseAmount += abs($amount);
			}
		}
		$result->free();

		$visibleGroups = array();
		foreach ($groups as $group) {
			if (!count($group['entries'])) {
				continue;
			}

			usort($group['entries'], function($first, $second) {
				$firstAmount = abs((int) $first['betrag']);
				$secondAmount = abs((int) $second['betrag']);
				if ($firstAmount === $secondAmount) {
					return strcmp((string) $first['verwendung'], (string) $second['verwendung']);
				}
				return ($firstAmount > $secondAmount) ? -1 : 1;
			});

			$group['is_high_loss'] = false;
			if ($group['balance'] < 0 && $totalExpenseAmount > 0) {
				$group['is_high_loss'] = (abs($group['balance']) / $totalExpenseAmount) >= 0.20;
			}

			$visibleGroups[] = $group;
		}

		return $visibleGroups;
	}

	/**
	 * Defines the fixed presentation order and labels of the finance overview.
	 * Entries are assigned by their account statement subject in
	 * classifyFinanceSubject().
	 *
	 * @return array
	 */
	private static function getFinanceGroupDefinitions() {
		return array(
			'match_operations' => self::createFinanceGroup('match_operations', 'finance_group_match_operations', 'icon-group'),
			'transfers' => self::createFinanceGroup('transfers', 'finance_group_transfers_contracts', 'icon-random'),
			'scouting' => self::createFinanceGroup('scouting', 'finance_group_scouting', 'icon-search'),
			'youth' => self::createFinanceGroup('youth', 'finance_group_youth', 'icon-star-empty'),
			'stadium' => self::createFinanceGroup('stadium', 'finance_group_stadium', 'icon-home'),
			'marketing' => self::createFinanceGroup('marketing', 'finance_group_marketing', 'icon-bullhorn'),
			'competitions' => self::createFinanceGroup('competitions', 'finance_group_competitions', 'icon-trophy'),
			'banking' => self::createFinanceGroup('banking', 'finance_group_banking', 'icon-briefcase'),
			'other' => self::createFinanceGroup('other', 'finance_group_other', 'icon-list-alt')
		);
	}

	private static function createFinanceGroup($key, $labelKey, $icon) {
		return array(
			'key' => $key,
			'label_key' => $labelKey,
			'icon' => $icon,
			'revenues' => 0,
			'expenses' => 0,
			'balance' => 0,
			'entries' => array(),
			'is_high_loss' => false
		);
	}

	/**
	 * Assigns historic and current account statement subjects to a meaningful
	 * finance area. Exact keys cover the regular bookings; keyword fallbacks
	 * also handle older installations and translated/raw legacy subjects.
	 *
	 * @param string $subject
	 * @return string
	 */
	private static function classifyFinanceSubject($subject) {
		$exactGroups = array(
			'transfers' => array(
				'player_transfer_message', 'directtransfer_subject', 'transfer_transaction_subject_handmoney',
				'lending_fee_subject', 'lending_salary_share_subject', 'lending_buy_fee_subject',
				'fireplayer_compensation_subject', 'youthteam_transferfee_subject', 'transfer_violation',
				'transfer_penalty_distribution', 'youth_transfer_violation'
			),
			'match_operations' => array(
				'match_salarypayment_subject', 'clubstaff_account_salary_subject', 'managerprofile_account_salary_subject',
				'training_trainer_salary_subject', 'trainingcamp_booking_costs_subject'
			),
			'scouting' => array(
				'scouting_scout_fee', 'scouting_scout_hire_fee', 'scouting_camp_fee',
				'scouting_department_maintenance_fee', 'scouting_department_build_cost',
				'scouting_department_upgrade_cost', 'scouting_proposal_transfer_fee',
				'youthteam_scouting_fee_subject'
			),
			'youth' => array(
				'youthteam_salarypayment_subject', 'youthteam_matchrequest_reward_subject',
				'youthacademy_account_build_subject', 'youthacademy_account_upgrade_subject',
				'youthacademy_account_maintenance_subject'
			),
			'stadium' => array(
				'stadium_extend_transaction_subject', 'stadium_upgrade_transaction_subject',
				'building_construction_fee_subject', 'stadiumenvironment_matchincome_subject',
				'stadiumenvironment_costs_per_match_subject', 'stadium_naming_payout_subject',
				'rivalries_derby_building_income_subject'
			),
			'marketing' => array(
				'match_sponsorpayment_subject', 'sponsor_championship_bonus_subject',
				'merchandising_development_cost_subject', 'merchandising_order_cost_subject',
				'merchandising_campaign_cost_subject', 'merchandising_liquidation_income_subject',
				'merchandising_stadium_revenue_subject', 'merchandising_online_revenue_subject',
				'merchandising_matchday_profit_subject'
			),
			'competitions' => array(
				'cup_cuproundaward_perround_subject', 'cup_cuproundaward_winner_subject',
				'cup_cuproundaward_second_subject', 'seasontarget_failed_penalty_subject',
				'seasontarget_accomplished_reward_subject', 'premium-exchange_team_subject'
			),
			'banking' => array(
				'bankloans_account_payout', 'bankloans_account_early_repayment', 'bankloans_account_installment',
				'buy_stock_message', 'sell_stock_message', 'team_on_stockmarket_message',
				'stockmarket_dividend_payment', 'stockmarket_dividend_income'
			)
		);

		foreach ($exactGroups as $groupKey => $subjects) {
			if (in_array($subject, $subjects, true)) {
				return $groupKey;
			}
		}

		$normalized = function_exists('mb_strtolower')
			? mb_strtolower($subject, 'UTF-8')
			: strtolower($subject);

		$keywordGroups = array(
			'transfers' => array('transfer', 'ablöse', 'handgeld', 'spielerleihe', 'leihgebühr', 'leihgebuehr', 'vertragsauflösung', 'spieler sprechen'),
			'scouting' => array('scout', 'scouting'),
			'youth' => array('jugendakademie', 'jugendkader', 'jugendspiel', 'nachwuchs'),
			'stadium' => array('stadion', 'baukosten', 'ausbaukosten', 'wartungsarbeiten', 'infrastruktur'),
			'marketing' => array('sponsor', 'merchandising', 'fanshop', 'marketing'),
			'competitions' => array('pokal', 'meisterschaft', 'saisonziel', 'prämie', 'praemie', 'belohnung'),
			'banking' => array('kredit', 'darlehen', 'aktie', 'dividende', 'börse', 'boerse', 'zins'),
			'match_operations' => array('gehalt', 'gehälter', 'gehaelter', 'training', 'physio', 'medizin', 'ticket', 'spielbetrieb', 'mitarbeiter')
		);

		foreach ($keywordGroups as $groupKey => $keywords) {
			foreach ($keywords as $keyword) {
				if (strpos($normalized, $keyword) !== false) {
					return $groupKey;
				}
			}
		}

		return 'other';
	}
	
	/**
	 * Provides the cash/budget development of a team based on all
	 * currently available account statements.
	 *
	 * Calculation:
	 * current budget - sum(all transactions) = reconstructed start budget
	 *
	 * Afterwards, all transactions are added chronologically so that
	 * the last value equals the current team budget.
	 *
	 * @param WebSoccer $websoccer Application context.
	 * @param DbConnection $db DB connection.
	 * @param int $teamId ID of team.
	 * @param int $currentBudget Current absolute team budget.
	 * @return array
	 */
	public static function getCashDevelopmentOfCurrentSeason(WebSoccer $websoccer, DbConnection $db, $teamId, $currentBudget) {
	    
	    $currentBudget = (int) $currentBudget;
	    
	    // 1. Sum of all existing transactions of this team
	    $result = $db->querySelect(
	        "SUM(betrag) AS transaction_sum",
	        $websoccer->getConfig("db_prefix") . "_konto",
	        "verein_id = %d",
	        $teamId,
	        1
	        );
	    
	    $sumRow = $result->fetch_array();
	    $result->free();
	    
	    $transactionSum = 0;
	    
	    if (isset($sumRow["transaction_sum"]) && $sumRow["transaction_sum"] !== null) {
	        $transactionSum = (int) $sumRow["transaction_sum"];
	    }
	    
	    // 2. Reconstruct start budget
	    $startBudget = $currentBudget - $transactionSum;
	    
	    $labels = array();
	    $values = array();
	    
	    $labels[] = "Start";
	    $values[] = $startBudget;
	    
	    // 3. Load all transactions chronologically
	    $columns = array();
	    $columns["datum"] = "date";
	    $columns["betrag"] = "amount";
	    
	    $result = $db->querySelect(
	        $columns,
	        $websoccer->getConfig("db_prefix") . "_konto",
	        "verein_id = %d ORDER BY datum ASC, id ASC",
	        $teamId
	        );
	    
	    $dailyTransactions = array();
	    
	    while ($statement = $result->fetch_array()) {
	        
	        $timestamp = (int) $statement["date"];
	        $amount = (int) $statement["amount"];
	        
	        $dateKey = date("Y-m-d", $timestamp);
	        
	        if (!isset($dailyTransactions[$dateKey])) {
	            $dailyTransactions[$dateKey] = array(
	                "timestamp" => $timestamp,
	                "amount" => 0
	            );
	        }
	        
	        $dailyTransactions[$dateKey]["amount"] += $amount;
	    }
	    
	    $result->free();
	    
	    // 4. Build running cash balance day by day
	    $runningBalance = $startBudget;
	    
	    foreach ($dailyTransactions as $dailyTransaction) {
	        
	        $runningBalance += (int) $dailyTransaction["amount"];
	        
	        $labels[] = date("d.m.Y", (int) $dailyTransaction["timestamp"]);
	        $values[] = $runningBalance;
	    }
	    
	    // 5. Ensure final point is the exact current budget
	    $labels[] = "Heute";
	    $values[] = $currentBudget;
	    
	    return array(
	        "labels" => $labels,
	        "values" => $values,
	        "start_budget" => $startBudget,
	        "current_budget" => $currentBudget
	    );
	}
	
	
	/**
	 * Returns the first scheduled league match date of the currently active season
	 * of the team's league.
	 *
	 * @param WebSoccer $websoccer Application context.
	 * @param DbConnection $db DB connection.
	 * @param int $teamId ID of team.
	 * @return int Unix timestamp or 0
	 */
	private static function getCurrentSeasonStartDateOfTeam(WebSoccer $websoccer, DbConnection $db, $teamId) {
	    
	    $team = TeamsDataService::getTeamSummaryById($websoccer, $db, $teamId);
	    
	    if (!isset($team["team_league_id"]) || (int) $team["team_league_id"] < 1) {
	        return 0;
	    }
	    
	    // Get currently active season of the team's league.
	    $result = $db->querySelect(
	        "id",
	        $websoccer->getConfig("db_prefix") . "_saison",
	        "liga_id = %d AND beendet = '0' ORDER BY name DESC",
	        (int) $team["team_league_id"],
	        1
	        );
	    
	    $season = $result->fetch_array();
	    $result->free();
	    
	    if (!isset($season["id"]) || (int) $season["id"] < 1) {
	        return 0;
	    }
	    
	    // First match date of this active season.
	    $result = $db->querySelect(
	        "MIN(datum) AS season_start",
	        $websoccer->getConfig("db_prefix") . "_spiel",
	        "saison_id = %d",
	        (int) $season["id"],
	        1
	        );
	    
	    $seasonDate = $result->fetch_array();
	    $result->free();
	    
	    if (isset($seasonDate["season_start"]) && (int) $seasonDate["season_start"] > 0) {
	        return (int) $seasonDate["season_start"];
	    }
	    
	    return 0;
	}
	
	public static function payTaxes(WebSoccer $websoccer, DbConnection $db) {
		
		$tax_rate = '0.19';
		$now =$websoccer->getNowAsTimestamp();

		$sqlStr = "SELECT verein_id, SUM(betrag) AS balance
					FROM " . $websoccer->getConfig("db_prefix") . "_konto
					ORDER BY verein_id";
		$result = $db->executeQuery($sqlStr);
		while ($team = $result->fetch_array()) {
			
			$balance = $team['balance'];
			if($balance>0) {
				
				$tax = $team['balance']*$tax_rate;
				
				// pay tax (deduct from budget)
				$taxStr = "UPDATE " . $websoccer->getConfig("db_prefix") . "_verein
							SET finanz_budget=finanz_budget-".$tax."
							WHERE id='".$team['verein_id']."'";
				$db->executeQuery($taxStr);
				
				// bank account
				$kontoStr = "INSERT INTO " . $websoccer->getConfig("db_prefix") . "_konto (verein_id, absender, betrag, datum, verwendung) 
								VALUES ('".$team['verein_id']."', 'Bank', '".$tax*(-1)."', '".$now."', 'tax_payment_message')";
				$db->executeQuery($kontoStr);
				
				// add to penalty table
				$penStr = "UPDATE " . $websoccer->getConfig("db_prefix") . "_penalty SET budget=budget+$tax";
				$db->executeQuery($penStr);
			}
			
		}
		$result->free();

	}
	
	public static function clearAccount(WebSoccer $websoccer, DbConnection $db) {
	    
	    $sqlStr = "TRUNCATE TABLE " . $websoccer->getConfig("db_prefix") . "_konto";
	    $db->executeQuery($sqlStr);
	    
	}
}
?>