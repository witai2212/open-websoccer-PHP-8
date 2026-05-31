<?php
/******************************************************

This file is part of OpenWebSoccer-Sim.

OpenWebSoccer-Sim is free software: you can redistribute it
and/or modify it under the terms of the
GNU Lesser General Public License as published by the Free Software
Foundation, either version 3 of the License, or any later version.

******************************************************/

/**
 * Aggregates global and human-manager finance figures for economy balancing.
 *
 * The account table remains the source of truth for booked cash movements.
 * Dedicated feature tables are read as cross-checks, so admins can see where
 * a feature creates economic effects without corresponding account bookings.
 */
class FinancialEconomyStatsDataService {

    public static function getAvailableSeasons(WebSoccer $websoccer, DbConnection $db) {
        $prefix = $websoccer->getConfig('db_prefix');
        $rows = array();
        $sql = "SELECT S.id, S.name, S.liga_id, L.name AS league_name
                FROM " . $prefix . "_saison AS S
                LEFT JOIN " . $prefix . "_liga AS L ON L.id = S.liga_id
                ORDER BY S.id DESC
                LIMIT 250";
        $result = $db->executeQuery($sql);
        while ($row = $result->fetch_array()) {
            $rows[] = $row;
        }
        $result->free();
        return $rows;
    }

    public static function getAvailableMatchdays(WebSoccer $websoccer, DbConnection $db, $seasonId) {
        $seasonId = (int) $seasonId;
        if ($seasonId < 1) {
            return array();
        }

        $prefix = $websoccer->getConfig('db_prefix');
        $rows = array();
        $sql = "SELECT DISTINCT spieltag
                FROM " . $prefix . "_spiel
                WHERE saison_id = " . $seasonId . " AND spieltag IS NOT NULL
                ORDER BY spieltag ASC";
        $result = $db->executeQuery($sql);
        while ($row = $result->fetch_array()) {
            $rows[] = (int) $row['spieltag'];
        }
        $result->free();
        return $rows;
    }

    public static function getEconomyStats(WebSoccer $websoccer, DbConnection $db, $seasonId = 0, $matchday = 0, $humanOnly = FALSE) {
        $filter = self::buildDateFilter($websoccer, $db, $seasonId, $matchday);
        $teamWhere = self::teamWhere($humanOnly);

        $summary = self::getBookedSummary($websoccer, $db, $filter, $teamWhere);
        $categories = self::getBookedCategories($websoccer, $db, $filter, $teamWhere, $summary['team_count']);
        $clubRanking = self::getClubRanking($websoccer, $db, $filter, $teamWhere);
        $crossChecks = self::getCrossChecks($websoccer, $db, $filter, $humanOnly, $categories);
        $liabilities = self::getCurrentLiabilities($websoccer, $db, $humanOnly);

        return array(
            'filter' => $filter,
            'summary' => $summary,
            'categories' => $categories,
            'club_ranking' => $clubRanking,
            'cross_checks' => $crossChecks,
            'liabilities' => $liabilities,
            'human_only' => $humanOnly
        );
    }


