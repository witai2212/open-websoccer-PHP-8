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
 * Data service for transfer penalties.
 */
class TransferPenaltyDataService {
    
    /**
     * Returns the current transfer penalty pool.
     *
     * @param WebSoccer $websoccer Application Context
     * @param DbConnection $db DB connection
     * @return integer current penalty pool
     */
    public static function getPenaltyValue(WebSoccer $websoccer, DbConnection $db) {
        self::ensurePenaltyRow($websoccer, $db);
        
        $result = $db->querySelect('penalty', $websoccer->getConfig('db_prefix') . '_penalty', 'id = %d', 1, 1);
        $penalty = $result->fetch_array();
        $result->free();
        
        if (!$penalty || !isset($penalty['penalty'])) {
            return 0;
        }
        
        return max(0, (int) $penalty['penalty']);
    }

    /**
     * Builds a preview for the current transfer penalty redistribution.
     *
     * @param WebSoccer $websoccer Application Context
     * @param DbConnection $db DB connection
     * @return array preview data
     */
    public static function getDistributionPreview(WebSoccer $websoccer, DbConnection $db) {
        self::ensurePenaltyRow($websoccer, $db);

        $penalty = self::getPenaltyValue($websoccer, $db);
        $teams = self::getManagedTeams($websoccer, $db);
        $teamCount = count($teams);

        $baseAmount = 0;
        $remainder = 0;

        if ($penalty > 0 && $teamCount > 0) {
            $baseAmount = (int) floor($penalty / $teamCount);
            $remainder = (int) $penalty - ($baseAmount * $teamCount);
        }

        return array(
            'penalty_pool' => (int) $penalty,
            'managed_teams' => (int) $teamCount,
            'base_amount' => (int) $baseAmount,
            'remainder' => (int) $remainder,
            'distribution_possible' => ($penalty > 0 && $teamCount > 0) ? 1 : 0
        );
    }

    /**
     * Distributes the transfer penalty pool equally to active user-managed clubs.
     * The payout is booked through the normal bank account service so that users
     * can see it in finances/account statements. The penalty pool is reset only
     * after a successful distribution.
     *
     * @param WebSoccer $websoccer Application Context
     * @param DbConnection $db DB connection
     * @return array distribution result
     */
    public static function distributePenalties(WebSoccer $websoccer, DbConnection $db) {
        self::ensurePenaltyRow($websoccer, $db);

        $penalty = self::getPenaltyValue($websoccer, $db);
        $summary = array(
            'status' => 'empty',
            'penalty_pool_before' => (int) $penalty,
            'managed_teams' => 0,
            'base_amount' => 0,
            'remainder' => 0,
            'distributed_total' => 0,
            'penalty_pool_after' => (int) $penalty,
            'booked_teams' => array()
        );

        if ($penalty <= 0) {
            return $summary;
        }

        $teams = self::getManagedTeams($websoccer, $db);
        $teamCount = count($teams);
        $summary['managed_teams'] = (int) $teamCount;

        if (!$teamCount) {
            $summary['status'] = 'no_user_managed_clubs';
            return $summary;
        }

        $bonusPerTeam = (int) floor($penalty / $teamCount);
        $remainder = (int) $penalty - ($bonusPerTeam * $teamCount);
        $summary['base_amount'] = (int) $bonusPerTeam;
        $summary['remainder'] = (int) $remainder;

        if ($bonusPerTeam <= 0 && $remainder <= 0) {
            return $summary;
        }

        foreach ($teams as $team) {
            $bonus = $bonusPerTeam;
            if ($remainder > 0) {
                $bonus++;
                $remainder--;
            }

            if ($bonus <= 0) {
                continue;
            }

            BankAccountDataService::creditAmount(
                $websoccer,
                $db,
                (int) $team['id'],
                (int) $bonus,
                'transfer_penalty_distribution',
                'transfer_penalty_distribution_sender'
            );

            $summary['distributed_total'] += (int) $bonus;
            $summary['booked_teams'][] = array(
                'team_id' => (int) $team['id'],
                'team_name' => $team['name'],
                'user_id' => (int) $team['user_id'],
                'amount' => (int) $bonus
            );
        }

        if ($summary['distributed_total'] > 0) {
            $db->queryUpdate(
                array('penalty' => 0),
                $websoccer->getConfig('db_prefix') . '_penalty',
                'id = %d',
                1
            );

            $summary['status'] = 'distributed';
            $summary['penalty_pool_after'] = 0;
        }

        return $summary;
    }

    /**
     * Returns active clubs managed by a real user. Computer-managed clubs and
     * national teams are excluded from the redistribution.
     *
     * @param WebSoccer $websoccer Application Context
     * @param DbConnection $db DB connection
     * @return array managed clubs
     */
    private static function getManagedTeams(WebSoccer $websoccer, DbConnection $db) {
        $teams = array();

        $result = $db->querySelect(
            'id, name, user_id',
            $websoccer->getConfig('db_prefix') . '_verein',
            "status = '1' AND nationalteam != '1' AND user_id IS NOT NULL AND user_id > 0 ORDER BY id ASC"
        );

        while ($team = $result->fetch_array()) {
            $teams[] = $team;
        }
        $result->free();

        return $teams;
    }

    private static function ensurePenaltyRow(WebSoccer $websoccer, DbConnection $db) {
        $table = $websoccer->getConfig('db_prefix') . '_penalty';
        $result = $db->querySelect('COUNT(*) AS hits', $table, 'id = %d', 1, 1);
        $row = $result->fetch_array();
        $result->free();
        
        if ($row && (int) $row['hits'] > 0) {
            return;
        }
        
        $db->queryInsert(array(
            'id' => 1,
            'budget' => 0,
            'penalty' => 0
        ), $table);
    }
    
}
