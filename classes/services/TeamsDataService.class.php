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
 * Data service for teams
 */
class TeamsDataService {

	/**
	 * Provides data about team with specified ID.
	 * 
	 * @param WebSoccer $websoccer Application Context
	 * @param DbConnection $db DB connection
	 * @param int $teamId ID of requested team
	 * @return array team information as an assoc. array.
	 */
	public static function getTeamById(WebSoccer $websoccer, DbConnection $db, $teamId) {
		$fromTable = self::_getFromPart($websoccer);
		
		// where
		$whereCondition = 'C.id = %d AND C.status = 1';
		$parameters = $teamId;
	
		// select
		$columns['C.id'] = 'team_id';
		$columns['C.bild'] = 'team_logo';
		$columns['C.name'] = 'team_name';
		$columns['C.kurz'] = 'team_short';
		$columns['C.strength'] = 'team_strength';
		$columns['C.finanz_budget'] = 'team_budget';
		$columns['C.min_target_rank'] = 'team_min_target_rank';
		$columns['C.nationalteam'] = 'is_nationalteam';
		$columns['C.captain_id'] = 'captain_id';
		$columns['C.interimmanager'] = 'interimmanager';
		
		$columns['C.history'] = 'team_history';
		
		$columns['L.name'] = 'team_league_name';
		$columns['L.id'] = 'team_league_id';
		$columns['SPON.name'] = 'team_sponsor_name';
		$columns['SPON.bild'] = 'team_sponsor_picture';
		$columns['SPON.id'] = 'team_sponsor_id';
		$columns['U.nick'] = 'team_user_name';
		$columns['U.id'] = 'team_user_id';
		$columns['U.email'] = 'team_user_email';
		$columns['U.picture'] = 'team_user_picture';
		$columns['DEPUTY.nick'] = 'team_deputyuser_name';
		$columns['DEPUTY.id'] = 'team_deputyuser_id';
		$columns['DEPUTY.email'] = 'team_deputyuser_email';
		$columns['DEPUTY.picture'] = 'team_deputyuser_picture';
		
		// statistic
		$columns['C.sa_tore'] = 'team_season_goals';
		$columns['C.sa_gegentore'] = 'team_season_againsts';
		$columns['C.sa_spiele'] = 'team_season_matches';
		$columns['C.sa_siege'] = 'team_season_wins';
		$columns['C.sa_niederlagen'] = 'team_season_losses';
		$columns['C.sa_unentschieden'] = 'team_season_draws';
		$columns['C.sa_punkte'] = 'team_season_score';
		
		$columns['C.st_tore'] = 'team_total_goals';
		$columns['C.st_gegentore'] = 'team_total_againsts';
		$columns['C.st_spiele'] = 'team_total_matches';
		$columns['C.st_siege'] = 'team_total_wins';
		$columns['C.st_niederlagen'] = 'team_total_losses';
		$columns['C.st_unentschieden'] = 'team_total_draws';
		$columns['C.st_punkte'] = 'team_total_score';
		
		$teaminfos = $db->queryCachedSelect($columns, $fromTable, $whereCondition, $parameters, 1);
		$team = (isset($teaminfos[0])) ? $teaminfos[0] : array();
		
		if (isset($team['team_user_email'])) {
			$team['user_picture'] = UsersDataService::getUserProfilePicture($websoccer, $team['team_user_picture'], $team['team_user_email'], 20);
		}
		
		if (isset($team['team_deputyuser_email'])) {
			$team['deputyuser_picture'] = UsersDataService::getUserProfilePicture($websoccer, $team['team_deputyuser_picture'], $team['team_deputyuser_email'], 20);
		}
		
		return $team;
	}
	
	/**
	 * Provides name, budget, user ID (without name), league name and league id of team with specified ID.
	 * Query is cached.
	 * 
	 * @param WebSoccer $websoccer Application Context
	 * @param DbConnection $db DB connection
	 * @param int $teamId ID of requested team.
	 * @return array data about requested team as assoc. array. If not found, then empty array. If no team ID provided, then NULL.
	 */
	public static function getTeamSummaryById(WebSoccer $websoccer, DbConnection $db, $teamId) {
		if (!$teamId) {
			return NULL;
		}
		
		$tablePrefix = $websoccer->getConfig('db_prefix');
		
		// from
		$fromTable = $tablePrefix . '_verein AS C';
		$fromTable .= ' LEFT JOIN ' . $tablePrefix . '_liga AS L ON C.liga_id = L.id';
		
		// where
		$whereCondition = 'C.status = 1 AND C.id = %d';
		$parameters = $teamId;
		
		// select
		$columns['C.id'] = 'team_id';
		$columns['C.name'] = 'team_name';
		$columns['C.finanz_budget'] = 'team_budget';
		$columns['C.bild'] = 'team_picture';
		
		$columns['C.user_id'] = 'user_id';
		
		$columns['L.name'] = 'team_league_name';
		$columns['L.id'] = 'team_league_id';
		
		$teaminfos = $db->queryCachedSelect($columns, $fromTable, $whereCondition, $parameters, 1);
		$team = (isset($teaminfos[0])) ? $teaminfos[0] : array();
		
		return $team;
	}
	
