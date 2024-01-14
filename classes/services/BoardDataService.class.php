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
    public static function lowerBoardSatisfactionByTeamId(WebSoccer $websoccer, DbConnection $db, $value, $teamId, $userId) {
        
        $sqlStr = "UPDATE ". $websoccer->getConfig("db_prefix") ."_verein SET board_satisfaction=board_satisfaction-'$value' WHERE id='$teamId'";
        $db->executeQuery($sqlStr);
        
    }
    
    /*
     * BOARD SATISFACTION UPDATE
     */
    public static function updateBoardSatisfactionById(WebSoccer $websoccer, DbConnection $db, $index, $teamId) {
        
        //GET SATISFACTION
        $satisfaction = self::getBoardSatisfactionByTeamId($websoccer, $db, $teamId);        
        
        //GET SHARES
        $qtyFromUserClub = StockMarketDataService::getUserQuantityFromUserTeam($websoccer, $db, $index, $userId);
        
        //GET TOTAL OF INDEX
        $totalQty = StockMarketDataService::totalQtyByStockId($websoccer, $db, $index);
        
        //USER PERCENTAGE
        $userPercent = round(($qtyFromUserClub/$totalQty)*100,0);
        
        $new_satis = $satisfaction;

        //UPDATE ACC. SHARES
        if($satisfaction>100) {
            $new_satis = 100;
        } else if($satisfaction<0) {
            $new_satis = 10;
        }
        
        if($userPercent>33) {
            $new_satis = 51;
        } else if($userPercent>=100) {
            $new_satis = 100;
        }
        
        //UPDATE BOARD SATISFACTION IF CHANGE
        if($new_satis!=$satisfaction) {
            $sqlStr = "UPDATE ". $websoccer->getConfig("db_prefix") ."_verein
                        SET board_satisfaction=$new_satis'
                        WHERE id='$teamId'";
            $db->executeQuery($sqlStr);
        }
    }
    
    public static function getBoardinfoByTeamId(WebSoccer $websoccer, DbConnection $db, $clubId) {
        
        $SqlStr = "SELECT min_target_rank, min_target_highscore, highscore
					FROM ". $websoccer->getConfig("db_prefix") ."_verein
					WHERE id='".$clubId."'";
        $result = $db->executeQuery($SqlStr);
        $boardInfo = $result->fetch_array();
        $result->free();
        
        if($boardInfo['min_target_highscore']<1) {
            $boardInfo['min_target_highscore'] = 1;
        }
        if($boardInfo['min_target_rank']<1) {
            $boardInfo['min_target_rank'] = 1;
        }
        
        return $boardInfo;
    }
}
?>