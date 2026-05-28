<?php
/******************************************************

  This file is part of OpenWebSoccer-Sim.

  OpenWebSoccer-Sim is free software: you can redistribute it 
  and/or modify it under the terms of the 
  GNU Lesser General Public License 
  as published by the Free Software Foundation, either version 3 of
  the License, or any later version.

  OpenWebSoccer-Sim is distributed in the hope that it will be
  useful, but WITHOUT ANY WARRANTY; without even the implied
  warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. 
  See the GNU Lesser General Public License for more details.

  You should have received a copy of the GNU Lesser General Public 
  License along with OpenWebSoccer-Sim.  
  If not, see <http://www.gnu.org/licenses/>.

******************************************************/

/**
 * Data service for training camps data.
 */
class TrainingcampsDataService {

    private static $_columnExistsCache = array();
    private static $_tableExistsCache = array();

    public static function getCamps(WebSoccer $websoccer, DbConnection $db) {
        $fromTable = $websoccer->getConfig("db_prefix") . "_trainingslager";

        $camps = array();
        $result = $db->querySelect(self::_getColumns($websoccer, $db), $fromTable, "1=1 ORDER BY name ASC");
        while ($camp = $result->fetch_array()) {
            $camps[] = self::_normalizeCampDefaults($camp);
        }
        $result->free();

        return $camps;
    }

    public static function getCampBookingsByTeam(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $prefix = $websoccer->getConfig("db_prefix");
        $fromTable = $prefix . "_trainingslager_belegung AS B";
        $fromTable .= " INNER JOIN " . $prefix . "_trainingslager AS C ON C.id = B.lager_id";

        $columns = array();
        $columns["B.id"] = "id";
        $columns["B.datum_start"] = "date_start";
        $columns["B.datum_ende"] = "date_end";
        $columns["C.id"] = "camp_id";
        $columns["C.name"] = "name";
        $columns["C.land"] = "country";
        $columns["C.preis_spieler_tag"] = "costs";
        $columns["C.p_staerke"] = "effect_strength";
        $columns["C.p_technik"] = "effect_strength_technique";
        $columns["C.p_kondition"] = "effect_strength_stamina";
        $columns["C.p_frische"] = "effect_strength_freshness";
        $columns["C.p_zufriedenheit"] = "effect_strength_satisfaction";
        self::_appendSpecialAttributeColumns($websoccer, $db, $columns, 'C');

        if (self::_columnExists($websoccer, $db, 'trainingslager', 'camp_type')) {
            $columns["C.camp_type"] = "camp_type";
        }
        if (self::_columnExists($websoccer, $db, 'trainingslager', 'p_team_chemistry')) {
            $columns["C.p_team_chemistry"] = "effect_team_chemistry";
        }
        if (self::_columnExists($websoccer, $db, 'trainingslager', 'injury_risk')) {
            $columns["C.injury_risk"] = "injury_risk";
        }
        if (self::_columnExists($websoccer, $db, 'trainingslager_belegung', 'player_count')) {
            $columns["B.player_count"] = "player_count";
        }
        if (self::_columnExists($websoccer, $db, 'trainingslager_belegung', 'total_costs')) {
            $columns["B.total_costs"] = "total_costs";
        }

        $camps = array();
        $result = $db->querySelect($columns, $fromTable, "B.verein_id = %d ORDER BY B.datum_start DESC", $teamId);
        while ($camp = $result->fetch_array()) {
            $camps[] = self::_normalizeCampDefaults($camp);
        }
        $result->free();

        return $camps;
    }

    public static function getCampById(WebSoccer $websoccer, DbConnection $db, $campId) {
        $fromTable = $websoccer->getConfig("db_prefix") . "_trainingslager";

        $result = $db->querySelect(self::_getColumns($websoccer, $db), $fromTable, "id = %d", $campId);
        $camp = $result->fetch_array();
        $result->free();

        return $camp ? self::_normalizeCampDefaults($camp) : $camp;
    }