	/**
	 * Provides clubs in order of league table criteria.
	 * Additionally updates league history at first time of user session.
	 * 
	 * @param WebSoccer $websoccer Application Context
	 * @param DbConnection $db DB connection
	 * @param int $leagueId league ID
	 * @return array array of teams, ordered by table criteria.
	 */
	public static function getTeamsOfLeagueOrderedByTableCriteria(WebSoccer $websoccer, DbConnection $db, $leagueId) {
		
		// get current season
		$result = $db->querySelect('id', $websoccer->getConfig('db_prefix') .'_saison',
				'liga_id = %d AND beendet = \'0\' ORDER BY name DESC', $leagueId, 1);
		$season = $result->fetch_array();
		$result->free();
		
		$fromTable = $websoccer->getConfig('db_prefix') . '_verein AS C';
		$fromTable .= ' LEFT JOIN ' . $websoccer->getConfig('db_prefix') . '_user AS U ON C.user_id = U.id';
		$fromTable .= ' LEFT JOIN ' . $websoccer->getConfig('db_prefix') . '_leaguehistory AS PREVDAY ON (PREVDAY.team_id = C.id AND PREVDAY.matchday = (C.sa_spiele - 1)';
		if ($season) {
			$fromTable .= ' AND PREVDAY.season_id = ' . $season['id'];
		}
		$fromTable .= ')';
		
		$columns = array();
		$columns['C.id'] = 'id';
		$columns['C.name'] = 'name';
		$columns['C.sa_punkte'] = 'score';
		$columns['C.sa_tore'] = 'goals';
		$columns['C.sa_gegentore'] = 'goals_received';
		$columns['(C.sa_tore - C.sa_gegentore)'] = 'goals_diff';
		$columns['C.sa_siege'] = 'wins';
		$columns['C.sa_niederlagen'] = 'defeats';
		$columns['C.sa_unentschieden'] = 'draws';
		$columns['C.sa_spiele'] = 'matches';
		
		$columns['C.bild'] = 'picture';
		
		$columns['U.id'] = 'user_id';
		$columns['U.nick'] = 'user_name';
		$columns['U.email'] = 'user_email';
		$columns['U.picture'] = 'user_picture';
		
		$columns['PREVDAY.rank'] = 'previous_rank';
		
		// order by
		$whereCondition = 'C.liga_id = %d AND C.status = \'1\' ORDER BY score DESC, goals_diff DESC, wins DESC, draws DESC, goals DESC, name ASC';
		$parameters = $leagueId;
		
		$teams = array();
		
		// shall update league history? DO this only every 10 minutes
		$now = $websoccer->getNowAsTimestamp();
		$updateHistory = FALSE;
		if ($season && (!isset($_SESSION['leaguehist']) || $_SESSION['leaguehist'] < ($now - 600))) {
			$_SESSION['leaguehist'] = $now;
			$updateHistory = TRUE;
			
			$queryTemplate = 'REPLACE INTO ' . $websoccer->getConfig('db_prefix') . '_leaguehistory ';
			$queryTemplate .= '(team_id, season_id, user_id, matchday, rank) ';
			$queryTemplate .= 'VALUES (%d, ' . $season['id'] . ', %s, %d, %d);';
		}
		
		$result = $db->querySelect($columns, $fromTable, $whereCondition, $parameters);
		$rank = 0;
		while ($team = $result->fetch_array()) {
			$rank++;
			$team['user_picture'] = UsersDataService::getUserProfilePicture($websoccer, $team['user_picture'], $team['user_email'], 20);
			$teams[] = $team;
			
			// update history
			if ($updateHistory && $team['matches']) {
				$userId = ($team['user_id']) ? $team['user_id'] : 'DEFAULT';
				$query = sprintf($queryTemplate, $team['id'], $userId, $team['matches'], $rank);
				$db->executeQuery($query);
			}
		}
		$result->free();
		
		return $teams;
	}
	
	/**
	 * 
	 * @param WebSoccer $websoccer Application Context
	 * @param DbConnection $db DB connection
	 * @param int $seasonId season ID
	 * @param string $type table type: null|home|guest
	 * @return array array of teams, ordered by table criteria.
	 */
	public static function getTeamsOfSeasonOrderedByTableCriteria(WebSoccer $websoccer, DbConnection $db, $seasonId, $type) {
		$fromTable = $websoccer->getConfig('db_prefix') . '_team_league_statistics AS S';
		$fromTable .= ' INNER JOIN ' . $websoccer->getConfig('db_prefix') . '_verein AS C ON C.id = S.team_id';
		$fromTable .= ' LEFT JOIN ' . $websoccer->getConfig('db_prefix') . '_user AS U ON C.user_id = U.id';
		
		$whereCondition = 'S.season_id = %d';
		$parameters = $seasonId;
		
		$columns['C.id'] = 'id';
		$columns['C.name'] = 'name';
		$columns['C.bild'] = 'picture';
		
		$fieldPrefix = 'total';
		if ($type == 'home') {
			$fieldPrefix = 'home';
		} else if ($type == 'guest') {
			$fieldPrefix = 'guest';
		}
		
		$columns['S.' . $fieldPrefix . '_points'] = 'score';
		$columns['S.' . $fieldPrefix . '_goals'] = 'goals';
		$columns['S.' . $fieldPrefix . '_goalsreceived'] = 'goals_received';
		$columns['S.' . $fieldPrefix . '_goalsdiff'] = 'goals_diff';
		$columns['S.' . $fieldPrefix . '_wins'] = 'wins';
		$columns['S.' . $fieldPrefix . '_draws'] = 'draws';
		$columns['S.' . $fieldPrefix . '_losses'] = 'defeats';
		$columns['(S.' . $fieldPrefix . '_wins + S.' . $fieldPrefix . '_draws + S.' . $fieldPrefix . '_losses)'] = 'matches';
		
		$columns['U.id'] = 'user_id';
		$columns['U.nick'] = 'user_name';
		$columns['U.email'] = 'user_email';
		$columns['U.picture'] = 'user_picture';
		
		$teams = array();
		
		$whereCondition .= ' ORDER BY score DESC, goals_diff DESC, wins DESC, draws DESC, goals DESC, name ASC';
		
		$result = $db->querySelect($columns, $fromTable, $whereCondition, $parameters);
		while ($team = $result->fetch_array()) {
			$team['user_picture'] = UsersDataService::getUserProfilePicture($websoccer, $team['user_picture'], $team['user_email'], 20);
			$teams[] = $team;
		}
		$result->free();
		
		return $teams;
	}
	
