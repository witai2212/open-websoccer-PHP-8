<?php

/**
 * Data service for loan requests between clubs.
 *
 * A loan_offer means: a player is available for loan.
 * A loan_request means: another club wants to borrow this player and the lender must approve it.
 */
class LoanRequestDataService {
    const STATUS_OPEN = 'open';
    const STATUS_ACCEPTED = 'accepted';
    const STATUS_REJECTED = 'rejected';
    const STATUS_EXPIRED = 'expired';

    public static function getRequestById(WebSoccer $websoccer, DbConnection $db, $requestId) {
        $table = $websoccer->getConfig('db_prefix') . '_loan_request';
        $result = $db->querySelect('*', $table, 'id = %d', (int) $requestId, 1);
        $row = $result->fetch_array();
        $result->free();

        return ($row) ? $row : array();
    }

    public static function createRequest(WebSoccer $websoccer, DbConnection $db, $player, $borrowerTeamId, $borrowerUserId, $matches, $feePerMatch, $salaryShare, $optionType, $buyFee, $createdByComputer = false) {
        $playerId = isset($player['player_id']) ? (int) $player['player_id'] : (int) $player['id'];
        $lenderTeamId = isset($player['team_id']) ? (int) $player['team_id'] : (int) $player['verein_id'];
        $matches = (int) $matches;
        $feePerMatch = (int) $feePerMatch;
        $totalFee = $matches * $feePerMatch;
        $borrowerTeamId = (int) $borrowerTeamId;
        $borrowerUserId = (int) $borrowerUserId;

        $existing = self::getOpenRequestForPlayerAndBorrower($websoccer, $db, $playerId, $borrowerTeamId);
        if (isset($existing['id'])) {
            return (int) $existing['id'];
        }

        $columns = array(
            'player_id' => $playerId,
            'lender_team_id' => $lenderTeamId,
            'borrower_team_id' => $borrowerTeamId,
            'borrower_user_id' => $borrowerUserId,
            'requested_matches' => $matches,
            'loan_fee_per_match' => $feePerMatch,
            'total_fee' => $totalFee,
            'salary_share_percent' => LoanDataService::normalizeSalaryShare($salaryShare),
            'option_type' => LoanDataService::normalizeOptionType($optionType),
            'buy_fee' => (int) $buyFee,
            'created_by_computer' => $createdByComputer ? '1' : '0',
            'status' => self::STATUS_OPEN,
            'created_date' => $websoccer->getNowAsTimestamp(),
            'answered_date' => 0
        );

        $db->queryInsert($columns, $websoccer->getConfig('db_prefix') . '_loan_request');
        $requestId = (int) $db->getLastInsertedId();

        self::notifyNewRequest($websoccer, $db, $columns, $player);

        return $requestId;
    }

    public static function getOpenRequestForPlayerAndBorrower(WebSoccer $websoccer, DbConnection $db, $playerId, $borrowerTeamId) {
        $table = $websoccer->getConfig('db_prefix') . '_loan_request';
        $result = $db->querySelect('*', $table, 'player_id = %d AND borrower_team_id = %d AND status = \'open\' ORDER BY id DESC', array((int) $playerId, (int) $borrowerTeamId), 1);
        $row = $result->fetch_array();
        $result->free();

        return ($row) ? $row : array();
    }

    public static function expireOpenRequestsForPlayer(WebSoccer $websoccer, DbConnection $db, $playerId) {
        $db->queryUpdate(
            array('status' => self::STATUS_EXPIRED, 'answered_date' => $websoccer->getNowAsTimestamp()),
            $websoccer->getConfig('db_prefix') . '_loan_request',
            "player_id = %d AND status = 'open'",
            (int) $playerId
        );
    }

