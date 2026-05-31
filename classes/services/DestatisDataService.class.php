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
 * Data service for CM|STATIS rankings and dashboard widgets.
 */
class DestatisDataService {

    private static $_tableExistsCache = array();

    private static function _prefix(WebSoccer $websoccer) {
        return $websoccer->getConfig('db_prefix');
    }

    private static function _max(WebSoccer $websoccer, $fallback = 20) {
        $max = (int) $websoccer->getConfig('entries_per_page');
        return ($max > 0) ? $max : $fallback;
    }

    private static function _fetchAll(DbConnection $db, $sqlStr) {
        $rows = array();
        $result = $db->executeQuery($sqlStr);
        while ($row = $result->fetch_array()) {
            $rows[] = $row;
        }
        $result->free();
        return $rows;
    }

    private static function _fetchOne(DbConnection $db, $sqlStr) {
        $result = $db->executeQuery($sqlStr);
        $row = $result->fetch_array();
        $result->free();
        return ($row) ? $row : array();
    }

    private static function _tableExists(WebSoccer $websoccer, DbConnection $db, $tableName) {
        $fullTableName = self::_prefix($websoccer) . '_' . $tableName;
        if (isset(self::$_tableExistsCache[$fullTableName])) {
            return self::$_tableExistsCache[$fullTableName];
        }

        $escapedName = $db->connection->real_escape_string($fullTableName);
        $result = $db->executeQuery("SHOW TABLES LIKE '" . $escapedName . "'");
        $exists = ($result->num_rows > 0);
        $result->free();
        self::$_tableExistsCache[$fullTableName] = $exists;
        return $exists;
    }

    private static function _rankingResult($labelKey, $valueLabelKey, $rows, $teamId) {
        $total = count($rows);
        if ($teamId < 1 || $total < 1) {
            return array(
                'label_key' => $labelKey,
                'value_label_key' => $valueLabelKey,
                'rank' => 0,
                'total' => $total,
                'value' => 0,
                'top_percent' => 0
            );
        }

        $rank = 1;
        foreach ($rows as $row) {
            if ((int) $row['club_id'] === (int) $teamId) {
                $topPercent = ($total > 0) ? (int) ceil(($rank / $total) * 100) : 0;
                $row['label_key'] = $labelKey;
                $row['value_label_key'] = $valueLabelKey;
                $row['rank'] = $rank;
                $row['total'] = $total;
                $row['top_percent'] = $topPercent;
                return $row;
            }
            $rank++;
        }

        return array(
            'label_key' => $labelKey,
            'value_label_key' => $valueLabelKey,
            'rank' => 0,
            'total' => $total,
            'value' => 0,
            'top_percent' => 0
        );
    }

