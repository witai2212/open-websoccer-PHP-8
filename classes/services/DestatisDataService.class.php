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
 * Data service for scouting
 */
class DestatisDataService {
    
    /**
     * Provides list gates by leagueId
     *
     * @param WebSoccer $websoccer Application context.
     * @param DbConnection $db DB connection.
     * @param int $leagueId ID of league
     * @return array of avg gates by league Id.
     */
    public static function getAvgGatesByLeagueId(WebSoccer $websoccer, DbConnection $db, $leagueId) {
        
        $sqlStr = "SELECT G.liga_id AS league_id, G.home_verein AS home_verein, ROUND(AVG(G.zuschauer),0) AS zuschauer,
                        C.name AS team_name, C.bild AS team_bild, C.platz
                    FROM ". $websoccer->getConfig("db_prefix") ."_spiel AS G,
                        ". $websoccer->getConfig("db_prefix") ."_verein AS C
                    WHERE G.liga_id='$leagueId' AND C.id=G.home_verein
                    GROUP BY home_verein
                    ORDER BY AVG(zuschauer) DESC";
        $result = $db->executeQuery($sqlStr);
        
        $gates = array();
        $i = 0;
        while ($gate = $result->fetch_array()) {
            $gates[$i] = $gate;
            
            //GET TEAMS STADIUM DATA
            $stadium = StadiumsDataService::getStadiumByTeamId($websoccer, $db, $gate['home_verein']);
            
            $capacity = $stadium['places_stands']+$stadium['places_seats']+$stadium['places_stands_grand']+$stadium['places_seats_grand']+$stadium['places_vip'];
            $occupation = round($gate['zuschauer']/$capacity,2)*100;
            $gates[$i]['occupation'] = $occupation;
            
            $i++;
        }
        $result->free();
        
        return $gates;
    }
    
    /**
     * Provides list best players by rating/note
     *
     * @param WebSoccer $websoccer Application context.
     * @param DbConnection $db DB connection.
     * @param int $leagueId ID of league
     * @return array of player ratings by league Id.
     */
    public static function getAvgPlayerRatingsByLeagueId(WebSoccer $websoccer, DbConnection $db, $leagueId) {
        
        $max = $websoccer->getConfig('entries_per_page');
        
        $sqlStr = "SELECT P.id, P.vorname, P.nachname, P.note_last, P.note_schnitt
                       FROM ". $websoccer->getConfig("db_prefix") ."_spieler AS P, ". $websoccer->getConfig("db_prefix") ."_verein AS C
                    WHERE C.liga_id='$leagueId' AND P.verein_id=C.id AND P.note_schnitt>0
                    ORDER BY P.note_schnitt DESC, P.sa_spiele DESC LIMIT $max";
        $result = $db->executeQuery($sqlStr);
        
        $players = array();
        $i = 0;
        while ($player = $result->fetch_array()) {
            $players[$i] = $player;
            $i++;
        }
        $result->free();
        
        return $players;
    }
    
    /**
     * Provides list players with worst discipline
     *
     * @param WebSoccer $websoccer Application context.
     * @param DbConnection $db DB connection.
     * @param int $leagueId ID of league
     * @return array of worst disciplines players by league Id.
     */
    public static function getWorstDiscipinesByLeagueId(WebSoccer $websoccer, DbConnection $db, $leagueId) {
        
        $max = $websoccer->getConfig('entries_per_page');
        
        $sqlStr = "SELECT P.id, P.vorname, P.nachname, P.sa_karten_gelb, P.sa_karten_gelb_rot, P.sa_karten_rot
                       FROM ". $websoccer->getConfig("db_prefix") ."_spieler AS P, ". $websoccer->getConfig("db_prefix") ."_verein AS C
                    WHERE C.liga_id='$leagueId' AND P.verein_id=C.id
                    ORDER BY (P.sa_karten_rot+P.sa_karten_gelb_rot+P.sa_karten_gelb) DESC, P.sa_karten_rot DESC,
                        P.sa_karten_gelb_rot DESC, P.sa_karten_gelb DESC LIMIT $max";
        $result = $db->executeQuery($sqlStr);
        
        $players = array();
        $i = 0;
        while ($player = $result->fetch_array()) {
            $players[$i] = $player;
            $i++;
        }
        $result->free();
        
        return $players;
    }
    
