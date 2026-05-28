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
 * Data service for creating and reading badges.
 */
class BadgesDataService {
    
    /**
     * Assigns a new badge to user and notifies him, if a new badge is applicable.
     * Applicable means whether there are badges for the specified event and benchmark and whether user has
     * no higher level for this event. If an award key is supplied, the same badge can be earned repeatedly,
     * but only once for the same key.
     * 
     * @param WebSoccer $websoccer Application context.
     * @param DbConnection $db DB connection.
     * @param int $userId ID of user.
     * @param string $badgeEvent badge triggering event ID.
     * @param int|null $benchmark Benchmark, if applicable.
     * @param int|null $teamId Team context.
     * @param string|null $awardKey Unique key for repeatable badge awards.
     * @param array|null $contextData Additional context stored with the badge assignment.
     * @param bool $postNews TRUE if a public news post shall be created.
     * @param int|null $seasonId Season context.
     */
    public static function awardBadgeIfApplicable(WebSoccer $websoccer, DbConnection $db, $userId, $badgeEvent, $benchmark = NULL,
            $teamId = null, $awardKey = null, $contextData = null, $postNews = FALSE, $seasonId = null) {
        
        $userId = (int) $userId;
        if ($userId < 1) {
            return;
        }

        // get possible badge
        $badgeTable = $websoccer->getConfig('db_prefix') . '_badge';
        $badgeUserTable = $websoccer->getConfig('db_prefix') . '_badge_user';
        
        $parameters = array($badgeEvent);
        $whereCondition = 'event = \'%s\'';
        if ($benchmark !== NULL) {
            $whereCondition .= ' AND event_benchmark <= %d';
            $parameters[] = (int) $benchmark;
        }
        $whereCondition .= " ORDER BY event_benchmark DESC, FIELD(level, 'gold', 'silver', 'bronze') ASC";
        
        $result = $db->querySelect('id, name, description, level, event, event_benchmark', $badgeTable, $whereCondition, $parameters, 1);
        $badge = $result->fetch_array();
        $result->free();
        
        if (!$badge) {
            return;
        }

        if ($awardKey !== null && strlen((string) $awardKey)) {
            $result = $db->querySelect(
                'COUNT(*) AS hits',
                $badgeUserTable,
                'user_id = %d AND badge_id = %d AND award_key = \'%s\'',
                array($userId, (int) $badge['id'], (string) $awardKey),
                1
            );
            $userBadges = $result->fetch_array();
            $result->free();
            
            if ($userBadges && (int) $userBadges['hits'] > 0) {
                return;
            }
        } else {
            // keep the classic behaviour: if no repeat key is supplied, a user can only progress to a better badge.
            $fromTable = $badgeTable . ' AS B INNER JOIN ' . $badgeUserTable . ' AS BU ON B.id = BU.badge_id';
            $whereCondition = 'BU.user_id = %d AND B.event = \'%s\' AND B.event_benchmark >= %d';
            $result = $db->querySelect('COUNT(*) AS hits', $fromTable, $whereCondition,
                array($userId, $badgeEvent, (int) $badge['event_benchmark']), 1);
            $userBadges = $result->fetch_array();
            $result->free();
            
            if ($userBadges && (int) $userBadges['hits'] > 0) {
                return;
            }
        }
        
        self::awardBadge($websoccer, $db, $userId, (int) $badge['id'], $teamId, $awardKey, $contextData, $postNews, $seasonId);
    }
    
