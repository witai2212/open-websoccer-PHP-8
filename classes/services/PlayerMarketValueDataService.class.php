<?php
/******************************************************

  Central, economy-aware player market value service for CM23.

******************************************************/

/**
 * Calculates and persists the canonical market value of every player.
 *
 * The stored spieler.marktwert value is the only value shown by pages and
 * consumed by transfer, salary, pre-contract and squad-planning services.
 */
class PlayerMarketValueDataService {

    const MIN_ACTIVE_VALUE = 10000;
    const MIN_YOUTH_VALUE = 5000;
    const ABSOLUTE_MAX_VALUE = 100000000;
    const DEFAULT_ECONOMY_CEILING = 12000000;
    const UPDATE_BATCH_SIZE = 400;

    private static $_economySnapshot = null;
    private static $_tableCache = array();
    private static $_columnCache = array();

    /**
     * Recalculates one player and writes the result to the player table.
     *
     * @return array strength, market_value, factors, reasons
     */
    public static function recalculatePlayer(WebSoccer $websoccer, DbConnection $db, $playerId) {
        $playerId = (int) $playerId;
        if ($playerId < 1) {
            return array('strength' => 0, 'market_value' => 0, 'factors' => array(), 'reasons' => array());
        }

        $row = self::_fetchPlayerRow($websoccer, $db, $playerId);
        if (!$row) {
            return array('strength' => 0, 'market_value' => 0, 'factors' => array(), 'reasons' => array());
        }

        self::_attachTraits($websoccer, $db, $row);
        $singleRow = array($row);
        self::_attachNationalStatus($websoccer, $db, $singleRow);
        $row = $singleRow[0];
        $calculation = self::calculate($websoccer, $db, $row, self::getEconomySnapshot($websoccer, $db));
        self::_writeSingleResult($websoccer, $db, $playerId, $calculation);
        return $calculation;
    }

    /**
     * Recalculates one youth player and writes the automatic market value.
     */
    public static function recalculateYouthPlayer(WebSoccer $websoccer, DbConnection $db, $playerId) {
        $playerId = (int) $playerId;
        if ($playerId < 1) return array('market_value' => 0, 'factors' => array(), 'reasons' => array());
        self::ensureSchema($websoccer, $db);
        $rows = self::_fetchYouthPlayerRows($websoccer, $db, 'P.id = ' . $playerId, 1);
        if (!count($rows)) return array('market_value' => 0, 'factors' => array(), 'reasons' => array());
        self::_attachAllYouthTraits($websoccer, $db, $rows);
        $calculation = self::calculateYouth($websoccer, $db, $rows[0], self::getEconomySnapshot($websoccer, $db));
        $db->queryUpdate(array('market_value' => (int) $calculation['market_value']),
            $websoccer->getConfig('db_prefix') . '_youthplayer', 'id = %d', $playerId);
        return $calculation;
    }

    /**
     * Pure calculation method. It does not write to the database.
     */
    public static function calculate(WebSoccer $websoccer, DbConnection $db, $player, $economySnapshot = null) {
        if ($economySnapshot === null) {
            $economySnapshot = self::getEconomySnapshot($websoccer, $db);
        }

        $active = ((string) self::_value($player, 'status', '1') === '1');
        $position = self::_normalizePosition(self::_value($player, 'position', ''));
        $positionQuality = self::_calculatePositionQuality($player, $position);
        $baseStrength = self::_clamp((float) self::_value($player, 'w_staerke', 0), 0, 100);
        $technicalStrength = self::_clamp((float) self::_value($player, 'w_technik', $positionQuality), 0, 100);
        $fitness = self::_clamp((float) self::_value($player, 'w_kondition', 75), 0, 100);
        $freshness = self::_clamp((float) self::_value($player, 'w_frische', 75), 0, 100);
        $happiness = self::_clamp((float) self::_value($player, 'w_zufriedenheit', 75), 0, 100);

        // Sporting strength no longer depends on contract duration. Contract
        // duration belongs to the financial valuation only.
        $calculatedStrength = self::_clamp(
            ($baseStrength * 0.48) +
            ($positionQuality * 0.34) +
            ($technicalStrength * 0.10) +
            ($fitness * 0.04) +
            ($freshness * 0.02) +
            ($happiness * 0.02),
            0,
            100
        );
        $calculatedStrength = round($calculatedStrength, 0);

        if (!$active) {
            return array(
                'strength' => $calculatedStrength,
                'market_value' => 0,
                'unrounded_market_value' => 0,
                'factors' => array('status' => 0),
                'reasons' => array('Inaktiver Spieler: Marktwert 0 EUR'),
                'economy' => $economySnapshot
            );
        }

        $quality = self::_clamp(($baseStrength * 0.55) + ($positionQuality * 0.35) + ($technicalStrength * 0.10), 1, 100);
        $qualityRatio = self::_clamp($quality / 100, 0.01, 1.05);
        $economyCeiling = max(self::MIN_ACTIVE_VALUE, (float) $economySnapshot['market_ceiling']);
        $baseValue = $economyCeiling * pow($qualityRatio, 5.0);

        $age = (int) self::_value($player, 'age', 0);
        if ($age < 1 && strlen((string) self::_value($player, 'geburtstag', ''))) {
            $age = self::_ageFromBirthday((string) $player['geburtstag']);
        }
        $ageFactor = self::_ageFactor($age);
        $positionFactor = self::_positionFactor($position);
        $talentFactor = self::_talentFactor($player, $age, $baseStrength);
        $contractFactor = self::_contractFactor($websoccer, (int) self::_value($player, 'vertrag_spiele', 0));
        $healthFactor = self::_healthFactor($player);
        $formFactor = self::_formFactor($player);
        $performanceFactor = self::_performanceFactor($player, $position);
        $personalityFactor = self::_personalityFactor((string) self::_value($player, 'personality', ''));
        $leagueFactor = self::_leagueFactor($player);
        $clubFactor = self::_clubFactor($player);
        $internationalFactor = self::_internationalFactor($player);
        $traitFactor = 1.00;
        if (class_exists('PlayerTraitsDataService')) {
            $traitPlayer = $player;
            $traitPlayer['player_position'] = $position;
            $traitPlayer['player_position_main'] = self::_value($player, 'position_main', '');
            $traitFactor = self::_clamp(PlayerTraitsDataService::getMarketValueMultiplier($websoccer, $db, $traitPlayer), 1.00, 1.20);
        }
        // Market values are fully automatic. Historical manual finance-center
        // multipliers are deliberately ignored by the canonical calculation.
        $regulationFactor = 1.00;

        $unrounded = $baseValue * $ageFactor * $positionFactor * $talentFactor * $contractFactor *
            $healthFactor * $formFactor * $performanceFactor * $personalityFactor * $leagueFactor *
            $clubFactor * $internationalFactor * $traitFactor * $regulationFactor;

        // A player cannot become more expensive than the liquid market can
        // reasonably absorb. A small league/club premium is allowed, but the
        // hard ceiling remains linked to the economy and never exceeds 100m.
        $individualCeiling = min(
            self::ABSOLUTE_MAX_VALUE,
            $economyCeiling * self::_clamp(max(1.0, $leagueFactor * $clubFactor), 1.0, 1.18) * $regulationFactor
        );
        $unrounded = self::_clamp($unrounded, self::MIN_ACTIVE_VALUE, $individualCeiling);
        $marketValue = self::_roundMarketValue($unrounded);

        $factors = array(
            'quality' => round($quality, 2),
            'base_value' => round($baseValue),
            'age' => round($ageFactor, 3),
            'position' => round($positionFactor, 3),
            'talent' => round($talentFactor, 3),
            'contract' => round($contractFactor, 3),
            'health' => round($healthFactor, 3),
            'form' => round($formFactor, 3),
            'performance' => round($performanceFactor, 3),
            'personality' => round($personalityFactor, 3),
            'league' => round($leagueFactor, 3),
            'club' => round($clubFactor, 3),
            'international' => round($internationalFactor, 3),
            'traits' => round($traitFactor, 3),
            'regulation' => round($regulationFactor, 3),
            'ceiling' => round($individualCeiling)
        );

        return array(
            'strength' => $calculatedStrength,
            'market_value' => (int) $marketValue,
            'unrounded_market_value' => round($unrounded),
            'factors' => $factors,
            'reasons' => self::_buildReasons($player, $factors, $age, $position),
            'economy' => $economySnapshot
        );
    }

