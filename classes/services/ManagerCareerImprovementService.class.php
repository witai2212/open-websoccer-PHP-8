<?php
/******************************************************

  This file is part of OpenWebSoccer-Sim.

******************************************************/

/**
 * Extended manager career features: contracts, applications, country reputation,
 * sack risk and awards.
 */
class ManagerCareerImprovementService {

    const APPLICATION_OPEN = 'open';
    const APPLICATION_ACCEPTED = 'accepted';
    const APPLICATION_REJECTED = 'rejected';
    const APPLICATION_WITHDRAWN = 'withdrawn';
    const APPLICATION_EXPIRED = 'expired';

    const CONTRACT_ACTIVE = 'active';
    const CONTRACT_ENDED = 'ended';
    const CONTRACT_TERMINATED = 'terminated';
    const CONTRACT_SACKED = 'sacked';

    public static function isApplicationsEnabled(WebSoccer $websoccer) {
        return self::getConfigBoolean($websoccer, 'mgr_applications_enabled', TRUE);
    }

    public static function isAutoSackingEnabled(WebSoccer $websoccer) {
        return self::getConfigBoolean($websoccer, 'mgr_auto_sacking_enabled', TRUE);
    }

    public static function extendCareerPageData(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $data, $profileUserId, $viewerUserId) {
        if (!is_array($data)) {
            $data = array();
        }

        $isOwnProfile = ((int) $profileUserId === (int) $viewerUserId);
        $data['applications_enabled'] = self::isApplicationsEnabled($websoccer);
        $data['application_limit'] = self::getConfigNumber($websoccer, 'mgr_application_limit', 3);
        $data['application_open_count'] = 0;
        $data['application_search'] = '';
        $data['applications'] = array();
        $data['application_candidates'] = array();
        $data['country_reputation'] = array();
        $data['manager_contracts'] = array();
        $data['sack_risks'] = array();
        $data['sack_risks_by_team'] = array();
        $data['manager_awards'] = array();
        $data['manager_statistics'] = array('matches' => 0, 'wins' => 0, 'draws' => 0, 'losses' => 0, 'win_rate' => 0, 'titles' => 0);
        $data['mission_snapshot'] = array();

        try {
            $data['manager_statistics'] = self::getManagerStatistics($websoccer, $db, $profileUserId);
            $data['country_reputation'] = self::getCountryReputation($websoccer, $db, $profileUserId);
            $data['manager_contracts'] = self::getContractsOfUser($websoccer, $db, $profileUserId);
            $data['sack_risks'] = self::getSackRiskRowsForUser($websoccer, $db, $profileUserId);
            foreach ($data['sack_risks'] as $sackRiskRow) {
                $data['sack_risks_by_team'][(int) $sackRiskRow['team_id']] = $sackRiskRow;
            }
            $data['manager_awards'] = self::getAwardsOfUser($websoccer, $db, $profileUserId, 10);
            $data['mission_snapshot'] = self::getMissionSnapshot($websoccer, $db, $profileUserId);

            if ($isOwnProfile) {
                self::expireOldApplications($websoccer, $db);
                $applicationSearch = trim((string) $websoccer->getRequestParameter('applicationSearch'));
                $data['application_search'] = $applicationSearch;
                $data['applications'] = self::getApplicationsOfUser($websoccer, $db, $profileUserId, 20);
                $data['application_open_count'] = self::countOpenApplicationsOfUser($websoccer, $db, $profileUserId);
                $data['application_candidates'] = self::getApplicationCandidates($websoccer, $db, $profileUserId, $applicationSearch, strlen($applicationSearch) ? 80 : 30);
            }
        } catch (Exception $e) {
            $data['managercareer_extended_error'] = $e->getMessage();
        }

        return $data;
    }

    /**
     * Called from ManagerCareerDataService after a completed match marker has advanced.
     */
    public static function processMatchday(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $lastProcessedMatchId, $latestMatchId, $processApplications = TRUE) {
        $result = array(
            'applications_processed' => 0,
            'applications_accepted' => 0,
            'applications_rejected' => 0,
            'sack_checks' => 0,
            'sacked' => 0,
            'country_updates' => 0,
            'awards_created' => 0,
            'contracts_checked' => 0
        );

        if ((int) $latestMatchId < 1 || (int) $lastProcessedMatchId >= (int) $latestMatchId) {
            return $result;
        }

        try {
            $result['contracts_checked'] = self::ensureContractsForHumanClubs($websoccer, $db);
            $result['country_updates'] = self::processCountryReputationForMatches($websoccer, $db, (int) $lastProcessedMatchId, (int) $latestMatchId);
            if ($processApplications) {
                $applicationResult = self::processDueApplicationsNow($websoccer, $db, $i18n);
                $result['applications_processed'] = (int) $applicationResult['processed'];
                $result['applications_accepted'] = (int) $applicationResult['accepted'];
                $result['applications_rejected'] = (int) $applicationResult['rejected'];
            }
            $sackResult = self::processSackRisk($websoccer, $db, $i18n, (int) $latestMatchId);
            $result['sack_checks'] = (int) $sackResult['checked'];
            $result['sacked'] = (int) $sackResult['sacked'];
            $result['awards_created'] = self::processManagerAwards($websoccer, $db, $i18n);
        } catch (Exception $e) {
            $result['error'] = $e->getMessage();
        }

        return $result;
    }

    public static function handleManagerJoinedClub(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $userId, $teamId, $oldTeamId, $origin) {
        try {
            $team = self::getClubById($websoccer, $db, $teamId);
            if (!$team) {
                return;
            }

            self::ensureContract($websoccer, $db, $userId, $teamId, TRUE);
            if (isset($team['league_country']) && strlen($team['league_country'])) {
                self::increaseCountryReputation($websoccer, $db, $userId, $team['league_country'], 5, 'club_join');
            }

            // Once the manager changes club, manual applications from the old context should not remain open.
            self::closeOpenApplicationsAfterJobChange($websoccer, $db, $userId, $teamId);
        } catch (Exception $e) {
            return;
        }
    }

