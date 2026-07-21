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
 * @author Ingo Hofmann
 */
class FinancesModel implements IModel {
	private $_db;
	private $_i18n;
	private $_websoccer;
	
	public function __construct($db, $i18n, $websoccer) {
		$this->_db = $db;
		$this->_i18n = $i18n;
		$this->_websoccer = $websoccer;
	}
	
	public function renderView() {
		return TRUE;
	}
	
	public function getTemplateParameters() {
		
		$teamId = $this->_websoccer->getUser()->getClubId($this->_websoccer, $this->_db);
		if ($teamId < 1) {
			throw new Exception($this->_i18n->getMessage("feature_requires_team"));
		}
		
		$team = TeamsDataService::getTeamSummaryById($this->_websoccer, $this->_db, $teamId);
		
		$count = BankAccountDataService::countAccountStatementsOfTeam($this->_websoccer, $this->_db, $teamId);
		$eps = $this->_websoccer->getConfig("entries_per_page");
		$paginator = new Paginator($count, $eps, $this->_websoccer);
		
		if ($count > 0) {
			$statements = BankAccountDataService::getAccountStatementsOfTeam($this->_websoccer, $this->_db, $teamId, $paginator->getFirstIndex(), $eps);
		} else {
			$statements = array();
		}
		
		$statementGroups = $this->_groupStatementsByDate($statements);

		$stockmarketCriteria = StockMarketDataService::clubStockmarketCriteria($this->_websoccer, $this->_db, $teamId);
		$stockmarketInfo = StockMarketDataService::getClubStockmarketListingInfo($this->_websoccer, $this->_db, $teamId);
		$balance = BankAccountDataService::getAccountBalance($this->_websoccer, $this->_db, $teamId);
		
		$total_revenues = BankAccountDataService::getRevenuesBalance($this->_websoccer, $this->_db, $teamId);
		$total_expenses = BankAccountDataService::getExpensesBalance($this->_websoccer, $this->_db, $teamId);
		
		$expenses = BankAccountDataService::getExpensesByTeamId($this->_websoccer, $this->_db, $teamId);
		$revenues = BankAccountDataService::getRevenuesByTeamId($this->_websoccer, $this->_db, $teamId);
		
		$financeGroups = BankAccountDataService::groupedFinanceByTeamId($this->_websoccer, $this->_db, $teamId);
		
		$cashDevelopment = BankAccountDataService::getCashDevelopmentOfCurrentSeason(
		    $this->_websoccer,
		    $this->_db,
		    $teamId,
		    $team["team_budget"]
		    );
		
		$transferPenaltySummary = $this->_getTransferPenaltySummary($teamId, $team);
		
		return array(
		    "budget" => $team["team_budget"],
		    "team_id" => $teamId,
		    "statements" => $statements,
		    "statement_groups" => $statementGroups,
		    "stockmarketCriteria" => $stockmarketCriteria,
		    "stockmarket_info" => $stockmarketInfo,
		    "club_value" => $stockmarketInfo["club_value"],
		    "balance" => $balance,
		    "total_revenues" => $total_revenues,
		    "total_expenses" => $total_expenses,
		    "finance_groups" => $financeGroups,
		    "expenses" => $expenses,
		    "paginator" => $paginator,
		    
		    "cash_chart_labels" => $cashDevelopment["labels"],
		    "cash_chart_values" => $cashDevelopment["values"],
		    "cash_chart_season_start" => $cashDevelopment["season_start"],
		    "transfer_penalty_summary" => $transferPenaltySummary
		);
		
	}
	

	private function _groupStatementsByDate($statements) {
		$groups = array();
		foreach ($statements as $statement) {
			$timestamp = isset($statement["date"]) ? (int) $statement["date"] : 0;
			$key = $timestamp > 0 ? date("Y-m-d", $timestamp) : "unknown";
			if (!isset($groups[$key])) {
				$groups[$key] = array(
					"key" => str_replace("-", "", $key),
					"date" => $timestamp,
					"revenues" => 0,
					"expenses" => 0,
					"balance" => 0,
					"statements" => array()
				);
			}
			$amount = isset($statement["amount"]) ? (int) $statement["amount"] : 0;
			if ($amount >= 0) {
				$groups[$key]["revenues"] += $amount;
			} else {
				$groups[$key]["expenses"] += $amount;
			}
			$groups[$key]["balance"] += $amount;
			$groups[$key]["statements"][] = $statement;
		}
		return array_values($groups);
	}

