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
 * Central service for the expanded badge system.
 *
 * It records repeatable badge events in a small progress table and then awards
 * the fitting bronze/silver/gold badge through BadgesDataService. The concrete
 * badge assignment remains user-based, but events are tracked with team, season
 * and reference context so repeated achievements do not create duplicates.
 */
class BadgeAwardService {

    const EVENT_YOUTH_DEVELOPER = 'youth_developer';
    const EVENT_GIANT_KILLER = 'giant_killer';
    const EVENT_COMEBACK_KING = 'comeback_king';
    const EVENT_FINANCIAL_GENIUS = 'financial_genius';
    const EVENT_TRANSFER_MASTER = 'transfer_master';

    const MIN_GIANT_KILLER_STRENGTH_PERCENT = 20;
    const MIN_TRANSFER_PROFIT = 1000000;
    const MIN_TRANSFER_PROFIT_PERCENT = 100;

    /**
     * Handles match based badge progress.
     *
     * @param MatchCompletedEvent $event
     */
    public static function processCompletedMatch(MatchCompletedEvent $event) {
        $match = $event->match;

        if (!$match || $match->type === 'Freundschaft') {
            return;
        }

        if ($match->homeTeam->isNationalTeam || $match->guestTeam->isNationalTeam) {
            return;
        }

        $homeGoals = (int) $match->homeTeam->getGoals();
        $guestGoals = (int) $match->guestTeam->getGoals();

        if ($homeGoals === $guestGoals) {
            return;
        }

        $homeStrength = (float) $match->homeTeam->computeTotalStrength($event->websoccer, $match);
        $guestStrength = (float) $match->guestTeam->computeTotalStrength($event->websoccer, $match);

        if ($homeGoals > $guestGoals) {
            self::processWinnerMatchBadges($event, $match->homeTeam, $match->guestTeam, $homeStrength, $guestStrength, TRUE);
        } else {
            self::processWinnerMatchBadges($event, $match->guestTeam, $match->homeTeam, $guestStrength, $homeStrength, FALSE);
        }
    }

    /**
     * Handles season based badge progress.
     *
     * @param SeasonOfTeamCompletedEvent $event
     */
    public static function processCompletedSeason(SeasonOfTeamCompletedEvent $event) {
        $team = self::getManagedTeam($event->websoccer, $event->db, (int) $event->teamId);
        if (!$team) {
            return;
        }

        if (self::hadPositiveBalanceForSeason($event->websoccer, $event->db, (int) $event->teamId, (int) $event->seasonId)) {
            self::recordCumulativeEventAndAward(
                $event->websoccer,
                $event->db,
                (int) $team['user_id'],
                (int) $event->teamId,
                (int) $event->seasonId,
                self::EVENT_FINANCIAL_GENIUS,
                'season:' . (int) $event->seasonId . ':team:' . (int) $event->teamId,
                1,
                array('season_id' => (int) $event->seasonId)
            );
        }
    }

    /**
     * Records a youth player promotion and awards Youth Developer tiers.
     *
     * @param WebSoccer $websoccer
     * @param DbConnection $db
     * @param int $userId
     * @param int $teamId
     * @param int $professionalPlayerId
     */
    public static function processYouthPromotion(WebSoccer $websoccer, DbConnection $db, $userId, $teamId, $professionalPlayerId) {
        $userId = (int) $userId;
        $teamId = (int) $teamId;
        $professionalPlayerId = (int) $professionalPlayerId;

        if ($userId < 1 || $teamId < 1 || $professionalPlayerId < 1) {
            return;
        }

        self::recordCumulativeEventAndAward(
            $websoccer,
            $db,
            $userId,
            $teamId,
            0,
            self::EVENT_YOUTH_DEVELOPER,
            'player:' . $professionalPlayerId,
            1,
            array('professional_player_id' => $professionalPlayerId)
        );
    }

    /**
     * Records a profitable player sale and awards Transfer Master badges.
     *
     * @param WebSoccer $websoccer
     * @param DbConnection $db
     * @param int $sellerUserId
     * @param int $sellerTeamId
     * @param int $playerId
     * @param int $saleAmount
     * @param int $transferId
     */
    public static function processTransferSale(WebSoccer $websoccer, DbConnection $db, $sellerUserId, $sellerTeamId, $playerId, $saleAmount, $transferId = 0) {
        $sellerUserId = (int) $sellerUserId;
        $sellerTeamId = (int) $sellerTeamId;
        $playerId = (int) $playerId;
        $saleAmount = (int) $saleAmount;
        $transferId = (int) $transferId;

        if ($sellerUserId < 1 || $sellerTeamId < 1 || $playerId < 1 || $saleAmount <= 0) {
            return;
        }

        $purchaseAmount = self::getLastPurchasePrice($websoccer, $db, $sellerTeamId, $playerId, $transferId);
        if ($purchaseAmount <= 0) {
            return;
        }

        $profit = $saleAmount - $purchaseAmount;
        if ($profit < self::MIN_TRANSFER_PROFIT) {
            return;
        }

        $profitPercent = (int) floor(($profit / $purchaseAmount) * 100);
        if ($profitPercent < self::MIN_TRANSFER_PROFIT_PERCENT) {
            return;
        }

        $referenceKey = ($transferId > 0) ? 'transfer:' . $transferId : 'transfer-player:' . $playerId . ':' . $websoccer->getNowAsTimestamp();
        self::recordEvent($websoccer, $db, $sellerUserId, $sellerTeamId, 0, self::EVENT_TRANSFER_MASTER, $referenceKey, $profitPercent);

        BadgesDataService::awardBadgeIfApplicable(
            $websoccer,
            $db,
            $sellerUserId,
            self::EVENT_TRANSFER_MASTER,
            $profitPercent,
            $sellerTeamId,
            $referenceKey,
            array(
                'player_id' => $playerId,
                'sale_amount' => $saleAmount,
                'purchase_amount' => $purchaseAmount,
                'profit' => $profit,
                'profit_percent' => $profitPercent
            ),
            TRUE
        );
    }

