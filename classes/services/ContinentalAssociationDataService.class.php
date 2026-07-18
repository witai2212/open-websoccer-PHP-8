<?php
/******************************************************

This file is part of OpenWebSoccer-Sim.

******************************************************/

/**
 * Small helper for continent / association navigation and coefficient updates.
 * It keeps UEFA backwards-compatible and adds CONMEBOL / CONCACAF as additional associations.
 */
class ContinentalAssociationDataService {

    public static function getAssociationConfigs() {
        return array(
            'UEFA' => array(
                'code' => 'UEFA',
                'label' => 'UEFA',
                'ranking_page' => 'uefa',
                'ranking_label_key' => 'uefa_ranking_navlabel',
                'cup_pages' => array(
                    array('page' => 'championsleague', 'label' => 'Champions League'),
                    array('page' => 'uefaeuroleague', 'label' => 'UEFA Euro League')
                ),
                'coefficient_column' => 'uefa_s1'
            ),
            'CONMEBOL' => array(
                'code' => 'CONMEBOL',
                'label' => 'CONMEBOL',
                'ranking_page' => 'conmebol',
                'ranking_label_key' => 'conmebol_ranking_navlabel',
                'cup_pages' => array(
                    array('page' => 'copalibertadores', 'label' => 'Copa Libertadores'),
                    array('page' => 'copasudamericana', 'label' => 'Copa Sudamericana')
                ),
                'coefficient_column' => 'conmebol_s1'
            ),
            'CONCACAF' => array(
                'code' => 'CONCACAF',
                'label' => 'CONCACAF',
                'ranking_page' => 'concacaf',
                'ranking_label_key' => 'concacaf_ranking_navlabel',
                'cup_pages' => array(
                    array('page' => 'concacafchampionscup', 'label' => 'CONCACAF Champions Cup')
                ),
                'coefficient_column' => 'concacaf_s1'
            )
        );
    }

    public static function normalizeAssociationCode($value) {
        $value = strtoupper(trim((string) $value));

        if ($value === 'CONMEBOL' || strpos($value, 'SÜD') !== false || strpos($value, 'SUD') !== false || strpos($value, 'SOUTH') !== false) {
            return 'CONMEBOL';
        }

        if ($value === 'CONCACAF' || strpos($value, 'NORTH') !== false || strpos($value, 'CENTRAL') !== false || strpos($value, 'KARIB') !== false || strpos($value, 'CARIB') !== false) {
            return 'CONCACAF';
        }

        if ($value === 'UEFA' || strpos($value, 'EURO') !== false || strpos($value, 'EUROPA') !== false) {
            return 'UEFA';
        }

        return 'UEFA';
    }

    public static function getAssociationConfig($code) {
        $configs = self::getAssociationConfigs();
        $code = self::normalizeAssociationCode($code);
        return isset($configs[$code]) ? $configs[$code] : $configs['UEFA'];
    }

    public static function getTeamAssociation(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $teamId = (int) $teamId;
        if ($teamId <= 0) {
            return array();
        }

        $prefix = $websoccer->getConfig('db_prefix');
        $columns = 'T.id AS team_id, T.name AS team_name, L.land AS country, K.name AS continent_name';
        $fromTable = $prefix . '_verein AS T '
            . 'INNER JOIN ' . $prefix . '_liga AS L ON L.id = T.liga_id '
            . 'LEFT JOIN ' . $prefix . '_kontinent AS K ON K.id = L.kontinent_id';

        $result = $db->querySelect($columns, $fromTable, 'T.id = %d', $teamId, 1);
        $team = $result->fetch_array();
        $result->free();

        if (!$team) {
            return array();
        }

        $config = self::getAssociationConfig($team['continent_name']);
        $config['country'] = $team['country'];
        $config['team_id'] = $teamId;
        $config['team_name'] = $team['team_name'];

        return $config;
    }

    public static function getUserManagedAssociations(WebSoccer $websoccer, DbConnection $db, $userId) {
        $userId = (int) $userId;
        if ($userId <= 0) {
            return array();
        }

        $prefix = $websoccer->getConfig('db_prefix');
        $columns = 'DISTINCT K.name AS continent_name';
        $fromTable = $prefix . '_verein AS T '
            . 'INNER JOIN ' . $prefix . '_liga AS L ON L.id = T.liga_id '
            . 'LEFT JOIN ' . $prefix . '_kontinent AS K ON K.id = L.kontinent_id';

        $result = $db->querySelect(
            $columns,
            $fromTable,
            "T.user_id = %d AND T.status = '1' AND T.nationalteam != '1' ORDER BY K.name ASC",
            $userId
        );

        $associations = array();
        $seen = array();

        while ($row = $result->fetch_array()) {
            $config = self::getAssociationConfig($row['continent_name']);
            if (!isset($seen[$config['code']])) {
                $associations[] = $config;
                $seen[$config['code']] = true;
            }
        }

        $result->free();
        return $associations;
    }

    public static function getAssociationCodeForCupName($cupName) {
        $cupName = trim((string) $cupName);

        if ($cupName === 'Champions League'
            || $cupName === 'UEFA Euro League'
            || $cupName === 'UEFA Conference League'
            || $cupName === 'Conference League') {
            return 'UEFA';
        }

        if ($cupName === 'Copa Libertadores' || $cupName === 'Copa Sudamericana') {
            return 'CONMEBOL';
        }

        if ($cupName === 'CONCACAF Champions Cup') {
            return 'CONCACAF';
        }

        return '';
    }

    public static function addCoefficientPointsForCupMatch(
        WebSoccer $websoccer,
        DbConnection $db,
        $cupName,
        $homeCountry,
        $guestCountry,
        $homePoints,
        $guestPoints
    ) {
        $associationCode = self::getAssociationCodeForCupName($cupName);
        if ($associationCode === '') {
            return false;
        }

        $config = self::getAssociationConfig($associationCode);
        $column = $config['coefficient_column'];

        // Column name comes from internal whitelist, not user input.
        if (!preg_match('/^[a-z0-9_]+$/', $column)) {
            return false;
        }

        $prefix = $websoccer->getConfig('db_prefix');
        $homeCountry = $db->connection->real_escape_string((string) $homeCountry);
        $guestCountry = $db->connection->real_escape_string((string) $guestCountry);
        $homePoints = (float) $homePoints;
        $guestPoints = (float) $guestPoints;

        $db->executeQuery("UPDATE " . $prefix . "_land SET " . $column . " = COALESCE(" . $column . ", 0) + " . $homePoints . " WHERE name = '" . $homeCountry . "'");
        $db->executeQuery("UPDATE " . $prefix . "_land SET " . $column . " = COALESCE(" . $column . ", 0) + " . $guestPoints . " WHERE name = '" . $guestCountry . "'");

        return true;
    }
}
?>
