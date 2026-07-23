<?php
/******************************************************

  This file is part of OpenWebSoccer-Sim.

******************************************************/

/**
 * Transfer market intelligence for managers and admins.
 *
 * Manager recommendations intentionally use only listed players and require
 * matching active scouts by broad position group. Admin market health is not
 * scout-restricted because it is an economy-control tool.
 */
class TransferMarketIntelligenceDataService {

    private static $_positionKeys = array(
        'Torwart' => 'goaly',
        'Abwehr' => 'defense',
        'Mittelfeld' => 'midfield',
        'Sturm' => 'striker'
    );

    public static function getManagerAnalysis(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $teamId) {
        $teamId = (int) $teamId;
        $contractLimit = self::configInt($websoccer, 'transfermarket_intelligence_contract_days', 30);
        if ($contractLimit < 1) {
            $contractLimit = 30;
        }

        $team = self::getTeam($websoccer, $db, $teamId);
        $scouting = self::getScoutCoverage($websoccer, $db, $teamId);
        $clubNeeds = self::getClubNeeds($websoccer, $db, $i18n, $teamId);

        $listedPlayers = array();
        $loanPlayers = array();
        if (!empty($scouting['has_coverage'])) {
            $listedPlayers = self::getListedTransferPlayers($websoccer, $db, $teamId, $scouting['covered_positions']);
            $loanPlayers = self::getLoanPlayers($websoccer, $db, $teamId, $scouting['covered_positions']);
            $loanPlayers = self::buildLoanRecommendations($loanPlayers, $clubNeeds, $team);
        }

        $bestPlayers = $listedPlayers;
        usort($bestPlayers, array('TransferMarketIntelligenceDataService', 'sortBestPlayers'));
        $bestPlayers = array_slice($bestPlayers, 0, 15);

        $bargains = array();
        $overpriced = array();
        $expiring = array();
        $bargainThreshold = 1 - (self::configInt($websoccer, 'transfermarket_intelligence_bargain_percent', 15) / 100);
        $overpricedThreshold = 1 + (self::configInt($websoccer, 'transfermarket_intelligence_overpriced_percent', 25) / 100);

        foreach ($listedPlayers as $player) {
            if ((float) $player['price_ratio'] > 0 && (float) $player['price_ratio'] <= $bargainThreshold) {
                $bargains[] = $player;
            }
            if ((float) $player['price_ratio'] >= $overpricedThreshold) {
                $overpriced[] = $player;
            }
            if ((int) $player['contract_matches'] <= $contractLimit) {
                $expiring[] = $player;
            }
        }

        usort($bargains, array('TransferMarketIntelligenceDataService', 'sortBargains'));
        usort($overpriced, array('TransferMarketIntelligenceDataService', 'sortOverpriced'));
        usort($expiring, array('TransferMarketIntelligenceDataService', 'sortExpiring'));
        usort($loanPlayers, array('TransferMarketIntelligenceDataService', 'sortLoanPlayers'));

        return array(
            'market_intelligence_team' => $team,
            'market_intelligence_scouting' => $scouting,
            'market_intelligence_best_players' => $bestPlayers,
            'market_intelligence_bargains' => array_slice($bargains, 0, 15),
            'market_intelligence_overpriced' => array_slice($overpriced, 0, 15),
            'market_intelligence_loans' => array_slice($loanPlayers, 0, 10),
            'market_intelligence_expiring_contracts' => array_slice($expiring, 0, 20),
            'market_intelligence_club_needs' => $clubNeeds,
            'market_intelligence_contract_limit' => $contractLimit
        );
    }

