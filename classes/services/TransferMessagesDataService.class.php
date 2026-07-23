<?php

/**
 * Creates persistent inbox messages for transfers, loans and pre-contracts.
 * These messages intentionally replace short-lived notifications.
 */
class TransferMessagesDataService {

    const CATEGORY_TRANSFER = 'transfer';
    const CATEGORY_LOAN = 'loan';
    const CATEGORY_PRECONTRACT = 'precontract';

    private static $topMarketValueThresholdCache = array();

    public static function createOfferReceived(WebSoccer $websoccer, DbConnection $db, $recipientUserId, $playerId, $senderClubId, $receiverClubId, $amount, $details = array()) {
        $player = self::getPlayer($websoccer, $db, $playerId);
        $sender = self::getClub($websoccer, $db, $senderClubId);
        $receiver = self::getClub($websoccer, $db, $receiverClubId);
        $playerName = self::playerName($player);

        $context = self::baseContext(self::CATEGORY_TRANSFER, 'offer_received', $player, $receiver, $sender, 'transfermarket');
        $context['amount'] = (int) $amount;
        $context['target_anchor'] = 'offers';
        $context['sender_club'] = $sender;
        $context = array_merge($context, $details);

        self::createInboxMessage(
            $websoccer,
            $db,
            $recipientUserId,
            'Kaufangebot für ' . $playerName,
            $sender['name'] . ' hat ein Kaufangebot für ' . $playerName . ' abgegeben. Das Angebot kann auf der Seite Transferangebote geprüft werden.',
            'transfer_offer',
            $context,
            $sender['name']
        );
    }

    public static function createOfferAccepted(WebSoccer $websoccer, DbConnection $db, $recipientUserId, $playerId, $sellerClubId, $buyerClubId, $amount = 0, $details = array(), $approvalPending = false) {
        $player = self::getPlayer($websoccer, $db, $playerId);
        $seller = self::getClub($websoccer, $db, $sellerClubId);
        $buyer = self::getClub($websoccer, $db, $buyerClubId);
        $playerName = self::playerName($player);
        $context = self::baseContext(self::CATEGORY_TRANSFER, $approvalPending ? 'offer_accepted_pending' : 'offer_accepted', $player, $seller, $buyer, 'transfermarket');
        $context['amount'] = (int) $amount;
        $context['target_anchor'] = 'offers';
        $context['sender_club'] = $seller;
        $context = array_merge($context, $details);
        $content = $seller['name'] . ' hat das Kaufangebot für ' . $playerName . ' angenommen.';
        if ($approvalPending) {
            $content .= ' Der Transfer wartet noch auf die Freigabe durch die Spielleitung.';
        }
        self::createInboxMessage($websoccer, $db, $recipientUserId, 'Angebot angenommen: ' . $playerName, $content, 'transfer_offer', $context, $seller['name']);
    }

    public static function createOfferRejected(WebSoccer $websoccer, DbConnection $db, $recipientUserId, $playerId, $sellerClubId, $buyerClubId, $reason = '') {
        $player = self::getPlayer($websoccer, $db, $playerId);
        $seller = self::getClub($websoccer, $db, $sellerClubId);
        $buyer = self::getClub($websoccer, $db, $buyerClubId);
        $playerName = self::playerName($player);
        $context = self::baseContext(self::CATEGORY_TRANSFER, 'offer_rejected', $player, $seller, $buyer, 'transfermarket');
        $context['target_anchor'] = 'offers';
        $context['sender_club'] = $seller;
        if (strlen(trim((string) $reason))) {
            $context['reason'] = trim((string) $reason);
        }

        self::createInboxMessage(
            $websoccer,
            $db,
            $recipientUserId,
            'Angebot abgelehnt: ' . $playerName,
            $seller['name'] . ' hat das Kaufangebot für ' . $playerName . ' abgelehnt.',
            'transfer_offer',
            $context,
            $seller['name']
        );
    }

    public static function createOfferWithdrawn(WebSoccer $websoccer, DbConnection $db, $recipientUserId, $playerId, $sellerClubId, $buyerClubId) {
        $player = self::getPlayer($websoccer, $db, $playerId);
        $seller = self::getClub($websoccer, $db, $sellerClubId);
        $buyer = self::getClub($websoccer, $db, $buyerClubId);
        $playerName = self::playerName($player);
        $context = self::baseContext(self::CATEGORY_TRANSFER, 'offer_withdrawn', $player, $seller, $buyer, 'transfermarket');
        $context['target_anchor'] = 'offers';
        $context['sender_club'] = $buyer;

        self::createInboxMessage(
            $websoccer,
            $db,
            $recipientUserId,
            'Angebot zurückgezogen: ' . $playerName,
            $buyer['name'] . ' hat das Kaufangebot für ' . $playerName . ' zurückgezogen.',
            'transfer_offer',
            $context,
            $buyer['name']
        );
    }

