<?php
/******************************************************

  This file is part of OpenWebSoccer-Sim.

******************************************************/

/**
 * Complete merchandising management for human-controlled clubs.
 *
 * Products are global templates. Clubs develop editions, order real stock,
 * set prices, run campaigns and sell through stadium and online channels.
 */
class MerchandisingDataService {

    const MODE_MANUAL = 'manual';
    const MODE_ADVISORY = 'advisory';
    const MODE_DELEGATED = 'delegated';

    const STRATEGY_CONSERVATIVE = 'conservative';
    const STRATEGY_BALANCED = 'balanced';
    const STRATEGY_AMBITIOUS = 'ambitious';

    const QUALITY_SIMPLE = 'simple';
    const QUALITY_STANDARD = 'standard';
    const QUALITY_PREMIUM = 'premium';

    private static $_refreshLocks = array();

    public static function getPageData(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $teamId, $userId) {
        self::assertHumanTeam($websoccer, $db, $teamId, $userId);
        self::refreshTeamState($websoccer, $db, $teamId, true);

        $settings = self::getSettings($websoccer, $db, $teamId);
        $staff = self::getMarketingManager($websoccer, $db, $teamId);
        if (!$staff && $settings['management_mode'] !== self::MODE_MANUAL) {
            $settings['management_mode'] = self::MODE_MANUAL;
        }

        return array(
            'settings' => $settings,
            'marketingManager' => $staff,
            'summary' => self::getSummary($websoccer, $db, $teamId),
            'collections' => self::getCollections($websoccer, $db, $i18n, $teamId),
            'availableProducts' => self::getAvailableProducts($websoccer, $db, $i18n, $teamId, $staff),
            'starPlayers' => self::getStarPlayers($websoccer, $db, $teamId),
            'orders' => self::getOrders($websoccer, $db, $i18n, $teamId),
            'campaigns' => self::getCampaigns($websoccer, $db, $i18n, $teamId),
            'campaignTypes' => self::getCampaignTypes($websoccer, $db),
            'productStatistics' => self::getProductStatistics($websoccer, $db, $i18n, $teamId),
            'playerStatistics' => self::getPlayerStatistics($websoccer, $db, $teamId),
            'recentSales' => self::getRecentSales($websoccer, $db, $i18n, $teamId),
            'managementModes' => array(self::MODE_MANUAL, self::MODE_ADVISORY, self::MODE_DELEGATED),
            'strategies' => array(self::STRATEGY_CONSERVATIVE, self::STRATEGY_BALANCED, self::STRATEGY_AMBITIOUS),
            'qualities' => array(self::QUALITY_SIMPLE, self::QUALITY_STANDARD, self::QUALITY_PREMIUM)
        );
    }

    public static function saveSettings(WebSoccer $websoccer, DbConnection $db, $teamId, $userId, $parameters) {
        self::assertHumanTeam($websoccer, $db, $teamId, $userId);

        $mode = isset($parameters['management_mode']) ? (string) $parameters['management_mode'] : self::MODE_MANUAL;
        $strategy = isset($parameters['strategy']) ? (string) $parameters['strategy'] : self::STRATEGY_BALANCED;
        if (!in_array($mode, array(self::MODE_MANUAL, self::MODE_ADVISORY, self::MODE_DELEGATED), true)) {
            throw new Exception('merchandising_error_invalid_mode');
        }
        if (!in_array($strategy, array(self::STRATEGY_CONSERVATIVE, self::STRATEGY_BALANCED, self::STRATEGY_AMBITIOUS), true)) {
            throw new Exception('merchandising_error_invalid_strategy');
        }
        if ($mode !== self::MODE_MANUAL && !self::getMarketingManager($websoccer, $db, $teamId)) {
            throw new Exception('merchandising_error_staff_required');
        }

        $budgetLimit = max(0, min(2000000000, (int) $parameters['budget_limit']));
        $maxStockValue = max(0, min(2000000000, (int) $parameters['max_stock_value']));
        $minMargin = max(0, min(80, (int) $parameters['min_margin_percent']));
        $autoReorder = !empty($parameters['auto_reorder']) ? '1' : '0';

        $table = self::table($websoccer, 'merchandising_team_settings');
        $result = $db->querySelect('team_id', $table, 'team_id = %d', (int) $teamId, 1);
        $exists = $result->fetch_array();
        $result->free();

        $columns = array(
            'management_mode' => $mode,
            'strategy' => $strategy,
            'budget_limit' => $budgetLimit,
            'auto_reorder' => $autoReorder,
            'min_margin_percent' => $minMargin,
            'max_stock_value' => $maxStockValue,
            'updated_date' => $websoccer->getNowAsTimestamp()
        );

        if ($exists) {
            $db->queryUpdate($columns, $table, 'team_id = %d', (int) $teamId);
        } else {
            $columns['team_id'] = (int) $teamId;
            $db->queryInsert($columns, $table);
        }

        if ($mode === self::MODE_DELEGATED) {
            self::refreshTeamState($websoccer, $db, $teamId, true);
        }
    }

    public static function startDevelopment(WebSoccer $websoccer, DbConnection $db, $teamId, $userId, $productId, $playerId, $quality, $createdBy = 'manager') {
        $team = self::assertHumanTeam($websoccer, $db, $teamId, $userId, $createdBy === 'staff');
        $product = self::getProduct($websoccer, $db, $productId);
        if (!$product || $product['active'] !== '1') {
            throw new Exception('merchandising_error_product_unavailable');
        }

        if (!in_array($quality, array(self::QUALITY_SIMPLE, self::QUALITY_STANDARD, self::QUALITY_PREMIUM), true)) {
            $quality = self::QUALITY_STANDARD;
        }

        $playerId = (int) $playerId;
        if ($product['player_product'] === '1') {
            if ($playerId < 1 || !self::isEligibleStarPlayer($websoccer, $db, $teamId, $playerId)) {
                throw new Exception('merchandising_error_player_not_star');
            }
        } else {
            $playerId = 0;
        }

        $window = self::getProductWindow($websoccer, $db, $teamId, $product);
        if (!$window['available']) {
            throw new Exception('merchandising_error_product_not_in_window');
        }

        $seasonKey = self::getSeasonKey($websoccer, $db, $teamId, $product, $playerId, $window);
        $table = self::table($websoccer, 'merchandising_collection');
        $where = 'team_id = %d AND product_id = %d AND player_id ' . ($playerId > 0 ? '= %d' : 'IS NULL') . " AND season_key = '%s'";
        $params = $playerId > 0
            ? array((int) $teamId, (int) $productId, $playerId, $seasonKey)
            : array((int) $teamId, (int) $productId, $seasonKey);
        $result = $db->querySelect('id, status', $table, $where, $params, 1);
        $existing = $result->fetch_array();
        $result->free();
        if ($existing && $existing['status'] !== 'closed') {
            throw new Exception('merchandising_error_collection_exists');
        }

        $qualityData = self::getQualityData($quality);
        $developmentCost = (int) round((int) $product['development_cost'] * $qualityData['development_cost_factor']);
        self::assertAffordable($team, $developmentCost);
        self::assertWithinBudget($websoccer, $db, $teamId, $developmentCost);

        if ($developmentCost > 0) {
            BankAccountDataService::debitAmount(
                $websoccer,
                $db,
                $teamId,
                $developmentCost,
                'merchandising_development_cost_subject',
                $websoccer->getConfig('projectname')
            );
        }

        $now = $websoccer->getNowAsTimestamp();
        $developmentDays = max(1, (int) ceil((int) $product['development_days'] * $qualityData['development_time_factor']));
        $sellingPrice = max(1, (int) $product['sales_price']);

        $columns = array(
            'team_id' => (int) $teamId,
            'product_id' => (int) $productId,
            'player_id' => $playerId > 0 ? $playerId : NULL,
            'season_key' => $seasonKey,
            'quality' => $quality,
            'status' => 'development',
            'selling_price' => $sellingPrice,
            'stock' => 0,
            'incoming_stock' => 0,
            'reorder_point' => max(0, (int) $product['minimum_order']),
            'development_started' => $now,
            'development_ready' => $now + ($developmentDays * 86400),
            'active_from' => (int) $window['start'],
            'active_until' => (int) $window['end'],
            'created_date' => $now,
            'updated_date' => $now
        );
        $db->queryInsert($columns, $table);

        return (int) $db->getLastInsertedId();
    }

    public static function orderStock(WebSoccer $websoccer, DbConnection $db, $teamId, $userId, $collectionId, $quantity, $createdBy = 'manager') {
        $team = self::assertHumanTeam($websoccer, $db, $teamId, $userId, $createdBy === 'staff');
        self::refreshTeamState($websoccer, $db, $teamId, false);
        $collection = self::getCollection($websoccer, $db, $teamId, $collectionId);
        if (!$collection || !in_array($collection['status'], array('ready', 'active'), true)) {
            throw new Exception('merchandising_error_collection_not_orderable');
        }

        $quantity = max(0, (int) $quantity);
        $minimumOrder = max(1, (int) $collection['minimum_order']);
        if ($quantity < $minimumOrder) {
            throw new Exception('merchandising_error_minimum_order');
        }

        $unitCost = self::getUnitCost($collection);
        $totalCost = $quantity * $unitCost;
        self::assertAffordable($team, $totalCost);
        self::assertWithinBudget($websoccer, $db, $teamId, $totalCost);
        self::assertWithinStockLimit($websoccer, $db, $teamId, $totalCost);

        BankAccountDataService::debitAmount(
            $websoccer,
            $db,
            $teamId,
            $totalCost,
            'merchandising_order_cost_subject',
            $websoccer->getConfig('projectname')
        );

        $now = $websoccer->getNowAsTimestamp();
        $staff = self::getMarketingManager($websoccer, $db, $teamId);
        $deliveryDays = max(1, (int) $collection['delivery_days']);
        if ($createdBy === 'staff' && $staff && (int) $staff['level'] >= 4) {
            $deliveryDays = max(1, $deliveryDays - 1);
        }

        $db->queryInsert(array(
            'team_id' => (int) $teamId,
            'collection_id' => (int) $collectionId,
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
            'total_cost' => $totalCost,
            'order_date' => $now,
            'delivery_date' => $now + ($deliveryDays * 86400),
            'delivered_date' => 0,
            'status' => 'pending',
            'created_by' => $createdBy === 'staff' ? 'staff' : 'manager'
        ), self::table($websoccer, 'merchandising_order'));

        $db->queryUpdate(
            array(
                'incoming_stock' => (int) $collection['incoming_stock'] + $quantity,
                'updated_date' => $now
            ),
            self::table($websoccer, 'merchandising_collection'),
            'id = %d AND team_id = %d',
            array((int) $collectionId, (int) $teamId)
        );
    }

