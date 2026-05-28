<?php
/******************************************************

  This file is part of OpenWebSoccer-Sim.

******************************************************/

/**
 * Lightweight tactical style identity for clubs.
 *
 * Human clubs can choose one persistent identity. Computer clubs receive an
 * automatic style based on their squad / matchday players. The match impact is
 * intentionally small and capped.
 */
class TacticalStyleDataService {

    const CONFIG_ENABLED = 'tactical_style';

    private static $_schemaReady = null;
    private static $_columnCache = array();

    public static function isEnabled(WebSoccer $websoccer) {
        try {
            $value = $websoccer->getConfig(self::CONFIG_ENABLED);
        } catch (Exception $e) {
            return TRUE;
        }
        if ($value === null || $value === '') {
            return TRUE;
        }
        return ($value === TRUE || $value === 1 || $value === '1' || $value === 'true');
    }

    public static function getStyles() {
        return array(
            'possession',
            'counterattack',
            'pressing',
            'defensive_block',
            'wing_play',
            'set_pieces',
            'youth_focused',
            'physical'
        );
    }

    public static function normalizeStyle($style) {
        $style = trim((string) $style);
        return in_array($style, self::getStyles(), TRUE) ? $style : '';
    }

    public static function getPageData(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $teamId, $userId) {
        if (!self::isEnabled($websoccer) || (int) $teamId < 1) {
            return array('enabled' => FALSE, 'human_team' => FALSE, 'styles' => array(), 'current' => array());
        }

        self::ensureSchema($websoccer, $db);
        $team = self::getTeam($websoccer, $db, $teamId);
        $humanTeam = ($team && (int) $team['user_id'] > 0 && (int) $team['user_id'] === (int) $userId);
        if (!$humanTeam) {
            return array('enabled' => TRUE, 'human_team' => FALSE, 'styles' => array(), 'current' => array());
        }

        $rows = self::getSquadRows($websoccer, $db, $teamId);
        $scores = self::calculateAllStyleFitsFromRows($websoccer, $db, $teamId, $rows);
        $selected = self::normalizeStyle(isset($team['tactical_style']) ? $team['tactical_style'] : '');
        if (!strlen($selected)) {
            $selected = self::recommendStyleFromScores($scores);
            self::storeStyleSnapshot($websoccer, $db, $teamId, $selected, $scores[$selected]['fit'], $scores[$selected]['effect'], FALSE);
        }

        $styles = array();
        foreach (self::getStyles() as $style) {
            $styles[] = array(
                'key' => $style,
                'label' => self::message($i18n, 'tacticalstyle_' . $style),
                'description' => self::message($i18n, 'tacticalstyle_' . $style . '_help'),
                'fit' => isset($scores[$style]) ? (int) $scores[$style]['fit'] : 50,
                'effect' => isset($scores[$style]) ? (int) $scores[$style]['effect'] : 0,
                'selected' => ($style === $selected)
            );
        }

        return array(
            'enabled' => TRUE,
            'human_team' => TRUE,
            'styles' => $styles,
            'current' => array(
                'key' => $selected,
                'label' => self::message($i18n, 'tacticalstyle_' . $selected),
                'description' => self::message($i18n, 'tacticalstyle_' . $selected . '_help'),
                'fit' => isset($scores[$selected]) ? (int) $scores[$selected]['fit'] : 50,
                'effect' => isset($scores[$selected]) ? (int) $scores[$selected]['effect'] : 0,
                'hint_key' => self::getFitHintKey(isset($scores[$selected]) ? (int) $scores[$selected]['fit'] : 50),
                'fit_class' => self::getFitClass(isset($scores[$selected]) ? (int) $scores[$selected]['fit'] : 50)
            )
        );
    }

    public static function saveHumanStyle(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $teamId, $userId, $style) {
        if (!self::isEnabled($websoccer) || (int) $teamId < 1) {
            return;
        }

        self::ensureSchema($websoccer, $db);
        $style = self::normalizeStyle($style);
        if (!strlen($style)) {
            return;
        }

        $team = self::getTeam($websoccer, $db, $teamId);
        if (!$team || (int) $team['user_id'] < 1 || (int) $team['user_id'] !== (int) $userId) {
            return;
        }

        $oldStyle = self::normalizeStyle(isset($team['tactical_style']) ? $team['tactical_style'] : '');
        $rows = self::getSquadRows($websoccer, $db, $teamId);
        $scores = self::calculateAllStyleFitsFromRows($websoccer, $db, $teamId, $rows);
        $fit = isset($scores[$style]) ? (int) $scores[$style]['fit'] : 50;
        $effect = isset($scores[$style]) ? (int) $scores[$style]['effect'] : 0;

        if ($oldStyle === $style) {
            self::storeStyleSnapshot($websoccer, $db, $teamId, $style, $fit, $effect, FALSE);
            return;
        }

        self::storeStyleSnapshot($websoccer, $db, $teamId, $style, $fit, $effect, TRUE);

        // Create public news only for a real change, not for the initial automatic default.
        if (strlen($oldStyle)) {
            self::createStyleChangeNews($websoccer, $db, $i18n, $team['name'], $oldStyle, $style, $fit);
        }
    }

