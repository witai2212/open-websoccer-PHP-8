<?php
/******************************************************

  This file is part of OpenWebSoccer-Sim.

  OpenWebSoccer-Sim is free software: you can redistribute it 
  and/or modify it under the terms of the 
  GNU Lesser General Public License 
  as published by the Free Software Foundation, either version 3 of
  the License, or any later version.

  OpenWebSoccer-Sim is distributed in the hope that it will be
  useful, but WITHOUT ANY WARRANTY; without even the implied
  warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. 
  See the GNU Lesser General Public License for more details.

  You should have received a copy of the GNU Lesser General Public 
  License along with OpenWebSoccer-Sim.  
  If not, see <http://www.gnu.org/licenses/>.

******************************************************/

/**
 * Data service for player traits / special abilities.
 *
 * Phase 1 provides data and display helpers.
 * Phase 2 adds deliberately small effects on market value and match simulation.
 */
class PlayerTraitsDataService {

	private static $_installed = null;
	private static $_youthInstalled = null;
	private static $_traitsCache = array();
	private static $_youthTraitsCache = array();

	const MARKET_VALUE_BONUS_CAP = 0.20;

	/**
	 * Fixed CM23 trait catalog.
	 * Trait values are stored as 0-3. Only values greater than 0 are displayed and applied.
	 *
	 * @return array Trait definitions indexed by trait key.
	 */
	public static function getDefinitions() {
		return array(
			'torinstinkt' => array('label_key' => 'player_trait_torinstinkt', 'category' => 'field', 'sort_order' => 10),
			'spielmacher' => array('label_key' => 'player_trait_spielmacher', 'category' => 'field', 'sort_order' => 20),
			'ballzauberer' => array('label_key' => 'player_trait_ballzauberer', 'category' => 'field', 'sort_order' => 30),
			'flankenspezialist' => array('label_key' => 'player_trait_flankenspezialist', 'category' => 'field', 'sort_order' => 40),
			'kopfballstaerke' => array('label_key' => 'player_trait_kopfballstaerke', 'category' => 'field', 'sort_order' => 50),
			'viererkette' => array('label_key' => 'player_trait_viererkette', 'category' => 'field', 'sort_order' => 60),
			'laufstaerke' => array('label_key' => 'player_trait_laufstaerke', 'category' => 'field', 'sort_order' => 70),
			'freistossspezialist' => array('label_key' => 'player_trait_freistossspezialist', 'category' => 'field', 'sort_order' => 80),
			'elfmeterschuetze' => array('label_key' => 'player_trait_elfmeterschuetze', 'category' => 'field', 'sort_order' => 90),
			'dribbler' => array('label_key' => 'player_trait_dribbler', 'category' => 'field', 'sort_order' => 100),
			'elfmetertoeter' => array('label_key' => 'player_trait_elfmetertoeter', 'category' => 'goalkeeper', 'sort_order' => 110),
			'reflexe' => array('label_key' => 'player_trait_reflexe', 'category' => 'goalkeeper', 'sort_order' => 120)
		);
	}

	/**
	 * Returns a comma separated list of keys for admin module.xml select fields.
	 *
	 * @return string
	 */
	public static function getDefinitionKeysSelection() {
		return implode(',', array_keys(self::getDefinitions()));
	}


	/**
	 * Returns active traits for one youth player.
	 *
	 * @param WebSoccer $websoccer Application context.
	 * @param DbConnection $db DB connection.
	 * @param int $youthPlayerId Youth player ID.
	 * @return array List of trait info arrays.
	 */
	public static function getTraitsOfYouthPlayer(WebSoccer $websoccer, DbConnection $db, $youthPlayerId) {
		$traitsByPlayer = self::getTraitsOfYouthPlayers($websoccer, $db, array((int) $youthPlayerId));
		return (isset($traitsByPlayer[$youthPlayerId])) ? $traitsByPlayer[$youthPlayerId] : array();
	}

	/**
	 * Returns active traits for multiple youth players.
	 *
	 * @param WebSoccer $websoccer Application context.
	 * @param DbConnection $db DB connection.
	 * @param array $youthPlayerIds Youth player IDs.
	 * @return array Traits indexed by youth player ID.
	 */
	public static function getTraitsOfYouthPlayers(WebSoccer $websoccer, DbConnection $db, $youthPlayerIds) {
		$traits = array();
		$youthPlayerIds = self::_sanitizePlayerIds($youthPlayerIds);
		if (!count($youthPlayerIds) || !self::isYouthInstalled($websoccer, $db)) {
			return $traits;
		}

		$missingPlayerIds = array();
		foreach ($youthPlayerIds as $playerId) {
			if (isset(self::$_youthTraitsCache[$playerId])) {
				if (count(self::$_youthTraitsCache[$playerId])) {
					$traits[$playerId] = self::$_youthTraitsCache[$playerId];
				}
			} else {
				$missingPlayerIds[] = $playerId;
			}
		}

		if (!count($missingPlayerIds)) {
			return $traits;
		}

		foreach ($missingPlayerIds as $playerId) {
			self::$_youthTraitsCache[$playerId] = array();
		}

		$definitions = self::getDefinitions();
		$fromTable = $websoccer->getConfig('db_prefix') . '_youthplayer_trait';
		$whereCondition = 'youth_player_id IN (' . implode(',', $missingPlayerIds) . ') AND trait_value > 0 ORDER BY youth_player_id ASC, trait_key ASC';
		$result = $db->querySelect('*', $fromTable, $whereCondition);
		while ($row = $result->fetch_array()) {
			$key = $row['trait_key'];
			if (!isset($definitions[$key])) {
				continue;
			}
			$value = max(0, min(3, (int) $row['trait_value']));
			if ($value < 1) {
				continue;
			}

			$definition = $definitions[$key];
			$playerId = (int) $row['youth_player_id'];
			self::$_youthTraitsCache[$playerId][] = array(
				'key' => $key,
				'value' => $value,
				'label_key' => $definition['label_key'],
				'category' => $definition['category'],
				'sort_order' => $definition['sort_order']
			);
		}
		$result->free();

		foreach ($missingPlayerIds as $playerId) {
			if (count(self::$_youthTraitsCache[$playerId])) {
				usort(self::$_youthTraitsCache[$playerId], array('PlayerTraitsDataService', '_sortTraits'));
				$traits[$playerId] = self::$_youthTraitsCache[$playerId];
			}
		}

		return $traits;
	}