    public static function getMarketRecommendations($humanStats, $allStats) {
        $rows = array();
        $summary = $humanStats['summary'];
        $teamCount = (int) $summary['team_count'];

        if ($teamCount < 1) {
            return array(self::recommendationRow(
                'warning',
                'Keine menschlich geführten Vereine gefunden',
                'Für eine sinnvolle Marktregulierung braucht die Auswertung mindestens einen menschlich geführten Verein.',
                '',
                '',
                'Keine Aktion möglich.'
            ));
        }

        $avgIncome = (int) $summary['avg_income_per_club'];
        $avgExpense = (int) $summary['avg_expense_per_club'];
        $avgNet = (int) $summary['avg_net_per_club'];
        $tolerance = max(250000, (int) round(max(1, $avgIncome) * 0.05));
        $expenseRatio = ($avgIncome > 0) ? ($avgExpense / max(1, $avgIncome)) : 0;

        if ($avgNet > $tolerance) {
            $rows[] = self::recommendationRow(
                'danger',
                'Menschliche Vereine machen im Schnitt zu viel Gewinn',
                'Der durchschnittliche Saldo liegt über dem Zielkorridor. Um Inflation zu vermeiden, sollten Einnahmen leicht sinken oder laufende Kosten steigen.',
                'reduce_income_5',
                'Einnahmen-Hebel um 5 % senken',
                'Senkt Sponsoren, aktive Sponsorverträge, Stadionnamen-Prämien und einige künftige Nebeneinnahmen um 5 %.'
            );

            if ($expenseRatio < 0.85) {
                $rows[] = self::recommendationRow(
                    'warning',
                    'Laufende Kosten sind im Verhältnis zu den Einnahmen niedrig',
                    'Die Ausgaben liegen deutlich unter den Einnahmen. Das kann dauerhaft zu zu viel freiem Geld im Markt führen.',
                    'increase_costs_5',
                    'Laufende Kosten um 5 % erhöhen',
                    'Erhöht Staff-, Scout-, Trainer-, Scouting-, Trainingslager- und Stadionausbaukosten um 5 %.'
                );
            }
        } elseif ($avgNet < (0 - $tolerance)) {
            $rows[] = self::recommendationRow(
                'danger',
                'Menschliche Vereine verlieren im Schnitt zu viel Geld',
                'Der durchschnittliche Saldo liegt unter dem Zielkorridor. Das kann bei Vereinswechseln schnell zu finanziell kaputten Vereinen führen.',
                'increase_income_5',
                'Einnahmen-Hebel um 5 % erhöhen',
                'Erhöht Sponsoren, aktive Sponsorverträge, Stadionnamen-Prämien und einige künftige Nebeneinnahmen um 5 %.'
            );

            if ($expenseRatio > 1.15) {
                $rows[] = self::recommendationRow(
                    'warning',
                    'Laufende Kosten sind im Verhältnis zu den Einnahmen hoch',
                    'Die Kosten fressen die Einnahmen auf. Eine leichte Kostensenkung entlastet Vereine, ohne Budgets direkt zu manipulieren.',
                    'decrease_costs_5',
                    'Laufende Kosten um 5 % senken',
                    'Senkt Staff-, Scout-, Trainer-, Scouting-, Trainingslager- und Stadionausbaukosten um 5 %.'
                );
            }
        } else {
            $rows[] = self::recommendationRow(
                'success',
                'Durchschnittlicher Saldo liegt im Zielkorridor',
                'Der Markt wirkt im gewählten Zeitraum grundsätzlich stabil. Größere Eingriffe sind aktuell nicht nötig.',
                '',
                '',
                'Beobachten statt eingreifen.'
            );
        }

        $sponsorIncomePerClub = (int) round(self::getCategoryAmount($humanStats['categories'], 'Sponsoren', 'income_total') / max(1, $teamCount));
        if ($avgIncome > 0 && $sponsorIncomePerClub > (int) round($avgIncome * 0.45) && $avgNet > 0) {
            $rows[] = self::recommendationRow(
                'warning',
                'Sponsoren sind ein sehr großer Einnahmeblock',
                'Mehr als 45 % der durchschnittlichen Einnahmen kommen aus Sponsoren/Naming. Bei zu viel Gewinn ist das ein guter Stellhebel.',
                'reduce_income_5',
                'Einnahmen-Hebel um 5 % senken',
                'Fokussiert besonders auf Sponsoren und Stadionnamen-Prämien.'
            );
        }

        $liabilityTotal = 0;
        foreach ($humanStats['liabilities'] as $liability) {
            $liabilityTotal += (int) $liability['amount'];
        }
        $liabilityPerClub = (int) round($liabilityTotal / max(1, $teamCount));
        if ($avgIncome > 0 && $liabilityPerClub < (int) round($avgIncome * 0.10) && $avgNet > $tolerance) {
            $rows[] = self::recommendationRow(
                'warning',
                'Fixkosten pro Spieltag sind sehr niedrig',
                'Die aktuellen Personal-/Scouting-Kosten sind im Verhältnis zu den Einnahmen gering. Das begünstigt hohe Gewinne.',
                'increase_costs_5',
                'Laufende Kosten um 5 % erhöhen',
                'Erhöht die wichtigsten wiederkehrenden Kostenblöcke.'
            );
        }

        foreach ($humanStats['cross_checks'] as $crossCheck) {
            $featureAmount = abs((int) $crossCheck['feature_amount']);
            $difference = abs((int) $crossCheck['difference']);
            $limit = max(100000, (int) round($featureAmount * 0.10));
            if ($featureAmount > 0 && $difference > $limit) {
                $rows[] = self::recommendationRow(
                    'info',
                    'Gegenprüfung prüfen: ' . $crossCheck['label'],
                    'Die Feature-Tabelle und die Konto-Buchungen weichen deutlich voneinander ab. Das sollte zuerst fachlich geprüft werden, bevor Geldwerte reguliert werden.',
                    '',
                    '',
                    'Kein automatischer Eingriff, da es ein Buchungs-/Kategoriethema sein kann.'
                );
            }
        }

        return self::deduplicateRecommendations($rows);
    }

