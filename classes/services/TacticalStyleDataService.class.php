<?php
/******************************************************

  This file is part of OpenWebSoccer-Sim.

******************************************************/

/**
 * Club Identity / Tactical DNA.
 *
 * Uses the existing club columns tactical_style, tactical_style_fit and
 * tactical_style_effect, but limits the public concept to six clear styles.
 * The match impact stays deliberately small; the main effect is squad fit,
 * chemistry and staff-driven recommendations.
 */
class TacticalStyleDataService {

    const CONFIG_ENABLED = 'tactical_style';

    const STYLE_BALANCED = 'balanced';
    const STYLE_PRESSING = 'pressing';
    const STYLE_POSSESSION = 'possession';
    const STYLE_DEFENSIVE = 'defensive';
    const STYLE_PHYSICAL = 'physical';
    const STYLE_COUNTERATTACK = 'counterattack';

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
            self::STYLE_BALANCED,
            self::STYLE_PRESSING,
            self::STYLE_POSSESSION,
            self::STYLE_DEFENSIVE,
            self::STYLE_PHYSICAL,
            self::STYLE_COUNTERATTACK
        );
    }

    public static function normalizeStyle($style) {
        $style = strtolower(trim((string) $style));

        // Backwards compatibility for earlier tactical-style experiments.
        $aliases = array(
            'defensive_block' => self::STYLE_DEFENSIVE,
            'wing_play' => self::STYLE_POSSESSION,
            'set_pieces' => self::STYLE_BALANCED,
            'youth_focused' => self::STYLE_BALANCED,
            'youth-focused' => self::STYLE_BALANCED,
            'counter' => self::STYLE_COUNTERATTACK,
            'counter_attack' => self::STYLE_COUNTERATTACK
        );
        if (isset($aliases[$style])) {
            $style = $aliases[$style];
        }

        return in_array($style, self::getStyles(), TRUE) ? $style : '';
    }

    /**
     * Backwards-compatible data for the formation page.
     */
    public static function getPageData(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $teamId, $userId) {
        $data = self::getTeamIdentityData($websoccer, $db, $i18n, $teamId, $userId);
        if (!$data['enabled'] || !$data['can_change']) {
            return array('enabled' => $data['enabled'], 'human_team' => FALSE, 'styles' => array(), 'current' => array());
        }
        $data['human_team'] = TRUE;
        return $data;
    }

    /**
     * Data shown on ?page=team&id=...
     */
    public static function getTeamIdentityData(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $teamId, $userId = 0) {
        $empty = array(
            'enabled' => FALSE,
            'schema_ready' => FALSE,
            'team_id' => (int) $teamId,
            'can_change' => FALSE,
            'human_team' => FALSE,
            'styles' => array(),
            'current' => array(),
            'recommendation' => array(),
            'staff_advice' => array(),
            'change_effect' => 0
        );

        if (!self::isEnabled($websoccer) || (int) $teamId < 1) {
            return $empty;
        }

        self::ensureSchema($websoccer, $db);
        $team = self::getTeam($websoccer, $db, $teamId);
        if (!$team || (isset($team['nationalteam']) && $team['nationalteam'] == '1')) {
            $empty['enabled'] = TRUE;
            $empty['schema_ready'] = self::schemaReady($websoccer, $db);
            return $empty;
        }

        $rows = self::getSquadRows($websoccer, $db, $teamId);
        $scores = self::calculateAllStyleFitsFromRows($websoccer, $db, $teamId, $rows);
        $selected = self::normalizeStyle(isset($team['tactical_style']) ? $team['tactical_style'] : '');
        if (!strlen($selected)) {
            $selected = self::recommendStyleFromScores($scores);
            self::storeStyleSnapshot($websoccer, $db, $teamId, $selected, $scores[$selected]['fit'], $scores[$selected]['effect'], TRUE, TRUE, 0);
            $team['tactical_style'] = $selected;
            $team['tactical_style_change_effect'] = 0;
        }

        if (!isset($scores[$selected])) {
            $selected = self::STYLE_BALANCED;
        }

        $bestStyle = self::recommendStyleFromScores($scores);
        $currentFit = isset($scores[$selected]) ? (int) $scores[$selected]['fit'] : 50;
        $currentEffect = isset($scores[$selected]) ? (int) $scores[$selected]['effect'] : 0;
        $transition = isset($team['tactical_style_change_effect']) ? (int) $team['tactical_style_change_effect'] : 0;
        $humanTeam = ($team && (int) $team['user_id'] > 0);
        $canChange = ($humanTeam && (int) $team['user_id'] === (int) $userId);

        $styles = array();
        foreach (self::getStyles() as $style) {
            $styleFit = isset($scores[$style]) ? (int) $scores[$style]['fit'] : 50;
            $styleEffect = isset($scores[$style]) ? (int) $scores[$style]['effect'] : 0;
            $styles[] = array(
                'key' => $style,
                'label' => self::message($i18n, 'tacticalstyle_' . $style),
                'description' => self::message($i18n, 'tacticalstyle_' . $style . '_help'),
                'fit' => $styleFit,
                'effect' => $styleEffect,
                'effect_signed' => self::formatSignedNumber($styleEffect),
                'change_effect_preview' => self::calculateStyleChangeChemistryEffect($selected, $currentFit, $styleFit, $bestStyle, $style),
                'selected' => ($style === $selected),
                'recommended' => ($style === $bestStyle),
                'fit_class' => self::getFitClass($styleFit)
            );
        }

        return array(
            'enabled' => TRUE,
            'schema_ready' => self::schemaReady($websoccer, $db),
            'team_id' => (int) $teamId,
            'can_change' => $canChange,
            'human_team' => $canChange,
            'styles' => $styles,
            'current' => array(
                'key' => $selected,
                'label' => self::message($i18n, 'tacticalstyle_' . $selected),
                'description' => self::message($i18n, 'tacticalstyle_' . $selected . '_help'),
                'fit' => $currentFit,
                'effect' => $currentEffect,
                'effect_signed' => self::formatSignedNumber($currentEffect),
                'hint_key' => self::getFitHintKey($currentFit),
                'fit_class' => self::getFitClass($currentFit),
                'chemistry_factor' => self::chemistryScoreFromFitAndTransition($currentFit, $transition),
                'change_effect' => $transition,
                'change_effect_signed' => self::formatSignedNumber($transition)
            ),
            'recommendation' => array(
                'key' => $bestStyle,
                'label' => self::message($i18n, 'tacticalstyle_' . $bestStyle),
                'fit' => isset($scores[$bestStyle]) ? (int) $scores[$bestStyle]['fit'] : 50,
                'effect' => isset($scores[$bestStyle]) ? (int) $scores[$bestStyle]['effect'] : 0,
                'diff' => (isset($scores[$bestStyle]) ? (int) $scores[$bestStyle]['fit'] : 50) - $currentFit,
                'is_current' => ($bestStyle === $selected)
            ),
            'staff_advice' => self::getStaffAdvice($websoccer, $db, $i18n, $teamId, $selected, $currentFit, $bestStyle, $scores),
            'change_effect' => $transition
        );
    }

    public static function saveHumanStyle(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $teamId, $userId, $style) {
        if (!self::isEnabled($websoccer) || (int) $teamId < 1) {
            return array('changed' => FALSE, 'message_key' => 'tacticaldna_not_enabled');
        }

        self::ensureSchema($websoccer, $db);
        $style = self::normalizeStyle($style);
        if (!strlen($style)) {
            return array('changed' => FALSE, 'message_key' => 'tacticaldna_invalid_style');
        }

        $team = self::getTeam($websoccer, $db, $teamId);
        if (!$team || (int) $team['user_id'] < 1 || (int) $team['user_id'] !== (int) $userId) {
            return array('changed' => FALSE, 'message_key' => 'tacticaldna_no_permission');
        }

        return self::saveStyleForTeam($websoccer, $db, $i18n, $teamId, $style, TRUE, 'human');
    }

    public static function saveAdminStyle(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $teamId, $style) {
        if (!self::isEnabled($websoccer) || (int) $teamId < 1) {
            return array('changed' => FALSE, 'message_key' => 'tacticaldna_not_enabled');
        }
        self::ensureSchema($websoccer, $db);
        $style = self::normalizeStyle($style);
        if (!strlen($style)) {
            return array('changed' => FALSE, 'message_key' => 'tacticaldna_invalid_style');
        }
        return self::saveStyleForTeam($websoccer, $db, $i18n, $teamId, $style, FALSE, 'admin');
    }

    public static function saveRecommendedStyle(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $teamId, $source = 'admin') {
        $rows = self::getSquadRows($websoccer, $db, $teamId);
        $scores = self::calculateAllStyleFitsFromRows($websoccer, $db, $teamId, $rows);
        $style = self::recommendStyleFromScores($scores);
        return self::saveStyleForTeam($websoccer, $db, $i18n, $teamId, $style, FALSE, $source);
    }

    private static function saveStyleForTeam(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $teamId, $style, $createNews, $source) {
        $team = self::getTeam($websoccer, $db, $teamId);
        if (!$team || (isset($team['nationalteam']) && $team['nationalteam'] == '1')) {
            return array('changed' => FALSE, 'message_key' => 'tacticaldna_no_team');
        }

        $oldStyle = self::normalizeStyle(isset($team['tactical_style']) ? $team['tactical_style'] : '');
        $rows = self::getSquadRows($websoccer, $db, $teamId);
        $scores = self::calculateAllStyleFitsFromRows($websoccer, $db, $teamId, $rows);
        $bestStyle = self::recommendStyleFromScores($scores);
        $oldFit = (strlen($oldStyle) && isset($scores[$oldStyle])) ? (int) $scores[$oldStyle]['fit'] : 50;
        $fit = isset($scores[$style]) ? (int) $scores[$style]['fit'] : 50;
        $effect = isset($scores[$style]) ? (int) $scores[$style]['effect'] : 0;

        if ($oldStyle === $style) {
            self::storeStyleSnapshot($websoccer, $db, $teamId, $style, $fit, $effect, TRUE, FALSE, null);
            self::refreshChemistryIfAvailable($websoccer, $db, $teamId, 'tactical_style');
            return array(
                'changed' => FALSE,
                'style' => $style,
                'fit' => $fit,
                'effect' => $effect,
                'chemistry_change' => 0,
                'message_key' => 'tacticaldna_saved_no_change'
            );
        }

        $chemistryChange = self::calculateStyleChangeChemistryEffect($oldStyle, $oldFit, $fit, $bestStyle, $style);
        self::storeStyleSnapshot($websoccer, $db, $teamId, $style, $fit, $effect, TRUE, TRUE, $chemistryChange);
        self::logStyleChange($websoccer, $db, $teamId, $oldStyle, $style, $oldFit, $fit, $chemistryChange, $source);
        self::refreshChemistryIfAvailable($websoccer, $db, $teamId, 'tactical_style');

        if ($createNews && strlen($oldStyle)) {
            self::createStyleChangeNews($websoccer, $db, $i18n, $team['name'], $teamId, $oldStyle, $style, $fit, $chemistryChange);
        }

        return array(
            'changed' => TRUE,
            'old_style' => $oldStyle,
            'style' => $style,
            'fit' => $fit,
            'effect' => $effect,
            'chemistry_change' => $chemistryChange,
            'message_key' => ($chemistryChange >= 0) ? 'tacticaldna_saved_positive' : 'tacticaldna_saved_negative'
        );
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
        if (!$teamInfo) {
            return;
        }

        $scores = self::calculateAllStyleFitsFromSimulationTeam($websoccer, $db, $team);
        $style = self::normalizeStyle(isset($teamInfo['tactical_style']) ? $teamInfo['tactical_style'] : '');
        $humanTeam = ((int) $teamInfo['user_id'] > 0);

        if (!$team->isNationalTeam && (!$humanTeam || !strlen($style))) {
            $style = self::getCpuStyleForMatch($websoccer, $db, $teamInfo, $scores);
        }
        if (!strlen($style)) {
            $style = self::STYLE_BALANCED;
        }

        $fit = isset($scores[$style]) ? (int) $scores[$style]['fit'] : 50;
        $effect = isset($scores[$style]) ? (int) $scores[$style]['effect'] : 0;

        $team->tacticalStyle = $style;
        $team->tacticalStyleFit = $fit;
        $team->tacticalStyleEffect = $effect;

        if (!$team->isNationalTeam) {
            $styleChanged = (self::normalizeStyle(isset($teamInfo['tactical_style']) ? $teamInfo['tactical_style'] : '') !== $style);
            self::storeStyleSnapshot($websoccer, $db, $team->id, $style, $fit, $effect, TRUE, $styleChanged, $styleChanged ? 0 : null);
        }
    }

    private static function getCpuStyleForMatch(WebSoccer $websoccer, DbConnection $db, $teamInfo, $scores) {
        $currentStyle = self::normalizeStyle(isset($teamInfo['tactical_style']) ? $teamInfo['tactical_style'] : '');
        $lastChange = isset($teamInfo['tactical_style_last_change']) ? (int) $teamInfo['tactical_style_last_change'] : 0;
        $now = (int) $websoccer->getNowAsTimestamp();
        $reevaluateAfter = 30 * 24 * 60 * 60;

        if (strlen($currentStyle) && $lastChange > 0 && $lastChange > ($now - $reevaluateAfter)) {
            return $currentStyle;
        }

        $bestStyle = self::recommendStyleFromScores($scores);
        if (!strlen($currentStyle)) {
            return $bestStyle;
        }

        $currentFit = isset($scores[$currentStyle]) ? (int) $scores[$currentStyle]['fit'] : 50;
        $bestFit = isset($scores[$bestStyle]) ? (int) $scores[$bestStyle]['fit'] : 50;
        return ($bestFit >= $currentFit + 8) ? $bestStyle : $currentStyle;
    }

    /**
     * Called before chemistry is refreshed after a match.
     */
    public static function processCompletedMatch(MatchCompletedEvent $event) {
        if (!self::isEnabled($event->websoccer) || !$event->match) {
            return;
        }
        self::progressStyleChemistry($event->websoccer, $event->db, (int) $event->match->homeTeam->id);
        self::progressStyleChemistry($event->websoccer, $event->db, (int) $event->match->guestTeam->id);
    }

    public static function progressStyleChemistry(WebSoccer $websoccer, DbConnection $db, $teamId) {
        self::ensureSchema($websoccer, $db);
        $team = self::getTeam($websoccer, $db, $teamId);
        if (!$team || (isset($team['nationalteam']) && $team['nationalteam'] == '1')) {
            return;
        }
        $style = self::normalizeStyle(isset($team['tactical_style']) ? $team['tactical_style'] : '');
        if (!strlen($style)) {
            return;
        }
        $rows = self::getSquadRows($websoccer, $db, $teamId);
        $scores = self::calculateAllStyleFitsFromRows($websoccer, $db, $teamId, $rows);
        $fit = isset($scores[$style]) ? (int) $scores[$style]['fit'] : 50;
        $effect = isset($scores[$style]) ? (int) $scores[$style]['effect'] : 0;
        $transition = isset($team['tactical_style_change_effect']) ? (int) $team['tactical_style_change_effect'] : 0;
        $newTransition = $transition;

        if ($fit < 45) {
            // A style that clearly does not fit slowly hurts chemistry until changed.
            $newTransition = max(-8, $transition - 1);
        } elseif ($transition < 0) {
            // Negative change effects recover automatically through match practice.
            $newTransition = min(0, $transition + 1);
        } elseif ($transition > 0) {
            // Positive change impulse is temporary; the long-term benefit remains in the fit factor.
            $newTransition = max(0, $transition - 1);
        }

        self::storeStyleSnapshot($websoccer, $db, $teamId, $style, $fit, $effect, TRUE, FALSE, $newTransition);
    }

    public static function getChemistryFactor(WebSoccer $websoccer, DbConnection $db, $i18n, $teamId) {
        if (!self::isEnabled($websoccer) || (int) $teamId < 1) {
            return array('score' => 50, 'detail' => '');
        }
        self::ensureSchema($websoccer, $db);
        $team = self::getTeam($websoccer, $db, $teamId);
        if (!$team) {
            return array('score' => 50, 'detail' => '');
        }
        $style = self::normalizeStyle(isset($team['tactical_style']) ? $team['tactical_style'] : '');
        if (!strlen($style)) {
            $style = self::STYLE_BALANCED;
        }
        $rows = self::getSquadRows($websoccer, $db, $teamId);
        $scores = self::calculateAllStyleFitsFromRows($websoccer, $db, $teamId, $rows);
        $fit = isset($scores[$style]) ? (int) $scores[$style]['fit'] : 50;
        $transition = isset($team['tactical_style_change_effect']) ? (int) $team['tactical_style_change_effect'] : 0;
        $score = self::chemistryScoreFromFitAndTransition($fit, $transition);
        $detail = self::message($i18n, 'tacticalstyle_' . $style) . ': ' . $fit . '%';
        if ($transition != 0) {
            $detail .= ' (' . self::formatSignedNumber($transition) . ')';
        }
        return array('score' => $score, 'detail' => $detail);
    }

    public static function getPassSuccessEffect($team, $opponentTeam = null) {
        $effect = self::getTeamRuntimeEffect($team);
        $style = self::getTeamRuntimeStyle($team);
        $modifier = 0;

        if ($style === self::STYLE_POSSESSION) {
            $modifier += $effect;
        } elseif ($style === self::STYLE_BALANCED) {
            $modifier += (int) round($effect / 2);
        } elseif ($style === self::STYLE_COUNTERATTACK && $effect > 0) {
            $modifier += (int) round($effect / 3);
        }

        if ($opponentTeam) {
            $opponentStyle = self::getTeamRuntimeStyle($opponentTeam);
            $opponentEffect = self::getTeamRuntimeEffect($opponentTeam);
            if ($opponentStyle === self::STYLE_PRESSING || $opponentStyle === self::STYLE_PHYSICAL || $opponentStyle === self::STYLE_DEFENSIVE) {
                $modifier -= (int) round($opponentEffect / 2);
            }
        }

        return max(-5, min(5, $modifier));
    }

    public static function getShootProbabilityEffect($team, $opponentTeam = null, $player = null) {
        $effect = self::getTeamRuntimeEffect($team);
        $style = self::getTeamRuntimeStyle($team);
        $modifier = 0;

        if ($style === self::STYLE_COUNTERATTACK) {
            $modifier += $effect;
        } elseif ($style === self::STYLE_PRESSING) {
            $modifier += (int) round($effect / 2);
        } elseif ($style === self::STYLE_POSSESSION && $effect > 0) {
            $modifier += (int) round($effect / 3);
        }

        if ($opponentTeam && self::getTeamRuntimeStyle($opponentTeam) === self::STYLE_DEFENSIVE) {
            $modifier -= (int) round(self::getTeamRuntimeEffect($opponentTeam) / 2);
        }

        return max(-5, min(5, $modifier));
    }

    public static function getTackleProbabilityEffect($pressingTeam) {
        $style = self::getTeamRuntimeStyle($pressingTeam);
        $effect = self::getTeamRuntimeEffect($pressingTeam);
        if ($style === self::STYLE_PRESSING || $style === self::STYLE_PHYSICAL || $style === self::STYLE_DEFENSIVE) {
            return max(-4, min(4, $effect));
        }
        return 0;
    }

    public static function getGoalChanceEffect($team, $opponentTeam = null, $player = null, $situation = 'shot') {
        $style = self::getTeamRuntimeStyle($team);
        $effect = self::getTeamRuntimeEffect($team);
        $modifier = 0;

        if ($situation === 'shot' && $style === self::STYLE_COUNTERATTACK) {
            $modifier += (int) round($effect / 2);
        } elseif ($situation === 'shot' && $style === self::STYLE_POSSESSION && $effect > 0) {
            $modifier += (int) round($effect / 3);
        } elseif ($situation === 'freekick' && $style === self::STYLE_PHYSICAL && $effect > 0) {
            $modifier += (int) round($effect / 3);
        }

        if ($opponentTeam && self::getTeamRuntimeStyle($opponentTeam) === self::STYLE_DEFENSIVE) {
            $modifier -= (int) round(self::getTeamRuntimeEffect($opponentTeam) / 2);
        }

        return max(-5, min(5, $modifier));
    }

    public static function getAdminClubOptions(WebSoccer $websoccer, DbConnection $db) {
        $prefix = $websoccer->getConfig('db_prefix');
        $sql = "SELECT C.id, C.name, L.name AS league_name
                FROM " . $prefix . "_verein AS C
                LEFT JOIN " . $prefix . "_liga AS L ON L.id = C.liga_id
                WHERE C.status = '1' AND C.nationalteam != '1'
                ORDER BY C.name ASC";
        $result = $db->executeQuery($sql);
        $clubs = array();
        while ($row = $result->fetch_array()) {
            $clubs[] = $row;
        }
        $result->free();
        return $clubs;
    }

    public static function getAdminOverview(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $limit = 200) {
        self::ensureSchema($websoccer, $db);
        $prefix = $websoccer->getConfig('db_prefix');
        $sql = "SELECT C.id, C.name, C.user_id, C.team_chemistry, C.tactical_style, C.tactical_style_fit,
                       C.tactical_style_effect, C.tactical_style_change_effect, L.name AS league_name, U.nick AS manager_name
                FROM " . $prefix . "_verein AS C
                LEFT JOIN " . $prefix . "_liga AS L ON L.id = C.liga_id
                LEFT JOIN " . $prefix . "_user AS U ON U.id = C.user_id
                WHERE C.status = '1' AND C.nationalteam != '1'
                ORDER BY C.tactical_style_fit ASC, C.name ASC
                LIMIT " . (int) $limit;
        $result = $db->executeQuery($sql);
        $rows = array();
        while ($row = $result->fetch_array()) {
            $style = self::normalizeStyle($row['tactical_style']);
            if (!strlen($style)) {
                $style = self::STYLE_BALANCED;
            }
            $scores = self::calculateAllStyleFitsFromRows($websoccer, $db, (int) $row['id'], self::getSquadRows($websoccer, $db, (int) $row['id']));
            if (isset($scores[$style])) {
                $row['tactical_style_fit'] = (int) $scores[$style]['fit'];
                $row['tactical_style_effect'] = (int) $scores[$style]['effect'];
            }
            $row['style_key'] = $style;
            $row['style_label'] = self::message($i18n, 'tacticalstyle_' . $style);
            $row['effect_signed'] = self::formatSignedNumber((int) $row['tactical_style_effect']);
            $row['change_effect_signed'] = self::formatSignedNumber((int) $row['tactical_style_change_effect']);
            $row['fit_class'] = self::getFitClass((int) $row['tactical_style_fit']);
            $rows[] = $row;
        }
        $result->free();
        return $rows;
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
            case self::STYLE_BALANCED:
                $fit = self::weighted(array(array($avg['strength'], 20), array($avg['technique'], 20), array($avg['stamina'], 15), array($avg['passing'], 15), array($avg['tackling'], 15), array($avg['freshness'], 15)));
                break;
            case self::STYLE_PRESSING:
                // Aggression is not available as own attribute; tackling/influence are used as practical proxies.
                $fit = self::weighted(array(array($avg['stamina'], 35), array($avg['pace'], 25), array($avg['tackling'], 25), array($avg['influence'], 15)));
                break;
            case self::STYLE_POSSESSION:
                $fit = self::weighted(array(array($avg['passing'], 40), array($avg['creativity'], 30), array($avg['technique'], 30)));
                break;
            case self::STYLE_DEFENSIVE:
                $fit = self::weighted(array(array($avg['tackling'], 45), array($avg['heading'], 30), array($avg['stamina'], 15), array($avg['influence'], 10)));
                break;
            case self::STYLE_PHYSICAL:
                $fit = self::weighted(array(array($avg['strength'], 35), array($avg['heading'], 35), array($avg['stamina'], 20), array($avg['tackling'], 10)));
                break;
            case self::STYLE_COUNTERATTACK:
                $fit = self::weighted(array(array($avg['pace'], 40), array($avg['shooting'], 35), array($avg['passing'], 15), array($avg['freshness'], 10)));
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

                if ($style === self::STYLE_PRESSING || $style === self::STYLE_COUNTERATTACK || $style === self::STYLE_PHYSICAL) {
                    $fitness = ClubStaffDataService::getRoleBonus($websoccer, $db, $teamId, ClubStaffDataService::ROLE_FITNESS_COACH);
                    $bonus += min(2, (int) round($fitness / 5));
                }

                if ($style === self::STYLE_DEFENSIVE) {
                    $goalkeeping = ClubStaffDataService::getRoleBonus($websoccer, $db, $teamId, ClubStaffDataService::ROLE_GOALKEEPING_COACH);
                    $bonus += min(1, (int) round($goalkeeping / 6));
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
                self::STYLE_BALANCED => array('balanced', 'matchprep', 'teambuilding'),
                self::STYLE_PRESSING => array('athletics', 'defense', 'matchprep'),
                self::STYLE_POSSESSION => array('passing', 'technique', 'matchprep'),
                self::STYLE_DEFENSIVE => array('defense', 'matchprep'),
                self::STYLE_PHYSICAL => array('athletics', 'defense'),
                self::STYLE_COUNTERATTACK => array('athletics', 'passing', 'shooting', 'matchprep')
            );
            return (isset($mapping[$style]) && in_array($training, $mapping[$style], TRUE)) ? 2 : 0;
        } catch (Exception $e) {
            return 0;
        }
    }

    private static function scoreToMatchEffect($fit) {
        $fit = (int) $fit;
        if ($fit >= 82) return 3;
        if ($fit >= 72) return 2;
        if ($fit >= 62) return 1;
        if ($fit >= 48) return 0;
        if ($fit >= 38) return -1;
        return -2;
    }

    private static function recommendStyleFromScores($scores) {
        $bestStyle = self::STYLE_BALANCED;
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
                $columns .= ', tactical_style, tactical_style_fit, tactical_style_effect, tactical_style_updated, tactical_style_last_change, tactical_style_change_effect, tactical_style_staff_advice, tactical_style_staff_advice_updated';
                if (self::columnExists($db, $websoccer->getConfig('db_prefix') . '_verein', 'team_chemistry')) {
                    $columns .= ', team_chemistry';
                }
            }
            $result = $db->querySelect($columns, $websoccer->getConfig('db_prefix') . '_verein', 'id = %d', (int) $teamId, 1);
            $team = $result->fetch_array();
            $result->free();
            return $team ? $team : array();
        } catch (Exception $e) {
            return array();
        }
    }

    private static function storeStyleSnapshot(WebSoccer $websoccer, DbConnection $db, $teamId, $style, $fit, $effect, $includeStyle, $styleChanged = FALSE, $transition = null) {
        if (!self::schemaReady($websoccer, $db)) {
            return;
        }
        $now = (int) $websoccer->getNowAsTimestamp();
        $columns = array(
            'tactical_style_fit' => (int) $fit,
            'tactical_style_effect' => (int) $effect,
            'tactical_style_updated' => $now
        );
        if ($includeStyle || strlen($style)) {
            $columns['tactical_style'] = $style;
        }
        if ($styleChanged) {
            $columns['tactical_style_last_change'] = $now;
        }
        if ($transition !== null) {
            $columns['tactical_style_change_effect'] = (int) $transition;
        }
        try {
            $db->queryUpdate($columns, $websoccer->getConfig('db_prefix') . '_verein', 'id = %d', (int) $teamId);
        } catch (Exception $e) {
            // Runtime effect should continue even if the optional snapshot cannot be written.
        }
    }

    private static function logStyleChange(WebSoccer $websoccer, DbConnection $db, $teamId, $oldStyle, $newStyle, $oldFit, $newFit, $chemistryChange, $source) {
        try {
            $context = array(
                'source' => $source,
                'old_style' => $oldStyle,
                'new_style' => $newStyle,
                'old_fit' => (int) $oldFit,
                'new_fit' => (int) $newFit,
                'chemistry_change' => (int) $chemistryChange
            );
            $oldScore = 50;
            $newScore = self::chemistryScoreFromFitAndTransition($newFit, $chemistryChange);
            $team = self::getTeam($websoccer, $db, $teamId);
            if ($team && isset($team['team_chemistry'])) {
                $oldScore = (int) $team['team_chemistry'];
            }
            if (self::tableExists($db, $websoccer->getConfig('db_prefix') . '_team_chemistry_log')) {
                $db->queryInsert(array(
                    'team_id' => (int) $teamId,
                    'event_date' => $websoccer->getNowAsTimestamp(),
                    'source' => 'tactical_style',
                    'old_score' => max(1, min(100, $oldScore)),
                    'new_score' => max(1, min(100, $newScore)),
                    'match_effect' => self::scoreToMatchEffect($newFit),
                    'match_id' => '',
                    'breakdown_data' => json_encode($context)
                ), $websoccer->getConfig('db_prefix') . '_team_chemistry_log');
            }
        } catch (Exception $e) {
            // Optional logging only.
        }
    }

    private static function createStyleChangeNews(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $teamName, $teamId, $oldStyle, $newStyle, $fit, $chemistryChange) {
        $title = self::message($i18n, 'tacticalstyle_news_title');
        $message = self::message($i18n, 'tacticalstyle_news_message');
        $message = str_replace('{team}', $teamName, $message);
        $message = str_replace('{oldstyle}', self::message($i18n, 'tacticalstyle_' . $oldStyle), $message);
        $message = str_replace('{newstyle}', self::message($i18n, 'tacticalstyle_' . $newStyle), $message);
        $message = str_replace('{fit}', (int) $fit, $message);
        $message = str_replace('{chemistry}', self::formatSignedNumber($chemistryChange), $message);

        try {
            $db->queryInsert(array(
                'datum' => $websoccer->getNowAsTimestamp(),
                'autor_id' => 1,
                'titel' => $title,
                'nachricht' => $message,
                'linktext1' => self::message($i18n, 'tacticalstyle_news_link'),
                'linkurl1' => $websoccer->getInternalUrl('team', 'id=' . (int) $teamId),
                'c_br' => '1',
                'c_links' => '1',
                'c_smilies' => '0',
                'status' => '1'
            ), $websoccer->getConfig('db_prefix') . '_news');
        } catch (Exception $e) {
            // Optional news integration.
        }
    }

    private static function refreshChemistryIfAvailable(WebSoccer $websoccer, DbConnection $db, $teamId, $source) {
        if (class_exists('TeamChemistryDataService')) {
            try {
                TeamChemistryDataService::refreshTeamChemistry($websoccer, $db, $teamId, $source);
            } catch (Exception $e) {
                // Optional integration only.
            }
        }
    }

    private static function getStaffAdvice(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $teamId, $currentStyle, $currentFit, $bestStyle, $scores) {
        $hasStaff = FALSE;
        $assistantBonus = 0;
        if (class_exists('ClubStaffDataService')) {
            try {
                $assistantBonus = (int) ClubStaffDataService::getRoleBonus($websoccer, $db, $teamId, ClubStaffDataService::ROLE_ASSISTANT_MANAGER);
                $hasStaff = ($assistantBonus > 0);
            } catch (Exception $e) {
                $hasStaff = FALSE;
            }
        }

        $bestFit = isset($scores[$bestStyle]) ? (int) $scores[$bestStyle]['fit'] : 50;
        $diff = $bestFit - (int) $currentFit;
        $showAdvice = ($hasStaff && $bestStyle !== $currentStyle && $bestFit >= 60 && $diff >= 8);

        return array(
            'available' => $hasStaff,
            'show' => $showAdvice,
            'assistant_bonus' => $assistantBonus,
            'style' => $bestStyle,
            'label' => self::message($i18n, 'tacticalstyle_' . $bestStyle),
            'fit' => $bestFit,
            'diff' => $diff,
            'message' => $showAdvice ? str_replace(array('{style}', '{fit}', '{diff}'), array(self::message($i18n, 'tacticalstyle_' . $bestStyle), $bestFit, $diff), self::message($i18n, 'tacticaldna_staff_recommendation')) : ''
        );
    }

    private static function calculateStyleChangeChemistryEffect($oldStyle, $oldFit, $newFit, $bestStyle, $newStyle) {
        $oldFit = (int) $oldFit;
        $newFit = (int) $newFit;
        $diff = $newFit - $oldFit;

        if (!strlen($oldStyle)) {
            if ($newFit >= 75) return 3;
            if ($newFit >= 65) return 1;
            return 0;
        }
        if ($newStyle === $bestStyle && $newFit >= 70 && $diff >= 8) {
            return min(5, 2 + (int) floor($diff / 10));
        }
        if ($newFit >= 75 && $diff >= 0) {
            return 2;
        }
        if ($newFit < 45) {
            return -8;
        }
        if ($diff <= -12) {
            return -6;
        }
        if ($diff < 0) {
            return -4;
        }
        return -2;
    }

    private static function chemistryScoreFromFitAndTransition($fit, $transition) {
        $score = 50 + (((int) $fit - 50) * 0.65) + ((int) $transition * 2);
        return max(1, min(100, (int) round($score)));
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
        return max(-2, min(3, (int) $team->tacticalStyleEffect));
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

    private static function formatSignedNumber($value) {
        $value = (int) $value;
        return ($value > 0 ? '+' : '') . $value;
    }

    private static function message($i18n, $key) {
        if ($i18n && method_exists($i18n, 'hasMessage') && $i18n->hasMessage($key)) {
            return $i18n->getMessage($key);
        }
        $fallback = array(
            'tacticalstyle_balanced' => 'Ausgewogen',
            'tacticalstyle_pressing' => 'Pressing',
            'tacticalstyle_possession' => 'Ballbesitz',
            'tacticalstyle_defensive' => 'Defensiv',
            'tacticalstyle_physical' => 'Körperbetont',
            'tacticalstyle_counterattack' => 'Konter'
        );
        return isset($fallback[$key]) ? $fallback[$key] : $key;
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
        self::ensureColumn($db, $table, 'tactical_style_last_change', "ALTER TABLE " . $table . " ADD COLUMN tactical_style_last_change INT(11) NOT NULL DEFAULT 0");
        self::ensureColumn($db, $table, 'tactical_style_change_effect', "ALTER TABLE " . $table . " ADD COLUMN tactical_style_change_effect TINYINT(4) NOT NULL DEFAULT 0");
        self::ensureColumn($db, $table, 'tactical_style_staff_advice', "ALTER TABLE " . $table . " ADD COLUMN tactical_style_staff_advice VARCHAR(32) NOT NULL DEFAULT ''");
        self::ensureColumn($db, $table, 'tactical_style_staff_advice_updated', "ALTER TABLE " . $table . " ADD COLUMN tactical_style_staff_advice_updated INT(11) NOT NULL DEFAULT 0");
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

    private static function tableExists(DbConnection $db, $table) {
        try {
            $result = $db->executeQuery("SHOW TABLES LIKE '" . $db->connection->real_escape_string($table) . "'");
            $exists = ($result && $result->num_rows > 0);
            if ($result) {
                $result->free();
            }
            return $exists;
        } catch (Exception $e) {
            return FALSE;
        }
    }
}
?>
