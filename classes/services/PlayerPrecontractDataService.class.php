<?php
/** Vorverträge für ablösefreie Wechsel zum globalen Saisonwechsel. */
class PlayerPrecontractDataService {
    const STATUS_OPEN = 'open';
    const STATUS_ACCEPTED = 'accepted';

    public static function isEligible(WebSoccer $websoccer, DbConnection $db, $playerId) {
        $limit = (int) $websoccer->getConfig('contract_max_number_of_remaining_matches');
        if ($limit < 1) {
            return false;
        }

        $result = $db->querySelect(
            'verein_id, vertrag_spiele, status',
            $websoccer->getConfig('db_prefix') . '_spieler',
            'id = %d',
            (int) $playerId,
            1
        );
        $player = $result->fetch_array();
        $result->free();

        return $player
            && $player['status'] == '1'
            && (int) $player['verein_id'] > 0
            && (int) $player['vertrag_spiele'] < $limit;
    }

    public static function hasAcceptedAgreement(WebSoccer $websoccer, DbConnection $db, $playerId) {
        $result = $db->querySelect(
            'id',
            $websoccer->getConfig('db_prefix') . '_player_precontract',
            "player_id = %d AND status = 'accepted'",
            (int) $playerId,
            1
        );
        $row = $result->fetch_array();
        $result->free();

        return (bool) $row;
    }

    public static function hasOpenExternalOffers(WebSoccer $websoccer, DbConnection $db, $playerId, $currentTeamId) {
        return self::hasExternalOfferWithStatuses(
            $websoccer,
            $db,
            $playerId,
            $currentTeamId,
            array(self::STATUS_OPEN)
        );
    }

    public static function hasExternalOfferOrAgreement(WebSoccer $websoccer, DbConnection $db, $playerId, $currentTeamId) {
        return self::hasExternalOfferWithStatuses(
            $websoccer,
            $db,
            $playerId,
            $currentTeamId,
            array(self::STATUS_OPEN, self::STATUS_ACCEPTED)
        );
    }

    private static function hasExternalOfferWithStatuses(WebSoccer $websoccer, DbConnection $db, $playerId, $currentTeamId, $statuses) {
        $quotedStatuses = array();
        foreach ($statuses as $status) {
            $quotedStatuses[] = "'" . $status . "'";
        }

        $prefix = $websoccer->getConfig('db_prefix');
        $where = 'player_id = %d AND destination_team_id <> %d AND status IN (' . implode(',', $quotedStatuses) . ')';
        $result = $db->querySelect(
            'id',
            $prefix . '_player_precontract',
            $where,
            array((int) $playerId, (int) $currentTeamId),
            1
        );
        $row = $result->fetch_array();
        $result->free();

        return (bool) $row;
    }

    public static function getAcceptedByPlayer(WebSoccer $websoccer, DbConnection $db, $playerId) {
        $prefix = $websoccer->getConfig('db_prefix');
        $sql = "SELECT A.*, T.name AS destination_team_name
                FROM {$prefix}_player_precontract A
                INNER JOIN {$prefix}_verein T ON T.id = A.destination_team_id
                WHERE A.player_id = " . (int) $playerId . "
                  AND A.status = 'accepted'
                LIMIT 1";
        $result = $db->executeQuery($sql);
        $row = $result->fetch_array();
        $result->free();

        return $row ?: array();
    }

    public static function getOfferByPlayerAndTeam(WebSoccer $websoccer, DbConnection $db, $playerId, $teamId) {
        if ((int) $playerId < 1 || (int) $teamId < 1) {
            return array();
        }

        $prefix = $websoccer->getConfig('db_prefix');
        $sql = "SELECT A.*, T.name AS destination_team_name
                FROM {$prefix}_player_precontract A
                INNER JOIN {$prefix}_verein T ON T.id = A.destination_team_id
                WHERE A.player_id = " . (int) $playerId . "
                  AND A.destination_team_id = " . (int) $teamId . "
                  AND A.status IN ('open','accepted')
                ORDER BY A.id DESC
                LIMIT 1";
        $result = $db->executeQuery($sql);
        $row = $result->fetch_array();
        $result->free();

        return $row ?: array();
    }

