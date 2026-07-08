<?php
/******************************************************

  This file is part of OpenWebSoccer-Sim.

******************************************************/

/**
 * Builds the live "Heute im Verein" briefing for the office page.
 *
 * The service intentionally does not write a briefing table. It reads the
 * newest relevant signals from existing game systems and turns them into a
 * compact manager inbox.
 */
class DailyBriefingDataService {

    const MAX_ITEMS = 8;
    const MIN_ITEMS = 3;

    public static function getOfficeData(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $userId, $teamId) {
        $teamId = (int) $teamId;
        $userId = (int) $userId;
        $now = (int) $websoccer->getNowAsTimestamp();

        $data = array(
            'enabled' => TRUE,
            'has_team' => FALSE,
            'generated_at' => $now,
            'cards' => array(),
            'items' => array(),
            'links' => self::buildQuickLinks($websoccer)
        );

        if ($teamId < 1) {
            return $data;
        }

        $team = self::getTeam($websoccer, $db, $teamId);
        if (!$team) {
            return $data;
        }

        $data['has_team'] = TRUE;
        $data['team'] = $team;
        $data['cards'] = self::buildStatusCards($websoccer, $i18n, $team);

        $items = array();
        self::addThresholdItems($websoccer, $i18n, $items, $team);
        self::addOpenInterviews($websoccer, $db, $i18n, $items, $userId, $teamId, $now);
        self::addScoutingProposals($websoccer, $db, $i18n, $items, $teamId);
        self::addYouthAcademyItems($websoccer, $db, $i18n, $items, $teamId);
        self::addLoanItems($websoccer, $db, $i18n, $items, $teamId);
        self::addTransferItems($websoccer, $db, $i18n, $items, $teamId);
        self::addLoanRequestItems($websoccer, $db, $i18n, $items, $teamId);
        self::addWhatChangedItem($websoccer, $db, $i18n, $items, $userId, $teamId);
        self::addFanMediaStoryItems($websoccer, $db, $i18n, $items, $teamId);
        self::addUpcomingMatchItem($websoccer, $db, $i18n, $items, $teamId, $now);

        self::addFallbackItems($websoccer, $i18n, $items, $team);
        $data['items'] = self::sortAndLimitItems($items, self::MAX_ITEMS);

        return $data;
    }

    private static function buildQuickLinks(WebSoccer $websoccer) {
        return array(
            'fanpressure' => $websoccer->getInternalUrl('fanpressure'),
            'scouting' => $websoccer->getInternalUrl('scouting'),
            'youth_academy' => $websoccer->getInternalUrl('youth-academy'),
            'loans' => $websoccer->getInternalUrl('loans'),
            'what_changed' => $websoccer->getInternalUrl('what-changed')
        );
    }

    private static function getTeam(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $prefix = $websoccer->getConfig('db_prefix');
        $columns = 'id,name,user_id,board_satisfaction,fan_mood,media_pressure,team_chemistry,finanz_budget,strength,liga_id,platz,min_target_rank';
        $result = $db->querySelect($columns, $prefix . '_verein', 'id = %d', (int) $teamId, 1);
        $team = $result->fetch_array();
        $result->free();
        return $team ? $team : null;
    }

    private static function buildStatusCards(WebSoccer $websoccer, I18n $i18n, $team) {
        return array(
            self::buildStatusCard($i18n, 'board', 'dailybriefing_card_board', (int) $team['board_satisfaction'], FALSE),
            self::buildStatusCard($i18n, 'fans', 'dailybriefing_card_fans', (int) $team['fan_mood'], FALSE),
            self::buildStatusCard($i18n, 'media', 'dailybriefing_card_media', (int) $team['media_pressure'], TRUE),
            self::buildStatusCard($i18n, 'chemistry', 'dailybriefing_card_chemistry', (int) $team['team_chemistry'], FALSE)
        );
    }

