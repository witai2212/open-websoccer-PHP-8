-- CM23 Task 1004 | 2026-08-24 | Revision 1
-- Private manager account and shady financial advisor support.
-- Existing financial advisors remain non-shady until the admin explicitly marks them.

START TRANSACTION;

SET @cm23_user_exists = (
    SELECT COUNT(*)
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'cm23_user'
);

SET @cm23_user_konto_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'cm23_user'
      AND COLUMN_NAME = 'konto'
);

SET @cm23_user_konto_sql = IF(
    @cm23_user_exists = 1 AND @cm23_user_konto_exists = 0,
    'ALTER TABLE `cm23_user` ADD COLUMN `konto` BIGINT(20) NOT NULL DEFAULT 0 AFTER `manager_salary_per_match`',
    'SELECT 1'
);

PREPARE cm23_stmt FROM @cm23_user_konto_sql;
EXECUTE cm23_stmt;
DEALLOCATE PREPARE cm23_stmt;

SET @cm23_staff_exists = (
    SELECT COUNT(*)
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'cm23_club_staff'
);

SET @cm23_staff_shady_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'cm23_club_staff'
      AND COLUMN_NAME = 'shady'
);

SET @cm23_staff_shady_sql = IF(
    @cm23_staff_exists = 1 AND @cm23_staff_shady_exists = 0,
    'ALTER TABLE `cm23_club_staff` ADD COLUMN `shady` ENUM(''1'',''0'') NOT NULL DEFAULT ''0'' AFTER `description`',
    'SELECT 1'
);

PREPARE cm23_stmt FROM @cm23_staff_shady_sql;
EXECUTE cm23_stmt;
DEALLOCATE PREPARE cm23_stmt;

COMMIT;
