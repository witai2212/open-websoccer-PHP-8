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
 * Executes a training unit and adds training effect results to context parameters.
 */
class ExecuteTrainingController implements IActionController {
	private $_i18n;
	private $_websoccer;
	private $_db;
	
	public function __construct(I18n $i18n, WebSoccer $websoccer, DbConnection $db) {
		$this->_i18n = $i18n;
		$this->_websoccer = $websoccer;
		$this->_db = $db;
	}
	
	/**
	 * (non-PHPdoc)
	 * @see IActionController::executeAction()
	 */
	public function executeAction($parameters) {
		
		$user = $this->_websoccer->getUser();
		$teamId = $user->getClubId($this->_websoccer, $this->_db);
		if ($teamId < 1) {
			return null;
		}
		
		// get unit info
		$unit = TrainingDataService::getTrainingUnitById($this->_websoccer, $this->_db, $teamId, $parameters["id"]);
		if (!isset($unit["id"])) {
			throw new Exception("invalid ID");
		}
		
		if ($unit["date_executed"]) {
			throw new Exception($this->_i18n->getMessage("training_execute_err_already_executed"));
		}
		
		// check if minimum time break between two units is matched
		$previousExecution = TrainingDataService::getLatestTrainingExecutionTime($this->_websoccer, $this->_db, $teamId);
		$earliestValidExecution = $previousExecution + 3600 * $this->_websoccer->getConfig("training_min_hours_between_execution");
		$now = $this->_websoccer->getNowAsTimestamp();
		
		if ($now < $earliestValidExecution) {
			throw new Exception($this->_i18n->getMessage("training_execute_err_too_early", $this->_websoccer->getFormattedDatetime($earliestValidExecution)));
		}
		
		// check if team is in training camp.
		$campBookings = TrainingcampsDataService::getCampBookingsByTeam($this->_websoccer, $this->_db, $teamId);
		foreach ($campBookings as $booking) {
			if ($booking["date_start"] <= $now && $booking["date_end"] >= $now) {
				throw new Exception($this->_i18n->getMessage("training_execute_err_team_in_training_camp"));
			}
		}
		
		// check if there is currently a match simulating
		$liveMatch = MatchesDataService::getLiveMatchByTeam($this->_websoccer, $this->_db, $teamId);
		if (isset($liveMatch["match_id"])) {
			throw new Exception($this->_i18n->getMessage("training_execute_err_match_simulating"));
		}
		
		// trainer info
		$trainer = TrainingDataService::getTrainerById($this->_websoccer, $this->_db, $unit["trainer_id"]);
		
		$columns["focus"] = $parameters["focus"];
		$unit["focus"] = $parameters["focus"];
		$columns["intensity"] = $parameters["intensity"];
		$unit["intensity"] = $parameters["intensity"];
		
		// train players
		$this->trainPlayers($teamId, $trainer, $unit);
		
		// update execution time of unit
		$columns["date_executed"] = $now;
		$fromTable = $this->_websoccer->getConfig("db_prefix") . "_training_unit";
		$whereCondition = "id = %d";
		$this->_db->queryUpdate($columns, $fromTable, $whereCondition, $unit["id"]);
		
		// success message
		$this->_websoccer->addFrontMessage(new FrontMessage(MESSAGE_TYPE_SUCCESS, 
				$this->_i18n->getMessage("training_execute_success"),
				""));
		
		return null;
	}
	
