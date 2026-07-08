<?php
/******************************************************

  Manager character helper service for CM23.

******************************************************/

/**
 * Centralizes manager character rules so every style has clear strengths and weaknesses.
 * Effects are deliberately small and situational; no character is perfect everywhere.
 */
class ManagerCharacterDataService {

    const CHARACTER_BALANCED = 'balanced';
    const CHARACTER_MOTIVATOR = 'motivator';
    const CHARACTER_TACTICIAN = 'tactician';
    const CHARACTER_DISCIPLINARIAN = 'disciplinarian';
    const CHARACTER_DEVELOPER = 'developer';
    const CHARACTER_MEDIA_FRIENDLY = 'media_friendly';
    const CHARACTER_FINANCIAL_STRATEGIST = 'financial_strategist';
    const CHARACTER_CLUB_ICON = 'club_icon';
    const CHARACTER_RISK_TAKER = 'risk_taker';

    private static $_userCharacterCache = array();
    private static $_teamCharacterCache = array();
    private static $_schemaReady = null;

    public static function getCharacters() {
        return array(
            self::CHARACTER_BALANCED,
            self::CHARACTER_MOTIVATOR,
            self::CHARACTER_TACTICIAN,
            self::CHARACTER_DISCIPLINARIAN,
            self::CHARACTER_DEVELOPER,
            self::CHARACTER_MEDIA_FRIENDLY,
            self::CHARACTER_FINANCIAL_STRATEGIST,
            self::CHARACTER_CLUB_ICON,
            self::CHARACTER_RISK_TAKER
        );
    }

    public static function isValidCharacter($character) {
        return in_array((string) $character, self::getCharacters(), TRUE);
    }

    public static function normalizeCharacter($character, $fallback = '') {
        $character = trim((string) $character);
        if (self::isValidCharacter($character)) {
            return $character;
        }
        return $fallback;
    }

    public static function getCharacterOptions(I18n $i18n) {
        $options = array();
        foreach (self::getCharacters() as $character) {
            $options[] = array(
                'key' => $character,
                'label' => self::label($i18n, $character),
                'summary' => self::message($i18n, 'manager_character_' . $character . '_summary'),
                'positive' => self::message($i18n, 'manager_character_' . $character . '_positive'),
                'negative' => self::message($i18n, 'manager_character_' . $character . '_negative')
            );
        }
        return $options;
    }

    public static function getUserCharacter(WebSoccer $websoccer, DbConnection $db, $userId) {
        $userId = (int) $userId;
        if ($userId < 1 || !self::schemaReady($websoccer, $db)) {
            return '';
        }
        if (isset(self::$_userCharacterCache[$userId])) {
            return self::$_userCharacterCache[$userId];
        }

        $result = $db->querySelect(
            'manager_character',
            $websoccer->getConfig('db_prefix') . '_user',
            'id = %d',
            $userId,
            1
        );
        $row = $result->fetch_array();
        $result->free();

        $character = ($row && isset($row['manager_character'])) ? self::normalizeCharacter($row['manager_character']) : '';
        self::$_userCharacterCache[$userId] = $character;
        return $character;
    }

    public static function getTeamManagerCharacter(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $teamId = (int) $teamId;
        if ($teamId < 1 || !self::schemaReady($websoccer, $db)) {
            return '';
        }
        if (isset(self::$_teamCharacterCache[$teamId])) {
            return self::$_teamCharacterCache[$teamId];
        }

        $result = $db->querySelect(
            'U.manager_character',
            $websoccer->getConfig('db_prefix') . '_verein AS T INNER JOIN ' . $websoccer->getConfig('db_prefix') . '_user AS U ON U.id = T.user_id',
            'T.id = %d AND T.user_id > 0',
            $teamId,
            1
        );
        $row = $result->fetch_array();
        $result->free();

        $character = ($row && isset($row['manager_character'])) ? self::normalizeCharacter($row['manager_character']) : '';
        self::$_teamCharacterCache[$teamId] = $character;
        return $character;
    }