    public static function getAdminAnalysis(WebSoccer $websoccer, DbConnection $db) {
        $scope = strtolower(trim((string) $websoccer->getRequestParameter('adminscope')));
        if (!in_array($scope, array('global', 'league', 'country', 'club'))) {
            $scope = 'global';
        }

        $leagueId = max(0, (int) $websoccer->getRequestParameter('league_id'));
        $clubId = max(0, (int) $websoccer->getRequestParameter('club_id'));
        $country = trim((string) $websoccer->getRequestParameter('country'));

        $filters = self::getAdminFilters($websoccer, $db);
        $scopeSql = self::buildScopeSql($db, $scope, $leagueId, $country, $clubId);

        $cpuMinPercent = self::configInt($websoccer, 'transfermarket_intelligence_cpu_min_percent', 85);
        $cpuMaxPercent = self::configInt($websoccer, 'transfermarket_intelligence_cpu_max_percent', 115);
        if ($cpuMinPercent < 1) {
            $cpuMinPercent = 85;
        }
        if ($cpuMaxPercent <= $cpuMinPercent) {
            $cpuMaxPercent = 115;
        }
        $cpuMinRatio = $cpuMinPercent / 100;
        $cpuMaxRatio = $cpuMaxPercent / 100;

        $avgTransferFee = self::fetchScalar($db, self::adminTransferSql($websoccer, $scopeSql, 'AVG(NULLIF(T.directtransfer_amount, 0))'));
        $highestFee = self::fetchScalar($db, self::adminTransferSql($websoccer, $scopeSql, 'MAX(T.directtransfer_amount)'));
        $avgSalary = self::fetchScalar($db, self::adminPlayerSql($websoccer, $scopeSql, 'AVG(P.vertrag_gehalt)', "P.status = '1'"));
        $listedPlayers = self::fetchScalar($db, self::adminPlayerSql($websoccer, $scopeSql, 'COUNT(*)', "P.status = '1' AND P.transfermarkt = '1'"));
        $activeOffers = self::fetchScalar($db, self::adminOfferSql($websoccer, $scopeSql, 'COUNT(*) AS value', '1=1'));
        $avgOffer = self::fetchScalar($db, self::adminOfferSql($websoccer, $scopeSql, 'AVG(A.abloese) AS value', '1=1'));
        $marketRatio = self::fetchScalar($db, self::adminPlayerSql($websoccer, $scopeSql, 'AVG(CASE WHEN P.marktwert > 0 THEN P.transfer_mindestgebot / P.marktwert ELSE NULL END)', "P.status = '1' AND P.transfermarkt = '1'"));

        $cpuSql = self::adminOfferSql(
            $websoccer,
            $scopeSql,
            "COUNT(*) AS cpu_total,
             SUM(CASE WHEN P.marktwert > 0 AND (A.abloese / P.marktwert) >= " . $cpuMinRatio . " AND (A.abloese / P.marktwert) <= " . $cpuMaxRatio . " THEN 1 ELSE 0 END) AS cpu_within,
             SUM(CASE WHEN P.marktwert > 0 AND (A.abloese / P.marktwert) < " . $cpuMinRatio . " THEN 1 ELSE 0 END) AS cpu_below,
             SUM(CASE WHEN P.marktwert > 0 AND (A.abloese / P.marktwert) > " . $cpuMaxRatio . " THEN 1 ELSE 0 END) AS cpu_above,
             AVG(CASE WHEN P.marktwert > 0 THEN A.abloese / P.marktwert ELSE NULL END) AS cpu_avg_ratio",
            '(A.user_id IS NULL OR A.user_id = 0)'
        );
        $cpu = self::fetchRow($db, $cpuSql);
        $cpuTotal = isset($cpu['cpu_total']) ? (int) $cpu['cpu_total'] : 0;
        $cpuWithin = isset($cpu['cpu_within']) ? (int) $cpu['cpu_within'] : 0;
        $cpuBelow = isset($cpu['cpu_below']) ? (int) $cpu['cpu_below'] : 0;
        $cpuAbove = isset($cpu['cpu_above']) ? (int) $cpu['cpu_above'] : 0;
        $cpuAvgRatio = isset($cpu['cpu_avg_ratio']) ? (float) $cpu['cpu_avg_ratio'] : 0;
        $cpuRealism = ($cpuTotal > 0) ? round(($cpuWithin / $cpuTotal) * 100, 1) : 0;

        $offerPressure = ((int) $listedPlayers > 0) ? ((float) $activeOffers / (float) $listedPlayers) : 0;
        $overheating = false;
        if ((float) $marketRatio > 1.25 || (float) $cpuAvgRatio > 1.15 || (float) $offerPressure > 3) {
            $overheating = true;
        }

        return array(
            'market_admin_scope' => $scope,
            'market_admin_league_id' => $leagueId,
            'market_admin_country' => $country,
            'market_admin_club_id' => $clubId,
            'market_admin_filters' => $filters,
            'market_admin_stats' => array(
                'average_transfer_fee' => (int) round((float) $avgTransferFee),
                'highest_fee' => (int) round((float) $highestFee),
                'average_salary' => (int) round((float) $avgSalary),
                'number_offers' => (int) $activeOffers,
                'average_offer' => (int) round((float) $avgOffer),
                'listed_players' => (int) $listedPlayers,
                'market_ratio' => round((float) $marketRatio, 3),
                'cpu_total' => $cpuTotal,
                'cpu_within' => $cpuWithin,
                'cpu_below' => $cpuBelow,
                'cpu_above' => $cpuAbove,
                'cpu_avg_ratio' => round($cpuAvgRatio, 3),
                'cpu_realism' => $cpuRealism,
                'offer_pressure' => round($offerPressure, 2),
                'overheating' => $overheating
            )
        );
    }

