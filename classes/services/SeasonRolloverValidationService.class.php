<?php
/******************************************************

  Season rollover validation helpers for OpenWebSoccer-Sim.

******************************************************/

/**
 * Collects pre-flight information for the season rollover wizard.
 */
class SeasonRolloverValidationService {

    public static function getOverview(WebSoccer $websoccer, DbConnection $db) {
        $prefix = $websoccer->getConfig('db_prefix');

        return array(
            'open_seasons' => self::countRows($db, $prefix . '_saison', "beendet = '0'"),
            'eligible_seasons' => count(self::getEligibleSeasons($websoccer, $db)),
            'leagues_total' => self::countRows($db, $prefix . '_liga', '1 = 1'),
            'leagues_without_open_season' => count(self::getLeaguesWithoutOpenSeason($websoccer, $db)),
            'uncalculated_league_matches' => self::countRows($db, $prefix . '_spiel', "spieltyp = 'Ligaspiel' AND berechnet = '0'"),
            'uncalculated_cup_matches' => self::countRows($db, $prefix . '_spiel', "spieltyp = 'Pokalspiel' AND berechnet = '0'"),
            'uncalculated_competitive_matches' => self::countOpenCompetitiveMatches($websoccer, $db),
            'uncalculated_matches_total' => self::countRows($db, $prefix . '_spiel', "berechnet = '0'"),
            'duplicate_team_bookings' => self::countDuplicateTeamBookings($websoccer, $db),
            'parent_club_division_conflicts' => class_exists('ParentClubDataService') ? ParentClubDataService::countActiveDivisionConflicts($websoccer, $db) : 0,
            'national_countries' => self::countCountriesWithTeams($websoccer, $db),
            'uefa_countries' => self::countRows($db, $prefix . '_land', '1 = 1'),
            'uefa_temp_teams' => self::tableExists($db, $prefix . '_uefa_temp') ? self::countRows($db, $prefix . '_uefa_temp', '1 = 1') : 0,
            'conmebol_countries' => self::countRows($db, $prefix . '_land', "continent = 'CONMEBOL'"),
            'conmebol_temp_teams' => self::tableExists($db, $prefix . '_conmebol_temp') ? self::countRows($db, $prefix . '_conmebol_temp', '1 = 1') : 0,
            'concacaf_countries' => self::countRows($db, $prefix . '_land', "continent = 'CONCACAF'"),
            'concacaf_leagues' => self::countConcacafLeagues($websoccer, $db),
            'concacaf_teams' => self::countConcacafTeams($websoccer, $db),
            'concacaf_players' => self::countConcacafPlayers($websoccer, $db),
            'concacaf_youthplayers' => self::countConcacafYouthPlayers($websoccer, $db),
            'concacaf_temp_teams' => self::tableExists($db, $prefix . '_concacaf_temp') ? self::countRows($db, $prefix . '_concacaf_temp', '1 = 1') : 0,
            'champions_league_exists' => self::cupExists($websoccer, $db, 'Champions League'),
            'uefa_league_exists' => self::cupExists($websoccer, $db, 'UEFA Euro League'),
            'copa_libertadores_exists' => self::cupExists($websoccer, $db, 'Copa Libertadores'),
            'copa_sudamericana_exists' => self::cupExists($websoccer, $db, 'Copa Sudamericana'),
            'concacaf_champions_cup_exists' => self::cupExists($websoccer, $db, 'CONCACAF Champions Cup'),
            'champions_league_group_round' => self::cupGroupRoundExists($websoccer, $db, 'Champions League'),
            'uefa_league_group_round' => self::cupGroupRoundExists($websoccer, $db, 'UEFA Euro League'),
            'copa_libertadores_group_round' => self::cupGroupRoundExists($websoccer, $db, 'Copa Libertadores'),
            'copa_sudamericana_group_round' => self::cupGroupRoundExists($websoccer, $db, 'Copa Sudamericana'),
            'concacaf_champions_cup_group_round' => self::cupGroupRoundExists($websoccer, $db, 'CONCACAF Champions Cup')
        );
    }

    public static function getEligibleSeasons(WebSoccer $websoccer, DbConnection $db) {
        $prefix = $websoccer->getConfig('db_prefix');

        $columns = 'S.id AS id, S.name AS name, S.liga_id AS league_id, L.name AS league_name, L.land AS league_country';
        $fromTable = $prefix . '_saison AS S INNER JOIN ' . $prefix . '_liga AS L ON L.id = S.liga_id';
        $whereCondition = "S.beendet = '0'
            AND 0 = (
                SELECT COUNT(*)
                FROM " . $prefix . "_spiel AS M
                WHERE M.berechnet = '0'
                AND M.saison_id = S.id
            )
            ORDER BY L.land ASC, L.division ASC, L.name ASC, S.name ASC";

        $result = $db->querySelect($columns, $fromTable, $whereCondition);

        $seasons = array();
        while ($season = $result->fetch_array()) {
            $seasons[] = $season;
        }
        $result->free();

        return $seasons;
    }

