<?php
/******************************************************

This file is part of OpenWebSoccer-Sim.

******************************************************/

if (!defined("COMPUTER_YOUTH_NAMES_DIRECTORY")) {
    define("COMPUTER_YOUTH_NAMES_DIRECTORY", BASE_FOLDER . "/admin/config/names");
}

/**
 * Data service for automatically managing youth teams of computer-controlled clubs.
 */
class ComputerYouthTeamsDataService {
    
    /**
     * Main entry point for ComputerYouthTeamsJob.
     *
     * @param WebSoccer $websoccer
     * @param DbConnection $db
     * @param I18n|null $i18n
     */
    public static function execute(WebSoccer $websoccer, DbConnection $db, I18n $i18n = null) {
        
        echo "[ComputerYouthTeamsDataService] started.\n";
        
        self::createMissingYouthSquads($websoccer, $db);
        self::promoteEligibleYouthPlayers($websoccer, $db, 0, false);
        YouthTransferOfferDataService::prepareComputerRun($websoccer, $db);
        self::sellSurplusYouthPlayers($websoccer, $db);
        self::buyUsefulYouthPlayers($websoccer, $db);
        YouthTransferOfferDataService::finalizeComputerRun($websoccer, $db);
        self::acceptManagerYouthMatchRequests($websoccer, $db);
        self::acceptComputerYouthMatchRequests($websoccer, $db);
        self::createLimitedComputerYouthMatchRequests($websoccer, $db);
        self::createDirectComputerYouthMatches($websoccer, $db);
        
        echo "[ComputerYouthTeamsDataService] finished.\n";
    }
    
    /**
     * Creates youth players for computer clubs until each club reaches the configured target size.
     *
     * @param WebSoccer $websoccer
     * @param DbConnection $db
     */
    private static function createMissingYouthSquads(WebSoccer $websoccer, DbConnection $db) {
        
        $targetPlayers = max(11, (int) $websoccer->getConfig("computer_youth_target_players"));
        $maxPlayers = max($targetPlayers, (int) $websoccer->getConfig("computer_youth_max_players"));
        $createdTotal = 0;
        
        $teams = self::getComputerControlledTeams($websoccer, $db);
        
        foreach ($teams as $team) {
            $teamId = (int) $team["id"];
            $currentPlayers = self::countYouthPlayersOfTeam($websoccer, $db, $teamId);
            
            if ($currentPlayers >= $targetPlayers) {
                continue;
            }
            
            $missingPlayers = $targetPlayers - $currentPlayers;
            
            if (($currentPlayers + $missingPlayers) > $maxPlayers) {
                $missingPlayers = $maxPlayers - $currentPlayers;
            }
            
            if ($missingPlayers <= 0) {
                continue;
            }
            
            $country = self::getCountryForTeam($websoccer, $db, $teamId);
            
            for ($i = 0; $i < $missingPlayers; $i++) {
                self::createRandomYouthPlayer($websoccer, $db, $teamId, $country);
                $createdTotal++;
            }
            
            echo "[ComputerYouthTeamsDataService] created " . $missingPlayers . " youth players for team #" . $teamId . ".\n";
        }
        
        echo "[ComputerYouthTeamsDataService] youth players created total: " . $createdTotal . ".\n";
    }
    
    /**
     * @param WebSoccer $websoccer
     * @param DbConnection $db
     * @return array
     */
    private static function getComputerControlledTeams(WebSoccer $websoccer, DbConnection $db, $leagueId = 0) {
        
        $teams = array();
        
        $columns = array(
            "C.id" => "id",
            "C.name" => "name",
            "C.liga_id" => "league_id",
            "L.land" => "league_country"
        );
        
        $fromTable = $websoccer->getConfig("db_prefix") . "_verein AS C";
        $fromTable .= " LEFT JOIN " . $websoccer->getConfig("db_prefix") . "_liga AS L ON L.id = C.liga_id";
        
        $whereCondition = "(C.user_id IS NULL OR C.user_id <= 0) AND C.nationalteam = '0' AND C.status = '1'";
        $parameters = array();
        if ((int) $leagueId > 0) {
            $whereCondition .= " AND C.liga_id = %d";
            $parameters[] = (int) $leagueId;
        }
        $whereCondition .= " ORDER BY RAND()";
        
        $result = $db->querySelect($columns, $fromTable, $whereCondition, $parameters);
        while ($team = $result->fetch_array()) {
            $teams[] = $team;
        }
        $result->free();
        
        return $teams;
    }
    
    /**
     * @param WebSoccer $websoccer
     * @param DbConnection $db
     * @param int $teamId
     * @return int
     */
    private static function countYouthPlayersOfTeam(WebSoccer $websoccer, DbConnection $db, $teamId) {
        
        $result = $db->querySelect(
            "COUNT(*) AS hits",
            $websoccer->getConfig("db_prefix") . "_youthplayer",
            "team_id = %d",
            $teamId
            );
        
        $row = $result->fetch_array();
        $result->free();
        
        return ($row && isset($row["hits"])) ? (int) $row["hits"] : 0;
    }
    
    /**
     * Creates one random youth player for the specified team.
     *
     * @param WebSoccer $websoccer
     * @param DbConnection $db
     * @param int $teamId
     * @param string|null $country
     */
    private static function createRandomYouthPlayer(WebSoccer $websoccer, DbConnection $db, $teamId, $country = null) {
        
        if (!$country || !self::hasNameFilesForCountry($country)) {
            $country = self::getRandomScoutingCountry();
        }
        
        $firstName = self::getItemFromFile(COMPUTER_YOUTH_NAMES_DIRECTORY . "/" . $country . "/firstnames.txt");
        $lastName = self::getItemFromFile(COMPUTER_YOUTH_NAMES_DIRECTORY . "/" . $country . "/lastnames.txt");
        $position = self::getNextNeededPosition($websoccer, $db, $teamId);
        
        $minAge = (int) $websoccer->getConfig("computer_youth_generation_min_age");
        $maxAge = (int) $websoccer->getConfig("computer_youth_generation_max_age");
        $professionalAge = (int) $websoccer->getConfig("youth_min_age_professional");
        
        if ($minAge < 10) {
            $minAge = 14;
        }
        
        if ($professionalAge > 0) {
            $maxAge = min($maxAge, $professionalAge - 1);
        }
        
        if ($maxAge < $minAge) {
            $maxAge = $minAge;
        }
        
        $age = mt_rand($minAge, $maxAge);
        
        $minStrength = (int) $websoccer->getConfig("computer_youth_generation_min_strength");
        $maxStrength = (int) $websoccer->getConfig("computer_youth_generation_max_strength");
        
        if ($minStrength < 1) {
            $minStrength = 5;
        }
        
        if ($maxStrength < $minStrength) {
            $maxStrength = $minStrength;
        }
        
        if ($maxStrength > 100) {
            $maxStrength = 100;
        }
        
        $strength = mt_rand($minStrength, $maxStrength);
        
        $db->queryInsert(array(
            "team_id" => $teamId,
            "firstname" => $firstName,
            "lastname" => $lastName,
            "age" => $age,
            "position" => $position,
            "nation" => $country,
            "strength" => $strength,
            "transfer_fee" => 0
        ), $websoccer->getConfig("db_prefix") . "_youthplayer");
        $youthPlayerId = (int) $db->getLastInsertedId();
        if (class_exists('PlayerMarketValueDataService')) {
            PlayerMarketValueDataService::recalculateYouthPlayer($websoccer, $db, $youthPlayerId);
        }
    }
    
    /**
     * Selects a useful next position based on the current youth squad.
     *
     * @param WebSoccer $websoccer
     * @param DbConnection $db
     * @param int $teamId
     * @return string
     */
    private static function getNextNeededPosition(WebSoccer $websoccer, DbConnection $db, $teamId) {
        
        $desired = array(
            "Torwart" => 2,
            "Abwehr" => 6,
            "Mittelfeld" => 7,
            "Sturm" => 5
        );
        
        $current = array(
            "Torwart" => 0,
            "Abwehr" => 0,
            "Mittelfeld" => 0,
            "Sturm" => 0
        );
        
        $result = $db->querySelect(
            "position, COUNT(*) AS hits",
            $websoccer->getConfig("db_prefix") . "_youthplayer",
            "team_id = %d GROUP BY position",
            $teamId
            );
        
        while ($row = $result->fetch_array()) {
            if (isset($current[$row["position"]])) {
                $current[$row["position"]] = (int) $row["hits"];
            }
        }
        $result->free();
        
        $deficits = array();
        foreach ($desired as $position => $target) {
            $missing = $target - $current[$position];
            if ($missing > 0) {
                $deficits[$position] = $missing;
            }
        }
        
        if (count($deficits)) {
            return self::selectWeighted($deficits);
        }
        
        return self::selectWeighted(array(
            "Torwart" => 10,
            "Abwehr" => 30,
            "Mittelfeld" => 35,
            "Sturm" => 25
        ));
    }
    
    /**
     * Tries to use the country of the team's league. Falls back to a random configured names folder.
     *
     * @param WebSoccer $websoccer
     * @param DbConnection $db
     * @param int $teamId
     * @return string
     */
    private static function getCountryForTeam(WebSoccer $websoccer, DbConnection $db, $teamId) {
        
        $columns = array("L.land" => "league_country");
        $fromTable = $websoccer->getConfig("db_prefix") . "_verein AS C";
        $fromTable .= " LEFT JOIN " . $websoccer->getConfig("db_prefix") . "_liga AS L ON L.id = C.liga_id";
        
        $result = $db->querySelect($columns, $fromTable, "C.id = %d", $teamId, 1);
        $team = $result->fetch_array();
        $result->free();
        
        if ($team && isset($team["league_country"]) && self::hasNameFilesForCountry($team["league_country"])) {
            return $team["league_country"];
        }
        
        return self::getRandomScoutingCountry();
    }
    
    /**
     * @return array
     */
    private static function getPossibleScoutingCountries() {
        
        $countries = array();
        
        if (!is_dir(COMPUTER_YOUTH_NAMES_DIRECTORY)) {
            return $countries;
        }
        
        $iterator = new DirectoryIterator(COMPUTER_YOUTH_NAMES_DIRECTORY);
        while ($iterator->valid()) {
            if ($iterator->isDir() && !$iterator->isDot()) {
                $country = $iterator->getFilename();
                if (self::hasNameFilesForCountry($country)) {
                    $countries[] = $country;
                }
            }
            $iterator->next();
        }
        
        return $countries;
    }
    
