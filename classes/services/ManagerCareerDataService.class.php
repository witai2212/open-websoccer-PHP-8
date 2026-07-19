<?php
/******************************************************

  This file is part of OpenWebSoccer-Sim.

******************************************************/

/**
 * Data service for manager career mode / job offers.
 */
class ManagerCareerDataService {

    const OFFER_OPEN = 'open';
    const OFFER_ACCEPTED = 'accepted';
    const OFFER_DECLINED = 'declined';
    const OFFER_EXPIRED = 'expired';

    const ORIGIN_JOB_OFFER = 'job_offer';
    const ORIGIN_FREE_CLUB = 'free_club';
    const ORIGIN_ADDITIONAL_CLUB = 'additional_club';
    const ORIGIN_MANUAL_APPLICATION = 'manual_application';

    public static function isEnabled(WebSoccer $websoccer) {
        return self::getConfigBoolean($websoccer, 'mgr_career_enabled', TRUE);
    }

    public static function isJobOffersEnabled(WebSoccer $websoccer) {
        return self::isEnabled($websoccer) && self::getConfigBoolean($websoccer, 'mgr_job_offers', TRUE);
    }

    public static function isFreeClubReputationCheckEnabled(WebSoccer $websoccer) {
        return self::isEnabled($websoccer) && self::getConfigBoolean($websoccer, 'freeclub_rep_check', TRUE);
    }


    public static function getManagerScoreForUser(WebSoccer $websoccer, DbConnection $db, $userId) {
        return self::getManagerReputationScore($websoccer, $db, $userId);
    }

    public static function getCareerPageData(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $profileUserId, $viewerUserId) {
        $enabled = self::isEnabled($websoccer);
        $isOwnProfile = ((int) $profileUserId === (int) $viewerUserId);
        $manager = self::getUser($websoccer, $db, $profileUserId);

        if (!$manager) {
            return array('enabled' => FALSE);
        }

        $teams = self::getTeamsOfUser($websoccer, $db, $profileUserId);
        $managerScore = self::getManagerReputationScore($websoccer, $db, $profileUserId);
        $avgBoard = self::getAverageBoardSatisfaction($teams);
        $history = array();
        $openOffers = array();
        $freeClubs = array();

        if ($enabled) {
            try {
                $history = self::getCareerHistory($websoccer, $db, $profileUserId, 20);
            } catch (Exception $e) {
                $history = array();
            }
        }

        if ($enabled && $isOwnProfile) {
            try {
                self::expireOldOffers($websoccer, $db);
                if (class_exists('ManagerCareerImprovementService')) {
                    ManagerCareerImprovementService::processDueApplicationsNow($websoccer, $db, $i18n);
                }
                $openOffers = self::getOpenOffersOfUser($websoccer, $db, $profileUserId);
            } catch (Exception $e) {
                $openOffers = array();
            }
            $freeClubs = self::getFreeClubsForManager($websoccer, $db, $profileUserId, 12, TRUE, TRUE);
        }

        $data = array(
            'enabled' => $enabled,
            'is_own_profile' => $isOwnProfile,
            'manager_score' => $managerScore,
            'user_highscore' => (int) $manager['highscore'],
            'avg_board_satisfaction' => $avgBoard,
            'teams' => $teams,
            'open_offers' => $openOffers,
            'offer_limit' => self::getConfigNumber($websoccer, 'mgr_offer_limit', 3),
            'free_clubs' => $freeClubs,
            'history' => $history,
            'job_offers_enabled' => self::isJobOffersEnabled($websoccer),
            'freeclub_rep_check_enabled' => self::isFreeClubReputationCheckEnabled($websoccer)
        );

        if (class_exists('ManagerCareerImprovementService')) {
            $data = ManagerCareerImprovementService::extendCareerPageData($websoccer, $db, $i18n, $data, $profileUserId, $viewerUserId);
        }

        return $data;
    }

    /**
     * Called from reload_content.php after a matchday has been fully processed.
     */
    public static function processJobOffersMatchday(WebSoccer $websoccer, DbConnection $db, I18n $i18n) {
        if (!self::isJobOffersEnabled($websoccer)) {
            return array('processed' => 0, 'created' => 0, 'skipped' => 'disabled');
        }

        $prefix = $websoccer->getConfig('db_prefix');
        self::ensureOfferMarker($websoccer, $db);
        self::expireOldOffers($websoccer, $db);

        // Manual job applications are date-based. They must be decided even if no
        // new matchday has advanced since the last manager-career marker.
        $applicationExtended = array();
        if (class_exists('ManagerCareerImprovementService')) {
            $applicationResult = ManagerCareerImprovementService::processDueApplicationsNow($websoccer, $db, $i18n);
            $applicationExtended = array(
                'applications_processed' => (int) $applicationResult['processed'],
                'applications_accepted' => (int) $applicationResult['accepted'],
                'applications_rejected' => (int) $applicationResult['rejected']
            );
        }

        $matchId = self::getLatestComputedMatchId($websoccer, $db);
        if ($matchId < 1) {
            return array_merge(array('processed' => 0, 'created' => 0, 'skipped' => 'no_match'), $applicationExtended);
        }

        $lastProcessed = self::getOfferMarker($websoccer, $db);
        if ($lastProcessed >= $matchId) {
            return array_merge(array('processed' => 0, 'created' => 0, 'skipped' => 'already_processed'), $applicationExtended);
        }

        $result = $db->querySelect('id', $prefix . '_user', "status = '1' ORDER BY id ASC");
        $processed = 0;
        $created = 0;
        while ($row = $result->fetch_array()) {
            $created += self::generateOffersForUser($websoccer, $db, $i18n, (int) $row['id'], $matchId);
            $processed++;
        }
        $result->free();

        $extended = $applicationExtended;
        if (class_exists('ManagerCareerImprovementService')) {
            $matchdayExtended = ManagerCareerImprovementService::processMatchday($websoccer, $db, $i18n, $lastProcessed, $matchId, FALSE);
            $extended = array_merge($matchdayExtended, $applicationExtended);
        }

        self::setOfferMarker($websoccer, $db, $matchId);
        return array_merge(array('processed' => $processed, 'created' => $created, 'skipped' => ''), $extended);
    }

