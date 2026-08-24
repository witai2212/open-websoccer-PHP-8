<?php
/******************************************************

  Season rollover cup helpers for OpenWebSoccer-Sim.
  CM23 Task 1002 | 24.08.2026 | Revision 2

******************************************************/

/**
 * Handles national and European cup preparation during season rollover.
 */
class SeasonRolloverCupService {

    public static function getLargestPowerOfTwo($number) {
        $number = (int) $number;
        $power = 1;

        while ($power * 2 <= $number) {
            $power *= 2;
        }

        return $power;
    }

    public static function getRoundsForTeamCount($teams) {
        $teams = (int) $teams;
        $rounds = 0;

        while ($teams > 1) {
            $teams = (int) ($teams / 2);
            $rounds++;
        }

        return $rounds;
    }

    public static function storeWinnerOfLastNationalCupMatch(WebSoccer $websoccer, DbConnection $db, $cupName) {
        $cupName = trim((string) $cupName);
        if ($cupName === '') {
            return false;
        }

        $prefix = $websoccer->getConfig('db_prefix');

        $result = $db->querySelect(
            'id',
            $prefix . '_cup',
            "name = '%s' AND archived = '0'",
            $cupName,
            1
        );
        $cup = $result->fetch_array();
        $result->free();

        if (!$cup) {
            return false;
        }

        $result = $db->querySelect(
            'home_verein, gast_verein, home_tore, gast_tore',
            $prefix . '_spiel',
            "spieltyp = 'Pokalspiel' AND pokalname = '%s' AND berechnet = '1' ORDER BY datum DESC, id DESC",
            $cupName,
            1
        );
        $match = $result->fetch_array();
        $result->free();

        if (!$match) {
            return false;
        }

        $homeGoals = (int) $match['home_tore'];
        $guestGoals = (int) $match['gast_tore'];
        $winnerId = 0;

        if ($homeGoals > $guestGoals) {
            $winnerId = (int) $match['home_verein'];
        } elseif ($guestGoals > $homeGoals) {
            $winnerId = (int) $match['gast_verein'];
        }

        if ($winnerId <= 0) {
            return false;
        }

        $db->queryUpdate(
            array('winner_id' => $winnerId),
            $prefix . '_cup',
            'id = %d',
            (int) $cup['id']
        );

        return true;
    }

    public static function deleteCupMatches(WebSoccer $websoccer, DbConnection $db, $cupName) {
        $cupName = trim((string) $cupName);
        if ($cupName === '') {
            return 0;
        }

        $prefix = $websoccer->getConfig('db_prefix');

        $result = $db->querySelect(
            'id',
            $prefix . '_spiel',
            "spieltyp = 'Pokalspiel' AND pokalname = '%s'",
            $cupName
        );

        $matchIds = array();
        while ($match = $result->fetch_array()) {
            $matchIds[] = (int) $match['id'];
        }
        $result->free();

        foreach ($matchIds as $matchId) {
            $db->queryDelete($prefix . '_matchreport', 'match_id = %d', $matchId);
            $db->queryDelete($prefix . '_spiel_berechnung', 'spiel_id = %d', $matchId);
            $db->queryDelete($prefix . '_aufstellung', 'match_id = %d', $matchId);
            $db->queryDelete($prefix . '_spiel', 'id = %d', $matchId);
        }

        return count($matchIds);
    }