    private static function buildStatusCard(I18n $i18n, $key, $titleKey, $value, $inverse) {
        $labelKey = 'dailybriefing_status_stable';
        $css = 'info';
        if ($inverse) {
            if ($value >= 75) {
                $labelKey = 'dailybriefing_status_critical';
                $css = 'important';
            } elseif ($value >= 55) {
                $labelKey = 'dailybriefing_status_attention';
                $css = 'warning';
            } elseif ($value <= 30) {
                $labelKey = 'dailybriefing_status_calm';
                $css = 'success';
            }
        } else {
            if ($value <= 30) {
                $labelKey = 'dailybriefing_status_critical';
                $css = 'important';
            } elseif ($value <= 45) {
                $labelKey = 'dailybriefing_status_attention';
                $css = 'warning';
            } elseif ($value >= 75) {
                $labelKey = 'dailybriefing_status_positive';
                $css = 'success';
            }
        }

        return array(
            'key' => $key,
            'title' => $i18n->getMessage($titleKey),
            'value' => max(0, min(100, (int) $value)),
            'label' => $i18n->getMessage($labelKey),
            'css' => $css
        );
    }

    private static function addThresholdItems(WebSoccer $websoccer, I18n $i18n, &$items, $team) {
        $board = (int) $team['board_satisfaction'];
        $fans = (int) $team['fan_mood'];
        $media = (int) $team['media_pressure'];
        $chemistry = (int) $team['team_chemistry'];
        $now = (int) $websoccer->getNowAsTimestamp();

        if ($board <= 30) {
            $items[] = self::item($i18n, 'board', 'critical', 'icon-briefcase', $i18n->getMessage('dailybriefing_item_board_low_title'), $i18n->getMessage('dailybriefing_item_board_low_text'), $now, $websoccer->getInternalUrl('manager-missions'), 'dailybriefing_action_board');
        } elseif ($board >= 80) {
            $items[] = self::item($i18n, 'board', 'positive', 'icon-briefcase', $i18n->getMessage('dailybriefing_item_board_high_title'), $i18n->getMessage('dailybriefing_item_board_high_text'), $now, $websoccer->getInternalUrl('manager-missions'), 'dailybriefing_action_board');
        }

        if ($fans <= 30) {
            $items[] = self::item($i18n, 'fans', 'important', 'icon-heart', $i18n->getMessage('dailybriefing_item_fans_low_title'), $i18n->getMessage('dailybriefing_item_fans_low_text'), $now, $websoccer->getInternalUrl('fanpressure'), 'dailybriefing_action_fanpressure');
        } elseif ($fans >= 80) {
            $items[] = self::item($i18n, 'fans', 'positive', 'icon-heart', $i18n->getMessage('dailybriefing_item_fans_high_title'), $i18n->getMessage('dailybriefing_item_fans_high_text'), $now, $websoccer->getInternalUrl('fanpressure'), 'dailybriefing_action_fanpressure');
        }

        if ($media >= 75) {
            $items[] = self::item($i18n, 'media', 'critical', 'icon-bullhorn', $i18n->getMessage('dailybriefing_item_media_high_title'), $i18n->getMessage('dailybriefing_item_media_high_text'), $now, $websoccer->getInternalUrl('fanpressure'), 'dailybriefing_action_fanpressure');
        }

        if ($chemistry <= 35) {
            $items[] = self::item($i18n, 'chemistry', 'important', 'icon-group', $i18n->getMessage('dailybriefing_item_chemistry_low_title'), $i18n->getMessage('dailybriefing_item_chemistry_low_text'), $now, $websoccer->getInternalUrl('training'), 'dailybriefing_action_training');
        } elseif ($chemistry >= 80) {
            $items[] = self::item($i18n, 'chemistry', 'positive', 'icon-group', $i18n->getMessage('dailybriefing_item_chemistry_high_title'), $i18n->getMessage('dailybriefing_item_chemistry_high_text'), $now, $websoccer->getInternalUrl('training'), 'dailybriefing_action_training');
        }
    }

    private static function addOpenInterviews(WebSoccer $websoccer, DbConnection $db, I18n $i18n, &$items, $userId, $teamId, $now) {
        $prefix = $websoccer->getConfig('db_prefix');
        if (!self::tableExists($db, $prefix . '_fanpressure_interview_occurrence') || !self::tableExists($db, $prefix . '_fanpressure_interview_question')) {
            return;
        }

        $from = $prefix . '_fanpressure_interview_occurrence I INNER JOIN ' . $prefix . '_fanpressure_interview_question Q ON Q.id = I.question_id';
        $where = "I.user_id = %d AND I.team_id = %d AND I.status = 'open' ORDER BY I.expires_date ASC, I.created_date DESC";
        $result = $db->querySelect('I.id,I.created_date,I.expires_date,I.event_key,Q.question', $from, $where, array((int) $userId, (int) $teamId), 2);
        while ($row = $result->fetch_array()) {
            $expiresIn = (int) $row['expires_date'] - (int) $now;
            $priority = ($expiresIn > 0 && $expiresIn <= 2 * 24 * 3600) ? 'critical' : 'important';
            $items[] = self::item(
                $i18n,
                'interview',
                $priority,
                'icon-comment',
                $i18n->getMessage('dailybriefing_item_interview_title'),
                $row['question'],
                (int) $row['created_date'],
                $websoccer->getInternalUrl('fanpressure'),
                'dailybriefing_action_interview'
            );
        }
        $result->free();
    }

