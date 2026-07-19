<?php
/******************************************************

  Player salary repair service for CM23 / OpenWebSoccer-Sim.

******************************************************/

class PlayerSalaryRepairService {

    const CORRUPT_SALARY_MIN = 100000;
    const REPAIRED_SALARY_MIN = 500;
    const REPAIRED_SALARY_MAX = 50000;
    const REPAIR_TYPE_FACTOR_100 = 'factor_100';

    private static function getPrefix(WebSoccer $websoccer) {
        return $websoccer->getConfig('db_prefix');
    }

    private static function getCandidateCondition($alias) {
        $prefix = strlen($alias) ? $alias . '.' : '';
        return $prefix . "status = '1'"
            . ' AND ' . $prefix . 'verein_id IS NOT NULL'
            . ' AND ' . $prefix . 'verein_id > 0'
            . ' AND ' . $prefix . 'marktwert > 0'
            . ' AND ' . $prefix . 'vertrag_gehalt >= ' . self::CORRUPT_SALARY_MIN
            . ' AND ROUND(' . $prefix . 'vertrag_gehalt / 100) BETWEEN '
            . self::REPAIRED_SALARY_MIN . ' AND ' . self::REPAIRED_SALARY_MAX;
    }

    public static function getCandidateSummary(WebSoccer $websoccer, DbConnection $db) {
        $prefix = self::getPrefix($websoccer);
        $sql = "SELECT COUNT(*) AS candidate_count,
                    COALESCE(SUM(P.vertrag_gehalt), 0) AS old_total,
                    COALESCE(SUM(ROUND(P.vertrag_gehalt / 100)), 0) AS new_total,
                    COALESCE(MIN(P.vertrag_gehalt), 0) AS min_salary,
                    COALESCE(MAX(P.vertrag_gehalt), 0) AS max_salary
                FROM " . $prefix . "_spieler AS P
                WHERE " . self::getCandidateCondition('P');
        $result = $db->executeQuery($sql);
        $row = $result->fetch_assoc();
        $result->free();

        return array(
            'candidate_count' => (int) $row['candidate_count'],
            'old_total' => (int) $row['old_total'],
            'new_total' => (int) $row['new_total'],
            'min_salary' => (int) $row['min_salary'],
            'max_salary' => (int) $row['max_salary']
        );
    }

    public static function getCandidates(WebSoccer $websoccer, DbConnection $db, $limit) {
        $prefix = self::getPrefix($websoccer);
        $limit = max(1, min(1000, (int) $limit));
        $sql = "SELECT P.id,
                    P.vorname,
                    P.nachname,
                    P.kunstname,
                    P.position,
                    P.w_staerke,
                    P.marktwert,
                    P.vertrag_gehalt AS old_salary,
                    ROUND(P.vertrag_gehalt / 100) AS new_salary,
                    V.name AS club_name,
                    L.name AS league_name
                FROM " . $prefix . "_spieler AS P
                INNER JOIN " . $prefix . "_verein AS V ON V.id = P.verein_id
                LEFT JOIN " . $prefix . "_liga AS L ON L.id = V.liga_id
                WHERE " . self::getCandidateCondition('P') . "
                ORDER BY P.vertrag_gehalt DESC, P.id ASC
                LIMIT " . $limit;
        $result = $db->executeQuery($sql);
        $rows = array();
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $result->free();
        return $rows;
    }

