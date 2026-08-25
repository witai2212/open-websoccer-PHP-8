<?php
/******************************************************

  This file is part of OpenWebSoccer-Sim.
  CM23 Task 1002 | 24.08.2026 | Revision 1
  CM23 Task 1007 | 25.08.2026 | Revision 2

******************************************************/

/**
 * Helps considering whether a cup match needs to be extended
 * and helps generating matches.
 *
 * @author Ingo Hofmann
 */
class SimulationCupMatchHelper {

    public static function checkIfExtensionIsRequired(WebSoccer $websoccer, DbConnection $db, SimulationMatch $match) {
        if (strlen($match->cupRoundGroup)) {
            return FALSE;
        }

        $columns['home_tore'] = 'home_goals';
        $columns['gast_tore'] = 'guest_goals';
        $columns['berechnet'] = 'is_simulated';
        $whereCondition = 'home_verein = %d AND gast_verein = %d AND pokalname = \'%s\' AND pokalrunde = \'%s\'';
        $result = $db->querySelect($columns, $websoccer->getConfig('db_prefix') . '_spiel', $whereCondition,
            array($match->guestTeam->id, $match->homeTeam->id, $match->cupName, $match->cupRoundName), 1);
        $otherRound = $result->fetch_array();
        $result->free();

        if (!$otherRound) {
            if ($match->homeTeam->getGoals() == $match->guestTeam->getGoals()) {
                return TRUE;
            } elseif ($match->homeTeam->getGoals() > $match->guestTeam->getGoals()) {
                self::createNextRoundMatchAndPayAwards($websoccer, $db,
                    $match->homeTeam->id, $match->guestTeam->id, $match->cupName, $match->cupRoundName);
                return FALSE;
            } else {
                self::createNextRoundMatchAndPayAwards($websoccer, $db,
                    $match->guestTeam->id, $match->homeTeam->id, $match->cupName, $match->cupRoundName);
                return FALSE;
            }
        }

        if (isset($otherRound['is_simulated']) && !$otherRound['is_simulated']) {
            return FALSE;
        }

        $totalHomeGoals = $match->homeTeam->getGoals() + $otherRound['guest_goals'];
        $totalGuestGoals = $match->guestTeam->getGoals() + $otherRound['home_goals'];
        $winnerTeam = null;
        $loserTeam = null;

        if ($totalHomeGoals > $totalGuestGoals) {
            $winnerTeam = $match->homeTeam;
            $loserTeam = $match->guestTeam;
        } elseif ($totalHomeGoals < $totalGuestGoals) {
            $winnerTeam = $match->guestTeam;
            $loserTeam = $match->homeTeam;
        } else {
            $homeTeamAwayGoals = $otherRound['guest_goals'];
            $guestTeamAwayGoals = $match->guestTeam->getGoals();
            if ($homeTeamAwayGoals > $guestTeamAwayGoals) {
                $winnerTeam = $match->homeTeam;
                $loserTeam = $match->guestTeam;
            } elseif ($homeTeamAwayGoals < $guestTeamAwayGoals) {
                $winnerTeam = $match->guestTeam;
                $loserTeam = $match->homeTeam;
            } else {
                return TRUE;
            }
        }

        self::createNextRoundMatchAndPayAwards($websoccer, $db,
            $winnerTeam->id, $loserTeam->id, $match->cupName, $match->cupRoundName);
        return FALSE;
    }