    public static function generateOffersForUser(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $userId, $matchId = 0) {
        if (!self::isJobOffersEnabled($websoccer)) {
            return 0;
        }

        $offerLimit = self::getConfigNumber($websoccer, 'mgr_offer_limit', 3);
        $openCount = self::countOpenOffersOfUser($websoccer, $db, $userId);
        if ($openCount >= $offerLimit) {
            return 0;
        }

        if ($matchId > 0 && self::countOffersCreatedAtMatch($websoccer, $db, $userId, $matchId) > 0) {
            return 0;
        }

        $teams = self::getTeamsOfUser($websoccer, $db, $userId);
        $currentTeamId = self::getMainTeamIdOfUser($websoccer, $db, $userId);
        $currentPrestige = ($currentTeamId > 0) ? self::getClubPrestigeById($websoccer, $db, $currentTeamId) : 0;
        $avgBoard = self::getAverageBoardSatisfaction($teams);
        $managerScore = self::getManagerReputationScore($websoccer, $db, $userId);

        $slots = $offerLimit - $openCount;
        $maxNewOffers = ($currentTeamId < 1) ? min($slots, 3) : min($slots, 1);
        if ($maxNewOffers < 1) {
            return 0;
        }

        $maxClubScore = $managerScore + 70;
        if ($managerScore >= 150) {
            $maxClubScore += 40;
        }
        if ($avgBoard > 70) {
            $maxClubScore += 30;
        }
        if ($avgBoard > 0 && $avgBoard < 40) {
            $maxClubScore = min($maxClubScore, $managerScore + 20);
        }
        if ($currentTeamId < 1) {
            $maxClubScore = max($maxClubScore, 80);
        }

        $candidates = self::getFreeClubRows($websoccer, $db, 140, TRUE);
        $selected = array();
        foreach ($candidates as $club) {
            $club = self::decorateClubForManager($club, $managerScore);
            if (!$club['eligible']) {
                continue;
            }
            if ($club['club_score'] > $maxClubScore) {
                continue;
            }
            if ($currentTeamId > 0 && (int) $club['team_id'] === (int) $currentTeamId) {
                continue;
            }

            // Poor performance should still lead to offers, but mostly weaker or safer clubs.
            if ($avgBoard > 0 && $avgBoard < 40 && $currentPrestige > 0 && $club['club_score'] > ($currentPrestige + 10)) {
                continue;
            }

            $selected[] = $club;
            if (count($selected) >= $maxNewOffers) {
                break;
            }
        }

        $created = 0;
        foreach ($selected as $club) {
            self::createOffer($websoccer, $db, $i18n, $userId, $currentTeamId, (int) $club['team_id'], $managerScore, (int) $club['club_score'], $matchId);
            $created++;
        }

        return $created;
    }

    public static function acceptJobOffer(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $userId, $offerId) {
        if (!self::isEnabled($websoccer)) {
            throw new Exception(self::message($i18n, 'managercareer_error_disabled'));
        }

        self::expireOldOffers($websoccer, $db);
        $offer = self::getOfferById($websoccer, $db, $offerId);
        if (!$offer || (int) $offer['user_id'] !== (int) $userId || $offer['status'] !== self::OFFER_OPEN) {
            throw new Exception(self::message($i18n, 'managercareer_error_offer_invalid'));
        }

        if ((int) $offer['expires_date'] > 0 && (int) $offer['expires_date'] < $websoccer->getNowAsTimestamp()) {
            self::setOfferStatus($websoccer, $db, $offerId, self::OFFER_EXPIRED);
            throw new Exception(self::message($i18n, 'managercareer_error_offer_expired'));
        }

        $targetTeamId = (int) $offer['target_team_id'];
        self::validateTargetTeamIsFree($websoccer, $db, $i18n, $targetTeamId);

        $currentTeamId = self::getMainTeamIdOfUser($websoccer, $db, $userId);
        if ((int) $offer['source_team_id'] > 0 && self::userOwnsTeam($websoccer, $db, $userId, (int) $offer['source_team_id'])) {
            $currentTeamId = (int) $offer['source_team_id'];
        }

        $origin = self::ORIGIN_JOB_OFFER;
        if (isset($offer['context_data']) && strlen((string) $offer['context_data'])) {
            $contextData = json_decode($offer['context_data'], TRUE);
            if (is_array($contextData) && isset($contextData['manual_application_id']) && (int) $contextData['manual_application_id'] > 0) {
                $origin = self::ORIGIN_MANUAL_APPLICATION;
            }
        }

        $switchResult = self::switchManagerToClub($websoccer, $db, $i18n, $userId, $targetTeamId, $currentTeamId, $origin, $offerId);
        self::setOfferStatus($websoccer, $db, $offerId, self::OFFER_ACCEPTED);
        self::closeOtherOffers($websoccer, $db, $userId, $offerId);
        
        //set vertrag_spiele = 50 for new manager
        TeamsDataService::extendContractForNewManager($websoccer, $db, $targetTeamId);

        return $switchResult;
    }

    public static function declineJobOffer(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $userId, $offerId) {
        $offer = self::getOfferById($websoccer, $db, $offerId);
        if (!$offer || (int) $offer['user_id'] !== (int) $userId || $offer['status'] !== self::OFFER_OPEN) {
            throw new Exception(self::message($i18n, 'managercareer_error_offer_invalid'));
        }

        self::setOfferStatus($websoccer, $db, $offerId, self::OFFER_DECLINED);
        return TRUE;
    }

