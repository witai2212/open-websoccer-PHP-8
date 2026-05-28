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
 * Provides deeper post-match analytics based on cm23_spiel_berechnung.
 */
class MatchAnalyticsDataService {

    public static function getAnalytics(WebSoccer $websoccer, DbConnection $db, $matchId) {
        $match = MatchesDataService::getMatchById($websoccer, $db, $matchId, TRUE, TRUE);
        if (!isset($match['match_id'])) {
            return array();
        }

        $homePlayers = self::getPlayerAnalytics($websoccer, $db, $matchId, $match['match_home_id']);
        $guestPlayers = self::getPlayerAnalytics($websoccer, $db, $matchId, $match['match_guest_id']);

        $homeStats = self::_createTeamStats($homePlayers);
        $guestStats = self::_createTeamStats($guestPlayers);

        $homeStats['goals'] = (int) $match['match_goals_home'];
        $guestStats['goals'] = (int) $match['match_goals_guest'];

        return array(
            'match' => $match,
            'home_players' => $homePlayers,
            'guest_players' => $guestPlayers,
            'home_statistics' => $homeStats,
            'guest_statistics' => $guestStats,
            'top_performers' => self::_getTopPerformers($homePlayers, $guestPlayers),
            'chart' => self::_getRatingTrendChartData($homePlayers, $guestPlayers)
        );
    }

    public static function getPlayerAnalytics(WebSoccer $websoccer, DbConnection $db, $matchId, $teamId) {
        $prefix = $websoccer->getConfig('db_prefix');

        $fromTable = $prefix . '_spiel_berechnung AS M';
        $fromTable .= ' LEFT JOIN ' . $prefix . '_spieler AS P ON P.id = M.spieler_id';

        $columns = array(
            'M.id' => 'record_id',
            'M.spieler_id' => 'id',
            'M.team_id' => 'team_id',
            'M.name' => 'snapshot_name',
            'P.vorname' => 'firstName',
            'P.nachname' => 'lastName',
            'P.kunstname' => 'pseudonym',
            'P.position' => 'position',
            'P.note_last' => 'grade_last',
            'P.note_schnitt' => 'grade_average',
            'M.position_main' => 'position_main',
            'M.position' => 'match_position',
            'M.note' => 'grade',
            'M.minuten_gespielt' => 'minutesPlayed',
            'M.feld' => 'playstatus',
            'M.tore' => 'goals',
            'M.assists' => 'assists',
            'M.ballcontacts' => 'ballcontacts',
            'M.wontackles' => 'wontackles',
            'M.losttackles' => 'losttackles',
            'M.shoots' => 'shoots',
            'M.passes_successed' => 'passes_successed',
            'M.passes_failed' => 'passes_failed',
            'M.freekicks' => 'freekicks',
            'M.freekicks_successed' => 'freekicks_successed',
            'M.freekicks_failed' => 'freekicks_failed',
            'M.karte_gelb' => 'yellowCards',
            'M.karte_rot' => 'redCard',
            'M.verletzt' => 'injured',
            'M.gesperrt' => 'blocked'
        );

        $order = 'FIELD(M.feld, \'1\', \'Ausgewechselt\', \'Ersatzbank\'), FIELD(M.position_main, \'T\', \'LV\', \'IV\', \'RV\', \'DM\', \'LM\', \'ZM\', \'RM\', \'OM\', \'LS\', \'MS\', \'RS\'), M.id ASC';
        $whereCondition = 'M.spiel_id = %d AND M.team_id = %d ORDER BY ' . $order;
        $parameters = array($matchId, $teamId);

        $players = array();
        $result = $db->querySelect($columns, $fromTable, $whereCondition, $parameters);
        while ($player = $result->fetch_array()) {
            $player = self::_normalizePlayer($player);
            $players[] = $player;
        }
        $result->free();

        return $players;
    }

