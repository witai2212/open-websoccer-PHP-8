<?php
/******************************************************

  This file is part of OpenWebSoccer-Sim.

  OpenWebSoccer-Sim is free software: you can redistribute it
  and/or modify it under the terms of the
  GNU Lesser General Public License
  as published by the Free Software Foundation, either version 3 of
  the License, or any later version.

******************************************************/

/**
 * Data service for automatic youth match formations.
 *
 * Youth players only have a general position and strength. Therefore the
 * automatic setup supports three manual strategies:
 * development, balanced and winning.
 */
class YouthFormationDataService {

	public static function getDefaultSetup() {
		return array(
				'defense' => 4,
				'dm' => 1,
				'midfield' => 3,
				'om' => 1,
				'striker' => 1,
				'outsideforward' => 0
				);
	}

	public static function getAvailableStrategies() {
		return array(
				'development' => TRUE,
				'balanced' => TRUE,
				'winning' => TRUE
				);
	}

	public static function isValidStrategy($strategy) {
		$strategies = self::getAvailableStrategies();
		return isset($strategies[$strategy]);
	}

	public static function normalizeStrategy($strategy) {
		return self::isValidStrategy($strategy) ? $strategy : 'balanced';
	}

	public static function normalizeSetup($setup) {
		$normalized = self::getDefaultSetup();
		foreach ($normalized as $key => $defaultValue) {
			if (isset($setup[$key])) {
				$normalized[$key] = (int) $setup[$key];
			}
		}

		if ($normalized['outsideforward'] != 2) {
			$normalized['outsideforward'] = 0;
		}

		$altered = FALSE;
		while (($noOfPlayers = $normalized['defense'] + $normalized['dm'] + $normalized['midfield'] + $normalized['om'] + $normalized['striker'] + $normalized['outsideforward']) != 10) {
			if ($noOfPlayers > 10) {
				if ($normalized['striker'] > 1) {
					$normalized['striker'] = $normalized['striker'] - 1;
				} elseif ($normalized['outsideforward'] > 0) {
					$normalized['outsideforward'] = 0;
				} elseif ($normalized['om'] > 1) {
					$normalized['om'] = $normalized['om'] - 1;
				} elseif ($normalized['dm'] > 1) {
					$normalized['dm'] = $normalized['dm'] - 1;
				} elseif ($normalized['midfield'] > 2) {
					$normalized['midfield'] = $normalized['midfield'] - 1;
				} else {
					$normalized['defense'] = $normalized['defense'] - 1;
				}
			} else {
				if ($normalized['defense'] < 4) {
					$normalized['defense'] = $normalized['defense'] + 1;
				} else if ($normalized['midfield'] < 4) {
					$normalized['midfield'] = $normalized['midfield'] + 1;
				} else if ($normalized['dm'] < 2) {
					$normalized['dm'] = $normalized['dm'] + 1;
				} else if ($normalized['om'] < 2) {
					$normalized['om'] = $normalized['om'] + 1;
				} else {
					$normalized['striker'] = $normalized['striker'] + 1;
				}
			}

			$altered = TRUE;
		}

		$normalized['_altered'] = $altered;
		return $normalized;
	}

	public static function setupToString($setup) {
		return (int) $setup['defense'] . '-' . (int) $setup['dm'] . '-' . (int) $setup['midfield'] . '-' . (int) $setup['om'] . '-' . (int) $setup['striker'] . '-' . (int) $setup['outsideforward'];
	}

	public static function getPositionMapping() {
		return array(
				'T' => 'Torwart',
				'LV' => 'Abwehr',
				'IV' => 'Abwehr',
				'RV' => 'Abwehr',
				'DM' => 'Mittelfeld',
				'OM' => 'Mittelfeld',
				'ZM' => 'Mittelfeld',
				'LM' => 'Mittelfeld',
				'RM' => 'Mittelfeld',
				'LS' => 'Sturm',
				'MS' => 'Sturm',
				'RS' => 'Sturm'
				);
	}

