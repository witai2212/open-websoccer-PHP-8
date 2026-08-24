<?php
/******************************************************

  Season rollover schedule helpers for OpenWebSoccer-Sim.
  CM23 Task 1002 | 24.08.2026 | Revision 2

******************************************************/

/**
 * Central schedule helper used by the season rollover wizard.
 */
class SeasonRolloverScheduleService {

    const MATCH_TYPE_LEAGUE = 'Ligaspiel';
    const MATCH_TYPE_CUP = 'Pokalspiel';

    const LEAGUE_KICKOFF_HOUR = 11;
    const LEAGUE_KICKOFF_MINUTE = 0;
    const CUP_KICKOFF_HOUR = 20;
    const CUP_KICKOFF_MINUTE = 0;

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

    public static function nextWeekdayStrictlyAfter($timestamp, $targetWeekday, $hour, $minute) {
        $nextDay = self::addDays((int) $timestamp, 1, 0, 0);
        return self::nextWeekday($nextDay, (int) $targetWeekday, (int) $hour, (int) $minute);
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

    public static function getLeagueWeekdays() {
        return array(5, 6, 7, 1, 2);
    }

    public static function getCupWeekdays() {
        return array(2, 3, 4);
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

    public static function isConfiguredCupRoundDay(WebSoccer $websoccer, DbConnection $db, $timestamp) {
        $dayStart = mktime(0, 0, 0, (int) date('n', $timestamp), (int) date('j', $timestamp), (int) date('Y', $timestamp));
        $nextDayStart = self::addDays($dayStart, 1, 0, 0);
        $prefix = $websoccer->getConfig('db_prefix');

        $result = $db->querySelect(
            'id',
            $prefix . '_cup_round',
            "((firstround_date >= %d AND firstround_date < %d) OR (secondround_date >= %d AND secondround_date < %d))",
            array($dayStart, $nextDayStart, $dayStart, $nextDayStart),
            1
        );

        $round = $result->fetch_array();
        $result->free();

        return $round ? true : false;
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

    public static function getOpenSeasonsWithoutLeagueMatches(WebSoccer $websoccer, DbConnection $db) {
        $prefix = $websoccer->getConfig('db_prefix');
        $columns = 'S.id AS season_id, S.name AS season_name, L.id AS league_id, L.name AS league_name, L.land AS league_country, L.division AS league_division';
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
        $seasons = array();

        while ($season = $result->fetch_array()) {
            $seasons[] = $season;
        }

        $result->free();
        return $seasons;
    }

    public static function getLeagueTeamIds(WebSoccer $websoccer, DbConnection $db, $leagueId) {
        $prefix = $websoccer->getConfig('db_prefix');
        $result = $db->querySelect(
            'id',
            $prefix . '_verein',
            "liga_id = %d AND status = '1' ORDER BY id ASC",
            (int) $leagueId
        );

        $teamIds = array();
        while ($team = $result->fetch_array()) {
            $teamIds[] = (int) $team['id'];
        }

        $result->free();
        return $teamIds;
    }

    public static function getLeagueMatchdayCountForTeamCount($teamCount, $rounds = 2) {
        $teamCount = (int) $teamCount;
        $rounds = max(1, min(4, (int) $rounds));

        if ($teamCount < 2) {
            return 0;
        }

        $matchdaysPerRound = ($teamCount % 2 === 0) ? ($teamCount - 1) : $teamCount;
        return $matchdaysPerRound * $rounds;
    }

    public static function calculateCommonLeagueEndTimestamp(WebSoccer $websoccer, DbConnection $db, $firstLeagueFridayTimestamp, $rounds = 2) {
        $firstLeagueFridayTimestamp = self::nextWeekday(
            (int) $firstLeagueFridayTimestamp,
            5,
            self::LEAGUE_KICKOFF_HOUR,
            self::LEAGUE_KICKOFF_MINUTE
        );
        $rounds = max(1, min(4, (int) $rounds));

        $maxMatchdays = 0;
        $seasons = self::getOpenSeasonsWithoutLeagueMatches($websoccer, $db);

        foreach ($seasons as $season) {
            $teamIds = self::getLeagueTeamIds($websoccer, $db, (int) $season['league_id']);
            $matchdays = self::getLeagueMatchdayCountForTeamCount(count($teamIds), $rounds);
            if ($matchdays > $maxMatchdays) {
                $maxMatchdays = $matchdays;
            }
        }

        if ($maxMatchdays < 1) {
            return self::addDays($firstLeagueFridayTimestamp, 4, self::LEAGUE_KICKOFF_HOUR, self::LEAGUE_KICKOFF_MINUTE);
        }

        $lastMatchweekFriday = self::addDays(
            $firstLeagueFridayTimestamp,
            ($maxMatchdays - 1) * 7,
            self::LEAGUE_KICKOFF_HOUR,
            self::LEAGUE_KICKOFF_MINUTE
        );

        return self::addDays(
            $lastMatchweekFriday,
            4,
            self::LEAGUE_KICKOFF_HOUR,
            self::LEAGUE_KICKOFF_MINUTE
        );
    }

    public static function getNationalCupFinalTimestamp($commonLeagueEndTimestamp) {
        return self::nextWeekdayStrictlyAfter(
            (int) $commonLeagueEndTimestamp,
            2,
            self::CUP_KICKOFF_HOUR,
            self::CUP_KICKOFF_MINUTE
        );
    }

    public static function getInternationalCupFinalTimestamp($nationalCupFinalTimestamp, $targetWeekday = 4) {
        $targetWeekday = (int) $targetWeekday;

        if (!in_array($targetWeekday, self::getCupWeekdays(), true)) {
            throw new Exception('Ungültiger Wochentag für internationales Pokalfinale.');
        }

        $oneWeekLater = self::addDays((int) $nationalCupFinalTimestamp, 7, 0, 0);

        return self::nextWeekday(
            $oneWeekLater,
            $targetWeekday,
            self::CUP_KICKOFF_HOUR,
            self::CUP_KICKOFF_MINUTE
        );
    }

    public static function buildEvenlyDistributedCupRoundDates($startTimestamp, $finalTimestamp, $roundCount, array $blackoutTimestamps = array()) {
        $roundCount = (int) $roundCount;
        if ($roundCount < 1) {
            return array();
        }

        $startTimestamp = (int) $startTimestamp;
        $finalTimestamp = (int) $finalTimestamp;

        if ($finalTimestamp <= $startTimestamp) {
            throw new Exception('Pokal-Finaltermin muss nach dem Pokalstart liegen.');
        }

        $blackoutDays = array();
        foreach ($blackoutTimestamps as $blackoutTimestamp) {
            $blackoutDays[date('Y-m-d', (int) $blackoutTimestamp)] = true;
        }

        $allowedWeekdays = self::getCupWeekdays();
        $candidates = array();
        $cursor = mktime(
            self::CUP_KICKOFF_HOUR,
            self::CUP_KICKOFF_MINUTE,
            0,
            (int) date('n', $startTimestamp),
            (int) date('j', $startTimestamp),
            (int) date('Y', $startTimestamp)
        );

        if ($cursor < $startTimestamp) {
            $cursor = self::addDays($cursor, 1, self::CUP_KICKOFF_HOUR, self::CUP_KICKOFF_MINUTE);
        }

        while ($cursor <= $finalTimestamp) {
            $weekday = (int) date('N', $cursor);
            $dayKey = date('Y-m-d', $cursor);

            if (in_array($weekday, $allowedWeekdays, true) && !isset($blackoutDays[$dayKey])) {
                $candidates[] = $cursor;
            }

            $cursor = self::addDays($cursor, 1, self::CUP_KICKOFF_HOUR, self::CUP_KICKOFF_MINUTE);
        }

        $finalDayKey = date('Y-m-d', $finalTimestamp);
        if (
            in_array((int) date('N', $finalTimestamp), $allowedWeekdays, true)
            && !isset($blackoutDays[$finalDayKey])
        ) {
            $normalizedFinal = mktime(
                self::CUP_KICKOFF_HOUR,
                self::CUP_KICKOFF_MINUTE,
                0,
                (int) date('n', $finalTimestamp),
                (int) date('j', $finalTimestamp),
                (int) date('Y', $finalTimestamp)
            );

            if (empty($candidates) || end($candidates) !== $normalizedFinal) {
                $candidates[] = $normalizedFinal;
            }
        }

        $candidates = array_values(array_unique($candidates));
        sort($candidates, SORT_NUMERIC);

        if (count($candidates) < $roundCount) {
            throw new Exception('Nicht genug Pokaltermine zwischen Start und Finale verfügbar.');
        }

        if ($roundCount === 1) {
            return array($candidates[count($candidates) - 1]);
        }

        $selected = array();
        $candidateCount = count($candidates);
        $previousIndex = -1;

        for ($roundIndex = 0; $roundIndex < $roundCount; $roundIndex++) {
            if ($roundIndex === $roundCount - 1) {
                $candidateIndex = $candidateCount - 1;
            } else {
                $candidateIndex = (int) round(
                    $roundIndex * ($candidateCount - 1) / ($roundCount - 1)
                );

                $minimumIndex = $previousIndex + 1;
                $maximumIndex = $candidateCount - ($roundCount - $roundIndex);

                if ($candidateIndex < $minimumIndex) {
                    $candidateIndex = $minimumIndex;
                }
                if ($candidateIndex > $maximumIndex) {
                    $candidateIndex = $maximumIndex;
                }
            }

            $selected[] = $candidates[$candidateIndex];
            $previousIndex = $candidateIndex;
        }

        return $selected;
    }

    private static function createFullLeagueSchedule(array $teamIds, $rounds) {
        $baseSchedule = array_values(ScheduleGenerator::createRoundRobinSchedule($teamIds));
        if (empty($baseSchedule)) {
            return array();
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

        return $fullSchedule;
    }

    private static function findLeagueMatchTimestamp(WebSoccer $websoccer, DbConnection $db, array $teamIds, $matchweekFriday, $preferredOffset, $isFinalMatchday) {
        $preferredOffset = max(0, min(4, (int) $preferredOffset));

        if ($isFinalMatchday) {
            $candidate = self::addDays(
                $matchweekFriday,
                4,
                self::LEAGUE_KICKOFF_HOUR,
                self::LEAGUE_KICKOFF_MINUTE
            );

            if (
                self::isConfiguredCupRoundDay($websoccer, $db, $candidate)
                || self::teamsHaveMatchOnDay($websoccer, $db, $teamIds, $candidate)
            ) {
                throw new Exception(
                    'Der gemeinsame letzte Ligaspieltag kollidiert mit einem Pokaltermin. '
                    . 'Bitte die Pokal-Starttermine im Saisonwechsel-Assistenten anpassen.'
                );
            }

            return $candidate;
        }

        $offsets = array();
        for ($step = 0; $step < 5; $step++) {
            $offsets[] = ($preferredOffset + $step) % 5;
        }

        foreach ($offsets as $offset) {
            $candidate = self::addDays(
                $matchweekFriday,
                $offset,
                self::LEAGUE_KICKOFF_HOUR,
                self::LEAGUE_KICKOFF_MINUTE
            );

            if (self::isConfiguredCupRoundDay($websoccer, $db, $candidate)) {
                continue;
            }

            if (self::teamsHaveMatchOnDay($websoccer, $db, $teamIds, $candidate)) {
                continue;
            }

            return $candidate;
        }

        throw new Exception('Kein konfliktfreier Liga-Termin innerhalb der Spielwoche gefunden.');
    }

    public static function generateLeagueSchedulesForOpenSeasons(WebSoccer $websoccer, DbConnection $db, $firstLeagueFridayTimestamp, $rounds = 2) {
        $prefix = $websoccer->getConfig('db_prefix');
        $firstLeagueFridayTimestamp = self::nextWeekday(
            (int) $firstLeagueFridayTimestamp,
            5,
            self::LEAGUE_KICKOFF_HOUR,
            self::LEAGUE_KICKOFF_MINUTE
        );
        $rounds = max(1, min(4, (int) $rounds));

        $openSeasons = self::getOpenSeasonsWithoutLeagueMatches($websoccer, $db);
        $preparedSeasons = array();
        $skippedSeasons = array();
        $maxMatchdays = 0;

        foreach ($openSeasons as $season) {
            $teamIds = self::getLeagueTeamIds($websoccer, $db, (int) $season['league_id']);

            if (count($teamIds) < 2) {
                $skippedSeasons[] = array(
                    'season' => $season,
                    'reason' => 'Nicht genug aktive Teams.'
                );
                continue;
            }

            $fullSchedule = self::createFullLeagueSchedule($teamIds, $rounds);
            if (empty($fullSchedule)) {
                $skippedSeasons[] = array(
                    'season' => $season,
                    'reason' => 'Spielplan konnte nicht erzeugt werden.'
                );
                continue;
            }

            $matchdayCount = count($fullSchedule);
            if ($matchdayCount > $maxMatchdays) {
                $maxMatchdays = $matchdayCount;
            }

            $preparedSeasons[] = array(
                'season' => $season,
                'schedule' => $fullSchedule,
                'matchday_count' => $matchdayCount
            );
        }

        $createdMatches = 0;
        $processedSeasons = array();

        foreach ($preparedSeasons as $preparedSeason) {
            $season = $preparedSeason['season'];
            $fullSchedule = $preparedSeason['schedule'];
            $matchdayCount = (int) $preparedSeason['matchday_count'];
            $leagueId = (int) $season['league_id'];
            $seasonId = (int) $season['season_id'];

            foreach ($fullSchedule as $matchdayIndex => $matches) {
                $matchdayNumber = $matchdayIndex + 1;

                if ($matchdayCount <= 1 || $maxMatchdays <= 1) {
                    $weekIndex = max(0, $maxMatchdays - 1);
                } else {
                    $weekIndex = (int) round(
                        $matchdayIndex * ($maxMatchdays - 1) / ($matchdayCount - 1)
                    );
                }

                $matchweekFriday = self::addDays(
                    $firstLeagueFridayTimestamp,
                    $weekIndex * 7,
                    self::LEAGUE_KICKOFF_HOUR,
                    self::LEAGUE_KICKOFF_MINUTE
                );

                $isFinalMatchday = ($matchdayNumber === $matchdayCount);

                foreach ($matches as $matchIndex => $match) {
                    $homeTeam = (int) $match[0];
                    $guestTeam = (int) $match[1];

                    $preferredOffset = $isFinalMatchday ? 4 : ($matchIndex % 5);
                    $timestamp = self::findLeagueMatchTimestamp(
                        $websoccer,
                        $db,
                        array($homeTeam, $guestTeam),
                        $matchweekFriday,
                        $preferredOffset,
                        $isFinalMatchday
                    );

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

        $commonLeagueEnd = ($maxMatchdays > 0)
            ? self::addDays(
                $firstLeagueFridayTimestamp,
                (($maxMatchdays - 1) * 7) + 4,
                self::LEAGUE_KICKOFF_HOUR,
                self::LEAGUE_KICKOFF_MINUTE
            )
            : self::addDays(
                $firstLeagueFridayTimestamp,
                4,
                self::LEAGUE_KICKOFF_HOUR,
                self::LEAGUE_KICKOFF_MINUTE
            );

        return array(
            'processed_seasons' => $processedSeasons,
            'skipped_seasons' => $skippedSeasons,
            'created_matches' => $createdMatches,
            'maximum_matchdays' => $maxMatchdays,
            'common_league_end' => self::formatGermanDate($commonLeagueEnd) . ' 11:00'
        );
    }
}
?>