	/**
	 * Adds youth trait data to each youth player row.
	 *
	 * @param WebSoccer $websoccer Application context.
	 * @param DbConnection $db DB connection.
	 * @param array $players Youth player rows.
	 * @return array Youth player rows with traits key.
	 */
	public static function attachTraitsToYouthPlayers(WebSoccer $websoccer, DbConnection $db, $players) {
		if (!is_array($players) || !count($players)) {
			return $players;
		}

		$playerIds = array();
		foreach ($players as $player) {
			if (isset($player['id'])) {
				$playerIds[] = (int) $player['id'];
			} elseif (isset($player['player_id'])) {
				$playerIds[] = (int) $player['player_id'];
			}
		}

		$traitsByPlayer = self::getTraitsOfYouthPlayers($websoccer, $db, $playerIds);
		foreach ($players as $index => $player) {
			$playerId = isset($player['id']) ? (int) $player['id'] : (isset($player['player_id']) ? (int) $player['player_id'] : 0);
			$players[$index]['traits'] = (isset($traitsByPlayer[$playerId])) ? $traitsByPlayer[$playerId] : array();
			$players[$index]['traits_count'] = count($players[$index]['traits']);
		}

		return $players;
	}

	/**
	 * Stores a trait map for an existing professional player.
	 * Existing keys in the supplied map are replaced, not all traits of the player.
	 *
	 * @param WebSoccer $websoccer Application context.
	 * @param DbConnection $db DB connection.
	 * @param int $playerId Professional player ID.
	 * @param array $traits Trait map key => value.
	 */
	public static function assignTraitsToPlayer(WebSoccer $websoccer, DbConnection $db, $playerId, $traits) {
		$playerId = (int) $playerId;
		if ($playerId < 1 || !is_array($traits) || !count($traits) || !self::isInstalled($websoccer, $db)) {
			return;
		}

		self::_assignTraitsToTable($websoccer, $db, $websoccer->getConfig('db_prefix') . '_player_trait', 'player_id', $playerId, $traits);
		unset(self::$_traitsCache[$playerId]);
	}

	/**
	 * Stores a trait map for an existing youth player.
	 *
	 * @param WebSoccer $websoccer Application context.
	 * @param DbConnection $db DB connection.
	 * @param int $youthPlayerId Youth player ID.
	 * @param array $traits Trait map key => value.
	 */
	public static function assignTraitsToYouthPlayer(WebSoccer $websoccer, DbConnection $db, $youthPlayerId, $traits) {
		$youthPlayerId = (int) $youthPlayerId;
		if ($youthPlayerId < 1 || !is_array($traits) || !count($traits) || !self::isYouthInstalled($websoccer, $db)) {
			return;
		}

		self::_assignTraitsToTable($websoccer, $db, $websoccer->getConfig('db_prefix') . '_youthplayer_trait', 'youth_player_id', $youthPlayerId, $traits);
		unset(self::$_youthTraitsCache[$youthPlayerId]);
	}

	/**
	 * Copies youth traits to the created professional player.
	 *
	 * @param WebSoccer $websoccer Application context.
	 * @param DbConnection $db DB connection.
	 * @param int $youthPlayerId Youth player ID.
	 * @param int $professionalPlayerId Professional player ID.
	 */
	public static function copyYouthTraitsToProfessionalPlayer(WebSoccer $websoccer, DbConnection $db, $youthPlayerId, $professionalPlayerId) {
		$map = array();
		foreach (self::getTraitsOfYouthPlayer($websoccer, $db, (int) $youthPlayerId) as $trait) {
			$map[$trait['key']] = (int) $trait['value'];
		}
		self::assignTraitsToPlayer($websoccer, $db, (int) $professionalPlayerId, $map);
	}

	/**
	 * Generates traits for a fully generated scouting candidate.
	 *
	 * @param string $position General position.
	 * @param string $mainPosition Detailed position.
	 * @param int $age Player age.
	 * @param int $talent Talent value.
	 * @param array $attributes Numeric generated attributes.
	 * @param int $quality Scouting generation quality.
	 * @return array Trait map key => value.
	 */
	public static function generateTraitsForCandidate($position, $mainPosition, $age, $talent, $attributes, $quality = 50) {
		$traits = array();
		$quality = max(1, min(100, (int) $quality));
		$talent = max(1, min(6, (int) $talent));
		$position = self::_normalizePosition($position);
		$mainPosition = strtoupper((string) $mainPosition);

		$candidates = array();
		if ($position == 'goaly') {
			$candidates['reflexe'] = self::_skillValue($attributes, 'penalty_killing');
			$candidates['elfmetertoeter'] = self::_skillValue($attributes, 'penalty_killing');
		} else {
			$candidates['laufstaerke'] = self::_skillValue($attributes, 'pace');
			$candidates['freistossspezialist'] = self::_skillValue($attributes, 'freekick');
			$candidates['elfmeterschuetze'] = self::_skillValue($attributes, 'penalty');
			$candidates['kopfballstaerke'] = self::_skillValue($attributes, 'heading');

			if ($position == 'striker') {
				$candidates['torinstinkt'] = max(self::_skillValue($attributes, 'shooting'), self::_skillValue($attributes, 'flair') - 5);
			} elseif ($position == 'midfield') {
				$candidates['spielmacher'] = max(self::_skillValue($attributes, 'passing'), self::_skillValue($attributes, 'creativity'));
				$candidates['ballzauberer'] = max(self::_skillValue($attributes, 'flair'), self::_skillValue($attributes, 'technique'));
				$candidates['dribbler'] = max(self::_skillValue($attributes, 'pace'), self::_skillValue($attributes, 'flair'));
			} elseif ($position == 'defense') {
				$candidates['viererkette'] = max(self::_skillValue($attributes, 'tackling'), self::_skillValue($attributes, 'influence'));
			}

			if (in_array($mainPosition, array('LV', 'RV', 'LM', 'RM', 'LS', 'RS'))) {
				$candidates['flankenspezialist'] = max(self::_skillValue($attributes, 'passing'), self::_skillValue($attributes, 'pace'));
			}
		}

		foreach ($candidates as $key => $skill) {
			$value = self::_traitValueFromSkill($skill, $talent, $quality);
			if ($value > 0) {
				$traits[$key] = $value;
			}
		}

		return self::_limitTraitMap($traits, 3);
	}