    public static function acceptRequest(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $requestId, $lenderTeamId) {
        $request = self::getRequestById($websoccer, $db, $requestId);
        if (!isset($request['id']) || $request['status'] != self::STATUS_OPEN || (int) $request['lender_team_id'] !== (int) $lenderTeamId) {
            throw new Exception($i18n->getMessage('lending_request_err_not_allowed'));
        }

        $player = self::getPlayerRow($websoccer, $db, $request['player_id']);
        if (!isset($player['id']) || (int) $player['verein_id'] !== (int) $lenderTeamId || (int) $player['lending_owner_id'] > 0 || (int) $player['lending_fee'] <= 0) {
            throw new Exception($i18n->getMessage('lending_request_err_no_longer_available'));
        }

        if ($player['transfermarkt'] == '1') {
            throw new Exception($i18n->getMessage('lending_err_on_transfermarket'));
        }

        $matches = (int) $request['requested_matches'];
        if ($matches < (int) $websoccer->getConfig('lending_matches_min') || $matches > (int) $websoccer->getConfig('lending_matches_max')) {
            throw new Exception(sprintf($i18n->getMessage('lending_hire_err_illegalduration'), $websoccer->getConfig('lending_matches_min'), $websoccer->getConfig('lending_matches_max')));
        }

        if ($matches >= (int) $player['vertrag_spiele']) {
            throw new Exception($i18n->getMessage('lending_hire_err_contractendingtoosoon', $player['vertrag_spiele']));
        }

        $borrowerTeam = TeamsDataService::getTeamSummaryById($websoccer, $db, $request['borrower_team_id']);
        $lenderTeam = TeamsDataService::getTeamSummaryById($websoccer, $db, $request['lender_team_id']);
        if (!isset($borrowerTeam['team_id']) || !isset($lenderTeam['team_id'])) {
            throw new Exception($i18n->getMessage('lending_request_err_no_longer_available'));
        }

        $salaryShare = LoanDataService::normalizeSalaryShare($request['salary_share_percent']);
        $optionType = LoanDataService::normalizeOptionType($request['option_type']);
        $buyFee = (int) $request['buy_fee'];
        $totalFee = (int) $request['total_fee'];
        $salaryPart = (int) round((int) $player['vertrag_gehalt'] * $salaryShare / 100);
        $minBudget = $totalFee + 5 * $salaryPart;
        if ($optionType == LoanDataService::OPTION_OBLIGATION) {
            $minBudget += $buyFee;
        }

        if ((int) $borrowerTeam['team_budget'] < $minBudget) {
            throw new Exception($i18n->getMessage('lending_hire_err_budget_too_low'));
        }

        BankAccountDataService::debitAmount($websoccer, $db, $request['borrower_team_id'], $totalFee, 'lending_fee_subject', $lenderTeam['team_name']);
        BankAccountDataService::creditAmount($websoccer, $db, $request['lender_team_id'], $totalFee, 'lending_fee_subject', $borrowerTeam['team_name']);

        $db->queryUpdate(
            array('lending_matches' => $matches, 'lending_owner_id' => (int) $request['lender_team_id'], 'verein_id' => (int) $request['borrower_team_id']),
            $websoccer->getConfig('db_prefix') . '_spieler',
            'id = %d',
            (int) $request['player_id']
        );

        LoanDataService::createLoan($websoccer, $db, $request['player_id'], $request['lender_team_id'], $request['borrower_team_id'], $matches, $request['loan_fee_per_match'], $salaryShare, $optionType, $buyFee);
        LoanDataService::closeOffer($websoccer, $db, $request['player_id'], 'accepted');

        $now = $websoccer->getNowAsTimestamp();
        $table = $websoccer->getConfig('db_prefix') . '_loan_request';
        $db->queryUpdate(array('status' => self::STATUS_ACCEPTED, 'answered_date' => $now), $table, 'id = %d', (int) $request['id']);
        $db->queryUpdate(array('status' => self::STATUS_EXPIRED, 'answered_date' => $now), $table, 'player_id = %d AND status = \'open\' AND id <> %d', array((int) $request['player_id'], (int) $request['id']));

        self::notifyAcceptedRequest($websoccer, $db, $request, $player, $lenderTeam, $borrowerTeam);
    }

