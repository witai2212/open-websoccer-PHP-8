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
 * Builds a conservative cash-flow forecast for one club until the end of the current season.
 *
 * The forecast intentionally uses only values which are already known in the database:
 * - current club budget
 * - pending transfer offers
 * - player salary per simulated non-friendly match
 * - sponsor base, home, cup and estimated attendance bonus, but not conditional win bonus
 * - stadium income based on the club's last known spectator values
 *
 * It does not write any data and does not create forecast snapshots.
 */
class FinancialForecastDataService {

    public static function getForecast(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $teamId = (int) $teamId;
        $team = TeamsDataService::getTeamSummaryById($websoccer, $db, $teamId);
        if (!isset($team['team_id'])) {
            return array();
        }

        $currentBudget = (int) $team['team_budget'];
        $season = self::getActiveSeason($websoccer, $db, $team['team_league_id']);
        $seasonEndDate = (isset($season['season_id'])) ? self::getSeasonEndDate($websoccer, $db, $teamId, $season['season_id']) : 0;
        $matches = self::getUpcomingMatchesUntilSeasonEnd($websoccer, $db, $teamId, $seasonEndDate);

        $salaryPerMatch = self::getPlayerSalaryPerMatch($websoccer, $db, $teamId);
        $ticketInfo = self::getTicketForecastInfo($websoccer, $db, $teamId);
        $stadiumIncomePerHomeMatch = self::calculateStadiumIncomeFromLastSpectators($ticketInfo);
        $sponsor = SponsorsDataService::getSponsorinfoByTeamId($websoccer, $db, $teamId);
        $transfers = self::getPendingTransferCashflow($websoccer, $db, $teamId);
        $prepaidCosts = self::getPrepaidCostInfo($websoccer, $db, $teamId);
        $loanRepaymentSchedule = self::getLoanRepaymentSchedule($websoccer, $db, $teamId, count($matches));

        $nonFriendlyMatches = 0;
        $homeMatches = 0;
        $salaryTotal = 0;
        $sponsorTotal = 0;
        $sponsorWinBonusPotential = 0;
        $stadiumTotal = 0;
        $loanRepaymentTotal = 0;

        $balance = $currentBudget;
        $rows = array();
        $chartLabels = array('Aktuell');
        $chartData = array($balance);

        if ($transfers['net'] != 0) {
            $balance += $transfers['net'];
            $rows[] = array(
                'date' => 0,
                'label' => 'financialforecast_pending_transfers',
                'match_type' => '',
                'home_match' => FALSE,
                'salary_cost' => 0,
                'sponsor_income' => 0,
                'stadium_income' => 0,
                'loan_repayment' => 0,
                'transfer_cashflow' => $transfers['net'],
                'balance' => $balance
            );
            $chartLabels[] = 'Transfers';
            $chartData[] = $balance;
            $matchIndex++;
        }

        $useLegacySponsorLimit = (!isset($sponsor['contract_id']) || (int) $sponsor['contract_id'] <= 0);
        $sponsorMatchesLeft = (isset($sponsor['matchdays'])) ? (int) $sponsor['matchdays'] : 0;

        $matchIndex = 0;
        foreach ($matches as $match) {
            $isHomeMatch = ((int) $match['match_home_id'] == $teamId);
            $isFriendlyMatch = ($match['match_type'] == 'Freundschaft');

            $salaryCost = 0;
            $sponsorIncome = 0;
            $stadiumIncome = 0;
            $loanRepayment = isset($loanRepaymentSchedule[$matchIndex]) ? (int) $loanRepaymentSchedule[$matchIndex] : 0;

            if (!$isFriendlyMatch) {
                $nonFriendlyMatches++;
                $salaryCost = $salaryPerMatch;
                $salaryTotal += $salaryCost;

                if (isset($sponsor['sponsor_id']) && (!$useLegacySponsorLimit || $sponsorMatchesLeft > 0)) {
                    $sponsorIncome = (int) $sponsor['amount_match'];
                    if ($isHomeMatch) {
                        $sponsorIncome += (int) $sponsor['amount_home_bonus'];
                        $sponsorIncome += self::calculateSponsorAttendanceBonusFromTicketInfo(
                            $ticketInfo,
                            (int) $sponsor['amount_match'],
                            isset($sponsor['amount_attendance_percent']) ? (int) $sponsor['amount_attendance_percent'] : 0
                        );
                    }
                    if ($match['match_type'] == 'Pokalspiel') {
                        $sponsorIncome += (int) $sponsor['amount_cup'];
                    }
                    $sponsorTotal += $sponsorIncome;
                    $sponsorWinBonusPotential += (int) $sponsor['amount_win'];
                    if ($useLegacySponsorLimit) {
                        $sponsorMatchesLeft--;
                    }
                }
            }

            if ($isHomeMatch) {
                $homeMatches++;
                $stadiumIncome = $stadiumIncomePerHomeMatch;
                $stadiumTotal += $stadiumIncome;
            }

            $loanRepaymentTotal += $loanRepayment;
            $balance = $balance - $salaryCost + $sponsorIncome + $stadiumIncome - $loanRepayment;

            $opponent = $isHomeMatch ? $match['match_guest_name'] : $match['match_home_name'];
            $label = '';
            if ((int) $match['match_matchday'] > 0) {
                $label = 'ST ' . (int) $match['match_matchday'] . ': ' . $opponent;
            } else {
                $label = $opponent;
            }

            $rows[] = array(
                'date' => (int) $match['match_date'],
                'label' => $label,
                'match_type' => $match['match_type'],
                'home_match' => $isHomeMatch,
                'salary_cost' => $salaryCost * -1,
                'sponsor_income' => $sponsorIncome,
                'stadium_income' => $stadiumIncome,
                'loan_repayment' => $loanRepayment * -1,
                'transfer_cashflow' => 0,
                'balance' => $balance
            );

            $chartLabels[] = self::escapeJsString($label);
            $chartData[] = $balance;
        }

        return array(
            'team' => $team,
            'season' => $season,
            'season_end_date' => $seasonEndDate,
            'current_budget' => $currentBudget,
            'projected_balance' => $balance,
            'projected_change' => $balance - $currentBudget,
            'forecast_rows' => $rows,
            'matches_count' => count($matches),
            'non_friendly_matches_count' => $nonFriendlyMatches,
            'home_matches_count' => $homeMatches,
            'salary_per_match' => $salaryPerMatch,
            'salary_total' => $salaryTotal * -1,
            'sponsor' => $sponsor,
            'sponsor_income_total' => $sponsorTotal,
            'sponsor_win_bonus_potential' => $sponsorWinBonusPotential,
            'stadium_income_per_home_match' => $stadiumIncomePerHomeMatch,
            'stadium_income_total' => $stadiumTotal,
            'ticket_info' => $ticketInfo,
            'transfer_income_total' => $transfers['income'],
            'transfer_expense_total' => $transfers['expense'] * -1,
            'loan_repayment_total' => $loanRepaymentTotal * -1,
            'transfer_net' => $transfers['net'],
            'transfer_items' => $transfers['items'],
            'prepaid_costs' => $prepaidCosts,
            'chart_labels' => "'" . implode("','", $chartLabels) . "'",
            'chart_data' => implode(',', $chartData)
        );
    }