    private static function getTeam(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $prefix = $websoccer->getConfig('db_prefix');
        $sql = "SELECT C.id, C.name, C.finanz_budget, L.name AS league_name, L.land AS country
                FROM " . $prefix . "_verein AS C
                LEFT JOIN " . $prefix . "_liga AS L ON L.id = C.liga_id
                WHERE C.id = " . (int) $teamId . "
                LIMIT 1";
        return self::fetchRow($db, $sql);
    }

    private static function getScoutCoverage(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $coverage = array(
            'enabled' => class_exists('ScoutingDataService') ? ScoutingDataService::isEnabled($websoccer) : false,
            'has_department' => false,
            'has_coverage' => false,
            'covered_positions' => array(),
            'scouts' => array()
        );

        if (!$coverage['enabled'] || !class_exists('ScoutingDataService')) {
            return $coverage;
        }

        $department = ScoutingDataService::getDepartment($websoccer, $db, $teamId);
        if ($department && isset($department['id']) && (string) $department['status'] === '1') {
            $coverage['has_department'] = true;
        }
        if (!$coverage['has_department']) {
            return $coverage;
        }

        $scouts = ScoutingDataService::getTeamScouts($websoccer, $db, $teamId);
        foreach ($scouts as $scout) {
            $speciality = isset($scout['speciality']) ? trim((string) $scout['speciality']) : '';
            if (isset(self::$_positionKeys[$speciality])) {
                $coverage['covered_positions'][$speciality] = array(
                    'name' => $speciality,
                    'key' => self::$_positionKeys[$speciality]
                );
                $coverage['scouts'][] = $scout;
            }
        }

        $coverage['has_coverage'] = (count($coverage['covered_positions']) > 0);
        return $coverage;
    }

    private static function getListedTransferPlayers(WebSoccer $websoccer, DbConnection $db, $teamId, $coveredPositions) {
        $prefix = $websoccer->getConfig('db_prefix');
        $positionSql = self::buildPositionSql($db, $coveredPositions, 'P.position');
        if (!strlen($positionSql)) {
            return array();
        }

        $ageColumn = self::ageSql($websoccer, 'P');
        $sql = "SELECT P.id, P.vorname, P.nachname, P.kunstname, P.position, P.position_main,
                       P.w_staerke, P.w_talent, P.marktwert, P.transfer_mindestgebot,
                       P.transfer_ende, P.vertrag_gehalt, P.vertrag_spiele, P.verein_id,
                       " . $ageColumn . " AS age,
                       C.name AS team_name, C.user_id AS team_user_id, L.name AS league_name, L.land AS country,
                       IF(WL.spieler_id IS NULL, 0, 1) AS on_watchlist
                FROM " . $prefix . "_spieler AS P
                INNER JOIN " . $prefix . "_verein AS C ON C.id = P.verein_id
                LEFT JOIN " . $prefix . "_liga AS L ON L.id = C.liga_id
                LEFT JOIN (SELECT DISTINCT spieler_id FROM " . $prefix . "_watchlist WHERE verein_id = " . (int) $teamId . ") AS WL ON WL.spieler_id = P.id
                WHERE P.status = '1'
                  AND P.transfermarkt = '1'
                  AND P.verein_id <> " . (int) $teamId . "
                  AND " . $positionSql . "
                ORDER BY P.w_staerke DESC, P.transfer_mindestgebot ASC
                LIMIT 250";

        $rows = self::fetchRows($db, $sql);
        return self::preparePlayers($rows);
    }