    public static function saveCollection(WebSoccer $websoccer, DbConnection $db, $teamId, $userId, $collectionId, $sellingPrice, $reorderPoint, $status) {
        self::assertHumanTeam($websoccer, $db, $teamId, $userId);
        self::refreshTeamState($websoccer, $db, $teamId, false);
        $collection = self::getCollection($websoccer, $db, $teamId, $collectionId);
        if (!$collection) {
            throw new Exception('merchandising_error_collection_not_found');
        }

        $sellingPrice = max(1, (int) $sellingPrice);
        $minPrice = max(self::getUnitCost($collection), (int) round((int) $collection['sales_price'] * 0.70));
        $maxPrice = max($minPrice, (int) round((int) $collection['sales_price'] * (float) $collection['max_price_factor']));
        if ($sellingPrice < $minPrice || $sellingPrice > $maxPrice) {
            throw new Exception('merchandising_error_price_range');
        }

        $allowedStatus = array('ready', 'active', 'clearance', 'closed');
        if (!in_array($status, $allowedStatus, true)) {
            $status = $collection['status'];
        }
        if ($collection['status'] === 'development') {
            $status = 'development';
        }
        if ($status === 'active' && (int) $collection['active_until'] > 0 && $websoccer->getNowAsTimestamp() > (int) $collection['active_until']) {
            $status = 'clearance';
        }
        if ($status === 'closed' && ((int) $collection['stock'] > 0 || (int) $collection['incoming_stock'] > 0)) {
            throw new Exception('merchandising_error_stock_must_be_liquidated');
        }

        $db->queryUpdate(array(
            'selling_price' => $sellingPrice,
            'reorder_point' => max(0, (int) $reorderPoint),
            'status' => $status,
            'updated_date' => $websoccer->getNowAsTimestamp()
        ), self::table($websoccer, 'merchandising_collection'), 'id = %d AND team_id = %d', array((int) $collectionId, (int) $teamId));
    }

    public static function liquidateCollection(WebSoccer $websoccer, DbConnection $db, $teamId, $userId, $collectionId) {
        self::assertHumanTeam($websoccer, $db, $teamId, $userId);
        self::refreshTeamState($websoccer, $db, $teamId, false);
        $collection = self::getCollection($websoccer, $db, $teamId, $collectionId);
        if (!$collection) {
            throw new Exception('merchandising_error_collection_not_found');
        }
        if ((int) $collection['incoming_stock'] > 0) {
            throw new Exception('merchandising_error_pending_order');
        }

        $stock = max(0, (int) $collection['stock']);
        $recovery = 0;
        if ($stock > 0) {
            $recovery = (int) round($stock * self::getUnitCost($collection) * ((int) $collection['liquidation_percent'] / 100));
            if ($recovery > 0) {
                BankAccountDataService::creditAmount(
                    $websoccer,
                    $db,
                    $teamId,
                    $recovery,
                    'merchandising_liquidation_income_subject',
                    $websoccer->getConfig('projectname')
                );
            }
        }

        $db->queryUpdate(array(
            'stock' => 0,
            'status' => 'closed',
            'updated_date' => $websoccer->getNowAsTimestamp()
        ), self::table($websoccer, 'merchandising_collection'), 'id = %d AND team_id = %d', array((int) $collectionId, (int) $teamId));

        if ($stock > 0) {
            self::logStock($websoccer, $db, $teamId, $collectionId, 'liquidation', 0 - $stock, 0, 0, 'Restbestand liquidiert');
        }

        return $recovery;
    }

    public static function startCampaign(WebSoccer $websoccer, DbConnection $db, $teamId, $userId, $campaignTypeId, $collectionId, $createdBy = 'manager') {
        $team = self::assertHumanTeam($websoccer, $db, $teamId, $userId, $createdBy === 'staff');
        $campaignType = self::getCampaignType($websoccer, $db, $campaignTypeId);
        if (!$campaignType || $campaignType['active'] !== '1') {
            throw new Exception('merchandising_error_campaign_unavailable');
        }

        $collectionId = (int) $collectionId;
        if ($collectionId > 0 && !self::getCollection($websoccer, $db, $teamId, $collectionId)) {
            throw new Exception('merchandising_error_collection_not_found');
        }

        $result = $db->querySelect('COUNT(*) AS hits', self::table($websoccer, 'merchandising_campaign'), "team_id = %d AND status = 'active'", (int) $teamId);
        $row = $result->fetch_array();
        $result->free();
        if ($row && (int) $row['hits'] >= 2) {
            throw new Exception('merchandising_error_campaign_limit');
        }

        $cost = max(0, (int) $campaignType['cost']);
        self::assertAffordable($team, $cost);
        self::assertWithinBudget($websoccer, $db, $teamId, $cost);
        if ($cost > 0) {
            BankAccountDataService::debitAmount($websoccer, $db, $teamId, $cost, 'merchandising_campaign_cost_subject', $websoccer->getConfig('projectname'));
        }

        $now = $websoccer->getNowAsTimestamp();
        $db->queryInsert(array(
            'team_id' => (int) $teamId,
            'collection_id' => $collectionId > 0 ? $collectionId : NULL,
            'campaign_type_id' => (int) $campaignTypeId,
            'cost' => $cost,
            'demand_bonus' => max(0, min(50, (int) $campaignType['demand_bonus'])),
            'start_date' => $now,
            'end_date' => $now + (max(1, (int) $campaignType['duration_days']) * 86400),
            'status' => 'active',
            'created_by' => $createdBy === 'staff' ? 'staff' : 'manager'
        ), self::table($websoccer, 'merchandising_campaign'));
    }

    /**
     * Called after every completed first-team competitive match.
     */
    public static function processCompletedMatch(MatchCompletedEvent $event) {
        $enabled = $event->websoccer->getConfig('merchandising_enabled');
        if (!($enabled === null || $enabled === '' || $enabled == '1' || $enabled === TRUE)) {
            return;
        }
        $match = $event->match;
        if (!$match || ($match->type !== 'Ligaspiel' && $match->type !== 'Pokalspiel')) {
            return;
        }

        $websoccer = $event->websoccer;
        $db = $event->db;
        $matchId = (int) $match->id;
        $homeTeamId = (int) $match->homeTeam->id;
        $guestTeamId = (int) $match->guestTeam->id;

        $home = self::getTeam($websoccer, $db, $homeTeamId);
        $guest = self::getTeam($websoccer, $db, $guestTeamId);

        if ($home && (int) $home['user_id'] > 0 && (int) $home['nationalteam'] === 0) {
            self::refreshTeamState($websoccer, $db, $homeTeamId, true);
            if (!$match->isAtForeignStadium) {
                $spectators = self::getMatchSpectators($websoccer, $db, $matchId);
                if ($spectators > 0) {
                    self::processSalesChannel($event, $home, 'stadium', $spectators, true);
                }
            }
            self::processSalesChannel($event, $home, 'online', self::getOnlineAudience($websoccer, $db, $homeTeamId), true);
        }

        if ($guest && (int) $guest['user_id'] > 0 && (int) $guest['nationalteam'] === 0) {
            self::refreshTeamState($websoccer, $db, $guestTeamId, true);
            self::processSalesChannel($event, $guest, 'online', self::getOnlineAudience($websoccer, $db, $guestTeamId), false);
        }
    }

    public static function refreshTeamState(WebSoccer $websoccer, DbConnection $db, $teamId, $allowAutomation) {
        $teamId = (int) $teamId;
        if ($teamId < 1 || isset(self::$_refreshLocks[$teamId])) {
            return;
        }
        self::$_refreshLocks[$teamId] = true;

        try {
            $team = self::getTeam($websoccer, $db, $teamId);
            if (!$team || (int) $team['user_id'] < 1) {
                return;
            }
            $now = $websoccer->getNowAsTimestamp();

            // Complete product development.
            $table = self::table($websoccer, 'merchandising_collection');
            $result = $db->querySelect('id', $table, "team_id = %d AND status = 'development' AND development_ready <= %d", array($teamId, $now));
            $readyIds = array();
            while ($row = $result->fetch_array()) {
                $readyIds[] = (int) $row['id'];
            }
            $result->free();
            foreach ($readyIds as $id) {
                $db->queryUpdate(array('status' => 'ready', 'updated_date' => $now), $table, 'id = %d AND team_id = %d', array($id, $teamId));
                self::notify($websoccer, $db, $team, 'merchandising_notification_development_ready', array(), 'development');
            }

            // Deliver pending orders.
            $orderTable = self::table($websoccer, 'merchandising_order');
            $result = $db->querySelect('*', $orderTable, "team_id = %d AND status = 'pending' AND delivery_date <= %d ORDER BY id ASC", array($teamId, $now));
            $orders = array();
            while ($row = $result->fetch_array()) {
                $orders[] = $row;
            }
            $result->free();
            foreach ($orders as $order) {
                $collection = self::getCollection($websoccer, $db, $teamId, (int) $order['collection_id']);
                if (!$collection) {
                    continue;
                }
                $newStock = (int) $collection['stock'] + (int) $order['quantity'];
                $newIncoming = max(0, (int) $collection['incoming_stock'] - (int) $order['quantity']);
                $newStatus = $collection['status'] === 'ready' ? 'active' : $collection['status'];
                $db->queryUpdate(array(
                    'stock' => $newStock,
                    'incoming_stock' => $newIncoming,
                    'status' => $newStatus,
                    'updated_date' => $now
                ), $table, 'id = %d AND team_id = %d', array((int) $collection['id'], $teamId));
                $db->queryUpdate(array('status' => 'delivered', 'delivered_date' => $now), $orderTable, 'id = %d', (int) $order['id']);
                self::logStock($websoccer, $db, $teamId, (int) $collection['id'], 'delivery', (int) $order['quantity'], $newStock, (int) $order['id'], 'Warenlieferung');
                self::notify($websoccer, $db, $team, 'merchandising_notification_delivery', array('quantity' => (int) $order['quantity']), 'delivery');
            }

            // Complete campaigns.
            $db->queryUpdate(
                array('status' => 'completed'),
                self::table($websoccer, 'merchandising_campaign'),
                "team_id = %d AND status = 'active' AND end_date < %d",
                array($teamId, $now)
            );

            // Move expired collections into clearance.
            $result = $db->querySelect('id', $table, "team_id = %d AND status = 'active' AND active_until > 0 AND active_until < %d", array($teamId, $now));
            $expired = array();
            while ($row = $result->fetch_array()) {
                $expired[] = (int) $row['id'];
            }
            $result->free();
            foreach ($expired as $id) {
                $collection = self::getCollection($websoccer, $db, $teamId, $id);
                $clearancePrice = $collection ? max(self::getUnitCost($collection), (int) round((int) $collection['selling_price'] * 0.80)) : 1;
                $db->queryUpdate(array('status' => 'clearance', 'selling_price' => $clearancePrice, 'updated_date' => $now), $table, 'id = %d AND team_id = %d', array($id, $teamId));
                self::notify($websoccer, $db, $team, 'merchandising_notification_clearance', array(), 'clearance');
            }

            // Player shirts leave normal sale immediately when the player leaves the club.
            $formerPlayerFrom = $table . ' AS C LEFT JOIN ' . self::table($websoccer, 'spieler') . ' AS SP ON SP.id = C.player_id AND SP.verein_id = C.team_id';
            $result = $db->querySelect('C.id', $formerPlayerFrom, "C.team_id = %d AND C.player_id IS NOT NULL AND C.status IN ('ready','active') AND SP.id IS NULL", $teamId);
            $formerPlayerCollections = array();
            while ($row = $result->fetch_array()) {
                $formerPlayerCollections[] = (int) $row['id'];
            }
            $result->free();
            foreach ($formerPlayerCollections as $id) {
                $collection = self::getCollection($websoccer, $db, $teamId, $id);
                $clearancePrice = $collection ? max(self::getUnitCost($collection), (int) round((int) $collection['selling_price'] * 0.80)) : 1;
                $db->queryUpdate(array('status' => 'clearance', 'selling_price' => $clearancePrice, 'updated_date' => $now), $table, 'id = %d AND team_id = %d', array($id, $teamId));
                self::notify($websoccer, $db, $team, 'merchandising_notification_player_left', array(), 'clearance');
            }

            if ($allowAutomation) {
                self::runDelegatedManagement($websoccer, $db, $team);
            }
        } finally {
            unset(self::$_refreshLocks[$teamId]);
        }
    }

