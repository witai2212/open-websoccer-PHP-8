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
		
		$columns['COALESCE(U.id, 0)'] = 'user_id';
		$columns['COALESCE(U.nick, C.name)'] = 'user_name';
		
		$fromTable = $websoccer->getConfig('db_prefix') . '_transfer_angebot AS B';
		$fromTable .= ' INNER JOIN ' . $websoccer->getConfig('db_prefix') . '_verein AS C ON C.id = B.verein_id';
		$fromTable .= ' LEFT JOIN ' . $websoccer->getConfig('db_prefix') . '_user AS U ON U.id = B.user_id';
		
		$whereCondition = 'B.spieler_id = %d ORDER BY (amount+hand_money+(contract_matches*contract_matches)) DESC';
		$parameters = array($playerId);
		
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
		$parameters = array($userId);
	
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
	    
	    echo "PL without Team -> TL\n";
		
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
		while ($player = $result->fetch_array()) {
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
			if (!isset($bid['bid_id']) && $player['transfer_end'] < $now) {
				
				// self::extendDuration($websoccer, $db, $player['player_id']);
				$updStr = "UPDATE " . $websoccer->getConfig('db_prefix') . "_spieler SET transfermarkt='0', transfer_start='0', transfer_ende='0' WHERE id='" . $player['player_id'] . "'";
				$db->executeQuery($updStr);
				
				// delete all other offers
				ComputerTransfersDataService::deleteOfferByPlayerId($websoccer, $db, $player['player_id']);
				
			} else {
				
				$strLog = "executeOpenTransfers: processing player " . $player['player_id'] . " with bid " . $bid['bid_id'];
				self::transferLog($websoccer, $db, $strLog);
				self::transferPlayer($websoccer, $db, $player, $bid);
				
				// delete all other offers
				ComputerTransfersDataService::deleteOfferByPlayerId($websoccer, $db, $player['player_id']);
			}
		}
		$result->free();
		
	}
	
	public static function getTransactionsBetweenUsers(WebSoccer $websoccer, DbConnection $db, $user1, $user2) {
		$columns = 'COUNT(*) AS number';
		$fromTable = $websoccer->getConfig('db_prefix') . '_transfer';

		// Keep this method for real human user-to-user transfer limits.
		// Parentheses are important here to avoid wrong OR/AND precedence.
		$whereCondition = 'datum >= %d AND ((seller_user_id = %d AND buyer_user_id = %d) OR (seller_user_id = %d AND buyer_user_id = %d))';
	
		$parameters = array(
			$websoccer->getNowAsTimestamp() - 30 * 3600 * 24,
			$user1,
			$user2,
			$user2,
			$user1
		);
	
		$result = $db->querySelect($columns, $fromTable, $whereCondition, $parameters);
		$transactions = $result->fetch_array();
		$result->free();
	
		if (isset($transactions['number'])) {
			return (int) $transactions['number'];
		}
	
		return 0;
	}

	public static function getTransactionsBetweenClubs(WebSoccer $websoccer, DbConnection $db, $club1, $club2) {
		$club1 = (int) $club1;
		$club2 = (int) $club2;

		if ($club1 < 1 || $club2 < 1) {
			return 0;
		}

		$columns = 'COUNT(*) AS number';
		$fromTable = $websoccer->getConfig('db_prefix') . '_transfer';

		// Used when at least one side is a computer/unmanaged club.
		// This avoids counting all user_id = 0 clubs as one seller.
		$whereCondition = 'datum >= %d AND ((seller_club_id = %d AND buyer_club_id = %d) OR (seller_club_id = %d AND buyer_club_id = %d))';

		$parameters = array(
			$websoccer->getNowAsTimestamp() - 30 * 3600 * 24,
			$club1,
			$club2,
			$club2,
			$club1
		);

		$result = $db->querySelect($columns, $fromTable, $whereCondition, $parameters);
		$transactions = $result->fetch_array();
		$result->free();

		if (isset($transactions['number'])) {
			return (int) $transactions['number'];
		}

		return 0;
	}

	public static function getTransactionsBetweenUsersOrClubs(WebSoccer $websoccer, DbConnection $db, $sellerUserId, $buyerUserId, $sellerClubId, $buyerClubId) {
		$sellerUserId = (int) $sellerUserId;
		$buyerUserId = (int) $buyerUserId;
		$sellerClubId = (int) $sellerClubId;
		$buyerClubId = (int) $buyerClubId;

		// If both sides are real human users, keep the original user-based anti-collusion rule.
		if ($sellerUserId > 0 && $buyerUserId > 0) {
			return self::getTransactionsBetweenUsers($websoccer, $db, $sellerUserId, $buyerUserId);
		}

		// If one side is CPU/unmanaged, use club IDs instead.
		// Otherwise all clubs with user_id = 0 would be counted together.
		return self::getTransactionsBetweenClubs($websoccer, $db, $sellerClubId, $buyerClubId);
	}
	
	public static function awardUserForTrades(WebSoccer $websoccer, DbConnection $db, $userId) {
	
		// count transactions of users
		$result = $db->querySelect(
			'COUNT(*) AS hits',
			$websoccer->getConfig('db_prefix') . '_transfer', 
			'buyer_user_id = %d OR seller_user_id = %d',
			array($userId, $userId)
		);
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
		
		// transfer logging
		$txt = "transferPlayer " . $playerName . " - " . $player['player_id'];
		self::transferLog($websoccer, $db, $txt);
		
		// transfer without fee
		if ($player['team_id'] < 1) {
			// debit hand money
			if ($bid['hand_money'] > 0) {
				BankAccountDataService::debitAmount(
					$websoccer,
					$db,
					$bid['team_id'], 
					$bid['hand_money'], 
					'transfer_transaction_subject_handmoney', 
					$playerName
				);
			}
			
		// debit / credit fee
		} else {
			BankAccountDataService::debitAmount(
				$websoccer,
				$db,
				$bid['team_id'],
				$bid['amount'],
				'player_transfer_message',
				$player['team_name']
			);
			
			BankAccountDataService::creditAmount(
				$websoccer,
				$db,
				$player['team_id'],
				$bid['amount'],
				'player_transfer_message',
				$bid['team_name']
			);
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
		
		// create transfer log
		$logcolumns['spieler_id'] = $player['player_id'];
		$logcolumns['seller_user_id'] = !empty($player['team_user_id']) ? (int) $player['team_user_id'] : 0;
		$logcolumns['seller_club_id'] = $player['team_id'];
		$logcolumns['buyer_user_id'] = !empty($bid['user_id']) ? (int) $bid['user_id'] : 0;
		$logcolumns['buyer_club_id'] = $bid['team_id'];
		$logcolumns['datum'] = $websoccer->getNowAsTimestamp();
		$logcolumns['bid_id'] = $bid['bid_id'];
		$logcolumns['directtransfer_amount'] = $bid['amount'];
		
		$logTable = $websoccer->getConfig('db_prefix') . '_transfer';
		
		$db->queryInsert($logcolumns, $logTable);
		$transferId = (int) $db->getLastInsertedId();

		if (class_exists('BadgeAwardService') && !empty($player['team_user_id'])) {
			BadgeAwardService::processTransferSale(
				$websoccer,
				$db,
				(int) $player['team_user_id'],
				(int) $player['team_id'],
				(int) $player['player_id'],
				(int) $bid['amount'],
				$transferId
			);
		}

		if (class_exists('FanPressureDataService')) {
			FanPressureDataService::processTransfer(
				$websoccer,
				$db,
				I18n::getInstance($websoccer->getConfig('supported_languages')),
				$player['player_id'],
				$player['team_id'],
				$bid['team_id'],
				$bid['amount']
			);
		}
		
		// notify human buyer, if this was not a computer bid
		if (!empty($bid['user_id'])) {
			NotificationsDataService::createNotification(
				$websoccer,
				$db,
				$bid['user_id'],
				'transfer_bid_notification_transfered',
				array('player' => $playerName),
				'transfermarket',
				'player',
				'id=' . $player['player_id']
			);
		}
		
		// transfer watchdog
		self::transferWatchdog($websoccer, $db, $bid['bid_id'], $player['team_id']);
		
		// delete old bids
		$db->queryDelete($websoccer->getConfig('db_prefix') . '_transfer_angebot', 'spieler_id = %d', $player['player_id']);
		
		// award badges only for human users
		if (!empty($bid['user_id'])) {
			self::awardUserForTrades($websoccer, $db, $bid['user_id']);
		}
		if (!empty($player['team_user_id'])) {
			self::awardUserForTrades($websoccer, $db, $player['team_user_id']);
		}
	}
	
	public static function getTransferOffers(WebSoccer $websoccer, DbConnection $db, $teamId) {
	           
	    $offers = array();
	    
	    // Important: do not select P.verein_id without an alias here.
	    // T.verein_id is the bidding club, while P.verein_id is the owning club.
	    // Without aliases, mysqli overwrites the associative key "verein_id" with
	    // the player's current club, so myoffers shows the seller as bidder.
	    $sqlStr = "SELECT T.*, T.verein_id AS bidder_team_id, P.verein_id AS player_team_id,
                        P.vorname, P.nachname, P.position_main, P.position_second, P.marktwert, P.transfer_ende
                    FROM " . $websoccer->getConfig("db_prefix") . "_spieler AS P,
                         " . $websoccer->getConfig("db_prefix") . "_transfer_angebot AS T
                    WHERE P.verein_id='" . (int) $teamId . "'
                    AND P.id=T.spieler_id";
	    $result = $db->executeQuery($sqlStr);
	    $i = 0;
	    while ($offer = $result->fetch_array()) {
	        
	        $offers[] = $offer;
	        
	        $bidderId = (int) $offer['bidder_team_id'];
	        $bidder = TeamsDataService::getTeamById($websoccer, $db, $bidderId);
	        $offers[$i]['bidder'] = $bidder;
	        
	        $i++;
	    }
	    $result->free();
	    
	    return $offers;
	}
	
	public static function getTransferBids(WebSoccer $websoccer, DbConnection $db, $playerId) {

	    $bids = array();
	    
	    $sqlStr = "SELECT A.*, P.id AS p_id, P.verein_id, P.vorname, P.nachname, P.position_main, P.position_second, P.marktwert AS marketvalue,
                        S.id AS player_club_id, S.name AS team_name
        	       FROM " . $websoccer->getConfig("db_prefix") . "_transfer_angebot AS A 
                        INNER JOIN " . $websoccer->getConfig("db_prefix") . "_spieler AS P ON P.id = A.spieler_id
                        INNER JOIN " . $websoccer->getConfig("db_prefix") . "_verein AS S ON S.id = P.verein_id
                   WHERE A.verein_id='" . (int) $playerId . "'
                    ORDER BY A.datum";
                    
	    $result = $db->executeQuery($sqlStr);
	    while ($offer = $result->fetch_array()) {
	        
	        $bids[] = $offer;
			
			// update if offer is highest
			self::updateHighestOffer($websoccer, $db, $offer['p_id']);
	    }
	    $result->free();
	 
	    return $bids;
	}
	
	public static function getPlayersOnTLByTeamId(WebSoccer $websoccer, DbConnection $db, $playerId) {
		
	    $players = array();
	    $sqlStr = "SELECT P.*
					FROM " . $websoccer->getConfig("db_prefix") . "_spieler AS P
                    WHERE P.verein_id='" . (int) $playerId . "' AND P.transfermarkt='1'
                    ORDER BY transfer_start, nachname, vorname";
	    $result = $db->executeQuery($sqlStr);
	    while ($player = $result->fetch_array()) {
	        $players[] = $player;
	    }
	    $result->free();
	 
	    return $players;
	}
	
	public static function transferLog(WebSoccer $websoccer, DbConnection $db, $text) {
		$insStr = "INSERT INTO " . $websoccer->getConfig("db_prefix") . "_transfer_log (text) VALUES ('" . $text . "')";
		$db->executeQuery($insStr);
	}
	
	public static function updateHighestOffer(WebSoccer $websoccer, DbConnection $db, $spieler_id) {
		
		$table = $websoccer->getConfig('db_prefix') . '_transfer_angebot';
		
		// Reset ishighest for all offers of the player
		$db->queryUpdate(array('ishighest' => '0'), $table, 'spieler_id = %d', $spieler_id);

		// Find the highest offer, sorted by abloese, then handgeld
		$result = $db->querySelect('id', $table, 'spieler_id = %d ORDER BY abloese DESC, handgeld DESC LIMIT 1', $spieler_id);

		if ($result && $row = $result->fetch_assoc()) {
			
			$highestOfferId = $row['id'];

			// Update ishighest for the highest offer
			$db->queryUpdate(array('ishighest' => '1'), $table, 'id = %d', $highestOfferId);
		}
	}
	
	public static function transferWatchdog(WebSoccer $websoccer, DbConnection $db, $offerId, $sellerClubId = null) {
	    
	    if ($websoccer->getConfig("transferoffers_deviation_penalty") !== "1") {
	        return 0;
	    }
	    
	    $penaltyBuyer = 0;
	    $penaltySeller = 0;
	    $offerId = (int) $offerId;
	    
	    if ($offerId < 1) {
	        return 0;
	    }
	    
	    // get data from offer
	    $result = $db->querySelect("*", $websoccer->getConfig("db_prefix") . "_transfer_angebot", "id = %d", $offerId, 1);
	    $offer = $result->fetch_array();
	    $result->free();
	    
	    if (!$offer) {
	        return 0;
	    }
	    
	    // get player data: market value and current club before transfer, if no seller was explicitly provided
	    $result = $db->querySelect("*", $websoccer->getConfig("db_prefix") . "_spieler", "id = %d", (int) $offer["spieler_id"], 1);
	    $player = $result->fetch_array();
	    $result->free();
	    
	    if (!$player) {
	        return 0;
	    }
	    
	    if ($sellerClubId === null) {
	        $sellerClubId = (int) $player["verein_id"];
	    } else {
	        $sellerClubId = (int) $sellerClubId;
	    }
	    
	    $marketValue = self::normalizeMoneyValue($player["marktwert"]);
	    $amount = self::normalizeMoneyValue($offer["abloese"]);
	    $salary = self::normalizeMoneyValue($offer["vertrag_gehalt"]);
	    
	    if ($marketValue <= 0) {
	        return 0;
	    }
	    
	    $maxDeviation = (float) $websoccer->getConfig("transferoffers_max_offer_deviation");
	    if ($maxDeviation < 0) {
	        $maxDeviation = 0;
	    }
	    
	    $minAllowedAmount = $marketValue * (1 - ($maxDeviation / 100));
	    $maxAllowedAmount = $marketValue * (1 + ($maxDeviation / 100));
	    
	    // Human buyer pays the penalty for overpaying.
	    if ($amount > $maxAllowedAmount) {
	        $penaltyBuyer += (int) round($amount - $maxAllowedAmount, 0);
	    }
	    
	    // Human seller pays the penalty only for selling far below market value.
	    // This keeps the anti-collusion rule without punishing the seller for a buyer's overpriced offer.
	    if ($amount < $minAllowedAmount) {
	        $penaltySeller += (int) round($minAllowedAmount - $amount, 0);
	    }
	    
	    // Salary rule: compare with similar market-value players. If there is no useful average,
	    // fall back to the player's old salary. Penalize only the excess above the allowed threshold.
	    $avgSalary = self::getAverageSalaryForMarketRange($websoccer, $db, $minAllowedAmount, $maxAllowedAmount);
	    if ($avgSalary <= 0) {
	        $avgSalary = self::normalizeMoneyValue($player["vertrag_gehalt"]);
	    }
	    
	    if ($avgSalary > 0) {
	        $maxAllowedSalary = $avgSalary * 1.5;
	        if ($salary > $maxAllowedSalary) {
	            $penaltyBuyer += (int) round(($salary - $maxAllowedSalary) * 20, 0);
	        }
	    }
	    
	    $buyer = self::getPenaltyTeamRow($websoccer, $db, (int) $offer["verein_id"]);
	    $seller = ($sellerClubId > 0) ? self::getPenaltyTeamRow($websoccer, $db, $sellerClubId) : null;
	    
	    $chargedPenalty = 0;
	    
	    if ($penaltyBuyer > 0 && $buyer && (int) $offer["user_id"] > 0) {
	        self::chargeTransferPenalty($websoccer, $db, (int) $buyer["id"], (int) $buyer["user_id"], $penaltyBuyer, $buyer["name"]);
	        $chargedPenalty += $penaltyBuyer;
	    }
	    
	    if ($penaltySeller > 0 && $seller && (int) $seller["user_id"] > 0) {
	        self::chargeTransferPenalty($websoccer, $db, (int) $seller["id"], (int) $seller["user_id"], $penaltySeller, $seller["name"]);
	        $chargedPenalty += $penaltySeller;
	    }
	    
	    if ($chargedPenalty > 0) {
	        // Add only actually charged human penalties to the redistribution pot.
	        $penaltyTable = $websoccer->getConfig("db_prefix") . "_penalty";
	        $db->executeQuery("INSERT IGNORE INTO " . $penaltyTable . " (id, budget, penalty) VALUES (1, 0, 0)");
	        $db->executeQuery("UPDATE " . $penaltyTable . " SET penalty = penalty + " . (int) $chargedPenalty . " WHERE id = 1");
	    }
	    
	    self::transferLog($websoccer, $db, "penalty buyer: " . $penaltyBuyer . " seller: " . $penaltySeller . " charged: " . $chargedPenalty . " - buyerId: " . $offer["verein_id"] . " sellerId: " . $sellerClubId);
	    
	    return $chargedPenalty;
	}
	
	private static function chargeTransferPenalty(WebSoccer $websoccer, DbConnection $db, $teamId, $userId, $penalty, $teamName) {
	    $penalty = (int) $penalty;
	    
	    if ($penalty <= 0 || $teamId < 1 || $userId < 1) {
	        return;
	    }
	    
	    BankAccountDataService::debitAmount($websoccer, $db, $teamId, $penalty, "transfer_violation", $teamName);
	    NotificationsDataService::createNotification(
	        $websoccer,
	        $db,
	        $userId,
	        "transfer_violation_notification",
	        array("penalty" => $penalty),
	        "transfermarket",
	        "mybids",
	        null,
	        $teamId
	    );
	}
	
	private static function getPenaltyTeamRow(WebSoccer $websoccer, DbConnection $db, $teamId) {
	    $result = $db->querySelect("id, name, user_id", $websoccer->getConfig("db_prefix") . "_verein", "id = %d", (int) $teamId, 1);
	    $team = $result->fetch_array();
	    $result->free();
	    
	    return $team;
	}
	
	private static function getAverageSalaryForMarketRange(WebSoccer $websoccer, DbConnection $db, $minMarketValue, $maxMarketValue) {
	    $result = $db->querySelect(
	        "AVG(vertrag_gehalt) AS avg_salary",
	        $websoccer->getConfig("db_prefix") . "_spieler",
	        "CAST(marktwert AS DECIMAL(15,2)) BETWEEN %d AND %d AND status = '1'",
	        array((int) round($minMarketValue, 0), (int) round($maxMarketValue, 0)),
	        1
	    );
	    $salary = $result->fetch_array();
	    $result->free();
	    
	    if (!$salary || $salary["avg_salary"] === null) {
	        return 0;
	    }
	    
	    return (float) $salary["avg_salary"];
	}
	
	private static function normalizeMoneyValue($value) {
	    $value = str_replace(array(" ", "\xc2\xa0"), "", (string) $value);
	    $value = str_replace(",", ".", $value);
	    $value = preg_replace("/[^0-9.\-]/", "", $value);
	    
	    if ($value === "" || $value === "-" || !is_numeric($value)) {
	        return 0;
	    }
	    
	    return (float) $value;
	}

}
?>