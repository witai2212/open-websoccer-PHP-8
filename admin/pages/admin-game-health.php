<?php
/******************************************************

  Admin Game Health Check for CM23 / OpenWebSoccer-Sim.
  Shows live consistency checks and selected safe repair actions.

******************************************************/

if (!$admin['r_admin'] && !$admin['r_demo']) {
    echo '<p>' . $i18n->getMessage('error_access_denied') . '</p>';
    exit;
}

function ghMsg($i18n, $key, $fallback) {
    return $i18n->hasMessage($key) ? $i18n->getMessage($key) : $fallback;
}

function ghPrefix(WebSoccer $websoccer) {
    return $websoccer->getConfig('db_prefix');
}

function ghTable(WebSoccer $websoccer, $shortName) {
    return ghPrefix($websoccer) . '_' . $shortName;
}

function ghQuoteTable($tableName) {
    return '`' . str_replace('`', '``', $tableName) . '`';
}

function ghTableExists(WebSoccer $websoccer, DbConnection $db, $shortName) {
    static $cache = array();
    $table = ghTable($websoccer, $shortName);
    if (isset($cache[$table])) {
        return $cache[$table];
    }
    $like = $db->connection->real_escape_string($table);
    $result = $db->executeQuery("SHOW TABLES LIKE '" . $like . "'");
    $exists = ($result && $result->num_rows > 0);
    if ($result) {
        $result->free();
    }
    $cache[$table] = $exists;
    return $exists;
}

function ghColumnExists(WebSoccer $websoccer, DbConnection $db, $shortName, $column) {
    static $cache = array();
    $table = ghTable($websoccer, $shortName);
    $key = $table . '.' . $column;
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    if (!ghTableExists($websoccer, $db, $shortName)) {
        $cache[$key] = FALSE;
        return FALSE;
    }
    $columnEsc = $db->connection->real_escape_string($column);
    $result = $db->executeQuery('SHOW COLUMNS FROM ' . ghQuoteTable($table) . " LIKE '" . $columnEsc . "'");
    $exists = ($result && $result->num_rows > 0);
    if ($result) {
        $result->free();
    }
    $cache[$key] = $exists;
    return $exists;
}

function ghFetchRows(DbConnection $db, $sql, $limit = 50) {
    $rows = array();
    $result = $db->executeQuery($sql . ' LIMIT ' . (int) $limit);
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    $result->free();
    return $rows;
}

function ghCountSql(DbConnection $db, $sql) {
    $result = $db->executeQuery('SELECT COUNT(*) AS cnt FROM (' . $sql . ') AS HealthCountTab');
    $row = $result->fetch_assoc();
    $result->free();
    return (int) $row['cnt'];
}

function ghAddCheck(&$checks, $area, $severity, $title, $description, $sql, $repairAction = '', $repairLabel = '') {
    global $db;
    try {
        $countSql = 'SELECT COUNT(*) AS cnt FROM (' . $sql . ') AS HealthCountTab';
        $count = ghCountSql($db, $sql);
        if ($count <= 0) {
            return;
        }
        $checks[] = array(
            'area' => $area,
            'severity' => $severity,
            'title' => $title,
            'description' => $description,
            'count' => $count,
            'details' => ghFetchRows($db, $sql, 50),
            'repair_action' => $repairAction,
            'repair_label' => $repairLabel
        );
    } catch (Exception $e) {
        $checks[] = array(
            'area' => 'system',
            'severity' => 'critical',
            'title' => 'Prüfung fehlgeschlagen: ' . $title,
            'description' => 'Die Prüfung konnte wegen eines SQL- oder Datenfehlers nicht abgeschlossen werden. Die fehlerhafte Prüfung wird hier mit Bereich, Fehlermeldung und SQL angezeigt.',
            'count' => 1,
            'details' => array(array(
                'Bereich' => ghAreaLabel($area),
                'Prüfung' => $title,
                'Fehler' => $e->getMessage(),
                'Count-SQL' => 'SELECT COUNT(*) AS cnt FROM (' . $sql . ') AS HealthCountTab',
                'Details-SQL' => $sql
            )),
            'repair_action' => '',
            'repair_label' => ''
        );
    }
}

function ghOpenDirectOfferCondition(WebSoccer $websoccer, DbConnection $db) {
    if (ghColumnExists($websoccer, $db, 'transfer_offer', 'rejected_date')) {
        return '(O.rejected_date IS NULL OR O.rejected_date = 0)';
    }
    return '1 = 1';
}

function ghSeverityLabelClass($severity) {
    if ($severity == 'critical') {
        return 'important';
    }
    if ($severity == 'warning') {
        return 'warning';
    }
    if ($severity == 'info') {
        return 'info';
    }
    return 'success';
}

function ghSeverityLabel($severity) {
    if ($severity == 'critical') {
        return 'Kritisch';
    }
    if ($severity == 'warning') {
        return 'Warnung';
    }
    if ($severity == 'info') {
        return 'Info';
    }
    return 'OK';
}

function ghAreaLabel($area) {
    $labels = array(
        'clubs' => 'Vereine',
        'players' => 'Spieler',
        'transfers' => 'Transfers',
        'loans' => 'Leihen',
        'finances' => 'Finanzen',
        'staff' => 'Staff & Scouting',
        'competitions' => 'Wettbewerbe',
        'stockmarket' => 'Börse',
        'system' => 'System'
    );
    return isset($labels[$area]) ? $labels[$area] : $area;
}

function ghFormatTimestamp($value) {
    $value = (int) $value;
    if ($value <= 0) {
        return '-';
    }
    return date('d.m.Y H:i', $value);
}

function ghRenderCell($key, $value) {
    if ($value === NULL || $value === '') {
        return '<span class="muted">-</span>';
    }
    if ($key === 'SQL' || $key === 'Count-SQL' || $key === 'Details-SQL') {
        return '<pre style="white-space: pre-wrap; word-break: break-word; max-width: 900px;">' . escapeOutput($value) . '</pre>';
    }
    if (preg_match('/(_date|datum|timestamp|created_date|transfer_ende|transfer_start)$/', $key)) {
        return escapeOutput(ghFormatTimestamp($value));
    }
    if (is_numeric($value) && preg_match('/(budget|amount|marktwert|abloese|fee|price|preis|gehalt|handgeld)/', $key)) {
        return escapeOutput(number_format((float) $value, 0, ',', ' '));
    }
    return escapeOutput($value);
}

function ghAffected(DbConnection $db) {
    return (int) $db->connection->affected_rows;
}

