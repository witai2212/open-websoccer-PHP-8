<?php
// CM23 | 2026-09-01 | Revision 1 | Task 1017 phase 2

class ComputerFormationTransferStrategyDataService {

    const DEFAULT_FORMATION = '4-0-4-0-2-0';
    const SQUAD_DEPTH_FACTOR = 1.70;
    const MAX_TRANSFER_LIST_PLAYERS = 3;
    const DEFAULT_MAX_ACTIVE_OFFERS = 3;
    const DEFAULT_MAX_OFFERS_PER_PLAYER = 3;

    private static $processedTeamIds = array();

    public static function prepareFormationDrivenTransfers(WebSoccer $websoccer, DbConnection $db) {
        self::$processedTeamIds = self::getComputerTeams($websoccer, $db);

        foreach (self::$processedTeamIds as $teamId) {
            $formation = self::getTeamFormation($websoccer, $db, $teamId);
            $targets = self::getFormationDepthTargets($formation);
            $squad = self::getTeamSquad($websoccer, $db, $teamId);

            self::manageFormationTransferList($websoccer, $db, $teamId, $squad, $targets);
            self::placeFormationNeedOffers($websoccer, $db, $teamId, $squad, $targets);
        }
    }

    public static function cleanupFormationDrivenTransfers(WebSoccer $websoccer, DbConnection $db) {
        foreach (self::$processedTeamIds as $teamId) {
            $formation = self::getTeamFormation($websoccer, $db, $teamId);
            $targets = self::getFormationDepthTargets($formation);
            $squad = self::getTeamSquad($websoccer, $db, $teamId);
            $counts = self::countPositions($squad);

            $query = "SELECT id, position
                      FROM ". $websoccer->getConfig('db_prefix') ."_spieler
                      WHERE verein_id = '". (int) $teamId ."'
                        AND status = '1'
                        AND transfermarkt = '1'";
            $result = $db->executeQuery($query);
            $removeIds = array();

            while ($player = $result->fetch_assoc()) {
                $position = isset($player['position']) ? $player['position'] : '';
                if (!isset($targets[$position]) || !isset($counts[$position])) {
                    continue;
                }

                if ($counts[$position] <= $targets[$position]) {
                    $removeIds[] = (int) $player['id'];
                } else {
                    $counts[$position]--;
                }
            }
            $result->free();

            if (count($removeIds)) {
                $db->executeQuery(
                    "UPDATE ". $websoccer->getConfig('db_prefix') ."_spieler
                     SET transfermarkt = '0', transfer_start = '0', transfer_ende = '0'
                     WHERE id IN (". implode(',', $removeIds) .")"
                );
            }
        }
    }

    private static function getComputerTeams(WebSoccer $websoccer, DbConnection $db) {
        $limit = self::getOptionalConfigInt($websoccer, 'computer_transfers_teams_per_run', 100);
        $query = "SELECT id
                  FROM ". $websoccer->getConfig('db_prefix') ."_verein
                  WHERE (user_id IS NULL OR user_id <= 0)
                    AND status = '1'
                    AND nationalteam = '0'
                  ORDER BY RAND()
                  LIMIT ". max(1, (int) $limit);
        $result = $db->executeQuery($query);
        $teamIds = array();

        while ($team = $result->fetch_assoc()) {
            $teamIds[] = (int) $team['id'];
        }
        $result->free();

        return $teamIds;
    }

    private static function getTeamFormation(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $query = "SELECT formation
                  FROM ". $websoccer->getConfig('db_prefix') ."_verein
                  WHERE id = '". (int) $teamId ."'
                  LIMIT 1";
        $result = $db->executeQuery($query);
        $team = $result->fetch_assoc();
        $result->free();

        if (!isset($team['formation']) || !strlen(trim($team['formation']))) {
            return self::DEFAULT_FORMATION;
        }

        return trim($team['formation']);
    }

    private static function getFormationDepthTargets($formation) {
        $parts = self::parseFormation($formation);
        $defenders = $parts[0];
        $midfielders = $parts[1] + $parts[2] + $parts[3];
        $strikers = $parts[4] + $parts[5];

        return array(
            'Torwart' => 2,
            'Abwehr' => max($defenders, (int) ceil($defenders * self::SQUAD_DEPTH_FACTOR)),
            'Mittelfeld' => max($midfielders, (int) ceil($midfielders * self::SQUAD_DEPTH_FACTOR)),
            'Sturm' => max($strikers, (int) ceil($strikers * self::SQUAD_DEPTH_FACTOR))
        );
    }