	private function trainPlayers($teamId, $trainer, $unit) {
		
		// compute effect on every player
		$players = PlayersDataService::getPlayersOfTeamById($this->_websoccer, $this->_db, $teamId);
		
		// freshness decrease for stamina and technique training
		$freshnessDecrease = round(1 + $unit["intensity"] / 100 * 5);
		
		$fromTable = $this->_websoccer->getConfig("db_prefix") . "_spieler";
		$whereCondition = "id = %d";
		
		$trainingEffects = array();
		foreach ($players as $player) {
			
			// injured player only refreshes and looses stamina
			$effectFreshness = 0;
			$effectStamina = 0;
			$effectTechnique = 0;
			$effectSatisfaction = 0;
			if ($player["matches_injured"]) {
				$effectFreshness = 1;
				$effectStamina = -1;
			} else {
				
				// regeneration training
				if ($unit["focus"] == "FR") {
					$effectFreshness = 5;
					$effectStamina = -2;
					$effectSatisfaction = 1;
					
					// motivation training
				} else if ($unit["focus"] == "MOT") {
					$effectFreshness = 1;
					$effectStamina = -1;
					$effectSatisfaction = 5;
					
					// stamina training
				} else if ($unit["focus"] == "STA") {
					$effectSatisfaction = -1;
					
					// freshness depends on intensity
					$effectFreshness = -$freshnessDecrease;
					
					// success depends on trainer skills and intensity
					$staminaIncrease = 1;
					if ($unit["intensity"] > 50) {
						$successFactor = $unit["intensity"] * $trainer["p_stamina"] / 100;
						$pStamina[5] = $successFactor;
						$pStamina[1] = 100 - $successFactor;
						
						$staminaIncrease += SimulationHelper::selectItemFromProbabilities($pStamina);
						$paceIncrease += SimulationHelper::selectItemFromProbabilities($pStamina);
					}
					
					$effectStamina = $staminaIncrease;
					$effectPace = $paceIncrease;
					
					// technique
				} else {
					$effectFreshness = -$freshnessDecrease;
					
					if ($unit["intensity"] > 20) {
						$effectStamina = 1;
					}
					
					$techIncrease = 0;
					if ($unit["intensity"] > 75) {
						$successFactor = $unit["intensity"] * $trainer["p_technique"] / 100;
						$pTech[2] = $successFactor;
						$pTech[0] = 100 - $successFactor;
					
						$techIncrease += SimulationHelper::selectItemFromProbabilities($pTech);
						
						$passIncrease += SimulationHelper::selectItemFromProbabilities($pTech);
						$shootIncreas += SimulationHelper::selectItemFromProbabilities($pTech);
						$headIncrease += SimulationHelper::selectItemFromProbabilities($pTech);
						$tacklingIncrease += SimulationHelper::selectItemFromProbabilities($pTech);
						$freekickIncrease += SimulationHelper::selectItemFromProbabilities($pTech);
						$paceIncrease += SimulationHelper::selectItemFromProbabilities($pTech);
						$penaltyIncrease += SimulationHelper::selectItemFromProbabilities($pTech);
						if($player['position_main']=='T') {
						  $penkillingIncease += SimulationHelper::selectItemFromProbabilities($pTech);
						} else {
						  $penkillingIncease = 0;
						}
					}
					
					$effectTechnique = $techIncrease;
					$effectPassing = $passIncrease;
					$effectShooting = $shootIncreas;
					$effectHeading = $headIncrease;
					$effectTackling = $tacklingIncrease;
					$effectFreekick = $freekickIncrease;
					$effectPace = $paceIncrease;
					$effectPenalty = $penaltyIncrease; 
					$effectPenaltyKilling = $penkillingIncease;
				}
			}
			
			$effectInfluence = $effectFreshness;
			$effectFlair = $effectFreshness;
			$effectCreativity = $effectFreshness;
			
			// call plugins
			$event = new PlayerTrainedEvent($this->_websoccer, $this->_db, $this->_i18n,
					$player["id"], $teamId, $trainer["id"], 
			    $effectFreshness, $effectTechnique, $effectStamina, $effectSatisfaction, $effectPassing,
			    $effectShooting, $effectHeading, $effectTackling, $effectFreekick, $effectPace, $effectCreativity, $effectInfluence,
			    $effectFlair, $effectPenalty, $effectPenaltyKilling
			    );
			PluginMediator::dispatchEvent($event);
			
			// slow down training to 10%
			$talent_factor = $player['strength_talent']/5;
			$effectFreshness = $effectFreshness*0.2*$talent_factor;
			$effectTechnique = $effectTechnique*0.1*$talent_factor;
			$effectStamina = $effectStamina*0.1*$talent_factor;
			$effectSatisfaction = $effectSatisfaction*0.1*$talent_factor;
			$effectPassing = $effectPassing*0.1*$talent_factor;
			$effectShooting = $effectShooting*0.1*$talent_factor;
			$effectHeading = $effectHeading*0.1*$talent_factor;
			$effectTackling = $effectTackling*0.1*$talent_factor;
			$effectFreekick = $effectFreekick*0.1*$talent_factor;
			$effectPace = $effectPace*0.1*$talent_factor;
			$effectCreativity = $effectCreativity*0.1*$talent_factor;
			$effectInfluence = $effectInfluence*0.1*$talent_factor;
			$effectFlair = $effectFlair*0.1*$talent_factor;
			$effectPenalty = $effectPenalty*0.1*$talent_factor;
			$effectPenaltyKilling = $effectPenaltyKilling*0.1*$talent_factor;
			
			// update player
			$columns = array(
			    "w_frische" => round(min(100, max(1, $player["strength_freshness"] + $effectFreshness)),2),
			    "w_technik" => round(min(100, max(1, $player["strength_technic"] + $effectTechnique)),2),
			    "w_kondition" => round(min(100, max(1, $player["strength_stamina"] + $effectStamina)),2),
			    "w_zufriedenheit" => round(min(100, max(1, $player["strength_satisfaction"] + $effectSatisfaction)),2),
			    "w_passing" => round(min(100, max(1, $player["strength_passing"] + $effectPassing)),2),
			    "w_shooting" => round(min(100, max(1, $player["strength_shooting"] + $effectShooting)),2),
			    "w_heading" => round(min(100, max(1, $player["strength_heading"] + $effectHeading)),2),
			    "w_tackling" => round(min(100, max(1, $player["strength_tackling"] + $effectTackling)),2),
			    "w_freekick" => round(min(100, max(1, $player["strength_freekick"] + $effectFreekick)),2),
			    "w_pace" => round(min(100, max(1, $player["strength_pace"] + $effectPace)),2),
			    "w_influence" => round(min(100, max(1, $player["strength_influence"] + $effectInfluence)),2),
			    "w_creativity" => round(min(100, max(1, $player["strength_creativity"] + $effectCreativity)),2),
			    "w_flair" => round(min(100, max(1, $player["strength_flair"] + $effectFlair)),2),
			    "w_penalty" => round(min(100, max(1, $player["strength_penalty"] + $effectPenalty)),2),
			    "w_penalty_killing" => round(min(100, max(1, $player["strength_penalty_killing"] + $effectPenaltyKilling)),2)
			    );
			$this->_db->queryUpdate($columns, $fromTable, $whereCondition, $player["id"]);
			
			// add effect
			$trainingEffects[$player["id"]] = array(
					"name" => ($player["pseudonym"]) ? $player["pseudonym"] : $player["firstname"] . " " . $player["lastname"],
					"freshness" => round($effectFreshness,2),
    			    "technique" => round($effectTechnique,2),
    			    "stamina" => round($effectStamina,2),
    			    "satisfaction" => round($effectSatisfaction,2),
    			    "passing" => round($effectPassing,2),
    			    "shooting" => round($effectShooting,2),
    			    "heading" => round($effectHeading,2),
    			    "tackling" => round($effectTackling,2),
    			    "freekick" => round($effectFreekick,2),
    			    "pace" => round($effectPace,2),
    			    "influence" => round($effectInfluence,2),
    			    "creativity" => round($effectCreativity,2),
    			    "flair" => round($effectFlair,2),
    			    "penalty" => round($effectPenalty,2),
    			    "penalty_killing" => round($effectPenaltyKilling,2)
					);
		}
		
		$this->_websoccer->addContextParameter("trainingEffects", $trainingEffects);
	}
	
}

?>