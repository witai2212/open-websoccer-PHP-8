<?php
/******************************************************

  Club Partnerships 2.0 service.

******************************************************/

class ClubPartnershipDataService {
    const STATUS_PENDING = 'pending';
    const STATUS_ACTIVE = 'active';
    const STATUS_REJECTED = 'rejected';
    const STATUS_STOPPED = 'stopped';
    const STATUS_SUSPENDED = 'suspended';

    const REASON_SAME_DIVISION = 'same_division';
    const REASON_MANAGER_LEFT = 'manager_left';
    const REASON_ADMIN = 'admin';
    const REASON_MANAGER_STOPPED = 'manager_stopped';

    const FIRST_OPTION_STATUS_OPEN = 'open';
    const FIRST_OPTION_STATUS_USED = 'used';
    const FIRST_OPTION_STATUS_DECLINED = 'declined';
    const FIRST_OPTION_STATUS_EXPIRED = 'expired';
    const FIRST_OPTION_STATUS_CANCELLED = 'cancelled';

    const FIRST_OPTION_SOURCE_PROFESSIONAL_TRANSFER = 'professional_transfer';
    const FIRST_OPTION_SOURCE_PROFESSIONAL_PROMOTION = 'professional_promotion';
    const FIRST_OPTION_SOURCE_YOUTH_TRANSFER = 'youth_transfer';

    private static $_schemaReady = false;