    private static function getLoanPlayers(WebSoccer $websoccer, DbConnection $db, $teamId, $coveredPositions) {
        $prefix = $websoccer->getConfig('db_prefix');
        $positionSql = self::buildPositionSql($db, $coveredPositions, 'P.position');
        if (!strlen($positionSql)) {
            return array();
        }

        $ageColumn = self::ageSql($websoccer, 'P');
        $sql = "SELECT P.id, P.vorname, P.nachname, P.kunstname, P.position, P.position_main,
                       P.w_staerke, P.w_talent, P.marktwert, P.lending_fee,
                       P.vertrag_gehalt, P.vertrag_spiele, P.verein_id,
                       " . $ageColumn . " AS age,
                       C.name AS team_name, C.user_id AS team_user_id, L.name AS league_name, L.land AS country,
                       O.salary_share_percent, O.option_type, O.buy_fee,
                       IF(WL.spieler_id IS NULL, 0, 1) AS on_watchlist
                FROM " . $prefix . "_spieler AS P
                INNER JOIN " . $prefix . "_verein AS C ON C.id = P.verein_id
                LEFT JOIN " . $prefix . "_liga AS L ON L.id = C.liga_id
                LEFT JOIN " . $prefix . "_loan_offer AS O ON O.player_id = P.id AND O.status = 'open'
                LEFT JOIN (SELECT DISTINCT spieler_id FROM " . $prefix . "_watchlist WHERE verein_id = " . (int) $teamId . ") AS WL ON WL.spieler_id = P.id
                WHERE P.status = '1'
                  AND P.verein_id <> " . (int) $teamId . "
                  AND P.transfermarkt <> '1'
                  AND P.lending_fee > 0
                  AND (P.lending_owner_id IS NULL OR P.lending_owner_id = 0)
                  AND " . $positionSql . "
                ORDER BY P.w_staerke DESC, P.lending_fee ASC, P.nachname ASC
                LIMIT 250";

        $rows = self::fetchRows($db, $sql);
        $players = self::preparePlayers($rows, 'lending_fee');
        foreach ($players as $idx => $player) {
            if (!isset($player['salary_share_percent']) || $player['salary_share_percent'] === null || $player['salary_share_percent'] === '') {
                $players[$idx]['salary_share_percent'] = 100;
            }
            if (!isset($player['option_type']) || $player['option_type'] === null || $player['option_type'] === '') {
                $players[$idx]['option_type'] = 'none';
            }
            if (!isset($player['buy_fee']) || $player['buy_fee'] === null) {
                $players[$idx]['buy_fee'] = 0;
            }
        }
        return $players;
    }

