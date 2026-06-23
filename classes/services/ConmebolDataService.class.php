<?php
/******************************************************

This file is part of OpenWebSoccer-Sim.

******************************************************/

/**
 * Data service for CONMEBOL ranking, qualification and temporary cup teams.
 */
class ConmebolDataService {

    const COPA_LIBERTADORES = 'Copa Libertadores';
    const COPA_SUDAMERICANA = 'Copa Sudamericana';

    const MAX_TEAMS_PER_CUP = 32;
    const MAX_TEAMS_PER_COUNTRY_PER_CUP = 4;

    public static function getConmebolRanking(WebSoccer $websoccer, DbConnection $db) {
        $prefix = $websoccer->getConfig('db_prefix');

        $columns = "
            L.*,
            (
                COALESCE(L.conmebol_s1, 0)
                + COALESCE(L.conmebol_s2, 0)
                + COALESCE(L.conmebol_s3, 0)
                + COALESCE(L.conmebol_s4, 0)
                + COALESCE(L.conmebol_s5, 0)
            ) AS total
        ";

        $result = $db->querySelect(
            $columns,
            $prefix . '_land AS L',
            "L.continent = 'CONMEBOL' ORDER BY total DESC, L.name ASC"
        );

        $ranking = array();
        while ($row = $result->fetch_array()) {
            $ranking[] = $row;
        }
        $result->free();

        return $ranking;
    }

    public static function updateQualificationPlacesByRanking(WebSoccer $websoccer, DbConnection $db) {
        $prefix = $websoccer->getConfig('db_prefix');
        $ranking = self::getConmebolRanking($websoccer, $db);
        $countryCount = count($ranking);

        $libDistribution = self::createDistribution($countryCount, self::MAX_TEAMS_PER_CUP, self::MAX_TEAMS_PER_COUNTRY_PER_CUP);
        $sudDistribution = self::createDistribution($countryCount, self::MAX_TEAMS_PER_CUP, self::MAX_TEAMS_PER_COUNTRY_PER_CUP);

        foreach ($ranking as $index => $country) {
            $countryId = (int) $country['id'];
            $total = isset($country['total']) ? number_format((float) $country['total'], 3, '.', '') : '0.000';

            $db->queryUpdate(
                array(
                    'conmebol_coeff' => $total,
                    'conmebol_lib' => (int) $libDistribution[$index],
                    'conmebol_sud' => (int) $sudDistribution[$index]
                ),
                $prefix . '_land',
                'id = %d',
                $countryId
            );
        }

        return array(
            'countries_updated' => $countryCount,
            'libertadores_total' => array_sum($libDistribution),
            'sudamericana_total' => array_sum($sudDistribution)
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

    public static function getConmebolPlacesByLand(WebSoccer $websoccer, DbConnection $db) {
        $prefix = $websoccer->getConfig('db_prefix');
        $teams = array();

        $db->executeQuery('DELETE FROM ' . $prefix . '_conmebol_temp');
        $places = self::getConmebolRanking($websoccer, $db);

        foreach ($places as $place) {
            $land = $place['name'];
            $libPlaces = isset($place['conmebol_lib']) ? (int) $place['conmebol_lib'] : 0;
            $sudPlaces = isset($place['conmebol_sud']) ? (int) $place['conmebol_sud'] : 0;

            if ($libPlaces > 0) {
                $libTeams = self::getTopDivisionTeamsByCountry($websoccer, $db, $land, 0, $libPlaces);
                foreach ($libTeams as $team) {
                    $teams[] = $team;
                    $db->queryInsert(
                        array(
                            'verein_id' => (int) $team['club_id'],
                            'cup_name' => self::COPA_LIBERTADORES
                        ),
                        $prefix . '_conmebol_temp'
                    );
                }
            }

            if ($sudPlaces > 0) {
                $sudTeams = self::getTopDivisionTeamsByCountry($websoccer, $db, $land, $libPlaces, $sudPlaces);
                foreach ($sudTeams as $team) {
                    $teams[] = $team;
                    $db->queryInsert(
                        array(
                            'verein_id' => (int) $team['club_id'],
                            'cup_name' => self::COPA_SUDAMERICANA
                        ),
                        $prefix . '_conmebol_temp'
                    );
                }
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

    public static function getConmebolTeamsByCupName(WebSoccer $websoccer, DbConnection $db, $cupName) {
        $prefix = $websoccer->getConfig('db_prefix');
        $teams = array();

        $result = $db->querySelect(
            'verein_id',
            $prefix . '_conmebol_temp',
            "cup_name = '%s' ORDER BY RAND()",
            (string) $cupName
        );

        while ($team = $result->fetch_array()) {
            $teams[] = $team['verein_id'];
        }
        $result->free();

        return $teams;
    }

    public static function rebuildQualificationAndTempTables(WebSoccer $websoccer, DbConnection $db) {
        $allocation = self::updateQualificationPlacesByRanking($websoccer, $db);
        $teams = self::getConmebolPlacesByLand($websoccer, $db);

        return array(
            'allocation' => $allocation,
            'teams' => $teams,
            'team_count' => count($teams)
        );
    }
}
?>