	/**
	 * Generates rare starting traits for newly scouted youth players.
	 *
	 * @param string $position General position.
	 * @param int $age Youth player age.
	 * @param int $strength Current youth strength.
	 * @param array $academy Academy row or empty array.
	 * @param int $scoutExpertise Youth scout expertise.
	 * @return array Trait map key => value.
	 */
	public static function generateTraitsForYouthPlayer($position, $age, $strength, $academy = array(), $scoutExpertise = 50) {
		$traits = array();
		$position = self::_normalizePosition($position);
		$strength = max(1, min(100, (int) $strength));
		$level = (isset($academy['level'])) ? (int) $academy['level'] : 1;
		$reputation = (isset($academy['reputation'])) ? (int) $academy['reputation'] : 50;
		$focus = (isset($academy['focus'])) ? $academy['focus'] : 'balanced';
		$scoutExpertise = max(1, min(100, (int) $scoutExpertise));

		$chance = 2 + ($level * 2) + (int) round(max(0, $reputation - 50) / 8) + (int) round($scoutExpertise / 25) + (int) round(max(0, $strength - 50) / 10);
		$chance = max(1, min(28, $chance));
		if (mt_rand(1, 100) > $chance) {
			return $traits;
		}

		$pool = self::_getYouthTraitPool($position, $focus);
		if (!count($pool)) {
			return $traits;
		}

		$key = $pool[array_rand($pool)];
		$value = 1;
		if ($strength >= 65 && mt_rand(1, 100) <= 25) {
			$value = 2;
		}
		if ($strength >= 80 && $level >= 4 && mt_rand(1, 100) <= 10) {
			$value = 3;
		}
		$traits[$key] = $value;

		return $traits;
	}

	/**
	 * Builds a manager-facing scouting report for generated traits.
	 *
	 * @param array $traits Real trait map.
	 * @param int $expertise Effective scout quality.
	 * @return array Reported trait map.
	 */
	public static function buildReportedTraits($traits, $expertise) {
		$reported = array();
		if (!is_array($traits) || !count($traits)) {
			return $reported;
		}

		$expertise = max(1, min(100, (int) $expertise));
		foreach ($traits as $key => $value) {
			$value = max(1, min(3, (int) $value));
			$detectChance = 35 + (int) round($expertise / 2) + ($value * 8);
			if ($expertise < 35) {
				$detectChance -= 15;
			}
			if (mt_rand(1, 100) > max(15, min(95, $detectChance))) {
				continue;
			}

			$reportedValue = $value;
			if ($expertise < 70 && mt_rand(1, 100) <= 45) {
				$reportedValue += mt_rand(-1, 1);
			} elseif ($expertise < 40 && mt_rand(1, 100) <= 35) {
				$reportedValue += mt_rand(-1, 1);
			}
			$reported[$key] = max(1, min(3, (int) $reportedValue));
		}

		return $reported;
	}

	/**
	 * Converts a trait map to display rows.
	 *
	 * @param array|string $traits Trait map or JSON string.
	 * @return array Display rows.
	 */
	public static function traitMapToDisplayRows($traits) {
		if (is_string($traits)) {
			$traits = self::decodeTraitMap($traits);
		}
		$rows = array();
		if (!is_array($traits) || !count($traits)) {
			return $rows;
		}

		$definitions = self::getDefinitions();
		foreach ($traits as $key => $value) {
			if (!isset($definitions[$key])) {
				continue;
			}
			$value = max(1, min(3, (int) $value));
			$rows[] = array(
				'key' => $key,
				'value' => $value,
				'label_key' => $definitions[$key]['label_key'],
				'category' => $definitions[$key]['category'],
				'sort_order' => $definitions[$key]['sort_order']
			);
		}
		usort($rows, array('PlayerTraitsDataService', '_sortTraits'));
		return $rows;
	}

	/**
	 * Encodes a trait map for storage in text columns.
	 *
	 * @param array $traits Trait map.
	 * @return string JSON map.
	 */
	public static function encodeTraitMap($traits) {
		$clean = array();
		$definitions = self::getDefinitions();
		if (is_array($traits)) {
			foreach ($traits as $key => $value) {
				if (isset($definitions[$key])) {
					$value = max(0, min(3, (int) $value));
					if ($value > 0) {
						$clean[$key] = $value;
					}
				}
			}
		}
		return json_encode($clean);
	}

	/**
	 * Decodes a stored trait map.
	 *
	 * @param string $json JSON map.
	 * @return array Trait map.
	 */
	public static function decodeTraitMap($json) {
		if (!strlen((string) $json)) {
			return array();
		}
		$data = json_decode($json, true);
		if (!is_array($data)) {
			return array();
		}
		$clean = array();
		$definitions = self::getDefinitions();
		foreach ($data as $key => $value) {
			if (isset($definitions[$key])) {
				$value = max(0, min(3, (int) $value));
				if ($value > 0) {
					$clean[$key] = $value;
				}
			}
		}
		return $clean;
	}

	/**
	 * Returns active traits for one player.
	 *
	 * @param WebSoccer $websoccer Application context.
	 * @param DbConnection $db DB connection.
	 * @param int $playerId Player ID.
	 * @return array List of trait info arrays.
	 */
	public static function getTraitsOfPlayer(WebSoccer $websoccer, DbConnection $db, $playerId) {
		$traitsByPlayer = self::getTraitsOfPlayers($websoccer, $db, array((int) $playerId));
		return (isset($traitsByPlayer[$playerId])) ? $traitsByPlayer[$playerId] : array();
	}