    public static function applyToClub(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $userId, $teamId) {
        if (!self::isApplicationsEnabled($websoccer)) {
            throw new Exception(self::message($i18n, 'managercareer_application_error_disabled'));
        }

        self::expireOldApplications($websoccer, $db);

        $openCount = self::countOpenApplicationsOfUser($websoccer, $db, $userId);
        $limit = self::getConfigNumber($websoccer, 'mgr_application_limit', 3);
        if ($openCount >= $limit) {
            throw new Exception(self::message($i18n, 'managercareer_application_error_limit', array('limit' => $limit)));
        }

        if (self::hasOpenApplicationForClub($websoccer, $db, $userId, $teamId)) {
            throw new Exception(self::message($i18n, 'managercareer_application_error_duplicate'));
        }

        $club = self::getApplicationClubById($websoccer, $db, $teamId);
        if (!$club) {
            throw new Exception(self::message($i18n, 'managercareer_application_error_club_not_available'));
        }

        $currentTeamId = self::getMainTeamIdOfUser($websoccer, $db, $userId);
        if ($currentTeamId > 0 && (int) $currentTeamId === (int) $teamId) {
            throw new Exception(self::message($i18n, 'managercareer_application_error_own_club'));
        }

        $managerScore = self::getManagerScore($websoccer, $db, $userId);
        $clubScore = self::getClubPrestigeFromRow($club);
        $chance = self::calculateApplicationChance($websoccer, $db, $userId, $club, $managerScore, $clubScore);
        $now = $websoccer->getNowAsTimestamp();
        $minDays = self::getConfigNumber($websoccer, 'mgr_application_decision_min_days', 3);
        $maxDays = self::getConfigNumber($websoccer, 'mgr_application_decision_max_days', 7);
        if ($maxDays < $minDays) {
            $maxDays = $minDays;
        }
        $decisionDays = rand((int) $minDays, (int) $maxDays);
        $expiryDays = self::getConfigNumber($websoccer, 'mgr_application_exp_days', 14);

        $db->queryInsert(array(
            'user_id' => (int) $userId,
            'source_team_id' => (int) $currentTeamId,
            'target_team_id' => (int) $teamId,
            'manager_score' => (int) $managerScore,
            'club_score' => (int) $clubScore,
            'application_score' => (int) round(($managerScore + $chance) / 2),
            'acceptance_chance' => (int) $chance,
            'status' => self::APPLICATION_OPEN,
            'created_date' => $now,
            'decision_date' => $now + ($decisionDays * 86400),
            'expires_date' => $now + ((int) $expiryDays * 86400),
            'answered_date' => 0,
            'offer_id' => 0,
            'context_data' => json_encode(array('decision_days' => $decisionDays))
        ), $websoccer->getConfig('db_prefix') . '_manager_application');

        self::createInboxMessage($websoccer, $db, $i18n, $userId,
            self::message($i18n, 'managercareer_application_message_sent_subject', array('team' => $club['team_name'])),
            self::message($i18n, 'managercareer_application_message_sent_body', array(
                'team' => $club['team_name'],
                'league' => $club['league_name'],
                'days' => $decisionDays,
                'chance' => $chance
            ))
        );

        return array('team_id' => $teamId, 'team_name' => $club['team_name'], 'decision_days' => $decisionDays, 'chance' => $chance);
    }

    public static function withdrawApplication(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $userId, $applicationId) {
        $application = self::getApplicationById($websoccer, $db, $applicationId);
        if (!$application || (int) $application['user_id'] !== (int) $userId || $application['status'] !== self::APPLICATION_OPEN) {
            throw new Exception(self::message($i18n, 'managercareer_application_error_invalid'));
        }

        $db->queryUpdate(array(
            'status' => self::APPLICATION_WITHDRAWN,
            'answered_date' => $websoccer->getNowAsTimestamp()
        ), $websoccer->getConfig('db_prefix') . '_manager_application', 'id = %d', (int) $applicationId);
        return TRUE;
    }

    public static function adminAcceptApplication(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $applicationId) {
        $application = self::getApplicationById($websoccer, $db, $applicationId);
        if (!$application || $application['status'] !== self::APPLICATION_OPEN) {
            throw new Exception(self::message($i18n, 'managercareer_application_error_invalid'));
        }
        return self::acceptApplication($websoccer, $db, $i18n, $application, TRUE);
    }

    public static function adminRejectApplication(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $applicationId) {
        $application = self::getApplicationById($websoccer, $db, $applicationId);
        if (!$application || $application['status'] !== self::APPLICATION_OPEN) {
            throw new Exception(self::message($i18n, 'managercareer_application_error_invalid'));
        }
        self::rejectApplication($websoccer, $db, $i18n, $application, TRUE);
        return TRUE;
    }

    public static function getAdminPageData(WebSoccer $websoccer, DbConnection $db, I18n $i18n) {
        $data = array(
            'open_applications' => array(),
            'recent_applications' => array(),
            'sack_risks' => array(),
            'recent_awards' => array(),
            'settings' => array()
        );

        try {
            self::processDueApplicationsNow($websoccer, $db, $i18n);
            self::expireOldApplications($websoccer, $db);
            $data['open_applications'] = self::getAdminApplications($websoccer, $db, TRUE, 50);
            $data['recent_applications'] = self::getAdminApplications($websoccer, $db, FALSE, 50);
            $data['sack_risks'] = self::getSackRiskRows($websoccer, $db, 80);
            $data['recent_awards'] = self::getRecentAwards($websoccer, $db, 50);
            $data['settings'] = array(
                'mgr_applications_enabled' => self::isApplicationsEnabled($websoccer),
                'mgr_application_limit' => self::getConfigNumber($websoccer, 'mgr_application_limit', 3),
                'mgr_application_decision_min_days' => self::getConfigNumber($websoccer, 'mgr_application_decision_min_days', 3),
                'mgr_application_decision_max_days' => self::getConfigNumber($websoccer, 'mgr_application_decision_max_days', 7),
                'mgr_application_exp_days' => self::getConfigNumber($websoccer, 'mgr_application_exp_days', 14),
                'mgr_auto_sacking_enabled' => self::isAutoSackingEnabled($websoccer),
                'mgr_contract_days' => self::getConfigNumber($websoccer, 'mgr_contract_days', 180)
            );
        } catch (Exception $e) {
            $data['error'] = $e->getMessage();
        }

        return $data;
    }

    public static function createOfferFromApplication(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $application) {
        $club = self::getClubById($websoccer, $db, (int) $application['target_team_id']);
        if (!$club) {
            return 0;
        }

        // Human club poaching is not allowed. If the club is no longer free/interim, reject the application.
        if ((int) $club['user_id'] > 0 && $club['interimmanager'] !== '1') {
            return 0;
        }

        // An accepted application creates an offer. Do not create a second offer
        // for a club that is already reserved by another open offer.
        if (class_exists('ManagerCareerDataService') && ManagerCareerDataService::isTeamReservedByOpenOffer($websoccer, $db, (int) $application['target_team_id'])) {
            return 0;
        }

        $now = $websoccer->getNowAsTimestamp();
        $validDays = self::getConfigNumber($websoccer, 'mgr_offer_exp_days', 21);
        $context = array('manual_application_id' => (int) $application['id']);

        $db->queryInsert(array(
            'user_id' => (int) $application['user_id'],
            'source_team_id' => (int) $application['source_team_id'],
            'target_team_id' => (int) $application['target_team_id'],
            'manager_score' => (int) $application['manager_score'],
            'club_score' => (int) $application['club_score'],
            'status' => 'open',
            'created_date' => $now,
            'expires_date' => $now + ((int) $validDays * 86400),
            'created_match_id' => self::getLatestComputedMatchId($websoccer, $db),
            'accepted_date' => 0,
            'declined_date' => 0,
            'context_data' => json_encode($context)
        ), $websoccer->getConfig('db_prefix') . '_manager_job_offer');

        $offerId = (int) $db->getLastInsertedId();

        self::createInboxMessage($websoccer, $db, $i18n, (int) $application['user_id'],
            self::message($i18n, 'managercareer_application_accepted_subject', array('team' => $club['team_name'])),
            self::message($i18n, 'managercareer_application_accepted_body', array(
                'team' => $club['team_name'],
                'league' => $club['league_name'],
                'expires' => date('d.m.Y', $now + ((int) $validDays * 86400)),
                'url' => $websoccer->getInternalUrl('managercareer')
            )),
            $club
        );

        NotificationsDataService::createNotification(
            $websoccer,
            $db,
            (int) $application['user_id'],
            'managercareer_application_notification_accepted',
            array('team' => $club['team_name']),
            'managercareer',
            'managercareer',
            '',
            (int) $application['target_team_id']
        );

        return $offerId;
    }

