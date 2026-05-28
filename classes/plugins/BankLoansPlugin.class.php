<?php
/******************************************************

  This file is part of OpenWebSoccer-Sim.

******************************************************/

/**
 * Processes automatic loan installments after completed matches.
 */
class BankLoansPlugin {

    public static function handleMatchCompleted(MatchCompletedEvent $event) {
        if (!BankLoansDataService::isEnabled($event->websoccer)) {
            return;
        }

        $matchId = ($event->match && isset($event->match->id)) ? (int) $event->match->id : 0;
        if ($matchId < 1) {
            return;
        }

        if (isset($event->match->type) && $event->match->type === 'Youth') {
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
            BankLoansDataService::processMatchRepayments($event->websoccer, $event->db, $event->i18n, $teamId, $matchId);
        }
    }
}
?>
