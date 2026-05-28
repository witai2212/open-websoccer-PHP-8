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
 * Data service for watchlist
 */
class WatchlistDataService {
	
	/**
	 * Provides list of players on mywatchlist
	 * 
	 * @param WebSoccer $websoccer Application context.
	 * @param DbConnection $db DB connection.
	 * @param int $teamId ID of team
	 * @return array of players on watchlist which belongs to the specified team.
	 */
    public static function getMyWatchlist(WebSoccer $websoccer, DbConnection $db, $teamId) {
        
        // Remove players from watchlist who are already in my own team
        self::removeOwnPlayersFromWatchlist($websoccer, $db, $teamId);

        $recommendationContext = self::getScoutRecommendationContext($websoccer, $db, $teamId);
        
        $queryString = "SELECT wl.*, s.*, v.id, v.bild
                FROM ". $websoccer->getConfig('db_prefix') ."_watchlist AS wl,
                    ". $websoccer->getConfig('db_prefix') ."_spieler AS s, 
                    ". $websoccer->getConfig('db_prefix') ."_verein AS v
                WHERE wl.verein_id='$teamId' AND s.id=wl.spieler_id AND v.id=s.verein_id";
        $result = $db->executeQuery($queryString);
        
        $i=0;
        $players = array();
        while ($player = $result->fetch_array()) {
            
            $players[$i] = $player;
            
            //GET PLAYERS TEAM DATA
            $playerTeam = TeamsDataService::getTeamById($websoccer, $db, $player['verein_id']);
            $players[$i]['team_name'] = $playerTeam['team_name'];
            $players[$i]['team_country'] = $playerTeam['team_country'];
			
			//GET CHECK if on Player has an offer
			$hasOffer = self:: checkIfPLayerHasOffer($websoccer, $db, $player['spieler_id'], $teamId);
			$players[$i]['hasoffer'] = $hasOffer;

			// Dynamic scout recommendation for this watchlist entry.
			// Hidden values such as w_talent and w_staerke_max are intentionally not used here.
			$players[$i]['scout_recommendation'] = self::calculateScoutRecommendation($recommendationContext, $player);
			
            $i++;
        }
        $result->free();
        
        return $players;
    }
    
    /**
     * Check if playerId is on watchlist
     *
     * @param WebSoccer $websoccer Application context.
     * @param DbConnection $db DB connection.
     * @param int $playerId ID of player and teamId of team
     * @return boolean if player in watchlist.
     */
    public static function checkIfPlayerOnWatchlist(WebSoccer $websoccer, DbConnection $db, $playerId, $teamId) {
        
        $onList = 0;
        
        $queryString = "SELECT * FROM ". $websoccer->getConfig('db_prefix') ."_watchlist
                            WHERE verein_id='$teamId' AND spieler_id='$playerId'";
        $result = $db->executeQuery($queryString);
        $wl = $result->fetch_array();
        $result->free();
        
        if($wl['id']>0) {
            $onList = 1;
        }
        return $onList;
    }
	
	public static function checkIfPLayerHasOffer(WebSoccer $websoccer, DbConnection $db, $playerId, $teamId) {
		
		$bid = 0;
        
        $queryString = "SELECT * FROM ". $websoccer->getConfig('db_prefix') ."_transfer_angebot
                            WHERE verein_id='$teamId' AND spieler_id='$playerId'";
        $result = $db->executeQuery($queryString);
        $tl = $result->fetch_array();
        $result->free();
        
        if($tl['id']>0) {
            $bid = 1;
        }
        return $bid;
		
		
	}
	

