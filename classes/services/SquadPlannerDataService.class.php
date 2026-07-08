<?php
/******************************************************

  This file is part of OpenWebSoccer-Sim.

******************************************************/

/**
 * Squad analysis and safe squad-planning actions.
 *
 * The service is intentionally reusable: the same analysis can later be used by
 * CPU transfer behavior before placing bids or changing transfer/loan lists.
 */
class SquadPlannerDataService {

    private static $_positionTargets = array(
        'Torwart' => 2,
        'Abwehr' => 7,
        'Mittelfeld' => 7,
        'Sturm' => 4
    );

    private static $_positionKeys = array(
        'Torwart' => 'goaly',
        'Abwehr' => 'defense',
        'Mittelfeld' => 'midfield',
        'Sturm' => 'striker'
    );

    public static function getAnalysis(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $teamId) {
        $teamId = (int) $teamId;
        if ($teamId < 1) {
            throw new Exception($i18n->getMessage('feature_requires_team'));
        }

        $team = self::getTeam($websoccer, $db, $teamId);
        if (!isset($team['id'])) {
            throw new Exception($i18n->getMessage('error_page_not_found'));
        }

        $players = self::getSquadPlayers($websoccer, $db, $teamId);
        $youthPlayers = self::getYouthPlayers($websoccer, $db, $teamId);
        if (class_exists('PlayerTraitsDataService')) {
            $players = PlayerTraitsDataService::attachTraitsToPlayers($websoccer, $db, $players);
            $youthPlayers = PlayerTraitsDataService::attachTraitsToYouthPlayers($websoccer, $db, $youthPlayers);
        }
        $contractRiskLimit = self::getContractRiskLimit($websoccer);

        $depth = self::computeDepth($players);
        $summary = self::computeSummary($players, $contractRiskLimit);
        $ageStructure = self::computeAgeStructure($players);
        $weaknesses = self::computeWeaknesses($i18n, $depth);
        $traitNeeds = self::computeTraitNeeds($players, $depth);

        $sellCandidates = self::computeSellCandidates($websoccer, $players, $summary, $depth);
        $loanCandidates = self::computeLoanCandidates($websoccer, $players, $summary, $depth);
        $youthCandidates = self::computeYouthCandidates($websoccer, $youthPlayers, $players, $depth, $weaknesses, $traitNeeds);

        return array(
            'team' => $team,
            'summary' => $summary,
            'age_structure' => $ageStructure,
            'depth' => $depth,
            'contract_risks' => self::getContractRiskPlayers($players, $contractRiskLimit),
            'weaknesses' => $weaknesses,
            'trait_needs' => $traitNeeds,
            'sell_candidates' => $sellCandidates,
            'loan_candidates' => $loanCandidates,
            'youth_candidates' => $youthCandidates,
            'contract_risk_limit' => $contractRiskLimit,
            'auto_sell_limit' => self::getPositiveConfig($websoccer, 'squadplanner_auto_sell_limit', 3),
            'auto_loan_limit' => self::getPositiveConfig($websoccer, 'squadplanner_auto_loan_limit', 3),
            'auto_youth_limit' => self::getPositiveConfig($websoccer, 'squadplanner_auto_youth_limit', 1)
        );
    }


    /**
     * Lightweight public helper for CPU transfers. It returns only the trait gaps,
     * without rendering texts or actions.
     *
     * @param WebSoccer $websoccer Application context.
     * @param DbConnection $db DB connection.
     * @param int $teamId Team ID.
     * @return array Trait need rows.
     */
    public static function getTraitNeedsForTeam(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $players = self::getSquadPlayers($websoccer, $db, (int) $teamId);
        if (class_exists('PlayerTraitsDataService')) {
            $players = PlayerTraitsDataService::attachTraitsToPlayers($websoccer, $db, $players);
        }
        return self::computeTraitNeeds($players, self::computeDepth($players));
    }

    public static function applyAction(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $teamId, $mode, $id = 0, $userId = 0) {
        $mode = trim((string) $mode);
        $teamId = (int) $teamId;
        $id = (int) $id;
        $userId = (int) $userId;

        if ($teamId < 1) {
            throw new Exception($i18n->getMessage('feature_requires_team'));
        }

        if ($mode == 'auto') {
            return self::applyAutomatic($websoccer, $db, $i18n, $teamId, $userId);
        }

        if ($mode == 'sell') {
            self::applySellCandidate($websoccer, $db, $i18n, $teamId, $id);
            return array('sell' => 1, 'loan' => 0, 'youth' => 0, 'messages' => array($i18n->getMessage('squadplanner_success_sell')));
        }

        if ($mode == 'loan') {
            self::applyLoanCandidate($websoccer, $db, $i18n, $teamId, $id);
            return array('sell' => 0, 'loan' => 1, 'youth' => 0, 'messages' => array($i18n->getMessage('squadplanner_success_loan')));
        }

        if ($mode == 'youth') {
            self::applyYouthCandidate($websoccer, $db, $i18n, $teamId, $id, $userId);
            return array('sell' => 0, 'loan' => 0, 'youth' => 1, 'messages' => array($i18n->getMessage('squadplanner_success_youth')));
        }

        throw new Exception($i18n->getMessage('error_page_not_found'));
    }

