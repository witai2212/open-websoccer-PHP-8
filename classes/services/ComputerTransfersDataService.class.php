<?php

class ComputerTransfersDataService {
    
    const MAX_SQUAD_SIZE = 23;
    const MAX_OFFERS = 8; // fallback; configurable
    const MAX_LENDING_OFFERS_PER_TEAM = 2;
    const MAX_BORROWED_PLAYERS_PER_TEAM = 2;
    const MIN_SQUAD_SIZE_AFTER_LENDING = 20;
    const MIN_SQUAD_SIZE_BEFORE_BORROWING = 20;
    const CPU_LOAN_OFFER_DURATION_DAYS = 21;
    const CPU_LOAN_OFFER_COOLDOWN_DAYS = 20;
    const CPU_LOAN_REQUEST_EXPIRY_DAYS = 10;
    const TRANSFER_DURATION_DAYS = 1;
    const MAX_PERCENTAGE_PLAYERS_ON_TL = 2;
    const MAX_PLAYERS_ON_TL = 800;

    // Computer offers shall normally stay close to player market value.
    // In a small number of cases the limits are widened a little to keep the market dynamic.
    const CPU_MARKET_VALUE_MIN_RATIO = 0.70;
    const CPU_MARKET_VALUE_MAX_RATIO = 1.15;
    const CPU_MARKET_VALUE_EXCEPTION_CHANCE = 15;
    const CPU_MARKET_VALUE_EXCEPTION_MIN_RATIO = 0.60;
    const CPU_MARKET_VALUE_EXCEPTION_MAX_RATIO = 1.30;
    const CPU_LOAN_FEE_MIN_MARKET_RATIO = 0.005;
    const CPU_LOAN_FEE_MAX_MARKET_RATIO = 0.030;

    CONST MAX_OFFER_DEVIATION = 1.15;
    
    private static $logger;
    
    public static function setLogger($logger) {
        self::$logger = $logger;
    }

	public static function executeComputerBids(WebSoccer $websoccer, DbConnection $db, $executeOpenTransfers = true) {
	    
	    if ($executeOpenTransfers) {
	        TransfermarketDataService::executeOpenTransfers($websoccer, $db);
	    }

	    $MAX_PLAYERS_ON_TL = $websoccer->getConfig("transfermarket_max_players_on_tl");
	    
	    // TL time expired
	    self::TLExpired($websoccer, $db);
	    
	    // delete offers if not on TL
	    self::offerButNotONTL($websoccer, $db);
	    
		$now = $websoccer->getNowAsTimestamp();
		$teamsPerRun = max(1, self::getOptionalConfigInt($websoccer, 'computer_transfers_teams_per_run', 100));
		$maxOffersPerTeam = max(1, self::getOptionalConfigInt($websoccer, 'computer_transfers_max_active_offers_per_team', 8));
		$maxOffersPerPlayer = max(1, self::getOptionalConfigInt($websoccer, 'computer_transfers_max_offers_per_player', 6));
		$teamIds = self::getComputerControlledTeams($websoccer, $db, $teamsPerRun);

		// --- Managing Transfer List (only if condition is met) ---
		$playersOnTL = self::getNumberOfPlayersOnTL($websoccer, $db);
		
		echo"[executeComputerBids]: ONTL: ". $playersOnTL ." - MAXONTL: ". $MAX_PLAYERS_ON_TL ."\n";

		if($playersOnTL<$MAX_PLAYERS_ON_TL) {
		//if ((($playersOnTL / $totalPlayers) < self::MAX_PERCENTAGE_PLAYERS_ON_TL) || ($playersOnTL < self::MAX_PLAYERS_ON_TL)) {
			foreach ($teamIds as $teamId) {
				$squad = self::getTeamSquad($websoccer, $db, $teamId);

				// If the squad size exceeds MAX_SQUAD_SIZE, manage transfer list
				if (count($squad) > self::MAX_SQUAD_SIZE) {
					self::manageTransferList($websoccer, $db, $teamId, $squad);
				}
			}
		}

		// --- Managing loans: all computer-controlled teams may offer and borrow players. ---
		if ($websoccer->getConfig("lending_enabled")) {
			self::expireOldComputerLoanOffers($websoccer, $db);
			self::expireOldComputerLoanRequests($websoccer, $db);

			$loanTeamIds = self::getComputerControlledTeamsForLoans($websoccer, $db);
			foreach ($loanTeamIds as $teamId) {
				self::manageLendingList($websoccer, $db, $teamId);
				self::borrowLendablePlayer($websoccer, $db, $teamId);
			}
		}

		// --- Bidding on Players (always runs) ---
        foreach ($teamIds as $teamId) {
            PlayerPrecontractDataService::createComputerOffers($websoccer, $db, $teamId);
            
			$budget = self::getTeamBudget($websoccer, $db, $teamId);
			$currentOffers = self::getTeamOfferCount($websoccer, $db, $teamId);
			$squad = self::getLoanRelevantTeamSquad($websoccer, $db, $teamId);

			// The squad planner logic starts with squad needs. Use the full active squad here,
			// not only older players, so recent transfers do not create false shortages.

			$teamStrength = self::calculateAverageStrength($squad);
			$positionsCount = self::countPositions($squad);
			$transferNeeds = self::getPositionNeeds($positionsCount);
			$traitNeeds = self::getTraitNeedsForTeam($websoccer, $db, $teamId);
			$playersOnMarket = self::getPlayersOnTransferMarket($websoccer, $db);

			foreach ($playersOnMarket as $player) {
				if ($player['transfermarkt'] == '1') {
					if ($currentOffers >= $maxOffersPerTeam) break;
					$traitNeedScore = self::scorePlayerForTraitNeeds($websoccer, $db, $player, $traitNeeds);
					if (!self::positionFitsTransferNeeds($player, $transferNeeds, $squad, $traitNeedScore)) continue;
				    
				    $playerId = $player['id'];
				    
				    $attributes['w_passing'] = $player['w_passing'];
				    $attributes['shooting'] = $player['shooting'];
				    $attributes['heading'] = $player['heading'];
				    $attributes['tackling'] = $player['tackling'];
				    $attributes['freekick'] = $player['freekick'];
				    $attributes['pace'] = $player['pace'];
				    $attributes['creativity'] = $player['creativity'];
				    $attributes['influence'] = $player['influence'];
				    $attributes['flair'] = $player['flair'];
				    $attributes['penalty'] = $player['penalty'];
				    $attributes['penalty_killing'] = $player['penalty_killing'];

				    //old: $playerStrength = PlayersDataService::calculatePlayerStrengt_h($player['w_strength'], $player['w_stamina'], $player['w_freshness'], $player['w_satisfaction'], $player['w_technique'], $player['w_talent'], $player['age'], $player['position'], $attributes);
					$playerStrength = PlayersStrengthDataService::calculatePlayerStrength2($websoccer, $db, $playerId);

					// Check if player's strength is within the acceptable range
					if (!self::isStrengthWithinRange($playerStrength, $teamStrength)) continue;

					// Calculate bid amount and salary. Skip players whose minimum bid is outside
					// the accepted computer-offer market value range.
					$bidAmount = self::calculateBidAmount($player, $traitNeedScore);
					if ($bidAmount <= 0) {
						continue;
					}
					$salaryAmount = self::calculateSalary($player);
					$goalAmount = self::calculateGoal($player);

					// Place bid if the team has enough budget
					if ($bidAmount <= $budget) {
						
						$offersForPlayerId = self::countOffersForPlayerId($websoccer, $db, $player['id']);
						if ($offersForPlayerId < $maxOffersPerPlayer) {
							
							self::placeBid($websoccer, $db, $teamId, $player['id'], $bidAmount, $now, $salaryAmount, $goalAmount);
							self::deleteTooManyOffers($websoccer, $db, $maxOffersPerPlayer);
							$currentOffers++;
							if ($currentOffers >= $maxOffersPerTeam) break;
						} else {
							self::deleteTooManyOffers($websoccer, $db, $maxOffersPerPlayer);
						}
					}
				}
			}
		}
	}
    
    
    private static function getComputerControlledTeams(WebSoccer $websoccer, DbConnection $db, $limit = 100) {
        $query = "
            SELECT id
            FROM ". $websoccer->getConfig('db_prefix') ."_verein
            WHERE user_id IS NULL OR user_id <= '0'
            ORDER BY RAND()
            LIMIT ". max(1, (int) $limit);
        $result = $db->executeQuery($query);
        
        $teamIds = [];
        while ($team = $result->fetch_array()) {
            $teamIds[] = $team['id'];
        }
        $result->free();
        return $teamIds;
    }
    
