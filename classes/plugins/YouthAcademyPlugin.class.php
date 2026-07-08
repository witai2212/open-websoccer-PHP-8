<?php
/******************************************************

  This file is part of OpenWebSoccer-Sim.

******************************************************/

/**
 * Event bridge for Youth Academy 2.0.
 */
class YouthAcademyPlugin {

    public static function handleYouthPlayerPlayed(YouthPlayerPlayedEvent $event) {
        YouthAcademyDataService::applyYouthPlayerPlayedBonus($event);
    }

    public static function handleYouthPlayerScouted(YouthPlayerScoutedEvent $event) {
        YouthAcademyDataService::applyYouthPlayerScoutedBonus($event);
    }

    public static function handleMatchCompleted(MatchCompletedEvent $event) {
        YouthAcademyDataService::processMatchCompletedAcademy($event);
    }
}
?>
