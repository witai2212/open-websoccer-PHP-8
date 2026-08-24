<?php
// CM23 Task 1004 | 2026-08-24 | Revision 1
/******************************************************

  This file is part of OpenWebSoccer-Sim.

******************************************************/

/**
 * Handles the manager's private account.
 *
 * Transfers are deliberately not written to the club account statement.
 * They are available only while the currently selected club employs an
 * active shady financial advisor.
 */
class PrivateAccountDataService {

    const MIN_CLUB_RESERVE = 5000000;

    private static $_schemaReady = false;

    public static function ensureSchema(WebSoccer $websoccer, DbConnection $db) {
        if (self::$_schemaReady) {
            return;
        }

        ClubStaffDataService::ensureSchema($websoccer, $db);

        $userTable = $websoccer->getConfig('db_prefix') . '_user';
        $columnResult = $db->executeQuery("SHOW COLUMNS FROM " . $userTable . " LIKE 'konto'");
        $hasAccountColumn = ($columnResult && $columnResult->num_rows > 0);
        if ($columnResult) {
            $columnResult->free();
        }

        if (!$hasAccountColumn) {
            $db->executeQuery(
                "ALTER TABLE " . $userTable
                . " ADD COLUMN konto BIGINT(20) NOT NULL DEFAULT 0 AFTER manager_salary_per_match"
            );
        }

        self::$_schemaReady = true;
    }

    public static function getPageData(WebSoccer $websoccer, DbConnection $db, $teamId, $userId) {
        self::ensureSchema($websoccer, $db);

        $teamId = (int) $teamId;
        $userId = (int) $userId;

        $team = self::getTeamForUser($websoccer, $db, $teamId, $userId);
        if (!$team) {
            throw new Exception('private_account_error_team_not_owned');
        }

        $privateBalance = self::getPrivateBalance($websoccer, $db, $userId);
        $clubBalance = (int) $team['finanz_budget'];
        $hasShadyAdvisor = ClubStaffDataService::hasShadyFinancialAdvisor($websoccer, $db, $teamId);

        return array(
            'private_balance' => $privateBalance,
            'club_balance' => $clubBalance,
            'minimum_club_reserve' => self::MIN_CLUB_RESERVE,
            'max_withdrawal' => max(0, $clubBalance - self::MIN_CLUB_RESERVE),
            'max_deposit' => max(0, $privateBalance),
            'has_shady_advisor' => $hasShadyAdvisor
        );
    }

    public static function transfer(WebSoccer $websoccer, DbConnection $db, $teamId, $userId, $direction, $amount) {
        self::ensureSchema($websoccer, $db);

        $teamId = (int) $teamId;
        $userId = (int) $userId;
        $amount = (int) $amount;
        $direction = (string) $direction;

        if ($teamId < 1 || $userId < 1) {
            throw new Exception('private_account_error_team_not_owned');
        }
        if ($amount < 1) {
            throw new Exception('private_account_error_invalid_amount');
        }
        if ($direction !== 'club_to_private' && $direction !== 'private_to_club') {
            throw new Exception('private_account_error_invalid_direction');
        }
        if (!ClubStaffDataService::hasShadyFinancialAdvisor($websoccer, $db, $teamId)) {
            throw new Exception('private_account_error_no_shady_advisor');
        }

        $prefix = $websoccer->getConfig('db_prefix');
        $teamTable = $prefix . '_verein';
        $userTable = $prefix . '_user';

        $db->executeQuery('START TRANSACTION');

        try {
            $teamResult = $db->executeQuery(
                "SELECT id, user_id, finanz_budget FROM " . $teamTable
                . " WHERE id = " . $teamId . " FOR UPDATE"
            );
            $team = $teamResult->fetch_array();
            $teamResult->free();

            if (!$team || (int) $team['user_id'] !== $userId) {
                throw new Exception('private_account_error_team_not_owned');
            }

            $userResult = $db->executeQuery(
                "SELECT id, konto FROM " . $userTable
                . " WHERE id = " . $userId . " FOR UPDATE"
            );
            $user = $userResult->fetch_array();
            $userResult->free();

            if (!$user) {
                throw new Exception('private_account_error_user_not_found');
            }

            $clubBalance = (int) $team['finanz_budget'];
            $privateBalance = (int) $user['konto'];

            if ($direction === 'club_to_private') {
                if (($clubBalance - $amount) < self::MIN_CLUB_RESERVE) {
                    throw new Exception('private_account_error_min_reserve');
                }

                $clubBalance -= $amount;
                $privateBalance += $amount;
            } else {
                if ($privateBalance < $amount) {
                    throw new Exception('private_account_error_insufficient_private_funds');
                }

                $privateBalance -= $amount;
                $clubBalance += $amount;
            }

            $db->queryUpdate(
                array('finanz_budget' => $clubBalance),
                $teamTable,
                'id = %d AND user_id = %d',
                array($teamId, $userId)
            );
            $db->queryUpdate(
                array('konto' => $privateBalance),
                $userTable,
                'id = %d',
                $userId
            );

            $db->executeQuery('COMMIT');
        } catch (Exception $e) {
            $db->executeQuery('ROLLBACK');
            throw $e;
        }

        return true;
    }

    private static function getTeamForUser(WebSoccer $websoccer, DbConnection $db, $teamId, $userId) {
        $result = $db->querySelect(
            'id, user_id, finanz_budget',
            $websoccer->getConfig('db_prefix') . '_verein',
            'id = %d AND user_id = %d',
            array((int) $teamId, (int) $userId),
            1
        );
        $team = $result->fetch_array();
        $result->free();

        return $team ? $team : array();
    }

    private static function getPrivateBalance(WebSoccer $websoccer, DbConnection $db, $userId) {
        $result = $db->querySelect(
            'konto',
            $websoccer->getConfig('db_prefix') . '_user',
            'id = %d',
            (int) $userId,
            1
        );
        $user = $result->fetch_array();
        $result->free();

        if (!$user) {
            throw new Exception('private_account_error_user_not_found');
        }

        return isset($user['konto']) ? (int) $user['konto'] : 0;
    }
}
?>