    private static function parseFormation($formation) {
        $parts = explode('-', (string) $formation);
        if (count($parts) === 5) {
            $parts[] = 0;
        }

        if (count($parts) !== 6) {
            return self::parseFormation(self::DEFAULT_FORMATION);
        }

        $sum = 0;
        foreach ($parts as $index => $value) {
            if (!is_numeric($value)) {
                return self::parseFormation(self::DEFAULT_FORMATION);
            }
            $parts[$index] = max(0, (int) $value);
            $sum += $parts[$index];
        }

        if ($sum !== 10) {
            return self::parseFormation(self::DEFAULT_FORMATION);
        }

        return $parts;
    }

    private static function getTeamSquad(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $query = "SELECT id, position, w_technik, w_staerke, w_kondition, w_frische,
                         transfermarkt, transfer_blocked_until, lending_owner_id
                  FROM ". $websoccer->getConfig('db_prefix') ."_spieler
                  WHERE verein_id = '". (int) $teamId ."'
                    AND status = '1'";
        $result = $db->executeQuery($query);
        $squad = array();

        while ($player = $result->fetch_assoc()) {
            $squad[] = $player;
        }
        $result->free();

        return $squad;
    }

    private static function countPositions($squad) {
        $counts = array(
            'Torwart' => 0,
            'Abwehr' => 0,
            'Mittelfeld' => 0,
            'Sturm' => 0
        );

        foreach ($squad as $player) {
            if (isset($counts[$player['position']])) {
                $counts[$player['position']]++;
            }
        }

        return $counts;
    }

    private static function manageFormationTransferList(WebSoccer $websoccer, DbConnection $db, $teamId, $squad, $targets) {
        $targetTotal = array_sum($targets);
        if (count($squad) <= $targetTotal) {
            return;
        }

        $counts = self::countPositions($squad);
        $currentListed = 0;
        foreach ($squad as $player) {
            if ((int) $player['transfermarkt'] === 1) {
                $currentListed++;
                if (isset($counts[$player['position']]) && $counts[$player['position']] > 0) {
                    $counts[$player['position']]--;
                }
            }
        }
        if ($currentListed >= self::MAX_TRANSFER_LIST_PLAYERS) {
            return;
        }

        $candidates = array();
        foreach ($squad as $player) {
            $position = isset($player['position']) ? $player['position'] : '';
            if (!isset($targets[$position]) || !isset($counts[$position])) {
                continue;
            }
            if ($counts[$position] <= $targets[$position]) {
                continue;
            }
            if ((int) $player['transfermarkt'] === 1) {
                continue;
            }
            if (!empty($player['lending_owner_id'])) {
                continue;
            }
            if ((int) $player['transfer_blocked_until'] > $websoccer->getNowAsTimestamp()) {
                continue;
            }
            if (class_exists('PlayerPrecontractDataService')) {
                if (PlayerPrecontractDataService::getOpenOfferCount($websoccer, $db, (int) $player['id']) > 0) {
                    continue;
                }
                if (PlayerPrecontractDataService::hasAcceptedAgreement($websoccer, $db, (int) $player['id'])) {
                    continue;
                }
            }

            $player['cpu_strength'] = self::calculateSimpleStrength($player);
            $candidates[] = $player;
        }

        usort($candidates, array('ComputerFormationTransferStrategyDataService', 'sortWeakestFirst'));

        $remainingSquadSize = count($squad) - $currentListed;
        foreach ($candidates as $player) {
            if ($currentListed >= self::MAX_TRANSFER_LIST_PLAYERS || $remainingSquadSize <= $targetTotal) {
                break;
            }

            $position = $player['position'];
            if ($counts[$position] <= $targets[$position]) {
                continue;
            }

            self::listPlayerForTransfer($websoccer, $db, (int) $player['id']);
            $counts[$position]--;
            $remainingSquadSize--;
            $currentListed++;
        }
    }

    public static function sortWeakestFirst($a, $b) {
        $strengthA = isset($a['cpu_strength']) ? (float) $a['cpu_strength'] : 0;
        $strengthB = isset($b['cpu_strength']) ? (float) $b['cpu_strength'] : 0;
        if ($strengthA == $strengthB) {
            return 0;
        }
        return ($strengthA < $strengthB) ? -1 : 1;
    }