    private static function _normalizePlayer($player) {
        $player['display_name'] = self::_getPlayerDisplayName($player);

        $integerFields = array('minutesPlayed', 'goals', 'assists', 'ballcontacts', 'wontackles', 'losttackles', 'shoots', 'passes_successed', 'passes_failed', 'freekicks', 'freekicks_successed', 'freekicks_failed', 'yellowCards', 'redCard', 'injured', 'blocked');
        foreach ($integerFields as $field) {
            $player[$field] = isset($player[$field]) ? (int) $player[$field] : 0;
        }

        $player['grade'] = isset($player['grade']) ? (float) $player['grade'] : 0.0;
        $player['grade_last'] = isset($player['grade_last']) ? (float) $player['grade_last'] : 0.0;
        $player['grade_average'] = isset($player['grade_average']) ? (float) $player['grade_average'] : 0.0;

        $player['passes_total'] = $player['passes_successed'] + $player['passes_failed'];
        $player['pass_success_percent'] = self::_percentage($player['passes_successed'], $player['passes_total']);

        $player['tackles_total'] = $player['wontackles'] + $player['losttackles'];
        $player['tackle_success_percent'] = self::_percentage($player['wontackles'], $player['tackles_total']);

        $player['freekicks_total'] = $player['freekicks_successed'] + $player['freekicks_failed'];
        if ($player['freekicks_total'] < 1 && $player['freekicks'] > 0) {
            $player['freekicks_total'] = $player['freekicks'];
        }
        $player['freekick_success_percent'] = self::_percentage($player['freekicks_successed'], $player['freekicks_total']);

        $player['grade_difference_average'] = ($player['grade'] > 0 && $player['grade_average'] > 0) ? round($player['grade'] - $player['grade_average'], 2) : 0;
        $player['grade_difference_last'] = ($player['grade'] > 0 && $player['grade_last'] > 0) ? round($player['grade'] - $player['grade_last'], 2) : 0;

        return $player;
    }

    private static function _getPlayerDisplayName($player) {
        if (isset($player['pseudonym']) && strlen($player['pseudonym'])) {
            return $player['pseudonym'];
        }

        $name = trim((string) $player['firstName'] . ' ' . (string) $player['lastName']);
        if (strlen($name)) {
            return $name;
        }

        if (isset($player['snapshot_name']) && strlen($player['snapshot_name'])) {
            return $player['snapshot_name'];
        }

        return '-';
    }

    private static function _createTeamStats($players) {
        $stats = array(
            'players_used' => 0,
            'goals' => 0,
            'shoots' => 0,
            'ballcontacts' => 0,
            'assists' => 0,
            'wontackles' => 0,
            'losttackles' => 0,
            'tackles_total' => 0,
            'tackle_success_percent' => 0,
            'passes_successed' => 0,
            'passes_failed' => 0,
            'passes_total' => 0,
            'pass_success_percent' => 0,
            'freekicks' => 0,
            'freekicks_successed' => 0,
            'freekicks_failed' => 0,
            'freekicks_total' => 0,
            'freekick_success_percent' => 0,
            'grade_sum' => 0,
            'grade_count' => 0,
            'grade_average' => 0
        );

        foreach ($players as $player) {
            if ($player['minutesPlayed'] > 0) {
                $stats['players_used']++;
            }

            $stats['goals'] += $player['goals'];
            $stats['shoots'] += $player['shoots'];
            $stats['ballcontacts'] += $player['ballcontacts'];
            $stats['assists'] += $player['assists'];
            $stats['wontackles'] += $player['wontackles'];
            $stats['losttackles'] += $player['losttackles'];
            $stats['passes_successed'] += $player['passes_successed'];
            $stats['passes_failed'] += $player['passes_failed'];
            $stats['freekicks'] += $player['freekicks'];
            $stats['freekicks_successed'] += $player['freekicks_successed'];
            $stats['freekicks_failed'] += $player['freekicks_failed'];

            if ($player['minutesPlayed'] > 0 && $player['grade'] > 0) {
                $stats['grade_sum'] += $player['grade'];
                $stats['grade_count']++;
            }
        }

        $stats['tackles_total'] = $stats['wontackles'] + $stats['losttackles'];
        $stats['tackle_success_percent'] = self::_percentage($stats['wontackles'], $stats['tackles_total']);

        $stats['passes_total'] = $stats['passes_successed'] + $stats['passes_failed'];
        $stats['pass_success_percent'] = self::_percentage($stats['passes_successed'], $stats['passes_total']);

        $stats['freekicks_total'] = $stats['freekicks_successed'] + $stats['freekicks_failed'];
        if ($stats['freekicks_total'] < 1 && $stats['freekicks'] > 0) {
            $stats['freekicks_total'] = $stats['freekicks'];
        }
        $stats['freekick_success_percent'] = self::_percentage($stats['freekicks_successed'], $stats['freekicks_total']);

        if ($stats['grade_count'] > 0) {
            $stats['grade_average'] = round($stats['grade_sum'] / $stats['grade_count'], 2);
        }

        return $stats;
    }

