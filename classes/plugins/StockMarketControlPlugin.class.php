<?php
/******************************************************

  This file is part of OpenWebSoccer-Sim.

******************************************************/

/**
 * Keeps majority-owner board control active after match processing.
 */
class StockMarketControlPlugin {

    public static function handleMatchCompleted(MatchCompletedEvent $event) {
        if ($event->match && isset($event->match->type) && $event->match->type === 'Youth') {
            return;
        }

        StockMarketDataService::applyMajorityBoardControl($event->websoccer, $event->db);
    }

    public static function handleSeasonCompleted(SeasonOfTeamCompletedEvent $event) {
        StockMarketDataService::processSeasonDividends($event->websoccer, $event->db, $event->i18n, $event->teamId, $event->seasonId);
        StockMarketDataService::applyMajorityBoardControl($event->websoccer, $event->db);
    }
}
?>
