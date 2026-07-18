<?php
/******************************************************

  This file is part of OpenWebSoccer-Sim.

******************************************************/

/**
 * Processes stadium and online merchandising after competitive matches.
 */
class MerchandisingPlugin {
    public static function processCompletedMatch(MatchCompletedEvent $event) {
        MerchandisingDataService::processCompletedMatch($event);
    }
}
?>
