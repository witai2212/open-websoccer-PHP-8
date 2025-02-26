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
 * Data service for players.
 */
class PlayersDataService {

	/**
	 * Provides players of a team, grouped by their positions.
	 * 
	 * @param WebSoccer $websoccer Application context.
	 * @param DbConnection $db DB connection
	 * @param int $clubId ID of team
	 * @param string $positionSort ASC|DESC - sort order of position.
	 * @param boolean $considerBlocksForCups if TRUE, then consider only blocked matches for cups, not for league matches.
	 * @return array array with key=converted position ID, value=array of players.
	 */
    public static function getPlayersOfTeamByPosition(WebSoccer $websoccer, DbConnection $db, $clubId, $positionSort = 'ASC', $considerBlocksForCups = FALSE, $considerBlocks = TRUE) {
		$columns = array(
				'id' => 'id', 
				'vorname' => 'firstname', 
				'nachname' => 'lastname', 
				'kunstname' => 'pseudonym', 
				'verletzt' => 'matches_injured', 
				'position' => 'position', 
				'position_main' => 'position_main', 
				'position_second' => 'position_second', 
				'w_staerke' => 'strength', 
				'w_technik' => 'strength_technique', 
				'w_kondition' => 'strength_stamina', 
				'w_frische' => 'strength_freshness', 
				'w_zufriedenheit' => 'strength_satisfaction', 
				'transfermarkt' => 'transfermarket', 
				'nation' => 'player_nationality', 
				'picture' => 'picture',
				'sa_tore' => 'st_goals',
				'sa_spiele' => 'st_matches',
				'sa_karten_gelb' => 'st_cards_yellow',
				'sa_karten_gelb_rot' => 'st_cards_yellow_red',
				'sa_karten_rot' => 'st_cards_red',
				'marktwert' => 'marketvalue'
				);
		
		if ($websoccer->getConfig('players_aging') == 'birthday') {
			$ageColumn = 'TIMESTAMPDIFF(YEAR,geburtstag,CURDATE())';
		} else {
			$ageColumn = 'age';
		}
		$columns[$ageColumn] = 'age';
		
		if ($considerBlocksForCups) {
			$columns['gesperrt_cups'] = 'matches_blocked';
		} else if ($considerBlocks) {
			$columns['gesperrt'] = 'matches_blocked';
		}
		
		$fromTable = $websoccer->getConfig('db_prefix') . '_spieler';
		$whereCondition = 'status = 1 AND verein_id = %d ORDER BY position '. $positionSort . ', position_main ASC, nachname ASC, vorname ASC';
		$result = $db->querySelect($columns, $fromTable, $whereCondition, $clubId, 50);
		
		$players = array();
		while ($player = $result->fetch_array()) {
			$player['position'] = self::_convertPosition($player['position']);
			$player['player_nationality_filename'] = self::getFlagFilename($player['player_nationality']);
			$player['marketvalue'] = self::getMarketValue($websoccer, $db, $player, '');
			$players[$player['position']][] = $player;
		}
		$result->free();
		
		return $players;
	}
	
	/**
	 * Provides players of a team.
	 * 
	 * @param WebSoccer $websoccer Application context.
	 * @param DbConnection $db DB Connection.
	 * @param int $clubId ID of team
	 * @param boolean $nationalteam TRUE if team is a national team.
	 * @param boolean $considerBlocksForCups if TRUE, then consider only blocked matches for cups, not for league matches. Irrelevant for national teams.
	 * @return array List of players with key=Player ID, value=player info array.
	 */
	public static function getPlayersOfTeamById(WebSoccer $websoccer, DbConnection $db, $clubId, $nationalteam = FALSE, $considerBlocksForCups = FALSE, $considerBlocks = TRUE) {
		
		$columns = array(
				'id' => 'id',
				'vorname' => 'firstname',
				'nachname' => 'lastname',
				'kunstname' => 'pseudonym',
				'verletzt' => 'matches_injured',
				'position' => 'position',
				'position_main' => 'position_main',
				'position_second' => 'position_second',
				'w_staerke' => 'strength',
				'w_technik' => 'strength_technic',
				'w_kondition' => 'strength_stamina',
				'w_frische' => 'strength_freshness',
		        'w_zufriedenheit' => 'strength_satisfaction',
		        'w_talent' => 'strength_talent',
				'transfermarkt' => 'transfermarket',
				'nation' => 'player_nationality',
				'picture' => 'picture',
				'sa_tore' => 'st_goals',
				'sa_spiele' => 'st_matches',
				'sa_karten_gelb' => 'st_cards_yellow',
				'sa_karten_gelb_rot' => 'st_cards_yellow_red',
				'sa_karten_rot' => 'st_cards_red',
				'marktwert' => 'marketvalue',
				'vertrag_spiele' => 'contract_matches',
				'vertrag_gehalt' => 'contract_salary',
				'unsellable' => 'unsellable',
				'lending_matches' => 'lending_matches',
				'lending_fee' => 'lending_fee',
				'lending_owner_id' => 'lending_owner_id',
				'transfermarkt' => 'transfermarket'
		);
		
		if ($websoccer->getConfig('players_aging') == 'birthday') {
			$ageColumn = 'TIMESTAMPDIFF(YEAR,geburtstag,CURDATE())';
		} else {
			$ageColumn = 'age';
		}
		$columns[$ageColumn] = 'age';
		
		if (!$nationalteam) {
			if ($considerBlocksForCups) {
				$columns['gesperrt_cups'] = 'matches_blocked';
			} elseif ($considerBlocks) {
				$columns['gesperrt'] = 'matches_blocked';
			} else {
				$columns['\'0\''] = 'matches_blocked';
			}
			
			$fromTable = $websoccer->getConfig('db_prefix') . '_spieler';
			$whereCondition = 'status = 1 AND verein_id = %d';
		} else {
			$columns['gesperrt_nationalteam'] = 'matches_blocked';
			$fromTable = $websoccer->getConfig('db_prefix') . '_spieler AS P';
			$fromTable .= ' INNER JOIN ' . $websoccer->getConfig('db_prefix') . '_nationalplayer AS NP ON NP.player_id = P.id';
			$whereCondition = 'status = 1 AND NP.team_id = %d';
		}
		
		$whereCondition .= ' ORDER BY position ASC, position_main ASC, nachname ASC, vorname ASC';
		
		$result = $db->querySelect($columns, $fromTable, $whereCondition, $clubId, 50);
	
		$players = array();
		while ($player = $result->fetch_array()) {
			$player['position'] = self::_convertPosition($player['position']);
			$players[$player['id']] = $player;
			
			//update marketvalue
			$marketvalue = PlayersDataService::getMarketValue($websoccer, $db, $player);
			$player['marketvalue'] = $marketvalue;
		}
		$result->free();
	
		return $players;
	}
	