    private static function getComputerControlledTeamsForLoans(WebSoccer $websoccer, DbConnection $db) {
        $query = "
            SELECT id
            FROM ". $websoccer->getConfig('db_prefix') ."_verein
            WHERE user_id IS NULL OR user_id <= '0'
            ORDER BY RAND()";
        $result = $db->executeQuery($query);

        $teamIds = array();
        while ($team = $result->fetch_array()) {
            $teamIds[] = $team['id'];
        }
        $result->free();
        return $teamIds;
    }

    private static function getTeamBudget(WebSoccer $websoccer, DbConnection $db, $teamId) {
        
        $sqlStr = "SELECT finanz_budget
                    FROM ". $websoccer->getConfig("db_prefix") ."_verein
                    WHERE id='$teamId'";
        $result = $db->executeQuery($sqlStr);
        $budget = $result->fetch_array();
        $result->free();
        
        return $budget['finanz_budget']*100;
        
    }
    
    private static function getTeamOfferCount(WebSoccer $websoccer, DbConnection $db, $teamId) {
        
        $sqlStr = "SELECT COUNT(*) AS offer_count
                    FROM ". $websoccer->getConfig("db_prefix") ."_transfer_angebot
                    WHERE verein_id='$teamId'";
        $result = $db->executeQuery($sqlStr);
        $offers = $result->fetch_assoc();
        $result->free();
        
        return $offers['offer_count'];
        
    }
    
    private static function getTeamSquad(WebSoccer $websoccer, DbConnection $db, $teamId) {
        
        $squad = [];
		
		$transfer_deadline = $websoccer->getNowAsTimestamp() - (1045*86400);
        
        $sqlStr = "SELECT id, position, w_technik, w_staerke, w_kondition, w_frische
                    FROM ". $websoccer->getConfig("db_prefix") ."_spieler
                    WHERE verein_id = '$teamId' AND last_transfer<='$transfer_deadline'";
        $result = $db->executeQuery($sqlStr);
        //while ($player = $result->fetch_array())
        while ($player = $result->fetch_assoc()) {
            $squad[] = $player;
        }
        $result->free();
        
        return $squad;
    }
    
    private static function getPlayersOnTransferMarket(WebSoccer $websoccer, DbConnection $db) {
        
        $players = [];
        
        $query = "
            SELECT * FROM ". $websoccer->getConfig('db_prefix') ."_spieler WHERE transfermarkt = '1'";
        $result = $db->executeQuery($query);
        
        while ($player = $result->fetch_assoc()) {
            $players[] = $player;
        }
        return $players;
    }
    
    private static function getNumberOfPlayersOnTL(WebSoccer $websoccer, DbConnection $db) {
        
        $query = "SELECT COUNT(*) AS onTL
                    FROM ". $websoccer->getConfig('db_prefix') ."_spieler
                    WHERE transfermarkt = 1";
        $result = $db->executeQuery($query);
        $tl = $result->fetch_assoc();
        $result->free();
        
        return $tl['onTL'];
    }
    
    private static function getNumberOfPlayers(WebSoccer $websoccer, DbConnection $db) {
        
        $query = "
            SELECT COUNT(*) AS players
            FROM ". $websoccer->getConfig('db_prefix') ."_spieler
            WHERE status='1'";
        $result = $db->executeQuery($query);
        $players = $result->fetch_assoc();
        $result->free();
        
        return $players['players'];
    }
    
    private static function calculateAverageStrength($squad) {
        $totalStrength = 0;
        $count = 0;
        foreach ($squad as $player) {
            $totalStrength += ($player['w_technik'] + $player['w_staerke'] + $player['w_kondition'] + $player['w_frische']) / 4;
            $count++;
        }
        return $count > 0 ? $totalStrength / $count : 0;
    }
    
    private static function calculatePlayerStrengthX($player) {
        return ($player['w_technik'] + $player['w_staerke'] + $player['w_kondition'] + $player['w_frische']) / 4;
    }
    