    public static function getLeaguesWithoutOpenSeason(WebSoccer $websoccer, DbConnection $db) {
        $prefix = $websoccer->getConfig('db_prefix');

        $columns = 'L.id AS league_id, L.name AS league_name, L.land AS league_country, L.division AS league_division';
        $fromTable = $prefix . '_liga AS L';
        $whereCondition = "0 = (
                SELECT COUNT(*)
                FROM " . $prefix . "_saison AS S
                WHERE S.liga_id = L.id
                AND S.beendet = '0'
            )
            ORDER BY L.land ASC, L.division ASC, L.name ASC";

        $result = $db->querySelect($columns, $fromTable, $whereCondition);

        $leagues = array();
        while ($league = $result->fetch_array()) {
            $leagues[] = $league;
        }
        $result->free();

        return $leagues;
    }


    public static function countOpenCompetitiveMatches(WebSoccer $websoccer, DbConnection $db) {
        $prefix = $websoccer->getConfig('db_prefix');

        return self::countRows(
            $db,
            $prefix . '_spiel',
            "berechnet = '0' AND spieltyp IN ('Ligaspiel', 'Pokalspiel')"
        );
    }

    public static function getOpenCompetitiveMatchesSummary(WebSoccer $websoccer, DbConnection $db) {
        $prefix = $websoccer->getConfig('db_prefix');
        $summary = array();

        $sql = "
            SELECT
                spieltyp,
                COUNT(*) AS matches,
                MIN(datum) AS first_match,
                MAX(datum) AS last_match
            FROM " . $prefix . "_spiel
            WHERE berechnet = '0'
            AND spieltyp IN ('Ligaspiel', 'Pokalspiel')
            GROUP BY spieltyp
            ORDER BY spieltyp ASC
        ";

        $result = $db->executeQuery($sql);
        while ($row = $result->fetch_array()) {
            $summary[] = array(
                'spieltyp' => $row['spieltyp'],
                'matches' => (int) $row['matches'],
                'first_match' => !empty($row['first_match']) ? date('d.m.Y H:i', (int) $row['first_match']) : '-',
                'last_match' => !empty($row['last_match']) ? date('d.m.Y H:i', (int) $row['last_match']) : '-'
            );
        }
        $result->free();

        return $summary;
    }

    public static function getBlockingErrorsForStep(WebSoccer $websoccer, DbConnection $db, $step) {
        $overview = self::getOverview($websoccer, $db);
        $errors = array();

        $stepsRequiringFinishedCompetitiveMatches = array(
            'end_seasons',
            'uefa_temp',
            'new_seasons',
            'national_cups',
            'european_cups',
            'league_schedules',
            'execute_all'
        );

        if (in_array($step, $stepsRequiringFinishedCompetitiveMatches, true)
            && (int) $overview['uncalculated_competitive_matches'] > 0) {
            $errors[] = 'Es gibt noch ' . (int) $overview['uncalculated_competitive_matches'] . ' unberechnete Pflichtspiele. Der Saisonwechsel ist gesperrt, bis alle Liga- und Pokalspiele berechnet wurden.';
        }

        if ($step === 'new_seasons'
            || $step === 'national_cups'
            || $step === 'european_cups'
            || $step === 'league_schedules') {
            if ((int) $overview['open_seasons'] > 0) {
                $errors[] = 'Es gibt noch ' . (int) $overview['open_seasons'] . ' offene Saison(en). Neue Saisons, Pokale und Spielpläne dürfen erst nach dem vollständigen Saisonabschluss erzeugt werden.';
            }
        }

        if ($step === 'execute_all') {
            if ((int) $overview['open_seasons'] === 0) {
                $errors[] = 'Es gibt keine offene Saison, die beendet werden kann. Bitte den passenden Einzelschritt verwenden.';
            }
        }

        return $errors;
    }

    public static function countRows(DbConnection $db, $table, $whereCondition) {
        $result = $db->querySelect('COUNT(*) AS hits', $table, $whereCondition);
        $row = $result->fetch_array();
        $result->free();

        return $row ? (int) $row['hits'] : 0;
    }


    public static function tableExists(DbConnection $db, $tableName) {
        try {
            $result = $db->executeQuery('SHOW TABLES LIKE \'' . $db->connection->real_escape_string($tableName) . '\'');
            $exists = ($result && $result->num_rows > 0);
            if ($result) {
                $result->free();
            }
            return $exists;
        } catch (Exception $e) {
            return false;
        }
    }


    public static function countConcacafLeagues(WebSoccer $websoccer, DbConnection $db) {
        $prefix = $websoccer->getConfig('db_prefix');
        $sql = "
            SELECT COUNT(*) AS hits
            FROM {$prefix}_liga AS LG
            INNER JOIN {$prefix}_land AS L ON L.name = LG.land
            WHERE L.continent = 'CONCACAF'
        ";

        $result = $db->executeQuery($sql);
        $row = $result->fetch_array();
        $result->free();
        return $row ? (int) $row['hits'] : 0;
    }

    public static function countConcacafTeams(WebSoccer $websoccer, DbConnection $db) {
        $prefix = $websoccer->getConfig('db_prefix');
        $sql = "
            SELECT COUNT(*) AS hits
            FROM {$prefix}_verein AS C
            INNER JOIN {$prefix}_liga AS LG ON LG.id = C.liga_id
            INNER JOIN {$prefix}_land AS L ON L.name = LG.land
            WHERE L.continent = 'CONCACAF'
        ";

        $result = $db->executeQuery($sql);
        $row = $result->fetch_array();
        $result->free();
        return $row ? (int) $row['hits'] : 0;
    }

    public static function countConcacafPlayers(WebSoccer $websoccer, DbConnection $db) {
        $prefix = $websoccer->getConfig('db_prefix');
        $sql = "
            SELECT COUNT(*) AS hits
            FROM {$prefix}_spieler AS P
            INNER JOIN {$prefix}_verein AS C ON C.id = P.verein_id
            INNER JOIN {$prefix}_liga AS LG ON LG.id = C.liga_id
            INNER JOIN {$prefix}_land AS L ON L.name = LG.land
            WHERE L.continent = 'CONCACAF'
            AND P.status = '1'
        ";

        $result = $db->executeQuery($sql);
        $row = $result->fetch_array();
        $result->free();
        return $row ? (int) $row['hits'] : 0;
    }

    public static function countConcacafYouthPlayers(WebSoccer $websoccer, DbConnection $db) {
        $prefix = $websoccer->getConfig('db_prefix');
        $sql = "
            SELECT COUNT(*) AS hits
            FROM {$prefix}_youthplayer AS YP
            INNER JOIN {$prefix}_verein AS C ON C.id = YP.team_id
            INNER JOIN {$prefix}_liga AS LG ON LG.id = C.liga_id
            INNER JOIN {$prefix}_land AS L ON L.name = LG.land
            WHERE L.continent = 'CONCACAF'
        ";

        $result = $db->executeQuery($sql);
        $row = $result->fetch_array();
        $result->free();
        return $row ? (int) $row['hits'] : 0;
    }

    public static function countCountriesWithTeams(WebSoccer $websoccer, DbConnection $db) {
        $prefix = $websoccer->getConfig('db_prefix');
        $sql = "
            SELECT COUNT(*) AS hits
            FROM (
                SELECT LG.land
                FROM {$prefix}_verein AS C
                INNER JOIN {$prefix}_liga AS LG ON C.liga_id = LG.id
                GROUP BY LG.land
            ) AS X
        ";

        $result = $db->executeQuery($sql);
        $row = $result->fetch_array();
        $result->free();

        return $row ? (int) $row['hits'] : 0;
    }

    public static function countDuplicateTeamBookings(WebSoccer $websoccer, DbConnection $db) {
        $prefix = $websoccer->getConfig('db_prefix');

        $sql = "
            SELECT COUNT(*) AS hits
            FROM (
                SELECT team_id, match_day, COUNT(*) AS booked
                FROM (
                    SELECT home_verein AS team_id, FROM_UNIXTIME(datum, '%Y-%m-%d') AS match_day
                    FROM {$prefix}_spiel
                    UNION ALL
                    SELECT gast_verein AS team_id, FROM_UNIXTIME(datum, '%Y-%m-%d') AS match_day
                    FROM {$prefix}_spiel
                ) AS Bookings
                WHERE team_id > 0
                GROUP BY team_id, match_day
                HAVING booked > 1
            ) AS Duplicates
        ";

        $result = $db->executeQuery($sql);
        $row = $result->fetch_array();
        $result->free();

        return $row ? (int) $row['hits'] : 0;
    }

    public static function cupExists(WebSoccer $websoccer, DbConnection $db, $cupName) {
        $prefix = $websoccer->getConfig('db_prefix');

        $result = $db->querySelect(
            'id',
            $prefix . '_cup',
            "name = '%s'",
            (string) $cupName,
            1
        );

        $cup = $result->fetch_array();
        $result->free();

        return $cup ? true : false;
    }

    public static function cupGroupRoundExists(WebSoccer $websoccer, DbConnection $db, $cupName) {
        $prefix = $websoccer->getConfig('db_prefix');

        $fromTable = $prefix . '_cup AS C INNER JOIN ' . $prefix . '_cup_round AS R ON R.cup_id = C.id';
        $result = $db->querySelect(
            'R.id',
            $fromTable,
            "C.name = '%s' AND R.name = 'Gruppen'",
            (string) $cupName,
            1
        );

        $round = $result->fetch_array();
        $result->free();

        return $round ? true : false;
    }
}
?>
