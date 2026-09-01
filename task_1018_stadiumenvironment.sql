-- CM23 | 2026-09-01 | Revision 1
-- Korrigiert die fehlerhafte Selbstreferenz des Gebäudes 14.
UPDATE `cm23_stadiumbuilding`
SET `required_building_id` = NULL
WHERE `id` = 14
  AND `required_building_id` = 14;
