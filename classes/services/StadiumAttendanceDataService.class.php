<?php
/******************************************************

  This file is part of OpenWebSoccer-Sim.

  OpenWebSoccer-Sim is free software: you can redistribute it 
  and/or modify it under the terms of the 
  GNU Lesser General Public License 
  as published by the Free Software Foundation, either version 3 of
  the License, or any later version.

  OpenWebSoccer-Sim is distributed in the hope that it will be
  useful, but WITHOUT ANY WARRANTY; without even the implied warranty of 
  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. 
  See the GNU Lesser General Public License for more details.

  You should have received a copy of the GNU Lesser General Public 
  License along with OpenWebSoccer-Sim.  
  If not, see <http://www.gnu.org/licenses/>.

******************************************************/

/**
 * Stores and provides historical stadium attendance snapshots.
 */
class StadiumAttendanceDataService {

    private static $_tableReady = FALSE;

    public static function saveMatchAttendance(WebSoccer $websoccer, DbConnection $db, SimulationMatch $match, $homeInfo,
            $ticketsStands, $ticketsSeats, $ticketsStandsGrand, $ticketsSeatsGrand, $ticketsVip) {

        if (!self::ensureTableExists($websoccer, $db)) {
            return;
        }

        $standingVisitors = self::toInt($ticketsStands) + self::toInt($ticketsStandsGrand);
        $seatingVisitors = self::toInt($ticketsSeats) + self::toInt($ticketsSeatsGrand);
        $vipVisitors = self::toInt($ticketsVip);
        $totalVisitors = $standingVisitors + $seatingVisitors + $vipVisitors;

        $standingCapacity = self::toInt($homeInfo['places_stands']) + self::toInt($homeInfo['places_stands_grand']);
        $seatingCapacity = self::toInt($homeInfo['places_seats']) + self::toInt($homeInfo['places_seats_grand']);
        $vipCapacity = self::toInt($homeInfo['places_vip']);
        $totalCapacity = $standingCapacity + $seatingCapacity + $vipCapacity;

        $standingRevenue = self::toInt($ticketsStands) * self::toInt($homeInfo['price_stands']);
        $standingRevenue += self::toInt($ticketsStandsGrand) * self::toInt($homeInfo['price_stands_grand']);

        $seatingRevenue = self::toInt($ticketsSeats) * self::toInt($homeInfo['price_seats']);
        $seatingRevenue += self::toInt($ticketsSeatsGrand) * self::toInt($homeInfo['price_seats_grand']);

        $vipRevenue = self::toInt($ticketsVip) * self::toInt($homeInfo['price_vip']);
        $totalRevenue = $standingRevenue + $seatingRevenue + $vipRevenue;

        $columns = array(
            'match_id' => self::toInt($match->id),
            'team_id' => self::toInt($match->homeTeam->id),
            'stadium_id' => self::toInt($homeInfo['stadium_id']),
            'standing_visitors' => $standingVisitors,
            'seating_visitors' => $seatingVisitors,
            'vip_visitors' => $vipVisitors,
            'total_visitors' => $totalVisitors,
            'standing_capacity' => $standingCapacity,
            'seating_capacity' => $seatingCapacity,
            'vip_capacity' => $vipCapacity,
            'total_capacity' => $totalCapacity,
            'standing_revenue' => $standingRevenue,
            'seating_revenue' => $seatingRevenue,
            'vip_revenue' => $vipRevenue,
            'total_revenue' => $totalRevenue,
            'standing_average_price' => self::avgPrice($standingRevenue, $standingVisitors),
            'seating_average_price' => self::avgPrice($seatingRevenue, $seatingVisitors),
            'vip_average_price' => self::avgPrice($vipRevenue, $vipVisitors),
            'average_ticket_price' => self::avgPrice($totalRevenue, $totalVisitors),
            'created_date' => time()
        );

        $table = self::getTableName($websoccer);
        $existing = $db->querySelect('id', $table, 'match_id = %d AND team_id = %d', array($match->id, $match->homeTeam->id), 1);
        $existingRow = $existing->fetch_array();
        $existing->free();

        if ($existingRow) {
            unset($columns['match_id']);
            unset($columns['team_id']);
            $db->queryUpdate($columns, $table, 'id = %d', $existingRow['id']);
        } else {
            $db->queryInsert($columns, $table);
        }
    }