    private static function getActiveSeason(WebSoccer $websoccer, DbConnection $db, $leagueId) {
        $columns = array(
            'id' => 'season_id',
            'name' => 'season_name'
        );
        $result = $db->querySelect($columns, $websoccer->getConfig('db_prefix') . '_saison',
            'liga_id = %d AND beendet = \'0\' ORDER BY name DESC', $leagueId, 1);
        $season = $result->fetch_array();
        $result->free();

        return ($season) ? $season : array();
    }

    private static function getSeasonEndDate(WebSoccer $websoccer, DbConnection $db, $teamId, $seasonId) {
        $columns = 'MAX(datum) AS season_end_date';
        $fromTable = $websoccer->getConfig('db_prefix') . '_spiel';
        $whereCondition = 'saison_id = %d AND (home_verein = %d OR gast_verein = %d)';
        $parameters = array($seasonId, $teamId, $teamId);

        $result = $db->querySelect($columns, $fromTable, $whereCondition, $parameters, 1);
        $row = $result->fetch_array();
        $result->free();

        return (isset($row['season_end_date'])) ? (int) $row['season_end_date'] : 0;
    }

    private static function getUpcomingMatchesUntilSeasonEnd(WebSoccer $websoccer, DbConnection $db, $teamId, $seasonEndDate) {
        $now = $websoccer->getNowAsTimestamp();
        $prefix = $websoccer->getConfig('db_prefix');

        $fromTable = $prefix . '_spiel AS M';
        $fromTable .= ' INNER JOIN ' . $prefix . '_verein AS HOME ON HOME.id = M.home_verein';
        $fromTable .= ' INNER JOIN ' . $prefix . '_verein AS GUEST ON GUEST.id = M.gast_verein';

        $columns = array(
            'M.id' => 'match_id',
            'M.datum' => 'match_date',
            'M.spieltyp' => 'match_type',
            'M.spieltag' => 'match_matchday',
            'M.saison_id' => 'match_season_id',
            'HOME.id' => 'match_home_id',
            'HOME.name' => 'match_home_name',
            'GUEST.id' => 'match_guest_id',
            'GUEST.name' => 'match_guest_name'
        );

        if ($seasonEndDate > 0) {
            $whereCondition = 'M.berechnet != \'1\' AND (HOME.id = %d OR GUEST.id = %d) AND M.datum > %d AND M.datum <= %d ORDER BY M.datum ASC';
            $parameters = array($teamId, $teamId, $now, $seasonEndDate);
        } else {
            $whereCondition = 'M.berechnet != \'1\' AND (HOME.id = %d OR GUEST.id = %d) AND M.datum > %d ORDER BY M.datum ASC';
            $parameters = array($teamId, $teamId, $now);
        }

        $matches = array();
        $result = $db->querySelect($columns, $fromTable, $whereCondition, $parameters);
        while ($match = $result->fetch_array()) {
            $matches[] = $match;
        }
        $result->free();

        return $matches;
    }