	/**
	 * 
	 * @param WebSoccer $websoccer Application Context
	 * @param DbConnection $db DB connection
	 * @param int $leagueId league id
	 * @param string $type table type: null|home|guest
	 * @return array array of teams, ordered by table criteria.
	 */
	public static function getTeamsOfLeagueOrderedByAlltimeTableCriteria(WebSoccer $websoccer, DbConnection $db, $leagueId, $type = null) {
		$fromTable = $websoccer->getConfig('db_prefix') . '_team_league_statistics AS S';
		$fromTable .= ' INNER JOIN ' . $websoccer->getConfig('db_prefix') . '_verein AS C ON C.id = S.team_id';
		$fromTable .= ' INNER JOIN ' . $websoccer->getConfig('db_prefix') . '_saison AS SEASON ON SEASON.id = S.season_id';
		$fromTable .= ' LEFT JOIN ' . $websoccer->getConfig('db_prefix') . '_user AS U ON C.user_id = U.id';
	
		$whereCondition = 'SEASON.liga_id = %d';
		$parameters = $leagueId;
	
		$columns['C.id'] = 'id';
		$columns['C.name'] = 'name';
		$columns['C.bild'] = 'picture';
	
		$fieldPrefix = 'total';
		if ($type == 'home') {
			$fieldPrefix = 'home';
		} else if ($type == 'guest') {
			$fieldPrefix = 'guest';
		}
	
		$columns['SUM(S.' . $fieldPrefix . '_points)'] = 'score';
		$columns['SUM(S.' . $fieldPrefix . '_goals)'] = 'goals';
		$columns['SUM(S.' . $fieldPrefix . '_goalsreceived)'] = 'goals_received';
		$columns['SUM(S.' . $fieldPrefix . '_goalsdiff)'] = 'goals_diff';
		$columns['SUM(S.' . $fieldPrefix . '_wins)'] = 'wins';
		$columns['SUM(S.' . $fieldPrefix . '_draws)'] = 'draws';
		$columns['SUM(S.' . $fieldPrefix . '_losses)'] = 'defeats';
		$columns['SUM((S.' . $fieldPrefix . '_wins + S.' . $fieldPrefix . '_draws + S.' . $fieldPrefix . '_losses))'] = 'matches';
	
		$columns['U.id'] = 'user_id';
		$columns['U.nick'] = 'user_name';
		$columns['U.email'] = 'user_email';
		$columns['U.picture'] = 'user_picture';
		
		$teams = array();
	
		$whereCondition .= ' GROUP BY C.id ORDER BY score DESC, goals_diff DESC, wins DESC, draws DESC, goals DESC, name ASC';
	
		$result = $db->querySelect($columns, $fromTable, $whereCondition, $parameters);
		while ($team = $result->fetch_array()) {
			$team['user_picture'] = UsersDataService::getUserProfilePicture($websoccer, $team['user_picture'], $team['user_email'], 20);
			$teams[] = $team;
		}
		$result->free();
	
		return $teams;
	}
	
	/**
	 * 
	 * @param WebSoccer $websoccer Application Context
	 * @param DbConnection $db DB connection
	 * @param int $teamId ID of team of which the table rank shall be determined.
	 * @return number team's current table position (rank). 0 if no matches have been played yet.
	 */
	public static function getTableRankOfTeam(WebSoccer $websoccer, DbConnection $db, $teamId) {
		$subQuery = '(SELECT COUNT(*) FROM ' . $websoccer->getConfig('db_prefix') . '_verein AS T2 WHERE'
                . ' T2.liga_id = T1.liga_id'
				. ' AND (T2.sa_punkte > T1.sa_punkte'
				. ' OR T2.sa_punkte = T1.sa_punkte AND (T2.sa_tore - T2.sa_gegentore) > (T1.sa_tore - T1.sa_gegentore)'
				. ' OR T2.sa_punkte = T1.sa_punkte AND (T2.sa_tore - T2.sa_gegentore) = (T1.sa_tore - T1.sa_gegentore) AND T2.sa_siege > T1.sa_siege'
				. ' OR T2.sa_punkte = T1.sa_punkte AND (T2.sa_tore - T2.sa_gegentore) = (T1.sa_tore - T1.sa_gegentore) AND T2.sa_siege = T1.sa_siege AND T2.sa_tore > T1.sa_tore))';
	
		$columns = $subQuery . ' + 1 AS RNK';
		$fromTable = $websoccer->getConfig('db_prefix') . '_verein AS T1';
		$whereCondition = 'T1.id = %d';
		
		$result = $db->querySelect($columns, $fromTable, $whereCondition, $teamId);
		$teamRank = $result->fetch_array();
		$result->free();
		
		if ($teamRank) {
			return (int) $teamRank['RNK'];
		}
		
		return 0;
	}
	
