<?php
/******************************************************

  International cup final venue helpers for OpenWebSoccer-Sim.
  CM23 Task 1007 | 25.08.2026 | Revision 1

******************************************************/

/**
 * Selects neutral stadiums for selected international cup finals and keeps
 * Task 1007 disabled until a new season's international cups are generated.
 */
class InternationalCupFinalDataService {

    const ACTIVATION_CONFIG_NAME = 'task1007_cupfinal';
    const ACTIVATION_CONFIG_DESCRIPTION = 'Task 1007 cup finals';

    /**
     * Enables Task 1007 for international cup rounds generated from this
     * season rollover onwards. Existing/current-season finals stay untouched.
     */
    public static function activateForNewSeason(WebSoccer $websoccer, DbConnection $db) {
        $prefix = $websoccer->getConfig('db_prefix');
        $timestamp = (int) $websoccer->getNowAsTimestamp();

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

    /**
     * Returns the configured neutral final stadium for a supported final round.
     * Returns 0 for non-finals, unsupported competitions or the current season.
     */
    public static function getFinalStadiumIdForRound(WebSoccer $websoccer, DbConnection $db, $cupName, $roundId) {
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

        return self::getStadiumIdByAssociationRank(
            $websoccer,
            $db,
            $specification['association'],
            $specification['rank']
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
        $columns = 'M.stadion_id, M.pokalname, R.id AS round_id, R.finalround, R.firstround_date';
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
            (int) $match['round_id']
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

        if (!$row || (int) $row['zeitstempel'] <= 0) {
            return false;
        }

        return $roundTimestamp >= (int) $row['zeitstempel'];
    }

    private static function getCompetitionSpecification($cupName) {
        $cupName = trim((string) $cupName);

        $specifications = array(
            'Champions League' => array('association' => 'UEFA', 'rank' => 1),
            'UEFA Euro League' => array('association' => 'UEFA', 'rank' => 2),
            'CONCACAF Champions Cup' => array('association' => 'CONCACAF', 'rank' => 1),
            'Copa Libertadores' => array('association' => 'CONMEBOL', 'rank' => 1),
            'Copa Sudamericana' => array('association' => 'CONMEBOL', 'rank' => 1)
        );

        return isset($specifications[$cupName]) ? $specifications[$cupName] : null;
    }

    private static function getStadiumIdByAssociationRank(WebSoccer $websoccer, DbConnection $db, $association, $rank) {
        $rank = max(1, (int) $rank);
        $prefix = $websoccer->getConfig('db_prefix');

        $capacityExpression = 'COALESCE(S.p_steh, 0) + COALESCE(S.p_sitz, 0) + '
            . 'COALESCE(S.p_haupt_steh, 0) + COALESCE(S.p_haupt_sitz, 0) + COALESCE(S.p_vip, 0)';
        $columns = 'S.id, (' . $capacityExpression . ') AS capacity';
        $fromTable = $prefix . '_stadion AS S '
            . 'INNER JOIN ' . $prefix . '_verein AS T ON T.stadion_id = S.id '
            . 'INNER JOIN ' . $prefix . '_liga AS L ON L.id = T.liga_id '
            . 'INNER JOIN ' . $prefix . '_land AS C ON C.name = L.land';
        $whereCondition = "C.continent = '%s' AND T.nationalteam = '0' "
            . 'GROUP BY S.id ORDER BY capacity DESC, S.id ASC';

        $result = $db->querySelect(
            $columns,
            $fromTable,
            $whereCondition,
            (string) $association,
            $rank
        );

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