	/**
	 * Returns active traits for multiple players.
	 *
	 * @param WebSoccer $websoccer Application context.
	 * @param DbConnection $db DB connection.
	 * @param array $playerIds Player IDs.
	 * @return array Traits indexed by player ID.
	 */
	public static function getTraitsOfPlayers(WebSoccer $websoccer, DbConnection $db, $playerIds) {
		$traits = array();
		$playerIds = self::_sanitizePlayerIds($playerIds);
		if (!count($playerIds) || !self::isInstalled($websoccer, $db)) {
			return $traits;
		}

		$missingPlayerIds = array();
		foreach ($playerIds as $playerId) {
			if (isset(self::$_traitsCache[$playerId])) {
				if (count(self::$_traitsCache[$playerId])) {
					$traits[$playerId] = self::$_traitsCache[$playerId];
				}
			} else {
				$missingPlayerIds[] = $playerId;
			}
		}

		if (!count($missingPlayerIds)) {
			return $traits;
		}

		foreach ($missingPlayerIds as $playerId) {
			self::$_traitsCache[$playerId] = array();
		}

		$definitions = self::getDefinitions();
		$fromTable = $websoccer->getConfig('db_prefix') . '_player_trait';
		$whereCondition = 'player_id IN (' . implode(',', $missingPlayerIds) . ') AND trait_value > 0 ORDER BY player_id ASC, trait_key ASC';
		$result = $db->querySelect('*', $fromTable, $whereCondition);
		while ($row = $result->fetch_array()) {
			$key = $row['trait_key'];
			if (!isset($definitions[$key])) {
				continue;
			}
			$value = max(0, min(3, (int) $row['trait_value']));
			if ($value < 1) {
				continue;
			}

			$definition = $definitions[$key];
			$playerId = (int) $row['player_id'];
			self::$_traitsCache[$playerId][] = array(
				'key' => $key,
				'value' => $value,
				'label_key' => $definition['label_key'],
				'category' => $definition['category'],
				'sort_order' => $definition['sort_order']
			);
		}
		$result->free();

		foreach ($missingPlayerIds as $playerId) {
			if (count(self::$_traitsCache[$playerId])) {
				usort(self::$_traitsCache[$playerId], array('PlayerTraitsDataService', '_sortTraits'));
				$traits[$playerId] = self::$_traitsCache[$playerId];
			}
		}

		return $traits;
	}

	/**
	 * Returns a compact key => value map for one player.
	 *
	 * @param WebSoccer $websoccer Application context.
	 * @param DbConnection $db DB connection.
	 * @param int $playerId Player ID.
	 * @return array Trait map.
	 */
	public static function getTraitMapOfPlayer(WebSoccer $websoccer, DbConnection $db, $playerId) {
		$map = array();
		foreach (self::getTraitsOfPlayer($websoccer, $db, (int) $playerId) as $trait) {
			$map[$trait['key']] = (int) $trait['value'];
		}
		return $map;
	}

	/**
	 * Adds trait data to each player array in a flat player list.
	 *
	 * @param WebSoccer $websoccer Application context.
	 * @param DbConnection $db DB connection.
	 * @param array $players Player rows indexed by player ID.
	 * @return array Player rows with traits key.
	 */
	public static function attachTraitsToPlayers(WebSoccer $websoccer, DbConnection $db, $players) {
		if (!is_array($players) || !count($players)) {
			return $players;
		}

		$playerIds = array();
		foreach ($players as $player) {
			if (isset($player['id'])) {
				$playerIds[] = (int) $player['id'];
			} elseif (isset($player['player_id'])) {
				$playerIds[] = (int) $player['player_id'];
			}
		}

		$traitsByPlayer = self::getTraitsOfPlayers($websoccer, $db, $playerIds);
		foreach ($players as $index => $player) {
			$playerId = isset($player['id']) ? (int) $player['id'] : (isset($player['player_id']) ? (int) $player['player_id'] : 0);
			$players[$index]['traits'] = (isset($traitsByPlayer[$playerId])) ? $traitsByPlayer[$playerId] : array();
			$players[$index]['traits_count'] = count($players[$index]['traits']);
		}

		return $players;
	}

	/**
	 * Checks whether player traits may be shown to the current user.
	 * Currently aligned with the existing personality visibility rule.
	 *
	 * @param WebSoccer $websoccer Application context.
	 * @param DbConnection $db DB connection.
	 * @param int $playerTeamId Team ID of player.
	 * @param array|null $scouting Existing scouting visibility context.
	 * @return bool TRUE if traits should be visible.
	 */
	public static function isVisibleForUser(WebSoccer $websoccer, DbConnection $db, $playerTeamId, $scouting = null) {
		if (!$websoccer->getUser()) {
			return FALSE;
		}

		$userTeamId = (int) $websoccer->getUser()->getClubId($websoccer, $db);
		if ($userTeamId > 0 && $userTeamId === (int) $playerTeamId) {
			return TRUE;
		}

		return is_array($scouting) && count($scouting) > 0;
	}

	/**
	 * Market value multiplier for player traits. This is intentionally capped and position-aware.
	 *
	 * @param WebSoccer $websoccer Application context.
	 * @param DbConnection $db DB connection.
	 * @param array $player Player row, usually from PlayersDataService::getPlayerById().
	 * @return float Multiplier, e.g. 1.08.
	 */
	public static function getMarketValueMultiplier(WebSoccer $websoccer, DbConnection $db, $player) {
		$traits = self::_getTraitMapFromPlayerArray($websoccer, $db, $player);
		if (!count($traits)) {
			return 1.0;
		}

		$position = isset($player['player_position']) ? $player['player_position'] : (isset($player['position']) ? $player['position'] : '');
		$mainPosition = isset($player['player_position_main']) ? $player['player_position_main'] : (isset($player['position_main']) ? $player['position_main'] : '');
		$bonus = 0.0;

		foreach ($traits as $key => $value) {
			$bonus += self::_getTraitMarketBonus($key, $value) * self::_getMarketRelevanceFactor($key, $position, $mainPosition);
		}

		$bonus = min(self::MARKET_VALUE_BONUS_CAP, max(0.0, $bonus));
		return 1.0 + $bonus;
	}

	/**
	 * Applies loaded trait values to a SimulationPlayer instance.
	 *
	 * @param WebSoccer $websoccer Application context.
	 * @param DbConnection $db DB connection.
	 * @param $player Simulation player.
	 */
	public static function applyTraitsToSimulationPlayer(WebSoccer $websoccer, DbConnection $db, $player) {
		if (!method_exists($player, 'setTraits')) {
			return;
		}
		$player->setTraits(self::getTraitMapOfPlayer($websoccer, $db, (int) $player->id));
	}

	/**
	 * Pass success bonus in probability points.
	 *
	 * @param $player Simulation player.
	 * @return int Probability point effect.
	 */
	public static function getPassSuccessEffect($player) {
		$effect = 0;
		$effect += self::_traitValue($player, 'spielmacher') * 2;
		$effect += self::_traitValue($player, 'ballzauberer');
		$effect += self::_traitValue($player, 'dribbler');
		$effect += self::_traitValue($player, 'flankenspezialist');
		return min(8, $effect);
	}