    public static function getLastCampReportByTeam(WebSoccer $websoccer, DbConnection $db, $teamId) {
        if (!self::_tableExists($websoccer, $db, 'trainingslager_report')) {
            return array();
        }

        $result = $db->querySelect(
            '*',
            $websoccer->getConfig("db_prefix") . "_trainingslager_report",
            "team_id = %d ORDER BY completed_date DESC, id DESC",
            (int) $teamId,
            1
        );
        $report = $result->fetch_array();
        $result->free();

        if (!$report) {
            return array();
        }

        return self::_prepareReportForTemplate($report);
    }

    public static function countActivePlayersOfTeam(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $players = PlayersDataService::getPlayersOfTeamById($websoccer, $db, $teamId);
        return count($players);
    }

    public static function calculateTotalCosts($camp, $days, $playerCount) {
        return max(0, (int) $camp["costs"]) * max(1, (int) $days) * max(0, (int) $playerCount);
    }

    public static function executeCamp(WebSoccer $websoccer, DbConnection $db, $teamId, $bookingInfo) {
        $players = PlayersDataService::getPlayersOfTeamById($websoccer, $db, $teamId);
        $duration = max(1, (int) round(((int) $bookingInfo["date_end"] - (int) $bookingInfo["date_start"]) / (24 * 3600)));
        $playerCount = count($players);
        $injuries = 0;

        $effectStrength = (float) $bookingInfo["effect_strength"] * $duration;
        $effectTechnique = (float) $bookingInfo["effect_strength_technique"] * $duration;
        $effectStamina = (float) $bookingInfo["effect_strength_stamina"] * $duration;
        $effectFreshness = (float) $bookingInfo["effect_strength_freshness"] * $duration;
        $effectSatisfaction = (float) $bookingInfo["effect_strength_satisfaction"] * $duration;
        $effectChemistry = (int) round((float) $bookingInfo["effect_team_chemistry"] * $duration);
        $specialEffects = self::_calculateSpecialAttributeEffects($bookingInfo, $duration);
        $injuryRisk = max(0, min(100, (int) $bookingInfo["injury_risk"]));

        if ($playerCount) {
            $playerTable = $websoccer->getConfig("db_prefix") . "_spieler";
            $updateCondition = "id = %d";

            foreach ($players as $player) {
                $columns = array();

                $columns["w_staerke"] = self::_normalizePlayerValue((float) $player["strength"] + $effectStrength);
                $columns["w_technik"] = self::_normalizePlayerValue((float) $player["strength_technic"] + $effectTechnique);
                $columns["w_kondition"] = self::_normalizePlayerValue((float) $player["strength_stamina"] + $effectStamina);
                $columns["w_frische"] = self::_normalizePlayerValue((float) $player["strength_freshness"] + $effectFreshness);
                $columns["w_zufriedenheit"] = self::_normalizePlayerValue((float) $player["strength_satisfaction"] + $effectSatisfaction);

                foreach (self::_getSpecialAttributeDefinitions() as $attribute) {
                    $effectValue = isset($specialEffects[$attribute['alias']]) ? (float) $specialEffects[$attribute['alias']] : 0;
                    if ($effectValue != 0 && isset($player[$attribute['player_key']])) {
                        $columns[$attribute['player_column']] = self::_normalizePlayerValue((float) $player[$attribute['player_key']] + $effectValue);
                    }
                }

                if ($injuryRisk > 0 && mt_rand(1, 100) <= $injuryRisk) {
                    $columns["verletzt"] = max((int) $player["matches_injured"], mt_rand(1, 2));
                    $injuries++;
                    if (class_exists('MedicalCenterDataService')) {
                        MedicalCenterDataService::logInjury($websoccer, $db, (int) $teamId, (int) $player["id"], 'trainingcamp', (int) $bookingInfo["id"], $columns["verletzt"], '', 'trainingcamp:' . (int) $bookingInfo["id"] . ':' . (int) $player["id"]);
                    }
                }

                $db->queryUpdate($columns, $playerTable, $updateCondition, $player["id"]);
            }
        }

        $chemistryData = self::_applyTeamChemistryEffect($websoccer, $db, $teamId, $effectChemistry, $bookingInfo);
        $report = self::_createCampReport($websoccer, $db, $teamId, $bookingInfo, $duration, $playerCount, $injuries, $chemistryData);

        $db->queryDelete($websoccer->getConfig("db_prefix") . "_trainingslager_belegung", "id = %d", $bookingInfo["id"]);

        return $report;
    }