    public static function validateFreeClubEligibility(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $userId, $teamId) {
        self::validateTargetTeamIsFree($websoccer, $db, $i18n, $teamId);

        if (self::isTeamReservedByOpenOffer($websoccer, $db, $teamId)) {
            throw new Exception(self::message($i18n, 'freeclubs_msg_error'));
        }

        if (!self::isFreeClubReputationCheckEnabled($websoccer)) {
            return TRUE;
        }

        $club = self::getFreeClubRowById($websoccer, $db, $teamId);
        if (!$club) {
            throw new Exception(self::message($i18n, 'freeclubs_msg_error'));
        }

        $managerScore = self::getManagerReputationScore($websoccer, $db, $userId);
        $club = self::decorateClubForManager($club, $managerScore);
        if (!$club['eligible']) {
            throw new Exception(self::message($i18n, 'freeclubs_msg_error_reputation_required', array(
                'required' => $club['required_score'],
                'score' => $managerScore
            )));
        }

        return TRUE;
    }

    public static function assignFreeClub(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $userId, $teamId, $origin = self::ORIGIN_FREE_CLUB) {
        self::validateFreeClubEligibility($websoccer, $db, $i18n, $userId, $teamId);

        $oldTeamId = 0;
        if ($origin === self::ORIGIN_FREE_CLUB) {
            $oldTeamId = self::getMainTeamIdOfUser($websoccer, $db, $userId);
        }

        return self::switchManagerToClub($websoccer, $db, $i18n, $userId, $teamId, $oldTeamId, $origin, 0);
    }

    public static function getFreeClubsForManager(WebSoccer $websoccer, DbConnection $db, $userId, $limit = 80, $includeLocked = TRUE, $flat = FALSE) {
        $managerScore = self::getManagerReputationScore($websoccer, $db, $userId);
        $rows = self::getFreeClubRows($websoccer, $db, 300, FALSE);

        $eligible = array();
        $locked = array();
        foreach ($rows as $club) {
            $club = self::decorateClubForManager($club, $managerScore);
            if ($club['eligible']) {
                $eligible[] = $club;
            } else if ($includeLocked && $club['score_gap'] <= 120) {
                $locked[] = $club;
            }
        }

        usort($eligible, array('ManagerCareerDataService', 'sortClubsForCareerList'));
        usort($locked, array('ManagerCareerDataService', 'sortClubsForCareerList'));

        $clubs = array_slice(array_merge($eligible, $locked), 0, $limit);
        if ($flat) {
            return $clubs;
        }

        $grouped = array();
        foreach ($clubs as $club) {
            $country = (isset($club['league_country']) && strlen($club['league_country'])) ? $club['league_country'] : 'Sonstige';
            $grouped[$country][] = $club;
        }

        ksort($grouped);
        return $grouped;
    }

    public static function sortClubsForCareerList($a, $b) {
        if ($a['eligible'] && !$b['eligible']) {
            return -1;
        }
        if (!$a['eligible'] && $b['eligible']) {
            return 1;
        }
        if ($a['club_score'] == $b['club_score']) {
            return strcmp($a['team_name'], $b['team_name']);
        }
        return ($a['club_score'] > $b['club_score']) ? -1 : 1;
    }

    private static function switchManagerToClub(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $userId, $targetTeamId, $oldTeamId, $origin, $offerId) {
        $prefix = $websoccer->getConfig('db_prefix');
        $now = $websoccer->getNowAsTimestamp();

        $target = self::getClubById($websoccer, $db, $targetTeamId);
        if (!$target) {
            throw new Exception(self::message($i18n, 'freeclubs_msg_error'));
        }

        $old = null;
        if ($oldTeamId > 0 && (int) $oldTeamId !== (int) $targetTeamId && self::userOwnsTeam($websoccer, $db, $userId, $oldTeamId)) {
            $old = self::getClubById($websoccer, $db, $oldTeamId);

            self::closeOpenMissionsForOldClub($websoccer, $db, $userId, $oldTeamId);
            self::cleanupPersonnelAndScoutingForClub($websoccer, $db, $oldTeamId);

            $db->queryUpdate(
                array(
                    'user_id' => '0',
                    'user_id_actual' => '0',
                    'interimmanager' => '0'
                ),
                $prefix . '_verein',
                'id = %d',
                (int) $oldTeamId
            );
            PlayersDataService::resetUnsellableForTeam($websoccer, $db, $oldTeamId);

            if (class_exists('ManagerProfileDataService')) {
                ManagerProfileDataService::handleUserLeftTeam($websoccer, $db, $oldTeamId, 'user_left');
            }
        } else {
            $oldTeamId = 0;
        }

        // Remove orphan staff/scouting data from the target club before the new manager takes over.
        self::cleanupPersonnelAndScoutingForClub($websoccer, $db, $targetTeamId);

        if (class_exists('ManagerProfileDataService')) {
            ManagerProfileDataService::handleUserTakesOverTeam($websoccer, $db, $targetTeamId);
        }

        $db->queryUpdate(
            array(
                'user_id' => (int) $userId,
                'user_id_actual' => '0',
                'interimmanager' => '0'
            ),
            $prefix . '_verein',
            'id = %d',
            (int) $targetTeamId
        );

        // Keep old OWS behavior: after first assignment, stadium quality starts from a clean baseline.
        if (class_exists('StadiumsDataService')) {
            StadiumsDataService::resetStadiumLevels($websoccer, $db, $targetTeamId);
        }

        if (class_exists('ManagerMissionsDataService')) {
            ManagerMissionsDataService::ensureMissionsForCurrentSeason($websoccer, $db, $userId, $targetTeamId);
        }


        $oldPrestige = ($old) ? self::getClubPrestigeFromRow($old) : 0;
        $targetPrestige = self::getClubPrestigeFromRow($target);
        $highscoreBonus = self::calculateMoveBonus($oldPrestige, $targetPrestige, $origin);
        if ($highscoreBonus > 0) {
            $db->executeQuery('UPDATE ' . $prefix . '_user SET highscore = highscore + ' . (int) $highscoreBonus . ' WHERE id = ' . (int) $userId);
        }

        if (class_exists('ManagerProfileDataService')) {
            ManagerProfileDataService::assignHumanManagerToTeam($websoccer, $db, $i18n, $userId, $targetTeamId, $origin);
        }

        self::recordCareerHistory($websoccer, $db, $userId, $oldTeamId, $targetTeamId, $origin, $oldPrestige, $targetPrestige, $highscoreBonus, $offerId);
        self::appendUserHistory($websoccer, $db, $i18n, $userId, ($old ? $old['team_name'] : ''), $target['team_name'], $origin, $highscoreBonus);
        self::createCareerNews($websoccer, $db, $i18n, $userId, ($old ? $old['team_name'] : ''), $target['team_name'], $origin, $highscoreBonus);

        NotificationsDataService::createNotification(
            $websoccer,
            $db,
            $userId,
            'managercareer_notification_job_accepted',
            array('team' => $target['team_name']),
            'managercareer',
            'user',
            'id=' . (int) $userId,
            $targetTeamId
        );

        if (class_exists('ActionLogDataService')) {
            ActionLogDataService::createOrUpdateActionLog($websoccer, $db, $userId, 'managercareer_job_change');
        }

        if (class_exists('ManagerCareerImprovementService')) {
            ManagerCareerImprovementService::handleManagerJoinedClub($websoccer, $db, $i18n, $userId, $targetTeamId, $oldTeamId, $origin);
        }

        return array(
            'team_id' => $targetTeamId,
            'old_team_id' => $oldTeamId,
            'team_name' => $target['team_name'],
            'old_team_name' => ($old ? $old['team_name'] : ''),
            'highscore_bonus' => $highscoreBonus
        );
    }