    public static function getProfileData(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $userId, $teamId = 0) {
        $info = self::getUserCharacterInfo($websoccer, $db, $userId);
        $status = self::getChangeStatus($websoccer, $db, $userId, $teamId, isset($info['manager_character']) ? $info['manager_character'] : '');
        $character = isset($info['manager_character']) ? self::normalizeCharacter($info['manager_character']) : '';
        return array(
            'schema_ready' => self::schemaReady($websoccer, $db),
            'character' => $character,
            'character_label' => strlen($character) ? self::label($i18n, $character) : self::message($i18n, 'manager_character_not_set'),
            'set_date' => isset($info['manager_character_set_date']) ? (int) $info['manager_character_set_date'] : 0,
            'last_change' => isset($info['manager_character_last_change']) ? (int) $info['manager_character_last_change'] : 0,
            'changes' => isset($info['manager_character_changes']) ? (int) $info['manager_character_changes'] : 0,
            'options' => self::getCharacterOptions($i18n),
            'change_status' => $status
        );
    }

    public static function saveUserCharacter(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $userId, $teamId, $newCharacter) {
        if (!self::schemaReady($websoccer, $db)) {
            throw new Exception(self::message($i18n, 'manager_character_error_schema_missing'));
        }

        $newCharacter = self::normalizeCharacter($newCharacter);
        if (!strlen($newCharacter)) {
            return array('changed' => FALSE, 'first_choice' => FALSE, 'old_character' => '', 'new_character' => '');
        }

        $info = self::getUserCharacterInfo($websoccer, $db, $userId);
        $oldCharacter = isset($info['manager_character']) ? self::normalizeCharacter($info['manager_character']) : '';
        if ($oldCharacter === $newCharacter) {
            return array('changed' => FALSE, 'first_choice' => FALSE, 'old_character' => $oldCharacter, 'new_character' => $newCharacter);
        }

        $status = self::getChangeStatus($websoccer, $db, $userId, $teamId, $oldCharacter);
        if (!$status['allowed']) {
            throw new Exception(self::message($i18n, 'manager_character_error_change_not_allowed', array('matches' => (int) $status['matches_until_change'])));
        }

        $now = $websoccer->getNowAsTimestamp();
        $currentMatches = self::getCurrentOfficialMatches($websoccer, $db, $teamId, $userId);
        $firstChoice = !strlen($oldCharacter);
        $columns = array(
            'manager_character' => $newCharacter,
            'manager_character_last_change' => $now,
            'manager_character_last_change_matches' => (int) $currentMatches
        );
        if ($firstChoice) {
            $columns['manager_character_set_date'] = $now;
            $columns['manager_character_changes'] = 0;
        } else {
            $columns['manager_character_changes'] = (isset($info['manager_character_changes']) ? (int) $info['manager_character_changes'] : 0) + 1;
        }

        $db->queryUpdate($columns, $websoccer->getConfig('db_prefix') . '_user', 'id = %d', (int) $userId);
        self::$_userCharacterCache[(int) $userId] = $newCharacter;
        self::$_teamCharacterCache = array();

        if (!$firstChoice && (int) $teamId > 0) {
            self::applyCharacterChangePenalty($websoccer, $db, $teamId, $oldCharacter, $newCharacter);
        }

        return array('changed' => TRUE, 'first_choice' => $firstChoice, 'old_character' => $oldCharacter, 'new_character' => $newCharacter);
    }

    public static function getChangeStatus(WebSoccer $websoccer, DbConnection $db, $userId, $teamId = 0, $currentCharacter = null) {
        $info = self::getUserCharacterInfo($websoccer, $db, $userId);
        if ($currentCharacter === null) {
            $currentCharacter = isset($info['manager_character']) ? self::normalizeCharacter($info['manager_character']) : '';
        }

        if (!strlen($currentCharacter)) {
            return array('allowed' => TRUE, 'first_choice' => TRUE, 'reason' => 'first_choice', 'matches_until_change' => 0, 'matches_since_change' => 0, 'cooldown_matches' => self::getCooldownMatches($websoccer));
        }

        $cooldown = self::getCooldownMatches($websoccer);
        $lastMatches = isset($info['manager_character_last_change_matches']) ? (int) $info['manager_character_last_change_matches'] : 0;
        $currentMatches = self::getCurrentOfficialMatches($websoccer, $db, $teamId, $userId);
        $matchesSince = $currentMatches - $lastMatches;

        if ($currentMatches < $lastMatches) {
            return array('allowed' => TRUE, 'first_choice' => FALSE, 'reason' => 'new_season', 'matches_until_change' => 0, 'matches_since_change' => 0, 'cooldown_matches' => $cooldown);
        }
        if ($matchesSince >= $cooldown) {
            return array('allowed' => TRUE, 'first_choice' => FALSE, 'reason' => 'cooldown_reached', 'matches_until_change' => 0, 'matches_since_change' => $matchesSince, 'cooldown_matches' => $cooldown);
        }

        return array('allowed' => FALSE, 'first_choice' => FALSE, 'reason' => 'cooldown', 'matches_until_change' => max(1, $cooldown - $matchesSince), 'matches_since_change' => max(0, $matchesSince), 'cooldown_matches' => $cooldown);
    }