    public static function getMarketRegulationActions() {
        return array(
            'reduce_income_5' => array(
                'label' => 'Einnahmen-Hebel um 5 % senken',
                'factor' => 0.95,
                'type' => 'income'
            ),
            'increase_income_5' => array(
                'label' => 'Einnahmen-Hebel um 5 % erhöhen',
                'factor' => 1.05,
                'type' => 'income'
            ),
            'increase_costs_5' => array(
                'label' => 'Laufende Kosten um 5 % erhöhen',
                'factor' => 1.05,
                'type' => 'cost'
            ),
            'decrease_costs_5' => array(
                'label' => 'Laufende Kosten um 5 % senken',
                'factor' => 0.95,
                'type' => 'cost'
            )
        );
    }

    public static function applyMarketRegulation(WebSoccer $websoccer, DbConnection $db, $actionKey) {
        $actions = self::getMarketRegulationActions();
        if (!isset($actions[$actionKey])) {
            throw new Exception('Unbekannte Marktregulierung: ' . $actionKey);
        }

        $action = $actions[$actionKey];
        $factor = (float) $action['factor'];
        $updates = array();

        if ($action['type'] == 'income') {
            $updates[] = self::scaleColumns($websoccer, $db, 'sponsor', array('b_spiel', 'b_heimzuschlag', 'b_sieg', 'b_meisterschaft', 'b_cup'), $factor, '1=1');
            $updates[] = self::scaleColumns($websoccer, $db, 'sponsor_contract', array('b_spiel', 'b_heimzuschlag', 'b_sieg', 'b_meisterschaft', 'b_cup'), $factor, "status = 'active'");
            $updates[] = self::scaleColumns($websoccer, $db, 'stadium_naming_contract', array('base_payout_per_match'), $factor, "status = 'active'");
            $updates[] = self::scaleColumns($websoccer, $db, 'stadiumbuilding', array('effect_income', 'effect_merchandising'), $factor, '1=1');
            $updates[] = self::scaleColumns($websoccer, $db, 'merchandising_product', array('sales_price'), $factor, "active = '1'");
        } else {
            $updates[] = self::scaleColumns($websoccer, $db, 'club_staff', array('salary'), $factor, "active = '1'");
            $updates[] = self::scaleColumns($websoccer, $db, 'scout', array('fee'), $factor, '1=1');
            $updates[] = self::scaleColumns($websoccer, $db, 'scouting_department', array('maintenance_fee'), $factor, "status = '1'");
            $updates[] = self::scaleColumns($websoccer, $db, 'scouting_department_level', array('maintenance_fee'), $factor, "status = '1'");
            $updates[] = self::scaleColumns($websoccer, $db, 'scouting_camp', array('fee_per_matchday'), $factor, "status = '1'");
            $updates[] = self::scaleColumns($websoccer, $db, 'scouting_camp_location', array('base_fee'), $factor, "status = '1'");
            $updates[] = self::scaleColumns($websoccer, $db, 'trainer', array('salary'), $factor, '1=1');
            $updates[] = self::scaleColumns($websoccer, $db, 'trainingslager', array('preis_spieler_tag'), $factor, '1=1');
            $updates[] = self::scaleColumns($websoccer, $db, 'stadium_builder', array('fixedcosts', 'cost_per_seat'), $factor, '1=1');
            $updates[] = self::scaleColumns($websoccer, $db, 'stadiumbuilding', array('costs'), $factor, '1=1');
            $updates[] = self::scaleColumns($websoccer, $db, 'merchandising_product', array('purchase_price'), $factor, "active = '1'");
        }

        return array(
            'key' => $actionKey,
            'label' => $action['label'],
            'factor' => $factor,
            'updates' => $updates
        );
    }