	/**
	 * 
	 * @param WebSoccer $websoccer Application Context
	 * @param DbConnection $db DB connection
	 * @return array array of teams which do not have a manager or only an interims manager assigned.
	 */
	public static function getTeamsWithoutUser(WebSoccer $websoccer, DbConnection $db, $userId) {
	    
	    // GET USER DATA TO DEFINE HIGHSCORE
	    $user = UsersDataService::getUserById($websoccer, $db, $userId);
	    $userHighScore = $user['highscore']+20;
	    /*
	     * 
	    $fromTable = $websoccer->getConfig('db_prefix') . '_verein AS C, ';
		$fromTable .= ' INNER JOIN ' . $websoccer->getConfig('db_prefix') . '_liga AS L ON C.liga_id = L.id';
		$fromTable .= ' LEFT JOIN ' . $websoccer->getConfig('db_prefix') . '_stadion AS S ON C.stadion_id = S.id';
		
		$whereCondition = 'C.liga_id=L.id AND C.stadion_id=S.id AND nationalteam != \'1\' AND (C.user_id <= 0 OR C.user_id IS NULL OR C.interimmanager = \'1\') AND C.status = 1';
		
		$columns['C.id'] = 'team_id';
		$columns['C.name'] = 'team_name';
		$columns['C.finanz_budget'] = 'team_budget';
		$columns['C.bild'] = 'team_picture';
		$columns['C.strength'] = 'team_strength';
		$columns['L.id'] = 'league_id';
		$columns['L.name'] = 'league_name';
		$columns['L.land'] = 'league_country';
		$columns['L.division'] = 'league_division';
		$columns['S.p_steh'] = 'stadium_p_steh';
		$columns['S.p_sitz'] = 'stadium_p_sitz';
		$columns['S.p_haupt_steh'] = 'stadium_p_haupt_steh';
		$columns['S.p_haupt_sitz'] = 'stadium_p_haupt_sitz';
		$columns['S.p_vip'] = 'stadium_p_vip';
		
		// order by
		$whereCondition .= ' ORDER BY league_country ASC, league_division ASC, league_name ASC, team_name ASC';
		
	     $teams = array();
	     
	     $result = $db->querySelect($columns, $fromTable, $whereCondition, array(), 100000);
	     while ($team = $result->fetch_array()) {
	     $teams[$team['league_country']][] = $team;
	     }
	     $result->free();
	     */
	    
	    $sqlStr = "SELECT C.id AS team_id,
                    	    C.name AS team_name,
                    	    C.finanz_budget AS team_budget,
                    	    C.bild AS team_picture,
                    	    C.strength AS team_strength,
                    	    L.id AS league_id,
                    	    L.name AS league_name,
                    	    L.land AS league_country,
                    	    L.division AS league_division,
                    	    S.p_steh AS stadium_p_steh,
                    	    S.p_sitz AS stadium_p_sitz,
                    	    S.p_haupt_steh AS stadium_p_haupt_steh,
                    	    S.p_haupt_sitz AS stadium_p_haupt_sitz,
                    	    S.p_vip AS stadium_p_vip
        	       FROM ". $websoccer->getConfig('db_prefix') . "_verein AS C,
                            ". $websoccer->getConfig('db_prefix') . "_liga AS L,
                            ". $websoccer->getConfig('db_prefix') . "_stadion AS S
        	       WHERE L.id=C.liga_id AND S.id=C.stadion_id AND C.highscore<=$userHighScore
                    ORDER BY RAND() LIMIT 25";
        //ORDER BY L.land ASC, L.division ASC, C.name LIMIT 75";
	    $result = $db->executeQuery($sqlStr);
	    
	    $teams = array();
	    
	    while ($team = $result->fetch_array()) {
	        $teams[$team['league_country']][] = $team;
	    }
	    $result->free();
	    		
		return $teams;
	}
	
	/**
	 * Provide total number of teams which do not have a manager at the moment.
	 *
	 * @param WebSoccer $websoccer Application context.
	 * @param DbConnection $db DB connection.
	 * @return int total number of teams without manager.
	 */
	public static function countTeamsWithoutManager(WebSoccer $websoccer, DbConnection $db) {
		$result = $db->querySelect('COUNT(*) AS hits', $websoccer->getConfig('db_prefix') . '_verein',
				'(user_id = 0 OR user_id IS NULL) AND status = 1');
		$teams = $result->fetch_array();
		$result->free();
	
		if (isset($teams['hits'])) {
			return $teams['hits'];
		}
	
		return 0;
	}
	
	/**
	 * 
	 * @param WebSoccer $websoccer Application context
	 * @param DbConnection $db DB connection
	 * @param string $query complete club name or part of it.
	 * @return array list of matching club names.
	 */
	public static function findTeamNames(WebSoccer $websoccer, DbConnection $db, $query) {
		$columns = 'name';
		$fromTable = $websoccer->getConfig('db_prefix') . '_verein';
		$whereCondition = 'UPPER(name) LIKE \'%s%%\' AND status = 1';
		
		$result = $db->querySelect($columns, $fromTable, $whereCondition, strtoupper($query), 10);
		
		$teams = array();
		while($team = $result->fetch_array()) {
			$teams[] = $team['name'];
		}
		$result->free();
		
		return $teams;		
	}
	