    public static function rescheduleCupRounds(
        WebSoccer $websoccer,
        DbConnection $db,
        $cupName,
        $firstRoundTimestamp,
        $finalTimestamp,
        array $blackoutTimestamps = array()
    ) {
        $cupId = CupsDataService::getCupIdByName($websoccer, $db, (string) $cupName);

        if (!$cupId) {
            return array();
        }

        $prefix = $websoccer->getConfig('db_prefix');
        $result = $db->querySelect(
            'id, name, firstround_date, secondround_date, finalround, groupmatches',
            $prefix . '_cup_round',
            'cup_id = %d ORDER BY firstround_date ASC, id ASC',
            (int) $cupId
        );

        $rounds = array();
        while ($round = $result->fetch_array()) {
            $rounds[] = $round;
        }
        $result->free();

        if (empty($rounds)) {
            return array();
        }

        $roundDates = SeasonRolloverScheduleService::buildEvenlyDistributedCupRoundDates(
            (int) $firstRoundTimestamp,
            (int) $finalTimestamp,
            count($rounds),
            $blackoutTimestamps
        );

        $updatedRounds = array();

        foreach ($rounds as $index => $round) {
            $firstLegTimestamp = (int) $roundDates[$index];
            $secondLegTimestamp = 0;

            if (!empty($round['secondround_date'])) {
                $secondLegTimestamp = SeasonRolloverScheduleService::addDays(
                    $firstLegTimestamp,
                    7,
                    SeasonRolloverScheduleService::CUP_KICKOFF_HOUR,
                    SeasonRolloverScheduleService::CUP_KICKOFF_MINUTE
                );

                if (isset($roundDates[$index + 1]) && $secondLegTimestamp >= (int) $roundDates[$index + 1]) {
                    throw new Exception(
                        'Pokaltermine für ' . $cupName . ' sind zu dicht für Hin- und Rückspiele.'
                    );
                }
            }

            $db->queryUpdate(
                array(
                    'firstround_date' => $firstLegTimestamp,
                    'secondround_date' => $secondLegTimestamp
                ),
                $prefix . '_cup_round',
                'id = %d',
                (int) $round['id']
            );

            $updatedRounds[] = array(
                'round_id' => (int) $round['id'],
                'round_name' => (string) $round['name'],
                'first_date' => SeasonRolloverScheduleService::formatGermanDate($firstLegTimestamp) . ' 20:00',
                'second_date' => $secondLegTimestamp > 0
                    ? SeasonRolloverScheduleService::formatGermanDate($secondLegTimestamp) . ' 20:00'
                    : '',
                'finalround' => !empty($round['finalround']) ? 1 : 0
            );
        }

        return $updatedRounds;
    }

    public static function generateNationalCups(
        WebSoccer $websoccer,
        DbConnection $db,
        $firstCupTuesdayTimestamp,
        $nationalCupFinalTimestamp = 0,
        $commonLeagueEndTimestamp = 0
    ) {
        $countries = TeamsDataService::getNumberOfTeamsByCountry($websoccer, $db);
        if (!is_array($countries)) {
            $countries = array();
        }

        $firstCupTuesdayTimestamp = SeasonRolloverScheduleService::nextWeekday(
            (int) $firstCupTuesdayTimestamp,
            2,
            SeasonRolloverScheduleService::CUP_KICKOFF_HOUR,
            SeasonRolloverScheduleService::CUP_KICKOFF_MINUTE
        );
        $firstDate = SeasonRolloverScheduleService::formatGermanDate($firstCupTuesdayTimestamp);

        $createdCups = array();
        $skippedCountries = array();
        $deletedMatches = 0;
        $winnersStored = 0;

        foreach ($countries as $country) {
            if (empty($country['name'])) {
                continue;
            }

            $land = trim((string) $country['name']);
            $numberOfTeams = isset($country['teams']) ? (int) $country['teams'] : 0;

            if ($land === '') {
                continue;
            }

            if (self::storeWinnerOfLastNationalCupMatch($websoccer, $db, $land)) {
                $winnersStored++;
            }

            $deletedMatches += self::deleteCupMatches($websoccer, $db, $land);

            $cupTeams = self::getLargestPowerOfTwo($numberOfTeams);
            if ($cupTeams < 8) {
                $skippedCountries[] = array(
                    'country' => $land,
                    'teams' => $numberOfTeams,
                    'reason' => 'Weniger als 8 gültige Teams.'
                );
                continue;
            }

            $rounds = self::getRoundsForTeamCount($cupTeams);

            CupsDataService::generateNationalCup(
                $websoccer,
                $db,
                $land,
                $rounds,
                $firstDate,
                SeasonRolloverScheduleService::CUP_KICKOFF_HOUR,
                SeasonRolloverScheduleService::CUP_KICKOFF_MINUTE
            );

            $scheduledRounds = array();
            if ((int) $nationalCupFinalTimestamp > 0) {
                $blackouts = array();
                if ((int) $commonLeagueEndTimestamp > 0) {
                    $blackouts[] = (int) $commonLeagueEndTimestamp;
                }

                $scheduledRounds = self::rescheduleCupRounds(
                    $websoccer,
                    $db,
                    $land,
                    $firstCupTuesdayTimestamp,
                    (int) $nationalCupFinalTimestamp,
                    $blackouts
                );
            }

            $createdCups[] = array(
                'country' => $land,
                'teams' => $cupTeams,
                'rounds' => $rounds,
                'scheduled_rounds' => $scheduledRounds,
                'final_date' => (int) $nationalCupFinalTimestamp > 0
                    ? SeasonRolloverScheduleService::formatGermanDate((int) $nationalCupFinalTimestamp) . ' 20:00'
                    : ''
            );
        }

        CupScheduleDataService::createFirstCupMatch($websoccer, $db);

        return array(
            'created_cups' => $createdCups,
            'skipped_countries' => $skippedCountries,
            'deleted_matches' => $deletedMatches,
            'winners_stored' => $winnersStored
        );
    }

