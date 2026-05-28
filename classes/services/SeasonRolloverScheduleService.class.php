<?php
/******************************************************

  Season rollover schedule helpers for OpenWebSoccer-Sim.

******************************************************/

/**
 * Central schedule helper used by the season rollover wizard.
 */
class SeasonRolloverScheduleService {

    const MATCH_TYPE_LEAGUE = 'Ligaspiel';
    const MATCH_TYPE_CUP = 'Pokalspiel';

    public static function parseGermanDate($dateString, $hour = 15, $minute = 0) {
        $dateString = trim((string) $dateString);
        $parts = explode('.', $dateString);

        if (count($parts) !== 3) {
            throw new Exception('Ungültiges Datum. Erwartetes Format: TT.MM.JJJJ.');
        }

        $day = (int) $parts[0];
        $month = (int) $parts[1];
        $year = (int) $parts[2];
        $hour = (int) $hour;
        $minute = (int) $minute;

        if (!checkdate($month, $day, $year)) {
            throw new Exception('Ungültiges Datum.');
        }

        if ($hour < 0 || $hour > 23 || $minute < 0 || $minute > 59) {
            throw new Exception('Ungültige Uhrzeit.');
        }

        return mktime($hour, $minute, 0, $month, $day, $year);
    }

    public static function formatGermanDate($timestamp) {
        return date('d.m.Y', (int) $timestamp);
    }

    public static function nextWeekday($timestamp, $targetWeekday, $hour, $minute) {
        $timestamp = (int) $timestamp;
        $targetWeekday = (int) $targetWeekday;

        $day = (int) date('j', $timestamp);
        $month = (int) date('n', $timestamp);
        $year = (int) date('Y', $timestamp);
        $currentWeekday = (int) date('N', $timestamp);

        $daysToAdd = ($targetWeekday - $currentWeekday + 7) % 7;

        return mktime((int) $hour, (int) $minute, 0, $month, $day + $daysToAdd, $year);
    }

    public static function addDays($timestamp, $days, $hour = null, $minute = null) {
        $timestamp = (int) $timestamp;
        $hour = ($hour === null) ? (int) date('G', $timestamp) : (int) $hour;
        $minute = ($minute === null) ? (int) date('i', $timestamp) : (int) $minute;

        return mktime(
            $hour,
            $minute,
            0,
            (int) date('n', $timestamp),
            (int) date('j', $timestamp) + (int) $days,
            (int) date('Y', $timestamp)
        );
    }

    public static function teamsHaveMatchOnDay(WebSoccer $websoccer, DbConnection $db, array $teamIds, $timestamp) {
        $teamIds = array_values(array_unique(array_filter(array_map('intval', $teamIds), function($teamId) {
            return $teamId > 0;
        })));

        if (empty($teamIds)) {
            return false;
        }

        $dayStart = mktime(0, 0, 0, (int) date('n', $timestamp), (int) date('j', $timestamp), (int) date('Y', $timestamp));
        $nextDayStart = self::addDays($dayStart, 1, 0, 0);
        $teamSql = implode(',', $teamIds);
        $prefix = $websoccer->getConfig('db_prefix');

        $result = $db->querySelect(
            'id',
            $prefix . '_spiel',
            "datum >= %d AND datum < %d AND (home_verein IN (" . $teamSql . ") OR gast_verein IN (" . $teamSql . "))",
            array($dayStart, $nextDayStart),
            1
        );

        $match = $result->fetch_array();
        $result->free();

        return $match ? true : false;
    }

    public static function findAvailableTimestampForTeams(WebSoccer $websoccer, DbConnection $db, array $teamIds, $baseTimestamp, array $allowedWeekdays, array $slots, $maxDays = 90) {
        $baseTimestamp = (int) $baseTimestamp;
        $maxDays = (int) $maxDays;

        for ($offset = 0; $offset <= $maxDays; $offset++) {
            $candidateDay = self::addDays($baseTimestamp, $offset, 0, 0);
            $weekday = (int) date('N', $candidateDay);

            if (!in_array($weekday, $allowedWeekdays)) {
                continue;
            }

            if (self::teamsHaveMatchOnDay($websoccer, $db, $teamIds, $candidateDay)) {
                continue;
            }

            foreach ($slots as $slot) {
                $hour = isset($slot[0]) ? (int) $slot[0] : 15;
                $minute = isset($slot[1]) ? (int) $slot[1] : 0;

                return mktime(
                    $hour,
                    $minute,
                    0,
                    (int) date('n', $candidateDay),
                    (int) date('j', $candidateDay),
                    (int) date('Y', $candidateDay)
                );
            }
        }

        throw new Exception('Kein freier Termin für ein Spiel gefunden.');
    }

