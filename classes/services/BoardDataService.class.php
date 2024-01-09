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
 * Data service for stockmarket/teams-finances
 */
class BoardDataService {
        
    /*
     * GET BOARD SATISFACTION
     */
    public static function getBoardSatisfactionByTeamId(WebSoccer $websoccer, DbConnection $db, $teamId) {
                
        $sqlStr = "SELECT board_satisfaction FROM ". $websoccer->getConfig("db_prefix") ."_verein WHERE id='$teamId'";
        $result = $db->executeQuery($sqlStr);
        $board = $result->fetch_array();
        $result->free();
        
        $board_satisfaction = $board['board_satisfaction'];
        
        return $board_satisfaction;
        
    }
    
    /*
     * RAISE BOARD SATISFACTION
     */
    public static function raiseBoardSatisfactionByTeamId(WebSoccer $websoccer, DbConnection $db, $value, $teamId) {
        
        $sqlStr = "UPDATE ". $websoccer->getConfig("db_prefix") ."_verein SET board_satisfaction=board_satisfaction+'$value' WHERE id='$teamId'";
        $db->executeQuery($sqlStr);
        
    }
    
    /*
     * LOWER BOARD SATISFACTION
     */
    public static function lowerBoardSatisfactionByTeamId(WebSoccer $websoccer, DbConnection $db, $value, $teamId) {
        
        $sqlStr = "UPDATE ". $websoccer->getConfig("db_prefix") ."_verein SET board_satisfaction=board_satisfaction-'$value' WHERE id='$teamId'";
        $db->executeQuery($sqlStr);
        
    }
    
    /*
     * BOARD SATISFACTION UPDATE
     */
    public static function updateBoardSatisfactionById(WebSoccer $websoccer, DbConnection $db, $value, $teamId) {
        
        //GET SATISFACTION
        
        //GET SHARES

        //UPDATE ACC. SHARES
        
    }
}
?>