	/**
	 * Builds all data which is needed for the dynamic scout recommendation.
	 * The recommendation is only available if the club has an active scouting
	 * department and at least one active scout. A recommendation is only
	 * calculated for player positions covered by a matching scout speciality.
	 *
	 * @param WebSoccer $websoccer Application context.
	 * @param DbConnection $db DB connection.
	 * @param int $teamId ID of team.
	 * @return array recommendation context.
	 */
	private static function getScoutRecommendationContext(WebSoccer $websoccer, DbConnection $db, $teamId) {
		$prefix = $websoccer->getConfig('db_prefix');

		$context = array(
			'available' => FALSE,
			'scouts' => array(),
			'scout_specialities' => array(),
			'scout_quality' => 0,
			'active_camp_count' => 0,
			'active_camp_positions' => array(),
			'department_level' => 0,
			'team' => array(),
			'squad' => array(),
			'squad_average_strength' => 0,
			'position_average_strength' => array(),
			'position_count' => array(),
			'nation_count' => array(),
			'top_nation' => ''
		);

		$result = $db->querySelect('*', $prefix . '_scout', 'team_id = %d AND team_matches > 0', $teamId);
		$scoutExpertiseTotal = 0;
		$scoutCount = 0;
		while ($scout = $result->fetch_array()) {
			$context['scouts'][] = $scout;
			$scoutCount++;
			$scoutExpertiseTotal += self::toFloat($scout['expertise']);
			if (isset($scout['speciality']) && strlen($scout['speciality'])) {
				$context['scout_specialities'][$scout['speciality']] = TRUE;
			}
		}
		$result->free();

		if ($scoutCount > 0) {
			$context['scout_quality'] = round($scoutExpertiseTotal / $scoutCount, 2);
		}

		$result = $db->querySelect('level', $prefix . '_scouting_department', 'team_id = %d AND status = \'1\'', $teamId, 1);
		$department = $result->fetch_array();
		$result->free();
		if ($department && isset($department['level'])) {
			$context['department_level'] = (int) $department['level'];
		}

		$result = $db->querySelect('id, position', $prefix . '_scouting_camp', 'team_id = %d AND status = \'1\'', $teamId);
		$activeCampCount = 0;
		while ($camp = $result->fetch_array()) {
			$activeCampCount++;
			$campPosition = (isset($camp['position'])) ? trim($camp['position']) : '';
			$context['active_camp_positions'][$campPosition] = TRUE;
		}
		$result->free();
		$context['active_camp_count'] = $activeCampCount;

		$result = $db->querySelect(
			'id, finanz_budget, strength, team_chemistry, tactical_style, tactical_style_fit',
			$prefix . '_verein',
			'id = %d',
			$teamId,
			1
		);
		$team = $result->fetch_array();
		$result->free();
		if ($team) {
			$context['team'] = $team;
		}

		$result = $db->querySelect(
			'id, position, position_main, nation, personality, w_staerke, w_technik, w_kondition, w_frische, w_zufriedenheit, w_passing, w_shooting, w_heading, w_tackling, w_freekick, w_pace, w_creativity, w_influence, w_flair, w_penalty, w_penalty_killing, vertrag_gehalt',
			$prefix . '_spieler',
			'verein_id = %d AND status = \'1\'',
			$teamId
		);

		$strengthTotal = 0;
		$squadCount = 0;
		$positionStrengthTotal = array();

		while ($squadPlayer = $result->fetch_array()) {
			$context['squad'][] = $squadPlayer;
			$squadCount++;

			$strength = self::toFloat($squadPlayer['w_staerke']);
			$strengthTotal += $strength;

			$position = $squadPlayer['position'];
			if (!isset($context['position_count'][$position])) {
				$context['position_count'][$position] = 0;
				$positionStrengthTotal[$position] = 0;
			}
			$context['position_count'][$position]++;
			$positionStrengthTotal[$position] += $strength;

			$nation = (isset($squadPlayer['nation'])) ? $squadPlayer['nation'] : '';
			if (strlen($nation)) {
				if (!isset($context['nation_count'][$nation])) {
					$context['nation_count'][$nation] = 0;
				}
				$context['nation_count'][$nation]++;
			}
		}
		$result->free();

		if ($squadCount > 0) {
			$context['squad_average_strength'] = round($strengthTotal / $squadCount, 2);
		}

		foreach ($positionStrengthTotal as $position => $positionTotal) {
			$count = $context['position_count'][$position];
			$context['position_average_strength'][$position] = ($count > 0) ? round($positionTotal / $count, 2) : 0;
		}

		$topNationCount = 0;
		foreach ($context['nation_count'] as $nation => $count) {
			if ($count > $topNationCount) {
				$topNationCount = $count;
				$context['top_nation'] = $nation;
			}
		}

		$context['available'] = ($context['department_level'] > 0 && $scoutCount > 0);

		return $context;
	}