    public static function processDueApplicationsNow(WebSoccer $websoccer, DbConnection $db, I18n $i18n) {
        $result = array('processed' => 0, 'accepted' => 0, 'rejected' => 0);
        if (!self::isApplicationsEnabled($websoccer)) {
            return $result;
        }

        self::expireOldApplications($websoccer, $db);
        $prefix = $websoccer->getConfig('db_prefix');
        $now = $websoccer->getNowAsTimestamp();
        $rows = $db->querySelect('*', $prefix . '_manager_application', "status = 'open' AND decision_date > 0 AND decision_date <= %d ORDER BY decision_date ASC", (int) $now, 80);

        while ($application = $rows->fetch_array()) {
            $result['processed']++;
            $roll = rand(1, 100);
            if ($roll <= (int) $application['acceptance_chance']) {
                $offerId = self::acceptApplication($websoccer, $db, $i18n, $application, FALSE);
                if ($offerId > 0) {
                    $result['accepted']++;
                } else {
                    self::rejectApplication($websoccer, $db, $i18n, $application, FALSE);
                    $result['rejected']++;
                }
            } else {
                self::rejectApplication($websoccer, $db, $i18n, $application, FALSE);
                $result['rejected']++;
            }
        }
        $rows->free();

        return $result;
    }

    private static function acceptApplication(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $application, $adminDecision) {
        $offerId = self::createOfferFromApplication($websoccer, $db, $i18n, $application);
        if ($offerId < 1) {
            return 0;
        }

        $db->queryUpdate(array(
            'status' => self::APPLICATION_ACCEPTED,
            'answered_date' => $websoccer->getNowAsTimestamp(),
            'offer_id' => (int) $offerId,
            'context_data' => self::appendJsonFlag($application['context_data'], 'admin_decision', $adminDecision ? 1 : 0)
        ), $websoccer->getConfig('db_prefix') . '_manager_application', 'id = %d', (int) $application['id']);

        return $offerId;
    }

    private static function rejectApplication(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $application, $adminDecision) {
        $club = self::getClubById($websoccer, $db, (int) $application['target_team_id']);
        $teamName = ($club && isset($club['team_name'])) ? $club['team_name'] : '';
        self::createInboxMessage($websoccer, $db, $i18n, (int) $application['user_id'],
            self::message($i18n, 'managercareer_application_rejected_subject', array('team' => $teamName)),
            self::message($i18n, 'managercareer_application_rejected_body', array('team' => $teamName))
        );

        NotificationsDataService::createNotification(
            $websoccer,
            $db,
            (int) $application['user_id'],
            'managercareer_application_notification_rejected',
            array('team' => $teamName),
            'managercareer',
            'managercareer',
            '',
            (int) $application['target_team_id']
        );

        // Rejected applications are only actionable until the decision is made.
        // After the user has been informed, remove the application row so it no
        // longer appears as an outstanding or recent application.
        $db->queryDelete($websoccer->getConfig('db_prefix') . '_manager_application', 'id = %d', (int) $application['id']);
    }

    private static function appendJsonFlag($json, $key, $value) {
        $data = array();
        if (strlen((string) $json)) {
            $decoded = json_decode($json, TRUE);
            if (is_array($decoded)) {
                $data = $decoded;
            }
        }
        $data[$key] = $value;
        return json_encode($data);
    }

    private static function processSackRisk(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $latestMatchId) {
        $result = array('checked' => 0, 'sacked' => 0);
        if (!self::isAutoSackingEnabled($websoccer)) {
            return $result;
        }

        $prefix = $websoccer->getConfig('db_prefix');
        $sql = "SELECT C.id AS team_id, C.name AS team_name, C.user_id, C.board_satisfaction, C.liga_id, C.highscore AS team_highscore, C.strength AS team_strength, C.finanz_budget AS team_budget, C.superclub AS team_superclub, L.division AS league_division, U.nick AS manager_name, MC.id AS contract_id, MC.low_board_checks, MC.last_sack_check_match_id "
            . "FROM " . $prefix . "_verein AS C "
            . "INNER JOIN " . $prefix . "_user AS U ON U.id = C.user_id "
            . "LEFT JOIN " . $prefix . "_liga AS L ON L.id = C.liga_id "
            . "LEFT JOIN " . $prefix . "_manager_contract AS MC ON MC.user_id = C.user_id AND MC.team_id = C.id AND MC.status = 'active' "
            . "WHERE C.user_id > 0 AND C.status = '1' AND C.nationalteam != '1' "
            . "ORDER BY C.board_satisfaction ASC";
        $rows = $db->executeQuery($sql);

        while ($team = $rows->fetch_array()) {
            $result['checked']++;
            $contractId = (int) $team['contract_id'];
            if ($contractId < 1) {
                self::ensureContract($websoccer, $db, (int) $team['user_id'], (int) $team['team_id'], FALSE);
                continue;
            }

            if ((int) $team['last_sack_check_match_id'] >= (int) $latestMatchId) {
                continue;
            }

            $board = (int) $team['board_satisfaction'];
            $checks = (int) $team['low_board_checks'];
            if ($board < 10) {
                $checks++;
            } else if ($board >= 20) {
                $checks = 0;
            }

            $db->queryUpdate(array(
                'low_board_checks' => (int) $checks,
                'last_sack_check_match_id' => (int) $latestMatchId,
                'updated_date' => $websoccer->getNowAsTimestamp()
            ), $prefix . '_manager_contract', 'id = %d', $contractId);

            if (($board <= 5 && $checks >= 1) || ($board < 10 && $checks >= 3)) {
                self::sackManager($websoccer, $db, $i18n, $team, $contractId, $board);
                $result['sacked']++;
            } else if ($board < 20) {
                self::sendSackWarning($websoccer, $db, $i18n, $team, $checks);
            }
        }
        $rows->free();

        return $result;
    }

    private static function sackManager(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $team, $contractId, $board) {
        $prefix = $websoccer->getConfig('db_prefix');
        $now = $websoccer->getNowAsTimestamp();
        $userId = (int) $team['user_id'];
        $teamId = (int) $team['team_id'];

        $db->queryUpdate(array(
            'user_id' => '0',
            'user_id_actual' => '0',
            'interimmanager' => '1'
        ), $prefix . '_verein', 'id = %d', $teamId);

        $db->queryUpdate(array(
            'status' => self::CONTRACT_SACKED,
            'ended_date' => $now,
            'end_reason' => 'sacked',
            'updated_date' => $now
        ), $prefix . '_manager_contract', 'id = %d', (int) $contractId);

        $db->queryUpdate(array(
            'status' => 'failed',
            'penalized' => '1',
            'failed_date' => $now,
            'checked_date' => $now
        ), $prefix . '_manager_mission', "user_id = %d AND team_id = %d AND status = 'open'", array($userId, $teamId));

        $db->queryUpdate(array(
            'status' => self::APPLICATION_EXPIRED,
            'answered_date' => $now
        ), $prefix . '_manager_application', "user_id = %d AND status = 'open'", $userId);

        $db->queryUpdate(array(
            'status' => 'expired'
        ), $prefix . '_manager_job_offer', "user_id = %d AND status = 'open'", $userId);

        $db->queryInsert(array(
            'user_id' => $userId,
            'old_team_id' => $teamId,
            'new_team_id' => 0,
            'offer_id' => 0,
            'origin' => 'sacked',
            'old_club_score' => self::getClubPrestigeFromRow($team),
            'new_club_score' => 0,
            'highscore_bonus' => 0,
            'change_date' => $now
        ), $prefix . '_manager_career_history');

        self::createInboxMessage($websoccer, $db, $i18n, $userId,
            self::message($i18n, 'managercareer_sacked_subject', array('team' => $team['team_name'])),
            self::message($i18n, 'managercareer_sacked_body', array('team' => $team['team_name'], 'board' => $board))
        );

        NotificationsDataService::createNotification(
            $websoccer,
            $db,
            $userId,
            'managercareer_notification_sacked',
            array('team' => $team['team_name']),
            'managercareer',
            'managercareer',
            '',
            $teamId
        );

        if (class_exists('ManagerProfileDataService')) {
            ManagerProfileDataService::registerHumanSacking($websoccer, $db, $i18n, $userId, $teamId);
        }
    }