    public static function ensureRepairLogTable(WebSoccer $websoccer, DbConnection $db) {
        $prefix = self::getPrefix($websoccer);
        $sql = "CREATE TABLE IF NOT EXISTS " . $prefix . "_player_salary_repair (
                    id INT(10) NOT NULL AUTO_INCREMENT,
                    player_id INT(10) NOT NULL,
                    admin_id SMALLINT(5) DEFAULT NULL,
                    repair_type VARCHAR(32) NOT NULL,
                    old_salary INT(10) NOT NULL,
                    new_salary INT(10) NOT NULL,
                    market_value BIGINT(20) NOT NULL DEFAULT 0,
                    player_strength DECIMAL(7,2) NOT NULL DEFAULT 0,
                    repaired_at INT(10) NOT NULL,
                    PRIMARY KEY (id),
                    KEY idx_salary_repair_player (player_id),
                    KEY idx_salary_repair_type_date (repair_type, repaired_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci";
        $db->executeQuery($sql);
    }

    public static function repairFactor100Candidates(WebSoccer $websoccer, DbConnection $db, $adminId) {
        $prefix = self::getPrefix($websoccer);
        $adminId = (int) $adminId;
        $now = (int) $websoccer->getNowAsTimestamp();
        $summaryBefore = self::getCandidateSummary($websoccer, $db);

        if ($summaryBefore['candidate_count'] <= 0) {
            return array(
                'affected' => 0,
                'summary_before' => $summaryBefore,
                'summary_after' => $summaryBefore
            );
        }

        self::ensureRepairLogTable($websoccer, $db);
        $db->connection->begin_transaction();

        try {
            $insertSql = "INSERT INTO " . $prefix . "_player_salary_repair
                    (player_id, admin_id, repair_type, old_salary, new_salary, market_value, player_strength, repaired_at)
                SELECT P.id,
                    " . ($adminId > 0 ? $adminId : 'NULL') . ",
                    '" . self::REPAIR_TYPE_FACTOR_100 . "',
                    P.vertrag_gehalt,
                    ROUND(P.vertrag_gehalt / 100),
                    P.marktwert,
                    P.w_staerke,
                    " . $now . "
                FROM " . $prefix . "_spieler AS P
                WHERE " . self::getCandidateCondition('P');
            $db->executeQuery($insertSql);

            $updateSql = "UPDATE " . $prefix . "_spieler AS P
                SET P.vertrag_gehalt = ROUND(P.vertrag_gehalt / 100)
                WHERE " . self::getCandidateCondition('P');
            $db->executeQuery($updateSql);
            $affected = (int) $db->connection->affected_rows;

            if ($affected !== $summaryBefore['candidate_count']) {
                throw new Exception('Die Anzahl der geänderten Datensätze stimmt nicht mit der Vorschau überein. Die Transaktion wurde abgebrochen.');
            }

            $db->connection->commit();
        } catch (Exception $e) {
            $db->connection->rollback();
            throw $e;
        }

        return array(
            'affected' => $affected,
            'summary_before' => $summaryBefore,
            'summary_after' => self::getCandidateSummary($websoccer, $db)
        );
    }

    public static function getRecentRepairs(WebSoccer $websoccer, DbConnection $db, $limit) {
        $prefix = self::getPrefix($websoccer);
        $limit = max(1, min(100, (int) $limit));

        $check = $db->executeQuery("SHOW TABLES LIKE '" . $db->connection->real_escape_string($prefix . '_player_salary_repair') . "'");
        $exists = ($check->num_rows > 0);
        $check->free();
        if (!$exists) {
            return array();
        }

        $sql = "SELECT R.player_id,
                    R.old_salary,
                    R.new_salary,
                    R.market_value,
                    R.player_strength,
                    R.repaired_at,
                    R.admin_id,
                    P.vorname,
                    P.nachname,
                    P.kunstname,
                    V.name AS club_name
                FROM " . $prefix . "_player_salary_repair AS R
                LEFT JOIN " . $prefix . "_spieler AS P ON P.id = R.player_id
                LEFT JOIN " . $prefix . "_verein AS V ON V.id = P.verein_id
                WHERE R.repair_type = '" . self::REPAIR_TYPE_FACTOR_100 . "'
                ORDER BY R.id DESC
                LIMIT " . $limit;
        $result = $db->executeQuery($sql);
        $rows = array();
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $result->free();
        return $rows;
    }
}
?>