    private static function buildDateFilter(WebSoccer $websoccer, DbConnection $db, $seasonId, $matchday) {
        $seasonId = (int) $seasonId;
        $matchday = (int) $matchday;
        $start = 0;
        $end = 0;
        $label = 'Alle Buchungen';

        if ($seasonId > 0) {
            $prefix = $websoccer->getConfig('db_prefix');
            $where = 'saison_id = ' . $seasonId;
            if ($matchday > 0) {
                $where .= ' AND spieltag = ' . $matchday;
            }

            $sql = "SELECT MIN(datum) AS date_start, MAX(datum) AS date_end
                    FROM " . $prefix . "_spiel
                    WHERE " . $where;
            $result = $db->executeQuery($sql);
            $row = $result->fetch_array();
            $result->free();

            if (isset($row['date_start']) && (int) $row['date_start'] > 0) {
                $start = (int) $row['date_start'];
                $end = (int) $row['date_end'] + 86399;
                $label = ($matchday > 0)
                    ? 'Saison #' . $seasonId . ', Spieltag ' . $matchday
                    : 'Saison #' . $seasonId;
            }
        }

        return array(
            'season_id' => $seasonId,
            'matchday' => $matchday,
            'date_start' => $start,
            'date_end' => $end,
            'label' => $label
        );
    }

    private static function teamWhere($humanOnly) {
        return $humanOnly ? "V.user_id > 0 AND V.status = '1'" : "V.status = '1'";
    }

    private static function dateCondition($column, $filter) {
        if ((int) $filter['date_start'] > 0 && (int) $filter['date_end'] > 0) {
            return " AND " . $column . " BETWEEN " . (int) $filter['date_start'] . " AND " . (int) $filter['date_end'];
        }
        return '';
    }

    private static function getBookedSummary(WebSoccer $websoccer, DbConnection $db, $filter, $teamWhere) {
        $prefix = $websoccer->getConfig('db_prefix');
        $dateJoin = self::dateCondition('K.datum', $filter);

        $sql = "SELECT COUNT(DISTINCT V.id) AS team_count,
                       COUNT(K.id) AS booking_count,
                       COALESCE(SUM(CASE WHEN K.betrag > 0 THEN K.betrag ELSE 0 END), 0) AS income_total,
                       COALESCE(SUM(CASE WHEN K.betrag < 0 THEN ABS(K.betrag) ELSE 0 END), 0) AS expense_total,
                       COALESCE(SUM(K.betrag), 0) AS net_total
                FROM " . $prefix . "_verein AS V
                LEFT JOIN " . $prefix . "_konto AS K ON K.verein_id = V.id" . $dateJoin . "
                WHERE " . $teamWhere;

        $result = $db->executeQuery($sql);
        $row = $result->fetch_array();
        $result->free();

        $teamCount = max(1, (int) $row['team_count']);
        $income = (int) round($row['income_total']);
        $expenses = (int) round($row['expense_total']);
        $net = (int) round($row['net_total']);

        return array(
            'team_count' => (int) $row['team_count'],
            'booking_count' => (int) $row['booking_count'],
            'income_total' => $income,
            'expense_total' => $expenses,
            'net_total' => $net,
            'avg_income_per_club' => (int) round($income / $teamCount),
            'avg_expense_per_club' => (int) round($expenses / $teamCount),
            'avg_net_per_club' => (int) round($net / $teamCount)
        );
    }

