-- Revision 2 - 2026-08-24
-- Task 1000: Scout-Kompetenz nur fuer den User sichtbar, der den Scout eingestellt hat.
--
-- Diese Revision ersetzt die globale expertise_known-Loesung aus Revision 1.
-- Bestehende aktive Altvertraege werden NICHT einem User zugeordnet, weil aus dem
-- aktuellen Scout-Datensatz nicht verlaesslich hervorgeht, welcher User den Vertrag
-- urspruenglich abgeschlossen hat. Die Zuordnung erfolgt fuer alle neuen Vertraege
-- ab Einspielen dieser Revision korrekt beim Einstellen.

DROP TRIGGER IF EXISTS `cm23_scout_reveal_expertise_before_update`;

CREATE TABLE IF NOT EXISTS `cm23_scout_expertise_knowledge` (
    `id` INT(10) NOT NULL AUTO_INCREMENT,
    `user_id` INT(10) NOT NULL,
    `scout_id` INT(10) NOT NULL,
    `revealed_date` INT(11) NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_scout_expertise_knowledge_user_scout` (`user_id`, `scout_id`),
    KEY `idx_scout_expertise_knowledge_scout` (`scout_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

SET @column_exists := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'cm23_scout'
      AND COLUMN_NAME = 'hired_by_user_id'
);
SET @sql := IF(
    @column_exists = 0,
    'ALTER TABLE `cm23_scout` ADD COLUMN `hired_by_user_id` INT(10) NOT NULL DEFAULT 0 AFTER `team_matches`',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @old_column_exists := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'cm23_scout'
      AND COLUMN_NAME = 'expertise_known'
);
SET @sql := IF(
    @old_column_exists > 0,
    'ALTER TABLE `cm23_scout` DROP COLUMN `expertise_known`',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
