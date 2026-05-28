<?php
/******************************************************

  This file is part of OpenWebSoccer-Sim.

  OpenWebSoccer-Sim is free software: you can redistribute it
  and/or modify it under the terms of the
  GNU Lesser General Public License as published by the Free Software Foundation,
  either version 3 of the License, or any later version.

******************************************************/

/**
 * Applies temporary effects of unresolved random event chains.
 */
class RandomEventChainsPlugin {

    /**
     * Applies temporary attendance penalties from unresolved stadium damage choices.
     *
     * @param TicketsComputedEvent $event Event.
     */
    public static function applyStadiumDamageAttendancePenalty(TicketsComputedEvent $event) {
        $penalty = RandomEventsDataService::getOpenStadiumDamageAttendancePenalty(
            $event->websoccer,
            $event->db,
            $event->match->homeTeam->id
        );

        if ($penalty >= 0) {
            return;
        }

        $change = $penalty / 100;
        $event->rateSeats = max(0.0, min(1.0, $event->rateSeats + $change));
        $event->rateStands = max(0.0, min(1.0, $event->rateStands + $change));
        $event->rateSeatsGrand = max(0.0, min(1.0, $event->rateSeatsGrand + $change));
        $event->rateStandsGrand = max(0.0, min(1.0, $event->rateStandsGrand + $change));
        $event->rateVip = max(0.0, min(1.0, $event->rateVip + $change));
    }
}

?>