    private static function createOffer(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $userId, $sourceTeamId, $targetTeamId, $managerScore, $clubScore, $matchId) {
        $prefix = $websoccer->getConfig('db_prefix');
        $now = $websoccer->getNowAsTimestamp();
        $validDays = self::getConfigNumber($websoccer, 'mgr_offer_exp_days', 21);
        $target = self::getClubById($websoccer, $db, $targetTeamId);
        if (!$target) {
            return;
        }

        $existing = $db->querySelect(
            'id',
            $prefix . '_manager_job_offer',
            "user_id = %d AND target_team_id = %d AND status = '" . self::OFFER_OPEN . "'",
            array((int) $userId, (int) $targetTeamId),
            1
        );
        $row = $existing->fetch_array();
        $existing->free();
        if ($row) {
            return;
        }

        $db->queryInsert(
            array(
                'user_id' => (int) $userId,
                'source_team_id' => (int) $sourceTeamId,
                'target_team_id' => (int) $targetTeamId,
                'manager_score' => (int) $managerScore,
                'club_score' => (int) $clubScore,
                'status' => self::OFFER_OPEN,
                'created_date' => $now,
                'expires_date' => $now + ((int) $validDays * 86400),
                'created_match_id' => (int) $matchId,
                'accepted_date' => 0,
                'declined_date' => 0,
                'context_data' => ''
            ),
            $prefix . '_manager_job_offer'
        );

        self::createJobOfferInboxMessage(
            $websoccer,
            $db,
            $i18n,
            $userId,
            $target,
            $managerScore,
            $clubScore,
            $now + ((int) $validDays * 86400)
        );
    }

    private static function createJobOfferInboxMessage(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $userId, $target, $managerScore, $clubScore, $expiresDate) {
        if (!$target || (int) $userId < 1) {
            return;
        }

        $subject = self::message($i18n, 'managercareer_message_offer_subject', array('team' => $target['team_name']));
        if (strlen($subject) > 50) {
            $subject = substr($subject, 0, 47) . '...';
        }

        $message = self::message($i18n, 'managercareer_message_offer_body', array(
            'team' => $target['team_name'],
            'league' => (isset($target['league_name']) ? $target['league_name'] : ''),
            'clubscore' => (int) $clubScore,
            'managerscore' => (int) $managerScore,
            'expires' => date('d.m.Y', (int) $expiresDate),
            'url' => $websoccer->getInternalUrl('managercareer')
        ));

        $db->queryInsert(
            array(
                'empfaenger_id' => (int) $userId,
                    'absender_name' => self::message($i18n, 'managercareer_message_sender'),
                'datum' => $websoccer->getNowAsTimestamp(),
                'betreff' => $subject,
                'nachricht' => $message,
                'gelesen' => '0',
                'typ' => 'eingang'
            ),
            $websoccer->getConfig('db_prefix') . '_briefe'
        );
    }

    private static function getOpenOffersOfUser(WebSoccer $websoccer, DbConnection $db, $userId) {
        $prefix = $websoccer->getConfig('db_prefix');
        $fromTable = $prefix . '_manager_job_offer AS O '
            . 'INNER JOIN ' . $prefix . '_verein AS C ON C.id = O.target_team_id '
            . 'INNER JOIN ' . $prefix . '_liga AS L ON L.id = C.liga_id '
            . 'LEFT JOIN ' . $prefix . '_stadion AS S ON S.id = C.stadion_id';

        $columns = array(
            'O.id' => 'offer_id',
            'O.source_team_id' => 'source_team_id',
            'O.target_team_id' => 'team_id',
            'O.manager_score' => 'manager_score',
            'O.club_score' => 'club_score',
            'O.created_date' => 'created_date',
            'O.expires_date' => 'expires_date',
            'C.name' => 'team_name',
            'C.bild' => 'team_picture',
            'C.finanz_budget' => 'team_budget',
            'C.highscore' => 'team_highscore',
            'C.strength' => 'team_strength',
            'L.id' => 'league_id',
            'L.name' => 'league_name',
            'L.land' => 'league_country',
            'COALESCE(S.p_steh, 0)' => 'stadium_p_steh',
            'COALESCE(S.p_sitz, 0)' => 'stadium_p_sitz',
            'COALESCE(S.p_haupt_steh, 0)' => 'stadium_p_haupt_steh',
            'COALESCE(S.p_haupt_sitz, 0)' => 'stadium_p_haupt_sitz',
            'COALESCE(S.p_vip, 0)' => 'stadium_p_vip'
        );

        $result = $db->querySelect(
            $columns,
            $fromTable,
            "O.user_id = %d AND O.status = '" . self::OFFER_OPEN . "' ORDER BY O.created_date DESC",
            (int) $userId
        );

        $offers = array();
        while ($offer = $result->fetch_array()) {
            $offers[] = $offer;
        }
        $result->free();
        return $offers;
    }