    public static function applyAutomatic(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $teamId, $userId = 0) {
        $teamId = (int) $teamId;
        $analysis = self::getAnalysis($websoccer, $db, $i18n, $teamId);
        $result = array('sell' => 0, 'loan' => 0, 'youth' => 0, 'messages' => array());

        $sellLimit = (int) $analysis['auto_sell_limit'];
        foreach ($analysis['sell_candidates'] as $candidate) {
            if ($result['sell'] >= $sellLimit) {
                break;
            }
            try {
                self::applySellCandidate($websoccer, $db, $i18n, $teamId, (int) $candidate['id']);
                $result['sell']++;
            } catch (Exception $e) {
                // A single candidate can become invalid after a previous action. Continue safely.
            }
        }

        $loanLimit = (int) $analysis['auto_loan_limit'];
        foreach ($analysis['loan_candidates'] as $candidate) {
            if ($result['loan'] >= $loanLimit) {
                break;
            }
            try {
                self::applyLoanCandidate($websoccer, $db, $i18n, $teamId, (int) $candidate['id']);
                $result['loan']++;
            } catch (Exception $e) {
                // Continue with remaining safe candidates.
            }
        }

        $youthLimit = (int) $analysis['auto_youth_limit'];
        foreach ($analysis['youth_candidates'] as $candidate) {
            if ($result['youth'] >= $youthLimit) {
                break;
            }
            if (empty($candidate['promotable']) || empty($candidate['fits_need'])) {
                continue;
            }
            try {
                self::applyYouthCandidate($websoccer, $db, $i18n, $teamId, (int) $candidate['id'], $userId);
                $result['youth']++;
            } catch (Exception $e) {
                // Continue with remaining safe candidates.
            }
        }

        if ($result['sell'] || $result['loan'] || $result['youth']) {
            $result['messages'][] = $i18n->getMessage('squadplanner_auto_success', $result['sell'], $result['loan'], $result['youth']);
        } else {
            $result['messages'][] = $i18n->getMessage('squadplanner_auto_noop');
        }

        return $result;
    }

    public static function applySellCandidate(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $teamId, $playerId) {
        if (!$websoccer->getConfig('transfermarket_enabled')) {
            throw new Exception($i18n->getMessage('squadplanner_err_transfer_disabled'));
        }

        $player = self::getPlayerForAction($websoccer, $db, $teamId, $playerId);
        if (!self::isSafeSellCandidate($websoccer, $db, $i18n, $teamId, $player)) {
            throw new Exception($i18n->getMessage('squadplanner_err_not_candidate'));
        }

        $minBid = self::computeRecommendedMinBid($player);
        $now = $websoccer->getNowAsTimestamp();
        $durationDays = (int) $websoccer->getConfig('transfermarket_duration_days');
        if ($durationDays < 1) {
            $durationDays = 7;
        }

        $db->queryUpdate(
            array(
                'transfermarkt' => 1,
                'transfer_start' => $now,
                'transfer_ende' => $now + 24 * 3600 * $durationDays,
                'transfer_mindestgebot' => $minBid
            ),
            $websoccer->getConfig('db_prefix') . '_spieler',
            'id = %d',
            (int) $playerId
        );
    }

    public static function applyLoanCandidate(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $teamId, $playerId) {
        if (!$websoccer->getConfig('lending_enabled')) {
            throw new Exception($i18n->getMessage('squadplanner_err_lending_disabled'));
        }

        $player = self::getPlayerForAction($websoccer, $db, $teamId, $playerId);
        if (!self::isSafeLoanCandidate($websoccer, $db, $i18n, $teamId, $player)) {
            throw new Exception($i18n->getMessage('squadplanner_err_not_candidate'));
        }

        $fee = self::computeRecommendedLoanFee($player);
        $db->queryUpdate(
            array('lending_fee' => (int) $fee),
            $websoccer->getConfig('db_prefix') . '_spieler',
            'id = %d',
            (int) $playerId
        );

        LoanDataService::saveOffer(
            $websoccer,
            $db,
            (int) $playerId,
            (int) $teamId,
            (int) $fee,
            100,
            LoanDataService::OPTION_NONE,
            0,
            false
        );
    }

    public static function applyYouthCandidate(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $teamId, $youthPlayerId, $userId = 0) {
        if (!$websoccer->getConfig('youth_enabled')) {
            throw new Exception($i18n->getMessage('error_access_denied'));
        }

        $analysis = self::getAnalysis($websoccer, $db, $i18n, $teamId);
        $candidate = null;
        foreach ($analysis['youth_candidates'] as $candidateRow) {
            if ((int) $candidateRow['id'] == (int) $youthPlayerId) {
                $candidate = $candidateRow;
                break;
            }
        }

        if (!$candidate || empty($candidate['promotable'])) {
            throw new Exception($i18n->getMessage('squadplanner_err_not_candidate'));
        }

        $mainPosition = self::getDefaultMainPosition($candidate['position_raw']);
        self::promoteYouthPlayer($websoccer, $db, $i18n, $candidate, $mainPosition, (int) $userId);
    }

    public static function getAdminClubs(WebSoccer $websoccer, DbConnection $db, $query = '', $limit = 80) {
        $query = trim((string) $query);
        $limit = (int) $limit;
        if ($limit < 1) {
            $limit = 80;
        }

        $columns = array(
            'C.id' => 'id',
            'C.name' => 'name',
            'L.name' => 'league_name',
            'L.land' => 'country'
        );
        $fromTable = $websoccer->getConfig('db_prefix') . '_verein AS C';
        $fromTable .= ' LEFT JOIN ' . $websoccer->getConfig('db_prefix') . '_liga AS L ON L.id = C.liga_id';
        $where = "C.status = '1'";
        $parameters = array();

        if (strlen($query)) {
            $where .= ' AND C.name LIKE \'%s\'';
            $parameters[] = '%' . $query . '%';
        }

        $where .= ' ORDER BY C.name ASC';
        $result = $db->querySelect($columns, $fromTable, $where, $parameters, $limit);
        $clubs = array();
        while ($row = $result->fetch_array()) {
            $clubs[] = $row;
        }
        $result->free();
        return $clubs;
    }