    public static function storeWinnerOfLastEuropeanFinal(WebSoccer $websoccer, DbConnection $db, $cupName) {
        $cupName = trim((string) $cupName);
        if ($cupName === '') {
            return false;
        }

        $prefix = $websoccer->getConfig('db_prefix');
        $columns = 'C.id AS cup_id, M.home_verein, M.gast_verein, M.home_tore, M.gast_tore';
        $fromTable = $prefix . '_spiel AS M '
            . 'INNER JOIN ' . $prefix . '_cup AS C ON C.name = M.pokalname '
            . 'INNER JOIN ' . $prefix . '_cup_round AS R ON R.cup_id = C.id AND R.name = M.pokalrunde';

        $whereCondition = "C.name = '%s' AND M.spieltyp = 'Pokalspiel' AND M.berechnet = '1' AND R.finalround = '1' ORDER BY M.datum DESC, M.id DESC";

        $result = $db->querySelect($columns, $fromTable, $whereCondition, $cupName, 1);
        $match = $result->fetch_array();
        $result->free();

        if (!$match) {
            return false;
        }

        $homeGoals = (int) $match['home_tore'];
        $guestGoals = (int) $match['gast_tore'];
        $winnerId = 0;

        if ($homeGoals > $guestGoals) {
            $winnerId = (int) $match['home_verein'];
        } elseif ($guestGoals > $homeGoals) {
            $winnerId = (int) $match['gast_verein'];
        }

        if ($winnerId <= 0) {
            return false;
        }

        $db->queryUpdate(
            array('winner_id' => $winnerId),
            $prefix . '_cup',
            'id = %d',
            (int) $match['cup_id']
        );

        return true;
    }

    public static function clearEuropeanCupGroupAssignments(WebSoccer $websoccer, DbConnection $db, $roundId) {
        $roundId = (int) $roundId;
        if ($roundId <= 0) {
            return;
        }

        $prefix = $websoccer->getConfig('db_prefix');
        $db->queryDelete($prefix . '_cup_round_group', 'cup_round_id = %d', $roundId);
    }

