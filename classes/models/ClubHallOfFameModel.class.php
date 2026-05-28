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
 * Club history and hall of fame.
 *
 * Uses existing match, player, transfer, title and league-history data only.
 */
class ClubHallOfFameModel implements IModel {
	private $_db;
	private $_i18n;
	private $_websoccer;
	private $_teamId;
	private $_tablePrefix;
	
	public function __construct($db, $i18n, $websoccer) {
		$this->_db = $db;
		$this->_i18n = $i18n;
		$this->_websoccer = $websoccer;
		$this->_tablePrefix = $websoccer->getConfig('db_prefix');
	}
	
	public function renderView() {
		$this->_teamId = (int) $this->_websoccer->getRequestParameter('teamid');
		return $this->_teamId > 0;
	}
	
	public function getTemplateParameters() {
		$bestPlayers = $this->getBestPlayers(20, 10);
		$bestPlayersMinMatches = 20;
		
		// Do not show an empty list for new clubs. Fall back to players with at least one official match.
		if (!count($bestPlayers)) {
			$bestPlayers = $this->getBestPlayers(1, 10);
			$bestPlayersMinMatches = 1;
		}
		
		$topScorers = $this->getPlayerRanking('goals', 10);
		$topAssists = $this->getPlayerRanking('assists', 10);
		$mostAppearances = $this->getPlayerRanking('matches', 10);
		$highestWin = $this->getRecordMatch(TRUE);
		$worstDefeat = $this->getRecordMatch(FALSE);
		$recordIncomingTransfer = $this->getRecordTransfer(TRUE);
		$recordOutgoingTransfer = $this->getRecordTransfer(FALSE);
		$titles = $this->getTitles();
		
		return array(
			'team_id' => $this->_teamId,
			'summary' => $this->getMatchSummary(),
			'titles' => $titles,
			'titles_count' => count($titles),
			'best_players' => $bestPlayers,
			'best_players_min_matches' => $bestPlayersMinMatches,
			'top_scorers' => $topScorers,
			'top_assists' => $topAssists,
			'most_appearances' => $mostAppearances,
			'highest_win' => $highestWin,
			'worst_defeat' => $worstDefeat,
			'record_incoming_transfer' => $recordIncomingTransfer,
			'record_outgoing_transfer' => $recordOutgoingTransfer,
			'league_positions' => $this->getLeaguePositions()
		);
	}
	
	private function getTitles() {
		$titles = array();
		$knownTitles = array();
		
		// Dedicated title archive, if filled by season jobs or admin scripts.
		$result = $this->_db->querySelect('id, competition, saison_name', $this->_tablePrefix . '_titles_won', 'team_id = %d ORDER BY id DESC', $this->_teamId);
		while ($title = $result->fetch_array()) {
			$key = $this->titleKey($title['competition'], $title['saison_name']);
			$knownTitles[$key] = TRUE;
			$title['source'] = 'titles_won';
			$titles[] = $title;
		}
		$result->free();
		
		// League championships from completed seasons. This also works when titles_won is not populated.
		$columns = array(
			'S.name' => 'saison_name',
			'L.name' => 'competition'
		);
		$fromTable = $this->_tablePrefix . '_saison AS S INNER JOIN ' . $this->_tablePrefix . '_liga AS L ON L.id = S.liga_id';
		$result = $this->_db->querySelect($columns, $fromTable, 'S.beendet = \'1\' AND S.platz_1_id = %d ORDER BY S.id DESC', $this->_teamId);
		while ($title = $result->fetch_array()) {
			$title['competition'] = $this->_i18n->getMessage('club_hof_title_league_prefix') . ' ' . $title['competition'];
			$key = $this->titleKey($title['competition'], $title['saison_name']);
			if (!isset($knownTitles[$key])) {
				$knownTitles[$key] = TRUE;
				$title['source'] = 'season';
				$titles[] = $title;
			}
		}
		$result->free();
		
		// Current/archived cup winners as fallback source.
		$result = $this->_db->querySelect('name AS competition', $this->_tablePrefix . '_cup', 'winner_id = %d ORDER BY name ASC', $this->_teamId);
		while ($title = $result->fetch_array()) {
			$title['competition'] = $this->_i18n->getMessage('club_hof_title_cup_prefix') . ' ' . $title['competition'];
			$title['saison_name'] = '';
			$key = $this->titleKey($title['competition'], $title['saison_name']);
			if (!isset($knownTitles[$key])) {
				$knownTitles[$key] = TRUE;
				$title['source'] = 'cup';
				$titles[] = $title;
			}
		}
		$result->free();
		
		return $titles;
	}
	
