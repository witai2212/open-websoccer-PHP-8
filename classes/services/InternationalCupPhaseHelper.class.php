<?php
/******************************************************

This file is part of OpenWebSoccer-Sim.

******************************************************/

/**
 * Resolves the phase which should be shown when opening an international cup.
 *
 * An explicitly requested phase always wins. Otherwise the first round with
 * an open match is selected. Once all matches are completed, the last round
 * of the competition is shown.
 */
class InternationalCupPhaseHelper {

    public static function selectCurrentPhase(WebSoccer $websoccer, DbConnection $db, $cupName) {
        if ($websoccer->getRequestParameter('phase') !== null) {
            return;
        }

        $cup = EuropeanCupDataService::getCupByName($websoccer, $db, $cupName);
        if (!$cup) {
            return;
        }

        $rounds = EuropeanCupDataService::getCupRounds($websoccer, $db, (int) $cup['id']);
        if (!count($rounds)) {
            return;
        }

        $groupRound = EuropeanCupDataService::getGroupRound($websoccer, $db, (int) $cup['id']);
        $openMatch = self::getFirstOpenMatch($websoccer, $db, $cupName);

        if ($openMatch) {
            $roundName = isset($openMatch['round_name']) ? (string) $openMatch['round_name'] : '';

            if ($groupRound && $roundName === (string) $groupRound['name']) {
                $groupName = self::resolveGroupName(
                    $websoccer,
                    $db,
                    (int) $groupRound['id'],
                    isset($openMatch['group_name']) ? (string) $openMatch['group_name'] : ''
                );

                if ($groupName !== '') {
                    $_REQUEST['phase'] = $groupName;
                }
                return;
            }

            foreach ($rounds as $round) {
                if ((string) $round['name'] === $roundName) {
                    $_REQUEST['phase'] = 'r' . (int) $round['id'];
                    return;
                }
            }
        }

        // No open match remains: show the last configured round, normally the final.
        $lastRound = $rounds[count($rounds) - 1];

        if ($groupRound && (int) $lastRound['id'] === (int) $groupRound['id']) {
            $groupName = self::resolveGroupName($websoccer, $db, (int) $groupRound['id'], '');
            if ($groupName !== '') {
                $_REQUEST['phase'] = $groupName;
            }
            return;
        }

        $_REQUEST['phase'] = 'r' . (int) $lastRound['id'];
    }

    private static function getFirstOpenMatch(WebSoccer $websoccer, DbConnection $db, $cupName) {
        $columns = array();
        $columns['M.pokalrunde'] = 'round_name';
        $columns['M.pokalgruppe'] = 'group_name';

        $result = $db->querySelect(
            $columns,
            $websoccer->getConfig('db_prefix') . '_spiel AS M',
            "M.pokalname = '%s' AND M.berechnet != '1' ORDER BY M.datum ASC, M.id ASC",
            (string) $cupName,
            1
        );

        $match = $result->fetch_array();
        $result->free();

        return $match ? $match : null;
    }

    private static function resolveGroupName(WebSoccer $websoccer, DbConnection $db, $groupRoundId, $fallbackGroupName) {
        $user = $websoccer->getUser();
        if ($user && $user->id != null) {
            $teamId = (int) $user->getClubId($websoccer, $db);
            if ($teamId > 0) {
                $result = $db->querySelect(
                    'G.name',
                    $websoccer->getConfig('db_prefix') . '_cup_round_group AS G',
                    'G.cup_round_id = %d AND G.team_id = %d',
                    array((int) $groupRoundId, $teamId),
                    1
                );
                $group = $result->fetch_array();
                $result->free();

                if ($group && strlen((string) $group['name'])) {
                    return (string) $group['name'];
                }
            }
        }

        if (strlen($fallbackGroupName)) {
            return $fallbackGroupName;
        }

        $groups = EuropeanCupDataService::getGroupNames($websoccer, $db, (int) $groupRoundId);
        return count($groups) ? (string) $groups[0] : '';
    }
}
?>
