<?php
/******************************************************

  Player personality helper service for CM23.

******************************************************/

/**
 * Centralizes all player personality rules so effects stay small, visible and easy to tune.
 */
class PlayerPersonalityDataService {

    const TRAIT_LEADER = 'leader';
    const TRAIT_PROFESSIONAL = 'professional';
    const TRAIT_INJURY_PRONE = 'injury_prone';
    const TRAIT_INCONSISTENT = 'inconsistent';
    const TRAIT_LOYAL = 'loyal';
    const TRAIT_AMBITIOUS = 'ambitious';
    const TRAIT_BIG_GAME_PLAYER = 'big_game_player';
    const TRAIT_TROUBLEMAKER = 'troublemaker';

    public static function getTraits() {
        return array(
            self::TRAIT_LEADER,
            self::TRAIT_PROFESSIONAL,
            self::TRAIT_INJURY_PRONE,
            self::TRAIT_INCONSISTENT,
            self::TRAIT_LOYAL,
            self::TRAIT_AMBITIOUS,
            self::TRAIT_BIG_GAME_PLAYER,
            self::TRAIT_TROUBLEMAKER
        );
    }

    public static function normalizeTrait($trait) {
        $trait = trim((string) $trait);
        if (!in_array($trait, self::getTraits())) {
            return self::TRAIT_PROFESSIONAL;
        }
        return $trait;
    }

    public static function getRandomTrait() {
        $traits = self::getTraits();
        return $traits[mt_rand(0, count($traits) - 1)];
    }

    public static function isVisibleForUser(WebSoccer $websoccer, DbConnection $db, $playerTeamId, $scoutingResult = null) {
        $userTeamId = 0;
        if ($websoccer->getUser()) {
            $userTeamId = (int) $websoccer->getUser()->getClubId($websoccer, $db);
        }

        if ($userTeamId > 0 && (int) $playerTeamId === $userTeamId) {
            return TRUE;
        }

        if (is_array($scoutingResult)) {
            return count($scoutingResult) > 0;
        }

        return ($scoutingResult !== null && strlen((string) $scoutingResult) > 0);
    }

    public static function getInjuryProbabilityMultiplier($trait) {
        $trait = self::normalizeTrait($trait);
        if ($trait === self::TRAIT_INJURY_PRONE) {
            return 1.15;
        }
        if ($trait === self::TRAIT_PROFESSIONAL) {
            return 0.95;
        }
        return 1.0;
    }

    public static function getCaptainMoraleMultiplier($trait) {
        $trait = self::normalizeTrait($trait);
        if ($trait === self::TRAIT_LEADER) {
            return 1.15;
        }
        if ($trait === self::TRAIT_PROFESSIONAL) {
            return 1.05;
        }
        if ($trait === self::TRAIT_TROUBLEMAKER) {
            return 0.90;
        }
        return 1.0;
    }

    public static function getMatchStrengthMultiplier($trait, SimulationMatch $match, SimulationPlayer $player) {
        $trait = self::normalizeTrait($trait);
        $factor = 1.0;

        if ($trait === self::TRAIT_BIG_GAME_PLAYER && $match->isBigGame) {
            $factor *= 1.10;
        }

        if ($trait === self::TRAIT_INCONSISTENT) {
            // Stable per player+match: -15% to +15%, without changing global random state.
            $hash = (int) sprintf('%u', crc32($match->id . ':' . $player->id . ':personality'));
            $variation = ($hash % 31) - 15;
            $factor *= (1 + ($variation / 100));
        }

        if ($trait === self::TRAIT_TROUBLEMAKER && (int) $player->team->morale < 35) {
            $factor *= 0.95;
        }

        return $factor;
    }

    public static function adjustSatisfactionDelta($trait, $delta, $context = '') {
        $trait = self::normalizeTrait($trait);
        $delta = (float) $delta;

        if ($delta < 0) {
            if ($trait === self::TRAIT_LOYAL || $trait === self::TRAIT_PROFESSIONAL) {
                $delta *= 0.85;
            } elseif ($trait === self::TRAIT_TROUBLEMAKER) {
                $delta *= 1.15;
            } elseif ($trait === self::TRAIT_AMBITIOUS && ($context === 'loss' || $context === 'not_played')) {
                $delta *= 1.15;
            }
        } elseif ($delta > 0) {
            if ($trait === self::TRAIT_PROFESSIONAL) {
                $delta *= 1.05;
            } elseif ($trait === self::TRAIT_AMBITIOUS && $context === 'win') {
                $delta *= 1.15;
            } elseif ($trait === self::TRAIT_LEADER) {
                $delta *= 1.05;
            }
        }

        return $delta;
    }