	private function getMatchSummary() {
		$teamId = (int) $this->_teamId;
		$query = 'SELECT '
			. 'COUNT(*) AS matches, '
			. 'SUM(CASE WHEN (M.home_verein = ' . $teamId . ' AND M.home_tore > M.gast_tore) OR (M.gast_verein = ' . $teamId . ' AND M.gast_tore > M.home_tore) THEN 1 ELSE 0 END) AS wins, '
			. 'SUM(CASE WHEN M.home_tore = M.gast_tore THEN 1 ELSE 0 END) AS draws, '
			. 'SUM(CASE WHEN (M.home_verein = ' . $teamId . ' AND M.home_tore < M.gast_tore) OR (M.gast_verein = ' . $teamId . ' AND M.gast_tore < M.home_tore) THEN 1 ELSE 0 END) AS losses, '
			. 'SUM(CASE WHEN M.home_verein = ' . $teamId . ' THEN M.home_tore ELSE M.gast_tore END) AS goals_for, '
			. 'SUM(CASE WHEN M.home_verein = ' . $teamId . ' THEN M.gast_tore ELSE M.home_tore END) AS goals_against '
			. 'FROM ' . $this->_tablePrefix . '_spiel AS M '
			. 'WHERE ' . $this->officialMatchWhere($teamId);
		
		$result = $this->_db->executeQuery($query);
		$summary = $result->fetch_array();
		$result->free();
		
		if (!isset($summary['matches'])) {
			$summary = array();
		}
		
		foreach (array('matches', 'wins', 'draws', 'losses', 'goals_for', 'goals_against') as $key) {
			if (!isset($summary[$key])) {
				$summary[$key] = 0;
			}
		}
		
		return $summary;
	}
	
	private function getBestPlayers($minMatches, $limit) {
		$minMatches = (int) $minMatches;
		$limit = (int) $limit;
		
		$query = 'SELECT '
			. 'SB.spieler_id AS player_id, '
			. $this->playerNameExpression() . ' AS player_name, '
			. 'COUNT(DISTINCT SB.spiel_id) AS matches, '
			. 'SUM(SB.tore) AS goals, '
			. 'SUM(SB.assists) AS assists, '
			. 'AVG(SB.note) AS avg_grade '
			. 'FROM ' . $this->_tablePrefix . '_spiel_berechnung AS SB '
			. 'INNER JOIN ' . $this->_tablePrefix . '_spiel AS M ON M.id = SB.spiel_id '
			. 'LEFT JOIN ' . $this->_tablePrefix . '_spieler AS P ON P.id = SB.spieler_id '
			. 'WHERE SB.team_id = ' . (int) $this->_teamId . ' '
			. 'AND SB.minuten_gespielt > 0 '
			. 'AND SB.note > 0 '
			. 'AND ' . $this->officialMatchWhere((int) $this->_teamId, 'M') . ' '
			. 'GROUP BY SB.spieler_id '
			. 'HAVING matches >= ' . $minMatches . ' '
			. 'ORDER BY avg_grade ASC, matches DESC, goals DESC, assists DESC '
			. 'LIMIT ' . $limit;
		
		return $this->fetchRows($query);
	}
	