    private static function getBookedCategories(WebSoccer $websoccer, DbConnection $db, $filter, $teamWhere, $teamCount) {
        $prefix = $websoccer->getConfig('db_prefix');
        $dateWhere = self::dateCondition('K.datum', $filter);
        $teamCount = max(1, (int) $teamCount);

        $categorySql = self::categoryCaseSql('K.absender', 'K.verwendung');
        $sql = "SELECT C.category,
                       C.booking_count,
                       C.affected_clubs,
                       C.income_total,
                       C.expense_total,
                       C.net_total
                FROM (
                    SELECT " . $categorySql . " AS category,
                           COUNT(K.id) AS booking_count,
                           COUNT(DISTINCT K.verein_id) AS affected_clubs,
                           COALESCE(SUM(CASE WHEN K.betrag > 0 THEN K.betrag ELSE 0 END), 0) AS income_total,
                           COALESCE(SUM(CASE WHEN K.betrag < 0 THEN ABS(K.betrag) ELSE 0 END), 0) AS expense_total,
                           COALESCE(SUM(K.betrag), 0) AS net_total
                    FROM " . $prefix . "_konto AS K
                    INNER JOIN " . $prefix . "_verein AS V ON V.id = K.verein_id
                    WHERE " . $teamWhere . $dateWhere . "
                    GROUP BY category
                ) AS C
                ORDER BY (C.income_total + C.expense_total) DESC, C.category ASC";

        $rows = array();
        $result = $db->executeQuery($sql);
        while ($row = $result->fetch_array()) {
            $income = (int) round($row['income_total']);
            $expenses = (int) round($row['expense_total']);
            $net = (int) round($row['net_total']);
            $rows[] = array(
                'category' => $row['category'],
                'booking_count' => (int) $row['booking_count'],
                'affected_clubs' => (int) $row['affected_clubs'],
                'income_total' => $income,
                'expense_total' => $expenses,
                'net_total' => $net,
                'avg_income_per_club' => (int) round($income / $teamCount),
                'avg_expense_per_club' => (int) round($expenses / $teamCount),
                'avg_net_per_club' => (int) round($net / $teamCount)
            );
        }
        $result->free();
        return $rows;
    }

    private static function categoryCaseSql($senderColumn, $subjectColumn) {
        $text = "LOWER(CONCAT(COALESCE(" . $senderColumn . ", ''), ' ', COALESCE(" . $subjectColumn . ", '')))";
        return "CASE
            WHEN " . $text . " REGEXP 'sponsor|naming' THEN 'Sponsoren'
            WHEN " . $text . " REGEXP 'merch|fanartikel' THEN 'Merchandising'
            WHEN " . $text . " REGEXP 'scout|scouting' THEN 'Scouting'
            WHEN " . $text . " REGEXP 'medical|medizin|physio|injury|verletz|behandlung' THEN 'Medizinisches Zentrum'
            WHEN " . $text . " REGEXP 'trainingslager|training' THEN 'Training'
            WHEN " . $text . " REGEXP 'transfer|ablöse|abloese|handgeld|loan|leihe|lending' THEN 'Transfers/Leihen'
            WHEN " . $text . " REGEXP 'staff|trainer|mitarbeiter|club_staff' THEN 'Staff/Trainer'
            WHEN " . $text . " REGEXP 'spieler|player|gehalt|salary|vertrag' THEN 'Spieler'
            WHEN " . $text . " REGEXP 'stadion|stadium|ticket|zuschauer|eintritt' THEN 'Stadion'
            WHEN " . $text . " REGEXP 'bank|zins|kredit|darlehen|tax|steuer|penalty|strafe' THEN 'Bank/Strafen'
            ELSE 'Sonstiges'
        END";
    }

    private static function getClubRanking(WebSoccer $websoccer, DbConnection $db, $filter, $teamWhere) {
        $prefix = $websoccer->getConfig('db_prefix');
        $dateJoin = self::dateCondition('K.datum', $filter);
        $baseSql = "SELECT V.id, V.name, V.finanz_budget, U.nick AS manager_name,
                           COALESCE(SUM(CASE WHEN K.betrag > 0 THEN K.betrag ELSE 0 END), 0) AS income_total,
                           COALESCE(SUM(CASE WHEN K.betrag < 0 THEN ABS(K.betrag) ELSE 0 END), 0) AS expense_total,
                           COALESCE(SUM(K.betrag), 0) AS net_total
                    FROM " . $prefix . "_verein AS V
                    LEFT JOIN " . $prefix . "_user AS U ON U.id = V.user_id
                    LEFT JOIN " . $prefix . "_konto AS K ON K.verein_id = V.id" . $dateJoin . "
                    WHERE " . $teamWhere . "
                    GROUP BY V.id, V.name, V.finanz_budget, U.nick";

        $sql = "SELECT R.* FROM (" . $baseSql . ") AS R ORDER BY R.net_total ASC LIMIT 10";
        $deficits = array();
        $result = $db->executeQuery($sql);
        while ($row = $result->fetch_array()) {
            $deficits[] = self::normalizeClubRow($row);
        }
        $result->free();

        $sql = "SELECT R.* FROM (" . $baseSql . ") AS R ORDER BY R.net_total DESC LIMIT 10";
        $surpluses = array();
        $result = $db->executeQuery($sql);
        while ($row = $result->fetch_array()) {
            $surpluses[] = self::normalizeClubRow($row);
        }
        $result->free();

        return array('deficits' => $deficits, 'surpluses' => $surpluses);
    }