    public static function formatSignedNumber($number) {
        $number = (int) $number;
        return ($number > 0 ? '+' : '') . $number;
    }

    private static function _getSpecialAttributeDefinitions() {
        return array(
            array('column' => 'p_passing', 'alias' => 'effect_passing', 'player_column' => 'w_passing', 'player_key' => 'strength_passing', 'report_column' => 'effect_passing_total', 'signed_key' => 'effect_passing_signed', 'message_key' => 'entity_trainingcamp_p_passing'),
            array('column' => 'p_shooting', 'alias' => 'effect_shooting', 'player_column' => 'w_shooting', 'player_key' => 'strength_shooting', 'report_column' => 'effect_shooting_total', 'signed_key' => 'effect_shooting_signed', 'message_key' => 'entity_trainingcamp_p_shooting'),
            array('column' => 'p_heading', 'alias' => 'effect_heading', 'player_column' => 'w_heading', 'player_key' => 'strength_heading', 'report_column' => 'effect_heading_total', 'signed_key' => 'effect_heading_signed', 'message_key' => 'entity_trainingcamp_p_heading'),
            array('column' => 'p_tackling', 'alias' => 'effect_tackling', 'player_column' => 'w_tackling', 'player_key' => 'strength_tackling', 'report_column' => 'effect_tackling_total', 'signed_key' => 'effect_tackling_signed', 'message_key' => 'entity_trainingcamp_p_tackling'),
            array('column' => 'p_freekick', 'alias' => 'effect_freekick', 'player_column' => 'w_freekick', 'player_key' => 'strength_freekick', 'report_column' => 'effect_freekick_total', 'signed_key' => 'effect_freekick_signed', 'message_key' => 'entity_trainingcamp_p_freekick'),
            array('column' => 'p_pace', 'alias' => 'effect_pace', 'player_column' => 'w_pace', 'player_key' => 'strength_pace', 'report_column' => 'effect_pace_total', 'signed_key' => 'effect_pace_signed', 'message_key' => 'entity_trainingcamp_p_pace'),
            array('column' => 'p_creativity', 'alias' => 'effect_creativity', 'player_column' => 'w_creativity', 'player_key' => 'strength_creativity', 'report_column' => 'effect_creativity_total', 'signed_key' => 'effect_creativity_signed', 'message_key' => 'entity_trainingcamp_p_creativity'),
            array('column' => 'p_influence', 'alias' => 'effect_influence', 'player_column' => 'w_influence', 'player_key' => 'strength_influence', 'report_column' => 'effect_influence_total', 'signed_key' => 'effect_influence_signed', 'message_key' => 'entity_trainingcamp_p_influence'),
            array('column' => 'p_flair', 'alias' => 'effect_flair', 'player_column' => 'w_flair', 'player_key' => 'strength_flair', 'report_column' => 'effect_flair_total', 'signed_key' => 'effect_flair_signed', 'message_key' => 'entity_trainingcamp_p_flair'),
            array('column' => 'p_penalty', 'alias' => 'effect_penalty', 'player_column' => 'w_penalty', 'player_key' => 'strength_penalty', 'report_column' => 'effect_penalty_total', 'signed_key' => 'effect_penalty_signed', 'message_key' => 'entity_trainingcamp_p_penalty'),
            array('column' => 'p_penalty_killing', 'alias' => 'effect_penalty_killing', 'player_column' => 'w_penalty_killing', 'player_key' => 'strength_penalty_killing', 'report_column' => 'effect_penalty_killing_total', 'signed_key' => 'effect_penalty_killing_signed', 'message_key' => 'entity_trainingcamp_p_penalty_killing')
        );
    }