	/**
	 * Calculates the dynamic scout recommendation for one watchlist player.
	 * No hidden player values are considered.
	 *
	 * @param array $context recommendation context.
	 * @param array $player watchlist player row.
	 * @return array recommendation result for Twig.
	 */
	private static function calculateScoutRecommendation($context, $player) {
		$position = isset($player['position']) ? $player['position'] : '';

		if (!$context['available']) {
			return array(
				'available' => FALSE,
				'label_key' => 'mywatchlist_scout_no_data',
				'label' => 'Keine Scout-Daten',
				'score' => 0,
				'css_class' => '',
				'reason' => 'Für eine Empfehlung werden eine Scouting-Abteilung und mindestens ein aktiver Scout benötigt.'
			);
		}

		if (!isset($context['scout_specialities'][$position])) {
			return array(
				'available' => FALSE,
				'label_key' => 'mywatchlist_scout_no_matching_position',
				'label' => 'Kein passender Scout',
				'score' => 0,
				'css_class' => '',
				'reason' => 'Kein passender Scout für diese Position vorhanden.'
			);
		}

		$score = 50;
		$positiveReasons = array();
		$negativeReasons = array();
		$strength = self::toFloat($player['w_staerke']);
		$squadAverage = self::toFloat($context['squad_average_strength']);
		$positionAverage = (isset($context['position_average_strength'][$position])) ? self::toFloat($context['position_average_strength'][$position]) : $squadAverage;
		$positionCount = (isset($context['position_count'][$position])) ? (int) $context['position_count'][$position] : 0;

		$minimumPositionCount = self::getMinimumPositionCount($position);
		if ($positionCount < $minimumPositionCount) {
			$score += 12;
			$positiveReasons[] = 'Kaderbedarf auf dieser Position';
		}

		if ($strength >= ($positionAverage + 5)) {
			$score += 12;
			$positiveReasons[] = 'stärkt die Position deutlich';
		} elseif ($strength >= ($positionAverage + 2)) {
			$score += 6;
			$positiveReasons[] = 'leicht stärker als der Positionsschnitt';
		} elseif ($strength <= ($positionAverage - 5)) {
			$score -= 10;
			$negativeReasons[] = 'unter dem Positionsschnitt';
		}

		if ($squadAverage > 0 && $strength >= ($squadAverage + 4)) {
			$score += 6;
			$positiveReasons[] = 'hebt die Kaderstärke';
		} elseif ($squadAverage > 0 && $strength <= ($squadAverage - 6)) {
			$score -= 6;
			$negativeReasons[] = 'senkt die Kaderqualität';
		}

		$positiveReasons[] = 'passender Scout für diese Position vorhanden';

		if (isset($context['active_camp_positions'][$position]) || isset($context['active_camp_positions'][''])) {
			$score += 3;
			$positiveReasons[] = 'durch passendes Scouting-Camp beobachtbar';
		}

		$tacticalFit = self::calculateTacticalFit($context, $player);
		$score += $tacticalFit['score_change'];
		if (strlen($tacticalFit['positive_reason'])) {
			$positiveReasons[] = $tacticalFit['positive_reason'];
		}
		if (strlen($tacticalFit['negative_reason'])) {
			$negativeReasons[] = $tacticalFit['negative_reason'];
		}

		$chemistryFit = self::calculateChemistryFit($context, $player);
		$score += $chemistryFit['score_change'];
		foreach ($chemistryFit['positive_reasons'] as $reason) {
			$positiveReasons[] = $reason;
		}
		foreach ($chemistryFit['negative_reasons'] as $reason) {
			$negativeReasons[] = $reason;
		}

		$financialFit = self::calculateFinancialFit($context, $player);
		$score += $financialFit['score_change'];
		if (strlen($financialFit['positive_reason'])) {
			$positiveReasons[] = $financialFit['positive_reason'];
		}
		if (strlen($financialFit['negative_reason'])) {
			$negativeReasons[] = $financialFit['negative_reason'];
		}

		if (isset($player['transfermarkt']) && $player['transfermarkt'] == '1') {
			$score += 4;
			$positiveReasons[] = 'aktuell auf dem Transfermarkt';
		}

		$age = (isset($player['age'])) ? (int) $player['age'] : 0;
		if ($age > 0 && $age <= 23) {
			$score += 5;
			$positiveReasons[] = 'jung genug für Entwicklung';
		} elseif ($age >= 33) {
			$score -= 5;
			$negativeReasons[] = 'kurzfristige Lösung wegen Alter';
		}

		$score = max(0, min(100, (int) round($score)));

		if ($score >= 70) {
			$labelKey = 'mywatchlist_scout_recommend_yes';
			$label = 'Empfohlen';
			$cssClass = 'label-success';
		} elseif ($score >= 45) {
			$labelKey = 'mywatchlist_scout_recommend_watch';
			$label = 'Beobachten';
			$cssClass = 'label-warning';
		} else {
			$labelKey = 'mywatchlist_scout_recommend_no';
			$label = 'Nicht empfohlen';
			$cssClass = 'label-important';
		}

		$reason = self::buildRecommendationReason($positiveReasons, $negativeReasons);

		return array(
			'available' => TRUE,
			'label_key' => $labelKey,
			'label' => $label,
			'score' => $score,
			'css_class' => $cssClass,
			'reason' => $reason
		);
	}