    public static function createOutbidMessage(WebSoccer $websoccer, DbConnection $db, $recipientUserId, $playerId, $clubId, $newAmount = 0) {
        $player = self::getPlayer($websoccer, $db, $playerId);
        $club = self::getClub($websoccer, $db, $clubId);
        $playerName = self::playerName($player);
        $context = self::baseContext(self::CATEGORY_TRANSFER, 'outbid', $player, array(), $club, 'transfermarket');
        $context['target_query'] = 'id=' . (int) $playerId;
        $context['amount'] = (int) $newAmount;

        self::createInboxMessage(
            $websoccer,
            $db,
            $recipientUserId,
            'Überboten: ' . $playerName,
            'Das Gebot für ' . $playerName . ' ist nicht mehr das Höchstgebot.',
            'transfer_offer',
            $context,
            'Transferabteilung'
        );
    }

    public static function createTransferCompleted(WebSoccer $websoccer, DbConnection $db, $playerId, $sellerClubId, $buyerClubId, $amount, $sellerUserId = 0, $buyerUserId = 0, $details = array()) {
        $player = self::getPlayer($websoccer, $db, $playerId);
        $seller = self::getClub($websoccer, $db, $sellerClubId);
        $buyer = self::getClub($websoccer, $db, $buyerClubId);
        $playerName = self::playerName($player);
        $context = self::baseContext(self::CATEGORY_TRANSFER, 'completed', $player, $seller, $buyer, 'myteam');
        $context['amount'] = (int) $amount;
        $context = array_merge($context, $details);

        if ((int) $sellerUserId > 0) {
            $sellerContext = $context;
            $sellerContext['sender_club'] = $buyer;
            self::createInboxMessage(
                $websoccer,
                $db,
                $sellerUserId,
                'Spieler abgegeben: ' . $playerName,
                $playerName . ' hat den Verein verlassen und ist zu ' . $buyer['name'] . ' gewechselt.',
                'transfer_completed',
                $sellerContext,
                $buyer['name']
            );
        }

        if ((int) $buyerUserId > 0) {
            $buyerContext = $context;
            $buyerContext['target_page'] = 'myteam';
            if ((int) $seller['id'] > 0) {
                $buyerContext['sender_club'] = $seller;
            }
            self::createInboxMessage(
                $websoccer,
                $db,
                $buyerUserId,
                'Neuzugang: ' . $playerName,
                $playerName . ' ist von ' . self::clubNameOrFreeAgent($seller) . ' zu ' . $buyer['name'] . ' gewechselt.',
                'transfer_completed',
                $buyerContext,
                self::clubNameOrTransferDepartment($seller)
            );
        }

        self::maybeCreateMajorTransferNews($websoccer, $db, $player, $seller, $buyer, (int) $amount);

        if (class_exists('PlayerTalentChangeDataService')) {
            PlayerTalentChangeDataService::applyTransferChance($websoccer, $db, (int) $playerId, (int) $buyerClubId, (int) $buyerUserId);
        }
    }

    public static function createLoanMessage(WebSoccer $websoccer, DbConnection $db, $recipientUserId, $event, $playerId, $lenderClubId, $borrowerClubId, $details = array(), $senderClubId = 0) {
        if ((int) $recipientUserId < 1) {
            return;
        }

        $player = self::getPlayer($websoccer, $db, $playerId);
        $lender = self::getClub($websoccer, $db, $lenderClubId);
        $borrower = self::getClub($websoccer, $db, $borrowerClubId);
        $sender = self::getClub($websoccer, $db, $senderClubId);
        $playerName = self::playerName($player);
        $context = self::baseContext(self::CATEGORY_LOAN, $event, $player, $lender, $borrower, 'loans');
        if ((int) $sender['id'] > 0) {
            $context['sender_club'] = $sender;
        }
        $context = array_merge($context, $details);

        $texts = self::loanTexts($event, $playerName, $lender, $borrower);
        self::createInboxMessage(
            $websoccer,
            $db,
            $recipientUserId,
            $texts['subject'],
            $texts['content'],
            'loan',
            $context,
            isset($sender['name']) && strlen($sender['name']) ? $sender['name'] : 'Transferabteilung'
        );
    }

