<?php
/******************************************************

  This file is part of OpenWebSoccer-Sim.

******************************************************/

/**
 * Central handling of the calendar-day transfer blockade for players who
 * complete a permanent transfer. Loans do not call this service and therefore
 * remain possible during the blockade.
 */
class TransferBlockadeDataService {

    const DEFAULT_WINDOW_DAYS = 30;

    /**
     * Returns the configured blockade duration in calendar days.
     */
    public static function getWindowDays(WebSoccer $websoccer) {
        try {
            $days = (int) $websoccer->getConfig('min_transfer_window');
        } catch (Exception $e) {
            $days = self::DEFAULT_WINDOW_DAYS;
        }

        return max(0, $days);
    }

    /**
     * Returns the UNIX timestamp until which the player is blocked.
     */
    public static function getBlockedUntil(WebSoccer $websoccer, DbConnection $db, $playerId) {
        $result = $db->querySelect(
            'transfer_blocked_until',
            $websoccer->getConfig('db_prefix') . '_spieler',
            'id = %d',
            (int) $playerId,
            1
        );
        $player = $result->fetch_array();
        $result->free();

        return $player ? (int) $player['transfer_blocked_until'] : 0;
    }

    /**
     * Checks whether the player is currently blocked from another permanent
     * transfer. Existing rows remain unaffected because the new DB field starts
     * with zero and is populated only by future completed transfers.
     */
    public static function isBlocked(WebSoccer $websoccer, DbConnection $db, $playerId) {
        return self::getBlockedUntil($websoccer, $db, $playerId) > $websoccer->getNowAsTimestamp();
    }

    /**
     * Returns the remaining calendar days, rounded up.
     */
    public static function getRemainingDays(WebSoccer $websoccer, DbConnection $db, $playerId) {
        $remainingSeconds = self::getBlockedUntil($websoccer, $db, $playerId) - $websoccer->getNowAsTimestamp();
        if ($remainingSeconds <= 0) {
            return 0;
        }

        return (int) ceil($remainingSeconds / 86400);
    }

    /**
     * Throws a translated exception when a new sale, listing or offer is not
     * allowed. Loan actions intentionally do not use this assertion.
     */
    public static function assertPermanentTransferAllowed(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $playerId) {
        $remainingDays = self::getRemainingDays($websoccer, $db, $playerId);
        if ($remainingDays > 0) {
            throw new Exception($i18n->getMessage('transfer_blockade_active', $remainingDays));
        }
    }

    /**
     * Adds the timestamps required after a completed permanent transfer.
     */
    public static function addCompletedTransferColumns(WebSoccer $websoccer, &$columns) {
        $now = $websoccer->getNowAsTimestamp();
        $days = self::getWindowDays($websoccer);

        $columns['last_transfer'] = $now;
        $columns['transfer_blocked_until'] = ($days > 0) ? $now + ($days * 86400) : 0;
    }
}

?>
