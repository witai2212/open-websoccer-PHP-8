<?php
/******************************************************

  Season finance archive for OpenWebSoccer-Sim.
  CM23 Task 1026 | 09.09.2026 | Revision 1

******************************************************/

class SeasonFinanceHistoryService {

    public static function ensureSchema(WebSoccer $websoccer, DbConnection $db) {
        $prefix = $websoccer->getConfig('db_prefix');
        $db->executeQuery("CREATE TABLE IF NOT EXISTS {$prefix}_finance_season_history (
            id INT(10) NOT NULL AUTO_INCREMENT,
            verein_id INT(10) NOT NULL,
            season_id INT(10) NOT NULL DEFAULT 0,
            season_name VARCHAR(100) NOT NULL DEFAULT '',
            league_id INT(10) NOT NULL DEFAULT 0,
            league_name VARCHAR(100) NOT NULL DEFAULT '',
            country VARCHAR(50) NOT NULL DEFAULT '',
            budget_start BIGINT(20) NOT NULL DEFAULT 0,
            total_income BIGINT(20) NOT NULL DEFAULT 0,
            total_expenses BIGINT(20) NOT NULL DEFAULT 0,
            profit_loss BIGINT(20) NOT NULL DEFAULT 0,
            budget_end BIGINT(20) NOT NULL DEFAULT 0,
            transactions_count INT(10) NOT NULL DEFAULT 0,
            breakdown_json MEDIUMTEXT NULL,
            archived_at INT(11) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_finance_season_team (verein_id, season_id),
            KEY idx_finance_season (season_id),
            KEY idx_finance_team (verein_id),
            KEY idx_finance_archived (archived_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    public static function archiveAndReset(WebSoccer $websoccer, DbConnection $db, array $seasonMap) {
        self::ensureSchema($websoccer, $db);
        $prefix = $websoccer->getConfig('db_prefix');
        $now = time();

        $db->connection->begin_transaction();
        try {
            $result = $db->executeQuery("SELECT T.id AS team_id, T.finanz_budget AS budget_end,
                    COALESCE(SUM(CASE WHEN K.betrag > 0 THEN K.betrag ELSE 0 END),0) AS total_income,
                    COALESCE(SUM(CASE WHEN K.betrag < 0 THEN K.betrag ELSE 0 END),0) AS total_expenses,
                    COALESCE(SUM(K.betrag),0) AS profit_loss,
                    COUNT(K.id) AS transactions_count
                FROM {$prefix}_verein T
                LEFT JOIN {$prefix}_konto K ON K.verein_id = T.id
                WHERE T.status = '1' AND T.nationalteam != '1'
                GROUP BY T.id, T.finanz_budget
                ORDER BY T.id ASC");

            $archived = 0;
            $totalTransactions = 0;
            while ($row = $result->fetch_array()) {
                $teamId = (int) $row['team_id'];
                $meta = isset($seasonMap[(string) $teamId]) ? $seasonMap[(string) $teamId] : array();
                $seasonId = isset($meta['season_id']) ? (int) $meta['season_id'] : 0;
                $seasonName = isset($meta['season_name']) ? (string) $meta['season_name'] : '';
                $leagueId = isset($meta['league_id']) ? (int) $meta['league_id'] : 0;
                $leagueName = isset($meta['league_name']) ? (string) $meta['league_name'] : '';
                $country = isset($meta['country']) ? (string) $meta['country'] : '';
                $income = (int) $row['total_income'];
                $expenses = (int) $row['total_expenses'];
                $profitLoss = (int) $row['profit_loss'];
                $budgetEnd = (int) $row['budget_end'];
                $budgetStart = $budgetEnd - $profitLoss;
                $transactionCount = (int) $row['transactions_count'];
                $breakdown = self::getBreakdown($websoccer, $db, $teamId);

                $safeSeasonName = $db->connection->real_escape_string($seasonName);
                $safeLeagueName = $db->connection->real_escape_string($leagueName);
                $safeCountry = $db->connection->real_escape_string($country);
                $safeBreakdown = $db->connection->real_escape_string(json_encode($breakdown, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

                $db->executeQuery("INSERT INTO {$prefix}_finance_season_history
                    (verein_id, season_id, season_name, league_id, league_name, country, budget_start, total_income, total_expenses, profit_loss, budget_end, transactions_count, breakdown_json, archived_at)
                    VALUES ({$teamId}, {$seasonId}, '{$safeSeasonName}', {$leagueId}, '{$safeLeagueName}', '{$safeCountry}', {$budgetStart}, {$income}, {$expenses}, {$profitLoss}, {$budgetEnd}, {$transactionCount}, '{$safeBreakdown}', {$now})
                    ON DUPLICATE KEY UPDATE
                      season_name = VALUES(season_name), league_id = VALUES(league_id), league_name = VALUES(league_name), country = VALUES(country),
                      budget_start = VALUES(budget_start), total_income = VALUES(total_income), total_expenses = VALUES(total_expenses),
                      profit_loss = VALUES(profit_loss), budget_end = VALUES(budget_end), transactions_count = VALUES(transactions_count),
                      breakdown_json = VALUES(breakdown_json), archived_at = VALUES(archived_at)");
                $archived++;
                $totalTransactions += $transactionCount;
            }
            $result->free();

            $db->executeQuery('DELETE FROM ' . $prefix . '_konto');
            $db->connection->commit();

            return array(
                'teams_archived' => $archived,
                'transactions_archived' => $totalTransactions,
                'account_rows_after_reset' => 0,
                'budgets_changed' => 0
            );
        } catch (Exception $e) {
            $db->connection->rollback();
            throw $e;
        }
    }

    private static function getBreakdown(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $prefix = $websoccer->getConfig('db_prefix');
        $result = $db->querySelect(
            'verwendung, SUM(betrag) AS amount, COUNT(*) AS entries',
            $prefix . '_konto',
            'verein_id = %d GROUP BY verwendung ORDER BY verwendung ASC',
            (int) $teamId
        );
        $items = array();
        while ($row = $result->fetch_array()) {
            $items[] = array(
                'subject' => isset($row['verwendung']) ? (string) $row['verwendung'] : '',
                'amount' => (int) $row['amount'],
                'entries' => (int) $row['entries']
            );
        }
        $result->free();
        return $items;
    }
}
?>