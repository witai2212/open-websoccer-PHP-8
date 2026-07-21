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
 * Creates a professional football player out of a youth player.
 */
class MoveYouthPlayerToProfessionalController implements IActionController {
    
    private $_i18n;
    private $_websoccer;
    private $_db;
    
    public function __construct(I18n $i18n, WebSoccer $websoccer, DbConnection $db) {
        $this->_i18n = $i18n;
        $this->_websoccer = $websoccer;
        $this->_db = $db;
    }
    
    /**
     * @see IActionController::executeAction()
     */
    public function executeAction($parameters) {
        
        // Check if youth feature is enabled
        if (!$this->_websoccer->getConfig("youth_enabled")) {
            return NULL;
        }
        
        // Validate required parameters
        if (!isset($parameters["id"])) {
            throw new Exception($this->_i18n->getMessage("error_page_not_found"));
        }
        
        $playerId = (int) $parameters["id"];
        $mainPosition = isset($parameters["mainposition"])
        ? trim($parameters["mainposition"])
        : "";
        
        $user = $this->_websoccer->getUser();
        $clubId = $user->getClubId($this->_websoccer, $this->_db);
        
        // Load youth player
        // Service already throws "page not found" if invalid ID
        $player = YouthPlayersDataService::getYouthPlayerById(
            $this->_websoccer,
            $this->_db,
            $this->_i18n,
            $playerId
            );
        
        // Check if player belongs to user's own club
        if ((int) $clubId !== (int) $player["team_id"]) {
            throw new Exception($this->_i18n->getMessage("youthteam_err_notownplayer"));
        }
        
        // Check if player is old enough
        $minimumAge = (int) $this->_websoccer->getConfig("youth_min_age_professional");
        
        if ((int) $player["age"] < $minimumAge) {
            throw new Exception(
                $this->_i18n->getMessage(
                    "youthteam_makeprofessional_err_tooyoung",
                    $minimumAge
                    )
                );
        }
        
        // Validate main position against general position
        $validPositions = $this->getValidMainPositions($player["position"]);
        
        if (!in_array($mainPosition, $validPositions, true)) {
            throw new Exception(
                $this->_i18n->getMessage("youthteam_makeprofessional_err_invalidmainposition")
                );
        }
        
        // Calculate new salary
        $newSalary = $this->_websoccer->getConfig("youth_salary_per_strength") * $player["strength"];
        
        // Check if team can afford salary including the newly promoted player
        $team = TeamsDataService::getTeamSummaryById(
            $this->_websoccer,
            $this->_db,
            $clubId
            );
        
        if (!$team || !isset($team["team_budget"])) {
            throw new Exception($this->_i18n->getMessage("error_page_not_found"));
        }
        
        $currentPlayerSalaries = TeamsDataService::getTotalPlayersSalariesOfTeam(
            $this->_websoccer,
            $this->_db,
            $clubId
            );
        
        if ($team["team_budget"] <= ($currentPlayerSalaries + $newSalary)) {
            throw new Exception(
                $this->_i18n->getMessage("youthteam_makeprofessional_err_budgettooless")
                );
        }
        
        // ---------------------------------------------------------------------
        // Generate talent
        // ---------------------------------------------------------------------
        
        $talent = PlayerTalentDataService::generateTalent($this->_websoccer);
        $player["w_talent"] = $talent;

        $range = PlayerTalentDataService::getPotentialRange($talent);
        $a = $range[0];
        $b = $range[1];
        $maxStrength = PlayerTalentDataService::generateMaximumStrength($talent, $player["strength"]);
        $player["strength_max"] = $maxStrength;
        
        // ---------------------------------------------------------------------
        // Generate individual skills
        // ---------------------------------------------------------------------
        
        $player["w_passing"] = self::myMagicNumber($a, $b);
        $player["w_shooting"] = self::myMagicNumber($a, $b);
        $player["w_heading"] = self::myMagicNumber($a, $b);
        $player["w_tackling"] = self::myMagicNumber($a, $b);
        $player["w_freekick"] = self::myMagicNumber($a, $b);
        $player["w_pace"] = self::myMagicNumber($a, $b);
        $player["w_creativity"] = self::myMagicNumber($a, $b);
        $player["w_influence"] = self::myMagicNumber($a, $b);
        $player["w_flair"] = self::myMagicNumber($a, $b);
        $player["w_penalty"] = self::myMagicNumber($a, $b);
        $player["w_penalty_killing"] = self::myMagicNumber($a, $b);
        
        // Create professional player and delete youth player
        $this->createPlayer($player, $mainPosition, $newSalary, (int) $user->id);
        
        // Success message
        $this->_websoccer->addFrontMessage(
            new FrontMessage(
                MESSAGE_TYPE_SUCCESS,
                $this->_i18n->getMessage("youthteam_makeprofessional_success"),
                ""
                )
            );
        
        return "myteam";
    }
    
