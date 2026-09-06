-- CM23 | 2026-09-06 | Revision 1 | Task 1020
-- Defensive data repair for invalid building dependencies.
-- A building must never require itself, otherwise it and its successors remain locked.

UPDATE `cm23_stadiumbuilding`
SET `required_building_id` = NULL
WHERE `required_building_id` = `id`;