    public static function applyMatchEffects(WebSoccer $websoccer, DbConnection $db, SimulationMatch $match, $applyMorale = FALSE) {
        if (!$match) {
            return;
        }
        self::applyTeamMatchEffects($websoccer, $db, $match, $match->homeTeam, TRUE, $applyMorale);
        self::applyTeamMatchEffects($websoccer, $db, $match, $match->guestTeam, FALSE, $applyMorale);
    }

    public static function getPassSuccessEffect(SimulationTeam $team, SimulationMatch $match = null) {
        $character = self::normalizeCharacter(isset($team->managerCharacter) ? $team->managerCharacter : '');
        if (!strlen($character) || $character === self::CHARACTER_BALANCED) {
            return 0;
        }
        $effect = 0;
        if ($character === self::CHARACTER_TACTICIAN && (int) $team->tacticalStyleFit >= 70) {
            $effect += 2;
        } elseif ($character === self::CHARACTER_DISCIPLINARIAN) {
            $effect += 1;
        } elseif ($character === self::CHARACTER_RISK_TAKER) {
            $effect -= 1;
        } elseif ($character === self::CHARACTER_MOTIVATOR && $match && self::teamIsTrailing($team, $match)) {
            $effect += 1;
        }
        return max(-2, min(2, $effect));
    }

    public static function getShootProbabilityEffect(SimulationTeam $team, SimulationTeam $opponentTeam, SimulationMatch $match) {
        $character = self::normalizeCharacter(isset($team->managerCharacter) ? $team->managerCharacter : '');
        if (!strlen($character) || $character === self::CHARACTER_BALANCED) {
            return 0;
        }
        $effect = 0;
        if ($character === self::CHARACTER_RISK_TAKER) {
            $effect += ((int) $team->offensive >= 60) ? 2 : 1;
            if ($opponentTeam && (int) $opponentTeam->offensive >= 60) {
                $effect -= 1;
            }
        } elseif ($character === self::CHARACTER_MOTIVATOR && self::teamIsTrailing($team, $match)) {
            $effect += 1;
        } elseif ($character === self::CHARACTER_TACTICIAN && (int) $team->tacticalStyleFit >= 75) {
            $effect += 1;
        } elseif ($character === self::CHARACTER_DISCIPLINARIAN && (int) $team->offensive >= 70) {
            $effect -= 1;
        }
        return max(-2, min(2, $effect));
    }

    public static function getCardProbabilityMultiplier(SimulationTeam $team) {
        $character = self::normalizeCharacter(isset($team->managerCharacter) ? $team->managerCharacter : '');
        if ($character === self::CHARACTER_DISCIPLINARIAN) {
            return 0.90;
        }
        if ($character === self::CHARACTER_RISK_TAKER) {
            return 1.10;
        }
        if ($character === self::CHARACTER_MOTIVATOR && (int) $team->morale > 70) {
            return 0.95;
        }
        return 1.0;
    }

    public static function getTrainingInjuryMultiplier(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $character = self::getTeamManagerCharacter($websoccer, $db, $teamId);
        if ($character === self::CHARACTER_DISCIPLINARIAN) {
            return 0.95;
        }
        if ($character === self::CHARACTER_RISK_TAKER) {
            return 1.10;
        }
        return 1.0;
    }