    public static function generateEuropeanCups(
        WebSoccer $websoccer,
        DbConnection $db,
        $firstClWednesdayTimestamp,
        $firstUlThursdayTimestamp,
        $firstLibertadoresTimestamp = 0,
        $firstSudamericanaTimestamp = 0,
        $firstConcacafTimestamp = 0,
        $nationalCupFinalTimestamp = 0,
        $commonLeagueEndTimestamp = 0
    ) {
        $results = array();
        $blackouts = array();

        if ((int) $commonLeagueEndTimestamp > 0) {
            $blackouts[] = (int) $commonLeagueEndTimestamp;
        }
        if ((int) $nationalCupFinalTimestamp > 0) {
            $blackouts[] = (int) $nationalCupFinalTimestamp;
        }

        $clStart = SeasonRolloverScheduleService::nextWeekday(
            (int) $firstClWednesdayTimestamp,
            3,
            SeasonRolloverScheduleService::CUP_KICKOFF_HOUR,
            SeasonRolloverScheduleService::CUP_KICKOFF_MINUTE
        );
        $ulStart = SeasonRolloverScheduleService::nextWeekday(
            (int) $firstUlThursdayTimestamp,
            4,
            SeasonRolloverScheduleService::CUP_KICKOFF_HOUR,
            SeasonRolloverScheduleService::CUP_KICKOFF_MINUTE
        );

        $clFinal = (int) $nationalCupFinalTimestamp > 0
            ? SeasonRolloverScheduleService::getInternationalCupFinalTimestamp((int) $nationalCupFinalTimestamp, 3)
            : 0;
        $ulFinal = (int) $nationalCupFinalTimestamp > 0
            ? SeasonRolloverScheduleService::getInternationalCupFinalTimestamp((int) $nationalCupFinalTimestamp, 4)
            : 0;

        $results[] = self::generateEuropeanCup(
            $websoccer,
            $db,
            'Champions League',
            UefaDataService::UEFA_CL_CUP_ID,
            $clStart,
            $clFinal,
            $blackouts
        );

        $results[] = self::generateEuropeanCup(
            $websoccer,
            $db,
            'UEFA Euro League',
            UefaDataService::UEFA_UL_CUP_ID,
            $ulStart,
            $ulFinal,
            $blackouts
        );

        if (class_exists('ConmebolDataService')) {
            $libStart = ((int) $firstLibertadoresTimestamp > 0)
                ? SeasonRolloverScheduleService::nextWeekday(
                    (int) $firstLibertadoresTimestamp,
                    2,
                    SeasonRolloverScheduleService::CUP_KICKOFF_HOUR,
                    SeasonRolloverScheduleService::CUP_KICKOFF_MINUTE
                )
                : $clStart;

            $sudStart = ((int) $firstSudamericanaTimestamp > 0)
                ? SeasonRolloverScheduleService::nextWeekday(
                    (int) $firstSudamericanaTimestamp,
                    4,
                    SeasonRolloverScheduleService::CUP_KICKOFF_HOUR,
                    SeasonRolloverScheduleService::CUP_KICKOFF_MINUTE
                )
                : $ulStart;

            $libFinal = (int) $nationalCupFinalTimestamp > 0
                ? SeasonRolloverScheduleService::getInternationalCupFinalTimestamp((int) $nationalCupFinalTimestamp, 2)
                : 0;
            $sudFinal = (int) $nationalCupFinalTimestamp > 0
                ? SeasonRolloverScheduleService::getInternationalCupFinalTimestamp((int) $nationalCupFinalTimestamp, 4)
                : 0;

            $results[] = self::generateConmebolCup(
                $websoccer,
                $db,
                ConmebolDataService::COPA_LIBERTADORES,
                $libStart,
                $libFinal,
                $blackouts
            );
            $results[] = self::generateConmebolCup(
                $websoccer,
                $db,
                ConmebolDataService::COPA_SUDAMERICANA,
                $sudStart,
                $sudFinal,
                $blackouts
            );
        }

        if (class_exists('ConcacafDataService') && (int) $firstConcacafTimestamp > 0) {
            $concacafStart = SeasonRolloverScheduleService::nextWeekday(
                (int) $firstConcacafTimestamp,
                2,
                SeasonRolloverScheduleService::CUP_KICKOFF_HOUR,
                SeasonRolloverScheduleService::CUP_KICKOFF_MINUTE
            );
            $concacafFinal = (int) $nationalCupFinalTimestamp > 0
                ? SeasonRolloverScheduleService::getInternationalCupFinalTimestamp((int) $nationalCupFinalTimestamp, 2)
                : 0;

            $results[] = self::generateConcacafCupIfReady(
                $websoccer,
                $db,
                ConcacafDataService::CONCACAF_CHAMPIONS_CUP,
                $concacafStart,
                $concacafFinal,
                $blackouts
            );
        }

        return $results;
    }