    public static function ensureSchema(WebSoccer $websoccer, DbConnection $db) {
        if (self::$_schemaReady) {
            return;
        }

        $db->executeQuery("CREATE TABLE IF NOT EXISTS " . self::table($websoccer) . " (
            id INT(10) NOT NULL AUTO_INCREMENT,
            parent_team_id INT(10) NOT NULL,
            partner_team_id INT(10) NOT NULL,
            parent_user_id INT(10) NOT NULL DEFAULT 0,
            partner_user_id INT(10) NOT NULL DEFAULT 0,
            requested_by_user_id INT(10) NOT NULL DEFAULT 0,
            requested_by_team_id INT(10) NOT NULL DEFAULT 0,
            pending_user_id INT(10) NOT NULL DEFAULT 0,
            status ENUM('pending','active','rejected','stopped','suspended') NOT NULL DEFAULT 'pending',
            shared_scouting ENUM('1','0') NOT NULL DEFAULT '1',
            preferred_loans ENUM('1','0') NOT NULL DEFAULT '1',
            first_option ENUM('1','0') NOT NULL DEFAULT '1',
            development_bonus_percent TINYINT(3) NOT NULL DEFAULT 5,
            suspended_reason VARCHAR(128) DEFAULT NULL,
            created_date INT(11) NOT NULL DEFAULT 0,
            updated_date INT(11) NOT NULL DEFAULT 0,
            confirmed_date INT(11) NOT NULL DEFAULT 0,
            stopped_date INT(11) NOT NULL DEFAULT 0,
            context_data TEXT DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_club_partnership_parent_status (parent_team_id, status),
            KEY idx_club_partnership_partner_status (partner_team_id, status),
            KEY idx_club_partnership_pending_user (pending_user_id, status),
            KEY idx_club_partnership_status_date (status, updated_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        $db->executeQuery("CREATE TABLE IF NOT EXISTS " . self::logTable($websoccer) . " (
            id INT(10) NOT NULL AUTO_INCREMENT,
            partnership_id INT(10) NOT NULL DEFAULT 0,
            event_key VARCHAR(64) NOT NULL,
            message VARCHAR(255) DEFAULT NULL,
            created_date INT(11) NOT NULL DEFAULT 0,
            user_id INT(10) NOT NULL DEFAULT 0,
            admin_id INT(10) NOT NULL DEFAULT 0,
            context_data TEXT DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_club_partnership_log_partnership (partnership_id, created_date),
            KEY idx_club_partnership_log_event (event_key, created_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        $db->executeQuery("CREATE TABLE IF NOT EXISTS " . self::firstOptionTable($websoccer) . " (
            id INT(10) NOT NULL AUTO_INCREMENT,
            partnership_id INT(10) NOT NULL DEFAULT 0,
            parent_team_id INT(10) NOT NULL DEFAULT 0,
            partner_team_id INT(10) NOT NULL DEFAULT 0,
            parent_user_id INT(10) NOT NULL DEFAULT 0,
            player_id INT(10) NOT NULL DEFAULT 0,
            youth_player_id INT(10) NOT NULL DEFAULT 0,
            player_name VARCHAR(128) NOT NULL DEFAULT '',
            source VARCHAR(32) NOT NULL DEFAULT '',
            status ENUM('open','used','declined','expired','cancelled') NOT NULL DEFAULT 'open',
            created_date INT(11) NOT NULL DEFAULT 0,
            expires_date INT(11) NOT NULL DEFAULT 0,
            decision_date INT(11) NOT NULL DEFAULT 0,
            used_by_team_id INT(10) NOT NULL DEFAULT 0,
            context_data TEXT DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_club_partnership_first_option_parent (parent_team_id, status, expires_date),
            KEY idx_club_partnership_first_option_partner (partner_team_id, status, expires_date),
            KEY idx_club_partnership_first_option_player (player_id, status),
            KEY idx_club_partnership_first_option_youth (youth_player_id, status),
            KEY idx_club_partnership_first_option_partnership (partnership_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        self::importLegacyRelationships($websoccer, $db);
        self::$_schemaReady = true;
    }

    public static function getPageData(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $userId, $teamId) {
        self::ensureSchema($websoccer, $db);
        self::resolveAutomaticStopsAndConflicts($websoccer, $db, $i18n);

        $team = self::getTeam($websoccer, $db, $teamId);
        $hasOpen = ($team) ? self::hasOpenPartnershipForTeam($websoccer, $db, $teamId) : true;

        return array(
            'team' => $team,
            'active_partnership' => ($team) ? self::getOpenPartnershipForTeam($websoccer, $db, $teamId, true) : array(),
            'incoming_requests' => self::getRequestsForUser($websoccer, $db, $userId),
            'outgoing_requests' => ($team) ? self::getOutgoingRequestsForTeam($websoccer, $db, $teamId) : array(),
            'candidate_parents' => (!$hasOpen && $team) ? self::getCandidateParents($websoccer, $db, $teamId) : array(),
            'candidate_partners' => (!$hasOpen && $team) ? self::getCandidatePartners($websoccer, $db, $teamId) : array(),
            'recent_logs' => self::getRecentLogsForTeam($websoccer, $db, $teamId),
            'first_options' => self::getFirstOptionsForTeam($websoccer, $db, $teamId, 20),
            'has_open_partnership' => $hasOpen,
            'score_gap' => self::getScoreGap($websoccer),
            'development_bonus_percent' => self::getDevelopmentBonusPercent($websoccer)
        );
    }

    public static function createRequest(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $userId, $currentTeamId, $parentTeamId, $partnerTeamId) {
        self::ensureSchema($websoccer, $db);
        self::resolveAutomaticStopsAndConflicts($websoccer, $db, $i18n);

        $currentTeam = self::getTeam($websoccer, $db, $currentTeamId);
        $parent = self::getTeam($websoccer, $db, $parentTeamId);
        $partner = self::getTeam($websoccer, $db, $partnerTeamId);

        if (!$currentTeam || !$parent || !$partner) {
            throw new Exception(self::message($i18n, 'clubpartnership_error_club_missing'));
        }

        if ((int) $currentTeam['user_id'] !== (int) $userId) {
            throw new Exception(self::message($i18n, 'clubpartnership_error_not_manager'));
        }

        if ((int) $parentTeamId === (int) $partnerTeamId) {
            throw new Exception(self::message($i18n, 'clubpartnership_error_same_club'));
        }

        if ((int) $currentTeamId !== (int) $parentTeamId && (int) $currentTeamId !== (int) $partnerTeamId) {
            throw new Exception(self::message($i18n, 'clubpartnership_error_not_involved'));
        }

        self::validatePair($websoccer, $db, $i18n, $parent, $partner);

        $parentUserId = (int) $parent['user_id'];
        $partnerUserId = (int) $partner['user_id'];
        if ($parentUserId <= 0 && $partnerUserId <= 0) {
            throw new Exception(self::message($i18n, 'clubpartnership_error_cpu_cpu'));
        }

        $pendingUserId = 0;
        $status = self::STATUS_ACTIVE;
        if ((int) $parentTeamId === (int) $currentTeamId) {
            if ($partnerUserId > 0 && $partnerUserId !== (int) $userId) {
                $status = self::STATUS_PENDING;
                $pendingUserId = $partnerUserId;
            }
        } else {
            if ($parentUserId > 0 && $parentUserId !== (int) $userId) {
                $status = self::STATUS_PENDING;
                $pendingUserId = $parentUserId;
            }
        }

        $now = $websoccer->getNowAsTimestamp();
        $columns = array(
            'parent_team_id' => (int) $parentTeamId,
            'partner_team_id' => (int) $partnerTeamId,
            'parent_user_id' => $parentUserId,
            'partner_user_id' => $partnerUserId,
            'requested_by_user_id' => (int) $userId,
            'requested_by_team_id' => (int) $currentTeamId,
            'pending_user_id' => $pendingUserId,
            'status' => $status,
            'shared_scouting' => '1',
            'preferred_loans' => '1',
            'first_option' => '1',
            'development_bonus_percent' => self::getDevelopmentBonusPercent($websoccer),
            'suspended_reason' => '',
            'created_date' => $now,
            'updated_date' => $now,
            'confirmed_date' => ($status === self::STATUS_ACTIVE) ? $now : 0,
            'stopped_date' => 0,
            'context_data' => self::json(array('parent_score' => self::calculateTeamScore($parent), 'partner_score' => self::calculateTeamScore($partner)))
        );

        $db->queryInsert($columns, self::table($websoccer));
        $partnershipId = (int) $db->getLastInsertedId();

        if ($status === self::STATUS_ACTIVE) {
            self::syncLegacyRelationship($websoccer, $db, $partnershipId, self::STATUS_ACTIVE, '');
            self::createAnnouncementNews($websoccer, $db, $i18n, $partnershipId);
            self::notifyBothSides($websoccer, $db, $partnershipId, 'clubpartnership_notification_created', 'clubpartnership_created');
            self::log($websoccer, $db, $partnershipId, 'created', self::message($i18n, 'clubpartnership_log_created'), $userId, 0);
        } else {
            self::notifyUser($websoccer, $db, $pendingUserId, 'clubpartnership_notification_request', $partnershipId, 'clubpartnership_request', self::getNotificationData($websoccer, $db, $partnershipId));
            self::log($websoccer, $db, $partnershipId, 'requested', self::message($i18n, 'clubpartnership_log_requested'), $userId, 0);
        }

        return $partnershipId;
    }

    public static function acceptRequest(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $userId, $partnershipId) {
        self::ensureSchema($websoccer, $db);
        $partnership = self::getPartnershipById($websoccer, $db, $partnershipId);
        if (!$partnership || $partnership['status'] !== self::STATUS_PENDING || (int) $partnership['pending_user_id'] !== (int) $userId) {
            throw new Exception(self::message($i18n, 'clubpartnership_error_request_not_allowed'));
        }

        $parent = self::getTeam($websoccer, $db, $partnership['parent_team_id']);
        $partner = self::getTeam($websoccer, $db, $partnership['partner_team_id']);
        // The pending request itself is already an open partnership.
        // During acceptance we must ignore the current row, otherwise the
        // duplicate-check blocks the confirmation with "parent/partner busy".
        self::validatePair($websoccer, $db, $i18n, $parent, $partner, $partnershipId);

        $now = $websoccer->getNowAsTimestamp();
        $db->queryUpdate(array(
            'parent_user_id' => (int) $parent['user_id'],
            'partner_user_id' => (int) $partner['user_id'],
            'pending_user_id' => 0,
            'status' => self::STATUS_ACTIVE,
            'updated_date' => $now,
            'confirmed_date' => $now,
            'suspended_reason' => ''
        ), self::table($websoccer), 'id = %d', (int) $partnershipId);

        self::syncLegacyRelationship($websoccer, $db, $partnershipId, self::STATUS_ACTIVE, '');
        self::createAnnouncementNews($websoccer, $db, $i18n, $partnershipId);
        self::notifyBothSides($websoccer, $db, $partnershipId, 'clubpartnership_notification_created', 'clubpartnership_created');
        self::log($websoccer, $db, $partnershipId, 'accepted', self::message($i18n, 'clubpartnership_log_accepted'), $userId, 0);
    }

    public static function rejectRequest(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $userId, $partnershipId) {
        self::ensureSchema($websoccer, $db);
        $partnership = self::getPartnershipById($websoccer, $db, $partnershipId);
        if (!$partnership || $partnership['status'] !== self::STATUS_PENDING || (int) $partnership['pending_user_id'] !== (int) $userId) {
            throw new Exception(self::message($i18n, 'clubpartnership_error_request_not_allowed'));
        }

        $db->queryUpdate(array('status' => self::STATUS_REJECTED, 'updated_date' => $websoccer->getNowAsTimestamp(), 'pending_user_id' => 0), self::table($websoccer), 'id = %d', (int) $partnershipId);
        self::notifyRequester($websoccer, $db, $partnershipId, 'clubpartnership_notification_rejected', 'clubpartnership_rejected');
        self::log($websoccer, $db, $partnershipId, 'rejected', self::message($i18n, 'clubpartnership_log_rejected'), $userId, 0);
    }

    public static function stopByManager(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $userId, $partnershipId) {
        self::ensureSchema($websoccer, $db);
        $partnership = self::getPartnershipById($websoccer, $db, $partnershipId);
        if (!$partnership || !in_array($partnership['status'], array(self::STATUS_ACTIVE, self::STATUS_SUSPENDED, self::STATUS_PENDING), true)) {
            throw new Exception(self::message($i18n, 'clubpartnership_error_missing'));
        }

        $parent = self::getTeam($websoccer, $db, $partnership['parent_team_id']);
        $partner = self::getTeam($websoccer, $db, $partnership['partner_team_id']);
        if ((!$parent || (int) $parent['user_id'] !== (int) $userId) && (!$partner || (int) $partner['user_id'] !== (int) $userId)) {
            throw new Exception(self::message($i18n, 'clubpartnership_error_not_manager'));
        }

        self::setStatus($websoccer, $db, $partnershipId, self::STATUS_STOPPED, self::REASON_MANAGER_STOPPED);
        self::notifyBothSides($websoccer, $db, $partnershipId, 'clubpartnership_notification_stopped', 'clubpartnership_stopped');
        self::log($websoccer, $db, $partnershipId, 'stopped', self::message($i18n, 'clubpartnership_log_stopped'), $userId, 0);
    }

    public static function adminSetStatus(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $adminId, $partnershipId, $status) {
        self::ensureSchema($websoccer, $db);
        $status = (string) $status;
        if (!in_array($status, array(self::STATUS_ACTIVE, self::STATUS_SUSPENDED, self::STATUS_STOPPED), true)) {
            throw new Exception('Invalid status');
        }

        if ($status === self::STATUS_ACTIVE) {
            $partnership = self::getPartnershipById($websoccer, $db, $partnershipId);
            $parent = self::getTeam($websoccer, $db, $partnership['parent_team_id']);
            $partner = self::getTeam($websoccer, $db, $partnership['partner_team_id']);
            self::validatePair($websoccer, $db, $i18n, $parent, $partner, $partnershipId);
        }

        $reason = ($status === self::STATUS_SUSPENDED) ? self::REASON_ADMIN : '';
        self::setStatus($websoccer, $db, $partnershipId, $status, $reason, $adminId);
        self::log($websoccer, $db, $partnershipId, 'admin_' . $status, 'Admin: ' . $status, 0, $adminId);
    }

    public static function adminDelete(WebSoccer $websoccer, DbConnection $db, $partnershipId) {
        self::ensureSchema($websoccer, $db);
        self::clearLegacyRelationship($websoccer, $db, $partnershipId);
        $db->queryDelete(self::table($websoccer), 'id = %d', (int) $partnershipId);
    }

    public static function getAdminPageData(WebSoccer $websoccer, DbConnection $db, I18n $i18n) {
        self::ensureSchema($websoccer, $db);
        $actions = self::resolveAutomaticStopsAndConflicts($websoccer, $db, $i18n);
        return array(
            'actions' => $actions,
            'open_partnerships' => self::getAdminPartnerships($websoccer, $db, array(self::STATUS_ACTIVE, self::STATUS_PENDING, self::STATUS_SUSPENDED)),
            'recent_closed' => self::getAdminPartnerships($websoccer, $db, array(self::STATUS_STOPPED, self::STATUS_REJECTED), 30),
            'logs' => self::getAdminLogs($websoccer, $db, 80)
        );
    }

    public static function resolveAutomaticStopsAndConflicts(WebSoccer $websoccer, DbConnection $db, I18n $i18n = null) {
        self::ensureSchema($websoccer, $db);
        $rows = self::getAdminPartnerships($websoccer, $db, array(self::STATUS_ACTIVE, self::STATUS_PENDING, self::STATUS_SUSPENDED), 500);
        $actions = array();

        foreach ($rows as $row) {
            $status = $row['status'];
            $mustStop = false;
            if ((int) $row['parent_user_id'] > 0 && (int) $row['current_parent_user_id'] !== (int) $row['parent_user_id']) {
                $mustStop = true;
            }
            if ((int) $row['partner_user_id'] > 0 && (int) $row['current_partner_user_id'] !== (int) $row['partner_user_id']) {
                $mustStop = true;
            }

            if ($mustStop) {
                self::setStatus($websoccer, $db, (int) $row['id'], self::STATUS_STOPPED, self::REASON_MANAGER_LEFT);
                self::log($websoccer, $db, (int) $row['id'], 'auto_stopped_manager_left', 'Automatisch beendet: Managerwechsel', 0, 0);
                $actions[] = array('action' => 'stopped', 'partnership_id' => (int) $row['id'], 'reason' => self::REASON_MANAGER_LEFT, 'parent_name' => $row['parent_name'], 'partner_name' => $row['partner_name']);
                continue;
            }

            $conflict = self::hasDivisionConflictRows($row);
            if ($status === self::STATUS_ACTIVE && $conflict) {
                self::setStatus($websoccer, $db, (int) $row['id'], self::STATUS_SUSPENDED, self::REASON_SAME_DIVISION);
                self::log($websoccer, $db, (int) $row['id'], 'auto_suspended_same_division', 'Automatisch pausiert: Ligakonflikt', 0, 0);
                $actions[] = array('action' => 'suspended', 'partnership_id' => (int) $row['id'], 'reason' => self::REASON_SAME_DIVISION, 'parent_name' => $row['parent_name'], 'partner_name' => $row['partner_name']);
            } elseif ($status === self::STATUS_SUSPENDED && $row['suspended_reason'] === self::REASON_SAME_DIVISION && !$conflict) {
                self::setStatus($websoccer, $db, (int) $row['id'], self::STATUS_ACTIVE, '');
                self::log($websoccer, $db, (int) $row['id'], 'auto_reactivated', 'Automatisch reaktiviert', 0, 0);
                $actions[] = array('action' => 'reactivated', 'partnership_id' => (int) $row['id'], 'reason' => '', 'parent_name' => $row['parent_name'], 'partner_name' => $row['partner_name']);
            }
        }

        return $actions;
    }

    public static function getActivePartnershipBetween(WebSoccer $websoccer, DbConnection $db, $parentTeamId, $partnerTeamId) {
        self::ensureSchema($websoccer, $db);
        $result = $db->querySelect('*', self::table($websoccer), "parent_team_id = %d AND partner_team_id = %d AND status = 'active'", array((int) $parentTeamId, (int) $partnerTeamId), 1);
        $row = $result->fetch_array();
        $result->free();
        return $row ? $row : array();
    }

    public static function getLoanDevelopmentBonusPercent(WebSoccer $websoccer, DbConnection $db, $lenderTeamId, $borrowerTeamId) {
        $row = self::getActivePartnershipBetween($websoccer, $db, $lenderTeamId, $borrowerTeamId);
        if ($row && $row['development_bonus_percent'] > 0) {
            return (int) $row['development_bonus_percent'];
        }
        return 0;
    }

    public static function getSharedScoutingBonus(WebSoccer $websoccer, DbConnection $db, $teamId) {
        self::ensureSchema($websoccer, $db);
        $result = $db->querySelect('shared_scouting', self::table($websoccer), "partner_team_id = %d AND status = 'active' AND shared_scouting = '1'", (int) $teamId, 1);
        $row = $result->fetch_array();
        $result->free();
        return $row ? self::getSharedScoutingPercent($websoccer) : 0;
    }

    public static function notifyFirstOptionProfessional(WebSoccer $websoccer, DbConnection $db, $teamId, $playerId, $playerName, $source) {
        self::ensureSchema($websoccer, $db);
        $partnership = self::getActivePartnershipForPartner($websoccer, $db, $teamId);
        if (!$partnership || $partnership['first_option'] !== '1') {
            return;
        }
        $parent = self::getTeam($websoccer, $db, $partnership['parent_team_id']);
        if (!$parent || (int) $parent['user_id'] <= 0) {
            return;
        }

        $sourceKey = self::FIRST_OPTION_SOURCE_PROFESSIONAL_TRANSFER;
        if (stripos((string) $source, 'Jugend') !== false || stripos((string) $source, 'youth') !== false || stripos((string) $source, 'Nachwuchs') !== false) {
            $sourceKey = self::FIRST_OPTION_SOURCE_PROFESSIONAL_PROMOTION;
        }

        $firstOptionId = self::createOrRefreshFirstOption(
            $websoccer,
            $db,
            $partnership,
            (int) $playerId,
            0,
            (string) $playerName,
            $sourceKey,
            array('source_label' => (string) $source)
        );

        NotificationsDataService::createNotification(
            $websoccer,
            $db,
            (int) $parent['user_id'],
            'clubpartnership_notification_first_option_player',
            array('player' => $playerName, 'team' => $partnership['partner_name'], 'source' => $source, 'expires' => self::formatDateTime($websoccer, self::getFirstOptionExpiry($websoccer))),
            'clubpartnership_first_option',
            'clubpartnerships',
            '',
            (int) $partnership['parent_team_id']
        );
        self::log($websoccer, $db, (int) $partnership['id'], 'first_option_player', 'Erstzugriff reserviert: ' . $playerName, 0, 0);
    }

    public static function notifyFirstOptionYouth(WebSoccer $websoccer, DbConnection $db, $teamId, $youthPlayerId, $playerName) {
        self::ensureSchema($websoccer, $db);
        $partnership = self::getActivePartnershipForPartner($websoccer, $db, $teamId);
        if (!$partnership || $partnership['first_option'] !== '1') {
            return;
        }
        $parent = self::getTeam($websoccer, $db, $partnership['parent_team_id']);
        if (!$parent || (int) $parent['user_id'] <= 0) {
            return;
        }

        self::createOrRefreshFirstOption(
            $websoccer,
            $db,
            $partnership,
            0,
            (int) $youthPlayerId,
            (string) $playerName,
            self::FIRST_OPTION_SOURCE_YOUTH_TRANSFER,
            array()
        );

        NotificationsDataService::createNotification(
            $websoccer,
            $db,
            (int) $parent['user_id'],
            'clubpartnership_notification_first_option_youth',
            array('player' => $playerName, 'team' => $partnership['partner_name'], 'expires' => self::formatDateTime($websoccer, self::getFirstOptionExpiry($websoccer))),
            'clubpartnership_first_option',
            'clubpartnerships',
            '',
            (int) $partnership['parent_team_id']
        );
        self::log($websoccer, $db, (int) $partnership['id'], 'first_option_youth', 'Erstzugriff Jugend reserviert: ' . $playerName, 0, 0);
    }

    public static function assertProfessionalTransferAllowed(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $playerId, $buyerTeamId) {
        self::ensureSchema($websoccer, $db);
        self::expireFirstOptions($websoccer, $db);
        $option = self::getOpenFirstOptionForProfessional($websoccer, $db, $playerId);
        if (!$option) {
            return;
        }
        if ((int) $option['parent_team_id'] === (int) $buyerTeamId) {
            return;
        }
        throw new Exception(self::message($i18n, 'clubpartnership_first_option_blocked', array($option['player_name'], $option['parent_name'], self::formatDateTime($websoccer, (int) $option['expires_date']))));
    }

    public static function assertYouthTransferAllowed(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $youthPlayerId, $buyerTeamId) {
        self::ensureSchema($websoccer, $db);
        self::expireFirstOptions($websoccer, $db);
        $option = self::getOpenFirstOptionForYouth($websoccer, $db, $youthPlayerId);
        if (!$option) {
            return;
        }
        if ((int) $option['parent_team_id'] === (int) $buyerTeamId) {
            return;
        }
        throw new Exception(self::message($i18n, 'clubpartnership_first_option_blocked', array($option['player_name'], $option['parent_name'], self::formatDateTime($websoccer, (int) $option['expires_date']))));
    }

    public static function markProfessionalFirstOptionUsed(WebSoccer $websoccer, DbConnection $db, $playerId, $buyerTeamId) {
        self::ensureSchema($websoccer, $db);
        $option = self::getOpenFirstOptionForProfessional($websoccer, $db, $playerId);
        if ($option && (int) $option['parent_team_id'] === (int) $buyerTeamId) {
            self::setFirstOptionStatus($websoccer, $db, (int) $option['id'], self::FIRST_OPTION_STATUS_USED, (int) $buyerTeamId);
            self::log($websoccer, $db, (int) $option['partnership_id'], 'first_option_used', 'Erstzugriff genutzt: ' . $option['player_name'], 0, 0);
        }
    }

    public static function markYouthFirstOptionUsed(WebSoccer $websoccer, DbConnection $db, $youthPlayerId, $buyerTeamId) {
        self::ensureSchema($websoccer, $db);
        $option = self::getOpenFirstOptionForYouth($websoccer, $db, $youthPlayerId);
        if ($option && (int) $option['parent_team_id'] === (int) $buyerTeamId) {
            self::setFirstOptionStatus($websoccer, $db, (int) $option['id'], self::FIRST_OPTION_STATUS_USED, (int) $buyerTeamId);
            self::log($websoccer, $db, (int) $option['partnership_id'], 'first_option_used', 'Erstzugriff Jugend genutzt: ' . $option['player_name'], 0, 0);
        }
    }

    public static function cancelProfessionalFirstOptions(WebSoccer $websoccer, DbConnection $db, $playerId) {
        self::ensureSchema($websoccer, $db);
        self::cancelFirstOptions($websoccer, $db, (int) $playerId, 0);
    }

    public static function cancelYouthFirstOptions(WebSoccer $websoccer, DbConnection $db, $youthPlayerId) {
        self::ensureSchema($websoccer, $db);
        self::cancelFirstOptions($websoccer, $db, 0, (int) $youthPlayerId);
    }

    public static function declineFirstOption(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $userId, $firstOptionId) {
        self::ensureSchema($websoccer, $db);
        self::expireFirstOptions($websoccer, $db);
        $option = self::getFirstOptionById($websoccer, $db, $firstOptionId);
        if (!$option || $option['status'] !== self::FIRST_OPTION_STATUS_OPEN || (int) $option['parent_user_id'] !== (int) $userId) {
            throw new Exception(self::message($i18n, 'clubpartnership_first_option_error_not_allowed'));
        }
        self::setFirstOptionStatus($websoccer, $db, (int) $firstOptionId, self::FIRST_OPTION_STATUS_DECLINED, (int) $option['parent_team_id']);
        self::log($websoccer, $db, (int) $option['partnership_id'], 'first_option_declined', 'Erstzugriff verzichtet: ' . $option['player_name'], (int) $userId, 0);
    }

    public static function getProfessionalFirstOptionLock(WebSoccer $websoccer, DbConnection $db, $playerId, $teamId) {
        self::ensureSchema($websoccer, $db);
        self::expireFirstOptions($websoccer, $db);
        $option = self::getOpenFirstOptionForProfessional($websoccer, $db, $playerId);
        if (!$option) {
            return array();
        }
        $option['can_use'] = ((int) $option['parent_team_id'] === (int) $teamId) ? '1' : '0';
        $option['expires_label'] = self::formatDateTime($websoccer, (int) $option['expires_date']);
        return $option;
    }

    public static function getYouthFirstOptionLock(WebSoccer $websoccer, DbConnection $db, $youthPlayerId, $teamId) {
        self::ensureSchema($websoccer, $db);
        self::expireFirstOptions($websoccer, $db);
        $option = self::getOpenFirstOptionForYouth($websoccer, $db, $youthPlayerId);
        if (!$option) {
            return array();
        }
        $option['can_use'] = ((int) $option['parent_team_id'] === (int) $teamId) ? '1' : '0';
        $option['expires_label'] = self::formatDateTime($websoccer, (int) $option['expires_date']);
        return $option;
    }

    private static function createOrRefreshFirstOption(WebSoccer $websoccer, DbConnection $db, $partnership, $playerId, $youthPlayerId, $playerName, $source, $context) {
        self::expireFirstOptions($websoccer, $db);
        $now = $websoccer->getNowAsTimestamp();
        $expires = self::getFirstOptionExpiry($websoccer);
        $where = "partnership_id = %d AND status = 'open'";
        $params = array((int) $partnership['id']);
        if ((int) $playerId > 0) {
            $where .= " AND player_id = %d";
            $params[] = (int) $playerId;
        } else {
            $where .= " AND youth_player_id = %d";
            $params[] = (int) $youthPlayerId;
        }

        $result = $db->querySelect('id', self::firstOptionTable($websoccer), $where, $params, 1);
        $existing = $result->fetch_array();
        $result->free();
        if ($existing) {
            $db->queryUpdate(array(
                'player_name' => (string) $playerName,
                'source' => (string) $source,
                'expires_date' => $expires,
                'context_data' => self::json($context)
            ), self::firstOptionTable($websoccer), 'id = %d', (int) $existing['id']);
            return (int) $existing['id'];
        }

        $db->queryInsert(array(
            'partnership_id' => (int) $partnership['id'],
            'parent_team_id' => (int) $partnership['parent_team_id'],
            'partner_team_id' => (int) $partnership['partner_team_id'],
            'parent_user_id' => (int) $partnership['parent_user_id'],
            'player_id' => (int) $playerId,
            'youth_player_id' => (int) $youthPlayerId,
            'player_name' => (string) $playerName,
            'source' => (string) $source,
            'status' => self::FIRST_OPTION_STATUS_OPEN,
            'created_date' => $now,
            'expires_date' => $expires,
            'decision_date' => 0,
            'used_by_team_id' => 0,
            'context_data' => self::json($context)
        ), self::firstOptionTable($websoccer));
        return (int) $db->getLastInsertedId();
    }

    private static function getOpenFirstOptionForProfessional(WebSoccer $websoccer, DbConnection $db, $playerId) {
        if ((int) $playerId <= 0) {
            return array();
        }
        $sql = self::baseFirstOptionSql($websoccer->getConfig('db_prefix')) . " WHERE FO.player_id = " . (int) $playerId . " AND FO.status = 'open' AND FO.expires_date >= " . (int) $websoccer->getNowAsTimestamp() . " ORDER BY FO.expires_date DESC LIMIT 1";
        $rows = self::fetchFirstOptionRows($db, $sql);
        return isset($rows[0]) ? $rows[0] : array();
    }

    private static function getOpenFirstOptionForYouth(WebSoccer $websoccer, DbConnection $db, $youthPlayerId) {
        if ((int) $youthPlayerId <= 0) {
            return array();
        }
        $sql = self::baseFirstOptionSql($websoccer->getConfig('db_prefix')) . " WHERE FO.youth_player_id = " . (int) $youthPlayerId . " AND FO.status = 'open' AND FO.expires_date >= " . (int) $websoccer->getNowAsTimestamp() . " ORDER BY FO.expires_date DESC LIMIT 1";
        $rows = self::fetchFirstOptionRows($db, $sql);
        return isset($rows[0]) ? $rows[0] : array();
    }

    private static function getFirstOptionById(WebSoccer $websoccer, DbConnection $db, $firstOptionId) {
        $sql = self::baseFirstOptionSql($websoccer->getConfig('db_prefix')) . " WHERE FO.id = " . (int) $firstOptionId . " LIMIT 1";
        $rows = self::fetchFirstOptionRows($db, $sql);
        return isset($rows[0]) ? $rows[0] : array();
    }

    private static function getFirstOptionsForTeam(WebSoccer $websoccer, DbConnection $db, $teamId, $limit) {
        if ((int) $teamId <= 0) {
            return array();
        }
        self::expireFirstOptions($websoccer, $db);
        $sql = self::baseFirstOptionSql($websoccer->getConfig('db_prefix')) . " WHERE (FO.parent_team_id = " . (int) $teamId . " OR FO.partner_team_id = " . (int) $teamId . ") ORDER BY FIELD(FO.status,'open','used','declined','expired','cancelled'), FO.created_date DESC LIMIT " . (int) $limit;
        $rows = self::fetchFirstOptionRows($db, $sql);
        foreach ($rows as $idx => $row) {
            $rows[$idx]['expires_label'] = self::formatDateTime($websoccer, (int) $row['expires_date']);
            $rows[$idx]['created_label'] = self::formatDateTime($websoccer, (int) $row['created_date']);
            $rows[$idx]['is_parent_team'] = ((int) $row['parent_team_id'] === (int) $teamId) ? '1' : '0';
        }
        return $rows;
    }

    private static function baseFirstOptionSql($prefix) {
        return "SELECT FO.*, P.name AS parent_name, C.name AS partner_name, CP.status AS partnership_status
                FROM " . $prefix . "_club_partnership_first_option AS FO
                LEFT JOIN " . $prefix . "_club_partnership AS CP ON CP.id = FO.partnership_id
                LEFT JOIN " . $prefix . "_verein AS P ON P.id = FO.parent_team_id
                LEFT JOIN " . $prefix . "_verein AS C ON C.id = FO.partner_team_id";
    }

    private static function fetchFirstOptionRows(DbConnection $db, $sql) {
        $result = $db->executeQuery($sql);
        $rows = array();
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $result->free();
        return $rows;
    }

    private static function setFirstOptionStatus(WebSoccer $websoccer, DbConnection $db, $firstOptionId, $status, $usedByTeamId = 0) {
        $db->queryUpdate(array(
            'status' => (string) $status,
            'decision_date' => $websoccer->getNowAsTimestamp(),
            'used_by_team_id' => (int) $usedByTeamId
        ), self::firstOptionTable($websoccer), 'id = %d', (int) $firstOptionId);
    }

    private static function cancelFirstOptions(WebSoccer $websoccer, DbConnection $db, $playerId, $youthPlayerId) {
        $where = "status = 'open'";
        $parameters = array();
        if ((int) $playerId > 0) {
            $where .= " AND player_id = %d";
            $parameters[] = (int) $playerId;
        } elseif ((int) $youthPlayerId > 0) {
            $where .= " AND youth_player_id = %d";
            $parameters[] = (int) $youthPlayerId;
        } else {
            return;
        }
        $db->queryUpdate(array(
            'status' => self::FIRST_OPTION_STATUS_CANCELLED,
            'decision_date' => $websoccer->getNowAsTimestamp()
        ), self::firstOptionTable($websoccer), $where, $parameters);
    }

    private static function expireFirstOptions(WebSoccer $websoccer, DbConnection $db) {
        $db->executeQuery("UPDATE " . self::firstOptionTable($websoccer) . " SET status = 'expired', decision_date = " . (int) $websoccer->getNowAsTimestamp() . " WHERE status = 'open' AND expires_date < " . (int) $websoccer->getNowAsTimestamp());
    }

    private static function getFirstOptionExpiry(WebSoccer $websoccer) {
        return $websoccer->getNowAsTimestamp() + 24 * 3600 * max(1, self::getFirstOptionDays($websoccer));
    }

    private static function formatDateTime(WebSoccer $websoccer, $timestamp) {
        if (method_exists($websoccer, 'getFormattedDatetime')) {
            return $websoccer->getFormattedDatetime((int) $timestamp);
        }
        return date('d.m.Y H:i', (int) $timestamp);
    }

    private static function validatePair(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $parent, $partner, $ignorePartnershipId = 0) {
        if (!$parent || !$partner) {
            throw new Exception(self::message($i18n, 'clubpartnership_error_club_missing'));
        }
        if ((int) $parent['id'] === (int) $partner['id']) {
            throw new Exception(self::message($i18n, 'clubpartnership_error_same_club'));
        }
        if ($parent['nationalteam'] === '1' || $partner['nationalteam'] === '1') {
            throw new Exception(self::message($i18n, 'clubpartnership_error_nationalteam'));
        }
        if (self::hasDivisionConflict($parent, $partner)) {
            throw new Exception(self::message($i18n, 'clubpartnership_error_same_division'));
        }
        $parentScore = self::calculateTeamScore($parent);
        $partnerScore = self::calculateTeamScore($partner);
        if ($parentScore < ($partnerScore + self::getScoreGap($websoccer))) {
            throw new Exception(self::message($i18n, 'clubpartnership_error_parent_not_bigger'));
        }
        if (self::hasOpenPartnershipForTeam($websoccer, $db, (int) $parent['id'], $ignorePartnershipId)) {
            throw new Exception(self::message($i18n, 'clubpartnership_error_parent_busy'));
        }
        if (self::hasOpenPartnershipForTeam($websoccer, $db, (int) $partner['id'], $ignorePartnershipId)) {
            throw new Exception(self::message($i18n, 'clubpartnership_error_partner_busy'));
        }
    }

    private static function getCandidateParents(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $team = self::getTeam($websoccer, $db, $teamId);
        if (!$team) {
            return array();
        }
        $score = self::calculateTeamScore($team);
        $gap = self::getScoreGap($websoccer);
        $candidates = array();
        foreach (self::getCandidateTeams($websoccer, $db, $teamId) as $candidate) {
            $candidate['club_score'] = self::calculateTeamScore($candidate);
            if ($candidate['club_score'] >= $score + $gap && !self::hasDivisionConflict($candidate, $team)) {
                $candidates[] = $candidate;
            }
        }
        usort($candidates, array('ClubPartnershipDataService', 'sortByScoreDesc'));
        return array_slice($candidates, 0, 20);
    }

    private static function getCandidatePartners(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $team = self::getTeam($websoccer, $db, $teamId);
        if (!$team) {
            return array();
        }
        $score = self::calculateTeamScore($team);
        $gap = self::getScoreGap($websoccer);
        $candidates = array();
        foreach (self::getCandidateTeams($websoccer, $db, $teamId) as $candidate) {
            $candidate['club_score'] = self::calculateTeamScore($candidate);
            if ($candidate['club_score'] + $gap <= $score && !self::hasDivisionConflict($team, $candidate)) {
                $candidates[] = $candidate;
            }
        }
        usort($candidates, array('ClubPartnershipDataService', 'sortByScoreDesc'));
        return array_slice($candidates, 0, 20);
    }

    public static function sortByScoreDesc($a, $b) {
        if ($a['club_score'] == $b['club_score']) {
            return strcmp($a['name'], $b['name']);
        }
        return ($a['club_score'] > $b['club_score']) ? -1 : 1;
    }

    private static function getCandidateTeams(WebSoccer $websoccer, DbConnection $db, $currentTeamId) {
        $prefix = $websoccer->getConfig('db_prefix');
        $sql = "SELECT C.id, C.name, C.kurz, C.bild, C.liga_id, C.user_id, C.nationalteam, C.strength, C.finanz_budget, C.highscore, C.superclub, C.status, C.interimmanager,
                       L.name AS league_name, L.land AS league_country, L.division AS league_division,
                       U.nick AS manager_name
                FROM " . $prefix . "_verein AS C
                LEFT JOIN " . $prefix . "_liga AS L ON L.id = C.liga_id
                LEFT JOIN " . $prefix . "_user AS U ON U.id = C.user_id
                WHERE C.status = '1' AND C.nationalteam != '1' AND C.id <> " . (int) $currentTeamId . "
                ORDER BY C.strength DESC, C.name ASC
                LIMIT 250";
        $result = $db->executeQuery($sql);
        $rows = array();
        while ($row = $result->fetch_assoc()) {
            if (!self::hasOpenPartnershipForTeam($websoccer, $db, (int) $row['id'])) {
                $rows[] = $row;
            }
        }
        $result->free();
        return $rows;
    }

    private static function getTeam(WebSoccer $websoccer, DbConnection $db, $teamId) {
        if ((int) $teamId <= 0) {
            return null;
        }
        $prefix = $websoccer->getConfig('db_prefix');
        $sql = "SELECT C.id, C.name, C.kurz, C.bild, C.liga_id, C.user_id, C.nationalteam, C.strength, C.finanz_budget, C.highscore, C.superclub, C.status, C.interimmanager,
                       L.name AS league_name, L.land AS league_country, L.division AS league_division,
                       U.nick AS manager_name
                FROM " . $prefix . "_verein AS C
                LEFT JOIN " . $prefix . "_liga AS L ON L.id = C.liga_id
                LEFT JOIN " . $prefix . "_user AS U ON U.id = C.user_id
                WHERE C.id = " . (int) $teamId . " AND C.status = '1'
                LIMIT 1";
        $result = $db->executeQuery($sql);
        $row = $result->fetch_assoc();
        $result->free();
        if ($row) {
            $row['club_score'] = self::calculateTeamScore($row);
        }
        return $row ? $row : null;
    }

    private static function getPartnershipById(WebSoccer $websoccer, DbConnection $db, $partnershipId) {
        $result = $db->querySelect('*', self::table($websoccer), 'id = %d', (int) $partnershipId, 1);
        $row = $result->fetch_array();
        $result->free();
        return $row ? $row : array();
    }

    private static function getDecoratedPartnershipById(WebSoccer $websoccer, DbConnection $db, $partnershipId) {
        $prefix = $websoccer->getConfig('db_prefix');
        $sql = self::basePartnershipSql($prefix) . ' WHERE CP.id = ' . (int) $partnershipId . ' LIMIT 1';
        $rows = self::fetchPartnershipRows($db, $sql);
        return isset($rows[0]) ? $rows[0] : array();
    }

    private static function getOpenPartnershipForTeam(WebSoccer $websoccer, DbConnection $db, $teamId, $decorated = false) {
        $rows = self::getAdminPartnerships($websoccer, $db, array(self::STATUS_ACTIVE, self::STATUS_PENDING, self::STATUS_SUSPENDED), 1, (int) $teamId);
        return isset($rows[0]) ? $rows[0] : array();
    }

    private static function getActivePartnershipForPartner(WebSoccer $websoccer, DbConnection $db, $partnerTeamId) {
        $rows = self::getAdminPartnerships($websoccer, $db, array(self::STATUS_ACTIVE), 1, (int) $partnerTeamId, true);
        return isset($rows[0]) ? $rows[0] : array();
    }

    private static function hasOpenPartnershipForTeam(WebSoccer $websoccer, DbConnection $db, $teamId, $ignorePartnershipId = 0) {
        $where = "(parent_team_id = %d OR partner_team_id = %d) AND status IN ('pending','active','suspended')";
        $params = array((int) $teamId, (int) $teamId);
        if ((int) $ignorePartnershipId > 0) {
            $where .= ' AND id <> %d';
            $params[] = (int) $ignorePartnershipId;
        }
        $result = $db->querySelect('id', self::table($websoccer), $where, $params, 1);
        $row = $result->fetch_array();
        $result->free();
        return $row ? true : false;
    }

    private static function getRequestsForUser(WebSoccer $websoccer, DbConnection $db, $userId) {
        return self::getAdminPartnerships($websoccer, $db, array(self::STATUS_PENDING), 50, 0, false, (int) $userId);
    }

    private static function getOutgoingRequestsForTeam(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $prefix = $websoccer->getConfig('db_prefix');
        $sql = self::basePartnershipSql($prefix) . " WHERE CP.status = 'pending' AND CP.requested_by_team_id = " . (int) $teamId . " ORDER BY CP.created_date DESC";
        return self::fetchPartnershipRows($db, $sql);
    }

    private static function getAdminPartnerships(WebSoccer $websoccer, DbConnection $db, $statuses, $limit = 200, $teamId = 0, $partnerOnly = false, $pendingUserId = 0) {
        $prefix = $websoccer->getConfig('db_prefix');
        $statusList = array();
        foreach ($statuses as $status) {
            $statusList[] = "'" . $db->connection->real_escape_string($status) . "'";
        }
        $where = "CP.status IN (" . implode(',', $statusList) . ")";
        if ((int) $teamId > 0) {
            if ($partnerOnly) {
                $where .= ' AND CP.partner_team_id = ' . (int) $teamId;
            } else {
                $where .= ' AND (CP.parent_team_id = ' . (int) $teamId . ' OR CP.partner_team_id = ' . (int) $teamId . ')';
            }
        }
        if ((int) $pendingUserId > 0) {
            $where .= ' AND CP.pending_user_id = ' . (int) $pendingUserId;
        }
        $sql = self::basePartnershipSql($prefix) . " WHERE " . $where . " ORDER BY FIELD(CP.status,'pending','suspended','active','stopped','rejected'), CP.updated_date DESC, CP.created_date DESC LIMIT " . (int) $limit;
        return self::fetchPartnershipRows($db, $sql);
    }

    private static function basePartnershipSql($prefix) {
        return "SELECT CP.*,
                       P.name AS parent_name, P.bild AS parent_logo, P.user_id AS current_parent_user_id, P.liga_id AS parent_league_id, P.strength AS parent_strength, P.finanz_budget AS parent_budget, P.highscore AS parent_highscore, P.superclub AS parent_superclub,
                       PL.name AS parent_league_name, PL.land AS parent_country, PL.division AS parent_division,
                       PU.nick AS parent_manager_name,
                       C.name AS partner_name, C.bild AS partner_logo, C.user_id AS current_partner_user_id, C.liga_id AS partner_league_id, C.strength AS partner_strength, C.finanz_budget AS partner_budget, C.highscore AS partner_highscore, C.superclub AS partner_superclub,
                       CL.name AS partner_league_name, CL.land AS partner_country, CL.division AS partner_division,
                       CU.nick AS partner_manager_name,
                       RU.nick AS requested_by_manager_name
                FROM " . $prefix . "_club_partnership AS CP
                INNER JOIN " . $prefix . "_verein AS P ON P.id = CP.parent_team_id
                INNER JOIN " . $prefix . "_verein AS C ON C.id = CP.partner_team_id
                LEFT JOIN " . $prefix . "_liga AS PL ON PL.id = P.liga_id
                LEFT JOIN " . $prefix . "_liga AS CL ON CL.id = C.liga_id
                LEFT JOIN " . $prefix . "_user AS PU ON PU.id = P.user_id
                LEFT JOIN " . $prefix . "_user AS CU ON CU.id = C.user_id
                LEFT JOIN " . $prefix . "_user AS RU ON RU.id = CP.requested_by_user_id";
    }

    private static function fetchPartnershipRows(DbConnection $db, $sql) {
        $result = $db->executeQuery($sql);
        $rows = array();
        while ($row = $result->fetch_assoc()) {
            $row['parent_score'] = self::calculateTeamScore(array('highscore' => $row['parent_highscore'], 'strength' => $row['parent_strength'], 'finanz_budget' => $row['parent_budget'], 'league_division' => $row['parent_division'], 'superclub' => $row['parent_superclub']));
            $row['partner_score'] = self::calculateTeamScore(array('highscore' => $row['partner_highscore'], 'strength' => $row['partner_strength'], 'finanz_budget' => $row['partner_budget'], 'league_division' => $row['partner_division'], 'superclub' => $row['partner_superclub']));
            $row['has_division_conflict'] = self::hasDivisionConflictRows($row);
            $rows[] = $row;
        }
        $result->free();
        return $rows;
    }

    private static function setStatus(WebSoccer $websoccer, DbConnection $db, $partnershipId, $status, $reason = '', $adminId = 0) {
        $now = $websoccer->getNowAsTimestamp();
        $cols = array('status' => $status, 'suspended_reason' => (string) $reason, 'updated_date' => $now);
        if ($status === self::STATUS_STOPPED || $status === self::STATUS_REJECTED) {
            $cols['stopped_date'] = $now;
            $cols['pending_user_id'] = 0;
        }
        if ($status === self::STATUS_ACTIVE) {
            $cols['confirmed_date'] = $now;
            $cols['pending_user_id'] = 0;
        }
        $db->queryUpdate($cols, self::table($websoccer), 'id = %d', (int) $partnershipId);

        if ($status === self::STATUS_ACTIVE || $status === self::STATUS_SUSPENDED) {
            self::syncLegacyRelationship($websoccer, $db, $partnershipId, $status, $reason);
        } else {
            self::clearLegacyRelationship($websoccer, $db, $partnershipId);
        }
    }

    private static function syncLegacyRelationship(WebSoccer $websoccer, DbConnection $db, $partnershipId, $status, $reason) {
        $partnership = self::getPartnershipById($websoccer, $db, $partnershipId);
        if (!$partnership) {
            return;
        }
        $db->queryUpdate(array(
            'parent_club_id' => (int) $partnership['parent_team_id'],
            'parent_club_status' => ($status === self::STATUS_SUSPENDED ? 'suspended' : 'active'),
            'parent_club_suspended_reason' => (string) $reason
        ), $websoccer->getConfig('db_prefix') . '_verein', 'id = %d', (int) $partnership['partner_team_id']);
    }

    private static function clearLegacyRelationship(WebSoccer $websoccer, DbConnection $db, $partnershipId) {
        $partnership = self::getPartnershipById($websoccer, $db, $partnershipId);
        if (!$partnership) {
            return;
        }
        $prefix = $websoccer->getConfig('db_prefix');
        $db->executeQuery("UPDATE " . $prefix . "_verein SET parent_club_id = NULL, parent_club_status = 'active', parent_club_suspended_reason = NULL WHERE id = " . (int) $partnership['partner_team_id'] . " AND parent_club_id = " . (int) $partnership['parent_team_id']);
    }

    private static function getNotificationData(WebSoccer $websoccer, DbConnection $db, $partnershipId) {
        $row = self::getDecoratedPartnershipById($websoccer, $db, $partnershipId);
        if ($row) {
            return array('parent' => $row['parent_name'], 'partner' => $row['partner_name']);
        }
        return array('parent' => '', 'partner' => '');
    }

    private static function notifyUser(WebSoccer $websoccer, DbConnection $db, $userId, $messageKey, $partnershipId, $type, $data = null) {
        if ((int) $userId <= 0) {
            return;
        }
        if ($data === null) {
            $data = self::getNotificationData($websoccer, $db, $partnershipId);
        }
        NotificationsDataService::createNotification($websoccer, $db, (int) $userId, $messageKey, $data, $type, 'clubpartnerships', '', null);
    }

    private static function notifyBothSides(WebSoccer $websoccer, DbConnection $db, $partnershipId, $messageKey, $type) {
        $partnership = self::getPartnershipById($websoccer, $db, $partnershipId);
        $data = self::getNotificationData($websoccer, $db, $partnershipId);
        self::notifyUser($websoccer, $db, (int) $partnership['parent_user_id'], $messageKey, $partnershipId, $type, $data);
        if ((int) $partnership['partner_user_id'] !== (int) $partnership['parent_user_id']) {
            self::notifyUser($websoccer, $db, (int) $partnership['partner_user_id'], $messageKey, $partnershipId, $type, $data);
        }
    }

    private static function notifyRequester(WebSoccer $websoccer, DbConnection $db, $partnershipId, $messageKey, $type) {
        $partnership = self::getPartnershipById($websoccer, $db, $partnershipId);
        self::notifyUser($websoccer, $db, (int) $partnership['requested_by_user_id'], $messageKey, $partnershipId, $type, self::getNotificationData($websoccer, $db, $partnershipId));
    }

    private static function createAnnouncementNews(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $partnershipId) {
        $partnership = self::getDecoratedPartnershipById($websoccer, $db, $partnershipId);
        if (!$partnership || $partnership['status'] !== self::STATUS_ACTIVE) {
            return;
        }

        $parentManager = strlen((string) $partnership['parent_manager_name']) ? $partnership['parent_manager_name'] : self::message($i18n, 'clubpartnership_cpu_manager');
        $partnerManager = strlen((string) $partnership['partner_manager_name']) ? $partnership['partner_manager_name'] : self::message($i18n, 'clubpartnership_cpu_manager');
        $title = self::message($i18n, 'clubpartnership_news_title', array($partnership['parent_name'], $partnership['partner_name']));
        $parentNameHtml = htmlspecialchars($partnership['parent_name'], ENT_COMPAT, 'UTF-8');
        $partnerNameHtml = htmlspecialchars($partnership['partner_name'], ENT_COMPAT, 'UTF-8');
        $parentManagerHtml = htmlspecialchars($parentManager, ENT_COMPAT, 'UTF-8');
        $partnerManagerHtml = htmlspecialchars($partnerManager, ENT_COMPAT, 'UTF-8');

        $logoHtml = self::logoHtml($websoccer, $partnership['parent_logo'], $partnership['parent_name']) . ' ' . self::logoHtml($websoccer, $partnership['partner_logo'], $partnership['partner_name']);
        $message = $logoHtml . '<br>' . self::message($i18n, 'clubpartnership_news_message', array($parentNameHtml, $parentManagerHtml, $partnerNameHtml, $partnerManagerHtml));
        $message .= '<br><br>' . self::message($i18n, 'clubpartnership_news_effects', array((int) $partnership['development_bonus_percent']));

        $db->queryInsert(array(
            'datum' => $websoccer->getNowAsTimestamp(),
            'autor_id' => 1,
            'titel' => $title,
            'nachricht' => $message,
            'linktext1' => self::message($i18n, 'clubpartnership_news_link'),
            'linkurl1' => $websoccer->getInternalUrl('clubpartnerships'),
            'c_br' => '1',
            'c_links' => '1',
            'c_smilies' => '0',
            'status' => '1'
        ), $websoccer->getConfig('db_prefix') . '_news');
    }

    private static function logoHtml(WebSoccer $websoccer, $logo, $alt) {
        if (!strlen((string) $logo)) {
            return '';
        }
        return '<img src="' . htmlspecialchars($websoccer->getConfig('context_root') . '/uploads/club/' . $logo, ENT_COMPAT, 'UTF-8') . '" alt="' . htmlspecialchars($alt, ENT_COMPAT, 'UTF-8') . '" style="max-width:42px;max-height:42px;margin-right:8px;">';
    }

    private static function log(WebSoccer $websoccer, DbConnection $db, $partnershipId, $eventKey, $message, $userId = 0, $adminId = 0) {
        $db->queryInsert(array(
            'partnership_id' => (int) $partnershipId,
            'event_key' => (string) $eventKey,
            'message' => (string) $message,
            'created_date' => $websoccer->getNowAsTimestamp(),
            'user_id' => (int) $userId,
            'admin_id' => (int) $adminId,
            'context_data' => ''
        ), self::logTable($websoccer));
    }

    private static function getRecentLogsForTeam(WebSoccer $websoccer, DbConnection $db, $teamId) {
        if ((int) $teamId <= 0) {
            return array();
        }
        $prefix = $websoccer->getConfig('db_prefix');
        $sql = "SELECT L.*, CP.parent_team_id, CP.partner_team_id, P.name AS parent_name, C.name AS partner_name
                FROM " . $prefix . "_club_partnership_log AS L
                LEFT JOIN " . $prefix . "_club_partnership AS CP ON CP.id = L.partnership_id
                LEFT JOIN " . $prefix . "_verein AS P ON P.id = CP.parent_team_id
                LEFT JOIN " . $prefix . "_verein AS C ON C.id = CP.partner_team_id
                WHERE CP.parent_team_id = " . (int) $teamId . " OR CP.partner_team_id = " . (int) $teamId . "
                ORDER BY L.created_date DESC LIMIT 20";
        $result = $db->executeQuery($sql);
        $rows = array();
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $result->free();
        return $rows;
    }

    private static function getAdminLogs(WebSoccer $websoccer, DbConnection $db, $limit) {
        $prefix = $websoccer->getConfig('db_prefix');
        $sql = "SELECT L.*, P.name AS parent_name, C.name AS partner_name, U.nick AS user_name
                FROM " . $prefix . "_club_partnership_log AS L
                LEFT JOIN " . $prefix . "_club_partnership AS CP ON CP.id = L.partnership_id
                LEFT JOIN " . $prefix . "_verein AS P ON P.id = CP.parent_team_id
                LEFT JOIN " . $prefix . "_verein AS C ON C.id = CP.partner_team_id
                LEFT JOIN " . $prefix . "_user AS U ON U.id = L.user_id
                ORDER BY L.created_date DESC LIMIT " . (int) $limit;
        $result = $db->executeQuery($sql);
        $rows = array();
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $result->free();
        return $rows;
    }

    private static function importLegacyRelationships(WebSoccer $websoccer, DbConnection $db) {
        $result = $db->querySelect('COUNT(*) AS qty', self::table($websoccer), '1=1');
        $row = $result->fetch_array();
        $result->free();
        if ($row && (int) $row['qty'] > 0) {
            return;
        }

        $prefix = $websoccer->getConfig('db_prefix');
        $sql = "SELECT C.id AS partner_team_id, C.parent_club_id AS parent_team_id, C.user_id AS partner_user_id, C.parent_club_status, C.parent_club_suspended_reason, P.user_id AS parent_user_id
                FROM " . $prefix . "_verein AS C
                INNER JOIN " . $prefix . "_verein AS P ON P.id = C.parent_club_id
                WHERE C.parent_club_id IS NOT NULL AND C.parent_club_id > 0";
        $result = $db->executeQuery($sql);
        while ($legacy = $result->fetch_assoc()) {
            if ((int) $legacy['parent_user_id'] <= 0 && (int) $legacy['partner_user_id'] <= 0) {
                continue;
            }
            if (self::hasOpenPartnershipForTeam($websoccer, $db, (int) $legacy['parent_team_id']) || self::hasOpenPartnershipForTeam($websoccer, $db, (int) $legacy['partner_team_id'])) {
                continue;
            }
            $db->queryInsert(array(
                'parent_team_id' => (int) $legacy['parent_team_id'],
                'partner_team_id' => (int) $legacy['partner_team_id'],
                'parent_user_id' => (int) $legacy['parent_user_id'],
                'partner_user_id' => (int) $legacy['partner_user_id'],
                'requested_by_user_id' => 0,
                'requested_by_team_id' => 0,
                'pending_user_id' => 0,
                'status' => ($legacy['parent_club_status'] === 'suspended') ? self::STATUS_SUSPENDED : self::STATUS_ACTIVE,
                'shared_scouting' => '1',
                'preferred_loans' => '1',
                'first_option' => '1',
                'development_bonus_percent' => self::getDevelopmentBonusPercent($websoccer),
                'suspended_reason' => (string) $legacy['parent_club_suspended_reason'],
                'created_date' => $websoccer->getNowAsTimestamp(),
                'updated_date' => $websoccer->getNowAsTimestamp(),
                'confirmed_date' => $websoccer->getNowAsTimestamp(),
                'stopped_date' => 0,
                'context_data' => self::json(array('imported' => true))
            ), self::table($websoccer));
        }
        $result->free();
    }

    private static function hasDivisionConflict($parent, $partner) {
        if (!$parent || !$partner) {
            return false;
        }
        if (!empty($parent['liga_id']) && !empty($partner['liga_id']) && (int) $parent['liga_id'] === (int) $partner['liga_id']) {
            return true;
        }
        if (strlen((string) $parent['league_country']) && strlen((string) $partner['league_country'])
            && (string) $parent['league_country'] === (string) $partner['league_country']
            && strlen((string) $parent['league_division']) && strlen((string) $partner['league_division'])
            && (int) $parent['league_division'] === (int) $partner['league_division']) {
            return true;
        }
        return false;
    }

    private static function hasDivisionConflictRows($row) {
        if (!empty($row['parent_league_id']) && !empty($row['partner_league_id']) && (int) $row['parent_league_id'] === (int) $row['partner_league_id']) {
            return true;
        }
        if (strlen((string) $row['parent_country']) && strlen((string) $row['partner_country'])
            && (string) $row['parent_country'] === (string) $row['partner_country']
            && strlen((string) $row['parent_division']) && strlen((string) $row['partner_division'])
            && (int) $row['parent_division'] === (int) $row['partner_division']) {
            return true;
        }
        return false;
    }

    private static function calculateTeamScore($team) {
        $highscore = isset($team['highscore']) ? (int) $team['highscore'] : 0;
        $strength = isset($team['strength']) ? (int) $team['strength'] : 0;
        $budget = isset($team['finanz_budget']) ? (int) $team['finanz_budget'] : (isset($team['team_budget']) ? (int) $team['team_budget'] : 0);
        $division = isset($team['league_division']) ? (int) $team['league_division'] : 1;
        $superclub = isset($team['superclub']) ? (int) $team['superclub'] : 0;
        $leagueBonus = max(0, 35 - max(0, $division - 1) * 10);
        $budgetBonus = min(30, (int) floor($budget / 1000000));
        $score = $highscore + (int) floor($strength / 2) + $leagueBonus + $budgetBonus;
        if ($superclub > 0) {
            $score += 50;
        }
        return max(0, (int) $score);
    }

    private static function table(WebSoccer $websoccer) {
        return $websoccer->getConfig('db_prefix') . '_club_partnership';
    }

    private static function logTable(WebSoccer $websoccer) {
        return $websoccer->getConfig('db_prefix') . '_club_partnership_log';
    }

    private static function firstOptionTable(WebSoccer $websoccer) {
        return $websoccer->getConfig('db_prefix') . '_club_partnership_first_option';
    }

    private static function getScoreGap(WebSoccer $websoccer) {
        return self::configInt($websoccer, 'club_partnership_score_gap', 10);
    }

    private static function getDevelopmentBonusPercent(WebSoccer $websoccer) {
        return self::configInt($websoccer, 'club_partnership_development_bonus_percent', 5);
    }

    private static function getSharedScoutingPercent(WebSoccer $websoccer) {
        return self::configInt($websoccer, 'club_partnership_shared_scouting_bonus', 5);
    }

    private static function getFirstOptionDays(WebSoccer $websoccer) {
        return self::configInt($websoccer, 'club_partnership_first_option_days', 2);
    }

    private static function configInt(WebSoccer $websoccer, $key, $default) {
        try {
            $value = $websoccer->getConfig($key);
            if ($value === null || $value === '') {
                return (int) $default;
            }
            return (int) $value;
        } catch (Exception $e) {
            return (int) $default;
        }
    }

    private static function message(I18n $i18n, $key, $values = array()) {
        $message = $i18n->hasMessage($key) ? $i18n->getMessage($key) : $key;
        foreach ($values as $idx => $value) {
            $message = str_replace('{' . $idx . '}', $value, $message);
        }
        return $message;
    }

    private static function json($data) {
        return json_encode($data);
    }
}
?>
