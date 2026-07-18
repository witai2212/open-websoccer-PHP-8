<?php
/******************************************************

  CONCACAF data service for season rollover.

******************************************************/

/**
 * Handles CONCACAF ranking, qualification and temporary cup teams.
 *
 * The service is intentionally similar to ConmebolDataService, but keeps its
 * own coefficient columns so future CONCACAF balancing does not affect UEFA or
 * CONMEBOL settings.
 */
class ConcacafDataService {

    const CONCACAF_CHAMPIONS_CUP = 'CONCACAF Champions Cup';
    const MAX_TEAMS_FOR_CUP = 32;
    const MAX_TEAMS_PER_COUNTRY = 4;

    public static function ensureSchema(WebSoccer $websoccer, DbConnection $db) {
        $prefix = $websoccer->getConfig('db_prefix');

        $db->executeQuery("CREATE TABLE IF NOT EXISTS " . $prefix . "_concacaf_temp (
            id INT(10) NOT NULL AUTO_INCREMENT,
            verein_id INT(10) NOT NULL,
            cup_name VARCHAR(64) NOT NULL,
            PRIMARY KEY (id),
            KEY idx_concacaf_temp_cup (cup_name),
            KEY idx_concacaf_temp_team (verein_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        self::addColumnIfMissing($db, $prefix . '_land', 'concacaf_s1', "ALTER TABLE " . $prefix . "_land ADD COLUMN concacaf_s1 DECIMAL(10,3) DEFAULT 0.000");
        self::addColumnIfMissing($db, $prefix . '_land', 'concacaf_s2', "ALTER TABLE " . $prefix . "_land ADD COLUMN concacaf_s2 DECIMAL(10,3) DEFAULT 0.000");
        self::addColumnIfMissing($db, $prefix . '_land', 'concacaf_s3', "ALTER TABLE " . $prefix . "_land ADD COLUMN concacaf_s3 DECIMAL(10,3) DEFAULT 0.000");
        self::addColumnIfMissing($db, $prefix . '_land', 'concacaf_s4', "ALTER TABLE " . $prefix . "_land ADD COLUMN concacaf_s4 DECIMAL(10,3) DEFAULT 0.000");
        self::addColumnIfMissing($db, $prefix . '_land', 'concacaf_s5', "ALTER TABLE " . $prefix . "_land ADD COLUMN concacaf_s5 DECIMAL(10,3) DEFAULT 0.000");
        self::addColumnIfMissing($db, $prefix . '_land', 'concacaf_champions', "ALTER TABLE " . $prefix . "_land ADD COLUMN concacaf_champions TINYINT(1) NOT NULL DEFAULT 0");
        self::addColumnIfMissing($db, $prefix . '_land', 'concacaf_coeff', "ALTER TABLE " . $prefix . "_land ADD COLUMN concacaf_coeff DECIMAL(10,3) NOT NULL DEFAULT 0.000");
    }

    private static function addColumnIfMissing(DbConnection $db, $tableName, $columnName, $alterSql) {
        $safeTable = $db->connection->real_escape_string($tableName);
        $safeColumn = $db->connection->real_escape_string($columnName);
        $result = $db->executeQuery("SHOW COLUMNS FROM `" . $safeTable . "` LIKE '" . $safeColumn . "'");
        $exists = ($result && $result->num_rows > 0);
        if ($result) {
            $result->free();
        }
        if (!$exists) {
            $db->executeQuery($alterSql);
        }
    }

    public static function getConcacafRanking(WebSoccer $websoccer, DbConnection $db) {
        self::ensureSchema($websoccer, $db);
        $prefix = $websoccer->getConfig('db_prefix');

        $columns = "
            L.*,
            (
                COALESCE(L.concacaf_s1, 0)
                + COALESCE(L.concacaf_s2, 0)
                + COALESCE(L.concacaf_s3, 0)
                + COALESCE(L.concacaf_s4, 0)
                + COALESCE(L.concacaf_s5, 0)
            ) AS total
        ";

        $result = $db->querySelect(
            $columns,
            $prefix . '_land AS L',
            "L.continent = 'CONCACAF' ORDER BY total DESC, L.name ASC"
        );

        $ranking = array();
        while ($row = $result->fetch_array()) {
            $ranking[] = $row;
        }
        $result->free();

        return $ranking;
    }

    public static function updateQualificationPlacesByRanking(WebSoccer $websoccer, DbConnection $db) {
        self::ensureSchema($websoccer, $db);
        $prefix = $websoccer->getConfig('db_prefix');
        $ranking = self::getConcacafRanking($websoccer, $db);
        $countryCount = count($ranking);
        $distribution = self::createDistribution($countryCount, self::MAX_TEAMS_FOR_CUP, self::MAX_TEAMS_PER_COUNTRY);

        foreach ($ranking as $index => $country) {
            $countryId = (int) $country['id'];
            $total = isset($country['total']) ? number_format((float) $country['total'], 3, '.', '') : '0.000';

            $db->queryUpdate(
                array(
                    'concacaf_coeff' => $total,
                    'concacaf_champions' => isset($distribution[$index]) ? (int) $distribution[$index] : 0
                ),
                $prefix . '_land',
                'id = %d',
                $countryId
            );
        }

        return array(
            'countries_updated' => $countryCount,
            'champions_cup_total' => array_sum($distribution)
        );
    }

    private static function createDistribution($countryCount, $maxTeamsForCup, $maxTeamsPerCountry) {
        $countryCount = (int) $countryCount;
        if ($countryCount <= 0) {
            return array();
        }

        $distribution = array_fill(0, $countryCount, 0);
        $allocated = 0;

        while ($allocated < (int) $maxTeamsForCup) {
            $assigned = false;

            for ($i = 0; $i < $countryCount; $i++) {
                if ($allocated >= (int) $maxTeamsForCup) {
                    break;
                }
                if ($distribution[$i] < (int) $maxTeamsPerCountry) {
                    $distribution[$i]++;
                    $allocated++;
                    $assigned = true;
                }
            }

            if (!$assigned) {
                break;
            }
        }

        return $distribution;
    }

    public static function getConcacafPlacesByLand(WebSoccer $websoccer, DbConnection $db) {
        self::ensureSchema($websoccer, $db);
        $prefix = $websoccer->getConfig('db_prefix');
        $teams = array();

        $db->executeQuery('DELETE FROM ' . $prefix . '_concacaf_temp');
        $places = self::getConcacafRanking($websoccer, $db);

        foreach ($places as $place) {
            $land = $place['name'];
            $championsPlaces = isset($place['concacaf_champions']) ? (int) $place['concacaf_champions'] : 0;
            if ($championsPlaces <= 0) {
                continue;
            }

            $countryTeams = self::getTopDivisionTeamsByCountry($websoccer, $db, $land, 0, $championsPlaces);
            foreach ($countryTeams as $team) {
                $teams[] = $team;
                $db->queryInsert(
                    array(
                        'verein_id' => (int) $team['club_id'],
                        'cup_name' => self::CONCACAF_CHAMPIONS_CUP
                    ),
                    $prefix . '_concacaf_temp'
                );
            }
        }

        return $teams;
    }

    private static function getTopDivisionTeamsByCountry(WebSoccer $websoccer, DbConnection $db, $land, $start, $limit) {
        $prefix = $websoccer->getConfig('db_prefix');
        $start = max(0, (int) $start);
        $limit = max(0, (int) $limit);
        if ($limit <= 0) {
            return array();
        }

        $columns = 'C.id AS club_id, C.name AS club_name';
        $fromTable = $prefix . '_verein AS C INNER JOIN ' . $prefix . '_liga AS LG ON C.liga_id = LG.id';
        $whereCondition = "
            LG.land = '%s'
            AND LG.division = '1'
            ORDER BY
                C.sa_punkte DESC,
                C.sa_tore DESC,
                (C.sa_tore - C.sa_gegentore) DESC,
                C.strength DESC,
                C.name ASC
            LIMIT " . $start . ', ' . $limit;

        $result = $db->querySelect($columns, $fromTable, $whereCondition, $land);
        $teams = array();
        while ($team = $result->fetch_array()) {
            $teams[] = $team;
        }
        $result->free();
        return $teams;
    }

    public static function getConcacafTeamsByCupName(WebSoccer $websoccer, DbConnection $db, $cupName) {
        self::ensureSchema($websoccer, $db);
        $prefix = $websoccer->getConfig('db_prefix');
        $teams = array();
        $result = $db->querySelect('verein_id', $prefix . '_concacaf_temp', "cup_name = '%s' ORDER BY RAND()", (string) $cupName);
        while ($team = $result->fetch_array()) {
            $teams[] = $team['verein_id'];
        }
        $result->free();
        return $teams;
    }

    public static function rebuildQualificationAndTempTables(WebSoccer $websoccer, DbConnection $db) {
        $allocation = self::updateQualificationPlacesByRanking($websoccer, $db);
        $teams = self::getConcacafPlacesByLand($websoccer, $db);

        return array(
            'allocation' => $allocation,
            'teams' => $teams,
            'team_count' => count($teams),
            'cup_name' => self::CONCACAF_CHAMPIONS_CUP,
            'status' => count($teams) ? 'prepared' : 'no_teams_found'
        );
    }
}
?>