    private static function getPlayerSalaryPerMatch(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $columns = 'SUM(vertrag_gehalt) AS salary_sum';
        $fromTable = $websoccer->getConfig('db_prefix') . '_spieler';
        $whereCondition = 'verein_id = %d AND status = \'1\'';

        $result = $db->querySelect($columns, $fromTable, $whereCondition, $teamId, 1);
        $row = $result->fetch_array();
        $result->free();

        $playerSalary = (isset($row['salary_sum'])) ? (int) $row['salary_sum'] : 0;
        $managerSalary = class_exists('ManagerProfileDataService') ? ManagerProfileDataService::getSalaryPerMatchForTeam($websoccer, $db, $teamId) : 0;
        return $playerSalary + (int) $managerSalary;
    }

    private static function getTicketForecastInfo(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $columns = array(
            'last_steh' => 'last_stands',
            'last_sitz' => 'last_seats',
            'last_haupt_steh' => 'last_stands_grand',
            'last_haupt_sitz' => 'last_seats_grand',
            'last_vip' => 'last_vip',
            'S.p_steh' => 'places_stands',
            'S.p_sitz' => 'places_seats',
            'S.p_haupt_steh' => 'places_stands_grand',
            'S.p_haupt_sitz' => 'places_seats_grand',
            'S.p_vip' => 'places_vip',
            'preis_stehen' => 'price_stands',
            'preis_sitz' => 'price_seats',
            'preis_haupt_stehen' => 'price_stands_grand',
            'preis_haupt_sitze' => 'price_seats_grand',
            'preis_vip' => 'price_vip'
        );

        $fromTable = $websoccer->getConfig('db_prefix') . '_verein AS T';
        $fromTable .= ' LEFT JOIN ' . $websoccer->getConfig('db_prefix') . '_stadion AS S ON S.id = T.stadion_id';

        $result = $db->querySelect($columns, $fromTable, 'T.id = %d', $teamId, 1);
        $ticketInfo = $result->fetch_array();
        $result->free();

        return ($ticketInfo) ? $ticketInfo : array();
    }

    private static function calculateStadiumIncomeFromLastSpectators($ticketInfo) {
        if (!count($ticketInfo)) {
            return 0;
        }

        $amount = (int) $ticketInfo['last_stands'] * (int) $ticketInfo['price_stands'];
        $amount += (int) $ticketInfo['last_seats'] * (int) $ticketInfo['price_seats'];
        $amount += (int) $ticketInfo['last_stands_grand'] * (int) $ticketInfo['price_stands_grand'];
        $amount += (int) $ticketInfo['last_seats_grand'] * (int) $ticketInfo['price_seats_grand'];
        $amount += (int) $ticketInfo['last_vip'] * (int) $ticketInfo['price_vip'];

        return $amount;
    }

    private static function calculateSponsorAttendanceBonusFromTicketInfo($ticketInfo, $baseAmount, $bonusPercent) {
        if (!count($ticketInfo) || $baseAmount <= 0 || $bonusPercent <= 0) {
            return 0;
        }

        $lastSpectators = (int) $ticketInfo['last_stands']
            + (int) $ticketInfo['last_seats']
            + (int) $ticketInfo['last_stands_grand']
            + (int) $ticketInfo['last_seats_grand']
            + (int) $ticketInfo['last_vip'];

        $capacity = (int) $ticketInfo['places_stands']
            + (int) $ticketInfo['places_seats']
            + (int) $ticketInfo['places_stands_grand']
            + (int) $ticketInfo['places_seats_grand']
            + (int) $ticketInfo['places_vip'];

        if ($lastSpectators <= 0 || $capacity <= 0) {
            return 0;
        }

        $occupancy = min(1, max(0, $lastSpectators / $capacity));
        return (int) round($baseAmount * ($bonusPercent / 100) * $occupancy);
    }