    public static function applyTrainingEffects(WebSoccer $websoccer, DbConnection $db, $teamId, $player, $trainingType, &$effects) {
        $character = self::getTeamManagerCharacter($websoccer, $db, $teamId);
        if (!strlen($character) || $character === self::CHARACTER_BALANCED) {
            return;
        }

        if ($character === self::CHARACTER_DEVELOPER) {
            $age = isset($player['age']) ? (int) $player['age'] : 25;
            if ($age <= 23) {
                self::multiplyPositiveEffects($effects, 1.06);
            } else if ($age >= 30) {
                self::multiplyPositiveEffects($effects, 0.98);
            }
        } elseif ($character === self::CHARACTER_DISCIPLINARIAN) {
            $effects['stamina'] = (isset($effects['stamina']) ? (float) $effects['stamina'] : 0) + 0.03;
            $effects['tackling'] = (isset($effects['tackling']) ? (float) $effects['tackling'] : 0) + 0.02;
            $effects['satisfaction'] = (isset($effects['satisfaction']) ? (float) $effects['satisfaction'] : 0) - 0.08;
        } elseif ($character === self::CHARACTER_MOTIVATOR) {
            $effects['satisfaction'] = (isset($effects['satisfaction']) ? (float) $effects['satisfaction'] : 0) + 0.08;
            if ($trainingType === 'teambuilding' || $trainingType === 'matchprep') {
                $effects['influence'] = (isset($effects['influence']) ? (float) $effects['influence'] : 0) + 0.03;
            }
        } elseif ($character === self::CHARACTER_TACTICIAN) {
            if ($trainingType === 'matchprep' || $trainingType === 'passing') {
                self::multiplyPositiveEffects($effects, 1.03);
            }
        } elseif ($character === self::CHARACTER_RISK_TAKER) {
            self::multiplyPositiveEffects($effects, 1.03);
            $effects['freshness'] = (isset($effects['freshness']) ? (float) $effects['freshness'] : 0) - 0.05;
        } elseif ($character === self::CHARACTER_MEDIA_FRIENDLY) {
            $effects['satisfaction'] = (isset($effects['satisfaction']) ? (float) $effects['satisfaction'] : 0) + 0.03;
        }
    }

    public static function adjustYouthDevelopmentChance(WebSoccer $websoccer, DbConnection $db, $teamId, $chance) {
        $character = self::getTeamManagerCharacter($websoccer, $db, $teamId);
        $chance = (int) $chance;
        if ($character === self::CHARACTER_DEVELOPER) {
            $chance += 4;
        } elseif ($character === self::CHARACTER_DISCIPLINARIAN) {
            $chance += 1;
        } elseif ($character === self::CHARACTER_FINANCIAL_STRATEGIST) {
            $chance -= 1;
        } elseif ($character === self::CHARACTER_RISK_TAKER) {
            $chance += 2;
        }
        return $chance;
    }

    public static function adjustYouthStagnationReductionChance(WebSoccer $websoccer, DbConnection $db, $teamId, $chance) {
        $character = self::getTeamManagerCharacter($websoccer, $db, $teamId);
        $chance = (int) $chance;
        if ($character === self::CHARACTER_DEVELOPER || $character === self::CHARACTER_MOTIVATOR) {
            $chance += 2;
        } elseif ($character === self::CHARACTER_RISK_TAKER) {
            $chance -= 1;
        }
        return $chance;
    }

    public static function getChemistryFactor(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $character = self::getTeamManagerCharacter($websoccer, $db, $teamId);
        if (!strlen($character)) {
            return array('score' => 50, 'detail' => '');
        }

        $score = 50;
        if ($character === self::CHARACTER_MOTIVATOR || $character === self::CHARACTER_CLUB_ICON) {
            $score = 58;
        } elseif ($character === self::CHARACTER_DISCIPLINARIAN) {
            $score = 52;
        } elseif ($character === self::CHARACTER_DEVELOPER) {
            $score = 54;
        } elseif ($character === self::CHARACTER_MEDIA_FRIENDLY) {
            $score = 51;
        } elseif ($character === self::CHARACTER_FINANCIAL_STRATEGIST) {
            $score = 48;
        } elseif ($character === self::CHARACTER_RISK_TAKER) {
            $score = 46;
        } elseif ($character === self::CHARACTER_TACTICIAN) {
            $score = 53;
        }
        return array('score' => $score, 'detail' => $character);
    }

