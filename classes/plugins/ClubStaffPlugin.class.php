<?php
/******************************************************

  This file is part of OpenWebSoccer-Sim.

******************************************************/

/**
 * Event bridge for lightweight club staff effects.
 */
class ClubStaffPlugin {

    public static function handlePlayerTrained(PlayerTrainedEvent $event) {
        ClubStaffDataService::applyTrainingEventBonuses($event);
    }

    public static function handleYouthPlayerPlayed(YouthPlayerPlayedEvent $event) {
        ClubStaffDataService::applyYouthPlayerPlayedBonus($event);
    }

    public static function handleYouthPlayerScouted(YouthPlayerScoutedEvent $event) {
        ClubStaffDataService::applyYouthPlayerScoutedBonus($event);
    }

    public static function handleTicketsComputed(TicketsComputedEvent $event) {
        if (!$event->match || !$event->match->homeTeam || $event->match->homeTeam->isNationalTeam) {
            return;
        }
        $teamId = (int) $event->match->homeTeam->id;
        $factor = ClubStaffDataService::getMarketingAttendanceFactor($event->websoccer, $event->db, $teamId);
        if ($factor <= 1) {
            return;
        }
        $event->rateStands = min(1, max(0, $event->rateStands * $factor));
        $event->rateSeats = min(1, max(0, $event->rateSeats * $factor));
        $event->rateStandsGrand = min(1, max(0, $event->rateStandsGrand * $factor));
        $event->rateSeatsGrand = min(1, max(0, $event->rateSeatsGrand * $factor));
        $event->rateVip = min(1, max(0, $event->rateVip * $factor));
    }

    public static function handleMatchCompleted(MatchCompletedEvent $event) {
        ClubStaffDataService::processMatchCompletedSalaries($event);
        ClubStaffDataService::applyMatchCompletedRecovery($event);
    }
}
?>