    private static function runDelegatedManagement(WebSoccer $websoccer, DbConnection $db, $team) {
        $teamId = (int) $team['id'];
        $settings = self::getSettings($websoccer, $db, $teamId);
        $staff = self::getMarketingManager($websoccer, $db, $teamId);
        if (!$staff || $settings['management_mode'] !== self::MODE_DELEGATED) {
            return;
        }

        $now = $websoccer->getNowAsTimestamp();
        $maxCollections = $settings['strategy'] === self::STRATEGY_CONSERVATIVE ? 3 : ($settings['strategy'] === self::STRATEGY_AMBITIOUS ? 7 : 5);
        $activeCount = self::countOpenCollections($websoccer, $db, $teamId);

        // Start sensible permanent and seasonal products when their launch window approaches.
        $result = $db->querySelect('*', self::table($websoccer, 'merchandising_product'), "active = '1' AND player_product = '0' ORDER BY FIELD(season_type,'christmas','easter','season','always','summer','event'), id ASC");
        $products = array();
        while ($row = $result->fetch_array()) {
            $products[] = $row;
        }
        $result->free();

        foreach ($products as $product) {
            if ($activeCount >= $maxCollections) {
                break;
            }
            if ($product['season_type'] === 'event') {
                continue;
            }
            $window = self::getProductWindow($websoccer, $db, $teamId, $product);
            if (!$window['available']) {
                continue;
            }
            $leadSeconds = ((int) $product['development_days'] + (int) $product['delivery_days'] + 10) * 86400;
            if ((int) $window['start'] > 0 && $now < ((int) $window['start'] - $leadSeconds)) {
                continue;
            }
            $seasonKey = self::getSeasonKey($websoccer, $db, $teamId, $product, 0, $window);
            if (self::collectionEditionExists($websoccer, $db, $teamId, (int) $product['id'], 0, $seasonKey)) {
                continue;
            }
            try {
                $quality = (int) $staff['level'] >= 5 ? self::QUALITY_PREMIUM : ((int) $staff['level'] <= 2 ? self::QUALITY_SIMPLE : self::QUALITY_STANDARD);
                self::startDevelopment($websoccer, $db, $teamId, 0, (int) $product['id'], 0, $quality, 'staff');
                $activeCount++;
            } catch (Exception $e) {
                // Spending guards intentionally stop unsuitable automated decisions.
            }
        }

        // A capable marketing manager also selects one eligible star shirt when capacity permits.
        if ($activeCount < $maxCollections) {
            $result = $db->querySelect('*', self::table($websoccer, 'merchandising_product'), "active = '1' AND player_product = '1' ORDER BY id ASC", null, 1);
            $playerProduct = $result->fetch_array();
            $result->free();
            if ($playerProduct) {
                $window = self::getProductWindow($websoccer, $db, $teamId, $playerProduct);
                foreach (self::getStarPlayers($websoccer, $db, $teamId) as $player) {
                    if (empty($player['eligible'])) {
                        continue;
                    }
                    $seasonKey = self::getSeasonKey($websoccer, $db, $teamId, $playerProduct, (int) $player['id'], $window);
                    if (self::collectionEditionExists($websoccer, $db, $teamId, (int) $playerProduct['id'], (int) $player['id'], $seasonKey)) {
                        continue;
                    }
                    try {
                        $quality = (int) $staff['level'] >= 4 ? self::QUALITY_PREMIUM : self::QUALITY_STANDARD;
                        self::startDevelopment($websoccer, $db, $teamId, 0, (int) $playerProduct['id'], (int) $player['id'], $quality, 'staff');
                    } catch (Exception $e) {
                        // No automatic shirt if budget, timing or eligibility prevents it.
                    }
                    break;
                }
            }
        }

        // Set prices and order initial or replacement stock.
        $collections = self::getCollectionsRaw($websoccer, $db, $teamId);
        foreach ($collections as $collection) {
            if (!in_array($collection['status'], array('ready', 'active'), true)) {
                continue;
            }

            $strategyPriceFactor = $settings['strategy'] === self::STRATEGY_CONSERVATIVE ? 0.95 : ($settings['strategy'] === self::STRATEGY_AMBITIOUS ? 1.10 : 1.00);
            $error = self::getForecastError((int) $staff['level']) / 100;
            $priceError = self::deterministicFactor($teamId . ':' . $collection['id'] . ':price:' . date('Ym', $now), 1 - ($error / 5), 1 + ($error / 5));
            $targetPrice = (int) round((int) $collection['sales_price'] * $strategyPriceFactor * $priceError);
            $unitCost = self::getUnitCost($collection);
            $marginFloor = max(0, min(80, (int) $settings['min_margin_percent']));
            if ($marginFloor < 100) {
                $targetPrice = max($targetPrice, (int) ceil($unitCost / max(0.20, 1 - ($marginFloor / 100))));
            }
            $minimumAllowedPrice = max($unitCost, (int) round((int) $collection['sales_price'] * 0.70));
            $maximumAllowedPrice = max($minimumAllowedPrice, (int) round((int) $collection['sales_price'] * (float) $collection['max_price_factor']));
            $targetPrice = max($minimumAllowedPrice, min($maximumAllowedPrice, $targetPrice));
            $db->queryUpdate(
                array('selling_price' => $targetPrice, 'updated_date' => $now),
                self::table($websoccer, 'merchandising_collection'),
                'id = %d AND team_id = %d',
                array((int) $collection['id'], $teamId)
            );
            $collection['selling_price'] = $targetPrice;

            $available = (int) $collection['stock'] + (int) $collection['incoming_stock'];
            $needsInitial = $collection['status'] === 'ready' && $available === 0;
            $needsReorder = $settings['auto_reorder'] === '1' && $available <= (int) $collection['reorder_point'];
            if (!$needsInitial && !$needsReorder) {
                continue;
            }

            $forecast = self::estimateDemand($websoccer, $db, $teamId, $collection, $staff);
            $strategyQuantityFactor = $settings['strategy'] === self::STRATEGY_CONSERVATIVE ? 0.80 : ($settings['strategy'] === self::STRATEGY_AMBITIOUS ? 1.20 : 1.00);
            $planningFactor = self::deterministicFactor(
                $teamId . ':' . $collection['id'] . ':order:' . date('Ym', $now),
                1 - $error,
                1 + $error
            );
            $quantity = max((int) $collection['minimum_order'], (int) ceil($forecast['expected'] * $strategyQuantityFactor * $planningFactor));
            try {
                self::orderStock($websoccer, $db, $teamId, 0, (int) $collection['id'], $quantity, 'staff');
            } catch (Exception $e) {
                // Spending and stock guards intentionally stop unsuitable orders.
            }
        }

        // Delegated management may run one campaign after a cooling-off period.
        $campaignTable = self::table($websoccer, 'merchandising_campaign');
        $result = $db->querySelect('COUNT(*) AS active_count, MAX(start_date) AS last_start', $campaignTable, 'team_id = %d', $teamId);
        $campaignState = $result->fetch_array();
        $result->free();
        $activeCampaigns = $campaignState ? (int) $campaignState['active_count'] : 0;
        $lastCampaignStart = $campaignState ? (int) $campaignState['last_start'] : 0;

        // COUNT above includes all statuses, therefore explicitly count active campaigns.
        $result = $db->querySelect('COUNT(*) AS hits', $campaignTable, "team_id = %d AND status = 'active'", $teamId);
        $activeRow = $result->fetch_array();
        $result->free();
        $activeCampaigns = $activeRow ? (int) $activeRow['hits'] : 0;

        if ($activeCampaigns === 0 && ($lastCampaignStart < 1 || $lastCampaignStart < $now - (21 * 86400))) {
            $targetCollection = array();
            foreach (self::getCollectionsRaw($websoccer, $db, $teamId, true) as $candidate) {
                if ((int) $candidate['stock'] > 0) {
                    $targetCollection = $candidate;
                    break;
                }
            }
            if ($targetCollection) {
                $orderBy = $settings['strategy'] === self::STRATEGY_CONSERVATIVE ? 'cost ASC, demand_bonus DESC' : 'demand_bonus DESC, cost ASC';
                $result = $db->querySelect('id', self::table($websoccer, 'merchandising_campaign_type'), "active = '1' ORDER BY " . $orderBy, null, 1);
                $campaignType = $result->fetch_array();
                $result->free();
                if ($campaignType) {
                    try {
                        self::startCampaign($websoccer, $db, $teamId, 0, (int) $campaignType['id'], (int) $targetCollection['id'], 'staff');
                    } catch (Exception $e) {
                        // The club's safeguards remain authoritative.
                    }
                }
            }
        }
    }

