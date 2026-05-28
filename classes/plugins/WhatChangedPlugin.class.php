<?php
/******************************************************

  This file is part of OpenWebSoccer-Sim.

******************************************************/

/**
 * Creates a compact "What changed?" dashboard after completed matches.
 */
class WhatChangedPlugin {

    /**
     * @param MatchCompletedEvent $event Completed match event.
     */
    public static function handleMatchCompleted(MatchCompletedEvent $event) {
        WhatChangedDataService::processCompletedMatch($event);
    }
}

?>
