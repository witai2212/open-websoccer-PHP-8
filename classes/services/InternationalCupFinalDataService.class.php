<?php
/******************************************************

  International cup final venue helpers for OpenWebSoccer-Sim.
  CM23 Task 1007 | 25.08.2026 | Revision 2

******************************************************/

/**
 * Selects neutral stadiums for selected international cup finals.
 *
 * Task 1007 becomes active only after the currently configured season:
 * the first lookup stores a boundary directly behind the latest configured
 * final date of the current season. This keeps all current-season finals
 * untouched without requiring a DB migration. Later season rollovers move
 * the final dates beyond that boundary and therefore activate the feature.
 */
class InternationalCupFinalDataService {

    const ACTIVATION_CONFIG_NAME = 'task1007_cupfinal';
    const ACTIVATION_CONFIG_DESCRIPTION = 'Task 1007 cup finals';

    /**
     * Optional explicit activation hook for a season rollover.
     * Existing installations can use the lazy boundary logic below as well.
     */
    public static function activateForNewSeason(WebSoccer $websoccer, DbConnection $db) {
        return self::storeActivationTimestamp(
            $websoccer,
            $db,
            (int) $websoccer->getNowAsTimestamp()
        );
    }

    /**
     * Returns a neutral final stadium for a supported final round.
     *
     * @param array $excludeTeamIds finalist club IDs; their own stadiums are excluded.
     */
    public static function getFinalStadiumIdForRound(
        WebSoccer $websoccer,
        DbConnection $db,
        $cupName,
        $roundId,
        array $excludeTeamIds = array()
    ) {
        $specification = self::getCompetitionSpecification($cupName);
        if (!$specification) {
            return 0;
        }

        $roundId = (int) $roundId;
        if ($roundId <= 0) {
            return 0;
        }

        $prefix = $websoccer->getConfig('db_prefix');
        $result = $db->querySelect(
            'R.finalround, R.firstround_date',
            $prefix . '_cup_round AS R INNER JOIN ' . $prefix . '_cup AS C ON C.id = R.cup_id',
            "R.id = %d AND C.name = '%s'",
            array($roundId, (string) $cupName),
            1
        );
        $round = $result->fetch_array();
        $result->free();

        if (!$round || $round['finalround'] != '1') {
            return 0;
        }

        if (!self::isActivatedForRoundTimestamp($websoccer, $db, (int) $round['firstround_date'])) {
            return 0;
        }

        return self::getEligibleStadiumId(
            $websoccer,
            $db,
            $specification['association'],
            $specification['minimum_capacity'],
            $specification['rank'],
            $excludeTeamIds
        );
    }

    /**
     * Returns the Task-1007 neutral stadium of an existing final match.
     * A positive result also means that ticket revenue must be split 50/50.
     */
    public static function getManagedFinalStadiumIdForMatch(WebSoccer $websoccer, DbConnection $db, $matchId) {
        $matchId = (int) $matchId;
        if ($matchId <= 0) {
            return 0;
        }

        $prefix = $websoccer->getConfig('db_prefix');
        $columns = 'M.stadion_id, M.pokalname, M.home_verein, M.gast_verein, '
            . 'R.id AS round_id, R.finalround, R.firstround_date';
        $fromTable = $prefix . '_spiel AS M '
            . 'INNER JOIN ' . $prefix . '_cup AS C ON C.name = M.pokalname '
            . 'INNER JOIN ' . $prefix . '_cup_round AS R ON R.cup_id = C.id AND R.name = M.pokalrunde';

        $result = $db->querySelect(
            $columns,
            $fromTable,
            "M.id = %d AND M.spieltyp = 'Pokalspiel'",
            $matchId,
            1
        );
        $match = $result->fetch_array();
        $result->free();

        if (!$match || $match['finalround'] != '1' || (int) $match['stadion_id'] <= 0) {
            return 0;
        }

        if (!self::isActivatedForRoundTimestamp($websoccer, $db, (int) $match['firstround_date'])) {
            return 0;
        }

        $expectedStadiumId = self::getFinalStadiumIdForRound(
            $websoccer,
            $db,
            (string) $match['pokalname'],
            (int) $match['round_id'],
            array((int) $match['home_verein'], (int) $match['gast_verein'])
        );

        if ($expectedStadiumId <= 0 || $expectedStadiumId !== (int) $match['stadion_id']) {
            return 0;
        }

        return $expectedStadiumId;
    }