    private static function processSalesChannel(MatchCompletedEvent $event, $team, $channel, $audience, $isHome) {
        $websoccer = $event->websoccer;
        $db = $event->db;
        $teamId = (int) $team['id'];
        $matchId = (int) $event->match->id;
        $audience = max(0, (int) $audience);
        if ($audience < 1) {
            return;
        }

        $collections = self::getCollectionsRaw($websoccer, $db, $teamId, true);
        if (!$collections) {
            return;
        }

        $homeGoals = (int) $event->match->homeTeam->getGoals();
        $guestGoals = (int) $event->match->guestTeam->getGoals();
        if (($isHome && $homeGoals > $guestGoals) || (!$isHome && $guestGoals > $homeGoals)) {
            $resultFactor = 1.10;
        } elseif ($homeGoals === $guestGoals) {
            $resultFactor = 1.00;
        } else {
            $resultFactor = 0.92;
        }

        $fanPopularity = max(0, min(100, (int) $team['fan_popularity']));
        $fanMood = max(0, min(100, (int) $team['fan_mood']));
        $popularityFactor = 0.72 + ($fanPopularity / 180);
        $moodFactor = 0.80 + ($fanMood / 250);
        $reachFactor = self::getClubReachFactor($team);
        $buildingFactor = $channel === 'stadium' ? 1 + (self::getBuildingBonus($websoccer, $db, $teamId) / 100) : 1.00;
        $staffFactor = class_exists('ClubStaffDataService') ? ClubStaffDataService::getMerchandisingFactor($websoccer, $db, $teamId) : 1.00;
        $derbyFactor = 1.00;
        if ($channel === 'stadium' && class_exists('RivalriesDataService')) {
            $derbyFactor = RivalriesDataService::getDerbyBusinessFactor($websoccer, $db, $event->match);
        }

        $totalRevenue = 0;
        foreach ($collections as $collection) {
            if ((int) $collection['stock'] <= 0) {
                continue;
            }
            if (!self::isCollectionSaleActive($websoccer, $collection)) {
                continue;
            }
            if (self::saleExists($websoccer, $db, $matchId, $teamId, (int) $collection['id'], $channel)) {
                continue;
            }

            $baseDemand = max(0.0001, (float) $collection['base_demand']);
            $channelFactor = $channel === 'stadium' ? (float) $collection['stadium_factor'] : (float) $collection['online_factor'];
            $qualityFactor = self::getQualityData($collection['quality'])['demand_factor'];
            $seasonFactor = self::getSeasonDemandFactor($websoccer, $collection);
            $priceFactor = self::getPriceDemandFactor($collection);
            $playerFactor = self::getPlayerDemandFactor($websoccer, $db, $teamId, $collection);
            $campaign = self::getCampaignFactor($websoccer, $db, $teamId, (int) $collection['id']);
            $clearanceFactor = $collection['status'] === 'clearance' ? 1.18 : 1.00;
            $randomFactor = self::deterministicFactor($matchId . ':' . $teamId . ':' . $collection['id'] . ':' . $channel, 0.92, 1.08);

            $demand = (int) round(
                $audience * $baseDemand * $channelFactor * $popularityFactor * $moodFactor *
                $reachFactor * $resultFactor * $buildingFactor * $staffFactor * $derbyFactor *
                $qualityFactor * $seasonFactor * $priceFactor * $playerFactor * $campaign['factor'] *
                $clearanceFactor * $randomFactor
            );
            if ($demand < 1) {
                continue;
            }

            $stockBefore = (int) $collection['stock'];
            $units = min($stockBefore, $demand);
            $stockAfter = $stockBefore - $units;
            $missed = max(0, $demand - $units);
            $price = max(1, (int) $collection['selling_price']);
            $revenue = $units * $price;
            $costs = $units * self::getUnitCost($collection);
            $profit = $revenue - $costs;

            $totalRevenue += $revenue;

            $db->queryInsert(array(
                'match_id' => $matchId,
                'team_id' => $teamId,
                'product_id' => (int) $collection['product_id'],
                'collection_id' => (int) $collection['id'],
                'player_id' => (int) $collection['player_id'] > 0 ? (int) $collection['player_id'] : NULL,
                'channel' => $channel,
                'demand_units' => $demand,
                'missed_units' => $missed,
                'stock_before' => $stockBefore,
                'stock_after' => $stockAfter,
                'campaign_id' => $campaign['id'] > 0 ? $campaign['id'] : NULL,
                'units_sold' => $units,
                'revenue' => $revenue,
                'costs' => $costs,
                'profit' => $profit,
                'created_date' => $websoccer->getNowAsTimestamp()
            ), self::table($websoccer, 'merchandising_sales'));
            $saleId = (int) $db->getLastInsertedId();

            $db->queryUpdate(array('stock' => $stockAfter, 'updated_date' => $websoccer->getNowAsTimestamp()), self::table($websoccer, 'merchandising_collection'), 'id = %d AND team_id = %d', array((int) $collection['id'], $teamId));
            self::logStock($websoccer, $db, $teamId, (int) $collection['id'], 'sale', 0 - $units, $stockAfter, $saleId, $channel);

            if ($missed > 0) {
                self::notify($websoccer, $db, $team, 'merchandising_notification_missed_sales', array('units' => $missed, 'product' => self::displayProductName($collection)), 'stockout');
            } elseif ($stockAfter > 0 && $stockAfter <= max(1, (int) $collection['reorder_point'])) {
                self::notify($websoccer, $db, $team, 'merchandising_notification_low_stock', array('stock' => $stockAfter, 'product' => self::displayProductName($collection)), 'low_stock');
            }
        }

        if ($totalRevenue > 0) {
            BankAccountDataService::creditAmount(
                $websoccer,
                $db,
                $teamId,
                $totalRevenue,
                $channel === 'stadium' ? 'merchandising_stadium_revenue_subject' : 'merchandising_online_revenue_subject',
                $websoccer->getConfig('projectname')
            );
        }

        self::runDelegatedManagement($websoccer, $db, $team);
    }

    private static function getSettings(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $table = self::table($websoccer, 'merchandising_team_settings');
        $result = $db->querySelect('*', $table, 'team_id = %d', (int) $teamId, 1);
        $row = $result->fetch_array();
        $result->free();
        if (!$row) {
            $row = array(
                'team_id' => (int) $teamId,
                'management_mode' => self::MODE_MANUAL,
                'strategy' => self::STRATEGY_BALANCED,
                'budget_limit' => 250000,
                'auto_reorder' => '0',
                'min_margin_percent' => 20,
                'max_stock_value' => 500000,
                'updated_date' => 0
            );
        }
        $row['team_id'] = (int) $row['team_id'];
        $row['budget_limit'] = (int) $row['budget_limit'];
        $row['min_margin_percent'] = (int) $row['min_margin_percent'];
        $row['max_stock_value'] = (int) $row['max_stock_value'];
        return $row;
    }

    private static function getMarketingManager(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $prefix = $websoccer->getConfig('db_prefix');
        $columns = array('S.id' => 'id', 'S.name' => 'name', 'S.level' => 'level', 'S.salary' => 'salary', 'S.bonus' => 'bonus', 'S.description' => 'description');
        $from = $prefix . '_club_staff_assignment AS A INNER JOIN ' . $prefix . '_club_staff AS S ON S.id = A.staff_id';
        $result = $db->querySelect($columns, $from, "A.team_id = %d AND A.role = 'marketing_manager' AND S.active = '1'", (int) $teamId, 1);
        $row = $result->fetch_array();
        $result->free();
        if (!$row) {
            return array();
        }
        $row['id'] = (int) $row['id'];
        $row['level'] = max(1, min(5, (int) $row['level']));
        $row['salary'] = (int) $row['salary'];
        $row['bonus'] = (int) $row['bonus'];
        $row['forecast_error'] = self::getForecastError($row['level']);
        return $row;
    }

    private static function getSummary(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $salesTable = self::table($websoccer, 'merchandising_sales');
        $result = $db->querySelect(
            'COALESCE(SUM(units_sold),0) AS units_sold, COALESCE(SUM(demand_units),0) AS demand_units, COALESCE(SUM(missed_units),0) AS missed_units, COALESCE(SUM(revenue),0) AS revenue, COALESCE(SUM(costs),0) AS costs, COALESCE(SUM(profit),0) AS profit, COUNT(DISTINCT match_id) AS matches_with_sales',
            $salesTable,
            'team_id = %d',
            (int) $teamId
        );
        $row = $result->fetch_array();
        $result->free();
        foreach (array('units_sold','demand_units','missed_units','revenue','costs','profit','matches_with_sales') as $key) {
            $row[$key] = isset($row[$key]) ? (int) $row[$key] : 0;
        }

        $result = $db->querySelect(
            'COALESCE(SUM(C.stock * P.purchase_price),0) AS stock_value, COALESCE(SUM(C.incoming_stock * P.purchase_price),0) AS incoming_value, COUNT(*) AS open_collections',
            self::table($websoccer, 'merchandising_collection') . ' AS C INNER JOIN ' . self::table($websoccer, 'merchandising_product') . ' AS P ON P.id = C.product_id',
            "C.team_id = %d AND C.status <> 'closed'",
            (int) $teamId
        );
        $stock = $result->fetch_array();
        $result->free();
        $row['stock_value'] = $stock ? (int) $stock['stock_value'] : 0;
        $row['incoming_value'] = $stock ? (int) $stock['incoming_value'] : 0;
        $row['open_collections'] = $stock ? (int) $stock['open_collections'] : 0;
        $row['fulfilment_percent'] = $row['demand_units'] > 0 ? round(($row['units_sold'] / $row['demand_units']) * 100, 1) : 100.0;
        return $row;
    }