    private static function isStrengthWithinRange($playerStrength, $teamStrength) {
        $lowerBound = $teamStrength * 0.75;
        $upperBound = $teamStrength * 1.30;
        return $playerStrength >= $lowerBound && $playerStrength <= $upperBound;
    }
    
    private static function calculateBidAmount($player, $traitNeedScore = 0) {

        $marketValue = isset($player['marktwert']) ? (float) $player['marktwert'] : 0;
        $minimumBid = isset($player['transfer_mindestgebot']) ? (float) $player['transfer_mindestgebot'] : 0;

        if ($marketValue <= 0) {
            return ($minimumBid > 0) ? self::roundMoney($minimumBid) : 0;
        }

        $range = self::getComputerMarketValueRange($marketValue);

        $traitNeedScore = max(0, min(12, (int) $traitNeedScore));
        if ($traitNeedScore > 0) {
            // Traits already increase the market value in Phase 2. Phase 4 only widens
            // the CPU willingness slightly for players who solve a concrete squad need.
            $range['max'] = $range['max'] * (1 + min(0.10, $traitNeedScore * 0.0125));
        }

        // Never let a CPU bid become silly. If the seller's minimum bid is above
        // the maximum accepted CPU range, the computer club simply skips the player.
        if ($minimumBid > $range['max']) {
            return 0;
        }

        $minOffer = max($range['min'], $minimumBid);
        $maxOffer = $range['max'];

        if ($minOffer > $maxOffer) {
            return 0;
        }

        return self::randomMoneyInRange($minOffer, $maxOffer);
    }

    private static function getComputerMarketValueRange($marketValue) {
        $exceptionalCase = (rand(1, 100) <= self::CPU_MARKET_VALUE_EXCEPTION_CHANCE);

        $minRatio = $exceptionalCase ? self::CPU_MARKET_VALUE_EXCEPTION_MIN_RATIO : self::CPU_MARKET_VALUE_MIN_RATIO;
        $maxRatio = $exceptionalCase ? self::CPU_MARKET_VALUE_EXCEPTION_MAX_RATIO : self::CPU_MARKET_VALUE_MAX_RATIO;

        return array(
            'min' => max(0, (float) $marketValue * $minRatio),
            'max' => max(0, (float) $marketValue * $maxRatio)
        );
    }

    private static function randomMoneyInRange($minAmount, $maxAmount) {
        $min = (int) ceil(max(0, $minAmount) / 100);
        $max = (int) floor(max(0, $maxAmount) / 100);

        if ($max < $min) {
            return self::roundMoney($minAmount);
        }

        return (float) (rand($min, $max) * 100);
    }

    private static function roundMoney($amount) {
        return (float) (round(max(0, (float) $amount) / 100) * 100);
    }
    
    private static function calculateSalary($player) {
        
        //salary
        $baseSalary = $player['vertrag_gehalt'];
        $adjustSalary = $baseSalary * (rand(-10, 10) / 100);
        
        return $baseSalary + $adjustSalary;
    }
    
    private static function calculateGoal($player) {
        
        //goal prime
        $baseGoal = $player['vertrag_torpraemie'];
        $adjustGoal = $baseGoal * (rand(-10, 10) / 100);
        
        return $baseGoal + $adjustGoal;
    }
    
    private static function placeBid(WebSoccer $websoccer, DbConnection $db, $teamId, $playerId, $bidAmount, $now, $salaryAmount, $goalAmount) {
        
        if (class_exists('ClubPartnershipDataService')) {
            $firstOptionLock = ClubPartnershipDataService::getProfessionalFirstOptionLock($websoccer, $db, $playerId, $teamId);
            if ($firstOptionLock && (!isset($firstOptionLock['can_use']) || $firstOptionLock['can_use'] !== '1')) {
                return;
            }
        }
        
        $rand = rand(10, 50)/100;
        $handgeld = $bidAmount *  $rand;
        
        $insStr = "INSERT INTO ". $websoccer->getConfig('db_prefix') . "_transfer_angebot
                    (spieler_id, verein_id, user_id, abloese, handgeld, vertrag_spiele, datum, vertrag_gehalt, vertrag_torpraemie)
                    VALUES ('$playerId', '$teamId', NULL, '$bidAmount', '$handgeld', '60', '$now', '$salaryAmount', '$goalAmount')";
        $db->executeQuery($insStr);
                
        echo"- BID: ". $playerId ."\n";

		// create notification for owner
		$playerData = PlayersDataService::getPlayerById($websoccer, $db, $playerId);
		$playerName = (strlen($playerData["player_pseudonym"])) ? $playerData["player_pseudonym"] : $playerData["player_firstname"] . " " . $playerData["player_lastname"];
			
		if($playerData["team_user_id"]>0) {
			NotificationsDataService::createNotification($websoccer, $db, $playerData["team_user_id"], "received_offer_for_player",
				array("player" => $playerName), "myoffers", "myoffers", "");
		}
    }
    
    private static function manageTransferList(WebSoccer $websoccer, DbConnection $db, $teamId, $squad) {
        $positionsCount = self::countPositions($squad);
        $traitNeeds = self::getTraitNeedsForTeam($websoccer, $db, $teamId);
        $currentTransferList = self::getTeamTransferList($websoccer, $db, $teamId);
        
        if (count($currentTransferList) >= 3) return;
        
        $transferCandidates = [];
        foreach ($squad as $player) {
            if (self::canListPlayerForTransfer($player, $positionsCount, $traitNeeds, $websoccer, $db)) {
                $transferCandidates[] = $player;
            }
        }
        
        shuffle($transferCandidates);
        foreach ($transferCandidates as $player) {
            if (count($currentTransferList) >= 3) break;
            self::listPlayerForTransfer($websoccer, $db, $player['id']);
            $currentTransferList[] = $player;
        }
    }
    
    private static function countPositions($squad) {
        $counts = [
            'Torwart' => 0,
            'Abwehr' => 0,
            'Mittelfeld' => 0,
            'Sturm' => 0
        ];
        foreach ($squad as $player) {
            if (isset($counts[$player['position']])) {
                $counts[$player['position']]++;
            }
        }
        return $counts;
    }
    
