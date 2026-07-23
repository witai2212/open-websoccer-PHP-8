<?php
/******************************************************

  This file is part of OpenWebSoccer-Sim.

  OpenWebSoccer-Sim is free software: you can redistribute it
  and/or modify it under the terms of the
  GNU Lesser General Public License
  as published by the Free Software Foundation, either version 3 of
  the License, or any later version.

******************************************************/

/**
 * Shared data service for European cup overview pages.
 */
class EuropeanCupDataService {

    /**
     * Builds all data required by the Champions League / UEFA Euro League overview pages.
     *
     * @param WebSoccer $websoccer Application context.
     * @param DbConnection $db Database connection.
     * @param string $cupName Exact value from _cup.name / _spiel.pokalname.
     * @param string $pageId Frontend page id, e.g. championsleague.
     * @param string $messagePrefix Message prefix used by the Twig template.
     * @return array Template parameters.
     */
    public static function getCupOverview(
        WebSoccer $websoccer,
        DbConnection $db,
        $cupName,
        $pageId,
        $messagePrefix
    ) {
        $cup = self::getCupByName($websoccer, $db, $cupName);

        if (!$cup) {
            return array(
                'cup' => array(),
                'cup_found' => false,
                'page_id' => $pageId,
                'message_prefix' => $messagePrefix,
                'selected_phase' => '',
                'selected_phase_label' => '',
                'phases' => array(),
                'group_table' => array(),
                'matches' => array(),
                'latest_results' => array(),
                'next_matches' => array(),
                'round_stats' => array('total' => 0, 'played' => 0, 'open' => 0, 'live' => 0),
                'my_team' => array(),
                'participant_count' => 0,
                'round_count' => 0,
                'is_group_phase' => false,
                'user_team_id' => 0
            );
        }

        $rounds = self::getCupRounds($websoccer, $db, (int) $cup['id']);
        $groupRound = self::getGroupRound($websoccer, $db, (int) $cup['id']);
        $groupNames = $groupRound ? self::getGroupNames($websoccer, $db, (int) $groupRound['id']) : array();

        $userTeamId = self::getUserTeamId($websoccer, $db);
        $myTeam = self::getMyTeamInfo($websoccer, $db, $cupName, $userTeamId, $groupRound);

        $phases = self::buildPhaseList($rounds, $groupRound, $groupNames);
        $selected = self::resolveSelectedPhase(
            $websoccer->getRequestParameter('phase'),
            $phases,
            $myTeam
        );

        $matches = array();
        $groupTable = array();
        $isGroupPhase = false;

        if ($selected && $selected['type'] === 'group') {
            $isGroupPhase = true;
            $groupTable = self::getGroupTable(
                $websoccer,
                $db,
                (int) $selected['round_id'],
                $selected['group_name']
            );
            $matches = MatchesDataService::getMatchesByCondition(
                $websoccer,
                $db,
                "M.pokalname = '%s' AND M.pokalrunde = '%s' AND M.pokalgruppe = '%s' ORDER BY M.datum ASC",
                array($cupName, $selected['round_name'], $selected['group_name']),
                200
            );
        } elseif ($selected) {
            $matches = MatchesDataService::getMatchesByCondition(
                $websoccer,
                $db,
                "M.pokalname = '%s' AND M.pokalrunde = '%s' ORDER BY M.datum ASC",
                array($cupName, $selected['round_name']),
                200
            );
        }

        return array(
            'cup' => $cup,
            'cup_found' => true,
            'page_id' => $pageId,
            'message_prefix' => $messagePrefix,
            'selected_phase' => $selected ? $selected['key'] : '',
            'selected_phase_label' => $selected ? $selected['label'] : '',
            'phases' => $phases,
            'group_table' => $groupTable,
            'matches' => $matches,
            'match_date_groups' => MatchGroupingDataService::groupByDate($matches, $websoccer->getNowAsTimestamp()),
            'latest_results' => self::getLatestResults($websoccer, $db, $cupName, 5),
            'next_matches' => self::getNextMatches($websoccer, $db, $cupName, 5),
            'round_stats' => self::getMatchStats($matches),
            'my_team' => $myTeam,
            'participant_count' => self::getParticipantCount($websoccer, $db, (int) $cup['id'], $cupName),
            'round_count' => count($rounds),
            'is_group_phase' => $isGroupPhase,
            'user_team_id' => $userTeamId
        );
    }