    private static function getCollections(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $teamId) {
        $rows = self::getCollectionsRaw($websoccer, $db, $teamId);
        $staff = self::getMarketingManager($websoccer, $db, $teamId);
        foreach ($rows as &$row) {
            self::translateProductRow($i18n, $row);
            $row['display_name'] = self::displayProductName($row);
            $row['unit_cost'] = self::getUnitCost($row);
            $row['stock_value'] = (int) $row['stock'] * $row['unit_cost'];
            $row['margin'] = (int) $row['selling_price'] - $row['unit_cost'];
            $row['margin_percent'] = (int) $row['selling_price'] > 0 ? round(($row['margin'] / (int) $row['selling_price']) * 100, 1) : 0;
            $row['minimum_price'] = max($row['unit_cost'], (int) round((int) $row['sales_price'] * 0.70));
            $row['maximum_price'] = max($row['minimum_price'], (int) round((int) $row['sales_price'] * (float) $row['max_price_factor']));
            $row['forecast'] = self::estimateDemand($websoccer, $db, $teamId, $row, $staff);
            $row['development_ready_formatted'] = (int) $row['development_ready'] > 0 ? date('d.m.Y', (int) $row['development_ready']) : '';
            $row['active_until_formatted'] = (int) $row['active_until'] > 0 ? date('d.m.Y', (int) $row['active_until']) : '';
        }
        unset($row);
        return $rows;
    }

    private static function getCollectionsRaw(WebSoccer $websoccer, DbConnection $db, $teamId, $saleOnly = false) {
        $columns = array(
            'C.*' => 'collection_all',
            'P.name' => 'product_name',
            'P.description' => 'product_description',
            'P.category' => 'category',
            'P.season_type' => 'season_type',
            'P.purchase_price' => 'purchase_price',
            'P.sales_price' => 'sales_price',
            'P.base_demand' => 'base_demand',
            'P.delivery_days' => 'delivery_days',
            'P.minimum_order' => 'minimum_order',
            'P.liquidation_percent' => 'liquidation_percent',
            'P.player_product' => 'player_product',
            'P.event_type' => 'event_type',
            'P.stadium_factor' => 'stadium_factor',
            'P.online_factor' => 'online_factor',
            'P.max_price_factor' => 'max_price_factor',
            "CASE WHEN SP.kunstname IS NOT NULL AND SP.kunstname <> '' THEN SP.kunstname ELSE CONCAT(COALESCE(SP.vorname,''),' ',COALESCE(SP.nachname,'')) END" => 'player_name'
        );
        // DbConnection cannot expand C.* under an alias mapping. Build explicit select string.
        $select = "C.id, C.team_id, C.product_id, C.player_id, C.season_key, C.quality, C.status, C.selling_price, C.stock, C.incoming_stock, C.reorder_point, C.development_started, C.development_ready, C.active_from, C.active_until, C.created_date, C.updated_date, P.name AS product_name, P.description AS product_description, P.category, P.season_type, P.purchase_price, P.sales_price, P.base_demand, P.delivery_days, P.minimum_order, P.liquidation_percent, P.player_product, P.event_type, P.stadium_factor, P.online_factor, P.max_price_factor, CASE WHEN SP.kunstname IS NOT NULL AND SP.kunstname <> '' THEN SP.kunstname ELSE TRIM(CONCAT(COALESCE(SP.vorname,''),' ',COALESCE(SP.nachname,''))) END AS player_name";
        $from = self::table($websoccer, 'merchandising_collection') . ' AS C INNER JOIN ' . self::table($websoccer, 'merchandising_product') . ' AS P ON P.id = C.product_id LEFT JOIN ' . self::table($websoccer, 'spieler') . ' AS SP ON SP.id = C.player_id';
        $where = 'C.team_id = %d';
        if ($saleOnly) {
            $where .= " AND C.status IN ('active','clearance')";
        }
        $where .= " ORDER BY FIELD(C.status,'development','ready','active','clearance','closed'), C.updated_date DESC, C.id DESC";
        $result = $db->querySelect($select, $from, $where, (int) $teamId);
        $rows = array();
        while ($row = $result->fetch_array()) {
            foreach (array('id','team_id','product_id','player_id','selling_price','stock','incoming_stock','reorder_point','development_started','development_ready','active_from','active_until','created_date','updated_date','purchase_price','sales_price','delivery_days','minimum_order','liquidation_percent') as $key) {
                $row[$key] = isset($row[$key]) ? (int) $row[$key] : 0;
            }
            $row['base_demand'] = (float) $row['base_demand'];
            $row['stadium_factor'] = (float) $row['stadium_factor'];
            $row['online_factor'] = (float) $row['online_factor'];
            $row['max_price_factor'] = (float) $row['max_price_factor'];
            $rows[] = $row;
        }
        $result->free();
        return $rows;
    }

    private static function getAvailableProducts(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $teamId, $staff) {
        $result = $db->querySelect('*', self::table($websoccer, 'merchandising_product'), "active = '1' ORDER BY category ASC, name ASC");
        $rows = array();
        while ($row = $result->fetch_array()) {
            self::translateProductRow($i18n, $row);
            $window = self::getProductWindow($websoccer, $db, $teamId, $row);
            $seasonKey = self::getSeasonKey($websoccer, $db, $teamId, $row, 0, $window);
            $row['available_now'] = $window['available'];
            $row['window_start'] = (int) $window['start'];
            $row['window_end'] = (int) $window['end'];
            $row['window_label'] = self::getWindowLabel($window);
            $row['already_created'] = self::collectionEditionExists($websoccer, $db, $teamId, (int) $row['id'], 0, $seasonKey);
            $row['forecast'] = self::estimateTemplateDemand($websoccer, $db, $teamId, $row, $staff);
            foreach (array('id','purchase_price','sales_price','development_cost','development_days','delivery_days','minimum_order','liquidation_percent') as $key) {
                $row[$key] = (int) $row[$key];
            }
            $rows[] = $row;
        }
        $result->free();
        return $rows;
    }

    private static function getOrders(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $teamId) {
        $select = "O.id, O.collection_id, O.quantity, O.unit_cost, O.total_cost, O.order_date, O.delivery_date, O.delivered_date, O.status, O.created_by, P.name AS product_name, CASE WHEN SP.kunstname IS NOT NULL AND SP.kunstname <> '' THEN SP.kunstname ELSE TRIM(CONCAT(COALESCE(SP.vorname,''),' ',COALESCE(SP.nachname,''))) END AS player_name";
        $from = self::table($websoccer, 'merchandising_order') . ' AS O INNER JOIN ' . self::table($websoccer, 'merchandising_collection') . ' AS C ON C.id = O.collection_id INNER JOIN ' . self::table($websoccer, 'merchandising_product') . ' AS P ON P.id = C.product_id LEFT JOIN ' . self::table($websoccer, 'spieler') . ' AS SP ON SP.id = C.player_id';
        $result = $db->querySelect($select, $from, 'O.team_id = %d ORDER BY O.order_date DESC, O.id DESC', (int) $teamId, 30);
        $rows = array();
        while ($row = $result->fetch_array()) {
            if ($i18n->hasMessage($row['product_name'])) {
                $row['product_name'] = $i18n->getMessage($row['product_name']);
            }
            foreach (array('id','collection_id','quantity','unit_cost','total_cost','order_date','delivery_date','delivered_date') as $key) {
                $row[$key] = (int) $row[$key];
            }
            $row['display_name'] = trim($row['product_name'] . (!empty($row['player_name']) ? ' - ' . $row['player_name'] : ''));
            $rows[] = $row;
        }
        $result->free();
        return $rows;
    }

    private static function getCampaigns(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $teamId) {
        $select = "C.id, C.collection_id, C.cost, C.demand_bonus, C.start_date, C.end_date, C.status, C.created_by, T.name AS campaign_name, P.name AS product_name, CASE WHEN SP.kunstname IS NOT NULL AND SP.kunstname <> '' THEN SP.kunstname ELSE TRIM(CONCAT(COALESCE(SP.vorname,''),' ',COALESCE(SP.nachname,''))) END AS player_name";
        $from = self::table($websoccer, 'merchandising_campaign') . ' AS C INNER JOIN ' . self::table($websoccer, 'merchandising_campaign_type') . ' AS T ON T.id = C.campaign_type_id LEFT JOIN ' . self::table($websoccer, 'merchandising_collection') . ' AS MC ON MC.id = C.collection_id LEFT JOIN ' . self::table($websoccer, 'merchandising_product') . ' AS P ON P.id = MC.product_id LEFT JOIN ' . self::table($websoccer, 'spieler') . ' AS SP ON SP.id = MC.player_id';
        $result = $db->querySelect($select, $from, 'C.team_id = %d ORDER BY C.start_date DESC, C.id DESC', (int) $teamId, 30);
        $rows = array();
        while ($row = $result->fetch_array()) {
            if (!empty($row['product_name']) && $i18n->hasMessage($row['product_name'])) {
                $row['product_name'] = $i18n->getMessage($row['product_name']);
            }
            foreach (array('id','collection_id','cost','demand_bonus','start_date','end_date') as $key) {
                $row[$key] = (int) $row[$key];
            }
            $row['target_name'] = !empty($row['product_name']) ? trim($row['product_name'] . (!empty($row['player_name']) ? ' - ' . $row['player_name'] : '')) : 'Gesamtes Sortiment';
            $rows[] = $row;
        }
        $result->free();
        return $rows;
    }

    private static function getCampaignTypes(WebSoccer $websoccer, DbConnection $db) {
        $result = $db->querySelect('*', self::table($websoccer, 'merchandising_campaign_type'), "active = '1' ORDER BY cost ASC, id ASC");
        $rows = array();
        while ($row = $result->fetch_array()) {
            foreach (array('id','cost','duration_days','demand_bonus') as $key) {
                $row[$key] = (int) $row[$key];
            }
            $rows[] = $row;
        }
        $result->free();
        return $rows;
    }