	/**
	 * @param string $position Main position group.
	 * @return int minimum useful squad depth for this position group.
	 */
	private static function getMinimumPositionCount($position) {
		switch ($position) {
			case 'Torwart':
				return 2;
			case 'Abwehr':
				return 6;
			case 'Mittelfeld':
				return 6;
			case 'Sturm':
				return 4;
			default:
				return 3;
		}
	}

	/**
	 * @param array $context recommendation context.
	 * @param array $player watchlist player row.
	 * @return array score change and reasons.
	 */
	private static function calculateTacticalFit($context, $player) {
		$style = '';
		if (isset($context['team']['tactical_style'])) {
			$style = strtolower(trim($context['team']['tactical_style']));
		}

		if (!strlen($style)) {
			return array('score_change' => 0, 'positive_reason' => '', 'negative_reason' => '');
		}

		$fit = 50;
		switch ($style) {
			case 'possession':
			case 'ballbesitz':
				$fit = self::averageVisibleValues($player, array('w_passing', 'w_technik', 'w_creativity'));
				break;
			case 'counterattack':
			case 'counter_attack':
			case 'konter':
				$fit = self::averageVisibleValues($player, array('w_pace', 'w_passing', 'w_shooting'));
				break;
			case 'pressing':
				$fit = self::averageVisibleValues($player, array('w_pace', 'w_tackling', 'w_kondition'));
				break;
			case 'defensive_block':
			case 'defensivblock':
				$fit = self::averageVisibleValues($player, array('w_tackling', 'w_heading', 'w_kondition'));
				break;
			case 'wing_play':
			case 'fluegelspiel':
			case 'flügelspiel':
				$fit = self::averageVisibleValues($player, array('w_pace', 'w_passing', 'w_flair'));
				break;
			case 'set_pieces':
			case 'standards':
				$fit = self::averageVisibleValues($player, array('w_freekick', 'w_heading', 'w_penalty'));
				break;
			case 'youth_focus':
			case 'jugendfokus':
				$age = (isset($player['age'])) ? (int) $player['age'] : 30;
				if ($age <= 21) {
					$fit = 80;
				} elseif ($age <= 24) {
					$fit = 65;
				} elseif ($age <= 28) {
					$fit = 50;
				} else {
					$fit = 35;
				}
				break;
			case 'physical':
			case 'physical_style':
			case 'koerperbetont':
			case 'körperbetont':
				$fit = self::averageVisibleValues($player, array('w_kondition', 'w_heading', 'w_tackling'));
				break;
		}

		if ($fit >= 70) {
			return array('score_change' => 14, 'positive_reason' => 'passt sehr gut zur Teamphilosophie', 'negative_reason' => '');
		}
		if ($fit >= 60) {
			return array('score_change' => 8, 'positive_reason' => 'passt zur Teamphilosophie', 'negative_reason' => '');
		}
		if ($fit < 45) {
			return array('score_change' => -8, 'positive_reason' => '', 'negative_reason' => 'schwacher Fit zur Teamphilosophie');
		}

		return array('score_change' => 0, 'positive_reason' => '', 'negative_reason' => '');
	}