    private static function getCareerHistory(WebSoccer $websoccer, DbConnection $db, $userId, $limit) {
        $prefix = $websoccer->getConfig('db_prefix');
        $fromTable = $prefix . '_manager_career_history AS H '
            . 'LEFT JOIN ' . $prefix . '_verein AS OLDTEAM ON OLDTEAM.id = H.old_team_id '
            . 'LEFT JOIN ' . $prefix . '_verein AS NEWTEAM ON NEWTEAM.id = H.new_team_id';

        $columns = array(
            'H.id' => 'id',
            'H.change_date' => 'change_date',
            'H.origin' => 'origin',
            'H.old_team_id' => 'old_team_id',
            'H.new_team_id' => 'new_team_id',
            'H.old_club_score' => 'old_club_score',
            'H.new_club_score' => 'new_club_score',
            'H.highscore_bonus' => 'highscore_bonus',
            'OLDTEAM.name' => 'old_team_name',
            'NEWTEAM.name' => 'new_team_name'
        );

        $result = $db->querySelect(
            $columns,
            $fromTable,
            'H.user_id = %d ORDER BY H.change_date DESC, H.id DESC',
            (int) $userId,
            (int) $limit
        );

        $history = array();
        while ($row = $result->fetch_array()) {
            $history[] = $row;
        }
        $result->free();
        return $history;
    }

    private static function getFreeClubRows(WebSoccer $websoccer, DbConnection $db, $limit = 300, $random = FALSE) {
        $prefix = $websoccer->getConfig('db_prefix');
        $order = ($random) ? 'RAND()' : 'C.highscore DESC, C.strength DESC, C.name ASC';
        $sql = "SELECT C.id AS team_id,
                       C.name AS team_name,
                       C.finanz_budget AS team_budget,
                       C.bild AS team_picture,
                       C.strength AS team_strength,
                       C.highscore AS team_highscore,
                       C.superclub AS team_superclub,
                       L.id AS league_id,
                       L.name AS league_name,
                       L.land AS league_country,
                       L.division AS league_division,
                       COALESCE(S.p_steh, 0) AS stadium_p_steh,
                       COALESCE(S.p_sitz, 0) AS stadium_p_sitz,
                       COALESCE(S.p_haupt_steh, 0) AS stadium_p_haupt_steh,
                       COALESCE(S.p_haupt_sitz, 0) AS stadium_p_haupt_sitz,
                       COALESCE(S.p_vip, 0) AS stadium_p_vip
                FROM " . $prefix . "_verein AS C
                INNER JOIN " . $prefix . "_liga AS L ON C.liga_id = L.id
                LEFT JOIN " . $prefix . "_stadion AS S ON C.stadion_id = S.id
                WHERE C.nationalteam != '1'
                  AND (C.user_id = 0 OR C.user_id IS NULL OR C.interimmanager = '1')
                  AND C.status = '1'
                  AND NOT EXISTS (
                      SELECT 1
                      FROM " . $prefix . "_manager_job_offer AS JO
                      WHERE JO.target_team_id = C.id
                        AND JO.status = 'open'
                        AND (JO.expires_date = 0 OR JO.expires_date >= " . $websoccer->getNowAsTimestamp() . ")
                  )
                ORDER BY " . $order . "
                LIMIT " . (int) $limit;

        $result = $db->executeQuery($sql);
        $clubs = array();
        while ($row = $result->fetch_array()) {
            $clubs[] = $row;
        }
        $result->free();
        return $clubs;
    }

    private static function getFreeClubRowById(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $prefix = $websoccer->getConfig('db_prefix');
        $sql = "SELECT C.id AS team_id,
                       C.name AS team_name,
                       C.finanz_budget AS team_budget,
                       C.bild AS team_picture,
                       C.strength AS team_strength,
                       C.highscore AS team_highscore,
                       C.superclub AS team_superclub,
                       L.id AS league_id,
                       L.name AS league_name,
                       L.land AS league_country,
                       L.division AS league_division,
                       COALESCE(S.p_steh, 0) AS stadium_p_steh,
                       COALESCE(S.p_sitz, 0) AS stadium_p_sitz,
                       COALESCE(S.p_haupt_steh, 0) AS stadium_p_haupt_steh,
                       COALESCE(S.p_haupt_sitz, 0) AS stadium_p_haupt_sitz,
                       COALESCE(S.p_vip, 0) AS stadium_p_vip
                FROM " . $prefix . "_verein AS C
                INNER JOIN " . $prefix . "_liga AS L ON C.liga_id = L.id
                LEFT JOIN " . $prefix . "_stadion AS S ON C.stadion_id = S.id
                WHERE C.id = " . (int) $teamId . "
                  AND C.nationalteam != '1'
                  AND (C.user_id = 0 OR C.user_id IS NULL OR C.interimmanager = '1')
                  AND C.status = '1'
                  AND NOT EXISTS (
                      SELECT 1
                      FROM " . $prefix . "_manager_job_offer AS JO
                      WHERE JO.target_team_id = C.id
                        AND JO.status = 'open'
                        AND (JO.expires_date = 0 OR JO.expires_date >= " . $websoccer->getNowAsTimestamp() . ")
                  )
                LIMIT 1";
        $result = $db->executeQuery($sql);
        $row = $result->fetch_array();
        $result->free();
        return ($row) ? $row : null;
    }