    public static function getOpenOfferCount(WebSoccer $websoccer, DbConnection $db, $playerId) {
        $prefix = $websoccer->getConfig('db_prefix');
        $result = $db->querySelect(
            'COUNT(*) AS offer_count',
            $prefix . '_player_precontract',
            "player_id = %d AND status = 'open'",
            (int) $playerId,
            1
        );
        $row = $result->fetch_array();
        $result->free();

        return $row ? (int) $row['offer_count'] : 0;
    }

    public static function getOpenComputerOfferCount(WebSoccer $websoccer, DbConnection $db, $playerId) {
        $result = $db->querySelect(
            'COUNT(*) AS offer_count',
            $websoccer->getConfig('db_prefix') . '_player_precontract',
            "player_id = %d AND status = 'open' AND is_computer = '1' AND destination_team_id <> current_team_id",
            (int) $playerId,
            1
        );
        $row = $result->fetch_array();
        $result->free();
        return $row ? (int) $row['offer_count'] : 0;
    }

    public static function cancelOffersBecauseContractWasExtended(WebSoccer $websoccer, DbConnection $db, $playerId, $currentTeamId) {
        $prefix = $websoccer->getConfig('db_prefix');
        $sql = "SELECT A.* FROM {$prefix}_player_precontract A
                WHERE A.player_id = " . (int) $playerId . " AND A.status = 'open'";
        $result = $db->executeQuery($sql);
        $offers = array();
        while ($offer = $result->fetch_array()) {
            $offers[] = $offer;
        }
        $result->free();
        if (!count($offers)) {
            return 0;
        }

        $now = $websoccer->getNowAsTimestamp();
        foreach ($offers as $offer) {
            if ((int) $offer['destination_user_id'] > 0 && (int) $offer['destination_team_id'] !== (int) $currentTeamId) {
                TransferMessagesDataService::createPrecontractMessage(
                    $websoccer,
                    $db,
                    (int) $offer['destination_user_id'],
                    'contract_extended',
                    (int) $playerId,
                    (int) $currentTeamId,
                    (int) $offer['destination_team_id'],
                    self::offerDetails($offer)
                );
            }
        }
        $db->queryUpdate(
            array('status' => 'cancelled', 'decision_date' => $now, 'completed_date' => $now),
            $prefix . '_player_precontract',
            "player_id = %d AND status = 'open'",
            (int) $playerId
        );
        return count($offers);
    }

    private static function cancelIneligibleOpenOffers(WebSoccer $websoccer, DbConnection $db) {
        $prefix = $websoccer->getConfig('db_prefix');
        $limit = max(1, (int) $websoccer->getConfig('contract_max_number_of_remaining_matches'));
        $sql = "SELECT DISTINCT A.player_id, A.current_team_id
                FROM {$prefix}_player_precontract A
                INNER JOIN {$prefix}_spieler P ON P.id=A.player_id
                WHERE A.status='open' AND P.vertrag_spiele >= " . (int) $limit;
        $result = $db->executeQuery($sql);
        $players = array();
        while ($row = $result->fetch_array()) {
            $players[] = $row;
        }
        $result->free();
        foreach ($players as $row) {
            self::cancelOffersBecauseContractWasExtended($websoccer, $db, (int) $row['player_id'], (int) $row['current_team_id']);
        }
        return count($players);
    }

    public static function getIncoming(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $prefix = $websoccer->getConfig('db_prefix');
        $sql = "SELECT A.*, P.vorname AS firstname, P.nachname AS lastname,
                       P.kunstname AS pseudonym, P.position, P.position_main,
                       P.geburtstag, P.w_staerke AS strength, P.marktwert AS marketvalue,
                       T.name AS current_team_name
                FROM {$prefix}_player_precontract A
                INNER JOIN {$prefix}_spieler P ON P.id = A.player_id
                LEFT JOIN {$prefix}_verein T ON T.id = A.current_team_id
                WHERE A.destination_team_id = " . (int) $teamId . "
                  AND A.current_team_id <> A.destination_team_id
                  AND A.status = 'accepted'
                ORDER BY P.position, P.nachname";
        $result = $db->executeQuery($sql);
        $rows = array();
        while ($row = $result->fetch_array()) {
            $row['age'] = (int) date('Y') - (int) substr($row['geburtstag'], 0, 4);
            $rows[] = $row;
        }
        $result->free();

        return $rows;
    }