    private static function getPendingTransferCashflow(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $income = 0;
        $expense = 0;
        $items = array();

        $legacyItems = self::getPendingLegacyTransferOffers($websoccer, $db, $teamId);
        foreach ($legacyItems as $item) {
            if ($item['direction'] == 'out') {
                $expense += $item['amount'];
            } else {
                $income += $item['amount'];
            }
            $items[] = $item;
        }

        $directItems = self::getPendingDirectTransferOffers($websoccer, $db, $teamId);
        foreach ($directItems as $item) {
            if ($item['direction'] == 'out') {
                $expense += $item['amount'];
            } else {
                $income += $item['amount'];
            }
            $items[] = $item;
        }

        return array(
            'income' => $income,
            'expense' => $expense,
            'net' => $income - $expense,
            'items' => $items
        );
    }

    private static function getPendingLegacyTransferOffers(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $prefix = $websoccer->getConfig('db_prefix');
        $fromTable = $prefix . '_transfer_angebot AS O';
        $fromTable .= ' INNER JOIN ' . $prefix . '_spieler AS P ON P.id = O.spieler_id';
        $fromTable .= ' LEFT JOIN ' . $prefix . '_verein AS BUYER ON BUYER.id = O.verein_id';
        $fromTable .= ' LEFT JOIN ' . $prefix . '_verein AS SELLER ON SELLER.id = P.verein_id';

        $columns = array(
            'O.id' => 'offer_id',
            'O.abloese' => 'amount_fee',
            'O.handgeld' => 'amount_handmoney',
            'O.ishighest' => 'is_highest',
            'O.datum' => 'date_submitted',
            'P.id' => 'player_id',
            'P.vorname' => 'firstname',
            'P.nachname' => 'lastname',
            'P.kunstname' => 'pseudonym',
            'P.transfermarkt' => 'on_transfermarket',
            'BUYER.id' => 'buyer_team_id',
            'BUYER.name' => 'buyer_team_name',
            'SELLER.id' => 'seller_team_id',
            'SELLER.name' => 'seller_team_name'
        );

        $whereCondition = '(O.verein_id = %d OR P.verein_id = %d) ORDER BY O.datum DESC';
        $parameters = array($teamId, $teamId);
        $result = $db->querySelect($columns, $fromTable, $whereCondition, $parameters);

        $items = array();
        while ($row = $result->fetch_array()) {
            $buyerTeamId = (int) $row['buyer_team_id'];
            $sellerTeamId = (int) $row['seller_team_id'];

            if ($buyerTeamId == $teamId && $sellerTeamId == $teamId) {
                continue;
            }

            // For transfer market auctions, only the current highest bid should be planned.
            // Direct/manual offers can have ishighest = 0, therefore keep them when the player is not on the transfer market.
            if ($row['on_transfermarket'] == '1' && $row['is_highest'] != '1') {
                continue;
            }

            $playerName = self::buildPlayerName($row);
            $feeAmount = (int) $row['amount_fee'];
            $handMoneyAmount = (int) $row['amount_handmoney'];

            if ($buyerTeamId == $teamId) {
                // Conservative buyer view: reserve transfer fee plus hand money.
                $items[] = array(
                    'direction' => 'out',
                    'type' => 'transfer_angebot',
                    'date' => (int) $row['date_submitted'],
                    'player_name' => $playerName,
                    'club_name' => $row['seller_team_name'],
                    'amount' => $feeAmount + $handMoneyAmount
                );
            } else if ($sellerTeamId == $teamId) {
                // Seller receives the transfer fee. Hand money belongs to the buyer/player side.
                $items[] = array(
                    'direction' => 'in',
                    'type' => 'transfer_angebot',
                    'date' => (int) $row['date_submitted'],
                    'player_name' => $playerName,
                    'club_name' => $row['buyer_team_name'],
                    'amount' => $feeAmount
                );
            }
        }
        $result->free();

        return $items;
    }

