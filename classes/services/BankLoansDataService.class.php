<?php
/******************************************************

  This file is part of OpenWebSoccer-Sim.

  OpenWebSoccer-Sim is free software: you can redistribute it
  and/or modify it under the terms of the
  GNU Lesser General Public License as published by the Free Software Foundation,
  either version 3 of the License, or any later version.

******************************************************/

/**
 * Data service for the bank / loans feature.
 */
class BankLoansDataService {

    const STATUS_ACTIVE = 'active';
    const STATUS_REPAID = 'repaid';
    const STATUS_DEFAULTED = 'defaulted';

    private static $_schemaReady = FALSE;

    public static function isEnabled(WebSoccer $websoccer) {
        return self::getConfigBoolean($websoccer, 'bank_loans_enabled', TRUE);
    }

    public static function getBankPageData(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $teamId, $userId) {
        self::ensureSchema($websoccer, $db);

        $team = self::getTeam($websoccer, $db, $teamId);
        if (!$team || (int) $team['user_id'] < 1 || (int) $team['user_id'] !== (int) $userId) {
            throw new Exception($i18n->getMessage('feature_requires_team'));
        }

        $rating = self::calculateCreditRating($websoccer, $db, $teamId, $team);
        $activeLoans = self::getActiveLoans($websoccer, $db, $teamId);
        $repaidLoans = self::getRepaidLoans($websoccer, $db, $teamId, 10);
        $offers = self::generateOffers($websoccer, $db, $teamId, $team, $rating);

        return array(
            'team' => $team,
            'team_id' => $teamId,
            'enabled' => self::isEnabled($websoccer),
            'rating' => $rating,
            'offers' => $offers,
            'active_loans' => $activeLoans,
            'repaid_loans' => $repaidLoans,
            'currency' => $websoccer->getConfig('game_currency')
        );
    }

    public static function takeLoan(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $teamId, $userId, $offerKey) {
        self::ensureSchema($websoccer, $db);

        if (!self::isEnabled($websoccer)) {
            throw new Exception($i18n->getMessage('bankloans_error_disabled'));
        }

        $team = self::getTeam($websoccer, $db, $teamId);
        if (!$team || (int) $team['user_id'] < 1 || (int) $team['user_id'] !== (int) $userId) {
            throw new Exception($i18n->getMessage('feature_requires_team'));
        }

        $rating = self::calculateCreditRating($websoccer, $db, $teamId, $team);
        $offers = self::generateOffers($websoccer, $db, $teamId, $team, $rating);

        $selectedOffer = null;
        foreach ($offers as $offer) {
            if ($offer['key'] === $offerKey) {
                $selectedOffer = $offer;
                break;
            }
        }

        if (!$selectedOffer) {
            throw new Exception($i18n->getMessage('bankloans_error_offer_invalid'));
        }

        if ((int) $selectedOffer['amount'] < 1) {
            throw new Exception($i18n->getMessage('bankloans_error_offer_invalid'));
        }

        $totalDebtAfter = (int) $rating['active_debt'] + (int) $selectedOffer['total_repayment'];
        if ($totalDebtAfter > (int) $rating['max_debt']) {
            throw new Exception($i18n->getMessage('bankloans_error_debt_limit'));
        }

        $now = $websoccer->getNowAsTimestamp();
        $table = $websoccer->getConfig('db_prefix') . '_bank';

        $columns = array(
            'verein_id' => (int) $teamId,
            'amount' => (string) (int) $selectedOffer['amount'],
            'matches' => (int) $selectedOffer['matches_total'],
            'interest' => (string) $selectedOffer['interest_rate'],
            'original_amount' => (int) $selectedOffer['amount'],
            'remaining_principal' => (int) $selectedOffer['amount'],
            'interest_rate' => (float) $selectedOffer['interest_rate'],
            'total_interest' => (int) $selectedOffer['interest_amount'],
            'remaining_interest' => (int) $selectedOffer['interest_amount'],
            'total_repayment' => (int) $selectedOffer['total_repayment'],
            'remaining_amount' => (int) $selectedOffer['total_repayment'],
            'matches_total' => (int) $selectedOffer['matches_total'],
            'matches_left' => (int) $selectedOffer['matches_total'],
            'status' => self::STATUS_ACTIVE,
            'offer_type' => $selectedOffer['type'],
            'credit_rating' => $rating['rating'],
            'credit_score' => (int) $rating['score'],
            'created_date' => $now,
            'updated_date' => $now,
            'last_payment_match_id' => 0,
            'last_payment_date' => 0,
            'repaid_date' => 0,
            'board_warning_sent' => '0'
        );

        $db->queryInsert($columns, $table);
        $loanId = $db->getLastInsertedId();

        BankAccountDataService::creditAmount(
            $websoccer,
            $db,
            $teamId,
            (int) $selectedOffer['amount'],
            'bankloans_account_payout',
            'bankloans_sender_bank'
        );

        self::createNotificationIfPossible(
            $websoccer,
            $db,
            (int) $userId,
            'bankloans_notification_taken',
            array('amount' => number_format((int) $selectedOffer['amount'], 0, ',', ' ')),
            'bankloans',
            'bank-loans',
            null,
            (int) $teamId
        );

        return $loanId;
    }