    private static function _appendSpecialAttributeColumns(WebSoccer $websoccer, DbConnection $db, &$columns, $tableAlias) {
        $tablePrefix = strlen($tableAlias) ? $tableAlias . '.' : '';
        foreach (self::_getSpecialAttributeDefinitions() as $attribute) {
            if (self::_columnExists($websoccer, $db, 'trainingslager', $attribute['column'])) {
                $columns[$tablePrefix . $attribute['column']] = $attribute['alias'];
            }
        }
    }

    private static function _calculateSpecialAttributeEffects($camp, $duration) {
        $effects = array();
        foreach (self::_getSpecialAttributeDefinitions() as $attribute) {
            $effects[$attribute['alias']] = (isset($camp[$attribute['alias']]) ? (float) $camp[$attribute['alias']] : 0) * max(1, (int) $duration);
        }
        return $effects;
    }

    private static function _buildSpecialEffectList($data, $mode) {
        $effects = array();
        foreach (self::_getSpecialAttributeDefinitions() as $attribute) {
            $key = ($mode === 'report') ? $attribute['report_column'] : $attribute['alias'];
            $value = isset($data[$key]) ? (int) round((float) $data[$key]) : 0;
            if ($value !== 0) {
                $effects[] = array(
                    'label_key' => $attribute['message_key'],
                    'value' => $value,
                    'signed' => self::formatSignedNumber($value)
                );
            }
        }
        return $effects;
    }

    private static function _getColumns(WebSoccer $websoccer, DbConnection $db) {
        $columns = array();
        $columns["id"] = "id";
        $columns["name"] = "name";
        $columns["land"] = "country";
        $columns["preis_spieler_tag"] = "costs";
        $columns["p_staerke"] = "effect_strength";
        $columns["p_technik"] = "effect_strength_technique";
        $columns["p_kondition"] = "effect_strength_stamina";
        $columns["p_frische"] = "effect_strength_freshness";
        $columns["p_zufriedenheit"] = "effect_strength_satisfaction";
        self::_appendSpecialAttributeColumns($websoccer, $db, $columns, '');

        if (self::_columnExists($websoccer, $db, 'trainingslager', 'camp_type')) {
            $columns["camp_type"] = "camp_type";
        }
        if (self::_columnExists($websoccer, $db, 'trainingslager', 'p_team_chemistry')) {
            $columns["p_team_chemistry"] = "effect_team_chemistry";
        }
        if (self::_columnExists($websoccer, $db, 'trainingslager', 'injury_risk')) {
            $columns["injury_risk"] = "injury_risk";
        }

        return $columns;
    }

    private static function _normalizeCampDefaults($camp) {
        if (!isset($camp['camp_type']) || !strlen((string) $camp['camp_type'])) {
            $camp['camp_type'] = 'balanced';
        }
        if (!isset($camp['effect_team_chemistry'])) {
            $camp['effect_team_chemistry'] = 0;
        }
        if (!isset($camp['injury_risk'])) {
            $camp['injury_risk'] = 0;
        }
        if (!isset($camp['player_count'])) {
            $camp['player_count'] = 0;
        }
        if (!isset($camp['total_costs'])) {
            $camp['total_costs'] = 0;
        }
        foreach (self::_getSpecialAttributeDefinitions() as $attribute) {
            if (!isset($camp[$attribute['alias']])) {
                $camp[$attribute['alias']] = 0;
            }
        }
        $camp['special_effects'] = self::_buildSpecialEffectList($camp, 'camp');
        return $camp;
    }

    private static function _normalizePlayerValue($value) {
        return number_format(min(100, max(1, (float) $value)), 2, '.', '');
    }

    private static function _normalizePercent($value) {
        return min(100, max(0, (int) round((float) $value)));
    }