	/**
	 * Provides players who are currently available on the transfer market.
	 * 
	 * @param WebSoccer $websoccer Application context.
	 * @param DbConnection $db DB connection.
	 * @param string $positionFilter position ID as in DB table.
	 * @param int $startIndex fetch start index.
	 * @param int $entries_per_page number of items to fetch.
	 * @return array list of found players or empty array.
	 */
	public static function getPlayersOnTransferList(WebSoccer $websoccer, DbConnection $db, $startIndex, $entries_per_page, $positionFilter) {
		
		$columns['P.id'] = 'id';
		$columns['P.vorname'] = 'firstname';
		$columns['P.nachname'] = 'lastname';
		$columns['P.kunstname'] = 'pseudonym';
		$columns['P.position'] = 'position';
		$columns['P.position_main'] = 'position_main';
		
		$columns['P.vertrag_gehalt'] = 'contract_salary';
		$columns['P.vertrag_torpraemie'] = 'contract_goalbonus';
		
		$columns['P.w_staerke'] = 'strength';
		$columns['P.w_technik'] = 'strength_technique';
		$columns['P.w_kondition'] = 'strength_stamina';
		$columns['P.w_frische'] = 'strength_freshness';
		$columns['P.w_zufriedenheit'] = 'strength_satisfaction';
		
		$columns['P.transfermarkt'] = 'transfermarket';
		$columns['P.marktwert'] = 'marketvalue';
		$columns['P.transfer_start'] = 'transfer_start';
		$columns['P.transfer_ende'] = 'transfer_deadline';
		$columns['P.transfer_mindestgebot'] = 'min_bid';
		
		$columns['C.id'] = 'team_id';
		$columns['C.name'] = 'team_name';
		
		$fromTable = $websoccer->getConfig('db_prefix') . '_spieler AS P';
		$fromTable .= ' LEFT JOIN ' . $websoccer->getConfig('db_prefix') . '_verein AS C ON C.id = P.verein_id';
		
		//$whereCondition = 'P.status = 1 AND P.transfermarkt = 1 AND P.transfer_ende > %d';
		$whereCondition = 'P.status = 1 AND P.transfermarkt = 1';
		$parameters[] = $websoccer->getNowAsTimestamp();
		
		if ($positionFilter != null) {
			$whereCondition .= " AND P.position_main = '$positionFilter'";
			$parameters[] = $positionFilter;
		}
		
		$whereCondition .= ' ORDER BY P.transfer_ende ASC, P.nachname ASC, P.vorname ASC';
		
		$limit = $startIndex .','. $entries_per_page;
		$result = $db->querySelect($columns, $fromTable, $whereCondition, $parameters, $limit);
	
		$players = array();
		while ($player = $result->fetch_array()) {
			
			self::updateMarketValueById($websoccer, $db, $player['id']);
				
			$player['position'] = self::_convertPosition($player['position']);
			$player['highestbid'] = TransfermarketDataService::getHighestBidForPlayer($websoccer, $db, $player['id'], $player['transfer_start'], $player['transfer_deadline']);
			$players[] = $player;

			
		}
		$result->free();
	
		return $players;
	}
	
	/**
	 * Counts number of players who are currently available on the transfer market.
	 * 
	 * @param WebSoccer $websoccer Application context.
	 * @param DbConnection $db DB connection.
	 * @param string $positionFilter position ID as in DB table.
	 * @return int number of found players. 0 if no players found.
	 */
	public static function countPlayersOnTransferList(WebSoccer $websoccer, DbConnection $db, $positionFilter = null) {
	
		$columns = 'COUNT(*) AS hits';
	
		$fromTable = $websoccer->getConfig('db_prefix') . '_spieler AS P';
	
		$whereCondition = 'P.status = 1 AND P.transfermarkt = 1 AND P.transfer_ende > %d';
		$parameters[] = $websoccer->getNowAsTimestamp();
		
		if ($positionFilter != null) {
			$whereCondition .= ' AND P.position_main = \'%s\'';
			$parameters[] = $positionFilter;
		}
		
		$result = $db->querySelect($columns, $fromTable, $whereCondition, $parameters);
		$players = $result->fetch_array();
		$result->free();
		
		if (isset($players['hits'])) {
			return $players['hits'];
		}
	
		return 0;
	}
	
