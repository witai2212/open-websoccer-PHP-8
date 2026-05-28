<?php

/**
 * Provides the complete loan overview for the transfer section.
 */
class LoanOverviewModel implements IModel {
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
		if (!$teamId) {
			throw new Exception($this->_i18n->getMessage('feature_requires_team'));
		}

		return array(
			'loaned_out_players' => $this->getLoanedOutPlayers($teamId),
			'borrowed_players' => $this->getBorrowedPlayers($teamId),
			'loan_offers' => $this->getOwnLoanOffers($teamId),
			'available_loan_players' => $this->getAvailableLoanPlayers($teamId)
		);
	}

	private function getLoanedOutPlayers($teamId) {
		$dbPrefix = $this->_websoccer->getConfig('db_prefix');
		$query = "
			SELECT P.id, P.vorname, P.nachname, P.kunstname, P.position, P.position_main, P.lending_matches, P.lending_fee,
			       B.name AS borrower_name, L.id AS loan_id, L.matches_completed, L.total_matches, L.remaining_matches,
			       L.salary_share_percent, L.option_type, L.buy_fee, L.status, L.min_recall_matches,
			       COALESCE(SUM(R.minutes_played), 0) AS loan_minutes,
			       COALESCE(AVG(NULLIF(R.grade, 0)), 0) AS avg_grade,
			       COALESCE(SUM(R.goals), 0) AS goals,
			       COALESCE(SUM(R.assists), 0) AS assists,
			       COALESCE(SUM(R.development_bonus), 0) AS development_bonus,
			       COALESCE(AVG(R.destination_quality), 0) AS destination_quality,
			       COUNT(R.id) AS reports
			FROM ". $dbPrefix ."_spieler AS P
			INNER JOIN ". $dbPrefix ."_verein AS B ON B.id = P.verein_id
			LEFT JOIN ". $dbPrefix ."_loan AS L ON L.player_id = P.id AND L.status = 'active'
			LEFT JOIN ". $dbPrefix ."_loan_report AS R ON R.loan_id = L.id
			WHERE P.status = '1'
			  AND P.lending_owner_id = '". (int) $teamId ."'
			GROUP BY P.id
			ORDER BY P.lending_matches ASC, P.position ASC, P.nachname ASC";

		return $this->fetchLoanRows($query, true);
	}

	private function getBorrowedPlayers($teamId) {
		$dbPrefix = $this->_websoccer->getConfig('db_prefix');
		$query = "
			SELECT P.id, P.vorname, P.nachname, P.kunstname, P.position, P.position_main, P.lending_matches, P.lending_fee,
			       O.name AS lender_name, L.id AS loan_id, L.matches_completed, L.total_matches, L.remaining_matches,
			       L.salary_share_percent, L.option_type, L.buy_fee, L.status, L.min_recall_matches,
			       COALESCE(SUM(R.minutes_played), 0) AS loan_minutes,
			       COALESCE(AVG(NULLIF(R.grade, 0)), 0) AS avg_grade,
			       COALESCE(SUM(R.goals), 0) AS goals,
			       COALESCE(SUM(R.assists), 0) AS assists,
			       COALESCE(SUM(R.development_bonus), 0) AS development_bonus,
			       COALESCE(AVG(R.destination_quality), 0) AS destination_quality,
			       COUNT(R.id) AS reports
			FROM ". $dbPrefix ."_spieler AS P
			INNER JOIN ". $dbPrefix ."_verein AS O ON O.id = P.lending_owner_id
			LEFT JOIN ". $dbPrefix ."_loan AS L ON L.player_id = P.id AND L.status = 'active'
			LEFT JOIN ". $dbPrefix ."_loan_report AS R ON R.loan_id = L.id
			WHERE P.status = '1'
			  AND P.verein_id = '". (int) $teamId ."'
			  AND P.lending_owner_id > 0
			GROUP BY P.id
			ORDER BY P.lending_matches ASC, P.position ASC, P.nachname ASC";

		return $this->fetchLoanRows($query, false);
	}

	private function getOwnLoanOffers($teamId) {
		$dbPrefix = $this->_websoccer->getConfig('db_prefix');
		$query = "
			SELECT P.id, P.vorname, P.nachname, P.kunstname, P.position, P.position_main, P.lending_fee,
			       O.salary_share_percent, O.option_type, O.buy_fee
			FROM ". $dbPrefix ."_spieler AS P
			LEFT JOIN ". $dbPrefix ."_loan_offer AS O ON O.player_id = P.id AND O.status = 'open'
			WHERE P.status = '1'
			  AND P.verein_id = '". (int) $teamId ."'
			  AND P.lending_fee > 0
			  AND (P.lending_owner_id IS NULL OR P.lending_owner_id = 0)
			ORDER BY P.position ASC, P.nachname ASC";

		return $this->fetchSimpleRows($query);
	}

	private function getAvailableLoanPlayers($teamId) {
		$dbPrefix = $this->_websoccer->getConfig('db_prefix');
		$query = "
			SELECT P.id, P.vorname, P.nachname, P.kunstname, P.position, P.position_main, P.lending_fee, P.vertrag_gehalt,
			       C.name AS team_name, O.salary_share_percent, O.option_type, O.buy_fee
			FROM ". $dbPrefix ."_spieler AS P
			INNER JOIN ". $dbPrefix ."_verein AS C ON C.id = P.verein_id
			LEFT JOIN ". $dbPrefix ."_loan_offer AS O ON O.player_id = P.id AND O.status = 'open'
			WHERE P.status = '1'
			  AND P.verein_id <> '". (int) $teamId ."'
			  AND P.transfermarkt <> '1'
			  AND P.lending_fee > 0
			  AND (P.lending_owner_id IS NULL OR P.lending_owner_id = 0)
			ORDER BY P.position ASC, P.w_staerke DESC, P.nachname ASC
			LIMIT 100";

		return $this->fetchSimpleRows($query);
	}

	private function fetchLoanRows($query, $includeRecall) {
		$result = $this->_db->executeQuery($query);
		$rows = array();
		while ($row = $result->fetch_assoc()) {
			$row['position'] = PlayersDataService::_convertPosition($row['position']);
			$row['can_recall'] = false;
			if ($includeRecall && !empty($row['loan_id'])) {
				$row['can_recall'] = LoanDataService::canRecallLoan($this->_websoccer, $this->_db, $row);
			}
			$rows[] = $row;
		}
		$result->free();
		return $rows;
	}

	private function fetchSimpleRows($query) {
		$result = $this->_db->executeQuery($query);
		$rows = array();
		while ($row = $result->fetch_assoc()) {
			$row['position'] = PlayersDataService::_convertPosition($row['position']);
			if (!isset($row['salary_share_percent']) || $row['salary_share_percent'] === null || $row['salary_share_percent'] === '') {
				$row['salary_share_percent'] = 100;
			}
			if (!isset($row['option_type']) || $row['option_type'] === null || $row['option_type'] === '') {
				$row['option_type'] = LoanDataService::OPTION_NONE;
			}
			if (!isset($row['buy_fee']) || $row['buy_fee'] === null) {
				$row['buy_fee'] = 0;
			}
			$rows[] = $row;
		}
		$result->free();
		return $rows;
	}
}

?>