    private static function decorateClubForManager($club, $managerScore) {
        $clubScore = self::getClubPrestigeFromRow($club);
        $required = self::getRequiredReputationForClub($clubScore, $club);
        $club['club_score'] = $clubScore;
        $club['required_score'] = $required;
        $club['manager_score'] = (int) $managerScore;
        $club['eligible'] = ((int) $managerScore >= (int) $required) ? TRUE : FALSE;
        $club['score_gap'] = max(0, (int) $required - (int) $managerScore);
        $club['career_status_class'] = ($club['eligible']) ? 'label-success' : 'label-important';
        return $club;
    }

    private static function getRequiredReputationForClub($clubScore, $club) {
        $required = max(0, (int) $clubScore - 15);
        if ((int) $club['team_highscore'] <= 20) {
            $required = 0;
        }
        if (isset($club['team_superclub']) && (int) $club['team_superclub'] > 0) {
            $required += 25;
        }
        return (int) $required;
    }

    private static function getClubPrestigeById(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $club = self::getClubById($websoccer, $db, $teamId);
        return ($club) ? self::getClubPrestigeFromRow($club) : 0;
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

    private static function getManagerReputationScore(WebSoccer $websoccer, DbConnection $db, $userId) {
        $user = self::getUser($websoccer, $db, $userId);
        if (!$user) {
            return 0;
        }

        $score = (int) $user['highscore'];
        $score += (int) floor(((int) $user['fanbeliebtheit'] - 50) / 5);

        $teams = self::getTeamsOfUser($websoccer, $db, $userId);
        $avgBoard = self::getAverageBoardSatisfaction($teams);
        if ($avgBoard > 0) {
            $score += (int) floor(($avgBoard - 50) / 2);
            if ($avgBoard < 40) {
                $score -= 30;
            }
        } else {
            // Unemployed managers should still receive realistic lower-tier offers.
            $score += 10;
        }

        return max(0, (int) $score);
    }

    private static function getAverageBoardSatisfaction($teams) {
        if (!is_array($teams) || !count($teams)) {
            return 0;
        }

        $sum = 0;
        foreach ($teams as $team) {
            $sum += (int) $team['board_satisfaction'];
        }
        return (int) round($sum / count($teams));
    }

    private static function getTeamsOfUser(WebSoccer $websoccer, DbConnection $db, $userId) {
        $prefix = $websoccer->getConfig('db_prefix');
        $result = $db->querySelect(
            'id, name, liga_id, board_satisfaction, highscore, strength, interimmanager',
            $prefix . '_verein',
            "user_id = %d AND status = '1' AND nationalteam != '1' ORDER BY name ASC",
            (int) $userId
        );
        $teams = array();
        while ($row = $result->fetch_array()) {
            $teams[] = $row;
        }
        $result->free();
        return $teams;
    }

    private static function getMainTeamIdOfUser(WebSoccer $websoccer, DbConnection $db, $userId) {
        $prefix = $websoccer->getConfig('db_prefix');
        $result = $db->querySelect(
            'id',
            $prefix . '_verein',
            "user_id = %d AND status = '1' AND nationalteam != '1' ORDER BY interimmanager ASC, id ASC",
            (int) $userId,
            1
        );
        $row = $result->fetch_array();
        $result->free();
        return ($row && isset($row['id'])) ? (int) $row['id'] : 0;
    }

    private static function userOwnsTeam(WebSoccer $websoccer, DbConnection $db, $userId, $teamId) {
        $prefix = $websoccer->getConfig('db_prefix');
        $result = $db->querySelect('id', $prefix . '_verein', 'id = %d AND user_id = %d', array((int) $teamId, (int) $userId), 1);
        $row = $result->fetch_array();
        $result->free();
        return ($row && isset($row['id']));
    }

    public static function isTeamReservedByOpenOffer(WebSoccer $websoccer, DbConnection $db, $teamId, $ignoreOfferId = 0) {
        $prefix = $websoccer->getConfig('db_prefix');
        $where = "target_team_id = %d AND status = 'open' AND (expires_date = 0 OR expires_date >= %d)";
        $params = array((int) $teamId, (int) $websoccer->getNowAsTimestamp());
        if ((int) $ignoreOfferId > 0) {
            $where .= " AND id != %d";
            $params[] = (int) $ignoreOfferId;
        }

        $result = $db->querySelect('id', $prefix . '_manager_job_offer', $where, $params, 1);
        $row = $result->fetch_array();
        $result->free();
        return ($row && isset($row['id']));
    }

    private static function validateTargetTeamIsFree(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $teamId) {
        $prefix = $websoccer->getConfig('db_prefix');
        $result = $db->querySelect(
            'id,user_id,interimmanager',
            $prefix . '_verein',
            "id = %d AND status = '1' AND nationalteam != '1'",
            (int) $teamId,
            1
        );
        $club = $result->fetch_array();
        $result->free();

        if (!$club || (!empty($club['user_id']) && (int) $club['user_id'] > 0 && $club['interimmanager'] !== '1')) {
            throw new Exception(self::message($i18n, 'freeclubs_msg_error'));
        }
        return TRUE;
    }

    private static function getClubById(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $prefix = $websoccer->getConfig('db_prefix');
        $fromTable = $prefix . '_verein AS C LEFT JOIN ' . $prefix . '_liga AS L ON L.id = C.liga_id';
        $columns = array(
            'C.id' => 'team_id',
            'C.name' => 'team_name',
            'C.user_id' => 'user_id',
            'C.finanz_budget' => 'team_budget',
            'C.highscore' => 'team_highscore',
            'C.strength' => 'team_strength',
            'C.superclub' => 'team_superclub',
            'C.board_satisfaction' => 'board_satisfaction',
            'L.division' => 'league_division',
            'L.name' => 'league_name'
        );
        $result = $db->querySelect($columns, $fromTable, "C.id = %d AND C.status = '1'", (int) $teamId, 1);
        $row = $result->fetch_array();
        $result->free();
        return ($row) ? $row : null;
    }

    private static function getUser(WebSoccer $websoccer, DbConnection $db, $userId) {
        $prefix = $websoccer->getConfig('db_prefix');
        $result = $db->querySelect('id, nick, highscore, fanbeliebtheit, history', $prefix . '_user', 'id = %d', (int) $userId, 1);
        $row = $result->fetch_array();
        $result->free();
        return ($row) ? $row : null;
    }

    private static function calculateMoveBonus($oldPrestige, $targetPrestige, $origin) {
        if (($origin !== self::ORIGIN_JOB_OFFER && $origin !== self::ORIGIN_MANUAL_APPLICATION) || $targetPrestige <= $oldPrestige + 25) {
            return 0;
        }
        return min(100, max(10, (int) floor(($targetPrestige - $oldPrestige) / 5)));
    }

    private static function closeOpenMissionsForOldClub(WebSoccer $websoccer, DbConnection $db, $userId, $teamId) {
        $prefix = $websoccer->getConfig('db_prefix');
        $now = $websoccer->getNowAsTimestamp();

        // Job changes are voluntary career moves, not failed season objectives.
        // Therefore old open missions are cancelled without board penalty.
        $db->queryUpdate(
            array(
                'status' => 'cancelled',
                'penalized' => '0',
                'failed_date' => $now,
                'checked_date' => $now
            ),
            $prefix . '_manager_mission',
            "user_id = %d AND team_id = %d AND status = 'open'",
            array((int) $userId, (int) $teamId)
        );
    }

    private static function cleanupPersonnelAndScoutingForClub(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $teamId = (int) $teamId;
        if ($teamId < 1) {
            return;
        }

        $prefix = $websoccer->getConfig('db_prefix');

        // Hired club staff belongs to the previous manager assignment, not to an unmanaged/free club.
        $db->queryDelete($prefix . '_club_staff_assignment', 'team_id = %d', $teamId);

        // Release assigned scouts back to the general scout pool and reset their contract duration.
        $db->queryUpdate(
            array(
                'team_id' => 0,
                'team_matches' => 0
            ),
            $prefix . '_scout',
            'team_id = %d',
            $teamId
        );

        // Stop running scouting camps so no further camp/personnel costs or proposals are generated.
        $db->queryUpdate(
            array('status' => '0'),
            $prefix . '_scouting_camp',
            "team_id = %d AND status = '1'",
            $teamId
        );

        // Open scouting proposals were created for the previous manager context.
        $db->queryUpdate(
            array(
                'status' => 'expired',
                'expires_date' => $websoccer->getNowAsTimestamp()
            ),
            $prefix . '_scouting_proposal',
            "team_id = %d AND status = 'open'",
            $teamId
        );
    }

    private static function recordCareerHistory(WebSoccer $websoccer, DbConnection $db, $userId, $oldTeamId, $newTeamId, $origin, $oldPrestige, $newPrestige, $bonus, $offerId) {
        $db->queryInsert(
            array(
                'user_id' => (int) $userId,
                'old_team_id' => (int) $oldTeamId,
                'new_team_id' => (int) $newTeamId,
                'offer_id' => (int) $offerId,
                'origin' => $origin,
                'old_club_score' => (int) $oldPrestige,
                'new_club_score' => (int) $newPrestige,
                'highscore_bonus' => (int) $bonus,
                'change_date' => $websoccer->getNowAsTimestamp()
            ),
            $websoccer->getConfig('db_prefix') . '_manager_career_history'
        );
    }

    private static function appendUserHistory(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $userId, $oldTeamName, $newTeamName, $origin, $bonus) {
        $user = self::getUser($websoccer, $db, $userId);
        if (!$user) {
            return;
        }

        $line = date('d.m.Y', $websoccer->getNowAsTimestamp()) . ': ';
        if (strlen($oldTeamName)) {
            $line .= self::message($i18n, 'managercareer_history_moved', array('old' => $oldTeamName, 'new' => $newTeamName));
        } else {
            $line .= self::message($i18n, 'managercareer_history_joined', array('new' => $newTeamName));
        }
        if ($bonus > 0) {
            $line .= ' ' . self::message($i18n, 'managercareer_history_bonus', array('bonus' => $bonus));
        }

        $history = trim((string) $user['history']);
        $history = (strlen($history)) ? $history . "\n" . $line : $line;
        $db->queryUpdate(array('history' => $history), $websoccer->getConfig('db_prefix') . '_user', 'id = %d', (int) $userId);
    }

    private static function createCareerNews(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $userId, $oldTeamName, $newTeamName, $origin, $bonus) {
        $user = self::getUser($websoccer, $db, $userId);
        if (!$user) {
            return;
        }

        $title = self::message($i18n, 'managercareer_news_title', array('manager' => $user['nick'], 'team' => $newTeamName));
        $messageKey = (strlen($oldTeamName)) ? 'managercareer_news_message_move' : 'managercareer_news_message_join';
        $message = self::message($i18n, $messageKey, array(
            'manager' => $user['nick'],
            'old' => $oldTeamName,
            'new' => $newTeamName,
            'bonus' => $bonus
        ));

        if ($bonus > 0) {
            $message .= ' ' . self::message($i18n, 'managercareer_news_message_bonus', array('bonus' => $bonus));
        }

        $db->queryInsert(
            array(
                'datum' => $websoccer->getNowAsTimestamp(),
                'autor_id' => 1,
                'titel' => $title,
                'nachricht' => $message,
                'linktext1' => self::message($i18n, 'managercareer_news_link'),
                'linkurl1' => $websoccer->getInternalUrl('user', 'id=' . (int) $userId),
                'c_br' => '1',
                'c_links' => '1',
                'c_smilies' => '0',
                'status' => '1'
            ),
            $websoccer->getConfig('db_prefix') . '_news'
        );
    }

    private static function getOfferById(WebSoccer $websoccer, DbConnection $db, $offerId) {
        $result = $db->querySelect('*', $websoccer->getConfig('db_prefix') . '_manager_job_offer', 'id = %d', (int) $offerId, 1);
        $row = $result->fetch_array();
        $result->free();
        return ($row) ? $row : null;
    }

    private static function setOfferStatus(WebSoccer $websoccer, DbConnection $db, $offerId, $status) {
        $dateColumn = '';
        if ($status === self::OFFER_ACCEPTED) {
            $dateColumn = 'accepted_date';
        } else if ($status === self::OFFER_DECLINED) {
            $dateColumn = 'declined_date';
        }

        $columns = array('status' => $status);
        if (strlen($dateColumn)) {
            $columns[$dateColumn] = $websoccer->getNowAsTimestamp();
        }

        $db->queryUpdate($columns, $websoccer->getConfig('db_prefix') . '_manager_job_offer', 'id = %d', (int) $offerId);
    }

    private static function closeOtherOffers(WebSoccer $websoccer, DbConnection $db, $userId, $acceptedOfferId) {
        $db->queryUpdate(
            array('status' => self::OFFER_DECLINED, 'declined_date' => $websoccer->getNowAsTimestamp()),
            $websoccer->getConfig('db_prefix') . '_manager_job_offer',
            "user_id = %d AND id != %d AND status = '" . self::OFFER_OPEN . "'",
            array((int) $userId, (int) $acceptedOfferId)
        );
    }

    private static function expireOldOffers(WebSoccer $websoccer, DbConnection $db) {
        $db->queryUpdate(
            array('status' => self::OFFER_EXPIRED),
            $websoccer->getConfig('db_prefix') . '_manager_job_offer',
            "status = '" . self::OFFER_OPEN . "' AND expires_date > 0 AND expires_date < %d",
            $websoccer->getNowAsTimestamp()
        );
    }

    private static function countOpenOffersOfUser(WebSoccer $websoccer, DbConnection $db, $userId) {
        $result = $db->querySelect('COUNT(*) AS hits', $websoccer->getConfig('db_prefix') . '_manager_job_offer', "user_id = %d AND status = '" . self::OFFER_OPEN . "'", (int) $userId, 1);
        $row = $result->fetch_array();
        $result->free();
        return ($row) ? (int) $row['hits'] : 0;
    }

    private static function countOffersCreatedAtMatch(WebSoccer $websoccer, DbConnection $db, $userId, $matchId) {
        $result = $db->querySelect('COUNT(*) AS hits', $websoccer->getConfig('db_prefix') . '_manager_job_offer', 'user_id = %d AND created_match_id = %d', array((int) $userId, (int) $matchId), 1);
        $row = $result->fetch_array();
        $result->free();
        return ($row) ? (int) $row['hits'] : 0;
    }

    private static function getLatestComputedMatchId(WebSoccer $websoccer, DbConnection $db) {
        $result = $db->querySelect('MAX(id) AS match_id', $websoccer->getConfig('db_prefix') . '_spiel', "berechnet = '1'", null, 1);
        $row = $result->fetch_array();
        $result->free();
        return ($row && isset($row['match_id'])) ? (int) $row['match_id'] : 0;
    }

    private static function ensureOfferMarker(WebSoccer $websoccer, DbConnection $db) {
        $prefix = $websoccer->getConfig('db_prefix');
        $result = $db->querySelect('id', $prefix . '_config', "name = 'mgr_offer_marker'", null, 1);
        $row = $result->fetch_array();
        $result->free();
        if ($row) {
            return;
        }

        $db->queryInsert(array(
            'name' => 'mgr_offer_marker',
            'zeitstempel' => 0,
            'descr' => 'Manager career marker'
        ), $prefix . '_config');
    }

    private static function getOfferMarker(WebSoccer $websoccer, DbConnection $db) {
        $result = $db->querySelect('zeitstempel', $websoccer->getConfig('db_prefix') . '_config', "name = 'mgr_offer_marker'", null, 1);
        $row = $result->fetch_array();
        $result->free();
        return ($row && isset($row['zeitstempel'])) ? (int) $row['zeitstempel'] : 0;
    }

    private static function setOfferMarker(WebSoccer $websoccer, DbConnection $db, $matchId) {
        $db->queryUpdate(array('zeitstempel' => (int) $matchId, 'descr' => 'Manager career marker'), $websoccer->getConfig('db_prefix') . '_config', "name = 'mgr_offer_marker'", null);
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
            $fallback = self::getFallbackMessages();
            $message = isset($fallback[$key]) ? $fallback[$key] : $key;
        }

        if (is_array($data) && count($data)) {
            foreach ($data as $placeholder => $value) {
                $message = str_replace('{' . $placeholder . '}', $value, $message);
            }
        }
        return $message;
    }