    private static function addScoutingProposals(WebSoccer $websoccer, DbConnection $db, I18n $i18n, &$items, $teamId) {
        $prefix = $websoccer->getConfig('db_prefix');
        if (!self::tableExists($db, $prefix . '_scouting_proposal')) {
            return;
        }

        $columns = 'id,firstname,lastname,position,position_main,reported_strength,reported_talent,reported_potential,created_date,expires_after_matches,transfer_fee';
        $where = "team_id = %d AND status = 'open' ORDER BY created_date DESC";
        $result = $db->querySelect($columns, $prefix . '_scouting_proposal', $where, (int) $teamId, 2);
        while ($row = $result->fetch_array()) {
            $name = trim($row['firstname'] . ' ' . $row['lastname']);
            $details = array();
            if (strlen($row['position'])) {
                $details[] = $row['position'];
            }
            if (strlen($row['reported_strength'])) {
                $details[] = $i18n->getMessage('dailybriefing_strength_short') . ': ' . $row['reported_strength'];
            }
            if (strlen($row['reported_talent'])) {
                $details[] = $i18n->getMessage('dailybriefing_talent_short') . ': ' . $row['reported_talent'];
            }
            $message = sprintf($i18n->getMessage('dailybriefing_item_scouting_text'), $name);
            if (count($details)) {
                $message .= ' ' . implode(' · ', $details);
            }

            $items[] = self::item(
                $i18n,
                'scouting',
                'important',
                'icon-search',
                $i18n->getMessage('dailybriefing_item_scouting_title'),
                $message,
                (int) $row['created_date'],
                $websoccer->getInternalUrl('scouting'),
                'dailybriefing_action_scouting'
            );
        }
        $result->free();
    }

    private static function addYouthAcademyItems(WebSoccer $websoccer, DbConnection $db, I18n $i18n, &$items, $teamId) {
        $prefix = $websoccer->getConfig('db_prefix');
        if (self::tableExists($db, $prefix . '_youth_academy')) {
            $from = $prefix . '_youth_academy A LEFT JOIN ' . $prefix . '_youth_academy_level L ON L.level = A.level';
            $result = $db->querySelect('A.level,A.reputation,A.missed_payments,L.name AS level_name', $from, 'A.team_id = %d', (int) $teamId, 1);
            $academy = $result->fetch_array();
            $result->free();
            if ($academy) {
                if ((int) $academy['missed_payments'] > 0) {
                    $items[] = self::item($i18n, 'youth', 'critical', 'icon-warning-sign', $i18n->getMessage('dailybriefing_item_youth_payment_title'), $i18n->getMessage('dailybriefing_item_youth_payment_text'), 0, $websoccer->getInternalUrl('youth-academy'), 'dailybriefing_action_youth_academy');
                } elseif ((int) $academy['reputation'] >= 75) {
                    $text = sprintf($i18n->getMessage('dailybriefing_item_youth_reputation_text'), (int) $academy['reputation']);
                    $items[] = self::item($i18n, 'youth', 'positive', 'icon-star', $i18n->getMessage('dailybriefing_item_youth_reputation_title'), $text, 0, $websoccer->getInternalUrl('youth-academy'), 'dailybriefing_action_youth_academy');
                }
            }
        }

        if (!self::tableExists($db, $prefix . '_youth_academy_log')) {
            return;
        }

        $from = $prefix . '_youth_academy_log L LEFT JOIN ' . $prefix . '_youthplayer Y ON Y.id = L.player_id';
        $columns = 'L.type,L.message,L.old_strength,L.new_strength,L.change_amount,L.created_date,Y.firstname,Y.lastname';
        $where = 'L.team_id = %d ORDER BY L.created_date DESC';
        $result = $db->querySelect($columns, $from, $where, (int) $teamId, 2);
        while ($row = $result->fetch_array()) {
            $priority = ((int) $row['change_amount'] < 0 || $row['type'] == 'risk') ? 'important' : 'positive';
            $title = ($priority == 'positive') ? $i18n->getMessage('dailybriefing_item_youth_log_positive_title') : $i18n->getMessage('dailybriefing_item_youth_log_warning_title');
            $playerName = trim($row['firstname'] . ' ' . $row['lastname']);
            $message = self::translateYouthAcademyLogMessage($i18n, $row);
            if (strlen($playerName)) {
                $message = $playerName . ': ' . $message;
            }
            $items[] = self::item($i18n, 'youth', $priority, 'icon-leaf', $title, $message, (int) $row['created_date'], $websoccer->getInternalUrl('youth-academy'), 'dailybriefing_action_youth_academy');
        }
        $result->free();
    }


