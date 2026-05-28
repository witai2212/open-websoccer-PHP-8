<?php
/******************************************************

  This file is part of OpenWebSoccer-Sim.

******************************************************/

class ScoutingPlugin {

    public static function handleMatchCompleted(MatchCompletedEvent $event) {
        ScoutingDataService::processMatchCompletedScouting($event);
    }
}
?>