	/**
	 * Provides info about player, its team and lender.
	 * 
	 * @param WebSoccer $websoccer Application context.
	 * @param DbConnection $db DB Connection.
	 * @param int $playerId ID of player.
	 * @return array assoc. array with data about player.
	 */
	public static function getPlayerById(WebSoccer $websoccer, DbConnection $db, $playerId) {

		self::updateMarketValueById($websoccer, $db, $playerId);
		
		$columns['P.id'] = 'player_id';
		$columns['P.vorname'] = 'player_firstname';
		$columns['P.nachname'] = 'player_lastname';
		$columns['P.kunstname'] = 'player_pseudonym';
		$columns['P.position'] = 'player_position';
		$columns['P.position_main'] = 'player_position_main';
		$columns['P.position_second'] = 'player_position_second';
		$columns['P.geburtstag'] = 'player_birthday';
		$columns['P.nation'] = 'player_nationality';
		$columns['P.picture'] = 'player_picture';
		
		if ($websoccer->getConfig('players_aging') == 'birthday') {
			$ageColumn = 'TIMESTAMPDIFF(YEAR,P.geburtstag,CURDATE())';
		} else {
			$ageColumn = 'P.age';
		}
		$columns[$ageColumn] = 'player_age';
		
		$columns['P.verletzt'] = 'player_matches_injured';
		$columns['P.gesperrt'] = 'player_matches_blocked';
		$columns['P.gesperrt_cups'] = 'player_matches_blocked_cups';
		$columns['P.gesperrt_nationalteam'] = 'player_matches_blocked_nationalteam';
		
		$columns['P.vertrag_gehalt'] = 'player_contract_salary';
		$columns['P.vertrag_spiele'] = 'player_contract_matches';
		$columns['P.vertrag_torpraemie'] = 'player_contract_goalbonus';
		
		$columns['P.w_staerke'] = 'player_strength';
		$columns['P.w_technik'] = 'player_strength_technique';
		$columns['P.w_kondition'] = 'player_strength_stamina';
		$columns['P.w_frische'] = 'player_strength_freshness';
		$columns['P.w_zufriedenheit'] = 'player_strength_satisfaction';
		$columns['P.w_talent'] = 'player_strength_talent';
		
		$columns['P.sa_tore'] = 'player_season_goals';
		$columns['P.sa_assists'] = 'player_season_assists';
		$columns['P.sa_spiele'] = 'player_season_matches';
		$columns['P.sa_karten_gelb'] = 'player_season_yellow';
		$columns['P.sa_karten_gelb_rot'] = 'player_season_yellow_red';
		$columns['P.sa_karten_rot'] = 'player_season_red';
		
		$columns['P.st_tore'] = 'player_total_goals';
		$columns['P.st_assists'] = 'player_total_assists';
		$columns['P.st_spiele'] = 'player_total_matches';
		$columns['P.st_karten_gelb'] = 'player_total_yellow';
		$columns['P.st_karten_gelb_rot'] = 'player_total_yellow_red';
		$columns['P.st_karten_rot'] = 'player_total_red';
		
	
		$columns['P.transfermarkt'] = 'player_transfermarket';
		$columns['P.marktwert'] = 'player_marketvalue';
		
		$columns['P.transfer_start'] = 'transfer_start';
		$columns['P.transfer_ende'] = 'transfer_end';
		$columns['P.transfer_mindestgebot'] = 'transfer_min_bid';
		
		$columns['P.history'] = 'player_history';
		
		$columns['P.unsellable'] = 'player_unsellable';
		
		$columns['P.lending_owner_id'] = 'lending_owner_id';
		$columns['L.name'] = 'lending_owner_name';
		$columns['P.lending_fee'] = 'lending_fee';
		$columns['P.lending_matches'] = 'lending_matches';
		
		$columns['C.id'] = 'team_id';
		$columns['C.name'] = 'team_name';
		$columns['C.finanz_budget'] = 'team_budget';
		$columns['C.user_id'] = 'team_user_id';
		
		$columns['(SELECT CONCAT(AVG(S.note), \';\', SUM(S.assists)) FROM ' . $websoccer->getConfig('db_prefix') . '_spiel_berechnung AS S WHERE S.spieler_id = P.id AND S.minuten_gespielt > 0 AND S.note > 0)'] = 'matches_info';
		
		$fromTable = $websoccer->getConfig('db_prefix') . '_spieler AS P';
		$fromTable .= ' LEFT JOIN ' . $websoccer->getConfig('db_prefix') . '_verein AS C ON C.id = P.verein_id';
		$fromTable .= ' LEFT JOIN ' . $websoccer->getConfig('db_prefix') . '_verein AS L ON L.id = P.lending_owner_id';
		
		$whereCondition = 'P.status = 1 AND P.id = %d';
		$players = $db->queryCachedSelect($columns, $fromTable, $whereCondition, $playerId, 1);
		if (count($players)) {
			$player = $players[0];
						$player['player_position_de'] = $player['player_position'];
			$player['player_position'] = self::_convertPosition($player['player_position']);
			$player['player_marketvalue'] = self::getMarketValue($websoccer, $db, $player);
			$player['player_nationality_filename'] = self::getFlagFilename($player['player_nationality']);
			
			$matchesInfo = explode(';', $player['matches_info']);
			if(empty($matchesInfo[0])) {
			    $matchesInfo[0] = 0;
			}
			$player['player_avg_grade'] = round($matchesInfo[0], 2);
			if (isset($matchesInfo[1])) {
				$player['player_assists'] = $matchesInfo[1];
			} else {
				$player['player_assists'] = 0;
			}
			
		} else {
			$player = array();
		}
		return $player;
	}
	