    private static function getTeam(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $columns = array(
            'C.id' => 'id',
            'C.name' => 'name',
            'C.user_id' => 'user_id',
            'C.finanz_budget' => 'budget',
            'L.name' => 'league_name',
            'L.land' => 'country',
            'L.division' => 'division'
        );
        $fromTable = $websoccer->getConfig('db_prefix') . '_verein AS C';
        $fromTable .= ' LEFT JOIN ' . $websoccer->getConfig('db_prefix') . '_liga AS L ON L.id = C.liga_id';
        $result = $db->querySelect($columns, $fromTable, 'C.id = %d', (int) $teamId, 1);
        $team = $result->fetch_array();
        $result->free();
        return $team ? $team : array();
    }

    private static function getSquadPlayers(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $columns = array(
            'P.id' => 'id',
            'P.vorname' => 'firstname',
            'P.nachname' => 'lastname',
            'P.kunstname' => 'pseudonym',
            'P.position' => 'position_raw',
            'P.position_main' => 'position_main',
            'P.position_second' => 'position_second',
            'P.w_staerke' => 'strength',
            'P.w_talent' => 'talent',
            'P.w_zufriedenheit' => 'satisfaction',
            'P.vertrag_spiele' => 'contract_matches',
            'P.vertrag_gehalt' => 'contract_salary',
            'P.marktwert' => 'marketvalue',
            'P.transfermarkt' => 'transfermarket',
            'P.transfer_start' => 'transfer_start',
            'P.transfer_ende' => 'transfer_end',
            'P.lending_fee' => 'lending_fee',
            'P.lending_owner_id' => 'lending_owner_id',
            'P.lending_matches' => 'lending_matches',
            'P.unsellable' => 'unsellable',
            'P.sa_spiele' => 'season_matches'
        );

        if ($websoccer->getConfig('players_aging') == 'birthday') {
            $columns['TIMESTAMPDIFF(YEAR,P.geburtstag,CURDATE())'] = 'age';
        } else {
            $columns['P.age'] = 'age';
        }

        $fromTable = $websoccer->getConfig('db_prefix') . '_spieler AS P';
        $whereCondition = "P.status = '1' AND P.verein_id = %d ORDER BY P.position ASC, P.position_main ASC, P.w_staerke DESC, P.nachname ASC";
        $result = $db->querySelect($columns, $fromTable, $whereCondition, (int) $teamId, 80);

        $players = array();
        while ($player = $result->fetch_array()) {
            $player['name'] = self::formatPlayerName($player);
            $player['position_key'] = self::getPositionKey($player['position_raw']);
            $player['strength'] = (float) $player['strength'];
            $player['age'] = (int) $player['age'];
            $player['contract_matches'] = (int) $player['contract_matches'];
            $player['contract_salary'] = (int) $player['contract_salary'];
            $player['marketvalue'] = (int) $player['marketvalue'];
            $player['talent'] = (int) $player['talent'];
            $players[] = $player;
        }
        $result->free();
        return $players;
    }

    private static function getYouthPlayers(WebSoccer $websoccer, DbConnection $db, $teamId) {
        if (!$websoccer->getConfig('youth_enabled')) {
            return array();
        }

        $columns = array(
            'id' => 'id',
            'firstname' => 'firstname',
            'lastname' => 'lastname',
            'position' => 'position_raw',
            'nation' => 'nation',
            'age' => 'age',
            'strength' => 'strength',
            'transfer_fee' => 'transfer_fee',
            'st_matches' => 'st_matches',
            'st_goals' => 'st_goals',
            'st_assists' => 'st_assists',
            'team_id' => 'team_id'
        );

        $result = $db->querySelect($columns, $websoccer->getConfig('db_prefix') . '_youthplayer', 'team_id = %d ORDER BY strength DESC, age DESC, lastname ASC', (int) $teamId, 80);
        $players = array();
        while ($player = $result->fetch_array()) {
            $player['name'] = trim($player['firstname'] . ' ' . $player['lastname']);
            $player['position_key'] = self::getPositionKey($player['position_raw']);
            $player['age'] = (int) $player['age'];
            $player['strength'] = (float) $player['strength'];
            $players[] = $player;
        }
        $result->free();
        return $players;
    }

    private static function computeSummary($players, $contractRiskLimit) {
        $count = count($players);
        $sumAge = 0;
        $sumStrength = 0;
        $sumSalary = 0;
        $riskCount = 0;
        foreach ($players as $player) {
            $sumAge += (int) $player['age'];
            $sumStrength += (float) $player['strength'];
            $sumSalary += (int) $player['contract_salary'];
            if ((int) $player['contract_matches'] <= (int) $contractRiskLimit) {
                $riskCount++;
            }
        }

        return array(
            'player_count' => $count,
            'avg_age' => ($count) ? round($sumAge / $count, 1) : 0,
            'avg_strength' => ($count) ? round($sumStrength / $count, 1) : 0,
            'avg_salary' => ($count) ? round($sumSalary / $count) : 0,
            'contract_risk_count' => $riskCount
        );
    }

