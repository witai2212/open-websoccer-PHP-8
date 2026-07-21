<?php
/******************************************************

  Player talent visibility helper for CM23.

******************************************************/

/**
 * Centralizes player talent visibility on the player detail page.
 *
 * Rules:
 * - Own players: exact talent.
 * - Active club partnership with shared scouting: exact talent.
 * - Watched players with matching scout speciality: estimate depending on scout expertise.
 * - Watched players without access: show unknown with a useful hint.
 * - Unwatched players without access: hide talent row.
 */
class PlayerTalentVisibilityDataService {

    const MAX_TALENT_BAR = 6;

    public static function getVisibility(WebSoccer $websoccer, DbConnection $db, $player, $userTeamId, $isOnMyWatchlist) {
        $userTeamId = (int) $userTeamId;
        $playerTeamId = (isset($player['team_id'])) ? (int) $player['team_id'] : 0;
        $talent = self::normalizeTalent(isset($player['player_strength_talent']) ? $player['player_strength_talent'] : 0);
        $position = self::normalizePosition(isset($player['player_position_de']) ? $player['player_position_de'] : '');

        $visibility = self::baseVisibility($talent, $position);
        $visibility['partnership'] = self::getActivePartnershipBetween($websoccer, $db, $userTeamId, $playerTeamId);

        if ($userTeamId > 0 && $playerTeamId > 0 && $userTeamId === $playerTeamId) {
            return self::exactVisibility($visibility, 'player_talent_source_own', 'player_talent_access_exact');
        }

        if (!empty($visibility['partnership']) && isset($visibility['partnership']['shared_scouting']) && $visibility['partnership']['shared_scouting'] === '1') {
            return self::exactVisibility($visibility, 'player_talent_source_partnership', 'player_talent_access_exact');
        }

        if ((int) $isOnMyWatchlist > 0 && $userTeamId > 0 && strlen($position)) {
            $scout = self::getBestMatchingScout($websoccer, $db, $userTeamId, $position);
            if ($scout && isset($scout['id'])) {
                return self::scoutVisibility($visibility, $scout);
            }

            $visibility['show'] = TRUE;
            $visibility['mode'] = 'unknown';
            $visibility['source_key'] = 'player_talent_source_watchlist';
            $visibility['hint_key'] = 'player_talent_hint_matching_scout_required';
            return $visibility;
        }

        return $visibility;
    }

    public static function isAccessVisible($visibility) {
        if (!is_array($visibility)) {
            return FALSE;
        }
        if (!isset($visibility['show']) || !$visibility['show']) {
            return FALSE;
        }
        if (!isset($visibility['mode'])) {
            return FALSE;
        }
        return in_array($visibility['mode'], array('exact', 'range', 'category'), TRUE);
    }

    private static function baseVisibility($talent, $position) {
        return array(
            'show' => FALSE,
            'mode' => 'hidden',
            'value' => $talent,
            'bar_value' => self::barValue($talent),
            'range_min' => 0,
            'range_max' => 0,
            'range_min_bar' => 0,
            'range_max_bar' => 0,
            'label_key' => self::talentLabelKey($talent),
            'source_key' => '',
            'hint_key' => '',
            'accuracy_key' => '',
            'required_position' => $position,
            'scout_name' => '',
            'scout_speciality' => '',
            'partnership' => array()
        );
    }

    private static function exactVisibility($visibility, $sourceKey, $accuracyKey) {
        $visibility['show'] = TRUE;
        $visibility['mode'] = 'exact';
        $visibility['source_key'] = $sourceKey;
        $visibility['accuracy_key'] = $accuracyKey;
        return $visibility;
    }

