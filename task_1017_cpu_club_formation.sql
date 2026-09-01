-- CM23 | 2026-09-01 | Revision 1 | Task 1017
-- Phase 1: feste Standardformation pro Verein; Transferphilosophie ist nicht Bestandteil dieses Schritts.

ALTER TABLE `cm23_verein`
	ADD COLUMN `formation` varchar(16) DEFAULT NULL AFTER `nationalteam`;

UPDATE `cm23_verein`
SET `formation` = CASE FLOOR(RAND() * 6)
	WHEN 0 THEN '4-0-4-0-2-0'
	WHEN 1 THEN '4-0-3-0-3-0'
	WHEN 2 THEN '3-0-4-0-3-0'
	WHEN 3 THEN '3-0-5-0-2-0'
	WHEN 4 THEN '5-0-3-0-2-0'
	ELSE '4-0-5-0-1-0'
END
WHERE (`user_id` IS NULL OR `user_id` = 0)
	AND `nationalteam` = '0'
	AND (`formation` IS NULL OR `formation` = '');