    private static function _applyTeamChemistryEffect(WebSoccer $websoccer, DbConnection $db, $teamId, $effectChemistry, $bookingInfo) {
        $data = array('old_score' => 0, 'new_score' => 0, 'change' => 0, 'match_effect' => 0);
        $effectChemistry = (int) $effectChemistry;

        if ($effectChemistry === 0 || !self::_columnExists($websoccer, $db, 'verein', 'team_chemistry')) {
            return $data;
        }

        $result = $db->querySelect(
            'team_chemistry',
            $websoccer->getConfig('db_prefix') . '_verein',
            'id = %d',
            (int) $teamId,
            1
        );
        $team = $result->fetch_array();
        $result->free();

        $oldScore = ($team && isset($team['team_chemistry'])) ? (int) $team['team_chemistry'] : 50;
        $newScore = self::_normalizePercent($oldScore + $effectChemistry);
        $change = $newScore - $oldScore;
        $matchEffect = self::_scoreToMatchEffect($websoccer, $newScore);

        $columns = array(
            'team_chemistry' => $newScore,
            'team_chemistry_updated' => $websoccer->getNowAsTimestamp()
        );
        if (self::_columnExists($websoccer, $db, 'verein', 'team_chemistry_effect')) {
            $columns['team_chemistry_effect'] = $matchEffect;
        }

        $db->queryUpdate($columns, $websoccer->getConfig('db_prefix') . '_verein', 'id = %d', (int) $teamId);

        if (self::_tableExists($websoccer, $db, 'team_chemistry_log')) {
            $db->queryInsert(
                array(
                    'team_id' => (int) $teamId,
                    'event_date' => $websoccer->getNowAsTimestamp(),
                    'source' => 'trainingcamp',
                    'old_score' => $oldScore,
                    'new_score' => $newScore,
                    'match_effect' => $matchEffect,
                    'match_id' => '',
                    'breakdown_data' => json_encode(array(
                        'trainingcamp' => array(
                            'score' => $newScore,
                            'detail' => isset($bookingInfo['name']) ? $bookingInfo['name'] : ''
                        )
                    ))
                ),
                $websoccer->getConfig('db_prefix') . '_team_chemistry_log'
            );
        }

        $data['old_score'] = $oldScore;
        $data['new_score'] = $newScore;
        $data['change'] = $change;
        $data['match_effect'] = $matchEffect;
        return $data;
    }

    private static function _scoreToMatchEffect(WebSoccer $websoccer, $score) {
        $maxEffect = 3;
        try {
            $configured = $websoccer->getConfig('team_chemistry_max_match_effect');
            if ($configured !== null && $configured !== '') {
                $maxEffect = (int) $configured;
            }
        } catch (Exception $e) {
            $maxEffect = 3;
        }
        $maxEffect = min(5, max(0, $maxEffect));
        $effect = (int) round(((self::_normalizePercent($score) - 50) / 50) * $maxEffect);
        return min($maxEffect, max(0 - $maxEffect, $effect));
    }

    private static function _createCampReport(WebSoccer $websoccer, DbConnection $db, $teamId, $bookingInfo, $duration, $playerCount, $injuries, $chemistryData) {
        $totalCosts = isset($bookingInfo['total_costs']) && (int) $bookingInfo['total_costs'] > 0
            ? (int) $bookingInfo['total_costs']
            : self::calculateTotalCosts($bookingInfo, $duration, $playerCount);

        $report = array(
            'team_id' => (int) $teamId,
            'camp_id' => isset($bookingInfo['camp_id']) ? (int) $bookingInfo['camp_id'] : (isset($bookingInfo['id']) ? (int) $bookingInfo['id'] : 0),
            'camp_name' => isset($bookingInfo['name']) ? $bookingInfo['name'] : '',
            'camp_type' => isset($bookingInfo['camp_type']) ? $bookingInfo['camp_type'] : 'balanced',
            'date_start' => (int) $bookingInfo['date_start'],
            'date_end' => (int) $bookingInfo['date_end'],
            'completed_date' => $websoccer->getNowAsTimestamp(),
            'duration_days' => (int) $duration,
            'player_count' => (int) $playerCount,
            'total_costs' => (int) $totalCosts,
            'effect_strength_total' => (int) round((float) $bookingInfo['effect_strength'] * $duration),
            'effect_technique_total' => (int) round((float) $bookingInfo['effect_strength_technique'] * $duration),
            'effect_stamina_total' => (int) round((float) $bookingInfo['effect_strength_stamina'] * $duration),
            'effect_freshness_total' => (int) round((float) $bookingInfo['effect_strength_freshness'] * $duration),
            'effect_satisfaction_total' => (int) round((float) $bookingInfo['effect_strength_satisfaction'] * $duration),
            'effect_chemistry_total' => (int) $chemistryData['change'],
            'injuries' => (int) $injuries,
            'old_chemistry' => (int) $chemistryData['old_score'],
            'new_chemistry' => (int) $chemistryData['new_score']
        );

        foreach (self::_getSpecialAttributeDefinitions() as $attribute) {
            $report[$attribute['report_column']] = (int) round((isset($bookingInfo[$attribute['alias']]) ? (float) $bookingInfo[$attribute['alias']] : 0) * $duration);
        }

        if (self::_tableExists($websoccer, $db, 'trainingslager_report')) {
            $insertReport = $report;
            foreach (self::_getSpecialAttributeDefinitions() as $attribute) {
                if (!self::_columnExists($websoccer, $db, 'trainingslager_report', $attribute['report_column'])) {
                    unset($insertReport[$attribute['report_column']]);
                }
            }
            $db->queryInsert($insertReport, $websoccer->getConfig('db_prefix') . '_trainingslager_report');
            $report['id'] = $db->getLastInsertedId();
        }

        return self::_prepareReportForTemplate($report);
    }