    private static function translateYouthAcademyLogMessage(I18n $i18n, $row) {
        $message = isset($row['message']) ? trim($row['message']) : '';
        if (!strlen($message)) {
            return $i18n->getMessage('dailybriefing_youthacademy_log_generic');
        }

        $messageMap = array(
            'youthacademy_log_built' => 'dailybriefing_youthacademy_log_built',
            'youthacademy_log_upgraded' => 'dailybriefing_youthacademy_log_upgraded',
            'youthacademy_log_downgraded' => 'dailybriefing_youthacademy_log_downgraded',
            'youthacademy_log_auto_downgraded' => 'dailybriefing_youthacademy_log_auto_downgraded',
            'youthacademy_log_development_bonus' => 'dailybriefing_youthacademy_log_development_bonus',
            'youthacademy_log_scouting_bonus' => 'dailybriefing_youthacademy_log_scouting_bonus'
        );

        if (isset($messageMap[$message])) {
            $translated = $i18n->getMessage($messageMap[$message]);
            if ((int) $row['change_amount'] !== 0) {
                $change = (int) $row['change_amount'];
                $translated .= ' (' . (($change > 0) ? '+' : '') . $change . ')';
            }
            return $translated;
        }

        // If the database contains an unknown technical key, avoid showing it
        // raw in the manager briefing. Real text messages remain unchanged.
        if (preg_match('/^[a-z0-9_]+$/i', $message)) {
            return $i18n->getMessage('dailybriefing_youthacademy_log_generic');
        }

        return $message;
    }

    private static function addLoanItems(WebSoccer $websoccer, DbConnection $db, I18n $i18n, &$items, $teamId) {
        $prefix = $websoccer->getConfig('db_prefix');
        if (!self::tableExists($db, $prefix . '_loan') || !self::tableExists($db, $prefix . '_loan_report')) {
            return;
        }

        $from = $prefix . '_loan L '
            . 'INNER JOIN ' . $prefix . '_spieler P ON P.id = L.player_id '
            . 'INNER JOIN ' . $prefix . '_verein B ON B.id = L.borrower_team_id '
            . 'LEFT JOIN ' . $prefix . '_loan_report R ON R.loan_id = L.id';
        $columns = 'L.id,L.player_id,L.created_date,L.matches_completed,L.remaining_matches,B.name AS borrower_name,'
            . 'P.vorname,P.nachname,P.kunstname,COUNT(R.id) AS report_count,SUM(CASE WHEN R.minutes_played < 20 THEN 1 ELSE 0 END) AS low_minutes_reports,MAX(R.match_date) AS last_report_date';
        $where = "L.lender_team_id = %d AND L.status = 'active' GROUP BY L.id HAVING report_count >= 2 AND low_minutes_reports >= 2 ORDER BY last_report_date DESC";
        $result = $db->querySelect($columns, $from, $where, (int) $teamId, 2);
        while ($row = $result->fetch_array()) {
            $playerName = self::playerName($row);
            $text = sprintf($i18n->getMessage('dailybriefing_item_loan_unused_text'), $playerName);
            if (strlen($row['borrower_name'])) {
                $text .= ' ' . sprintf($i18n->getMessage('dailybriefing_item_loan_borrower_suffix'), $row['borrower_name']);
            }
            $items[] = self::item($i18n, 'loans', 'important', 'icon-retweet', $i18n->getMessage('dailybriefing_item_loan_unused_title'), $text, (int) $row['last_report_date'], $websoccer->getInternalUrl('loans'), 'dailybriefing_action_loans');
        }
        $result->free();
    }