	/**
	 * Provides players ranked by number of shot goals in the current season.
	 * 
	 * @param WebSoccer $websoccer Application context.
	 * @param DbConnection $db DB connection.
	 * @param int $limit Maximum number of players to fetch.
	 * @param int|NULL $leagueId ID of league. If not provided, total top strikers will be returned.
	 * @return array list of found players or empty array if no players exist.
	 */
	public static function getTopStrikers(WebSoccer $websoccer, DbConnection $db, $limit = 20, $leagueId = null) {
		$parameters = array();
		
		$columns['P.id'] = 'id';
		$columns['P.vorname'] = 'firstname';
		$columns['P.nachname'] = 'lastname';
		$columns['P.kunstname'] = 'pseudonym';
		
		$columns['P.sa_tore'] = 'goals';
		$columns['P.sa_spiele'] = 'matches';
		
		$columns['P.transfermarkt'] = 'transfermarket';
		
		$columns['C.id'] = 'team_id';
		$columns['C.name'] = 'team_name';
		
		$fromTable = $websoccer->getConfig('db_prefix') . '_spieler AS P';
		$fromTable .= ' LEFT JOIN ' . $websoccer->getConfig('db_prefix') . '_verein AS C ON C.id = P.verein_id';
		
		$whereCondition = 'P.status = 1 AND P.sa_tore > 0';
		if ($leagueId != null) {
			$whereCondition .= ' AND liga_id = %d';
			$parameters[] = (int) $leagueId;
		}
		$whereCondition .= ' ORDER BY P.sa_tore DESC, P.sa_spiele ASC';
		
		$result = $db->querySelect($columns, $fromTable, $whereCondition, $parameters, $limit);
		
		$players = array();
		while ($player = $result->fetch_array()) {
			$players[] = $player;
		}
		$result->free();
		
		return $players;
	}
	
	/**
	 * Provides players ranked by sum of number of shot goals and assists in the current season.
	 *
	 * @param WebSoccer $websoccer Application context.
	 * @param DbConnection $db DB connection.
	 * @param int $limit Maximum number of players to fetch.
	 * @param int|NULL $leagueId ID of league. If not provided, total top strikers will be returned.
	 * @return array list of found players or empty array if no players exist.
	 */
	public static function getTopScorers(WebSoccer $websoccer, DbConnection $db, $limit = 20, $leagueId = null) {
		$parameters = array();
	
		$columns['P.id'] = 'id';
		$columns['P.vorname'] = 'firstname';
		$columns['P.nachname'] = 'lastname';
		$columns['P.kunstname'] = 'pseudonym';
	
		$columns['P.sa_tore'] = 'goals';
		$columns['P.sa_assists'] = 'assists';
		$columns['P.sa_spiele'] = 'matches';
		
		$columns['(P.sa_tore + P.sa_assists)'] = 'score';
	
		$columns['P.transfermarkt'] = 'transfermarket';
	
		$columns['C.id'] = 'team_id';
		$columns['C.name'] = 'team_name';
	
		$fromTable = $websoccer->getConfig('db_prefix') . '_spieler AS P';
		$fromTable .= ' LEFT JOIN ' . $websoccer->getConfig('db_prefix') . '_verein AS C ON C.id = P.verein_id';
	
		$whereCondition = 'P.status = \'1\' AND (P.sa_tore + P.sa_assists) > 0';
		if ($leagueId != null) {
			$whereCondition .= ' AND liga_id = %d';
			$parameters[] = (int) $leagueId;
		}
		$whereCondition .= ' ORDER BY score DESC, P.sa_assists DESC, P.sa_tore DESC, P.sa_spiele ASC, P.id ASC';
	
		$result = $db->querySelect($columns, $fromTable, $whereCondition, $parameters, $limit);
	
		$players = array();
		while ($player = $result->fetch_array()) {
			$players[] = $player;
		}
		$result->free();
	
		return $players;
	}
	
	/**
	 * Dynamic query to find players by specified criteria.
	 * 
	 * @param WebSoccer $websoccer Application context.
	 * @param DbConnection $db DB connection.
	 * @param string $firstName Start of first name (case sensitive).
	 * @param string $lastName Start of last name or pseudonym (case sensitive).
	 * @param string $clubName name of team (exact match)
	 * @param string $position position ID as in DB.
	 * @param int $strengthMax Maximum strength value
	 * @param boolean $lendableOnly TRUE if only lendable players shall be returned.
	 * @param int $startIndex fetch start index.
	 * @param int $entries_per_page number of items to fetch.
	 * @return array list of found players or empty array.
	 */
	public static function findPlayers(WebSoccer $websoccer, DbConnection $db, 
			$firstName, $lastName, $clubName, $position, $strengthMax, $lendableOnly, $startIndex, $entries_per_page) {
		
		$columns['P.id'] = 'id';
		$columns['P.vorname'] = 'firstname';
		$columns['P.nachname'] = 'lastname';
		$columns['P.kunstname'] = 'pseudonym';
		
		$columns['P.position'] = 'position';
		$columns['P.position_main'] = 'position_main';
		$columns['P.position_second'] = 'position_second';
		
		$columns['P.transfermarkt'] = 'transfermarket';
		$columns['P.unsellable'] = 'unsellable';
		
		$columns['P.w_staerke'] = 'strength';
		$columns['P.w_technik'] = 'strength_technique';
		$columns['P.w_kondition'] = 'strength_stamina';
		$columns['P.w_frische'] = 'strength_freshness';
		$columns['P.w_zufriedenheit'] = 'strength_satisfaction';
		
		$columns['P.vertrag_gehalt'] = 'contract_salary';
		$columns['P.vertrag_spiele'] = 'contract_matches';
		
		$columns['P.lending_owner_id'] = 'lending_owner_id';
		$columns['P.lending_fee'] = 'lending_fee';
		$columns['P.lending_matches'] = 'lending_matches';
		
		$columns['C.id'] = 'team_id';
		$columns['C.name'] = 'team_name';	
		
		$limit = $startIndex .','. $entries_per_page;
		$result = self::executeFindQuery($websoccer, $db, $columns, $limit, $firstName, $lastName, $clubName, $position, $strengthMax, $lendableOnly);
		
		$players = array();
		while ($player = $result->fetch_array()) {
			$player['position'] = self::_convertPosition($player['position']);
			$players[] = $player;
			
		}
		$result->free();
		
		return $players;
		
	}
	