    public static function generateEuropeanCup(WebSoccer $websoccer, DbConnection $db, $cupName, $cupId, $firstMatchTimestamp, $finalTimestamp = 0, array $blackoutTimestamps = array()) {
        $cupId = (int) $cupId;
        $prefix = $websoccer->getConfig('db_prefix');

        $resolvedCupId = CupsDataService::getCupIdByName($websoccer, $db, $cupName);
        if (!$resolvedCupId) {
            throw new Exception('Europäischer Pokal nicht gefunden: ' . $cupName);
        }

        $roundId = CupsDataService::getGroupIdByCupId($websoccer, $db, (int) $resolvedCupId, 'Gruppen');
        if (!$roundId) {
            throw new Exception('Gruppenrunde für ' . $cupName . ' nicht gefunden.');
        }

        $winnerStored = self::storeWinnerOfLastEuropeanFinal($websoccer, $db, $cupName);
        $deletedMatches = self::deleteCupMatches($websoccer, $db, $cupName);
        self::clearEuropeanCupGroupAssignments($websoccer, $db, $roundId);

        $tempTeams = UefaDataService::getUefaTeamsByCupId($websoccer, $db, $cupId);
        if (!is_array($tempTeams) || empty($tempTeams)) {
            throw new Exception('Keine UEFA-Temp-Teams gefunden für: ' . $cupName);
        }

        UefaDataService::putTempTeamsInGroups($websoccer, $db, $roundId, $tempTeams);

        if ((int) $finalTimestamp > 0) {
            self::rescheduleCupRounds(
                $websoccer,
                $db,
                $cupName,
                (int) $firstMatchTimestamp,
                (int) $finalTimestamp,
                $blackoutTimestamps
            );
        }

        $firstDate = SeasonRolloverScheduleService::formatGermanDate($firstMatchTimestamp);
        $groups = array('A', 'B', 'C', 'D');
        $groupsGenerated = 0;

        foreach ($groups as $groupName) {
            $groupTeams = UefaDataService::getUefaTeamsByGroup($websoccer, $db, $groupName, $roundId);
            if (!is_array($groupTeams) || empty($groupTeams)) {
                continue;
            }

            ScheduleGenerator::createUEFACupGroupSchedule(
                $websoccer,
                $db,
                $groupTeams,
                $firstDate,
                20,
                0,
                7,
                $cupName,
                max(1, count($groupTeams) - 1),
                $groupName,
                'Gruppen'
            );

            $groupsGenerated++;
        }

        $result = $db->querySelect(
            'COUNT(*) AS matches',
            $prefix . '_spiel',
            "spieltyp = 'Pokalspiel' AND pokalname = '%s'",
            $cupName
        );
        $row = $result->fetch_array();
        $result->free();

        return array(
            'cup_name' => $cupName,
            'temp_teams' => count($tempTeams),
            'groups_generated' => $groupsGenerated,
            'created_matches' => $row ? (int) $row['matches'] : 0,
            'deleted_matches' => $deletedMatches,
            'winner_stored' => $winnerStored
        );
    }

    public static function generateConmebolCup(WebSoccer $websoccer, DbConnection $db, $cupName, $firstMatchTimestamp, $finalTimestamp = 0, array $blackoutTimestamps = array()) {
        $resolvedCupId = CupsDataService::getCupIdByName($websoccer, $db, $cupName);
        if (!$resolvedCupId) {
            return array(
                'cup_name' => $cupName,
                'skipped' => 1,
                'reason' => 'Pokal nicht gefunden'
            );
        }

        $roundId = CupsDataService::getGroupIdByCupId($websoccer, $db, (int) $resolvedCupId, 'Gruppen');
        if (!$roundId) {
            return array(
                'cup_name' => $cupName,
                'skipped' => 1,
                'reason' => 'Gruppenrunde nicht gefunden'
            );
        }

        $winnerStored = self::storeWinnerOfLastEuropeanFinal($websoccer, $db, $cupName);
        $deletedMatches = self::deleteCupMatches($websoccer, $db, $cupName);
        self::clearEuropeanCupGroupAssignments($websoccer, $db, $roundId);

        $tempTeams = ConmebolDataService::getConmebolTeamsByCupName($websoccer, $db, $cupName);
        if (!is_array($tempTeams) || empty($tempTeams)) {
            return array(
                'cup_name' => $cupName,
                'skipped' => 1,
                'reason' => 'Keine CONMEBOL-Temp-Teams gefunden'
            );
        }

        $groups = array('A', 'B', 'C', 'D');
        self::putTeamsInCupGroups($websoccer, $db, $roundId, $tempTeams, $groups);

        if ((int) $finalTimestamp > 0) {
            self::rescheduleCupRounds(
                $websoccer,
                $db,
                $cupName,
                (int) $firstMatchTimestamp,
                (int) $finalTimestamp,
                $blackoutTimestamps
            );
        }

        $firstDate = SeasonRolloverScheduleService::formatGermanDate($firstMatchTimestamp);
        $groupsGenerated = 0;

        foreach ($groups as $groupName) {
            $groupTeams = UefaDataService::getUefaTeamsByGroup($websoccer, $db, $groupName, $roundId);
            if (!is_array($groupTeams) || empty($groupTeams)) {
                continue;
            }

            ScheduleGenerator::createUEFACupGroupSchedule(
                $websoccer,
                $db,
                $groupTeams,
                $firstDate,
                20,
                0,
                7,
                $cupName,
                max(1, count($groupTeams) - 1),
                $groupName,
                'Gruppen'
            );

            $groupsGenerated++;
        }

        return self::buildCupGenerationResult($websoccer, $db, $cupName, count($tempTeams), $groupsGenerated, $deletedMatches, $winnerStored);
    }