    public static function getCupByName(WebSoccer $websoccer, DbConnection $db, $cupName) {
        $prefix = $websoccer->getConfig('db_prefix');

        $fromTable  = $prefix . '_cup AS C';
        $fromTable .= ' LEFT JOIN ' . $prefix . '_verein AS W ON W.id = C.winner_id';

        $columns = array();
        $columns['C.id'] = 'id';
        $columns['C.name'] = 'name';
        $columns['C.logo'] = 'logo';
        $columns['C.winner_id'] = 'winner_id';
        $columns['C.winner_award'] = 'winner_award';
        $columns['C.second_award'] = 'second_award';
        $columns['C.perround_award'] = 'perround_award';
        $columns['C.archived'] = 'archived';
        $columns['W.name'] = 'winner_name';
        $columns['W.bild'] = 'winner_picture';

        $result = $db->querySelect(
            $columns,
            $fromTable,
            "C.name = '%s'",
            (string) $cupName,
            1
        );

        $cup = $result->fetch_array();
        $result->free();

        return $cup ? $cup : null;
    }

    public static function getCupRounds(WebSoccer $websoccer, DbConnection $db, $cupId) {
        $prefix = $websoccer->getConfig('db_prefix');

        $columns = array();
        $columns['R.id'] = 'id';
        $columns['R.name'] = 'name';
        $columns['R.firstround_date'] = 'firstround_date';
        $columns['R.secondround_date'] = 'secondround_date';
        $columns['R.finalround'] = 'finalround';
        $columns['R.groupmatches'] = 'groupmatches';

        $result = $db->querySelect(
            $columns,
            $prefix . '_cup_round AS R',
            'R.cup_id = %d ORDER BY R.firstround_date ASC, R.id ASC',
            (int) $cupId
        );

        $rounds = array();
        while ($round = $result->fetch_array()) {
            $rounds[] = $round;
        }
        $result->free();

        return $rounds;
    }

    public static function getGroupRound(WebSoccer $websoccer, DbConnection $db, $cupId) {
        $prefix = $websoccer->getConfig('db_prefix');

        $columns = array();
        $columns['R.id'] = 'id';
        $columns['R.name'] = 'name';
        $columns['R.firstround_date'] = 'firstround_date';
        $columns['R.secondround_date'] = 'secondround_date';
        $columns['R.finalround'] = 'finalround';
        $columns['R.groupmatches'] = 'groupmatches';

        $result = $db->querySelect(
            $columns,
            $prefix . '_cup_round AS R',
            "R.cup_id = %d AND R.groupmatches = '1' ORDER BY R.firstround_date ASC, R.id ASC",
            (int) $cupId,
            1
        );
        $round = $result->fetch_array();
        $result->free();

        if ($round) {
            return $round;
        }

        $result = $db->querySelect(
            $columns,
            $prefix . '_cup_round AS R',
            "R.cup_id = %d AND R.name = 'Gruppen' ORDER BY R.firstround_date ASC, R.id ASC",
            (int) $cupId,
            1
        );
        $round = $result->fetch_array();
        $result->free();

        return $round ? $round : null;
    }

    public static function getGroupNames(WebSoccer $websoccer, DbConnection $db, $roundId) {
        $prefix = $websoccer->getConfig('db_prefix');

        $result = $db->querySelect(
            'G.name',
            $prefix . '_cup_round_group AS G',
            'G.cup_round_id = %d GROUP BY G.name ORDER BY G.name ASC',
            (int) $roundId
        );

        $groups = array();
        while ($group = $result->fetch_array()) {
            $groups[] = $group['name'];
        }
        $result->free();

        return $groups;
    }

