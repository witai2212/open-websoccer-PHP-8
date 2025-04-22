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
        
        $player_data = PlayersDataService::getPlayerById($websoccer, $db, $playerId);
        
        $position = $player_data['player_position'];
        $note_schnitt = round($player_data['player_avg_grade'],2);
        $vertrag_spiele = $player_data['player_contract_matches'];
        
        //echo"pos: ". $position ."<br>";
        switch($position) {
            
            case "goaly":
                $w_passing = $player_data['player_strength_passing'] * 0.75;
                $w_shooting = $player_data['player_strength_shooting'] * 0.75;
                $w_heading = $player_data['player_strength_heading'] * 0.25;
                $w_tackling = $player_data['player_strength_tackling'] * 1;
                $w_freekick = $player_data['player_strength_freekick'] * 0.5;
                $w_pace = $player_data['player_strength_pace'] * 0.5;
                $w_creativity = $player_data['player_strength_creativity'] * 0.5;
                $w_influence = $player_data['player_strength_influence'] * 2;
                $w_flair = $player_data['player_strength_flair'] * 1.75;
                $w_penalty = $player_data['player_strength_penalty'] * 0.5;
                $w_penalty_killing = $player_data['player_strength_penalty_killing'] * 1.5;
                break;
                
            case "defense":
                $w_passing = $player_data['player_strength_passing'] * 1;
                $w_shooting = $player_data['player_strength_shooting'] * 0.5;
                $w_heading = $player_data['player_strength_heading'] * 1.5;
                $w_tackling = $player_data['player_strength_tackling'] * 1.5;
                $w_freekick = $player_data['player_strength_freekick'] * 0.5;
                $w_pace = $player_data['player_strength_pace'] * 1;
                $w_creativity = $player_data['player_strength_creativity'] * 0.5;
                $w_influence = $player_data['player_strength_influence'] * 1.25;
                $w_flair = $player_data['player_strength_flair'] * 1;
                $w_penalty = $player_data['player_strength_penalty'] * 0.75;
                $w_penalty_killing = $player_data['player_strength_penalty_killing'] * 0.5;
                break;
                
            case "midfield":
                $w_passing = $player_data['player_strength_passing'] * 1.5;
                $w_shooting = $player_data['player_strength_shooting'] * 1;
                $w_heading = $player_data['player_strength_heading'] * 0.5;
                $w_tackling = $player_data['player_strength_tackling'] * 1;
                $w_freekick = $player_data['player_strength_freekick'] * 1;
                $w_pace = $player_data['player_strength_pace'] * 0.5;
                $w_creativity = $player_data['player_strength_creativity'] * 1;
                $w_influence = $player_data['player_strength_influence'] * 1;
                $w_flair = $player_data['player_strength_flair'] * 1;
                $w_penalty = $player_data['player_strength_penalty'] * 1;
                $w_penalty_killing = $player_data['player_strength_penalty_killing'] * 0.5;
                break;
                
            case "striker":
                $w_passing = $player_data['player_strength_passing'] * 1.3;
                $w_shooting = $player_data['player_strength_shooting'] * 1.6;
                $w_heading = $player_data['player_strength_heading'] * 1.3;
                $w_tackling = $player_data['player_strength_tackling'] * 0.5;
                $w_freekick = $player_data['player_strength_freekick'] * 0.5;
                $w_pace = $player_data['player_strength_pace'] * 1.5;
                $w_creativity = $player_data['player_strength_creativity'] * 0.5;
                $w_influence = $player_data['player_strength_influence'] * 0.5;
                $w_flair = $player_data['player_strength_flair'] * 0.5;
                $w_penalty = $player_data['player_strength_penalty'] * 1.3;
                $w_penalty_killing = $player_data['player_strength_penalty_killing'] * 0.5;
                break;
        }
        
        $strength = round(((($w_passing+$w_shooting+$w_heading+$w_tackling+$w_freekick+
            $w_pace+$w_creativity+$w_influence+$w_flair+$w_penalty+$w_penalty_killing)
            /10)+$note_schnitt)*($vertrag_spiele/60),0);
        //echo "(((".$w_passing ." + ". $w_shooting ." + ". $w_heading ." + ". $w_tackling ." + ". $w_freekick ." + ". $w_pace ." + ". $w_creativity ." + ". $w_influence ." + ". $w_flair ." + ". $w_penalty ." + ". $w_penalty_killing.")/10) + ". $note_schnitt .")*(". $vertrag_spiele ."/60)<br>";
        
        // update in DB
        $updStr = "UPDATE ". $websoccer->getConfig('db_prefix') ."_spieler SET w_staerke_calc='$strength' WHERE id='$playerId'";
        $db->executeQuery($updStr);
        //echo"str: ". $strength ."<br>";
        return $strength;
    }
    
    /***
    public static function calculateMarketValue2(WebSoccer $websoccer, DbConnection $db, $playerId) {
        
        //echo"calculateMarketValue2: ". $playerId ."<br>";
        /
         * =G34*E40*E39*E38*E37*E36
         * alter faktor: =MAX(0,5; 1 - ((age - 28) * 0,02))
         * summe pos_faktoren = =((SUMME(faktoren)/10)+note_durchschnitt)*(vertragg_spiele/60)
         * =125000 * positions_wert * ((staerke/staerke_max)*(staerke/100)) * talent_wert * alter_faktor * summe_positions_faktoren *
         *
        
        $player_data = PlayersDataService::getPlayerById($websoccer, $db, $playerId);
        if(!isset($playerId)) {
            $playerId = $player_data['player_id'];
        }
        
        //print_r($player_data);
        //echo"<br>";
        
        $strength = round(self::calculatePlayerStrength2($websoccer, $db, $playerId),2);
        $age = $player_data['player_age'];
        $age_factor = max(0.5, 1-(($age-28)*0.02));
        
        $position = $player_data['player_position'];
        //echo"position: ". $position ."<br>";
        
        if($player_data['player_strength_talent']>5) {
            $talent_factor = 1.4;
        } else {
            $talent_factor = $player_data['player_strength_talent']/5;
        }
        
        switch($position) {
            
            case "goaly":
                $position_factor = 0.9;
                break;
            case "defense":
                $position_factor = 0.95;
                break;
            case "midfield":
                $position_factor = 1;
                break;
            case "striker":
                $position_factor = 1.05;
                break;
        }
        
        if($player_data['player_strength_max']<1) {
            $player_data['player_strength_max'] = $player_data['player_strength'];
        }
        $max_factor = 100-$player_data['player_strength_max'];
        if($max_factor<1) {
            $max_factor = 1;
        }
        $w_staerke_max_factor = $player_data['player_strength']/$max_factor;
        
        //echo $position_factor ." * ". $w_staerke_max_factor ." * ". $talent_factor ." * ". $age_factor ." * ". $strength ."<br>";
        
        // transfermarket_value_per_strength = 11000
        $start_value = $websoccer->getConfig('transfermarket_value_per_strength');
        $marketvalue = round($start_value * $position_factor * $w_staerke_max_factor * $talent_factor * $age_factor * $strength,0);
        
        //echo"calculateMarketValue2: ". $marketvalue ."<br>";
        
        // update in DB
        $updStr1 = "UPDATE ". $websoccer->getConfig('db_prefix') ."_spieler SET marktwert='$marketvalue' WHERE id='$playerId'";
        $db->executeQuery($updStr1);
        
        // update in DB
        $updStr2 = "UPDATE ". $websoccer->getConfig('db_prefix') ."_spieler SET w_staerke_calc='$strength' WHERE id='$playerId'";
        $db->executeQuery($updStr2);
        
        return $marketvalue;
    }
    */
    
    public static function updateAllPlayersMarketAndStrength(WebSoccer $websoccer, DbConnection $db) {
        
        $now = $websoccer->getNowAsTimestamp()-(86400*2);
        
        // Query all necessary fields from the players table.
        $sqlStr = "SELECT * FROM " . $websoccer->getConfig('db_prefix') . "_spieler
                    WHERE on_update<$now LIMIT 100";
        //echo $sqlStr ."<br>";
        $result = $db->executeQuery($sqlStr);
        while ($player_data = $result->fetch_array()) {
            
            $playerId = $player_data['id'];
            
            //$playerStrength = self::calculatePlayerStrength2($websoccer, $db, $playerId);
            //$marketvalue = self::calculateMarketValue2($websoccer, $db, $playerId);
            self::calculatePlayerStats($websoccer, $db, $playerId);
        
        }
        $result->free();
    }
    
    
    public static function updateAllPlayersMarketAndStrengthByPlayerId(WebSoccer $websoccer, DbConnection $db, $playerId) {
        
        $now = $websoccer->getNowAsTimestamp()-(86400*2);
        
        // Query all necessary fields from the players table.
        $sqlStr = "SELECT * FROM " . $websoccer->getConfig('db_prefix') . "_spieler
                    WHERE id='".$playerId."'";
        //echo $sqlStr ."<br>";
        $result = $db->executeQuery($sqlStr);
        while ($player_data = $result->fetch_array()) {
            
            $playerId = $player_data['id'];
            
            //$playerStrength = self::calculatePlayerStrength2($websoccer, $db, $playerId);
            //$marketvalue = self::calculateMarketValue2($websoccer, $db, $playerId);
            self::calculatePlayerStats($websoccer, $db, $playerId);
            
        }
        $result->free();
    }
    
    public static function calculatePlayerStats(WebSoccer $websoccer, DbConnection $db, $playerId) {
        
        $player_data = PlayersDataService::getPlayerById($websoccer, $db, $playerId);
        if (!isset($playerId)) {
            $playerId = $player_data['player_id'];
        }
        
        $position = $player_data['player_position'];
        $note_schnitt = round($player_data['player_avg_grade'], 2);
        $vertrag_spiele = $player_data['player_contract_matches'];
        
        // Define weight factors for each position
        $position_weights = [
            "goaly" => [0.75, 0.75, 0.25, 1, 0.5, 0.5, 0.5, 2, 1.75, 0.5, 1.5],
            "defense" => [1, 0.5, 1.5, 1.5, 0.5, 1, 0.5, 1.25, 1, 0.75, 0.5],
            "midfield" => [1.5, 1, 0.5, 1, 1, 0.5, 1, 1, 1, 1, 0.5],
            "striker" => [1.3, 1.6, 1.3, 0.5, 0.5, 1.5, 0.5, 0.5, 0.5, 1.3, 0.5]
        ];
        
        // Extract weights based on position
        [
        $w_passing, $w_shooting, $w_heading, $w_tackling, $w_freekick,
        $w_pace, $w_creativity, $w_influence, $w_flair, $w_penalty,
        $w_penalty_killing
        ] = $position_weights[$position] ?? array_fill(0, 11, 1); // Default to 1 if position is missing
        
        // Calculate player strength
        $strength = round(((
            ($w_passing * $player_data['player_strength_passing']) +
            ($w_shooting * $player_data['player_strength_shooting']) +
            ($w_heading * $player_data['player_strength_heading']) +
            ($w_tackling * $player_data['player_strength_tackling']) +
            ($w_freekick * $player_data['player_strength_freekick']) +
            ($w_pace * $player_data['player_strength_pace']) +
            ($w_creativity * $player_data['player_strength_creativity']) +
            ($w_influence * $player_data['player_strength_influence']) +
            ($w_flair * $player_data['player_strength_flair']) +
            ($w_penalty * $player_data['player_strength_penalty']) +
            ($w_penalty_killing * $player_data['player_strength_penalty_killing'])
            ) / 10 + $note_schnitt) * ($vertrag_spiele / 60), 0);
        
        // Calculate market value
        $age = $player_data['player_age'];
        $age_factor = max(0.5, 1 - (($age - 28) * 0.02));
        
        $position_factors = [
            "goaly" => 0.9,
            "defense" => 0.95,
            "midfield" => 1,
            "striker" => 1.05
        ];
        $position_factor = $position_factors[$position] ?? 1; // Default to 1
        
        $talent_factor = ($player_data['player_strength_talent'] > 5) ? 1.4 : ($player_data['player_strength_talent'] / 5);
        
        if ($player_data['player_strength_max'] < 1) {
            $player_data['player_strength_max'] = $player_data['player_strength'];
        }
        $max_factor = max(1, 100 - $player_data['player_strength_max']);
        $w_staerke_max_factor = $player_data['player_strength'] / $max_factor;
        
        $start_value = $websoccer->getConfig('transfermarket_value_per_strength');
        $marketvalue = round($start_value * $position_factor * $w_staerke_max_factor * $talent_factor * $age_factor * $strength, 0);
        
        // Update database
        $now = $websoccer->getNowAsTimestamp();
        $db->executeQuery("UPDATE ". $websoccer->getConfig('db_prefix') ."_spieler
                            SET w_staerke_calc='$strength', marktwert='$marketvalue', on_update='".$now."'
                            WHERE id='$playerId'");
        
        return ["strength" => $strength, "market_value" => $marketvalue];
    }
    
	
}
?>