    public static function generateConcacafCupIfReady(WebSoccer $websoccer, DbConnection $db, $cupName, $firstMatchTimestamp, $finalTimestamp = 0, array $blackoutTimestamps = array()) {
        $resolvedCupId = CupsDataService::getCupIdByName($websoccer, $db, $cupName);
        if (!$resolvedCupId) {
            return array(
                'cup_name' => $cupName,
                'skipped' => 1,
                'reason' => 'Vorbereitet, aber Pokal noch nicht angelegt'
            );
        }

        $roundId = CupsDataService::getGroupIdByCupId($websoccer, $db, (int) $resolvedCupId, 'Gruppen');
        if (!$roundId) {
            return array(
                'cup_name' => $cupName,
                'skipped' => 1,
                'reason' => 'Vorbereitet, aber Gruppenrunde noch nicht angelegt'
            );
        }

        $deletedMatches = self::deleteCupMatches($websoccer, $db, $cupName);
        self::clearEuropeanCupGroupAssignments($websoccer, $db, $roundId);
        $tempTeams = ConcacafDataService::getConcacafTeamsByCupName($websoccer, $db, $cupName);
        if (!is_array($tempTeams) || empty($tempTeams)) {
            return array(
                'cup_name' => $cupName,
                'skipped' => 1,
                'reason' => 'Keine CONCACAF-Temp-Teams gefunden'
            );
        }

        $groups = array('A', 'B', 'C', 'D');
        self::putTeamsInCupGroups($websoccer, $db, $roundId, $tempTeams, $groups);

        if ((int) $finalTimestamp > 0) {
            self::rescheduleCupRounds(
                $websoccer,
                $db,
                $cupName,
                (int) $firstMatchTimestamp,
                (int) $finalTimestamp,
                $blackoutTimestamps
            );
        }

        $firstDate = SeasonRolloverScheduleService::formatGermanDate($firstMatchTimestamp);
        $groupsGenerated = 0;

        foreach ($groups as $groupName) {
            $groupTeams = UefaDataService::getUefaTeamsByGroup($websoccer, $db, $groupName, $roundId);
            if (!is_array($groupTeams) || empty($groupTeams)) {
                continue;
            }
            ScheduleGenerator::createUEFACupGroupSchedule($websoccer, $db, $groupTeams, $firstDate, 20, 0, 7, $cupName, max(1, count($groupTeams) - 1), $groupName, 'Gruppen');
            $groupsGenerated++;
        }

        return self::buildCupGenerationResult($websoccer, $db, $cupName, count($tempTeams), $groupsGenerated, $deletedMatches, false);
    }


    public static function putTeamsInCupGroups(WebSoccer $websoccer, DbConnection $db, $roundId, array $teams, array $groups) {
        $roundId = (int) $roundId;
        if ($roundId <= 0 || empty($teams) || empty($groups)) {
            return 0;
        }

        $prefix = $websoccer->getConfig('db_prefix');
        $db->queryDelete($prefix . '_cup_round_group', 'cup_round_id = %d', $roundId);

        $groupCount = count($groups);
        $inserted = 0;
        foreach (array_values($teams) as $index => $teamId) {
            $teamId = (int) $teamId;
            if ($teamId <= 0) {
                continue;
            }

            $groupName = $groups[$index % $groupCount];
            $db->queryInsert(
                array(
                    'cup_round_id' => $roundId,
                    'team_id' => $teamId,
                    'name' => $groupName
                ),
                $prefix . '_cup_round_group'
            );
            $inserted++;
        }

        return $inserted;
    }

    private static function buildCupGenerationResult(WebSoccer $websoccer, DbConnection $db, $cupName, $tempTeams, $groupsGenerated, $deletedMatches, $winnerStored) {
        $prefix = $websoccer->getConfig('db_prefix');
        $result = $db->querySelect(
            'COUNT(*) AS matches',
            $prefix . '_spiel',
            "spieltyp = 'Pokalspiel' AND pokalname = '%s'",
            $cupName
        );
        $row = $result->fetch_array();
        $result->free();

        return array(
            'cup_name' => $cupName,
            'temp_teams' => (int) $tempTeams,
            'groups_generated' => (int) $groupsGenerated,
            'created_matches' => $row ? (int) $row['matches'] : 0,
            'deleted_matches' => (int) $deletedMatches,
            'winner_stored' => $winnerStored
        );
    }

}
?>