    private static function addTransferItems(WebSoccer $websoccer, DbConnection $db, I18n $i18n, &$items, $teamId) {
        $prefix = $websoccer->getConfig('db_prefix');
        if (!self::tableExists($db, $prefix . '_transfer_offer')) {
            return;
        }

        $from = $prefix . '_transfer_offer O '
            . 'INNER JOIN ' . $prefix . '_spieler P ON P.id = O.player_id '
            . 'INNER JOIN ' . $prefix . '_verein S ON S.id = O.sender_club_id';
        $columns = 'O.id,O.submitted_date,O.offer_amount,S.name AS sender_name,P.vorname,P.nachname,P.kunstname';
        $where = 'O.receiver_club_id = %d AND O.rejected_date = 0 ORDER BY O.submitted_date DESC';
        $result = $db->querySelect($columns, $from, $where, (int) $teamId, 2);
        while ($row = $result->fetch_array()) {
            $text = sprintf($i18n->getMessage('dailybriefing_item_transfer_offer_text'), $row['sender_name'], self::playerName($row));
            $items[] = self::item($i18n, 'transfers', 'important', 'icon-shopping-cart', $i18n->getMessage('dailybriefing_item_transfer_offer_title'), $text, (int) $row['submitted_date'], $websoccer->getInternalUrl('transfers'), 'dailybriefing_action_transfers');
        }
        $result->free();
    }

    private static function addLoanRequestItems(WebSoccer $websoccer, DbConnection $db, I18n $i18n, &$items, $teamId) {
        $prefix = $websoccer->getConfig('db_prefix');
        if (!self::tableExists($db, $prefix . '_loan_request')) {
            return;
        }

        $from = $prefix . '_loan_request R '
            . 'INNER JOIN ' . $prefix . '_spieler P ON P.id = R.player_id '
            . 'INNER JOIN ' . $prefix . '_verein B ON B.id = R.borrower_team_id';
        $columns = 'R.id,R.created_date,R.requested_matches,B.name AS borrower_name,P.vorname,P.nachname,P.kunstname';
        $where = "R.lender_team_id = %d AND R.status = 'open' ORDER BY R.created_date DESC";
        $result = $db->querySelect($columns, $from, $where, (int) $teamId, 2);
        while ($row = $result->fetch_array()) {
            $text = sprintf($i18n->getMessage('dailybriefing_item_loan_request_text'), $row['borrower_name'], self::playerName($row));
            $items[] = self::item($i18n, 'loans', 'important', 'icon-share', $i18n->getMessage('dailybriefing_item_loan_request_title'), $text, (int) $row['created_date'], $websoccer->getInternalUrl('loans'), 'dailybriefing_action_loans');
        }
        $result->free();
    }

    private static function addWhatChangedItem(WebSoccer $websoccer, DbConnection $db, I18n $i18n, &$items, $userId, $teamId) {
        $prefix = $websoccer->getConfig('db_prefix');
        if (!self::tableExists($db, $prefix . '_what_changed')) {
            return;
        }

        $result = $db->querySelect('id,match_id,match_date,summary_title,summary_data', $prefix . '_what_changed', 'user_id = %d AND team_id = %d ORDER BY match_date DESC, id DESC', array((int) $userId, (int) $teamId), 1);
        $row = $result->fetch_array();
        $result->free();
        if (!$row) {
            return;
        }

        $priority = 'hint';
        $title = $i18n->getMessage('dailybriefing_item_whatchanged_title');
        $summary = self::jsonDecode($row['summary_data']);
        if (isset($summary['match']['outcome'])) {
            if ($summary['match']['outcome'] == 'loss') {
                $priority = 'important';
                $title = $i18n->getMessage('dailybriefing_item_whatchanged_loss_title');
            } elseif ($summary['match']['outcome'] == 'win') {
                $priority = 'positive';
                $title = $i18n->getMessage('dailybriefing_item_whatchanged_win_title');
            }
        }

        $items[] = self::item($i18n, 'what_changed', $priority, 'icon-list-alt', $title, $row['summary_title'], (int) $row['match_date'], $websoccer->getInternalUrl('what-changed', 'id=' . (int) $row['id']), 'dailybriefing_action_whatchanged');
    }