    public static function placeOffer(WebSoccer $websoccer, DbConnection $db, $playerId, $teamId, $userId, $salary, $goalBonus, $handMoney, $contractMatches, $isComputer = false) {
        if (!self::isEligible($websoccer, $db, $playerId)) {
            throw new Exception('Der Spieler kann noch nicht für die nächste Saison angesprochen werden.');
        }
        if (self::hasAcceptedAgreement($websoccer, $db, $playerId)) {
            throw new Exception('Der Spieler hat bereits einen Vertrag für die nächste Saison unterschrieben.');
        }

        $prefix = $websoccer->getConfig('db_prefix');
        $player = self::getPlayerContractData($websoccer, $db, $playerId);
        if (!$player || (int) $player['verein_id'] === (int) $teamId) {
            throw new Exception('Ein Verein kann dem eigenen Spieler keinen Vorvertrag anbieten.');
        }

        // CPU-Vereine dürfen nur die konfigurierte Anzahl aktiver externer
        // Vorverträge gleichzeitig besitzen. Ein vorhandenes Angebot darf
        // weiterhin aktualisiert werden, auch wenn das Limit bereits erreicht ist.
        if ($isComputer) {
            $existingOffer = self::getOfferByPlayerAndTeam($websoccer, $db, $playerId, $teamId);
            if (!$existingOffer
                    && self::getCurrentPreContractOffers($websoccer, $db, $teamId)
                        >= self::getMaxComputerPreContractOffers($websoccer)) {
                throw new Exception('Der CPU-Verein hat bereits die maximale Anzahl aktiver Vorverträge erreicht.');
            }
        }

        $contractMatches = self::normalizeContractMatches($websoccer, $contractMatches);
        $salary = max((int) $salary, (int) ceil((int) $player['vertrag_gehalt'] * 1.05));
        $goalBonus = max((int) $goalBonus, (int) $player['vertrag_torpraemie']);
        $handMoney = max(0, (int) $handMoney);

        $offerId = self::upsertOffer(
            $websoccer,
            $db,
            array(
                'player_id' => (int) $playerId,
                'current_team_id' => (int) $player['verein_id'],
                'destination_team_id' => (int) $teamId,
                'destination_user_id' => (int) $userId,
                'contract_salary' => $salary,
                'contract_goal_bonus' => $goalBonus,
                'hand_money' => $handMoney,
                'contract_matches' => $contractMatches,
                'is_computer' => $isComputer ? '1' : '0'
            )
        );

        self::handleCurrentClubReaction(
            $websoccer,
            $db,
            $player,
            (int) $teamId,
            array(
                'hand_money' => $handMoney,
                'contract_matches' => $contractMatches,
                'contract_salary' => $salary,
                'contract_goal_bonus' => $goalBonus
            )
        );

        return $offerId;
    }

    public static function placeRetentionOffer(WebSoccer $websoccer, DbConnection $db, $playerId, $teamId, $userId, $salary, $goalBonus, $contractMatches, $isComputer = false) {
        if (!self::isEligible($websoccer, $db, $playerId)) {
            throw new Exception('Der Spieler kann noch nicht für die nächste Saison angesprochen werden.');
        }
        if (self::hasAcceptedAgreement($websoccer, $db, $playerId)) {
            throw new Exception('Der Spieler hat bereits einen Vertrag für die nächste Saison unterschrieben.');
        }

        $player = self::getPlayerContractData($websoccer, $db, $playerId);
        if (!$player || (int) $player['verein_id'] !== (int) $teamId) {
            throw new Exception('Ein Gegenangebot kann nur der aktuelle Verein abgeben.');
        }

        return self::upsertOffer(
            $websoccer,
            $db,
            array(
                'player_id' => (int) $playerId,
                'current_team_id' => (int) $teamId,
                'destination_team_id' => (int) $teamId,
                'destination_user_id' => (int) $userId,
                'contract_salary' => max((int) $salary, (int) $player['vertrag_gehalt']),
                'contract_goal_bonus' => max((int) $goalBonus, (int) $player['vertrag_torpraemie']),
                'hand_money' => 0,
                'contract_matches' => self::normalizeContractMatches($websoccer, $contractMatches),
                'is_computer' => $isComputer ? '1' : '0'
            )
        );
    }