	/**
	 * Counts found players of dynamic query.
	 * 
	 * @param WebSoccer $websoccer Application context.
	 * @param DbConnection $db DB connection.
	 * @param string $firstName Start of first name (case sensitive).
	 * @param string $lastName Start of last name or pseudonym (case sensitive).
	 * @param string $clubName name of team (exact match)
	 * @param string $position position ID as in DB.
	 * @param int $strengthMax Maximum strength value
	 * @param boolean $lendableOnly TRUE if only lendable players shall be returned.
	 * @return int number of found player. 0 if no players found.
	 */
	public static function findPlayersCount(WebSoccer $websoccer, DbConnection $db,
			$firstName, $lastName, $clubName, $position, $strengthMax, $lendableOnly) {
		$columns = 'COUNT(*) AS hits';
		
		$result = self::executeFindQuery($websoccer, $db, $columns, 1, 
				$firstName, $lastName, $clubName, $position, $strengthMax, $lendableOnly);
		$players = $result->fetch_array();
		$result->free();
		
		if (isset($players['hits'])) {
			return $players['hits'];
		}
		
		return 0;
	}
	
	private static function executeFindQuery(WebSoccer $websoccer, DbConnection $db, $columns, $limit,
			$firstName, $lastName, $clubName, $position, $strengthMax, $lendableOnly) {
		$whereCondition = 'P.status = 1';
		
		$parameters = array();
		
		if ($firstName != null) {
			$firstName = ucfirst($firstName);
			$whereCondition .= ' AND P.vorname LIKE \'%s%%\'';
			$parameters[] = $firstName;
		}
		
		if ($lastName != null) {
			$lastName = ucfirst($lastName);
			$whereCondition .= ' AND (P.nachname LIKE \'%s%%\' OR P.kunstname LIKE \'%s%%\')';
			$parameters[] = $lastName;
			$parameters[] = $lastName;
		}
		
		if ($clubName != null) {
			$whereCondition .= ' AND C.name = \'%s\'';
			$parameters[] = $clubName;
		}
		
		if ($position != null) {
			$whereCondition .= ' AND P.position = \'%s\'';
			$parameters[] = $position;
		}
		
		if ($strengthMax != null && $websoccer->getConfig('hide_strength_attributes') !== '1') {
			$strengthMinValue = $strengthMax - 20;
			$strengthMaxValue = $strengthMax;
			
			$whereCondition .= ' AND P.w_staerke > %d AND P.w_staerke <= %d';
			$parameters[] = $strengthMinValue;
			$parameters[] = $strengthMaxValue;
		}
		
		if ($lendableOnly) {
			$whereCondition .= ' AND P.lending_fee > 0 AND (P.lending_owner_id IS NULL OR P.lending_owner_id = 0)';
		}
		
		$fromTable = $websoccer->getConfig('db_prefix') . '_spieler AS P';
		$fromTable .= ' LEFT JOIN ' . $websoccer->getConfig('db_prefix') . '_verein AS C ON C.id = P.verein_id';
		
		return $db->querySelect($columns, $fromTable, $whereCondition, $parameters, $limit);
	}
	
	/**
	 * Converts DB position ID into ID for view.
	 * 
	 * @param string $dbPosition Position ID as in database.
	 * @return string goaly|defense|midfield|striker
	 */
	public static function _convertPosition($dbPosition) {
		switch ($dbPosition) {
			case 'Torwart':
				return 'goaly';
			case 'Abwehr':
				return 'defense';
			case 'Mittelfeld':
				return 'midfield';
			default:
				return 'striker';
		}
	
	}
		