    private static function canListPlayerForTransfer($player, $positionsCount, $traitNeeds = array(), WebSoccer $websoccer = null, DbConnection $db = null) {
        $position = $player['position'];
        $targets = self::getSquadDepthTargets();
        if (!isset($targets[$position]) || !isset($positionsCount[$position]) || $positionsCount[$position] <= $targets[$position]) {
            return false;
        }

        // Do not list one of the few specialists if the squad planner says this trait is missing.
        if ($websoccer && $db && self::scorePlayerForTraitNeeds($websoccer, $db, $player, $traitNeeds) > 0) {
            return false;
        }
        return true;
    }
    
    private static function getTeamTransferList(WebSoccer $websoccer, DbConnection $db, $teamId) {
        
        $transferList = [];
        
        $query = "
            SELECT id, position
            FROM ". $websoccer->getConfig('db_prefix') ."_spieler
            WHERE verein_id ='$teamId' AND transfermarkt = 1";
        $result = $db->executeQuery($query);
        while ($player = $result->fetch_assoc()) {
            $transferList[] = $player;
        }
        return $transferList;
    }
    
    private static function listPlayerForTransfer(WebSoccer $websoccer, DbConnection $db, $playerId) {
        
        $transfermarket_duration_days = $websoccer->getConfig("transfermarket_duration_days");
        
        $transfer_start = $websoccer->getNowAsTimestamp();
        $transfer_ende = $websoccer->getNowAsTimestamp() + ($transfermarket_duration_days * 24 * 60 * 60);
        
        $updStr = "UPDATE ". $websoccer->getConfig('db_prefix') . "_spieler
                    SET transfermarkt='1', transfer_start='".$transfer_start."',
                            transfer_ende='".$transfer_ende."'
                    WHERE id='$playerId'";
        $db->executeQuery($updStr);
		
        echo"--- ON TL: ". $playerId ."\n";
        
		/* check if player on watchlist
		 *
		 */
		$watchList = array();

		$query = "SELECT * FROM ". $websoccer->getConfig('db_prefix') ."_watchlist
				  WHERE spieler_id ='$playerId'";
		$result = $db->executeQuery($query);

		while ($player = $result->fetch_assoc()) {
			$watchList[] = $player;
		}

		if (!empty($watchList)) {
			foreach ($watchList as $watch) {
				// Notify user that player is on transfer list
				// Watchlist: id, spieler_id, verein_id
				$playerData = PlayersDataService::getPlayerById($websoccer, $db, $playerId);
				$playerName = $playerData['player_firstname'] ." ". $playerData['player_lastname'];
				$userData = TeamsDataService::getTeamById($websoccer, $db, $watch['verein_id']);
				
				NotificationsDataService::createNotification(
					$websoccer, $db, $userData['team_user_id'], 'player_on_watchlist_notification', array('player' => $playerName), '', '');
			}
		}
        
    }
    
    
    private static function manageLendingList(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $squad = self::getLoanRelevantTeamSquad($websoccer, $db, $teamId);
        if (count($squad) <= self::MIN_SQUAD_SIZE_AFTER_LENDING) {
            return;
        }

        $currentLoanOffers = self::getTeamLendingOfferCount($websoccer, $db, $teamId);
        if ($currentLoanOffers >= self::MAX_LENDING_OFFERS_PER_TEAM) {
            return;
        }

        $positionsCount = self::countPositions($squad);
        $profile = self::getLoanProfile($websoccer, $db, $teamId, $squad);
        $candidates = array();
        foreach ($squad as $player) {
            if (self::canOfferPlayerForLoan($websoccer, $db, $player, $positionsCount)) {
                $player['loan_score'] = self::scoreLoanOfferCandidate($player, $profile);
                $candidates[] = $player;
            }
        }

        usort($candidates, function($a, $b) {
            if ($a['loan_score'] == $b['loan_score']) {
                return 0;
            }
            return ($a['loan_score'] > $b['loan_score']) ? -1 : 1;
        });

        foreach ($candidates as $player) {
            if ($currentLoanOffers >= self::MAX_LENDING_OFFERS_PER_TEAM) {
                break;
            }
            if ($player['loan_score'] < 25) {
                continue;
            }

            self::markPlayerForLoan($websoccer, $db, $teamId, $player, $profile);
            $currentLoanOffers++;
        }
    }

    private static function borrowLendablePlayer(WebSoccer $websoccer, DbConnection $db, $teamId) {
        if (self::getBorrowedPlayersCount($websoccer, $db, $teamId) >= self::MAX_BORROWED_PLAYERS_PER_TEAM) {
            return;
        }

        $squad = self::getLoanRelevantTeamSquad($websoccer, $db, $teamId);
        if (count($squad) >= self::MAX_SQUAD_SIZE) {
            return;
        }

        $positionsCount = self::countPositions($squad);
        $needs = self::getPositionNeeds($positionsCount);
        $traitNeeds = self::getTraitNeedsForTeam($websoccer, $db, $teamId);
        if (count($squad) >= self::MIN_SQUAD_SIZE_BEFORE_BORROWING && count($needs) == 0 && !count($traitNeeds)) {
            return;
        }

        $budget = self::getTeamBudgetRaw($websoccer, $db, $teamId);
        if ($budget <= 0) {
            return;
        }

        $teamStrength = self::calculateAverageStrength($squad);
        $candidates = self::getLendablePlayersForComputerTeam($websoccer, $db, $teamId, $needs);
        foreach ($candidates as $index => $candidate) {
            $candidates[$index]['cpu_trait_need_score'] = self::scorePlayerForTraitNeeds($websoccer, $db, $candidate, $traitNeeds);
        }
        usort($candidates, array('ComputerTransfersDataService', 'sortByCpuTraitNeedScore'));
        foreach ($candidates as $player) {
            if (!self::computerLoanStrengthFits($teamStrength, $player)) {
                continue;
            }

            $maxMatches = min((int) $websoccer->getConfig('lending_matches_max'), (int) $player['vertrag_spiele'] - 1);
            $minMatches = (int) $websoccer->getConfig('lending_matches_min');
            if ($maxMatches < $minMatches) {
                continue;
            }

            $offer = LoanDataService::getOfferByPlayerId($websoccer, $db, $player['id']);
            $salaryShare = isset($offer['salary_share_percent']) ? LoanDataService::normalizeSalaryShare($offer['salary_share_percent']) : 100;
            $optionType = isset($offer['option_type']) ? LoanDataService::normalizeOptionType($offer['option_type']) : LoanDataService::OPTION_NONE;
            $buyFee = isset($offer['buy_fee']) ? (int) $offer['buy_fee'] : 0;

            $matches = rand($minMatches, $maxMatches);
            $totalFee = $matches * (int) $player['lending_fee'];
            $salaryPart = (int) round((int) $player['vertrag_gehalt'] * $salaryShare / 100);
            $minimumRequiredBudget = $totalFee + (5 * $salaryPart);
            if ($optionType == LoanDataService::OPTION_OBLIGATION) {
                $minimumRequiredBudget += $buyFee;
            }
            if ($budget < $minimumRequiredBudget) {
                continue;
            }

            if ((int) $player['lender_user_id'] > 0) {
                self::createComputerLoanRequest($websoccer, $db, $teamId, $player, $matches, $salaryShare, $optionType, $buyFee);
            } else {
                self::executeComputerLoan($websoccer, $db, $teamId, $player, $matches, $totalFee, $salaryShare, $optionType, $buyFee);
            }
            break;
        }
    }

