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
 * Watchdog for youth player transfers.
 *
 * It uses the same deviation setting and the same penalty account as the
 * professional transfer watchdog:
 * - transferoffers_deviation_penalty
 * - transferoffers_max_offer_deviation
 * - {db_prefix}_penalty.penalty
 */
class YouthTransferWatchdogDataService {

    /**
     * Checks a completed youth transfer and charges penalties for unreasonable prices.
     *
     * @param WebSoccer $websoccer Application context
     * @param DbConnection $db DB connection
     * @param array|int $player Youth player row or youth player ID
     * @param int $buyerClubId Buying club ID
     * @param int $sellerClubId Selling club ID
     * @param int $transferFee Transfer fee
     * @return array Watchdog result summary
     */
    public static function watchTransfer(WebSoccer $websoccer, DbConnection $db, $player, $buyerClubId, $sellerClubId, $transferFee) {
        $result = self::emptyResult();

        if ($websoccer->getConfig('transferoffers_deviation_penalty') !== '1') {
            return $result;
        }

        $player = self::normalizePlayerRow($websoccer, $db, $player);
        if (!$player) {
            return $result;
        }

        $buyerClubId = (int) $buyerClubId;
        $sellerClubId = (int) $sellerClubId;
        $transferFee = self::normalizeMoneyValue($transferFee);

        if ($buyerClubId < 1 || $sellerClubId < 1 || $buyerClubId === $sellerClubId || $transferFee <= 0) {
            return $result;
        }

        $fairValue = self::calculateFairYouthPlayerValue($player);
        if ($fairValue <= 0) {
            return $result;
        }

        $maxDeviation = self::getMaxDeviation($websoccer);
        $minAllowedAmount = $fairValue * (1 - ($maxDeviation / 100));
        $maxAllowedAmount = $fairValue * (1 + ($maxDeviation / 100));

        $result['fair_value'] = (int) round($fairValue, 0);
        $result['min_allowed_amount'] = (int) round($minAllowedAmount, 0);
        $result['max_allowed_amount'] = (int) round($maxAllowedAmount, 0);
        $result['transfer_fee'] = (int) round($transferFee, 0);

        if ($transferFee > $maxAllowedAmount) {
            $result['buyer_penalty'] = (int) round($transferFee - $maxAllowedAmount, 0);
        }

        if ($transferFee < $minAllowedAmount) {
            $result['seller_penalty'] = (int) round($minAllowedAmount - $transferFee, 0);
        }

        $buyer = self::getPenaltyTeamRow($websoccer, $db, $buyerClubId);
        $seller = self::getPenaltyTeamRow($websoccer, $db, $sellerClubId);
        $playerName = trim($player['firstname'] . ' ' . $player['lastname']);

        if ($result['buyer_penalty'] > 0 && $buyer && (int) $buyer['user_id'] > 0) {
            self::chargeYouthTransferPenalty(
                $websoccer,
                $db,
                (int) $buyer['id'],
                (int) $buyer['user_id'],
                (int) $result['buyer_penalty'],
                $playerName,
                (int) $result['transfer_fee'],
                (int) $result['fair_value']
            );
            $result['charged_total'] += (int) $result['buyer_penalty'];
        }

        if ($result['seller_penalty'] > 0 && $seller && (int) $seller['user_id'] > 0) {
            self::chargeYouthTransferPenalty(
                $websoccer,
                $db,
                (int) $seller['id'],
                (int) $seller['user_id'],
                (int) $result['seller_penalty'],
                $playerName,
                (int) $result['transfer_fee'],
                (int) $result['fair_value']
            );
            $result['charged_total'] += (int) $result['seller_penalty'];
        }

        if ($result['charged_total'] > 0) {
            self::addToPenaltyAccount($websoccer, $db, (int) $result['charged_total']);
        }

        self::logWatchdogResult($websoccer, $db, $player, $buyerClubId, $sellerClubId, $result);

        return $result;
    }

    /**
     * Calculates a fair youth player value from strength, age and position.
     * This mirrors the computer youth transfer logic so CPU behaviour and
     * human transfer control use the same economic baseline.
     *
     * @param array $player Youth player row
     * @return int Fair value
     */
    public static function calculateFairYouthPlayerValue($player) {
        if (isset($player['market_value']) && (int) $player['market_value'] > 0) {
            return (int) $player['market_value'];
        }
        $strength = max(1, (int) $player['strength']);
        $age = max(14, (int) $player['age']);
        $position = isset($player['position']) ? $player['position'] : '';

        $fairValue = $strength * $strength * 1000;

        if ($age <= 14) {
            $fairValue *= 1.35;
        } elseif ($age == 15) {
            $fairValue *= 1.25;
        } elseif ($age == 16) {
            $fairValue *= 1.10;
        } elseif ($age == 17) {
            $fairValue *= 0.95;
        } else {
            $fairValue *= 0.70;
        }

        if ($position === 'Torwart') {
            $fairValue *= 1.10;
        }

        if ($strength < 20) {
            $fairValue *= 0.60;
        } elseif ($strength < 30) {
            $fairValue *= 0.80;
        }

        $fairValue = (int) round($fairValue / 1000) * 1000;

        if ($fairValue < 25000) {
            $fairValue = 25000;
        }

        return (int) $fairValue;
    }