    private static function scoutVisibility($visibility, $scout) {
        $expertise = isset($scout['expertise']) ? (int) $scout['expertise'] : 0;
        $talent = (int) $visibility['value'];

        $visibility['show'] = TRUE;
        $visibility['source_key'] = 'player_talent_source_scout';
        $visibility['scout_name'] = isset($scout['name']) ? $scout['name'] : '';
        $visibility['scout_speciality'] = isset($scout['speciality']) ? $scout['speciality'] : '';

        if ($expertise >= 90) {
            $visibility['mode'] = 'exact';
            $visibility['accuracy_key'] = 'player_talent_accuracy_very_high';
            return $visibility;
        }

        if ($expertise >= 70) {
            $rangeMin = max(1, $talent - 1);
            $rangeMax = min(self::MAX_TALENT_BAR, $talent + 1);
            if ($rangeMin === $rangeMax) {
                if ($rangeMin > 1) {
                    $rangeMin--;
                } else {
                    $rangeMax = min(self::MAX_TALENT_BAR, $rangeMax + 1);
                }
            }
            $visibility['mode'] = 'range';
            $visibility['range_min'] = $rangeMin;
            $visibility['range_max'] = $rangeMax;
            $visibility['range_min_bar'] = self::barValue($rangeMin);
            $visibility['range_max_bar'] = self::barValue($rangeMax);
            $visibility['accuracy_key'] = 'player_talent_accuracy_high';
            return $visibility;
        }

        if ($expertise >= 50) {
            $visibility['mode'] = 'category';
            $visibility['accuracy_key'] = 'player_talent_accuracy_medium';
            return $visibility;
        }

        $visibility['mode'] = 'unknown';
        $visibility['source_key'] = 'player_talent_source_scout';
        $visibility['accuracy_key'] = 'player_talent_accuracy_low';
        $visibility['hint_key'] = 'player_talent_hint_scout_too_weak';
        return $visibility;
    }

    private static function getBestMatchingScout(WebSoccer $websoccer, DbConnection $db, $teamId, $speciality) {
        $result = $db->querySelect(
            '*',
            $websoccer->getConfig('db_prefix') . '_scout',
            'team_id = %d AND speciality = \'%s\' AND team_matches > 0 ORDER BY expertise DESC, fee DESC',
            array((int) $teamId, $speciality),
            1
        );
        $row = $result->fetch_array();
        $result->free();
        return ($row) ? $row : array();
    }

    private static function getActivePartnershipBetween(WebSoccer $websoccer, DbConnection $db, $userTeamId, $playerTeamId) {
        $userTeamId = (int) $userTeamId;
        $playerTeamId = (int) $playerTeamId;
        if ($userTeamId <= 0 || $playerTeamId <= 0 || $userTeamId === $playerTeamId) {
            return array();
        }

        $prefix = $websoccer->getConfig('db_prefix');
        $columns = 'CP.*, P.name AS parent_name, C.name AS partner_name';
        $fromTable = $prefix . '_club_partnership AS CP '
            . 'INNER JOIN ' . $prefix . '_verein AS P ON P.id = CP.parent_team_id '
            . 'INNER JOIN ' . $prefix . '_verein AS C ON C.id = CP.partner_team_id';
        $where = "CP.status = 'active' AND ((CP.parent_team_id = %d AND CP.partner_team_id = %d) OR (CP.parent_team_id = %d AND CP.partner_team_id = %d))";
        $params = array($userTeamId, $playerTeamId, $playerTeamId, $userTeamId);

        $result = $db->querySelect($columns, $fromTable, $where, $params, 1);
        $row = $result->fetch_array();
        $result->free();

        if (!$row) {
            return array();
        }

        $row['user_is_parent'] = ((int) $row['parent_team_id'] === $userTeamId) ? '1' : '0';
        $row['player_club_name'] = ((int) $row['parent_team_id'] === $playerTeamId) ? $row['parent_name'] : $row['partner_name'];
        $row['player_club_role_key'] = ((int) $row['parent_team_id'] === $playerTeamId) ? 'player_partnership_role_parent' : 'player_partnership_role_partner';
        $row['user_club_role_key'] = ((int) $row['parent_team_id'] === $userTeamId) ? 'player_partnership_role_parent' : 'player_partnership_role_partner';

        return $row;
    }

    private static function normalizePosition($position) {
        $position = trim((string) $position);
        $allowed = array('Torwart', 'Abwehr', 'Mittelfeld', 'Sturm');
        return in_array($position, $allowed) ? $position : '';
    }

    private static function normalizeTalent($talent) {
        $talent = (int) $talent;
        if ($talent < 1) {
            return 1;
        }
        if ($talent > 6) {
            return 6;
        }
        return $talent;
    }

    private static function barValue($talent) {
        $talent = (int) $talent;
        if ($talent < 1) {
            return 1;
        }
        if ($talent > self::MAX_TALENT_BAR) {
            return self::MAX_TALENT_BAR;
        }
        return $talent;
    }

    private static function talentLabelKey($talent) {
        $talent = (int) $talent;
        if ($talent <= 1) return 'player_talent_label_low';
        if ($talent === 2) return 'player_talent_label_fair';
        if ($talent === 3) return 'player_talent_label_good';
        if ($talent === 4) return 'player_talent_label_high';
        return 'player_talent_label_exceptional';
    }
}
?>
