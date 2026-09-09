<?php
/******************************************************

  Season rollover qualification snapshot for OpenWebSoccer-Sim.
  CM23 Task 1026 | 09.09.2026 | Revision 1

******************************************************/

class SeasonRolloverQualificationService {

    const UEFA_CL_CUP_ID = 2;
    const UEFA_UL_CUP_ID = 3;

    public static function captureSnapshot(WebSoccer $websoccer, DbConnection $db) {
        $prefix = $websoccer->getConfig('db_prefix');

        $snapshot = array(
            'captured_at' => time(),
            'uefa' => self::captureUefa($websoccer, $db),
            'conmebol' => self::captureConmebol($websoccer, $db),
            'concacaf' => self::captureConcacaf($websoccer, $db),
            'finance_seasons' => self::captureFinanceSeasonMap($websoccer, $db)
        );

        $db->executeQuery("UPDATE {$prefix}_land SET uefa_cl = 0, uefa_ul = 0, uefa_conf = 0 WHERE continent <> 'UEFA'");
        $db->executeQuery("UPDATE {$prefix}_land SET conmebol_lib = 0, conmebol_sud = 0 WHERE continent <> 'CONMEBOL'");
        if (self::columnExists($db, $prefix . '_land', 'concacaf_champions')) {
            $db->executeQuery("UPDATE {$prefix}_land SET concacaf_champions = 0 WHERE continent <> 'CONCACAF'");
        }

        return $snapshot;
    }

    public static function applySnapshotToTempTables(WebSoccer $websoccer, DbConnection $db, array $snapshot) {
        if (empty($snapshot['uefa']) || empty($snapshot['conmebol']) || empty($snapshot['concacaf'])) {
            throw new Exception('Der gespeicherte internationale Qualifikations-Snapshot ist unvollständig.');
        }

        $prefix = $websoccer->getConfig('db_prefix');

        $db->executeQuery('DELETE FROM ' . $prefix . '_uefa_temp');
        foreach ($snapshot['uefa']['champions_league'] as $team) {
            $db->queryInsert(array('verein_id' => (int) $team['team_id'], 'cup_id' => self::UEFA_CL_CUP_ID), $prefix . '_uefa_temp');
        }
        foreach ($snapshot['uefa']['euro_league'] as $team) {
            $db->queryInsert(array('verein_id' => (int) $team['team_id'], 'cup_id' => self::UEFA_UL_CUP_ID), $prefix . '_uefa_temp');
        }
        if (method_exists('UefaDataService', 'syncLegacyTempTablesFromUefaTemp')) {
            UefaDataService::syncLegacyTempTablesFromUefaTemp($websoccer, $db);
        }

        $db->executeQuery('DELETE FROM ' . $prefix . '_conmebol_temp');
        foreach ($snapshot['conmebol']['libertadores'] as $team) {
            $db->queryInsert(array('verein_id' => (int) $team['team_id'], 'cup_name' => ConmebolDataService::COPA_LIBERTADORES), $prefix . '_conmebol_temp');
        }
        foreach ($snapshot['conmebol']['sudamericana'] as $team) {
            $db->queryInsert(array('verein_id' => (int) $team['team_id'], 'cup_name' => ConmebolDataService::COPA_SUDAMERICANA), $prefix . '_conmebol_temp');
        }

        if (class_exists('ConcacafDataService')) {
            ConcacafDataService::ensureSchema($websoccer, $db);
        }
        $db->executeQuery('DELETE FROM ' . $prefix . '_concacaf_temp');
        foreach ($snapshot['concacaf']['champions_cup'] as $team) {
            $db->queryInsert(array('verein_id' => (int) $team['team_id'], 'cup_name' => ConcacafDataService::CONCACAF_CHAMPIONS_CUP), $prefix . '_concacaf_temp');
        }

        return array(
            'uefa_cl' => count($snapshot['uefa']['champions_league']),
            'uefa_ul' => count($snapshot['uefa']['euro_league']),
            'conmebol_lib' => count($snapshot['conmebol']['libertadores']),
            'conmebol_sud' => count($snapshot['conmebol']['sudamericana']),
            'concacaf' => count($snapshot['concacaf']['champions_cup'])
        );
    }

