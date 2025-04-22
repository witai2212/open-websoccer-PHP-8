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
 * Data service for transfer market actions
 */
class TransfermarketDataService {

	public static function getHighestBidForPlayer(WebSoccer $websoccer, DbConnection $db, $playerId) {
		$columns['B.id'] = 'bid_id';
		$columns['B.abloese'] = 'amount';
		$columns['B.handgeld'] = 'hand_money';
		$columns['B.vertrag_spiele'] = 'contract_matches';
		$columns['B.vertrag_gehalt'] = 'contract_salary';
		$columns['B.vertrag_torpraemie'] = 'contract_goalbonus';
		$columns['B.datum'] = 'date';
		
		$columns['C.id'] = 'team_id';
		$columns['C.name'] = 'team_name';
		
		$columns['U.id'] = 'user_id';
		$columns['U.nick'] = 'user_name';
		
		$fromTable = $websoccer->getConfig('db_prefix') . '_transfer_angebot AS B';
		$fromTable .= ' INNER JOIN ' . $websoccer->getConfig('db_prefix') . '_verein AS C ON C.id = B.verein_id';
		$fromTable .= ' INNER JOIN ' . $websoccer->getConfig('db_prefix') . '_user AS U ON U.id = B.user_id';
		
		$whereCondition = 'B.spieler_id = %d ORDER BY (amount+hand_money+(contract_matches*contract_matches)) DESC';
		$parameters = array($playerId, $transferStart, $transferEnd);
		
		$result = $db->querySelect($columns, $fromTable, $whereCondition, $parameters, 1);
		$bid = $result->fetch_array();
		$result->free();
		
		return $bid;
	}
	
	public static function getCurrentBidsOfTeam(WebSoccer $websoccer, DbConnection $db, $teamId) {
	    
		$columns['B.id'] = 'bid_id';
		$columns['B.abloese'] = 'amount';
		$columns['B.handgeld'] = 'hand_money';
		$columns['B.vertrag_spiele'] = 'contract_matches';
		$columns['B.vertrag_gehalt'] = 'contract_salary';
		$columns['B.vertrag_torpraemie'] = 'contract_goalbonus';
		$columns['B.datum'] = 'date';
		$columns['B.ishighest'] = 'ishighest';
	
		$columns['P.id'] = 'player_id';
		$columns['P.verein_id'] = 'player_team_id';
		$columns['P.vorname'] = 'player_firstname';
		$columns['P.nachname'] = 'player_lastname';
		$columns['P.kunstname'] = 'player_pseudonym';
		$columns['P.transfer_ende'] = 'auction_end';
		$columns['P.marktwert'] = 'marketvalue';
		$columns['P.position_main'] = 'position_main';
		$columns['P.position_second'] = 'position_second';
	
		$fromTable = $websoccer->getConfig('db_prefix') . '_transfer_angebot AS B';
		$fromTable .= ' INNER JOIN ' . $websoccer->getConfig('db_prefix') . '_verein AS C ON C.id = B.verein_id';
		$fromTable .= ' INNER JOIN ' . $websoccer->getConfig('db_prefix') . '_spieler AS P ON P.id = B.spieler_id';
	
		$whereCondition = 'C.id = %d AND P.transfer_ende >= %d ORDER BY P.transfer_ende ASC, B.datum DESC, P.transfer_ende ASC';
		$parameters = array($teamId, $websoccer->getNowAsTimestamp());
	
		$bids = array();
		$result = $db->querySelect($columns, $fromTable, $whereCondition, $parameters, 20);
		while ($bid = $result->fetch_array()) {
			$bid['player_team'] = TeamsDataService::getTeamById($websoccer, $db, $bid['player_team_id']);
			$bids[] = $bid;
		}
		$result->free();
		
		return $bids;
	}
	