    public static function applyMatchEffects(WebSoccer $websoccer, DbConnection $db, SimulationMatch $match) {
        if (!$match || !self::isEnabled($websoccer)) {
            return;
        }

        if ($match->homeTeam) {
            self::applyTeamMatchEffect($websoccer, $db, $match->homeTeam);
        }
        if ($match->guestTeam) {
            self::applyTeamMatchEffect($websoccer, $db, $match->guestTeam);
        }
    }

    public static function applyTeamMatchEffect(WebSoccer $websoccer, DbConnection $db, SimulationTeam $team) {
        if (!$team || (int) $team->id < 1) {
            return;
        }

        self::ensureSchema($websoccer, $db);
        $teamInfo = self::getTeam($websoccer, $db, $team->id);
        $humanTeam = ($teamInfo && (int) $teamInfo['user_id'] > 0);
        $style = '';
        if ($humanTeam) {
            $style = self::normalizeStyle(isset($teamInfo['tactical_style']) ? $teamInfo['tactical_style'] : '');
        }

        $scores = self::calculateAllStyleFitsFromSimulationTeam($websoccer, $db, $team);
        if (!strlen($style)) {
            $style = self::recommendStyleFromScores($scores);
        }

        $fit = isset($scores[$style]) ? (int) $scores[$style]['fit'] : 50;
        $effect = isset($scores[$style]) ? (int) $scores[$style]['effect'] : 0;

        $team->tacticalStyle = $style;
        $team->tacticalStyleFit = $fit;
        $team->tacticalStyleEffect = $effect;

        if (!$team->isNationalTeam) {
            self::storeStyleSnapshot($websoccer, $db, $team->id, $style, $fit, $effect, (!$humanTeam));
        }
    }

    public static function getPassSuccessEffect($team, $opponentTeam = null) {
        $effect = self::getTeamRuntimeEffect($team);
        $style = self::getTeamRuntimeStyle($team);
        $modifier = 0;

        if ($style === 'possession') {
            $modifier += $effect;
        } elseif ($style === 'wing_play' || $style === 'youth_focused') {
            $modifier += (int) round($effect / 2);
        } elseif ($style === 'physical' && $effect < 0) {
            $modifier += (int) round($effect / 2);
        }

        if ($opponentTeam) {
            $opponentStyle = self::getTeamRuntimeStyle($opponentTeam);
            $opponentEffect = self::getTeamRuntimeEffect($opponentTeam);
            if ($opponentStyle === 'pressing' || $opponentStyle === 'physical') {
                $modifier -= (int) round($opponentEffect / 2);
            }
        }

        return max(-5, min(5, $modifier));
    }

    public static function getShootProbabilityEffect($team, $opponentTeam = null, $player = null) {
        $effect = self::getTeamRuntimeEffect($team);
        $style = self::getTeamRuntimeStyle($team);
        $modifier = 0;

        if ($style === 'counterattack' || $style === 'pressing') {
            $modifier += $effect;
        } elseif ($style === 'possession' || $style === 'wing_play') {
            $modifier += (int) round($effect / 2);
        } elseif ($style === 'youth_focused') {
            $modifier += (int) round($effect / 3);
        }

        if ($opponentTeam && self::getTeamRuntimeStyle($opponentTeam) === 'defensive_block') {
            $modifier -= (int) round(self::getTeamRuntimeEffect($opponentTeam) / 2);
        }

        return max(-5, min(5, $modifier));
    }

    public static function getTackleProbabilityEffect($pressingTeam) {
        $style = self::getTeamRuntimeStyle($pressingTeam);
        $effect = self::getTeamRuntimeEffect($pressingTeam);
        if ($style === 'pressing' || $style === 'physical' || $style === 'defensive_block') {
            return max(-4, min(4, $effect));
        }
        return 0;
    }

