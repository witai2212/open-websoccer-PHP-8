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
 * Data service for user notifications.
 */
class NotificationsDataService {

	/**
	 * Creates a new unseen notification about any event which shall catch the user's attention.
	 * 
	 * @param WebSoccer $websoccer application context.
	 * @param DbConnection $db DB connection.
	 * @param int $userId ID of notification receiver.
	 * @param string $messageKey key of message to display. Can contain place holders in form of {myplaceholder}.
	 * @param array $messageData values of placeholder as an assoc. array. Array keys are message place holders.
	 * @param string $type Optional. Type of notification as a string ID. Can be used for displaying icons or similar in the view layer.
	 * @param string $targetPageId Optional. ID of page to which a link shall be targetting.
	 * @param string $targetPageQueryString Optional. Query string to append to the target page link.
	 * @param int $teamId Optional. ID of team to which this notification is assigned.
	 */
	public static function createNotification(WebSoccer $websoccer, DbConnection $db, $userId, $messageKey, 
			$messageData = null, $type = null, $targetPageId = null, $targetPageQueryString = null, $teamId = null) {
		
        $merchandisingNotification = self::prepareMerchandisingNotification(
            $websoccer,
            $db,
            $teamId,
            $messageKey,
            $messageData,
            $type
        );
        if ($merchandisingNotification !== null) {
            self::createOrMergeGroupedNotification(
                $websoccer,
                $db,
                $userId,
                $teamId,
                $merchandisingNotification['message_key'],
                $merchandisingNotification['message_data'],
                $merchandisingNotification['type'],
                $merchandisingNotification['group_key'],
                $targetPageId,
                $targetPageQueryString
            );
            return;
        }

		$columns = array(
				'user_id' => $userId,
				'eventdate' => $websoccer->getNowAsTimestamp(),
				'message_key' => $messageKey
				);
		
		if ($messageData != null) {
			$columns['message_data'] = json_encode($messageData);
		}
		
		if ($type != null) {
			$columns['eventtype'] = $type;
		}
		
		if ($targetPageId != null) {
			$columns['target_pageid'] = $targetPageId;
		}
		
		if ($targetPageQueryString != null) {
			$columns['target_querystr'] = $targetPageQueryString;
		}
		
		if ($teamId != null) {
			$columns['team_id'] = $teamId;
		}
		
		$db->queryInsert($columns, $websoccer->getConfig('db_prefix') . '_notification');
	}

    private static function prepareMerchandisingNotification(WebSoccer $websoccer, DbConnection $db, $teamId, $messageKey, $messageData, $type) {
        if ($teamId === null || $type === null || strpos((string) $type, 'merchandising_') !== 0) {
            return null;
        }

        $teamId = (int) $teamId;
        if ($teamId < 1) {
            return null;
        }

        if ($messageKey === 'merchandising_notification_delivery') {
            $timestamp = self::getLatestMerchandisingDeliveryTimestamp($websoccer, $db, $teamId);
            if ($timestamp < 1) {
                return null;
            }
            $products = self::getDeliveredMerchandisingProducts($websoccer, $db, $teamId, $timestamp);
            if (!$products) {
                return null;
            }
            return array(
                'message_key' => 'merchandising_notification_delivery_summary',
                'message_data' => array('products' => $products),
                'type' => 'merchandising_delivery',
                'group_key' => 'delivery:' . $teamId . ':' . $timestamp
            );
        }

        if ($messageKey === 'merchandising_notification_missed_sales' || $messageKey === 'merchandising_notification_low_stock') {
            $product = is_array($messageData) && !empty($messageData['product']) ? trim((string) $messageData['product']) : '';
            if ($product === '') {
                return null;
            }
            $matchId = self::getLatestMerchandisingMatchId($websoccer, $db, $teamId);
            if ($matchId < 1) {
                return null;
            }
            return array(
                'message_key' => 'merchandising_notification_matchday_stock_summary',
                'message_data' => array('products' => array($product)),
                'type' => 'merchandising_matchday_stock',
                'group_key' => 'match:' . $teamId . ':' . $matchId
            );
        }

        return null;
    }