    public static function ensureComputerRetentionOffer(WebSoccer $websoccer, DbConnection $db, $playerId, $currentTeamId) {
        if (!self::isEligible($websoccer, $db, $playerId)
                || self::hasAcceptedAgreement($websoccer, $db, $playerId)
                || !self::hasOpenExternalOffers($websoccer, $db, $playerId, $currentTeamId)
                || self::isTeamManagedByHuman($websoccer, $db, $currentTeamId)) {
            return 0;
        }

        $player = self::getPlayerContractData($websoccer, $db, $playerId);
        if (!$player || (int) $player['verein_id'] !== (int) $currentTeamId) {
            return 0;
        }

        $prefix = $websoccer->getConfig('db_prefix');
        $sql = "SELECT MAX(contract_salary) AS max_salary,
                       MAX(contract_goal_bonus) AS max_goal_bonus,
                       MAX(contract_matches) AS max_contract_matches
                FROM {$prefix}_player_precontract
                WHERE player_id = " . (int) $playerId . "
                  AND destination_team_id <> " . (int) $currentTeamId . "
                  AND status = 'open'";
        $result = $db->executeQuery($sql);
        $external = $result->fetch_array();
        $result->free();

        $salary = max(
            (int) ceil((int) $player['vertrag_gehalt'] * 1.10),
            (int) ceil((int) $player['marktwert'] / 900),
            (int) ceil((int) $external['max_salary'] * (1 + mt_rand(0, 8) / 100))
        );
        $goalBonus = max(
            (int) ceil((int) $player['vertrag_torpraemie'] * 1.20),
            (int) $external['max_goal_bonus']
        );
        $contractMatches = max(
            (int) $external['max_contract_matches'],
            self::getMaxContractMatches($websoccer)
        );

        $existing = self::getOfferByPlayerAndTeam($websoccer, $db, $playerId, $currentTeamId);
        if ($existing) {
            $salary = max($salary, (int) $existing['contract_salary']);
            $goalBonus = max($goalBonus, (int) $existing['contract_goal_bonus']);
            $contractMatches = max($contractMatches, (int) $existing['contract_matches']);
        }

        return self::placeRetentionOffer(
            $websoccer,
            $db,
            $playerId,
            $currentTeamId,
            0,
            $salary,
            $goalBonus,
            $contractMatches,
            true
        );
    }

    private static function upsertOffer(WebSoccer $websoccer, DbConnection $db, $offer) {
        $prefix = $websoccer->getConfig('db_prefix');
        $result = $db->querySelect(
            'id',
            $prefix . '_player_precontract',
            "player_id = %d AND destination_team_id = %d AND status = 'open'",
            array((int) $offer['player_id'], (int) $offer['destination_team_id']),
            1
        );
        $existing = $result->fetch_array();
        $result->free();

        $columns = array(
            'current_team_id' => (int) $offer['current_team_id'],
            'destination_user_id' => (int) $offer['destination_user_id'],
            'contract_salary' => (int) $offer['contract_salary'],
            'contract_goal_bonus' => (int) $offer['contract_goal_bonus'],
            'hand_money' => (int) $offer['hand_money'],
            'contract_matches' => (int) $offer['contract_matches'],
            'is_computer' => $offer['is_computer'] === '1' ? '1' : '0'
        );

        if ($existing) {
            $db->queryUpdate($columns, $prefix . '_player_precontract', 'id = %d', (int) $existing['id']);
            return (int) $existing['id'];
        }

        $waitMin = max(1, (int) $websoccer->getConfig('precontract_decision_matches_min'));
        $waitMax = max($waitMin, (int) $websoccer->getConfig('precontract_decision_matches_max'));
        $columns['player_id'] = (int) $offer['player_id'];
        $columns['destination_team_id'] = (int) $offer['destination_team_id'];
        $columns['created_date'] = $websoccer->getNowAsTimestamp();
        $columns['decision_after_matches'] = mt_rand($waitMin, $waitMax);
        $columns['waited_matches'] = 0;
        $columns['decision_date'] = 0;
        $columns['completed_date'] = 0;
        $columns['status'] = self::STATUS_OPEN;

        // Wegen des vorhandenen Unique-Keys pro Spieler, Verein und Status wird
        // ein alter abgeschlossener Datensatz für eine spätere Saison wiederverwendet.
        $result = $db->querySelect(
            'id',
            $prefix . '_player_precontract',
            "player_id = %d AND destination_team_id = %d AND status IN ('rejected','cancelled','completed')",
            array((int) $offer['player_id'], (int) $offer['destination_team_id']),
            1
        );
        $reusable = $result->fetch_array();
        $result->free();
        if ($reusable) {
            $db->queryUpdate($columns, $prefix . '_player_precontract', 'id = %d', (int) $reusable['id']);
            return (int) $reusable['id'];
        }

        $db->queryInsert($columns, $prefix . '_player_precontract');
        return $db->getLastInsertedId();
    }