    private static function sendSackWarning(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $team, $checks) {
        $prefix = $websoccer->getConfig('db_prefix');
        $sql = "SELECT id FROM " . $prefix . "_briefe "
            . "WHERE empfaenger_id = " . (int) $team['user_id'] . " "
            . "AND absender_name = 'Managerkarriere' "
            . "AND betreff LIKE '%Krisensitzung%' "
            . "AND datum > " . (int) ($websoccer->getNowAsTimestamp() - 86400) . " LIMIT 1";
        $result = $db->executeQuery($sql);
        $row = $result->fetch_array();
        $result->free();
        if ($row) {
            return;
        }

        self::createInboxMessage($websoccer, $db, $i18n, (int) $team['user_id'],
            self::message($i18n, 'managercareer_sack_warning_subject', array('team' => $team['team_name'])),
            self::message($i18n, 'managercareer_sack_warning_body', array('team' => $team['team_name'], 'checks' => $checks))
        );
    }

    private static function ensureContractsForHumanClubs(WebSoccer $websoccer, DbConnection $db) {
        $prefix = $websoccer->getConfig('db_prefix');
        $rows = $db->querySelect('id,user_id', $prefix . '_verein', "user_id > 0 AND status = '1' AND nationalteam != '1'", null);
        $count = 0;
        while ($team = $rows->fetch_array()) {
            self::ensureContract($websoccer, $db, (int) $team['user_id'], (int) $team['id'], FALSE);
            $count++;
        }
        $rows->free();
        return $count;
    }

    private static function ensureContract(WebSoccer $websoccer, DbConnection $db, $userId, $teamId, $forceNew) {
        $prefix = $websoccer->getConfig('db_prefix');
        if (!$forceNew) {
            $existing = $db->querySelect('id', $prefix . '_manager_contract', "user_id = %d AND team_id = %d AND status = 'active'", array((int) $userId, (int) $teamId), 1);
            $row = $existing->fetch_array();
            $existing->free();
            if ($row) {
                return (int) $row['id'];
            }
        } else {
            $db->queryUpdate(array(
                'status' => self::CONTRACT_ENDED,
                'ended_date' => $websoccer->getNowAsTimestamp(),
                'end_reason' => 'new_contract',
                'updated_date' => $websoccer->getNowAsTimestamp()
            ), $prefix . '_manager_contract', "user_id = %d AND team_id = %d AND status = 'active'", array((int) $userId, (int) $teamId));
        }

        $now = $websoccer->getNowAsTimestamp();
        $contractDays = self::getConfigNumber($websoccer, 'mgr_contract_days', 180);
        $db->queryInsert(array(
            'user_id' => (int) $userId,
            'team_id' => (int) $teamId,
            'start_date' => $now,
            'contract_until_date' => $now + ((int) $contractDays * 86400),
            'status' => self::CONTRACT_ACTIVE,
            'low_board_checks' => 0,
            'last_sack_check_match_id' => 0,
            'created_date' => $now,
            'updated_date' => $now,
            'ended_date' => 0,
            'end_reason' => ''
        ), $prefix . '_manager_contract');
        return (int) $db->getLastInsertedId();
    }

    private static function processCountryReputationForMatches(WebSoccer $websoccer, DbConnection $db, $lastProcessedMatchId, $latestMatchId) {
        $prefix = $websoccer->getConfig('db_prefix');
        $sql = "SELECT M.id, M.home_user_id, M.gast_user_id, M.home_tore, M.gast_tore, L.land AS league_country, HL.land AS home_country, GL.land AS guest_country "
            . "FROM " . $prefix . "_spiel AS M "
            . "LEFT JOIN " . $prefix . "_liga AS L ON L.id = M.liga_id "
            . "LEFT JOIN " . $prefix . "_verein AS HV ON HV.id = M.home_verein LEFT JOIN " . $prefix . "_liga AS HL ON HL.id = HV.liga_id "
            . "LEFT JOIN " . $prefix . "_verein AS GV ON GV.id = M.gast_verein LEFT JOIN " . $prefix . "_liga AS GL ON GL.id = GV.liga_id "
            . "WHERE M.berechnet = '1' AND M.id > " . (int) $lastProcessedMatchId . " AND M.id <= " . (int) $latestMatchId . " "
            . "ORDER BY M.id ASC";
        $rows = $db->executeQuery($sql);
        $updates = 0;
        while ($match = $rows->fetch_array()) {
            $homeUserId = (int) $match['home_user_id'];
            $guestUserId = (int) $match['gast_user_id'];
            $homeCountry = strlen((string) $match['league_country']) ? $match['league_country'] : $match['home_country'];
            $guestCountry = strlen((string) $match['league_country']) ? $match['league_country'] : $match['guest_country'];
            $homeGoals = (int) $match['home_tore'];
            $guestGoals = (int) $match['gast_tore'];

            if ($homeUserId > 0 && strlen((string) $homeCountry)) {
                $delta = ($homeGoals > $guestGoals) ? 2 : (($homeGoals === $guestGoals) ? 1 : 0);
                if ($delta > 0) {
                    self::increaseCountryReputation($websoccer, $db, $homeUserId, $homeCountry, $delta, 'match_' . (int) $match['id']);
                    $updates++;
                }
            }
            if ($guestUserId > 0 && strlen((string) $guestCountry)) {
                $delta = ($guestGoals > $homeGoals) ? 2 : (($homeGoals === $guestGoals) ? 1 : 0);
                if ($delta > 0) {
                    self::increaseCountryReputation($websoccer, $db, $guestUserId, $guestCountry, $delta, 'match_' . (int) $match['id']);
                    $updates++;
                }
            }
        }
        $rows->free();
        return $updates;
    }

