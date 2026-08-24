-- Revision 1 - 2026-08-24
-- Task 1000: Scout-Kompetenz nach dem ersten vollständig abgelaufenen Vertrag dauerhaft sichtbar.

ALTER TABLE `cm23_scout`
    ADD COLUMN `expertise_known` TINYINT(1) NOT NULL DEFAULT 0 AFTER `expertise`;

-- Bereits beschäftigte Scouts, deren Vertrag aktuell auf 0 steht, haben nachweislich
-- mindestens einen Vereinsvertrag vollständig erreicht. Freie Scouts mit 0 Spielen
-- werden bewusst nicht rückwirkend als bekannt markiert.
UPDATE `cm23_scout`
SET `expertise_known` = 1
WHERE `team_id` > 0
  AND `team_matches` <= 0;

DROP TRIGGER IF EXISTS `cm23_scout_reveal_expertise_before_update`;

DELIMITER //
CREATE TRIGGER `cm23_scout_reveal_expertise_before_update`
BEFORE UPDATE ON `cm23_scout`
FOR EACH ROW
BEGIN
    IF OLD.team_id > 0
       AND OLD.team_matches > 0
       AND NEW.team_matches <= 0 THEN
        SET NEW.expertise_known = 1;
    END IF;
END//
DELIMITER ;
