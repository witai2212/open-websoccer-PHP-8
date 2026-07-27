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
			'incoming_loan_requests' => $this->getIncomingLoanRequests($teamId),
			'outgoing_loan_requests' => $this->getOutgoingLoanRequests($teamId),
			'loan_offers' => $this->getOwnLoanOffers($teamId),
			'available_loan_players' => $this->getAvailableLoanPlayers($teamId)
		);
	}

	private function getLoanedOutPlayers($teamId) {
		$dbPrefix = $this->_websoccer->getConfig('db_prefix');
		$query = "
			SELECT P.id, P.vorname, P.nachname, P.kunstname, P.position, P.position_main,
			       P.lending_matches, P.lending_fee, B.name AS borrower_name
			FROM ". $dbPrefix ."_spieler AS P
			INNER JOIN ". $dbPrefix ."_verein AS B ON B.id = P.verein_id
			WHERE P.status = '1'
			  AND P.lending_owner_id = '". (int) $teamId ."'
			ORDER BY P.lending_matches ASC, P.position ASC, P.nachname ASC";

		return $this->decorateLoanRows($this->fetchSimpleRows($query), true);
	}

	private function getBorrowedPlayers($teamId) {
		$dbPrefix = $this->_websoccer->getConfig('db_prefix');
		$query = "
			SELECT P.id, P.vorname, P.nachname, P.kunstname, P.position, P.position_main,
			       P.lending_matches, P.lending_fee, O.name AS lender_name
			FROM ". $dbPrefix ."_spieler AS P
			INNER JOIN ". $dbPrefix ."_verein AS O ON O.id = P.lending_owner_id
			WHERE P.status = '1'
			  AND P.verein_id = '". (int) $teamId ."'
			  AND P.lending_owner_id > 0
			ORDER BY P.lending_matches ASC, P.position ASC, P.nachname ASC";

		return $this->decorateLoanRows($this->fetchSimpleRows($query), false);
	}

	private function getIncomingLoanRequests($teamId) {
		$dbPrefix = $this->_websoccer->getConfig('db_prefix');
		$query = "
			SELECT R.id AS request_id, R.requested_matches, R.loan_fee_per_match, R.total_fee,
			       R.salary_share_percent, R.option_type, R.buy_fee, R.created_by_computer, R.created_date,
			       P.id, P.vorname, P.nachname, P.kunstname, P.position, P.position_main,
			       B.name AS borrower_name
			FROM ". $dbPrefix ."_loan_request AS R
			INNER JOIN ". $dbPrefix ."_spieler AS P ON P.id = R.player_id
			INNER JOIN ". $dbPrefix ."_verein AS B ON B.id = R.borrower_team_id
			WHERE R.lender_team_id = '". (int) $teamId ."'
			  AND R.status = 'open'
			ORDER BY R.created_date ASC, P.position ASC, P.nachname ASC";

		return $this->fetchRequestRows($query);
	}

	private function getOutgoingLoanRequests($teamId) {
		$dbPrefix = $this->_websoccer->getConfig('db_prefix');
		$query = "
			SELECT R.id AS request_id, R.requested_matches, R.loan_fee_per_match, R.total_fee,
			       R.salary_share_percent, R.option_type, R.buy_fee, R.created_by_computer, R.created_date,
			       P.id, P.vorname, P.nachname, P.kunstname, P.position, P.position_main,
			       L.name AS lender_name
			FROM ". $dbPrefix ."_loan_request AS R
			INNER JOIN ". $dbPrefix ."_spieler AS P ON P.id = R.player_id
			INNER JOIN ". $dbPrefix ."_verein AS L ON L.id = R.lender_team_id
			WHERE R.borrower_team_id = '". (int) $teamId ."'
			  AND R.status = 'open'
			ORDER BY R.created_date ASC, P.position ASC, P.nachname ASC";

		return $this->fetchRequestRows($query);
	}

	private function getOwnLoanOffers($teamId) {
		$dbPrefix = $this->_websoccer->getConfig('db_prefix');
		$query = "
			SELECT P.id, P.vorname, P.nachname, P.kunstname, P.position, P.position_main, P.lending_fee
			FROM ". $dbPrefix ."_spieler AS P
			WHERE P.status = '1'
			  AND P.verein_id = '". (int) $teamId ."'
			  AND P.lending_fee > 0
			  AND (P.lending_owner_id IS NULL OR P.lending_owner_id = 0)
			ORDER BY P.position ASC, P.nachname ASC";

		$rows = $this->fetchSimpleRows($query);
		return $this->applyOpenLoanOffers($rows);
	}

	private function getAvailableLoanPlayers($teamId) {
		$dbPrefix = $this->_websoccer->getConfig('db_prefix');
		if (class_exists('ClubPartnershipDataService')) {
			ClubPartnershipDataService::ensureSchema($this->_websoccer, $this->_db);
		}

		$partnerships = $this->getPreferredLoanPartnerships($teamId);
		$preferredOrder = '';
		if (count($partnerships)) {
			$preferredTeamIds = array_map('intval', array_keys($partnerships));
			$preferredOrder = 'CASE WHEN P.verein_id IN (' . implode(',', $preferredTeamIds) . ') THEN 0 ELSE 1 END ASC, ';
		}

		$query = "
			SELECT P.id, P.vorname, P.nachname, P.kunstname, P.position, P.position_main,
			       P.lending_fee, P.vertrag_gehalt, C.id AS team_id, C.name AS team_name, C.user_id AS team_user_id
			FROM ". $dbPrefix ."_spieler AS P
			INNER JOIN ". $dbPrefix ."_verein AS C ON C.id = P.verein_id
			WHERE P.status = '1'
			  AND P.verein_id <> '". (int) $teamId ."'
			  AND P.transfermarkt <> '1'
			  AND P.lending_fee > 0
			  AND (P.lending_owner_id IS NULL OR P.lending_owner_id = 0)
			ORDER BY ". $preferredOrder ."P.position ASC, P.w_staerke DESC, P.nachname ASC";

		$rows = $this->applyOpenLoanOffers($this->fetchSimpleRows($query));
		foreach ($rows as &$row) {
			$teamIdOfPlayer = isset($row['team_id']) ? (int) $row['team_id'] : 0;
			if ($teamIdOfPlayer > 0 && isset($partnerships[$teamIdOfPlayer])) {
				$row['partnership_id'] = (int) $partnerships[$teamIdOfPlayer]['id'];
				$row['partnership_development_bonus'] = (int) $partnerships[$teamIdOfPlayer]['development_bonus_percent'];
			} else {
				$row['partnership_id'] = 0;
				$row['partnership_development_bonus'] = 0;
			}
		}
		unset($row);

		return $rows;
	}

	private function decorateLoanRows($rows, $includeRecall) {
		if (!count($rows)) {
			return $rows;
		}

		$playerIds = array();
		foreach ($rows as $row) {
			$playerIds[] = (int) $row['id'];
		}

		$loans = $this->getActiveLoansByPlayerIds($playerIds);
		$loanIds = array();
		foreach ($loans as $loan) {
			$loanIds[] = (int) $loan['loan_id'];
		}
		$reports = $this->getLoanReportSummaries($loanIds);

		foreach ($rows as &$row) {
			$playerId = (int) $row['id'];
			$row['loan_id'] = 0;
			$row['matches_completed'] = 0;
			$row['total_matches'] = 0;
			$row['remaining_matches'] = (int) $row['lending_matches'];
			$row['salary_share_percent'] = 100;
			$row['option_type'] = LoanDataService::OPTION_NONE;
			$row['buy_fee'] = 0;
			$row['status'] = '';
			$row['min_recall_matches'] = LoanDataService::MIN_RECALL_MATCHES;
			$row['loan_minutes'] = 0;
			$row['avg_grade'] = 0;
			$row['goals'] = 0;
			$row['assists'] = 0;
			$row['development_bonus'] = 0;
			$row['destination_quality'] = 0;
			$row['reports'] = 0;

			if (isset($loans[$playerId])) {
				$loan = $loans[$playerId];
				$row['loan_id'] = (int) $loan['loan_id'];
				$row['matches_completed'] = (int) $loan['matches_completed'];
				$row['total_matches'] = (int) $loan['total_matches'];
				$row['remaining_matches'] = (int) $loan['remaining_matches'];
				$row['salary_share_percent'] = (int) $loan['salary_share_percent'];
				$row['option_type'] = LoanDataService::normalizeOptionType($loan['option_type']);
				$row['buy_fee'] = (int) $loan['buy_fee'];
				$row['status'] = $loan['status'];
				$row['min_recall_matches'] = (int) $loan['min_recall_matches'];

				$loanId = (int) $loan['loan_id'];
				if (isset($reports[$loanId])) {
					$row['loan_minutes'] = (int) $reports[$loanId]['loan_minutes'];
					$row['avg_grade'] = (float) $reports[$loanId]['avg_grade'];
					$row['goals'] = (int) $reports[$loanId]['goals'];
					$row['assists'] = (int) $reports[$loanId]['assists'];
					$row['development_bonus'] = (float) $reports[$loanId]['development_bonus'];
					$row['destination_quality'] = (float) $reports[$loanId]['destination_quality'];
					$row['reports'] = (int) $reports[$loanId]['reports'];
				}
			}

			$row['can_recall'] = false;
			if ($includeRecall && $row['loan_id'] > 0) {
				$recallLoan = $row;
				$recallLoan['id'] = $row['loan_id'];
				$row['can_recall'] = LoanDataService::canRecallLoan($this->_websoccer, $this->_db, $recallLoan);
			}
		}
		unset($row);

		return $rows;
	}

	private function getActiveLoansByPlayerIds($playerIds) {
		$loans = array();
		if (!count($playerIds)) {
			return $loans;
		}

		$table = $this->_websoccer->getConfig('db_prefix') . '_loan';
		foreach (array_chunk(array_unique(array_map('intval', $playerIds)), 500) as $chunk) {
			$query = "SELECT id AS loan_id, player_id, matches_completed, total_matches, remaining_matches,
			                 salary_share_percent, option_type, buy_fee, status, min_recall_matches
			          FROM ". $table ."
			          WHERE status = 'active' AND player_id IN (". implode(',', $chunk) .")
			          ORDER BY id DESC";
			$result = $this->_db->executeQuery($query);
			while ($row = $result->fetch_assoc()) {
				$playerId = (int) $row['player_id'];
				if (!isset($loans[$playerId])) {
					$loans[$playerId] = $row;
				}
			}
			$result->free();
		}
		return $loans;
	}

	private function getLoanReportSummaries($loanIds) {
		$reports = array();
		if (!count($loanIds)) {
			return $reports;
		}

		$table = $this->_websoccer->getConfig('db_prefix') . '_loan_report';
		foreach (array_chunk(array_unique(array_map('intval', $loanIds)), 500) as $chunk) {
			$query = "SELECT loan_id,
			                 COALESCE(SUM(minutes_played), 0) AS loan_minutes,
			                 COALESCE(AVG(NULLIF(grade, 0)), 0) AS avg_grade,
			                 COALESCE(SUM(goals), 0) AS goals,
			                 COALESCE(SUM(assists), 0) AS assists,
			                 COALESCE(SUM(development_bonus), 0) AS development_bonus,
			                 COALESCE(AVG(destination_quality), 0) AS destination_quality,
			                 COUNT(id) AS reports
			          FROM ". $table ."
			          WHERE loan_id IN (". implode(',', $chunk) .")
			          GROUP BY loan_id";
			$result = $this->_db->executeQuery($query);
			while ($row = $result->fetch_assoc()) {
				$reports[(int) $row['loan_id']] = $row;
			}
			$result->free();
		}
		return $reports;
	}

	private function applyOpenLoanOffers($rows) {
		if (!count($rows)) {
			return $rows;
		}

		$playerIds = array();
		foreach ($rows as $row) {
			$playerIds[] = (int) $row['id'];
		}
		$offers = $this->getOpenLoanOffersByPlayerIds($playerIds);

		foreach ($rows as &$row) {
			$playerId = (int) $row['id'];
			if (isset($offers[$playerId])) {
				$row['salary_share_percent'] = (int) $offers[$playerId]['salary_share_percent'];
				$row['option_type'] = LoanDataService::normalizeOptionType($offers[$playerId]['option_type']);
				$row['buy_fee'] = (int) $offers[$playerId]['buy_fee'];
			}
		}
		unset($row);

		return $rows;
	}

	private function getOpenLoanOffersByPlayerIds($playerIds) {
		$offers = array();
		if (!count($playerIds)) {
			return $offers;
		}

		$table = $this->_websoccer->getConfig('db_prefix') . '_loan_offer';
		foreach (array_chunk(array_unique(array_map('intval', $playerIds)), 500) as $chunk) {
			$query = "SELECT id, player_id, salary_share_percent, option_type, buy_fee
			          FROM ". $table ."
			          WHERE status = 'open' AND player_id IN (". implode(',', $chunk) .")
			          ORDER BY id DESC";
			$result = $this->_db->executeQuery($query);
			while ($row = $result->fetch_assoc()) {
				$playerId = (int) $row['player_id'];
				if (!isset($offers[$playerId])) {
					$offers[$playerId] = $row;
				}
			}
			$result->free();
		}
		return $offers;
	}

	private function getPreferredLoanPartnerships($teamId) {
		$partnerships = array();
		$table = $this->_websoccer->getConfig('db_prefix') . '_club_partnership';
		$query = "SELECT id, parent_team_id, development_bonus_percent
		          FROM ". $table ."
		          WHERE partner_team_id = '". (int) $teamId ."'
		            AND status = 'active'
		            AND preferred_loans = '1'
		          ORDER BY updated_date DESC, id DESC";
		$result = $this->_db->executeQuery($query);
		while ($row = $result->fetch_assoc()) {
			$parentTeamId = (int) $row['parent_team_id'];
			if (!isset($partnerships[$parentTeamId])) {
				$partnerships[$parentTeamId] = $row;
			}
		}
		$result->free();
		return $partnerships;
	}


	private function fetchRequestRows($query) {
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
