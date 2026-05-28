<?php
/******************************************************

  This file is part of OpenWebSoccer-Sim.

  OpenWebSoccer-Sim is free software: you can redistribute it 
  and/or modify it under the terms of the 
  GNU Lesser General Public License as published by the Free Software Foundation,
  either version 3 of the License, or any later version.

******************************************************/

/**
 * Event listener for fan mood and media pressure.
 */
class FanPressurePlugin {

    /**
     * Applies attendance effect right after the base ticket sales rates were computed.
     *
     * @param TicketsComputedEvent $event Event.
     */
    public static function adjustAttendance(TicketsComputedEvent $event) {
        FanPressureDataService::applyAttendanceEffect($event);
    }

    /**
     * Applies fan/media changes after official match completion.
     *
     * @param MatchCompletedEvent $event Event.
     */
    public static function handleMatchCompleted(MatchCompletedEvent $event) {
        FanPressureDataService::processCompletedMatch($event);
    }
}

?>