    public static function getGoalChanceEffect($team, $opponentTeam = null, $player = null, $situation = 'shot') {
        $style = self::getTeamRuntimeStyle($team);
        $effect = self::getTeamRuntimeEffect($team);
        $modifier = 0;

        if ($situation === 'freekick' && $style === 'set_pieces') {
            $modifier += $effect;
        } elseif ($situation === 'shot' && ($style === 'counterattack' || $style === 'wing_play')) {
            $modifier += (int) round($effect / 2);
        }

        if ($opponentTeam && self::getTeamRuntimeStyle($opponentTeam) === 'defensive_block') {
            $modifier -= (int) round(self::getTeamRuntimeEffect($opponentTeam) / 2);
        }

        return max(-5, min(5, $modifier));
    }

    private static function calculateAllStyleFitsFromRows(WebSoccer $websoccer, DbConnection $db, $teamId, $rows) {
        $scores = array();
        foreach (self::getStyles() as $style) {
            $fit = self::calculateStyleFitFromRows($style, $rows);
            $fit = self::applySynergyBonus($websoccer, $db, $teamId, $style, $fit);
            $scores[$style] = array('fit' => $fit, 'effect' => self::scoreToMatchEffect($fit));
        }
        return $scores;
    }

    private static function calculateAllStyleFitsFromSimulationTeam(WebSoccer $websoccer, DbConnection $db, SimulationTeam $team) {
        $rows = self::simulationTeamToRows($team);
        $scores = array();
        foreach (self::getStyles() as $style) {
            $fit = self::calculateStyleFitFromRows($style, $rows);
            $fit = self::applySynergyBonus($websoccer, $db, $team->id, $style, $fit);
            $scores[$style] = array('fit' => $fit, 'effect' => self::scoreToMatchEffect($fit));
        }
        return $scores;
    }

    private static function calculateStyleFitFromRows($style, $rows) {
        if (!is_array($rows) || !count($rows)) {
            return 50;
        }

        $avg = self::aggregateRows($rows);
        switch ($style) {
            case 'possession':
                $fit = self::weighted(array(array($avg['passing'], 35), array($avg['technique'], 25), array($avg['creativity'], 25), array($avg['flair'], 15)));
                break;
            case 'counterattack':
                $fit = self::weighted(array(array($avg['pace'], 35), array($avg['tackling'], 25), array($avg['freshness'], 20), array($avg['passing'], 20)));
                break;
            case 'pressing':
                $fit = self::weighted(array(array($avg['stamina'], 35), array($avg['tackling'], 30), array($avg['pace'], 20), array($avg['freshness'], 15)));
                break;
            case 'defensive_block':
                $fit = self::weighted(array(array($avg['tackling'], 35), array($avg['heading'], 25), array($avg['stamina'], 20), array($avg['influence'], 20)));
                break;
            case 'wing_play':
                $fit = self::weighted(array(array($avg['pace'], 30), array($avg['passing'], 25), array($avg['flair'], 25), array($avg['creativity'], 20)));
                break;
            case 'set_pieces':
                $fit = self::weighted(array(array($avg['freekick'], 40), array($avg['heading'], 25), array($avg['shooting'], 20), array($avg['influence'], 15)));
                break;
            case 'youth_focused':
                $fit = self::weighted(array(array($avg['talent_norm'], 45), array($avg['youth_share'], 30), array($avg['freshness'], 15), array($avg['influence'], 10)));
                break;
            case 'physical':
                $fit = self::weighted(array(array($avg['stamina'], 35), array($avg['heading'], 25), array($avg['tackling'], 25), array($avg['strength'], 15)));
                break;
            default:
                $fit = 50;
        }

        return max(1, min(100, (int) round($fit)));
    }