    public static function repayLoanEarly(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $teamId, $userId, $loanId) {
        self::ensureSchema($websoccer, $db);

        $team = self::getTeam($websoccer, $db, $teamId);
        if (!$team || (int) $team['user_id'] < 1 || (int) $team['user_id'] !== (int) $userId) {
            throw new Exception($i18n->getMessage('feature_requires_team'));
        }

        $loan = self::getLoanById($websoccer, $db, $teamId, $loanId);
        if (!$loan || $loan['status'] !== self::STATUS_ACTIVE) {
            throw new Exception($i18n->getMessage('bankloans_error_loan_invalid'));
        }

        $payoff = self::calculateEarlyRepaymentAmount($loan);
        if ($payoff <= 0) {
            throw new Exception($i18n->getMessage('bankloans_error_loan_invalid'));
        }

        BankAccountDataService::debitAmount(
            $websoccer,
            $db,
            $teamId,
            $payoff,
            'bankloans_account_early_repayment',
            'bankloans_sender_bank'
        );

        $now = $websoccer->getNowAsTimestamp();
        $columns = array(
            'remaining_principal' => 0,
            'remaining_interest' => 0,
            'remaining_amount' => 0,
            'matches_left' => 0,
            'status' => self::STATUS_REPAID,
            'updated_date' => $now,
            'repaid_date' => $now
        );
        $db->queryUpdate($columns, $websoccer->getConfig('db_prefix') . '_bank', 'id = %d AND verein_id = %d', array((int) $loanId, (int) $teamId));

        self::createNotificationIfPossible(
            $websoccer,
            $db,
            (int) $userId,
            'bankloans_notification_repaid_early',
            array('amount' => number_format($payoff, 0, ',', ' ')),
            'bankloans',
            'bank-loans',
            null,
            (int) $teamId
        );

        return $payoff;
    }

    /**
     * Processes one repayment for a human club after a completed first-team match.
     */
    public static function processMatchRepayments(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $teamId, $matchId) {
        self::ensureSchema($websoccer, $db);

        if (!self::isEnabled($websoccer)) {
            return array('processed' => 0, 'amount' => 0);
        }

        $team = self::getTeam($websoccer, $db, $teamId);
        if (!$team || (int) $team['user_id'] < 1) {
            return array('processed' => 0, 'amount' => 0);
        }

        $loans = self::getActiveLoans($websoccer, $db, $teamId);
        $processed = 0;
        $totalAmount = 0;

        foreach ($loans as $loan) {
            if ((int) $loan['last_payment_match_id'] === (int) $matchId) {
                continue;
            }

            $remainingAmount = (int) $loan['remaining_amount'];
            $matchesLeft = max(1, (int) $loan['matches_left']);
            if ($remainingAmount <= 0) {
                self::markLoanRepaid($websoccer, $db, $teamId, (int) $loan['id']);
                continue;
            }

            $installment = (int) ceil($remainingAmount / $matchesLeft);
            $installment = min($installment, $remainingAmount);

            $currentTeam = self::getTeam($websoccer, $db, $teamId);
            $budgetBefore = $currentTeam ? (int) $currentTeam['finanz_budget'] : 0;

            BankAccountDataService::debitAmount(
                $websoccer,
                $db,
                $teamId,
                $installment,
                'bankloans_account_installment',
                'bankloans_sender_bank'
            );

            $allocation = self::allocateRepayment($loan, $installment);
            $newPrincipal = max(0, (int) $loan['remaining_principal'] - (int) $allocation['principal']);
            $newInterest = max(0, (int) $loan['remaining_interest'] - (int) $allocation['interest']);
            $newRemainingAmount = max(0, $remainingAmount - $installment);
            $newMatchesLeft = max(0, $matchesLeft - 1);

            $status = ($newRemainingAmount <= 0 || $newMatchesLeft <= 0) ? self::STATUS_REPAID : self::STATUS_ACTIVE;
            $now = $websoccer->getNowAsTimestamp();

            $columns = array(
                'remaining_principal' => $newPrincipal,
                'remaining_interest' => $newInterest,
                'remaining_amount' => $newRemainingAmount,
                'matches_left' => $newMatchesLeft,
                'last_payment_match_id' => (int) $matchId,
                'last_payment_date' => $now,
                'updated_date' => $now,
                'status' => $status
            );
            if ($status === self::STATUS_REPAID) {
                $columns['repaid_date'] = $now;
                $columns['remaining_principal'] = 0;
                $columns['remaining_interest'] = 0;
                $columns['remaining_amount'] = 0;
                $columns['matches_left'] = 0;
            }

            $db->queryUpdate($columns, $websoccer->getConfig('db_prefix') . '_bank', 'id = %d AND verein_id = %d', array((int) $loan['id'], (int) $teamId));

            if ($budgetBefore < $installment) {
                self::applyBoardSatisfactionChange($websoccer, $db, $teamId, -2);
                self::createNotificationIfPossible(
                    $websoccer,
                    $db,
                    (int) $team['user_id'],
                    'bankloans_notification_insufficient_budget',
                    array('amount' => number_format($installment, 0, ',', ' ')),
                    'bankloans',
                    'bank-loans',
                    null,
                    (int) $teamId
                );
            }

            if ($status === self::STATUS_REPAID) {
                self::createNotificationIfPossible(
                    $websoccer,
                    $db,
                    (int) $team['user_id'],
                    'bankloans_notification_repaid',
                    null,
                    'bankloans',
                    'bank-loans',
                    null,
                    (int) $teamId
                );
            }

            $processed++;
            $totalAmount += $installment;
        }

        self::processDebtWarning($websoccer, $db, $teamId);

        return array('processed' => $processed, 'amount' => $totalAmount);
    }