    public static function getRecentAttendanceByTeam(WebSoccer $websoccer, DbConnection $db, $teamId, $limit = 10) {
        if (!self::ensureTableExists($websoccer, $db)) {
            return array('matches' => array(), 'summary' => array(), 'has_snapshot' => FALSE);
        }

        $prefix = $websoccer->getConfig('db_prefix');
        $attendanceTable = self::getTableName($websoccer);

        $columns = array(
            'M.id' => 'match_id',
            'M.datum' => 'match_date',
            'M.spieltyp' => 'match_type',
            'M.pokalname' => 'cup_name',
            'M.pokalrunde' => 'cup_round',
            'M.spieltag' => 'matchday',
            'M.zuschauer' => 'legacy_total_visitors',
            'G.name' => 'opponent_name',
            'A.id' => 'attendance_log_id',
            'A.standing_visitors' => 'standing_visitors',
            'A.seating_visitors' => 'seating_visitors',
            'A.vip_visitors' => 'vip_visitors',
            'A.total_visitors' => 'total_visitors',
            'A.standing_capacity' => 'standing_capacity',
            'A.seating_capacity' => 'seating_capacity',
            'A.vip_capacity' => 'vip_capacity',
            'A.total_capacity' => 'total_capacity',
            'A.standing_revenue' => 'standing_revenue',
            'A.seating_revenue' => 'seating_revenue',
            'A.vip_revenue' => 'vip_revenue',
            'A.total_revenue' => 'total_revenue',
            'A.standing_average_price' => 'standing_average_price',
            'A.seating_average_price' => 'seating_average_price',
            'A.vip_average_price' => 'vip_average_price',
            'A.average_ticket_price' => 'average_ticket_price',
            'COALESCE(MS.p_steh, TS.p_steh, 0)' => 'legacy_places_stands',
            'COALESCE(MS.p_sitz, TS.p_sitz, 0)' => 'legacy_places_seats',
            'COALESCE(MS.p_haupt_steh, TS.p_haupt_steh, 0)' => 'legacy_places_stands_grand',
            'COALESCE(MS.p_haupt_sitz, TS.p_haupt_sitz, 0)' => 'legacy_places_seats_grand',
            'COALESCE(MS.p_vip, TS.p_vip, 0)' => 'legacy_places_vip',
            'T.preis_stehen' => 'legacy_price_stands',
            'T.preis_sitz' => 'legacy_price_seats',
            'T.preis_haupt_stehen' => 'legacy_price_stands_grand',
            'T.preis_haupt_sitze' => 'legacy_price_seats_grand',
            'T.preis_vip' => 'legacy_price_vip'
        );

        $fromTable = $prefix . '_spiel AS M';
        $fromTable .= ' INNER JOIN ' . $prefix . '_verein AS T ON T.id = M.home_verein';
        $fromTable .= ' INNER JOIN ' . $prefix . '_verein AS G ON G.id = M.gast_verein';
        $fromTable .= ' LEFT JOIN ' . $attendanceTable . ' AS A ON A.match_id = M.id AND A.team_id = M.home_verein';
        $fromTable .= ' LEFT JOIN ' . $prefix . '_stadion AS MS ON MS.id = M.stadion_id';
        $fromTable .= ' LEFT JOIN ' . $prefix . '_stadion AS TS ON TS.id = T.stadion_id';

        $whereCondition = 'M.home_verein = %d AND M.zuschauer IS NOT NULL ORDER BY M.datum DESC, M.id DESC';
        $result = $db->querySelect($columns, $fromTable, $whereCondition, $teamId, self::toInt($limit));

        $matches = array();
        $hasSnapshot = FALSE;
        while ($row = $result->fetch_array()) {
            $item = self::buildMatchItem($row);
            if ($item['has_snapshot']) {
                $hasSnapshot = TRUE;
            }
            $matches[] = $item;
        }
        $result->free();

        return array(
            'matches' => $matches,
            'summary' => self::buildSummary($matches),
            'has_snapshot' => $hasSnapshot
        );
    }