    private static function preparePlayers($rows, $priceColumn = 'transfer_mindestgebot') {
        $players = array();
        foreach ($rows as $row) {
            $row['name'] = self::formatPlayerName($row);
            $row['position_key'] = self::positionKey(isset($row['position']) ? $row['position'] : '');
            $row['age'] = (int) $row['age'];
            $row['strength'] = (float) $row['w_staerke'];
            $row['talent'] = (int) $row['w_talent'];
            $row['marketvalue'] = max(0, (int) $row['marktwert']);
            $row['listed_price'] = max(0, (int) $row[$priceColumn]);
            if ($row['listed_price'] <= 0 && $row['marketvalue'] > 0) {
                $row['listed_price'] = $row['marketvalue'];
            }
            $row['price_ratio'] = ($row['marketvalue'] > 0) ? round($row['listed_price'] / $row['marketvalue'], 3) : 0;
            $row['saving_percent'] = ($row['price_ratio'] > 0) ? round(max(0, (1 - $row['price_ratio']) * 100), 1) : 0;
            $row['overprice_percent'] = ($row['price_ratio'] > 0) ? round(max(0, ($row['price_ratio'] - 1) * 100), 1) : 0;
            $row['contract_salary'] = isset($row['vertrag_gehalt']) ? (int) $row['vertrag_gehalt'] : 0;
            $row['contract_matches'] = isset($row['vertrag_spiele']) ? (int) $row['vertrag_spiele'] : 0;
            $players[] = $row;
        }
        return $players;
    }

    private static function getClubNeeds(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $teamId) {
        if (!class_exists('SquadPlannerDataService')) {
            return array('available' => false, 'depth' => array(), 'weaknesses' => array(), 'summary' => array());
        }

        try {
            $analysis = SquadPlannerDataService::getAnalysis($websoccer, $db, $i18n, $teamId);
            return array(
                'available' => true,
                'depth' => isset($analysis['depth']) ? $analysis['depth'] : array(),
                'weaknesses' => isset($analysis['weaknesses']) ? $analysis['weaknesses'] : array(),
                'summary' => isset($analysis['summary']) ? $analysis['summary'] : array()
            );
        } catch (Exception $e) {
            return array('available' => false, 'depth' => array(), 'weaknesses' => array(), 'summary' => array());
        }
    }