    private static function increaseCountryReputation(WebSoccer $websoccer, DbConnection $db, $userId, $country, $delta, $reason) {
        $prefix = $websoccer->getConfig('db_prefix');
        $country = trim((string) $country);
        if (!strlen($country) || (int) $userId < 1 || (int) $delta === 0) {
            return;
        }
        $countryEsc = $db->connection->real_escape_string($country);
        $reasonEsc = $db->connection->real_escape_string($reason);
        $now = (int) $websoccer->getNowAsTimestamp();
        $sql = "INSERT INTO " . $prefix . "_manager_country_reputation "
            . "(user_id, country, reputation, last_change, last_reason, updated_date) VALUES ("
            . (int) $userId . ", '" . $countryEsc . "', " . (int) $delta . ", " . (int) $delta . ", '" . $reasonEsc . "', " . $now . ") "
            . "ON DUPLICATE KEY UPDATE reputation = LEAST(100, GREATEST(0, reputation + " . (int) $delta . ")), "
            . "last_change = " . (int) $delta . ", last_reason = '" . $reasonEsc . "', updated_date = " . $now;
        $db->executeQuery($sql);
    }

    private static function processManagerAwards(WebSoccer $websoccer, DbConnection $db, I18n $i18n) {
        $created = 0;
        $created += self::processMonthlyAward($websoccer, $db, $i18n);
        $created += self::processSeasonAwards($websoccer, $db, $i18n);
        return $created;
    }

    private static function processMonthlyAward(WebSoccer $websoccer, DbConnection $db, I18n $i18n) {
        $prefix = $websoccer->getConfig('db_prefix');
        $now = $websoccer->getNowAsTimestamp();
        $monthStart = strtotime(date('Y-m-01 00:00:00', $now));
        $awardKey = 'manager_month_' . date('Ym', $now);

        if (self::awardExists($websoccer, $db, $awardKey)) {
            return 0;
        }

        $sql = "SELECT user_id, SUM(points) AS points, COUNT(*) AS matches_played FROM ("
            . "SELECT home_user_id AS user_id, CASE WHEN home_tore > gast_tore THEN 3 WHEN home_tore = gast_tore THEN 1 ELSE 0 END AS points FROM " . $prefix . "_spiel WHERE berechnet = '1' AND datum >= " . (int) $monthStart . " AND home_user_id > 0 "
            . "UNION ALL "
            . "SELECT gast_user_id AS user_id, CASE WHEN gast_tore > home_tore THEN 3 WHEN gast_tore = home_tore THEN 1 ELSE 0 END AS points FROM " . $prefix . "_spiel WHERE berechnet = '1' AND datum >= " . (int) $monthStart . " AND gast_user_id > 0"
            . ") AS X GROUP BY user_id HAVING matches_played >= 3 ORDER BY (SUM(points) / COUNT(*)) DESC, SUM(points) DESC LIMIT 1";
        $rows = $db->executeQuery($sql);
        $winner = $rows->fetch_array();
        $rows->free();
        if (!$winner || (int) $winner['user_id'] < 1) {
            return 0;
        }

        self::insertAward($websoccer, $db, (int) $winner['user_id'], 0, 0, 'manager_of_month', $awardKey, self::message($i18n, 'managercareer_award_month_title'), self::message($i18n, 'managercareer_award_month_description'), (int) $winner['points']);
        return 1;
    }

    private static function processSeasonAwards(WebSoccer $websoccer, DbConnection $db, I18n $i18n) {
        $prefix = $websoccer->getConfig('db_prefix');
        $sql = "SELECT A.user_id, A.team_id, A.season_id, A.rank FROM " . $prefix . "_achievement AS A "
            . "INNER JOIN " . $prefix . "_saison AS S ON S.id = A.season_id "
            . "WHERE S.beendet = '1' AND A.user_id > 0 AND A.rank = 1 "
            . "ORDER BY A.date_recorded DESC LIMIT 20";
        $rows = $db->executeQuery($sql);
        $created = 0;
        while ($row = $rows->fetch_array()) {
            $awardKey = 'manager_season_' . (int) $row['season_id'] . '_' . (int) $row['user_id'];
            if (self::awardExists($websoccer, $db, $awardKey)) {
                continue;
            }
            self::insertAward($websoccer, $db, (int) $row['user_id'], (int) $row['team_id'], (int) $row['season_id'], 'manager_of_season', $awardKey, self::message($i18n, 'managercareer_award_season_title'), self::message($i18n, 'managercareer_award_season_description'), 100);
            $created++;
        }
        $rows->free();
        return $created;
    }

    private static function awardExists(WebSoccer $websoccer, DbConnection $db, $awardKey) {
        $result = $db->querySelect('id', $websoccer->getConfig('db_prefix') . '_manager_award', 'award_key = \'%s\'', $awardKey, 1);
        $row = $result->fetch_array();
        $result->free();
        return ($row && isset($row['id']));
    }

    private static function insertAward(WebSoccer $websoccer, DbConnection $db, $userId, $teamId, $seasonId, $awardType, $awardKey, $title, $description, $scoreValue) {
        $db->queryInsert(array(
            'user_id' => (int) $userId,
            'team_id' => (int) $teamId,
            'season_id' => (int) $seasonId,
            'award_type' => $awardType,
            'award_key' => $awardKey,
            'title' => $title,
            'description' => $description,
            'score_value' => (int) $scoreValue,
            'created_date' => $websoccer->getNowAsTimestamp()
        ), $websoccer->getConfig('db_prefix') . '_manager_award');
    }

    private static function getApplicationsOfUser(WebSoccer $websoccer, DbConnection $db, $userId, $limit) {
        $prefix = $websoccer->getConfig('db_prefix');
        $sql = "SELECT A.*, C.name AS team_name, C.bild AS team_picture, L.id AS league_id, L.name AS league_name, L.land AS league_country "
            . "FROM " . $prefix . "_manager_application AS A "
            . "INNER JOIN " . $prefix . "_verein AS C ON C.id = A.target_team_id "
            . "LEFT JOIN " . $prefix . "_liga AS L ON L.id = C.liga_id "
            . "WHERE A.user_id = " . (int) $userId . " AND NOT (A.status = 'accepted' AND C.user_id = " . (int) $userId . ") "
            . "ORDER BY A.created_date DESC, A.id DESC LIMIT " . (int) $limit;
        return self::fetchAll($db, $sql);
    }

    private static function getAdminApplications(WebSoccer $websoccer, DbConnection $db, $openOnly, $limit) {
        $prefix = $websoccer->getConfig('db_prefix');
        $where = $openOnly ? "A.status = 'open'" : "A.status != 'open'";
        $sql = "SELECT A.*, U.nick AS manager_name, C.name AS team_name, L.name AS league_name, L.land AS league_country "
            . "FROM " . $prefix . "_manager_application AS A "
            . "INNER JOIN " . $prefix . "_user AS U ON U.id = A.user_id "
            . "INNER JOIN " . $prefix . "_verein AS C ON C.id = A.target_team_id "
            . "LEFT JOIN " . $prefix . "_liga AS L ON L.id = C.liga_id "
            . "WHERE " . $where . " ORDER BY A.created_date DESC, A.id DESC LIMIT " . (int) $limit;
        return self::fetchAll($db, $sql);
    }