    public static function getGroupTable(WebSoccer $websoccer, DbConnection $db, $roundId, $groupName) {
        $prefix = $websoccer->getConfig('db_prefix');

        $fromTable  = $prefix . '_cup_round_group AS G';
        $fromTable .= ' INNER JOIN ' . $prefix . '_verein AS T ON T.id = G.team_id';
        $fromTable .= ' LEFT JOIN ' . $prefix . '_liga AS L ON L.id = T.liga_id';
        $fromTable .= ' LEFT JOIN ' . $prefix . '_user AS U ON U.id = T.user_id';

        $columns = array();
        $columns['T.id'] = 'id';
        $columns['T.name'] = 'name';
        $columns['T.bild'] = 'picture';
        $columns['T.user_id'] = 'user_id';
        $columns['U.nick'] = 'user_name';
        $columns['L.land'] = 'land';
        $columns['G.tab_points'] = 'points';
        $columns['G.tab_goals'] = 'goals';
        $columns['G.tab_goalsreceived'] = 'goals_received';
        $columns['(G.tab_goals - G.tab_goalsreceived)'] = 'goal_diff';
        $columns['G.tab_wins'] = 'wins';
        $columns['G.tab_draws'] = 'draws';
        $columns['G.tab_losses'] = 'losses';

        $where  = "G.cup_round_id = %d AND G.name = '%s' ";
        $where .= 'ORDER BY G.tab_points DESC, ';
        $where .= '(G.tab_goals - G.tab_goalsreceived) DESC, ';
        $where .= 'G.tab_goals DESC, ';
        $where .= 'G.tab_wins DESC, ';
        $where .= 'G.tab_draws DESC, ';
        $where .= 'T.name ASC';

        $result = $db->querySelect(
            $columns,
            $fromTable,
            $where,
            array((int) $roundId, (string) $groupName)
        );

        $teams = array();
        while ($team = $result->fetch_array()) {
            $teams[] = $team;
        }
        $result->free();

        return $teams;
    }

    public static function getLatestResults(WebSoccer $websoccer, DbConnection $db, $cupName, $limit) {
        return MatchesDataService::getMatchesByCondition(
            $websoccer,
            $db,
            "M.pokalname = '%s' AND M.berechnet = '1' ORDER BY M.datum DESC",
            array($cupName),
            (int) $limit
        );
    }

    public static function getNextMatches(WebSoccer $websoccer, DbConnection $db, $cupName, $limit) {
        return MatchesDataService::getMatchesByCondition(
            $websoccer,
            $db,
            "M.pokalname = '%s' AND M.berechnet != '1' ORDER BY M.datum ASC",
            array($cupName),
            (int) $limit
        );
    }

    public static function getMyTeamInfo(WebSoccer $websoccer, DbConnection $db, $cupName, $teamId, $groupRound) {
        if ($teamId < 1) {
            return array();
        }

        $prefix = $websoccer->getConfig('db_prefix');

        $result = $db->querySelect(
            'T.id, T.name, T.bild',
            $prefix . '_verein AS T',
            'T.id = %d',
            (int) $teamId,
            1
        );
        $team = $result->fetch_array();
        $result->free();

        if (!$team) {
            return array();
        }

        $team['group_name'] = '';
        $team['participating'] = false;
        $team['next_match'] = array();
        $team['last_match'] = array();

        if ($groupRound) {
            $result = $db->querySelect(
                'G.name',
                $prefix . '_cup_round_group AS G',
                'G.cup_round_id = %d AND G.team_id = %d',
                array((int) $groupRound['id'], (int) $teamId),
                1
            );
            $group = $result->fetch_array();
            $result->free();

            if ($group) {
                $team['group_name'] = $group['name'];
                $team['participating'] = true;
            }
        }

        $nextMatches = MatchesDataService::getMatchesByCondition(
            $websoccer,
            $db,
            "M.pokalname = '%s' AND (M.home_verein = %d OR M.gast_verein = %d) AND M.berechnet != '1' ORDER BY M.datum ASC",
            array($cupName, (int) $teamId, (int) $teamId),
            1
        );

        if (isset($nextMatches[0])) {
            $team['next_match'] = $nextMatches[0];
            $team['participating'] = true;
        }

        $lastMatches = MatchesDataService::getMatchesByCondition(
            $websoccer,
            $db,
            "M.pokalname = '%s' AND (M.home_verein = %d OR M.gast_verein = %d) AND M.berechnet = '1' ORDER BY M.datum DESC",
            array($cupName, (int) $teamId, (int) $teamId),
            1
        );

        if (isset($lastMatches[0])) {
            $team['last_match'] = $lastMatches[0];
            $team['participating'] = true;
        }

        return $team;
    }