	/**
	 * Shooting action bonus in probability points.
	 *
	 * @param $player Simulation player.
	 * @return int Probability point effect.
	 */
	public static function getShootProbabilityEffect($player) {
		$effect = 0;
		if ($player->position == PLAYER_POSITION_STRIKER) {
			$effect += self::_traitValue($player, 'torinstinkt') * 2;
		} else {
			$effect += self::_traitValue($player, 'torinstinkt');
		}
		$effect += self::_traitValue($player, 'spielmacher');
		$effect += self::_traitValue($player, 'ballzauberer');
		return min(8, $effect);
	}

	/**
	 * Goal chance bonus/reduction in probability points for shots, set pieces and penalties.
	 *
	 * @param $player Acting player.
	 * @param string $context shot|freekick|penalty|corner_header|save_shot|save_penalty.
	 * @return int Probability point effect.
	 */
	public static function getGoalChanceEffect($player, $context) {
		$effect = 0;
		switch ($context) {
			case 'shot':
				$effect += self::_traitValue($player, 'torinstinkt') * 2;
				$effect += self::_traitValue($player, 'ballzauberer');
				$effect += self::_traitValue($player, 'dribbler');
				break;
			case 'freekick':
				$effect += self::_traitValue($player, 'freistossspezialist') * 3;
				break;
			case 'penalty':
				$effect += self::_traitValue($player, 'elfmeterschuetze') * 3;
				break;
			case 'corner_header':
				$effect += self::_traitValue($player, 'kopfballstaerke') * 2;
				$effect += self::_traitValue($player, 'torinstinkt');
				break;
			case 'save_shot':
				$effect -= self::_traitValue($player, 'reflexe') * 2;
				break;
			case 'save_penalty':
				$effect -= self::_traitValue($player, 'elfmetertoeter') * 3;
				break;
		}
		return max(-9, min(9, $effect));
	}

	/**
	 * Tackle duel probability effect for the player with the ball.
	 * Positive values help the ball carrier, negative values help the defender.
	 *
	 * @param $player Ball carrier.
	 * @param $opponent Defender.
	 * @return int Probability point effect for the ball carrier.
	 */
	public static function getTackleDuelEffect($player, $opponent) {
		$effect = 0;
		$effect += self::_traitValue($player, 'dribbler') * 2;
		$effect += self::_traitValue($player, 'ballzauberer');

		$effect -= self::_traitValue($opponent, 'viererkette') * 2;
		if ($opponent->position == PLAYER_POSITION_DEFENCE) {
			$effect -= self::_traitValue($opponent, 'kopfballstaerke');
		}
		return max(-8, min(8, $effect));
	}

	/**
	 * Extra corner-to-header scoring probability.
	 *
	 * @param $passingPlayer Corner taker.
	 * @param $targetPlayer Header target.
	 * @return int Probability point effect.
	 */
	public static function getCornerHeaderEffect($passingPlayer, $targetPlayer) {
		$effect = 0;
		$effect += self::_traitValue($passingPlayer, 'flankenspezialist') * 2;
		$effect += self::_traitValue($targetPlayer, 'kopfballstaerke') * 2;
		$effect += self::_traitValue($targetPlayer, 'torinstinkt');
		return min(10, $effect);
	}

	/**
	 * Returns whether freshness loss should be skipped this time due to running strength.
	 * It only affects the 20-minute freshness ticks and therefore remains moderate.
	 *
	 * @param $player Simulation player.
	 * @return bool TRUE if freshness loss should be skipped.
	 */
	public static function shouldSkipFreshnessLoss($player) {
		$value = self::_traitValue($player, 'laufstaerke');
		if ($value < 1) {
			return FALSE;
		}

		$chance = $value * 10;
		return SimulationHelper::getMagicNumber(1, 100) <= $chance;
	}


	/**
	 * Exposes the normalized trait map for any player array.
	 * This is used by squad planning and CPU transfers so all modules score traits consistently.
	 *
	 * @param WebSoccer $websoccer Application context.
	 * @param DbConnection $db DB connection.
	 * @param array $player Player row.
	 * @return array Trait map key => value.
	 */
	public static function getTraitMapFromPlayerArray(WebSoccer $websoccer, DbConnection $db, $player) {
		return self::_getTraitMapFromPlayerArray($websoccer, $db, $player);
	}

	/**
	 * Returns the label key for a trait.
	 *
	 * @param string $traitKey Trait key.
	 * @return string Message key or empty string.
	 */
	public static function getTraitLabelKey($traitKey) {
		$definitions = self::getDefinitions();
		return (isset($definitions[$traitKey])) ? $definitions[$traitKey]['label_key'] : '';
	}

	/**
	 * Recommended traits per squad position. Used for squad planner and CPU transfers.
	 *
	 * @param string $position General position.
	 * @return array Trait keys.
	 */
	public static function getRecommendedTraitsForPosition($position) {
		$position = self::_normalizePosition($position);
		switch ($position) {
			case 'goaly':
				return array('reflexe', 'elfmetertoeter');
			case 'defense':
				return array('viererkette', 'kopfballstaerke', 'laufstaerke');
			case 'midfield':
				return array('spielmacher', 'ballzauberer', 'flankenspezialist', 'freistossspezialist', 'laufstaerke');
			case 'striker':
				return array('torinstinkt', 'kopfballstaerke', 'dribbler', 'elfmeterschuetze');
		}
		return array();
	}

	/**
	 * Computes how well a player's trait map fits open trait needs.
	 * Needs may be indexed arrays of trait keys or squad-planner need rows.
	 *
	 * @param array $traitMap Player trait map key => value.
	 * @param array $traitNeeds Need rows or trait keys.
	 * @return int Score. 0 means no relevant trait match.
	 */
	public static function getTraitNeedScore($traitMap, $traitNeeds) {
		if (!is_array($traitMap) || !count($traitMap) || !is_array($traitNeeds) || !count($traitNeeds)) {
			return 0;
		}

		$score = 0;
		foreach ($traitNeeds as $need) {
			$traitKey = '';
			$priority = 1;
			if (is_array($need)) {
				$traitKey = isset($need['trait_key']) ? $need['trait_key'] : (isset($need['key']) ? $need['key'] : '');
				$priority = isset($need['priority']) ? max(1, (int) $need['priority']) : 1;
			} else {
				$traitKey = (string) $need;
			}
			if (!isset($traitMap[$traitKey])) {
				continue;
			}
			$value = max(0, min(3, (int) $traitMap[$traitKey]));
			if ($value < 1) {
				continue;
			}
			$score += ($value * $priority);
		}
		return (int) $score;
	}