	public static function getLatestBidOfUser(WebSoccer $websoccer, DbConnection $db, $userId) {
		$columns['B.abloese'] = 'amount';
		$columns['B.handgeld'] = 'hand_money';
		$columns['B.vertrag_spiele'] = 'contract_matches';
		$columns['B.vertrag_gehalt'] = 'contract_salary';
		$columns['B.vertrag_torpraemie'] = 'contract_goalbonus';
		$columns['B.datum'] = 'date';
	
		$columns['P.id'] = 'player_id';
		$columns['P.vorname'] = 'player_firstname';
		$columns['P.nachname'] = 'player_lastname';
		$columns['P.transfer_ende'] = 'auction_end';
	
		$fromTable = $websoccer->getConfig('db_prefix') . '_transfer_angebot AS B';
		$fromTable .= ' INNER JOIN ' . $websoccer->getConfig('db_prefix') . '_spieler AS P ON P.id = B.spieler_id';
	
		$whereCondition = 'B.user_id = %d ORDER BY B.datum DESC';
		$parameters = $userId;
	
		$bids = array();
		$result = $db->querySelect($columns, $fromTable, $whereCondition, $parameters, 1);
		$bid = $result->fetch_array();
		$result->free();
	
		return $bid;
	}
	
	public static function getCompletedTransfersOfUser(WebSoccer $websoccer, DbConnection $db, $userId) {
	
		$whereCondition = 'T.buyer_user_id = %d OR T.seller_user_id = %d ORDER BY T.datum DESC';
		$parameters = array($userId, $userId);
	
		return self::getCompletedTransfers($websoccer, $db, $whereCondition, $parameters);
	}
	
	public static function getCompletedTransfersOfTeam(WebSoccer $websoccer, DbConnection $db, $teamId) {
		
		$whereCondition = 'SELLER.id = %d OR BUYER.id = %d ORDER BY T.datum DESC';
		$parameters = array($teamId, $teamId);
		
		return self::getCompletedTransfers($websoccer, $db, $whereCondition, $parameters);
	}
	
	public static function getCompletedTransfersOfPlayer(WebSoccer $websoccer, DbConnection $db, $playerId) {
	
		$whereCondition = 'T.spieler_id = %d ORDER BY T.datum DESC';
		$parameters = array($playerId);
	
		return self::getCompletedTransfers($websoccer, $db, $whereCondition, $parameters);
	}
	
	public static function getLastCompletedTransfers(WebSoccer $websoccer, DbConnection $db) {
		$whereCondition = '1=1 ORDER BY T.datum DESC';
		
		return self::getCompletedTransfers($websoccer, $db, $whereCondition, array());
	}
	
	private static function getCompletedTransfers(WebSoccer $websoccer, DbConnection $db, $whereCondition, $parameters) {
		$transfers = array();
		
		$columns['T.datum'] = 'transfer_date';
		
		$columns['P.id'] = 'player_id';
		$columns['P.vorname'] = 'player_firstname';
		$columns['P.nachname'] = 'player_lastname';
		
		$columns['SELLER.id'] = 'from_id';
		$columns['SELLER.name'] = 'from_name';
		
		$columns['BUYER.id'] = 'to_id';
		$columns['BUYER.name'] = 'to_name';
		
		$columns['T.directtransfer_amount'] = 'directtransfer_amount';
		
		$columns['EP1.id'] = 'exchangeplayer1_id';
		$columns['EP1.kunstname'] = 'exchangeplayer1_pseudonym';
		$columns['EP1.vorname'] = 'exchangeplayer1_firstname';
		$columns['EP1.nachname'] = 'exchangeplayer1_lastname';
		
		$columns['EP2.id'] = 'exchangeplayer2_id';
		$columns['EP2.kunstname'] = 'exchangeplayer2_pseudonym';
		$columns['EP2.vorname'] = 'exchangeplayer2_firstname';
		$columns['EP2.nachname'] = 'exchangeplayer2_lastname';
		
		$fromTable = $websoccer->getConfig('db_prefix') . '_transfer AS T';
		$fromTable .= ' INNER JOIN ' .$websoccer->getConfig('db_prefix') . '_spieler AS P ON P.id = T.spieler_id';
		$fromTable .= ' INNER JOIN ' .$websoccer->getConfig('db_prefix') . '_verein AS BUYER ON BUYER.id = T.buyer_club_id';
		$fromTable .= ' LEFT JOIN ' .$websoccer->getConfig('db_prefix') . '_verein AS SELLER ON SELLER.id = T.seller_club_id';
		$fromTable .= ' LEFT JOIN ' .$websoccer->getConfig('db_prefix') . '_spieler AS EP1 ON EP1.id = T.directtransfer_player1';
		$fromTable .= ' LEFT JOIN ' .$websoccer->getConfig('db_prefix') . '_spieler AS EP2 ON EP2.id = T.directtransfer_player2';
		
		
		$result = $db->querySelect($columns, $fromTable, $whereCondition, $parameters, 20);
		while ($transfer = $result->fetch_array()) {
			// provide column for handmoney due to backwards compatibility
			$transfer['hand_money'] = 0;
			$transfer['amount'] = $transfer['directtransfer_amount'];
			$transfers[] = $transfer;
		}
		$result->free();
		
		return $transfers;
	}
	
