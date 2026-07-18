<?php
/******************************************************

  Season rollover cup helpers for OpenWebSoccer-Sim.

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

    public static function generateNationalCups(WebSoccer $websoccer, DbConnection $db, $firstCupTuesdayTimestamp) {
        $countries = TeamsDataService::getNumberOfTeamsByCountry($websoccer, $db);
        if (!is_array($countries)) {
            $countries = array();
        }

        $firstCupTuesdayTimestamp = SeasonRolloverScheduleService::nextWeekday((int) $firstCupTuesdayTimestamp, 2, 19, 0);
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
                19,
                0
            );

            $createdCups[] = array(
                'country' => $land,
                'teams' => $cupTeams,
                'rounds' => $rounds
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

    public static function generateEuropeanCups(WebSoccer $websoccer, DbConnection $db, $firstClWednesdayTimestamp, $firstUlThursdayTimestamp, $firstLibertadoresTimestamp = 0, $firstSudamericanaTimestamp = 0, $firstConcacafTimestamp = 0) {
        $results = array();

        $results[] = self::generateEuropeanCup(
            $websoccer,
            $db,
            'Champions League',
            UefaDataService::UEFA_CL_CUP_ID,
            SeasonRolloverScheduleService::nextWeekday((int) $firstClWednesdayTimestamp, 3, 20, 0)
        );

        $results[] = self::generateEuropeanCup(
            $websoccer,
            $db,
            'UEFA Euro League',
            UefaDataService::UEFA_UL_CUP_ID,
            SeasonRolloverScheduleService::nextWeekday((int) $firstUlThursdayTimestamp, 4, 20, 0)
        );

        if (class_exists('ConmebolDataService')) {
            $libStart = ((int) $firstLibertadoresTimestamp > 0) ? (int) $firstLibertadoresTimestamp : (int) $firstClWednesdayTimestamp;
            $sudStart = ((int) $firstSudamericanaTimestamp > 0) ? (int) $firstSudamericanaTimestamp : (int) $firstUlThursdayTimestamp;
            $results[] = self::generateConmebolCup($websoccer, $db, ConmebolDataService::COPA_LIBERTADORES, $libStart);
            $results[] = self::generateConmebolCup($websoccer, $db, ConmebolDataService::COPA_SUDAMERICANA, $sudStart);
        }

        if (class_exists('ConcacafDataService') && (int) $firstConcacafTimestamp > 0) {
            $results[] = self::generateConcacafCupIfReady($websoccer, $db, ConcacafDataService::CONCACAF_CHAMPIONS_CUP, (int) $firstConcacafTimestamp);
        }

        return $results;
    }

    public static function generateEuropeanCup(WebSoccer $websoccer, DbConnection $db, $cupName, $cupId, $firstMatchTimestamp) {
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

    public static function generateConmebolCup(WebSoccer $websoccer, DbConnection $db, $cupName, $firstMatchTimestamp) {
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

    public static function generateConcacafCupIfReady(WebSoccer $websoccer, DbConnection $db, $cupName, $firstMatchTimestamp) {
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
        $firstDate = SeasonRolloverScheduleService::formatGermanDate($firstMatchTimestamp);
        $groupsGenerated = 0;

        foreach ($groups as $groupName) {
            $groupTeams = UefaDataService::getUefaTeamsByGroup($websoccer, $db, $groupName, $roundId);
            if (!is_array($groupTeams) || empty($groupTeams)) {
                continue;
            }
            ScheduleGenerator::createUEFACupGroupSchedule($websoccer, $db, $groupTeams, $firstDate, 19, 0, 7, $cupName, max(1, count($groupTeams) - 1), $groupName, 'Gruppen');
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