    private static function aggregateRows($rows) {
        $sum = array(
            'strength' => 0,
            'technique' => 0,
            'stamina' => 0,
            'freshness' => 0,
            'talent_norm' => 0,
            'passing' => 0,
            'shooting' => 0,
            'heading' => 0,
            'tackling' => 0,
            'freekick' => 0,
            'pace' => 0,
            'creativity' => 0,
            'influence' => 0,
            'flair' => 0,
            'youth_share' => 0
        );
        $count = max(1, count($rows));
        $youth = 0;

        foreach ($rows as $row) {
            $sum['strength'] += self::normalizePercent(isset($row['strength']) ? $row['strength'] : 50);
            $sum['technique'] += self::normalizePercent(isset($row['technique']) ? $row['technique'] : 50);
            $sum['stamina'] += self::normalizePercent(isset($row['stamina']) ? $row['stamina'] : 50);
            $sum['freshness'] += self::normalizePercent(isset($row['freshness']) ? $row['freshness'] : 50);
            $sum['talent_norm'] += max(1, min(100, (float) (isset($row['talent']) ? $row['talent'] : 3) * 20));
            $sum['passing'] += self::normalizePercent(isset($row['passing']) ? $row['passing'] : 50);
            $sum['shooting'] += self::normalizePercent(isset($row['shooting']) ? $row['shooting'] : 50);
            $sum['heading'] += self::normalizePercent(isset($row['heading']) ? $row['heading'] : 50);
            $sum['tackling'] += self::normalizePercent(isset($row['tackling']) ? $row['tackling'] : 50);
            $sum['freekick'] += self::normalizePercent(isset($row['freekick']) ? $row['freekick'] : 50);
            $sum['pace'] += self::normalizePercent(isset($row['pace']) ? $row['pace'] : 50);
            $sum['creativity'] += self::normalizePercent(isset($row['creativity']) ? $row['creativity'] : 50);
            $sum['influence'] += self::normalizePercent(isset($row['influence']) ? $row['influence'] : 50);
            $sum['flair'] += self::normalizePercent(isset($row['flair']) ? $row['flair'] : 50);

            $age = isset($row['age']) ? (int) $row['age'] : 99;
            if ($age > 0 && $age <= 23) {
                $youth++;
            }
        }

        foreach ($sum as $key => $value) {
            if ($key !== 'youth_share') {
                $sum[$key] = $value / $count;
            }
        }
        $sum['youth_share'] = ($youth / $count) * 100;
        return $sum;
    }

    private static function weighted($values) {
        $weighted = 0;
        $totalWeight = 0;
        foreach ($values as $item) {
            $value = isset($item[0]) ? (float) $item[0] : 50;
            $weight = isset($item[1]) ? (float) $item[1] : 0;
            $weighted += $value * $weight;
            $totalWeight += $weight;
        }
        if ($totalWeight <= 0) {
            return 50;
        }
        return $weighted / $totalWeight;
    }

    private static function applySynergyBonus(WebSoccer $websoccer, DbConnection $db, $teamId, $style, $fit) {
        $bonus = 0;

        if (class_exists('ClubStaffDataService')) {
            try {
                $assistant = ClubStaffDataService::getRoleBonus($websoccer, $db, $teamId, ClubStaffDataService::ROLE_ASSISTANT_MANAGER);
                $bonus += min(3, (int) round($assistant / 4));

                if ($style === 'pressing' || $style === 'counterattack' || $style === 'physical') {
                    $fitness = ClubStaffDataService::getRoleBonus($websoccer, $db, $teamId, ClubStaffDataService::ROLE_FITNESS_COACH);
                    $bonus += min(2, (int) round($fitness / 5));
                }

                if ($style === 'youth_focused') {
                    $youth = ClubStaffDataService::getRoleBonus($websoccer, $db, $teamId, ClubStaffDataService::ROLE_YOUTH_COACH);
                    $bonus += min(3, (int) round($youth / 4));
                }
            } catch (Exception $e) {
                // Optional staff integration.
            }
        }

        $bonus += self::getRecentTrainingBonus($websoccer, $db, $teamId, $style);

        return max(1, min(100, (int) round($fit + $bonus)));
    }

    private static function getRecentTrainingBonus(WebSoccer $websoccer, DbConnection $db, $teamId, $style) {
        try {
            $table = $websoccer->getConfig('db_prefix') . '_training_report';
            $result = $db->querySelect('training_type', $table, 'team_id = %d ORDER BY created_date DESC, id DESC', (int) $teamId, 1);
            $row = $result->fetch_array();
            $result->free();
            if (!$row || !isset($row['training_type'])) {
                return 0;
            }
            $training = (string) $row['training_type'];
            $mapping = array(
                'possession' => array('passing', 'technique', 'matchprep'),
                'counterattack' => array('athletics', 'passing', 'matchprep'),
                'pressing' => array('athletics', 'defense', 'matchprep'),
                'defensive_block' => array('defense', 'matchprep'),
                'wing_play' => array('passing', 'athletics', 'technique'),
                'set_pieces' => array('setpieces'),
                'youth_focused' => array('teambuilding', 'matchprep'),
                'physical' => array('athletics', 'defense')
            );
            return (isset($mapping[$style]) && in_array($training, $mapping[$style], TRUE)) ? 2 : 0;
        } catch (Exception $e) {
            return 0;
        }
    }