    public static function getUpcomingRepaymentTotal(WebSoccer $websoccer, DbConnection $db, $teamId, $matchCount) {
        self::ensureSchema($websoccer, $db);

        $matchCount = max(0, (int) $matchCount);
        if ($matchCount <= 0) {
            return array('total' => 0, 'per_match' => 0, 'active_count' => 0);
        }

        $loans = self::getActiveLoans($websoccer, $db, $teamId);
        $total = 0;
        $perMatch = 0;
        foreach ($loans as $loan) {
            $remaining = (int) $loan['remaining_amount'];
            $matchesLeft = max(1, (int) $loan['matches_left']);
            $installment = (int) ceil($remaining / $matchesLeft);
            $perMatch += min($installment, $remaining);

            $simRemaining = $remaining;
            $simMatches = $matchesLeft;
            for ($i = 0; $i < $matchCount && $simRemaining > 0 && $simMatches > 0; $i++) {
                $due = min((int) ceil($simRemaining / max(1, $simMatches)), $simRemaining);
                $total += $due;
                $simRemaining -= $due;
                $simMatches--;
            }
        }

        return array('total' => $total, 'per_match' => $perMatch, 'active_count' => count($loans));
    }

    public static function getActiveDebt(WebSoccer $websoccer, DbConnection $db, $teamId) {
        self::ensureSchema($websoccer, $db);
        return self::sumActiveDebt($websoccer, $db, $teamId);
    }

    public static function getActiveLoans(WebSoccer $websoccer, DbConnection $db, $teamId) {
        self::ensureSchema($websoccer, $db);

        $columns = self::getLoanColumns();
        $result = $db->querySelect(
            $columns,
            $websoccer->getConfig('db_prefix') . '_bank',
            "verein_id = %d AND status = 'active' ORDER BY created_date ASC, id ASC",
            (int) $teamId
        );

        $loans = array();
        while ($loan = $result->fetch_array()) {
            $loans[] = self::prepareLoanForTemplate($loan);
        }
        $result->free();

        return $loans;
    }

    public static function getRepaidLoans(WebSoccer $websoccer, DbConnection $db, $teamId, $limit = 10) {
        self::ensureSchema($websoccer, $db);

        $columns = self::getLoanColumns();
        $result = $db->querySelect(
            $columns,
            $websoccer->getConfig('db_prefix') . '_bank',
            "verein_id = %d AND status = 'repaid' ORDER BY repaid_date DESC, updated_date DESC, id DESC",
            (int) $teamId,
            (int) $limit
        );

        $loans = array();
        while ($loan = $result->fetch_array()) {
            $loans[] = self::prepareLoanForTemplate($loan);
        }
        $result->free();

        return $loans;
    }