    public static function adjustFanPressureChanges(WebSoccer $websoccer, DbConnection $db, $team, $source, $reasonKey, &$moodChange, &$pressureChange, &$boardChange, &$chemistryChange, &$context) {
        if (!is_array($team) || !isset($team['user_id']) || (int) $team['user_id'] < 1) {
            return;
        }
        $character = self::getUserCharacter($websoccer, $db, (int) $team['user_id']);
        if (!strlen($character) || $character === self::CHARACTER_BALANCED) {
            return;
        }

        $before = array((int) $moodChange, (int) $pressureChange, (int) $boardChange, (int) $chemistryChange);

        if ($character === self::CHARACTER_MEDIA_FRIENDLY) {
            if ($pressureChange > 0) {
                $pressureChange -= 1;
            }
            if ($source === 'interview') {
                $moodChange += 1;
                $pressureChange -= 1;
            }
            if ($boardChange > 0) {
                $boardChange -= 1;
            }
        } elseif ($character === self::CHARACTER_CLUB_ICON) {
            if ($moodChange < 0) {
                $moodChange += 1;
            }
            if ($source === 'derby' && $moodChange > 0) {
                $moodChange += 1;
            }
            if ($reasonKey === 'fanpressure_reason_transfer_sale') {
                $pressureChange += 1;
            }
        } elseif ($character === self::CHARACTER_FINANCIAL_STRATEGIST) {
            if ($source === 'transfer' || $source === 'mission') {
                $boardChange += 1;
            }
            if ($reasonKey === 'fanpressure_reason_transfer_sale') {
                $moodChange -= 1;
            }
        } elseif ($character === self::CHARACTER_DEVELOPER) {
            if ($source === 'youth' || $reasonKey === 'fanpressure_reason_youth_used') {
                $moodChange += 1;
                $boardChange += 1;
                $chemistryChange += 1;
            }
            if ($reasonKey === 'fanpressure_reason_transfer_signing') {
                $boardChange -= 1;
            }
        } elseif ($character === self::CHARACTER_RISK_TAKER) {
            if ($moodChange > 0 || $boardChange > 0) {
                $moodChange += 1;
                $pressureChange -= 1;
            }
            if ($moodChange < 0 || $boardChange < 0) {
                $pressureChange += 1;
                $boardChange -= 1;
            }
        } elseif ($character === self::CHARACTER_MOTIVATOR) {
            if ($moodChange < 0) {
                $moodChange += 1;
            }
            if ($chemistryChange < 0) {
                $chemistryChange += 1;
            }
        } elseif ($character === self::CHARACTER_DISCIPLINARIAN) {
            if ($boardChange < 0 && $source !== 'interview') {
                $boardChange += 1;
            }
            if ($moodChange > 0) {
                $moodChange -= 1;
            }
        } elseif ($character === self::CHARACTER_TACTICIAN) {
            if ($source === 'match' && $boardChange < 0) {
                $boardChange += 1;
            }
            if ($moodChange > 0 && $pressureChange < 0) {
                $moodChange -= 1;
            }
        }

        $moodChange = max(-30, min(30, (int) $moodChange));
        $pressureChange = max(-30, min(30, (int) $pressureChange));
        $boardChange = max(-30, min(30, (int) $boardChange));
        $chemistryChange = max(-30, min(30, (int) $chemistryChange));

        $after = array((int) $moodChange, (int) $pressureChange, (int) $boardChange, (int) $chemistryChange);
        if ($before !== $after) {
            if (!is_array($context)) {
                $context = array();
            }
            $context['manager_character'] = $character;
            $context['manager_character_effect'] = array(
                'mood' => $after[0] - $before[0],
                'pressure' => $after[1] - $before[1],
                'board' => $after[2] - $before[2],
                'chemistry' => $after[3] - $before[3]
            );
        }
    }