    private static function normalizeClubRow($row) {
        return array(
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'manager_name' => $row['manager_name'],
            'finanz_budget' => (int) round($row['finanz_budget']),
            'income_total' => (int) round($row['income_total']),
            'expense_total' => (int) round($row['expense_total']),
            'net_total' => (int) round($row['net_total'])
        );
    }

    private static function getCategoryAmount($categories, $name, $field) {
        foreach ($categories as $category) {
            if ($category['category'] == $name) {
                return (int) $category[$field];
            }
        }
        return 0;
    }

    private static function getCrossChecks(WebSoccer $websoccer, DbConnection $db, $filter, $humanOnly, $categories) {
        $rows = array();
        $prefix = $websoccer->getConfig('db_prefix');
        $teamJoin = $humanOnly ? " INNER JOIN " . $prefix . "_verein AS V ON V.id = X.team_id AND V.user_id > 0 AND V.status = '1'" : '';

        $stadium = self::sumFromTable($websoccer, $db, 'stadium_attendance_log', 'total_revenue', 'created_date', $filter, $humanOnly);
        $rows[] = self::crossCheckRow('Stadion-Einnahmen', $stadium, self::getCategoryAmount($categories, 'Stadion', 'income_total'));

        $naming = self::sumFromTable($websoccer, $db, 'stadium_naming_payout', 'payout_amount', 'created_date', $filter, $humanOnly);
        $rows[] = self::crossCheckRow('Stadionname / Sponsoring', $naming, self::getCategoryAmount($categories, 'Sponsoren', 'income_total'));

        $merchRevenue = self::sumFromTable($websoccer, $db, 'merchandising_sales', 'revenue', 'created_date', $filter, $humanOnly);
        $rows[] = self::crossCheckRow('Merchandising Umsatz', $merchRevenue, self::getCategoryAmount($categories, 'Merchandising', 'income_total'));

        $merchCosts = self::sumFromTable($websoccer, $db, 'merchandising_sales', 'costs', 'created_date', $filter, $humanOnly);
        $rows[] = self::crossCheckRow('Merchandising Kosten', $merchCosts, self::getCategoryAmount($categories, 'Merchandising', 'expense_total'));

        $medical = self::sumFromTable($websoccer, $db, 'injury_treatment', 'costs', 'created_date', $filter, $humanOnly);
        $rows[] = self::crossCheckRow('Medizinische Behandlungen', $medical, self::getCategoryAmount($categories, 'Medizinisches Zentrum', 'expense_total'));

        $trainingCamp = self::sumFromTable($websoccer, $db, 'trainingslager_report', 'total_costs', 'completed_date', $filter, $humanOnly);
        $rows[] = self::crossCheckRow('Trainingslager', $trainingCamp, self::getCategoryAmount($categories, 'Training', 'expense_total'));

        $transferVolume = self::sumTransfers($websoccer, $db, $filter, $humanOnly);
        $rows[] = self::crossCheckRow('Transfer-Volumen', $transferVolume, self::getCategoryAmount($categories, 'Transfers/Leihen', 'expense_total') + self::getCategoryAmount($categories, 'Transfers/Leihen', 'income_total'));

        return $rows;
    }