    /**
     * Calculates the automatic market value of a youth player. The value is
     * deliberately separate from transfer_fee, which remains the manager's
     * freely chosen asking price on the youth marketplace.
     */
    public static function calculateYouth(WebSoccer $websoccer, DbConnection $db, $player, $economySnapshot = null) {
        if ($economySnapshot === null) $economySnapshot = self::getEconomySnapshot($websoccer, $db);
        $position = self::_normalizePosition(self::_value($player, 'position', ''));
        $strength = self::_clamp((float) self::_value($player, 'strength', 0), 1, 100);
        $qualityRatio = self::_clamp($strength / 100, 0.01, 1.0);
        $economyCeiling = max(self::MIN_YOUTH_VALUE, (float) $economySnapshot['market_ceiling']);
        $baseValue = $economyCeiling * pow($qualityRatio, 5.0) * 0.55;
        $age = max(0, (int) self::_value($player, 'age', 0));
        $ageFactor = self::_youthAgeFactor($age);
        $positionFactor = self::_positionFactor($position);
        $performanceFactor = self::_youthPerformanceFactor($player, $position);
        $leagueFactor = self::_leagueFactor($player);
        $clubFactor = self::_clubFactor($player);
        $traitFactor = 1.0;
        if (class_exists('PlayerTraitsDataService')) {
            $traitPlayer = $player;
            $traitPlayer['player_position'] = $position;
            $traitPlayer['player_position_main'] = '';
            $traitFactor = self::_clamp(PlayerTraitsDataService::getMarketValueMultiplier($websoccer, $db, $traitPlayer), 1.0, 1.20);
        }
        $individualCeiling = min(self::ABSOLUTE_MAX_VALUE, $economyCeiling * 0.65);
        $unrounded = $baseValue * $ageFactor * $positionFactor * $performanceFactor * $leagueFactor * $clubFactor * $traitFactor;
        $unrounded = self::_clamp($unrounded, self::MIN_YOUTH_VALUE, $individualCeiling);
        $marketValue = self::_roundMarketValue($unrounded);
        $factors = array(
            'quality' => round($strength, 2),
            'base_value' => round($baseValue),
            'age' => round($ageFactor, 3),
            'position' => round($positionFactor, 3),
            'performance' => round($performanceFactor, 3),
            'league' => round($leagueFactor, 3),
            'club' => round($clubFactor, 3),
            'traits' => round($traitFactor, 3),
            'ceiling' => round($individualCeiling)
        );
        return array(
            'market_value' => (int) $marketValue,
            'unrounded_market_value' => round($unrounded),
            'factors' => $factors,
            'reasons' => array(
                'Stärke ' . number_format($strength, 1, ',', '.') . '/100',
                'Alter ' . $age . ' (Faktor ' . number_format($ageFactor, 2, ',', '.') . ')',
                'Liga/Verein ' . number_format($leagueFactor * $clubFactor, 2, ',', '.'),
                'Wirtschaftsobergrenze ' . number_format($individualCeiling, 0, ',', '.') . ' EUR'
            ),
            'economy' => $economySnapshot
        );
    }

    /**
     * Recommended salary per match for new offers and extensions. The market
     * value already contains the economy, quality, age and status factors, so
     * salary negotiations use the same central economic basis.
     */
    public static function getRecommendedSalary($player) {
        $marketValue = 0;
        foreach (array('marktwert', 'player_marketvalue', 'marketvalue', 'market_value') as $key) {
            if (isset($player[$key])) {
                $marketValue = max($marketValue, (int) $player[$key]);
            }
        }
        $strength = 0;
        foreach (array('w_staerke', 'player_strength', 'strength') as $key) {
            if (isset($player[$key])) {
                $strength = max($strength, (float) $player[$key]);
            }
        }
        $salary = max(100, $strength * 20, $marketValue / 900);
        $salary = min(100000, $salary);
        $step = ($salary < 10000) ? 10 : 100;
        return (int) (round($salary / $step) * $step);
    }

    /**
     * Runs once for each newly completed match state. The regular one-minute
     * job can therefore stay enabled without recalculating the whole database
     * every minute.
     */
    public static function recalculateAfterLatestMatch(WebSoccer $websoccer, DbConnection $db) {
        self::ensureSchema($websoccer, $db);
        $prefix = $websoccer->getConfig('db_prefix');
        $result = $db->executeQuery("SELECT COALESCE(MAX(id), 0) AS latest_match_id FROM " . $prefix . "_spiel WHERE berechnet = '1'");
        $row = $result->fetch_assoc();
        $result->free();
        $latestMatchId = isset($row['latest_match_id']) ? (int) $row['latest_match_id'] : 0;
        $source = ($latestMatchId > 0) ? 'match_' . $latestMatchId : 'initial_job';
        $sourceEscaped = $db->connection->real_escape_string($source);
        $result = $db->executeQuery("SELECT id FROM " . $prefix . "_market_value_recalculation_log WHERE source = '" . $sourceEscaped . "' LIMIT 1");
        $alreadyProcessed = ($result && $result->num_rows > 0);
        if ($result) $result->free();
        if ($alreadyProcessed) {
            return array('skipped' => true, 'source' => $source, 'affected' => 0);
        }
        return self::recalculateAll($websoccer, $db, 0, $source);
    }