    private static function scoreToMatchEffect($fit) {
        $effect = (int) round(((int) $fit - 55) / 9);
        return max(-3, min(5, $effect));
    }

    private static function recommendStyleFromScores($scores) {
        $bestStyle = 'possession';
        $bestFit = -1;
        foreach (self::getStyles() as $style) {
            $fit = (isset($scores[$style]['fit'])) ? (int) $scores[$style]['fit'] : 0;
            if ($fit > $bestFit) {
                $bestFit = $fit;
                $bestStyle = $style;
            }
        }
        return $bestStyle;
    }

    private static function simulationTeamToRows(SimulationTeam $team) {
        $rows = array();
        foreach ($team->positionsAndPlayers as $position => $players) {
            foreach ($players as $player) {
                $rows[] = array(
                    'strength' => $player->strength,
                    'technique' => $player->strengthTech,
                    'stamina' => $player->strengthStamina,
                    'freshness' => $player->strengthFreshness,
                    'talent' => 3,
                    'passing' => $player->strengthPassing,
                    'shooting' => $player->strengthShooting,
                    'heading' => $player->strengthHeading,
                    'tackling' => $player->strengthTackling,
                    'freekick' => $player->strengthFreekick,
                    'pace' => $player->strengthPace,
                    'creativity' => $player->strengthCreativity,
                    'influence' => $player->strengthInfluence,
                    'flair' => $player->strengthFlair,
                    'age' => $player->age
                );
            }
        }
        return $rows;
    }

    private static function getSquadRows(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $rows = array();
        try {
            $ageColumn = ($websoccer->getConfig('players_aging') == 'birthday') ? 'TIMESTAMPDIFF(YEAR, geburtstag, CURDATE())' : 'age';
            $columns = array(
                'w_staerke' => 'strength',
                'w_technik' => 'technique',
                'w_kondition' => 'stamina',
                'w_frische' => 'freshness',
                'w_talent' => 'talent',
                'w_passing' => 'passing',
                'w_shooting' => 'shooting',
                'w_heading' => 'heading',
                'w_tackling' => 'tackling',
                'w_freekick' => 'freekick',
                'w_pace' => 'pace',
                'w_creativity' => 'creativity',
                'w_influence' => 'influence',
                'w_flair' => 'flair',
                $ageColumn => 'age'
            );
            $result = $db->querySelect($columns, $websoccer->getConfig('db_prefix') . '_spieler', "verein_id = %d AND status = '1' ORDER BY w_staerke DESC", (int) $teamId, 24);
            while ($row = $result->fetch_array()) {
                $rows[] = $row;
            }
            $result->free();
        } catch (Exception $e) {
            $rows = array();
        }
        return $rows;
    }

    private static function getTeam(WebSoccer $websoccer, DbConnection $db, $teamId) {
        try {
            $columns = 'id, name, user_id, nationalteam, status';
            if (self::schemaReady($websoccer, $db)) {
                $columns .= ', tactical_style, tactical_style_fit, tactical_style_effect, tactical_style_updated';
            }
            $result = $db->querySelect($columns, $websoccer->getConfig('db_prefix') . '_verein', 'id = %d', (int) $teamId, 1);
            $team = $result->fetch_array();
            $result->free();
            return $team ? $team : array();
        } catch (Exception $e) {
            return array();
        }
    }

    private static function storeStyleSnapshot(WebSoccer $websoccer, DbConnection $db, $teamId, $style, $fit, $effect, $includeStyle) {
        if (!self::schemaReady($websoccer, $db)) {
            return;
        }
        $columns = array(
            'tactical_style_fit' => (int) $fit,
            'tactical_style_effect' => (int) $effect,
            'tactical_style_updated' => (int) $websoccer->getNowAsTimestamp()
        );
        if ($includeStyle || strlen($style)) {
            $columns['tactical_style'] = $style;
        }
        try {
            $db->queryUpdate($columns, $websoccer->getConfig('db_prefix') . '_verein', 'id = %d', (int) $teamId);
        } catch (Exception $e) {
            // Runtime effect should continue even if the optional snapshot cannot be written.
        }
    }

