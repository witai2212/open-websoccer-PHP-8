<?php
/** Seltene, nachvollziehbare Talentänderungen bei Transfers und Saisonwechseln. */
class PlayerTalentChangeDataService {
    private static function ensureSchema(WebSoccer $websoccer, DbConnection $db) {
        static $done = array();
        $prefix = $websoccer->getConfig('db_prefix');
        if (isset($done[$prefix])) {
            return;
        }
        $done[$prefix] = true;
        $db->executeQuery("CREATE TABLE IF NOT EXISTS `{$prefix}_player_talent_change_log` (
            `id` int(10) NOT NULL AUTO_INCREMENT,
            `player_id` int(10) NOT NULL,
            `season_id` int(10) NOT NULL DEFAULT 0,
            `event_type` varchar(32) NOT NULL,
            `reference_key` varchar(96) NOT NULL,
            `old_talent` tinyint(2) NOT NULL,
            `new_talent` tinyint(2) NOT NULL,
            `change_amount` tinyint(3) NOT NULL,
            `created_date` int(11) NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_player_talent_reference` (`reference_key`),
            KEY `idx_player_talent_season` (`season_id`,`player_id`),
            KEY `idx_player_talent_player` (`player_id`,`created_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    }

    public static function applyTransferChance(WebSoccer $websoccer, DbConnection $db, $playerId, $buyerClubId, $buyerUserId = 0) {
        self::ensureSchema($websoccer, $db);
        $chance = (int) $websoccer->getConfig('player_talent_transfer_change_chance');
        if ($chance < 1) {
            $chance = 2;
        }
        if (mt_rand(1, 100) > $chance) {
            return 0;
        }

        $prefix = $websoccer->getConfig('db_prefix');
        $result = $db->querySelect('id, vorname, nachname, kunstname, w_talent', $prefix . '_spieler', 'id = %d', (int) $playerId, 1);
        $player = $result->fetch_array();
        $result->free();
        if (!$player) {
            return 0;
        }

        $transferResult = $db->querySelect('id', $prefix . '_transfer', 'spieler_id = %d AND buyer_club_id = %d ORDER BY id DESC', array((int) $playerId, (int) $buyerClubId), 1);
        $transfer = $transferResult->fetch_array();
        $transferResult->free();
        $reference = 'transfer:' . ((int) ($transfer ? $transfer['id'] : 0)) . ':player:' . (int) $playerId;
        if (self::referenceExists($websoccer, $db, $reference)) {
            return 0;
        }

        $oldTalent = max(1, min(6, (int) $player['w_talent']));
        $change = mt_rand(0, 1) === 1 ? 1 : -1;
        $newTalent = max(1, min(6, $oldTalent + $change));
        if ($newTalent === $oldTalent) {
            $change *= -1;
            $newTalent = max(1, min(6, $oldTalent + $change));
        }
        if ($newTalent === $oldTalent) {
            return 0;
        }

        self::persistChange($websoccer, $db, $playerId, 0, 'transfer', $reference, $oldTalent, $newTalent);
        if ((int) $buyerUserId > 0) {
            $playerName = strlen((string) $player['kunstname']) ? $player['kunstname'] : trim($player['vorname'] . ' ' . $player['nachname']);
            NotificationsDataService::createNotification(
                $websoccer,
                $db,
                (int) $buyerUserId,
                'player_talent_transfer_changed',
                array('player' => $playerName, 'old' => $oldTalent, 'new' => $newTalent, 'change' => ($change > 0 ? '+1' : '-1')),
                'player_talent',
                'player',
                'id=' . (int) $playerId,
                (int) $buyerClubId
            );
        }
        return $change;
    }

    public static function processSeasonEnd(WebSoccer $websoccer, DbConnection $db, $seasonId, $leagueId) {
        self::ensureSchema($websoccer, $db);
        $prefix = $websoccer->getConfig('db_prefix');
        $sql = "SELECT P.id, P.geburtstag, P.age, P.position, P.w_talent, P.sa_spiele, P.sa_tore, P.sa_assists, P.note_schnitt
                FROM {$prefix}_spieler P
                INNER JOIN {$prefix}_verein T ON T.id = P.verein_id
                WHERE T.liga_id = " . (int) $leagueId . " AND P.status = '1'";
        $result = $db->executeQuery($sql);
        $changed = 0;
        while ($player = $result->fetch_array()) {
            $reference = 'season:' . (int) $seasonId . ':player:' . (int) $player['id'];
            if (self::referenceExists($websoccer, $db, $reference)) {
                continue;
            }
            $age = !empty($player['geburtstag']) ? (int) date_diff(date_create($player['geburtstag']), date_create('today'))->y : (int) $player['age'];
            $change = self::rollSeasonChange($player, $age);
            if ($change === 0) {
                continue;
            }
            $oldTalent = max(1, min(6, (int) $player['w_talent']));
            $newTalent = max(1, min(6, $oldTalent + $change));
            if ($newTalent === $oldTalent) {
                continue;
            }
            self::persistChange($websoccer, $db, (int) $player['id'], (int) $seasonId, 'season_end', $reference, $oldTalent, $newTalent);
            $changed++;
        }
        $result->free();
        return $changed;
    }

    private static function rollSeasonChange($player, $age) {
        if ($age <= 21) {
            $goodPerformance = (int) $player['sa_spiele'] >= 15 && (
                ((float) $player['note_schnitt'] > 0 && (float) $player['note_schnitt'] <= 2.50)
                || ((int) $player['sa_tore'] + (int) $player['sa_assists']) >= 8
            );
            return $goodPerformance && mt_rand(1, 100) <= 5 ? 1 : 0;
        }
        if ($age <= 29) {
            return mt_rand(1, 100) <= 1 ? (mt_rand(0, 1) ? 1 : -1) : 0;
        }
        if ($age <= 32) {
            return mt_rand(1, 100) <= 3 ? -1 : 0;
        }
        if ($age <= 35) {
            return mt_rand(1, 100) <= 8 ? -1 : 0;
        }
        return mt_rand(1, 100) <= 15 ? -1 : 0;
    }

    private static function referenceExists(WebSoccer $websoccer, DbConnection $db, $reference) {
        $result = $db->querySelect('id', $websoccer->getConfig('db_prefix') . '_player_talent_change_log', "reference_key = '%s'", $reference, 1);
        $row = $result->fetch_array();
        $result->free();
        return (bool) $row;
    }

    private static function persistChange(WebSoccer $websoccer, DbConnection $db, $playerId, $seasonId, $eventType, $reference, $oldTalent, $newTalent) {
        $prefix = $websoccer->getConfig('db_prefix');
        $db->queryUpdate(array('w_talent' => (int) $newTalent), $prefix . '_spieler', 'id = %d', (int) $playerId);
        $db->queryInsert(array(
            'player_id' => (int) $playerId,
            'season_id' => (int) $seasonId,
            'event_type' => $eventType,
            'reference_key' => $reference,
            'old_talent' => (int) $oldTalent,
            'new_talent' => (int) $newTalent,
            'change_amount' => (int) $newTalent - (int) $oldTalent,
            'created_date' => $websoccer->getNowAsTimestamp()
        ), $prefix . '_player_talent_change_log');
    }
}
?>