    /**
     * Recalculates every professional and youth player in one run. Updates are grouped
     * into CASE batches so the operation remains practical with large squads.
     */
    public static function recalculateAll(WebSoccer $websoccer, DbConnection $db, $adminId = 0, $source = 'admin') {
        if (function_exists('set_time_limit')) @set_time_limit(0);
        self::ensureSchema($websoccer, $db);
        self::$_economySnapshot = null;
        $economy = self::getEconomySnapshot($websoccer, $db);
        $players = self::_fetchPlayerRows($websoccer, $db, '', 0);
        self::_attachAllTraits($websoccer, $db, $players);
        self::_attachNationalStatus($websoccer, $db, $players);
        $youthPlayers = self::_fetchYouthPlayerRows($websoccer, $db, '', 0);
        self::_attachAllYouthTraits($websoccer, $db, $youthPlayers);

        $updates = array();
        $oldTotal = 0;
        $newTotal = 0;
        $increased = 0;
        $decreased = 0;
        $unchanged = 0;
        $above100Before = 0;
        $above100After = 0;

        foreach ($players as $player) {
            $calculation = self::calculate($websoccer, $db, $player, $economy);
            $oldValue = (int) self::_value($player, 'marktwert', 0);
            $newValue = (int) $calculation['market_value'];
            $oldTotal += $oldValue;
            $newTotal += $newValue;
            if ($oldValue > self::ABSOLUTE_MAX_VALUE) $above100Before++;
            if ($newValue > self::ABSOLUTE_MAX_VALUE) $above100After++;
            if ($newValue > $oldValue) $increased++;
            elseif ($newValue < $oldValue) $decreased++;
            else $unchanged++;

            $updates[] = array(
                'id' => (int) $player['id'],
                'market_value' => $newValue,
                'strength' => (int) $calculation['strength']
            );
            if (count($updates) >= self::UPDATE_BATCH_SIZE) {
                self::_writeBatch($websoccer, $db, $updates);
                $updates = array();
            }
        }
        if (count($updates)) {
            self::_writeBatch($websoccer, $db, $updates);
        }

        $youthUpdates = array();
        foreach ($youthPlayers as $youthPlayer) {
            $calculation = self::calculateYouth($websoccer, $db, $youthPlayer, $economy);
            $oldValue = (int) self::_value($youthPlayer, 'market_value', 0);
            $newValue = (int) $calculation['market_value'];
            $oldTotal += $oldValue;
            $newTotal += $newValue;
            if ($oldValue > self::ABSOLUTE_MAX_VALUE) $above100Before++;
            if ($newValue > self::ABSOLUTE_MAX_VALUE) $above100After++;
            if ($newValue > $oldValue) $increased++;
            elseif ($newValue < $oldValue) $decreased++;
            else $unchanged++;
            $youthUpdates[] = array('id' => (int) $youthPlayer['id'], 'market_value' => $newValue);
            if (count($youthUpdates) >= self::UPDATE_BATCH_SIZE) {
                self::_writeYouthBatch($websoccer, $db, $youthUpdates);
                $youthUpdates = array();
            }
        }
        if (count($youthUpdates)) self::_writeYouthBatch($websoccer, $db, $youthUpdates);

        $summary = array(
            'affected' => count($players) + count($youthPlayers),
            'professional_players' => count($players),
            'youth_players' => count($youthPlayers),
            'old_total' => $oldTotal,
            'new_total' => $newTotal,
            'increased' => $increased,
            'decreased' => $decreased,
            'unchanged' => $unchanged,
            'above_100m_before' => $above100Before,
            'above_100m_after' => $above100After,
            'economy' => $economy
        );
        self::_writeLog($websoccer, $db, $adminId, $source, $summary);
        return $summary;
    }

    /**
     * Preview for the admin page. Supports club, league, age, strength and
     * minimum deviation filters without changing values.
     */
    public static function getPreview(WebSoccer $websoccer, DbConnection $db, $filters = array(), $limit = 100) {
        $where = array('1 = 1');
        if (!empty($filters['club_id'])) $where[] = 'P.verein_id = ' . (int) $filters['club_id'];
        if (!empty($filters['league_id'])) $where[] = 'V.liga_id = ' . (int) $filters['league_id'];
        if (isset($filters['age_min']) && $filters['age_min'] !== '') $where[] = 'P.age >= ' . (int) $filters['age_min'];
        if (isset($filters['age_max']) && $filters['age_max'] !== '') $where[] = 'P.age <= ' . (int) $filters['age_max'];
        if (isset($filters['strength_min']) && $filters['strength_min'] !== '') $where[] = 'P.w_staerke >= ' . (float) $filters['strength_min'];
        if (isset($filters['strength_max']) && $filters['strength_max'] !== '') $where[] = 'P.w_staerke <= ' . (float) $filters['strength_max'];

        // Preview the highest current values first so expensive outliers cannot
        // be hidden merely because they have a high player ID. The final list
        // is still sorted by absolute deviation.
        $candidateLimit = max(2000, min(10000, max(1, min(1000, (int) $limit)) * 20));
        $rows = self::_fetchPlayerRows($websoccer, $db, implode(' AND ', $where), $candidateLimit, 'P.marktwert DESC, P.id ASC');
        self::_attachAllTraits($websoccer, $db, $rows);
        self::_attachNationalStatus($websoccer, $db, $rows);
        $economy = self::getEconomySnapshot($websoccer, $db);
        $result = array();
        $minimumDeviation = isset($filters['deviation_min']) && $filters['deviation_min'] !== '' ? abs((float) $filters['deviation_min']) : 0;

        foreach ($rows as $row) {
            $calculation = self::calculate($websoccer, $db, $row, $economy);
            $old = (int) $row['marktwert'];
            $new = (int) $calculation['market_value'];
            $deviationPercent = ($old > 0) ? (($new - $old) / $old) * 100 : (($new > 0) ? 100 : 0);
            if (abs($deviationPercent) < $minimumDeviation) continue;
            $row['calculation'] = $calculation;
            $row['proposed_market_value'] = $new;
            $row['deviation_amount'] = $new - $old;
            $row['deviation_percent'] = $deviationPercent;
            $result[] = $row;
        }

        usort($result, array('PlayerMarketValueDataService', '_sortByDeviation'));
        return array_slice($result, 0, max(1, min(1000, (int) $limit)));
    }