    /**
     * Convert the complete loan market into a short, club-specific recommendation list.
     * The score deliberately combines squad need, sporting improvement and affordability.
     */
    private static function buildLoanRecommendations($players, $clubNeeds, $team) {
        if (!count($players)) {
            return array();
        }

        $depth = (isset($clubNeeds['available']) && $clubNeeds['available'] && isset($clubNeeds['depth']))
            ? $clubNeeds['depth']
            : array();
        $budget = isset($team['finanz_budget']) ? max(0, (int) $team['finanz_budget']) : 0;
        $maxFee = 0;
        foreach ($players as $player) {
            $maxFee = max($maxFee, isset($player['listed_price']) ? (int) $player['listed_price'] : 0);
        }

        $recommendations = array();
        foreach ($players as $player) {
            $position = isset($player['position']) ? (string) $player['position'] : '';
            $positionDepth = isset($depth[$position]) ? $depth[$position] : array();
            $needStatus = isset($positionDepth['status']) ? (string) $positionDepth['status'] : 'unknown';
            $averageStrength = isset($positionDepth['avg_strength']) ? (float) $positionDepth['avg_strength'] : 0;
            $weakestStrength = isset($positionDepth['weakest_strength']) ? (float) $positionDepth['weakest_strength'] : 0;
            $playerStrength = isset($player['strength']) ? (float) $player['strength'] : 0;

            // A recommendation must solve a shortage or be a clear quality upgrade.
            if ($needStatus === 'surplus') {
                continue;
            }
            if ($needStatus !== 'shortage' && $averageStrength > 0 && $playerStrength < ($averageStrength + 2)) {
                continue;
            }

            $score = 20;
            $reasons = array();

            if ($needStatus === 'shortage') {
                $missingPlayers = isset($positionDepth['diff']) ? abs((int) $positionDepth['diff']) : 1;
                $score += 38 + min(12, $missingPlayers * 6);
                $reasons[] = 'transfermarket_intelligence_loan_reason_shortage';
            } elseif ($needStatus === 'ok') {
                $score += 10;
            } else {
                $score += 8;
                $reasons[] = 'transfermarket_intelligence_loan_reason_scouted';
            }

            if ($averageStrength > 0) {
                $strengthGap = $playerStrength - $averageStrength;
                $score += self::clamp((int) round($strengthGap * 2.5), -15, 24);
                if ($strengthGap >= 3) {
                    $reasons[] = 'transfermarket_intelligence_loan_reason_upgrade';
                } elseif ($weakestStrength > 0 && $playerStrength >= ($weakestStrength + 5)) {
                    $reasons[] = 'transfermarket_intelligence_loan_reason_depth';
                }
            } else {
                $score += 18;
            }

            $age = isset($player['age']) ? (int) $player['age'] : 0;
            if ($age > 0 && $age <= 23) {
                $score += 8;
                $reasons[] = 'transfermarket_intelligence_loan_reason_young';
            } elseif ($age > 0 && $age <= 26) {
                $score += 5;
            } elseif ($age >= 33) {
                $score -= 4;
            }

            $salaryShare = isset($player['salary_share_percent']) ? self::clamp((int) $player['salary_share_percent'], 0, 100) : 100;
            $score += (int) round((100 - $salaryShare) / 5);
            if ($salaryShare <= 75) {
                $reasons[] = 'transfermarket_intelligence_loan_reason_salary';
            }

            $optionType = isset($player['option_type']) ? (string) $player['option_type'] : 'none';
            $buyFee = isset($player['buy_fee']) ? max(0, (int) $player['buy_fee']) : 0;
            if ($optionType === 'buy_option') {
                $score += 7;
                $reasons[] = 'transfermarket_intelligence_loan_reason_buy_option';
                if ($buyFee > 0 && !empty($player['marketvalue']) && $buyFee <= (int) $player['marketvalue']) {
                    $score += 3;
                }
            } elseif ($optionType === 'buy_obligation') {
                $score -= 3;
            }

            $loanFee = isset($player['listed_price']) ? max(0, (int) $player['listed_price']) : 0;
            if ($maxFee > 0) {
                $score += (int) round((1 - ($loanFee / $maxFee)) * 10);
            }

            $salary = isset($player['contract_salary']) ? max(0, (int) $player['contract_salary']) : 0;
            $salaryCost = (int) round($salary * ($salaryShare / 100));
            $totalCostPerMatch = $loanFee + $salaryCost;
            $estimatedTenMatchCost = $totalCostPerMatch * 10;
            if ($budget > 0) {
                $budgetRatio = $estimatedTenMatchCost / $budget;
                if ($budgetRatio <= 0.05) {
                    $score += 8;
                    $reasons[] = 'transfermarket_intelligence_loan_reason_affordable';
                } elseif ($budgetRatio <= 0.10) {
                    $score += 3;
                } elseif ($budgetRatio > 0.20) {
                    $score -= 10;
                }
            }

            $player['position_need_status'] = $needStatus;
            $player['position_need_amount'] = ($needStatus === 'shortage' && isset($positionDepth['diff'])) ? abs((int) $positionDepth['diff']) : 0;
            $player['position_average_strength'] = $averageStrength;
            $player['loan_total_cost_per_match'] = $totalCostPerMatch;
            $player['loan_recommendation_score'] = self::clamp((int) round($score), 0, 100);
            $player['loan_recommendation_reasons'] = array_values(array_unique($reasons));

            if ($player['loan_recommendation_score'] >= 35) {
                $recommendations[] = $player;
            }
        }

        return $recommendations;
    }

    private static function getAdminFilters(WebSoccer $websoccer, DbConnection $db) {
        $prefix = $websoccer->getConfig('db_prefix');
        return array(
            'leagues' => self::fetchRows($db, "SELECT id, name, land AS country FROM " . $prefix . "_liga ORDER BY land ASC, division ASC, name ASC"),
            'countries' => self::fetchRows($db, "SELECT DISTINCT land AS country FROM " . $prefix . "_liga WHERE land IS NOT NULL AND land <> '' ORDER BY land ASC"),
            'clubs' => self::fetchRows($db, "SELECT C.id, C.name, L.name AS league_name, L.land AS country FROM " . $prefix . "_verein AS C LEFT JOIN " . $prefix . "_liga AS L ON L.id = C.liga_id WHERE C.status = '1' ORDER BY C.name ASC LIMIT 500")
        );
    }

