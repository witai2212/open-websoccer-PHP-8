<?php
// CM23 | 2026-09-07 | Revision 2 | Task 1017 formation-driven CPU transfer market

class ComputerFormationTransferStrategyDataService {

    const DEFAULT_FORMATION = '4-0-4-0-2-0';
    const SQUAD_DEPTH_PER_STARTER = 2;
    const MAX_TRANSFER_LIST_PLAYERS = 3;
    const MIN_ACTIVE_SQUAD_SIZE = 20;
    const DEFAULT_MARKET_REFRESH_CHANCE = 15;
    const DEFAULT_MAX_ACTIVE_OFFERS = 3;
    const DEFAULT_MAX_OFFERS_PER_PLAYER = 3;
    const DEFAULT_MAX_PLAYERS_ON_TRANSFERMARKET = 800;

    private static $processedTeamIds = array();

    public static function prepareFormationDrivenTransfers(WebSoccer $websoccer, DbConnection $db) {
        self::$processedTeamIds = self::getComputerTeams($websoccer, $db);

        $marketLimit = max(
            1,
            self::getOptionalConfigInt(
                $websoccer,
                'transfermarket_max_players_on_tl',
                self::DEFAULT_MAX_PLAYERS_ON_TRANSFERMARKET
            )
        );
        $playersOnMarket = self::getTransferMarketPlayerCount($websoccer, $db);

        foreach (self::$processedTeamIds as $teamId) {
            $formation = self::getTeamFormation($websoccer, $db, $teamId);
            $startingTargets = self::getFormationStartingTargets($formation);
            $depthTargets = self::getFormationDepthTargets($formation);
            $squad = self::getTeamSquad($websoccer, $db, $teamId);

            $availableMarketSlots = max(0, $marketLimit - $playersOnMarket);
            if ($availableMarketSlots > 0) {
                $newListings = self::manageFormationTransferList(
                    $websoccer,
                    $db,
                    $teamId,
                    $squad,
                    $startingTargets,
                    $depthTargets,
                    $availableMarketSlots
                );
                $playersOnMarket += $newListings;
            }

            // Reload after transfer-list changes. Players already listed are treated as
            // planned departures when formation-specific replacement needs are calculated.
            $squad = self::getTeamSquad($websoccer, $db, $teamId);
            self::placeFormationNeedOffers($websoccer, $db, $teamId, $squad, $depthTargets);
        }
    }

