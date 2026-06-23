<?php
class CupScheduleDataService {

    /****************************************
     *
     * CUP SCHEDULING CLASSES
     *
     ****************************************/
    public static function getCountryCups(WebSoccer $websoccer, DbConnection $db) {
        $prefix = $websoccer->getConfig("db_prefix");
        $cups = array();

        // National cups use the existing CM23 convention:
        // cup.name equals liga.land / land.name. This avoids mixing them with
        // international cups like UEFA Champions League or Copa Libertadores.
        $sqlStr = "SELECT DISTINCT C.id, C.name
            FROM " . $prefix . "_cup AS C
            INNER JOIN " . $prefix . "_liga AS L ON L.land = C.name
            WHERE C.archived = '0'
            ORDER BY C.name";

        $result = $db->executeQuery($sqlStr);
        while ($row = $result->fetch_assoc()) {
            $cups[] = $row;
        }
        $result->free();

        return $cups;
    }

    public static function getCupRoundsByCupId(WebSoccer $websoccer, DbConnection $db, $cupId) {
        $cupId = (int) $cupId;
        $result = $db->querySelect(
            'COUNT(id) AS rounds',
            $websoccer->getConfig("db_prefix") . '_cup_round',
            'cup_id = %d',
            $cupId
        );
        $round = $result->fetch_assoc();
        $result->free();

        return isset($round['rounds']) ? (int) $round['rounds'] : 0;
    }

    public static function getFirstMatchDayOfCup(WebSoccer $websoccer, DbConnection $db, $cupId) {
        $cupId = (int) $cupId;
        $result = $db->querySelect(
            'firstround_date',
            $websoccer->getConfig("db_prefix") . '_cup_round',
            "cup_id = %d AND (name = 'Round 1' OR name = 'Runde 1') ORDER BY id ASC",
            $cupId,
            1
        );
        $round = $result->fetch_assoc();
        $result->free();

        return ($round && isset($round['firstround_date'])) ? (int) $round['firstround_date'] : 0;
    }

    public static function getTeamsByCupName(WebSoccer $websoccer, DbConnection $db, $cupName, $limit) {
        $teams = array();
        $prefix = $websoccer->getConfig("db_prefix");
        $limit = max(2, (int) $limit);

        $fromTable = $prefix . '_liga AS L INNER JOIN ' . $prefix . '_verein AS C ON C.liga_id = L.id';
        $whereCondition = "L.land = '%s' AND C.status = '1'
            ORDER BY L.division ASC,
                C.sa_punkte DESC,
                (C.sa_tore - C.sa_gegentore) DESC,
                C.sa_siege DESC,
                C.sa_unentschieden DESC,
                C.sa_tore DESC,
                C.name ASC";

        $result = $db->querySelect(
            'C.id AS club_id',
            $fromTable,
            $whereCondition,
            (string) $cupName,
            '0,' . $limit
        );

        while ($row = $result->fetch_assoc()) {
            $teams[] = $row;
        }
        $result->free();

        return $teams;
    }

    public static function hasFirstRoundMatches(WebSoccer $websoccer, DbConnection $db, $cupName) {
        $result = $db->querySelect(
            'COUNT(id) AS matches_count',
            $websoccer->getConfig("db_prefix") . '_spiel',
            "spieltyp = 'Pokalspiel' AND pokalname = '%s' AND (pokalrunde = 'Round 1' OR pokalrunde = 'Runde 1')",
            (string) $cupName
        );
        $row = $result->fetch_assoc();
        $result->free();

        return ($row && (int) $row['matches_count'] > 0);
    }

    public static function createFirstCupMatch(WebSoccer $websoccer, DbConnection $db) {
        $created = 0;
        $skipped = 0;
        $cups = self::getCountryCups($websoccer, $db);

        foreach ($cups as $cup) {
            $cupId = (int) $cup['id'];
            $cupName = (string) $cup['name'];

            if ($cupId <= 0 || $cupName === '') {
                $skipped++;
                continue;
            }

            if (self::hasFirstRoundMatches($websoccer, $db, $cupName)) {
                $skipped++;
                continue;
            }

            $cupRoundNumber = self::getCupRoundsByCupId($websoccer, $db, $cupId);
            $teamLimit = (int) pow(2, $cupRoundNumber);
            $firstmatchDate = self::getFirstMatchDayOfCup($websoccer, $db, $cupId);

            if ($cupRoundNumber < 1 || $teamLimit < 2 || $firstmatchDate <= 0) {
                $skipped++;
                continue;
            }

            $teams = self::getTeamsByCupName($websoccer, $db, $cupName, $teamLimit);

            if (count($teams) < 2) {
                $skipped++;
                continue;
            }

            ScheduleGenerator::generateCupMatchSchedule($websoccer, $db, $teams, $firstmatchDate, $cupName);
            $created++;
        }

        return array('created' => $created, 'skipped' => $skipped);
    }

    public static function test() {
        return 'abc';
    }
}
?>