    private static function isActivatedForRoundTimestamp(WebSoccer $websoccer, DbConnection $db, $roundTimestamp) {
        $roundTimestamp = (int) $roundTimestamp;
        if ($roundTimestamp <= 0) {
            return false;
        }

        $activationTimestamp = self::getActivationTimestamp($websoccer, $db);

        if ($activationTimestamp <= 0) {
            /*
             * First deployment: freeze the complete current season.
             * Use the latest final date currently configured for all supported
             * competitions and start Task 1007 one second afterwards.
             */
            $latestCurrentFinal = self::getLatestConfiguredSupportedFinalTimestamp($websoccer, $db);
            if ($latestCurrentFinal <= 0) {
                $latestCurrentFinal = $roundTimestamp;
            }

            $activationTimestamp = self::storeActivationTimestamp(
                $websoccer,
                $db,
                $latestCurrentFinal + 1
            );
        }

        return $roundTimestamp >= $activationTimestamp;
    }

    private static function getActivationTimestamp(WebSoccer $websoccer, DbConnection $db) {
        $prefix = $websoccer->getConfig('db_prefix');
        $result = $db->querySelect(
            'zeitstempel',
            $prefix . '_config',
            "name = '%s'",
            self::ACTIVATION_CONFIG_NAME,
            1
        );
        $row = $result->fetch_array();
        $result->free();

        return $row ? (int) $row['zeitstempel'] : 0;
    }

    private static function storeActivationTimestamp(WebSoccer $websoccer, DbConnection $db, $timestamp) {
        $prefix = $websoccer->getConfig('db_prefix');
        $timestamp = max(1, (int) $timestamp);

        $result = $db->querySelect(
            'id',
            $prefix . '_config',
            "name = '%s'",
            self::ACTIVATION_CONFIG_NAME,
            1
        );
        $row = $result->fetch_array();
        $result->free();

        if ($row) {
            $db->queryUpdate(
                array(
                    'zeitstempel' => $timestamp,
                    'descr' => self::ACTIVATION_CONFIG_DESCRIPTION
                ),
                $prefix . '_config',
                'id = %d',
                (int) $row['id']
            );
        } else {
            $db->queryInsert(
                array(
                    'name' => self::ACTIVATION_CONFIG_NAME,
                    'zeitstempel' => $timestamp,
                    'descr' => self::ACTIVATION_CONFIG_DESCRIPTION
                ),
                $prefix . '_config'
            );
        }

        return $timestamp;
    }

    private static function getLatestConfiguredSupportedFinalTimestamp(WebSoccer $websoccer, DbConnection $db) {
        $prefix = $websoccer->getConfig('db_prefix');
        $cupNames = self::getSupportedCompetitionNames();

        if (empty($cupNames)) {
            return 0;
        }

        $escapedNames = array();
        foreach ($cupNames as $cupName) {
            $escapedNames[] = "'" . str_replace("'", "''", $cupName) . "'";
        }

        $sql = 'SELECT MAX(R.firstround_date) AS final_date '
            . 'FROM ' . $prefix . '_cup_round AS R '
            . 'INNER JOIN ' . $prefix . '_cup AS C ON C.id = R.cup_id '
            . "WHERE R.finalround = '1' AND C.name IN (" . implode(',', $escapedNames) . ')';

        $result = $db->executeQuery($sql);
        $row = $result->fetch_array();
        $result->free();

        return $row ? (int) $row['final_date'] : 0;
    }