	/**
	 * Provides number of players in team of specified ID. Only counts players who are not on transfer market and not marked as borrowable.
	 * 
	 * @param WebSoccer $websoccer application context.
	 * @param DbConnection $db DB connection.
	 * @param int $clubId club ID.
	 * @return int number of players playing for specified team.
	 */
	public static function getTeamSize(WebSoccer $websoccer, DbConnection $db, $clubId) {
		$columns = 'COUNT(*) AS number';
	
		$fromTable = $websoccer->getConfig('db_prefix') .'_spieler';
		$whereCondition = 'verein_id = %d AND status = \'1\' AND transfermarkt != \'1\' AND lending_fee = 0';
		$parameters = $clubId;
	
		$result = $db->querySelect($columns, $fromTable, $whereCondition, $parameters);
		$players = $result->fetch_array();
		$result->free();
	
		return ($players['number']) ? $players['number'] : 0;
	}
	
	/**
	 * Provides the sum of all wages of players who belong the the specified team.
	 * 
	 * @param WebSoccer $websoccer Application context.
	 * @param DbConnection $db DB connection.
	 * @param int $clubId ID of team
	 * @return int sum of all wages. 0 if team has no players or team ID is invalid.
	 */
	public static function getTotalPlayersSalariesOfTeam(WebSoccer $websoccer, DbConnection $db, $clubId) {
		$columns = 'SUM(vertrag_gehalt) AS salary';
	
		$fromTable = $websoccer->getConfig('db_prefix') .'_spieler';
		$whereCondition = 'verein_id = %d AND status = \'1\'';
		$parameters = $clubId;
	
		$result = $db->querySelect($columns, $fromTable, $whereCondition, $parameters);
		$players = $result->fetch_array();
		$result->free();
	
		return ($players['salary']) ? $players['salary'] : 0;
	}
	
	/**
	 * Provides ID of selected team captain of specified team.
	 * NOTE: Does not check whether player is still member of the team.
	 * 
	 * @param WebSoccer $websoccer Application context.
	 * @param DbConnection $db DB Connection.
	 * @param int $clubId team id.
	 * @return int ID of team captain. 0 if no captain available.
	 */
	public static function getTeamCaptainIdOfTeam(WebSoccer $websoccer, DbConnection $db, $clubId) {
		$result = $db->querySelect('captain_id', $websoccer->getConfig('db_prefix') .'_verein', 'id = %d', $clubId);
		$team = $result->fetch_array();
		$result->free();
		
		return (isset($team['captain_id'])) ? $team['captain_id'] : 0;
	}
	
	/**
	 * Check if team has enough budget to pay the secified salary.
	 * 
	 * @param WebSoccer $websoccer Application context.
	 * @param DbConnection $db DB Connection.
	 * @param I18n $i18n I18n.
	 * @param int $clubId team id.
	 * @param int $salary Salary which the user whishes to offer.
	 * @throws Exception if budget is not enough. Exception contains translated message.
	 */
	public static function validateWhetherTeamHasEnoughBudgetForSalaryBid(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $clubId, $salary) {
		
		// get salary sum of all players
		$result = $db->querySelect('SUM(vertrag_gehalt) AS salary_sum', $websoccer->getConfig('db_prefix') .'_spieler', 'verein_id = %d', $clubId);
		$players = $result->fetch_array();
		$result->free();
		
		// check if team can afford at least X matches
		$minBudget = ($players['salary_sum'] + $salary) * 2;
		$team = self::getTeamSummaryById($websoccer, $db, $clubId);
		if ($team['team_budget'] < $minBudget) {
			throw new Exception($i18n->getMessage("extend-contract_cannot_afford_offer"));
		}
	}
	
	private static function _getFromPart(WebSoccer $websoccer) {
		$tablePrefix = $websoccer->getConfig('db_prefix');
		
		// from
		$fromTable = $tablePrefix . '_verein AS C';
		$fromTable .= ' LEFT JOIN ' . $tablePrefix . '_liga AS L ON C.liga_id = L.id';
		$fromTable .= ' LEFT JOIN ' . $tablePrefix . '_sponsor AS SPON ON C.sponsor_id = SPON.id';
		$fromTable .= ' LEFT JOIN ' . $tablePrefix . '_user AS U ON C.user_id = U.id';
		$fromTable .= ' LEFT JOIN ' . $tablePrefix . '_user AS DEPUTY ON C.user_id_actual = DEPUTY.id';
		return $fromTable;
	}
	