    private static function addFanMediaStoryItems(WebSoccer $websoccer, DbConnection $db, I18n $i18n, &$items, $teamId) {
        $prefix = $websoccer->getConfig('db_prefix');
        if (!self::tableExists($db, $prefix . '_fanpressure_story_log')) {
            return;
        }

        $columns = 'L.id,L.team_id,L.user_id,L.event_key,L.event_date,L.source,L.title,L.message,L.mood_change,L.pressure_change,L.board_change,L.chemistry_change,L.new_pressure,L.new_mood,L.new_board_satisfaction,L.new_chemistry,L.context_data,T.name AS team_name';
        $from = $prefix . '_fanpressure_story_log L LEFT JOIN ' . $prefix . '_verein T ON T.id = L.team_id';
        $where = 'L.team_id = %d ORDER BY L.event_date DESC, L.id DESC';
        $result = $db->querySelect($columns, $from, $where, (int) $teamId, 3);
        while ($row = $result->fetch_array()) {
            if (class_exists('FanPressureDataService')) {
                $row = FanPressureDataService::normalizeStoryDisplayRow($websoccer, $i18n, $row);
            }
            $priority = 'hint';
            if ((int) $row['pressure_change'] >= 5 || (int) $row['new_pressure'] >= 70 || (int) $row['board_change'] <= -5 || (int) $row['mood_change'] <= -5) {
                $priority = 'important';
            } elseif ((int) $row['mood_change'] > 0 || (int) $row['board_change'] > 0 || (int) $row['chemistry_change'] > 0) {
                $priority = 'positive';
            }
            $source = 'fanmedia';
            $icon = 'icon-bullhorn';
            $linkLabel = 'dailybriefing_action_fanpressure';
            if (isset($row['event_key']) && strpos((string) $row['event_key'], 'fanpressure_reason_trait_') === 0) {
                $source = 'traits';
                $icon = 'icon-star';
                $priority = ($priority == 'hint') ? 'positive' : $priority;
            }
            $items[] = self::item($i18n, $source, $priority, $icon, $row['title'], $row['message'], (int) $row['event_date'], $websoccer->getInternalUrl('fanpressure'), $linkLabel);
        }
        $result->free();
    }

    private static function addUpcomingMatchItem(WebSoccer $websoccer, DbConnection $db, I18n $i18n, &$items, $teamId, $now) {
        $prefix = $websoccer->getConfig('db_prefix');
        if (!self::tableExists($db, $prefix . '_spiel')) {
            return;
        }

        $from = $prefix . '_spiel M '
            . 'INNER JOIN ' . $prefix . '_verein H ON H.id = M.home_verein '
            . 'INNER JOIN ' . $prefix . '_verein G ON G.id = M.gast_verein';
        $columns = 'M.id,M.datum,M.spieltyp,M.home_verein,M.gast_verein,H.name AS home_name,G.name AS guest_name,H.strength AS home_strength,G.strength AS guest_strength';
        $where = "M.berechnet = '0' AND M.blocked = '0' AND M.datum >= %d AND (M.home_verein = %d OR M.gast_verein = %d) ORDER BY M.datum ASC";
        $result = $db->querySelect($columns, $from, $where, array((int) $now, (int) $teamId, (int) $teamId), 1);
        $row = $result->fetch_array();
        $result->free();
        if (!$row) {
            return;
        }

        $isHome = ((int) $row['home_verein'] === (int) $teamId);
        $opponent = $isHome ? $row['guest_name'] : $row['home_name'];
        $ownStrength = $isHome ? (int) $row['home_strength'] : (int) $row['guest_strength'];
        $oppStrength = $isHome ? (int) $row['guest_strength'] : (int) $row['home_strength'];
        $priority = ($oppStrength > $ownStrength + 8) ? 'important' : 'hint';
        $text = sprintf($i18n->getMessage('dailybriefing_item_nextmatch_text'), $opponent);
        $items[] = self::item($i18n, 'nextmatch', $priority, 'icon-calendar', $i18n->getMessage('dailybriefing_item_nextmatch_title'), $text, (int) $row['datum'], $websoccer->getInternalUrl('formation'), 'dailybriefing_action_formation');
    }