	public static function movePlayersWithoutTeamToTransfermarket(WebSoccer $websoccer, DbConnection $db) {
	    
	    echo"PL->TL<br>";
		
		$columns['unsellable'] = 0;
		$columns['lending_fee'] = 0;
		$columns['lending_owner_id'] = 0;
		$columns['lending_matches'] = 0;
		
		$fromTable = $websoccer->getConfig('db_prefix') . '_spieler';
		
		// select players: 
		// 1) any player who has no contract any more and are not on the market yet
		// 2) any player who has no contract any more, but still on the team list
		// 3) any player who had been added to the list before his contract ended.
		$whereCondition = 'status = 1 AND (transfermarkt != \'1\' AND (verein_id = 0 OR verein_id IS NULL) OR transfermarkt != \'1\' AND verein_id > 0 AND vertrag_spiele < 1 OR transfermarkt = \'1\' AND verein_id > 0 AND vertrag_spiele < 1)';
		
		// update each player, since we might also update user's inactivity
		$result = $db->querySelect('id, verein_id', $fromTable, $whereCondition);
		while($player = $result->fetch_array()) {
			$team = TeamsDataService::getTeamSummaryById($websoccer, $db, $player['verein_id']);
			if ($team == NULL || $team['user_id']) {
				
				if ($team['user_id']) {
					UserInactivityDataService::increaseContractExtensionField($websoccer, $db, $team['user_id']);
				}
				
				$columns['transfermarkt'] = '1';
				$columns['transfer_start'] = $websoccer->getNowAsTimestamp();
				$columns['transfer_ende'] = $columns['transfer_start'] + 24 * 3600 * $websoccer->getConfig('transfermarket_duration_days');
				$columns['transfer_mindestgebot'] = 0;
				$columns['verein_id'] = '';
				
				// do not move player out of team if team has no manager
				// (prevents shrinking of teams)
			} else {
				$columns['transfermarkt'] = '0';
				$columns['transfer_start'] = '0';
				$columns['transfer_ende'] = '0';
				$columns['vertrag_spiele'] = '5';
				$columns['verein_id'] = $player['verein_id'];
			}
			
			$db->queryUpdate($columns, $fromTable, 'id = %d', $player['id']);
		}
		
		$result->free();
	}
	