    private static function handleCurrentClubReaction(WebSoccer $websoccer, DbConnection $db, $player, $offeringTeamId, $details) {
        $currentTeamId = (int) $player['verein_id'];
        $team = self::getTeamManagerData($websoccer, $db, $currentTeamId);

        if ($team && (int) $team['user_id'] > 0 && $team['interimmanager'] != '1') {
            TransferMessagesDataService::createPrecontractMessage(
                $websoccer,
                $db,
                (int) $team['user_id'],
                'received',
                (int) $player['id'],
                $currentTeamId,
                (int) $offeringTeamId,
                $details
            );
            return;
        }

        self::ensureComputerRetentionOffer($websoccer, $db, (int) $player['id'], $currentTeamId);
    }

    public static function processOpenOffers(WebSoccer $websoccer, DbConnection $db) {
        self::cancelIneligibleOpenOffers($websoccer, $db);
        $prefix = $websoccer->getConfig('db_prefix');
        $db->executeQuery("UPDATE {$prefix}_player_precontract SET waited_matches = waited_matches + 1 WHERE status = 'open'");
        $sql = "SELECT player_id
                FROM {$prefix}_player_precontract
                WHERE status = 'open'
                GROUP BY player_id
                HAVING MAX(waited_matches >= decision_after_matches) = 1";
        $result = $db->executeQuery($sql);
        $playerIds = array();
        while ($row = $result->fetch_array()) {
            $playerIds[] = (int) $row['player_id'];
        }
        $result->free();

        foreach ($playerIds as $playerId) {
            self::decidePlayer($websoccer, $db, $playerId);
        }

        return count($playerIds);
    }