    private static function createOrMergeGroupedNotification(WebSoccer $websoccer, DbConnection $db, $userId, $teamId, $messageKey, $messageData, $type, $groupKey, $targetPageId, $targetPageQueryString) {
        $table = $websoccer->getConfig('db_prefix') . '_notification';
        $result = $db->querySelect(
            'id, message_data',
            $table,
            "user_id = %d AND team_id = %d AND eventtype = '%s' AND message_key = '%s' ORDER BY id DESC",
            array((int) $userId, (int) $teamId, $type, $messageKey),
            20
        );

        $existingId = 0;
        $existingData = array();
        while ($row = $result->fetch_array()) {
            $decoded = !empty($row['message_data']) ? json_decode($row['message_data'], true) : array();
            if (is_array($decoded) && isset($decoded['_group']) && $decoded['_group'] === $groupKey) {
                $existingId = (int) $row['id'];
                $existingData = $decoded;
                break;
            }
        }
        $result->free();

        $products = array();
        if (!empty($existingData['products']) && is_array($existingData['products'])) {
            $products = $existingData['products'];
        }
        if (!empty($messageData['products']) && is_array($messageData['products'])) {
            $products = array_merge($products, $messageData['products']);
        }
        $products = self::normalizeMerchandisingProducts($products);
        if (!$products) {
            return;
        }

        $storedData = array(
            '_group' => $groupKey,
            'products' => $products
        );
        $encodedData = json_encode($storedData);

        if ($existingId > 0) {
            $db->queryUpdate(array(
                'eventdate' => $websoccer->getNowAsTimestamp(),
                'message_data' => $encodedData,
                'seen' => '0'
            ), $table, 'id = %d', $existingId);
            return;
        }

        $columns = array(
            'user_id' => (int) $userId,
            'eventdate' => $websoccer->getNowAsTimestamp(),
            'message_key' => $messageKey,
            'message_data' => $encodedData,
            'eventtype' => $type,
            'team_id' => (int) $teamId
        );
        if ($targetPageId != null) {
            $columns['target_pageid'] = $targetPageId;
        }
        if ($targetPageQueryString != null) {
            $columns['target_querystr'] = $targetPageQueryString;
        }
        $db->queryInsert($columns, $table);
    }

    private static function getLatestMerchandisingDeliveryTimestamp(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $result = $db->querySelect(
            'MAX(delivered_date) AS delivered_date',
            $websoccer->getConfig('db_prefix') . '_merchandising_order',
            "team_id = %d AND status = 'delivered'",
            (int) $teamId
        );
        $row = $result->fetch_array();
        $result->free();
        return $row ? (int) $row['delivered_date'] : 0;
    }

    private static function getDeliveredMerchandisingProducts(WebSoccer $websoccer, DbConnection $db, $teamId, $timestamp) {
        $prefix = $websoccer->getConfig('db_prefix');
        $select = 'P.name AS product_name';
        $from = $prefix . '_merchandising_order AS O INNER JOIN ' . $prefix . '_merchandising_collection AS C ON C.id = O.collection_id INNER JOIN ' . $prefix . '_merchandising_product AS P ON P.id = C.product_id';
        $result = $db->querySelect(
            $select,
            $from,
            "O.team_id = %d AND O.status = 'delivered' AND O.delivered_date = %d ORDER BY O.id ASC",
            array((int) $teamId, (int) $timestamp)
        );
        $products = array();
        while ($row = $result->fetch_array()) {
            if (!empty($row['product_name'])) {
                $products[] = (string) $row['product_name'];
            }
        }
        $result->free();
        return self::normalizeMerchandisingProducts($products);
    }

    private static function getLatestMerchandisingMatchId(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $result = $db->querySelect(
            'match_id',
            $websoccer->getConfig('db_prefix') . '_merchandising_sales',
            'team_id = %d ORDER BY id DESC',
            (int) $teamId,
            1
        );
        $row = $result->fetch_array();
        $result->free();
        return $row ? (int) $row['match_id'] : 0;
    }

    private static function normalizeMerchandisingProducts($products) {
        $normalized = array();
        foreach ($products as $product) {
            $product = trim((string) $product);
            if ($product !== '' && !in_array($product, $normalized, true)) {
                $normalized[] = $product;
            }
        }
        return $normalized;
    }
	
	/**
	 * Resolves placeholder values before rendering notifications.
	 *
	 * Some services store translated labels in message_data. Others may store
	 * message keys, e.g. fanpressure_reason_youth_used. Translating these keys
	 * here prevents raw internal keys from being shown to users and also fixes
	 * already existing notifications in the database.
	 *
	 * @param I18n $i18n I18n context.
	 * @param mixed $value Placeholder value from decoded JSON.
	 * @return string Resolved placeholder text.
	 */
	private static function resolvePlaceholderValue(I18n $i18n, $value) {
		if (is_array($value)) {
			$resolvedValues = array();
			foreach ($value as $singleValue) {
				$resolvedValues[] = self::resolvePlaceholderValue($i18n, $singleValue);
			}
			return implode(', ', $resolvedValues);
		}

		if ($value === null) {
			return '';
		}

		if (is_bool($value)) {
			return $value ? '1' : '0';
		}

		$value = (string) $value;
		if ($value !== '' && preg_match('/^[A-Za-z0-9_.-]+$/', $value) && $i18n->hasMessage($value)) {
			return $i18n->getMessage($value);
		}

		return $value;
	}