    private static function getProductStatistics(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $teamId) {
        $select = 'S.product_id, P.name AS product_name, SUM(S.units_sold) AS units_sold, SUM(S.demand_units) AS demand_units, SUM(S.missed_units) AS missed_units, SUM(S.revenue) AS revenue, SUM(S.costs) AS costs, SUM(S.profit) AS profit';
        $from = self::table($websoccer, 'merchandising_sales') . ' AS S INNER JOIN ' . self::table($websoccer, 'merchandising_product') . ' AS P ON P.id = S.product_id';
        $result = $db->querySelect($select, $from, 'S.team_id = %d GROUP BY S.product_id, P.name ORDER BY profit DESC, revenue DESC', (int) $teamId);
        $rows = array();
        while ($row = $result->fetch_array()) {
            if ($i18n->hasMessage($row['product_name'])) {
                $row['product_name'] = $i18n->getMessage($row['product_name']);
            }
            foreach (array('product_id','units_sold','demand_units','missed_units','revenue','costs','profit') as $key) {
                $row[$key] = (int) $row[$key];
            }
            $rows[] = $row;
        }
        $result->free();
        return $rows;
    }

    private static function getPlayerStatistics(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $select = "S.player_id, CASE WHEN SP.kunstname IS NOT NULL AND SP.kunstname <> '' THEN SP.kunstname ELSE TRIM(CONCAT(COALESCE(SP.vorname,''),' ',COALESCE(SP.nachname,''))) END AS player_name, SUM(S.units_sold) AS units_sold, SUM(S.revenue) AS revenue, SUM(S.profit) AS profit";
        $from = self::table($websoccer, 'merchandising_sales') . ' AS S INNER JOIN ' . self::table($websoccer, 'spieler') . ' AS SP ON SP.id = S.player_id';
        $result = $db->querySelect($select, $from, 'S.team_id = %d AND S.player_id IS NOT NULL GROUP BY S.player_id, player_name ORDER BY units_sold DESC, revenue DESC', (int) $teamId);
        $rows = array();
        while ($row = $result->fetch_array()) {
            foreach (array('player_id','units_sold','revenue','profit') as $key) {
                $row[$key] = (int) $row[$key];
            }
            $rows[] = $row;
        }
        $result->free();
        return $rows;
    }

    private static function getRecentSales(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $teamId) {
        $select = "S.id, S.match_id, S.collection_id, S.player_id, S.channel, S.demand_units, S.units_sold, S.missed_units, S.revenue, S.costs, S.profit, S.created_date, P.name AS product_name, CASE WHEN SP.kunstname IS NOT NULL AND SP.kunstname <> '' THEN SP.kunstname ELSE TRIM(CONCAT(COALESCE(SP.vorname,''),' ',COALESCE(SP.nachname,''))) END AS player_name";
        $from = self::table($websoccer, 'merchandising_sales') . ' AS S INNER JOIN ' . self::table($websoccer, 'merchandising_product') . ' AS P ON P.id = S.product_id LEFT JOIN ' . self::table($websoccer, 'spieler') . ' AS SP ON SP.id = S.player_id';
        $result = $db->querySelect($select, $from, 'S.team_id = %d ORDER BY S.created_date DESC, S.id DESC', (int) $teamId, 30);
        $rows = array();
        while ($row = $result->fetch_array()) {
            if ($i18n->hasMessage($row['product_name'])) {
                $row['product_name'] = $i18n->getMessage($row['product_name']);
            }
            foreach (array('id','match_id','collection_id','player_id','demand_units','units_sold','missed_units','revenue','costs','profit','created_date') as $key) {
                $row[$key] = (int) $row[$key];
            }
            $row['display_name'] = trim($row['product_name'] . (!empty($row['player_name']) ? ' - ' . $row['player_name'] : ''));
            $rows[] = $row;
        }
        $result->free();
        return $rows;
    }

    public static function getStarPlayers(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $select = "SP.id, SP.vorname, SP.nachname, SP.kunstname, SP.w_staerke, SP.sa_tore, SP.sa_assists, SP.sa_spiele, SP.note_schnitt, SP.personality, V.captain_id, CASE WHEN EXISTS (SELECT 1 FROM " . self::table($websoccer, 'nationalplayer') . " NP WHERE NP.player_id = SP.id) THEN 1 ELSE 0 END AS national_player";
        $from = self::table($websoccer, 'spieler') . ' AS SP INNER JOIN ' . self::table($websoccer, 'verein') . ' AS V ON V.id = SP.verein_id';
        $result = $db->querySelect($select, $from, "SP.verein_id = %d AND SP.status = '1' ORDER BY CAST(SP.w_staerke AS DECIMAL(6,2)) DESC", (int) $teamId);
        $players = array();
        $strengths = array();
        while ($row = $result->fetch_array()) {
            $row['id'] = (int) $row['id'];
            $row['strength'] = (float) $row['w_staerke'];
            $row['sa_tore'] = (int) $row['sa_tore'];
            $row['sa_assists'] = (int) $row['sa_assists'];
            $row['sa_spiele'] = (int) $row['sa_spiele'];
            $row['note_schnitt'] = (float) $row['note_schnitt'];
            $row['captain_id'] = (int) $row['captain_id'];
            $row['national_player'] = (int) $row['national_player'];
            $row['name'] = !empty($row['kunstname']) ? $row['kunstname'] : trim($row['vorname'] . ' ' . $row['nachname']);
            $players[] = $row;
            $strengths[] = $row['strength'];
        }
        $result->free();

        if (!$players) {
            return array();
        }
        $maxStrength = max($strengths);
        $minStrength = min($strengths);
        $threshold = self::configInt($websoccer, 'merchandising_star_threshold', 65, 40, 95);
        $maxStars = self::configInt($websoccer, 'merchandising_max_player_collections', 3, 1, 8);

        foreach ($players as &$player) {
            $strengthScore = $maxStrength > $minStrength ? (($player['strength'] - $minStrength) / ($maxStrength - $minStrength)) * 35 : 25;
            $performanceRaw = ($player['sa_tore'] * 3) + ($player['sa_assists'] * 2) + max(0, $player['sa_spiele']);
            $performanceScore = min(20, $performanceRaw * 0.8);
            if ($player['note_schnitt'] > 0 && $player['note_schnitt'] <= 2.5) {
                $performanceScore = min(20, $performanceScore + 3);
            }
            $appearanceScore = min(15, $player['sa_spiele'] * 1.2);
            $captainScore = $player['captain_id'] === $player['id'] ? 10 : 0;
            $nationalScore = $player['national_player'] ? 10 : 0;
            $personalityScore = 5;
            if ($player['personality'] === 'leader' || $player['personality'] === 'big_game_player') {
                $personalityScore = 10;
            } elseif ($player['personality'] === 'troublemaker') {
                $personalityScore = 1;
            } elseif ($player['personality'] === 'professional' || $player['personality'] === 'loyal') {
                $personalityScore = 7;
            }
            $player['star_score'] = (int) round(min(100, $strengthScore + $performanceScore + $appearanceScore + $captainScore + $nationalScore + $personalityScore));
            $player['eligible'] = $player['star_score'] >= $threshold;
        }
        unset($player);

        usort($players, function($a, $b) {
            if ($a['star_score'] === $b['star_score']) {
                return $a['id'] <=> $b['id'];
            }
            return $b['star_score'] <=> $a['star_score'];
        });

        $eligibleRank = 0;
        foreach ($players as &$player) {
            if ($player['eligible']) {
                $eligibleRank++;
                if ($eligibleRank > $maxStars) {
                    $player['eligible'] = false;
                }
            }
            $result = $db->querySelect(
                'id',
                self::table($websoccer, 'merchandising_collection'),
                "team_id = %d AND player_id = %d AND status <> 'closed'",
                array((int) $teamId, (int) $player['id']),
                1
            );
            $player['has_collection'] = (bool) $result->fetch_array();
            $result->free();
        }
        unset($player);
        return $players;
    }

    private static function isEligibleStarPlayer(WebSoccer $websoccer, DbConnection $db, $teamId, $playerId) {
        foreach (self::getStarPlayers($websoccer, $db, $teamId) as $player) {
            if ((int) $player['id'] === (int) $playerId) {
                return !empty($player['eligible']);
            }
        }
        return false;
    }

    private static function getProductWindow(WebSoccer $websoccer, DbConnection $db, $teamId, $product) {
        $now = $websoccer->getNowAsTimestamp();
        $year = (int) date('Y', $now);
        $type = isset($product['season_type']) ? $product['season_type'] : 'always';
        $start = 0;
        $end = 0;
        $available = true;

        if ($type === 'always') {
            return array('available' => true, 'start' => 0, 'end' => 0);
        }
        if ($type === 'season') {
            $dates = self::getActiveSeasonDates($websoccer, $db, $teamId);
            return array('available' => true, 'start' => $dates['start'], 'end' => $dates['end']);
        }
        if ($type === 'event') {
            $eventAvailable = self::isEventProductAvailable($websoccer, $db, $teamId, isset($product['event_type']) ? $product['event_type'] : '');
            return array('available' => $eventAvailable, 'start' => $now, 'end' => $eventAvailable ? $now + (30 * 86400) : 0);
        }
        if ($type === 'easter') {
            $easter = self::getEasterTimestamp($year);
            $start = $easter - (28 * 86400);
            $end = $easter + (14 * 86400);
        } else {
            $startText = !empty($product['season_start']) ? $product['season_start'] : ($type === 'summer' ? '06-01' : '01-01');
            $endText = !empty($product['season_end']) ? $product['season_end'] : ($type === 'summer' ? '08-31' : '12-31');
            $start = strtotime($year . '-' . $startText . ' 00:00:00');
            $end = strtotime($year . '-' . $endText . ' 23:59:59');
            if ($end < $start) {
                if ($now < $start) {
                    $start = strtotime(($year - 1) . '-' . $startText . ' 00:00:00');
                } else {
                    $end = strtotime(($year + 1) . '-' . $endText . ' 23:59:59');
                }
            }
        }

        $leadDays = max(0, (int) $product['development_days'] + (int) $product['delivery_days'] + 14);
        $available = $now >= ($start - ($leadDays * 86400)) && $now <= $end;
        return array('available' => $available, 'start' => $start, 'end' => $end);
    }