    private static function listPlayerForTransfer(WebSoccer $websoccer, DbConnection $db, $playerId) {
        $durationDays = max(1, (int) $websoccer->getConfig('transfermarket_duration_days'));
        $start = $websoccer->getNowAsTimestamp();
        $end = $start + ($durationDays * 24 * 60 * 60);

        $db->executeQuery(
            "UPDATE ". $websoccer->getConfig('db_prefix') ."_spieler
             SET transfermarkt = '1', transfer_start = '". (int) $start ."', transfer_ende = '". (int) $end ."'
             WHERE id = '". (int) $playerId ."'"
        );
    }

    private static function placeFormationNeedOffers(WebSoccer $websoccer, DbConnection $db, $teamId, $squad, $targets) {
        $counts = self::countPositions($squad);
        $needs = array();
        foreach ($targets as $position => $target) {
            $current = isset($counts[$position]) ? (int) $counts[$position] : 0;
            if ($current < $target) {
                $needs[$position] = $target - $current;
            }
        }
        if (!count($needs)) {
            return;
        }

        arsort($needs);
        $maxOffersPerTeam = max(1, self::getOptionalConfigInt($websoccer, 'computer_transfers_max_active_offers_per_team', self::DEFAULT_MAX_ACTIVE_OFFERS));
        $maxOffersPerPlayer = max(1, self::getOptionalConfigInt($websoccer, 'computer_transfers_max_offers_per_player', self::DEFAULT_MAX_OFFERS_PER_PLAYER));
        $currentOffers = self::getTeamOfferCount($websoccer, $db, $teamId);
        if ($currentOffers >= $maxOffersPerTeam) {
            return;
        }

        $budget = self::getTeamBudget($websoccer, $db, $teamId);
        $teamStrength = self::calculateAverageStrength($squad);
        $positionSql = array();
        foreach (array_keys($needs) as $position) {
            $positionSql[] = "'". str_replace("'", "''", $position) ."'";
        }

        $query = "SELECT P.*, V.user_id AS seller_user_id
                  FROM ". $websoccer->getConfig('db_prefix') ."_spieler AS P
                  INNER JOIN ". $websoccer->getConfig('db_prefix') ."_verein AS V ON V.id = P.verein_id
                  WHERE P.status = '1'
                    AND P.transfermarkt = '1'
                    AND P.verein_id <> '". (int) $teamId ."'
                    AND P.transfer_blocked_until <= '". (int) $websoccer->getNowAsTimestamp() ."'
                    AND P.position IN (". implode(',', $positionSql) .")
                  ORDER BY RAND()
                  LIMIT 120";
        $result = $db->executeQuery($query);
        $candidates = array();
        while ($player = $result->fetch_assoc()) {
            $candidates[] = $player;
        }
        $result->free();

        usort($candidates, function($a, $b) use ($needs) {
            $needA = isset($needs[$a['position']]) ? (int) $needs[$a['position']] : 0;
            $needB = isset($needs[$b['position']]) ? (int) $needs[$b['position']] : 0;
            if ($needA == $needB) {
                return 0;
            }
            return ($needA > $needB) ? -1 : 1;
        });

        foreach ($candidates as $player) {
            if ($currentOffers >= $maxOffersPerTeam) {
                break;
            }
            $position = $player['position'];
            if (!isset($needs[$position]) || $needs[$position] <= 0) {
                continue;
            }
            if (self::hasTeamOffer($websoccer, $db, $teamId, $player['id'])) {
                continue;
            }
            if (self::getPlayerOfferCount($websoccer, $db, $player['id']) >= $maxOffersPerPlayer) {
                continue;
            }

            $playerStrength = self::calculateSimpleStrength($player);
            if ($teamStrength > 0 && ($playerStrength < $teamStrength * 0.75 || $playerStrength > $teamStrength * 1.30)) {
                continue;
            }

            $bid = self::calculateBid($player);
            if ($bid <= 0 || $bid > $budget) {
                continue;
            }

            self::insertOffer($websoccer, $db, $teamId, $player, $bid);
            $budget -= $bid;
            $currentOffers++;
            $needs[$position]--;
        }
    }

    private static function calculateBid($player) {
        $marketValue = isset($player['marktwert']) ? (float) $player['marktwert'] : 0;
        $minimumBid = isset($player['transfer_mindestgebot']) ? (float) $player['transfer_mindestgebot'] : 0;
        if ($marketValue <= 0) {
            return max(0, $minimumBid);
        }

        $min = max($minimumBid, $marketValue * 0.70);
        $max = $marketValue * 1.15;
        if ($min > $max) {
            return 0;
        }

        $minStep = (int) ceil($min / 100);
        $maxStep = (int) floor($max / 100);
        if ($maxStep < $minStep) {
            return (float) (round($min / 100) * 100);
        }

        return (float) (rand($minStep, $maxStep) * 100);
    }