	/**
	 * Provides data about team with specified userID.
	 *
	 * @param WebSoccer $websoccer Application Context
	 * @param DbConnection $db DB connection
	 * @param int $teamId ID of requested team
	 * @return array team information as an assoc. array.
	 */
	public static function getTeamByUserId(WebSoccer $websoccer, DbConnection $db, $userId) {
	    $fromTable = self::_getFromPart($websoccer);
	    
	    // where
	    $whereCondition = 'C.user_id = %d AND C.status = 1';
	    $parameters = $userId;
	    
	    // select
	    $columns['C.id'] = 'team_id';
	    $columns['C.bild'] = 'team_logo';
	    $columns['C.name'] = 'team_name';
	    $columns['C.kurz'] = 'team_short';
	    $columns['C.strength'] = 'team_strength';
	    $columns['C.finanz_budget'] = 'team_budget';
	    $columns['C.min_target_rank'] = 'team_min_target_rank';
	    $columns['C.nationalteam'] = 'is_nationalteam';
	    $columns['C.captain_id'] = 'captain_id';
	    $columns['C.interimmanager'] = 'interimmanager';
	    
	    $columns['C.history'] = 'team_history';
	    
	    $columns['L.name'] = 'team_league_name';
	    $columns['L.id'] = 'team_league_id';
	    $columns['SPON.name'] = 'team_sponsor_name';
	    $columns['SPON.bild'] = 'team_sponsor_picture';
	    $columns['SPON.id'] = 'team_sponsor_id';
	    $columns['U.nick'] = 'team_user_name';
	    $columns['U.id'] = 'team_user_id';
	    $columns['U.email'] = 'team_user_email';
	    $columns['U.picture'] = 'team_user_picture';
	    $columns['DEPUTY.nick'] = 'team_deputyuser_name';
	    $columns['DEPUTY.id'] = 'team_deputyuser_id';
	    $columns['DEPUTY.email'] = 'team_deputyuser_email';
	    $columns['DEPUTY.picture'] = 'team_deputyuser_picture';
	    
	    // statistic
	    $columns['C.sa_tore'] = 'team_season_goals';
	    $columns['C.sa_gegentore'] = 'team_season_againsts';
	    $columns['C.sa_spiele'] = 'team_season_matches';
	    $columns['C.sa_siege'] = 'team_season_wins';
	    $columns['C.sa_niederlagen'] = 'team_season_losses';
	    $columns['C.sa_unentschieden'] = 'team_season_draws';
	    $columns['C.sa_punkte'] = 'team_season_score';
	    
	    $columns['C.st_tore'] = 'team_total_goals';
	    $columns['C.st_gegentore'] = 'team_total_againsts';
	    $columns['C.st_spiele'] = 'team_total_matches';
	    $columns['C.st_siege'] = 'team_total_wins';
	    $columns['C.st_niederlagen'] = 'team_total_losses';
	    $columns['C.st_unentschieden'] = 'team_total_draws';
	    $columns['C.st_punkte'] = 'team_total_score';
	    
	    $teaminfos = $db->queryCachedSelect($columns, $fromTable, $whereCondition, $parameters, 1);
	    $team = (isset($teaminfos[0])) ? $teaminfos[0] : array();
	    
	    if (isset($team['team_user_email'])) {
	        $team['user_picture'] = UsersDataService::getUserProfilePicture($websoccer, $team['team_user_picture'], $team['team_user_email'], 20);
	    }
	    
	    if (isset($team['team_deputyuser_email'])) {
	        $team['deputyuser_picture'] = UsersDataService::getUserProfilePicture($websoccer, $team['team_deputyuser_picture'], $team['team_deputyuser_email'], 20);
	    }
	    
	    return $team;
	}
	
	public static function getWealthiestTeams(WebSoccer $websoccer, DbConnection $db, $limit) {
	    
	    $wealthiestTeams = array();
	    
	    //GET wealthiest teams
	    $sqlStr = "SELECT * FROM ". $websoccer->getConfig("db_prefix") ."_verein
					ORDER BY finanz_budget DESC
					LIMIT $limit";
	    $result = $db->executeQuery($sqlStr);
	    while ($team = $result->fetch_array()) {
	        
	        $leagueData = LeagueDataService::getLeagueById($websoccer, $db, $team['liga_id']);
	        $team['league_data'] = $leagueData;
	        
	        if($team['user_id']>0) {
	            $userData = UsersDataService::getUserById($websoccer, $db, $team['user_id']);
	            $team['nick_id'] = $userData['id'];
	            $team['nick'] = $userData['nick'];
	        } else {
	            $team['nick'] = '';
	        }
	        $wealthiestTeams[] = $team;
	    }
	    
	    return $wealthiestTeams;
	    
	}
	
	public static function getTeamsByMaxPlayerNumber(WebSoccer $websoccer, DbConnection $db, $limit) {
	    
	    $verein = array();
	    $club = array();
	    
	    $Str = "SELECT COUNT(s.id) AS anzahl, v.id AS verein_id
					FROM ". $websoccer->getConfig("db_prefix") ."_spieler AS s, ". $websoccer->getConfig("db_prefix") ."_verein AS v
					WHERE s.verein_id=v.id
					GROUP BY s.verein_id
					HAVING COUNT(s.id)<'$limit'
					ORDER BY anzahl ASC
					LIMIT 1";
	    $result = $db->executeQuery($Str);
	    //$cnt_rows = $result->num_rows;
	    while ($vereine = $result->fetch_array())
	    {
	        $club[] = $vereine;
	    }
	    $result->free();
	    
	    return $club;
	}
	
	public static function updateTeamTransfermarket(WebSoccer $websoccer, DbConnection $db) {
	    
	    
	    $Str = "SELECT COUNT(s.id) AS countSp, v.id
				FROM ". $websoccer->getConfig("db_prefix") ."_spieler AS s, ". $websoccer->getConfig("db_prefix") ."_verein AS v
				WHERE s.verein_id=v.id
				AND s.transfermarkt=1
				ORDER BY v.id";
	    $result = $db->executeQuery($Str);
	    //$cnt_rows = $result->num_rows;
	    while ($verein = $result->fetch_array())
	    {
	        $updSql = "UPDATE ". $websoccer->getConfig("db_prefix") ."_verein SET transfermarkt='".$verein['countSp']."' WHERE id='".$verein['id']."'";
	        $db->executeQuery($sqlStr);
	        $club[] = $verein;
	    }
	    $result->free();
	    
	    return $club;
	    
	    
	}
	
	public static function getBankClub(WebSoccer $websoccer, DbConnection $db) {
	    
	    $Str = "SELECT id FROM ". $websoccer->getConfig("db_prefix") ."_verein
				WHERE name = 'Bank'
				LIMIT 1";
	    $result = $db->executeQuery($Str);
	    //$cnt_rows = $result->num_rows;
	    $club = $result->fetch_array();
	    $result->free();
	    
	    return $club;
	    
	}
	