    /**
     * Returns all valid detailed main positions for a general player position.
     *
     * @param string $position
     * @return array
     */
    private function getValidMainPositions($position) {
        
        if ($position === "Torwart") {
            return array("T");
        }
        
        if ($position === "Abwehr") {
            return array("LV", "IV", "RV");
        }
        
        if ($position === "Mittelfeld") {
            return array("LM", "RM", "DM", "OM", "ZM");
        }
        
        if ($position === "Sturm") {
            return array("LS", "RS", "MS");
        }
        
        return array();
    }
    
    /**
     * Creates the professional player and deletes the youth player.
     *
     * @param array $player
     * @param string $mainPosition
     * @param int|float $salary
     */
    private function createPlayer($player, $mainPosition, $salary, $userId) {
        
        // Birthday
        $time = strtotime(
            "-" . (int) $player["age"] . " years",
            $this->_websoccer->getNowAsTimestamp()
            );
        
        $birthday = date("Y-m-d", $time);
        
        $columns = array(
            "verein_id" => $player["team_id"],
            "vorname" => $player["firstname"],
            "nachname" => $player["lastname"],
            "geburtstag" => $birthday,
            "age" => $player["age"],
            "position" => $player["position"],
            "position_main" => $mainPosition,
            "nation" => $player["nation"],
            
            "w_staerke" => $player["strength"],
            "w_staerke_max" => $player["strength_max"],
            "w_technik" => $this->_websoccer->getConfig("youth_professionalmove_technique"),
            "w_kondition" => $this->_websoccer->getConfig("youth_professionalmove_stamina"),
            "w_frische" => $this->_websoccer->getConfig("youth_professionalmove_freshness"),
            "w_zufriedenheit" => $this->_websoccer->getConfig("youth_professionalmove_satisfaction"),
            "w_talent" => $player["w_talent"],
            "personality" => PlayerPersonalityDataService::getRandomTrait(),
            
            "w_passing" => $player["w_passing"],
            "w_shooting" => $player["w_shooting"],
            "w_heading" => $player["w_heading"],
            "w_tackling" => $player["w_tackling"],
            "w_freekick" => $player["w_freekick"],
            "w_pace" => $player["w_pace"],
            "w_creativity" => $player["w_creativity"],
            "w_influence" => $player["w_influence"],
            "w_flair" => $player["w_flair"],
            "w_penalty" => $player["w_penalty"],
            "w_penalty_killing" => $player["w_penalty_killing"],
            
            "vertrag_gehalt" => $salary,
            "vertrag_spiele" => $this->_websoccer->getConfig("youth_professionalmove_matches"),
            "vertrag_torpraemie" => 0,
            
            "status" => "1"
        );
        
        // Keep both DB changes together:
        // 1. Insert professional player
        // 2. Delete youth player
        $this->_db->connection->begin_transaction();
        
        try {
            
            $this->_db->queryInsert(
                $columns,
                $this->_websoccer->getConfig("db_prefix") . "_spieler"
                );
            
            $professionalPlayerId = (int) $this->_db->getLastInsertedId();

            if (class_exists("PlayerTraitsDataService")) {
                PlayerTraitsDataService::copyYouthTraitsToProfessionalPlayer(
                    $this->_websoccer,
                    $this->_db,
                    (int) $player["id"],
                    $professionalPlayerId
                    );
            }
            if (class_exists("PlayerMarketValueDataService")) {
                PlayerMarketValueDataService::recalculatePlayer(
                    $this->_websoccer,
                    $this->_db,
                    $professionalPlayerId
                    );
            }

            $playerName = trim($player["firstname"] . " " . $player["lastname"]);
            if (class_exists("ManagerMissionsDataService")) {
                ManagerMissionsDataService::recordYouthPromotion(
                    $this->_websoccer,
                    $this->_db,
                    $userId,
                    (int) $player["team_id"],
                    (int) $player["id"],
                    $professionalPlayerId,
                    $playerName
                    );
            }

            if (class_exists("BadgeAwardService")) {
                BadgeAwardService::processYouthPromotion(
                    $this->_websoccer,
                    $this->_db,
                    $userId,
                    (int) $player["team_id"],
                    $professionalPlayerId
                    );
            }

            if (class_exists("ClubPartnershipDataService")) {
                ClubPartnershipDataService::notifyFirstOptionProfessional(
                    $this->_websoccer,
                    $this->_db,
                    (int) $player["team_id"],
                    $professionalPlayerId,
                    $playerName,
                    $this->_i18n->getMessage("youthteam_makeprofessional")
                    );
            }
            
            $this->_db->queryDelete(
                $this->_websoccer->getConfig("db_prefix") . "_youthplayer",
                "id = %d",
                $player["id"]
                );
            
            $this->_db->connection->commit();
            
        } catch (Exception $e) {
            
            $this->_db->connection->rollback();
            throw $e;
        }
    }
    
    /**
     * Generates a random skill value within the supplied range.
     *
     * @param int $a
     * @param int $b
     * @return int
     */
    private static function myMagicNumber($a, $b) {
        return mt_rand($a, $b);
    }
}

?>