    private static function getPendingDirectTransferOffers(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $prefix = $websoccer->getConfig('db_prefix');
        $fromTable = $prefix . '_transfer_offer AS O';
        $fromTable .= ' INNER JOIN ' . $prefix . '_spieler AS P ON P.id = O.player_id';
        $fromTable .= ' LEFT JOIN ' . $prefix . '_verein AS SENDER ON SENDER.id = O.sender_club_id';
        $fromTable .= ' LEFT JOIN ' . $prefix . '_verein AS RECEIVER ON RECEIVER.id = O.receiver_club_id';

        $columns = array(
            'O.id' => 'offer_id',
            'O.offer_amount' => 'amount',
            'O.submitted_date' => 'date_submitted',
            'O.sender_club_id' => 'sender_team_id',
            'O.receiver_club_id' => 'receiver_team_id',
            'P.vorname' => 'firstname',
            'P.nachname' => 'lastname',
            'P.kunstname' => 'pseudonym',
            'SENDER.name' => 'sender_team_name',
            'RECEIVER.name' => 'receiver_team_name'
        );

        $whereCondition = '(O.sender_club_id = %d OR O.receiver_club_id = %d) AND O.rejected_date = 0 ORDER BY O.submitted_date DESC';
        $parameters = array($teamId, $teamId);
        $result = $db->querySelect($columns, $fromTable, $whereCondition, $parameters);

        $items = array();
        while ($row = $result->fetch_array()) {
            $playerName = self::buildPlayerName($row);
            $amount = (int) $row['amount'];

            if ((int) $row['sender_team_id'] == $teamId) {
                $items[] = array(
                    'direction' => 'out',
                    'type' => 'transfer_offer',
                    'date' => (int) $row['date_submitted'],
                    'player_name' => $playerName,
                    'club_name' => $row['receiver_team_name'],
                    'amount' => $amount
                );
            } else if ((int) $row['receiver_team_id'] == $teamId) {
                $items[] = array(
                    'direction' => 'in',
                    'type' => 'transfer_offer',
                    'date' => (int) $row['date_submitted'],
                    'player_name' => $playerName,
                    'club_name' => $row['sender_team_name'],
                    'amount' => $amount
                );
            }
        }
        $result->free();

        return $items;
    }

    private static function getPrepaidCostInfo(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $prefix = $websoccer->getConfig('db_prefix');

        $scoutingAmount = 0;
        $scoutingCount = 0;
        $result = $db->querySelect('COUNT(*) AS scout_count, SUM(fee * team_matches) AS scout_value',
            $prefix . '_scout', 'team_id = %d AND team_matches > 0', $teamId, 1);
        $row = $result->fetch_array();
        $result->free();
        if ($row) {
            $scoutingCount = (int) $row['scout_count'];
            $scoutingAmount = (int) $row['scout_value'];
        }

        $trainingAmount = 0;
        $trainingCount = 0;
        $fromTable = $prefix . '_training_unit AS U INNER JOIN ' . $prefix . '_trainer AS T ON T.id = U.trainer_id';
        $whereCondition = 'U.team_id = %d AND (U.date_executed = 0 OR U.date_executed IS NULL)';
        $result = $db->querySelect('COUNT(*) AS training_count, SUM(T.salary) AS training_value', $fromTable, $whereCondition, $teamId, 1);
        $row = $result->fetch_array();
        $result->free();
        if ($row) {
            $trainingCount = (int) $row['training_count'];
            $trainingAmount = (int) $row['training_value'];
        }

        return array(
            'scouting_count' => $scoutingCount,
            'scouting_amount' => $scoutingAmount,
            'training_count' => $trainingCount,
            'training_amount' => $trainingAmount
        );
    }


    private static function getLoanRepaymentSchedule(WebSoccer $websoccer, DbConnection $db, $teamId, $matchCount) {
        $schedule = array();
        for ($i = 0; $i < $matchCount; $i++) {
            $schedule[$i] = 0;
        }

        if ($matchCount <= 0 || !class_exists('BankLoansDataService')) {
            return $schedule;
        }

        try {
            $loans = BankLoansDataService::getActiveLoans($websoccer, $db, $teamId);
        } catch (Exception $e) {
            return $schedule;
        }

        foreach ($loans as $loan) {
            $remaining = max(0, (int) $loan['remaining_amount']);
            $matchesLeft = max(1, (int) $loan['matches_left']);
            for ($i = 0; $i < $matchCount && $remaining > 0 && $matchesLeft > 0; $i++) {
                $due = min((int) ceil($remaining / max(1, $matchesLeft)), $remaining);
                $schedule[$i] += $due;
                $remaining -= $due;
                $matchesLeft--;
            }
        }

        return $schedule;
    }

    private static function buildPlayerName($row) {
        if (isset($row['pseudonym']) && strlen($row['pseudonym'])) {
            return $row['pseudonym'];
        }

        return trim($row['firstname'] . ' ' . $row['lastname']);
    }

    private static function escapeJsString($value) {
        $value = str_replace('\\', '\\\\', $value);
        $value = str_replace("'", "\\'", $value);
        return $value;
    }
}
?>