    private static function getApplicationCandidates(WebSoccer $websoccer, DbConnection $db, $userId, $search, $limit) {
        $prefix = $websoccer->getConfig('db_prefix');
        $managerScore = self::getManagerScore($websoccer, $db, $userId);
        $currentTeamId = self::getMainTeamIdOfUser($websoccer, $db, $userId);
        $whereSearch = '';
        if (strlen(trim((string) $search))) {
            $s = $db->connection->real_escape_string('%' . trim((string) $search) . '%');
            $whereSearch = " AND (C.name LIKE '" . $s . "' OR L.name LIKE '" . $s . "' OR L.land LIKE '" . $s . "')";
        }
        $sql = "SELECT C.id AS team_id, C.name AS team_name, C.bild AS team_picture, C.finanz_budget AS team_budget, C.strength AS team_strength, C.highscore AS team_highscore, C.superclub AS team_superclub, C.user_id, C.interimmanager, "
            . "L.id AS league_id, L.name AS league_name, L.land AS league_country, L.division AS league_division "
            . "FROM " . $prefix . "_verein AS C "
            . "LEFT JOIN " . $prefix . "_liga AS L ON L.id = C.liga_id "
            . "WHERE C.status = '1' AND C.nationalteam != '1' "
            . "AND (C.user_id = 0 OR C.user_id IS NULL OR C.interimmanager = '1') "
            . "AND NOT EXISTS (SELECT 1 FROM " . $prefix . "_manager_job_offer AS JO WHERE JO.target_team_id = C.id AND JO.status = 'open' AND (JO.expires_date = 0 OR JO.expires_date >= " . (int) $websoccer->getNowAsTimestamp() . ")) "
            . "AND C.id != " . (int) $currentTeamId . $whereSearch . " "
            . "ORDER BY C.highscore DESC, C.strength DESC, C.name ASC LIMIT " . (int) $limit;
        $rows = self::fetchAll($db, $sql);
        $openByClub = self::getOpenApplicationClubIds($websoccer, $db, $userId);
        foreach ($rows as &$row) {
            $clubScore = self::getClubPrestigeFromRow($row);
            $required = self::getRequiredReputationForClub($clubScore, $row);
            $row['club_score'] = $clubScore;
            $row['required_score'] = $required;
            $row['acceptance_chance'] = self::calculateApplicationChance($websoccer, $db, $userId, $row, $managerScore, $clubScore);
            $row['already_applied'] = isset($openByClub[(int) $row['team_id']]);
            $row['career_status_class'] = ((int) $managerScore >= (int) $required) ? 'label-success' : 'label-warning';
        }
        unset($row);
        return $rows;
    }

    private static function getOpenApplicationClubIds(WebSoccer $websoccer, DbConnection $db, $userId) {
        $prefix = $websoccer->getConfig('db_prefix');
        $rows = $db->querySelect('target_team_id', $prefix . '_manager_application', "user_id = %d AND status = 'open'", (int) $userId);
        $ids = array();
        while ($row = $rows->fetch_array()) {
            $ids[(int) $row['target_team_id']] = TRUE;
        }
        $rows->free();
        return $ids;
    }

    private static function calculateApplicationChance(WebSoccer $websoccer, DbConnection $db, $userId, $club, $managerScore, $clubScore) {
        $required = self::getRequiredReputationForClub($clubScore, $club);
        $countryRep = 0;
        if (isset($club['league_country']) && strlen($club['league_country'])) {
            $countryRep = self::getCountryReputationValue($websoccer, $db, $userId, $club['league_country']);
        }
        $avgBoard = self::getAverageBoardForUser($websoccer, $db, $userId);
        $chance = 45 + (($managerScore - $required) * 0.45) + ($countryRep * 0.20);
        if (isset($club['league_division']) && (int) $club['league_division'] <= 1) {
            $chance -= 5;
        }
        if (isset($club['team_superclub']) && (int) $club['team_superclub'] > 0) {
            $chance -= 12;
        }
        if ($avgBoard > 70) {
            $chance += 5;
        } else if ($avgBoard > 0 && $avgBoard < 30) {
            $chance -= 15;
        }
        return max(5, min(85, (int) round($chance)));
    }

    private static function getCountryReputationValue(WebSoccer $websoccer, DbConnection $db, $userId, $country) {
        $result = $db->querySelect('reputation', $websoccer->getConfig('db_prefix') . '_manager_country_reputation', 'user_id = %d AND country = \'%s\'', array((int) $userId, $country), 1);
        $row = $result->fetch_array();
        $result->free();
        return ($row && isset($row['reputation'])) ? (int) $row['reputation'] : 0;
    }

    private static function getCountryReputation(WebSoccer $websoccer, DbConnection $db, $userId) {
        $prefix = $websoccer->getConfig('db_prefix');
        $sql = "SELECT * FROM " . $prefix . "_manager_country_reputation WHERE user_id = " . (int) $userId . " ORDER BY reputation DESC, country ASC LIMIT 20";
        return self::fetchAll($db, $sql);
    }

    private static function getContractsOfUser(WebSoccer $websoccer, DbConnection $db, $userId) {
        $prefix = $websoccer->getConfig('db_prefix');
        $sql = "SELECT MC.*, C.name AS team_name, C.board_satisfaction, L.name AS league_name "
            . "FROM " . $prefix . "_manager_contract AS MC "
            . "INNER JOIN " . $prefix . "_verein AS C ON C.id = MC.team_id "
            . "LEFT JOIN " . $prefix . "_liga AS L ON L.id = C.liga_id "
            . "WHERE MC.user_id = " . (int) $userId . " ORDER BY FIELD(MC.status, 'active', 'sacked', 'terminated', 'ended'), MC.start_date DESC LIMIT 20";
        return self::fetchAll($db, $sql);
    }

    private static function getSackRiskRowsForUser(WebSoccer $websoccer, DbConnection $db, $userId) {
        $all = self::getSackRiskRows($websoccer, $db, 50, $userId);
        return $all;
    }

    private static function getSackRiskRows(WebSoccer $websoccer, DbConnection $db, $limit, $userId = 0) {
        $prefix = $websoccer->getConfig('db_prefix');
        $userWhere = ((int) $userId > 0) ? " AND C.user_id = " . (int) $userId : '';
        $sql = "SELECT C.id AS team_id, C.name AS team_name, C.user_id, C.board_satisfaction, U.nick AS manager_name, MC.low_board_checks, MC.contract_until_date, L.name AS league_name "
            . "FROM " . $prefix . "_verein AS C "
            . "INNER JOIN " . $prefix . "_user AS U ON U.id = C.user_id "
            . "LEFT JOIN " . $prefix . "_liga AS L ON L.id = C.liga_id "
            . "LEFT JOIN " . $prefix . "_manager_contract AS MC ON MC.user_id = C.user_id AND MC.team_id = C.id AND MC.status = 'active' "
            . "WHERE C.user_id > 0 AND C.status = '1' AND C.nationalteam != '1'" . $userWhere . " "
            . "ORDER BY C.board_satisfaction ASC, C.name ASC LIMIT " . (int) $limit;
        $rows = self::fetchAll($db, $sql);
        foreach ($rows as &$row) {
            $board = (int) $row['board_satisfaction'];
            if ($board <= 5) {
                $row['risk_level'] = 'critical';
                $row['risk_label'] = 'Akut';
                $row['risk_class'] = 'label-important';
            } else if ($board < 10) {
                $row['risk_level'] = 'high';
                $row['risk_label'] = 'Sehr hoch';
                $row['risk_class'] = 'label-important';
            } else if ($board < 20) {
                $row['risk_level'] = 'medium';
                $row['risk_label'] = 'Hoch';
                $row['risk_class'] = 'label-warning';
            } else if ($board < 40) {
                $row['risk_level'] = 'watch';
                $row['risk_label'] = 'Beobachten';
                $row['risk_class'] = 'label-info';
            } else {
                $row['risk_level'] = 'low';
                $row['risk_label'] = 'Gering';
                $row['risk_class'] = 'label-success';
            }
        }
        unset($row);
        return $rows;
    }