    private static function createStyleChangeNews(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $teamName, $oldStyle, $newStyle, $fit) {
        $title = self::message($i18n, 'tacticalstyle_news_title');
        $message = self::message($i18n, 'tacticalstyle_news_message');
        $message = str_replace('{team}', $teamName, $message);
        $message = str_replace('{oldstyle}', self::message($i18n, 'tacticalstyle_' . $oldStyle), $message);
        $message = str_replace('{newstyle}', self::message($i18n, 'tacticalstyle_' . $newStyle), $message);
        $message = str_replace('{fit}', (int) $fit, $message);

        try {
            $db->queryInsert(array(
                'datum' => $websoccer->getNowAsTimestamp(),
                'autor_id' => 1,
                'titel' => $title,
                'nachricht' => $message,
                'linktext1' => self::message($i18n, 'tacticalstyle_news_link'),
                'linkurl1' => $websoccer->getInternalUrl('formation'),
                'c_br' => '1',
                'c_links' => '1',
                'c_smilies' => '0',
                'status' => '1'
            ), $websoccer->getConfig('db_prefix') . '_news');
        } catch (Exception $e) {
            // Optional news integration.
        }
    }

    private static function getTeamRuntimeStyle($team) {
        if (!$team || !isset($team->tacticalStyle)) {
            return '';
        }
        return self::normalizeStyle($team->tacticalStyle);
    }

    private static function getTeamRuntimeEffect($team) {
        if (!$team || !isset($team->tacticalStyleEffect)) {
            return 0;
        }
        return max(-3, min(5, (int) $team->tacticalStyleEffect));
    }

    private static function normalizePercent($value) {
        return max(1, min(100, (float) str_replace(',', '.', (string) $value)));
    }

    private static function getFitHintKey($fit) {
        if ($fit >= 75) {
            return 'tacticalstyle_fit_good';
        }
        if ($fit <= 45) {
            return 'tacticalstyle_fit_bad';
        }
        return 'tacticalstyle_fit_neutral';
    }

    private static function getFitClass($fit) {
        if ($fit >= 75) {
            return 'label-success';
        }
        if ($fit <= 45) {
            return 'label-important';
        }
        return 'label-info';
    }

    private static function message(I18n $i18n, $key) {
        return $i18n->hasMessage($key) ? $i18n->getMessage($key) : $key;
    }

    public static function ensureSchema(WebSoccer $websoccer, DbConnection $db) {
        if (self::$_schemaReady === TRUE) {
            return;
        }
        $table = $websoccer->getConfig('db_prefix') . '_verein';
        self::ensureColumn($db, $table, 'tactical_style', "ALTER TABLE " . $table . " ADD COLUMN tactical_style VARCHAR(32) NOT NULL DEFAULT ''");
        self::ensureColumn($db, $table, 'tactical_style_fit', "ALTER TABLE " . $table . " ADD COLUMN tactical_style_fit TINYINT(3) NOT NULL DEFAULT 0");
        self::ensureColumn($db, $table, 'tactical_style_effect', "ALTER TABLE " . $table . " ADD COLUMN tactical_style_effect TINYINT(4) NOT NULL DEFAULT 0");
        self::ensureColumn($db, $table, 'tactical_style_updated', "ALTER TABLE " . $table . " ADD COLUMN tactical_style_updated INT(11) NOT NULL DEFAULT 0");
        self::$_schemaReady = TRUE;
    }

    private static function schemaReady(WebSoccer $websoccer, DbConnection $db) {
        if (self::$_schemaReady === TRUE) {
            return TRUE;
        }
        $table = $websoccer->getConfig('db_prefix') . '_verein';
        return self::columnExists($db, $table, 'tactical_style');
    }

    private static function ensureColumn(DbConnection $db, $table, $column, $alterSql) {
        if (self::columnExists($db, $table, $column)) {
            return;
        }
        $db->executeQuery($alterSql);
        self::$_columnCache[$table . ':' . $column] = TRUE;
    }

    private static function columnExists(DbConnection $db, $table, $column) {
        $key = $table . ':' . $column;
        if (isset(self::$_columnCache[$key])) {
            return self::$_columnCache[$key];
        }
        try {
            $result = $db->executeQuery("SHOW COLUMNS FROM " . $table . " LIKE '" . $db->connection->real_escape_string($column) . "'");
            $row = $result->fetch_array();
            $result->free();
            self::$_columnCache[$key] = ($row) ? TRUE : FALSE;
            return self::$_columnCache[$key];
        } catch (Exception $e) {
            self::$_columnCache[$key] = FALSE;
            return FALSE;
        }
    }
}
?>
