<?php

/**
 * Handles the offer phase for youth players listed by computer-controlled clubs.
 */
class YouthTransferOfferDataService {

    const STATUS_OPEN = 'open';
    const STATUS_ACCEPTED = 'accepted';
    const STATUS_REJECTED = 'rejected';

    public static function prepareComputerRun(WebSoccer $websoccer, DbConnection $db) {
        self::removeInvalidOpenOffers($websoccer, $db);
        self::removeUndatedLegacyComputerListings($websoccer, $db);
        self::enforceComputerListingLimit($websoccer, $db);
        self::processDueOffers($websoccer, $db);
        self::expireComputerListings($websoccer, $db);
    }

    public static function finalizeComputerRun(WebSoccer $websoccer, DbConnection $db) {
        self::removeInvalidOpenOffers($websoccer, $db);
        self::processDueOffers($websoccer, $db);
        self::expireComputerListings($websoccer, $db);
    }

    public static function createComputerOffer(WebSoccer $websoccer, DbConnection $db, $buyerTeamId, $player, $offerAmount = 0) {
        $buyerTeamId = (int) $buyerTeamId;
        $playerId = isset($player['id']) ? (int) $player['id'] : 0;
        $sellerTeamId = isset($player['team_id']) ? (int) $player['team_id'] : 0;
        $requestedFee = isset($player['transfer_fee']) ? (int) $player['transfer_fee'] : 0;
        $offerAmount = (int) $offerAmount;
        if ($offerAmount <= 0) {
            $offerAmount = $requestedFee;
        }

        if ($playerId < 1 || $sellerTeamId < 1 || $buyerTeamId < 1 || $buyerTeamId === $sellerTeamId || $requestedFee < 1 || $offerAmount < 1) {
            return false;
        }

        $maxOffers = self::getConfigInt($websoccer, 'computer_youth_max_offers_per_player', 3);
        if (self::countOpenComputerOffersForPlayer($websoccer, $db, $playerId) >= $maxOffers) {
            return false;
        }
        if (self::hasOpenOffer($websoccer, $db, $playerId, $buyerTeamId)) {
            return false;
        }

        if (class_exists('ComputerBudgetProtectionDataService')) {
            ComputerBudgetProtectionDataService::ensureTeamFloor($websoccer, $db, $buyerTeamId);
        }
        $buyer = TeamsDataService::getTeamSummaryById($websoccer, $db, $buyerTeamId);
        $maximumOffer = max(100000, self::getConfigInt($websoccer, 'computer_youth_max_buy_fee', 5000000));
        if (!$buyer || !isset($buyer['team_budget']) || (int) $buyer['team_budget'] < $offerAmount || $offerAmount > $maximumOffer) {
            return false;
        }

        $db->queryInsert(array(
            'player_id' => $playerId,
            'seller_team_id' => $sellerTeamId,
            'buyer_team_id' => $buyerTeamId,
            'offered_fee' => $offerAmount,
            'created_date' => $websoccer->getNowAsTimestamp(),
            'decided_date' => 0,
            'created_by_computer' => '1',
            'status' => self::STATUS_OPEN
        ), $websoccer->getConfig('db_prefix') . '_youth_transfer_offer');

        return true;
    }

    public static function countOpenComputerOffersForPlayer(WebSoccer $websoccer, DbConnection $db, $playerId) {
        $result = $db->querySelect(
            'COUNT(*) AS hits',
            $websoccer->getConfig('db_prefix') . '_youth_transfer_offer',
            "player_id = %d AND status = 'open' AND created_by_computer = '1'",
            (int) $playerId
        );
        $row = $result->fetch_assoc();
        $result->free();
        return isset($row['hits']) ? (int) $row['hits'] : 0;
    }