	public static function getMainPositionsForSetup($setup) {
		$setup = self::normalizeSetup($setup);
		$positions = array('T');

		if ($setup['defense'] < 4) {
			for ($i = 0; $i < $setup['defense']; $i++) {
				$positions[] = 'IV';
			}
		} else {
			$positions[] = 'LV';
			for ($i = 0; $i < $setup['defense'] - 2; $i++) {
				$positions[] = 'IV';
			}
			$positions[] = 'RV';
		}

		for ($i = 0; $i < $setup['dm']; $i++) {
			$positions[] = 'DM';
		}

		if ($setup['midfield'] == 1) {
			$positions[] = 'ZM';
		} else if ($setup['midfield'] == 2) {
			$positions[] = 'LM';
			$positions[] = 'RM';
		} else if ($setup['midfield'] == 3) {
			$positions[] = 'LM';
			$positions[] = 'ZM';
			$positions[] = 'RM';
		} else if ($setup['midfield'] >= 4) {
			$positions[] = 'LM';
			for ($i = 0; $i < $setup['midfield'] - 2; $i++) {
				$positions[] = 'ZM';
			}
			$positions[] = 'RM';
		}

		for ($i = 0; $i < $setup['om']; $i++) {
			$positions[] = 'OM';
		}

		if ($setup['striker'] == 1) {
			$positions[] = 'MS';
		} else if ($setup['striker'] == 2) {
			$positions[] = 'MS';
			$positions[] = 'MS';
		}

		if ($setup['outsideforward'] == 2) {
			$positions[] = 'LS';
			$positions[] = 'RS';
		}

		return array_slice($positions, 0, 11);
	}

	public static function getFormationProposalForTeamId(WebSoccer $websoccer, DbConnection $db, $teamId, $setup, $strategy = 'balanced') {
		$strategy = self::normalizeStrategy($strategy);
		$setup = self::normalizeSetup($setup);
		$mainPositions = self::getMainPositionsForSetup($setup);
		$positionMapping = self::getPositionMapping();

		$minAge = (int) $websoccer->getConfig('youth_scouting_min_age');
		if ($minAge <= 0) {
			$minAge = 14;
		}

		$players = self::getEligibleYouthPlayers($websoccer, $db, $teamId, $minAge, $strategy);
		$usedPlayerIds = array();
		$starters = array();

		foreach ($mainPositions as $mainPosition) {
			$requiredPosition = $positionMapping[$mainPosition];
			$player = self::pickBestPlayer($players, $usedPlayerIds, $requiredPosition, TRUE);
			if (!$player) {
				$player = self::pickBestPlayer($players, $usedPlayerIds, $requiredPosition, FALSE);
			}

			if ($player) {
				$usedPlayerIds[$player['id']] = TRUE;
				$starters[] = array(
						'id' => $player['id'],
						'position' => $mainPosition,
						'general_position' => $requiredPosition,
						'player' => $player
						);
			}
		}

		$bench = array();
		for ($benchNo = 1; $benchNo <= 5; $benchNo++) {
			$player = self::pickBestPlayer($players, $usedPlayerIds, NULL, FALSE);
			if (!$player) {
				break;
			}
			$usedPlayerIds[$player['id']] = TRUE;
			$bench[] = array(
					'id' => $player['id'],
					'position' => '-',
					'general_position' => $player['position'],
					'player' => $player
					);
		}

		return array(
				'setup' => $setup,
				'strategy' => $strategy,
				'players' => $starters,
				'bench' => $bench,
				'substitutions' => self::getAutomaticSubstitutions($starters, $bench, $strategy)
				);
	}

	private static function getEligibleYouthPlayers(WebSoccer $websoccer, DbConnection $db, $teamId, $minAge, $strategy) {
		$columns = 'id, firstname, lastname, position, age, strength, st_matches';
		$fromTable = $websoccer->getConfig('db_prefix') . '_youthplayer';
		$whereCondition = 'team_id = %d AND age >= %d ORDER BY strength DESC, age ASC, lastname ASC, firstname ASC';
		$result = $db->querySelect($columns, $fromTable, $whereCondition, array((int) $teamId, (int) $minAge));

		$players = array();
		while ($player = $result->fetch_array()) {
			$player['score'] = self::getStrategyScore($player, $strategy);
			$players[] = $player;
		}
		$result->free();

		usort($players, array('YouthFormationDataService', 'sortByScore'));
		return $players;
	}