    private static function crossCheckRow($label, $featureAmount, $bookedAmount) {
        return array(
            'label' => $label,
            'feature_amount' => (int) round($featureAmount),
            'booked_amount' => (int) round($bookedAmount),
            'difference' => (int) round($featureAmount - $bookedAmount)
        );
    }

    private static function sumFromTable(WebSoccer $websoccer, DbConnection $db, $table, $sumColumn, $dateColumn, $filter, $humanOnly) {
        $prefix = $websoccer->getConfig('db_prefix');
        if (!self::tableExists($websoccer, $db, $table)) {
            return 0;
        }

        $dateWhere = self::dateCondition('X.' . $dateColumn, $filter);
        $join = '';
        $where = '1=1';
        if ($humanOnly) {
            $join = " INNER JOIN " . $prefix . "_verein AS V ON V.id = X.team_id";
            $where .= " AND V.user_id > 0 AND V.status = '1'";
        }

        $sql = "SELECT COALESCE(SUM(X." . $sumColumn . "), 0) AS total_amount
                FROM " . $prefix . "_" . $table . " AS X" . $join . "
                WHERE " . $where . $dateWhere;
        $result = $db->executeQuery($sql);
        $row = $result->fetch_array();
        $result->free();
        return (int) round($row['total_amount']);
    }

    private static function sumTransfers(WebSoccer $websoccer, DbConnection $db, $filter, $humanOnly) {
        $prefix = $websoccer->getConfig('db_prefix');
        if (!self::tableExists($websoccer, $db, 'transfer')) {
            return 0;
        }

        $dateWhere = self::dateCondition('T.datum', $filter);
        $where = 'T.directtransfer_amount IS NOT NULL AND T.directtransfer_amount > 0';
        if ($humanOnly) {
            $where .= ' AND (Buyer.user_id > 0 OR Seller.user_id > 0)';
        }

        $sql = "SELECT COALESCE(SUM(T.directtransfer_amount), 0) AS total_amount
                FROM " . $prefix . "_transfer AS T
                LEFT JOIN " . $prefix . "_verein AS Buyer ON Buyer.id = T.buyer_club_id
                LEFT JOIN " . $prefix . "_verein AS Seller ON Seller.id = T.seller_club_id
                WHERE " . $where . $dateWhere;
        $result = $db->executeQuery($sql);
        $row = $result->fetch_array();
        $result->free();
        return (int) round($row['total_amount']);
    }

    private static function getCurrentLiabilities(WebSoccer $websoccer, DbConnection $db, $humanOnly) {
        $prefix = $websoccer->getConfig('db_prefix');
        $teamJoinWhere = $humanOnly ? " AND V.user_id > 0 AND V.status = '1'" : " AND V.status = '1'";
        $rows = array();

        $sql = "SELECT COALESCE(SUM(P.vertrag_gehalt), 0) AS total_amount
                FROM " . $prefix . "_spieler AS P
                INNER JOIN " . $prefix . "_verein AS V ON V.id = P.verein_id
                WHERE P.status = '1'" . $teamJoinWhere;
        $rows[] = array('label' => 'Spielergehälter pro Spieltag', 'amount' => self::fetchInt($db, $sql));

        if (self::tableExists($websoccer, $db, 'club_staff_assignment')) {
            $sql = "SELECT COALESCE(SUM(S.salary), 0) AS total_amount
                    FROM " . $prefix . "_club_staff_assignment AS A
                    INNER JOIN " . $prefix . "_club_staff AS S ON S.id = A.staff_id
                    INNER JOIN " . $prefix . "_verein AS V ON V.id = A.team_id
                    WHERE S.active = '1'" . $teamJoinWhere;
            $rows[] = array('label' => 'Club-Staff pro Spieltag', 'amount' => self::fetchInt($db, $sql));
        }

        if (self::tableExists($websoccer, $db, 'scout')) {
            $sql = "SELECT COALESCE(SUM(S.fee), 0) AS total_amount
                    FROM " . $prefix . "_scout AS S
                    INNER JOIN " . $prefix . "_verein AS V ON V.id = S.team_id
                    WHERE S.team_id > 0" . $teamJoinWhere;
            $rows[] = array('label' => 'Scouts pro Spieltag', 'amount' => self::fetchInt($db, $sql));
        }

        if (self::tableExists($websoccer, $db, 'scouting_camp')) {
            $sql = "SELECT COALESCE(SUM(C.fee_per_matchday), 0) AS total_amount
                    FROM " . $prefix . "_scouting_camp AS C
                    INNER JOIN " . $prefix . "_verein AS V ON V.id = C.team_id
                    WHERE C.status = '1'" . $teamJoinWhere;
            $rows[] = array('label' => 'Scouting-Camps pro Spieltag', 'amount' => self::fetchInt($db, $sql));
        }

        if (self::tableExists($websoccer, $db, 'scouting_department')) {
            $sql = "SELECT COALESCE(SUM(D.maintenance_fee), 0) AS total_amount
                    FROM " . $prefix . "_scouting_department AS D
                    INNER JOIN " . $prefix . "_verein AS V ON V.id = D.team_id
                    WHERE D.status = '1'" . $teamJoinWhere;
            $rows[] = array('label' => 'Scouting-Abteilungen pro Spieltag', 'amount' => self::fetchInt($db, $sql));
        }

        return $rows;
    }