    private static function applyTeamMatchEffects(WebSoccer $websoccer, DbConnection $db, SimulationMatch $match, SimulationTeam $team, $isHomeTeam, $applyMorale) {
        if (!$team || (int) $team->id < 1 || $team->isNationalTeam) {
            return;
        }
        $character = self::getTeamManagerCharacter($websoccer, $db, (int) $team->id);
        $team->managerCharacter = $character;
        $team->managerCharacterMatchEffect = 0;

        if (!strlen($character) || $character === self::CHARACTER_BALANCED) {
            return;
        }

        $moraleEffect = 0;
        if ($character === self::CHARACTER_MOTIVATOR) {
            $moraleEffect += ($match->isBigGame || (int) $team->morale < 55) ? 2 : 1;
        } elseif ($character === self::CHARACTER_TACTICIAN) {
            if ((int) $team->tacticalStyleFit >= 75) {
                $team->managerCharacterMatchEffect = 1;
            } else if ((int) $team->tacticalStyleFit < 35) {
                $moraleEffect -= 1;
            }
        } elseif ($character === self::CHARACTER_DISCIPLINARIAN) {
            $moraleEffect -= ((int) $team->morale > 70) ? 1 : 0;
        } elseif ($character === self::CHARACTER_DEVELOPER) {
            $moraleEffect -= ($match->isBigGame) ? 1 : 0;
        } elseif ($character === self::CHARACTER_MEDIA_FRIENDLY) {
            $moraleEffect += 0;
        } elseif ($character === self::CHARACTER_FINANCIAL_STRATEGIST) {
            $moraleEffect -= ($match->isBigGame) ? 1 : 0;
        } elseif ($character === self::CHARACTER_CLUB_ICON) {
            $moraleEffect += ($isHomeTeam || $match->isBigGame) ? 2 : 0;
        } elseif ($character === self::CHARACTER_RISK_TAKER) {
            $moraleEffect += ((int) $team->offensive >= 65) ? 1 : 0;
            if ((int) $team->offensive <= 40) {
                $moraleEffect -= 1;
            }
        }

        if ($moraleEffect != 0 && $applyMorale) {
            $team->morale = min(100, max(0, (int) $team->morale + (int) $moraleEffect));
            $team->managerCharacterMatchEffect = (int) $team->managerCharacterMatchEffect + (int) $moraleEffect;
        }
    }

    private static function applyCharacterChangePenalty(WebSoccer $websoccer, DbConnection $db, $teamId, $oldCharacter, $newCharacter) {
        $teamId = (int) $teamId;
        if ($teamId < 1) {
            return;
        }

        $team = self::getTeamMentalValues($websoccer, $db, $teamId);
        if (!$team) {
            return;
        }

        $columns = array();
        if (isset($team['team_chemistry'])) {
            $columns['team_chemistry'] = self::normalizePercent((int) $team['team_chemistry'] - 3);
            $columns['team_chemistry_updated'] = $websoccer->getNowAsTimestamp();
        }
        if (isset($team['board_satisfaction'])) {
            $columns['board_satisfaction'] = self::normalizePercent((int) $team['board_satisfaction'] - 2);
        }
        if (isset($team['media_pressure'])) {
            $columns['media_pressure'] = self::normalizePercent((int) $team['media_pressure'] + 3);
        }

        if (count($columns)) {
            $db->queryUpdate($columns, $websoccer->getConfig('db_prefix') . '_verein', 'id = %d', $teamId);
        }

        self::insertTeamChemistryLog($websoccer, $db, $teamId, $team, isset($columns['team_chemistry']) ? (int) $columns['team_chemistry'] : null, $oldCharacter, $newCharacter);
    }

    private static function insertTeamChemistryLog(WebSoccer $websoccer, DbConnection $db, $teamId, $team, $newChemistry, $oldCharacter, $newCharacter) {
        if ($newChemistry === null || !self::tableExists($db, $websoccer->getConfig('db_prefix') . '_team_chemistry_log')) {
            return;
        }
        $oldChemistry = isset($team['team_chemistry']) ? (int) $team['team_chemistry'] : $newChemistry;
        $db->queryInsert(array(
            'team_id' => (int) $teamId,
            'event_date' => $websoccer->getNowAsTimestamp(),
            'source' => 'manager_character',
            'old_score' => self::normalizePercent($oldChemistry),
            'new_score' => self::normalizePercent($newChemistry),
            'match_effect' => 0,
            'match_id' => '',
            'breakdown_data' => json_encode(array('old_character' => $oldCharacter, 'new_character' => $newCharacter))
        ), $websoccer->getConfig('db_prefix') . '_team_chemistry_log');
    }

    private static function getUserCharacterInfo(WebSoccer $websoccer, DbConnection $db, $userId) {
        if (!self::schemaReady($websoccer, $db)) {
            return array(
                'manager_character' => '',
                'manager_character_set_date' => 0,
                'manager_character_last_change' => 0,
                'manager_character_changes' => 0,
                'manager_character_last_change_matches' => 0
            );
        }
        $result = $db->querySelect(
            'manager_character, manager_character_set_date, manager_character_last_change, manager_character_changes, manager_character_last_change_matches',
            $websoccer->getConfig('db_prefix') . '_user',
            'id = %d',
            (int) $userId,
            1
        );
        $row = $result->fetch_array();
        $result->free();
        return $row ? $row : array();
    }