function ghApplyRepair(WebSoccer $websoccer, DbConnection $db, $action) {
    $prefix = ghPrefix($websoccer);
    $now = $websoccer->getNowAsTimestamp();
    $result = array('label' => '', 'affected' => 0, 'details' => array());

    if ($action == 'expire_stale_cpu_loan_offers') {
        if (!ghTableExists($websoccer, $db, 'loan_offer') || !ghTableExists($websoccer, $db, 'spieler')) {
            throw new Exception('Leihangebot-Tabelle nicht vorhanden.');
        }
        $threshold = $now - (21 * 24 * 60 * 60);
        $sql = "SELECT O.id, O.player_id
                FROM " . $prefix . "_loan_offer AS O
                INNER JOIN " . $prefix . "_spieler AS P ON P.id = O.player_id
                INNER JOIN " . $prefix . "_verein AS V ON V.id = O.lender_team_id
                WHERE O.status = 'open'
                  AND O.created_by_computer = '1'
                  AND O.created_date > 0
                  AND O.created_date <= '" . (int) $threshold . "'
                  AND P.verein_id = O.lender_team_id
                  AND (V.user_id IS NULL OR V.user_id <= 0)";
        $rows = ghFetchRows($db, $sql, 1000);
        foreach ($rows as $row) {
            $db->queryUpdate(array('lending_fee' => 0), $prefix . '_spieler', 'id = %d', (int) $row['player_id']);
            $db->queryUpdate(array('status' => 'expired'), $prefix . '_loan_offer', "id = %d AND status = 'open'", (int) $row['id']);
            $result['affected']++;
        }
        $result['label'] = 'Alte CPU-Leihangebote geschlossen';
        $result['details'][] = 'Geschlossene Leihangebote: ' . $result['affected'];
        return $result;
    }

    if ($action == 'close_invalid_loan_offers') {
        if (!ghTableExists($websoccer, $db, 'loan_offer')) {
            throw new Exception('Leihangebot-Tabelle nicht vorhanden.');
        }
        $sql = "SELECT O.id, O.player_id
                FROM " . $prefix . "_loan_offer AS O
                LEFT JOIN " . $prefix . "_spieler AS P ON P.id = O.player_id
                LEFT JOIN " . $prefix . "_verein AS V ON V.id = O.lender_team_id
                WHERE O.status = 'open'
                  AND (P.id IS NULL OR V.id IS NULL OR P.status != '1' OR V.status != '1')";
        $rows = ghFetchRows($db, $sql, 1000);
        foreach ($rows as $row) {
            if ((int) $row['player_id'] > 0) {
                $db->queryUpdate(array('lending_fee' => 0), $prefix . '_spieler', 'id = %d', (int) $row['player_id']);
            }
            $db->queryUpdate(array('status' => 'invalid'), $prefix . '_loan_offer', "id = %d AND status = 'open'", (int) $row['id']);
            $result['affected']++;
        }
        $result['label'] = 'Ungültige Leihangebote geschlossen';
        $result['details'][] = 'Geschlossene Leihangebote: ' . $result['affected'];
        return $result;
    }

    if ($action == 'expire_old_loan_requests') {
        if (!ghTableExists($websoccer, $db, 'loan_request')) {
            throw new Exception('Leihanfragen-Tabelle nicht vorhanden.');
        }
        $threshold = $now - (10 * 24 * 60 * 60);
        $db->executeQuery("UPDATE " . $prefix . "_loan_request SET status = 'expired', answered_date = '" . (int) $now . "' WHERE status = 'open' AND created_date > 0 AND created_date <= '" . (int) $threshold . "'");
        $result['affected'] = ghAffected($db);
        $result['label'] = 'Alte Leihanfragen geschlossen';
        return $result;
    }

    if ($action == 'delete_invalid_transfer_offers') {
        if (!ghTableExists($websoccer, $db, 'transfer_offer')) {
            throw new Exception('Transferangebot-Tabelle nicht vorhanden.');
        }
        $open = ghOpenDirectOfferCondition($websoccer, $db);
        $db->executeQuery("DELETE O FROM " . $prefix . "_transfer_offer AS O
            LEFT JOIN " . $prefix . "_spieler AS P ON P.id = O.player_id
            LEFT JOIN " . $prefix . "_verein AS R ON R.id = O.receiver_club_id
            LEFT JOIN " . $prefix . "_verein AS S ON S.id = O.sender_club_id
            WHERE " . $open . " AND (P.id IS NULL OR R.id IS NULL OR S.id IS NULL)");
        $result['affected'] = ghAffected($db);
        $result['label'] = 'Ungültige Direktangebote gelöscht';
        return $result;
    }

    if ($action == 'delete_extreme_transfer_offers') {
        if (!ghTableExists($websoccer, $db, 'transfer_offer')) {
            throw new Exception('Transferangebot-Tabelle nicht vorhanden.');
        }
        $open = ghOpenDirectOfferCondition($websoccer, $db);
        $db->executeQuery("DELETE O FROM " . $prefix . "_transfer_offer AS O
            INNER JOIN " . $prefix . "_spieler AS P ON P.id = O.player_id
            INNER JOIN " . $prefix . "_verein AS R ON R.id = O.receiver_club_id
            INNER JOIN " . $prefix . "_verein AS S ON S.id = O.sender_club_id
            WHERE " . $open . "
              AND P.status = '1' AND R.status = '1' AND S.status = '1'
              AND P.marktwert > 0
              AND (O.offer_amount < (P.marktwert * 0.50) OR O.offer_amount > (P.marktwert * 2.00))");
        $result['affected'] = ghAffected($db);
        $result['label'] = 'Extreme Direktangebote gelöscht';
        return $result;
    }

    if ($action == 'delete_invalid_bids') {
        if (!ghTableExists($websoccer, $db, 'transfer_angebot')) {
            throw new Exception('Bieter-Tabelle nicht vorhanden.');
        }
        $db->executeQuery("DELETE A FROM " . $prefix . "_transfer_angebot AS A
            LEFT JOIN " . $prefix . "_spieler AS P ON P.id = A.spieler_id
            LEFT JOIN " . $prefix . "_verein AS V ON V.id = A.verein_id
            WHERE P.id IS NULL OR V.id IS NULL");
        $result['affected'] = ghAffected($db);
        $result['label'] = 'Ungültige Transfergebote gelöscht';
        return $result;
    }

    if ($action == 'delete_extreme_bids') {
        if (!ghTableExists($websoccer, $db, 'transfer_angebot')) {
            throw new Exception('Bieter-Tabelle nicht vorhanden.');
        }
        $db->executeQuery("DELETE A FROM " . $prefix . "_transfer_angebot AS A
            INNER JOIN " . $prefix . "_spieler AS P ON P.id = A.spieler_id
            INNER JOIN " . $prefix . "_verein AS V ON V.id = A.verein_id
            WHERE P.status = '1' AND V.status = '1' AND P.marktwert > 0
              AND (A.abloese < (P.marktwert * 0.50) OR A.abloese > (P.marktwert * 2.00))");
        $result['affected'] = ghAffected($db);
        $result['label'] = 'Extreme Transfergebote gelöscht';
        return $result;
    }

    if ($action == 'deactivate_orphan_players') {
        if (!ghTableExists($websoccer, $db, 'spieler')) {
            throw new Exception('Spieler-Tabelle nicht vorhanden.');
        }
        $db->executeQuery("UPDATE " . $prefix . "_spieler AS P
            LEFT JOIN " . $prefix . "_verein AS V ON V.id = P.verein_id
            SET P.status = '0'
            WHERE P.status = '1' AND (P.verein_id IS NULL OR P.verein_id = 0 OR V.id IS NULL OR V.status != '1')");
        $result['affected'] = ghAffected($db);
        $result['label'] = 'Spieler ohne gültigen aktiven Verein deaktiviert';
        return $result;
    }

    if ($action == 'unlist_expired_transfer_players') {
        if (!ghTableExists($websoccer, $db, 'spieler')) {
            throw new Exception('Spieler-Tabelle nicht vorhanden.');
        }
        $setParts = array("transfermarkt = '0'");
        if (ghColumnExists($websoccer, $db, 'spieler', 'transfer_start')) {
            $setParts[] = 'transfer_start = 0';
        }
        if (ghColumnExists($websoccer, $db, 'spieler', 'transfer_ende')) {
            $setParts[] = 'transfer_ende = 0';
        }
        if (ghColumnExists($websoccer, $db, 'spieler', 'transfer_mindestgebot')) {
            $setParts[] = 'transfer_mindestgebot = 0';
        }
        $db->executeQuery("UPDATE " . $prefix . "_spieler SET " . implode(', ', $setParts) . " WHERE status = '1' AND transfermarkt = '1' AND transfer_ende > 0 AND transfer_ende <= '" . (int) $now . "'");
        $result['affected'] = ghAffected($db);
        $result['label'] = 'Abgelaufene Transferlistungen entfernt';
        return $result;
    }

    if ($action == 'normalize_negative_ticket_prices') {
        if (!ghTableExists($websoccer, $db, 'verein')) {
            throw new Exception('Vereins-Tabelle nicht vorhanden.');
        }
        $cols = array('preis_stehen', 'preis_sitz', 'preis_haupt_stehen', 'preis_haupt_sitze', 'preis_vip');
        foreach ($cols as $col) {
            if (!ghColumnExists($websoccer, $db, 'verein', $col)) {
                continue;
            }
            $db->executeQuery("UPDATE " . $prefix . "_verein SET " . $col . " = 0 WHERE status = '1' AND " . $col . " < 0");
            $result['affected'] += ghAffected($db);
        }
        $result['label'] = 'Negative Ticketpreise auf 0 gesetzt';
        return $result;
    }

    if ($action == 'suspend_same_league_parent_links') {
        if (!ghColumnExists($websoccer, $db, 'verein', 'parent_club_id') || !ghColumnExists($websoccer, $db, 'verein', 'parent_club_status')) {
            throw new Exception('Parent-Club-Spalten nicht vorhanden.');
        }
        $reasonSql = ghColumnExists($websoccer, $db, 'verein', 'parent_club_suspended_reason') ? ", V.parent_club_suspended_reason = 'same_division'" : '';
        $db->executeQuery("UPDATE " . $prefix . "_verein AS V
            INNER JOIN " . $prefix . "_verein AS P ON P.id = V.parent_club_id
            SET V.parent_club_status = 'suspended'" . $reasonSql . "
            WHERE V.status = '1' AND P.status = '1' AND V.parent_club_id > 0 AND V.liga_id = P.liga_id");
        $result['affected'] = ghAffected($db);
        $result['label'] = 'Parent-Club-Verbindungen in gleicher Liga pausiert';
        return $result;
    }

    if ($action == 'delete_invalid_staff_assignments') {
        if (!ghTableExists($websoccer, $db, 'club_staff_assignment')) {
            throw new Exception('Staff-Zuordnungstabelle nicht vorhanden.');
        }
        $db->executeQuery("DELETE A FROM " . $prefix . "_club_staff_assignment AS A
            LEFT JOIN " . $prefix . "_verein AS V ON V.id = A.team_id
            LEFT JOIN " . $prefix . "_club_staff AS S ON S.id = A.staff_id
            WHERE V.id IS NULL OR S.id IS NULL");
        $result['affected'] = ghAffected($db);
        $result['label'] = 'Ungültige Staff-Zuordnungen gelöscht';
        return $result;
    }

    if ($action == 'reset_invalid_scout_assignments') {
        if (!ghTableExists($websoccer, $db, 'scout')) {
            throw new Exception('Scout-Tabelle nicht vorhanden.');
        }
        $db->executeQuery("UPDATE " . $prefix . "_scout AS S
            LEFT JOIN " . $prefix . "_verein AS V ON V.id = S.team_id
            SET S.team_id = 0, S.team_matches = 0
            WHERE S.team_id > 0 AND (V.id IS NULL OR V.status != '1')");
        $result['affected'] = ghAffected($db);
        $result['label'] = 'Ungültige Scout-Zuordnungen zurückgesetzt';
        return $result;
    }

    throw new Exception('Unbekannte Reparaturaktion.');
}

function ghBuildChecks(WebSoccer $websoccer, DbConnection $db) {
    $checks = array();
    $prefix = ghPrefix($websoccer);
    $now = $websoccer->getNowAsTimestamp();

    if (ghTableExists($websoccer, $db, 'verein')) {
        if (ghTableExists($websoccer, $db, 'liga')) {
            ghAddCheck($checks, 'clubs', 'critical', 'Aktive Vereine ohne gültige Liga', 'Diese Vereine können Tabellen, Spielplan und Wettbewerbe beschädigen.',
                "SELECT V.id AS verein_id, V.name AS verein, V.liga_id
                 FROM " . $prefix . "_verein AS V
                 LEFT JOIN " . $prefix . "_liga AS L ON L.id = V.liga_id
                 WHERE V.status = '1' AND (V.liga_id IS NULL OR V.liga_id = 0 OR L.id IS NULL)");
        }

        if (ghTableExists($websoccer, $db, 'stadion')) {
            ghAddCheck($checks, 'clubs', 'warning', 'Aktive Vereine ohne gültiges Stadion', 'Stadion- und Zuschauereinnahmen können dadurch falsch sein.',
                "SELECT V.id AS verein_id, V.name AS verein, V.stadion_id
                 FROM " . $prefix . "_verein AS V
                 LEFT JOIN " . $prefix . "_stadion AS S ON S.id = V.stadion_id
                 WHERE V.status = '1' AND (V.stadion_id IS NULL OR V.stadion_id = 0 OR S.id IS NULL)");
        }

        if (ghColumnExists($websoccer, $db, 'verein', 'bild')) {
            ghAddCheck($checks, 'clubs', 'info', 'Aktive Vereine ohne Logo', 'Nur kosmetisch. Die Seite kann ersatzweise Länderlogo oder Text anzeigen.',
                "SELECT V.id AS verein_id, V.name AS verein
                 FROM " . $prefix . "_verein AS V
                 WHERE V.status = '1' AND (V.bild IS NULL OR V.bild = '')");
        }

        if (ghColumnExists($websoccer, $db, 'verein', 'finanz_budget')) {
            ghAddCheck($checks, 'finances', 'critical', 'Aktive Vereine mit negativem Budget', 'Diese Vereine sind zahlungsunfähig oder falsch reguliert.',
                "SELECT V.id AS verein_id, V.name AS verein, V.finanz_budget AS budget
                 FROM " . $prefix . "_verein AS V
                 WHERE V.status = '1' AND V.finanz_budget < 0");
            ghAddCheck($checks, 'finances', 'warning', 'Aktive Vereine mit extrem hohem Budget', 'Hinweis auf Marktinflation oder fehlerhafte Geldbuchungen.',
                "SELECT V.id AS verein_id, V.name AS verein, V.finanz_budget AS budget
                 FROM " . $prefix . "_verein AS V
                 WHERE V.status = '1' AND V.finanz_budget > 100000000");
        }

        if (ghColumnExists($websoccer, $db, 'verein', 'sponsor_id')) {
            ghAddCheck($checks, 'finances', 'info', 'Aktive Vereine ohne Sponsor', 'Nicht zwingend fatal, aber Sponsor-Einnahmen können fehlen.',
                "SELECT V.id AS verein_id, V.name AS verein, V.sponsor_id
                 FROM " . $prefix . "_verein AS V
                 WHERE V.status = '1' AND (V.sponsor_id IS NULL OR V.sponsor_id = 0)");
        }

        $ticketCols = array('preis_stehen', 'preis_sitz', 'preis_haupt_stehen', 'preis_haupt_sitze', 'preis_vip');
        $ticketChecks = array();
        foreach ($ticketCols as $col) {
            if (ghColumnExists($websoccer, $db, 'verein', $col)) {
                $ticketChecks[] = $col . ' < 0 OR ' . $col . ' > 500';
            }
        }
        if (count($ticketChecks)) {
            ghAddCheck($checks, 'finances', 'warning', 'Auffällige Ticketpreise', 'Preise unter 0 oder über 500 wirken fehlerhaft. Die Reparatur setzt nur negative Preise auf 0.',
                "SELECT V.id AS verein_id, V.name AS verein, V.preis_stehen, V.preis_sitz, V.preis_haupt_stehen, V.preis_haupt_sitze, V.preis_vip
                 FROM " . $prefix . "_verein AS V
                 WHERE V.status = '1' AND (" . implode(' OR ', $ticketChecks) . ")",
                'normalize_negative_ticket_prices', 'Negative Preise reparieren');
        }

        if (ghColumnExists($websoccer, $db, 'verein', 'parent_club_id')) {
            ghAddCheck($checks, 'clubs', 'warning', 'Parent-/Partnervereine in gleicher Liga', 'Diese Beziehungen sollten pausiert werden, wenn beide Vereine in derselben Liga spielen.',
                "SELECT V.id AS verein_id, V.name AS verein, P.id AS parent_id, P.name AS parent_verein, V.liga_id
                 FROM " . $prefix . "_verein AS V
                 INNER JOIN " . $prefix . "_verein AS P ON P.id = V.parent_club_id
                 WHERE V.status = '1' AND P.status = '1' AND V.parent_club_id > 0 AND V.liga_id = P.liga_id",
                'suspend_same_league_parent_links', 'Beziehungen pausieren');
        }
    }

    if (ghTableExists($websoccer, $db, 'spieler')) {
        ghAddCheck($checks, 'players', 'critical', 'Aktive Spieler ohne gültigen aktiven Verein', 'Diese Spieler können Kader, Transfers und Simulationen verfälschen.',
            "SELECT P.id AS spieler_id, CONCAT(P.vorname, ' ', P.nachname) AS spieler, P.verein_id
             FROM " . $prefix . "_spieler AS P
             LEFT JOIN " . $prefix . "_verein AS V ON V.id = P.verein_id
             WHERE P.status = '1' AND (P.verein_id IS NULL OR P.verein_id = 0 OR V.id IS NULL OR V.status != '1')",
            'deactivate_orphan_players', 'Spieler deaktivieren');

        ghAddCheck($checks, 'players', 'warning', 'Aktive Spieler mit ungültiger Position', 'Die Positionslogik und Aufstellung können dadurch fehlerhaft werden.',
            "SELECT P.id AS spieler_id, CONCAT(P.vorname, ' ', P.nachname) AS spieler, P.position
             FROM " . $prefix . "_spieler AS P
             INNER JOIN " . $prefix . "_verein AS V ON V.id = P.verein_id AND V.status = '1'
             WHERE P.status = '1' AND (P.position IS NULL OR P.position = '' OR P.position NOT IN ('Torwart', 'Abwehr', 'Mittelfeld', 'Sturm'))");

        if (ghColumnExists($websoccer, $db, 'spieler', 'w_staerke') && ghColumnExists($websoccer, $db, 'spieler', 'w_talent')) {
            ghAddCheck($checks, 'players', 'warning', 'Aktive Spieler mit ungültiger Stärke/Talent', 'Stärke sollte 0-100 und Talent 1-5 sein.',
                "SELECT P.id AS spieler_id, CONCAT(P.vorname, ' ', P.nachname) AS spieler, P.w_staerke, P.w_talent
                 FROM " . $prefix . "_spieler AS P
                 INNER JOIN " . $prefix . "_verein AS V ON V.id = P.verein_id AND V.status = '1'
                 WHERE P.status = '1' AND (P.w_staerke IS NULL OR P.w_staerke < 0 OR P.w_staerke > 100 OR P.w_talent IS NULL OR P.w_talent < 1 OR P.w_talent > 6)");
        }

        if (ghColumnExists($websoccer, $db, 'spieler', 'transfermarkt') && ghColumnExists($websoccer, $db, 'spieler', 'transfer_ende')) {
            ghAddCheck($checks, 'transfers', 'info', 'Abgelaufene Spieler auf dem Transfermarkt', 'Diese Spieler sollten von der Transferliste entfernt werden.',
                "SELECT P.id AS spieler_id, CONCAT(P.vorname, ' ', P.nachname) AS spieler, P.transfer_ende
                 FROM " . $prefix . "_spieler AS P
                 INNER JOIN " . $prefix . "_verein AS V ON V.id = P.verein_id AND V.status = '1'
                 WHERE P.status = '1' AND P.transfermarkt = '1' AND P.transfer_ende > 0 AND P.transfer_ende <= '" . (int) $now . "'",
                'unlist_expired_transfer_players', 'Transferlistung entfernen');
        }
    }

    if (ghTableExists($websoccer, $db, 'transfer_offer')) {
        $open = ghOpenDirectOfferCondition($websoccer, $db);
        ghAddCheck($checks, 'transfers', 'critical', 'Ungültige Direktangebote', 'Angebote mit fehlendem Spieler oder fehlendem Verein können Seiten brechen.',
            "SELECT O.id AS angebot_id, O.player_id, O.receiver_club_id, O.sender_club_id, O.offer_amount
             FROM " . $prefix . "_transfer_offer AS O
             LEFT JOIN " . $prefix . "_spieler AS P ON P.id = O.player_id
             LEFT JOIN " . $prefix . "_verein AS R ON R.id = O.receiver_club_id
             LEFT JOIN " . $prefix . "_verein AS S ON S.id = O.sender_club_id
             WHERE " . $open . " AND (P.id IS NULL OR R.id IS NULL OR S.id IS NULL)",
            'delete_invalid_transfer_offers', 'Ungültige Direktangebote löschen');

        ghAddCheck($checks, 'transfers', 'critical', 'Extreme Direktangebote außerhalb 50%-200%', 'Diese Angebote liegen sehr weit außerhalb des Marktwerts.',
            "SELECT O.id AS angebot_id, CONCAT(P.vorname, ' ', P.nachname) AS spieler, R.name AS empfaenger, S.name AS bieter, P.marktwert, O.offer_amount
             FROM " . $prefix . "_transfer_offer AS O
             INNER JOIN " . $prefix . "_spieler AS P ON P.id = O.player_id
             INNER JOIN " . $prefix . "_verein AS R ON R.id = O.receiver_club_id
             INNER JOIN " . $prefix . "_verein AS S ON S.id = O.sender_club_id
             WHERE " . $open . " AND P.status = '1' AND R.status = '1' AND S.status = '1' AND P.marktwert > 0
               AND (O.offer_amount < (P.marktwert * 0.50) OR O.offer_amount > (P.marktwert * 2.00))",
            'delete_extreme_transfer_offers', 'Extreme Direktangebote löschen');

        ghAddCheck($checks, 'transfers', 'warning', 'Direktangebote außerhalb 70%-130%', 'Diese Angebote sind auffällig, aber nicht extrem.',
            "SELECT O.id AS angebot_id, CONCAT(P.vorname, ' ', P.nachname) AS spieler, R.name AS empfaenger, S.name AS bieter, P.marktwert, O.offer_amount
             FROM " . $prefix . "_transfer_offer AS O
             INNER JOIN " . $prefix . "_spieler AS P ON P.id = O.player_id
             INNER JOIN " . $prefix . "_verein AS R ON R.id = O.receiver_club_id
             INNER JOIN " . $prefix . "_verein AS S ON S.id = O.sender_club_id
             WHERE " . $open . " AND P.status = '1' AND R.status = '1' AND S.status = '1' AND P.marktwert > 0
               AND (O.offer_amount < (P.marktwert * 0.70) OR O.offer_amount > (P.marktwert * 1.30))
               AND NOT (O.offer_amount < (P.marktwert * 0.50) OR O.offer_amount > (P.marktwert * 2.00))");

        ghAddCheck($checks, 'transfers', 'warning', 'Mehr als 3 offene Direktangebote pro Spieler', 'Zu viele Angebote pro Spieler können das Transferbalancing stören.',
            "SELECT O.player_id, CONCAT(P.vorname, ' ', P.nachname) AS spieler, COUNT(*) AS offene_angebote
             FROM " . $prefix . "_transfer_offer AS O
             INNER JOIN " . $prefix . "_spieler AS P ON P.id = O.player_id
             WHERE " . $open . " AND P.status = '1'
             GROUP BY O.player_id, spieler
             HAVING COUNT(*) > 3");
    }

    if (ghTableExists($websoccer, $db, 'transfer_angebot')) {
        ghAddCheck($checks, 'transfers', 'critical', 'Ungültige Transfergebote', 'Gebote mit fehlendem Spieler oder Bieter-Verein sollten entfernt werden.',
            "SELECT A.id AS gebot_id, A.spieler_id, A.verein_id, A.abloese
             FROM " . $prefix . "_transfer_angebot AS A
             LEFT JOIN " . $prefix . "_spieler AS P ON P.id = A.spieler_id
             LEFT JOIN " . $prefix . "_verein AS V ON V.id = A.verein_id
             WHERE P.id IS NULL OR V.id IS NULL",
            'delete_invalid_bids', 'Ungültige Gebote löschen');

        ghAddCheck($checks, 'transfers', 'critical', 'Extreme Transfergebote außerhalb 50%-200%', 'Diese Gebote liegen sehr weit außerhalb des Marktwerts.',
            "SELECT A.id AS gebot_id, CONCAT(P.vorname, ' ', P.nachname) AS spieler, V.name AS bieter, P.marktwert, A.abloese
             FROM " . $prefix . "_transfer_angebot AS A
             INNER JOIN " . $prefix . "_spieler AS P ON P.id = A.spieler_id
             INNER JOIN " . $prefix . "_verein AS V ON V.id = A.verein_id
             WHERE P.status = '1' AND V.status = '1' AND P.marktwert > 0
               AND (A.abloese < (P.marktwert * 0.50) OR A.abloese > (P.marktwert * 2.00))",
            'delete_extreme_bids', 'Extreme Gebote löschen');

        ghAddCheck($checks, 'transfers', 'warning', 'Transfergebote außerhalb 70%-130%', 'Diese Gebote sind auffällig, aber nicht extrem.',
            "SELECT A.id AS gebot_id, CONCAT(P.vorname, ' ', P.nachname) AS spieler, V.name AS bieter, P.marktwert, A.abloese
             FROM " . $prefix . "_transfer_angebot AS A
             INNER JOIN " . $prefix . "_spieler AS P ON P.id = A.spieler_id
             INNER JOIN " . $prefix . "_verein AS V ON V.id = A.verein_id
             WHERE P.status = '1' AND V.status = '1' AND P.marktwert > 0
               AND (A.abloese < (P.marktwert * 0.70) OR A.abloese > (P.marktwert * 1.30))
               AND NOT (A.abloese < (P.marktwert * 0.50) OR A.abloese > (P.marktwert * 2.00))");

        ghAddCheck($checks, 'transfers', 'warning', 'Mehr als 3 Gebote pro Spieler', 'Der Zielwert sind maximal 3 aktive Angebote pro Spieler.',
            "SELECT A.spieler_id, CONCAT(P.vorname, ' ', P.nachname) AS spieler, COUNT(*) AS gebote
             FROM " . $prefix . "_transfer_angebot AS A
             INNER JOIN " . $prefix . "_spieler AS P ON P.id = A.spieler_id
             WHERE P.status = '1'
             GROUP BY A.spieler_id, spieler
             HAVING COUNT(*) > 3");
    }

    if (ghTableExists($websoccer, $db, 'loan_offer')) {
        $threshold = $now - (21 * 24 * 60 * 60);
        ghAddCheck($checks, 'loans', 'warning', 'CPU-Leihangebote älter als 21 Tage', 'Diese Angebote sollten geschlossen werden, damit die Leihliste rotiert.',
            "SELECT O.id AS angebot_id, CONCAT(P.vorname, ' ', P.nachname) AS spieler, V.name AS verein, O.created_date
             FROM " . $prefix . "_loan_offer AS O
             INNER JOIN " . $prefix . "_spieler AS P ON P.id = O.player_id
             INNER JOIN " . $prefix . "_verein AS V ON V.id = O.lender_team_id
             WHERE O.status = 'open' AND O.created_by_computer = '1' AND O.created_date > 0 AND O.created_date <= '" . (int) $threshold . "' AND V.status = '1'",
            'expire_stale_cpu_loan_offers', 'Alte CPU-Leihen schließen');

        ghAddCheck($checks, 'loans', 'critical', 'Ungültige offene Leihangebote', 'Leihangebote mit fehlendem Spieler oder Verein können die Leihseite beschädigen.',
            "SELECT O.id AS angebot_id, O.player_id, O.lender_team_id, O.loan_fee_per_match
             FROM " . $prefix . "_loan_offer AS O
             LEFT JOIN " . $prefix . "_spieler AS P ON P.id = O.player_id
             LEFT JOIN " . $prefix . "_verein AS V ON V.id = O.lender_team_id
             WHERE O.status = 'open' AND (P.id IS NULL OR V.id IS NULL OR P.status != '1' OR V.status != '1')",
            'close_invalid_loan_offers', 'Ungültige Leihen schließen');
    }

    if (ghTableExists($websoccer, $db, 'loan_request')) {
        $requestThreshold = $now - (10 * 24 * 60 * 60);
        ghAddCheck($checks, 'loans', 'info', 'Offene CPU-Leihanfragen älter als 10 Tage', 'Diese Anfragen können geschlossen werden, falls der CPU-Job sie nicht verarbeitet hat.',
            "SELECT R.id AS anfrage_id, R.player_id, R.borrower_team_id, R.created_date
             FROM " . $prefix . "_loan_request AS R
             WHERE R.status = 'open' AND R.created_by_computer = '1' AND R.created_date > 0 AND R.created_date <= '" . (int) $requestThreshold . "'",
            'expire_old_loan_requests', 'Alte Leihanfragen schließen');
    }

    if (ghTableExists($websoccer, $db, 'loan')) {
        ghAddCheck($checks, 'loans', 'critical', 'Aktive Leihen mit fehlenden Stammdaten', 'Aktive Leihen ohne Spieler, Leihgeber oder Entleiher sollten manuell geprüft werden.',
            "SELECT L.id AS leihe_id, L.player_id, L.lender_team_id, L.borrower_team_id, L.remaining_matches
             FROM " . $prefix . "_loan AS L
             LEFT JOIN " . $prefix . "_spieler AS P ON P.id = L.player_id
             LEFT JOIN " . $prefix . "_verein AS A ON A.id = L.lender_team_id
             LEFT JOIN " . $prefix . "_verein AS B ON B.id = L.borrower_team_id
             WHERE L.status = 'active' AND (P.id IS NULL OR A.id IS NULL OR B.id IS NULL)");
    }

    if (ghTableExists($websoccer, $db, 'club_staff_assignment')) {
        ghAddCheck($checks, 'staff', 'critical', 'Ungültige Staff-Zuordnungen', 'Zuordnungen ohne Verein oder Staff-Datensatz erzeugen falsche Kosten/Bonusse.',
            "SELECT A.team_id, A.role, A.staff_id, V.name AS verein, S.name AS staff
             FROM " . $prefix . "_club_staff_assignment AS A
             LEFT JOIN " . $prefix . "_verein AS V ON V.id = A.team_id
             LEFT JOIN " . $prefix . "_club_staff AS S ON S.id = A.staff_id
             WHERE V.id IS NULL OR S.id IS NULL",
            'delete_invalid_staff_assignments', 'Ungültige Zuordnungen löschen');
    }

    if (ghTableExists($websoccer, $db, 'scout')) {
        ghAddCheck($checks, 'staff', 'warning', 'Scouts mit ungültigem Verein', 'Diese Scouts verursachen möglicherweise falsche Scouting-Kosten.',
            "SELECT S.id AS scout_id, S.name AS scout, S.team_id, S.team_matches
             FROM " . $prefix . "_scout AS S
             LEFT JOIN " . $prefix . "_verein AS V ON V.id = S.team_id
             WHERE S.team_id > 0 AND (V.id IS NULL OR V.status != '1')",
            'reset_invalid_scout_assignments', 'Scout-Zuordnung zurücksetzen');
    }

    if (ghTableExists($websoccer, $db, 'spiel') && ghTableExists($websoccer, $db, 'liga')) {
        ghAddCheck($checks, 'competitions', 'warning', 'CONMEBOL-Vereine in UEFA-Spielen', 'UEFA-Wettbewerbe sollten keine Vereine aus Kontinent 7 enthalten.',
            "SELECT M.id AS spiel_id, M.pokalname, H.name AS heim, G.name AS gast, LH.kontinent_id AS heim_kontinent, LG.kontinent_id AS gast_kontinent
             FROM " . $prefix . "_spiel AS M
             LEFT JOIN " . $prefix . "_verein AS H ON H.id = M.home_verein
             LEFT JOIN " . $prefix . "_verein AS G ON G.id = M.gast_verein
             LEFT JOIN " . $prefix . "_liga AS LH ON LH.id = H.liga_id
             LEFT JOIN " . $prefix . "_liga AS LG ON LG.id = G.liga_id
             WHERE M.pokalname IN ('Champions League', 'UEFA Euro League')
               AND (LH.kontinent_id = 7 OR LG.kontinent_id = 7)");

        ghAddCheck($checks, 'competitions', 'warning', 'Nicht-CONMEBOL-Vereine in CONMEBOL-Spielen', 'Copa Libertadores/Sudamericana sollten nur Vereine aus Kontinent 7 enthalten.',
            "SELECT M.id AS spiel_id, M.pokalname, H.name AS heim, G.name AS gast, LH.kontinent_id AS heim_kontinent, LG.kontinent_id AS gast_kontinent
             FROM " . $prefix . "_spiel AS M
             LEFT JOIN " . $prefix . "_verein AS H ON H.id = M.home_verein
             LEFT JOIN " . $prefix . "_verein AS G ON G.id = M.gast_verein
             LEFT JOIN " . $prefix . "_liga AS LH ON LH.id = H.liga_id
             LEFT JOIN " . $prefix . "_liga AS LG ON LG.id = G.liga_id
             WHERE M.pokalname IN ('Copa Libertadores', 'Copa Sudamericana')
               AND ((LH.kontinent_id IS NOT NULL AND LH.kontinent_id != 7) OR (LG.kontinent_id IS NOT NULL AND LG.kontinent_id != 7))");
    }

    if (ghTableExists($websoccer, $db, 'stockmarket')) {
        ghAddCheck($checks, 'stockmarket', 'critical', 'Börseneinträge ohne gültigen aktiven Verein', 'Club-Aktien ohne passenden Verein können Finanzseiten beschädigen.',
            "SELECT S.id AS stock_id, S.team_id, S.abbrev, S.name, S.quantity
             FROM " . $prefix . "_stockmarket AS S
             LEFT JOIN " . $prefix . "_verein AS V ON V.id = S.team_id
             WHERE S.team_id IS NOT NULL AND S.team_id > 0 AND (V.id IS NULL OR V.status != '1')");

        ghAddCheck($checks, 'stockmarket', 'warning', 'Börseneinträge mit ungültigem Preis oder Aktienbestand', 'Preis und verfügbare Stückzahl sollten positiv sein.',
            "SELECT S.id AS stock_id, S.team_id, S.abbrev, S.name, S.quantity, S.v1 AS aktueller_kurs
             FROM " . $prefix . "_stockmarket AS S
             WHERE S.team_id IS NOT NULL AND S.team_id > 0
               AND (S.quantity < 0 OR S.v1 IS NULL OR S.v1 = '' OR CAST(REPLACE(S.v1, ',', '.') AS DECIMAL(14,4)) <= 0)");
    }

    if (ghTableExists($websoccer, $db, 'user_stock') && ghTableExists($websoccer, $db, 'stockmarket')) {
        ghAddCheck($checks, 'stockmarket', 'critical', 'Aktienbesitz mit ungültigen Referenzen', 'Bestände ohne Verein oder Aktie sollten manuell geprüft werden.',
            "SELECT U.id AS bestand_id, U.user_id AS verein_id, U.stock_id, U.qty, U.price
             FROM " . $prefix . "_user_stock AS U
             LEFT JOIN " . $prefix . "_verein AS V ON V.id = U.user_id
             LEFT JOIN " . $prefix . "_stockmarket AS S ON S.id = U.stock_id
             WHERE V.id IS NULL OR S.id IS NULL OR U.qty < 0");
    }

    $cacheFiles = array(
        BASE_FOLDER . '/cache/wsconfigadmin.inc.php',
        BASE_FOLDER . '/cache/adminmessages_de.inc.php',
        BASE_FOLDER . '/cache/entitymessages_de.inc.php'
    );
    $missingCache = array();
    foreach ($cacheFiles as $file) {
        if (!file_exists($file)) {
            $missingCache[] = array('datei' => str_replace(BASE_FOLDER . '/', '', $file));
        }
    }
    if (count($missingCache)) {
        $checks[] = array(
            'area' => 'system',
            'severity' => 'warning',
            'title' => 'Cache-Dateien fehlen',
            'description' => 'Nach neuen Modulen sollte der Cache neu aufgebaut werden.',
            'count' => count($missingCache),
            'details' => $missingCache,
            'repair_action' => '',
            'repair_label' => ''
        );
    }

    $folders = array('cache' => BASE_FOLDER . '/cache', 'uploads' => BASE_FOLDER . '/uploads');
    $folderProblems = array();
    foreach ($folders as $label => $folder) {
        if (!is_dir($folder) || !is_writable($folder)) {
            $folderProblems[] = array('ordner' => $label, 'pfad' => str_replace(BASE_FOLDER . '/', '', $folder));
        }
    }
    if (count($folderProblems)) {
        $checks[] = array(
            'area' => 'system',
            'severity' => 'critical',
            'title' => 'Wichtige Ordner fehlen oder sind nicht beschreibbar',
            'description' => 'Uploads, Cache oder Konfiguration können dadurch fehlschlagen.',
            'count' => count($folderProblems),
            'details' => $folderProblems,
            'repair_action' => '',
            'repair_label' => ''
        );
    }

    return $checks;
}

$repairResult = NULL;
$repairError = '';
if ($show == 'repair' && isset($_POST['repair_action'])) {
    try {
        if ($admin['r_demo']) {
            throw new Exception($i18n->getMessage('validationerror_no_changes_as_demo'));
        }
        $repairResult = ghApplyRepair($website, $db, trim($_POST['repair_action']));
        logAdminAction($website, LOG_TYPE_EDIT, $admin['name'], 'admin_game_health', $repairResult['label'] . ' (' . (int) $repairResult['affected'] . ')');
    } catch (Exception $e) {
        $repairError = $e->getMessage();
    }
}

$checks = ghBuildChecks($website, $db);
$areas = array('overview', 'clubs', 'players', 'transfers', 'loans', 'finances', 'staff', 'competitions', 'stockmarket', 'system');
$currentTab = isset($_REQUEST['tab']) ? preg_replace('/[^a-z]/', '', $_REQUEST['tab']) : 'overview';
if (!in_array($currentTab, $areas)) {
    $currentTab = 'overview';
}

$summary = array('critical' => 0, 'warning' => 0, 'info' => 0);
$areaCounts = array();
foreach ($areas as $area) {
    $areaCounts[$area] = 0;
}
foreach ($checks as $check) {
    if (isset($summary[$check['severity']])) {
        $summary[$check['severity']] += (int) $check['count'];
    }
    if (isset($areaCounts[$check['area']])) {
        $areaCounts[$check['area']] += (int) $check['count'];
    }
}

$visibleChecks = array();
foreach ($checks as $check) {
    if ($currentTab == 'overview' || $check['area'] == $currentTab) {
        $visibleChecks[] = $check;
    }
}
?>

<h1><?php echo ghMsg($i18n, 'admin_game_health_title', 'Admin Game Health Check'); ?></h1>
<p><?php echo ghMsg($i18n, 'admin_game_health_intro', 'Live-Prüfung wichtiger Spiel- und Systemdaten.'); ?></p>

<?php if ($repairResult) { ?>
    <?php echo createSuccessMessage('Reparatur ausgeführt', escapeOutput($repairResult['label']) . ' &mdash; Geänderte Datensätze: ' . (int) $repairResult['affected']); ?>
    <?php if (!empty($repairResult['details'])) { ?>
        <ul>
            <?php foreach ($repairResult['details'] as $detail) { ?>
                <li><?php echo escapeOutput($detail); ?></li>
            <?php } ?>
        </ul>
    <?php } ?>
<?php } ?>
<?php if ($repairError) { ?>
    <?php echo createErrorMessage($i18n->getMessage('alert_error_title'), escapeOutput($repairError)); ?>
<?php } ?>

<div class="row-fluid">
    <div class="span4">
        <div class="well">
            <h3><span class="label label-important"><?php echo (int) $summary['critical']; ?></span> Kritisch</h3>
        </div>
    </div>
    <div class="span4">
        <div class="well">
            <h3><span class="label label-warning"><?php echo (int) $summary['warning']; ?></span> Warnungen</h3>
        </div>
    </div>
    <div class="span4">
        <div class="well">
            <h3><span class="label label-info"><?php echo (int) $summary['info']; ?></span> Info</h3>
        </div>
    </div>
</div>

<ul class="nav nav-tabs">
    <li<?php if ($currentTab == 'overview') echo ' class="active"'; ?>><a href="?site=<?php echo escapeOutput($site); ?>&amp;tab=overview">Übersicht</a></li>
    <?php foreach ($areas as $area) { if ($area == 'overview') continue; ?>
        <li<?php if ($currentTab == $area) echo ' class="active"'; ?>>
            <a href="?site=<?php echo escapeOutput($site); ?>&amp;tab=<?php echo escapeOutput($area); ?>">
                <?php echo escapeOutput(ghAreaLabel($area)); ?>
                <?php if ((int) $areaCounts[$area] > 0) { ?>
                    <span class="badge"><?php echo (int) $areaCounts[$area]; ?></span>
                <?php } ?>
            </a>
        </li>
    <?php } ?>
</ul>

<?php if ($currentTab == 'overview') { ?>
    <h3>Bereiche</h3>
    <table class="table table-bordered table-striped">
        <thead>
            <tr>
                <th>Bereich</th>
                <th>Auffälligkeiten</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($areas as $area) { if ($area == 'overview') continue; ?>
                <tr>
                    <td><a href="?site=<?php echo escapeOutput($site); ?>&amp;tab=<?php echo escapeOutput($area); ?>"><?php echo escapeOutput(ghAreaLabel($area)); ?></a></td>
                    <td><?php echo (int) $areaCounts[$area]; ?></td>
                    <td>
                        <?php if ((int) $areaCounts[$area] > 0) { ?>
                            <span class="label label-warning">Prüfen</span>
                        <?php } else { ?>
                            <span class="label label-success">OK</span>
                        <?php } ?>
                    </td>
                </tr>
            <?php } ?>
        </tbody>
    </table>
<?php } ?>

<?php if (!count($visibleChecks)) { ?>
    <?php echo createSuccessMessage('Keine Auffälligkeiten', 'Für diesen Bereich wurden keine Probleme gefunden.'); ?>
<?php } ?>

<?php foreach ($visibleChecks as $check) { ?>
    <div class="well">
        <h3>
            <span class="label label-<?php echo ghSeverityLabelClass($check['severity']); ?>"><?php echo ghSeverityLabel($check['severity']); ?></span>
            <?php echo escapeOutput($check['title']); ?>
            <small>(<?php echo (int) $check['count']; ?>)</small>
        </h3>
        <p><?php echo escapeOutput($check['description']); ?></p>

        <?php if (!empty($check['repair_action'])) { ?>
            <form method="post" action="index.php" style="margin-bottom: 10px" onsubmit="return confirm('Diese Reparatur ändert Daten. Fortfahren?');">
                <input type="hidden" name="site" value="<?php echo escapeOutput($site); ?>">
                <input type="hidden" name="show" value="repair">
                <input type="hidden" name="tab" value="<?php echo escapeOutput($currentTab); ?>">
                <input type="hidden" name="repair_action" value="<?php echo escapeOutput($check['repair_action']); ?>">
                <button type="submit" class="btn btn-small btn-warning"><?php echo escapeOutput($check['repair_label']); ?></button>
            </form>
        <?php } ?>

        <?php if (!empty($check['details'])) { ?>
            <table class="table table-condensed table-bordered table-striped">
                <thead>
                    <tr>
                        <?php foreach (array_keys($check['details'][0]) as $column) { ?>
                            <th><?php echo escapeOutput($column); ?></th>
                        <?php } ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($check['details'] as $row) { ?>
                        <tr>
                            <?php foreach ($row as $key => $value) { ?>
                                <td><?php echo ghRenderCell($key, $value); ?></td>
                            <?php } ?>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
            <?php if ((int) $check['count'] > count($check['details'])) { ?>
                <p class="muted">Es werden nur die ersten <?php echo count($check['details']); ?> Datensätze angezeigt.</p>
            <?php } ?>
        <?php } ?>
    </div>
<?php } ?>