    private static function fetchInt(DbConnection $db, $sql) {
        $result = $db->executeQuery($sql);
        $row = $result->fetch_array();
        $result->free();
        return (isset($row['total_amount'])) ? (int) round($row['total_amount']) : 0;
    }


    private static function recommendationRow($severity, $title, $message, $actionKey, $actionLabel, $effect) {
        return array(
            'severity' => $severity,
            'title' => $title,
            'message' => $message,
            'action_key' => $actionKey,
            'action_label' => $actionLabel,
            'effect' => $effect
        );
    }

    private static function deduplicateRecommendations($rows) {
        $seenActions = array();
        $result = array();
        foreach ($rows as $row) {
            $actionKey = isset($row['action_key']) ? $row['action_key'] : '';
            if ($actionKey) {
                if (isset($seenActions[$actionKey])) {
                    continue;
                }
                $seenActions[$actionKey] = TRUE;
            }
            $result[] = $row;
        }
        return $result;
    }

    private static function scaleColumns(WebSoccer $websoccer, DbConnection $db, $table, $columns, $factor, $where) {
        if (!self::tableExists($websoccer, $db, $table)) {
            return array('table' => $table, 'columns' => implode(', ', $columns), 'affected_rows' => 0, 'skipped' => TRUE);
        }

        $prefix = $websoccer->getConfig('db_prefix');
        $safeTable = preg_replace('/[^a-zA-Z0-9_]/', '', $prefix . '_' . $table);
        $setParts = array();
        foreach ($columns as $column) {
            $safeColumn = preg_replace('/[^a-zA-Z0-9_]/', '', $column);
            if (!$safeColumn) {
                continue;
            }
            $setParts[] = '`' . $safeColumn . '` = CASE WHEN `' . $safeColumn . '` IS NULL THEN NULL ELSE GREATEST(0, ROUND(`' . $safeColumn . '` * ' . $factor . ')) END';
        }

        if (!count($setParts)) {
            return array('table' => $table, 'columns' => '', 'affected_rows' => 0, 'skipped' => TRUE);
        }

        $safeWhere = preg_replace("/[^a-zA-Z0-9_ ='><\.\-]/", '', $where);
        if (!$safeWhere) {
            $safeWhere = '1=1';
        }

        $sql = 'UPDATE `' . $safeTable . '` SET ' . implode(', ', $setParts) . ' WHERE ' . $safeWhere;
        $db->executeQuery($sql);
        $affected = (isset($db->connection)) ? (int) $db->connection->affected_rows : 0;

        return array(
            'table' => $table,
            'columns' => implode(', ', $columns),
            'affected_rows' => $affected,
            'skipped' => FALSE
        );
    }

    private static function tableExists(WebSoccer $websoccer, DbConnection $db, $table) {
        $prefix = $websoccer->getConfig('db_prefix');
        $safeTable = preg_replace('/[^a-zA-Z0-9_]/', '', $prefix . '_' . $table);
        $result = $db->executeQuery("SHOW TABLES LIKE '" . $safeTable . "'");
        $exists = ($result->num_rows > 0);
        $result->free();
        return $exists;
    }
}
?>