    private static function captureUefa(WebSoccer $websoccer, DbConnection $db) {
        $ranking = self::getRanking($websoccer, $db, 'UEFA', 'uefa');
        $countryCount = count($ranking);
        $cl = self::createEuropeanDistribution($countryCount, 64, 4);

        $lastTenStart = max(0, $countryCount - 10);
        for ($i = $lastTenStart; $i < $countryCount; $i++) {
            $cl[$i] = 0;
        }
        for ($i = 0; $i < min(5, $countryCount); $i++) {
            $cl[$i] += 2;
        }
        $ul = self::createEuropeanDistribution($countryCount, 64, 4);
        $conf = self::createEuropeanDistribution($countryCount, 64, 4);

        $clTeams = array();
        $ulTeams = array();
        $prefix = $websoccer->getConfig('db_prefix');

        foreach ($ranking as $index => $country) {
            $countryId = (int) $country['id'];
            $db->queryUpdate(array(
                'uefa_coeff' => number_format((float) $country['total'], 3, '.', ''),
                'uefa_cl' => (int) $cl[$index],
                'uefa_ul' => (int) $ul[$index],
                'uefa_conf' => (int) $conf[$index]
            ), $prefix . '_land', 'id = %d', $countryId);

            $clTeams = array_merge($clTeams, self::getTopDivisionTeamsByCountry($websoccer, $db, $country['name'], 0, (int) $cl[$index], 'UEFA'));
            $ulTeams = array_merge($ulTeams, self::getTopDivisionTeamsByCountry($websoccer, $db, $country['name'], (int) $cl[$index], (int) $ul[$index], 'UEFA'));
        }

        return array(
            'countries' => $countryCount,
            'champions_league' => $clTeams,
            'euro_league' => $ulTeams
        );
    }

    private static function captureConmebol(WebSoccer $websoccer, DbConnection $db) {
        $ranking = self::getRanking($websoccer, $db, 'CONMEBOL', 'conmebol');
        $distribution = self::createEuropeanDistribution(count($ranking), 32, 4);
        $lib = array();
        $sud = array();
        $prefix = $websoccer->getConfig('db_prefix');

        foreach ($ranking as $index => $country) {
            $places = (int) $distribution[$index];
            $db->queryUpdate(array(
                'conmebol_coeff' => number_format((float) $country['total'], 3, '.', ''),
                'conmebol_lib' => $places,
                'conmebol_sud' => $places
            ), $prefix . '_land', 'id = %d', (int) $country['id']);

            $lib = array_merge($lib, self::getTopDivisionTeamsByCountry($websoccer, $db, $country['name'], 0, $places, 'CONMEBOL'));
            $sud = array_merge($sud, self::getTopDivisionTeamsByCountry($websoccer, $db, $country['name'], $places, $places, 'CONMEBOL'));
        }

        return array('countries' => count($ranking), 'libertadores' => $lib, 'sudamericana' => $sud);
    }

    private static function captureConcacaf(WebSoccer $websoccer, DbConnection $db) {
        if (class_exists('ConcacafDataService')) {
            ConcacafDataService::ensureSchema($websoccer, $db);
        }
        $ranking = self::getRanking($websoccer, $db, 'CONCACAF', 'concacaf');
        $distribution = self::createEuropeanDistribution(count($ranking), 32, 4);
        $teams = array();
        $prefix = $websoccer->getConfig('db_prefix');

        foreach ($ranking as $index => $country) {
            $places = (int) $distribution[$index];
            $db->queryUpdate(array(
                'concacaf_coeff' => number_format((float) $country['total'], 3, '.', ''),
                'concacaf_champions' => $places
            ), $prefix . '_land', 'id = %d', (int) $country['id']);
            $teams = array_merge($teams, self::getTopDivisionTeamsByCountry($websoccer, $db, $country['name'], 0, $places, 'CONCACAF'));
        }

        return array('countries' => count($ranking), 'champions_cup' => $teams);
    }