    private static function buildScopeSql(DbConnection $db, $scope, $leagueId, $country, $clubId) {
        if ($scope == 'league' && (int) $leagueId > 0) {
            return array(
                'player' => 'C.liga_id = ' . (int) $leagueId,
                'offer' => '(C.liga_id = ' . (int) $leagueId . ' OR BC.liga_id = ' . (int) $leagueId . ')',
                'transfer' => '(SELLER.liga_id = ' . (int) $leagueId . ' OR BUYER.liga_id = ' . (int) $leagueId . ')'
            );
        }

        if ($scope == 'country' && strlen($country)) {
            $escaped = "'" . $db->connection->real_escape_string($country) . "'";
            return array(
                'player' => 'L.land = ' . $escaped,
                'offer' => '(L.land = ' . $escaped . ' OR BL.land = ' . $escaped . ')',
                'transfer' => '(SL.land = ' . $escaped . ' OR BL.land = ' . $escaped . ')'
            );
        }

        if ($scope == 'club' && (int) $clubId > 0) {
            return array(
                'player' => 'C.id = ' . (int) $clubId,
                'offer' => '(C.id = ' . (int) $clubId . ' OR BC.id = ' . (int) $clubId . ')',
                'transfer' => '(SELLER.id = ' . (int) $clubId . ' OR BUYER.id = ' . (int) $clubId . ')'
            );
        }

        return array('player' => '1=1', 'offer' => '1=1', 'transfer' => '1=1');
    }

    private static function adminPlayerSql(WebSoccer $websoccer, $scopeSql, $select, $extraWhere) {
        $prefix = $websoccer->getConfig('db_prefix');
        return "SELECT " . $select . " AS value
                FROM " . $prefix . "_spieler AS P
                INNER JOIN " . $prefix . "_verein AS C ON C.id = P.verein_id
                LEFT JOIN " . $prefix . "_liga AS L ON L.id = C.liga_id
                WHERE " . $extraWhere . " AND " . $scopeSql['player'];
    }

    private static function adminOfferSql(WebSoccer $websoccer, $scopeSql, $select, $extraWhere) {
        $prefix = $websoccer->getConfig('db_prefix');
        return "SELECT " . $select . "
                FROM " . $prefix . "_transfer_angebot AS A
                INNER JOIN " . $prefix . "_spieler AS P ON P.id = A.spieler_id
                LEFT JOIN " . $prefix . "_verein AS C ON C.id = P.verein_id
                LEFT JOIN " . $prefix . "_liga AS L ON L.id = C.liga_id
                LEFT JOIN " . $prefix . "_verein AS BC ON BC.id = A.verein_id
                LEFT JOIN " . $prefix . "_liga AS BL ON BL.id = BC.liga_id
                WHERE " . $extraWhere . " AND " . $scopeSql['offer'];
    }

    private static function adminTransferSql(WebSoccer $websoccer, $scopeSql, $select) {
        $prefix = $websoccer->getConfig('db_prefix');
        return "SELECT " . $select . " AS value
                FROM " . $prefix . "_transfer AS T
                LEFT JOIN " . $prefix . "_verein AS SELLER ON SELLER.id = T.seller_club_id
                LEFT JOIN " . $prefix . "_liga AS SL ON SL.id = SELLER.liga_id
                LEFT JOIN " . $prefix . "_verein AS BUYER ON BUYER.id = T.buyer_club_id
                LEFT JOIN " . $prefix . "_liga AS BL ON BL.id = BUYER.liga_id
                WHERE " . $scopeSql['transfer'];
    }

    private static function buildPositionSql(DbConnection $db, $coveredPositions, $columnName) {
        $values = array();
        foreach ($coveredPositions as $position => $info) {
            if (isset(self::$_positionKeys[$position])) {
                $values[] = "'" . $db->connection->real_escape_string($position) . "'";
            }
        }
        if (!count($values)) {
            return '';
        }
        return $columnName . ' IN (' . implode(',', $values) . ')';
    }

