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
		
		$statements = [];

		$sqlStr = "SELECT verwendung, SUM(betrag) as betrag
					FROM " . $websoccer->getConfig("db_prefix") . "_konto
					WHERE verein_id='$teamId'
					GROUP BY verwendung ORDER BY betrag, verwendung";
		$result = $db->executeQuery($sqlStr);

		while ($cost = $result->fetch_array()) {
			$statements[] = $cost;
		}
		$result->free();
		
		/*echo"<pre>";
		print_r($statements);
		echo"</pre>";*/
		return $statements;
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
	
	/**
	 * get data from fixed deposit account
	 */
	public static function getFixedDepositByTeamId(WebSoccer $websoccer, DbConnection $db, $teamId) {
	    
	    $deposit = array();
	    
	    $sqlStr = "SELECT * FROM " . $websoccer->getConfig("db_prefix") . "_bank
					WHERE verein_id='".$teamId."'";
	    $result = $db->executeQuery($sqlStr);
	    while ($team = $result->fetch_array()) {
	        $deposit[] = $team;
	    }
	    
	    return $deposit;
	    
	}
}
?>