    private static function getAwardsOfUser(WebSoccer $websoccer, DbConnection $db, $userId, $limit) {
        $prefix = $websoccer->getConfig('db_prefix');
        $sql = "SELECT A.*, C.name AS team_name, S.name AS season_name FROM " . $prefix . "_manager_award AS A "
            . "LEFT JOIN " . $prefix . "_verein AS C ON C.id = A.team_id "
            . "LEFT JOIN " . $prefix . "_saison AS S ON S.id = A.season_id "
            . "WHERE A.user_id = " . (int) $userId . " ORDER BY A.created_date DESC LIMIT " . (int) $limit;
        return self::fetchAll($db, $sql);
    }

    private static function getRecentAwards(WebSoccer $websoccer, DbConnection $db, $limit) {
        $prefix = $websoccer->getConfig('db_prefix');
        $sql = "SELECT A.*, U.nick AS manager_name, C.name AS team_name, S.name AS season_name FROM " . $prefix . "_manager_award AS A "
            . "INNER JOIN " . $prefix . "_user AS U ON U.id = A.user_id "
            . "LEFT JOIN " . $prefix . "_verein AS C ON C.id = A.team_id "
            . "LEFT JOIN " . $prefix . "_saison AS S ON S.id = A.season_id "
            . "ORDER BY A.created_date DESC LIMIT " . (int) $limit;
        return self::fetchAll($db, $sql);
    }

    private static function getManagerStatistics(WebSoccer $websoccer, DbConnection $db, $userId) {
        $prefix = $websoccer->getConfig('db_prefix');
        $sql = "SELECT COUNT(*) AS matches_played, SUM(win) AS wins, SUM(draw_result) AS draws, SUM(loss) AS losses FROM ("
            . "SELECT CASE WHEN home_tore > gast_tore THEN 1 ELSE 0 END AS win, CASE WHEN home_tore = gast_tore THEN 1 ELSE 0 END AS draw_result, CASE WHEN home_tore < gast_tore THEN 1 ELSE 0 END AS loss FROM " . $prefix . "_spiel WHERE berechnet = '1' AND home_user_id = " . (int) $userId . " "
            . "UNION ALL "
            . "SELECT CASE WHEN gast_tore > home_tore THEN 1 ELSE 0 END AS win, CASE WHEN home_tore = gast_tore THEN 1 ELSE 0 END AS draw_result, CASE WHEN gast_tore < home_tore THEN 1 ELSE 0 END AS loss FROM " . $prefix . "_spiel WHERE berechnet = '1' AND gast_user_id = " . (int) $userId
            . ") AS X";
        $rows = $db->executeQuery($sql);
        $row = $rows->fetch_array();
        $rows->free();

        $matches = ($row && isset($row['matches_played'])) ? (int) $row['matches_played'] : 0;
        $wins = ($row && isset($row['wins'])) ? (int) $row['wins'] : 0;
        $draws = ($row && isset($row['draws'])) ? (int) $row['draws'] : 0;
        $losses = ($row && isset($row['losses'])) ? (int) $row['losses'] : 0;

        $titleResult = $db->querySelect('COUNT(*) AS titles', $prefix . '_achievement', 'user_id = %d AND rank = 1', (int) $userId, 1);
        $titleRow = $titleResult->fetch_array();
        $titleResult->free();

        return array(
            'matches' => $matches,
            'wins' => $wins,
            'draws' => $draws,
            'losses' => $losses,
            'win_rate' => ($matches > 0) ? round(($wins / $matches) * 100, 1) : 0,
            'titles' => ($titleRow && isset($titleRow['titles'])) ? (int) $titleRow['titles'] : 0
        );
    }

    private static function getMissionSnapshot(WebSoccer $websoccer, DbConnection $db, $userId) {
        $prefix = $websoccer->getConfig('db_prefix');
        $sql = "SELECT M.*, C.name AS team_name, S.name AS season_name FROM " . $prefix . "_manager_mission AS M "
            . "LEFT JOIN " . $prefix . "_verein AS C ON C.id = M.team_id "
            . "LEFT JOIN " . $prefix . "_saison AS S ON S.id = M.season_id "
            . "WHERE M.user_id = " . (int) $userId . " ORDER BY FIELD(M.status, 'open', 'completed', 'failed', 'cancelled'), M.created_date DESC LIMIT 20";
        return self::fetchAll($db, $sql);
    }

    private static function expireOldApplications(WebSoccer $websoccer, DbConnection $db) {
        $db->queryUpdate(array(
            'status' => self::APPLICATION_EXPIRED,
            'answered_date' => $websoccer->getNowAsTimestamp()
        ), $websoccer->getConfig('db_prefix') . '_manager_application', "status = 'open' AND expires_date > 0 AND expires_date < %d", $websoccer->getNowAsTimestamp());
    }

    private static function closeOpenApplicationsAfterJobChange(WebSoccer $websoccer, DbConnection $db, $userId, $acceptedTeamId) {
        $db->queryUpdate(array(
            'status' => self::APPLICATION_WITHDRAWN,
            'answered_date' => $websoccer->getNowAsTimestamp()
        ), $websoccer->getConfig('db_prefix') . '_manager_application', "user_id = %d AND target_team_id != %d AND status = 'open'", array((int) $userId, (int) $acceptedTeamId));
    }

    private static function countOpenApplicationsOfUser(WebSoccer $websoccer, DbConnection $db, $userId) {
        $result = $db->querySelect('COUNT(*) AS hits', $websoccer->getConfig('db_prefix') . '_manager_application', "user_id = %d AND status = 'open'", (int) $userId, 1);
        $row = $result->fetch_array();
        $result->free();
        return ($row && isset($row['hits'])) ? (int) $row['hits'] : 0;
    }

    private static function hasOpenApplicationForClub(WebSoccer $websoccer, DbConnection $db, $userId, $teamId) {
        $result = $db->querySelect('id', $websoccer->getConfig('db_prefix') . '_manager_application', "user_id = %d AND target_team_id = %d AND status = 'open'", array((int) $userId, (int) $teamId), 1);
        $row = $result->fetch_array();
        $result->free();
        return ($row && isset($row['id']));
    }

    private static function getApplicationById(WebSoccer $websoccer, DbConnection $db, $applicationId) {
        $result = $db->querySelect('*', $websoccer->getConfig('db_prefix') . '_manager_application', 'id = %d', (int) $applicationId, 1);
        $row = $result->fetch_array();
        $result->free();
        return ($row) ? $row : null;
    }