    private static function getCurrentOfficialMatches(WebSoccer $websoccer, DbConnection $db, $teamId, $userId) {
        $teamId = (int) $teamId;
        if ($teamId < 1) {
            $teamId = self::getMainTeamIdOfUser($websoccer, $db, $userId);
        }
        if ($teamId < 1) {
            return 0;
        }
        $result = $db->querySelect('st_spiele', $websoccer->getConfig('db_prefix') . '_verein', 'id = %d', $teamId, 1);
        $row = $result->fetch_array();
        $result->free();
        return ($row && isset($row['st_spiele'])) ? (int) $row['st_spiele'] : 0;
    }

    private static function getMainTeamIdOfUser(WebSoccer $websoccer, DbConnection $db, $userId) {
        $result = $db->querySelect('id', $websoccer->getConfig('db_prefix') . '_verein', "status = '1' AND user_id = %d AND nationalteam != '1' ORDER BY interimmanager DESC, id ASC", (int) $userId, 1);
        $row = $result->fetch_array();
        $result->free();
        return ($row && isset($row['id'])) ? (int) $row['id'] : 0;
    }

    private static function getTeamMentalValues(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $result = $db->querySelect('team_chemistry, board_satisfaction, media_pressure', $websoccer->getConfig('db_prefix') . '_verein', 'id = %d', (int) $teamId, 1);
        $row = $result->fetch_array();
        $result->free();
        return $row ? $row : null;
    }

    private static function schemaReady(WebSoccer $websoccer, DbConnection $db) {
        if (self::$_schemaReady !== null) {
            return self::$_schemaReady;
        }
        $table = $websoccer->getConfig('db_prefix') . '_user';
        self::$_schemaReady = self::columnExists($db, $table, 'manager_character');
        return self::$_schemaReady;
    }

    private static function columnExists(DbConnection $db, $table, $column) {
        $result = $db->executeQuery("SHOW COLUMNS FROM " . $table . " LIKE '" . $db->connection->real_escape_string($column) . "'");
        $row = $result->fetch_array();
        $result->free();
        return ($row) ? TRUE : FALSE;
    }

    private static function tableExists(DbConnection $db, $table) {
        $result = $db->executeQuery("SHOW TABLES LIKE '" . $db->connection->real_escape_string($table) . "'");
        $row = $result->fetch_array();
        $result->free();
        return ($row) ? TRUE : FALSE;
    }

    private static function getCooldownMatches(WebSoccer $websoccer) {
        try {
            $value = (int) $websoccer->getConfig('manager_character_change_cooldown_matches');
            if ($value > 0) {
                return $value;
            }
        } catch (Exception $e) {
        }
        return 30;
    }

    private static function multiplyPositiveEffects(&$effects, $multiplier) {
        foreach ($effects as $key => $value) {
            if ((float) $value > 0) {
                $effects[$key] = (float) $value * (float) $multiplier;
            }
        }
    }

    private static function normalizePercent($value) {
        return max(0, min(100, (int) round($value)));
    }

    private static function teamIsTrailing(SimulationTeam $team, SimulationMatch $match) {
        if (!$team || !$match) {
            return FALSE;
        }
        $opponent = ($match->homeTeam->id == $team->id) ? $match->guestTeam : $match->homeTeam;
        return ((int) $team->getGoals() < (int) $opponent->getGoals());
    }

    private static function label(I18n $i18n, $character) {
        return self::message($i18n, 'manager_character_' . $character);
    }

    private static function message(I18n $i18n, $key, $data = array()) {
        if (is_object($i18n) && method_exists($i18n, 'hasMessage') && $i18n->hasMessage($key)) {
            if (count($data)) {
                $message = $i18n->getMessage($key);
                foreach ($data as $placeholder => $value) {
                    $message = str_replace('{' . $placeholder . '}', $value, $message);
                }
                return $message;
            }
            return $i18n->getMessage($key);
        }
        return $key;
    }
}
?>
