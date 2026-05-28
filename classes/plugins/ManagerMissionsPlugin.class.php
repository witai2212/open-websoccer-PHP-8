<?php
/******************************************************

  This file is part of OpenWebSoccer-Sim.

  OpenWebSoccer-Sim is free software: you can redistribute it
  and/or modify it under the terms of the
  GNU Lesser General Public License as published by the Free Software Foundation,
  either version 3 of the License, or any later version.

******************************************************/

/**
 * Keeps manager missions up to date after match simulations.
 */
class ManagerMissionsPlugin {

    /**
     * Updates missions for both clubs after a completed match.
     * Friendly matches are harmless: only missions with changed live values will complete.
     */
    public static function handleMatchCompleted(MatchCompletedEvent $event) {
        if (!ManagerMissionsDataService::isEnabled($event->websoccer)) {
            return;
        }

        $teamIds = array();
        if ($event->match && $event->match->homeTeam && (int) $event->match->homeTeam->id > 0) {
            $teamIds[] = (int) $event->match->homeTeam->id;
        }
        if ($event->match && $event->match->guestTeam && (int) $event->match->guestTeam->id > 0) {
            $teamIds[] = (int) $event->match->guestTeam->id;
        }

        $teamIds = array_unique($teamIds);
        foreach ($teamIds as $teamId) {
            $userId = ManagerMissionsDataService::getTeamManagerUserId($event->websoccer, $event->db, $teamId);
            if ($userId < 1) {
                continue;
            }

            ManagerMissionsDataService::updateMissionsForCurrentSeason(
                $event->websoccer,
                $event->db,
                $event->i18n,
                $userId,
                $teamId
            );
        }
    }
}

?>