	/**
	 * @param array $context recommendation context.
	 * @param array $player watchlist player row.
	 * @return array score change and reasons.
	 */
	private static function calculateChemistryFit($context, $player) {
		$scoreChange = 0;
		$positiveReasons = array();
		$negativeReasons = array();

		$nation = (isset($player['nation'])) ? $player['nation'] : '';
		if (strlen($nation) && isset($context['nation_count'][$nation]) && $context['nation_count'][$nation] >= 2) {
			$scoreChange += 5;
			$positiveReasons[] = 'Nationalität passt zur Kabine';
		} elseif (strlen($nation) && strlen($context['top_nation']) && $nation != $context['top_nation']) {
			$scoreChange -= 2;
		}

		$personality = (isset($player['personality'])) ? $player['personality'] : 'professional';
		switch ($personality) {
			case 'leader':
				$scoreChange += 7;
				$positiveReasons[] = 'starke Persönlichkeit';
				break;
			case 'professional':
			case 'loyal':
			case 'big_game_player':
				$scoreChange += 5;
				$positiveReasons[] = 'gute Mentalität';
				break;
			case 'ambitious':
				$scoreChange += 2;
				break;
			case 'troublemaker':
				$scoreChange -= 10;
				$negativeReasons[] = 'Risiko für Teamchemie';
				break;
			case 'inconsistent':
				$scoreChange -= 6;
				$negativeReasons[] = 'schwankende Leistungen';
				break;
			case 'injury_prone':
				$scoreChange -= 5;
				$negativeReasons[] = 'erhöhtes Verletzungsrisiko';
				break;
		}

		$teamChemistry = (isset($context['team']['team_chemistry'])) ? (int) $context['team']['team_chemistry'] : 50;
		if ($teamChemistry < 45 && $scoreChange < 0) {
			$scoreChange -= 4;
			$negativeReasons[] = 'Teamchemie ist bereits empfindlich';
		} elseif ($teamChemistry >= 70 && $scoreChange > 0) {
			$scoreChange += 2;
		}

		return array(
			'score_change' => $scoreChange,
			'positive_reasons' => $positiveReasons,
			'negative_reasons' => $negativeReasons
		);
	}