    private static function computeDepth($players) {
        $depth = array();
        foreach (self::$_positionTargets as $position => $target) {
            $depth[$position] = array(
                'position' => $position,
                'key' => self::getPositionKey($position),
                'target' => $target,
                'count' => 0,
                'avg_strength' => 0,
                'weakest_strength' => 0,
                'strongest_strength' => 0,
                'status' => 'ok',
                'diff' => 0
            );
        }

        $strengths = array();
        foreach ($players as $player) {
            $position = self::normalizePosition($player['position_raw']);
            if (!isset($depth[$position])) {
                continue;
            }
            $depth[$position]['count']++;
            if (!isset($strengths[$position])) {
                $strengths[$position] = array();
            }
            $strengths[$position][] = (float) $player['strength'];
        }

        foreach ($depth as $position => $row) {
            $count = (int) $row['count'];
            $target = (int) $row['target'];
            $depth[$position]['diff'] = $count - $target;
            if ($count < $target) {
                $depth[$position]['status'] = 'shortage';
            } elseif ($count > ($target + 2)) {
                $depth[$position]['status'] = 'surplus';
            }
            if (isset($strengths[$position]) && count($strengths[$position])) {
                sort($strengths[$position]);
                $depth[$position]['weakest_strength'] = reset($strengths[$position]);
                $depth[$position]['strongest_strength'] = end($strengths[$position]);
                $depth[$position]['avg_strength'] = round(array_sum($strengths[$position]) / count($strengths[$position]), 1);
            }
        }

        return $depth;
    }

    private static function computeAgeStructure($players) {
        if (!count($players)) {
            return array('key' => 'balanced', 'label_key' => 'squadplanner_age_balanced', 'older_count' => 0, 'prime_count' => 0, 'young_count' => 0);
        }

        $sum = 0;
        $older = 0;
        $prime = 0;
        $young = 0;
        foreach ($players as $player) {
            $age = (int) $player['age'];
            $sum += $age;
            if ($age >= 32) {
                $older++;
            } elseif ($age >= 28) {
                $prime++;
            } elseif ($age <= 22) {
                $young++;
            }
        }

        $avg = $sum / count($players);
        if ($avg > 29 || ($older / count($players)) >= 0.30) {
            return array('key' => 'old', 'label_key' => 'squadplanner_age_old', 'older_count' => $older, 'prime_count' => $prime, 'young_count' => $young);
        }
        if ($avg < 23 && $prime < 3) {
            return array('key' => 'young', 'label_key' => 'squadplanner_age_young', 'older_count' => $older, 'prime_count' => $prime, 'young_count' => $young);
        }
        return array('key' => 'balanced', 'label_key' => 'squadplanner_age_balanced', 'older_count' => $older, 'prime_count' => $prime, 'young_count' => $young);
    }

    private static function computeWeaknesses(I18n $i18n, $depth) {
        $weaknesses = array();
        foreach ($depth as $position => $row) {
            if ($row['status'] == 'shortage') {
                $need = abs((int) $row['diff']);
                $weaknesses[] = array(
                    'type' => 'need',
                    'position' => $position,
                    'position_key' => $row['key'],
                    'amount' => $need,
                    'message' => sprintf($i18n->getMessage('squadplanner_need_position'), $need, $i18n->getMessage('player_position_' . $row['key']))
                );
            } elseif ($row['status'] == 'surplus') {
                $weaknesses[] = array(
                    'type' => 'surplus',
                    'position' => $position,
                    'position_key' => $row['key'],
                    'amount' => (int) $row['diff'],
                    'message' => sprintf($i18n->getMessage('squadplanner_too_many_position'), $i18n->getMessage('player_position_' . $row['key']))
                );
            }
        }
        return $weaknesses;
    }


    private static function computeTraitNeeds($players, $depth) {
        if (!class_exists('PlayerTraitsDataService')) {
            return array();
        }

        $requirements = self::getTraitRequirements();
        $needs = array();
        foreach ($requirements as $requirement) {
            $traitKey = $requirement['trait_key'];
            $position = $requirement['position'];
            $minimum = (int) $requirement['min_count'];
            $current = 0;
            $bestValue = 0;

            foreach ($players as $player) {
                if (self::normalizePosition($player['position_raw']) != $position) {
                    continue;
                }
                $map = self::getAttachedTraitMap($player);
                if (!isset($map[$traitKey]) || (int) $map[$traitKey] < 1) {
                    continue;
                }
                $current++;
                $bestValue = max($bestValue, (int) $map[$traitKey]);
            }

            if ($current >= $minimum) {
                continue;
            }

            $positionDepth = isset($depth[$position]) ? $depth[$position] : array('status' => 'ok');
            $priority = (int) $requirement['priority'];
            if (isset($positionDepth['status']) && $positionDepth['status'] == 'shortage') {
                $priority += 2;
            }
            if ($current == 0) {
                $priority += 1;
            }

            $needs[] = array(
                'trait_key' => $traitKey,
                'label_key' => PlayerTraitsDataService::getTraitLabelKey($traitKey),
                'position' => $position,
                'position_key' => self::getPositionKey($position),
                'current_count' => $current,
                'needed_count' => $minimum,
                'best_value' => $bestValue,
                'priority' => max(1, min(6, $priority))
            );
        }

        usort($needs, array('SquadPlannerDataService', 'sortTraitNeeds'));
        return array_slice($needs, 0, 8);
    }