    private static function emptyResult() {
        return array(
            'charged_total' => 0,
            'buyer_penalty' => 0,
            'seller_penalty' => 0,
            'fair_value' => 0,
            'min_allowed_amount' => 0,
            'max_allowed_amount' => 0,
            'transfer_fee' => 0
        );
    }

    private static function normalizePlayerRow(WebSoccer $websoccer, DbConnection $db, $player) {
        if (is_array($player)) {
            return $player;
        }

        $playerId = (int) $player;
        if ($playerId < 1) {
            return null;
        }

        $result = $db->querySelect('*', $websoccer->getConfig('db_prefix') . '_youthplayer', 'id = %d', $playerId, 1);
        $row = $result->fetch_array();
        $result->free();

        return $row ? $row : null;
    }

    private static function getMaxDeviation(WebSoccer $websoccer) {
        $configuredDeviation = $websoccer->getConfig('transferoffers_max_offer_deviation');

        if ($configuredDeviation === null || $configuredDeviation === '' || !is_numeric($configuredDeviation)) {
            return 25.0;
        }

        $maxDeviation = (float) $configuredDeviation;
        if ($maxDeviation < 0) {
            $maxDeviation = 0;
        }

        return $maxDeviation;
    }

    private static function chargeYouthTransferPenalty(WebSoccer $websoccer, DbConnection $db, $teamId, $userId, $penalty, $playerName, $transferFee, $fairValue) {
        $penalty = (int) $penalty;

        if ($penalty <= 0 || $teamId < 1 || $userId < 1) {
            return;
        }

        BankAccountDataService::debitAmount(
            $websoccer,
            $db,
            $teamId,
            $penalty,
            'youth_transfer_violation',
            'Transferaufsicht'
        );

        NotificationsDataService::createNotification(
            $websoccer,
            $db,
            $userId,
            'youth_transfer_violation_notification',
            array(
                'penalty' => $penalty,
                'player' => $playerName,
                'amount' => $transferFee,
                'fairvalue' => $fairValue
            ),
            'youth_transfer',
            'youth-team',
            null,
            $teamId
        );
    }

    private static function addToPenaltyAccount(WebSoccer $websoccer, DbConnection $db, $amount) {
        $amount = (int) $amount;
        if ($amount <= 0) {
            return;
        }

        $penaltyTable = $websoccer->getConfig('db_prefix') . '_penalty';
        $db->executeQuery('INSERT IGNORE INTO ' . $penaltyTable . ' (id, budget, penalty) VALUES (1, 0, 0)');
        $db->executeQuery('UPDATE ' . $penaltyTable . ' SET penalty = penalty + ' . $amount . ' WHERE id = 1');
    }

    private static function getPenaltyTeamRow(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $result = $db->querySelect('id, name, user_id', $websoccer->getConfig('db_prefix') . '_verein', 'id = %d', (int) $teamId, 1);
        $team = $result->fetch_array();
        $result->free();

        return $team ? $team : null;
    }

    private static function normalizeMoneyValue($value) {
        $value = str_replace(array(' ', "\xc2\xa0"), '', (string) $value);
        $value = str_replace(',', '.', $value);
        $value = preg_replace('/[^0-9.\-]/', '', $value);

        if ($value === '' || $value === '-' || !is_numeric($value)) {
            return 0;
        }

        return (float) $value;
    }

    private static function logWatchdogResult(WebSoccer $websoccer, DbConnection $db, $player, $buyerClubId, $sellerClubId, $result) {
        $text = 'youth penalty buyer: ' . (int) $result['buyer_penalty']
            . ' seller: ' . (int) $result['seller_penalty']
            . ' charged: ' . (int) $result['charged_total']
            . ' fairValue: ' . (int) $result['fair_value']
            . ' amount: ' . (int) $result['transfer_fee']
            . ' - playerId: ' . (int) $player['id']
            . ' buyerId: ' . (int) $buyerClubId
            . ' sellerId: ' . (int) $sellerClubId;

        $db->queryInsert(array('text' => $text), $websoccer->getConfig('db_prefix') . '_transfer_log');
    }
}

?>