    public static function createNextRoundMatchAndPayAwards(WebSoccer $websoccer, DbConnection $db,
        $winnerTeamId, $loserTeamId, $cupName, $cupRound) {

        $columns['C.id'] = 'cup_id';
        $columns['C.winner_award'] = 'cup_winner_award';
        $columns['C.second_award'] = 'cup_second_award';
        $columns['C.perround_award'] = 'cup_perround_award';
        $columns['R.id'] = 'round_id';
        $columns['R.finalround'] = 'is_finalround';
        $fromTable = $websoccer->getConfig('db_prefix') . '_cup_round AS R';
        $fromTable .= ' INNER JOIN ' . $websoccer->getConfig('db_prefix') . '_cup AS C ON C.id = R.cup_id';
        $result = $db->querySelect($columns, $fromTable,
            'C.name = \'%s\' AND R.name = \'%s\'', array($cupName, $cupRound), 1);
        $round = $result->fetch_array();
        $result->free();

        if (!$round) {
            return;
        }

        if ($round['cup_perround_award']) {
            BankAccountDataService::creditAmount($websoccer, $db, $winnerTeamId, $round['cup_perround_award'],
                'cup_cuproundaward_perround_subject', $cupName);
            BankAccountDataService::creditAmount($websoccer, $db, $loserTeamId, $round['cup_perround_award'],
                'cup_cuproundaward_perround_subject', $cupName);
        }

        $result = $db->querySelect('user_id', $websoccer->getConfig('db_prefix') . '_verein', 'id = %d', $winnerTeamId);
        $winnerclub = $result->fetch_array();
        $result->free();
        $result = $db->querySelect('user_id', $websoccer->getConfig('db_prefix') . '_verein', 'id = %d', $loserTeamId);
        $loserclub = $result->fetch_array();
        $result->free();
        $now = $websoccer->getNowAsTimestamp();

        if (!empty($winnerclub['user_id'])) {
            $db->queryInsert(array(
                'user_id' => $winnerclub['user_id'],
                'team_id' => $winnerTeamId,
                'cup_round_id' => $round['round_id'],
                'date_recorded' => $now
            ), $websoccer->getConfig('db_prefix') . '_achievement');
        }
        if (!empty($loserclub['user_id'])) {
            $db->queryInsert(array(
                'user_id' => $loserclub['user_id'],
                'team_id' => $loserTeamId,
                'cup_round_id' => $round['round_id'],
                'date_recorded' => $now
            ), $websoccer->getConfig('db_prefix') . '_achievement');
        }

        if ($round['is_finalround']) {
            if ($round['cup_winner_award']) {
                BankAccountDataService::creditAmount($websoccer, $db, $winnerTeamId, $round['cup_winner_award'],
                    'cup_cuproundaward_winner_subject', $cupName);
            }
            if ($round['cup_second_award']) {
                BankAccountDataService::creditAmount($websoccer, $db, $loserTeamId, $round['cup_second_award'],
                    'cup_cuproundaward_second_subject', $cupName);
            }
            $db->queryUpdate(array('winner_id' => $winnerTeamId), $websoccer->getConfig('db_prefix') . '_cup',
                'id = %d', $round['cup_id']);
            if (!empty($winnerclub['user_id'])) {
                BadgesDataService::awardBadgeIfApplicable($websoccer, $db, $winnerclub['user_id'], 'cupwinner');
            }
            return;
        }

        $columns = 'id,firstround_date,secondround_date,name';
        $fromTable = $websoccer->getConfig('db_prefix') . '_cup_round';
        $result = $db->querySelect($columns, $fromTable, 'from_winners_round_id = %d', $round['round_id'], 1);
        $winnerRound = $result->fetch_array();
        $result->free();
        if (isset($winnerRound['id'])) {
            self::createMatchForTeamAndRound($websoccer, $db, $winnerTeamId, $winnerRound['id'],
                $winnerRound['firstround_date'], $winnerRound['secondround_date'], $cupName, $winnerRound['name']);
        }

        $result = $db->querySelect($columns, $fromTable, 'from_loosers_round_id = %d', $round['round_id'], 1);
        $loserRound = $result->fetch_array();
        $result->free();
        if (isset($loserRound['id'])) {
            self::createMatchForTeamAndRound($websoccer, $db, $loserTeamId, $loserRound['id'],
                $loserRound['firstround_date'], $loserRound['secondround_date'], $cupName, $loserRound['name']);
        }
    }