    private static function _getTopPerformers($homePlayers, $guestPlayers) {
        $players = array_merge($homePlayers, $guestPlayers);
        $players = array_filter($players, array('MatchAnalyticsDataService', '_hasPlayed'));

        return array(
            'best_grade' => self::_findTopPlayer($players, 'grade', TRUE, 0),
            'most_ballcontacts' => self::_findTopPlayer($players, 'ballcontacts', FALSE, 1),
            'most_shots' => self::_findTopPlayer($players, 'shoots', FALSE, 1),
            'most_assists' => self::_findTopPlayer($players, 'assists', FALSE, 1),
            'best_passer' => self::_findTopPlayer($players, 'pass_success_percent', FALSE, 1, 'passes_total', 5),
            'best_tackler' => self::_findTopPlayer($players, 'tackle_success_percent', FALSE, 1, 'tackles_total', 3)
        );
    }

    public static function _hasPlayed($player) {
        return ((int) $player['minutesPlayed'] > 0);
    }

    private static function _findTopPlayer($players, $field, $lowerIsBetter = FALSE, $minimumValue = 0, $minimumField = null, $minimumFieldValue = 0) {
        $best = null;

        foreach ($players as $player) {
            if (!isset($player[$field])) {
                continue;
            }

            if ($minimumField !== null && (!isset($player[$minimumField]) || (float) $player[$minimumField] < $minimumFieldValue)) {
                continue;
            }

            $value = (float) $player[$field];
            if ($value <= $minimumValue) {
                continue;
            }

            if ($best === null) {
                $best = $player;
                continue;
            }

            if ($lowerIsBetter && $value < (float) $best[$field]) {
                $best = $player;
            } else if (!$lowerIsBetter && $value > (float) $best[$field]) {
                $best = $player;
            }
        }

        return $best;
    }

    private static function _getRatingTrendChartData($homePlayers, $guestPlayers) {
        $players = array_merge($homePlayers, $guestPlayers);
        $players = array_filter($players, array('MatchAnalyticsDataService', '_hasPlayed'));
        usort($players, array('MatchAnalyticsDataService', '_sortByBestGrade'));
        $players = array_slice($players, 0, 8);

        $labels = array();
        $matchGrades = array();
        $lastGrades = array();
        $averageGrades = array();

        foreach ($players as $player) {
            $labels[] = $player['display_name'];
            $matchGrades[] = ($player['grade'] > 0) ? round($player['grade'], 2) : null;
            $lastGrades[] = ($player['grade_last'] > 0) ? round($player['grade_last'], 2) : null;
            $averageGrades[] = ($player['grade_average'] > 0) ? round($player['grade_average'], 2) : null;
        }

        return array(
            'labels' => json_encode($labels),
            'match_grades' => json_encode($matchGrades),
            'last_grades' => json_encode($lastGrades),
            'average_grades' => json_encode($averageGrades)
        );
    }

    public static function _sortByBestGrade($a, $b) {
        $gradeA = isset($a['grade']) ? (float) $a['grade'] : 99;
        $gradeB = isset($b['grade']) ? (float) $b['grade'] : 99;

        if ($gradeA == $gradeB) {
            return 0;
        }

        return ($gradeA < $gradeB) ? -1 : 1;
    }

    private static function _percentage($value, $total) {
        $total = (int) $total;
        if ($total < 1) {
            return 0;
        }

        return round(((int) $value) * 100 / $total, 1);
    }
}
?>