    public static function createPrecontractMessage(WebSoccer $websoccer, DbConnection $db, $recipientUserId, $event, $playerId, $currentClubId, $destinationClubId, $details = array()) {
        if ((int) $recipientUserId < 1) {
            return;
        }

        $player = self::getPlayer($websoccer, $db, $playerId);
        $current = self::getClub($websoccer, $db, $currentClubId);
        $destination = self::getClub($websoccer, $db, $destinationClubId);
        $playerName = self::playerName($player);
        $context = self::baseContext(self::CATEGORY_PRECONTRACT, $event, $player, $current, $destination, 'myteam');
        $context = array_merge($context, $details);

        $subject = 'Vorvertrag: ' . $playerName;
        $content = $playerName . ' hat das Vorvertragsangebot von ' . $destination['name'] . ' angenommen.';
        $senderName = 'Transferabteilung';

        if ($event === 'received') {
            $subject = 'Vorvertragsangebot für ' . $playerName;
            $content = $destination['name'] . ' hat ' . $playerName . ' ein Vertragsangebot für die nächste Saison unterbreitet. Ihr Verein kann über die Vertragsverlängerung ein Gegenangebot abgeben.';
            $context['target_page'] = 'extend-contract';
            $context['target_query'] = 'id=' . (int) $playerId;
            $senderName = $destination['name'];
        } elseif ($event === 'rejected') {
            $subject = 'Vorvertrag abgelehnt: ' . $playerName;
            $content = $playerName . ' hat das Vorvertragsangebot von ' . $destination['name'] . ' abgelehnt.';
        } elseif ($event === 'retained') {
            $subject = 'Vertrag verlängert: ' . $playerName;
            $content = $playerName . ' hat das Gegenangebot Ihres Vereins angenommen und den Vertrag verlängert.';
            $context['target_page'] = 'myteam';
            $context['target_query'] = '';
        } elseif ($event === 'retention_rejected') {
            $subject = 'Vertragsverlängerung abgelehnt: ' . $playerName;
            $content = $playerName . ' hat das Gegenangebot Ihres Vereins abgelehnt und sich für einen Wechsel zur nächsten Saison entschieden.';
        } elseif ($event === 'leaving') {
            $subject = 'Vorvertrag unterschrieben: ' . $playerName;
            $content = $playerName . ' hat ein Vertragsangebot von ' . $destination['name'] . ' angenommen und wird den Verein zur nächsten Saison verlassen.';
        } elseif ($event === 'completed') {
            $subject = 'Vorvertrag vollzogen: ' . $playerName;
            $content = $playerName . ' ist zur neuen Saison zu ' . $destination['name'] . ' gewechselt.';
        } elseif ($event === 'contract_extended') {
            $subject = 'Vorvertrag beendet: ' . $playerName;
            $content = 'Der aktuelle Verein hat den Vertrag mit ' . $playerName . ' verlängert. Ihr Vorvertragsangebot wurde deshalb automatisch zurückgezogen.';
            $context['target_page'] = 'player';
            $context['target_query'] = 'id=' . (int) $playerId;
        }

        self::createInboxMessage($websoccer, $db, $recipientUserId, $subject, $content, 'precontract', $context, $senderName);
    }

    public static function getPlayerReference(WebSoccer $websoccer, DbConnection $db, $playerId) {
        $player = self::getPlayer($websoccer, $db, $playerId);
        return array(
            'id' => isset($player['id']) ? (int) $player['id'] : (int) $playerId,
            'name' => self::playerName($player)
        );
    }

    public static function createMajorTransferNewsForPlayer(WebSoccer $websoccer, DbConnection $db, $playerId, $sellerClubId, $buyerClubId, $amount = 0) {
        self::maybeCreateMajorTransferNews(
            $websoccer,
            $db,
            self::getPlayer($websoccer, $db, $playerId),
            self::getClub($websoccer, $db, $sellerClubId),
            self::getClub($websoccer, $db, $buyerClubId),
            (int) $amount
        );
    }