	public static function executeOpenTransfers(WebSoccer $websoccer, DbConnection $db) {

	    $now = $websoccer->getNowAsTimestamp();
		
		// get ended auctions
		$columns['P.id'] = 'player_id';
		$columns['P.transfer_start'] = 'transfer_start';
		$columns['P.transfer_ende'] = 'transfer_end';
		$columns['P.vorname'] = 'first_name';
		$columns['P.nachname'] = 'last_name';
		$columns['P.kunstname'] = 'pseudonym';
		
		$columns['C.id'] = 'team_id';
		$columns['C.name'] = 'team_name';
		$columns['C.user_id'] = 'team_user_id';
		
		$fromTable = $websoccer->getConfig('db_prefix') . '_spieler AS P';
		$fromTable .= ' LEFT JOIN ' . $websoccer->getConfig('db_prefix') . '_verein AS C ON C.id = P.verein_id';
		
		$whereCondition = 'P.transfermarkt = \'1\' AND P.status = \'1\' AND P.transfer_ende < %d';
		$parameters = $websoccer->getNowAsTimestamp();
		
		// only handle 50 per time
		$result = $db->querySelect($columns, $fromTable, $whereCondition, $parameters, 50);
		while ($player = $result->fetch_array()) {
			
			$bid = self::getHighestBidForPlayer($websoccer, $db, $player['player_id']);
			if (!isset($bid['bid_id']) && $player['transfer_end']<$now) {
				
				//self::extendDuration($websoccer, $db, $player['player_id']);
				$updStr = "UPDATE " . $websoccer->getConfig('db_prefix') . "_spieler SET transfermarkt='0', transfer_start='0', transfer_ende='0' WHERE id='".$player['player_id']."'";
				//echo"278:". $now ." - ". $player['transfer_end'] ."\n";
				$db->executeQuery($updStr);
				
				// delete all other offers
				ComputerTransfersDataService::deleteOfferByPlayerId($websoccer, $db, $player['player_id']);
				
			} else {
				
				$strLog = "___". $string ."___";
				self::transferLog($websoccer, $db, $strLog);
				self::transferPlayer($websoccer, $db, $player, $bid);
				
				$updStr = "UPDATE " . $websoccer->getConfig('db_prefix') . "_spieler SET transfermarkt='0', transfer_start='0', transfer_ende='0' WHERE id='".$player['player_id']."'";
				$db->executeQuery($updStr);
				
				// delete all other offers
				ComputerTransfersDataService::deleteOfferByPlayerId($websoccer, $db, $player['player_id']);
			}
		}
		$result->free();
		
		
	}
	
	public static function getTransactionsBetweenUsers(WebSoccer $websoccer, DbConnection $db, $user1, $user2) {
		$columns = 'COUNT(*) AS number';
		$fromTable = $websoccer->getConfig('db_prefix') .'_transfer';
		$whereCondition = 'datum >= %d AND (seller_user_id = %d AND buyer_user_id = %d OR seller_user_id = %d AND buyer_user_id = %d)';
	
		$parameters = array($websoccer->getNowAsTimestamp() - 30 * 3600 * 24, $user1, $user2, $user2, $user1);
	
		$result = $db->querySelect($columns, $fromTable, $whereCondition, $parameters);
		$transactions = $result->fetch_array();
		$result->free();
	
		if (isset($transactions['number'])) {
			return $transactions['number'];
		}
	
		return 0;
	}
	
	public static function awardUserForTrades(WebSoccer $websoccer, DbConnection $db, $userId) {
	
		// count transactions of users
		$result = $db->querySelect('COUNT(*) AS hits', $websoccer->getConfig('db_prefix') . '_transfer', 
				'buyer_user_id = %d OR seller_user_id = %d', array($userId, $userId));
		$transactions = $result->fetch_array();
		$result->free();
		
		if (!$transactions || !$transactions['hits']) {
			return;
		}
		
		BadgesDataService::awardBadgeIfApplicable($websoccer, $db, $userId, 'x_trades', $transactions['hits']);
	}
	
	public static function extendDuration($websoccer, $db, $playerId) {
		$fromTable = $websoccer->getConfig('db_prefix') . '_spieler';
		
		$columns['transfer_ende'] = $websoccer->getNowAsTimestamp() + 24 * 3600 * $websoccer->getConfig('transfermarket_duration_days');
		
		$whereCondition = 'id = %d';
		
		$db->queryUpdate($columns, $fromTable, $whereCondition, $playerId);
	}
	