	/**
	 * @param array $context recommendation context.
	 * @param array $player watchlist player row.
	 * @return array score change and reasons.
	 */
	private static function calculateFinancialFit($context, $player) {
		$budget = (isset($context['team']['finanz_budget'])) ? self::toFloat($context['team']['finanz_budget']) : 0;
		$marketValue = (isset($player['marktwert'])) ? self::toFloat($player['marktwert']) : 0;
		$salary = (isset($player['vertrag_gehalt'])) ? self::toFloat($player['vertrag_gehalt']) : 0;
		$estimatedCommitment = $marketValue + ($salary * 10);

		if ($budget <= 0 || $estimatedCommitment <= 0) {
			return array('score_change' => 0, 'positive_reason' => '', 'negative_reason' => '');
		}

		if ($estimatedCommitment <= ($budget * 0.10)) {
			return array('score_change' => 8, 'positive_reason' => 'finanziell sehr gut machbar', 'negative_reason' => '');
		}
		if ($estimatedCommitment <= ($budget * 0.25)) {
			return array('score_change' => 4, 'positive_reason' => 'finanziell machbar', 'negative_reason' => '');
		}
		if ($estimatedCommitment >= ($budget * 0.50)) {
			return array('score_change' => -14, 'positive_reason' => '', 'negative_reason' => 'finanziell sehr riskant');
		}
		if ($estimatedCommitment >= ($budget * 0.35)) {
			return array('score_change' => -8, 'positive_reason' => '', 'negative_reason' => 'finanziell anspruchsvoll');
		}

		return array('score_change' => 0, 'positive_reason' => '', 'negative_reason' => '');
	}

	/**
	 * Builds a compact German explanation for the recommendation.
	 *
	 * @param array $positiveReasons positive reason snippets.
	 * @param array $negativeReasons negative reason snippets.
	 * @return string compact reason.
	 */
	private static function buildRecommendationReason($positiveReasons, $negativeReasons) {
		$positiveReasons = array_values(array_unique($positiveReasons));
		$negativeReasons = array_values(array_unique($negativeReasons));

		$parts = array();
		$positiveCount = 0;
		foreach ($positiveReasons as $reason) {
			if ($positiveCount >= 2) {
				break;
			}
			if (strlen($reason)) {
				$parts[] = $reason;
				$positiveCount++;
			}
		}

		$negativeCount = 0;
		foreach ($negativeReasons as $reason) {
			if ($negativeCount >= 1) {
				break;
			}
			if (strlen($reason)) {
				$parts[] = $reason;
				$negativeCount++;
			}
		}

		if (!count($parts)) {
			return 'Neutrale Einschätzung ohne starken Ausschlag.';
		}

		return implode('; ', $parts) . '.';
	}

	/**
	 * Averages visible player attributes. Hidden attributes are not passed to this helper.
	 *
	 * @param array $player player row.
	 * @param array $keys attribute keys.
	 * @return float average value.
	 */
	private static function averageVisibleValues($player, $keys) {
		$total = 0;
		$count = 0;
		foreach ($keys as $key) {
			if (isset($player[$key]) && strlen($player[$key])) {
				$total += self::toFloat($player[$key]);
				$count++;
			}
		}

		return ($count > 0) ? round($total / $count, 2) : 0;
	}

	/**
	 * Converts DB values safely to float. Some legacy values are stored as varchar.
	 *
	 * @param mixed $value raw DB value.
	 * @return float converted value.
	 */
	private static function toFloat($value) {
		return (float) str_replace(',', '.', (string) $value);
	}

	/**
	 * Removes all watchlist entries of players who already belong
	 * to the specified team.
	 *
	 * This is useful after a transfer was completed:
	 * a previously watched player should no longer remain
	 * on the team's own watchlist.
	 *
	 * @param WebSoccer $websoccer Application context.
	 * @param DbConnection $db DB connection.
	 * @param int $teamId ID of team.
	 */
	public static function removeOwnPlayersFromWatchlist(WebSoccer $websoccer, DbConnection $db, $teamId) {
	    
	    $db->queryDelete(
	        $websoccer->getConfig('db_prefix') . "_watchlist",
	        "verein_id = %d
         AND spieler_id IN (
             SELECT id
             FROM " . $websoccer->getConfig('db_prefix') . "_spieler
             WHERE verein_id = %d
         )",
	        array($teamId, $teamId)
	        );
	}

}
?>