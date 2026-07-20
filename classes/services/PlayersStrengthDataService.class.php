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
class PlayersStrengthDataService {

    public static function calculatePlayerStrength2(WebSoccer $websoccer, DbConnection $db, $playerId) {
        // Compatibility entry point: use the same central sporting-strength calculation as the market-value service.
        $result = PlayerMarketValueDataService::recalculatePlayer($websoccer, $db, (int) $playerId);
        return isset($result['strength']) ? (float) $result['strength'] : 0.0;
    }
    
    
    public static function updateAllPlayersMarketAndStrength(WebSoccer $websoccer, DbConnection $db) {
        PlayerMarketValueDataService::recalculateAfterLatestMatch($websoccer, $db);
    }

    public static function updateAllPlayersMarketAndStrengthByPlayerId(WebSoccer $websoccer, DbConnection $db, $playerId) {
        return PlayerMarketValueDataService::recalculatePlayer($websoccer, $db, (int) $playerId);
    }

    /**
     * Compatibility entry point used throughout the existing codebase.
     * All calculations now run through the economy-aware central service.
     */
    public static function calculatePlayerStats(WebSoccer $websoccer, DbConnection $db, $playerId) {
        return PlayerMarketValueDataService::recalculatePlayer($websoccer, $db, (int) $playerId);
    }

	
}
?>