	public static function transferPlayer(WebSoccer $websoccer, DbConnection $db, $player, $bid) {
	    
		
		$playerName = (strlen($player['pseudonym'])) ? $player['pseudonym'] : $player['first_name'] . ' ' . $player['last_name'];
		
		// trasnfer logging
		$txt = "transferPlayer ". $playerName ." - ". $player['player_id'];
		self::transferLog($websoccer, $db, $txt);
		
		// transfer without fee
		if ($player['team_id'] < 1) {
			// debit hand money
			if ($bid['hand_money'] > 0) {
				BankAccountDataService::debitAmount($websoccer, $db, $bid['team_id'], 
					$bid['hand_money'], 
					'transfer_transaction_subject_handmoney', 
					$playerName);
			}
			
		// debit / credit fee
		} else {
			BankAccountDataService::debitAmount($websoccer, $db, $bid['team_id'],
				$bid['amount'],
				'player_transfer_message',
				$player['team_name']);
			
			BankAccountDataService::creditAmount($websoccer, $db, $player['team_id'],
				$bid['amount'],
				'player_transfer_message',
				$bid['team_name']);
		}
		
		$fromTable = $websoccer->getConfig('db_prefix') . '_spieler';
		
		// move and update player
		$columns['transfermarkt'] = 0;
		$columns['transfer_start'] = 0;
		$columns['transfer_ende'] = 0;
		$columns['last_transfer'] = $websoccer->getNowAsTimestamp();
		$columns['verein_id'] = $bid['team_id'];
		
		$columns['vertrag_spiele'] = $bid['contract_matches'];
		$columns['vertrag_gehalt'] = $bid['contract_salary'];
		$columns['vertrag_torpraemie'] = $bid['contract_goalbonus'];
		
		$whereCondition = 'id = %d';
		$db->queryUpdate($columns, $fromTable, $whereCondition, $player['player_id']);
		
		$db->executeQuery("UPDATE ". $websoccer->getConfig('db_prefix') ."_spieler SET last_transfer=".$websoccer->getNowAsTimestamp()." 
                            WHERE id='".$player['player_id']."'");
		
		// create transfer log
		$logcolumns['spieler_id'] = $player['player_id'];
		$logcolumns['seller_user_id'] = $player['team_user_id'];
		$logcolumns['seller_club_id'] = $player['team_id'];
		$logcolumns['buyer_user_id'] = $bid['user_id'];
		$logcolumns['buyer_club_id'] = $bid['team_id'];
		$logcolumns['datum'] = $websoccer->getNowAsTimestamp();
		$logcolumns['directtransfer_amount'] = $bid['amount'];
		
		$logTable = $websoccer->getConfig('db_prefix') . '_transfer';
		
		$db->queryInsert($logcolumns, $logTable);
		
		// notify user
		NotificationsDataService::createNotification($websoccer, $db, $bid['user_id'],
			'transfer_bid_notification_transfered', array('player' => $playerName), 'transfermarket', 'player', 'id=' . $player['player_id']);
		
		// delete old bids
		$db->queryDelete($websoccer->getConfig('db_prefix') . '_transfer_angebot', 'spieler_id = %d', $player['player_id']);
		
		// award badges
		self::awardUserForTrades($websoccer, $db, $bid['user_id']);
		if ($player['team_user_id']) {
			self::awardUserForTrades($websoccer, $db, $player['team_user_id']);
		}
		
		//save in _transfer table
		//id spieler_id seller_user_id seller_club_id buyer_user_id buyer_club_id datum bid_id directtransfer_amount directtransfer_player1 directtransfer_player2
		if(isset($bid['id'])) {
			$bidId = $bid['id'];
		} else {
			$bidId = 0;
		}
		
		//$date = new DateTime();
		$trStr = "INSERT INTO " . $websoccer->getConfig("db_prefix") . "_transfer (spieler_id, seller_club_id, buyer_club_id, datum, bid_id, directtransfer_amount)
					VALUES ('".$player['player_id']."','".$player['team_id']."', '".$bid['team_id']."', 
							'".$websoccer->getNowAsTimestamp()."', '".$bidId."',
							'".$bid['amount']."')";
		$db->executeQuery($trStr);
	}
	
	public static function getTransferOffers(WebSoccer $websoccer, DbConnection $db, $teamId) {
	           
	    $offers = array();
	    $bids = array();
	    
	    $sqlStr = "SELECT T.*, P.vorname, P.nachname, P.verein_id, P.position_main, P.position_second, P.marktwert
                    FROM ". $websoccer->getConfig("db_prefix") ."_spieler AS P,
                                ". $websoccer->getConfig("db_prefix") ."_transfer_angebot AS T
                    WHERE P.verein_id='$teamId'
                    AND P.id=T.spieler_id";
	    $result = $db->executeQuery($sqlStr);
	    $i = 0;
	    while ($offer = $result->fetch_array()) {
	        
	        $offers[] = $offer;
	        
	        $bidderId = $offers[$i][2];
	        $bidder = TeamsDataService::getTeamById($websoccer, $db, $bidderId);
	        $offers[$i]['bidder'] = $bidder;
	        
	        $i++;
	    }
	    $result->free();
	    
	    return $offers;
	}
	
	public static function getTransferBids(WebSoccer $websoccer, DbConnection $db, $playerId) {

	    /*
	    $bid = array();
	    
	    $bid['id'] = '1111';
	    $bid['player_name'] = 'SpielerClubname';
	    $bid['amount'] = '123456789';
	    $bid['status'] = 'pending';
	    */
	    $bids = array();
	    /*
	    $sqlStr = "SELECT O.*, O.offer_amount AS abloese, P.id AS p_id, P.vorname, P.nachname, P.position_main, P.position_second, C.id AS player_club_id, C.name AS team_name
                    FROM ". $websoccer->getConfig("db_prefix") ."_transfer_offer AS O, 
                        ". $websoccer->getConfig("db_prefix") ."_spieler AS P, 
                        ". $websoccer->getConfig("db_prefix") ."_verein AS C
                    WHERE P.verein_id=O.receiver_club_id 
                    AND C.id=P.verein_id
                    AND O.sender_club_id='$playerId'
                    AND P.id=O.player_id
                    GROUP BY O.sender_club_id
                    ORDER BY P.nachname ASC, O.offer_amount DESC, O.submitted_date";
	    */	    
	    
	    $sqlStr = "SELECT A.*, P.id AS p_id, P.verein_id, P.vorname, P.nachname, P.position_main, P.position_second, P.marktwert AS marketvalue,
                        S.id AS player_club_id, S.name AS team_name
        	       FROM ". $websoccer->getConfig("db_prefix") ."_transfer_angebot AS A 
                        INNER JOIN ". $websoccer->getConfig("db_prefix") ."_spieler AS P ON P.id = A.spieler_id
                        INNER JOIN ". $websoccer->getConfig("db_prefix") ."_verein AS S
                   WHERE A.verein_id='$playerId' AND S.id=P.verein_id
                    ORDER BY A.datum";
                    
	    $result = $db->executeQuery($sqlStr);
	    $i = 0;
	    while ($offer = $result->fetch_array()) {
	        
	        $bids[] = $offer;
	        $i++;
			
			//update if offer ishighest
			self::updateHighestOffer($websoccer, $db, $bids['p_id']);
	        
	    }
	    $result->free();
	 
	    return $bids;
	}
	
	public static function getPlayersOnTLByTeamId(WebSoccer $websoccer, DbConnection $db, $playerId) {
		
	    $sqlStr = "SELECT P.*
					FROM ". $websoccer->getConfig("db_prefix") ."_spieler AS P
                    WHERE P.verein_id='$playerId' AND P.transfermarkt='1'
                    ORDER BY transfer_start, nachname, vorname";
	    $result = $db->executeQuery($sqlStr);
	    while ($player = $result->fetch_array()) {
	        $players[] = $player;
	    }
	    $result->free();
	 
	    return $players;
		
	}
	
	public static function transferLog(WebSoccer $websoccer, DbConnection $db, $text) {
		
			$insStr = "INSERT INTO ". $websoccer->getConfig("db_prefix") ."_transfer_log (text) VALUES ('".$text."')";
			$db->executeQuery($insStr);
	
	}
	
	function updateHighestOffer(WebSoccer $websoccer, DbConnection $db, $spieler_id) {
		
		// Reset ishighest for all offers of the player
		$db->queryUpdate(['ishighest' => '0'], '". $websoccer->getConfig("db_prefix") ."_transfer_angebot', 'spieler_id = %d', $spieler_id);

		// Find the highest offer (sorted by abloese, then handgeld)
		$result = $db->querySelect('id', '". $websoccer->getConfig("db_prefix") ."_transfer_angebot', 'spieler_id = %d ORDER BY abloese DESC, handgeld DESC LIMIT 1', $spieler_id);

		if ($result && $row = $result->fetch_assoc()) {
			
			$highestOfferId = $row['id'];

			// Update ishighest for the highest offer
			$db->queryUpdate(['ishighest' => '1'], '". $websoccer->getConfig("db_prefix") ."_transfer_angebot', 'id = %d', $highestOfferId);
		}
	}
}
?>