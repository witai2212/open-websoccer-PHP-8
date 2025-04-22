<?php

class ComputerTransfersDataService {
    
    const MAX_SQUAD_SIZE = 23;
    const MAX_OFFERS = 3;
    const TRANSFER_DURATION_DAYS = 1;
    const MAX_PERCENTAGE_PLAYERS_ON_TL = 2;
    const MAX_PLAYERS_ON_TL = 800;
    CONST MAX_OFFER_DEVIATION = 1.15;
    
    private static $logger;
    
    public static function setLogger($logger) {
        self::$logger = $logger;
    }

	public static function executeComputerBids(WebSoccer $websoccer, DbConnection $db) {
	    
	    TransfermarketDataService::executeOpenTransfers($websoccer, $db);

	    $MAX_PLAYERS_ON_TL = $websoccer->getConfig("transfermarket_max_players_on_tl");
	    
	    echo"- compTL\n";
	    
	    // TL time expired
	    self::TLExpired($websoccer, $db);
	    
	    // delete offers if not on TL
	    self::offerButNotONTL($websoccer, $db);
	    
		$now = $websoccer->getNowAsTimestamp();
		$teamIds = self::getComputerControlledTeams($websoccer, $db);

		// --- Managing Transfer List (only if condition is met) ---
		$playersOnTL = self::getNumberOfPlayersOnTL($websoccer, $db);

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

		// --- Bidding on Players (always runs) ---
		foreach ($teamIds as $teamId) {
		    
			$budget = self::getTeamBudget($websoccer, $db, $teamId);
			$currentOffers = self::getTeamOfferCount($websoccer, $db, $teamId);
			$squad = self::getTeamSquad($websoccer, $db, $teamId);

			// Skip bidding if the team already has MAX_OFFERS offers

			$teamStrength = self::calculateAverageStrength($squad);
			$playersOnMarket = self::getPlayersOnTransferMarket($websoccer, $db);

			foreach ($playersOnMarket as $player) {
				if ($player['transfermarkt'] == '1') {
				    
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

					// Calculate bid amount and salary
					$bidAmount = self::calculateBidAmount($player);
					$salaryAmount = self::calculateSalary($player);
					$goalAmount = self::calculateGoal($player);

					// Place bid if the team has enough budget
					if ($bidAmount <= $budget) {
						
						$offersForPlayerId = self::countOffersForPlayerId($websoccer, $db, $player['id']);
						if($offersForPlayerId<3) {
							
							self::placeBid($websoccer, $db, $teamId, $player['id'], $bidAmount, $now, $salaryAmount, $goalAmount);
							self::deleteTooManyOffers($websoccer, $db);
							$currentOffers++;
							if ($currentOffers >= self::MAX_OFFERS) break;
						} else {
							self::deleteTooManyOffers($websoccer, $db);
						}
					}
				}
			}
		}
	}
    
    
    private static function getComputerControlledTeams(WebSoccer $websoccer, DbConnection $db) {
        $query = "
            SELECT id
            FROM ". $websoccer->getConfig('db_prefix') ."_verein
            WHERE user_id IS NULL OR user_id <= '0'
            ORDER BY RAND()
            LIMIT 1";
        $result = $db->executeQuery($query);
        
        $teamIds = [];
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
        $lowerBound = $teamStrength * 0.9;
        $upperBound = $teamStrength * 1.1;
        return $playerStrength >= $lowerBound && $playerStrength <= $upperBound;
    }
    
    private static function calculateBidAmount($player) {
        
        //
        /*if(isset($player['transfer_mindestgebot']) && isset($player['marktwert'])) {
            $offer_ratio = ($player['transfer_mindestgebot']/$player['marktwert']);
        } else {
            $offer_ratio = $player['marktwert'];
        }*/
        
        //offer
        (float) $baseAmount = $player['transfer_mindestgebot'];
        //if($baseAmount<1 || $offer_ratio>self::MAX_OFFER_DEVIATION) {
        if($baseAmount<1) {
            $baseAmount = $player['marktwert'];
        }        
        $adjustment = (float) $baseAmount * (rand(-10, 10) / 100);
        
        return (float) $baseAmount + (float) $adjustment;
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
        
        $rand = rand(10, 50)/100;
        $handgeld = $bidAmount *  $rand;
        
        $insStr = "INSERT INTO ". $websoccer->getConfig('db_prefix') . "_transfer_angebot
                    (spieler_id, verein_id, abloese, handgeld, vertrag_spiele, datum, vertrag_gehalt, vertrag_torpraemie)
                    VALUES ('$playerId', '$teamId', '$bidAmount', '$handgeld', '60', '$now', '$salaryAmount', '$goalAmount')";
        $db->executeQuery($insStr);

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
        $currentTransferList = self::getTeamTransferList($websoccer, $db, $teamId);
        
        if (count($currentTransferList) >= 3) return;
        
        $transferCandidates = [];
        foreach ($squad as $player) {
            if (self::canListPlayerForTransfer($player, $positionsCount)) {
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
    
    private static function canListPlayerForTransfer($player, $positionsCount) {
        $position = $player['position'];
        switch ($position) {
            case 'Torwart':
                return $positionsCount['Torwart'] > 2;
            case 'Abwehr':
                return $positionsCount['Abwehr'] > 5;
            case 'Mittelfeld':
                return $positionsCount['Mittelfeld'] > 5;
            case 'Sturm':
                return $positionsCount['Sturm'] > 5;
            default:
                return false;
        }
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
    
    public static function deleteTooManyOffers(WebSoccer $websoccer, DbConnection $db) {
        
        $query = "SELECT * FROM ". $websoccer->getConfig('db_prefix') ."_v_transfer_angebot_player
                    WHERE offer>3";
        $result = $db->executeQuery($query);
        $offer = $result->fetch_assoc();
        $result->free();
        
        //correct too many offers
        if($offer['offer']>3) {
            
            $delStr = "DELETE FROM ". $websoccer->getConfig('db_prefix') ."_transfer_angebot
                        WHERE spieler_id='".$offer['spieler_id']."'
                            AND user_id<=0";
            $db->executeQuery($delStr);
            
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
            
            echo"447: deleting offer if not on TL: ". $player['spieler_id'] ."<br>";
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

