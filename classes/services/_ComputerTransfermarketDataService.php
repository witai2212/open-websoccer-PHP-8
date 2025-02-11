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
class ComputerTransfermarketDataService {

	public static function getOffersByPlayerId(WebSoccer $websoccer, DbConnection $db, $playerId) {
		/*
			_transfer_angebot 	= spieler_id
			_transfer_offer		= player_id
		*/
		$offers["offers"] = 0;
		
		$columns = "COUNT(TA.spieler_id)+COUNT(T.player_id) AS offers";
        
        $fromTable = $websoccer->getConfig("db_prefix") . "_transfer_angebot AS TA";
		$fromTable .= ' INNER JOIN ' . $websoccer->getConfig('db_prefix') . '_transfer_offer AS T ON T.player_id = TA.spieler_id';
        
        $whereCondition = "TA.spieler_id = %d";
        $parameters = $playerId;
        
        $result = $db->querySelect($columns, $fromTable, $whereCondition, $parameters);
        $offers = $result->fetch_array();
        $result->free();
        
        return $offers["offers"];
		
	}

	public static function getOffersFromTeamId(WebSoccer $websoccer, DbConnection $db, $teamId) {
		/*
			_transfer_angebot 	= verein_id
			_transfer_offer		= sender_club_id
		*/
        $parameters = $teamId;
		$offers = 0;
		
		$columns1 = "COUNT(TA.*) AS offers";
        $fromTable1 = $websoccer->getConfig("db_prefix") . "_transfer_angebot AS TA";
        $whereCondition1 = "TA.verein_id = %d";
        
        $result1 = $db->querySelect($columns1, $fromTable1, $whereCondition1, $parameters);
        $offer1 = $result1->fetch_array();
        $result1->free();
		
		//'''''''''''''''''''''''''''''''''''''''''''''''''''''''''''''''''''''''''''''''''''''''
		
		$columns2 = "COUNT(T.*) AS offers";
        $fromTable2 = $websoccer->getConfig("db_prefix") . "_transfer_offers AS T";
        $whereCondition2 = "T.sender_club_id = %d";
        
        $result2 = $db->querySelect($columns2, $fromTable2, $whereCondition2, $parameters);
        $offer2 = $result2->fetch_array();
        $result2->free();
        
		$offers = $offer1["offers"]+$offer2["offers"];
        return $offers;
		
	}

	public static function getNumberOfPlayersByTeamId(WebSoccer $websoccer, DbConnection $db, $teamId) {
		/*
			_transfer_angebot 	= verein_id
			_transfer_offer		= sender_club_id
		*/
        $parameters = $teamId;
		$players = 0;
		
		$columns1 = "COUNT(P.*) AS players";
        $fromTable1 = $websoccer->getConfig("db_prefix") . "_spieler AS P";
        $whereCondition1 = "P.verein_id = %d";
        
        $result1 = $db->querySelect($columns1, $fromTable1, $whereCondition1, $parameters);
        $offer1 = $result1->fetch_array();
        $result1->free();
        
		$players = $offer1["players"];
        return $players;
		
	}
	
	public static function getNumberOfPositionsByTeamId(WebSoccer $websoccer, DbConnection $db, $teamId, $position) {
		
		/*
			22 Torwart 2
			22 Abwehr 9
			22 Mittelfeld 8
			22 Sturm 6
		*/
		
        $parameters = $teamId;
		
		$columns = "P.verein_id, P.position, COUNT(P.position) as qty";
        $fromTable = $websoccer->getConfig("db_prefix") . "_spieler AS P";
        $whereCondition = "P.verein_id = %d";
        
        $result = $db->querySelect($columns, $fromTable, $whereCondition, $parameters);
        $offer = $result->fetch_array();
        
        $positions = array();
        while ($statement = $result->fetch_array()) {
            $positions[] = $statement;
        }
        $result->free();
        
        return $positions;
		
	
	}
	
	public static function getPlayersOnTLByTeamId(WebSoccer $websoccer, DbConnection $db, $teamId) {
		
        $parameters = $teamId;
		
		$columns = "COUNT(P.position) as qty";
        $fromTable = $websoccer->getConfig("db_prefix") . "_spieler AS P";
        $whereCondition = "P.tranfermarkt='1' AND P.verein_id = %d";
        
        $result = $db->querySelect($columns, $fromTable, $whereCondition, $parameters);
        $ontl = $result->fetch_array();
        $result->free();
        
		$plonTL = $ontl["qty"];
        return $plonTL;
		
	
	}
	
	public static function getAVGTeamStrength(WebSoccer $websoccer, DbConnection $db, $teamId) {
		/*
			SELECT AVG(w_staerke) AS avg_strength, 
			AVG(w_frische) AS avg_frishness, 
			AVG(w_technik) AS avg_technic, 
			AVG(w_kondition) AS avg_stamina
			FROM `cm23_spieler` 
			WHERE verein_id='22';
		*/
		
        $parameters = $teamId;
		
		$columns = "AVG(w_staerke) AS avg_strength, AVG(w_frische) AS avg_frishness, AVG(w_technik) AS avg_technic, AVG(w_kondition) AS avg_stamina";
        $fromTable = $websoccer->getConfig("db_prefix") . "_spieler AS P";
        $whereCondition = "P.verein_id = %d";
        
        $result = $db->querySelect($columns, $fromTable, $whereCondition, $parameters);
        $avg = $result->fetch_array();
        $result->free();
        
        return $avg;
	}
}
?>