    public static function closeOpenOffersForPlayer(WebSoccer $websoccer, DbConnection $db, $playerId, $acceptedOfferId = 0) {
        $playerId = (int) $playerId;
        $acceptedOfferId = (int) $acceptedOfferId;
        $now = $websoccer->getNowAsTimestamp();
        $table = $websoccer->getConfig('db_prefix') . '_youth_transfer_offer';
        if ($acceptedOfferId > 0) {
            $db->queryUpdate(
                array('status' => self::STATUS_ACCEPTED, 'decided_date' => $now),
                $table,
                "id = %d AND player_id = %d AND status = 'open'",
                array($acceptedOfferId, $playerId)
            );
            $db->queryUpdate(
                array('status' => self::STATUS_REJECTED, 'decided_date' => $now),
                $table,
                "player_id = %d AND id <> %d AND status = 'open'",
                array($playerId, $acceptedOfferId)
            );
        } else {
            $db->queryUpdate(
                array('status' => self::STATUS_REJECTED, 'decided_date' => $now),
                $table,
                "player_id = %d AND status = 'open'",
                $playerId
            );
        }
    }

    private static function processDueOffers(WebSoccer $websoccer, DbConnection $db) {
        $now = $websoccer->getNowAsTimestamp();
        $hours = max(1, self::getConfigInt($websoccer, 'computer_youth_offer_decision_hours', 24));
        $decisionThreshold = $now - ($hours * 3600);
        $maxOffers = max(1, self::getConfigInt($websoccer, 'computer_youth_max_offers_per_player', 3));
        $prefix = $websoccer->getConfig('db_prefix');

        $query = "SELECT O.player_id, COUNT(*) AS offer_count, MIN(O.created_date) AS oldest_offer,
                         MAX(P.transfer_ende) AS transfer_end
                  FROM ". $prefix ."_youth_transfer_offer AS O
                  INNER JOIN ". $prefix ."_youthplayer AS P ON P.id = O.player_id
                  WHERE O.status = 'open' AND O.created_by_computer = '1' AND P.transfer_fee > 0
                  GROUP BY O.player_id
                  HAVING COUNT(*) >= ". $maxOffers ."
                     OR MIN(O.created_date) <= ". (int) $decisionThreshold ."
                     OR (MAX(P.transfer_ende) > 0 AND MAX(P.transfer_ende) <= ". (int) $now .")";
        $result = $db->executeQuery($query);
        $playerIds = array();
        while ($row = $result->fetch_assoc()) {
            $playerIds[] = (int) $row['player_id'];
        }
        $result->free();

        foreach ($playerIds as $playerId) {
            self::decideOffersForPlayer($websoccer, $db, $playerId);
        }
    }

    private static function decideOffersForPlayer(WebSoccer $websoccer, DbConnection $db, $playerId) {
        $prefix = $websoccer->getConfig('db_prefix');
        $query = "SELECT O.id AS offer_id,
                         O.player_id,
                         O.seller_team_id,
                         O.buyer_team_id,
                         O.offered_fee,
                         O.created_date AS offer_created_date,
                         P.*
                  FROM ". $prefix ."_youth_transfer_offer AS O
                  INNER JOIN ". $prefix ."_youthplayer AS P ON P.id = O.player_id
                  WHERE O.player_id = '". (int) $playerId ."'
                    AND O.status = 'open'
                    AND O.created_by_computer = '1'
                    AND P.transfer_fee > 0
                  ORDER BY O.offered_fee DESC, O.created_date ASC, O.id ASC";
        $result = $db->executeQuery($query);
        $offers = array();
        while ($row = $result->fetch_assoc()) {
            $offers[] = $row;
        }
        $result->free();

        foreach ($offers as $offer) {
            $player = $offer;
            $player['id'] = (int) $offer['player_id'];
            $player['team_id'] = (int) $offer['seller_team_id'];
            $player['transfer_fee'] = (int) $offer['offered_fee'];
            if (ComputerYouthTeamsDataService::completeComputerYouthTransfer($websoccer, $db, (int) $offer['buyer_team_id'], $player, (int) $offer['offered_fee'])) {
                self::closeOpenOffersForPlayer($websoccer, $db, $playerId, (int) $offer['offer_id']);
                return true;
            }
        }

        self::closeOpenOffersForPlayer($websoccer, $db, $playerId, 0);
        return false;
    }