    private static function getTraitRequirements() {
        return array(
            array('trait_key' => 'reflexe', 'position' => 'Torwart', 'min_count' => 1, 'priority' => 4),
            array('trait_key' => 'elfmetertoeter', 'position' => 'Torwart', 'min_count' => 1, 'priority' => 2),
            array('trait_key' => 'viererkette', 'position' => 'Abwehr', 'min_count' => 2, 'priority' => 4),
            array('trait_key' => 'kopfballstaerke', 'position' => 'Abwehr', 'min_count' => 2, 'priority' => 3),
            array('trait_key' => 'laufstaerke', 'position' => 'Abwehr', 'min_count' => 1, 'priority' => 2),
            array('trait_key' => 'spielmacher', 'position' => 'Mittelfeld', 'min_count' => 1, 'priority' => 5),
            array('trait_key' => 'ballzauberer', 'position' => 'Mittelfeld', 'min_count' => 1, 'priority' => 3),
            array('trait_key' => 'flankenspezialist', 'position' => 'Mittelfeld', 'min_count' => 1, 'priority' => 3),
            array('trait_key' => 'freistossspezialist', 'position' => 'Mittelfeld', 'min_count' => 1, 'priority' => 2),
            array('trait_key' => 'torinstinkt', 'position' => 'Sturm', 'min_count' => 1, 'priority' => 5),
            array('trait_key' => 'kopfballstaerke', 'position' => 'Sturm', 'min_count' => 1, 'priority' => 3),
            array('trait_key' => 'dribbler', 'position' => 'Sturm', 'min_count' => 1, 'priority' => 2),
            array('trait_key' => 'elfmeterschuetze', 'position' => 'Sturm', 'min_count' => 1, 'priority' => 2)
        );
    }

    private static function computeSellCandidates(WebSoccer $websoccer, $players, $summary, $depth) {
        $candidates = array();
        foreach ($players as $player) {
            if (!self::isOwnedActionablePlayer($player)) {
                continue;
            }
            if ((int) $player['transfermarket'] > 0 || (int) $player['lending_fee'] > 0 || (int) $player['unsellable'] > 0) {
                continue;
            }

            $position = self::normalizePosition($player['position_raw']);
            $positionDepth = isset($depth[$position]) ? $depth[$position] : null;
            $score = 0;
            $reasons = array();

            if ((int) $player['age'] >= 32) {
                $score += 3;
                $reasons[] = 'squadplanner_reason_old';
            } elseif ((int) $player['age'] >= 30) {
                $score += 2;
                $reasons[] = 'squadplanner_reason_old';
            }

            if ((int) $player['contract_matches'] <= self::getContractRiskLimit($websoccer)) {
                $score += 2;
                $reasons[] = 'squadplanner_reason_contract';
            }

            if ((float) $player['strength'] < ((float) $summary['avg_strength'] - 5)) {
                $score += 2;
                $reasons[] = 'squadplanner_reason_weak';
            }

            if ($positionDepth && (int) $positionDepth['count'] > ((int) $positionDepth['target'] + 1)) {
                $score += 2;
                $reasons[] = 'squadplanner_reason_surplus';
            }

            if ((int) $summary['avg_salary'] > 0 && (int) $player['contract_salary'] > ((int) $summary['avg_salary'] * 1.25) && (float) $player['strength'] < ((float) $summary['avg_strength'] + 1)) {
                $score += 1;
                $reasons[] = 'squadplanner_reason_salary';
            }

            if ($score >= 3 && $positionDepth && (int) $positionDepth['count'] > (int) $positionDepth['target']) {
                $player['score'] = $score;
                $player['reasons'] = array_values(array_unique($reasons));
                $player['recommended_min_bid'] = self::computeRecommendedMinBid($player);
                $candidates[] = $player;
            }
        }

        usort($candidates, array('SquadPlannerDataService', 'sortByScoreThenAge'));
        return array_slice($candidates, 0, 8);
    }

    private static function computeLoanCandidates(WebSoccer $websoccer, $players, $summary, $depth) {
        $candidates = array();
        foreach ($players as $player) {
            if (!self::isOwnedActionablePlayer($player)) {
                continue;
            }
            if ((int) $player['transfermarket'] > 0 || (int) $player['lending_fee'] > 0 || (int) $player['contract_matches'] <= (int) $websoccer->getConfig('lending_matches_min')) {
                continue;
            }
            if ((int) $player['age'] < 18 || (int) $player['age'] > 23 || (int) $player['talent'] < 3) {
                continue;
            }

            $position = self::normalizePosition($player['position_raw']);
            $positionDepth = isset($depth[$position]) ? $depth[$position] : null;
            $score = 0;
            $reasons = array('squadplanner_reason_young');

            if ((int) $player['talent'] >= 4) {
                $score += 2;
                $reasons[] = 'squadplanner_reason_talent';
            } else {
                $score += 1;
            }

            if ($positionDepth && (int) $positionDepth['count'] > (int) $positionDepth['target']) {
                $score += 2;
                $reasons[] = 'squadplanner_reason_depth';
            }

            if ($positionDepth && (float) $player['strength'] < ((float) $positionDepth['avg_strength'] - 3)) {
                $score += 1;
                $reasons[] = 'squadplanner_reason_weak';
            }

            if ($score >= 2 && $positionDepth && (int) $positionDepth['count'] > (int) $positionDepth['target']) {
                $player['score'] = $score;
                $player['reasons'] = array_values(array_unique($reasons));
                $player['recommended_loan_fee'] = self::computeRecommendedLoanFee($player);
                $candidates[] = $player;
            }
        }

        usort($candidates, array('SquadPlannerDataService', 'sortByScoreThenTalent'));
        return array_slice($candidates, 0, 8);
    }