    private static function expireOldComputerLoanOffers(WebSoccer $websoccer, DbConnection $db) {
        $durationDays = self::getOptionalConfigInt($websoccer, 'lending_cpu_offer_duration_days', self::CPU_LOAN_OFFER_DURATION_DAYS);
        if ($durationDays <= 0) {
            return;
        }

        $threshold = $websoccer->getNowAsTimestamp() - ($durationDays * 24 * 60 * 60);
        $dbPrefix = $websoccer->getConfig('db_prefix');
        $query = "
            SELECT O.id AS offer_id, O.player_id
            FROM ". $dbPrefix ."_loan_offer AS O
            INNER JOIN ". $dbPrefix ."_spieler AS P ON P.id = O.player_id
            INNER JOIN ". $dbPrefix ."_verein AS V ON V.id = O.lender_team_id
            WHERE O.status = 'open'
              AND O.created_by_computer = '1'
              AND O.created_date > 0
              AND O.created_date <= '". (int) $threshold ."'
              AND P.verein_id = O.lender_team_id
              AND P.lending_fee > 0
              AND (P.lending_owner_id IS NULL OR P.lending_owner_id = 0)
              AND (V.user_id IS NULL OR V.user_id <= 0)";
        $result = $db->executeQuery($query);

        $expired = array();
        while ($row = $result->fetch_assoc()) {
            $expired[] = $row;
        }
        $result->free();

        foreach ($expired as $row) {
            $playerId = (int) $row['player_id'];
            $offerId = (int) $row['offer_id'];

            $db->queryUpdate(
                array('lending_fee' => 0),
                $dbPrefix . '_spieler',
                "id = %d AND lending_fee > 0 AND (lending_owner_id IS NULL OR lending_owner_id = 0)",
                $playerId
            );
            $db->queryUpdate(
                array('status' => 'expired'),
                $dbPrefix . '_loan_offer',
                "id = %d AND status = 'open' AND created_by_computer = '1'",
                $offerId
            );
            if (class_exists('LoanRequestDataService')) {
                LoanRequestDataService::expireOpenRequestsForPlayer($websoccer, $db, $playerId);
            }

            echo "--- LOAN OFFER EXPIRED: ". $playerId ."\n";
        }
    }

    private static function expireOldComputerLoanRequests(WebSoccer $websoccer, DbConnection $db) {
        if (!class_exists('LoanRequestDataService')) {
            return;
        }

        $expiryDays = self::getOptionalConfigInt($websoccer, 'lending_cpu_request_expiry_days', self::CPU_LOAN_REQUEST_EXPIRY_DAYS);
        if ($expiryDays <= 0) {
            return;
        }

        $threshold = $websoccer->getNowAsTimestamp() - ($expiryDays * 24 * 60 * 60);
        $db->queryUpdate(
            array('status' => LoanRequestDataService::STATUS_EXPIRED, 'answered_date' => $websoccer->getNowAsTimestamp()),
            $websoccer->getConfig('db_prefix') . '_loan_request',
            "status = 'open' AND created_by_computer = '1' AND created_date > 0 AND created_date <= %d",
            (int) $threshold
        );
    }

    private static function hasRecentComputerLoanOffer(WebSoccer $websoccer, DbConnection $db, $playerId) {
        $cooldownDays = self::getOptionalConfigInt($websoccer, 'lending_cpu_offer_cooldown_days', self::CPU_LOAN_OFFER_COOLDOWN_DAYS);
        if ($cooldownDays <= 0) {
            return false;
        }

        $threshold = $websoccer->getNowAsTimestamp() - ($cooldownDays * 24 * 60 * 60);
        $query = "
            SELECT id
            FROM ". $websoccer->getConfig('db_prefix') ."_loan_offer
            WHERE player_id = '". (int) $playerId ."'
              AND created_by_computer = '1'
              AND created_date >= '". (int) $threshold ."'
            LIMIT 1";
        $result = $db->executeQuery($query);
        $row = $result->fetch_assoc();
        $result->free();

        return (isset($row['id']));
    }

    private static function getOptionalConfigInt(WebSoccer $websoccer, $name, $default) {
        try {
            $value = $websoccer->getConfig($name);
            if ($value === NULL || $value === '') {
                return (int) $default;
            }
            return (int) $value;
        } catch (Exception $e) {
            return (int) $default;
        }
    }

    private static function getLoanRelevantTeamSquad(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $squad = array();
        $query = "
            SELECT id, verein_id, position, age, w_talent, w_staerke, w_technik, w_kondition, w_frische, marktwert, vertrag_gehalt, vertrag_spiele,
                   lending_fee, lending_matches, lending_owner_id, transfermarkt
            FROM ". $websoccer->getConfig('db_prefix') ."_spieler
            WHERE verein_id = '". (int) $teamId ."'
              AND status = '1'";
        $result = $db->executeQuery($query);
        while ($player = $result->fetch_assoc()) {
            $squad[] = $player;
        }
        $result->free();

        return $squad;
    }