    public static function applyTrainingEffects($trait,
        &$effectFreshness, &$effectTechnique, &$effectStamina, &$effectSatisfaction, &$effectPassing,
        &$effectShooting, &$effectHeading, &$effectTackling, &$effectFreekick, &$effectPace, &$effectCreativity,
        &$effectInfluence, &$effectFlair, &$effectPenalty, &$effectPenaltyKilling) {

        $trait = self::normalizeTrait($trait);
        $positiveMultiplier = 1.0;
        $negativeMultiplier = 1.0;

        if ($trait === self::TRAIT_PROFESSIONAL) {
            $positiveMultiplier = 1.15;
            $negativeMultiplier = 0.90;
        } elseif ($trait === self::TRAIT_AMBITIOUS) {
            $positiveMultiplier = 1.08;
        } elseif ($trait === self::TRAIT_TROUBLEMAKER) {
            $positiveMultiplier = 0.95;
            $negativeMultiplier = 1.10;
        } elseif ($trait === self::TRAIT_INJURY_PRONE) {
            $negativeMultiplier = 1.05;
        }

        self::applyMultiplier($effectFreshness, $positiveMultiplier, $negativeMultiplier);
        self::applyMultiplier($effectTechnique, $positiveMultiplier, $negativeMultiplier);
        self::applyMultiplier($effectStamina, $positiveMultiplier, $negativeMultiplier);
        self::applyMultiplier($effectSatisfaction, $positiveMultiplier, $negativeMultiplier);
        self::applyMultiplier($effectPassing, $positiveMultiplier, $negativeMultiplier);
        self::applyMultiplier($effectShooting, $positiveMultiplier, $negativeMultiplier);
        self::applyMultiplier($effectHeading, $positiveMultiplier, $negativeMultiplier);
        self::applyMultiplier($effectTackling, $positiveMultiplier, $negativeMultiplier);
        self::applyMultiplier($effectFreekick, $positiveMultiplier, $negativeMultiplier);
        self::applyMultiplier($effectPace, $positiveMultiplier, $negativeMultiplier);
        self::applyMultiplier($effectCreativity, $positiveMultiplier, $negativeMultiplier);
        self::applyMultiplier($effectInfluence, $positiveMultiplier, $negativeMultiplier);
        self::applyMultiplier($effectFlair, $positiveMultiplier, $negativeMultiplier);
        self::applyMultiplier($effectPenalty, $positiveMultiplier, $negativeMultiplier);
        self::applyMultiplier($effectPenaltyKilling, $positiveMultiplier, $negativeMultiplier);
    }

    private static function applyMultiplier(&$value, $positiveMultiplier, $negativeMultiplier) {
        $value = (float) $value;
        if ($value > 0) {
            $value *= $positiveMultiplier;
        } elseif ($value < 0) {
            $value *= $negativeMultiplier;
        }
    }

    public static function isBigGameMatch(WebSoccer $websoccer, DbConnection $db, SimulationMatch $match) {
        if ($match->type === 'Pokalspiel') {
            return TRUE;
        }

        if (self::isDerbyMatch($websoccer, $db, $match->id)) {
            return TRUE;
        }

        return self::hasTopTableTeam($websoccer, $db, $match->homeTeam->id, $match->guestTeam->id);
    }

    private static function isDerbyMatch(WebSoccer $websoccer, DbConnection $db, $matchId) {
        $table = $websoccer->getConfig('db_prefix') . '_derby_match';
        $result = $db->querySelect('match_id', $table, 'match_id = %d', (int) $matchId, 1);
        $row = $result->fetch_array();
        $result->free();
        return isset($row['match_id']);
    }

    private static function hasTopTableTeam(WebSoccer $websoccer, DbConnection $db, $homeTeamId, $guestTeamId) {
        $table = $websoccer->getConfig('db_prefix') . '_verein';
        $result = $db->querySelect('id, platz', $table, 'id IN (%d, %d)', array((int) $homeTeamId, (int) $guestTeamId), 2);
        while ($team = $result->fetch_array()) {
            $rank = (int) $team['platz'];
            if ($rank > 0 && $rank <= 5) {
                $result->free();
                return TRUE;
            }
        }
        $result->free();
        return FALSE;
    }
}
?>