    public static function getParticipantCount(WebSoccer $websoccer, DbConnection $db, $cupId, $cupName) {
        $prefix = $websoccer->getConfig('db_prefix');
        $teamIds = array();

        $fromTable  = $prefix . '_cup_round_group AS G';
        $fromTable .= ' INNER JOIN ' . $prefix . '_cup_round AS R ON R.id = G.cup_round_id';

        $result = $db->querySelect(
            'DISTINCT G.team_id',
            $fromTable,
            'R.cup_id = %d',
            (int) $cupId
        );
        while ($row = $result->fetch_array()) {
            $teamIds[(int) $row['team_id']] = true;
        }
        $result->free();

        $result = $db->querySelect(
            'DISTINCT M.home_verein AS team_id',
            $prefix . '_spiel AS M',
            "M.pokalname = '%s'",
            (string) $cupName
        );
        while ($row = $result->fetch_array()) {
            $teamIds[(int) $row['team_id']] = true;
        }
        $result->free();

        $result = $db->querySelect(
            'DISTINCT M.gast_verein AS team_id',
            $prefix . '_spiel AS M',
            "M.pokalname = '%s'",
            (string) $cupName
        );
        while ($row = $result->fetch_array()) {
            $teamIds[(int) $row['team_id']] = true;
        }
        $result->free();

        return count($teamIds);
    }

    public static function getMatchStats($matches) {
        $stats = array('total' => 0, 'played' => 0, 'open' => 0, 'live' => 0);

        foreach ($matches as $match) {
            $stats['total']++;

            if ((string) $match['simulated'] === '1') {
                $stats['played']++;
            } else {
                $stats['open']++;
                if ((int) $match['minutes'] > 0) {
                    $stats['live']++;
                }
            }
        }

        return $stats;
    }

    public static function buildPhaseList($rounds, $groupRound, $groupNames) {
        $phases = array();

        if ($groupRound && count($groupNames)) {
            foreach ($groupNames as $groupName) {
                $phases[] = array(
                    'key' => $groupName,
                    'label' => 'Gruppe ' . $groupName,
                    'type' => 'group',
                    'round_id' => (int) $groupRound['id'],
                    'round_name' => $groupRound['name'],
                    'group_name' => $groupName
                );
            }
        }

        foreach ($rounds as $round) {
            if ($groupRound && (int) $round['id'] === (int) $groupRound['id']) {
                continue;
            }

            $phases[] = array(
                'key' => 'r' . (int) $round['id'],
                'label' => $round['name'],
                'type' => 'round',
                'round_id' => (int) $round['id'],
                'round_name' => $round['name'],
                'group_name' => ''
            );
        }

        return $phases;
    }

    public static function resolveSelectedPhase($phase, $phases, $myTeam) {
        if (!count($phases)) {
            return null;
        }

        $phase = trim((string) $phase);

        if ($phase !== '') {
            foreach ($phases as $phaseInfo) {
                if ($phaseInfo['key'] === $phase) {
                    return $phaseInfo;
                }
            }

            $legacyRoundName = self::getLegacyRoundName($phase);
            if ($legacyRoundName !== '') {
                foreach ($phases as $phaseInfo) {
                    if ($phaseInfo['round_name'] === $legacyRoundName) {
                        return $phaseInfo;
                    }
                }
            }
        }

        if (isset($myTeam['group_name']) && strlen($myTeam['group_name'])) {
            foreach ($phases as $phaseInfo) {
                if ($phaseInfo['type'] === 'group' && $phaseInfo['group_name'] === $myTeam['group_name']) {
                    return $phaseInfo;
                }
            }
        }

        return $phases[0];
    }

    public static function getLegacyRoundName($phase) {
        $map = array(
            'round1' => 'Runde 1',
            '1round' => 'Runde 1',
            'afinal' => 'Achtelfinale',
            'vfinal' => 'Viertelfinale',
            'qfinal' => 'Viertelfinale',
            'hfinal' => 'Halbfinale',
            'sfinal' => 'Halbfinale',
            'final' => 'Finale',
            'finale' => 'Finale'
        );

        return isset($map[$phase]) ? $map[$phase] : '';
    }

    private static function getUserTeamId(WebSoccer $websoccer, DbConnection $db) {
        $user = $websoccer->getUser();
        if (!$user || $user->id == null) {
            return 0;
        }

        $teamId = $user->getClubId($websoccer, $db);
        return $teamId ? (int) $teamId : 0;
    }
}
?>
