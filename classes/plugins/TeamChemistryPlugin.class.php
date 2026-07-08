<?php
/******************************************************

  This file is part of OpenWebSoccer-Sim.

  OpenWebSoccer-Sim is free software: you can redistribute it 
  and/or modify it under the terms of the 
  GNU Lesser General Public License as published by the Free Software Foundation,
  either version 3 of the License, or any later version.

******************************************************/

/**
 * Event listener for team chemistry refreshes.
 */
class TeamChemistryPlugin {

    /**
     * Refresh chemistry when a match is completed.
     *
     * @param MatchCompletedEvent $event Event.
     */
    public static function handleMatchCompleted(MatchCompletedEvent $event) {
        if (class_exists('TacticalStyleDataService')) {
            TacticalStyleDataService::processCompletedMatch($event);
        }
        TeamChemistryDataService::processCompletedMatch($event);
    }
}

?>