    private static function getApplicationClubById(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $prefix = $websoccer->getConfig('db_prefix');
        $sql = "SELECT C.id AS team_id, C.name AS team_name, C.bild AS team_picture, C.finanz_budget AS team_budget, C.strength AS team_strength, C.highscore AS team_highscore, C.superclub AS team_superclub, C.user_id, C.interimmanager, "
            . "L.id AS league_id, L.name AS league_name, L.land AS league_country, L.division AS league_division "
            . "FROM " . $prefix . "_verein AS C "
            . "LEFT JOIN " . $prefix . "_liga AS L ON L.id = C.liga_id "
            . "WHERE C.id = " . (int) $teamId . " AND C.status = '1' AND C.nationalteam != '1' "
            . "AND (C.user_id = 0 OR C.user_id IS NULL OR C.interimmanager = '1') "
            . "AND NOT EXISTS (SELECT 1 FROM " . $prefix . "_manager_job_offer AS JO WHERE JO.target_team_id = C.id AND JO.status = 'open' AND (JO.expires_date = 0 OR JO.expires_date >= " . (int) $websoccer->getNowAsTimestamp() . ")) LIMIT 1";
        $rows = $db->executeQuery($sql);
        $row = $rows->fetch_array();
        $rows->free();
        return ($row) ? $row : null;
    }

    private static function getClubById(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $prefix = $websoccer->getConfig('db_prefix');
        $sql = "SELECT C.id AS team_id, C.name AS team_name, C.bild AS team_picture, C.user_id, C.interimmanager, C.finanz_budget AS team_budget, C.highscore AS team_highscore, C.strength AS team_strength, C.superclub AS team_superclub, C.board_satisfaction, "
            . "L.name AS league_name, L.land AS league_country, L.division AS league_division "
            . "FROM " . $prefix . "_verein AS C "
            . "LEFT JOIN " . $prefix . "_liga AS L ON L.id = C.liga_id "
            . "WHERE C.id = " . (int) $teamId . " AND C.status = '1' LIMIT 1";
        $rows = $db->executeQuery($sql);
        $row = $rows->fetch_array();
        $rows->free();
        return ($row) ? $row : null;
    }

    private static function getManagerScore(WebSoccer $websoccer, DbConnection $db, $userId) {
        if (class_exists('ManagerCareerDataService')) {
            return ManagerCareerDataService::getManagerScoreForUser($websoccer, $db, $userId);
        }
        $result = $db->querySelect('highscore', $websoccer->getConfig('db_prefix') . '_user', 'id = %d', (int) $userId, 1);
        $row = $result->fetch_array();
        $result->free();
        return ($row && isset($row['highscore'])) ? (int) $row['highscore'] : 0;
    }

    private static function getMainTeamIdOfUser(WebSoccer $websoccer, DbConnection $db, $userId) {
        $result = $db->querySelect('id', $websoccer->getConfig('db_prefix') . '_verein', "user_id = %d AND status = '1' AND nationalteam != '1' ORDER BY interimmanager ASC, id ASC", (int) $userId, 1);
        $row = $result->fetch_array();
        $result->free();
        return ($row && isset($row['id'])) ? (int) $row['id'] : 0;
    }

    private static function getAverageBoardForUser(WebSoccer $websoccer, DbConnection $db, $userId) {
        $result = $db->querySelect('AVG(board_satisfaction) AS avg_board', $websoccer->getConfig('db_prefix') . '_verein', "user_id = %d AND status = '1' AND nationalteam != '1'", (int) $userId, 1);
        $row = $result->fetch_array();
        $result->free();
        return ($row && isset($row['avg_board'])) ? (int) round($row['avg_board']) : 0;
    }

    private static function getClubPrestigeFromRow($club) {
        $highscore = isset($club['team_highscore']) ? (int) $club['team_highscore'] : (isset($club['highscore']) ? (int) $club['highscore'] : 0);
        $strength = isset($club['team_strength']) ? (int) $club['team_strength'] : (isset($club['strength']) ? (int) $club['strength'] : 0);
        $budget = isset($club['team_budget']) ? (int) $club['team_budget'] : (isset($club['finanz_budget']) ? (int) $club['finanz_budget'] : 0);
        $division = isset($club['league_division']) ? (int) $club['league_division'] : 1;
        $superclub = isset($club['team_superclub']) ? (int) $club['team_superclub'] : (isset($club['superclub']) ? (int) $club['superclub'] : 0);
        $leagueBonus = max(0, 35 - max(0, $division - 1) * 10);
        $budgetBonus = min(30, (int) floor($budget / 1000000));
        $score = $highscore + (int) floor($strength / 2) + $leagueBonus + $budgetBonus;
        if ($superclub > 0) {
            $score += 50;
        }
        return max(0, (int) $score);
    }

    private static function getRequiredReputationForClub($clubScore, $club) {
        $required = max(0, (int) $clubScore - 15);
        if (isset($club['team_highscore']) && (int) $club['team_highscore'] <= 20) {
            $required = 0;
        }
        if (isset($club['team_superclub']) && (int) $club['team_superclub'] > 0) {
            $required += 25;
        }
        return (int) $required;
    }

    private static function getLatestComputedMatchId(WebSoccer $websoccer, DbConnection $db) {
        $result = $db->querySelect('MAX(id) AS match_id', $websoccer->getConfig('db_prefix') . '_spiel', "berechnet = '1'", null, 1);
        $row = $result->fetch_array();
        $result->free();
        return ($row && isset($row['match_id'])) ? (int) $row['match_id'] : 0;
    }

    private static function createInboxMessage(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $userId, $subject, $body, $senderClub = null) {
        if ((int) $userId < 1) {
            return;
        }
        if (strlen($subject) > 50) {
            $subject = substr($subject, 0, 47) . '...';
        }
        $columns = array(
            'empfaenger_id' => (int) $userId,
            'absender_name' => self::message($i18n, 'managercareer_message_sender'),
            'datum' => $websoccer->getNowAsTimestamp(),
            'betreff' => $subject,
            'nachricht' => $body,
            'gelesen' => '0',
            'typ' => 'eingang'
        );
        if (is_array($senderClub) && isset($senderClub['team_id'])) {
            $columns['message_type'] = 'manager_job_offer';
            $columns['context_data'] = json_encode(array(
                'sender_club' => array(
                    'id' => (int) $senderClub['team_id'],
                    'name' => isset($senderClub['team_name']) ? $senderClub['team_name'] : '',
                    'logo' => isset($senderClub['team_picture']) ? $senderClub['team_picture'] : ''
                )
            ));
        }
        $db->queryInsert($columns, $websoccer->getConfig('db_prefix') . '_briefe');
    }

    private static function fetchAll(DbConnection $db, $sql) {
        $result = $db->executeQuery($sql);
        $rows = array();
        while ($row = $result->fetch_array()) {
            $rows[] = $row;
        }
        $result->free();
        return $rows;
    }

    private static function getConfigBoolean(WebSoccer $websoccer, $name, $default) {
        try {
            return ((int) $websoccer->getConfig($name) > 0);
        } catch (Exception $e) {
            return $default;
        }
    }

    private static function getConfigNumber(WebSoccer $websoccer, $name, $default) {
        try {
            $value = (int) $websoccer->getConfig($name);
            return ($value > 0) ? $value : $default;
        } catch (Exception $e) {
            return $default;
        }
    }

    private static function message(I18n $i18n, $key, $data = array()) {
        if ($i18n->hasMessage($key)) {
            $message = $i18n->getMessage($key);
        } else {
            $message = $key;
        }
        if (is_array($data) && count($data)) {
            foreach ($data as $placeholder => $value) {
                $message = str_replace('{' . $placeholder . '}', $value, $message);
            }
        }
        return $message;
    }
}
?>