    public static function calculateCreditRating(WebSoccer $websoccer, DbConnection $db, $teamId, $team = null) {
        self::ensureSchema($websoccer, $db);
        if (!$team) {
            $team = self::getTeam($websoccer, $db, $teamId);
        }
        if (!$team) {
            return array('score' => 0, 'rating' => 'E', 'label_key' => 'bankloans_rating_e', 'active_debt' => 0, 'max_debt' => 0, 'available_debt' => 0, 'debt_ratio' => 100);
        }

        $budget = (int) $team['finanz_budget'];
        $board = max(0, min(100, (int) $team['board_satisfaction']));
        $fanMood = max(0, min(100, (int) $team['fan_mood']));
        $highscore = max(0, (int) $team['highscore']);
        $leagueRank = isset($team['platz']) ? (int) $team['platz'] : 0;
        $activeDebt = self::sumActiveDebt($websoccer, $db, $teamId);

        $score = 50;
        if ($budget >= 20000000) {
            $score += 15;
        } else if ($budget >= 5000000) {
            $score += 10;
        } else if ($budget >= 0) {
            $score += 5;
        } else {
            $score -= 20;
        }

        $score += (int) round(($board - 50) / 2);
        $score += (int) round(($fanMood - 50) / 4);
        $score += min(15, (int) floor($highscore / 20));

        if ($leagueRank > 0 && $leagueRank <= 3) {
            $score += 5;
        } else if ($leagueRank >= 15) {
            $score -= 5;
        }

        $basis = max(abs($budget), 1000000);
        $debtPressure = ($basis > 0) ? ($activeDebt / $basis) : 1;
        if ($debtPressure > 1.5) {
            $score -= 30;
        } else if ($debtPressure > 1.0) {
            $score -= 20;
        } else if ($debtPressure > 0.5) {
            $score -= 10;
        }

        if (class_exists('ClubStaffDataService')) {
            $score += ClubStaffDataService::getCreditScoreBonus($websoccer, $db, $teamId);
        }

        $score = max(0, min(100, $score));
        if ($score >= 85) {
            $rating = 'A';
            $labelKey = 'bankloans_rating_a';
        } else if ($score >= 70) {
            $rating = 'B';
            $labelKey = 'bankloans_rating_b';
        } else if ($score >= 50) {
            $rating = 'C';
            $labelKey = 'bankloans_rating_c';
        } else if ($score >= 30) {
            $rating = 'D';
            $labelKey = 'bankloans_rating_d';
        } else {
            $rating = 'E';
            $labelKey = 'bankloans_rating_e';
        }

        $maxDebt = self::calculateMaxDebt($rating, $budget, $highscore);
        $availableDebt = max(0, $maxDebt - $activeDebt);
        $debtRatio = ($maxDebt > 0) ? (int) round(($activeDebt / $maxDebt) * 100) : 100;

        return array(
            'score' => $score,
            'rating' => $rating,
            'label_key' => $labelKey,
            'active_debt' => $activeDebt,
            'max_debt' => $maxDebt,
            'available_debt' => $availableDebt,
            'debt_ratio' => min(999, $debtRatio)
        );
    }

    public static function generateOffers(WebSoccer $websoccer, DbConnection $db, $teamId, $team = null, $rating = null) {
        self::ensureSchema($websoccer, $db);
        if (!$team) {
            $team = self::getTeam($websoccer, $db, $teamId);
        }
        if (!$rating) {
            $rating = self::calculateCreditRating($websoccer, $db, $teamId, $team);
        }

        $available = (int) $rating['available_debt'];
        if ($available < 100000) {
            return array();
        }

        $defs = array(
            array('key' => 'small', 'type' => 'small', 'label_key' => 'bankloans_offer_small', 'factor' => 0.15, 'matches' => 12, 'risk_add' => 0),
            array('key' => 'medium', 'type' => 'medium', 'label_key' => 'bankloans_offer_medium', 'factor' => 0.35, 'matches' => 24, 'risk_add' => 1.5),
            array('key' => 'large', 'type' => 'large', 'label_key' => 'bankloans_offer_large', 'factor' => 0.70, 'matches' => 36, 'risk_add' => 3.0)
        );

        $offers = array();
        $baseRate = self::getBaseInterestRate($rating['rating']);
        foreach ($defs as $def) {
            $amount = (int) floor(($available * $def['factor']) / 10000) * 10000;
            if ($def['type'] === 'small') {
                $amount = max(100000, $amount);
            }
            if ($amount > $available) {
                $amount = (int) floor($available / 10000) * 10000;
            }
            if ($amount < 100000) {
                continue;
            }

            $interestRate = $baseRate + $def['risk_add'];
            if ($def['matches'] > 24) {
                $interestRate += 1.0;
            }
            if (class_exists('ClubStaffDataService')) {
                $interestRate = max(0.50, $interestRate - ClubStaffDataService::getLoanInterestDiscount($websoccer, $db, $teamId));
            }

            $interestAmount = (int) round($amount * ($interestRate / 100));
            $totalRepayment = $amount + $interestAmount;
            if ($totalRepayment > $available) {
                $amount = (int) floor(($available / (1 + ($interestRate / 100))) / 10000) * 10000;
                if ($amount < 100000) {
                    continue;
                }
                $interestAmount = (int) round($amount * ($interestRate / 100));
                $totalRepayment = $amount + $interestAmount;
            }
            $installment = (int) ceil($totalRepayment / $def['matches']);

            $offers[] = array(
                'key' => $def['key'],
                'type' => $def['type'],
                'label_key' => $def['label_key'],
                'amount' => $amount,
                'interest_rate' => number_format($interestRate, 2, '.', ''),
                'interest_amount' => $interestAmount,
                'total_repayment' => $totalRepayment,
                'matches_total' => $def['matches'],
                'installment' => $installment
            );
        }

        return $offers;
    }

