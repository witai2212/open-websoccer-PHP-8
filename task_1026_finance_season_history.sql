-- CM23 | 2026-09-09 | Revision 1 | Task 1026
-- Saison-Finanzhistorie: vor dem Leeren von cm23_konto ausführen.
-- Das PHP-Feature legt die Tabelle zusätzlich defensiv per CREATE TABLE IF NOT EXISTS an.

CREATE TABLE IF NOT EXISTS `cm23_finance_season_history` (
  `id` int(10) NOT NULL AUTO_INCREMENT,
  `verein_id` int(10) NOT NULL,
  `season_id` int(10) NOT NULL DEFAULT 0,
  `season_name` varchar(100) NOT NULL DEFAULT '',
  `league_id` int(10) NOT NULL DEFAULT 0,
  `league_name` varchar(100) NOT NULL DEFAULT '',
  `country` varchar(50) NOT NULL DEFAULT '',
  `budget_start` bigint(20) NOT NULL DEFAULT 0,
  `total_income` bigint(20) NOT NULL DEFAULT 0,
  `total_expenses` bigint(20) NOT NULL DEFAULT 0,
  `profit_loss` bigint(20) NOT NULL DEFAULT 0,
  `budget_end` bigint(20) NOT NULL DEFAULT 0,
  `transactions_count` int(10) NOT NULL DEFAULT 0,
  `breakdown_json` mediumtext DEFAULT NULL,
  `archived_at` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_finance_season_team` (`verein_id`,`season_id`),
  KEY `idx_finance_season` (`season_id`),
  KEY `idx_finance_team` (`verein_id`),
  KEY `idx_finance_archived` (`archived_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