	/**
	 * Builds a compact transfer penalty summary for the finances page.
	 * Penalties are booked as normal account statements with subject
	 * transfer_violation. The global penalty pot is stored in cm23_penalty.
	 *
	 * @param int $teamId
	 * @param array $team
	 * @return array
	 */
	private function _getTransferPenaltySummary($teamId, $team) {
	    $teamId = (int) $teamId;
	    $seasonStart = 0;
	    
	    if (isset($team["team_league_id"]) && (int) $team["team_league_id"] > 0) {
	        $seasonStart = $this->_getCurrentSeasonStartDate((int) $team["team_league_id"]);
	    }
	    
	    $whereCondition = "verein_id = %d AND verwendung = 'transfer_violation'";
	    $parameters = array($teamId);
	    
	    if ($seasonStart > 0) {
	        $whereCondition .= " AND datum >= %d";
	        $parameters[] = $seasonStart;
	    }
	    
	    $result = $this->_db->querySelect(
	        "COALESCE(SUM(ABS(betrag)), 0) AS season_total",
	        $this->_websoccer->getConfig("db_prefix") . "_konto",
	        $whereCondition,
	        $parameters,
	        1
	        );
	    
	    $seasonPenalty = $result->fetch_array();
	    $result->free();
	    
	    $seasonTotal = 0;
	    if (isset($seasonPenalty["season_total"]) && $seasonPenalty["season_total"] !== null) {
	        $seasonTotal = (int) round($seasonPenalty["season_total"], 0);
	    }
	    
	    $result = $this->_db->querySelect(
	        "ABS(betrag) AS last_amount, datum AS last_date",
	        $this->_websoccer->getConfig("db_prefix") . "_konto",
	        "verein_id = %d AND verwendung = 'transfer_violation' ORDER BY datum DESC, id DESC",
	        $teamId,
	        1
	        );
	    
	    $lastPenalty = $result->fetch_array();
	    $result->free();
	    
	    $lastAmount = 0;
	    $lastDate = 0;
	    
	    if (isset($lastPenalty["last_amount"])) {
	        $lastAmount = (int) round($lastPenalty["last_amount"], 0);
	    }
	    
	    if (isset($lastPenalty["last_date"])) {
	        $lastDate = (int) $lastPenalty["last_date"];
	    }

	    $result = $this->_db->querySelect(
	        "betrag AS last_distribution_amount, datum AS last_distribution_date",
	        $this->_websoccer->getConfig("db_prefix") . "_konto",
	        "verein_id = %d AND verwendung = 'transfer_penalty_distribution' ORDER BY datum DESC, id DESC",
	        $teamId,
	        1
	        );

	    $lastDistribution = $result->fetch_array();
	    $result->free();

	    $lastDistributionAmount = 0;
	    $lastDistributionDate = 0;

	    if (isset($lastDistribution["last_distribution_amount"])) {
	        $lastDistributionAmount = (int) round($lastDistribution["last_distribution_amount"], 0);
	    }

	    if (isset($lastDistribution["last_distribution_date"])) {
	        $lastDistributionDate = (int) $lastDistribution["last_distribution_date"];
	    }
	    
	    $penaltyPot = 0;
	    $penaltyTable = $this->_websoccer->getConfig("db_prefix") . "_penalty";
	    $result = $this->_db->executeQuery("SELECT COALESCE(SUM(penalty), 0) AS penalty_pot FROM " . $penaltyTable);
	    $potRow = $result->fetch_array();
	    $result->free();
	    
	    if (isset($potRow["penalty_pot"]) && $potRow["penalty_pot"] !== null) {
	        $penaltyPot = (int) round($potRow["penalty_pot"], 0);
	    }
	    
	    return array(
	        "season_total" => $seasonTotal,
	        "last_amount" => $lastAmount,
	        "last_date" => $lastDate,
	        "last_distribution_amount" => $lastDistributionAmount,
	        "last_distribution_date" => $lastDistributionDate,
	        "penalty_pot" => $penaltyPot,
	        "season_start" => $seasonStart
	    );
	}
	
	/**
	 * Returns the first scheduled match date of the active season of the
	 * current league. Used to keep the penalty summary season-based even
	 * though account statements do not store a season id.
	 *
	 * @param int $leagueId
	 * @return int Unix timestamp or 0
	 */
	private function _getCurrentSeasonStartDate($leagueId) {
	    $leagueId = (int) $leagueId;
	    
	    if ($leagueId < 1) {
	        return 0;
	    }
	    
	    $result = $this->_db->querySelect(
	        "id",
	        $this->_websoccer->getConfig("db_prefix") . "_saison",
	        "liga_id = %d AND beendet = '0' ORDER BY name DESC",
	        $leagueId,
	        1
	        );
	    
	    $season = $result->fetch_array();
	    $result->free();
	    
	    if (!isset($season["id"]) || (int) $season["id"] < 1) {
	        return 0;
	    }
	    
	    $result = $this->_db->querySelect(
	        "MIN(datum) AS season_start",
	        $this->_websoccer->getConfig("db_prefix") . "_spiel",
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
	
}

?>