    /**
     * Creates badge assignment.
     *
     * @param WebSoccer $websoccer Application context.
     * @param DbConnection $db DB connection.
     * @param int $userId ID of user.
     * @param int $badgeId ID of badge.
     * @param int|null $teamId Team context.
     * @param string|null $awardKey Unique key for repeatable badge awards.
     * @param array|null $contextData Additional context stored with the badge assignment.
     * @param bool $postNews TRUE if a public news post shall be created.
     * @param int|null $seasonId Season context.
     */
    public static function awardBadge(WebSoccer $websoccer, DbConnection $db, $userId, $badgeId,
            $teamId = null, $awardKey = null, $contextData = null, $postNews = FALSE, $seasonId = null) {
        $badgeUserTable = $websoccer->getConfig('db_prefix') . '_badge_user';
        $badge = self::getBadgeById($websoccer, $db, $badgeId);

        if (!$badge) {
            return;
        }

        if ($awardKey === null || !strlen((string) $awardKey)) {
            $awardKey = 'badge:' . (int) $badgeId;
        }

        // create assignment
        $columns = array(
            'user_id' => (int) $userId,
            'team_id' => ($teamId !== null && (int) $teamId > 0) ? (int) $teamId : '',
            'season_id' => ($seasonId !== null && (int) $seasonId > 0) ? (int) $seasonId : '',
            'badge_id' => (int) $badgeId,
            'date_rewarded' => $websoccer->getNowAsTimestamp(),
            'award_key' => (string) $awardKey
        );

        if ($contextData !== null) {
            $columns['context_data'] = is_array($contextData) ? json_encode($contextData) : (string) $contextData;
        }

        $db->queryInsert($columns, $badgeUserTable);
        
        // notify lucky user
        $badgeName = self::getDisplayText($badge['name']);
        NotificationsDataService::createNotification($websoccer, $db, (int) $userId, 'badge_notification_named',
            array('badge' => $badgeName), 'badge', 'badges', null, ($teamId !== null && (int) $teamId > 0) ? (int) $teamId : null);

        if ($postNews) {
            self::createBadgeNews($websoccer, $db, (int) $userId, $badge, $teamId);
        }
    }

    private static function getBadgeById(WebSoccer $websoccer, DbConnection $db, $badgeId) {
        $result = $db->querySelect(
            'id, name, description, level, event, event_benchmark',
            $websoccer->getConfig('db_prefix') . '_badge',
            'id = %d',
            (int) $badgeId,
            1
        );
        $badge = $result->fetch_array();
        $result->free();

        return $badge ? $badge : null;
    }

    private static function createBadgeNews(WebSoccer $websoccer, DbConnection $db, $userId, $badge, $teamId = null) {
        $badgeName = self::getDisplayText($badge['name']);
        $userName = self::getUserName($websoccer, $db, $userId);
        $teamName = self::getTeamName($websoccer, $db, $teamId);

        if (strlen($teamName)) {
            $title = 'Neue Auszeichnung für ' . $teamName;
            $message = $userName . ' hat mit ' . $teamName . ' die Auszeichnung "' . $badgeName . '" gewonnen.';
        } else {
            $title = 'Neue Auszeichnung für ' . $userName;
            $message = $userName . ' hat die Auszeichnung "' . $badgeName . '" gewonnen.';
        }

        $db->queryInsert(
            array(
                'datum' => $websoccer->getNowAsTimestamp(),
                'autor_id' => 1,
                'titel' => $title,
                'nachricht' => $message,
                'linktext1' => 'Auszeichnungen ansehen',
                'linkurl1' => $websoccer->getInternalUrl('badges'),
                'c_br' => '1',
                'c_links' => '1',
                'c_smilies' => '0',
                'status' => '1'
            ),
            $websoccer->getConfig('db_prefix') . '_news'
        );
    }

    private static function getUserName(WebSoccer $websoccer, DbConnection $db, $userId) {
        $result = $db->querySelect('nick, name', $websoccer->getConfig('db_prefix') . '_user', 'id = %d', (int) $userId, 1);
        $user = $result->fetch_array();
        $result->free();

        if (!$user) {
            return 'Ein Manager';
        }

        if (isset($user['nick']) && strlen($user['nick'])) {
            return $user['nick'];
        }

        if (isset($user['name']) && strlen($user['name'])) {
            return $user['name'];
        }

        return 'Ein Manager';
    }

    private static function getTeamName(WebSoccer $websoccer, DbConnection $db, $teamId) {
        if ($teamId === null || (int) $teamId < 1) {
            return '';
        }

        $result = $db->querySelect('name', $websoccer->getConfig('db_prefix') . '_verein', 'id = %d', (int) $teamId, 1);
        $team = $result->fetch_array();
        $result->free();

        return ($team && isset($team['name'])) ? $team['name'] : '';
    }

    private static function getDisplayText($text) {
        return (string) $text;
    }
    
}
?>