    public static function calculateEarlyRepaymentAmount($loan) {
        $principal = max(0, (int) $loan['remaining_principal']);
        $interest = max(0, (int) $loan['remaining_interest']);
        $discount = (int) floor($interest * 0.5);
        return max(0, $principal + $interest - $discount);
    }

    private static function prepareLoanForTemplate($loan) {
        $loan['id'] = (int) $loan['id'];
        $loan['original_amount'] = (int) $loan['original_amount'];
        $loan['remaining_principal'] = (int) $loan['remaining_principal'];
        $loan['total_interest'] = (int) $loan['total_interest'];
        $loan['remaining_interest'] = (int) $loan['remaining_interest'];
        $loan['total_repayment'] = (int) $loan['total_repayment'];
        $loan['remaining_amount'] = (int) $loan['remaining_amount'];
        $loan['matches_total'] = (int) $loan['matches_total'];
        $loan['matches_left'] = (int) $loan['matches_left'];
        $loan['credit_score'] = (int) $loan['credit_score'];
        $loan['created_date'] = (int) $loan['created_date'];
        $loan['repaid_date'] = (int) $loan['repaid_date'];
        $loan['progress_percent'] = ($loan['total_repayment'] > 0) ? min(100, (int) round((($loan['total_repayment'] - $loan['remaining_amount']) / $loan['total_repayment']) * 100)) : 100;
        $loan['next_installment'] = ($loan['status'] === self::STATUS_ACTIVE && $loan['remaining_amount'] > 0) ? min((int) ceil($loan['remaining_amount'] / max(1, $loan['matches_left'])), $loan['remaining_amount']) : 0;
        $loan['early_repayment_amount'] = ($loan['status'] === self::STATUS_ACTIVE) ? self::calculateEarlyRepaymentAmount($loan) : 0;
        $loan['interest_saved_early'] = ($loan['status'] === self::STATUS_ACTIVE) ? max(0, $loan['remaining_amount'] - $loan['early_repayment_amount']) : 0;
        return $loan;
    }

    private static function allocateRepayment($loan, $amount) {
        $remainingAmount = max(1, (int) $loan['remaining_amount']);
        $remainingInterest = max(0, (int) $loan['remaining_interest']);
        $interest = min($remainingInterest, (int) round($amount * ($remainingInterest / $remainingAmount)));
        $principal = max(0, (int) $amount - $interest);
        return array('principal' => $principal, 'interest' => $interest);
    }

    private static function getLoanById(WebSoccer $websoccer, DbConnection $db, $teamId, $loanId) {
        $result = $db->querySelect(self::getLoanColumns(), $websoccer->getConfig('db_prefix') . '_bank', 'id = %d AND verein_id = %d', array((int) $loanId, (int) $teamId), 1);
        $loan = $result->fetch_array();
        $result->free();
        return ($loan) ? self::prepareLoanForTemplate($loan) : null;
    }