    public static function getYouthPreview(WebSoccer $websoccer, DbConnection $db, $filters = array(), $limit = 50) {
        self::ensureSchema($websoccer, $db);
        $where = array('1 = 1');
        if (!empty($filters['club_id'])) $where[] = 'P.team_id = ' . (int) $filters['club_id'];
        if (!empty($filters['league_id'])) $where[] = 'V.liga_id = ' . (int) $filters['league_id'];
        if (isset($filters['age_min']) && $filters['age_min'] !== '') $where[] = 'P.age >= ' . (int) $filters['age_min'];
        if (isset($filters['age_max']) && $filters['age_max'] !== '') $where[] = 'P.age <= ' . (int) $filters['age_max'];
        if (isset($filters['strength_min']) && $filters['strength_min'] !== '') $where[] = 'P.strength >= ' . (float) $filters['strength_min'];
        if (isset($filters['strength_max']) && $filters['strength_max'] !== '') $where[] = 'P.strength <= ' . (float) $filters['strength_max'];
        $candidateLimit = max(1000, min(5000, max(1, (int) $limit) * 20));
        $rows = self::_fetchYouthPlayerRows($websoccer, $db, implode(' AND ', $where), $candidateLimit, 'P.market_value DESC, P.id ASC');
        self::_attachAllYouthTraits($websoccer, $db, $rows);
        $economy = self::getEconomySnapshot($websoccer, $db);
        $result = array();
        $minimumDeviation = isset($filters['deviation_min']) && $filters['deviation_min'] !== '' ? abs((float) $filters['deviation_min']) : 0;
        foreach ($rows as $row) {
            $calculation = self::calculateYouth($websoccer, $db, $row, $economy);
            $old = (int) self::_value($row, 'market_value', 0);
            $new = (int) $calculation['market_value'];
            $deviationPercent = ($old > 0) ? (($new - $old) / $old) * 100 : (($new > 0) ? 100 : 0);
            if (abs($deviationPercent) < $minimumDeviation) continue;
            $row['calculation'] = $calculation;
            $row['proposed_market_value'] = $new;
            $row['deviation_amount'] = $new - $old;
            $row['deviation_percent'] = $deviationPercent;
            $result[] = $row;
        }
        usort($result, array('PlayerMarketValueDataService', '_sortByDeviation'));
        return array_slice($result, 0, max(1, min(500, (int) $limit)));
    }

    public static function getBenchmarks(WebSoccer $websoccer, DbConnection $db) {
        $ids = array(1847, 1331, 7057, 1401);
        $rows = self::_fetchPlayerRows($websoccer, $db, 'P.id IN (' . implode(',', $ids) . ')', 20);
        self::_attachAllTraits($websoccer, $db, $rows);
        self::_attachNationalStatus($websoccer, $db, $rows);
        $economy = self::getEconomySnapshot($websoccer, $db);
        foreach ($rows as $index => $row) {
            $rows[$index]['calculation'] = self::calculate($websoccer, $db, $row, $economy);
            $rows[$index]['proposed_market_value'] = $rows[$index]['calculation']['market_value'];
            $rows[$index]['deviation_amount'] = $rows[$index]['proposed_market_value'] - (int) $row['marktwert'];
            $rows[$index]['deviation_percent'] = ((int) $row['marktwert'] > 0)
                ? ($rows[$index]['deviation_amount'] / (int) $row['marktwert']) * 100 : 100;
        }
        usort($rows, array('PlayerMarketValueDataService', '_sortById'));
        return $rows;
    }