    private static function expireComputerListings(WebSoccer $websoccer, DbConnection $db) {
        $now = $websoccer->getNowAsTimestamp();
        $prefix = $websoccer->getConfig('db_prefix');
        $query = "SELECT P.id
                  FROM ". $prefix ."_youthplayer AS P
                  INNER JOIN ". $prefix ."_verein AS V ON V.id = P.team_id
                  WHERE P.transfer_fee > 0
                    AND P.transfer_listed_by_cpu = '1'
                    AND P.transfer_ende > 0
                    AND P.transfer_ende <= '". (int) $now ."'
                    AND (V.user_id IS NULL OR V.user_id <= 0)";
        $result = $db->executeQuery($query);
        $playerIds = array();
        while ($row = $result->fetch_assoc()) {
            $playerIds[] = (int) $row['id'];
        }
        $result->free();

        foreach ($playerIds as $playerId) {
            if (self::countOpenComputerOffersForPlayer($websoccer, $db, $playerId) > 0) {
                self::decideOffersForPlayer($websoccer, $db, $playerId);
            }
            $db->queryUpdate(
                array('transfer_fee' => 0, 'transfer_start' => 0, 'transfer_ende' => 0, 'transfer_listed_by_cpu' => '0'),
                $prefix . '_youthplayer',
                'id = %d AND transfer_listed_by_cpu = \'1\'',
                $playerId
            );
        }
    }