    public static function cleanupFormationDrivenTransfers(WebSoccer $websoccer, DbConnection $db) {
        foreach (self::$processedTeamIds as $teamId) {
            $formation = self::getTeamFormation($websoccer, $db, $teamId);
            $startingTargets = self::getFormationStartingTargets($formation);
            $squad = self::getTeamSquad($websoccer, $db, $teamId);
            $unlistedAnalysis = self::analyseSquadForFormation($squad, $startingTargets, true);
            $availableCounts = $unlistedAnalysis['counts'];
            $removeIds = array();

            foreach ($squad as $player) {
                if ((int) $player['transfermarkt'] !== 1) {
                    continue;
                }

                $role = self::findBestDeficitRoleForPlayer($player, $startingTargets, $availableCounts);
                if (!strlen($role)) {
                    continue;
                }

                // A listed player is taken off the market only when he is needed to keep
                // the formation's starting XI positionally viable. Backup depth may be sold.
                if (
                    isset($startingTargets[$role])
                    && isset($availableCounts[$role])
                    && $availableCounts[$role] < $startingTargets[$role]
                ) {
                    $removeIds[] = (int) $player['id'];
                    $availableCounts[$role]++;
                }
            }

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

    private static function getFormationStartingTargets($formation) {
        $parts = self::parseFormation($formation);
        $targets = self::getEmptyDetailedPositionCounts();

        $targets['T'] = 1;

        // Same positional distribution as FormationDataService::getFormationProposalForTeamId().
        $setupDefense = $parts[0];
        if ($setupDefense < 4) {
            $targets['IV'] = $setupDefense;
        } else {
            $targets['LV'] = 1;
            $targets['RV'] = 1;
            $targets['IV'] = $setupDefense - 2;
        }

        $targets['DM'] = $parts[1];

        $setupMidfield = $parts[2];
        if ($setupMidfield === 1) {
            $targets['ZM'] = 1;
        } elseif ($setupMidfield === 2) {
            $targets['LM'] = 1;
            $targets['RM'] = 1;
        } elseif ($setupMidfield === 3) {
            $targets['LM'] = 1;
            $targets['ZM'] = 1;
            $targets['RM'] = 1;
        } elseif ($setupMidfield >= 4) {
            $targets['LM'] = 1;
            $targets['ZM'] = $setupMidfield - 2;
            $targets['RM'] = 1;
        }

        $targets['OM'] = $parts[3];
        $targets['MS'] = $parts[4];

        if ($parts[5] === 2) {
            $targets['LS'] = 1;
            $targets['RS'] = 1;
        }

        return $targets;
    }

    private static function getFormationDepthTargets($formation) {
        $startingTargets = self::getFormationStartingTargets($formation);
        $depthTargets = self::getEmptyDetailedPositionCounts();

        foreach ($startingTargets as $position => $startingCount) {
            $depthTargets[$position] = (int) $startingCount * self::SQUAD_DEPTH_PER_STARTER;
        }

        return $depthTargets;
    }

    private static function parseFormation($formation) {
        $parts = explode('-', trim((string) $formation));

        // Support the common short notation stored or entered as e.g. 4-4-2 / 3-4-3.
        if (count($parts) === 3) {
            $parts = array($parts[0], 0, $parts[1], 0, $parts[2], 0);
        } elseif (count($parts) === 5) {
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

        // FormationDataService supports outside forwards as a left/right pair.
        if ($parts[5] !== 0 && $parts[5] !== 2) {
            return self::parseFormation(self::DEFAULT_FORMATION);
        }

        return $parts;
    }

    private static function getTeamSquad(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $query = "SELECT id, position, position_main, position_second,
                         w_technik, w_staerke, w_kondition, w_frische,
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

    private static function getEmptyDetailedPositionCounts() {
        return array(
            'T' => 0,
            'LV' => 0,
            'IV' => 0,
            'RV' => 0,
            'LM' => 0,
            'DM' => 0,
            'ZM' => 0,
            'OM' => 0,
            'RM' => 0,
            'LS' => 0,
            'MS' => 0,
            'RS' => 0
        );
    }

    private static function getGenericPositions($genericPosition) {
        if ($genericPosition === 'Torwart') {
            return array('T');
        }
        if ($genericPosition === 'Abwehr') {
            return array('LV', 'IV', 'RV');
        }
        if ($genericPosition === 'Mittelfeld') {
            return array('RM', 'ZM', 'LM', 'DM', 'OM');
        }
        if ($genericPosition === 'Sturm') {
            return array('LS', 'MS', 'RS');
        }
        return array();
    }

    private static function getPositionArea($position) {
        if ($position === 'T') {
            return 'Torwart';
        }
        if (in_array($position, array('LV', 'IV', 'RV'), true)) {
            return 'Abwehr';
        }
        if (in_array($position, array('LM', 'DM', 'ZM', 'OM', 'RM'), true)) {
            return 'Mittelfeld';
        }
        if (in_array($position, array('LS', 'MS', 'RS'), true)) {
            return 'Sturm';
        }
        return '';
    }

    private static function analyseSquadForFormation($squad, $targets, $excludeTransferListed = false) {
        $counts = self::getEmptyDetailedPositionCounts();
        $roles = array();
        $unassigned = array();

        // First reserve players on their actual main position. This prevents all-rounders
        // or secondary positions from hiding a shortage where a true specialist exists.
        foreach ($squad as $index => $player) {
            if ($excludeTransferListed && (int) $player['transfermarkt'] === 1) {
                continue;
            }

            $main = isset($player['position_main']) ? trim($player['position_main']) : '';
            if (
                strlen($main)
                && isset($counts[$main])
                && isset($targets[$main])
                && $targets[$main] > 0
                && $counts[$main] < $targets[$main]
            ) {
                $counts[$main]++;
                $roles[(int) $player['id']] = $main;
            } else {
                $unassigned[$index] = $player;
            }
        }

        // Then use explicit secondary positions to cover remaining formation depth.
        foreach ($unassigned as $index => $player) {
            $second = isset($player['position_second']) ? trim($player['position_second']) : '';
            if (
                strlen($second)
                && isset($counts[$second])
                && isset($targets[$second])
                && $targets[$second] > 0
                && $counts[$second] < $targets[$second]
            ) {
                $counts[$second]++;
                $roles[(int) $player['id']] = $second;
                unset($unassigned[$index]);
            }
        }

        // Players without a detailed main position are allocated like FormationDataService.
        foreach ($unassigned as $index => $player) {
            $main = isset($player['position_main']) ? trim($player['position_main']) : '';
            if (strlen($main)) {
                continue;
            }

            foreach (self::getGenericPositions(isset($player['position']) ? $player['position'] : '') as $position) {
                if (
                    isset($targets[$position])
                    && $targets[$position] > 0
                    && $counts[$position] < $targets[$position]
                ) {
                    $counts[$position]++;
                    $roles[(int) $player['id']] = $position;
                    unset($unassigned[$index]);
                    break;
                }
            }
        }

        // Remaining players are genuine positional surplus for this formation. Count them
        // on their preferred usable role so sales can reduce that surplus deliberately.
        foreach ($unassigned as $player) {
            $role = self::getPreferredRoleForPlayer($player);
            if (!strlen($role)) {
                continue;
            }
            $counts[$role]++;
            $roles[(int) $player['id']] = $role;
        }

        return array(
            'counts' => $counts,
            'roles' => $roles
        );
    }

    private static function getPreferredRoleForPlayer($player) {
        $validPositions = self::getEmptyDetailedPositionCounts();
        $main = isset($player['position_main']) ? trim($player['position_main']) : '';
        if (isset($validPositions[$main])) {
            return $main;
        }

        $second = isset($player['position_second']) ? trim($player['position_second']) : '';
        if (isset($validPositions[$second])) {
            return $second;
        }

        $generic = self::getGenericPositions(isset($player['position']) ? $player['position'] : '');
        return count($generic) ? $generic[0] : '';
    }

    private static function findBestDeficitRoleForPlayer($player, $targets, $counts) {
        $candidates = array();
        $main = isset($player['position_main']) ? trim($player['position_main']) : '';
        $second = isset($player['position_second']) ? trim($player['position_second']) : '';

        if (strlen($main)) {
            $candidates[] = $main;
        }
        if (strlen($second) && !in_array($second, $candidates, true)) {
            $candidates[] = $second;
        }
        if (!strlen($main)) {
            foreach (self::getGenericPositions(isset($player['position']) ? $player['position'] : '') as $genericPosition) {
                if (!in_array($genericPosition, $candidates, true)) {
                    $candidates[] = $genericPosition;
                }
            }
        }

        foreach ($candidates as $position) {
            if (
                isset($targets[$position])
                && isset($counts[$position])
                && $targets[$position] > 0
                && $counts[$position] < $targets[$position]
            ) {
                return $position;
            }
        }

        return '';
    }

    private static function manageFormationTransferList(
        WebSoccer $websoccer,
        DbConnection $db,
        $teamId,
        $squad,
        $startingTargets,
        $depthTargets,
        $availableMarketSlots
    ) {
        if ($availableMarketSlots <= 0) {
            return 0;
        }

        $currentListed = 0;
        foreach ($squad as $player) {
            if ((int) $player['transfermarkt'] === 1) {
                $currentListed++;
            }
        }
        if ($currentListed >= self::MAX_TRANSFER_LIST_PLAYERS) {
            return 0;
        }

        $analysis = self::analyseSquadForFormation($squad, $depthTargets, true);
        $counts = $analysis['counts'];
        $roles = $analysis['roles'];
        $futureSquadSize = count($squad) - $currentListed;
        $targetTotal = array_sum($depthTargets);
        $maxNewListings = min(
            self::MAX_TRANSFER_LIST_PLAYERS - $currentListed,
            $availableMarketSlots,
            max(0, $futureSquadSize - self::MIN_ACTIVE_SQUAD_SIZE)
        );
        if ($maxNewListings <= 0) {
            return 0;
        }

        $candidates = array();
        foreach ($squad as $player) {
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

            $playerId = (int) $player['id'];
            $role = isset($roles[$playerId]) ? $roles[$playerId] : self::getPreferredRoleForPlayer($player);
            if (!strlen($role) || !isset($counts[$role])) {
                continue;
            }

            // Normal formation rebalancing: players above the two-deep target are expendable.
            if (isset($depthTargets[$role]) && $counts[$role] > $depthTargets[$role]) {
                $player['cpu_formation_role'] = $role;
                $player['cpu_sale_priority'] = 2;
                $player['cpu_strength'] = self::calculateSimpleStrength($player);
                $candidates[] = $player;
            }
        }

        // If the squad is already correctly balanced and two-deep, occasionally create one
        // controlled replacement cycle. The outgoing player must leave enough starters behind.
        if (!count($candidates) && $futureSquadSize >= $targetTotal && $currentListed === 0) {
            $refreshChance = max(
                0,
                min(
                    100,
                    self::getOptionalConfigInt(
                        $websoccer,
                        'computer_transfers_formation_refresh_chance',
                        self::DEFAULT_MARKET_REFRESH_CHANCE
                    )
                )
            );

            if ($refreshChance > 0 && rand(1, 100) <= $refreshChance) {
                foreach ($squad as $player) {
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

                    $playerId = (int) $player['id'];
                    $role = isset($roles[$playerId]) ? $roles[$playerId] : self::getPreferredRoleForPlayer($player);
                    if (
                        !strlen($role)
                        || !isset($counts[$role])
                        || !isset($startingTargets[$role])
                        || $counts[$role] <= $startingTargets[$role]
                    ) {
                        continue;
                    }

                    $player['cpu_formation_role'] = $role;
                    $player['cpu_sale_priority'] = 1;
                    $player['cpu_strength'] = self::calculateSimpleStrength($player);
                    $candidates[] = $player;
                }
                $maxNewListings = min($maxNewListings, 1);
            }
        }

        if (!count($candidates)) {
            return 0;
        }

        usort($candidates, array('ComputerFormationTransferStrategyDataService', 'sortSaleCandidates'));

        $newListings = 0;
        foreach ($candidates as $player) {
            if ($newListings >= $maxNewListings) {
                break;
            }

            $role = $player['cpu_formation_role'];
            $isSurplus = isset($depthTargets[$role]) && $counts[$role] > $depthTargets[$role];
            $isRefresh = (
                !$isSurplus
                && isset($startingTargets[$role])
                && $counts[$role] > $startingTargets[$role]
                && $futureSquadSize >= $targetTotal
            );

            if (!$isSurplus && !$isRefresh) {
                continue;
            }

            self::listPlayerForTransfer($websoccer, $db, (int) $player['id']);
            $counts[$role]--;
            $futureSquadSize--;
            $newListings++;

            // A refresh cycle deliberately lists only one player. Positional surplus may
            // create more listings, but never below the active-squad safety threshold.
            if ($isRefresh) {
                break;
            }
        }

        return $newListings;
    }

    public static function sortSaleCandidates($a, $b) {
        $priorityA = isset($a['cpu_sale_priority']) ? (int) $a['cpu_sale_priority'] : 0;
        $priorityB = isset($b['cpu_sale_priority']) ? (int) $b['cpu_sale_priority'] : 0;
        if ($priorityA !== $priorityB) {
            return ($priorityA > $priorityB) ? -1 : 1;
        }

        return self::sortWeakestFirst($a, $b);
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
        $analysis = self::analyseSquadForFormation($squad, $targets, true);
        $counts = $analysis['counts'];
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
        $broadPositions = array();
        foreach (array_keys($needs) as $position) {
            $area = self::getPositionArea($position);
            if (strlen($area) && !in_array($area, $broadPositions, true)) {
                $broadPositions[] = $area;
            }
        }
        if (!count($broadPositions)) {
            return;
        }

        $positionSql = array();
        foreach ($broadPositions as $position) {
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
                  LIMIT 200";
        $result = $db->executeQuery($query);
        $candidates = array();
        while ($player = $result->fetch_assoc()) {
            $match = self::getPlayerNeedMatch($player, $needs);
            if ($match === null) {
                continue;
            }
            $player['cpu_formation_need_position'] = $match['position'];
            $player['cpu_formation_fit'] = $match['fit'];
            $player['cpu_formation_need'] = $needs[$match['position']];
            $candidates[] = $player;
        }
        $result->free();

        usort($candidates, array('ComputerFormationTransferStrategyDataService', 'sortFormationOfferCandidates'));

        foreach ($candidates as $player) {
            if ($currentOffers >= $maxOffersPerTeam) {
                break;
            }

            $match = self::getPlayerNeedMatch($player, $needs);
            if ($match === null) {
                continue;
            }
            $neededPosition = $match['position'];
            if (!isset($needs[$neededPosition]) || $needs[$neededPosition] <= 0) {
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
            $needs[$neededPosition]--;
        }
    }

    private static function getPlayerNeedMatch($player, $needs) {
        if (!count($needs)) {
            return null;
        }

        $main = isset($player['position_main']) ? trim($player['position_main']) : '';
        if (strlen($main) && isset($needs[$main]) && $needs[$main] > 0) {
            return array('position' => $main, 'fit' => 0);
        }

        $second = isset($player['position_second']) ? trim($player['position_second']) : '';
        if (strlen($second) && isset($needs[$second]) && $needs[$second] > 0) {
            return array('position' => $second, 'fit' => 1);
        }

        if (!strlen($main)) {
            foreach (self::getGenericPositions(isset($player['position']) ? $player['position'] : '') as $position) {
                if (isset($needs[$position]) && $needs[$position] > 0) {
                    return array('position' => $position, 'fit' => 2);
                }
            }
        }

        return null;
    }

    public static function sortFormationOfferCandidates($a, $b) {
        $fitA = isset($a['cpu_formation_fit']) ? (int) $a['cpu_formation_fit'] : 99;
        $fitB = isset($b['cpu_formation_fit']) ? (int) $b['cpu_formation_fit'] : 99;
        if ($fitA !== $fitB) {
            return ($fitA < $fitB) ? -1 : 1;
        }

        $needA = isset($a['cpu_formation_need']) ? (int) $a['cpu_formation_need'] : 0;
        $needB = isset($b['cpu_formation_need']) ? (int) $b['cpu_formation_need'] : 0;
        if ($needA !== $needB) {
            return ($needA > $needB) ? -1 : 1;
        }

        return 0;
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

    private static function getTransferMarketPlayerCount(WebSoccer $websoccer, DbConnection $db) {
        $query = "SELECT COUNT(*) AS players
                  FROM ". $websoccer->getConfig('db_prefix') ."_spieler
                  WHERE status = '1'
                    AND transfermarkt = '1'";
        $result = $db->executeQuery($query);
        $row = $result->fetch_assoc();
        $result->free();

        return isset($row['players']) ? (int) $row['players'] : 0;
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