    private static function computeYouthCandidates(WebSoccer $websoccer, $youthPlayers, $players, $depth, $weaknesses, $traitNeeds = array()) {
        $neededPositions = array();
        foreach ($weaknesses as $weakness) {
            if ($weakness['type'] == 'need') {
                $neededPositions[$weakness['position']] = true;
            }
        }

        $positionStats = array();
        foreach (self::$_positionTargets as $position => $target) {
            $positionStats[$position] = array('weakest' => 0, 'avg' => 0, 'count' => 0, 'sum' => 0);
        }
        foreach ($players as $player) {
            $position = self::normalizePosition($player['position_raw']);
            if (!isset($positionStats[$position])) {
                continue;
            }
            $positionStats[$position]['count']++;
            $positionStats[$position]['sum'] += (float) $player['strength'];
            if (!$positionStats[$position]['weakest'] || (float) $player['strength'] < (float) $positionStats[$position]['weakest']) {
                $positionStats[$position]['weakest'] = (float) $player['strength'];
            }
        }
        foreach ($positionStats as $position => $stat) {
            if ((int) $stat['count'] > 0) {
                $positionStats[$position]['avg'] = round($stat['sum'] / $stat['count'], 1);
            }
        }

        $minProfessionalAge = (int) $websoccer->getConfig('youth_min_age_professional');
        if ($minProfessionalAge < 1) {
            $minProfessionalAge = 18;
        }

        $candidates = array();
        foreach ($youthPlayers as $player) {
            if ((int) $player['age'] < 16) {
                continue;
            }
            $position = self::normalizePosition($player['position_raw']);
            $stat = isset($positionStats[$position]) ? $positionStats[$position] : array('weakest' => 0, 'avg' => 0);
            $fitsNeed = isset($neededPositions[$position]);
            $traitNeedScore = self::scorePlayerTraitsForNeed($player, $traitNeeds, $position);
            $fitsTraitNeed = ($traitNeedScore > 0);
            $closeToSquad = false;
            if ((float) $stat['weakest'] > 0 && (float) $player['strength'] >= ((float) $stat['weakest'] - 5)) {
                $closeToSquad = true;
            }
            if ((float) $stat['avg'] > 0 && (float) $player['strength'] >= ((float) $stat['avg'] - 10)) {
                $closeToSquad = true;
            }
            if ($fitsNeed && (float) $player['strength'] >= 45) {
                $closeToSquad = true;
            }
            if ($fitsTraitNeed && (float) $player['strength'] >= 40) {
                $closeToSquad = true;
            }

            if (!$closeToSquad) {
                continue;
            }

            $score = (float) $player['strength'];
            $reasons = array('squadplanner_reason_close');
            if ($fitsNeed) {
                $score += 10;
                $reasons[] = 'squadplanner_reason_need';
            }
            if ($fitsTraitNeed) {
                $score += min(18, $traitNeedScore * 3);
                $reasons[] = 'squadplanner_reason_trait_need';
            }
            $player['score'] = $score;
            $player['fits_need'] = $fitsNeed;
            $player['fits_trait_need'] = $fitsTraitNeed;
            $player['trait_need_score'] = $traitNeedScore;
            $player['promotable'] = ((int) $player['age'] >= $minProfessionalAge);
            $player['reasons'] = $reasons;
            $candidates[] = $player;
        }

        usort($candidates, array('SquadPlannerDataService', 'sortByScoreOnly'));
        return array_slice($candidates, 0, 8);
    }


    private static function scorePlayerTraitsForNeed($player, $traitNeeds, $position = '') {
        if (!class_exists('PlayerTraitsDataService')) {
            return 0;
        }
        $filteredNeeds = array();
        foreach ($traitNeeds as $need) {
            if (isset($need['position']) && strlen($position) && $need['position'] != $position) {
                continue;
            }
            $filteredNeeds[] = $need;
        }
        if (!count($filteredNeeds)) {
            return 0;
        }
        return PlayerTraitsDataService::getTraitNeedScore(self::getAttachedTraitMap($player), $filteredNeeds);
    }

    private static function getAttachedTraitMap($player) {
        $map = array();
        if (!isset($player['traits']) || !is_array($player['traits'])) {
            return $map;
        }
        foreach ($player['traits'] as $trait) {
            if (isset($trait['key']) && isset($trait['value'])) {
                $value = max(0, min(3, (int) $trait['value']));
                if ($value > 0) {
                    $map[$trait['key']] = $value;
                }
            }
        }
        return $map;
    }

    public static function sortTraitNeeds($a, $b) {
        if ($a['priority'] == $b['priority']) {
            if ($a['needed_count'] == $b['needed_count']) {
                return strcmp($a['trait_key'], $b['trait_key']);
            }
            return ($a['needed_count'] > $b['needed_count']) ? -1 : 1;
        }
        return ($a['priority'] > $b['priority']) ? -1 : 1;
    }

    private static function getContractRiskPlayers($players, $limit) {
        $risk = array();
        foreach ($players as $player) {
            if ((int) $player['contract_matches'] <= (int) $limit) {
                $risk[] = $player;
            }
        }
        usort($risk, array('SquadPlannerDataService', 'sortByContract'));
        return $risk;
    }