    private static function getLoanColumns() {
        return array(
            'id' => 'id',
            'verein_id' => 'team_id',
            'amount' => 'legacy_amount',
            'matches' => 'legacy_matches',
            'interest' => 'legacy_interest',
            'original_amount' => 'original_amount',
            'remaining_principal' => 'remaining_principal',
            'interest_rate' => 'interest_rate',
            'total_interest' => 'total_interest',
            'remaining_interest' => 'remaining_interest',
            'total_repayment' => 'total_repayment',
            'remaining_amount' => 'remaining_amount',
            'matches_total' => 'matches_total',
            'matches_left' => 'matches_left',
            'status' => 'status',
            'offer_type' => 'offer_type',
            'credit_rating' => 'credit_rating',
            'credit_score' => 'credit_score',
            'created_date' => 'created_date',
            'updated_date' => 'updated_date',
            'last_payment_match_id' => 'last_payment_match_id',
            'last_payment_date' => 'last_payment_date',
            'repaid_date' => 'repaid_date',
            'board_warning_sent' => 'board_warning_sent'
        );
    }

    private static function getTeam(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $columns = array(
            'id' => 'id',
            'name' => 'name',
            'user_id' => 'user_id',
            'liga_id' => 'liga_id',
            'platz' => 'platz',
            'finanz_budget' => 'finanz_budget',
            'board_satisfaction' => 'board_satisfaction',
            'fan_mood' => 'fan_mood',
            'media_pressure' => 'media_pressure',
            'highscore' => 'highscore',
            'status' => 'status'
        );
        $result = $db->querySelect($columns, $websoccer->getConfig('db_prefix') . '_verein', "id = %d AND status = '1'", (int) $teamId, 1);
        $team = $result->fetch_array();
        $result->free();
        return $team ? $team : null;
    }

    private static function sumActiveDebt(WebSoccer $websoccer, DbConnection $db, $teamId) {
        self::ensureSchema($websoccer, $db);
        $result = $db->querySelect('SUM(remaining_amount) AS active_debt', $websoccer->getConfig('db_prefix') . '_bank', "verein_id = %d AND status = 'active'", (int) $teamId, 1);
        $row = $result->fetch_array();
        $result->free();
        return ($row && isset($row['active_debt'])) ? (int) $row['active_debt'] : 0;
    }

    private static function calculateMaxDebt($rating, $budget, $highscore) {
        $basis = max(abs((int) $budget), 1000000);
        $multiplier = 0.25;
        if ($rating === 'A') {
            $multiplier = 1.5;
        } else if ($rating === 'B') {
            $multiplier = 1.0;
        } else if ($rating === 'C') {
            $multiplier = 0.75;
        } else if ($rating === 'D') {
            $multiplier = 0.4;
        }

        $highscoreBonus = min(5000000, max(0, (int) $highscore) * 25000);
        return max(250000, (int) round($basis * $multiplier) + $highscoreBonus);
    }

    private static function getBaseInterestRate($rating) {
        if ($rating === 'A') {
            return 4.0;
        }
        if ($rating === 'B') {
            return 6.0;
        }
        if ($rating === 'C') {
            return 9.0;
        }
        if ($rating === 'D') {
            return 13.0;
        }
        return 18.0;
    }

    private static function markLoanRepaid(WebSoccer $websoccer, DbConnection $db, $teamId, $loanId) {
        $now = $websoccer->getNowAsTimestamp();
        $db->queryUpdate(array(
            'remaining_principal' => 0,
            'remaining_interest' => 0,
            'remaining_amount' => 0,
            'matches_left' => 0,
            'status' => self::STATUS_REPAID,
            'updated_date' => $now,
            'repaid_date' => $now
        ), $websoccer->getConfig('db_prefix') . '_bank', 'id = %d AND verein_id = %d', array((int) $loanId, (int) $teamId));
    }

    private static function processDebtWarning(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $team = self::getTeam($websoccer, $db, $teamId);
        if (!$team || (int) $team['user_id'] < 1) {
            return;
        }

        $rating = self::calculateCreditRating($websoccer, $db, $teamId, $team);
        $debtRatio = (int) $rating['debt_ratio'];
        $table = $websoccer->getConfig('db_prefix') . '_bank';

        if ($debtRatio >= 80) {
            $result = $db->querySelect('COUNT(*) AS hits', $table, "verein_id = %d AND status = 'active' AND board_warning_sent = '0'", (int) $teamId, 1);
            $row = $result->fetch_array();
            $result->free();
            if ($row && (int) $row['hits'] > 0) {
                self::applyBoardSatisfactionChange($websoccer, $db, $teamId, -2);
                $db->queryUpdate(array('board_warning_sent' => '1', 'updated_date' => $websoccer->getNowAsTimestamp()), $table, "verein_id = %d AND status = 'active'", (int) $teamId);
                self::createNotificationIfPossible(
                    $websoccer,
                    $db,
                    (int) $team['user_id'],
                    'bankloans_notification_high_debt',
                    array('ratio' => $debtRatio),
                    'bankloans',
                    'bank-loans',
                    null,
                    (int) $teamId
                );
            }
        } else if ($debtRatio < 60) {
            $db->queryUpdate(array('board_warning_sent' => '0'), $table, "verein_id = %d AND status = 'active'", (int) $teamId);
        }
    }