    private static function addFallbackItems(WebSoccer $websoccer, I18n $i18n, &$items, $team) {
        if (count($items) >= self::MIN_ITEMS) {
            return;
        }

        $now = (int) $websoccer->getNowAsTimestamp();
        $fallbacks = array(
            self::item($i18n, 'routine', 'hint', 'icon-ok-circle', $i18n->getMessage('dailybriefing_item_fallback_training_title'), $i18n->getMessage('dailybriefing_item_fallback_training_text'), $now, $websoccer->getInternalUrl('training'), 'dailybriefing_action_training'),
            self::item($i18n, 'routine', 'hint', 'icon-eye-open', $i18n->getMessage('dailybriefing_item_fallback_scouting_title'), $i18n->getMessage('dailybriefing_item_fallback_scouting_text'), $now, $websoccer->getInternalUrl('scouting'), 'dailybriefing_action_scouting'),
            self::item($i18n, 'routine', 'hint', 'icon-signal', $i18n->getMessage('dailybriefing_item_fallback_finance_title'), $i18n->getMessage('dailybriefing_item_fallback_finance_text'), $now, $websoccer->getInternalUrl('finances'), 'dailybriefing_action_finances')
        );

        foreach ($fallbacks as $fallback) {
            if (count($items) >= self::MIN_ITEMS) {
                break;
            }
            $fallback['priority_score'] = 5;
            $items[] = $fallback;
        }
    }

    private static function item(I18n $i18n, $source, $priority, $icon, $title, $message, $date, $link, $linkLabelKey) {
        $scoreMap = array(
            'critical' => 100,
            'important' => 75,
            'hint' => 45,
            'positive' => 35
        );
        $cssMap = array(
            'critical' => 'important',
            'important' => 'warning',
            'hint' => 'info',
            'positive' => 'success'
        );
        $labelKeyMap = array(
            'critical' => 'dailybriefing_priority_critical',
            'important' => 'dailybriefing_priority_important',
            'hint' => 'dailybriefing_priority_hint',
            'positive' => 'dailybriefing_priority_positive'
        );

        return array(
            'source' => $source,
            'priority' => $priority,
            'priority_score' => isset($scoreMap[$priority]) ? $scoreMap[$priority] : 0,
            'priority_css' => isset($cssMap[$priority]) ? $cssMap[$priority] : 'info',
            'priority_label' => $i18n->getMessage(isset($labelKeyMap[$priority]) ? $labelKeyMap[$priority] : 'dailybriefing_priority_hint'),
            'icon' => $icon,
            'title' => $title,
            'message' => $message,
            'date' => (int) $date,
            'link' => $link,
            'link_label' => $i18n->getMessage($linkLabelKey)
        );
    }

    private static function sortAndLimitItems($items, $limit) {
        usort($items, array('DailyBriefingDataService', 'compareItems'));
        return array_slice($items, 0, (int) $limit);
    }

    public static function compareItems($a, $b) {
        if ((int) $a['priority_score'] !== (int) $b['priority_score']) {
            return ((int) $a['priority_score'] > (int) $b['priority_score']) ? -1 : 1;
        }
        if ((int) $a['date'] === (int) $b['date']) {
            return 0;
        }
        return ((int) $a['date'] > (int) $b['date']) ? -1 : 1;
    }

    private static function tableExists(DbConnection $db, $tableName) {
        static $cache = array();
        if (isset($cache[$tableName])) {
            return $cache[$tableName];
        }
        $safeTable = $db->connection->real_escape_string($tableName);
        $result = $db->executeQuery("SHOW TABLES LIKE '" . $safeTable . "'");
        $exists = ($result && $result->num_rows > 0);
        if ($result) {
            $result->free();
        }
        $cache[$tableName] = $exists;
        return $exists;
    }

    private static function jsonDecode($json) {
        if (!strlen($json)) {
            return array();
        }
        $data = json_decode($json, TRUE);
        return is_array($data) ? $data : array();
    }

    private static function playerName($row) {
        if (isset($row['kunstname']) && strlen($row['kunstname'])) {
            return $row['kunstname'];
        }
        return trim((isset($row['vorname']) ? $row['vorname'] : '') . ' ' . (isset($row['nachname']) ? $row['nachname'] : ''));
    }
}

?>