    private static function getTeamLendingOfferCount(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $query = "
            SELECT COUNT(*) AS loan_offers
            FROM ". $websoccer->getConfig('db_prefix') ."_spieler
            WHERE verein_id = '". (int) $teamId ."'
              AND status = '1'
              AND lending_fee > 0
              AND (lending_owner_id IS NULL OR lending_owner_id = 0)";
        $result = $db->executeQuery($query);
        $row = $result->fetch_assoc();
        $result->free();

        return (int) $row['loan_offers'];
    }

    private static function getBorrowedPlayersCount(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $query = "
            SELECT COUNT(*) AS borrowed_players
            FROM ". $websoccer->getConfig('db_prefix') ."_spieler
            WHERE verein_id = '". (int) $teamId ."'
              AND status = '1'
              AND lending_matches > 0
              AND lending_owner_id > 0";
        $result = $db->executeQuery($query);
        $row = $result->fetch_assoc();
        $result->free();

        return (int) $row['borrowed_players'];
    }

    private static function canOfferPlayerForLoan(WebSoccer $websoccer, DbConnection $db, $player, $positionsCount) {
        if ($player['transfermarkt'] == '1') {
            return false;
        }
        if ((int) $player['lending_fee'] > 0 || (int) $player['lending_owner_id'] > 0 || (int) $player['lending_matches'] > 0) {
            return false;
        }
        if ((int) $player['vertrag_spiele'] <= (int) $websoccer->getConfig('lending_matches_min')) {
            return false;
        }
        if (self::hasRecentComputerLoanOffer($websoccer, $db, $player['id'])) {
            return false;
        }

        $traitNeeds = self::getTraitNeedsForTeam($websoccer, $db, isset($player['verein_id']) ? (int) $player['verein_id'] : 0);
        return self::canListPlayerForTransfer($player, $positionsCount, $traitNeeds, $websoccer, $db);
    }

    private static function markPlayerForLoan(WebSoccer $websoccer, DbConnection $db, $teamId, $player, $profile) {
        $fee = self::calculateLendingFee($player);
        $salaryShare = self::calculateComputerLoanSalaryShare($player, $profile);
        $optionType = self::calculateComputerLoanOptionType($player, $profile);
        $buyFee = 0;
        if ($optionType != LoanDataService::OPTION_NONE) {
            $buyFee = self::calculateComputerLoanBuyFee($player);
        }

        $updStr = "UPDATE ". $websoccer->getConfig('db_prefix') . "_spieler
                    SET lending_fee = '". (int) $fee ."'
                    WHERE id = '". (int) $player['id'] ."'";
        $db->executeQuery($updStr);
        LoanDataService::saveOffer($websoccer, $db, $player['id'], $teamId, $fee, $salaryShare, $optionType, $buyFee, true);

        echo "--- ON LOAN LIST: ". $player['id'] ."\n";
    }

    private static function calculateLendingFee($player) {
        $marketValue = max(0, (int) $player['marktwert']);
        $salary = max(0, (int) $player['vertrag_gehalt']);
        $fee = round(($marketValue * 0.01) + ($salary * 0.25));

        // Loan fees are per match, therefore not 70-115% of market value. Still,
        // cap CPU-generated fees to a sane market-value-based corridor.
        $minFee = max(1000, (int) round($marketValue * self::CPU_LOAN_FEE_MIN_MARKET_RATIO));
        $maxFee = max($minFee, (int) round($marketValue * self::CPU_LOAN_FEE_MAX_MARKET_RATIO));
        $maxFee = min($maxFee, LoanDataService::getMaxLoanFee($player));
        $fee = max($minFee, min($maxFee, $fee));

        return self::roundMoney($fee);
    }

    private static function calculateComputerLoanBuyFee($player) {
        $marketValue = isset($player['marktwert']) ? (float) $player['marktwert'] : 0;
        if ($marketValue <= 0) {
            return 0;
        }

        $range = self::getComputerMarketValueRange($marketValue);
        $minFee = max($range['min'], LoanDataService::getMinBuyFee($player));
        $maxFee = min($range['max'], LoanDataService::getMaxBuyFee($player));

        if ($minFee > $maxFee) {
            return self::roundMoney($marketValue);
        }

        return self::randomMoneyInRange($minFee, $maxFee);
    }


    private static function getSquadDepthTargets() {
        return array(
            'Torwart' => 2,
            'Abwehr' => 7,
            'Mittelfeld' => 7,
            'Sturm' => 4
        );
    }

    private static function positionFitsTransferNeeds($player, $needs, $squad, $traitNeedScore = 0) {
        $traitNeedScore = max(0, (int) $traitNeedScore);
        if (!count($needs)) {
            // Clubs with a complete squad must still participate in the market:
            // they can replace weaker players or react to an attractive opportunity.
            if ($traitNeedScore > 0) {
                return (rand(1, 100) <= min(90, 55 + ($traitNeedScore * 4)));
            }
            return (rand(1, 100) <= 35);
        }

        if (isset($player['position']) && in_array($player['position'], $needs)) {
            return true;
        }

        // A very good specialist can still be interesting if the squad is not full,
        // but hard positional shortages remain the main driver.
        return ($traitNeedScore >= 6 && rand(1, 100) <= 35);
    }

    private static function getPositionNeeds($positionsCount) {
        $minimum = self::getSquadDepthTargets();

        $needs = array();
        foreach ($minimum as $position => $minCount) {
            $count = isset($positionsCount[$position]) ? $positionsCount[$position] : 0;
            if ($count < $minCount) {
                $needs[] = $position;
            }
        }

        return $needs;
    }

    private static function getLendablePlayersForComputerTeam(WebSoccer $websoccer, DbConnection $db, $teamId, $needs) {
        $wherePosition = '';
        if (count($needs) > 0) {
            $positionParts = array();
            foreach ($needs as $position) {
                $positionParts[] = "'" . str_replace("'", "''", $position) . "'";
            }
            $wherePosition = " AND P.position IN (" . implode(',', $positionParts) . ")";
        }

        $query = "
            SELECT P.*, C.name AS lender_team_name, C.user_id AS lender_user_id
            FROM ". $websoccer->getConfig('db_prefix') ."_spieler AS P
            INNER JOIN ". $websoccer->getConfig('db_prefix') ."_verein AS C ON C.id = P.verein_id
            WHERE P.status = '1'
              AND P.verein_id <> '". (int) $teamId ."'
              AND P.transfermarkt <> '1'
              AND P.lending_fee > 0
              AND (P.lending_owner_id IS NULL OR P.lending_owner_id = 0)
              AND P.vertrag_spiele > '". (int) $websoccer->getConfig('lending_matches_min') ."'
              ". $wherePosition ."
            ORDER BY RAND()
            LIMIT 30";
        $result = $db->executeQuery($query);

        $players = array();
        while ($player = $result->fetch_assoc()) {
            $players[] = $player;
        }
        $result->free();

        return $players;
    }