    private static function processWinnerMatchBadges(MatchCompletedEvent $event, SimulationTeam $winner, SimulationTeam $loser, $winnerStrength, $loserStrength, $winnerIsHome) {
        if ($winner->noFormationSet || $winner->isManagedByInterimManager) {
            return;
        }

        $team = self::getManagedTeam($event->websoccer, $event->db, (int) $winner->id);
        if (!$team) {
            return;
        }

        $seasonId = self::getSeasonIdOfMatch($event->websoccer, $event->db, (int) $event->match->id);

        if ($winnerStrength > 0 && $loserStrength >= ($winnerStrength * (1 + self::MIN_GIANT_KILLER_STRENGTH_PERCENT / 100))) {
            self::recordCumulativeEventAndAward(
                $event->websoccer,
                $event->db,
                (int) $team['user_id'],
                (int) $winner->id,
                $seasonId,
                self::EVENT_GIANT_KILLER,
                'match:' . (int) $event->match->id,
                (int) round((($loserStrength - $winnerStrength) / $winnerStrength) * 100),
                array('match_id' => (int) $event->match->id)
            );
        }

        if (self::winnerWasBehind($event->websoccer, $event->db, (int) $event->match->id, $winnerIsHome)) {
            self::recordCumulativeEventAndAward(
                $event->websoccer,
                $event->db,
                (int) $team['user_id'],
                (int) $winner->id,
                $seasonId,
                self::EVENT_COMEBACK_KING,
                'match:' . (int) $event->match->id,
                1,
                array('match_id' => (int) $event->match->id)
            );
        }
    }

    private static function recordCumulativeEventAndAward(WebSoccer $websoccer, DbConnection $db, $userId, $teamId, $seasonId, $eventName, $referenceKey, $eventValue = 1, $contextData = null) {
        $logged = self::recordEvent($websoccer, $db, $userId, $teamId, $seasonId, $eventName, $referenceKey, $eventValue);
        if (!$logged) {
            return;
        }

        $count = self::countEvents($websoccer, $db, $userId, $eventName);
        if (!$contextData || !is_array($contextData)) {
            $contextData = array();
        }
        $contextData['count'] = $count;

        BadgesDataService::awardBadgeIfApplicable(
            $websoccer,
            $db,
            $userId,
            $eventName,
            $count,
            $teamId,
            null,
            $contextData,
            TRUE,
            $seasonId
        );
    }

    private static function recordEvent(WebSoccer $websoccer, DbConnection $db, $userId, $teamId, $seasonId, $eventName, $referenceKey, $eventValue = 1) {
        $userId = (int) $userId;
        $teamId = (int) $teamId;
        $seasonId = (int) $seasonId;
        $eventName = (string) $eventName;
        $referenceKey = (string) $referenceKey;

        if ($userId < 1 || !strlen($eventName) || !strlen($referenceKey)) {
            return FALSE;
        }

        $table = $websoccer->getConfig('db_prefix') . '_badge_event_log';
        $result = $db->querySelect('id', $table, "user_id = %d AND event = '%s' AND reference_key = '%s'", array($userId, $eventName, $referenceKey), 1);
        $existing = $result->fetch_array();
        $result->free();

        if ($existing) {
            return FALSE;
        }

        $db->queryInsert(array(
            'user_id' => $userId,
            'team_id' => ($teamId > 0) ? $teamId : '',
            'season_id' => ($seasonId > 0) ? $seasonId : '',
            'event' => $eventName,
            'event_value' => (int) $eventValue,
            'reference_key' => $referenceKey,
            'event_date' => $websoccer->getNowAsTimestamp()
        ), $table);

        return TRUE;
    }

    private static function countEvents(WebSoccer $websoccer, DbConnection $db, $userId, $eventName) {
        $result = $db->querySelect(
            'COUNT(*) AS hits',
            $websoccer->getConfig('db_prefix') . '_badge_event_log',
            "user_id = %d AND event = '%s'",
            array((int) $userId, (string) $eventName),
            1
        );
        $row = $result->fetch_array();
        $result->free();

        return ($row && isset($row['hits'])) ? (int) $row['hits'] : 0;
    }