    private static function ageSql(WebSoccer $websoccer, $alias) {
        if ($websoccer->getConfig('players_aging') == 'birthday') {
            return 'TIMESTAMPDIFF(YEAR,' . $alias . '.geburtstag,CURDATE())';
        }
        return $alias . '.age';
    }

    private static function formatPlayerName($player) {
        if (isset($player['kunstname']) && strlen(trim((string) $player['kunstname']))) {
            return $player['kunstname'];
        }
        return trim((isset($player['vorname']) ? $player['vorname'] : '') . ' ' . (isset($player['nachname']) ? $player['nachname'] : ''));
    }

    private static function positionKey($position) {
        return isset(self::$_positionKeys[$position]) ? self::$_positionKeys[$position] : 'field';
    }

    private static function clamp($value, $minimum, $maximum) {
        return max((int) $minimum, min((int) $maximum, (int) $value));
    }

    private static function configInt(WebSoccer $websoccer, $key, $default) {
        $value = $websoccer->getConfig($key);
        if ($value === null || $value === '') {
            return (int) $default;
        }
        return (int) $value;
    }

    private static function fetchRows(DbConnection $db, $sql) {
        $result = $db->executeQuery($sql);
        $rows = array();
        while ($row = $result->fetch_array()) {
            $rows[] = $row;
        }
        $result->free();
        return $rows;
    }

    private static function fetchRow(DbConnection $db, $sql) {
        $result = $db->executeQuery($sql);
        $row = $result->fetch_array();
        $result->free();
        return $row ? $row : array();
    }

    private static function fetchScalar(DbConnection $db, $sql) {
        $row = self::fetchRow($db, $sql);
        return isset($row['value']) ? $row['value'] : 0;
    }

    public static function sortBestPlayers($a, $b) {
        if ((float) $a['strength'] == (float) $b['strength']) {
            if ((int) $a['age'] == (int) $b['age']) {
                return (int) $a['listed_price'] - (int) $b['listed_price'];
            }
            return (int) $a['age'] - (int) $b['age'];
        }
        return ((float) $a['strength'] < (float) $b['strength']) ? 1 : -1;
    }

    public static function sortBargains($a, $b) {
        if ((float) $a['saving_percent'] == (float) $b['saving_percent']) {
            return self::sortBestPlayers($a, $b);
        }
        return ((float) $a['saving_percent'] < (float) $b['saving_percent']) ? 1 : -1;
    }

    public static function sortOverpriced($a, $b) {
        if ((float) $a['overprice_percent'] == (float) $b['overprice_percent']) {
            return ((float) $a['strength'] < (float) $b['strength']) ? 1 : -1;
        }
        return ((float) $a['overprice_percent'] < (float) $b['overprice_percent']) ? 1 : -1;
    }

    public static function sortExpiring($a, $b) {
        if ((int) $a['contract_matches'] == (int) $b['contract_matches']) {
            return self::sortBestPlayers($a, $b);
        }
        return (int) $a['contract_matches'] - (int) $b['contract_matches'];
    }

    public static function sortLoanPlayers($a, $b) {
        $scoreA = isset($a['loan_recommendation_score']) ? (int) $a['loan_recommendation_score'] : 0;
        $scoreB = isset($b['loan_recommendation_score']) ? (int) $b['loan_recommendation_score'] : 0;
        if ($scoreA !== $scoreB) {
            return ($scoreA < $scoreB) ? 1 : -1;
        }
        if ((float) $a['strength'] != (float) $b['strength']) {
            return ((float) $a['strength'] < (float) $b['strength']) ? 1 : -1;
        }
        $costA = isset($a['loan_total_cost_per_match']) ? (int) $a['loan_total_cost_per_match'] : (int) $a['listed_price'];
        $costB = isset($b['loan_total_cost_per_match']) ? (int) $b['loan_total_cost_per_match'] : (int) $b['listed_price'];
        return $costA - $costB;
    }
}

?>