    private static function getTeamBudgetRaw(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $sqlStr = "SELECT finanz_budget
                    FROM ". $websoccer->getConfig('db_prefix') ."_verein
                    WHERE id='". (int) $teamId ."'";
        $result = $db->executeQuery($sqlStr);
        $budget = $result->fetch_array();
        $result->free();

        return (int) $budget['finanz_budget'];
    }

    private static function createComputerLoanRequest(WebSoccer $websoccer, DbConnection $db, $borrowerTeamId, $player, $matches, $salaryShare = 100, $optionType = 'none', $buyFee = 0) {
        $borrowerTeam = TeamsDataService::getTeamSummaryById($websoccer, $db, $borrowerTeamId);
        $borrowerUserId = isset($borrowerTeam['user_id']) ? (int) $borrowerTeam['user_id'] : 0;

        LoanRequestDataService::createRequest(
            $websoccer,
            $db,
            $player,
            $borrowerTeamId,
            $borrowerUserId,
            $matches,
            (int) $player['lending_fee'],
            $salaryShare,
            $optionType,
            $buyFee,
            true
        );

        echo "--- LOAN REQUEST: ". $player['id'] ." -> ". $borrowerTeamId ."\n";
    }

    private static function executeComputerLoan(WebSoccer $websoccer, DbConnection $db, $borrowerTeamId, $player, $matches, $totalFee, $salaryShare = 100, $optionType = 'none', $buyFee = 0) {
        $borrowerTeam = TeamsDataService::getTeamSummaryById($websoccer, $db, $borrowerTeamId);
        $lenderTeamId = (int) $player['verein_id'];

        BankAccountDataService::debitAmount($websoccer, $db, $borrowerTeamId, $totalFee, 'lending_fee_subject', $player['lender_team_name']);
        BankAccountDataService::creditAmount($websoccer, $db, $lenderTeamId, $totalFee, 'lending_fee_subject', $borrowerTeam['team_name']);

        $updStr = "UPDATE ". $websoccer->getConfig('db_prefix') . "_spieler
                    SET lending_matches = '". (int) $matches ."',
                        lending_owner_id = '". $lenderTeamId ."',
                        verein_id = '". (int) $borrowerTeamId ."'
                    WHERE id = '". (int) $player['id'] ."'";
        $db->executeQuery($updStr);
        LoanDataService::createLoan($websoccer, $db, $player['id'], $lenderTeamId, $borrowerTeamId, $matches, $player['lending_fee'], $salaryShare, $optionType, $buyFee);
        LoanDataService::closeOffer($websoccer, $db, $player['id'], 'accepted');

        echo "--- BORROWED: ". $player['id'] ." -> ". $borrowerTeamId ."\n";

        if ((int) $player['lender_user_id'] > 0) {
            $playerName = (strlen($player['kunstname'])) ? $player['kunstname'] : $player['vorname'] . ' ' . $player['nachname'];
            NotificationsDataService::createNotification($websoccer, $db, $player['lender_user_id'], 'lending_notification_lent',
                array('player' => $playerName, 'matches' => $matches, 'newteam' => $borrowerTeam['team_name']),
                'lending_lent', 'loans', '');
        }
    }

    private static function getLoanProfile(WebSoccer $websoccer, DbConnection $db, $teamId, $squad) {
        $result = $db->executeQuery("SELECT tactical_style, finanz_budget, strength FROM ". $websoccer->getConfig('db_prefix') ."_verein WHERE id = '". (int) $teamId ."'");
        $team = $result->fetch_assoc();
        $result->free();
        return array(
            'tactical_style' => isset($team['tactical_style']) ? $team['tactical_style'] : '',
            'budget' => isset($team['finanz_budget']) ? (int) $team['finanz_budget'] : 0,
            'strength' => isset($team['strength']) ? (int) $team['strength'] : 0,
            'avg_strength' => self::calculateAverageStrength($squad)
        );
    }

    private static function scoreLoanOfferCandidate($player, $profile) {
        $score = 0;
        $playerStrength = self::calculatePlayerStrengthX($player);
        if ($playerStrength < $profile['avg_strength'] * 0.90) {
            $score += 20;
        }
        if ((int) $player['age'] <= 22) {
            $score += 25;
        } elseif ((int) $player['age'] <= 25) {
            $score += 10;
        }
        if ((int) $player['w_talent'] >= 4) {
            $score += 15;
        }
        if ($profile['tactical_style'] == 'youth_focused' || $profile['tactical_style'] == 'youth-focused') {
            $score += 10;
        }
        if ((int) $player['vertrag_gehalt'] > 0 && $profile['budget'] < ((int) $player['vertrag_gehalt'] * 20)) {
            $score += 10;
        }
        return $score;
    }

    private static function calculateComputerLoanSalaryShare($player, $profile) {
        if ((int) $player['vertrag_gehalt'] > 0 && $profile['budget'] < ((int) $player['vertrag_gehalt'] * 15)) {
            return 70;
        }
        if ((int) $player['age'] <= 22 && (int) $player['w_talent'] >= 4) {
            return 80;
        }
        return 100;
    }

    private static function calculateComputerLoanOptionType($player, $profile) {
        if ((int) $player['age'] <= 22 && (int) $player['w_talent'] >= 4) {
            return LoanDataService::OPTION_NONE;
        }
        if ((int) $player['vertrag_gehalt'] > 0 && $profile['budget'] < ((int) $player['vertrag_gehalt'] * 12)) {
            return LoanDataService::OPTION_BUY;
        }
        return LoanDataService::OPTION_NONE;
    }

    private static function computerLoanStrengthFits($teamStrength, $candidate) {
        if ($teamStrength <= 0) {
            return true;
        }
        $playerStrength = self::calculatePlayerStrengthX($candidate);
        return ($playerStrength >= $teamStrength * 0.75 && $playerStrength <= $teamStrength * 1.15);
    }