    private static function insertOffer(WebSoccer $websoccer, DbConnection $db, $teamId, $player, $bid) {
        $salary = isset($player['vertrag_gehalt']) ? (float) $player['vertrag_gehalt'] : 0;
        $goal = isset($player['vertrag_torpraemie']) ? (float) $player['vertrag_torpraemie'] : 0;
        $salary += $salary * (rand(-10, 10) / 100);
        $goal += $goal * (rand(-10, 10) / 100);
        $now = $websoccer->getNowAsTimestamp();

        $db->executeQuery(
            "INSERT INTO ". $websoccer->getConfig('db_prefix') ."_transfer_angebot
             (spieler_id, verein_id, user_id, abloese, handgeld, vertrag_spiele, datum, vertrag_gehalt, vertrag_torpraemie)
             VALUES ('". (int) $player['id'] ."', '". (int) $teamId ."', NULL, '". (float) $bid ."', '0', '60', '". (int) $now ."', '". (float) $salary ."', '". (float) $goal ."')"
        );

        if (!empty($player['seller_user_id']) && class_exists('TransferMessagesDataService')) {
            TransferMessagesDataService::createOfferReceived(
                $websoccer,
                $db,
                (int) $player['seller_user_id'],
                (int) $player['id'],
                (int) $teamId,
                (int) $player['verein_id'],
                (int) $bid,
                array(
                    'hand_money' => 0,
                    'contract_matches' => 60,
                    'contract_salary' => (int) $salary,
                    'contract_goal_bonus' => (int) $goal
                )
            );
        }
    }

    private static function getTeamOfferCount(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $query = "SELECT COUNT(*) AS offers
                  FROM ". $websoccer->getConfig('db_prefix') ."_transfer_angebot
                  WHERE verein_id = '". (int) $teamId ."'
                    AND (user_id IS NULL OR user_id <= 0)";
        $result = $db->executeQuery($query);
        $row = $result->fetch_assoc();
        $result->free();
        return isset($row['offers']) ? (int) $row['offers'] : 0;
    }

    private static function getPlayerOfferCount(WebSoccer $websoccer, DbConnection $db, $playerId) {
        $query = "SELECT COUNT(*) AS offers
                  FROM ". $websoccer->getConfig('db_prefix') ."_transfer_angebot
                  WHERE spieler_id = '". (int) $playerId ."'
                    AND (user_id IS NULL OR user_id <= 0)";
        $result = $db->executeQuery($query);
        $row = $result->fetch_assoc();
        $result->free();
        return isset($row['offers']) ? (int) $row['offers'] : 0;
    }

    private static function hasTeamOffer(WebSoccer $websoccer, DbConnection $db, $teamId, $playerId) {
        $query = "SELECT id
                  FROM ". $websoccer->getConfig('db_prefix') ."_transfer_angebot
                  WHERE verein_id = '". (int) $teamId ."'
                    AND spieler_id = '". (int) $playerId ."'
                    AND (user_id IS NULL OR user_id <= 0)
                  LIMIT 1";
        $result = $db->executeQuery($query);
        $row = $result->fetch_assoc();
        $result->free();
        return isset($row['id']);
    }

    private static function getTeamBudget(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $query = "SELECT finanz_budget
                  FROM ". $websoccer->getConfig('db_prefix') ."_verein
                  WHERE id = '". (int) $teamId ."'
                  LIMIT 1";
        $result = $db->executeQuery($query);
        $team = $result->fetch_assoc();
        $result->free();
        return isset($team['finanz_budget']) ? ((float) $team['finanz_budget'] * 100) : 0;
    }

    private static function calculateAverageStrength($squad) {
        if (!count($squad)) {
            return 0;
        }
        $sum = 0;
        foreach ($squad as $player) {
            $sum += self::calculateSimpleStrength($player);
        }
        return $sum / count($squad);
    }

    private static function calculateSimpleStrength($player) {
        return (
            (float) $player['w_technik']
            + (float) $player['w_staerke']
            + (float) $player['w_kondition']
            + (float) $player['w_frische']
        ) / 4;
    }

    private static function getOptionalConfigInt(WebSoccer $websoccer, $name, $default) {
        try {
            $value = $websoccer->getConfig($name);
            if ($value === NULL || $value === '') {
                return (int) $default;
            }
            return (int) $value;
        } catch (Exception $e) {
            return (int) $default;
        }
    }
}

?>