	/**
	 * Returns trait-related match highlights for a team.
	 * This is intentionally based only on persisted match statistics, so stories stay reproducible.
	 *
	 * @param WebSoccer $websoccer Application context.
	 * @param DbConnection $db DB connection.
	 * @param I18n $i18n Translation service.
	 * @param int $matchId Match ID.
	 * @param int $teamId Team ID.
	 * @param int $limit Maximum number of highlights.
	 * @return array Highlight rows ordered by relevance.
	 */
	public static function getMatchTraitHighlights(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $matchId, $teamId, $limit = 3) {
		$matchId = (int) $matchId;
		$teamId = (int) $teamId;
		$limit = max(1, min(10, (int) $limit));
		if ($matchId < 1 || $teamId < 1 || !self::isInstalled($websoccer, $db)) {
			return array();
		}

		$prefix = $websoccer->getConfig('db_prefix');
		$fromTable = $prefix . '_spiel_berechnung AS S LEFT JOIN ' . $prefix . '_spieler AS P ON P.id = S.spieler_id';
		$columns = 'S.spieler_id AS player_id,S.name AS match_name,S.position,S.position_main,S.note,S.minuten_gespielt,S.tore,S.assists,S.freekicks,S.freekicks_successed,S.wontackles,S.losttackles,S.shoots,S.ballcontacts,S.passes_successed,S.passes_failed,P.vorname,P.nachname,P.kunstname';
		$result = $db->querySelect($columns, $fromTable, 'S.spiel_id = %d AND S.team_id = %d', array($matchId, $teamId));

		$players = array();
		$playerIds = array();
		while ($row = $result->fetch_array()) {
			$playerId = (int) $row['player_id'];
			if ($playerId < 1) {
				continue;
			}
			$row['player_name'] = self::_matchPlayerName($row);
			$players[$playerId] = $row;
			$playerIds[] = $playerId;
		}
		$result->free();

		if (!count($players)) {
			return array();
		}

		$traitsByPlayer = self::getTraitsOfPlayers($websoccer, $db, $playerIds);
		$highlights = array();
		foreach ($players as $playerId => $row) {
			if (!isset($traitsByPlayer[$playerId])) {
				continue;
			}
			foreach ($traitsByPlayer[$playerId] as $trait) {
				$score = self::_scoreMatchTraitHighlight($row, $trait['key'], (int) $trait['value']);
				if ($score < 20) {
					continue;
				}
				$label = self::_translateTraitLabel($i18n, $trait['key']);
				$reason = self::_buildMatchTraitReason($row, $trait['key']);
				$highlights[] = array(
					'player_id' => $playerId,
					'player_name' => $row['player_name'],
					'trait_key' => $trait['key'],
					'trait_label' => $label,
					'trait_value' => max(1, min(3, (int) $trait['value'])),
					'reason' => $reason,
					'score' => (int) $score,
					'note' => (float) $row['note'],
					'goals' => (int) $row['tore'],
					'assists' => (int) $row['assists'],
					'freekicks_successed' => (int) $row['freekicks_successed'],
					'wontackles' => (int) $row['wontackles'],
					'passes_successed' => (int) $row['passes_successed'],
					'minutes_played' => (int) $row['minuten_gespielt']
				);
			}
		}

		usort($highlights, array('PlayerTraitsDataService', '_sortMatchTraitHighlights'));
		return array_slice($highlights, 0, $limit);
	}

	/**
	 * Prevents frontend crashes while the SQL update has not been imported yet.
	 *
	 * @param WebSoccer $websoccer Application context.
	 * @param DbConnection $db DB connection.
	 * @return bool TRUE if table exists.
	 */
	public static function isInstalled(WebSoccer $websoccer, DbConnection $db) {
		if (self::$_installed !== null) {
			return self::$_installed;
		}

		$tableName = $websoccer->getConfig('db_prefix') . '_player_trait';
		try {
			$escaped = $db->connection->real_escape_string($tableName);
			$result = $db->executeQuery("SHOW TABLES LIKE '" . $escaped . "'");
			self::$_installed = ($result && $result->num_rows > 0);
			if ($result) {
				$result->free();
			}
		} catch (Exception $e) {
			self::$_installed = FALSE;
		}

		return self::$_installed;
	}


	/**
	 * Prevents frontend crashes while the youth trait SQL update has not been imported yet.
	 *
	 * @param WebSoccer $websoccer Application context.
	 * @param DbConnection $db DB connection.
	 * @return bool TRUE if youth trait table exists.
	 */
	public static function isYouthInstalled(WebSoccer $websoccer, DbConnection $db) {
		if (self::$_youthInstalled !== null) {
			return self::$_youthInstalled;
		}

		$tableName = $websoccer->getConfig('db_prefix') . '_youthplayer_trait';
		try {
			$escaped = $db->connection->real_escape_string($tableName);
			$result = $db->executeQuery("SHOW TABLES LIKE '" . $escaped . "'");
			self::$_youthInstalled = ($result && $result->num_rows > 0);
			if ($result) {
				$result->free();
			}
		} catch (Exception $e) {
			self::$_youthInstalled = FALSE;
		}

		return self::$_youthInstalled;
	}


	private static function _assignTraitsToTable(WebSoccer $websoccer, DbConnection $db, $tableName, $idColumn, $id, $traits) {
		$definitions = self::getDefinitions();
		$now = $websoccer->getNowAsTimestamp();
		foreach ($traits as $key => $value) {
			if (!isset($definitions[$key])) {
				continue;
			}
			$value = max(0, min(3, (int) $value));
			$db->queryDelete($tableName, $idColumn . ' = %d AND trait_key = \'%s\'', array((int) $id, $key));
			if ($value > 0) {
				$db->queryInsert(array(
					$idColumn => (int) $id,
					'trait_key' => $key,
					'trait_value' => $value,
					'created_date' => $now,
					'updated_date' => $now
				), $tableName);
			}
		}
	}