	/**
	 * Counts and returns number of unseen notifications of specified user.
	 * 
	 * @param WebSoccer $websoccer application context.
	 * @param DbConnection $db DB connection.
	 * @param int $userId ID of user.
	 * @param int $teamId ID of user's team.
	 * @return int number of unseen notifications.
	 */
	public static function countUnseenNotifications(WebSoccer $websoccer, DbConnection $db, $userId, $teamId) {
		
		$result = $db->querySelect('COUNT(*) AS hits', $websoccer->getConfig('db_prefix') . '_notification', 
				'user_id = %d AND seen = \'0\' AND (team_id = %d OR team_id IS NULL)', array($userId, $teamId));
		$rows = $result->fetch_array();
		$rows = $result->fetch_array();
		$result->free();
		
		if ($rows) {
			return $rows['hits'];
		}
		
		return 0;
	}
	
	/**
	 * Retrieves the latest notifications for the specified user.
	 * 
	 * @param WebSoccer $websoccer Application contex
	 * @param DbConnection $db DB connection
	 * @param I18n $i18n I18n context.
	 * @param int $userId ID of user
	 * @param int $teamId ID of user's currently selected team
	 * @param int $limit maximum number of notifications to return.
	 * @return array Array of assoc. arrays which represent a notification. A notification has keys id, eventdate, eventtype, seen, message, link
	 */
	public static function getLatestNotifications(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $userId, $teamId, $limit) {
		
		$result = $db->querySelect('*', $websoccer->getConfig('db_prefix') . '_notification', 
				'user_id = %d AND (team_id = %d OR team_id IS NULL) ORDER BY eventdate DESC', array($userId, $teamId), $limit);
		
		$notifications = array();
		while ($row = $result->fetch_array()) {
			$notification = array(
					'id' => $row['id'],
					'eventdate' => $row['eventdate'],
					'eventtype' => $row['eventtype'],
					'seen' => $row['seen']
					);
			
			// prepare message
			if ($i18n->hasMessage($row['message_key'])) {
				$message = $i18n->getMessage($row['message_key']);
			} else {
				$message = $row['message_key'];
			}
			
			// replace place holders
			if (strlen($row['message_data'])) {
				$messageData = json_decode($row['message_data'], true);
				
				if ($messageData) {
					foreach ($messageData as $placeholderName => $placeholderValue) {
						$placeholderValue = self::resolvePlaceholderValue($i18n, $placeholderValue);
						$message = str_replace('{' . $placeholderName . '}', 
								htmlspecialchars($placeholderValue, ENT_COMPAT, 'UTF-8'), $message);
					}
				}
			}
			
			$notification['message'] = $message;
			
			// add target link
			$link = '';
			if ($row['target_pageid']) {
				if ($row['target_querystr']) {
					$link = $websoccer->getInternalUrl($row['target_pageid'], $row['target_querystr']);
				} else {
					$link = $websoccer->getInternalUrl($row['target_pageid']);
				}
			}
			
			$notification['link'] = $link;
			
			$notifications[] = $notification;
		}
		return $notifications;
	}

    public static function groupNotificationsByDateAndType($notifications) {
        $dateGroups = array();
        foreach ($notifications as $notification) {
            $timestamp = isset($notification['eventdate']) ? (int) $notification['eventdate'] : 0;
            $dateKey = $timestamp > 0 ? date('Y-m-d', $timestamp) : 'unknown';
            $typeKey = !empty($notification['eventtype']) ? preg_replace('/[^a-zA-Z0-9_-]/', '', $notification['eventtype']) : 'general';
            if (!isset($dateGroups[$dateKey])) {
                $dateGroups[$dateKey] = array(
                    'key' => str_replace('-', '', $dateKey),
                    'timestamp' => $timestamp,
                    'is_open' => count($dateGroups) === 0,
                    'types' => array()
                );
            }
            if (!isset($dateGroups[$dateKey]['types'][$typeKey])) {
                $dateGroups[$dateKey]['types'][$typeKey] = array(
                    'key' => $typeKey,
                    'eventtype' => isset($notification['eventtype']) ? $notification['eventtype'] : '',
                    'items' => array(),
                    'has_unseen' => false
                );
            }
            $dateGroups[$dateKey]['types'][$typeKey]['items'][] = $notification;
            if (empty($notification['seen'])) {
                $dateGroups[$dateKey]['types'][$typeKey]['has_unseen'] = true;
            }
        }
        foreach ($dateGroups as &$dateGroup) {
            $dateGroup['types'] = array_values($dateGroup['types']);
        }
        unset($dateGroup);
        return array_values($dateGroups);
    }

}
?>