    private static function buildMatchItem($row) {
        $hasSnapshot = self::toInt($row['attendance_log_id']) > 0;
        $competition = self::getCompetitionLabel($row);

        if ($hasSnapshot) {
            $rows = array(
                self::buildCategoryRow('stadium_attendance_standing', $row['standing_visitors'], $row['standing_capacity'], $row['standing_revenue'], $row['standing_average_price'], TRUE),
                self::buildCategoryRow('stadium_attendance_seating', $row['seating_visitors'], $row['seating_capacity'], $row['seating_revenue'], $row['seating_average_price'], TRUE),
                self::buildCategoryRow('stadium_attendance_vip', $row['vip_visitors'], $row['vip_capacity'], $row['vip_revenue'], $row['vip_average_price'], TRUE),
                self::buildCategoryRow('stadium_attendance_total', $row['total_visitors'], $row['total_capacity'], $row['total_revenue'], $row['average_ticket_price'], TRUE)
            );
        } else {
            $rows = self::buildLegacyEstimatedRows($row);
        }

        return array(
            'match_id' => self::toInt($row['match_id']),
            'match_date' => self::toInt($row['match_date']),
            'match_type_key' => self::getMatchTypeKey($row['match_type']),
            'competition' => $competition,
            'opponent_name' => $row['opponent_name'],
            'has_snapshot' => $hasSnapshot,
            'rows' => $rows
        );
    }

    private static function buildCategoryRow($messageKey, $visitors, $capacity, $revenue, $averagePrice, $hasPrice, $estimated = FALSE) {
        $visitors = self::toInt($visitors);
        $capacity = self::toInt($capacity);
        $revenue = self::toInt($revenue);
        $averagePrice = (float) $averagePrice;

        return array(
            'message_key' => $messageKey,
            'visitors' => $visitors,
            'capacity' => $capacity,
            'utilization_percent' => self::percent($visitors, $capacity),
            'revenue' => $revenue,
            'average_price' => $averagePrice,
            'has_price' => $hasPrice,
            'estimated' => $estimated
        );
    }

    private static function buildLegacyEstimatedRows($row) {
        $totalVisitors = self::toInt($row['legacy_total_visitors']);

        $placesStands = self::toInt($row['legacy_places_stands']);
        $placesSeats = self::toInt($row['legacy_places_seats']);
        $placesStandsGrand = self::toInt($row['legacy_places_stands_grand']);
        $placesSeatsGrand = self::toInt($row['legacy_places_seats_grand']);
        $placesVip = self::toInt($row['legacy_places_vip']);

        $standingCapacity = $placesStands + $placesStandsGrand;
        $seatingCapacity = $placesSeats + $placesSeatsGrand;
        $vipCapacity = $placesVip;
        $totalCapacity = $standingCapacity + $seatingCapacity + $vipCapacity;

        if ($totalCapacity < 1) {
            return array(
                self::buildCategoryRow('stadium_attendance_total', $totalVisitors, 0, 0, 0, FALSE, FALSE)
            );
        }

        $rate = max(0, min(1, ((float) $totalVisitors) / $totalCapacity));

        $standVisitors = self::visitorsByCapacity($placesStands, $rate);
        $standGrandVisitors = self::visitorsByCapacity($placesStandsGrand, $rate);
        $seatVisitors = self::visitorsByCapacity($placesSeats, $rate);
        $seatGrandVisitors = self::visitorsByCapacity($placesSeatsGrand, $rate);
        $vipVisitors = self::visitorsByCapacity($placesVip, $rate);

        $standingVisitors = $standVisitors + $standGrandVisitors;
        $seatingVisitors = $seatVisitors + $seatGrandVisitors;

        $standingRevenue = ($standVisitors * self::toInt($row['legacy_price_stands']))
            + ($standGrandVisitors * self::toInt($row['legacy_price_stands_grand']));
        $seatingRevenue = ($seatVisitors * self::toInt($row['legacy_price_seats']))
            + ($seatGrandVisitors * self::toInt($row['legacy_price_seats_grand']));
        $vipRevenue = $vipVisitors * self::toInt($row['legacy_price_vip']);
        $totalRevenue = $standingRevenue + $seatingRevenue + $vipRevenue;

        $hasPrice = $totalRevenue > 0 && $totalVisitors > 0;

        return array(
            self::buildCategoryRow('stadium_attendance_standing', $standingVisitors, $standingCapacity, $standingRevenue, self::avgPrice($standingRevenue, $standingVisitors), $hasPrice, TRUE),
            self::buildCategoryRow('stadium_attendance_seating', $seatingVisitors, $seatingCapacity, $seatingRevenue, self::avgPrice($seatingRevenue, $seatingVisitors), $hasPrice, TRUE),
            self::buildCategoryRow('stadium_attendance_vip', $vipVisitors, $vipCapacity, $vipRevenue, self::avgPrice($vipRevenue, $vipVisitors), $hasPrice, TRUE),
            self::buildCategoryRow('stadium_attendance_total', $totalVisitors, $totalCapacity, $totalRevenue, self::avgPrice($totalRevenue, $totalVisitors), $hasPrice, TRUE)
        );
    }