    private static function resolveCupMatchTimestamp(WebSoccer $websoccer, DbConnection $db,
        array $teamIds, $configuredTimestamp) {
        $configuredTimestamp = (int) $configuredTimestamp;
        if ($configuredTimestamp <= 0
            || !class_exists('SeasonRolloverScheduleService')
            || !SeasonRolloverScheduleService::teamsHaveMatchOnDay($websoccer, $db, $teamIds, $configuredTimestamp)) {
            return $configuredTimestamp;
        }
        return SeasonRolloverScheduleService::findAvailableTimestampForTeams(
            $websoccer, $db, $teamIds, $configuredTimestamp,
            SeasonRolloverScheduleService::getCupWeekdays(),
            array(array(SeasonRolloverScheduleService::CUP_KICKOFF_HOUR,
                SeasonRolloverScheduleService::CUP_KICKOFF_MINUTE)), 8);
    }

    private static function buildCupMatchColumns(WebSoccer $websoccer, DbConnection $db,
        $roundId, $cupName, $cupRound, $homeTeam, $guestTeam, $timestamp) {
        $columns = array(
            'spieltyp' => 'Pokalspiel',
            'pokalname' => $cupName,
            'pokalrunde' => $cupRound,
            'home_verein' => (int) $homeTeam,
            'gast_verein' => (int) $guestTeam,
            'datum' => (int) $timestamp
        );
        if (class_exists('InternationalCupFinalDataService')) {
            $stadiumId = InternationalCupFinalDataService::getFinalStadiumIdForRound(
                $websoccer, $db, (string) $cupName, (int) $roundId,
                array((int) $homeTeam, (int) $guestTeam));
            if ($stadiumId > 0) {
                $columns['stadion_id'] = (int) $stadiumId;
            }
        }
        return $columns;
    }

    public static function checkIfMatchIsLastMatchOfGroupRoundAndCreateFollowingMatches(
        WebSoccer $websoccer, DbConnection $db, SimulationMatch $match) {
        if (!strlen($match->cupRoundGroup)) {
            return;
        }
        $result = $db->querySelect('COUNT(*) AS hits', $websoccer->getConfig('db_prefix') . '_spiel',
            'berechnet = \'0\' AND pokalname = \'%s\' AND pokalrunde = \'%s\' AND id != %d',
            array($match->cupName, $match->cupRoundName, $match->id));
        $openMatches = $result->fetch_array();
        $result->free();
        if (isset($openMatches['hits']) && $openMatches['hits']) {
            return;
        }

        $columns = array();
        $columns['N.cup_round_id'] = 'round_id';
        $columns['N.groupname'] = 'groupname';
        $columns['N.rank'] = 'rank';
        $columns['N.target_cup_round_id'] = 'target_cup_round_id';
        $fromTable = $websoccer->getConfig('db_prefix') . '_cup_round_group_next AS N';
        $fromTable .= ' INNER JOIN ' . $websoccer->getConfig('db_prefix') . '_cup_round AS R ON N.cup_round_id = R.id';
        $fromTable .= ' INNER JOIN ' . $websoccer->getConfig('db_prefix') . '_cup AS C ON R.cup_id = C.id';
        $result = $db->querySelect($columns, $fromTable, 'C.name = \'%s\' AND R.name = \'%s\'',
            array($match->cupName, $match->cupRoundName));
        $nextConfigs = array();
        $roundId = null;
        while ($nextConfig = $result->fetch_array()) {
            $nextConfigs[$nextConfig['groupname']]['' . $nextConfig['rank']] = $nextConfig['target_cup_round_id'];
            $roundId = $nextConfig['round_id'];
        }
        $result->free();
        if (empty($nextConfigs) || $roundId === null) {
            return;
        }

        $nextRoundTeams = array();
        foreach ($nextConfigs as $groupName => $rankings) {
            $teamsInGroup = CupsDataService::getTeamsOfCupGroupInRankingOrder($websoccer, $db, $roundId, $groupName);
            for ($teamRank = 1; $teamRank <= count($teamsInGroup); $teamRank++) {
                $configIndex = '' . $teamRank;
                if (isset($rankings[$configIndex])) {
                    $team = $teamsInGroup[$teamRank - 1];
                    $targetRound = $rankings[$configIndex];
                    $nextRoundTeams[$targetRound][] = $team['id'];
                }
            }
        }

        $matchTable = $websoccer->getConfig('db_prefix') . '_spiel';
        foreach ($nextRoundTeams as $nextRoundId => $teamIds) {
            $result = $db->querySelect('name,firstround_date,secondround_date',
                $websoccer->getConfig('db_prefix') . '_cup_round', 'id = %d', $nextRoundId);
            $roundInfo = $result->fetch_array();
            $result->free();
            if (!$roundInfo) {
                continue;
            }
            $teams = $teamIds;
            shuffle($teams);
            while (count($teams) > 1) {
                $homeTeam = array_pop($teams);
                $guestTeam = array_pop($teams);
                $firstRoundDate = self::resolveCupMatchTimestamp($websoccer, $db,
                    array($homeTeam, $guestTeam), $roundInfo['firstround_date']);
                $db->queryInsert(self::buildCupMatchColumns($websoccer, $db, $nextRoundId,
                    $match->cupName, $roundInfo['name'], $homeTeam, $guestTeam, $firstRoundDate), $matchTable);
                if ($roundInfo['secondround_date']) {
                    $secondRoundDate = self::resolveCupMatchTimestamp($websoccer, $db,
                        array($homeTeam, $guestTeam), $roundInfo['secondround_date']);
                    $db->queryInsert(self::buildCupMatchColumns($websoccer, $db, $nextRoundId,
                        $match->cupName, $roundInfo['name'], $guestTeam, $homeTeam, $secondRoundDate), $matchTable);
                }
            }
        }
    }