	private static function _skillValue($attributes, $key) {
		return (isset($attributes[$key])) ? (int) round($attributes[$key]) : 0;
	}

	private static function _traitValueFromSkill($skill, $talent, $quality) {
		$skill = (int) $skill;
		$talent = (int) $talent;
		$quality = (int) $quality;
		$chance = max(0, $skill - 58) + ($talent * 3) + (int) round(max(0, $quality - 50) / 5);
		if ($skill < 62 || mt_rand(1, 100) > max(3, min(65, $chance))) {
			return 0;
		}
		if ($skill >= 88 && $talent >= 5 && mt_rand(1, 100) <= 35) {
			return 3;
		}
		if ($skill >= 76 && mt_rand(1, 100) <= 45) {
			return 2;
		}
		return 1;
	}

	private static function _limitTraitMap($traits, $maxTraits) {
		if (!is_array($traits) || count($traits) <= $maxTraits) {
			return $traits;
		}
		arsort($traits);
		$limited = array();
		$count = 0;
		foreach ($traits as $key => $value) {
			$limited[$key] = $value;
			$count++;
			if ($count >= $maxTraits) {
				break;
			}
		}
		return $limited;
	}

	private static function _getYouthTraitPool($position, $focus) {
		if ($position == 'goaly') {
			return array('reflexe', 'elfmetertoeter');
		}

		if ($focus == 'technique') {
			return array('spielmacher', 'ballzauberer', 'dribbler', 'flankenspezialist', 'freistossspezialist');
		}
		if ($focus == 'physical') {
			return array('laufstaerke', 'kopfballstaerke', 'dribbler', 'viererkette');
		}
		if ($focus == 'mental') {
			return array('spielmacher', 'viererkette', 'elfmeterschuetze', 'laufstaerke');
		}

		if ($position == 'striker') {
			return array('torinstinkt', 'kopfballstaerke', 'dribbler', 'elfmeterschuetze');
		}
		if ($position == 'midfield') {
			return array('spielmacher', 'ballzauberer', 'flankenspezialist', 'laufstaerke', 'freistossspezialist');
		}
		return array('viererkette', 'kopfballstaerke', 'laufstaerke', 'flankenspezialist');
	}

	private static function _sanitizePlayerIds($playerIds) {
		$cleanIds = array();
		foreach ($playerIds as $playerId) {
			$playerId = (int) $playerId;
			if ($playerId > 0) {
				$cleanIds[$playerId] = $playerId;
			}
		}
		return array_values($cleanIds);
	}

	private static function _sortTraits($a, $b) {
		if ($a['sort_order'] == $b['sort_order']) {
			return strcmp($a['key'], $b['key']);
		}
		return ($a['sort_order'] < $b['sort_order']) ? -1 : 1;
	}


	private static function _sortMatchTraitHighlights($a, $b) {
		if ((int) $a['score'] !== (int) $b['score']) {
			return ((int) $a['score'] > (int) $b['score']) ? -1 : 1;
		}
		if ((float) $a['note'] == (float) $b['note']) {
			return 0;
		}
		return ((float) $a['note'] < (float) $b['note']) ? -1 : 1;
	}

	private static function _scoreMatchTraitHighlight($row, $traitKey, $traitValue) {
		$value = max(1, min(3, (int) $traitValue));
		$note = isset($row['note']) ? (float) $row['note'] : 6.0;
		$goals = isset($row['tore']) ? (int) $row['tore'] : 0;
		$assists = isset($row['assists']) ? (int) $row['assists'] : 0;
		$freekicksSuccessed = isset($row['freekicks_successed']) ? (int) $row['freekicks_successed'] : 0;
		$wonTackles = isset($row['wontackles']) ? (int) $row['wontackles'] : 0;
		$passes = isset($row['passes_successed']) ? (int) $row['passes_successed'] : 0;
		$ballcontacts = isset($row['ballcontacts']) ? (int) $row['ballcontacts'] : 0;
		$minutes = isset($row['minuten_gespielt']) ? (int) $row['minuten_gespielt'] : 0;
		$position = isset($row['position']) ? (string) $row['position'] : '';
		$score = 0;

		switch ($traitKey) {
			case 'torinstinkt':
				$score = ($goals >= 2) ? 45 + ($goals * 8) : (($goals >= 1 && $note <= 2.5) ? 28 : 0);
				break;
			case 'spielmacher':
				$score = ($assists >= 2) ? 44 + ($assists * 7) : (($assists >= 1 && $passes >= 10) ? 30 : (($passes >= 18 && $note <= 2.5) ? 24 : 0));
				break;
			case 'ballzauberer':
			case 'dribbler':
				$score = (($assists >= 1 || $goals >= 1) && $note <= 2.5) ? 32 : (($ballcontacts >= 25 && $wonTackles >= 4 && $note <= 2.8) ? 26 : 0);
				break;
			case 'flankenspezialist':
				$score = ($assists >= 1 && $passes >= 8) ? 32 + ($assists * 6) : 0;
				break;
			case 'kopfballstaerke':
				$score = ($goals >= 1 && ($position == 'Abwehr' || $position == 'Sturm')) ? 28 + ($goals * 6) : (($position == 'Abwehr' && $wonTackles >= 6 && $note <= 2.6) ? 24 : 0);
				break;
			case 'viererkette':
				$score = ($position == 'Abwehr' && $wonTackles >= 5 && $note <= 2.8) ? 26 + $wonTackles : 0;
				break;
			case 'laufstaerke':
				$score = ($minutes >= 85 && $note <= 2.6 && ($ballcontacts + $wonTackles) >= 20) ? 24 + (int) floor(($ballcontacts + $wonTackles) / 8) : 0;
				break;
			case 'freistossspezialist':
				$score = ($freekicksSuccessed >= 1) ? 48 + ($freekicksSuccessed * 8) : 0;
				break;
			case 'elfmeterschuetze':
				$score = ($goals >= 1 && $note <= 2.3) ? 22 : 0;
				break;
			case 'reflexe':
			case 'elfmetertoeter':
				$score = ($position == 'Torwart' && $note <= 2.0) ? 34 : 0;
				break;
		}

		if ($score < 1) {
			return 0;
		}
		$score += ($value - 1) * 5;
		if ($note > 0 && $note <= 1.5) {
			$score += 6;
		}
		return min(90, (int) $score);
	}