    public static function rejectRequest(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $requestId, $lenderTeamId) {
        $request = self::getRequestById($websoccer, $db, $requestId);
        if (!isset($request['id']) || $request['status'] != self::STATUS_OPEN || (int) $request['lender_team_id'] !== (int) $lenderTeamId) {
            throw new Exception($i18n->getMessage('lending_request_err_not_allowed'));
        }

        $db->queryUpdate(
            array('status' => self::STATUS_REJECTED, 'answered_date' => $websoccer->getNowAsTimestamp()),
            $websoccer->getConfig('db_prefix') . '_loan_request',
            'id = %d',
            (int) $request['id']
        );

        $player = self::getPlayerRow($websoccer, $db, $request['player_id']);
        $borrowerTeam = TeamsDataService::getTeamSummaryById($websoccer, $db, $request['borrower_team_id']);
        if (!empty($request['borrower_user_id']) && isset($borrowerTeam['team_id'])) {
            NotificationsDataService::createNotification(
                $websoccer,
                $db,
                (int) $request['borrower_user_id'],
                'lending_request_notification_rejected',
                array('player' => self::playerName($player), 'borrower' => $borrowerTeam['team_name']),
                'lending_request_rejected',
                'loans',
                ''
            );
        }
    }

    private static function notifyNewRequest(WebSoccer $websoccer, DbConnection $db, $request, $player) {
        $lenderTeam = TeamsDataService::getTeamSummaryById($websoccer, $db, $request['lender_team_id']);
        $borrowerTeam = TeamsDataService::getTeamSummaryById($websoccer, $db, $request['borrower_team_id']);

        if (!empty($lenderTeam['user_id'])) {
            NotificationsDataService::createNotification(
                $websoccer,
                $db,
                (int) $lenderTeam['user_id'],
                'lending_request_notification_received',
                array(
                    'player' => self::playerName($player),
                    'borrower' => $borrowerTeam['team_name'],
                    'matches' => (int) $request['requested_matches'],
                    'fee' => number_format((int) $request['loan_fee_per_match'], 0, ',', ' ')
                ),
                'lending_request_received',
                'loans',
                ''
            );
        }
    }

    private static function notifyAcceptedRequest(WebSoccer $websoccer, DbConnection $db, $request, $player, $lenderTeam, $borrowerTeam) {
        $data = array(
            'player' => self::playerName($player),
            'matches' => (int) $request['requested_matches'],
            'newteam' => $borrowerTeam['team_name']
        );

        if (!empty($lenderTeam['user_id'])) {
            NotificationsDataService::createNotification($websoccer, $db, (int) $lenderTeam['user_id'], 'lending_notification_lent', $data, 'lending_lent', 'loans', '');
        }
        if (!empty($request['borrower_user_id'])) {
            NotificationsDataService::createNotification($websoccer, $db, (int) $request['borrower_user_id'], 'lending_request_notification_accepted', $data, 'lending_request_accepted', 'loans', '');
        }
    }

    private static function getPlayerRow(WebSoccer $websoccer, DbConnection $db, $playerId) {
        $result = $db->querySelect('*', $websoccer->getConfig('db_prefix') . '_spieler', 'id = %d', (int) $playerId, 1);
        $player = $result->fetch_array();
        $result->free();

        return ($player) ? $player : array();
    }

    private static function playerName($player) {
        if (isset($player['player_pseudonym']) && strlen($player['player_pseudonym'])) {
            return $player['player_pseudonym'];
        }
        if (isset($player['kunstname']) && strlen($player['kunstname'])) {
            return $player['kunstname'];
        }

        $firstName = isset($player['player_firstname']) ? $player['player_firstname'] : (isset($player['vorname']) ? $player['vorname'] : '');
        $lastName = isset($player['player_lastname']) ? $player['player_lastname'] : (isset($player['nachname']) ? $player['nachname'] : '');

        return trim($firstName . ' ' . $lastName);
    }
}

?>