    /**
     * Provides compact dashboard highlights for the CM|STATIS start page.
     */
    public static function getDashboardHighlights(WebSoccer $websoccer, DbConnection $db) {
        $prefix = self::_prefix($websoccer);
        $highlights = array();

        $highlights['best_player'] = self::_fetchOne($db, "
            SELECT
                P.id AS player_id,
                P.vorname,
                P.nachname,
                P.kunstname,
                CAST(P.w_staerke AS DECIMAL(10,2)) AS value,
                C.id AS club_id,
                C.name AS club_name,
                C.bild AS club_bild
            FROM " . $prefix . "_spieler AS P
            LEFT JOIN " . $prefix . "_verein AS C ON C.id = P.verein_id
            WHERE P.status = '1'
            ORDER BY CAST(P.w_staerke AS DECIMAL(10,2)) DESC,
                CAST(P.w_staerke_calc AS DECIMAL(10,2)) DESC,
                CAST(P.marktwert AS UNSIGNED) DESC
            LIMIT 1");

        $highlights['top_scorer'] = self::_fetchOne($db, "
            SELECT
                P.id AS player_id,
                P.vorname,
                P.nachname,
                P.kunstname,
                P.sa_tore AS value,
                P.sa_assists,
                (P.sa_tore + P.sa_assists) AS scorer_points,
                C.id AS club_id,
                C.name AS club_name,
                C.bild AS club_bild
            FROM " . $prefix . "_spieler AS P
            LEFT JOIN " . $prefix . "_verein AS C ON C.id = P.verein_id
            WHERE P.status = '1'
            ORDER BY P.sa_tore DESC, P.sa_assists DESC, P.note_schnitt ASC
            LIMIT 1");

        $highlights['richest_club'] = self::_fetchOne($db, "
            SELECT
                C.id AS club_id,
                C.name AS club_name,
                C.bild AS club_bild,
                C.finanz_budget AS value
            FROM " . $prefix . "_verein AS C
            WHERE C.status = '1' AND C.nationalteam != '1'
            ORDER BY C.finanz_budget DESC, C.name ASC
            LIMIT 1");

        $highlights['most_valuable_team'] = self::_fetchOne($db, "
            SELECT
                C.id AS club_id,
                C.name AS club_name,
                C.bild AS club_bild,
                SUM(CAST(P.marktwert AS UNSIGNED)) AS value
            FROM " . $prefix . "_verein AS C
            INNER JOIN " . $prefix . "_spieler AS P ON P.verein_id = C.id AND P.status = '1'
            WHERE C.status = '1' AND C.nationalteam != '1'
            GROUP BY C.id
            ORDER BY value DESC, C.name ASC
            LIMIT 1");

        $highlights['largest_stadium'] = self::_fetchOne($db, "
            SELECT
                S.id AS stadium_id,
                S.name AS stadium_name,
                C.id AS club_id,
                C.name AS club_name,
                C.bild AS club_bild,
                (IFNULL(S.p_sitz,0)+IFNULL(S.p_steh,0)+IFNULL(S.p_haupt_steh,0)+IFNULL(S.p_haupt_sitz,0)+IFNULL(S.p_vip,0)) AS value
            FROM " . $prefix . "_stadion AS S
            INNER JOIN " . $prefix . "_verein AS C ON C.stadion_id = S.id
            WHERE C.status = '1' AND C.nationalteam != '1'
            ORDER BY value DESC, C.name ASC
            LIMIT 1");

        $highlights['best_manager'] = self::_fetchOne($db, "
            SELECT
                U.id AS user_id,
                U.nick AS manager_name,
                U.highscore AS value,
                C.id AS club_id,
                C.name AS club_name,
                C.bild AS club_bild
            FROM " . $prefix . "_user AS U
            LEFT JOIN " . $prefix . "_verein AS C ON C.user_id = U.id AND C.status = '1'
            WHERE U.status = '1'
            ORDER BY U.highscore DESC, U.fanbeliebtheit DESC, U.nick ASC
            LIMIT 1");

        $highlights['highest_attendance'] = self::getHighestAttendanceTeam($websoccer, $db);

        return $highlights;
    }

    public static function getHighestAttendanceTeam(WebSoccer $websoccer, DbConnection $db) {
        $prefix = self::_prefix($websoccer);

        if (self::_tableExists($websoccer, $db, 'stadium_attendance_log')) {
            return self::_fetchOne($db, "
                SELECT
                    C.id AS club_id,
                    C.name AS club_name,
                    C.bild AS club_bild,
                    ROUND(AVG(SAL.total_visitors),0) AS value,
                    ROUND(AVG(SAL.total_visitors / NULLIF(SAL.total_capacity, 0)) * 100, 2) AS occupancy
                FROM " . $prefix . "_stadium_attendance_log AS SAL
                INNER JOIN " . $prefix . "_verein AS C ON C.id = SAL.team_id
                WHERE C.status = '1' AND C.nationalteam != '1' AND SAL.total_visitors > 0
                GROUP BY C.id
                ORDER BY value DESC, occupancy DESC, C.name ASC
                LIMIT 1");
        }

        return self::_fetchOne($db, "
            SELECT
                C.id AS club_id,
                C.name AS club_name,
                C.bild AS club_bild,
                ROUND(AVG(S.zuschauer),0) AS value
            FROM " . $prefix . "_spiel AS S
            INNER JOIN " . $prefix . "_verein AS C ON C.id = S.home_verein
            WHERE S.berechnet = '1' AND S.zuschauer IS NOT NULL AND C.status = '1' AND C.nationalteam != '1'
            GROUP BY C.id
            ORDER BY value DESC, C.name ASC
            LIMIT 1");
    }

    /**
     * Provides a deterministic daily highlight.
     */
    public static function getDailyStat(WebSoccer $websoccer, DbConnection $db) {
        $prefix = self::_prefix($websoccer);
        $dayVariant = ((int) date('z')) % 5;

        if ($dayVariant === 0) {
            $topScorer = self::_fetchOne($db, "
                SELECT P.id AS player_id, P.vorname, P.nachname, P.kunstname, P.sa_tore, P.sa_assists, C.id AS club_id, C.name AS club_name
                FROM " . $prefix . "_spieler AS P
                LEFT JOIN " . $prefix . "_verein AS C ON C.id = P.verein_id
                WHERE P.status = '1'
                ORDER BY P.sa_tore DESC, P.sa_assists DESC
                LIMIT 1");
            if (isset($topScorer['player_id'])) {
                $kunstname = (isset($topScorer['kunstname']) && strlen($topScorer['kunstname'])) ? $topScorer['kunstname'] . ' ' : '';
                $playerName = trim($topScorer['vorname'] . ' ' . $kunstname . $topScorer['nachname']);
                return array(
                    'icon' => 'tor.png',
                    'title' => 'Torjäger des Tages',
                    'text' => $playerName . ' führt mit ' . number_format((int) $topScorer['sa_tore'], 0, ',', ' ') . ' Toren und ' . number_format((int) $topScorer['sa_assists'], 0, ',', ' ') . ' Vorlagen.',
                    'link_page' => 'player',
                    'link_params' => 'id=' . (int) $topScorer['player_id']
                );
            }
        } elseif ($dayVariant === 1) {
            $team = self::_fetchOne($db, "
                SELECT id AS club_id, name AS club_name, team_chemistry
                FROM " . $prefix . "_verein
                WHERE status = '1' AND nationalteam != '1'
                ORDER BY team_chemistry DESC, name ASC
                LIMIT 1");
            if (isset($team['club_id'])) {
                return array(
                    'icon' => 'stark.png',
                    'title' => 'Teamchemie des Tages',
                    'text' => $team['club_name'] . ' hat aktuell die beste Teamchemie (' . (int) $team['team_chemistry'] . '%).',
                    'link_page' => 'team',
                    'link_params' => 'id=' . (int) $team['club_id']
                );
            }
        } elseif ($dayVariant === 2) {
            $team = self::_fetchOne($db, "
                SELECT id AS club_id, name AS club_name, fan_mood
                FROM " . $prefix . "_verein
                WHERE status = '1' AND nationalteam != '1'
                ORDER BY fan_mood DESC, name ASC
                LIMIT 1");
            if (isset($team['club_id'])) {
                return array(
                    'icon' => 'publikum.png',
                    'title' => 'Fanliebling des Tages',
                    'text' => $team['club_name'] . ' hat aktuell die beste Fan-Stimmung (' . (int) $team['fan_mood'] . '%).',
                    'link_page' => 'team',
                    'link_params' => 'id=' . (int) $team['club_id']
                );
            }
        } elseif ($dayVariant === 3) {
            $stadium = self::getBestOccupancyTeam($websoccer, $db);
            if (isset($stadium['club_id'])) {
                return array(
                    'icon' => 'stadion.png',
                    'title' => 'Stadion des Tages',
                    'text' => $stadium['club_name'] . ' erreicht im Schnitt ' . number_format((float) $stadium['occupancy'], 1, ',', ' ') . '% Auslastung.',
                    'link_page' => 'team',
                    'link_params' => 'id=' . (int) $stadium['club_id']
                );
            }
        }

        $team = self::_fetchOne($db, "
            SELECT id AS club_id, name AS club_name, highscore
            FROM " . $prefix . "_verein
            WHERE status = '1' AND nationalteam != '1'
            ORDER BY highscore DESC, name ASC
            LIMIT 1");
        if (isset($team['club_id'])) {
            return array(
                'icon' => 'soccer-world-cup.png',
                'title' => 'Highscore des Tages',
                'text' => $team['club_name'] . ' führt den Team-Highscore mit ' . number_format((int) $team['highscore'], 0, ',', ' ') . ' Punkten an.',
                'link_page' => 'team',
                'link_params' => 'id=' . (int) $team['club_id']
            );
        }

        return array();
    }

    private static function getBestOccupancyTeam(WebSoccer $websoccer, DbConnection $db) {
        $prefix = self::_prefix($websoccer);

        if (self::_tableExists($websoccer, $db, 'stadium_attendance_log')) {
            return self::_fetchOne($db, "
                SELECT
                    C.id AS club_id,
                    C.name AS club_name,
                    ROUND(AVG(SAL.total_visitors / NULLIF(SAL.total_capacity, 0)) * 100, 2) AS occupancy
                FROM " . $prefix . "_stadium_attendance_log AS SAL
                INNER JOIN " . $prefix . "_verein AS C ON C.id = SAL.team_id
                WHERE C.status = '1' AND C.nationalteam != '1' AND SAL.total_capacity > 0
                GROUP BY C.id
                ORDER BY occupancy DESC, AVG(SAL.total_visitors) DESC, C.name ASC
                LIMIT 1");
        }

        return self::_fetchOne($db, "
            SELECT
                C.id AS club_id,
                C.name AS club_name,
                ROUND(AVG(S.zuschauer / NULLIF((IFNULL(ST.p_sitz,0)+IFNULL(ST.p_steh,0)+IFNULL(ST.p_haupt_steh,0)+IFNULL(ST.p_haupt_sitz,0)+IFNULL(ST.p_vip,0)), 0)) * 100, 2) AS occupancy
            FROM " . $prefix . "_spiel AS S
            INNER JOIN " . $prefix . "_verein AS C ON C.id = S.home_verein
            INNER JOIN " . $prefix . "_stadion AS ST ON ST.id = C.stadion_id
            WHERE S.berechnet = '1' AND S.zuschauer IS NOT NULL AND C.status = '1' AND C.nationalteam != '1'
            GROUP BY C.id
            ORDER BY occupancy DESC, AVG(S.zuschauer) DESC, C.name ASC
            LIMIT 1");
    }

    /**
     * Provides personal ranking positions of the currently managed club.
     */
    public static function getClubComparison(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $teamId = (int) $teamId;
        $prefix = self::_prefix($websoccer);

        $comparison = array();
        $comparison[] = self::_rankingResult('destatis_compare_strength', 'entity_strength', self::_fetchAll($db, "
            SELECT C.id AS club_id, C.name AS club_name, C.strength AS value
            FROM " . $prefix . "_verein AS C
            WHERE C.status = '1' AND C.nationalteam != '1'
            ORDER BY C.strength DESC, C.name ASC"), $teamId);

        $comparison[] = self::_rankingResult('destatis_compare_budget', 'entity_budget', self::_fetchAll($db, "
            SELECT C.id AS club_id, C.name AS club_name, C.finanz_budget AS value
            FROM " . $prefix . "_verein AS C
            WHERE C.status = '1' AND C.nationalteam != '1'
            ORDER BY C.finanz_budget DESC, C.name ASC"), $teamId);

        $comparison[] = self::_rankingResult('destatis_compare_marketvalue', 'bestplayers_marktwert', self::_fetchAll($db, "
            SELECT C.id AS club_id, C.name AS club_name, SUM(CAST(P.marktwert AS UNSIGNED)) AS value
            FROM " . $prefix . "_verein AS C
            INNER JOIN " . $prefix . "_spieler AS P ON P.verein_id = C.id AND P.status = '1'
            WHERE C.status = '1' AND C.nationalteam != '1'
            GROUP BY C.id
            ORDER BY value DESC, C.name ASC"), $teamId);

        $comparison[] = self::_rankingResult('destatis_compare_stadium', 'largest_stadiums_capacity', self::_fetchAll($db, "
            SELECT C.id AS club_id, C.name AS club_name,
                (IFNULL(S.p_sitz,0)+IFNULL(S.p_steh,0)+IFNULL(S.p_haupt_steh,0)+IFNULL(S.p_haupt_sitz,0)+IFNULL(S.p_vip,0)) AS value
            FROM " . $prefix . "_verein AS C
            INNER JOIN " . $prefix . "_stadion AS S ON S.id = C.stadion_id
            WHERE C.status = '1' AND C.nationalteam != '1'
            ORDER BY value DESC, C.name ASC"), $teamId);

        $comparison[] = self::_rankingResult('destatis_compare_fanmood', 'team_fan_mood', self::_fetchAll($db, "
            SELECT C.id AS club_id, C.name AS club_name, C.fan_mood AS value
            FROM " . $prefix . "_verein AS C
            WHERE C.status = '1' AND C.nationalteam != '1'
            ORDER BY C.fan_mood DESC, C.name ASC"), $teamId);

        $comparison[] = self::_rankingResult('destatis_compare_chemistry', 'teamchemistry_navlabel', self::_fetchAll($db, "
            SELECT C.id AS club_id, C.name AS club_name, C.team_chemistry AS value
            FROM " . $prefix . "_verein AS C
            WHERE C.status = '1' AND C.nationalteam != '1'
            ORDER BY C.team_chemistry DESC, C.name ASC"), $teamId);

        $comparison[] = self::_rankingResult('destatis_compare_highscore', 'highscore_teams_score', self::_fetchAll($db, "
            SELECT C.id AS club_id, C.name AS club_name, C.highscore AS value
            FROM " . $prefix . "_verein AS C
            WHERE C.status = '1' AND C.nationalteam != '1'
            ORDER BY C.highscore DESC, C.name ASC"), $teamId);

        $comparison[] = self::_rankingResult('destatis_compare_attendance', 'avg_gates_team_viewers', self::getAttendanceRankingRows($websoccer, $db), $teamId);

        return $comparison;
    }

    private static function getAttendanceRankingRows(WebSoccer $websoccer, DbConnection $db) {
        $prefix = self::_prefix($websoccer);

        if (self::_tableExists($websoccer, $db, 'stadium_attendance_log')) {
            return self::_fetchAll($db, "
                SELECT C.id AS club_id, C.name AS club_name, ROUND(AVG(SAL.total_visitors),0) AS value
                FROM " . $prefix . "_stadium_attendance_log AS SAL
                INNER JOIN " . $prefix . "_verein AS C ON C.id = SAL.team_id
                WHERE C.status = '1' AND C.nationalteam != '1' AND SAL.total_visitors > 0
                GROUP BY C.id
                ORDER BY value DESC, C.name ASC");
        }

        return self::_fetchAll($db, "
            SELECT C.id AS club_id, C.name AS club_name, ROUND(AVG(S.zuschauer),0) AS value
            FROM " . $prefix . "_spiel AS S
            INNER JOIN " . $prefix . "_verein AS C ON C.id = S.home_verein
            WHERE S.berechnet = '1' AND S.zuschauer IS NOT NULL AND C.status = '1' AND C.nationalteam != '1'
            GROUP BY C.id
            ORDER BY value DESC, C.name ASC");
    }

    /**
     * Provides list gates by leagueId.
     */
    public static function getAvgGatesByLeagueId(WebSoccer $websoccer, DbConnection $db, $leagueId) {
        $leagueId = (int) $leagueId;
        if ($leagueId < 1) {
            return array();
        }

        $prefix = self::_prefix($websoccer);
        $sqlStr = "SELECT G.liga_id AS league_id, G.home_verein AS home_verein, ROUND(AVG(G.zuschauer),0) AS zuschauer,
                        C.name AS team_name, C.bild AS team_bild, C.platz,
                        (IFNULL(ST.p_sitz,0)+IFNULL(ST.p_steh,0)+IFNULL(ST.p_haupt_steh,0)+IFNULL(ST.p_haupt_sitz,0)+IFNULL(ST.p_vip,0)) AS capacity
                    FROM " . $prefix . "_spiel AS G
                    INNER JOIN " . $prefix . "_verein AS C ON C.id = G.home_verein
                    LEFT JOIN " . $prefix . "_stadion AS ST ON ST.id = C.stadion_id
                    WHERE G.liga_id = " . $leagueId . " AND G.berechnet = '1' AND G.zuschauer IS NOT NULL
                    GROUP BY G.home_verein
                    ORDER BY AVG(G.zuschauer) DESC";
        $gates = self::_fetchAll($db, $sqlStr);

        foreach ($gates as $i => $gate) {
            $capacity = (int) $gate['capacity'];
            $gates[$i]['occupation'] = ($capacity > 0) ? round(((float) $gate['zuschauer'] / $capacity) * 100, 2) : 0;
        }

        return $gates;
    }

    /**
     * Provides list best players by rating/note.
     */
    public static function getAvgPlayerRatingsByLeagueId(WebSoccer $websoccer, DbConnection $db, $leagueId) {
        $max = self::_max($websoccer);
        $leagueId = (int) $leagueId;
        $prefix = self::_prefix($websoccer);
        $leagueCondition = ($leagueId > 0) ? "AND C.liga_id = " . $leagueId : '';

        $sqlStr = "SELECT P.id, P.vorname, P.nachname, P.kunstname, P.note_last, P.note_schnitt, P.sa_spiele,
                        C.id AS club_id, C.name AS club_name, C.bild AS club_bild
                   FROM " . $prefix . "_spieler AS P
                   INNER JOIN " . $prefix . "_verein AS C ON P.verein_id = C.id
                   WHERE P.status = '1' AND P.note_schnitt > 0 " . $leagueCondition . "
                   ORDER BY P.note_schnitt ASC, P.sa_spiele DESC, P.note_last ASC
                   LIMIT " . $max;
        return self::_fetchAll($db, $sqlStr);
    }

    /**
     * Provides list players with worst discipline.
     */
    public static function getWorstDiscipinesByLeagueId(WebSoccer $websoccer, DbConnection $db, $leagueId) {
        $max = self::_max($websoccer);
        $leagueId = (int) $leagueId;
        $prefix = self::_prefix($websoccer);
        $leagueCondition = ($leagueId > 0) ? "AND C.liga_id = " . $leagueId : '';

        $sqlStr = "SELECT P.id, P.vorname, P.nachname, P.kunstname, P.sa_karten_gelb, P.sa_karten_gelb_rot, P.sa_karten_rot,
                        (P.sa_karten_rot * 5 + P.sa_karten_gelb_rot * 3 + P.sa_karten_gelb) AS card_score,
                        C.id AS club_id, C.name AS club_name, C.bild AS club_bild
                   FROM " . $prefix . "_spieler AS P
                   INNER JOIN " . $prefix . "_verein AS C ON P.verein_id = C.id
                   WHERE P.status = '1' " . $leagueCondition . "
                   ORDER BY card_score DESC, P.sa_karten_rot DESC, P.sa_karten_gelb_rot DESC, P.sa_karten_gelb DESC
                   LIMIT " . $max;
        return self::_fetchAll($db, $sqlStr);
    }

    /**
     * Provides list of richest clubs.
     */
    public static function getRichestClubs(WebSoccer $websoccer, DbConnection $db) {
        $max = self::_max($websoccer);
        $prefix = self::_prefix($websoccer);

        $sqlStr = "SELECT C.id, C.name, C.bild, C.finanz_budget, L.id AS league_id, L.name AS league_name, L.land AS country
                   FROM " . $prefix . "_verein AS C
                   LEFT JOIN " . $prefix . "_liga AS L ON L.id = C.liga_id
                   WHERE C.status = '1' AND C.nationalteam != '1'
                   ORDER BY C.finanz_budget DESC, C.name ASC
                   LIMIT " . $max;
        return self::_fetchAll($db, $sqlStr);
    }

    /**
     * get stadium visitors by club
     */
    public static function highestClubStadiumVisitorsByClub(WebSoccer $websoccer, DbConnection $db) {
        $prefix = self::_prefix($websoccer);

        $sqlStr = "SELECT V.id AS club_id, V.name AS club_name, V.bild AS club_bild, SUM(S.zuschauer) AS visitors, ROUND(AVG(S.zuschauer),0) AS avg_visitors, ST.id AS stadium_id, ST.name AS stadium_name
                    FROM " . $prefix . "_spiel AS S
                    INNER JOIN " . $prefix . "_verein AS V ON V.id = S.home_verein
                    LEFT JOIN " . $prefix . "_stadion AS ST ON ST.id = V.stadion_id
                    WHERE S.berechnet = '1' AND S.zuschauer IS NOT NULL AND V.status = '1' AND V.nationalteam != '1'
                    GROUP BY V.id
                    ORDER BY visitors DESC, avg_visitors DESC
                    LIMIT 20";
        return self::_fetchAll($db, $sqlStr);
    }

    /**
     * get stadium visitors by league
     */
    public static function highestClubStadiumVisitorsByLeague(WebSoccer $websoccer, DbConnection $db) {
        $prefix = self::_prefix($websoccer);

        $sqlStr = "SELECT L.id AS league_id, L.name AS league_name, L.land AS league_country, SUM(S.zuschauer) AS visitors, ROUND(AVG(S.zuschauer),0) AS avg_visitors
                    FROM " . $prefix . "_spiel AS S
                    INNER JOIN " . $prefix . "_verein AS V ON V.id = S.home_verein
                    INNER JOIN " . $prefix . "_liga AS L ON L.id = V.liga_id
                    WHERE S.berechnet = '1' AND S.zuschauer IS NOT NULL AND V.status = '1' AND V.nationalteam != '1'
                    GROUP BY L.id
                    ORDER BY visitors DESC, avg_visitors DESC
                    LIMIT 20";
        return self::_fetchAll($db, $sqlStr);
    }

    public static function getStrongestTeams(WebSoccer $websoccer, DbConnection $db, $leagueId = 0) {
        $prefix = self::_prefix($websoccer);
        $leagueId = (int) $leagueId;
        $leagueCondition = ($leagueId > 0) ? "AND C.liga_id = " . $leagueId : '';

        $sqlStr = "SELECT
                        C.id AS club_id,
                        C.name AS club_name,
                        C.bild,
                        C.strength,
                        ROUND(AVG(CAST(P.w_staerke AS DECIMAL(10,2))),2) AS avg_player_strength,
                        COUNT(P.id) AS player_count,
                        L.id AS league_id,
                        L.name AS league_name,
                        L.land AS country
                    FROM " . $prefix . "_verein AS C
                    LEFT JOIN " . $prefix . "_spieler AS P ON P.verein_id = C.id AND P.status = '1'
                    LEFT JOIN " . $prefix . "_liga AS L ON L.id = C.liga_id
                    WHERE C.status = '1' AND C.nationalteam != '1' " . $leagueCondition . "
                    GROUP BY C.id
                    ORDER BY C.strength DESC, avg_player_strength DESC, C.name ASC
                    LIMIT 30";
        return self::_fetchAll($db, $sqlStr);
    }

    public static function getPublicAttributeOptions() {
        return array(
            'w_freekick' => 'destatis_attr_freekick',
            'w_penalty' => 'destatis_attr_penalty',
            'w_pace' => 'destatis_attr_pace',
            'w_passing' => 'destatis_attr_passing',
            'w_shooting' => 'destatis_attr_shooting',
            'w_heading' => 'destatis_attr_heading',
            'w_tackling' => 'destatis_attr_tackling',
            'w_creativity' => 'destatis_attr_creativity',
            'w_influence' => 'destatis_attr_influence',
            'w_flair' => 'destatis_attr_flair',
            'w_penalty_killing' => 'destatis_attr_penalty_killing'
        );
    }

    public static function getPlayerAttributeRanking(WebSoccer $websoccer, DbConnection $db, $attribute) {
        $options = self::getPublicAttributeOptions();
        if (!isset($options[$attribute])) {
            $attribute = 'w_freekick';
        }

        $prefix = self::_prefix($websoccer);
        $sqlStr = "SELECT
                        P.id AS player_id,
                        P.vorname,
                        P.nachname,
                        P.kunstname,
                        P.position,
                        P.position_main,
                        P.nation,
                        CAST(P." . $attribute . " AS DECIMAL(10,2)) AS attribute_value,
                        C.id AS club_id,
                        C.name AS club_name,
                        C.bild AS club_bild
                    FROM " . $prefix . "_spieler AS P
                    LEFT JOIN " . $prefix . "_verein AS C ON C.id = P.verein_id
                    WHERE P.status = '1'
                    ORDER BY attribute_value DESC, CAST(P.w_staerke AS DECIMAL(10,2)) DESC, P.nachname ASC
                    LIMIT 30";
        return self::_fetchAll($db, $sqlStr);
    }

    public static function getStadiumEfficiencyRanking(WebSoccer $websoccer, DbConnection $db) {
        $prefix = self::_prefix($websoccer);

        if (self::_tableExists($websoccer, $db, 'stadium_attendance_log')) {
            $sqlStr = "SELECT
                            C.id AS club_id,
                            C.name AS club_name,
                            C.bild AS club_bild,
                            ST.id AS stadium_id,
                            ST.name AS stadium_name,
                            ROUND(AVG(SAL.total_visitors),0) AS avg_visitors,
                            ROUND(AVG(SAL.total_visitors / NULLIF(SAL.total_capacity, 0)) * 100, 2) AS occupancy,
                            SUM(SAL.total_revenue) AS total_revenue,
                            ROUND(AVG(SAL.average_ticket_price),2) AS avg_ticket_price,
                            COUNT(SAL.id) AS matches
                        FROM " . $prefix . "_stadium_attendance_log AS SAL
                        INNER JOIN " . $prefix . "_verein AS C ON C.id = SAL.team_id
                        LEFT JOIN " . $prefix . "_stadion AS ST ON ST.id = C.stadion_id
                        WHERE C.status = '1' AND C.nationalteam != '1' AND SAL.total_capacity > 0
                        GROUP BY C.id
                        ORDER BY occupancy DESC, avg_visitors DESC, total_revenue DESC
                        LIMIT 30";
            return self::_fetchAll($db, $sqlStr);
        }

        $sqlStr = "SELECT
                        C.id AS club_id,
                        C.name AS club_name,
                        C.bild AS club_bild,
                        ST.id AS stadium_id,
                        ST.name AS stadium_name,
                        ROUND(AVG(S.zuschauer),0) AS avg_visitors,
                        ROUND(AVG(S.zuschauer / NULLIF((IFNULL(ST.p_sitz,0)+IFNULL(ST.p_steh,0)+IFNULL(ST.p_haupt_steh,0)+IFNULL(ST.p_haupt_sitz,0)+IFNULL(ST.p_vip,0)), 0)) * 100, 2) AS occupancy,
                        0 AS total_revenue,
                        0 AS avg_ticket_price,
                        COUNT(S.id) AS matches
                    FROM " . $prefix . "_spiel AS S
                    INNER JOIN " . $prefix . "_verein AS C ON C.id = S.home_verein
                    LEFT JOIN " . $prefix . "_stadion AS ST ON ST.id = C.stadion_id
                    WHERE S.berechnet = '1' AND S.zuschauer IS NOT NULL AND C.status = '1' AND C.nationalteam != '1'
                    GROUP BY C.id
                    ORDER BY occupancy DESC, avg_visitors DESC
                    LIMIT 30";
        return self::_fetchAll($db, $sqlStr);
    }

    public static function getManagerRankings(WebSoccer $websoccer, DbConnection $db) {
        $prefix = self::_prefix($websoccer);

        $missionJoin = '';
        $careerJoin = '';
        $missionColumn = '0 AS completed_missions';
        $careerColumn = '0 AS club_changes';

        if (self::_tableExists($websoccer, $db, 'manager_mission')) {
            $missionJoin = " LEFT JOIN " . $prefix . "_manager_mission AS M ON M.user_id = U.id AND M.status = 'completed'";
            $missionColumn = 'COUNT(DISTINCT M.id) AS completed_missions';
        }

        if (self::_tableExists($websoccer, $db, 'manager_career_history')) {
            $careerJoin = " LEFT JOIN " . $prefix . "_manager_career_history AS H ON H.user_id = U.id";
            $careerColumn = 'COUNT(DISTINCT H.id) AS club_changes';
        }

        $sqlStr = "SELECT
                        U.id AS user_id,
                        U.nick AS manager_name,
                        U.highscore,
                        U.fanbeliebtheit,
                        " . $missionColumn . ",
                        " . $careerColumn . ",
                        C.id AS club_id,
                        C.name AS club_name,
                        C.bild AS club_bild,
                        L.id AS league_id,
                        L.name AS league_name
                    FROM " . $prefix . "_user AS U
                    LEFT JOIN " . $prefix . "_verein AS C ON C.user_id = U.id AND C.status = '1'
                    LEFT JOIN " . $prefix . "_liga AS L ON L.id = C.liga_id
                    " . $missionJoin . "
                    " . $careerJoin . "
                    WHERE U.status = '1'
                    GROUP BY U.id
                    ORDER BY U.highscore DESC, completed_missions DESC, U.fanbeliebtheit DESC, U.nick ASC
                    LIMIT 30";
        return self::_fetchAll($db, $sqlStr);
    }

    /**
     * get average sales from _konto table
     */
    public static function getAvgSales(WebSoccer $websoccer, DbConnection $db) {
        $sqlStr = "SELECT AVG(betrag) sales FROM " . self::_prefix($websoccer) . "_konto";
        $konto = self::_fetchOne($db, $sqlStr);
        return (isset($konto['sales'])) ? $konto['sales'] : 0;
    }

    /**
     * get average sales history
     */
    public static function getSalesHistory(WebSoccer $websoccer, DbConnection $db) {
        $sqlStr = "SELECT * FROM " . self::_prefix($websoccer) . "_kontohistory";
        return self::_fetchOne($db, $sqlStr);
    }
}
?>
