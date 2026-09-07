-- CM23 | 2026-09-07 | Revision 1 | Task 1022
-- Run once before deploying the Task 1022 PHP/JS files.

CREATE TABLE IF NOT EXISTS `cm23_spieler_marktwert_historie` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `spieler_id` INT NOT NULL,
  `snapshot_date` DATE NOT NULL,
  `marktwert` BIGINT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_spieler_marktwert_tag` (`spieler_id`, `snapshot_date`),
  KEY `idx_spieler_marktwert_datum` (`spieler_id`, `snapshot_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- Initial value = current market value at installation time.
-- No historical values before Task 1022 are reconstructed.
INSERT INTO `cm23_spieler_marktwert_historie` (`spieler_id`, `snapshot_date`, `marktwert`)
SELECT `id`, CURDATE(), `marktwert`
FROM `cm23_spieler`
ON DUPLICATE KEY UPDATE `marktwert` = VALUES(`marktwert`);