    private static function createInboxMessage(WebSoccer $websoccer, DbConnection $db, $recipientUserId, $subject, $content, $messageType, $context, $senderName) {
        if ((int) $recipientUserId < 1) {
            return;
        }

        $subject = trim((string) $subject);
        if (function_exists('mb_substr')) {
            $subject = mb_substr($subject, 0, 50, 'UTF-8');
        } else {
            $subject = substr($subject, 0, 50);
        }

        $columns = array(
            'empfaenger_id' => (int) $recipientUserId,
            'absender_id' => null,
            'absender_name' => strlen(trim((string) $senderName)) ? trim((string) $senderName) : 'Transferabteilung',
            'datum' => $websoccer->getNowAsTimestamp(),
            'betreff' => $subject,
            'nachricht' => trim((string) $content),
            'gelesen' => '0',
            'typ' => 'eingang',
            'message_type' => (string) $messageType,
            'context_data' => json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        $db->queryInsert($columns, $websoccer->getConfig('db_prefix') . '_briefe');
    }

    private static function baseContext($category, $event, $player, $fromClub, $toClub, $targetPage) {
        return array(
            'category' => $category,
            'event' => $event,
            'player' => array(
                'id' => isset($player['id']) ? (int) $player['id'] : 0,
                'name' => self::playerName($player)
            ),
            'from_club' => $fromClub,
            'to_club' => $toClub,
            'target_page' => $targetPage,
            'target_query' => ''
        );
    }

    private static function getPlayer(WebSoccer $websoccer, DbConnection $db, $playerId) {
        $result = $db->querySelect('id,vorname,nachname,kunstname,marktwert,w_staerke,vertrag_spiele,vertrag_gehalt,vertrag_torpraemie', $websoccer->getConfig('db_prefix') . '_spieler', 'id = %d', (int) $playerId, 1);
        $player = $result->fetch_array();
        $result->free();
        return $player ? $player : array('id' => (int) $playerId, 'vorname' => '', 'nachname' => '', 'kunstname' => 'Spieler');
    }

    private static function getClub(WebSoccer $websoccer, DbConnection $db, $clubId) {
        if ((int) $clubId < 1) {
            return array('id' => 0, 'name' => '', 'logo' => '', 'user_id' => 0);
        }
        $result = $db->querySelect('id,name,bild,user_id', $websoccer->getConfig('db_prefix') . '_verein', 'id = %d', (int) $clubId, 1);
        $club = $result->fetch_array();
        $result->free();
        if (!$club) {
            return array('id' => (int) $clubId, 'name' => 'Unbekannter Verein', 'logo' => '', 'user_id' => 0);
        }
        return array(
            'id' => (int) $club['id'],
            'name' => (string) $club['name'],
            'logo' => (string) $club['bild'],
            'user_id' => (int) $club['user_id']
        );
    }

    private static function playerName($player) {
        if (isset($player['kunstname']) && strlen(trim((string) $player['kunstname']))) {
            return trim((string) $player['kunstname']);
        }
        $name = trim((isset($player['vorname']) ? $player['vorname'] : '') . ' ' . (isset($player['nachname']) ? $player['nachname'] : ''));
        return strlen($name) ? $name : 'Spieler';
    }

    private static function clubNameOrFreeAgent($club) {
        return (isset($club['id']) && (int) $club['id'] > 0 && strlen((string) $club['name'])) ? $club['name'] : 'einem vertragslosen Status';
    }

    private static function clubNameOrTransferDepartment($club) {
        return (isset($club['id']) && (int) $club['id'] > 0 && strlen((string) $club['name'])) ? $club['name'] : 'Transferabteilung';
    }

    private static function loanTexts($event, $playerName, $lender, $borrower) {
        $lenderName = strlen($lender['name']) ? $lender['name'] : 'dem Stammverein';
        $borrowerName = strlen($borrower['name']) ? $borrower['name'] : 'dem Leihverein';
        $map = array(
            'request_received' => array('subject' => 'Leihanfrage für ' . $playerName, 'content' => $borrowerName . ' möchte ' . $playerName . ' ausleihen. Die Anfrage kann auf der Seite Leihen geprüft werden.'),
            'request_accepted' => array('subject' => 'Leihanfrage angenommen: ' . $playerName, 'content' => 'Die Leihanfrage für ' . $playerName . ' wurde angenommen. Der Spieler wechselt vorübergehend zu ' . $borrowerName . '.'),
            'request_rejected' => array('subject' => 'Leihanfrage abgelehnt: ' . $playerName, 'content' => $lenderName . ' hat die Leihanfrage für ' . $playerName . ' abgelehnt.'),
            'started' => array('subject' => 'Spieler verliehen: ' . $playerName, 'content' => $playerName . ' wurde von ' . $lenderName . ' an ' . $borrowerName . ' verliehen.'),
            'returned' => array('subject' => 'Leihe beendet: ' . $playerName, 'content' => $playerName . ' ist von ' . $borrowerName . ' zu ' . $lenderName . ' zurückgekehrt.'),
            'recalled' => array('subject' => 'Leihe zurückgerufen: ' . $playerName, 'content' => $playerName . ' wurde von ' . $borrowerName . ' zu ' . $lenderName . ' zurückgerufen.'),
            'bought' => array('subject' => 'Kaufoption genutzt: ' . $playerName, 'content' => $borrowerName . ' hat ' . $playerName . ' fest verpflichtet.'),
            'obligation_failed' => array('subject' => 'Kaufpflicht gescheitert: ' . $playerName, 'content' => 'Die Kaufpflicht für ' . $playerName . ' konnte wegen eines unzureichenden Budgets von ' . $borrowerName . ' nicht ausgeführt werden.')
        );
        return isset($map[$event]) ? $map[$event] : array('subject' => 'Leihgeschäft: ' . $playerName, 'content' => 'Zum Leihgeschäft von ' . $playerName . ' gibt es eine neue Information.');
    }

    private static function maybeCreateMajorTransferNews(WebSoccer $websoccer, DbConnection $db, $player, $seller, $buyer, $amount) {
        if (!self::configBool($websoccer, 'transfer_news_enabled', true) || (int) $buyer['id'] < 1) {
            return;
        }

        $marketValue = isset($player['marktwert']) ? (int) $player['marktwert'] : 0;
        $strength = isset($player['w_staerke']) ? (float) $player['w_staerke'] : 0;
        $marketMin = self::configInt($websoccer, 'transfer_news_market_value_min', 40000000);
        $strengthMin = self::configInt($websoccer, 'transfer_news_strength_min', 90);
        $feeMin = self::configInt($websoccer, 'transfer_news_fee_min', 30000000);
        $topPercent = max(1, min(20, self::configInt($websoccer, 'transfer_news_top_percent', 2)));
        $topThreshold = self::getTopMarketValueThreshold($websoccer, $db, $topPercent);

        $isMajor = ($marketMin > 0 && $marketValue >= $marketMin)
            || ($strengthMin > 0 && $strength >= $strengthMin)
            || ($feeMin > 0 && $amount >= $feeMin)
            || ($topThreshold > 0 && $marketValue >= $topThreshold);
        if (!$isMajor) {
            return;
        }

        $playerName = self::playerName($player);
        $title = 'Transfercoup: ' . $playerName . ' zu ' . $buyer['name'];
        if (function_exists('mb_substr')) {
            $title = mb_substr($title, 0, 100, 'UTF-8');
        } else {
            $title = substr($title, 0, 100);
        }

        $existing = $db->querySelect('id', $websoccer->getConfig('db_prefix') . '_news', 'datum >= %d AND titel = \'%s\'', array($websoccer->getNowAsTimestamp() - 86400, $title), 1);
        $row = $existing->fetch_array();
        $existing->free();
        if ($row) {
            return;
        }

        $playerLabel = htmlspecialchars($playerName, ENT_QUOTES, 'UTF-8');
        $buyerLabel = htmlspecialchars($buyer['name'], ENT_QUOTES, 'UTF-8');
        $message = '<strong>' . $playerLabel . '</strong> wechselt zu <strong>' . $buyerLabel . '</strong>.';
        if ((int) $seller['id'] > 0) {
            $sellerLabel = htmlspecialchars($seller['name'], ENT_QUOTES, 'UTF-8');
            $message = '<strong>' . $playerLabel . '</strong> wechselt von <strong>' . $sellerLabel . '</strong> zu <strong>' . $buyerLabel . '</strong>.';
        }
        if ($amount > 0) {
            $currency = trim((string) $websoccer->getConfig('game_currency'));
            if (!strlen($currency)) {
                $currency = 'EUR';
            }
            $message .= ' Die Ablösesumme beträgt <strong>' . number_format($amount, 0, ',', '.') . ' ' . htmlspecialchars($currency, ENT_QUOTES, 'UTF-8') . '</strong>.';
        } else {
            $message .= ' Der Wechsel erfolgt ablösefrei.';
        }
        $logoLine = self::majorTransferLogoLine($websoccer, $seller, $buyer);
        if (strlen($logoLine)) {
            $message .= $logoLine;
        }

        $columns = array(
            'datum' => $websoccer->getNowAsTimestamp(),
            'autor_id' => 1,
            'titel' => $title,
            'nachricht' => $message,
            'linktext1' => 'Zum Spieler',
            'linkurl1' => '?page=player&id=' . (int) $player['id'],
            'linktext2' => 'Zum neuen Verein',
            'linkurl2' => '?page=team&id=' . (int) $buyer['id'],
            'c_br' => '0',
            'c_links' => '0',
            'c_smilies' => '0',
            'status' => '1'
        );
        if ((int) $seller['id'] > 0) {
            $columns['linktext3'] = 'Zum bisherigen Verein';
            $columns['linkurl3'] = '?page=team&id=' . (int) $seller['id'];
        }
        $db->queryInsert($columns, $websoccer->getConfig('db_prefix') . '_news');
    }

    private static function majorTransferLogoLine(WebSoccer $websoccer, $seller, $buyer) {
        $sellerLogo = self::clubLogoHtml($websoccer, $seller);
        $buyerLogo = self::clubLogoHtml($websoccer, $buyer);
        if (!strlen($sellerLogo) && !strlen($buyerLogo)) {
            return '';
        }

        $parts = array();
        if (strlen($sellerLogo)) {
            $parts[] = $sellerLogo;
        }
        if (strlen($sellerLogo) && strlen($buyerLogo)) {
            $parts[] = '<span style="display:inline-block;margin:0 14px;font-size:22px;vertical-align:middle;">&rarr;</span>';
        }
        if (strlen($buyerLogo)) {
            $parts[] = $buyerLogo;
        }
        return "\n" . '<div style="margin:14px 0 8px;">' . implode('', $parts) . '</div>';
    }

    private static function clubLogoHtml(WebSoccer $websoccer, $club) {
        if (!isset($club['id'], $club['logo']) || (int) $club['id'] < 1 || !strlen(trim((string) $club['logo']))) {
            return '';
        }
        $url = rtrim((string) $websoccer->getConfig('context_root'), '/') . '/uploads/club/' . ltrim((string) $club['logo'], '/');
        return '<a href="?page=team&id=' . (int) $club['id'] . '" title="'
            . htmlspecialchars((string) $club['name'], ENT_QUOTES, 'UTF-8') . '"><img src="'
            . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" alt="'
            . htmlspecialchars((string) $club['name'], ENT_QUOTES, 'UTF-8')
            . '" style="max-width:70px;max-height:70px;vertical-align:middle;"></a>';
    }

    private static function getTopMarketValueThreshold(WebSoccer $websoccer, DbConnection $db, $percent) {
        $cacheKey = (int) $percent;
        if (isset(self::$topMarketValueThresholdCache[$cacheKey])) {
            return self::$topMarketValueThresholdCache[$cacheKey];
        }
        $table = $websoccer->getConfig('db_prefix') . '_spieler';
        $countResult = $db->querySelect('COUNT(*) AS hits', $table, "status = '1'");
        $countRow = $countResult->fetch_array();
        $countResult->free();
        $count = $countRow ? (int) $countRow['hits'] : 0;
        if ($count < 1) {
            self::$topMarketValueThresholdCache[$cacheKey] = 0;
            return 0;
        }
        $offset = max(0, (int) ceil($count * ($percent / 100)) - 1);
        $result = $db->querySelect('CAST(marktwert AS UNSIGNED) AS marketvalue', $table, "status = '1' ORDER BY CAST(marktwert AS UNSIGNED) DESC", null, $offset . ',1');
        $row = $result->fetch_array();
        $result->free();
        $threshold = $row ? (int) $row['marketvalue'] : 0;
        self::$topMarketValueThresholdCache[$cacheKey] = $threshold;
        return $threshold;
    }

    private static function configInt(WebSoccer $websoccer, $key, $default) {
        $value = $websoccer->getConfig($key);
        return ($value === null || $value === '') ? (int) $default : (int) $value;
    }

    private static function configBool(WebSoccer $websoccer, $key, $default) {
        $value = $websoccer->getConfig($key);
        if ($value === null || $value === '') {
            return (bool) $default;
        }
        return ((string) $value === '1' || $value === true);
    }
}

?>