    private static function getManagedTeam(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $result = $db->querySelect(
            'id, name, user_id, finanz_budget, interimmanager',
            $websoccer->getConfig('db_prefix') . '_verein',
            "id = %d AND status = '1' AND nationalteam != '1' AND user_id > 0",
            (int) $teamId,
            1
        );
        $team = $result->fetch_array();
        $result->free();

        if (!$team || (int) $team['user_id'] < 1 || $team['interimmanager'] === '1') {
            return null;
        }

        return $team;
    }

    private static function getSeasonIdOfMatch(WebSoccer $websoccer, DbConnection $db, $matchId) {
        $result = $db->querySelect('saison_id', $websoccer->getConfig('db_prefix') . '_spiel', 'id = %d', (int) $matchId, 1);
        $match = $result->fetch_array();
        $result->free();

        return ($match && isset($match['saison_id'])) ? (int) $match['saison_id'] : 0;
    }

    private static function winnerWasBehind(WebSoccer $websoccer, DbConnection $db, $matchId, $winnerIsHome) {
        $result = $db->querySelect(
            'goals',
            $websoccer->getConfig('db_prefix') . '_matchreport',
            "match_id = %d AND goals IS NOT NULL AND goals != '' ORDER BY minute ASC, id ASC",
            (int) $matchId
        );

        $wasBehind = FALSE;
        while ($row = $result->fetch_array()) {
            $score = explode(':', (string) $row['goals']);
            if (count($score) !== 2) {
                continue;
            }

            $homeGoals = (int) $score[0];
            $guestGoals = (int) $score[1];

            if ($winnerIsHome && $homeGoals < $guestGoals) {
                $wasBehind = TRUE;
                break;
            }

            if (!$winnerIsHome && $guestGoals < $homeGoals) {
                $wasBehind = TRUE;
                break;
            }
        }

        $result->free();
        return $wasBehind;
    }

    private static function getLastPurchasePrice(WebSoccer $websoccer, DbConnection $db, $teamId, $playerId, $currentTransferId = 0) {
        $whereCondition = 'spieler_id = %d AND buyer_club_id = %d AND directtransfer_amount > 0';
        $parameters = array((int) $playerId, (int) $teamId);

        if ((int) $currentTransferId > 0) {
            $whereCondition .= ' AND id != %d';
            $parameters[] = (int) $currentTransferId;
        }

        $whereCondition .= ' ORDER BY datum DESC, id DESC';

        $result = $db->querySelect(
            'directtransfer_amount',
            $websoccer->getConfig('db_prefix') . '_transfer',
            $whereCondition,
            $parameters,
            1
        );
        $purchase = $result->fetch_array();
        $result->free();

        return ($purchase && isset($purchase['directtransfer_amount'])) ? (int) $purchase['directtransfer_amount'] : 0;
    }

    private static function hadPositiveBalanceForSeason(WebSoccer $websoccer, DbConnection $db, $teamId, $seasonId) {
        $prefix = $websoccer->getConfig('db_prefix');

        $result = $db->querySelect(
            'MIN(datum) AS season_start, MAX(datum) AS season_end',
            $prefix . '_spiel',
            'saison_id = %d AND (home_verein = %d OR gast_verein = %d)',
            array((int) $seasonId, (int) $teamId, (int) $teamId),
            1
        );
        $dates = $result->fetch_array();
        $result->free();

        if (!$dates || empty($dates['season_start'])) {
            return FALSE;
        }

        $seasonStart = (int) $dates['season_start'];
        $now = $websoccer->getNowAsTimestamp();

        $team = self::getManagedTeam($websoccer, $db, (int) $teamId);
        if (!$team) {
            return FALSE;
        }

        $currentBudget = (int) $team['finanz_budget'];
        if ($currentBudget <= 0) {
            return FALSE;
        }

        $result = $db->querySelect(
            'SUM(betrag) AS transaction_sum',
            $prefix . '_konto',
            'verein_id = %d AND datum >= %d AND datum <= %d',
            array((int) $teamId, $seasonStart, $now),
            1
        );
        $sumRow = $result->fetch_array();
        $result->free();

        $transactionSum = ($sumRow && isset($sumRow['transaction_sum'])) ? (int) $sumRow['transaction_sum'] : 0;
        $runningBudget = $currentBudget - $transactionSum;

        if ($runningBudget <= 0) {
            return FALSE;
        }

        $result = $db->querySelect(
            'betrag',
            $prefix . '_konto',
            'verein_id = %d AND datum >= %d AND datum <= %d ORDER BY datum ASC, id ASC',
            array((int) $teamId, $seasonStart, $now)
        );

        while ($statement = $result->fetch_array()) {
            $runningBudget += (int) $statement['betrag'];
            if ($runningBudget <= 0) {
                $result->free();
                return FALSE;
            }
        }

        $result->free();
        return TRUE;
    }
}
?>