    private static function isSafeSellCandidate(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $teamId, $player) {
        if (!self::isOwnedActionablePlayer($player)) {
            return false;
        }
        if ((int) $player['transfermarket'] > 0 || (int) $player['lending_fee'] > 0 || (int) $player['unsellable'] > 0) {
            return false;
        }
        if (!self::teamSizeAllowsOutgoing($websoccer, $db, $teamId)) {
            throw new Exception($i18n->getMessage('squadplanner_err_min_team_size'));
        }

        $analysis = self::getAnalysis($websoccer, $db, $i18n, $teamId);
        foreach ($analysis['sell_candidates'] as $candidate) {
            if ((int) $candidate['id'] == (int) $player['id']) {
                return true;
            }
        }
        return false;
    }

    private static function isSafeLoanCandidate(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $teamId, $player) {
        if (!self::isOwnedActionablePlayer($player)) {
            return false;
        }
        if ((int) $player['transfermarket'] > 0 || (int) $player['lending_fee'] > 0) {
            return false;
        }
        if ((int) $player['contract_matches'] <= (int) $websoccer->getConfig('lending_matches_min')) {
            return false;
        }
        if (!self::teamSizeAllowsOutgoing($websoccer, $db, $teamId)) {
            throw new Exception($i18n->getMessage('squadplanner_err_min_team_size'));
        }

        $analysis = self::getAnalysis($websoccer, $db, $i18n, $teamId);
        foreach ($analysis['loan_candidates'] as $candidate) {
            if ((int) $candidate['id'] == (int) $player['id']) {
                return true;
            }
        }
        return false;
    }

    private static function getPlayerForAction(WebSoccer $websoccer, DbConnection $db, $teamId, $playerId) {
        $players = self::getSquadPlayers($websoccer, $db, $teamId);
        foreach ($players as $player) {
            if ((int) $player['id'] == (int) $playerId) {
                return $player;
            }
        }
        return array();
    }

    private static function isOwnedActionablePlayer($player) {
        if (!isset($player['id'])) {
            return false;
        }
        // Borrowed players have a lending owner and must never be sold or lent by the borrower.
        if ((int) $player['lending_owner_id'] > 0) {
            return false;
        }
        return true;
    }

    private static function teamSizeAllowsOutgoing(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $teamSize = TeamsDataService::getTeamSize($websoccer, $db, $teamId);
        $minSize = (int) $websoccer->getConfig('transfermarket_min_teamsize');
        if ($minSize < 1) {
            $minSize = 18;
        }
        return ((int) $teamSize > $minSize);
    }

    private static function computeRecommendedMinBid($player) {
        $marketValue = (int) $player['marketvalue'];
        if ($marketValue < 1) {
            $marketValue = max(100000, (int) round((float) $player['strength'] * 10000));
        }
        $minBid = max((int) round($marketValue * 0.70), (int) round($marketValue / 2));
        return self::roundMoney($minBid);
    }

    private static function computeRecommendedLoanFee($player) {
        $maxFee = LoanDataService::getMaxLoanFee(array(
            'marktwert' => (int) $player['marketvalue'],
            'vertrag_gehalt' => (int) $player['contract_salary']
        ));
        $fee = (int) round(((int) $player['marketvalue'] * 0.015) + ((int) $player['contract_salary'] * 0.25));
        $fee = max(1000, $fee);
        $fee = min($maxFee, $fee);
        return self::roundMoney($fee);
    }