	//get achievement point to put club onto stock market
	public static function getClubAchievementPoints(WebSoccer $websoccer, DbConnection $db, $clubId) {
	    
	    //GET FINALS
	    $Str = "SELECT COUNT(*) AS finals
				FROM ". $websoccer->getConfig("db_prefix") ."_achievement AS a
				LEFT JOIN ". $websoccer->getConfig("db_prefix") ."_cup_round AS cr
					ON a.cup_round_id = cr.id
				WHERE a.team_id='".$clubId."' AND cr.name='Finale'";
	    $result = $db->executeQuery($Str);
	    $finals = $result->fetch_array();
	    $result->free();
	    
	    //GET CHAMPIONSHIPS
	    $Str = "SELECT COUNT(*) AS champions
				FROM ". $websoccer->getConfig("db_prefix") ."_achievement
					WHERE rank='1' AND team_id='".$clubId."'";
	    $result = $db->executeQuery($Str);
	    $champions = $result->fetch_array();
	    $result->free();
	    
	    $achievements['achievements'] = $finals['finals']+$champions['champions'];
	    
	    return $achievements;
	    
	}
	
	//GET TOTAL TEAM VALUE
	public static function getTeamValueByTeamId(WebSoccer $websoccer, DbConnection $db, $clubId) {
	    
	    //GET FINALS
	    /*
	     $weightStrength = $this->_websoccer->getConfig('sim_weight_strength');
	     $weightTech = $this->_websoccer->getConfig('sim_weight_strengthTech');
	     $weightStamina = $this->_websoccer->getConfig('sim_weight_strengthStamina');
	     $weightFreshness = $this->_websoccer->getConfig('sim_weight_strengthFreshness');
	     $weightSatisfaction = $this->_websoccer->getConfig('sim_weight_strengthSatisfaction');
	     */
	    
	    $Str = "SELECT COUNT(id) AS spieler, SUM(w_staerke) AS strength,
						SUM(w_technik) AS technik,
						SUM(w_kondition) AS kondition,
						SUM(w_frische) AS frische,
						SUM(w_zufriedenheit) AS zufriedenheit
				FROM ". $websoccer->getConfig("db_prefix") ."_spieler
				WHERE verein_id='".$clubId."'";
	    $result = $db->executeQuery($Str);
	    $teamfacts = $result->fetch_array();
	    $result->free();
	    
	    $weightStrength = $websoccer->getConfig('sim_weight_strength');
	    $weightTech = $websoccer->getConfig('sim_weight_strengthTech');
	    $weightStamina = $websoccer->getConfig('sim_weight_strengthStamina');
	    $weightFreshness = $websoccer->getConfig('sim_weight_strengthFreshness');
	    $weightSatisfaction = $websoccer->getConfig('sim_weight_strengthSatisfaction');
	    
	    $totalStrength = $weightStrength * $teamfacts['strength'];
	    $totalStrength += $weightTech * $teamfacts['technik'];
	    $totalStrength += $weightStamina * $teamfacts['kondition'];
	    $totalStrength += $weightFreshness * $teamfacts['frische'];
	    $totalStrength += $weightSatisfaction * $teamfacts['zufriedenheit'];
	    
	    $totalStrength /= $weightStrength + $weightTech + $weightStamina + $weightFreshness + $weightSatisfaction;
	    $totalMarketvalue = $totalStrength * $websoccer->getConfig('transfermarket_value_per_strength');
	    
	    return round($totalMarketvalue,0);
	}
	
	
	//get average highscore
	public static function getTeamHighscore() {
	    
	    $sqlStr = "SELECT SUM(highscore) AS highscore, COUNT(id) AS teams FROM ". $websoccer->getConfig("db_prefix") ."_verein WHERE STATUS = '1'";
	    $result = $db->executeQuery($sqlStr);
	    $highscore = $result->fetch_array();
	    $result->free();
	    
	    $teamHighscore['teams'] = $highscore['teams'];
	    $teamHighscore['highscore'] = $highscore['highscore'];
	    $teamHighscore['avg_highscore'] = $highScore['highscore']/$highscore['teams'];
	    
	    return $teamHighscore;
	}
	
	/**
	 *
	 * @param WebSoccer $websoccer Application Context
	 * @param DbConnection $db DB connection
	 * @return array array of teams which do not have a manager or only an interims manager assigned.
	 */
	public static function getTeamsWithoutUserByhighscore(WebSoccer $websoccer, DbConnection $db, $highScore) {
	    $fromTable = $websoccer->getConfig('db_prefix') . '_verein AS C';
	    $fromTable .= ' INNER JOIN ' . $websoccer->getConfig('db_prefix') . '_liga AS L ON C.liga_id = L.id';
	    $fromTable .= ' LEFT JOIN ' . $websoccer->getConfig('db_prefix') . '_stadion AS S ON C.stadion_id = S.id';
	    
	    $whereCondition = 'nationalteam != \'1\' AND (C.user_id = 0 OR C.user_id IS NULL OR C.interimmanager = \'1\') AND C.status = 1';
	    
	    $columns['C.id'] = 'team_id';
	    $columns['C.name'] = 'team_name';
	    $columns['C.finanz_budget'] = 'team_budget';
	    $columns['C.bild'] = 'team_picture';
	    $columns['C.strength'] = 'team_strength';
	    $columns['C.highscore'] = 'team_highscore';
	    $columns['L.id'] = 'league_id';
	    $columns['L.division'] = 'league_division';
	    $columns['L.name'] = 'league_name';
	    $columns['L.land'] = 'league_country';
	    $columns['S.p_steh'] = 'stadium_p_steh';
	    $columns['S.p_sitz'] = 'stadium_p_sitz';
	    $columns['S.p_haupt_steh'] = 'stadium_p_haupt_steh';
	    $columns['S.p_haupt_sitz'] = 'stadium_p_haupt_sitz';
	    $columns['S.p_vip'] = 'stadium_p_vip';
	    
	    // order by
	    $whereCondition .= ' AND team_highscore<=\'$highScore\' ORDER BY league_country ASC, league_division ASC, league_name ASC, team_name ASC';
	    
	    $teams = array();
	    
	    $result = $db->querySelect($columns, $fromTable, $whereCondition, array(), 3000);
	    while ($team = $result->fetch_array()) {
	        $teams[$team['league_country']][] = $team;
	    }
	    $result->free();
	    
	    return $teams;
	}
	