	private function getPlayerRanking($ranking, $limit) {
		$limit = (int) $limit;
		
		switch ($ranking) {
			case 'goals':
				$orderColumn = 'goals';
				break;
			case 'assists':
				$orderColumn = 'assists';
				break;
			case 'matches':
			default:
				$orderColumn = 'matches';
				break;
		}
		
		$query = 'SELECT '
			. 'SB.spieler_id AS player_id, '
			. $this->playerNameExpression() . ' AS player_name, '
			. 'COUNT(DISTINCT SB.spiel_id) AS matches, '
			. 'SUM(SB.tore) AS goals, '
			. 'SUM(SB.assists) AS assists, '
			. 'SUM(SB.minuten_gespielt) AS minutes_played '
			. 'FROM ' . $this->_tablePrefix . '_spiel_berechnung AS SB '
			. 'INNER JOIN ' . $this->_tablePrefix . '_spiel AS M ON M.id = SB.spiel_id '
			. 'LEFT JOIN ' . $this->_tablePrefix . '_spieler AS P ON P.id = SB.spieler_id '
			. 'WHERE SB.team_id = ' . (int) $this->_teamId . ' '
			. 'AND SB.minuten_gespielt > 0 '
			. 'AND ' . $this->officialMatchWhere((int) $this->_teamId, 'M') . ' '
			. 'GROUP BY SB.spieler_id '
			. 'HAVING ' . $orderColumn . ' > 0 '
			. 'ORDER BY ' . $orderColumn . ' DESC, matches DESC, goals DESC, assists DESC '
			. 'LIMIT ' . $limit;
		
		return $this->fetchRows($query);
	}
	
	private function getRecordMatch($highestWin) {
		$teamId = (int) $this->_teamId;
		
		if ($highestWin) {
			$differenceExpression = '(CASE WHEN M.home_verein = ' . $teamId . ' THEN M.home_tore - M.gast_tore ELSE M.gast_tore - M.home_tore END)';
		} else {
			$differenceExpression = '(CASE WHEN M.home_verein = ' . $teamId . ' THEN M.gast_tore - M.home_tore ELSE M.home_tore - M.gast_tore END)';
		}
		
		$query = 'SELECT '
			. 'M.id AS match_id, '
			. 'M.datum AS match_date, '
			. 'M.spieltyp AS match_type, '
			. 'M.pokalname AS cup_name, '
			. 'SEAS.name AS season_name, '
			. 'L.name AS league_name, '
			. 'HOME.name AS home_name, '
			. 'GUEST.name AS guest_name, '
			. 'M.home_tore AS home_goals, '
			. 'M.gast_tore AS guest_goals, '
			. 'CASE WHEN M.home_verein = ' . $teamId . ' THEN GUEST.name ELSE HOME.name END AS opponent_name, '
			. 'CASE WHEN M.home_verein = ' . $teamId . ' THEN 1 ELSE 0 END AS is_home, '
			. 'CASE WHEN M.home_verein = ' . $teamId . ' THEN M.home_tore ELSE M.gast_tore END AS goals_for, '
			. 'CASE WHEN M.home_verein = ' . $teamId . ' THEN M.gast_tore ELSE M.home_tore END AS goals_against, '
			. $differenceExpression . ' AS goal_difference '
			. 'FROM ' . $this->_tablePrefix . '_spiel AS M '
			. 'INNER JOIN ' . $this->_tablePrefix . '_verein AS HOME ON HOME.id = M.home_verein '
			. 'INNER JOIN ' . $this->_tablePrefix . '_verein AS GUEST ON GUEST.id = M.gast_verein '
			. 'LEFT JOIN ' . $this->_tablePrefix . '_saison AS SEAS ON SEAS.id = M.saison_id '
			. 'LEFT JOIN ' . $this->_tablePrefix . '_liga AS L ON L.id = SEAS.liga_id '
			. 'WHERE ' . $this->officialMatchWhere($teamId, 'M') . ' '
			. 'AND ' . $differenceExpression . ' > 0 '
			. 'ORDER BY goal_difference DESC, goals_for DESC, M.datum DESC '
			. 'LIMIT 1';
		
		$rows = $this->fetchRows($query);
		return count($rows) ? $rows[0] : NULL;
	}
	