    private static function isEventProductAvailable(WebSoccer $websoccer, DbConnection $db, $teamId, $eventType) {
        $now = $websoccer->getNowAsTimestamp();
        if ($eventType === 'derby') {
            $from = self::table($websoccer, 'spiel') . ' AS M INNER JOIN ' . self::table($websoccer, 'rivalry') . ' AS R ON R.active = \'1\' AND ((R.team1_id = M.home_verein AND R.team2_id = M.gast_verein) OR (R.team2_id = M.home_verein AND R.team1_id = M.gast_verein))';
            $result = $db->querySelect('M.id', $from, "M.berechnet = '0' AND M.datum >= %d AND M.datum <= %d AND (M.home_verein = %d OR M.gast_verein = %d)", array($now, $now + (30 * 86400), (int) $teamId, (int) $teamId), 1);
            $row = $result->fetch_array();
            $result->free();
            return (bool) $row;
        }
        if ($eventType === 'cup_final') {
            $result = $db->querySelect('id', self::table($websoccer, 'spiel'), "berechnet = '0' AND spieltyp = 'Pokalspiel' AND LOWER(pokalrunde) LIKE '%%final%%' AND datum >= %d AND datum <= %d AND (home_verein = %d OR gast_verein = %d)", array($now, $now + (30 * 86400), (int) $teamId, (int) $teamId), 1);
            $row = $result->fetch_array();
            $result->free();
            return (bool) $row;
        }
        if ($eventType === 'promotion' || $eventType === 'champion') {
            $needle = $eventType === 'champion' ? '%meister%' : '%aufstieg%';
            $result = $db->querySelect('id', self::table($websoccer, 'titles_won'), "team_id = %d AND LOWER(competition) LIKE '%s'", array((int) $teamId, $needle), 1);
            $row = $result->fetch_array();
            $result->free();
            return (bool) $row;
        }
        return false;
    }

    private static function getActiveSeasonDates(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $team = self::getTeam($websoccer, $db, $teamId);
        $now = $websoccer->getNowAsTimestamp();
        if (!$team || (int) $team['liga_id'] < 1) {
            return array('start' => $now, 'end' => $now + (365 * 86400), 'season_id' => 0);
        }
        $result = $db->querySelect('id', self::table($websoccer, 'saison'), "liga_id = %d AND beendet = '0' ORDER BY id DESC", (int) $team['liga_id'], 1);
        $season = $result->fetch_array();
        $result->free();
        if (!$season) {
            return array('start' => $now, 'end' => $now + (365 * 86400), 'season_id' => 0);
        }
        $seasonId = (int) $season['id'];
        $result = $db->querySelect('MIN(datum) AS start_date, MAX(datum) AS end_date', self::table($websoccer, 'spiel'), 'saison_id = %d', $seasonId);
        $dates = $result->fetch_array();
        $result->free();
        return array(
            'start' => !empty($dates['start_date']) ? (int) $dates['start_date'] : $now,
            'end' => !empty($dates['end_date']) ? (int) $dates['end_date'] + (30 * 86400) : $now + (365 * 86400),
            'season_id' => $seasonId
        );
    }

    private static function getSeasonKey(WebSoccer $websoccer, DbConnection $db, $teamId, $product, $playerId, $window) {
        if ($product['season_type'] === 'always') {
            return 'permanent';
        }
        if ($product['season_type'] === 'season') {
            $dates = self::getActiveSeasonDates($websoccer, $db, $teamId);
            return 'season-' . (int) $dates['season_id'];
        }
        if ($product['season_type'] === 'event') {
            return 'event-' . $product['event_type'] . '-' . date('Ymd', $websoccer->getNowAsTimestamp());
        }
        return $product['season_type'] . '-' . date('Y', (int) $window['start'] > 0 ? (int) $window['start'] : $websoccer->getNowAsTimestamp());
    }

    private static function getWindowLabel($window) {
        if ((int) $window['start'] < 1 && (int) $window['end'] < 1) {
            return 'Ganzjaehrig';
        }
        if ((int) $window['end'] < 1) {
            return 'ab ' . date('d.m.Y', (int) $window['start']);
        }
        return date('d.m.Y', (int) $window['start']) . ' bis ' . date('d.m.Y', (int) $window['end']);
    }

    private static function estimateTemplateDemand(WebSoccer $websoccer, DbConnection $db, $teamId, $product, $staff) {
        $collection = $product;
        $collection['quality'] = self::QUALITY_STANDARD;
        $collection['selling_price'] = (int) $product['sales_price'];
        $collection['player_id'] = 0;
        $collection['status'] = 'active';
        return self::estimateDemand($websoccer, $db, $teamId, $collection, $staff);
    }

    private static function estimateDemand(WebSoccer $websoccer, DbConnection $db, $teamId, $collection, $staff) {
        $team = self::getTeam($websoccer, $db, $teamId);
        $audience = self::getOnlineAudience($websoccer, $db, $teamId) + (int) round(self::getAverageAttendance($websoccer, $db, $teamId) * 0.55);
        $expected = max(1, (int) round($audience * max(0.0001, (float) $collection['base_demand']) * self::getClubReachFactor($team) * self::getPriceDemandFactor($collection) * self::getQualityData($collection['quality'])['demand_factor'] * 3));
        $level = $staff ? (int) $staff['level'] : 0;
        $error = self::getForecastError($level);
        return array(
            'expected' => $expected,
            'min' => max(0, (int) floor($expected * (1 - ($error / 100)))),
            'max' => (int) ceil($expected * (1 + ($error / 100))),
            'error_percent' => $error
        );
    }

    private static function getForecastError($level) {
        $map = array(0 => 40, 1 => 30, 2 => 22, 3 => 15, 4 => 10, 5 => 6);
        $level = max(0, min(5, (int) $level));
        return $map[$level];
    }

    private static function getQualityData($quality) {
        if ($quality === self::QUALITY_SIMPLE) {
            return array('development_cost_factor' => 0.80, 'development_time_factor' => 0.80, 'unit_cost_factor' => 0.92, 'demand_factor' => 0.95);
        }
        if ($quality === self::QUALITY_PREMIUM) {
            return array('development_cost_factor' => 1.35, 'development_time_factor' => 1.25, 'unit_cost_factor' => 1.10, 'demand_factor' => 1.15);
        }
        return array('development_cost_factor' => 1.00, 'development_time_factor' => 1.00, 'unit_cost_factor' => 1.00, 'demand_factor' => 1.00);
    }

    private static function getUnitCost($collection) {
        $quality = isset($collection['quality']) ? $collection['quality'] : self::QUALITY_STANDARD;
        return max(1, (int) round((int) $collection['purchase_price'] * self::getQualityData($quality)['unit_cost_factor']));
    }

    private static function getPriceDemandFactor($collection) {
        $base = max(1, (int) $collection['sales_price']);
        $price = max(1, (int) $collection['selling_price']);
        $ratio = $price / $base;
        $factor = 1.45 - (0.45 * $ratio);
        return max(0.55, min(1.25, $factor));
    }

    private static function getSeasonDemandFactor(WebSoccer $websoccer, $collection) {
        if ((int) $collection['active_from'] < 1 || (int) $collection['active_until'] < 1) {
            return 1.00;
        }
        $now = $websoccer->getNowAsTimestamp();
        $start = (int) $collection['active_from'];
        $end = (int) $collection['active_until'];
        if ($now < $start) {
            return 0.35;
        }
        if ($now > $end) {
            return 0.45;
        }
        $duration = max(1, $end - $start);
        $progress = ($now - $start) / $duration;
        if ($progress < 0.20) {
            return 0.85;
        }
        if ($progress < 0.75) {
            return 1.20;
        }
        return 0.95;
    }

    private static function getPlayerDemandFactor(WebSoccer $websoccer, DbConnection $db, $teamId, $collection) {
        if ((int) $collection['player_id'] < 1) {
            return 1.00;
        }
        foreach (self::getStarPlayers($websoccer, $db, $teamId) as $player) {
            if ((int) $player['id'] === (int) $collection['player_id']) {
                return max(0.70, min(1.60, 0.60 + ($player['star_score'] / 100)));
            }
        }
        // Former player: only clearance demand remains.
        return 0.55;
    }

    private static function getCampaignFactor(WebSoccer $websoccer, DbConnection $db, $teamId, $collectionId) {
        $now = $websoccer->getNowAsTimestamp();
        $result = $db->querySelect('id, demand_bonus', self::table($websoccer, 'merchandising_campaign'), "team_id = %d AND status = 'active' AND start_date <= %d AND end_date >= %d AND (collection_id IS NULL OR collection_id = %d) ORDER BY demand_bonus DESC", array((int) $teamId, $now, $now, (int) $collectionId), 1);
        $row = $result->fetch_array();
        $result->free();
        if (!$row) {
            return array('id' => 0, 'factor' => 1.00);
        }
        return array('id' => (int) $row['id'], 'factor' => 1 + (max(0, min(50, (int) $row['demand_bonus'])) / 100));
    }

    private static function isCollectionSaleActive(WebSoccer $websoccer, $collection) {
        if (!in_array($collection['status'], array('active', 'clearance'), true)) {
            return false;
        }
        $now = $websoccer->getNowAsTimestamp();
        if ((int) $collection['active_from'] > 0 && $now < (int) $collection['active_from'] && $collection['status'] !== 'clearance') {
            return false;
        }
        return true;
    }

    private static function getClubReachFactor($team) {
        if (!$team) {
            return 1.00;
        }
        $factor = 0.75;
        $factor += min(0.35, max(0, (int) $team['strength']) / 400);
        $factor += min(0.20, ((int) $team['meisterschaften'] + (int) $team['pokale']) * 0.02);
        if ((int) $team['superclub'] === 1) {
            $factor += 0.20;
        }
        if ((int) $team['division'] === 1) {
            $factor += 0.10;
        } elseif ((int) $team['division'] >= 3) {
            $factor -= 0.10;
        }
        return max(0.55, min(1.65, $factor));
    }

    private static function getOnlineAudience(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $attendance = self::getAverageAttendance($websoccer, $db, $teamId);
        $team = self::getTeam($websoccer, $db, $teamId);
        $base = max(500, (int) round($attendance * 0.65));
        if ($team && (int) $team['superclub'] === 1) {
            $base = (int) round($base * 1.35);
        }
        return $base;
    }