    private static function getRanking(WebSoccer $websoccer, DbConnection $db, $association, $prefixName) {
        $prefix = $websoccer->getConfig('db_prefix');
        $association = $db->connection->real_escape_string($association);
        $safePrefix = preg_replace('/[^a-z]/', '', strtolower($prefixName));
        $total = '(COALESCE(L.' . $safePrefix . '_s1,0)+COALESCE(L.' . $safePrefix . '_s2,0)+COALESCE(L.' . $safePrefix . '_s3,0)+COALESCE(L.' . $safePrefix . '_s4,0)+COALESCE(L.' . $safePrefix . '_s5,0))';
        $result = $db->executeQuery('SELECT L.*, ' . $total . " AS total FROM {$prefix}_land AS L WHERE L.continent = '" . $association . "' ORDER BY total DESC, L.name ASC");
        $ranking = array();
        while ($row = $result->fetch_array()) {
            $ranking[] = $row;
        }
        $result->free();
        return $ranking;
    }

    private static function createEuropeanDistribution($countryCount, $maxTeams, $maxPerCountry) {
        $countryCount = (int) $countryCount;
        if ($countryCount <= 0) {
            return array();
        }
        $distribution = array_fill(0, $countryCount, 0);
        $allocated = 0;
        while ($allocated < (int) $maxTeams) {
            $assigned = false;
            for ($i = 0; $i < $countryCount && $allocated < (int) $maxTeams; $i++) {
                if ($distribution[$i] < (int) $maxPerCountry) {
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

    private static function getTopDivisionTeamsByCountry(WebSoccer $websoccer, DbConnection $db, $country, $start, $limit, $association) {
        $start = max(0, (int) $start);
        $limit = max(0, (int) $limit);
        if ($limit <= 0) {
            return array();
        }

        $prefix = $websoccer->getConfig('db_prefix');
        $countryEscaped = $db->connection->real_escape_string((string) $country);
        $associationEscaped = $db->connection->real_escape_string((string) $association);
        $sql = "SELECT C.id AS team_id, C.name AS team_name, LG.land AS country, LG.id AS league_id
                FROM {$prefix}_verein C
                INNER JOIN {$prefix}_liga LG ON LG.id = C.liga_id
                INNER JOIN {$prefix}_land L ON L.name = LG.land
                WHERE LG.land = '{$countryEscaped}'
                  AND LG.division = 1
                  AND L.continent = '{$associationEscaped}'
                  AND C.status = '1'
                  AND C.nationalteam != '1'
                ORDER BY C.sa_punkte DESC, (C.sa_tore-C.sa_gegentore) DESC, C.sa_siege DESC, C.sa_unentschieden DESC, C.sa_tore DESC, C.name ASC
                LIMIT {$start}, {$limit}";
        $result = $db->executeQuery($sql);
        $teams = array();
        while ($row = $result->fetch_array()) {
            $teams[] = $row;
        }
        $result->free();
        return $teams;
    }

    private static function captureFinanceSeasonMap(WebSoccer $websoccer, DbConnection $db) {
        $prefix = $websoccer->getConfig('db_prefix');
        $sql = "SELECT T.id AS team_id, S.id AS season_id, S.name AS season_name, L.id AS league_id, L.name AS league_name, L.land AS country
                FROM {$prefix}_verein T
                INNER JOIN {$prefix}_liga L ON L.id = T.liga_id
                LEFT JOIN {$prefix}_saison S ON S.liga_id = L.id AND S.beendet = '0'
                WHERE T.status = '1' AND T.nationalteam != '1'
                ORDER BY T.id ASC";
        $result = $db->executeQuery($sql);
        $map = array();
        while ($row = $result->fetch_array()) {
            $map[(string) ((int) $row['team_id'])] = array(
                'team_id' => (int) $row['team_id'],
                'season_id' => !empty($row['season_id']) ? (int) $row['season_id'] : 0,
                'season_name' => isset($row['season_name']) ? (string) $row['season_name'] : '',
                'league_id' => (int) $row['league_id'],
                'league_name' => (string) $row['league_name'],
                'country' => (string) $row['country']
            );
        }
        $result->free();
        return $map;
    }

    private static function columnExists(DbConnection $db, $table, $column) {
        $table = $db->connection->real_escape_string($table);
        $column = $db->connection->real_escape_string($column);
        $result = $db->executeQuery("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
        $exists = ($result && $result->num_rows > 0);
        if ($result) {
            $result->free();
        }
        return $exists;
    }
}
?>