	private function getRecordTransfer($incoming) {
		$teamId = (int) $this->_teamId;
		$condition = $incoming ? 'T.buyer_club_id = ' . $teamId : 'T.seller_club_id = ' . $teamId;
		
		$query = 'SELECT '
			. 'T.id AS transfer_id, '
			. 'T.datum AS transfer_date, '
			. 'IFNULL(T.directtransfer_amount, 0) AS amount, '
			. 'P.id AS player_id, '
			. 'COALESCE(NULLIF(P.kunstname, \'\'), NULLIF(TRIM(CONCAT(IFNULL(P.vorname, \'\'), \' \', IFNULL(P.nachname, \'\'))), \'\'), CONCAT(\'Spieler #\', P.id)) AS player_name, '
			. 'SELLER.id AS seller_id, '
			. 'SELLER.name AS seller_name, '
			. 'BUYER.id AS buyer_id, '
			. 'BUYER.name AS buyer_name '
			. 'FROM ' . $this->_tablePrefix . '_transfer AS T '
			. 'INNER JOIN ' . $this->_tablePrefix . '_spieler AS P ON P.id = T.spieler_id '
			. 'LEFT JOIN ' . $this->_tablePrefix . '_verein AS SELLER ON SELLER.id = T.seller_club_id '
			. 'LEFT JOIN ' . $this->_tablePrefix . '_verein AS BUYER ON BUYER.id = T.buyer_club_id '
			. 'WHERE ' . $condition . ' '
			. 'AND IFNULL(T.directtransfer_amount, 0) > 0 '
			. 'ORDER BY amount DESC, T.datum DESC '
			. 'LIMIT 1';
		
		$rows = $this->fetchRows($query);
		return count($rows) ? $rows[0] : NULL;
	}
	
	private function getLeaguePositions() {
		$teamId = (int) $this->_teamId;
		
		$query = 'SELECT '
			. 'H.season_id AS season_id, '
			. 'SEAS.name AS season_name, '
			. 'L.name AS league_name, '
			. 'H.matchday AS matchday, '
			. 'H.rank AS rank, '
			. 'U.id AS user_id, '
			. 'U.nick AS user_name '
			. 'FROM ' . $this->_tablePrefix . '_leaguehistory AS H '
			. 'INNER JOIN ( '
			. 'SELECT season_id, MAX(matchday) AS max_matchday '
			. 'FROM ' . $this->_tablePrefix . '_leaguehistory '
			. 'WHERE team_id = ' . $teamId . ' '
			. 'GROUP BY season_id '
			. ') AS LASTPOS ON LASTPOS.season_id = H.season_id AND LASTPOS.max_matchday = H.matchday '
			. 'LEFT JOIN ' . $this->_tablePrefix . '_saison AS SEAS ON SEAS.id = H.season_id '
			. 'LEFT JOIN ' . $this->_tablePrefix . '_liga AS L ON L.id = SEAS.liga_id '
			. 'LEFT JOIN ' . $this->_tablePrefix . '_user AS U ON U.id = H.user_id '
			. 'WHERE H.team_id = ' . $teamId . ' '
			. 'ORDER BY H.season_id DESC';
		
		return $this->fetchRows($query);
	}
	
	private function officialMatchWhere($teamId, $matchAlias = 'M') {
		$teamId = (int) $teamId;
		return $matchAlias . '.berechnet = \'1\' '
			. 'AND ' . $matchAlias . '.home_tore IS NOT NULL '
			. 'AND ' . $matchAlias . '.gast_tore IS NOT NULL '
			. 'AND (' . $matchAlias . '.home_verein = ' . $teamId . ' OR ' . $matchAlias . '.gast_verein = ' . $teamId . ') '
			. 'AND (' . $matchAlias . '.spieltyp = \'Ligaspiel\' OR ' . $matchAlias . '.spieltyp = \'Pokalspiel\')';
	}
	
	private function playerNameExpression() {
		return 'MAX(COALESCE(NULLIF(P.kunstname, \'\'), NULLIF(TRIM(CONCAT(IFNULL(P.vorname, \'\'), \' \', IFNULL(P.nachname, \'\'))), \'\'), NULLIF(SB.name, \'\'), CONCAT(\'Spieler #\', SB.spieler_id)))';
	}
	
	private function titleKey($competition, $seasonName) {
		return strtolower(trim($competition) . '|' . trim($seasonName));
	}
	
	private function fetchRows($query) {
		$rows = array();
		$result = $this->_db->executeQuery($query);
		while ($row = $result->fetch_array()) {
			$rows[] = $row;
		}
		$result->free();
		return $rows;
	}
}

?>