    private static function enforceComputerListingLimit(WebSoccer $websoccer, DbConnection $db) {
        $maxListed = max(1, self::getConfigInt($websoccer, 'computer_youth_max_players_on_transfer_list', 3));
        $prefix = $websoccer->getConfig('db_prefix');
        $teamQuery = "SELECT P.team_id
                      FROM ". $prefix ."_youthplayer AS P
                      INNER JOIN ". $prefix ."_verein AS V ON V.id = P.team_id
                      WHERE P.transfer_fee > 0 AND P.transfer_listed_by_cpu = '1'
                        AND (V.user_id IS NULL OR V.user_id <= 0)
                      GROUP BY P.team_id
                      HAVING COUNT(*) > ". $maxListed;
        $teams = $db->executeQuery($teamQuery);
        while ($team = $teams->fetch_assoc()) {
            $query = "SELECT id FROM ". $prefix ."_youthplayer
                      WHERE team_id='". (int) $team['team_id'] ."'
                        AND transfer_fee > 0 AND transfer_listed_by_cpu='1'
                      ORDER BY transfer_start DESC, strength DESC, id DESC";
            $result = $db->executeQuery($query);
            $position = 0;
            $remove = array();
            while ($row = $result->fetch_assoc()) {
                $position++;
                if ($position > $maxListed) {
                    $remove[] = (int) $row['id'];
                }
            }
            $result->free();
            if (count($remove)) {
                $db->executeQuery("UPDATE ". $prefix ."_youthplayer
                                   SET transfer_fee=0, transfer_start=0, transfer_ende=0, transfer_listed_by_cpu='0'
                                   WHERE id IN (". implode(',', $remove) .")");
                $db->executeQuery("DELETE FROM ". $prefix ."_youth_transfer_offer
                                   WHERE player_id IN (". implode(',', $remove) .") AND status='open'");
            }
        }
        $teams->free();

        $globalMaximum = max(1, self::getConfigInt($websoccer, 'computer_youth_global_max_players_on_transfer_list', 100));
        $globalQuery = "SELECT P.id FROM ". $prefix ."_youthplayer AS P
                        INNER JOIN ". $prefix ."_verein AS V ON V.id=P.team_id
                        WHERE P.transfer_fee > 0 AND P.transfer_listed_by_cpu='1'
                          AND (V.user_id IS NULL OR V.user_id <= 0)
                        ORDER BY P.transfer_start ASC, P.strength DESC, P.age ASC, P.id ASC";
        $globalResult = $db->executeQuery($globalQuery);
        $position = 0;
        $remove = array();
        while ($row = $globalResult->fetch_assoc()) {
            $position++;
            if ($position > $globalMaximum) {
                $remove[] = (int) $row['id'];
            }
        }
        $globalResult->free();
        if (count($remove)) {
            $db->executeQuery("UPDATE ". $prefix ."_youthplayer SET transfer_fee=0, transfer_start=0, transfer_ende=0, transfer_listed_by_cpu='0' WHERE id IN (". implode(',', $remove) .")");
            $db->executeQuery("DELETE FROM ". $prefix ."_youth_transfer_offer WHERE player_id IN (". implode(',', $remove) .") AND status='open'");
        }
    }

    private static function removeUndatedLegacyComputerListings(WebSoccer $websoccer, DbConnection $db) {
        $prefix = $websoccer->getConfig('db_prefix');
        $query = "SELECT P.id
                  FROM ". $prefix ."_youthplayer AS P
                  INNER JOIN ". $prefix ."_verein AS V ON V.id = P.team_id
                  WHERE P.transfer_fee > 0
                    AND P.transfer_start <= 0
                    AND (V.user_id IS NULL OR V.user_id <= 0)";
        $result = $db->executeQuery($query);
        $ids = array();
        while ($row = $result->fetch_assoc()) {
            $ids[] = (int) $row['id'];
        }
        $result->free();
        if (count($ids)) {
            $db->executeQuery("UPDATE ". $prefix ."_youthplayer
                               SET transfer_fee = 0, transfer_start = 0, transfer_ende = 0, transfer_listed_by_cpu = '0'
                               WHERE id IN (". implode(',', $ids) .")");
        }
    }

    private static function removeInvalidOpenOffers(WebSoccer $websoccer, DbConnection $db) {
        $prefix = $websoccer->getConfig('db_prefix');
        $query = "SELECT O.id
                  FROM ". $prefix ."_youth_transfer_offer AS O
                  LEFT JOIN ". $prefix ."_youthplayer AS P ON P.id = O.player_id
                  LEFT JOIN ". $prefix ."_verein AS B ON B.id = O.buyer_team_id
                  LEFT JOIN ". $prefix ."_verein AS S ON S.id = O.seller_team_id
                  WHERE O.status = 'open'
                    AND (P.id IS NULL OR B.id IS NULL OR S.id IS NULL OR P.transfer_fee <= 0
                         OR P.team_id <> O.seller_team_id OR O.buyer_team_id = O.seller_team_id)";
        $result = $db->executeQuery($query);
        $ids = array();
        while ($row = $result->fetch_assoc()) {
            $ids[] = (int) $row['id'];
        }
        $result->free();
        if (count($ids)) {
            $db->executeQuery("DELETE FROM ". $prefix ."_youth_transfer_offer WHERE id IN (". implode(',', $ids) .")");
        }

        // Remove duplicate open CPU offers by the same club for the same player, keeping the best/latest one.
        $dupQuery = "SELECT player_id, buyer_team_id
                     FROM ". $prefix ."_youth_transfer_offer
                     WHERE status = 'open' AND created_by_computer = '1'
                     GROUP BY player_id, buyer_team_id
                     HAVING COUNT(*) > 1";
        $dups = $db->executeQuery($dupQuery);
        while ($pair = $dups->fetch_assoc()) {
            $pairQuery = "SELECT id FROM ". $prefix ."_youth_transfer_offer
                          WHERE player_id='". (int) $pair['player_id'] ."'
                            AND buyer_team_id='". (int) $pair['buyer_team_id'] ."'
                            AND status='open' AND created_by_computer='1'
                          ORDER BY offered_fee DESC, created_date DESC, id DESC";
            $pairResult = $db->executeQuery($pairQuery);
            $keep = true;
            $delete = array();
            while ($row = $pairResult->fetch_assoc()) {
                if ($keep) {
                    $keep = false;
                } else {
                    $delete[] = (int) $row['id'];
                }
            }
            $pairResult->free();
            if (count($delete)) {
                $db->executeQuery("DELETE FROM ". $prefix ."_youth_transfer_offer WHERE id IN (". implode(',', $delete) .")");
            }
        }
        $dups->free();
    }

    private static function hasOpenOffer(WebSoccer $websoccer, DbConnection $db, $playerId, $buyerTeamId) {
        $result = $db->querySelect(
            'id',
            $websoccer->getConfig('db_prefix') . '_youth_transfer_offer',
            "player_id = %d AND buyer_team_id = %d AND status = 'open' AND created_by_computer = '1'",
            array((int) $playerId, (int) $buyerTeamId),
            1
        );
        $row = $result->fetch_assoc();
        $result->free();
        return isset($row['id']);
    }

    private static function getConfigInt(WebSoccer $websoccer, $key, $fallback) {
        try {
            $value = $websoccer->getConfig($key);
            return ($value === null || $value === '') ? (int) $fallback : (int) $value;
        } catch (Exception $e) {
            return (int) $fallback;
        }
    }
}

?>