	private static function getStrategyScore($player, $strategy) {
		$strength = isset($player['strength']) ? (int) $player['strength'] : 0;
		$age = isset($player['age']) ? (int) $player['age'] : 18;
		$matches = isset($player['st_matches']) ? (int) $player['st_matches'] : 0;

		if ($strategy == 'development') {
			$youngerBonus = max(0, 18 - $age) * 8;
			$matchPracticeBonus = max(0, 10 - min(10, $matches)) * 2;
			return $strength + $youngerBonus + $matchPracticeBonus;
		}

		if ($strategy == 'winning') {
			$maturityBonus = max(0, min(5, $age - 14)) * 2;
			return ($strength * 4) + $maturityBonus;
		}

		$developmentBonus = max(0, 18 - $age) * 3;
		return ($strength * 2) + $developmentBonus;
	}

	private static function sortByScore($a, $b) {
		if ($a['score'] == $b['score']) {
			if ((int) $a['strength'] == (int) $b['strength']) {
				if ((int) $a['age'] == (int) $b['age']) {
					return strcmp($a['lastname'] . $a['firstname'], $b['lastname'] . $b['firstname']);
				}
				return (int) $a['age'] - (int) $b['age'];
			}
			return (int) $b['strength'] - (int) $a['strength'];
		}
		return (int) $b['score'] - (int) $a['score'];
	}

	private static function pickBestPlayer($players, $usedPlayerIds, $requiredPosition = NULL, $exactPositionOnly = FALSE) {
		foreach ($players as $player) {
			if (isset($usedPlayerIds[$player['id']])) {
				continue;
			}

			if ($requiredPosition !== NULL && $exactPositionOnly && $player['position'] != $requiredPosition) {
				continue;
			}

			return $player;
		}

		return NULL;
	}

	public static function getAutomaticSubstitutions($starters, $bench, $strategy = 'balanced') {
		$strategy = self::normalizeStrategy($strategy);
		$substitutions = array();
		$usedOutPlayerIds = array();
		$minutes = self::getSubstitutionMinutes($strategy);

		for ($subNo = 0; $subNo < min(3, count($bench)); $subNo++) {
			$benchPlayer = $bench[$subNo];
			$outPlayer = self::findBestSubstitutionOutPlayer($starters, $usedOutPlayerIds, $benchPlayer['general_position'], $strategy, $benchPlayer['player']);
			if (!$outPlayer && $strategy != 'winning') {
				$outPlayer = self::findBestSubstitutionOutPlayer($starters, $usedOutPlayerIds, NULL, $strategy, $benchPlayer['player']);
			}

			if (!$outPlayer) {
				continue;
			}

			$usedOutPlayerIds[$outPlayer['id']] = TRUE;
			$substitutions[] = array(
					'out' => $outPlayer['id'],
					'in' => $benchPlayer['id'],
					'minute' => $minutes[$subNo],
					'condition' => '',
					'position' => $outPlayer['position']
					);
		}

		return $substitutions;
	}

	private static function getSubstitutionMinutes($strategy) {
		if ($strategy == 'development') {
			return array(55, 65, 75);
		}

		if ($strategy == 'winning') {
			return array(75, 82, 88);
		}

		return array(60, 70, 80);
	}

	private static function findBestSubstitutionOutPlayer($starters, $usedOutPlayerIds, $generalPosition = NULL, $strategy = 'balanced', $benchPlayer = NULL) {
		$selected = NULL;
		foreach ($starters as $starter) {
			if (isset($usedOutPlayerIds[$starter['id']])) {
				continue;
			}

			if ($generalPosition !== NULL && $starter['general_position'] != $generalPosition) {
				continue;
			}

			if ($strategy == 'winning' && $benchPlayer !== NULL && (int) $starter['player']['strength'] > (int) $benchPlayer['strength']) {
				continue;
			}

			if ($selected === NULL || self::isBetterOutPlayer($starter, $selected, $strategy)) {
				$selected = $starter;
			}
		}

		return $selected;
	}

	private static function isBetterOutPlayer($candidate, $selected, $strategy) {
		if ($strategy == 'winning') {
			if ((int) $candidate['player']['strength'] == (int) $selected['player']['strength']) {
				return (int) $candidate['player']['score'] < (int) $selected['player']['score'];
			}
			return (int) $candidate['player']['strength'] < (int) $selected['player']['strength'];
		}

		return (int) $candidate['player']['score'] < (int) $selected['player']['score'];
	}
}

?>