    public static function generateLeagueSchedulesForOpenSeasons(WebSoccer $websoccer, DbConnection $db, $firstLeagueFridayTimestamp, $rounds = 2) {
        $prefix = $websoccer->getConfig('db_prefix');
        $firstLeagueFridayTimestamp = self::nextWeekday((int) $firstLeagueFridayTimestamp, 5, 18, 0);
        $rounds = max(1, min(4, (int) $rounds));

        $columns = 'S.id AS season_id, S.name AS season_name, L.id AS league_id, L.name AS league_name, L.land AS league_country';
        $fromTable = $prefix . '_saison AS S INNER JOIN ' . $prefix . '_liga AS L ON L.id = S.liga_id';
        $whereCondition = "S.beendet = '0'
            AND 0 = (
                SELECT COUNT(*)
                FROM " . $prefix . "_spiel AS M
                WHERE M.saison_id = S.id
                AND M.spieltyp = 'Ligaspiel'
            )
            ORDER BY L.land ASC, L.division ASC, L.name ASC";

        $result = $db->querySelect($columns, $fromTable, $whereCondition);

        $openSeasons = array();
        while ($season = $result->fetch_array()) {
            $openSeasons[] = $season;
        }
        $result->free();

        $createdMatches = 0;
        $processedSeasons = array();
        $skippedSeasons = array();

        foreach ($openSeasons as $season) {
            $leagueId = (int) $season['league_id'];
            $seasonId = (int) $season['season_id'];

            $teamResult = $db->querySelect(
                'id',
                $prefix . '_verein',
                'liga_id = %d ORDER BY id ASC',
                $leagueId
            );

            $teamIds = array();
            while ($team = $teamResult->fetch_array()) {
                $teamIds[] = (int) $team['id'];
            }
            $teamResult->free();

            if (count($teamIds) < 2) {
                $skippedSeasons[] = array(
                    'season' => $season,
                    'reason' => 'Nicht genug Teams.'
                );
                continue;
            }

            $baseSchedule = array_values(ScheduleGenerator::createRoundRobinSchedule($teamIds));
            if (empty($baseSchedule)) {
                $skippedSeasons[] = array(
                    'season' => $season,
                    'reason' => 'Spielplan konnte nicht erzeugt werden.'
                );
                continue;
            }

            $fullSchedule = array();
            for ($round = 1; $round <= $rounds; $round++) {
                foreach ($baseSchedule as $matchesOfMatchday) {
                    $matchesForThisMatchday = array();
                    foreach ($matchesOfMatchday as $match) {
                        if (!isset($match[0], $match[1])) {
                            continue;
                        }
                        $matchesForThisMatchday[] = ($round % 2 === 1)
                            ? array((int) $match[0], (int) $match[1])
                            : array((int) $match[1], (int) $match[0]);
                    }
                    $fullSchedule[] = $matchesForThisMatchday;
                }
            }

            $leagueMatchSlots = array(
                array(18, 0), // Friday
                array(15, 30),
                array(18, 0),
                array(15, 30),
                array(18, 0)
            );

            foreach ($fullSchedule as $matchdayIndex => $matches) {
                $matchdayNumber = $matchdayIndex + 1;
                $matchdayBase = self::addDays($firstLeagueFridayTimestamp, ($matchdayNumber - 1) * 7, 18, 0);

                foreach ($matches as $matchIndex => $match) {
                    $homeTeam = (int) $match[0];
                    $guestTeam = (int) $match[1];

                    $slotIndex = $matchIndex % count($leagueMatchSlots);
                    $weekdayOffset = 0;

                    if ($slotIndex === 1 || $slotIndex === 2) {
                        $weekdayOffset = 1; // Saturday
                    } elseif ($slotIndex === 3 || $slotIndex === 4) {
                        $weekdayOffset = 2; // Sunday
                    }

                    $slot = $leagueMatchSlots[$slotIndex];
                    $timestamp = self::addDays($matchdayBase, $weekdayOffset, $slot[0], $slot[1]);

                    if (self::teamsHaveMatchOnDay($websoccer, $db, array($homeTeam, $guestTeam), $timestamp)) {
                        $timestamp = self::findAvailableTimestampForTeams(
                            $websoccer,
                            $db,
                            array($homeTeam, $guestTeam),
                            $matchdayBase,
                            array(5, 6, 7),
                            array($slot),
                            6
                        );
                    }

                    $db->queryInsert(
                        array(
                            'spieltyp' => self::MATCH_TYPE_LEAGUE,
                            'liga_id' => $leagueId,
                            'saison_id' => $seasonId,
                            'spieltag' => $matchdayNumber,
                            'home_verein' => $homeTeam,
                            'gast_verein' => $guestTeam,
                            'datum' => $timestamp
                        ),
                        $prefix . '_spiel'
                    );

                    $createdMatches++;
                }
            }

            $processedSeasons[] = $season;
        }

        return array(
            'processed_seasons' => $processedSeasons,
            'skipped_seasons' => $skippedSeasons,
            'created_matches' => $createdMatches
        );
    }
}
?>