    private static function visitorsByCapacity($capacity, $rate) {
        $capacity = self::toInt($capacity);
        if ($capacity < 1) {
            return 0;
        }

        return min($capacity, self::toInt($capacity * $rate));
    }

    private static function buildSummary($matches) {
        $summary = array();
        $keys = array('stadium_attendance_standing', 'stadium_attendance_seating', 'stadium_attendance_vip', 'stadium_attendance_total');

        foreach ($keys as $key) {
            $summary[$key] = array(
                'message_key' => $key,
                'visitors' => 0,
                'capacity' => 0,
                'revenue' => 0,
                'price_revenue' => 0,
                'price_visitors' => 0,
                'matches' => 0,
                'price_matches' => 0
            );
        }

        foreach ($matches as $match) {
            foreach ($match['rows'] as $row) {
                $key = $row['message_key'];
                if (!isset($summary[$key])) {
                    continue;
                }

                $summary[$key]['visitors'] += $row['visitors'];
                $summary[$key]['capacity'] += $row['capacity'];
                $summary[$key]['revenue'] += $row['revenue'];
                $summary[$key]['matches']++;
                if ($row['has_price']) {
                    $summary[$key]['price_matches']++;
                    $summary[$key]['price_revenue'] += $row['revenue'];
                    $summary[$key]['price_visitors'] += $row['visitors'];
                }
            }
        }

        $rows = array();
        foreach ($summary as $key => $values) {
            if ($values['matches'] < 1) {
                continue;
            }

            $avgVisitors = $values['visitors'] / $values['matches'];
            $avgCapacity = $values['capacity'] / $values['matches'];
            $hasPrice = $values['price_matches'] > 0 && $values['price_visitors'] > 0;

            $rows[] = array(
                'message_key' => $key,
                'visitors' => $avgVisitors,
                'capacity' => $avgCapacity,
                'utilization_percent' => self::percent($values['visitors'], $values['capacity']),
                'revenue' => ($values['price_matches'] > 0) ? ($values['price_revenue'] / max(1, $values['price_matches'])) : 0,
                'average_price' => $hasPrice ? self::avgPrice($values['price_revenue'], $values['price_visitors']) : 0,
                'has_price' => $hasPrice
            );
        }

        return $rows;
    }

    private static function getCompetitionLabel($row) {
        if ($row['match_type'] == 'Pokalspiel') {
            $cupName = (string) $row['cup_name'];
            $cupRound = (string) $row['cup_round'];
            $label = strlen($cupName) ? $cupName : 'Pokalspiel';
            if (strlen($cupRound)) {
                $label .= ' (' . $cupRound . ')';
            }
            return $label;
        }

        return '';
    }