    private static function _prepareReportForTemplate($report) {
        $report['effect_strength_signed'] = self::formatSignedNumber($report['effect_strength_total']);
        $report['effect_technique_signed'] = self::formatSignedNumber($report['effect_technique_total']);
        $report['effect_stamina_signed'] = self::formatSignedNumber($report['effect_stamina_total']);
        $report['effect_freshness_signed'] = self::formatSignedNumber($report['effect_freshness_total']);
        $report['effect_satisfaction_signed'] = self::formatSignedNumber($report['effect_satisfaction_total']);
        $report['effect_chemistry_signed'] = self::formatSignedNumber($report['effect_chemistry_total']);
        foreach (self::_getSpecialAttributeDefinitions() as $attribute) {
            if (!isset($report[$attribute['report_column']])) {
                $report[$attribute['report_column']] = 0;
            }
            $report[$attribute['signed_key']] = self::formatSignedNumber($report[$attribute['report_column']]);
        }
        $report['special_effects'] = self::_buildSpecialEffectList($report, 'report');
        return $report;
    }

    public static function columnExists(WebSoccer $websoccer, DbConnection $db, $tableName, $columnName) {
        return self::_columnExists($websoccer, $db, $tableName, $columnName);
    }

    public static function tableExists(WebSoccer $websoccer, DbConnection $db, $tableName) {
        return self::_tableExists($websoccer, $db, $tableName);
    }

    private static function _columnExists(WebSoccer $websoccer, DbConnection $db, $tableName, $columnName) {
        $cacheKey = $tableName . '.' . $columnName;
        if (isset(self::$_columnExistsCache[$cacheKey])) {
            return self::$_columnExistsCache[$cacheKey];
        }

        $table = $websoccer->getConfig('db_prefix') . '_' . $tableName;
        $result = $db->executeQuery("SHOW COLUMNS FROM " . $table . " LIKE '" . $db->connection->real_escape_string($columnName) . "'");
        $row = $result->fetch_array();
        $result->free();

        self::$_columnExistsCache[$cacheKey] = $row ? TRUE : FALSE;
        return self::$_columnExistsCache[$cacheKey];
    }

    private static function _tableExists(WebSoccer $websoccer, DbConnection $db, $tableName) {
        if (isset(self::$_tableExistsCache[$tableName])) {
            return self::$_tableExistsCache[$tableName];
        }

        $table = $websoccer->getConfig('db_prefix') . '_' . $tableName;
        $result = $db->executeQuery("SHOW TABLES LIKE '" . $db->connection->real_escape_string($table) . "'");
        $row = $result->fetch_array();
        $result->free();

        self::$_tableExistsCache[$tableName] = $row ? TRUE : FALSE;
        return self::$_tableExistsCache[$tableName];
    }
}
?>