    private static function getCompetitionSpecification($cupName) {
        $cupName = trim((string) $cupName);

        $specifications = array(
            'Champions League' => array('association' => 'UEFA', 'minimum_capacity' => 60000, 'rank' => 1),
            'UEFA Champions League' => array('association' => 'UEFA', 'minimum_capacity' => 60000, 'rank' => 1),
            'UEFA Euro League' => array('association' => 'UEFA', 'minimum_capacity' => 60000, 'rank' => 2),
            'Europa League' => array('association' => 'UEFA', 'minimum_capacity' => 60000, 'rank' => 2),
            'UEFA Europa League' => array('association' => 'UEFA', 'minimum_capacity' => 60000, 'rank' => 2),
            'Conference League' => array('association' => 'UEFA', 'minimum_capacity' => 60000, 'rank' => 3),
            'UEFA Conference League' => array('association' => 'UEFA', 'minimum_capacity' => 60000, 'rank' => 3),
            'Copa Libertadores' => array('association' => 'CONMEBOL', 'minimum_capacity' => 50000, 'rank' => 1),
            'Copa Sudamericana' => array('association' => 'CONMEBOL', 'minimum_capacity' => 50000, 'rank' => 2),
            'AFC Champions League' => array('association' => 'AFC', 'minimum_capacity' => 50000, 'rank' => 1),
            'CAF Champions League' => array('association' => 'CAF', 'minimum_capacity' => 50000, 'rank' => 1),
            'FIFA Club World Cup' => array('association' => '', 'minimum_capacity' => 60000, 'rank' => 1),
            'FIFA Klub-WM' => array('association' => '', 'minimum_capacity' => 60000, 'rank' => 1),
            'Club World Cup' => array('association' => '', 'minimum_capacity' => 60000, 'rank' => 1)
        );

        return isset($specifications[$cupName]) ? $specifications[$cupName] : null;
    }

    private static function getSupportedCompetitionNames() {
        return array(
            'Champions League',
            'UEFA Champions League',
            'UEFA Euro League',
            'Europa League',
            'UEFA Europa League',
            'Conference League',
            'UEFA Conference League',
            'Copa Libertadores',
            'Copa Sudamericana',
            'AFC Champions League',
            'CAF Champions League',
            'FIFA Club World Cup',
            'FIFA Klub-WM',
            'Club World Cup'
        );
    }

    private static function getEligibleStadiumId(
        WebSoccer $websoccer,
        DbConnection $db,
        $association,
        $minimumCapacity,
        $rank,
        array $excludeTeamIds = array()
    ) {
        $rank = max(1, (int) $rank);
        $minimumCapacity = max(0, (int) $minimumCapacity);
        $prefix = $websoccer->getConfig('db_prefix');

        $capacityExpression = 'COALESCE(S.p_steh, 0) + COALESCE(S.p_sitz, 0) + '
            . 'COALESCE(S.p_haupt_steh, 0) + COALESCE(S.p_haupt_sitz, 0) + COALESCE(S.p_vip, 0)';

        $columns = 'S.id, (' . $capacityExpression . ') AS capacity';
        $fromTable = $prefix . '_stadion AS S '
            . 'INNER JOIN ' . $prefix . '_verein AS T ON T.stadion_id = S.id '
            . 'INNER JOIN ' . $prefix . '_liga AS L ON L.id = T.liga_id '
            . 'INNER JOIN ' . $prefix . '_land AS C ON C.name = L.land';

        $whereParts = array(
            "T.nationalteam = '0'",
            '(' . $capacityExpression . ') > ' . $minimumCapacity
        );

        $association = strtoupper(trim((string) $association));
        if ($association !== '') {
            $whereParts[] = "C.continent = '" . str_replace("'", "''", $association) . "'";
        }

        $excludeTeamIds = array_values(array_unique(array_filter(
            array_map('intval', $excludeTeamIds),
            function ($teamId) {
                return $teamId > 0;
            }
        )));

        if (!empty($excludeTeamIds)) {
            $whereParts[] = 'S.id NOT IN (SELECT stadion_id FROM ' . $prefix
                . '_verein WHERE id IN (' . implode(',', $excludeTeamIds) . '))';
        }

        $whereCondition = implode(' AND ', $whereParts)
            . ' GROUP BY S.id ORDER BY capacity DESC, S.id ASC';

        $sql = 'SELECT ' . $columns . ' FROM ' . $fromTable
            . ' WHERE ' . $whereCondition . ' LIMIT 0, ' . $rank;
        $result = $db->executeQuery($sql);

        $stadiums = array();
        while ($row = $result->fetch_array()) {
            if ((int) $row['id'] > 0) {
                $stadiums[] = (int) $row['id'];
            }
        }
        $result->free();

        if (empty($stadiums)) {
            return 0;
        }

        if (isset($stadiums[$rank - 1])) {
            return $stadiums[$rank - 1];
        }

        return $stadiums[0];
    }
}
?>