    private static function decidePlayer(WebSoccer $websoccer, DbConnection $db, $playerId) {
        if (self::hasAcceptedAgreement($websoccer, $db, $playerId)) {
            return;
        }

        $prefix = $websoccer->getConfig('db_prefix');
        $sql = "SELECT A.*, T.strength AS club_strength, T.finanz_budget, L.division
                FROM {$prefix}_player_precontract A
                INNER JOIN {$prefix}_verein T ON T.id = A.destination_team_id
                LEFT JOIN {$prefix}_liga L ON L.id = T.liga_id
                WHERE A.player_id = " . (int) $playerId . "
                  AND A.status = 'open'";
        $result = $db->executeQuery($sql);
        $offers = array();
        while ($offer = $result->fetch_array()) {
            $league = max(1, 8 - (int) $offer['division']);
            $offer['score'] = (int) $offer['hand_money']
                + ((int) $offer['contract_salary'] * (int) $offer['contract_matches'])
                + ((int) $offer['contract_goal_bonus'] * 8)
                + ((int) $offer['club_strength'] * 25000)
                + ($league * 100000);
            $offers[] = $offer;
        }
        $result->free();

        if (!$offers) {
            return;
        }

        usort($offers, function($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        $best = $offers[0];
        $now = $websoccer->getNowAsTimestamp();
        $isRetention = (int) $best['current_team_id'] === (int) $best['destination_team_id'];

        if ($isRetention) {
            self::applyRetentionAgreement($websoccer, $db, $best);
            $db->queryUpdate(
                array('status' => 'completed', 'decision_date' => $now, 'completed_date' => $now),
                $prefix . '_player_precontract',
                'id = %d',
                (int) $best['id']
            );
        } else {
            $db->queryUpdate(
                array('status' => self::STATUS_ACCEPTED, 'decision_date' => $now),
                $prefix . '_player_precontract',
                'id = %d',
                (int) $best['id']
            );
        }

        $db->executeQuery(
            "UPDATE {$prefix}_player_precontract
             SET status = 'rejected', decision_date = " . (int) $now . "
             WHERE player_id = " . (int) $playerId . "
               AND status = 'open'"
        );

        $currentClubWasNotified = false;
        foreach ($offers as $offer) {
            if ((int) $offer['destination_user_id'] < 1) {
                continue;
            }

            $offerIsRetention = (int) $offer['current_team_id'] === (int) $offer['destination_team_id'];
            $offerWon = (int) $offer['id'] === (int) $best['id'];
            if ($offerIsRetention) {
                $event = $offerWon ? 'retained' : 'retention_rejected';
                $currentClubWasNotified = true;
            } else {
                $event = $offerWon ? 'accepted' : 'rejected';
            }

            TransferMessagesDataService::createPrecontractMessage(
                $websoccer,
                $db,
                (int) $offer['destination_user_id'],
                $event,
                $playerId,
                (int) $offer['current_team_id'],
                (int) $offer['destination_team_id'],
                self::offerDetails($offer)
            );
        }

        if (!$isRetention && !$currentClubWasNotified) {
            $currentTeam = self::getTeamManagerData($websoccer, $db, (int) $best['current_team_id']);
            if ($currentTeam && (int) $currentTeam['user_id'] > 0 && $currentTeam['interimmanager'] != '1') {
                TransferMessagesDataService::createPrecontractMessage(
                    $websoccer,
                    $db,
                    (int) $currentTeam['user_id'],
                    'leaving',
                    $playerId,
                    (int) $best['current_team_id'],
                    (int) $best['destination_team_id'],
                    self::offerDetails($best)
                );
            }
        }
    }

    public static function executeAcceptedTransfers(WebSoccer $websoccer, DbConnection $db) {
        $prefix = $websoccer->getConfig('db_prefix');
        $result = $db->executeQuery("SELECT * FROM {$prefix}_player_precontract WHERE status = 'accepted'");
        $count = 0;

        while ($agreement = $result->fetch_array()) {
            if ((int) $agreement['current_team_id'] === (int) $agreement['destination_team_id']) {
                self::applyRetentionAgreement($websoccer, $db, $agreement);
                $db->queryUpdate(
                    array('status' => 'completed', 'completed_date' => $websoccer->getNowAsTimestamp()),
                    $prefix . '_player_precontract',
                    'id = %d',
                    (int) $agreement['id']
                );
                $count++;
                continue;
            }

            $destination = TeamsDataService::getTeamSummaryById($websoccer, $db, (int) $agreement['destination_team_id']);
            $current = TeamsDataService::getTeamSummaryById($websoccer, $db, (int) $agreement['current_team_id']);
            if (!$destination) {
                continue;
            }

            BankAccountDataService::debitAmount(
                $websoccer,
                $db,
                (int) $agreement['destination_team_id'],
                (int) $agreement['hand_money'],
                'Handgeld für ablösefreien Wechsel',
                'Spieler'
            );

            $db->queryUpdate(
                array(
                    'verein_id' => (int) $agreement['destination_team_id'],
                    'vertrag_gehalt' => (int) $agreement['contract_salary'],
                    'vertrag_torpraemie' => (int) $agreement['contract_goal_bonus'],
                    'vertrag_spiele' => self::normalizeContractMatches($websoccer, $agreement['contract_matches']),
                    'transfermarkt' => '0',
                    'transfer_start' => 0,
                    'transfer_ende' => 0,
                    'transfer_mindestgebot' => 0,
                    'lending_fee' => 0
                ),
                $prefix . '_spieler',
                'id = %d',
                (int) $agreement['player_id']
            );

            $db->queryInsert(
                array(
                    'spieler_id' => (int) $agreement['player_id'],
                    'seller_user_id' => !empty($current['user_id']) ? (int) $current['user_id'] : 0,
                    'seller_club_id' => (int) $agreement['current_team_id'],
                    'buyer_user_id' => (int) $agreement['destination_user_id'],
                    'buyer_club_id' => (int) $agreement['destination_team_id'],
                    'datum' => $websoccer->getNowAsTimestamp(),
                    'directtransfer_amount' => 0
                ),
                $prefix . '_transfer'
            );

            if (class_exists('PlayerTalentChangeDataService')) {
                PlayerTalentChangeDataService::applyTransferChance(
                    $websoccer,
                    $db,
                    (int) $agreement['player_id'],
                    (int) $agreement['destination_team_id'],
                    (int) $agreement['destination_user_id']
                );
            }

            $details = self::offerDetails($agreement);
            if (!empty($current['user_id'])) {
                TransferMessagesDataService::createPrecontractMessage(
                    $websoccer,
                    $db,
                    (int) $current['user_id'],
                    'completed',
                    (int) $agreement['player_id'],
                    (int) $agreement['current_team_id'],
                    (int) $agreement['destination_team_id'],
                    $details
                );
            }
            if ((int) $agreement['destination_user_id'] > 0) {
                TransferMessagesDataService::createPrecontractMessage(
                    $websoccer,
                    $db,
                    (int) $agreement['destination_user_id'],
                    'completed',
                    (int) $agreement['player_id'],
                    (int) $agreement['current_team_id'],
                    (int) $agreement['destination_team_id'],
                    $details
                );
            }

            TransferMessagesDataService::createMajorTransferNewsForPlayer(
                $websoccer,
                $db,
                (int) $agreement['player_id'],
                (int) $agreement['current_team_id'],
                (int) $agreement['destination_team_id'],
                0
            );

            $db->queryUpdate(
                array('status' => 'completed', 'completed_date' => $websoccer->getNowAsTimestamp()),
                $prefix . '_player_precontract',
                'id = %d',
                (int) $agreement['id']
            );
            $count++;
        }

        $result->free();
        return $count;
    }

    private static function applyRetentionAgreement(WebSoccer $websoccer, DbConnection $db, $agreement) {
        $db->queryUpdate(
            array(
                'vertrag_gehalt' => (int) $agreement['contract_salary'],
                'vertrag_torpraemie' => (int) $agreement['contract_goal_bonus'],
                'vertrag_spiele' => self::normalizeContractMatches($websoccer, $agreement['contract_matches']),
                'transfermarkt' => '0',
                'transfer_start' => 0,
                'transfer_ende' => 0,
                'transfer_mindestgebot' => 0
            ),
            $websoccer->getConfig('db_prefix') . '_spieler',
            'id = %d',
            (int) $agreement['player_id']
        );
    }

    public static function getCurrentPreContractOffers(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $prefix = $websoccer->getConfig('db_prefix');
        $result = $db->querySelect(
            'COUNT(*) AS offers',
            $prefix . '_player_precontract',
            "destination_team_id = %d AND current_team_id <> destination_team_id AND status IN ('open','accepted')",
            (int) $teamId,
            1
        );
        $row = $result->fetch_array();
        $result->free();

        return $row ? (int) $row['offers'] : 0;
    }

    public static function createComputerOffers(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $prefix = $websoccer->getConfig('db_prefix');
        $limit = (int) $websoccer->getConfig('contract_max_number_of_remaining_matches');
        if ($limit < 1) {
            return 0;
        }

        $maxActiveOffers = self::getMaxComputerPreContractOffers($websoccer);
        if (self::getCurrentPreContractOffers($websoccer, $db, $teamId) >= $maxActiveOffers) {
            return 0;
        }

        $sql = "SELECT P.*
                FROM {$prefix}_spieler P
                WHERE P.status = '1'
                  AND P.verein_id > 0
                  AND P.verein_id <> " . (int) $teamId . "
                  AND P.vertrag_spiele < " . (int) $limit . "
                  AND NOT EXISTS (
                      SELECT 1
                      FROM {$prefix}_player_precontract A
                      WHERE A.player_id = P.id
                        AND A.destination_team_id = " . (int) $teamId . "
                        AND A.status IN ('open','accepted')
                  )
                ORDER BY RAND()
                LIMIT 8";
        $result = $db->executeQuery($sql);
        $made = 0;

        $maxComputerOffersPerPlayer = max(1, (int) $websoccer->getConfig('computer_transfers_max_offers_per_player'));
        if ($maxComputerOffersPerPlayer < 1) {
            $maxComputerOffersPerPlayer = 3;
        }

        while ($player = $result->fetch_array()) {
            if ($made >= 1
                    || self::getCurrentPreContractOffers($websoccer, $db, $teamId) >= $maxActiveOffers) {
                break;
            }
            if (self::getOpenComputerOfferCount($websoccer, $db, (int) $player['id']) >= $maxComputerOffersPerPlayer) {
                continue;
            }

            $salary = max(
                (int) ceil((int) $player['vertrag_gehalt'] * (1.05 + mt_rand(0, 15) / 100)),
                (int) ceil((int) $player['marktwert'] / 800)
            );
            $handMoney = min(
                (int) $player['marktwert'],
                max(
                    $salary * 3,
                    (int) round((int) $player['marktwert'] * (0.03 + mt_rand(0, 5) / 100))
                )
            );

            try {
                self::placeOffer(
                    $websoccer,
                    $db,
                    (int) $player['id'],
                    (int) $teamId,
                    0,
                    $salary,
                    (int) $player['vertrag_torpraemie'],
                    $handMoney,
                    self::getMaxContractMatches($websoccer),
                    true
                );
                $made++;
            } catch (Exception $e) {
                // Kein passender Spieler oder Angebot nicht mehr zulässig.
            }
        }

        $result->free();
        return $made;
    }

    private static function normalizeContractMatches(WebSoccer $websoccer, $contractMatches) {
        return max(20, min(self::getMaxContractMatches($websoccer), (int) $contractMatches));
    }

    private static function getMaxComputerPreContractOffers(WebSoccer $websoccer) {
        $max = (int) $websoccer->getConfig('precontract_max_cpu_offer');
        return $max > 0 ? $max : 3;
    }

    private static function getMaxContractMatches(WebSoccer $websoccer) {
        $max = (int) $websoccer->getConfig('max_number_of_contract_matches');
        return $max > 0 ? $max : 60;
    }

    private static function getPlayerContractData(WebSoccer $websoccer, DbConnection $db, $playerId) {
        $result = $db->querySelect(
            'id, verein_id, vertrag_gehalt, vertrag_torpraemie, marktwert',
            $websoccer->getConfig('db_prefix') . '_spieler',
            'id = %d',
            (int) $playerId,
            1
        );
        $player = $result->fetch_array();
        $result->free();

        return $player ?: array();
    }

    private static function getTeamManagerData(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $result = $db->querySelect(
            'user_id, interimmanager',
            $websoccer->getConfig('db_prefix') . '_verein',
            'id = %d',
            (int) $teamId,
            1
        );
        $team = $result->fetch_array();
        $result->free();

        return $team ?: array();
    }

    private static function isTeamManagedByHuman(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $team = self::getTeamManagerData($websoccer, $db, $teamId);
        return $team && (int) $team['user_id'] > 0 && $team['interimmanager'] != '1';
    }

    private static function offerDetails($offer) {
        return array(
            'hand_money' => (int) $offer['hand_money'],
            'contract_matches' => (int) $offer['contract_matches'],
            'contract_salary' => (int) $offer['contract_salary'],
            'contract_goal_bonus' => (int) $offer['contract_goal_bonus']
        );
    }

    private static function playerName(WebSoccer $websoccer, DbConnection $db, $id) {
        $result = $db->querySelect(
            'vorname,nachname,kunstname',
            $websoccer->getConfig('db_prefix') . '_spieler',
            'id = %d',
            (int) $id,
            1
        );
        $player = $result->fetch_array();
        $result->free();

        return $player['kunstname'] ?: trim($player['vorname'] . ' ' . $player['nachname']);
    }
}
?>