    private static function createMatchForTeamAndRound(WebSoccer $websoccer, DbConnection $db,
        $teamId, $roundId, $firstRoundDate, $secondRoundDate, $cupName, $cupRound) {
        $pendingTable = $websoccer->getConfig('db_prefix') . '_cup_round_pending';
        $result = $db->querySelect('team_id', $pendingTable, 'cup_round_id = %d', $roundId, 1);
        $opponent = $result->fetch_array();
        $result->free();
        if (!$opponent) {
            $db->queryInsert(array('team_id' => $teamId, 'cup_round_id' => $roundId), $pendingTable);
            return;
        }

        $matchTable = $websoccer->getConfig('db_prefix') . '_spiel';
        if (SimulationHelper::selectItemFromProbabilities(array(1 => 50, 0 => 50))) {
            $homeTeam = $teamId;
            $guestTeam = $opponent['team_id'];
        } else {
            $homeTeam = $opponent['team_id'];
            $guestTeam = $teamId;
        }
        $firstRoundDate = self::resolveCupMatchTimestamp($websoccer, $db,
            array($homeTeam, $guestTeam), $firstRoundDate);
        $db->queryInsert(self::buildCupMatchColumns($websoccer, $db, $roundId,
            $cupName, $cupRound, $homeTeam, $guestTeam, $firstRoundDate), $matchTable);
        if ($secondRoundDate) {
            $secondRoundDate = self::resolveCupMatchTimestamp($websoccer, $db,
                array($homeTeam, $guestTeam), $secondRoundDate);
            $db->queryInsert(self::buildCupMatchColumns($websoccer, $db, $roundId,
                $cupName, $cupRound, $guestTeam, $homeTeam, $secondRoundDate), $matchTable);
        }
        $db->queryDelete($pendingTable, 'team_id = %d AND cup_round_id = %d',
            array($opponent['team_id'], $roundId));
    }
}
?>