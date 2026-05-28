<?php
/******************************************************

  This file is part of OpenWebSoccer-Sim.

******************************************************/

/**
 * Event bridge for medical center logging and risky clearances.
 */
class MedicalCenterPlugin {

    public static function handleMatchCompleted(MatchCompletedEvent $event) {
        if (!MedicalCenterDataService::isEnabled($event->websoccer)) {
            return;
        }
        MedicalCenterDataService::logMatchInjuries($event);
        MedicalCenterDataService::processRiskClearancesAfterMatch($event);
    }
}
?>