    private static function getTraitNeedsForTeam(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $teamId = (int) $teamId;
        if ($teamId < 1 || !class_exists('SquadPlannerDataService') || !class_exists('PlayerTraitsDataService')) {
            return array();
        }
        try {
            return SquadPlannerDataService::getTraitNeedsForTeam($websoccer, $db, $teamId);
        } catch (Exception $e) {
            return array();
        }
    }

    private static function scorePlayerForTraitNeeds(WebSoccer $websoccer, DbConnection $db, $player, $traitNeeds) {
        if (!class_exists('PlayerTraitsDataService') || !is_array($traitNeeds) || !count($traitNeeds)) {
            return 0;
        }
        if (!isset($player['id']) || (int) $player['id'] < 1) {
            return 0;
        }

        $position = isset($player['position']) ? $player['position'] : '';
        $filteredNeeds = array();
        foreach ($traitNeeds as $need) {
            if (isset($need['position']) && strlen($position) && $need['position'] != $position) {
                continue;
            }
            $filteredNeeds[] = $need;
        }
        if (!count($filteredNeeds)) {
            return 0;
        }

        $traitMap = PlayerTraitsDataService::getTraitMapFromPlayerArray($websoccer, $db, $player);
        return PlayerTraitsDataService::getTraitNeedScore($traitMap, $filteredNeeds);
    }

    public static function sortByCpuTraitNeedScore($a, $b) {
        $scoreA = isset($a['cpu_trait_need_score']) ? (int) $a['cpu_trait_need_score'] : 0;
        $scoreB = isset($b['cpu_trait_need_score']) ? (int) $b['cpu_trait_need_score'] : 0;
        if ($scoreA == $scoreB) {
            return 0;
        }
        return ($scoreA > $scoreB) ? -1 : 1;
    }

    private static function countOffersForPlayerId(WebSoccer $websoccer, DbConnection $db, $playerId) {
        
		$query = "SELECT COUNT(*) AS offers
                    FROM ". $websoccer->getConfig('db_prefix') ."_transfer_angebot
                    WHERE spieler_id = '$playerId'";
        $result = $db->executeQuery($query);
        $offer = $result->fetch_assoc();
        $result->free();
		
		//correct too many offers
		return $offer['offers'];
    }
    
    public static function deleteTooManyOffers(WebSoccer $websoccer, DbConnection $db, $maxOffersPerPlayer = 6) {
        // Keep the best offers instead of deleting every computer offer once the old
        // hard-coded limit of three is exceeded.
        $maxOffersPerPlayer = max(1, (int) $maxOffersPerPlayer);
        $table = $websoccer->getConfig('db_prefix') . "_transfer_angebot";

        $query = "SELECT spieler_id, COUNT(*) AS offer_count
                  FROM " . $table . "
                  GROUP BY spieler_id
                  HAVING COUNT(*) > " . $maxOffersPerPlayer;
        $result = $db->executeQuery($query);
        $playerIds = array();
        while ($row = $result->fetch_assoc()) {
            $playerIds[] = (int) $row['spieler_id'];
        }
        $result->free();

        foreach ($playerIds as $playerId) {
            $userQuery = "SELECT COUNT(*) AS user_count FROM " . $table . "
                          WHERE spieler_id='" . $playerId . "' AND user_id > 0";
            $userResult = $db->executeQuery($userQuery);
            $userRow = $userResult->fetch_assoc();
            $userResult->free();

            $cpuSlots = max(0, $maxOffersPerPlayer - (int) $userRow['user_count']);
            $cpuQuery = "SELECT id FROM " . $table . "
                         WHERE spieler_id='" . $playerId . "'
                           AND (user_id IS NULL OR user_id <= 0)
                         ORDER BY abloese DESC, datum DESC, id DESC";
            $cpuResult = $db->executeQuery($cpuQuery);
            $keep = 0;
            $deleteIds = array();
            while ($cpuRow = $cpuResult->fetch_assoc()) {
                if ($keep < $cpuSlots) {
                    $keep++;
                } else {
                    $deleteIds[] = (int) $cpuRow['id'];
                }
            }
            $cpuResult->free();

            if (count($deleteIds)) {
                $db->executeQuery("DELETE FROM " . $table . " WHERE id IN (" . implode(',', $deleteIds) . ")");
            }
        }
    }

	public static function deleteOfferByPlayerId(WebSoccer $websoccer, DbConnection $db, $playerId) {
		
		$delStr = "DELETE FROM ". $websoccer->getConfig('db_prefix') ."_transfer_angebot WHERE spieler_id='".$playerId."'";
		$db->executeQuery($delStr);
		
	}
	
	/*
	 * find player with offer but not on TL and delete the offers
	 */
	public static function offerButNotONTL(WebSoccer $websoccer, DbConnection $db) {
	    
	    $sqlStr = "SELECT S.transfermarkt, T.* 
                FROM ". $websoccer->getConfig('db_prefix') ."_transfer_angebot AS T, ". $websoccer->getConfig('db_prefix') ."_spieler AS S 
                WHERE S.id=T.spieler_id AND S.transfermarkt='0'";
        $result = $db->executeQuery($sqlStr);
        while ($player = $result->fetch_assoc()) {
            
            echo"447: deleting offer if not on TL: ". $player['spieler_id'] ."\n";
            ComputerTransfersDataService::deleteOfferByPlayerId($websoccer, $db, $player['spieler_id']);
        }
	}
	
	/*
	 * find player with offer but not on TL and delete the offers
	 */
	public static function TLExpired(WebSoccer $websoccer, DbConnection $db) {
	    
	    $now = $websoccer->getNowAsTimestamp();
	    
	    $sqlStr = "SELECT id
                FROM ". $websoccer->getConfig('db_prefix') ."_spieler
                WHERE transfermarkt='1' AND transfer_ende<$now";
	    $result = $db->executeQuery($sqlStr);
	    while ($player = $result->fetch_assoc()) {
	        
	        $updStr = "UPDATE ". $websoccer->getConfig('db_prefix') ."_spieler
                        SET transfermarkt='0', transfer_start='0', transfer_ende='0'
                        WHERE id='".$player['id']."'";
	        echo"468:". $updStr ."\n";
	        $db->executeQuery($updStr);
	    }
	}
	
}