    private static function getMatchTypeKey($matchType) {
        if ($matchType == 'Ligaspiel') {
            return 'league';
        }
        if ($matchType == 'Pokalspiel') {
            return 'cup';
        }
        if ($matchType == 'Freundschaft') {
            return 'friendly';
        }
        return $matchType;
    }

    private static function avgPrice($revenue, $visitors) {
        $visitors = self::toInt($visitors);
        if ($visitors < 1) {
            return 0;
        }

        return round(((float) $revenue) / $visitors, 2);
    }

    private static function percent($visitors, $capacity) {
        $capacity = self::toInt($capacity);
        if ($capacity < 1) {
            return 0;
        }

        return round(((float) $visitors) / $capacity * 100, 1);
    }

    private static function toInt($value) {
        return (int) round((float) $value);
    }

    private static function getTableName(WebSoccer $websoccer) {
        return $websoccer->getConfig('db_prefix') . '_stadium_attendance_log';
    }

    private static function ensureTableExists(WebSoccer $websoccer, DbConnection $db) {
        if (self::$_tableReady) {
            return TRUE;
        }

        $table = self::getTableName($websoccer);
        try {
            $safeTableName = $db->connection->real_escape_string($table);
            $check = $db->executeQuery("SHOW TABLES LIKE '" . $safeTableName . "'");
            if ($check && $check->num_rows > 0) {
                $check->free();
                self::$_tableReady = TRUE;
                return TRUE;
            }
            if ($check) {
                $check->free();
            }
        } catch (Exception $e) {
            return FALSE;
        }

        $sql = 'CREATE TABLE IF NOT EXISTS `' . $table . '` ('
            . '`id` int(10) NOT NULL AUTO_INCREMENT,'
            . '`match_id` int(10) NOT NULL,'
            . '`team_id` int(10) NOT NULL,'
            . '`stadium_id` int(10) DEFAULT NULL,'
            . '`standing_visitors` int(10) NOT NULL DEFAULT 0,'
            . '`seating_visitors` int(10) NOT NULL DEFAULT 0,'
            . '`vip_visitors` int(10) NOT NULL DEFAULT 0,'
            . '`total_visitors` int(10) NOT NULL DEFAULT 0,'
            . '`standing_capacity` int(10) NOT NULL DEFAULT 0,'
            . '`seating_capacity` int(10) NOT NULL DEFAULT 0,'
            . '`vip_capacity` int(10) NOT NULL DEFAULT 0,'
            . '`total_capacity` int(10) NOT NULL DEFAULT 0,'
            . '`standing_revenue` int(10) NOT NULL DEFAULT 0,'
            . '`seating_revenue` int(10) NOT NULL DEFAULT 0,'
            . '`vip_revenue` int(10) NOT NULL DEFAULT 0,'
            . '`total_revenue` int(10) NOT NULL DEFAULT 0,'
            . '`standing_average_price` decimal(10,2) NOT NULL DEFAULT 0.00,'
            . '`seating_average_price` decimal(10,2) NOT NULL DEFAULT 0.00,'
            . '`vip_average_price` decimal(10,2) NOT NULL DEFAULT 0.00,'
            . '`average_ticket_price` decimal(10,2) NOT NULL DEFAULT 0.00,'
            . '`created_date` int(11) NOT NULL DEFAULT 0,'
            . 'PRIMARY KEY (`id`),'
            . 'UNIQUE KEY `uniq_stadium_attendance_match_team` (`match_id`,`team_id`),'
            . 'KEY `idx_stadium_attendance_team_date` (`team_id`,`created_date`),'
            . 'KEY `idx_stadium_attendance_stadium` (`stadium_id`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci';

        try {
            $db->executeQuery($sql);
            self::$_tableReady = TRUE;
            return TRUE;
        } catch (Exception $e) {
            return FALSE;
        }
    }
}

?>