    private static function promoteYouthPlayer(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $player, $mainPosition, $userId = 0) {
        if ((int) $player['team_id'] < 1) {
            throw new Exception($i18n->getMessage('error_page_not_found'));
        }

        $minimumAge = (int) $websoccer->getConfig('youth_min_age_professional');
        if ($minimumAge < 1) {
            $minimumAge = 18;
        }
        if ((int) $player['age'] < $minimumAge) {
            throw new Exception($i18n->getMessage('squadplanner_err_not_candidate'));
        }

        $salary = (int) round((int) $websoccer->getConfig('youth_salary_per_strength') * (float) $player['strength']);
        $team = TeamsDataService::getTeamSummaryById($websoccer, $db, (int) $player['team_id']);
        $currentPlayerSalaries = TeamsDataService::getTotalPlayersSalariesOfTeam($websoccer, $db, (int) $player['team_id']);
        if (!$team || !isset($team['team_budget']) || (int) $team['team_budget'] <= ($currentPlayerSalaries + $salary)) {
            throw new Exception($i18n->getMessage('youthteam_makeprofessional_err_budgettooless'));
        }

        $talentRoll = mt_rand(1, 100);
        $talent = ($talentRoll > 94) ? 6 : mt_rand(1, 5);
        if ($talent >= 4) {
            $minSkill = 75;
            $maxSkill = 100;
        } elseif ($talent >= 2) {
            $minSkill = 50;
            $maxSkill = 80;
        } else {
            $minSkill = 25;
            $maxSkill = 60;
        }

        $maxStrength = mt_rand($minSkill, $maxSkill);
        if ($maxStrength < (float) $player['strength']) {
            $maxStrength = (float) $player['strength'];
        }

        $birthday = date('Y-m-d', strtotime('-' . (int) $player['age'] . ' years', $websoccer->getNowAsTimestamp()));
        $columns = array(
            'verein_id' => (int) $player['team_id'],
            'vorname' => $player['firstname'],
            'nachname' => $player['lastname'],
            'geburtstag' => $birthday,
            'age' => (int) $player['age'],
            'position' => $player['position_raw'],
            'position_main' => $mainPosition,
            'nation' => $player['nation'],
            'w_staerke' => (float) $player['strength'],
            'w_staerke_max' => $maxStrength,
            'w_technik' => (int) $websoccer->getConfig('youth_professionalmove_technique'),
            'w_kondition' => (int) $websoccer->getConfig('youth_professionalmove_stamina'),
            'w_frische' => (int) $websoccer->getConfig('youth_professionalmove_freshness'),
            'w_zufriedenheit' => (int) $websoccer->getConfig('youth_professionalmove_satisfaction'),
            'w_talent' => $talent,
            'personality' => (class_exists('PlayerPersonalityDataService') ? PlayerPersonalityDataService::getRandomTrait() : ''),
            'w_passing' => mt_rand($minSkill, $maxSkill),
            'w_shooting' => mt_rand($minSkill, $maxSkill),
            'w_heading' => mt_rand($minSkill, $maxSkill),
            'w_tackling' => mt_rand($minSkill, $maxSkill),
            'w_freekick' => mt_rand($minSkill, $maxSkill),
            'w_pace' => mt_rand($minSkill, $maxSkill),
            'w_creativity' => mt_rand($minSkill, $maxSkill),
            'w_influence' => mt_rand($minSkill, $maxSkill),
            'w_flair' => mt_rand($minSkill, $maxSkill),
            'w_penalty' => mt_rand($minSkill, $maxSkill),
            'w_penalty_killing' => mt_rand($minSkill, $maxSkill),
            'vertrag_gehalt' => $salary,
            'vertrag_spiele' => (int) $websoccer->getConfig('youth_professionalmove_matches'),
            'vertrag_torpraemie' => 0,
            'status' => '1'
        );

        $db->connection->begin_transaction();
        try {
            $db->queryInsert($columns, $websoccer->getConfig('db_prefix') . '_spieler');
            $professionalPlayerId = (int) $db->getLastInsertedId();
            $playerName = trim($player['firstname'] . ' ' . $player['lastname']);

            if ((int) $userId > 0 && class_exists('ManagerMissionsDataService')) {
                ManagerMissionsDataService::recordYouthPromotion($websoccer, $db, (int) $userId, (int) $player['team_id'], (int) $player['id'], $professionalPlayerId, $playerName);
            }
            if ((int) $userId > 0 && class_exists('BadgeAwardService')) {
                BadgeAwardService::processYouthPromotion($websoccer, $db, (int) $userId, (int) $player['team_id'], $professionalPlayerId);
            }

            $db->queryDelete($websoccer->getConfig('db_prefix') . '_youthplayer', 'id = %d', (int) $player['id']);
            $db->connection->commit();
        } catch (Exception $e) {
            $db->connection->rollback();
            throw $e;
        }
    }

    private static function getDefaultMainPosition($position) {
        $position = self::normalizePosition($position);
        if ($position == 'Torwart') {
            return 'T';
        }
        if ($position == 'Abwehr') {
            return 'IV';
        }
        if ($position == 'Mittelfeld') {
            return 'ZM';
        }
        return 'MS';
    }

    private static function normalizePosition($position) {
        if ($position == 'Torwart' || $position == 'Abwehr' || $position == 'Mittelfeld' || $position == 'Sturm') {
            return $position;
        }
        return 'Sturm';
    }

    private static function getPositionKey($position) {
        $position = self::normalizePosition($position);
        return isset(self::$_positionKeys[$position]) ? self::$_positionKeys[$position] : 'striker';
    }

    private static function getContractRiskLimit(WebSoccer $websoccer) {
        return self::getPositiveConfig($websoccer, 'squadplanner_contract_risk_matches', 30);
    }

    private static function getPositiveConfig(WebSoccer $websoccer, $key, $default) {
        $value = (int) $websoccer->getConfig($key);
        return ($value > 0) ? $value : (int) $default;
    }

    private static function roundMoney($amount) {
        $amount = (int) round($amount);
        if ($amount >= 100000) {
            return (int) (round($amount / 10000) * 10000);
        }
        return (int) (round($amount / 1000) * 1000);
    }

    private static function formatPlayerName($player) {
        if (isset($player['pseudonym']) && strlen(trim($player['pseudonym']))) {
            return trim($player['pseudonym']);
        }
        return trim((isset($player['firstname']) ? $player['firstname'] : '') . ' ' . (isset($player['lastname']) ? $player['lastname'] : ''));
    }

    public static function sortByScoreThenAge($a, $b) {
        if ((float) $a['score'] == (float) $b['score']) {
            return (int) $b['age'] - (int) $a['age'];
        }
        return ((float) $a['score'] < (float) $b['score']) ? 1 : -1;
    }

    public static function sortByScoreThenTalent($a, $b) {
        if ((float) $a['score'] == (float) $b['score']) {
            return (int) $b['talent'] - (int) $a['talent'];
        }
        return ((float) $a['score'] < (float) $b['score']) ? 1 : -1;
    }

    public static function sortByScoreOnly($a, $b) {
        if ((float) $a['score'] == (float) $b['score']) {
            return (float) $b['strength'] < (float) $a['strength'] ? -1 : 1;
        }
        return ((float) $a['score'] < (float) $b['score']) ? 1 : -1;
    }

    public static function sortByContract($a, $b) {
        return (int) $a['contract_matches'] - (int) $b['contract_matches'];
    }
}

?>