    private static function getAverageAttendance(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $recentTable = '(SELECT total_visitors FROM ' . self::table($websoccer, 'stadium_attendance_log') . ' WHERE team_id = ' . (int) $teamId . ' ORDER BY created_date DESC LIMIT 10) AS RecentAttendance';
        $result = $db->querySelect('AVG(total_visitors) AS avg_visitors', $recentTable, '1 = 1');
        $row = $result->fetch_array();
        $result->free();
        if ($row && (int) $row['avg_visitors'] > 0) {
            return (int) round($row['avg_visitors']);
        }
        $team = self::getTeam($websoccer, $db, $teamId);
        if ($team) {
            return max(1000, (int) $team['last_steh'] + (int) $team['last_sitz'] + (int) $team['last_haupt_steh'] + (int) $team['last_haupt_sitz'] + (int) $team['last_vip']);
        }
        return 5000;
    }

    private static function getMatchSpectators(WebSoccer $websoccer, DbConnection $db, $matchId) {
        $result = $db->querySelect('zuschauer', self::table($websoccer, 'spiel'), 'id = %d', (int) $matchId, 1);
        $row = $result->fetch_array();
        $result->free();
        return $row ? max(0, (int) $row['zuschauer']) : 0;
    }

    private static function getBuildingBonus(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $from = self::table($websoccer, 'buildings_of_team') . ' AS BT INNER JOIN ' . self::table($websoccer, 'stadiumbuilding') . ' AS B ON B.id = BT.building_id';
        $result = $db->querySelect('COALESCE(SUM(B.effect_merchandising),0) AS bonus', $from, 'BT.team_id = %d AND BT.construction_deadline < %d', array((int) $teamId, $websoccer->getNowAsTimestamp()));
        $row = $result->fetch_array();
        $result->free();
        return $row ? max(0, (int) $row['bonus']) : 0;
    }

    private static function getProduct(WebSoccer $websoccer, DbConnection $db, $productId) {
        $result = $db->querySelect('*', self::table($websoccer, 'merchandising_product'), 'id = %d', (int) $productId, 1);
        $row = $result->fetch_array();
        $result->free();
        return $row ? $row : array();
    }

    private static function getCollection(WebSoccer $websoccer, DbConnection $db, $teamId, $collectionId) {
        $select = 'C.*, P.name AS product_name, P.purchase_price, P.sales_price, P.base_demand, P.delivery_days, P.minimum_order, P.liquidation_percent, P.player_product, P.event_type, P.stadium_factor, P.online_factor, P.max_price_factor';
        $from = self::table($websoccer, 'merchandising_collection') . ' AS C INNER JOIN ' . self::table($websoccer, 'merchandising_product') . ' AS P ON P.id = C.product_id';
        $result = $db->querySelect($select, $from, 'C.id = %d AND C.team_id = %d', array((int) $collectionId, (int) $teamId), 1);
        $row = $result->fetch_array();
        $result->free();
        return $row ? $row : array();
    }

    private static function getCampaignType(WebSoccer $websoccer, DbConnection $db, $campaignTypeId) {
        $result = $db->querySelect('*', self::table($websoccer, 'merchandising_campaign_type'), 'id = %d', (int) $campaignTypeId, 1);
        $row = $result->fetch_array();
        $result->free();
        return $row ? $row : array();
    }

    private static function collectionEditionExists(WebSoccer $websoccer, DbConnection $db, $teamId, $productId, $playerId, $seasonKey) {
        $where = 'team_id = %d AND product_id = %d AND ' . ((int) $playerId > 0 ? 'player_id = %d' : 'player_id IS NULL') . " AND season_key = '%s' AND status <> 'closed'";
        $params = (int) $playerId > 0 ? array((int) $teamId, (int) $productId, (int) $playerId, $seasonKey) : array((int) $teamId, (int) $productId, $seasonKey);
        $result = $db->querySelect('id', self::table($websoccer, 'merchandising_collection'), $where, $params, 1);
        $row = $result->fetch_array();
        $result->free();
        return (bool) $row;
    }

    private static function countOpenCollections(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $result = $db->querySelect('COUNT(*) AS hits', self::table($websoccer, 'merchandising_collection'), "team_id = %d AND status <> 'closed'", (int) $teamId);
        $row = $result->fetch_array();
        $result->free();
        return $row ? (int) $row['hits'] : 0;
    }

    private static function saleExists(WebSoccer $websoccer, DbConnection $db, $matchId, $teamId, $collectionId, $channel) {
        $result = $db->querySelect('id', self::table($websoccer, 'merchandising_sales'), "match_id = %d AND team_id = %d AND collection_id = %d AND channel = '%s'", array((int) $matchId, (int) $teamId, (int) $collectionId, $channel), 1);
        $row = $result->fetch_array();
        $result->free();
        return (bool) $row;
    }

    private static function assertWithinBudget(WebSoccer $websoccer, DbConnection $db, $teamId, $amount) {
        $settings = self::getSettings($websoccer, $db, $teamId);
        if ((int) $settings['budget_limit'] > 0 && (int) $amount > (int) $settings['budget_limit']) {
            throw new Exception('merchandising_error_budget_limit');
        }
    }

    private static function assertWithinStockLimit(WebSoccer $websoccer, DbConnection $db, $teamId, $newValue) {
        $settings = self::getSettings($websoccer, $db, $teamId);
        if ((int) $settings['max_stock_value'] < 1) {
            return;
        }
        $summary = self::getSummary($websoccer, $db, $teamId);
        if ((int) $summary['stock_value'] + (int) $summary['incoming_value'] + (int) $newValue > (int) $settings['max_stock_value']) {
            throw new Exception('merchandising_error_stock_limit');
        }
    }

    private static function assertAffordable($team, $amount) {
        if ((int) $amount > max(0, (int) $team['finanz_budget'])) {
            throw new Exception('merchandising_error_not_affordable');
        }
    }

    private static function assertHumanTeam(WebSoccer $websoccer, DbConnection $db, $teamId, $userId, $staffCall = false) {
        $team = self::getTeam($websoccer, $db, $teamId);
        if (!$team || (int) $team['user_id'] < 1) {
            throw new Exception('merchandising_error_human_team_required');
        }
        if (!$staffCall && (int) $userId > 0 && (int) $team['user_id'] !== (int) $userId) {
            throw new Exception('merchandising_error_not_your_team');
        }
        return $team;
    }

    private static function getTeam(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $select = "V.id, V.user_id, V.liga_id, V.finanz_budget, V.fan_mood, V.strength, V.meisterschaften, V.pokale, V.superclub, V.nationalteam, V.last_steh, V.last_sitz, V.last_haupt_steh, V.last_haupt_sitz, V.last_vip, L.division, COALESCE(U.fanbeliebtheit,50) AS fan_popularity";
        $from = self::table($websoccer, 'verein') . ' AS V LEFT JOIN ' . self::table($websoccer, 'user') . ' AS U ON U.id = V.user_id LEFT JOIN ' . self::table($websoccer, 'liga') . ' AS L ON L.id = V.liga_id';
        $result = $db->querySelect($select, $from, 'V.id = %d', (int) $teamId, 1);
        $row = $result->fetch_array();
        $result->free();
        if (!$row) {
            return array();
        }
        foreach (array('id','user_id','liga_id','finanz_budget','fan_mood','strength','meisterschaften','pokale','superclub','nationalteam','last_steh','last_sitz','last_haupt_steh','last_haupt_sitz','last_vip','division','fan_popularity') as $key) {
            $row[$key] = isset($row[$key]) ? (int) $row[$key] : 0;
        }
        return $row;
    }

    private static function logStock(WebSoccer $websoccer, DbConnection $db, $teamId, $collectionId, $type, $quantity, $stockAfter, $referenceId, $note) {
        $db->queryInsert(array(
            'team_id' => (int) $teamId,
            'collection_id' => (int) $collectionId,
            'movement_type' => $type,
            'quantity' => (int) $quantity,
            'stock_after' => (int) $stockAfter,
            'reference_id' => (int) $referenceId,
            'created_date' => $websoccer->getNowAsTimestamp(),
            'note' => $note
        ), self::table($websoccer, 'merchandising_stock_log'));
    }

    private static function notify(WebSoccer $websoccer, DbConnection $db, $team, $messageKey, $data, $type) {
        if (!$team || (int) $team['user_id'] < 1 || !class_exists('NotificationsDataService')) {
            return;
        }
        NotificationsDataService::createNotification($websoccer, $db, (int) $team['user_id'], $messageKey, $data, 'merchandising_' . $type, 'merchandising', '', (int) $team['id']);
    }

    private static function translateProductRow(I18n $i18n, &$row) {
        if (isset($row['name']) && $i18n->hasMessage($row['name'])) {
            $row['name'] = $i18n->getMessage($row['name']);
        }
        if (isset($row['description']) && !empty($row['description']) && $i18n->hasMessage($row['description'])) {
            $row['description'] = $i18n->getMessage($row['description']);
        }
        if (isset($row['product_name']) && $i18n->hasMessage($row['product_name'])) {
            $row['product_name'] = $i18n->getMessage($row['product_name']);
        }
        if (isset($row['product_description']) && !empty($row['product_description']) && $i18n->hasMessage($row['product_description'])) {
            $row['product_description'] = $i18n->getMessage($row['product_description']);
        }
    }

    private static function displayProductName($row) {
        $name = isset($row['product_name']) ? $row['product_name'] : (isset($row['name']) ? $row['name'] : 'Produkt');
        if (!empty($row['player_name'])) {
            $name .= ' - ' . trim($row['player_name']);
        }
        return $name;
    }

    private static function deterministicFactor($seed, $min, $max) {
        $hash = sprintf('%u', crc32($seed));
        $fraction = ((int) ($hash % 10000)) / 10000;
        return $min + (($max - $min) * $fraction);
    }

    private static function configInt(WebSoccer $websoccer, $key, $default, $min, $max) {
        $value = $websoccer->getConfig($key);
        if ($value === null || $value === '') {
            $value = $default;
        }
        return max($min, min($max, (int) $value));
    }

    private static function getEasterTimestamp($year) {
        // Gregorian computus without requiring PHP's optional calendar extension.
        $year = (int) $year;
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;
        return mktime(12, 0, 0, $month, $day, $year);
    }

    private static function table(WebSoccer $websoccer, $name) {
        return $websoccer->getConfig('db_prefix') . '_' . $name;
    }
}
?>