    public static function getEconomySnapshot(WebSoccer $websoccer, DbConnection $db) {
        if (self::$_economySnapshot !== null) return self::$_economySnapshot;
        $prefix = $websoccer->getConfig('db_prefix');

        $budgets = array();
        $result = $db->executeQuery("SELECT finanz_budget FROM " . $prefix . "_verein WHERE status = '1' AND finanz_budget > 0 ORDER BY finanz_budget ASC");
        while ($row = $result->fetch_assoc()) $budgets[] = (int) $row['finanz_budget'];
        $result->free();

        $medianBudget = self::_percentile($budgets, 0.50);
        $p90Budget = self::_percentile($budgets, 0.90);
        $maxBudget = count($budgets) ? max($budgets) : 0;

        $fees = array();
        $since = $websoccer->getNowAsTimestamp() - (365 * 24 * 60 * 60);
        $result = $db->executeQuery("SELECT COALESCE(NULLIF(T.directtransfer_amount, 0), A.abloese, 0) AS fee
            FROM " . $prefix . "_transfer AS T
            LEFT JOIN " . $prefix . "_transfer_angebot AS A ON A.id = T.bid_id
            WHERE T.datum >= '" . (int) $since . "'
              AND COALESCE(NULLIF(T.directtransfer_amount, 0), A.abloese, 0) > 0
            ORDER BY fee ASC");
        while ($row = $result->fetch_assoc()) {
            $fee = (int) $row['fee'];
            // Ignore historical outliers that cannot be financed by the present economy.
            if ($p90Budget <= 0 || $fee <= max(1000000, $p90Budget * 4)) $fees[] = $fee;
        }
        $result->free();

        $medianFee = self::_percentile($fees, 0.50);
        $p75Fee = self::_percentile($fees, 0.75);
        $candidate = max(
            2000000,
            $p90Budget * 1.35,
            $medianBudget * 3.0,
            $p75Fee * 1.25
        );
        $liquidityLimit = max(5000000, $p90Budget * 2.0, $maxBudget * 1.10);
        if (!count($budgets) && !count($fees)) {
            $ceiling = self::DEFAULT_ECONOMY_CEILING;
        } else {
            $ceiling = min(self::ABSOLUTE_MAX_VALUE, $candidate, $liquidityLimit);
            if ($ceiling <= 0) $ceiling = self::DEFAULT_ECONOMY_CEILING;
        }

        self::$_economySnapshot = array(
            'club_count' => count($budgets),
            'median_budget' => round($medianBudget),
            'p90_budget' => round($p90Budget),
            'max_budget' => round($maxBudget),
            'transfer_count' => count($fees),
            'median_transfer_fee' => round($medianFee),
            'p75_transfer_fee' => round($p75Fee),
            'market_ceiling' => self::_roundMarketValue($ceiling)
        );
        return self::$_economySnapshot;
    }

    public static function getRecentRuns(WebSoccer $websoccer, DbConnection $db, $limit = 20) {
        self::ensureSchema($websoccer, $db);
        $rows = array();
        $result = $db->querySelect('*', $websoccer->getConfig('db_prefix') . '_market_value_recalculation_log', 'id > 0 ORDER BY id DESC', null, max(1, (int) $limit));
        while ($row = $result->fetch_assoc()) $rows[] = $row;
        $result->free();
        return $rows;
    }

    public static function ensureSchema(WebSoccer $websoccer, DbConnection $db) {
        $prefix = $websoccer->getConfig('db_prefix');
        $youthTable = $prefix . '_youthplayer';
        if (self::_tableExists($db, $youthTable) && !self::_columnExists($db, $youthTable, 'market_value')) {
            $db->executeQuery("ALTER TABLE `" . $youthTable . "` ADD COLUMN `market_value` bigint(20) NOT NULL DEFAULT 0 AFTER `transfer_fee`");
            self::$_columnCache[$youthTable . '.market_value'] = true;
        }

        $table = $prefix . '_market_value_recalculation_log';
        if (!self::_tableExists($db, $table)) {
        $db->executeQuery("CREATE TABLE IF NOT EXISTS `" . $table . "` (
            `id` int(10) NOT NULL AUTO_INCREMENT,
            `created_date` int(11) NOT NULL DEFAULT 0,
            `admin_id` int(10) NOT NULL DEFAULT 0,
            `source` varchar(32) NOT NULL DEFAULT 'admin',
            `affected_players` int(10) NOT NULL DEFAULT 0,
            `old_total` bigint(20) NOT NULL DEFAULT 0,
            `new_total` bigint(20) NOT NULL DEFAULT 0,
            `increased` int(10) NOT NULL DEFAULT 0,
            `decreased` int(10) NOT NULL DEFAULT 0,
            `unchanged` int(10) NOT NULL DEFAULT 0,
            `above_100m_before` int(10) NOT NULL DEFAULT 0,
            `above_100m_after` int(10) NOT NULL DEFAULT 0,
            `market_ceiling` bigint(20) NOT NULL DEFAULT 0,
            `economy_json` mediumtext,
            PRIMARY KEY (`id`),
            KEY `idx_market_value_recalculation_date` (`created_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        self::$_tableCache[$table] = true;
        }
        if (!self::_columnExists($db, $table, 'affected_professional_players')) {
            $db->executeQuery("ALTER TABLE `" . $table . "` ADD COLUMN `affected_professional_players` int(10) NOT NULL DEFAULT 0 AFTER `affected_players`");
            self::$_columnCache[$table . '.affected_professional_players'] = true;
        }
        if (!self::_columnExists($db, $table, 'affected_youth_players')) {
            $db->executeQuery("ALTER TABLE `" . $table . "` ADD COLUMN `affected_youth_players` int(10) NOT NULL DEFAULT 0 AFTER `affected_professional_players`");
            self::$_columnCache[$table . '.affected_youth_players'] = true;
        }
    }

    public static function getClubs(WebSoccer $websoccer, DbConnection $db) {
        $rows = array();
        $result = $db->executeQuery('SELECT id, name FROM ' . $websoccer->getConfig('db_prefix') . "_verein WHERE status = '1' ORDER BY name ASC");
        while ($row = $result->fetch_assoc()) $rows[] = $row;
        $result->free();
        return $rows;
    }

    public static function getLeagues(WebSoccer $websoccer, DbConnection $db) {
        $rows = array();
        $result = $db->executeQuery('SELECT id, name, division FROM ' . $websoccer->getConfig('db_prefix') . '_liga ORDER BY land ASC, division ASC, name ASC');
        while ($row = $result->fetch_assoc()) $rows[] = $row;
        $result->free();
        return $rows;
    }

    private static function _fetchPlayerRow(WebSoccer $websoccer, DbConnection $db, $playerId) {
        $rows = self::_fetchPlayerRows($websoccer, $db, 'P.id = ' . (int) $playerId, 1);
        return count($rows) ? $rows[0] : null;
    }

    private static function _fetchPlayerRows(WebSoccer $websoccer, DbConnection $db, $where, $limit, $orderBy = 'P.id ASC') {
        $prefix = $websoccer->getConfig('db_prefix');
        $leagueRatingTable = $prefix . '_manager_league_rating';
        $hasLeagueRating = self::_tableExists($db, $leagueRatingTable);
        $joinRating = $hasLeagueRating ? ' LEFT JOIN ' . $leagueRatingTable . ' AS MLR ON MLR.league_id = L.id ' : '';
        $ratingColumn = $hasLeagueRating ? 'MLR.rating AS league_rating' : 'NULL AS league_rating';
        $sql = "SELECT P.*,
                V.name AS club_name, V.finanz_budget AS club_budget, V.strength AS club_strength,
                V.highscore AS club_highscore, V.superclub AS club_superclub, V.liga_id AS league_id,
                L.name AS league_name, L.land AS league_country, L.division AS league_division,
                " . $ratingColumn . "
            FROM " . $prefix . "_spieler AS P
            LEFT JOIN " . $prefix . "_verein AS V ON V.id = P.verein_id
            LEFT JOIN " . $prefix . "_liga AS L ON L.id = V.liga_id
            " . $joinRating;
        if (strlen(trim((string) $where))) $sql .= ' WHERE ' . $where;
        $sql .= ' ORDER BY ' . $orderBy;
        if ((int) $limit > 0) $sql .= ' LIMIT ' . (int) $limit;

        $rows = array();
        $result = $db->executeQuery($sql);
        while ($row = $result->fetch_assoc()) $rows[] = $row;
        $result->free();
        return $rows;
    }

    private static function _fetchYouthPlayerRows(WebSoccer $websoccer, DbConnection $db, $where, $limit, $orderBy = 'P.id ASC') {
        $prefix = $websoccer->getConfig('db_prefix');
        $leagueRatingTable = $prefix . '_manager_league_rating';
        $hasLeagueRating = self::_tableExists($db, $leagueRatingTable);
        $joinRating = $hasLeagueRating ? ' LEFT JOIN ' . $leagueRatingTable . ' AS MLR ON MLR.league_id = L.id ' : '';
        $ratingColumn = $hasLeagueRating ? 'MLR.rating AS league_rating' : 'NULL AS league_rating';
        $sql = "SELECT P.*, P.team_id AS verein_id,
                V.name AS club_name, V.finanz_budget AS club_budget, V.strength AS club_strength,
                V.highscore AS club_highscore, V.superclub AS club_superclub, V.liga_id AS league_id,
                L.name AS league_name, L.land AS league_country, L.division AS league_division,
                " . $ratingColumn . "
            FROM " . $prefix . "_youthplayer AS P
            LEFT JOIN " . $prefix . "_verein AS V ON V.id = P.team_id
            LEFT JOIN " . $prefix . "_liga AS L ON L.id = V.liga_id
            " . $joinRating;
        if (strlen(trim((string) $where))) $sql .= ' WHERE ' . $where;
        $sql .= ' ORDER BY ' . $orderBy;
        if ((int) $limit > 0) $sql .= ' LIMIT ' . (int) $limit;
        $rows = array();
        $result = $db->executeQuery($sql);
        while ($row = $result->fetch_assoc()) $rows[] = $row;
        $result->free();
        return $rows;
    }

    private static function _attachTraits(WebSoccer $websoccer, DbConnection $db, &$row) {
        if (!class_exists('PlayerTraitsDataService')) return;
        $traits = PlayerTraitsDataService::getTraitsOfPlayer($websoccer, $db, (int) $row['id']);
        $row['traits'] = $traits;
    }

    private static function _attachAllTraits(WebSoccer $websoccer, DbConnection $db, &$rows) {
        if (!count($rows) || !class_exists('PlayerTraitsDataService')) return;
        $ids = array();
        foreach ($rows as $row) $ids[] = (int) $row['id'];
        $traitsByPlayer = array();
        foreach (array_chunk($ids, 2000) as $chunk) {
            $chunkTraits = PlayerTraitsDataService::getTraitsOfPlayers($websoccer, $db, $chunk);
            foreach ($chunkTraits as $playerId => $traits) $traitsByPlayer[(int) $playerId] = $traits;
        }
        foreach ($rows as $index => $row) {
            $rows[$index]['traits'] = isset($traitsByPlayer[(int) $row['id']]) ? $traitsByPlayer[(int) $row['id']] : array();
        }
    }

    private static function _attachAllYouthTraits(WebSoccer $websoccer, DbConnection $db, &$rows) {
        if (!count($rows) || !class_exists('PlayerTraitsDataService')) return;
        $ids = array();
        foreach ($rows as $row) $ids[] = (int) $row['id'];
        $traitsByPlayer = array();
        foreach (array_chunk($ids, 2000) as $chunk) {
            $chunkTraits = PlayerTraitsDataService::getTraitsOfYouthPlayers($websoccer, $db, $chunk);
            foreach ($chunkTraits as $playerId => $traits) $traitsByPlayer[(int) $playerId] = $traits;
        }
        foreach ($rows as $index => $row) {
            $rows[$index]['traits'] = isset($traitsByPlayer[(int) $row['id']]) ? $traitsByPlayer[(int) $row['id']] : array();
        }
    }

    private static function _attachNationalStatus(WebSoccer $websoccer, DbConnection $db, &$rows) {
        if (!count($rows)) return;
        $prefix = $websoccer->getConfig('db_prefix');
        $nationalPlayerTable = $prefix . '_nationalplayer';
        $matchPlayerTable = $prefix . '_spiel_berechnung';
        $teamTable = $prefix . '_verein';
        $ids = array();
        foreach ($rows as $row) $ids[] = (int) $row['id'];
        $currentMap = array();
        $appearanceMap = array();

        foreach (array_chunk($ids, 1000) as $chunk) {
            $idList = implode(',', $chunk);
            if (self::_tableExists($db, $nationalPlayerTable)) {
                $result = $db->executeQuery('SELECT DISTINCT player_id FROM ' . $nationalPlayerTable . ' WHERE player_id IN (' . $idList . ')');
                while ($nationalRow = $result->fetch_assoc()) $currentMap[(int) $nationalRow['player_id']] = true;
                $result->free();
            }

            if (self::_tableExists($db, $matchPlayerTable)) {
                $result = $db->executeQuery('SELECT SB.spieler_id AS player_id, COUNT(DISTINCT SB.spiel_id) AS appearances '
                    . 'FROM ' . $matchPlayerTable . ' AS SB '
                    . 'INNER JOIN ' . $teamTable . " AS V ON V.id = SB.team_id AND V.nationalteam = '1' "
                    . 'WHERE SB.spieler_id IN (' . $idList . ') AND SB.minuten_gespielt > 0 '
                    . 'GROUP BY SB.spieler_id');
                while ($appearanceRow = $result->fetch_assoc()) {
                    $appearanceMap[(int) $appearanceRow['player_id']] = (int) $appearanceRow['appearances'];
                }
                $result->free();
            }
        }

        foreach ($rows as $index => $row) {
            $playerId = (int) $row['id'];
            $rows[$index]['is_national_player'] = isset($currentMap[$playerId]) ? 1 : 0;
            $rows[$index]['international_appearances'] = isset($appearanceMap[$playerId]) ? (int) $appearanceMap[$playerId] : 0;
        }
    }

    private static function _writeSingleResult(WebSoccer $websoccer, DbConnection $db, $playerId, $calculation) {
        $db->queryUpdate(array(
            'w_staerke_calc' => (int) $calculation['strength'],
            'marktwert' => (int) $calculation['market_value'],
            'on_update' => (int) $websoccer->getNowAsTimestamp()
        ), $websoccer->getConfig('db_prefix') . '_spieler', 'id = %d', (int) $playerId);
    }

    private static function _writeBatch(WebSoccer $websoccer, DbConnection $db, $updates) {
        if (!count($updates)) return;
        $ids = array();
        $marketCases = array();
        $strengthCases = array();
        foreach ($updates as $update) {
            $id = (int) $update['id'];
            $ids[] = $id;
            $marketCases[] = 'WHEN ' . $id . ' THEN ' . (int) $update['market_value'];
            $strengthCases[] = 'WHEN ' . $id . ' THEN ' . (int) $update['strength'];
        }
        $sql = 'UPDATE ' . $websoccer->getConfig('db_prefix') . '_spieler SET '
            . 'marktwert = CASE id ' . implode(' ', $marketCases) . ' END, '
            . 'w_staerke_calc = CASE id ' . implode(' ', $strengthCases) . ' END, '
            . 'on_update = ' . (int) $websoccer->getNowAsTimestamp()
            . ' WHERE id IN (' . implode(',', $ids) . ')';
        $db->executeQuery($sql);
    }

    private static function _writeYouthBatch(WebSoccer $websoccer, DbConnection $db, $updates) {
        if (!count($updates)) return;
        $ids = array();
        $marketCases = array();
        foreach ($updates as $update) {
            $id = (int) $update['id'];
            $ids[] = $id;
            $marketCases[] = 'WHEN ' . $id . ' THEN ' . (int) $update['market_value'];
        }
        $sql = 'UPDATE ' . $websoccer->getConfig('db_prefix') . '_youthplayer SET '
            . 'market_value = CASE id ' . implode(' ', $marketCases) . ' END '
            . 'WHERE id IN (' . implode(',', $ids) . ')';
        $db->executeQuery($sql);
    }

    private static function _writeLog(WebSoccer $websoccer, DbConnection $db, $adminId, $source, $summary) {
        $db->queryInsert(array(
            'created_date' => (int) $websoccer->getNowAsTimestamp(),
            'admin_id' => (int) $adminId,
            'source' => substr((string) $source, 0, 32),
            'affected_players' => (int) $summary['affected'],
            'affected_professional_players' => isset($summary['professional_players']) ? (int) $summary['professional_players'] : (int) $summary['affected'],
            'affected_youth_players' => isset($summary['youth_players']) ? (int) $summary['youth_players'] : 0,
            'old_total' => (int) $summary['old_total'],
            'new_total' => (int) $summary['new_total'],
            'increased' => (int) $summary['increased'],
            'decreased' => (int) $summary['decreased'],
            'unchanged' => (int) $summary['unchanged'],
            'above_100m_before' => (int) $summary['above_100m_before'],
            'above_100m_after' => (int) $summary['above_100m_after'],
            'market_ceiling' => (int) $summary['economy']['market_ceiling'],
            'economy_json' => json_encode($summary['economy'])
        ), $websoccer->getConfig('db_prefix') . '_market_value_recalculation_log');
    }

    private static function _calculatePositionQuality($player, $position) {
        $weights = array(
            'goaly' => array('w_penalty_killing' => 2.8, 'w_influence' => 1.5, 'w_flair' => 1.2, 'w_tackling' => 0.8, 'w_passing' => 0.6, 'w_pace' => 0.4),
            'defense' => array('w_tackling' => 2.2, 'w_heading' => 1.7, 'w_pace' => 1.2, 'w_passing' => 1.0, 'w_influence' => 0.9, 'w_creativity' => 0.5),
            'midfield' => array('w_passing' => 2.0, 'w_creativity' => 1.8, 'w_influence' => 1.1, 'w_pace' => 0.9, 'w_tackling' => 0.9, 'w_shooting' => 0.8, 'w_freekick' => 0.7),
            'striker' => array('w_shooting' => 2.4, 'w_heading' => 1.5, 'w_pace' => 1.5, 'w_flair' => 1.0, 'w_penalty' => 0.8, 'w_passing' => 0.6, 'w_creativity' => 0.5)
        );
        $selected = isset($weights[$position]) ? $weights[$position] : $weights['midfield'];
        $sum = 0.0;
        $weightSum = 0.0;
        foreach ($selected as $column => $weight) {
            $sum += self::_clamp((float) self::_value($player, $column, 0), 0, 100) * $weight;
            $weightSum += $weight;
        }
        return ($weightSum > 0) ? self::_clamp($sum / $weightSum, 0, 100) : 0;
    }

    private static function _youthAgeFactor($age) {
        $map = array(14 => 1.18, 15 => 1.15, 16 => 1.12, 17 => 1.08, 18 => 1.03, 19 => 0.96, 20 => 0.88, 21 => 0.80);
        if ($age < 14) return 1.20;
        if ($age > 21) return max(0.55, 0.80 - (($age - 21) * 0.06));
        return isset($map[$age]) ? $map[$age] : 1.0;
    }

    private static function _youthPerformanceFactor($player, $position) {
        $matches = max(0, (int) self::_value($player, 'st_matches', 0));
        if ($matches < 3) return 1.0;
        $goals = max(0, (int) self::_value($player, 'st_goals', 0));
        $assists = max(0, (int) self::_value($player, 'st_assists', 0));
        if ($position === 'striker') $score = (($goals * 1.0) + ($assists * 0.55)) / $matches;
        elseif ($position === 'midfield') $score = (($goals * 0.65) + ($assists * 0.90)) / $matches;
        elseif ($position === 'defense') $score = (($goals * 0.40) + ($assists * 0.50)) / $matches;
        else $score = ($assists * 0.20) / $matches;
        return self::_clamp(0.98 + min(0.14, $score * 0.14), 0.96, 1.12);
    }

    private static function _ageFactor($age) {
        $map = array(14 => 0.72, 15 => 0.76, 16 => 0.80, 17 => 0.85, 18 => 0.91, 19 => 0.98,
            20 => 1.05, 21 => 1.10, 22 => 1.14, 23 => 1.17, 24 => 1.20, 25 => 1.21,
            26 => 1.21, 27 => 1.18, 28 => 1.13, 29 => 1.06, 30 => 0.97, 31 => 0.87,
            32 => 0.77, 33 => 0.67, 34 => 0.58, 35 => 0.50, 36 => 0.43, 37 => 0.37,
            38 => 0.32, 39 => 0.28, 40 => 0.24);
        if ($age <= 0) return 1.0;
        if ($age < 14) return 0.65;
        if ($age > 40) return max(0.16, 0.24 - (($age - 40) * 0.02));
        return $map[$age];
    }

    private static function _positionFactor($position) {
        $factors = array('goaly' => 0.84, 'defense' => 0.94, 'midfield' => 1.00, 'striker' => 1.06);
        return isset($factors[$position]) ? $factors[$position] : 1.00;
    }

    private static function _talentFactor($player, $age, $strength) {
        $talent = self::_clamp((float) self::_value($player, 'w_talent', 3), 1, 6);
        $maximum = self::_clamp((float) self::_value($player, 'w_staerke_max', $strength), 0, 100);
        $potentialGap = max(0, $maximum - $strength);
        $youthWeight = ($age > 0) ? self::_clamp((30 - $age) / 12, 0, 1) : 0.4;
        $talentBonus = (($talent - 3) * 0.055) * (0.45 + (0.55 * $youthWeight));
        $potentialBonus = min(0.20, ($potentialGap / 100) * $youthWeight);
        return self::_clamp(1.0 + $talentBonus + $potentialBonus, 0.82, 1.35);
    }

    private static function _contractFactor(WebSoccer $websoccer, $remainingMatches) {
        $maximum = 60;
        try {
            $configuredMaximum = (int) $websoccer->getConfig('max_number_of_contract_matches');
            if ($configuredMaximum > 0) $maximum = $configuredMaximum;
        } catch (Exception $e) {
            $maximum = 60;
        }
        $ratio = self::_clamp($remainingMatches / $maximum, 0, 1);
        return 0.34 + (0.74 * sqrt($ratio));
    }

    private static function _healthFactor($player) {
        $injuredMatches = max(0, (int) self::_value($player, 'verletzt', 0));
        $factor = max(0.72, 1.0 - min(0.28, $injuredMatches * 0.035));
        if ((string) self::_value($player, 'personality', '') === 'injury_prone') $factor *= 0.93;
        return self::_clamp($factor, 0.68, 1.0);
    }

    private static function _formFactor($player) {
        $grade = (float) self::_value($player, 'note_schnitt', 0);
        $matches = max((int) self::_value($player, 'sa_spiele', 0), (int) self::_value($player, 'st_spiele', 0));
        if ($grade <= 0 || $matches < 3) return 1.0;
        // German-style grades: lower is better. Keep the impact intentionally bounded.
        return self::_clamp(1.115 - (($grade - 1.0) * 0.047), 0.88, 1.12);
    }

    private static function _performanceFactor($player, $position) {
        $matches = max(0, (int) self::_value($player, 'sa_spiele', 0));
        if ($matches < 3) return 1.0;
        $goals = max(0, (int) self::_value($player, 'sa_tore', 0));
        $assists = max(0, (int) self::_value($player, 'sa_assists', 0));
        $scorePerMatch = 0.0;
        if ($position === 'striker') $scorePerMatch = (($goals * 1.0) + ($assists * 0.55)) / $matches;
        elseif ($position === 'midfield') $scorePerMatch = (($goals * 0.65) + ($assists * 0.90)) / $matches;
        elseif ($position === 'defense') $scorePerMatch = (($goals * 0.45) + ($assists * 0.55)) / $matches;
        else $scorePerMatch = (($assists * 0.20)) / $matches;
        return self::_clamp(0.97 + min(0.18, $scorePerMatch * 0.16), 0.95, 1.15);
    }

    private static function _personalityFactor($personality) {
        $map = array('big_game_player' => 1.05, 'professional' => 1.04, 'leader' => 1.035,
            'ambitious' => 1.02, 'loyal' => 0.99, 'inconsistent' => 0.94,
            'troublemaker' => 0.91, 'injury_prone' => 0.94);
        return isset($map[$personality]) ? $map[$personality] : 1.0;
    }

    private static function _leagueFactor($player) {
        if ((int) self::_value($player, 'league_id', 0) < 1) return 0.92;
        $rating = self::_value($player, 'league_rating', null);
        if ($rating !== null && $rating !== '') {
            return self::_clamp(0.82 + ((float) $rating / 100 * 0.34), 0.82, 1.16);
        }
        $division = max(1, (int) self::_value($player, 'league_division', 1));
        $map = array(1 => 1.08, 2 => 0.98, 3 => 0.91, 4 => 0.85, 5 => 0.80);
        return isset($map[$division]) ? $map[$division] : 0.78;
    }


    private static function _internationalFactor($player) {
        $appearances = max(0, (int) self::_value($player, 'international_appearances', 0));
        $appearanceBonus = min(0.08, $appearances * 0.0016);
        $currentSquadBonus = !empty($player['is_national_player']) ? 0.02 : 0.0;
        return self::_clamp(1.0 + $appearanceBonus + $currentSquadBonus, 1.0, 1.10);
    }

    private static function _clubFactor($player) {
        if ((int) self::_value($player, 'verein_id', 0) < 1) return 0.92;
        $strength = self::_clamp((float) self::_value($player, 'club_strength', 50), 0, 100);
        $highscore = max(0, (float) self::_value($player, 'club_highscore', 0));
        $factor = 0.91 + ($strength / 100 * 0.14) + (min(200, $highscore) / 200 * 0.04);
        if ((string) self::_value($player, 'club_superclub', '0') === '1') $factor += 0.025;
        return self::_clamp($factor, 0.90, 1.12);
    }

    private static function _buildReasons($player, $factors, $age, $position) {
        $reasons = array();
        $reasons[] = 'Qualität ' . number_format($factors['quality'], 1, ',', '.') . '/100';
        $reasons[] = 'Wirtschaftsobergrenze ' . number_format($factors['ceiling'], 0, ',', '.') . ' EUR';
        $reasons[] = 'Alter ' . (int) $age . ' (Faktor ' . number_format($factors['age'], 2, ',', '.') . ')';
        $reasons[] = 'Position ' . self::_positionLabel($position) . ' (Faktor ' . number_format($factors['position'], 2, ',', '.') . ')';
        $reasons[] = 'Vertrag ' . (int) self::_value($player, 'vertrag_spiele', 0) . ' Spiele (Faktor ' . number_format($factors['contract'], 2, ',', '.') . ')';
        $notable = array('talent' => 'Talent/Potenzial', 'health' => 'Verletzungsrisiko', 'form' => 'Form',
            'performance' => 'Leistung', 'personality' => 'Persönlichkeit', 'league' => 'Ligastatus',
            'club' => 'Vereinsstatus', 'international' => 'Nationalteam/Länderspiele', 'traits' => 'Spezialfähigkeiten');
        foreach ($notable as $key => $label) {
            if (abs($factors[$key] - 1.0) >= 0.015) $reasons[] = $label . ' ' . number_format($factors[$key], 2, ',', '.');
        }
        $appearances = max(0, (int) self::_value($player, 'international_appearances', 0));
        if ($appearances > 0) $reasons[] = 'Länderspiele ' . $appearances;
        return $reasons;
    }

    private static function _roundMarketValue($value) {
        $value = max(0, (float) $value);
        if ($value < 100000) $step = 1000;
        elseif ($value < 1000000) $step = 5000;
        elseif ($value < 10000000) $step = 25000;
        else $step = 100000;
        return (int) (round($value / $step) * $step);
    }

    private static function _percentile($values, $percentile) {
        if (!is_array($values) || !count($values)) return 0;
        sort($values, SORT_NUMERIC);
        $index = (count($values) - 1) * self::_clamp($percentile, 0, 1);
        $lower = (int) floor($index);
        $upper = (int) ceil($index);
        if ($lower === $upper) return (float) $values[$lower];
        $weight = $index - $lower;
        return ((float) $values[$lower] * (1 - $weight)) + ((float) $values[$upper] * $weight);
    }

    private static function _normalizePosition($position) {
        if ($position === 'Torwart' || $position === 'goaly') return 'goaly';
        if ($position === 'Abwehr' || $position === 'defense') return 'defense';
        if ($position === 'Mittelfeld' || $position === 'midfield') return 'midfield';
        return 'striker';
    }

    private static function _positionLabel($position) {
        $map = array('goaly' => 'Torwart', 'defense' => 'Abwehr', 'midfield' => 'Mittelfeld', 'striker' => 'Sturm');
        return isset($map[$position]) ? $map[$position] : $position;
    }

    private static function _ageFromBirthday($birthday) {
        $timestamp = strtotime($birthday);
        if (!$timestamp) return 0;
        $birthYear = (int) date('Y', $timestamp);
        $birthMonthDay = date('md', $timestamp);
        $year = (int) date('Y');
        return $year - $birthYear - ((int) date('md') < (int) $birthMonthDay ? 1 : 0);
    }

    private static function _value($array, $key, $default = null) {
        return (is_array($array) && array_key_exists($key, $array)) ? $array[$key] : $default;
    }

    private static function _clamp($value, $minimum, $maximum) {
        return max($minimum, min($maximum, $value));
    }

    private static function _tableExists(DbConnection $db, $table) {
        if (isset(self::$_tableCache[$table])) return self::$_tableCache[$table];
        $escaped = $db->connection->real_escape_string($table);
        $result = $db->executeQuery("SHOW TABLES LIKE '" . $escaped . "'");
        $exists = ($result && $result->num_rows > 0);
        if ($result) $result->free();
        self::$_tableCache[$table] = $exists;
        return $exists;
    }

    private static function _columnExists(DbConnection $db, $table, $column) {
        $key = $table . '.' . $column;
        if (isset(self::$_columnCache[$key])) return self::$_columnCache[$key];
        $escapedColumn = $db->connection->real_escape_string($column);
        $result = $db->executeQuery("SHOW COLUMNS FROM `" . $table . "` LIKE '" . $escapedColumn . "'");
        $exists = ($result && $result->num_rows > 0);
        if ($result) $result->free();
        self::$_columnCache[$key] = $exists;
        return $exists;
    }

    private static function _sortByDeviation($a, $b) {
        $aValue = abs((float) $a['deviation_percent']);
        $bValue = abs((float) $b['deviation_percent']);
        if ($aValue == $bValue) return 0;
        return ($aValue > $bValue) ? -1 : 1;
    }

    private static function _sortById($a, $b) {
        return ((int) $a['id']) - ((int) $b['id']);
    }
}

?>