	private static function _buildMatchTraitReason($row, $traitKey) {
		$goals = isset($row['tore']) ? (int) $row['tore'] : 0;
		$assists = isset($row['assists']) ? (int) $row['assists'] : 0;
		$freekicksSuccessed = isset($row['freekicks_successed']) ? (int) $row['freekicks_successed'] : 0;
		$wonTackles = isset($row['wontackles']) ? (int) $row['wontackles'] : 0;
		$passes = isset($row['passes_successed']) ? (int) $row['passes_successed'] : 0;
		$minutes = isset($row['minuten_gespielt']) ? (int) $row['minuten_gespielt'] : 0;
		$note = isset($row['note']) ? (float) $row['note'] : 0.0;

		switch ($traitKey) {
			case 'torinstinkt':
				return ($goals >= 2) ? ($goals . ' Tore') : 'wichtiger Treffer';
			case 'spielmacher':
				return ($assists > 0) ? ($assists . ' Vorlagen und ' . $passes . ' erfolgreiche Pässe') : ($passes . ' erfolgreiche Pässe');
			case 'ballzauberer':
			case 'dribbler':
				return 'auffällige Offensivaktionen und Note ' . number_format($note, 2, ',', '.');
			case 'flankenspezialist':
				return ($assists > 0) ? ($assists . ' Vorlagen über außen') : 'gefährliche Zuspiele';
			case 'kopfballstaerke':
				return ($goals > 0) ? ($goals . ' Treffer und starke Luftduelle') : ($wonTackles . ' gewonnene Zweikämpfe');
			case 'viererkette':
				return $wonTackles . ' gewonnene Zweikämpfe in der Defensive';
			case 'laufstaerke':
				return $minutes . ' Minuten mit hoher Aktivität';
			case 'freistossspezialist':
				return ($freekicksSuccessed > 1) ? ($freekicksSuccessed . ' erfolgreiche Freistöße') : 'entscheidender Freistoß';
			case 'elfmeterschuetze':
				return 'sicherer Abschluss unter Druck';
			case 'reflexe':
				return 'starke Torwartleistung mit Note ' . number_format($note, 2, ',', '.');
			case 'elfmetertoeter':
				return 'nervenstarke Torwartleistung';
		}
		return 'auffällige Spezialfähigkeit im Spiel';
	}

	private static function _translateTraitLabel(I18n $i18n, $traitKey) {
		$labelKey = self::getTraitLabelKey($traitKey);
		if (strlen($labelKey) && $i18n->hasMessage($labelKey)) {
			return $i18n->getMessage($labelKey);
		}
		return $traitKey;
	}

	private static function _matchPlayerName($row) {
		if (isset($row['kunstname']) && strlen(trim((string) $row['kunstname']))) {
			return trim((string) $row['kunstname']);
		}
		$name = trim((isset($row['vorname']) ? $row['vorname'] : '') . ' ' . (isset($row['nachname']) ? $row['nachname'] : ''));
		if (strlen($name)) {
			return $name;
		}
		return isset($row['match_name']) ? (string) $row['match_name'] : '';
	}

	private static function _getTraitMapFromPlayerArray(WebSoccer $websoccer, DbConnection $db, $player) {
		if (isset($player['traits']) && is_array($player['traits'])) {
			$map = array();
			foreach ($player['traits'] as $trait) {
				if (isset($trait['key']) && isset($trait['value'])) {
					$map[$trait['key']] = (int) $trait['value'];
				}
			}
			return $map;
		}

		$playerId = 0;
		if (isset($player['player_id'])) {
			$playerId = (int) $player['player_id'];
		} elseif (isset($player['id'])) {
			$playerId = (int) $player['id'];
		}
		return ($playerId > 0) ? self::getTraitMapOfPlayer($websoccer, $db, $playerId) : array();
	}

	private static function _traitValue($player, $traitKey) {
		if (method_exists($player, 'getTraitValue')) {
			return (int) $player->getTraitValue($traitKey);
		}
		return 0;
	}

	private static function _getTraitMarketBonus($key, $value) {
		$value = max(0, min(3, (int) $value));
		$bonusPerValue = array(0 => 0.0, 1 => 0.02, 2 => 0.05, 3 => 0.09);
		return isset($bonusPerValue[$value]) ? $bonusPerValue[$value] : 0.0;
	}

	private static function _getMarketRelevanceFactor($key, $position, $mainPosition) {
		$position = self::_normalizePosition($position);
		$mainPosition = strtoupper((string) $mainPosition);

		switch ($key) {
			case 'torinstinkt':
				return ($position == 'striker') ? 1.0 : (($position == 'midfield' && in_array($mainPosition, array('OM', 'LM', 'RM'))) ? 0.7 : 0.35);
			case 'spielmacher':
				return ($position == 'midfield') ? 1.0 : (($position == 'defense') ? 0.45 : 0.55);
			case 'ballzauberer':
			case 'dribbler':
				return ($position == 'midfield' || $position == 'striker') ? 1.0 : (($position == 'defense') ? 0.35 : 0.0);
			case 'flankenspezialist':
				return in_array($mainPosition, array('LV', 'RV', 'LM', 'RM', 'LS', 'RS')) ? 1.0 : (($position == 'midfield' || $position == 'defense') ? 0.55 : 0.35);
			case 'kopfballstaerke':
				return ($position == 'defense' || $position == 'striker') ? 1.0 : (($position == 'midfield') ? 0.5 : 0.0);
			case 'viererkette':
				return ($position == 'defense') ? 1.0 : 0.0;
			case 'laufstaerke':
				return ($position == 'goaly') ? 0.0 : 0.75;
			case 'freistossspezialist':
			case 'elfmeterschuetze':
				return ($position == 'goaly') ? 0.0 : 0.75;
			case 'elfmetertoeter':
			case 'reflexe':
				return ($position == 'goaly') ? 1.0 : 0.0;
		}

		return 0.0;
	}

	private static function _normalizePosition($position) {
		switch ($position) {
			case 'Torwart':
				return 'goaly';
			case 'Abwehr':
				return 'defense';
			case 'Mittelfeld':
				return 'midfield';
			case 'Sturm':
				return 'striker';
		}
		return (string) $position;
	}
}

?>