	/**
	 * Provides market value of player. Depending on settings, either value from DB table or computed value.
	 * Computed value is configured value per strength point * weighted total strength of player.
	 * 
	 * @param WebSoccer $websoccer Application context.
	 * @param array $player Player info array.
	 * @param string $columnPrefix column prefix used in player array.
	 * @return int market value of player.
	 */
	public static function getMarketValue(WebSoccer $websoccer, DbConnection $db, $player, $columnPrefix = 'player_') {
		if (!$websoccer->getConfig('transfermarket_computed_marketvalue')) {
			return $player[$columnPrefix . 'marketvalue'];
		}
		
		$playerId = $player['player_id'];
		$playerAge = $player['player_age'];
		
		$queryString = "SELECT position, geburtstag, w_staerke, w_staerke_max, w_technik, w_kondition, w_frische, w_zufriedenheit, w_talent
                FROM ". $websoccer->getConfig('db_prefix') ."_spieler 
                WHERE id='$playerId'";
		$result = $db->executeQuery($queryString);
		
		$player_data = $result->fetch_array();
		$result->free();
		
		// compute market value
		$player_strength = $player_data['w_staerke'] * $websoccer->getConfig('sim_weight_strength');
		$player_strength = $player_data['w_staerke_max'];
		$player_technik = $player_data['w_technik'] * $websoccer->getConfig('sim_weight_strengthTech');
		$player_kondition = $player_data['w_kondition'] * $websoccer->getConfig('sim_weight_strengthStamina');
		$player_frische = $player_data['w_frische'] * $websoccer->getConfig('sim_weight_strengthFreshness');
		$player_zufriedenheit = $player_data['w_zufriedenheit'] * $websoccer->getConfig('sim_weight_strengthSatisfaction');
		$player_talent = $player_data['w_talent'];
				
		//player age factor
		if($playerAge>15 && $playerAge<22) {
		    $rand1 = 85;
		    $rand2 = 85;
		} else if($playerAge>21 && $playerAge<30) {
		    $rand1 = 95;
		    $rand2 = 95;
		} else if($playerAge>29 && $playerAge<36) {
		    $rand1 = 75;
		    $rand2 = 75;
		}
		mt_srand((double)microtime()*1000000);
		$age_factor = mt_rand($rand1,$rand2);
		
		$total_strength = ($player_strength + $player_technik + $player_kondition + $player_frische + $player_zufriedenheit)/5;
		$marketvalue = ROUND(($total_strength * ($player_talent/5) * $websoccer->getConfig('transfermarket_value_per_strength'))*($age_factor/100));
		
		$playerStrength = self::calculatePlayerStrength($player_strength, $player_kondition, $player_frische, $player_zufriedenheit, $player_technik, $player_talent, $playerAge);
		$marketvalue = self::calculateMarketValue($websoccer, $playerStrength, $playerAge, $player_talent, $player_data['position']);
		
		// update marketvalue in DB
		self::setPlayerMarketValue($websoccer, $db, $playerId, $marketvalue);
			
		return $marketvalue;
	}
	
	/**
	 * Provides the correct flag file name for specified nationality.
	 * Removes umlauts.
	 * 
	 * @param string $nationality
	 * @return string fag file name.
	 */
	public static function getFlagFilename($nationality) {
		if (!strlen($nationality)) {
			return $nationality;
		}
		
		// remove umlauts
		$filename = str_replace('??', 'Ae', $nationality);
		$filename = str_replace('??', 'Oe', $filename);
		$filename = str_replace('??', 'Ue', $filename);
		
		$filename = str_replace('??', 'ae', $filename);
		$filename = str_replace('??', 'oe', $filename);
		$filename = str_replace('??', 'ue', $filename);
		return $filename;
	}

	/**
	 * Provides best players.
	 *
	 * @param string $nationalitygetConfig('db_prefix')
	 * @return array with best players.
	 */
	public static function getBestPlayersByStrength(WebSoccer $websoccer, DbConnection $db) {
		
		/*
		$total_strength = ($player_strength + $player_technik + $player_kondition + $player_frische + $player_zufriedenheit)/5;
		$marketvalue = ROUND(($total_strength * ($player_talent/5) * $websoccer->getConfig('transfermarket_value_per_strength'))*($age_factor/100));
		*/
	    
	    $queryString = "SELECT S.*, (S.w_staerke/S.w_talent) AS strength
                            FROM ". $websoccer->getConfig('db_prefix') ."_spieler AS S
                            ORDER BY ((((w_staerke + w_technik + w_kondition + w_frische + w_zufriedenheit)/5)*(w_talent/5)*".$websoccer->getConfig('transfermarket_value_per_strength').")) DESC, 
								marktwert DESC LIMIT 20";
	    $result = $db->executeQuery($queryString);
	    
	    $players = array();
	    $i = 0;
	    while ($player = $result->fetch_array()) {
	        
	        $players[] = $player;
	        if(isset($players[$i]['verein_id'])) {
	            $verein = TeamsDataService::getTeamById($websoccer, $db, $players[$i]['verein_id']);
	            $players[$i]['verein'] = $verein;
	        }
	    $i++;
	    }
	    $result->free();
	    
	    return $players;
	}
	
	/**
	 * Provides most valueable players.
	 *
	 * @param string $nationalitygetConfig('db_prefix')
	 * @return array with best players.
	 */
	public static function getMostValuablePlayers(WebSoccer $websoccer, DbConnection $db) {
	    
	    $queryString = "SELECT * FROM ". $websoccer->getConfig('db_prefix') ."_spieler
                            ORDER BY marktwert DESC LIMIT 20";
	    $result = $db->executeQuery($queryString);
	    
	    $players = array();
	    $i = 0;
	    while ($player = $result->fetch_array()) {
	        
	        $players[] = $player;
	        if(isset($players[$i]['verein_id'])) {
	            $verein = TeamsDataService::getTeamById($websoccer, $db, $players[$i]['verein_id']);
	            $players[$i]['verein'] = $verein;
	        }
	        $i++;
	    }
	    $result->free();
	    
	    return $players;
	}
	
	/**
	 * Provides best players.
	 *
	 * @param string $nationalitygetConfig('db_prefix')
	 * @return array with best players.
	 */
	public static function getBestGoalscorers(WebSoccer $websoccer, DbConnection $db) {
	    
	    $queryString = "SELECT *, (sa_tore+sa_assists) AS scores
                            FROM ". $websoccer->getConfig('db_prefix') ."_spieler
                            ORDER BY (sa_tore/sa_spiele) DESC, 
                                sa_tore DESC, sa_assists, note_schnitt DESC
                            LIMIT 20";
	    $result = $db->executeQuery($queryString);
	    
	    $players = array();
	    $i = 0;
	    while ($player = $result->fetch_array()) {
	        
	        $players[] = $player;
	        if(isset($players[$i]['verein_id'])) {
	            $verein = TeamsDataService::getTeamById($websoccer, $db, $players[$i]['verein_id']);
	            $players[$i]['verein'] = $verein;
	        }
	        $i++;
	    }
	    $result->free();
	    
	    return $players;
	}