    /**
     * @return string
     */
    private static function getRandomScoutingCountry() {
        
        $countries = self::getPossibleScoutingCountries();
        
        if (!count($countries)) {
            throw new Exception("No valid youth player name files found in admin/config/names/.");
        }
        
        return $countries[mt_rand(0, count($countries) - 1)];
    }
    
    /**
     * @param string $country
     * @return bool
     */
    private static function hasNameFilesForCountry($country) {
        
        if (!$country) {
            return false;
        }
        
        $folder = COMPUTER_YOUTH_NAMES_DIRECTORY . "/" . $country;
        
        return file_exists($folder . "/firstnames.txt") && file_exists($folder . "/lastnames.txt");
    }
    
    /**
     * @param string $fileName
     * @return string
     */
    private static function getItemFromFile($fileName) {
        
        $items = file($fileName, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $itemsCount = count($items);
        
        if (!$itemsCount) {
            throw new Exception("Could not read youth player name file: " . $fileName);
        }
        
        return $items[mt_rand(0, $itemsCount - 1)];
    }
    
    /**
     * @param array $weights
     * @return string|int
     */
    private static function selectWeighted($weights) {
        
        $total = 0;
        foreach ($weights as $weight) {
            $total += (int) $weight;
        }
        
        if ($total <= 0) {
            $keys = array_keys($weights);
            return $keys[0];
        }
        
        $random = mt_rand(1, $total);
        $current = 0;
        
        foreach ($weights as $item => $weight) {
            $current += (int) $weight;
            if ($random <= $current) {
                return $item;
            }
        }
        
        $keys = array_keys($weights);
        return $keys[count($keys) - 1];
    }
    
    public static function promoteEligibleYouthPlayersForLeague(WebSoccer $websoccer, DbConnection $db, $leagueId, $silent = true) {
        return self::promoteEligibleYouthPlayers($websoccer, $db, (int) $leagueId, (bool) $silent);
    }


    /**
     * Promotes eligible computer youth players into the professional team.
     *
     * @param WebSoccer $websoccer
     * @param DbConnection $db
     */
    private static function promoteEligibleYouthPlayers(WebSoccer $websoccer, DbConnection $db, $leagueId = 0, $silent = false) {
        
        if ((int) $websoccer->getConfig("computer_youth_promote_enabled") !== 1) {
            if (!$silent) {
                echo "[ComputerYouthTeamsDataService] youth promotion disabled.\n";
            }
            return 0;
        }
        
        $minimumAge = (int) $websoccer->getConfig("youth_min_age_professional");
        $minimumStrength = (int) $websoccer->getConfig("computer_youth_promotion_min_strength");
        
        if ($minimumAge <= 0) {
            $minimumAge = 18;
        }
        
        if ($minimumStrength <= 0) {
            $minimumStrength = 45;
        }
        
        $promotedTotal = 0;
        $teams = self::getComputerControlledTeams($websoccer, $db, (int) $leagueId);
        
        foreach ($teams as $team) {
            $teamId = (int) $team["id"];
            $players = self::getPromotableYouthPlayers($websoccer, $db, $teamId, $minimumAge, $minimumStrength);
            
            if (!count($players)) {
                continue;
            }
            
            $promotedForTeam = 0;
            
            foreach ($players as $player) {
                if (self::promoteYouthPlayerToProfessional($websoccer, $db, $player)) {
                    $promotedTotal++;
                    $promotedForTeam++;
                }
            }
            
            if ($promotedForTeam > 0) {
                TeamsDataService::updateTeamStrength($websoccer, $db, $teamId);
                if (!$silent) {
                    echo "[ComputerYouthTeamsDataService] promoted " . $promotedForTeam . " youth players for team #" . $teamId . ".\n";
                }
            }
        }
        
        if (!$silent) {
            echo "[ComputerYouthTeamsDataService] youth players promoted total: " . $promotedTotal . ".\n";
        }
        return $promotedTotal;
    }
    
    /**
     * @param WebSoccer $websoccer
     * @param DbConnection $db
     * @param int $teamId
     * @param int $minimumAge
     * @param int $minimumStrength
     * @return array
     */
    private static function getPromotableYouthPlayers(WebSoccer $websoccer, DbConnection $db, $teamId, $minimumAge, $minimumStrength) {
        
        $result = $db->querySelect(
            "*",
            $websoccer->getConfig("db_prefix") . "_youthplayer",
            "team_id = %d AND age >= %d AND strength >= %d ORDER BY strength DESC",
            array($teamId, $minimumAge, $minimumStrength)
            );
        
        $players = array();
        while ($player = $result->fetch_array()) {
            $players[] = $player;
        }
        $result->free();
        
        return $players;
    }
    
    /**
     * @param WebSoccer $websoccer
     * @param DbConnection $db
     * @param array $player
     * @return bool
     */
    private static function promoteYouthPlayerToProfessional(WebSoccer $websoccer, DbConnection $db, $player) {
        
        $teamId = (int) $player["team_id"];
        $strength = (int) $player["strength"];
        $newSalary = (int) $websoccer->getConfig("youth_salary_per_strength") * $strength;
        
        $team = TeamsDataService::getTeamSummaryById($websoccer, $db, $teamId);
        
        if (!$team || !isset($team["team_budget"])) {
            return false;
        }
        
        $currentPlayerSalaries = TeamsDataService::getTotalPlayersSalariesOfTeam($websoccer, $db, $teamId);
        
        if ((int) $team["team_budget"] <= ($currentPlayerSalaries + $newSalary)) {
            echo "[ComputerYouthTeamsDataService] skipped promotion of youth player #" . $player["id"] . " because team #" . $teamId . " cannot afford salary.\n";
            return false;
        }
        
        $mainPosition = self::getAutomaticMainPosition($player["position"]);
        $talent = PlayerTalentDataService::generateTalent($websoccer);
        
        $range = PlayerTalentDataService::getPotentialRange($talent);
        $a = $range[0];
        $b = $range[1];
        $maxStrength = PlayerTalentDataService::generateMaximumStrength($talent, $strength);
        
        $birthdayTime = strtotime("-" . (int) $player["age"] . " years", $websoccer->getNowAsTimestamp());
        $birthday = date("Y-m-d", $birthdayTime);
        
        $columns = array(
            "verein_id" => $teamId,
            "vorname" => $player["firstname"],
            "nachname" => $player["lastname"],
            "geburtstag" => $birthday,
            "age" => $player["age"],
            "position" => $player["position"],
            "position_main" => $mainPosition,
            "nation" => $player["nation"],
            "w_staerke" => $strength,
            "w_staerke_max" => $maxStrength,
            "w_technik" => $websoccer->getConfig("youth_professionalmove_technique"),
            "w_kondition" => $websoccer->getConfig("youth_professionalmove_stamina"),
            "w_frische" => $websoccer->getConfig("youth_professionalmove_freshness"),
            "w_zufriedenheit" => $websoccer->getConfig("youth_professionalmove_satisfaction"),
            "w_talent" => $talent,
            "personality" => PlayerPersonalityDataService::getRandomTrait(),
            "w_passing" => self::generateProfessionalSkill($a, $b),
            "w_shooting" => self::generateProfessionalSkill($a, $b),
            "w_heading" => self::generateProfessionalSkill($a, $b),
            "w_tackling" => self::generateProfessionalSkill($a, $b),
            "w_freekick" => self::generateProfessionalSkill($a, $b),
            "w_pace" => self::generateProfessionalSkill($a, $b),
            "w_creativity" => self::generateProfessionalSkill($a, $b),
            "w_influence" => self::generateProfessionalSkill($a, $b),
            "w_flair" => self::generateProfessionalSkill($a, $b),
            "w_penalty" => self::generateProfessionalSkill($a, $b),
            "w_penalty_killing" => self::generateProfessionalSkill($a, $b),
            "vertrag_gehalt" => $newSalary,
            "vertrag_spiele" => $websoccer->getConfig("youth_professionalmove_matches"),
            "vertrag_torpraemie" => 0,
            "status" => "1"
        );
        
        $db->connection->begin_transaction();
        
        try {
            $db->queryInsert($columns, $websoccer->getConfig("db_prefix") . "_spieler");
            $professionalPlayerId = (int) $db->getLastInsertedId();
            if (class_exists('PlayerMarketValueDataService')) {
                PlayerMarketValueDataService::recalculatePlayer($websoccer, $db, $professionalPlayerId);
            }
            $db->queryDelete($websoccer->getConfig("db_prefix") . "_youthplayer", "id = %d", $player["id"]);
            $db->connection->commit();
            return true;
        } catch (Exception $e) {
            $db->connection->rollback();
            throw $e;
        }
    }
    
    /**
     * @param string $position
     * @return string
     */
    private static function getAutomaticMainPosition($position) {
        
        if ($position === "Torwart") {
            return "T";
        }
        
        if ($position === "Abwehr") {
            return self::selectWeighted(array("IV" => 50, "LV" => 25, "RV" => 25));
        }
        
        if ($position === "Mittelfeld") {
            return self::selectWeighted(array("ZM" => 35, "DM" => 20, "OM" => 20, "LM" => 12, "RM" => 13));
        }
        
        if ($position === "Sturm") {
            return self::selectWeighted(array("MS" => 50, "LS" => 25, "RS" => 25));
        }
        
        return "ZM";
    }
    
    /**
     * @return int
     */
    private static function generateProfessionalTalent() {
        
        $rTalent = mt_rand(1, 100);
        if ($rTalent > 94) {
            return 6;
        }
        
        return mt_rand(1, 5);
    }
    
    /**
     * @param int $a
     * @param int $b
     * @return int
     */
    private static function generateProfessionalSkill($a, $b) {
        return mt_rand($a, $b);
    }
    
    /**
     * Puts surplus or unsuitable computer youth players on the youth marketplace.
     *
     * @param WebSoccer $websoccer
     * @param DbConnection $db
     */
    private static function sellSurplusYouthPlayers(WebSoccer $websoccer, DbConnection $db) {
        
        if ((int) $websoccer->getConfig("computer_youth_sell_enabled") !== 1) {
            echo "[ComputerYouthTeamsDataService] youth selling disabled.\n";
            return;
        }
        
        $maxPlayers = (int) $websoccer->getConfig("computer_youth_max_players");
        $targetPlayers = (int) $websoccer->getConfig("computer_youth_target_players");
        $minimumPromotionAge = (int) $websoccer->getConfig("youth_min_age_professional");
        $minimumPromotionStrength = (int) $websoccer->getConfig("computer_youth_promotion_min_strength");
        $maxListedPlayers = max(1, (int) $websoccer->getConfig("computer_youth_max_players_on_transfer_list"));
        $listingDurationDays = max(1, (int) $websoccer->getConfig("computer_youth_transfer_list_duration_days"));
        $now = $websoccer->getNowAsTimestamp();
        
        if ($maxPlayers < 11) {
            $maxPlayers = 25;
        }
        
        if ($targetPlayers < 11) {
            $targetPlayers = 20;
        }
        
        if ($minimumPromotionAge <= 0) {
            $minimumPromotionAge = 18;
        }
        
        if ($minimumPromotionStrength <= 0) {
            $minimumPromotionStrength = 45;
        }
        
        $markedTotal = 0;
        $teams = self::getComputerControlledTeams($websoccer, $db);
        
        foreach ($teams as $team) {
            $teamId = (int) $team["id"];
            $players = self::getYouthPlayersForSellingCheck($websoccer, $db, $teamId);
            
            if (!count($players)) {
                continue;
            }

            $listedForTeam = 0;
            foreach ($players as $listedPlayer) {
                if ((int) $listedPlayer["transfer_fee"] > 0) {
                    $listedForTeam++;
                }
            }
            $availableListingSlots = max(0, $maxListedPlayers - $listedForTeam);
            if ($availableListingSlots <= 0) {
                continue;
            }
            
            $markedPlayerIds = array();
            
            foreach ($players as $player) {
                if ((int) $player["transfer_fee"] > 0) {
                    continue;
                }
                
                if ((int) $player["age"] >= $minimumPromotionAge && (int) $player["strength"] < $minimumPromotionStrength) {
                    $markedPlayerIds[(int) $player["id"]] = (int) $player["id"];
                }
            }
            
            $currentCount = count($players);
            if ($currentCount > $maxPlayers) {
                $surplus = $currentCount - $maxPlayers;
                $weakestPlayers = $players;
                usort($weakestPlayers, array("ComputerYouthTeamsDataService", "sortYouthPlayersWeakestFirst"));
                
                foreach ($weakestPlayers as $player) {
                    if ($surplus <= 0) {
                        break;
                    }
                    
                    if ((int) $player["transfer_fee"] > 0) {
                        continue;
                    }
                    
                    $playerId = (int) $player["id"];
                    if (!isset($markedPlayerIds[$playerId])) {
                        $markedPlayerIds[$playerId] = $playerId;
                        $surplus--;
                    }
                }
            }
            
            if ($currentCount > $targetPlayers) {
                $overloadedPlayers = self::getWeakPlayersFromOverloadedPositions($players);
                
                foreach ($overloadedPlayers as $player) {
                    if (count($markedPlayerIds) >= ($currentCount - $targetPlayers)) {
                        break;
                    }
                    
                    if ((int) $player["transfer_fee"] > 0) {
                        continue;
                    }
                    
                    $playerId = (int) $player["id"];
                    if (!isset($markedPlayerIds[$playerId])) {
                        $markedPlayerIds[$playerId] = $playerId;
                    }
                }
            }
            
            $markedForTeam = 0;
            
            foreach ($players as $player) {
                if ($markedForTeam >= $availableListingSlots) {
                    break;
                }
                $playerId = (int) $player["id"];
                
                if (!isset($markedPlayerIds[$playerId]) || (int) $player["transfer_fee"] > 0) {
                    continue;
                }
                
                $transferFee = self::calculateComputerYouthTransferFee($player);
                
                $db->queryUpdate(
                    array(
                        "transfer_fee" => $transferFee,
                        "transfer_start" => $now,
                        "transfer_ende" => $now + ($listingDurationDays * 24 * 60 * 60),
                        "transfer_listed_by_cpu" => "1"
                    ),
                    $websoccer->getConfig("db_prefix") . "_youthplayer",
                    "id = %d",
                    $playerId
                    );
                
                $markedForTeam++;
                $markedTotal++;
            }
            
            if ($markedForTeam > 0) {
                echo "[ComputerYouthTeamsDataService] marked " . $markedForTeam . " youth players for sale for team #" . $teamId . ".\n";
            }
        }
        
        echo "[ComputerYouthTeamsDataService] youth players marked for sale total: " . $markedTotal . ".\n";
    }
    
    /**
     * @param WebSoccer $websoccer
     * @param DbConnection $db
     * @param int $teamId
     * @return array
     */
    private static function getYouthPlayersForSellingCheck(WebSoccer $websoccer, DbConnection $db, $teamId) {
        
        $result = $db->querySelect(
            "*",
            $websoccer->getConfig("db_prefix") . "_youthplayer",
            "team_id = %d ORDER BY strength ASC, age DESC",
            $teamId
            );
        
        $players = array();
        while ($player = $result->fetch_array()) {
            $players[] = $player;
        }
        $result->free();
        
        return $players;
    }
    
    /**
     * @param array $players
     * @return array
     */
    private static function getWeakPlayersFromOverloadedPositions($players) {
        
        $desired = array(
            "Torwart" => 2,
            "Abwehr" => 6,
            "Mittelfeld" => 7,
            "Sturm" => 5
        );
        
        $byPosition = array(
            "Torwart" => array(),
            "Abwehr" => array(),
            "Mittelfeld" => array(),
            "Sturm" => array()
        );
        
        foreach ($players as $player) {
            $position = $player["position"];
            if (isset($byPosition[$position])) {
                $byPosition[$position][] = $player;
            }
        }
        
        $overloadedPlayers = array();
        
        foreach ($byPosition as $position => $positionPlayers) {
            if (count($positionPlayers) <= $desired[$position]) {
                continue;
            }
            
            usort($positionPlayers, array("ComputerYouthTeamsDataService", "sortYouthPlayersWeakestFirst"));
            
            $surplus = count($positionPlayers) - $desired[$position];
            $index = 0;
            
            while ($surplus > 0 && isset($positionPlayers[$index])) {
                $overloadedPlayers[] = $positionPlayers[$index];
                $index++;
                $surplus--;
            }
        }
        
        usort($overloadedPlayers, array("ComputerYouthTeamsDataService", "sortYouthPlayersWeakestFirst"));
        
        return $overloadedPlayers;
    }
    
    /**
     * Sort helper: weakest first, then oldest first.
     *
     * @param array $a
     * @param array $b
     * @return int
     */
    public static function sortYouthPlayersWeakestFirst($a, $b) {
        
        $strengthA = (int) $a["strength"];
        $strengthB = (int) $b["strength"];
        
        if ($strengthA === $strengthB) {
            $ageA = (int) $a["age"];
            $ageB = (int) $b["age"];
            
            if ($ageA === $ageB) {
                return 0;
            }
            
            return ($ageA > $ageB) ? -1 : 1;
        }
        
        return ($strengthA < $strengthB) ? -1 : 1;
    }
    
    /**
     * @param array $player
     * @return int
     */
    private static function calculateComputerYouthTransferFee($player) {
        
        if (isset($player["market_value"]) && (int) $player["market_value"] > 0) {
            return (int) $player["market_value"];
        }
        $strength = max(1, (int) $player["strength"]);
        $age = max(14, (int) $player["age"]);
        $fee = $strength * $strength * 1000;
        
        if ($age >= 18) {
            $fee = (int) round($fee * 0.75);
        } elseif ($age === 17) {
            $fee = (int) round($fee * 0.90);
        }
        
        if ($fee < 50000) {
            $fee = 50000;
        }
        
        return (int) (round($fee / 1000) * 1000);
    }
    
    /**
     * Lets computer-controlled clubs buy useful youth players from the youth marketplace.
     *
     * @param WebSoccer $websoccer
     * @param DbConnection $db
     */
    private static function buyUsefulYouthPlayers(WebSoccer $websoccer, DbConnection $db) {
        
        if ((int) $websoccer->getConfig("computer_youth_buy_enabled") !== 1) {
            echo "[ComputerYouthTeamsDataService] youth buying disabled.\n";
            return;
        }
        
        $targetPlayers = (int) $websoccer->getConfig("computer_youth_target_players");
        $maxPlayers = (int) $websoccer->getConfig("computer_youth_max_players");
        $minBudgetAfterBuy = (int) $websoccer->getConfig("computer_youth_min_budget_after_buy");
        $maxBuyFee = (int) $websoccer->getConfig("computer_youth_max_buy_fee");
        $maxBuysPerRun = (int) $websoccer->getConfig("computer_youth_max_buys_per_run");
        $maxBuysPerClub = (int) $websoccer->getConfig("computer_youth_max_buys_per_club");
        
        if ($targetPlayers < 11) {
            $targetPlayers = 20;
        }
        
        if ($maxPlayers < $targetPlayers) {
            $maxPlayers = $targetPlayers;
        }
        
        if ($maxBuyFee <= 0) {
            $maxBuyFee = 5000000;
        }
        
        if ($maxBuysPerRun <= 0) {
            $maxBuysPerRun = 10;
        }
        
        if ($maxBuysPerClub <= 0) {
            $maxBuysPerClub = 2;
        }
        
        $boughtTotal = 0;
        $teams = self::getComputerControlledTeams($websoccer, $db);
        
        foreach ($teams as $teamRow) {
            if ($boughtTotal >= $maxBuysPerRun) {
                break;
            }
            
            $buyerTeamId = (int) $teamRow["id"];
            $buyerTeam = TeamsDataService::getTeamSummaryById($websoccer, $db, $buyerTeamId);
            
            if (!$buyerTeam || !isset($buyerTeam["team_budget"])) {
                continue;
            }
            
            $buyerBudget = (int) $buyerTeam["team_budget"];
            if ($buyerBudget <= $minBudgetAfterBuy) {
                continue;
            }
            
            $ownPlayers = self::getYouthPlayersForBuyingCheck($websoccer, $db, $buyerTeamId);
            $currentCount = count($ownPlayers);
            
            if ($currentCount >= $maxPlayers) {
                continue;
            }
            
            $boughtForTeam = 0;
            
            while ($boughtForTeam < $maxBuysPerClub && $boughtTotal < $maxBuysPerRun && $currentCount < $maxPlayers) {
                $buyerTeam = TeamsDataService::getTeamSummaryById($websoccer, $db, $buyerTeamId);
                $buyerBudget = (int) $buyerTeam["team_budget"];
                $availableBudget = $buyerBudget - $minBudgetAfterBuy;
                
                if ($availableBudget <= 0) {
                    break;
                }
                
                $squadAnalysis = self::analyzeYouthSquadForBuying($ownPlayers);
                $candidate = self::findBestComputerYouthPurchaseCandidate(
                    $websoccer,
                    $db,
                    $buyerTeamId,
                    $squadAnalysis,
                    $currentCount,
                    $targetPlayers,
                    $maxPlayers,
                    min($availableBudget, $maxBuyFee)
                    );
                
                if (!$candidate) {
                    break;
                }
                
                if (!YouthTransferOfferDataService::createComputerOffer(
                    $websoccer,
                    $db,
                    $buyerTeamId,
                    $candidate,
                    (int) $candidate["transfer_fee"]
                )) {
                    break;
                }

                $boughtForTeam++;
                $boughtTotal++;
            }
            
            if ($boughtForTeam > 0) {
                echo "[ComputerYouthTeamsDataService] placed " . $boughtForTeam . " youth offers for team #" . $buyerTeamId . ".\n";
            }
        }
        
        echo "[ComputerYouthTeamsDataService] youth offers placed total: " . $boughtTotal . ".\n";
    }
    
    /**
     * @param WebSoccer $websoccer
     * @param DbConnection $db
     * @param int $teamId
     * @return array
     */
    private static function getYouthPlayersForBuyingCheck(WebSoccer $websoccer, DbConnection $db, $teamId) {
        
        $result = $db->querySelect(
            "*",
            $websoccer->getConfig("db_prefix") . "_youthplayer",
            "team_id = %d ORDER BY strength DESC",
            $teamId
            );
        
        $players = array();
        while ($player = $result->fetch_array()) {
            $players[] = $player;
        }
        $result->free();
        
        return $players;
    }
    
    /**
     * @param array $players
     * @return array
     */
    private static function analyzeYouthSquadForBuying($players) {
        
        $analysis = array(
            "position_counts" => array(
                "Torwart" => 0,
                "Abwehr" => 0,
                "Mittelfeld" => 0,
                "Sturm" => 0
            ),
            "weakest_by_position" => array(),
            "average_strength" => 0
        );
        
        $totalStrength = 0;
        
        foreach ($players as $player) {
            $position = $player["position"];
            $strength = (int) $player["strength"];
            
            if (isset($analysis["position_counts"][$position])) {
                $analysis["position_counts"][$position]++;
            }
            
            if (!isset($analysis["weakest_by_position"][$position]) || $strength < (int) $analysis["weakest_by_position"][$position]["strength"]) {
                $analysis["weakest_by_position"][$position] = $player;
            }
            
            $totalStrength += $strength;
        }
        
        if (count($players)) {
            $analysis["average_strength"] = round($totalStrength / count($players), 2);
        }
        
        return $analysis;
    }
    
    /**
     * @param WebSoccer $websoccer
     * @param DbConnection $db
     * @param int $buyerTeamId
     * @param array $squadAnalysis
     * @param int $currentCount
     * @param int $targetPlayers
     * @param int $maxPlayers
     * @param int $maxAffordableFee
     * @return array|null
     */
    private static function findBestComputerYouthPurchaseCandidate(
        WebSoccer $websoccer,
        DbConnection $db,
        $buyerTeamId,
        $squadAnalysis,
        $currentCount,
        $targetPlayers,
        $maxPlayers,
        $maxAffordableFee
        ) {
            
            if ($currentCount >= $maxPlayers || $maxAffordableFee <= 0) {
                return null;
            }
            
            $columns = "P.*, C.user_id AS seller_user_id, C.name AS seller_team_name";
            $fromTable = $websoccer->getConfig("db_prefix") . "_youthplayer AS P";
            $fromTable .= " INNER JOIN " . $websoccer->getConfig("db_prefix") . "_verein AS C ON C.id = P.team_id";
            
            $whereCondition = "P.transfer_fee > 0 AND P.team_id <> %d AND P.transfer_fee <= %d
                AND NOT EXISTS (
                    SELECT 1 FROM " . $websoccer->getConfig("db_prefix") . "_youth_transfer_offer AS O
                    WHERE O.player_id = P.id AND O.buyer_team_id = %d AND O.status = 'open'
                )
                ORDER BY P.strength DESC, P.age ASC";
            $parameters = array($buyerTeamId, $maxAffordableFee, $buyerTeamId);
            
            $result = $db->querySelect($columns, $fromTable, $whereCondition, $parameters, 100);
            
            $bestCandidate = null;
            $bestScore = -999999;
            
            while ($candidate = $result->fetch_array()) {
                if (!self::isYouthPlayerPriceReasonable($websoccer, $candidate)) {
                    continue;
                }
                
                $score = self::scoreYouthPurchaseCandidate($candidate, $squadAnalysis, $currentCount, $targetPlayers);
                
                if ($score > $bestScore) {
                    $bestCandidate = $candidate;
                    $bestScore = $score;
                }
            }
            
            $result->free();
            
            if ($bestCandidate === null || $bestScore < 0) {
                return null;
            }
            
            return $bestCandidate;
    }
    
    /**
     * @param array $candidate
     * @param array $squadAnalysis
     * @param int $currentCount
     * @param int $targetPlayers
     * @return int
     */
    private static function scoreYouthPurchaseCandidate($candidate, $squadAnalysis, $currentCount, $targetPlayers) {
        
        $desired = array(
            "Torwart" => 2,
            "Abwehr" => 6,
            "Mittelfeld" => 7,
            "Sturm" => 5
        );
        
        $position = $candidate["position"];
        $strength = (int) $candidate["strength"];
        $age = (int) $candidate["age"];
        $fee = (int) $candidate["transfer_fee"];
        
        if (!isset($desired[$position])) {
            return -1;
        }
        
        $positionCount = isset($squadAnalysis["position_counts"][$position])
        ? (int) $squadAnalysis["position_counts"][$position]
        : 0;
        
        $isPositionNeeded = $positionCount < $desired[$position];
        $isBelowTargetSize = $currentCount < $targetPlayers;
        $weakestSamePositionStrength = null;
        
        if (isset($squadAnalysis["weakest_by_position"][$position])) {
            $weakestSamePositionStrength = (int) $squadAnalysis["weakest_by_position"][$position]["strength"];
        }
        
        $isClearUpgrade = false;
        if ($weakestSamePositionStrength !== null && $strength >= ($weakestSamePositionStrength + 8)) {
            $isClearUpgrade = true;
        }
        
        if (!$isBelowTargetSize && !$isPositionNeeded && !$isClearUpgrade) {
            return -1;
        }
        
        $score = $strength;
        
        if ($isBelowTargetSize) {
            $score += 50;
        }
        
        if ($isPositionNeeded) {
            $score += 100;
        }
        
        if ($isClearUpgrade) {
            $score += 35;
        }
        
        if ($age <= 15) {
            $score += 10;
        } elseif ($age == 16) {
            $score += 5;
        } elseif ($age >= 18) {
            $score -= 20;
        }
        
        $fairValue = self::calculateFairYouthPlayerValue($candidate);
        if ($fee > $fairValue) {
            $score -= (int) round(($fee - $fairValue) / 10000);
        } else {
            $score += 15;
        }
        
        if ($strength < 25 && $fee > 1000000) {
            $score -= 50;
        }
        
        return $score;
    }
    
    /**
     * @param WebSoccer $websoccer
     * @param DbConnection $db
     * @param int $buyerTeamId
     * @param array $player
     * @return bool
     */
    public static function completeComputerYouthTransfer(WebSoccer $websoccer, DbConnection $db, $buyerTeamId, $player, $acceptedFee = 0) {
        
        $playerId = (int) $player["id"];
        $sellerTeamId = (int) $player["team_id"];
        $transferFee = (int) $acceptedFee > 0 ? (int) $acceptedFee : (int) $player["transfer_fee"];
        
        if (!self::isYouthPlayerPriceReasonable($websoccer, $player)) {
            echo "[ComputerYouthTeamsDataService] skipped overpriced youth player #" . $playerId . " with fee " . $transferFee . ".\n";
            return false;
        }
        
        if ($playerId <= 0 || $sellerTeamId <= 0 || $buyerTeamId <= 0 || $sellerTeamId === $buyerTeamId || $transferFee <= 0) {
            return false;
        }
        
        // Re-read the player before completing the transfer. The listing or owner may
        // have changed since the offer was created.
        $result = $db->querySelect(
            "*",
            $websoccer->getConfig("db_prefix") . "_youthplayer",
            "id = %d AND team_id = %d AND transfer_fee > 0",
            array($playerId, $sellerTeamId),
            1
        );
        $currentPlayer = $result->fetch_assoc();
        $result->free();
        if (!$currentPlayer) {
            return false;
        }
        $player = array_merge($player, $currentPlayer);
        $player["id"] = $playerId;
        $player["team_id"] = $sellerTeamId;
        $player["transfer_fee"] = $transferFee;

        $buyerTeam = TeamsDataService::getTeamSummaryById($websoccer, $db, $buyerTeamId);
        $sellerTeam = TeamsDataService::getTeamSummaryById($websoccer, $db, $sellerTeamId);
        
        if (!$buyerTeam || !$sellerTeam || !isset($buyerTeam["team_budget"]) || !isset($sellerTeam["team_budget"])) {
            return false;
        }
        if ((int) $buyerTeam["team_budget"] <= $transferFee) {
            return false;
        }
        
        $db->connection->begin_transaction();
        
        try {
            self::bookComputerYouthTransferFee($websoccer, $db, $buyerTeam, $sellerTeam, $transferFee);
            
            $db->queryUpdate(
                array(
                    "team_id" => $buyerTeamId,
                    "transfer_fee" => 0,
                    "transfer_start" => 0,
                    "transfer_ende" => 0,
                    "transfer_listed_by_cpu" => "0"
                ),
                $websoccer->getConfig("db_prefix") . "_youthplayer",
                "id = %d",
                $playerId
                );
            
            if (isset($sellerTeam["user_id"]) && (int) $sellerTeam["user_id"] > 0) {
                NotificationsDataService::createNotification(
                    $websoccer,
                    $db,
                    $sellerTeam["user_id"],
                    "youthteam_transfer_notification",
                    array(
                        "player" => $player["firstname"] . " " . $player["lastname"],
                        "newteam" => $buyerTeam["team_name"]
                    ),
                    "youth_transfer",
                    "team",
                    "id=" . $buyerTeamId
                    );
            }
            
            $db->connection->commit();
            return true;
            
        } catch (Exception $e) {
            $db->connection->rollback();
            throw $e;
        }
    }
    
    /**
     * Books the transfer fee.
     *
     * @param WebSoccer $websoccer
     * @param DbConnection $db
     * @param array $buyerTeam
     * @param array $sellerTeam
     * @param int $amount
     */
    private static function bookComputerYouthTransferFee(WebSoccer $websoccer, DbConnection $db, $buyerTeam, $sellerTeam, $amount) {
        
        $buyerTeamId = (int) $buyerTeam["team_id"];
        $sellerTeamId = (int) $sellerTeam["team_id"];
        $newBuyerBudget = (int) $buyerTeam["team_budget"] - $amount;
        $newSellerBudget = (int) $sellerTeam["team_budget"] + $amount;
        
        $db->queryUpdate(array("finanz_budget" => $newBuyerBudget), $websoccer->getConfig("db_prefix") . "_verein", "id = %d", $buyerTeamId);
        $db->queryUpdate(array("finanz_budget" => $newSellerBudget), $websoccer->getConfig("db_prefix") . "_verein", "id = %d", $sellerTeamId);
        
        self::createComputerYouthTransferStatement($websoccer, $db, $buyerTeam, 0 - $amount, "youthteam_transferfee_subject", $sellerTeam["team_name"]);
        self::createComputerYouthTransferStatement($websoccer, $db, $sellerTeam, $amount, "youthteam_transferfee_subject", $buyerTeam["team_name"]);
    }
    
    /**
     * @param WebSoccer $websoccer
     * @param DbConnection $db
     * @param array $team
     * @param int $amount
     * @param string $subject
     * @param string $sender
     */
    private static function createComputerYouthTransferStatement(WebSoccer $websoccer, DbConnection $db, $team, $amount, $subject, $sender) {
        
        $userId = isset($team["user_id"]) ? (int) $team["user_id"] : 0;
        
        if ($userId <= 0 && $websoccer->getConfig("no_transactions_for_teams_without_user")) {
            return;
        }
        
        $db->queryInsert(
            array(
                "verein_id" => $team["team_id"],
                "absender" => $sender,
                "betrag" => $amount,
                "datum" => $websoccer->getNowAsTimestamp(),
                "verwendung" => $subject
            ),
            $websoccer->getConfig("db_prefix") . "_konto"
            );
    }
    
    /**
     * Computer clubs accept valid manager-created youth match requests first.
     *
     * @param WebSoccer $websoccer
     * @param DbConnection $db
     */
    private static function acceptManagerYouthMatchRequests(WebSoccer $websoccer, DbConnection $db) {
        
        if ((int) $websoccer->getConfig("computer_youth_matches_enabled") !== 1
            || (int) $websoccer->getConfig("computer_youth_accept_manager_requests") !== 1) {
                echo "[ComputerYouthTeamsDataService] accepting manager youth requests disabled.\n";
                return;
            }
            
            $requests = self::getOpenManagerYouthMatchRequests($websoccer, $db);
            
            if (!count($requests)) {
                echo "[ComputerYouthTeamsDataService] no manager youth match requests found.\n";
                return;
            }
            
            $computerTeams = self::getComputerControlledTeams($websoccer, $db);
            $acceptedTotal = 0;
            
            foreach ($requests as $request) {
                $computerTeam = self::findComputerTeamForYouthRequest($websoccer, $db, $request, $computerTeams);
                
                if (!$computerTeam) {
                    continue;
                }
                
                if (self::acceptYouthMatchRequestByComputer($websoccer, $db, $request, $computerTeam)) {
                    $acceptedTotal++;
                }
            }
            
            echo "[ComputerYouthTeamsDataService] manager youth match requests accepted by computers: " . $acceptedTotal . ".\n";
    }
    
    /**
     * @param WebSoccer $websoccer
     * @param DbConnection $db
     * @return array
     */
    private static function getOpenManagerYouthMatchRequests(WebSoccer $websoccer, DbConnection $db) {
        
        $acceptHours = (int) $websoccer->getConfig("youth_matchrequest_accept_hours_in_advance");
        if ($acceptHours < 0) {
            $acceptHours = 0;
        }
        
        $timeBoundary = $websoccer->getNowAsTimestamp() + ($acceptHours * 3600);
        
        $columns = array(
            "R.id" => "request_id",
            "R.team_id" => "team_id",
            "R.matchdate" => "matchdate",
            "R.reward" => "reward",
            "C.name" => "team_name",
            "C.user_id" => "user_id"
        );
        
        $fromTable = $websoccer->getConfig("db_prefix") . "_youthmatch_request AS R";
        $fromTable .= " INNER JOIN " . $websoccer->getConfig("db_prefix") . "_verein AS C ON C.id = R.team_id";
        $whereCondition = "C.user_id IS NOT NULL AND C.user_id > 0 AND R.matchdate > %d ORDER BY R.matchdate ASC";
        
        $requests = array();
        $result = $db->querySelect($columns, $fromTable, $whereCondition, $timeBoundary);
        while ($request = $result->fetch_array()) {
            $requests[] = $request;
        }
        $result->free();
        
        return $requests;
    }
    
    /**
     * @param WebSoccer $websoccer
     * @param DbConnection $db
     * @param array $request
     * @param array $computerTeams
     * @return array|null
     */
    private static function findComputerTeamForYouthRequest(WebSoccer $websoccer, DbConnection $db, $request, $computerTeams) {
        
        if (!count($computerTeams)) {
            return null;
        }
        
        foreach ($computerTeams as $computerTeam) {
            $computerTeamId = (int) $computerTeam["id"];
            
            if (self::isYouthMatchPossibleForTeams($websoccer, $db, (int) $request["team_id"], $computerTeamId, (int) $request["matchdate"])) {
                return $computerTeam;
            }
        }
        
        return null;
    }
    
    /**
     * @param WebSoccer $websoccer
     * @param DbConnection $db
     * @param int $homeTeamId
     * @param int $guestTeamId
     * @param int $matchdate
     * @return bool
     */
    private static function isYouthMatchPossibleForTeams(WebSoccer $websoccer, DbConnection $db, $homeTeamId, $guestTeamId, $matchdate) {
        
        if ($homeTeamId <= 0 || $guestTeamId <= 0 || $homeTeamId === $guestTeamId) {
            return false;
        }
        
        $acceptHours = (int) $websoccer->getConfig("youth_matchrequest_accept_hours_in_advance");
        if ($acceptHours < 0) {
            $acceptHours = 0;
        }
        
        $timeBoundary = $websoccer->getNowAsTimestamp() + ($acceptHours * 3600);
        if ($matchdate <= $timeBoundary) {
            return false;
        }
        
        if (YouthPlayersDataService::countYouthPlayersOfTeam($websoccer, $db, $homeTeamId) < 11) {
            return false;
        }
        
        if (YouthPlayersDataService::countYouthPlayersOfTeam($websoccer, $db, $guestTeamId) < 11) {
            return false;
        }
        
        $maxMatchesPerDay = (int) $websoccer->getConfig("youth_match_maxperday");
        if ($maxMatchesPerDay <= 0) {
            $maxMatchesPerDay = 1;
        }
        
        if (YouthMatchesDataService::countMatchesOfTeamOnSameDay($websoccer, $db, $homeTeamId, $matchdate) >= $maxMatchesPerDay) {
            return false;
        }
        
        if (YouthMatchesDataService::countMatchesOfTeamOnSameDay($websoccer, $db, $guestTeamId, $matchdate) >= $maxMatchesPerDay) {
            return false;
        }
        
        if (self::countYouthMatchesBetweenTeamsOnSameDay($websoccer, $db, $homeTeamId, $guestTeamId, $matchdate) > 0) {
            return false;
        }
        
        return true;
    }
    
    /**
     * @param WebSoccer $websoccer
     * @param DbConnection $db
     * @param int $team1Id
     * @param int $team2Id
     * @param int $timestamp
     * @return int
     */
    private static function countYouthMatchesBetweenTeamsOnSameDay(WebSoccer $websoccer, DbConnection $db, $team1Id, $team2Id, $timestamp) {
        
        $dateObj = new DateTime();
        $dateObj->setTimestamp($timestamp);
        $dateObj->setTime(0, 0, 0);
        $minTimeBoundary = $dateObj->getTimestamp();
        $dateObj->setTime(23, 59, 59);
        $maxTimeBoundary = $dateObj->getTimestamp();
        
        $result = $db->querySelect(
            "COUNT(*) AS hits",
            $websoccer->getConfig("db_prefix") . "_youthmatch",
            "((home_team_id = %d AND guest_team_id = %d) OR (home_team_id = %d AND guest_team_id = %d)) AND matchdate BETWEEN %d AND %d",
            array($team1Id, $team2Id, $team2Id, $team1Id, $minTimeBoundary, $maxTimeBoundary)
            );
        
        $row = $result->fetch_array();
        $result->free();
        
        return ($row && isset($row["hits"])) ? (int) $row["hits"] : 0;
    }
    
    /**
     * @param WebSoccer $websoccer
     * @param DbConnection $db
     * @param array $request
     * @param array $computerTeam
     * @return bool
     */
    private static function acceptYouthMatchRequestByComputer(WebSoccer $websoccer, DbConnection $db, $request, $computerTeam) {
        
        $requestId = (int) $request["request_id"];
        $homeTeamId = (int) $request["team_id"];
        $guestTeamId = (int) $computerTeam["id"];
        $matchdate = (int) $request["matchdate"];
        $reward = (int) $request["reward"];
        
        if (!self::isYouthMatchPossibleForTeams($websoccer, $db, $homeTeamId, $guestTeamId, $matchdate)) {
            return false;
        }
        
        $homeTeam = self::getTeamSummaryByIdUncached($websoccer, $db, $homeTeamId);
        $guestTeam = self::getTeamSummaryByIdUncached($websoccer, $db, $guestTeamId);
        
        if (!$homeTeam || !$guestTeam) {
            return false;
        }
        
        if ($reward > 0 && (int) $homeTeam["team_budget"] <= $reward) {
            echo "[ComputerYouthTeamsDataService] skipped youth request #" . $requestId . " because reward cannot be paid.\n";
            return false;
        }
        
        $db->connection->begin_transaction();
        
        try {
            $currentRequest = self::getYouthMatchRequestById($websoccer, $db, $requestId);
            
            if (!$currentRequest || (int) $currentRequest["team_id"] !== $homeTeamId) {
                $db->connection->rollback();
                return false;
            }
            
            if (!self::isYouthMatchPossibleForTeams($websoccer, $db, $homeTeamId, $guestTeamId, $matchdate)) {
                $db->connection->rollback();
                return false;
            }
            
            if ($reward > 0) {
                self::bookComputerAcceptedYouthMatchReward($websoccer, $db, $homeTeam, $guestTeam, $reward);
            }
            
            $db->queryInsert(
                array("matchdate" => $matchdate, "home_team_id" => $homeTeamId, "guest_team_id" => $guestTeamId),
                $websoccer->getConfig("db_prefix") . "_youthmatch"
                );
            
            $db->queryDelete($websoccer->getConfig("db_prefix") . "_youthmatch_request", "id = %d", $requestId);
            
            if (isset($homeTeam["user_id"]) && (int) $homeTeam["user_id"] > 0) {
                NotificationsDataService::createNotification(
                    $websoccer,
                    $db,
                    (int) $homeTeam["user_id"],
                    "youthteam_matchrequest_accept_notification",
                    array("team" => $guestTeam["team_name"], "date" => $websoccer->getFormattedDatetime($matchdate)),
                    "youthmatch_accept",
                    "youth-matches",
                    null,
                    $homeTeamId
                    );
            }
            
            $db->connection->commit();
            echo "[ComputerYouthTeamsDataService] computer team #" . $guestTeamId . " accepted youth match request #" . $requestId . ".\n";
            return true;
            
        } catch (Exception $e) {
            $db->connection->rollback();
            throw $e;
        }
    }
    
    /**
     * @param WebSoccer $websoccer
     * @param DbConnection $db
     * @param int $requestId
     * @return array|null
     */
    private static function getYouthMatchRequestById(WebSoccer $websoccer, DbConnection $db, $requestId) {
        
        $result = $db->querySelect("*", $websoccer->getConfig("db_prefix") . "_youthmatch_request", "id = %d", $requestId, 1);
        $request = $result->fetch_array();
        $result->free();
        
        return $request ? $request : null;
    }
    
    /**
     * @param WebSoccer $websoccer
     * @param DbConnection $db
     * @param int $teamId
     * @return array|null
     */
    private static function getTeamSummaryByIdUncached(WebSoccer $websoccer, DbConnection $db, $teamId) {
        
        $columns = array(
            "C.id" => "team_id",
            "C.name" => "team_name",
            "C.finanz_budget" => "team_budget",
            "C.bild" => "team_picture",
            "C.user_id" => "user_id",
            "L.name" => "team_league_name",
            "L.id" => "team_league_id"
        );
        
        $fromTable = $websoccer->getConfig("db_prefix") . "_verein AS C";
        $fromTable .= " LEFT JOIN " . $websoccer->getConfig("db_prefix") . "_liga AS L ON C.liga_id = L.id";
        
        $result = $db->querySelect($columns, $fromTable, "C.status = 1 AND C.id = %d", $teamId, 1);
        $team = $result->fetch_array();
        $result->free();
        
        return $team ? $team : null;
    }
    
    /**
     * @param WebSoccer $websoccer
     * @param DbConnection $db
     * @param array $homeTeam
     * @param array $guestTeam
     * @param int $amount
     */
    private static function bookComputerAcceptedYouthMatchReward(WebSoccer $websoccer, DbConnection $db, $homeTeam, $guestTeam, $amount) {
        
        if ($amount <= 0) {
            return;
        }
        
        self::changeTeamBudgetDirectly($websoccer, $db, (int) $homeTeam["team_id"], 0 - $amount);
        self::changeTeamBudgetDirectly($websoccer, $db, (int) $guestTeam["team_id"], $amount);
        self::createComputerYouthAccountStatement($websoccer, $db, $homeTeam, 0 - $amount, "youthteam_matchrequest_reward_subject", $guestTeam["team_name"]);
        self::createComputerYouthAccountStatement($websoccer, $db, $guestTeam, $amount, "youthteam_matchrequest_reward_subject", $homeTeam["team_name"]);
    }
    
    /**
     * @param WebSoccer $websoccer
     * @param DbConnection $db
     * @param int $teamId
     * @param int $amount
     */
    private static function changeTeamBudgetDirectly(WebSoccer $websoccer, DbConnection $db, $teamId, $amount) {
        
        $teamId = (int) $teamId;
        $amount = (int) $amount;
        
        if (!$teamId || !$amount) {
            return;
        }
        
        $sql = "UPDATE " . $websoccer->getConfig("db_prefix") . "_verein SET finanz_budget = finanz_budget + " . $amount . " WHERE id = " . $teamId;
        $db->executeQuery($sql);
    }
    
    /**
     * @param WebSoccer $websoccer
     * @param DbConnection $db
     * @param array $team
     * @param int $amount
     * @param string $subject
     * @param string $sender
     */
    private static function createComputerYouthAccountStatement(WebSoccer $websoccer, DbConnection $db, $team, $amount, $subject, $sender) {
        
        $userId = isset($team["user_id"]) ? (int) $team["user_id"] : 0;
        
        if ($userId <= 0 && $websoccer->getConfig("no_transactions_for_teams_without_user")) {
            return;
        }
        
        $db->queryInsert(
            array(
                "verein_id" => $team["team_id"],
                "absender" => $sender,
                "betrag" => $amount,
                "datum" => $websoccer->getNowAsTimestamp(),
                "verwendung" => $subject
            ),
            $websoccer->getConfig("db_prefix") . "_konto"
            );
    }
    
    /**
     * Computer clubs accept valid computer-created youth match requests after manager requests.
     *
     * @param WebSoccer $websoccer
     * @param DbConnection $db
     */
    private static function acceptComputerYouthMatchRequests(WebSoccer $websoccer, DbConnection $db) {
        
        if ((int) $websoccer->getConfig("computer_youth_matches_enabled") !== 1
            || (int) $websoccer->getConfig("computer_youth_accept_computer_requests") !== 1) {
                echo "[ComputerYouthTeamsDataService] accepting computer youth requests disabled.\n";
                return;
            }
            
            $maxAcceptances = (int) $websoccer->getConfig("computer_youth_max_computer_request_acceptances_per_run");
            if ($maxAcceptances <= 0) {
                $maxAcceptances = 10;
            }
            
            $maxAcceptancesPerClub = (int) $websoccer->getConfig("computer_youth_max_request_acceptances_per_club_per_run");
            if ($maxAcceptancesPerClub <= 0) {
                $maxAcceptancesPerClub = 2;
            }
            
            $requests = self::getOpenComputerYouthMatchRequests($websoccer, $db);
            
            if (!count($requests)) {
                echo "[ComputerYouthTeamsDataService] no computer youth match requests found.\n";
                return;
            }
            
            $computerTeams = self::getComputerControlledTeams($websoccer, $db);
            $acceptedTotal = 0;
            $acceptedByTeam = array();
            
            foreach ($requests as $request) {
                if ($acceptedTotal >= $maxAcceptances) {
                    break;
                }
                
                $computerTeam = self::findComputerTeamForYouthRequestWithRunLimit($websoccer, $db, $request, $computerTeams, $acceptedByTeam, $maxAcceptancesPerClub);
                
                if (!$computerTeam) {
                    continue;
                }
                
                if (self::acceptYouthMatchRequestByComputer($websoccer, $db, $request, $computerTeam)) {
                    $acceptedTotal++;
                    $computerTeamId = (int) $computerTeam["id"];
                    if (!isset($acceptedByTeam[$computerTeamId])) {
                        $acceptedByTeam[$computerTeamId] = 0;
                    }
                    $acceptedByTeam[$computerTeamId]++;
                }
            }
            
            echo "[ComputerYouthTeamsDataService] computer youth match requests accepted by computers: " . $acceptedTotal . ".\n";
    }
    
    /**
     * @param WebSoccer $websoccer
     * @param DbConnection $db
     * @param array $request
     * @param array $computerTeams
     * @param array $acceptedByTeam
     * @param int $maxAcceptancesPerClub
     * @return array|null
     */
    private static function findComputerTeamForYouthRequestWithRunLimit(WebSoccer $websoccer, DbConnection $db, $request, $computerTeams, $acceptedByTeam, $maxAcceptancesPerClub) {
        
        if (!count($computerTeams)) {
            return null;
        }
        
        foreach ($computerTeams as $computerTeam) {
            $computerTeamId = (int) $computerTeam["id"];
            
            if (isset($acceptedByTeam[$computerTeamId]) && $acceptedByTeam[$computerTeamId] >= $maxAcceptancesPerClub) {
                continue;
            }
            
            if (self::isYouthMatchPossibleForTeams($websoccer, $db, (int) $request["team_id"], $computerTeamId, (int) $request["matchdate"])) {
                return $computerTeam;
            }
        }
        
        return null;
    }
    
    /**
     * @param WebSoccer $websoccer
     * @param DbConnection $db
     * @return array
     */
    private static function getOpenComputerYouthMatchRequests(WebSoccer $websoccer, DbConnection $db) {
        
        $acceptHours = (int) $websoccer->getConfig("youth_matchrequest_accept_hours_in_advance");
        if ($acceptHours < 0) {
            $acceptHours = 0;
        }
        
        $minimumRequestAgeHours = (int) $websoccer->getConfig("computer_youth_accept_computer_requests_min_age_hours");
        if ($minimumRequestAgeHours < 0) {
            $minimumRequestAgeHours = 12;
        }
        
        $timeBoundary = $websoccer->getNowAsTimestamp() + ($acceptHours * 3600);
        $createdBoundary = $websoccer->getNowAsTimestamp() - ($minimumRequestAgeHours * 3600);
        $hasCreatedDate = self::hasYouthMatchRequestCreatedDateColumn($websoccer, $db);
        
        $columns = array(
            "R.id" => "request_id",
            "R.team_id" => "team_id",
            "R.matchdate" => "matchdate",
            "R.reward" => "reward",
            "C.name" => "team_name",
            "C.user_id" => "user_id"
        );
        
        if ($hasCreatedDate) {
            $columns["R.created_date"] = "created_date";
        }
        
        $fromTable = $websoccer->getConfig("db_prefix") . "_youthmatch_request AS R";
        $fromTable .= " INNER JOIN " . $websoccer->getConfig("db_prefix") . "_verein AS C ON C.id = R.team_id";
        
        if ($hasCreatedDate) {
            $whereCondition = "(C.user_id IS NULL OR C.user_id <= 0) AND R.matchdate > %d AND R.created_date > 0 AND R.created_date <= %d ORDER BY R.matchdate ASC";
            $parameters = array($timeBoundary, $createdBoundary);
        } else {
            $whereCondition = "(C.user_id IS NULL OR C.user_id <= 0) AND R.matchdate > %d ORDER BY R.matchdate ASC";
            $parameters = $websoccer->getNowAsTimestamp() + (($acceptHours + $minimumRequestAgeHours) * 3600);
        }
        
        $requests = array();
        $result = $db->querySelect($columns, $fromTable, $whereCondition, $parameters);
        while ($request = $result->fetch_array()) {
            $requests[] = $request;
        }
        $result->free();
        
        return $requests;
    }
    
    /**
     * Creates limited open youth match requests for computer-controlled clubs.
     *
     * @param WebSoccer $websoccer
     * @param DbConnection $db
     */
    private static function createLimitedComputerYouthMatchRequests(WebSoccer $websoccer, DbConnection $db) {
        
        if ((int) $websoccer->getConfig("computer_youth_matches_enabled") !== 1) {
            echo "[ComputerYouthTeamsDataService] computer youth match request creation disabled.\n";
            return;
        }
        
        $maxOpenRequestsTotal = (int) $websoccer->getConfig("computer_youth_max_open_requests_total");
        $maxOpenRequestsPerClub = (int) $websoccer->getConfig("computer_youth_max_open_requests_per_club");
        $maxNewRequestsPerRun = (int) $websoccer->getConfig("computer_youth_max_new_requests_per_run");
        
        if ($maxOpenRequestsTotal <= 0) {
            $maxOpenRequestsTotal = 20;
        }
        
        if ($maxOpenRequestsPerClub <= 0) {
            $maxOpenRequestsPerClub = 1;
        }
        
        if ($maxNewRequestsPerRun <= 0) {
            $maxNewRequestsPerRun = 5;
        }
        
        $currentOpenComputerRequests = self::countOpenComputerYouthMatchRequests($websoccer, $db);
        
        if ($currentOpenComputerRequests >= $maxOpenRequestsTotal) {
            echo "[ComputerYouthTeamsDataService] computer youth request limit already reached.\n";
            return;
        }
        
        $remainingTotalSlots = $maxOpenRequestsTotal - $currentOpenComputerRequests;
        $maxToCreate = min($maxNewRequestsPerRun, $remainingTotalSlots);
        
        if ($maxToCreate <= 0) {
            return;
        }
        
        $hasCreatedDate = self::hasYouthMatchRequestCreatedDateColumn($websoccer, $db);
        $teams = self::getComputerControlledTeams($websoccer, $db);
        shuffle($teams);
        
        $createdTotal = 0;
        
        foreach ($teams as $team) {
            if ($createdTotal >= $maxToCreate) {
                break;
            }
            
            $teamId = (int) $team["id"];
            
            if (YouthPlayersDataService::countYouthPlayersOfTeam($websoccer, $db, $teamId) < 11) {
                continue;
            }
            
            if (self::countOpenYouthMatchRequestsOfTeam($websoccer, $db, $teamId) >= $maxOpenRequestsPerClub) {
                continue;
            }
            
            $matchdate = self::findPossibleYouthRequestDateForComputerTeam($websoccer, $db, $teamId);
            if (!$matchdate) {
                continue;
            }
            
            $columns = array(
                "team_id" => $teamId,
                "matchdate" => $matchdate,
                "reward" => 0
            );
            
            if ($hasCreatedDate) {
                $columns["created_date"] = $websoccer->getNowAsTimestamp();
            }
            
            $db->queryInsert($columns, $websoccer->getConfig("db_prefix") . "_youthmatch_request");
            
            $createdTotal++;
            echo "[ComputerYouthTeamsDataService] created computer youth match request for team #" . $teamId . " at " . date("Y-m-d H:i", $matchdate) . ".\n";
        }
        
        echo "[ComputerYouthTeamsDataService] computer youth match requests created total: " . $createdTotal . ".\n";
    }
    
    /**
     * @param WebSoccer $websoccer
     * @param DbConnection $db
     * @return int
     */
    private static function countOpenComputerYouthMatchRequests(WebSoccer $websoccer, DbConnection $db) {
        
        $columns = "COUNT(*) AS hits";
        $fromTable = $websoccer->getConfig("db_prefix") . "_youthmatch_request AS R";
        $fromTable .= " INNER JOIN " . $websoccer->getConfig("db_prefix") . "_verein AS C ON C.id = R.team_id";
        
        $result = $db->querySelect(
            $columns,
            $fromTable,
            "(C.user_id IS NULL OR C.user_id <= 0) AND R.matchdate > %d",
            $websoccer->getNowAsTimestamp()
            );
        
        $row = $result->fetch_array();
        $result->free();
        
        return ($row && isset($row["hits"])) ? (int) $row["hits"] : 0;
    }
    
    /**
     * @param WebSoccer $websoccer
     * @param DbConnection $db
     * @param int $teamId
     * @return int
     */
    private static function countOpenYouthMatchRequestsOfTeam(WebSoccer $websoccer, DbConnection $db, $teamId) {
        
        $result = $db->querySelect(
            "COUNT(*) AS hits",
            $websoccer->getConfig("db_prefix") . "_youthmatch_request",
            "team_id = %d AND matchdate > %d",
            array($teamId, $websoccer->getNowAsTimestamp())
            );
        
        $row = $result->fetch_array();
        $result->free();
        
        return ($row && isset($row["hits"])) ? (int) $row["hits"] : 0;
    }
    
    /**
     * @param WebSoccer $websoccer
     * @param DbConnection $db
     * @param int $teamId
     * @return int|null
     */
    private static function findPossibleYouthRequestDateForComputerTeam(WebSoccer $websoccer, DbConnection $db, $teamId) {
        
        $futureDays = (int) $websoccer->getConfig("youth_matchrequest_max_futuredays");
        if ($futureDays <= 0) {
            $futureDays = 14;
        }
        
        $acceptHours = (int) $websoccer->getConfig("youth_matchrequest_accept_hours_in_advance");
        if ($acceptHours < 0) {
            $acceptHours = 0;
        }
        
        $allowedTimes = self::getAllowedYouthMatchRequestTimes($websoccer);
        if (!count($allowedTimes)) {
            $allowedTimes = array("14:00", "15:00");
        }
        
        $now = $websoccer->getNowAsTimestamp();
        $minTimestamp = $now + ($acceptHours * 3600);
        
        for ($attempt = 0; $attempt < 50; $attempt++) {
            $daysAhead = mt_rand(1, $futureDays);
            $time = $allowedTimes[mt_rand(0, count($allowedTimes) - 1)];
            $dateString = date("Y-m-d", $now + ($daysAhead * 86400)) . " " . $time . ":00";
            $timestamp = strtotime($dateString);
            
            if (!$timestamp || $timestamp <= $minTimestamp) {
                continue;
            }
            
            if (!self::canTeamCreateYouthRequestAtDate($websoccer, $db, $teamId, $timestamp)) {
                continue;
            }
            
            return $timestamp;
        }
        
        return null;
    }
    
    /**
     * @param WebSoccer $websoccer
     * @return array
     */
    private static function getAllowedYouthMatchRequestTimes(WebSoccer $websoccer) {
        
        $config = $websoccer->getConfig("youth_matchrequest_allowedtimes");
        $rawTimes = is_array($config) ? $config : explode(",", (string) $config);
        $times = array();
        
        foreach ($rawTimes as $time) {
            $time = trim((string) $time);
            if (preg_match("/^[0-2][0-9]:[0-5][0-9]$/", $time)) {
                $hour = (int) substr($time, 0, 2);
                if ($hour <= 23) {
                    $times[] = $time;
                }
            }
        }
        
        return $times;
    }
    
    /**
     * @param WebSoccer $websoccer
     * @param DbConnection $db
     * @param int $teamId
     * @param int $matchdate
     * @return bool
     */
    private static function canTeamCreateYouthRequestAtDate(WebSoccer $websoccer, DbConnection $db, $teamId, $matchdate) {
        
        $maxMatchesPerDay = (int) $websoccer->getConfig("youth_match_maxperday");
        if ($maxMatchesPerDay <= 0) {
            $maxMatchesPerDay = 1;
        }
        
        if (YouthMatchesDataService::countMatchesOfTeamOnSameDay($websoccer, $db, $teamId, $matchdate) >= $maxMatchesPerDay) {
            return false;
        }
        
        if (self::countOpenYouthMatchRequestsOfTeamOnSameDay($websoccer, $db, $teamId, $matchdate) > 0) {
            return false;
        }
        
        return true;
    }
    
    /**
     * @param WebSoccer $websoccer
     * @param DbConnection $db
     * @param int $teamId
     * @param int $timestamp
     * @return int
     */
    private static function countOpenYouthMatchRequestsOfTeamOnSameDay(WebSoccer $websoccer, DbConnection $db, $teamId, $timestamp) {
        
        $dateObj = new DateTime();
        $dateObj->setTimestamp($timestamp);
        $dateObj->setTime(0, 0, 0);
        $minTimeBoundary = $dateObj->getTimestamp();
        $dateObj->setTime(23, 59, 59);
        $maxTimeBoundary = $dateObj->getTimestamp();
        
        $result = $db->querySelect(
            "COUNT(*) AS hits",
            $websoccer->getConfig("db_prefix") . "_youthmatch_request",
            "team_id = %d AND matchdate BETWEEN %d AND %d",
            array($teamId, $minTimeBoundary, $maxTimeBoundary)
            );
        
        $row = $result->fetch_array();
        $result->free();
        
        return ($row && isset($row["hits"])) ? (int) $row["hits"] : 0;
    }
    
    /**
     * Creates direct computer-vs-computer youth matches.
     *
     * @param WebSoccer $websoccer
     * @param DbConnection $db
     */
    private static function createDirectComputerYouthMatches(WebSoccer $websoccer, DbConnection $db) {
        
        if ((int) $websoccer->getConfig("computer_youth_matches_enabled") !== 1
            || (int) $websoccer->getConfig("computer_youth_direct_matches_enabled") !== 1) {
                echo "[ComputerYouthTeamsDataService] direct computer youth matches disabled.\n";
                return;
            }
            
            $maxFutureDirectMatchesTotal = (int) $websoccer->getConfig("computer_youth_max_future_direct_matches_total");
            if ($maxFutureDirectMatchesTotal <= 0) {
                $maxFutureDirectMatchesTotal = 50;
            }
            
            $currentFutureDirectMatches = self::countFutureDirectComputerYouthMatches($websoccer, $db);
            if ($currentFutureDirectMatches >= $maxFutureDirectMatchesTotal) {
                echo "[ComputerYouthTeamsDataService] future direct computer youth match limit already reached.\n";
                return;
            }
            
            $maxMatchesPerRun = (int) $websoccer->getConfig("computer_youth_max_direct_matches_per_run");
            if ($maxMatchesPerRun <= 0) {
                $maxMatchesPerRun = 2;
            }
            
            // Hard safety cap: direct computer-vs-computer matches should never flood the calendar.
            if ($maxMatchesPerRun > 2) {
                $maxMatchesPerRun = 2;
            }
            
            $remainingFutureSlots = $maxFutureDirectMatchesTotal - $currentFutureDirectMatches;
            $maxMatchesPerRun = min($maxMatchesPerRun, $remainingFutureSlots);
            
            if ($maxMatchesPerRun <= 0) {
                echo "[ComputerYouthTeamsDataService] no future direct computer youth match slots available.\n";
                return;
            }
            
            echo "[ComputerYouthTeamsDataService] direct computer youth max matches per run: " . $maxMatchesPerRun . ".\n";
            
            $teams = self::getComputerControlledTeams($websoccer, $db);
            shuffle($teams);
            $eligibleTeams = array();
            
            foreach ($teams as $team) {
                $teamId = (int) $team["id"];
                if (YouthPlayersDataService::countYouthPlayersOfTeam($websoccer, $db, $teamId) >= 11) {
                    $eligibleTeams[] = $team;
                }
            }
            
            if (count($eligibleTeams) < 2) {
                echo "[ComputerYouthTeamsDataService] not enough computer youth teams for direct matches.\n";
                return;
            }
            
            $createdTotal = 0;
            $usedPairs = array();
            
            foreach ($eligibleTeams as $homeTeam) {
                if ($createdTotal >= $maxMatchesPerRun) {
                    break;
                }
                
                $homeTeamId = (int) $homeTeam["id"];
                if (!self::canComputerTeamHaveAnotherFutureDirectYouthMatch($websoccer, $db, $homeTeamId)) {
                    continue;
                }
                
                $possibleGuests = $eligibleTeams;
                shuffle($possibleGuests);
                
                foreach ($possibleGuests as $guestTeam) {
                    if ($createdTotal >= $maxMatchesPerRun) {
                        break;
                    }
                    
                    $guestTeamId = (int) $guestTeam["id"];
                    
                    if ($homeTeamId === $guestTeamId) {
                        continue;
                    }
                    
                    if (!self::canComputerTeamHaveAnotherFutureDirectYouthMatch($websoccer, $db, $guestTeamId)) {
                        continue;
                    }
                    
                    $pairKey = self::getYouthTeamPairKey($homeTeamId, $guestTeamId);
                    if (isset($usedPairs[$pairKey])) {
                        continue;
                    }
                    
                    $matchdate = self::findPossibleDirectYouthMatchDateForComputerTeams($websoccer, $db, $homeTeamId, $guestTeamId);
                    if (!$matchdate) {
                        continue;
                    }
                    
                    $db->queryInsert(
                        array("matchdate" => $matchdate, "home_team_id" => $homeTeamId, "guest_team_id" => $guestTeamId),
                        $websoccer->getConfig("db_prefix") . "_youthmatch"
                        );
                    
                    $usedPairs[$pairKey] = true;
                    $createdTotal++;
                    
                    echo "[ComputerYouthTeamsDataService] created direct computer youth match: team #" . $homeTeamId
                    . " vs team #" . $guestTeamId
                    . " at " . date("Y-m-d H:i", $matchdate) . ".\n";
                    
                    break;
                }
            }
            
            echo "[ComputerYouthTeamsDataService] direct computer youth matches created total: " . $createdTotal . ".\n";
    }
    
    /**
     * @param WebSoccer $websoccer
     * @param DbConnection $db
     * @param int $homeTeamId
     * @param int $guestTeamId
     * @return int|null
     */
    private static function findPossibleDirectYouthMatchDateForComputerTeams(WebSoccer $websoccer, DbConnection $db, $homeTeamId, $guestTeamId) {
        
        $futureDays = (int) $websoccer->getConfig("youth_matchrequest_max_futuredays");
        if ($futureDays <= 0) {
            $futureDays = 14;
        }
        
        $acceptHours = (int) $websoccer->getConfig("youth_matchrequest_accept_hours_in_advance");
        if ($acceptHours < 0) {
            $acceptHours = 0;
        }
        
        $allowedTimes = self::getAllowedYouthMatchRequestTimes($websoccer);
        if (!count($allowedTimes)) {
            $allowedTimes = array("14:00", "15:00");
        }
        
        $now = $websoccer->getNowAsTimestamp();
        $minTimestamp = $now + ($acceptHours * 3600);
        
        for ($attempt = 0; $attempt < 80; $attempt++) {
            $daysAhead = mt_rand(1, $futureDays);
            $time = $allowedTimes[mt_rand(0, count($allowedTimes) - 1)];
            $dateString = date("Y-m-d", $now + ($daysAhead * 86400)) . " " . $time . ":00";
            $timestamp = strtotime($dateString);
            
            if (!$timestamp || $timestamp <= $minTimestamp) {
                continue;
            }
            
            if (!self::isYouthMatchPossibleForTeams($websoccer, $db, $homeTeamId, $guestTeamId, $timestamp)) {
                continue;
            }
            
            if (self::countOpenYouthMatchRequestsOfTeamOnSameDay($websoccer, $db, $homeTeamId, $timestamp) > 0) {
                continue;
            }
            
            if (self::countOpenYouthMatchRequestsOfTeamOnSameDay($websoccer, $db, $guestTeamId, $timestamp) > 0) {
                continue;
            }
            
            return $timestamp;
        }
        
        return null;
    }
    
    /**
     * @param int $team1Id
     * @param int $team2Id
     * @return string
     */
    private static function getYouthTeamPairKey($team1Id, $team2Id) {
        
        $team1Id = (int) $team1Id;
        $team2Id = (int) $team2Id;
        
        if ($team1Id < $team2Id) {
            return $team1Id . "-" . $team2Id;
        }
        
        return $team2Id . "-" . $team1Id;
    }
    
    /**
     * @param WebSoccer $websoccer
     * @param array $player
     * @return bool
     */
    private static function isYouthPlayerPriceReasonable(WebSoccer $websoccer, $player) {
        
        $requestedFee = (int) $player["transfer_fee"];
        if ($requestedFee <= 0) {
            return false;
        }
        
        $fairValue = self::calculateFairYouthPlayerValue($player);
        $maxPriceFactor = (int) $websoccer->getConfig("computer_youth_max_price_factor");
        
        if ($maxPriceFactor <= 0) {
            $maxPriceFactor = 120;
        }
        
        $maximumAllowedFee = (int) round($fairValue * ($maxPriceFactor / 100));
        
        return $requestedFee <= $maximumAllowedFee;
    }
    
    /**
     * @param array $player
     * @return int
     */
    private static function calculateFairYouthPlayerValue($player) {
        
        if (isset($player["market_value"]) && (int) $player["market_value"] > 0) {
            return (int) $player["market_value"];
        }
        $strength = max(1, (int) $player["strength"]);
        $age = max(14, (int) $player["age"]);
        $position = isset($player["position"]) ? $player["position"] : "";
        $fairValue = $strength * $strength * 1000;
        
        if ($age <= 14) {
            $fairValue *= 1.35;
        } elseif ($age == 15) {
            $fairValue *= 1.25;
        } elseif ($age == 16) {
            $fairValue *= 1.10;
        } elseif ($age == 17) {
            $fairValue *= 0.95;
        } else {
            $fairValue *= 0.70;
        }
        
        if ($position === "Torwart") {
            $fairValue *= 1.10;
        }
        
        if ($strength < 20) {
            $fairValue *= 0.60;
        } elseif ($strength < 30) {
            $fairValue *= 0.80;
        }
        
        $fairValue = (int) round($fairValue / 1000) * 1000;
        
        if ($fairValue < 25000) {
            $fairValue = 25000;
        }
        
        return $fairValue;
    }
    
    /**
     * @param WebSoccer $websoccer
     * @param DbConnection $db
     * @return int
     */
    private static function countFutureDirectComputerYouthMatches(WebSoccer $websoccer, DbConnection $db) {
        
        $columns = "COUNT(*) AS hits";
        $fromTable = $websoccer->getConfig("db_prefix") . "_youthmatch AS M";
        $fromTable .= " INNER JOIN " . $websoccer->getConfig("db_prefix") . "_verein AS H ON H.id = M.home_team_id";
        $fromTable .= " INNER JOIN " . $websoccer->getConfig("db_prefix") . "_verein AS G ON G.id = M.guest_team_id";
        
        $whereCondition = "M.matchdate > %d AND (H.user_id IS NULL OR H.user_id <= 0) AND (G.user_id IS NULL OR G.user_id <= 0)";
        
        $result = $db->querySelect($columns, $fromTable, $whereCondition, $websoccer->getNowAsTimestamp());
        $row = $result->fetch_array();
        $result->free();
        
        return ($row && isset($row["hits"])) ? (int) $row["hits"] : 0;
    }
    
    /**
     * @param WebSoccer $websoccer
     * @param DbConnection $db
     * @param int $teamId
     * @return bool
     */
    private static function canComputerTeamHaveAnotherFutureDirectYouthMatch(WebSoccer $websoccer, DbConnection $db, $teamId) {
        
        $maxFutureDirectMatchesPerClub = (int) $websoccer->getConfig("computer_youth_max_future_direct_matches_per_club");
        if ($maxFutureDirectMatchesPerClub <= 0) {
            $maxFutureDirectMatchesPerClub = 3;
        }
        
        $columns = "COUNT(*) AS hits";
        $fromTable = $websoccer->getConfig("db_prefix") . "_youthmatch AS M";
        $fromTable .= " INNER JOIN " . $websoccer->getConfig("db_prefix") . "_verein AS H ON H.id = M.home_team_id";
        $fromTable .= " INNER JOIN " . $websoccer->getConfig("db_prefix") . "_verein AS G ON G.id = M.guest_team_id";
        
        $whereCondition = "M.matchdate > %d AND (M.home_team_id = %d OR M.guest_team_id = %d) AND (H.user_id IS NULL OR H.user_id <= 0) AND (G.user_id IS NULL OR G.user_id <= 0)";
        
        $result = $db->querySelect(
            $columns,
            $fromTable,
            $whereCondition,
            array($websoccer->getNowAsTimestamp(), $teamId, $teamId)
            );
        
        $row = $result->fetch_array();
        $result->free();
        $currentFutureMatches = ($row && isset($row["hits"])) ? (int) $row["hits"] : 0;
        
        return $currentFutureMatches < $maxFutureDirectMatchesPerClub;
    }
    
    /**
     * @param WebSoccer $websoccer
     * @param DbConnection $db
     * @return bool
     */
    private static function hasYouthMatchRequestCreatedDateColumn(WebSoccer $websoccer, DbConnection $db) {
        
        static $hasColumn = null;
        
        if ($hasColumn !== null) {
            return $hasColumn;
        }
        
        $table = $websoccer->getConfig("db_prefix") . "_youthmatch_request";
        $result = $db->executeQuery("SHOW COLUMNS FROM " . $table . " LIKE 'created_date'");
        $hasColumn = ($result && $result->num_rows > 0);
        
        if ($result) {
            $result->free();
        }
        
        return $hasColumn;
    }
}

?>