    private static function applyBoardSatisfactionChange(WebSoccer $websoccer, DbConnection $db, $teamId, $change) {
        if ((int) $change === 0) {
            return;
        }
        $team = self::getTeam($websoccer, $db, $teamId);
        if (!$team) {
            return;
        }
        $newValue = max(0, min(100, (int) $team['board_satisfaction'] + (int) $change));
        $db->queryUpdate(array('board_satisfaction' => $newValue), $websoccer->getConfig('db_prefix') . '_verein', 'id = %d', (int) $teamId);
    }

    private static function createNotificationIfPossible(WebSoccer $websoccer, DbConnection $db, $userId, $messageKey, $messageData = null, $type = null, $targetPageId = null, $targetPageQueryString = null, $teamId = null) {
        if (!class_exists('NotificationsDataService')) {
            return;
        }
        try {
            NotificationsDataService::createNotification($websoccer, $db, $userId, $messageKey, $messageData, $type, $targetPageId, $targetPageQueryString, $teamId);
        } catch (Exception $e) {
            return;
        }
    }

    public static function ensureSchema(WebSoccer $websoccer, DbConnection $db) {
        if (self::$_schemaReady) {
            return;
        }

        $table = $websoccer->getConfig('db_prefix') . '_bank';
        self::ensureColumn($db, $table, 'original_amount', "ALTER TABLE " . $table . " ADD COLUMN original_amount INT(10) NOT NULL DEFAULT 0 AFTER interest");
        self::ensureColumn($db, $table, 'remaining_principal', "ALTER TABLE " . $table . " ADD COLUMN remaining_principal INT(10) NOT NULL DEFAULT 0 AFTER original_amount");
        self::ensureColumn($db, $table, 'interest_rate', "ALTER TABLE " . $table . " ADD COLUMN interest_rate DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER remaining_principal");
        self::ensureColumn($db, $table, 'total_interest', "ALTER TABLE " . $table . " ADD COLUMN total_interest INT(10) NOT NULL DEFAULT 0 AFTER interest_rate");
        self::ensureColumn($db, $table, 'remaining_interest', "ALTER TABLE " . $table . " ADD COLUMN remaining_interest INT(10) NOT NULL DEFAULT 0 AFTER total_interest");
        self::ensureColumn($db, $table, 'total_repayment', "ALTER TABLE " . $table . " ADD COLUMN total_repayment INT(10) NOT NULL DEFAULT 0 AFTER remaining_interest");
        self::ensureColumn($db, $table, 'remaining_amount', "ALTER TABLE " . $table . " ADD COLUMN remaining_amount INT(10) NOT NULL DEFAULT 0 AFTER total_repayment");
        self::ensureColumn($db, $table, 'matches_total', "ALTER TABLE " . $table . " ADD COLUMN matches_total INT(3) NOT NULL DEFAULT 0 AFTER remaining_amount");
        self::ensureColumn($db, $table, 'matches_left', "ALTER TABLE " . $table . " ADD COLUMN matches_left INT(3) NOT NULL DEFAULT 0 AFTER matches_total");
        self::ensureColumn($db, $table, 'status', "ALTER TABLE " . $table . " ADD COLUMN status ENUM('active','repaid','defaulted') NOT NULL DEFAULT 'active' AFTER matches_left");
        self::ensureColumn($db, $table, 'offer_type', "ALTER TABLE " . $table . " ADD COLUMN offer_type VARCHAR(16) NOT NULL DEFAULT 'legacy' AFTER status");
        self::ensureColumn($db, $table, 'credit_rating', "ALTER TABLE " . $table . " ADD COLUMN credit_rating CHAR(1) NOT NULL DEFAULT 'C' AFTER offer_type");
        self::ensureColumn($db, $table, 'credit_score', "ALTER TABLE " . $table . " ADD COLUMN credit_score TINYINT(3) NOT NULL DEFAULT 50 AFTER credit_rating");
        self::ensureColumn($db, $table, 'created_date', "ALTER TABLE " . $table . " ADD COLUMN created_date INT(11) NOT NULL DEFAULT 0 AFTER credit_score");
        self::ensureColumn($db, $table, 'updated_date', "ALTER TABLE " . $table . " ADD COLUMN updated_date INT(11) NOT NULL DEFAULT 0 AFTER created_date");
        self::ensureColumn($db, $table, 'last_payment_match_id', "ALTER TABLE " . $table . " ADD COLUMN last_payment_match_id INT(10) NOT NULL DEFAULT 0 AFTER updated_date");
        self::ensureColumn($db, $table, 'last_payment_date', "ALTER TABLE " . $table . " ADD COLUMN last_payment_date INT(11) NOT NULL DEFAULT 0 AFTER last_payment_match_id");
        self::ensureColumn($db, $table, 'repaid_date', "ALTER TABLE " . $table . " ADD COLUMN repaid_date INT(11) NOT NULL DEFAULT 0 AFTER last_payment_date");
        self::ensureColumn($db, $table, 'board_warning_sent', "ALTER TABLE " . $table . " ADD COLUMN board_warning_sent ENUM('1','0') NOT NULL DEFAULT '0' AFTER repaid_date");
        self::ensureIndex($db, $table, 'idx_bank_team_status', "ALTER TABLE " . $table . " ADD KEY idx_bank_team_status (verein_id, status)");
        self::ensureIndex($db, $table, 'idx_bank_payment_match', "ALTER TABLE " . $table . " ADD KEY idx_bank_payment_match (last_payment_match_id)");

        self::migrateLegacyRows($db, $table);
        self::$_schemaReady = TRUE;
    }