	/**
	 * Provides on which Teams watchlist the playerId is.
	 *
	 * @param string $playerId
	 * @return array with club names.
	 */
	public static function whoIsWatchingPlayerId(WebSoccer $websoccer, DbConnection $db, $playerId) {
	    
	    $queryString = "SELECT wl.verein_id, v.name, v.bild, l.land
                FROM ". $websoccer->getConfig('db_prefix') ."_watchlist AS wl, " .$websoccer->getConfig("db_prefix") . "_verein AS v,
						". $websoccer->getConfig('db_prefix') ."_liga AS l
                WHERE wl.spieler_id='$playerId' 
                    AND wl.verein_id=v.id
					AND l.id=v.liga_id";
	    $result = $db->executeQuery($queryString);
	    
	    $clubs = array();
	    $i = 0;
	    while ($club = $result->fetch_array()) {
	        $clubs[$i]['name'] = $club['name'];
	        $clubs[$i]['bild'] = $club['bild'];
			$clubs[$i]['land'] = $club['land'];
	        $i++;
	    }
	    $result->free();
	    
	    return $clubs;
	}
	
	/**
	 * Provides if playerId is on my watchlist.
	 *
	 * @param string $playerId
	 * @return boolean.
	 */
	public static function checkIfPlayerOnWatchlist(WebSoccer $websoccer, DbConnection $db, $playerId, $teamId) {
	    $queryString = "SELECT spieler_id  
                        FROM ". $websoccer->getConfig('db_prefix') ."_watchlist AS wl
                        WHERE spieler_id='$playerId' AND wl.verein_id='$teamId'";
	    $result = $db->executeQuery($queryString);
	    $wl = $result->fetch_array();
	    if(isset($wl['spieler_id'])) {
	        return true;
	    } else {
	        return false;
	    }
	}
	
	/*
	 * Correction on w_staerke acc. to w_staerke_max
	 */
	public static function playerStrengthCorrection(WebSoccer $websoccer, DbConnection $db) {
	    $updStr = "UPDATE ". $websoccer->getConfig('db_prefix') ."_spieler SET w_staerke=w_staerke_max WHERE w_staerke>w_staerke_max";
	    $db->executeQuery($updStr);
	}
	
	/*
	 * Save Marketvalue of PlayerId
	 *
	*/
	public static function setPlayerMarketValue(WebSoccer $websoccer, DbConnection $db, $playerId, $marketvalue) {
		
		// update marketvalue in DB
		$updStr = "UPDATE ". $websoccer->getConfig('db_prefix') ."_spieler SET marktwert='$marketvalue' WHERE id='$playerId'";
    	$db->executeQuery($updStr);
		
		
	}
	
	/*
	*
	* generate Marketvalue
	*/
	public static function updateMarketValueById(WebSoccer $websoccer, DbConnection $db, $playerId) {
			
		$sqlStr = "SELECT id AS player_id, TIMESTAMPDIFF(YEAR, geburtstag, CURDATE()) AS player_age
				FROM ". $websoccer->getConfig('db_prefix') ."_spieler WHERE id='$playerId'
				ORDER BY id";
		$result1 = $db->executeQuery($sqlStr);
		while ($player_data = $result1->fetch_array()) {
			
			$playerId = $player_data['player_id'];
			$playerAge = $player_data['player_age'];
	
			$queryString = "SELECT position, geburtstag, w_staerke, w_staerke_max, w_technik, w_kondition, w_frische, w_zufriedenheit, w_talent
					FROM ". $websoccer->getConfig('db_prefix') ."_spieler 
					WHERE id='$playerId'";
			$result2 = $db->executeQuery($queryString);
			
			$player_data = $result2->fetch_array();
			$result2->free();
			
			// compute market value
			$player_strength = $player_data['w_staerke'] * $websoccer->getConfig('sim_weight_strength');
			$player_strength = $player_data['w_staerke_max'];
			$player_technik = $player_data['w_technik'] * $websoccer->getConfig('sim_weight_strengthTech');
			$player_kondition = $player_data['w_kondition'] * $websoccer->getConfig('sim_weight_strengthStamina');
			$player_frische = $player_data['w_frische'] * $websoccer->getConfig('sim_weight_strengthFreshness');
			$player_zufriedenheit = $player_data['w_zufriedenheit'] * $websoccer->getConfig('sim_weight_strengthSatisfaction');
			$player_talent = $player_data['w_talent'];

			$playerStrength = self::calculatePlayerStrength($player_strength, $player_kondition, $player_frische, $player_zufriedenheit, $player_technik, $player_talent, $playerAge);
			$marketvalue = self::calculateMarketValue($websoccer, $playerStrength, $playerAge, $player_talent, $player_data['position']);
			
			// update marketvalue in DB
			self::setPlayerMarketValue($websoccer, $db, $playerId, $marketvalue);
			
		}
		$result1->free();
	}
	