    /**
     * Provides list of richest clubs
     *
     * @param WebSoccer $websoccer Application context.
     * @param DbConnection $db DB connection.
     * @return array of richest clubs.
     */
    public static function getRichestClubs(WebSoccer $websoccer, DbConnection $db) {
        
        $max = $websoccer->getConfig('entries_per_page');
        
        $sqlStr = "SELECT id, name, bild, finanz_budget FROM ". $websoccer->getConfig("db_prefix") ."_verein
                    ORDER BY finanz_budget DESC LIMIT $max";
        $result = $db->executeQuery($sqlStr);
        
        $teams = array();
        $i = 0;
        while ($team = $result->fetch_array()) {
            $teams[$i] = $team;
            $i++;
        }
        $result->free();
        
        return $teams;
    }

	/**
	 * get stadium visitors by club
	 *
	 * @param WebSoccer $websoccer Application context.
	 */
	public static function highestClubStadiumVisitorsByClub(WebSoccer $websoccer, DbConnection $db) {
		
		$visitors = [];

		$sqlStr = "SELECT V.id AS club_id, V.name AS club_name, V.bild AS club_bild, SUM(S.zuschauer) AS visitors, ST.id AS stadium_id, ST.name AS stadium_name
					FROM " . $websoccer->getConfig("db_prefix") . "_spiel AS S
						INNER JOIN " . $websoccer->getConfig("db_prefix") . "_verein AS V ON V.id = S.home_verein
						INNER JOIN " . $websoccer->getConfig("db_prefix") . "_liga AS L ON L.id = V.liga_id
						INNER JOIN " . $websoccer->getConfig("db_prefix") . "_stadion AS ST ON ST.id = V.stadion_id
					GROUP BY V.id
					ORDER BY visitors DESC
					LIMIT 20";
		$result = $db->executeQuery($sqlStr);
		while ($visitor = $result->fetch_array()) {
			$visitors[] = $visitor;
		}

		$result->free();
		return $visitors;
	}
	
	/**
	 * get stadium visitors by league
	 *
	 * @param WebSoccer $websoccer Application context.
	 */
	public static function highestClubStadiumVisitorsByLeague(WebSoccer $websoccer, DbConnection $db) {
		
		$visitors = [];

		$sqlStr = "SELECT L.id AS league_id, L.name AS league_name, L.land AS league_country, SUM(S.zuschauer) AS visitors, AVG(S.zuschauer) AS avg_visitors
					FROM " . $websoccer->getConfig("db_prefix") . "_spiel AS S
						INNER JOIN " . $websoccer->getConfig("db_prefix") . "_verein AS V ON V.id = S.home_verein
						INNER JOIN " . $websoccer->getConfig("db_prefix") . "_liga AS L ON L.id = V.liga_id
					GROUP BY L.land
					ORDER BY visitors DESC
					LIMIT 20";
		$result = $db->executeQuery($sqlStr);

		while ($visitor = $result->fetch_array()) {
			$visitors[] = $visitor;
		}

		$result->free();
		return $visitors;
	}
	
	/**
	 * get avarage sales from _konto table
	 *
	 */
    public static function getAvgSales(WebSoccer $websoccer, DbConnection $db) {
		
		$sqlStr = "SELECT AVG(betrag) sales FROM " . $websoccer->getConfig("db_prefix") . "_konto";
		$result = $db->executeQuery($sqlStr);
		$konto = $result->fetch_array();
		$result->free();
		$sales = $konto['sales'];

		return $sales;
		
	}
	
	/**
	 * get avarage sales history
	 *
	 */
    public static function getSalesHistory(WebSoccer $websoccer, DbConnection $db) {
		
		$sqlStr = "SELECT * FROM " . $websoccer->getConfig("db_prefix") . "_kontohistory";
		$result = $db->executeQuery($sqlStr);
		$history = $result->fetch_array();
		$result->free();

		return $history;
		
	}	
}