	public static function updateTeamsHighscore(WebSoccer $websoccer, DbConnection $db) {
	    
	    $updStr1 = "UPDATE ". $websoccer->getConfig("db_prefix") ."_verein SET highscore=(st_siege*3)+(st_unentschieden*1)-(st_niederlagen*1)";
	    $db->executeQuery($updStr1);
	    
	    //correct if negative
	    $updStr2 = "UPDATE ". $websoccer->getConfig("db_prefix") ."_verein SET highscore='0' WHERE highscore<0";
	    $db->executeQuery($updStr2);
	    
	}
	
	public static function getTeamsAvgHighscore(WebSoccer $websoccer, DbConnection $db) {
	    
	    $sqlStr = "SELECT SUM(highscore) AS highscore, COUNT(id) AS teams FROM ". $websoccer->getConfig("db_prefix") ."_verein WHERE STATUS = '1'";
	    $result = $db->executeQuery($sqlStr);
	    $highscore = $result->fetch_array();
	    $result->free();
	    
	    $teamHighscore['teams'] = $highscore['teams'];
	    $teamHighscore['highscore'] = $highscore['highscore'];
	    $teamHighscore['avg_highscore'] = $highScore['highscore']/$highscore['teams'];
	    
	    return $teamHighscore;
	    
	}
	
	public static function getTeamsByHighscore(WebSoccer $websoccer, DbConnection $db, $highscore) {
	    
	    $teams = array();
	    $i = 0;
	    
	    if($highscore<0) {
	        $highscore = '0';
	    }
	    
	    $sqlStr = "SELECT C.id AS team_id, C.name AS team_name, C.finanz_budget AS team_budget, C.bild AS team_picture, C.strength AS team_strength, C.highscore AS team_highscore,
                    L.id AS league_id, L.division AS league_division, L.name AS league_name, L.land AS league_country,
                    S.p_steh AS stadium_p_steh, S.p_sitz AS stadium_p_sitz, S.p_haupt_steh AS stadium_p_haupt_steh, S.p_haupt_sitz AS stadium_p_haupt_sitz, S.p_vip AS stadium_p_vip
                    FROM ". $websoccer->getConfig("db_prefix") ."_verein AS C, ". $websoccer->getConfig("db_prefix") ."_liga AS L, ". $websoccer->getConfig("db_prefix") ."_stadion AS S
                    WHERE C.liga_id = L.id AND C.stadion_id=S.id
                        AND C.highscore<='$highscore'
                        AND nationalteam!='1'
                        AND (C.user_id <= 0 OR C.user_id IS NULL OR C.interimmanager = '1')
                        AND C.status = '1'
                    ORDER BY league_country ASC, league_division ASC, league_name ASC, team_name ASC LIMIT 1000";
	    //echo $sqlStr ."<br>";
	    $result = $db->executeQuery($sqlStr);
	    while ($team = $result->fetch_array()) {
	        $teams[$team['league_country']][] = $team;
	    }
	    $result->free();
	    
	    if(count($teams)<1) {
	        self::getTeamsByHighscore($websoccer, $db, $highscore=$highscore+1);
	        $i++;
	    } else {
	        return $teams;
	    }
	}
	
	public static function getAvgTeamSkills(WebSoccer $websoccer, DbConnection $db, $verein_id) {
	    
	    $sqlStr = "SELECT ROUND(AVG(w_staerke),0) AS avg_strength, ROUND(AVG(w_technik),0) AS avg_technik,
                          ROUND(AVG(w_kondition),0) AS avg_stamina, ROUND(AVG(w_frische),0) AS avg_freshness,
                          ROUND(AVG(w_zufriedenheit),0) AS avg_satisfaction
                       FROM ". $websoccer->getConfig("db_prefix") ."_spieler
            	       WHERE verein_id='".$verein_id."'
            	       AND status='1'";
	    $result = $db->executeQuery($sqlStr);
	    $value = $result->fetch_array();
	    $result->free();
	    
	    return $value;
	}
	
	/*
	 * Return all titles won specified by TeamId
	 * @param WebSoccer $websoccer Application Context
	 * @param DbConnection $db DB connection
	 * @return array of titles won by team
	 */
	public static function getNumberTeamTitlesWon(WebSoccer $websoccer, DbConnection $db, $teamId) {
	    
	    $titles = array();
	    
	    $sqlStr = "SELECT * FROM ". $websoccer->getConfig("db_prefix") ."_titles_won WHERE team_id='".$teamId."'
                    ORDER BY saison_name DESC";
	    //echo $sqlStr ."<br>";
	    $result = $db->executeQuery($sqlStr);
	    while ($title = $result->fetch_array()) {
	        $titles[] = $title;
	    }
	    $result->free();
	    
	    return $titles;
	    
	}
	
}
?>