	/*
	 *
	 * generate Marketvalue
	 */
	public static function updateMarketValue(WebSoccer $websoccer, DbConnection $db) {
	    
	    if(!isset($_SESSION['market_value_calculated']) || $_SESSION['market_value_calculated']=='0') {
	        
	        $sqlStr = "SELECT id AS player_id, TIMESTAMPDIFF(YEAR, geburtstag, CURDATE()) AS player_age
					FROM ". $websoccer->getConfig('db_prefix') ."_spieler
					ORDER BY id";
	        $result1 = $db->executeQuery($sqlStr);
	        while ($player_data = $result1->fetch_array()) {
	            
	            $playerId = $player_data['player_id'];
	            $playerAge = $player_data['player_age'];
	            
	            $queryString = "SELECT position, geburtstag, w_staerke, w_staerke_max, w_technik, w_kondition, w_frische, w_zufriedenheit, w_talent
						FROM ". $websoccer->getConfig('db_prefix') ."_spieler
						WHERE id='$playerId'";
	            $result2 = $db->executeQuery($queryString);
	            
	            $player_data = $result2->fetch_array();
	            $result2->free();
	            
	            // compute market value
	            $player_strength = $player_data['w_staerke'] * $websoccer->getConfig('sim_weight_strength');
	            $player_strength = $player_data['w_staerke_max'];
	            $player_technik = $player_data['w_technik'] * $websoccer->getConfig('sim_weight_strengthTech');
	            $player_kondition = $player_data['w_kondition'] * $websoccer->getConfig('sim_weight_strengthStamina');
	            $player_frische = $player_data['w_frische'] * $websoccer->getConfig('sim_weight_strengthFreshness');
	            $player_zufriedenheit = $player_data['w_zufriedenheit'] * $websoccer->getConfig('sim_weight_strengthSatisfaction');
	            $player_talent = $player_data['w_talent'];

	            $playerStrength = self::calculatePlayerStrength($player_strength, $player_kondition, $player_frische, $player_zufriedenheit, $player_technik, $player_talent, $playerAge);
	            $marketvalue = self::calculateMarketValue($websoccer, $playerStrength, $playerAge, $player_talent, $player_data['position']);
	            
	            // update marketvalue in DB
	            self::setPlayerMarketValue($websoccer, $db, $playerId, $marketvalue);
	            
	        }
	        $result1->free();
	        $_SESSION['market_value_calculated'] = '1';
	    }
	}
	
	/*
	 *
	 * count total offers for players
	*/
	public static function countPlayerOffers(WebSoccer $websoccer, DbConnection $db) {
		
		$columns = 'COUNT(*) AS hits';
	
		$fromTable = $websoccer->getConfig('db_prefix') . '_transfer_angebot AS A';
	
		$whereCondition = 'A.id > 0';
		$parameters[] = $websoccer->getNowAsTimestamp();

		$result = $db->querySelect($columns, $fromTable, $whereCondition, $parameters);
		$players = $result->fetch_array();
		$result->free();
		
		if (isset($players['hits'])) {
			return $players['hits'];
		}
	
		return 0;
		
	}
	
	public static function calculatePlayerStrength($w_strength, $w_stamina, $w_freshness, $w_satisfaction, $w_technique, $w_talent, $age) {
	    // Weight factors
	    $W_p = 0.4; // Physical Performance Weight
	    $W_s = 0.3; // Skill & Mental Weight
	    $W_t = 0.2; // Talent Weight
	    $W_a = 0.1; // Age Factor Weight
	    
	    // Physical Performance Calculation
	    $P = ($w_strength * 0.4) + ($w_stamina * 0.35) + ($w_freshness * 0.25);
	    
	    // Skill & Mental Calculation
	    $S = ($w_technique * 0.6) + ($w_satisfaction * 0.4);
	    
	    // Talent Contribution
	    $T = $w_talent * 20; // Scale talent (1-5) to (20-100)
	    
	    // Age Factor Calculation (Peak age assumed at 28)
	    $A = 100 - abs(28 - $age) * 2;
	    $A = max(0, $A); // Ensure it doesn't go below 0
	    
	    // Final Strength Calculation
	    $overallStrength = ($P * $W_p) + ($S * $W_s) + ($T * $W_t) + ($A * $W_a);
	    
	    return round($overallStrength, 2); // Round to 2 decimal places
	}
	
	public static function calculateMarketValue($websoccer, $strength, $age, $talent, $position) {
	    // Base market value multiplier (adjust as needed)
	    $baseValue = $websoccer->getConfig('transfermarket_value_per_strength'); // Base price for an average player
	    
	    // Age factor: Younger players (18-26) have higher value
	    if ($age <= 18) {
	        $ageFactor = 1.5;
	    } elseif ($age <= 24) {
	        $ageFactor = 1.3;
	    } elseif ($age <= 28) {
	        $ageFactor = 1.1;
	    } elseif ($age <= 32) {
	        $ageFactor = 0.8;
	    } else {
	        $ageFactor = 0.5; // Older players lose market value
	    }
	    
	    // Talent factor: More talented players are more expensive
	    $talentFactor = 1 + ($talent - 1) * 0.3; // Talent 1 = 1.0, Talent 5 = 2.2
	    
	    // Position Factor: Attackers are typically more expensive
	    $positionFactors = [
	        "Torwart" => 0.8,  // Goalkeepers are slightly cheaper
	        "Abwehr" => 0.9, // Defenders are cheaper
	        "Mittelfeld" => 1.0, // Midfielders standard price
	        "Sturm" => 1.2  // Attackers are more expensive
	    ];
	    $positionFactor = isset($positionFactors[$position]) ? $positionFactors[$position] : 1.0;
	    
	    // Market value formula
	    $marketValue = $baseValue * pow($strength / 100, 2) * $ageFactor * $talentFactor * $positionFactor;
	    
	    return round($marketValue, 0); // Round to 0 decimal places
	}
	
	
}
?>