    private static function getFallbackMessages() {
        return array(
            'managercareer_error_disabled' => 'Die Managerkarriere ist derzeit deaktiviert.',
            'managercareer_error_offer_invalid' => 'Dieses Jobangebot ist nicht mehr verfügbar.',
            'managercareer_error_offer_expired' => 'Dieses Jobangebot ist bereits abgelaufen.',
            'managercareer_notification_new_offer' => 'Neues Jobangebot von {team}',
            'managercareer_notification_job_accepted' => 'Du bist jetzt Manager von {team}.',
            'managercareer_history_moved' => 'Wechsel von {old} zu {new}',
            'managercareer_history_joined' => 'Amtsantritt bei {new}',
            'managercareer_history_bonus' => 'Reputationsbonus: +{bonus} Highscore.',
            'managercareer_news_title' => '{manager} übernimmt {team}',
            'managercareer_news_message_move' => '{manager} wechselt von {old} zu {new}.',
            'managercareer_news_message_join' => '{manager} übernimmt den freien Verein {new}.',
            'managercareer_news_message_bonus' => 'Der Wechsel zu einem größeren Klub bringt +{bonus} Highscore.',
            'managercareer_news_link' => 'Zur Managerkarriere',
            'freeclubs_msg_error' => 'Das Team ist wohl inzwischen bereits vergeben.',
            'freeclubs_msg_error_reputation_required' => 'Für diesen Verein benötigst du mindestens {required} Manager-Reputation. Deine aktuelle Reputation: {score}.'
        );
    }
}
?>