    private static function migrateLegacyRows(DbConnection $db, $table) {
        try {
            $sql = "UPDATE " . $table . " SET "
                . "original_amount = CAST(amount AS SIGNED), "
                . "remaining_principal = CAST(amount AS SIGNED), "
                . "interest_rate = CAST(interest AS DECIMAL(5,2)), "
                . "total_interest = ROUND(CAST(amount AS SIGNED) * (CAST(interest AS DECIMAL(5,2)) / 100)), "
                . "remaining_interest = ROUND(CAST(amount AS SIGNED) * (CAST(interest AS DECIMAL(5,2)) / 100)), "
                . "total_repayment = CAST(amount AS SIGNED) + ROUND(CAST(amount AS SIGNED) * (CAST(interest AS DECIMAL(5,2)) / 100)), "
                . "remaining_amount = CAST(amount AS SIGNED) + ROUND(CAST(amount AS SIGNED) * (CAST(interest AS DECIMAL(5,2)) / 100)), "
                . "matches_total = matches, matches_left = matches, status = 'active', offer_type = 'legacy' "
                . "WHERE original_amount = 0 AND CAST(amount AS SIGNED) > 0";
            $db->executeQuery($sql);
        } catch (Exception $e) {
            return;
        }
    }

    private static function ensureColumn(DbConnection $db, $table, $column, $alterSql) {
        if (!self::columnExists($db, $table, $column)) {
            try {
                $db->executeQuery($alterSql);
            } catch (Exception $e) {
                // The explicit SQL update file contains the same DDL. Ignore duplicate-column errors here.
            }
        }
    }

    private static function ensureIndex(DbConnection $db, $table, $index, $alterSql) {
        if (!self::indexExists($db, $table, $index)) {
            try {
                $db->executeQuery($alterSql);
            } catch (Exception $e) {
                // Ignore duplicate-key or permission issues.
            }
        }
    }

    private static function columnExists(DbConnection $db, $table, $column) {
        try {
            $result = $db->executeQuery("SHOW COLUMNS FROM " . $table . " LIKE '" . $db->connection->real_escape_string($column) . "'");
            $row = $result->fetch_array();
            $result->free();
            return $row ? TRUE : FALSE;
        } catch (Exception $e) {
            return FALSE;
        }
    }

    private static function indexExists(DbConnection $db, $table, $index) {
        try {
            $result = $db->executeQuery("SHOW INDEX FROM " . $table . " WHERE Key_name = '" . $db->connection->real_escape_string($index) . "'");
            $row = $result->fetch_array();
            $result->free();
            return $row ? TRUE : FALSE;
        } catch (Exception $e) {
            return FALSE;
        }
    }

    private static function getConfigBoolean(WebSoccer $websoccer, $key, $defaultValue) {
        try {
            $value = $websoccer->getConfig($key);
        } catch (Exception $e) {
            return $defaultValue;
        }
        if ($value === null || $value === '') {
            return $defaultValue;
        }
        return ($value === TRUE || $value === 1 || $value === '1' || $value === 'true');
    }
}
?>
