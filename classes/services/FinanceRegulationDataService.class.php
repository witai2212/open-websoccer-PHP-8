<?php
/******************************************************

  This file is part of OpenWebSoccer-Sim.

  Finance Regulation Center for CM23.

******************************************************/

/**
 * Admin-only finance regulation service.
 *
 * The service intentionally separates simulation and application. Simulation
 * never writes game economy values. Apply creates a snapshot first, writes a
 * detailed log and only touches whitelisted finance columns.
 */
class FinanceRegulationDataService {

    const SETTING_MARKET_VALUE_FACTOR = 'market_value_factor';

    public static function ensureSchema(WebSoccer $websoccer, DbConnection $db) {
        $prefix = $websoccer->getConfig('db_prefix');

        $db->executeQuery("CREATE TABLE IF NOT EXISTS `" . $prefix . "_finance_regulation_setting` (
            `setting_key` varchar(64) NOT NULL,
            `setting_value` decimal(12,4) NOT NULL DEFAULT 1.0000,
            `description` varchar(255) DEFAULT NULL,
            `updated_date` int(11) NOT NULL DEFAULT 0,
            `updated_by_admin_id` int(10) NOT NULL DEFAULT 0,
            PRIMARY KEY (`setting_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        $db->executeQuery("CREATE TABLE IF NOT EXISTS `" . $prefix . "_finance_regulation_snapshot` (
            `id` int(10) NOT NULL AUTO_INCREMENT,
            `created_date` int(11) NOT NULL DEFAULT 0,
            `admin_id` int(10) NOT NULL DEFAULT 0,
            `title` varchar(128) NOT NULL,
            `season_id` int(10) NOT NULL DEFAULT 0,
            `scope` varchar(32) NOT NULL DEFAULT 'global',
            `metrics_json` mediumtext NOT NULL,
            `recommendations_json` mediumtext NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_finance_reg_snapshot_date` (`created_date`),
            KEY `idx_finance_reg_snapshot_season` (`season_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        $db->executeQuery("CREATE TABLE IF NOT EXISTS `" . $prefix . "_finance_regulation_log` (
            `id` int(10) NOT NULL AUTO_INCREMENT,
            `created_date` int(11) NOT NULL DEFAULT 0,
            `admin_id` int(10) NOT NULL DEFAULT 0,
            `action_key` varchar(64) NOT NULL,
            `action_label` varchar(128) NOT NULL,
            `mode` enum('simulate','apply','export','snapshot') NOT NULL DEFAULT 'simulate',
            `parameters_json` mediumtext NOT NULL,
            `result_json` mediumtext NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_finance_reg_log_date` (`created_date`),
            KEY `idx_finance_reg_log_mode` (`mode`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        $settingTable = self::table($websoccer, 'finance_regulation_setting');
        $now = (int) $websoccer->getNowAsTimestamp();
        $db->executeQuery("INSERT IGNORE INTO `" . $settingTable . "`
            (`setting_key`, `setting_value`, `description`, `updated_date`, `updated_by_admin_id`)
            VALUES ('" . self::SETTING_MARKET_VALUE_FACTOR . "', 1.0000, 'Globaler Multiplikator für automatisch berechnete Marktwerte.', " . $now . ", 0)");
    }

    public static function getAvailableSeasons(WebSoccer $websoccer, DbConnection $db) {
        $prefix = $websoccer->getConfig('db_prefix');
        $rows = array();
        $sql = "SELECT S.id, S.name, S.liga_id, S.beendet, L.name AS league_name
                FROM " . $prefix . "_saison AS S
                LEFT JOIN " . $prefix . "_liga AS L ON L.id = S.liga_id
                ORDER BY S.beendet ASC, S.id DESC
                LIMIT 250";
        $result = $db->executeQuery($sql);
        while ($row = $result->fetch_array()) {
            $rows[] = $row;
        }
        $result->free();
        return $rows;
    }

    public static function getDefaultSeasonId(WebSoccer $websoccer, DbConnection $db) {
        $prefix = $websoccer->getConfig('db_prefix');
        $sql = "SELECT id FROM " . $prefix . "_saison ORDER BY beendet ASC, id DESC LIMIT 1";
        $result = $db->executeQuery($sql);
        $row = $result->fetch_array();
        $result->free();
        return isset($row['id']) ? (int) $row['id'] : 0;
    }

    public static function getDashboard(WebSoccer $websoccer, DbConnection $db, $seasonId) {
        self::ensureSchema($websoccer, $db);

        $seasonId = (int) $seasonId;
        $filter = self::buildDateFilter($websoccer, $db, $seasonId);

        $dashboard = array(
            'season_id' => $seasonId,
            'filter' => $filter,
            'market_value_factor' => self::getMarketValueFactor($websoccer, $db),
            'scopes' => array(
                'all' => self::getScopeMetrics($websoccer, $db, $filter, 'all'),
                'human' => self::getScopeMetrics($websoccer, $db, $filter, 'human'),
                'cpu' => self::getScopeMetrics($websoccer, $db, $filter, 'cpu')
            ),
            'rankings' => self::getClubRankings($websoccer, $db, $filter),
            'settings' => self::getSettings($websoccer, $db)
        );

        $dashboard['recommendations'] = self::getRecommendations($dashboard);
        return $dashboard;
    }

    public static function getSettings(WebSoccer $websoccer, DbConnection $db) {
        return array(
            'market_value_factor' => self::getMarketValueFactor($websoccer, $db)
        );
    }

    public static function getMarketValueFactor(WebSoccer $websoccer, DbConnection $db) {
        $table = self::table($websoccer, 'finance_regulation_setting');
        if (!self::tableExists($websoccer, $db, 'finance_regulation_setting')) {
            return 1.0;
        }

        $key = $db->connection->real_escape_string(self::SETTING_MARKET_VALUE_FACTOR);
        $result = $db->executeQuery("SELECT setting_value FROM `" . $table . "` WHERE setting_key = '" . $key . "' LIMIT 1");
        $row = $result->fetch_array();
        $result->free();

        $factor = isset($row['setting_value']) ? (float) $row['setting_value'] : 1.0;
        if ($factor <= 0) {
            $factor = 1.0;
        }
        return $factor;
    }

    public static function parametersFromRequest($request) {
        $targets = array();
        if (isset($request['targets']) && is_array($request['targets'])) {
            foreach ($request['targets'] as $target) {
                $target = preg_replace('/[^a-z_]/', '', $target);
                if (strlen($target)) {
                    $targets[$target] = TRUE;
                }
            }
        }

        return array(
            'targets' => array_keys($targets),
            'player_salary_percent' => self::sanitizePercent(isset($request['player_salary_percent']) ? $request['player_salary_percent'] : -10),
            'secondary_cost_percent' => self::sanitizePercent(isset($request['secondary_cost_percent']) ? $request['secondary_cost_percent'] : 0),
            'sponsor_percent' => self::sanitizePercent(isset($request['sponsor_percent']) ? $request['sponsor_percent'] : 0),
            'sponsor_scope' => self::sanitizeChoice(isset($request['sponsor_scope']) ? $request['sponsor_scope'] : 'future', array('future', 'active', 'both'), 'future'),
            'budget_percent' => self::sanitizePercent(isset($request['budget_percent']) ? $request['budget_percent'] : 0),
            'budget_scope' => self::sanitizeChoice(isset($request['budget_scope']) ? $request['budget_scope'] : 'above_threshold', array('all', 'human', 'cpu', 'above_threshold'), 'above_threshold'),
            'budget_threshold' => max(0, (int) (isset($request['budget_threshold']) ? $request['budget_threshold'] : 100000000)),
            'ticket_percent' => self::sanitizePercent(isset($request['ticket_percent']) ? $request['ticket_percent'] : 0),
            'market_value_percent' => self::sanitizePercent(isset($request['market_value_percent']) ? $request['market_value_percent'] : 0),
            'season_id' => (int) (isset($request['season_id']) ? $request['season_id'] : 0)
        );
    }

    public static function simulateCorrection(WebSoccer $websoccer, DbConnection $db, $params, $adminId = 0) {
        self::ensureSchema($websoccer, $db);
        $rows = self::buildCorrectionEffects($websoccer, $db, $params, FALSE);
        $result = array(
            'mode' => 'simulate',
            'label' => 'Simulation',
            'effects' => $rows
        );
        self::writeLog($websoccer, $db, (int) $adminId, 'manual_simulation', 'Simulation', 'simulate', $params, $result);
        return $result;
    }

    public static function applyCorrection(WebSoccer $websoccer, DbConnection $db, $params, $adminId) {
        self::ensureSchema($websoccer, $db);
        self::createSnapshot($websoccer, $db, 'Snapshot vor Finanzkorrektur', (int) $params['season_id'], (int) $adminId);

        $updates = self::buildCorrectionEffects($websoccer, $db, $params, TRUE);
        $result = array(
            'mode' => 'apply',
            'label' => 'Finanzkorrektur angewendet',
            'updates' => $updates
        );
        self::writeLog($websoccer, $db, (int) $adminId, 'manual_apply', 'Finanzkorrektur angewendet', 'apply', $params, $result);
        return $result;
    }

    public static function createSnapshot(WebSoccer $websoccer, DbConnection $db, $title, $seasonId, $adminId) {
        self::ensureSchema($websoccer, $db);
        $dashboard = self::getDashboard($websoccer, $db, (int) $seasonId);
        $table = self::table($websoccer, 'finance_regulation_snapshot');
        $now = (int) $websoccer->getNowAsTimestamp();

        $metricsJson = json_encode($dashboard['scopes']);
        $recommendationsJson = json_encode($dashboard['recommendations']);

        $sql = "INSERT INTO `" . $table . "` SET "
            . "created_date = " . $now . ", "
            . "admin_id = " . (int) $adminId . ", "
            . "title = '" . self::esc($db, $title) . "', "
            . "season_id = " . (int) $seasonId . ", "
            . "scope = 'global', "
            . "metrics_json = '" . self::esc($db, $metricsJson) . "', "
            . "recommendations_json = '" . self::esc($db, $recommendationsJson) . "'";
        $db->executeQuery($sql);
        $snapshotId = $db->getLastInsertedId();

        $result = array('snapshot_id' => (int) $snapshotId, 'title' => $title);
        self::writeLog($websoccer, $db, (int) $adminId, 'snapshot', $title, 'snapshot', array('season_id' => (int) $seasonId), $result);
        return $result;
    }

    public static function getLatestLogs(WebSoccer $websoccer, DbConnection $db, $limit = 10) {
        self::ensureSchema($websoccer, $db);
        $rows = array();
        $table = self::table($websoccer, 'finance_regulation_log');
        $limit = max(1, min(50, (int) $limit));
        $result = $db->executeQuery("SELECT * FROM `" . $table . "` ORDER BY id DESC LIMIT " . $limit);
        while ($row = $result->fetch_array()) {
            $rows[] = $row;
        }
        $result->free();
        return $rows;
    }

    public static function getLatestSnapshots(WebSoccer $websoccer, DbConnection $db, $limit = 10) {
        self::ensureSchema($websoccer, $db);
        $rows = array();
        $table = self::table($websoccer, 'finance_regulation_snapshot');
        $limit = max(1, min(50, (int) $limit));
        $result = $db->executeQuery("SELECT * FROM `" . $table . "` ORDER BY id DESC LIMIT " . $limit);
        while ($row = $result->fetch_array()) {
            $rows[] = $row;
        }
        $result->free();
        return $rows;
    }

    public static function logExport(WebSoccer $websoccer, DbConnection $db, $adminId, $seasonId, $filename) {
        self::ensureSchema($websoccer, $db);
        self::writeLog($websoccer, $db, (int) $adminId, 'csv_export', 'CSV exportiert', 'export', array('season_id' => (int) $seasonId), array('filename' => $filename));
    }

    public static function buildCsvReport($dashboard) {
        $lines = array();
        $lines[] = self::csvLine(array('Finance Regulation Center'));
        $lines[] = self::csvLine(array('Zeitraum', $dashboard['filter']['label']));
        $lines[] = self::csvLine(array('Marktwert-Faktor', self::number($dashboard['market_value_factor'], 4)));
        $lines[] = '';
        $lines[] = self::csvLine(array('Scope', 'Vereine', 'Ø Budget', 'Einnahmen gesamt', 'Ø Einnahmen/Spieltag', 'Spielergehälter/Spieltag', 'Ø Spielergehalt', 'Gehalt/Einnahmen', 'Fixkosten/Einnahmen', 'Transferausgaben'));

        foreach (array('all' => 'Alle', 'human' => 'Menschlich', 'cpu' => 'CPU') as $scope => $label) {
            $m = $dashboard['scopes'][$scope];
            $lines[] = self::csvLine(array(
                $label,
                $m['team_count'],
                $m['avg_budget'],
                $m['income_total'],
                $m['avg_income_per_matchday'],
                $m['player_salary_total'],
                $m['avg_player_salary'],
                self::number($m['salary_income_ratio'], 4),
                self::number($m['recurring_income_ratio'], 4),
                $m['transfer_spending']
            ));
        }

        $lines[] = '';
        $lines[] = self::csvLine(array('Empfehlungen'));
        $lines[] = self::csvLine(array('Status', 'Titel', 'Details', 'Vorschlag'));
        foreach ($dashboard['recommendations'] as $row) {
            $lines[] = self::csvLine(array($row['severity'], $row['title'], $row['message'], $row['suggestion']));
        }

        $lines[] = '';
        $lines[] = self::csvLine(array('Reichste Vereine'));
        $lines[] = self::csvLine(array('Verein', 'Manager', 'Budget', 'Liga'));
        foreach ($dashboard['rankings']['richest'] as $club) {
            $lines[] = self::csvLine(array($club['name'], $club['manager_name'], $club['finanz_budget'], $club['league_name']));
        }

        $lines[] = '';
        $lines[] = self::csvLine(array('Ärmste Vereine'));
        $lines[] = self::csvLine(array('Verein', 'Manager', 'Budget', 'Liga'));
        foreach ($dashboard['rankings']['poorest'] as $club) {
            $lines[] = self::csvLine(array($club['name'], $club['manager_name'], $club['finanz_budget'], $club['league_name']));
        }

        return implode("\r\n", $lines) . "\r\n";
    }

    private static function getScopeMetrics(WebSoccer $websoccer, DbConnection $db, $filter, $scope) {
        $prefix = $websoccer->getConfig('db_prefix');
        $where = self::teamWhere($websoccer, $db, $scope, 'V');
        $dateWhere = self::dateCondition('K.datum', $filter);
        $matchdays = max(1, (int) $filter['matchdays']);

        $teamSql = "SELECT COUNT(*) AS team_count,
                           COALESCE(SUM(V.finanz_budget), 0) AS budget_total,
                           COALESCE(AVG(V.finanz_budget), 0) AS avg_budget,
                           COALESCE(MIN(V.finanz_budget), 0) AS min_budget,
                           COALESCE(MAX(V.finanz_budget), 0) AS max_budget
                    FROM " . $prefix . "_verein AS V
                    WHERE " . $where;
        $team = self::fetchOne($db, $teamSql);

        $bookingSql = "SELECT COUNT(K.id) AS booking_count,
                              COALESCE(SUM(CASE WHEN K.betrag > 0 THEN K.betrag ELSE 0 END), 0) AS income_total,
                              COALESCE(SUM(CASE WHEN K.betrag < 0 THEN 0 - K.betrag ELSE 0 END), 0) AS expense_total
                       FROM " . $prefix . "_verein AS V
                       LEFT JOIN " . $prefix . "_konto AS K ON K.verein_id = V.id" . $dateWhere . "
                       WHERE " . $where;
        $booking = self::fetchOne($db, $bookingSql);

        $salarySql = "SELECT COUNT(P.id) AS player_count,
                             COALESCE(SUM(P.vertrag_gehalt), 0) AS player_salary_total,
                             COALESCE(AVG(P.vertrag_gehalt), 0) AS avg_player_salary
                      FROM " . $prefix . "_spieler AS P
                      INNER JOIN " . $prefix . "_verein AS V ON V.id = P.verein_id
                      WHERE P.status = '1' AND " . $where;
        $salary = self::fetchOne($db, $salarySql);

        $secondary = self::getSecondaryCostItems($websoccer, $db, $scope);
        $secondaryTotal = 0;
        foreach ($secondary as $item) {
            $secondaryTotal += (int) $item['amount'];
        }

        $transferSpending = self::getTransferSpending($websoccer, $db, $filter, $scope);
        $avgIncomePerMatchday = ((int) $booking['income_total'] > 0) ? ((int) $booking['income_total'] / $matchdays) : 0;
        $playerSalaryTotal = (int) $salary['player_salary_total'];
        $recurringCostTotal = $playerSalaryTotal + $secondaryTotal;

        return array(
            'scope' => $scope,
            'team_count' => (int) $team['team_count'],
            'budget_total' => (int) round($team['budget_total']),
            'avg_budget' => (int) round($team['avg_budget']),
            'min_budget' => (int) round($team['min_budget']),
            'max_budget' => (int) round($team['max_budget']),
            'booking_count' => (int) $booking['booking_count'],
            'income_total' => (int) round($booking['income_total']),
            'expense_total' => (int) round($booking['expense_total']),
            'net_total' => (int) round($booking['income_total'] - $booking['expense_total']),
            'matchdays' => $matchdays,
            'avg_income_per_matchday' => (int) round($avgIncomePerMatchday),
            'player_count' => (int) $salary['player_count'],
            'player_salary_total' => $playerSalaryTotal,
            'avg_player_salary' => (int) round($salary['avg_player_salary']),
            'secondary_cost_total' => $secondaryTotal,
            'secondary_cost_items' => $secondary,
            'recurring_cost_total' => $recurringCostTotal,
            'salary_income_ratio' => ($avgIncomePerMatchday > 0) ? round($playerSalaryTotal / $avgIncomePerMatchday, 4) : 0,
            'recurring_income_ratio' => ($avgIncomePerMatchday > 0) ? round($recurringCostTotal / $avgIncomePerMatchday, 4) : 0,
            'transfer_spending' => $transferSpending
        );
    }

    private static function getClubRankings(WebSoccer $websoccer, DbConnection $db, $filter) {
        return array(
            'richest' => self::getBudgetRanking($websoccer, $db, 'DESC'),
            'poorest' => self::getBudgetRanking($websoccer, $db, 'ASC'),
            'salary_pressure' => self::getSalaryPressureRanking($websoccer, $db, $filter)
        );
    }

    private static function getBudgetRanking(WebSoccer $websoccer, DbConnection $db, $direction) {
        $prefix = $websoccer->getConfig('db_prefix');
        $where = self::teamWhere($websoccer, $db, 'all', 'V');
        $direction = ($direction == 'ASC') ? 'ASC' : 'DESC';
        $rows = array();

        $sql = "SELECT V.id, V.name, V.finanz_budget, V.user_id, U.nick AS manager_name, L.name AS league_name
                FROM " . $prefix . "_verein AS V
                LEFT JOIN " . $prefix . "_user AS U ON U.id = V.user_id
                LEFT JOIN " . $prefix . "_liga AS L ON L.id = V.liga_id
                WHERE " . $where . "
                ORDER BY V.finanz_budget " . $direction . ", V.name ASC
                LIMIT 10";
        $result = $db->executeQuery($sql);
        while ($row = $result->fetch_array()) {
            $row['manager_name'] = strlen($row['manager_name']) ? $row['manager_name'] : 'CPU';
            $rows[] = $row;
        }
        $result->free();
        return $rows;
    }

    private static function getSalaryPressureRanking(WebSoccer $websoccer, DbConnection $db, $filter) {
        $prefix = $websoccer->getConfig('db_prefix');
        $where = self::teamWhere($websoccer, $db, 'all', 'V');
        $dateWhere = self::dateCondition('K.datum', $filter);
        $matchdays = max(1, (int) $filter['matchdays']);
        $rows = array();

        $sql = "SELECT R.*,
                       CASE WHEN R.income_per_matchday > 0 THEN R.salary_total / R.income_per_matchday ELSE 0 END AS salary_ratio
                FROM (
                    SELECT V.id, V.name, V.finanz_budget, U.nick AS manager_name, L.name AS league_name,
                           COALESCE(P.salary_total, 0) AS salary_total,
                           COALESCE(K.income_total, 0) / " . $matchdays . " AS income_per_matchday
                    FROM " . $prefix . "_verein AS V
                    LEFT JOIN " . $prefix . "_user AS U ON U.id = V.user_id
                    LEFT JOIN " . $prefix . "_liga AS L ON L.id = V.liga_id
                    LEFT JOIN (
                        SELECT verein_id, SUM(vertrag_gehalt) AS salary_total
                        FROM " . $prefix . "_spieler
                        WHERE status = '1'
                        GROUP BY verein_id
                    ) AS P ON P.verein_id = V.id
                    LEFT JOIN (
                        SELECT verein_id, SUM(CASE WHEN betrag > 0 THEN betrag ELSE 0 END) AS income_total
                        FROM " . $prefix . "_konto AS K
                        WHERE 1=1" . self::dateCondition('K.datum', $filter) . "
                        GROUP BY verein_id
                    ) AS K ON K.verein_id = V.id
                    WHERE " . $where . "
                ) AS R
                ORDER BY salary_ratio DESC, salary_total DESC
                LIMIT 15";
        $result = $db->executeQuery($sql);
        while ($row = $result->fetch_array()) {
            $row['manager_name'] = strlen($row['manager_name']) ? $row['manager_name'] : 'CPU';
            $rows[] = $row;
        }
        $result->free();
        return $rows;
    }

    private static function getTransferSpending(WebSoccer $websoccer, DbConnection $db, $filter, $scope) {
        $prefix = $websoccer->getConfig('db_prefix');
        if (!self::tableExists($websoccer, $db, 'transfer')) {
            return 0;
        }
        $where = self::teamWhere($websoccer, $db, $scope, 'V');
        $dateWhere = self::dateCondition('T.datum', $filter);

        $sql = "SELECT COALESCE(SUM(COALESCE(NULLIF(T.directtransfer_amount, 0), A.abloese, 0)), 0) AS amount
                FROM " . $prefix . "_transfer AS T
                LEFT JOIN " . $prefix . "_transfer_angebot AS A ON A.id = T.bid_id
                INNER JOIN " . $prefix . "_verein AS V ON V.id = T.buyer_club_id
                WHERE " . $where . $dateWhere;
        $row = self::fetchOne($db, $sql);
        return (int) round($row['amount']);
    }

    private static function getSecondaryCostItems(WebSoccer $websoccer, DbConnection $db, $scope) {
        $prefix = $websoccer->getConfig('db_prefix');
        $where = self::teamWhere($websoccer, $db, $scope, 'V');
        $rows = array();

        if (self::tableExists($websoccer, $db, 'club_staff_assignment') && self::tableExists($websoccer, $db, 'club_staff')) {
            $rows[] = self::costRow($db, 'Club-Staff', "SELECT COALESCE(SUM(S.salary), 0) AS amount
                FROM " . $prefix . "_club_staff_assignment AS A
                INNER JOIN " . $prefix . "_club_staff AS S ON S.id = A.staff_id
                INNER JOIN " . $prefix . "_verein AS V ON V.id = A.team_id
                WHERE S.active = '1' AND " . $where);
        }

        if (self::tableExists($websoccer, $db, 'scout')) {
            $rows[] = self::costRow($db, 'Scouts', "SELECT COALESCE(SUM(S.fee), 0) AS amount
                FROM " . $prefix . "_scout AS S
                INNER JOIN " . $prefix . "_verein AS V ON V.id = S.team_id
                WHERE S.team_id > 0 AND " . $where);
        }

        if (self::tableExists($websoccer, $db, 'scouting_camp')) {
            $rows[] = self::costRow($db, 'Scouting-Camps', "SELECT COALESCE(SUM(C.fee_per_matchday), 0) AS amount
                FROM " . $prefix . "_scouting_camp AS C
                INNER JOIN " . $prefix . "_verein AS V ON V.id = C.team_id
                WHERE C.status = '1' AND " . $where);
        }

        if (self::tableExists($websoccer, $db, 'scouting_department')) {
            $rows[] = self::costRow($db, 'Scouting-Abteilungen', "SELECT COALESCE(SUM(D.maintenance_fee), 0) AS amount
                FROM " . $prefix . "_scouting_department AS D
                INNER JOIN " . $prefix . "_verein AS V ON V.id = D.team_id
                WHERE D.status = '1' AND " . $where);
        }

        if (self::tableExists($websoccer, $db, 'youth_academy') && self::tableExists($websoccer, $db, 'youth_academy_level')) {
            $rows[] = self::costRow($db, 'Jugendakademien', "SELECT COALESCE(SUM(L.maintenance_fee), 0) AS amount
                FROM " . $prefix . "_youth_academy AS Y
                INNER JOIN " . $prefix . "_youth_academy_level AS L ON L.level = Y.level
                INNER JOIN " . $prefix . "_verein AS V ON V.id = Y.team_id
                WHERE Y.status = '1' AND L.status = '1' AND " . $where);
        }

        if (self::tableExists($websoccer, $db, 'bank')) {
            $rows[] = self::costRow($db, 'Bank-Raten', "SELECT COALESCE(SUM(CASE WHEN B.matches_left > 0 THEN CEIL(B.remaining_amount / B.matches_left) ELSE 0 END), 0) AS amount
                FROM " . $prefix . "_bank AS B
                INNER JOIN " . $prefix . "_verein AS V ON V.id = B.verein_id
                WHERE B.status = 'active' AND " . $where);
        }

        return $rows;
    }

    private static function costRow(DbConnection $db, $label, $sql) {
        $row = self::fetchOne($db, $sql);
        return array('label' => $label, 'amount' => (int) round($row['amount']));
    }

    private static function getRecommendations($dashboard) {
        $rows = array();
        $all = $dashboard['scopes']['all'];
        $human = $dashboard['scopes']['human'];
        $cpu = $dashboard['scopes']['cpu'];

        if ($all['team_count'] < 1) {
            $rows[] = self::recommendation('warning', 'Keine aktiven Vereine gefunden', 'Die Auswertung kann ohne aktive Vereine keine Marktlogik ableiten.', 'Keine Aktion möglich.');
            return $rows;
        }

        if ($human['team_count'] > 0 && $human['salary_income_ratio'] > 0.70) {
            $rows[] = self::recommendation('danger', 'Spielergehälter bei menschlichen Vereinen sind sehr hoch', 'Die Spielergehälter liegen über 70 % der durchschnittlichen Einnahmen pro Spieltag. Das kann Vereine bei schlechter Form schnell ruinieren.', 'Simulation: Spielergehälter -10 %.');
        }

        if ($all['recurring_income_ratio'] > 0.90) {
            $rows[] = self::recommendation('danger', 'Fixkosten fressen fast alle Einnahmen', 'Spielergehälter plus laufende Neben-/Staffkosten liegen über 90 % der Einnahmen pro Spieltag.', 'Kosten senken oder Sponsoren/Stadioneinnahmen leicht erhöhen.');
        }

        if ($all['salary_income_ratio'] < 0.35 && $all['avg_income_per_matchday'] > 0 && $all['net_total'] > 0) {
            $rows[] = self::recommendation('warning', 'Gehälter sind im Verhältnis zu Einnahmen niedrig', 'Wenn der Markt insgesamt Gewinne macht und Gehälter niedrig bleiben, sammelt sich langfristig zu viel Budget an.', 'Simulation: Spielergehälter +5 % oder Sponsor-/Ticketwerte leicht senken.');
        }

        if ($human['team_count'] > 0 && $cpu['team_count'] > 0 && $cpu['avg_budget'] > 0 && $human['avg_budget'] > ($cpu['avg_budget'] * 1.5)) {
            $rows[] = self::recommendation('warning', 'Human-Budgets liegen deutlich über CPU-Budgets', 'Menschliche Vereine haben im Schnitt mehr als 150 % des CPU-Budgets. Das kann Wettbewerb und Transfers verzerren.', 'Budgetkorrektur für hohe Budgets simulieren.');
        }

        if ($all['income_total'] > 0 && $all['transfer_spending'] > ($all['income_total'] * 0.75)) {
            $rows[] = self::recommendation('warning', 'Transferausgaben sind sehr hoch', 'Die Transferausgaben liegen über 75 % der gebuchten Einnahmen im Zeitraum.', 'Marktwert-Faktor oder Budgets leicht senken.');
        }

        if ($all['max_budget'] > 0 && $all['min_budget'] >= 0 && $all['max_budget'] > max(50000000, $all['min_budget'] * 20)) {
            $rows[] = self::recommendation('info', 'Große Budgetspreizung', 'Zwischen reichstem und ärmstem Club besteht ein sehr großer Abstand. Das ist nicht automatisch falsch, sollte aber beobachtet werden.', 'Richest/poorest Clubs prüfen.');
        }

        if (!count($rows)) {
            $rows[] = self::recommendation('success', 'Keine akute Marktstörung erkannt', 'Die wichtigsten Verhältniswerte liegen in einem plausiblen Bereich.', 'Weiter beobachten und regelmäßig Snapshot exportieren.');
        }

        return $rows;
    }

    private static function recommendation($severity, $title, $message, $suggestion) {
        return array('severity' => $severity, 'title' => $title, 'message' => $message, 'suggestion' => $suggestion);
    }

    private static function buildCorrectionEffects(WebSoccer $websoccer, DbConnection $db, $params, $apply) {
        $targets = array_flip($params['targets']);
        $rows = array();

        if (isset($targets['player_salaries'])) {
            $rows[] = self::simulateOrScale($websoccer, $db, 'Spielergehälter', 'spieler', array('vertrag_gehalt'), self::factor($params['player_salary_percent']), "status = '1'", $apply, 0);
        }

        if (isset($targets['secondary_costs'])) {
            $rows = array_merge($rows, self::secondaryCostEffects($websoccer, $db, self::factor($params['secondary_cost_percent']), $apply));
        }

        if (isset($targets['sponsors'])) {
            $scope = $params['sponsor_scope'];
            $factor = self::factor($params['sponsor_percent']);
            if ($scope == 'future' || $scope == 'both') {
                $rows[] = self::simulateOrScale($websoccer, $db, 'Sponsoren: künftige Angebote', 'sponsor', array('b_spiel', 'b_heimzuschlag', 'b_sieg', 'b_meisterschaft', 'b_cup'), $factor, '1=1', $apply, 0);
            }
            if ($scope == 'active' || $scope == 'both') {
                $rows[] = self::simulateOrScale($websoccer, $db, 'Sponsoren: aktive Verträge', 'sponsor_contract', array('b_spiel', 'b_heimzuschlag', 'b_sieg', 'b_meisterschaft', 'b_cup'), $factor, "status = 'active'", $apply, 0);
                $rows[] = self::simulateOrScale($websoccer, $db, 'Stadionnamen: aktive Verträge', 'stadium_naming_contract', array('base_payout_per_match'), $factor, "status = 'active'", $apply, 0);
            }
        }

        if (isset($targets['club_budgets'])) {
            $rows[] = self::simulateOrScale($websoccer, $db, 'Vereinsbudgets', 'verein', array('finanz_budget'), self::factor($params['budget_percent']), self::budgetWhere($websoccer, $db, $params), $apply, 0);
        }

        if (isset($targets['ticket_prices'])) {
            $rows[] = self::simulateOrScale($websoccer, $db, 'Ticketpreise', 'verein', array('preis_stehen', 'preis_sitz', 'preis_haupt_stehen', 'preis_haupt_sitze', 'preis_vip'), self::factor($params['ticket_percent']), self::teamWhere($websoccer, $db, 'all', ''), $apply, 1);
        }

        if (isset($targets['market_values'])) {
            $rows[] = self::marketValueEffect($websoccer, $db, self::factor($params['market_value_percent']), $apply);
        }

        return $rows;
    }

    private static function secondaryCostEffects(WebSoccer $websoccer, DbConnection $db, $factor, $apply) {
        $rows = array();
        $rows[] = self::simulateOrScale($websoccer, $db, 'Club-Staff Gehälter', 'club_staff', array('salary'), $factor, "active = '1'", $apply, 0);
        $rows[] = self::simulateOrScale($websoccer, $db, 'Scout-Gebühren', 'scout', array('fee'), $factor, '1=1', $apply, 0);
        $rows[] = self::simulateOrScale($websoccer, $db, 'Scouting-Camp Gebühren', 'scouting_camp', array('fee_per_matchday'), $factor, "status = '1'", $apply, 0);
        $rows[] = self::simulateOrScale($websoccer, $db, 'Scouting-Abteilungen Wartung', 'scouting_department', array('maintenance_fee'), $factor, "status = '1'", $apply, 0);
        $rows[] = self::simulateOrScale($websoccer, $db, 'Scouting-Level Wartung', 'scouting_department_level', array('maintenance_fee'), $factor, "status = '1'", $apply, 0);
        $rows[] = self::simulateOrScale($websoccer, $db, 'Jugendakademie-Level Wartung', 'youth_academy_level', array('maintenance_fee'), $factor, "status = '1'", $apply, 0);
        $rows[] = self::simulateOrScale($websoccer, $db, 'Trainingslager Preise', 'trainingslager', array('preis_spieler_tag'), $factor, '1=1', $apply, 0);
        $rows[] = self::simulateOrScale($websoccer, $db, 'Stadionbauer Kosten', 'stadium_builder', array('fixedcosts', 'cost_per_seat'), $factor, '1=1', $apply, 0);
        $rows[] = self::simulateOrScale($websoccer, $db, 'Stadiongebäude Kosten', 'stadiumbuilding', array('costs'), $factor, '1=1', $apply, 0);
        $rows[] = self::simulateOrScale($websoccer, $db, 'Merchandising Einkaufspreise', 'merchandising_product', array('purchase_price'), $factor, "active = '1'", $apply, 0);
        return $rows;
    }

    private static function simulateOrScale(WebSoccer $websoccer, DbConnection $db, $label, $table, $columns, $factor, $where, $apply, $minimum) {
        if (!self::tableExists($websoccer, $db, $table)) {
            return self::effectRow($label, $table, implode(', ', $columns), 0, 0, 0, 0, TRUE);
        }

        $oldTotal = self::sumColumns($websoccer, $db, $table, $columns, $where);
        $newTotal = (int) round($oldTotal * $factor);
        $affected = 0;

        if ($apply && abs($factor - 1.0) > 0.0001) {
            $affected = self::scaleColumns($websoccer, $db, $table, $columns, $factor, $where, $minimum);
            $newTotal = self::sumColumns($websoccer, $db, $table, $columns, $where);
        }

        return self::effectRow($label, $table, implode(', ', $columns), $oldTotal, $newTotal, $newTotal - $oldTotal, $affected, FALSE);
    }

    private static function marketValueEffect(WebSoccer $websoccer, DbConnection $db, $factor, $apply) {
        if (!self::tableExists($websoccer, $db, 'spieler')) {
            return self::effectRow('Marktwerte', 'spieler', 'marktwert + Formel-Faktor', 0, 0, 0, 0, TRUE);
        }

        $oldTotal = self::sumColumns($websoccer, $db, 'spieler', array('marktwert'), "status = '1'");
        $oldFactor = self::getMarketValueFactor($websoccer, $db);
        $newFactor = max(0.05, min(10.0, $oldFactor * $factor));
        $newTotal = (int) round($oldTotal * $factor);
        $affected = 0;

        if ($apply && abs($factor - 1.0) > 0.0001) {
            $affected = self::scaleColumns($websoccer, $db, 'spieler', array('marktwert'), $factor, "status = '1'", 0);
            self::setMarketValueFactor($websoccer, $db, $newFactor);
            $newTotal = self::sumColumns($websoccer, $db, 'spieler', array('marktwert'), "status = '1'");
        }

        $row = self::effectRow('Marktwerte / Formel-Faktor', 'spieler + finance_regulation_setting', 'marktwert; market_value_factor ' . self::number($oldFactor, 4) . ' → ' . self::number($newFactor, 4), $oldTotal, $newTotal, $newTotal - $oldTotal, $affected, FALSE);
        $row['old_factor'] = $oldFactor;
        $row['new_factor'] = $newFactor;
        return $row;
    }

    private static function setMarketValueFactor(WebSoccer $websoccer, DbConnection $db, $factor) {
        self::ensureSchema($websoccer, $db);
        $table = self::table($websoccer, 'finance_regulation_setting');
        $factor = max(0.05, min(10.0, (float) $factor));
        $now = (int) $websoccer->getNowAsTimestamp();
        $key = self::esc($db, self::SETTING_MARKET_VALUE_FACTOR);
        $db->executeQuery("INSERT INTO `" . $table . "` (`setting_key`, `setting_value`, `description`, `updated_date`, `updated_by_admin_id`)
            VALUES ('" . $key . "', " . self::number($factor, 4) . ", 'Globaler Multiplikator für automatisch berechnete Marktwerte.', " . $now . ", 0)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_date = VALUES(updated_date)");
    }

    private static function effectRow($label, $table, $columns, $oldTotal, $newTotal, $delta, $affected, $skipped) {
        return array(
            'label' => $label,
            'table' => $table,
            'columns' => $columns,
            'old_total' => (int) round($oldTotal),
            'new_total' => (int) round($newTotal),
            'delta' => (int) round($delta),
            'affected_rows' => (int) $affected,
            'skipped' => $skipped
        );
    }

    private static function scaleColumns(WebSoccer $websoccer, DbConnection $db, $table, $columns, $factor, $where, $minimum) {
        $fullTable = self::table($websoccer, $table);
        $setParts = array();
        foreach ($columns as $column) {
            if (!self::columnExists($websoccer, $db, $table, $column)) {
                continue;
            }
            $col = self::quote($column);
            $setParts[] = $col . " = CASE WHEN " . $col . " IS NULL THEN NULL ELSE GREATEST(" . (int) $minimum . ", ROUND(CAST(" . $col . " AS DECIMAL(20,4)) * " . self::number($factor, 6) . ")) END";
        }
        if (!count($setParts)) {
            return 0;
        }
        $sql = "UPDATE `" . $fullTable . "` SET " . implode(', ', $setParts) . " WHERE " . $where;
        $db->executeQuery($sql);
        return (int) $db->connection->affected_rows;
    }

    private static function sumColumns(WebSoccer $websoccer, DbConnection $db, $table, $columns, $where) {
        if (!self::tableExists($websoccer, $db, $table)) {
            return 0;
        }
        $parts = array();
        foreach ($columns as $column) {
            if (self::columnExists($websoccer, $db, $table, $column)) {
                $parts[] = "COALESCE(SUM(CAST(" . self::quote($column) . " AS DECIMAL(20,4))), 0)";
            }
        }
        if (!count($parts)) {
            return 0;
        }
        $sql = "SELECT " . implode(' + ', $parts) . " AS amount FROM `" . self::table($websoccer, $table) . "` WHERE " . $where;
        $row = self::fetchOne($db, $sql);
        return (int) round($row['amount']);
    }

    private static function budgetWhere(WebSoccer $websoccer, DbConnection $db, $params) {
        $base = self::teamWhere($websoccer, $db, 'all', '');
        if ($params['budget_scope'] == 'human') {
            $base = self::teamWhere($websoccer, $db, 'human', '');
        } elseif ($params['budget_scope'] == 'cpu') {
            $base = self::teamWhere($websoccer, $db, 'cpu', '');
        } elseif ($params['budget_scope'] == 'above_threshold') {
            $base .= ' AND finanz_budget >= ' . (int) $params['budget_threshold'];
        }
        return $base;
    }

    private static function buildDateFilter(WebSoccer $websoccer, DbConnection $db, $seasonId) {
        $seasonId = (int) $seasonId;
        $filter = array('season_id' => $seasonId, 'date_start' => 0, 'date_end' => 0, 'label' => 'Alle Buchungen', 'matchdays' => 1);
        if ($seasonId < 1) {
            return $filter;
        }

        $prefix = $websoccer->getConfig('db_prefix');
        $sql = "SELECT MIN(datum) AS date_start, MAX(datum) AS date_end, COUNT(DISTINCT spieltag) AS matchdays
                FROM " . $prefix . "_spiel
                WHERE saison_id = " . $seasonId . " AND datum > 0";
        $row = self::fetchOne($db, $sql);
        if (isset($row['date_start']) && (int) $row['date_start'] > 0) {
            $filter['date_start'] = (int) $row['date_start'];
            $filter['date_end'] = (int) $row['date_end'] + 86399;
            $filter['matchdays'] = max(1, (int) $row['matchdays']);
        }

        $nameSql = "SELECT S.name, L.name AS league_name
                    FROM " . $prefix . "_saison AS S
                    LEFT JOIN " . $prefix . "_liga AS L ON L.id = S.liga_id
                    WHERE S.id = " . $seasonId . " LIMIT 1";
        $season = self::fetchOne($db, $nameSql);
        $filter['label'] = trim((isset($season['name']) ? $season['name'] : ('Saison #' . $seasonId)) . ' ' . (isset($season['league_name']) ? '(' . $season['league_name'] . ')' : ''));
        return $filter;
    }

    private static function dateCondition($column, $filter) {
        if ((int) $filter['date_start'] > 0 && (int) $filter['date_end'] > 0) {
            return ' AND ' . $column . ' BETWEEN ' . (int) $filter['date_start'] . ' AND ' . (int) $filter['date_end'];
        }
        return '';
    }

    private static function teamWhere(WebSoccer $websoccer, DbConnection $db, $scope, $alias) {
        $p = strlen($alias) ? $alias . '.' : '';
        $where = $p . "status = '1'";
        if (self::columnExists($websoccer, $db, 'verein', 'nationalteam')) {
            $where .= " AND " . $p . "nationalteam = '0'";
        }
        if ($scope == 'human') {
            $where .= " AND " . $p . "user_id > 0";
        } elseif ($scope == 'cpu') {
            $where .= " AND (" . $p . "user_id IS NULL OR " . $p . "user_id = 0)";
        }
        return $where;
    }

    private static function fetchOne(DbConnection $db, $sql) {
        $result = $db->executeQuery($sql);
        $row = $result->fetch_array();
        $result->free();
        return $row ? $row : array();
    }

    private static function writeLog(WebSoccer $websoccer, DbConnection $db, $adminId, $actionKey, $actionLabel, $mode, $params, $result) {
        $table = self::table($websoccer, 'finance_regulation_log');
        $now = (int) $websoccer->getNowAsTimestamp();
        $sql = "INSERT INTO `" . $table . "` SET "
            . "created_date = " . $now . ", "
            . "admin_id = " . (int) $adminId . ", "
            . "action_key = '" . self::esc($db, $actionKey) . "', "
            . "action_label = '" . self::esc($db, $actionLabel) . "', "
            . "mode = '" . self::esc($db, $mode) . "', "
            . "parameters_json = '" . self::esc($db, json_encode($params)) . "', "
            . "result_json = '" . self::esc($db, json_encode($result)) . "'";
        $db->executeQuery($sql);
    }

    private static function tableExists(WebSoccer $websoccer, DbConnection $db, $table) {
        static $cache = array();
        $fullTable = self::table($websoccer, $table);
        if (isset($cache[$fullTable])) {
            return $cache[$fullTable];
        }
        $result = $db->executeQuery("SHOW TABLES LIKE '" . self::esc($db, $fullTable) . "'");
        $exists = ($result && $result->num_rows > 0);
        if ($result) {
            $result->free();
        }
        $cache[$fullTable] = $exists;
        return $exists;
    }

    private static function columnExists(WebSoccer $websoccer, DbConnection $db, $table, $column) {
        static $cache = array();
        $fullTable = self::table($websoccer, $table);
        $key = $fullTable . '.' . $column;
        if (isset($cache[$key])) {
            return $cache[$key];
        }
        if (!self::tableExists($websoccer, $db, $table)) {
            $cache[$key] = FALSE;
            return FALSE;
        }
        $result = $db->executeQuery("SHOW COLUMNS FROM `" . $fullTable . "` LIKE '" . self::esc($db, $column) . "'");
        $exists = ($result && $result->num_rows > 0);
        if ($result) {
            $result->free();
        }
        $cache[$key] = $exists;
        return $exists;
    }

    private static function table(WebSoccer $websoccer, $shortName) {
        return preg_replace('/[^a-zA-Z0-9_]/', '', $websoccer->getConfig('db_prefix') . '_' . $shortName);
    }

    private static function quote($identifier) {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    private static function esc(DbConnection $db, $value) {
        return $db->connection->real_escape_string((string) $value);
    }

    private static function sanitizePercent($value) {
        $value = str_replace(',', '.', (string) $value);
        $value = (float) $value;
        if ($value < -90) {
            $value = -90;
        }
        if ($value > 200) {
            $value = 200;
        }
        return $value;
    }

    private static function sanitizeChoice($value, $allowed, $default) {
        return in_array($value, $allowed) ? $value : $default;
    }

    private static function factor($percent) {
        return max(0.1, 1 + ((float) $percent / 100));
    }

    private static function number($value, $decimals = 2) {
        return number_format((float) $value, $decimals, '.', '');
    }

    private static function csvLine($values) {
        $escaped = array();
        foreach ($values as $value) {
            $value = str_replace('"', '""', (string) $value);
            $escaped[] = '"' . $value . '"';
        }
        return implode(';', $escaped);
    }
}
?>
