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
class StockMarketDataService {
    
    /**
     * getting stockdata from google according to 'abbrev' in database table
     */
    public static function updateStockDataFromAlphavantage(WebSoccer $websoccer, DbConnection $db) {
        
        global $conf;
        $api_key = $conf["finnhub_api"];
        
        $month = date("M");
        $day = date("d");
        $year = date("Y");
        $weekday = date("D");
        $now = time();
        
        $sqlStr0 = "SELECT * FROM ". $websoccer->getConfig("db_prefix") ."_stockmarket WHERE team_id IS NULL ORDER BY id";
        $result0 = $db->executeQuery($sqlStr0);
        while ($stockdata = $result0->fetch_array())
        {
            
            $ticker = $stockdata['abbrev'];
            $time = $now-$stockdata['timestamp'];

            //only update stockmarket data between Monday an Friday and within 24 hours = 86400 seconds
            //if($weekday!="Sat" && $weekday!="Sun" && ($time>=86400)) {
            if($time>=86400) {
                
                /**
                 * FINNHUB.IO to retreive stock exchange data
                 * https://finnhub.io/api/v1/quote?symbol=".$ticker."&token=ABCDEF1234567890
                **/                
                $json = file_get_contents("https://finnhub.io/api/v1/quote?symbol=".$ticker."&token=".$api_key."");
                $data = json_decode($json, true);
                    
                $sqlStr2 = "SELECT * FROM ". $websoccer->getConfig("db_prefix") ."_stockmarket WHERE abbrev='$ticker'";
                $result2 = $db->executeQuery($sqlStr2);
                $index = $result2->fetch_array();
                    
                if(isset($data['c'])) {
                    $price = str_replace(".", ",", $data['c']);
                    
                    $v1 = $price;
                    $v2 = $index['v1'];
                    $v3 = $index['v2'];
                    $v4 = $index['v3'];
                    $v5 = $index['v4'];
                    $v6 = $index['v5'];
                    $v7 = $index['v6'];
                    $v8 = $index['v7'];
                    $v9 = $index['v8'];
                    $v10 = $index['v9'];
                    
                    $updSql = "UPDATE ". $websoccer->getConfig("db_prefix") ."_stockmarket
                                SET v1='".$v1."', 
                                    v2='".$v2."',  
                                    v3='".$v3."',  
                                    v4='".$v4."',  
                                    v5='".$v5."',  
                                    v6='".$v6."',  
                                    v7='".$v7."',  
                                    v8='".$v8."',  
                                    v9='".$v9."',  
                                    v10='".$v10."', 
                                    timestamp=".$now."
                                WHERE abbrev='".$ticker."'";
                    $db->executeQuery($updSql);
                }
            }
        }
        
        $updSql = "UPDATE ". $websoccer->getConfig("db_prefix") ."_stockmarket_date
                                SET date=$now";
        $db->executeQuery($updSql);
        
        $result0->free();
        
    }
    
    /**
     * getting stockdata from _stockmarket table
     */
    public static function getStockMarketData(WebSoccer $websoccer, DbConnection $db) {
        
        $indexes = array();
        $i=0;
        
        $sqlStr = "SELECT sm.* FROM ". $websoccer->getConfig("db_prefix") ."_stockmarket AS sm ORDER BY sm.abbrev";
        $result = $db->executeQuery($sqlStr);
        while ($stockdata = $result->fetch_array())
        {
            $usr_stockStr = "SELECT qty
                    FROM ". $websoccer->getConfig("db_prefix") ."_user_stock 
                    WHERE stock_id=".$stockdata['id']." AND user_id='1' LIMIT 1";
            $result2 = $db->executeQuery($usr_stockStr);
            $userstock = $result2->fetch_array();
            
            $indexes[$i] = $stockdata;
            $indexes[$i]['user_qty'] = $userstock['qty'];
            $i++; 
        }
        $result->free();
        
        return $indexes;
        
    }
    
    /**
     * getting stockdata from _stockmarket table by stockId
     */
    public static function getStockMarketDataById(WebSoccer $websoccer, DbConnection $db, $stockId) {
        
        $indexes = array();
        
        $sqlStr = "SELECT * FROM ". $websoccer->getConfig("db_prefix") ."_stockmarket
                    WHERE id='$stockId'";
        $result = $db->executeQuery($sqlStr);
        $indexes = $result->fetch_array();
        $result->free();
        
        return $indexes;
        
    }
    
    
    //INVERSE
    //SELECT v10 AS v1, v9 AS v2, v8 AS v3, v7 AS v4, v6 AS v5, v5 AS v6, v4 AS v7, v3 AS v8, v2 AS v9, v1 AS v10 FROM cm23_stockmarket WHERE id='4'; 
    /**
     * getting stockdata from _stockmarket table by stockId inverse order
     */
    public static function getStockMarketDataByIdInverse(WebSoccer $websoccer, DbConnection $db, $stockId) {
        
        $indexes = array();
        
        $sqlStr = "SELECT v10 AS v1, v9 AS v2, v8 AS v3, v7 AS v4, v6 AS v5, v5 AS v6, 
                        v4 AS v7, v3 AS v8, v2 AS v9, v1 AS v10 
                    FROM ". $websoccer->getConfig("db_prefix") ."_stockmarket
                    WHERE id='$stockId'";
        $result = $db->executeQuery($sqlStr);
        $indexes = $result->fetch_array();
        $result->free();
        
        return $indexes;
        
    }
    
    /**
     * getting user portfolio
     */
    public static function getUserPortfolio(WebSoccer $websoccer, DbConnection $db, $teamId) {
        
        $indexes = array();
        $i=0;
        
        $sqlStr = "SELECT us.*, sm.name, sm.v1
                    FROM ". $websoccer->getConfig("db_prefix") ."_user_stock AS us,
                        ". $websoccer->getConfig("db_prefix") ."_stockmarket AS sm
                    WHERE us.user_id='".$teamId."' 
                        AND sm.id=us.stock_id
                    ORDER BY id";
        $result = $db->executeQuery($sqlStr);
        while ($stockdata = $result->fetch_array())
        {
            $totalQty = self::totalQtyByStockId($websoccer, $db, $stockdata['stock_id']);
            $indexes[$i] = $stockdata;
            $indexes[$i]['total_qty'] = $totalQty;
            $i++;
        }
        $result->free();
        
        return $indexes;
        
    }
    
    /*
     * GET TOTAL AVAILABLE ON MARKET BY stock_id
     */
    static function totalQtyByStockId(WebSoccer $websoccer, DbConnection $db, $stockId) {
        //SELECT sm.quantity+SUM(us.qty) AS total FROM ". $websoccer->getConfig("db_prefix") ."_stockmarket AS sm, cm23_user_stock AS us WHERE us.stock_id=sm.id AND sm.id='$stockId';
        $indexes = array();
        
        $sqlStr = "SELECT sm.quantity+SUM(us.qty) AS total
                    FROM ". $websoccer->getConfig("db_prefix") ."_stockmarket AS sm,
                        ". $websoccer->getConfig("db_prefix") ."_user_stock AS us
                    WHERE us.stock_id=sm.id AND sm.id='$stockId'";
        $result = $db->executeQuery($sqlStr);
        $qty = $result->fetch_array();
        $result->free();
        
        $qty = $qty['total'];
        
        return $qty;
    }

    /**
     * getting user quantity of stock_id
     */
    public static function getQuantityFromUsersByIndex(WebSoccer $websoccer, DbConnection $db, $index, $userId) {
        
        $sqlStr = "SELECT SUM(qty) AS qty FROM ". $websoccer->getConfig("db_prefix") ."_user_stock
                    WHERE stock_id='".$index."' AND user_id='".$userId."'";
        $result = $db->executeQuery($sqlStr);
        $qty = $result->fetch_array();
        
        $qty = $qty['qty'];
        
        return $qty;
        
    }
    
    /**
     * getting maximum that player can buy acc. to cash available
     */
    public static function getUsersMaxByIndex(WebSoccer $websoccer, DbConnection $db, $index, $vereinId) {
        
        //GET CASH OF CLUB
        $userCashStr = "SELECT finanz_budget FROM ". $websoccer->getConfig("db_prefix") ."_verein
                    WHERE id='".$vereinId."'";
        $userCashResult = $db->executeQuery($userCashStr);
        $cash = $userCashResult->fetch_array();
        
        $cash = $cash['finanz_budget'];
        
        //GET PRICE OF INDEX
        $priceStr = "SELECT v1 FROM ". $websoccer->getConfig("db_prefix") ."_stockmarket
                    WHERE id='".$index."'";
        $priceResult = $db->executeQuery($priceStr);
        $price = $priceResult->fetch_array();
        
        $price = $price['v1'];
        
        $max = round($cash/$price,0);
        
        return $max;
        
    }
    
    /**
     * buy stock
     */
    public static function buyStock(WebSoccer $websoccer, DbConnection $db, $index, $qty, $teamId) {
        
        //check if index already in portfolio
        $isInPortfolioStr = "SELECT id FROM ". $websoccer->getConfig("db_prefix") ."_user_stock
                    WHERE stock_id='".$index."' AND user_id='$teamId'";
        $isInPortfolio = $db->executeQuery($isInPortfolioStr);
        $indexIn = $isInPortfolio->fetch_array();
        
        //get index price
        $indexPriceStr = "SELECT v1 FROM ". $websoccer->getConfig("db_prefix") ."_stockmarket
                    WHERE id='".$index."'";
        $indexPrice = $db->executeQuery($indexPriceStr);
        $price = $indexPrice->fetch_array();
        $price = $price['v1'];
        
        if(isset($indexIn['id'])) {
            $indexIn = 1;
        } else {
            $indexIn = 0;
        }
        
        //index price
        $price = $price*$qty;
        
        //buy stock transactions
        if($indexIn==1) {//add qty to portfolio
            $portfolioUpdateStr = "UPDATE ". $websoccer->getConfig("db_prefix") ."_user_stock SET qty=qty+$qty WHERE user_id='$teamId' AND stock_id='$index'";
        } else {
            //add qty to portfolio
            $portfolioUpdateStr = "INSERT ". $websoccer->getConfig("db_prefix") ."_user_stock (user_id, stock_id, qty) VALUES ('$teamId','$index','$qty')";
        }
        
        // credit / debit amount
        BankAccountDataService::debitAmount($websoccer, $db, $teamId, $price, "buy_stock_message", "sender_name");
        
        //deduct from available stock on from stockmarket table
        $stockmarketUpdateStr = "UPDATE ". $websoccer->getConfig("db_prefix") ."_stockmarket SET quantity=quantity-$qty WHERE id='$index'";
        
        $db->executeQuery($stockmarketUpdateStr);
        $db->executeQuery($portfolioUpdateStr);
    }
    
    /**
     * buy stock
     */
    public static function sellStock(WebSoccer $websoccer, DbConnection $db, $index, $qty, $teamId) {
        
        //check if index already in portfolio
        $isInPortfolioStr = "SELECT id FROM ". $websoccer->getConfig("db_prefix") ."_user_stock
                    WHERE stock_id='".$index."' AND user_id='$teamId'";
        $isInPortfolio = $db->executeQuery($isInPortfolioStr);
        $indexIn = $isInPortfolio->fetch_array();
        
        //get index price
        $indexPriceStr = "SELECT v1
                            FROM ". $websoccer->getConfig("db_prefix") ."_stockmarket
                            WHERE id='".$index."'";
        $indexPrice = $db->executeQuery($indexPriceStr);
        $price = $indexPrice->fetch_array();
        $price = $price['v1'];
        
        //index money - 5%
        $price = $price*$qty*0.95;
        
        // credit / debit amount
        BankAccountDataService::creditAmount($websoccer, $db, $teamId, $price, "sell_stock_message", "sender_name");
        
        //deduct from available stock on from stockmarket table
        $stockmarketUpdateStr = "UPDATE ". $websoccer->getConfig("db_prefix") ."_stockmarket SET quantity=quantity+$qty WHERE id='$index'";
        //portfolioUpdateQty
        $portfolioUpdateStr = "UPDATE ". $websoccer->getConfig("db_prefix") ."_user_stock SET qty=qty-$qty WHERE user_id='$teamId' AND stock_id='$index'";
        
        $db->executeQuery($stockmarketUpdateStr);
        $db->executeQuery($portfolioUpdateStr);
    }
    
    /*
     * Check if club is on stockmarket
     */
    public static function checkClubOnStockmarket(WebSoccer $websoccer, DbConnection $db, $teamId) {
        
        $sqlStr = "SELECT * FROM ". $websoccer->getConfig("db_prefix") ."_stockmarket
                            WHERE team_id='".$teamId."'";
        $return = $db->executeQuery($sqlStr);
        $index = $return->fetch_array();
        
        if(isset($index['team_id'])) {
            return $index;
        } else {
            return false;
        }
        
    }
    
    /*
     * Check club stockmarket criteria
     */
    public static function clubStockmarketCriteria(WebSoccer $websoccer, DbConnection $db, $teamId) {
        
        global $conf;
        $criteria_not_met = 0;
        
        //GET GLOBAL SETTINGS
        $min_stadium_size = $conf["min_stadium_size"];
        $min_team_value = $conf["min_team_value"];
        $active_credits = $conf["active_credits"];
        $min_team_titles_won = $conf["min_team_titles_won"];
        
        //CHECK IF ALREADY ON STOCKMARKET
        $onStockmarket = self::checkClubOnStockmarket($websoccer, $db, $teamId);
        if($onStockmarket==true) {
            $criteria_not_met++;
        }
        
        //CHECK IF ACTIVE CREDITS
        //NOT SET YET
        
        //GET STADIUM SIZE >=25.000
        $stadium = StadiumsDataService::getStadiumByTeamId($websoccer, $db, $teamId);
        $stadium_size = $stadium['p_steh']+$stadium['p_sitz']+$stadium['p_haupt_steh']+$stadium['p_haupt_sitz']+$stadium['p_vip'];
        
        if($stadium_size<$min_stadium_size) {
            $criteria_not_met++;
        }
        
        //GET TEAM VALUE >= 25.000.000
        $team_marketvalue = TeamsDataService::getTeamValueByTeamId($websoccer, $db, $teamId);
        if($team_marketvalue<$min_team_value) {
            $criteria_not_met++;
        }
        
        //GET HISTORY 10 Championships, Cups, etc. --> global config
        $titles = TeamsDataService::getNumberTeamTitlesWon($websoccer, $db, $teamId);
        if($titles<$min_team_titles_won) {
            $criteria_not_met++;
        }
        
        //SET 0 for testing reasons
        //$criteria_not_met = 0;
        
        if($criteria_not_met>=1) {
            return  true;
        } else {
            return false;
        }
        
    }
    
    /*
     * Get Club value to be registred on stockmarket
     */
    public static function clubValue(WebSoccer $websoccer, DbConnection $db, $teamId) {
        
        //GET finanz_budet
        $sqlStrFin = "SELECT finanz_budget
                        FROM ". $websoccer->getConfig("db_prefix") ."_verein
                        WHERE id='".$teamId."'";
        $returnFin = $db->executeQuery($sqlStrFin);
        $finance = $returnFin->fetch_array();
        
        $finance_budget = $finance['finanz_budget']*0.1;
        
        //GET TEAM market value
        $team_marketvalue = TeamsDataService::getTeamValueByTeamId($websoccer, $db, $teamId);
        $team_marketvalue = $team_marketvalue['team_marketvalue'];
        
        //GET STADIUM SIZE + VALUE
        $stadium = StadiumsDataService::getStadiumByTeamId($websoccer, $db, $teamId);
        $stadium_size = $stadium['p_steh']+$stadium['p_sitz']+$stadium['p_haupt_steh']+$stadium['p_haupt_sitz']+$stadium['p_vip'];
        $stadium_value = $stadium_size*100000;
        
        //GET CLUB TITLES WON
        $titles = TeamsDataService::getNumberTeamTitlesWon($websoccer, $db, $teamId);
        $titles_value = count($titles)*1000000;
        
        //GET PORTFOLIO VALUE
        $portfolio_value = self::getPortfolioValue($websoccer, $db, $teamId);
        $portfolio_value = $portfolio_value*0.5;
        
        $total_club_value = $finance_budget+$team_marketvalue+$stadium_value+$titles_value+$portfolio_value;
        
        return $total_club_value;
        
    }
    
    /*
     * GET USER PORTFOLIO VALUE
     */
    public static function getPortfolioValue(WebSoccer $websoccer, DbConnection $db, $teamId) {
        
        $portfolio_value = 0;
        
        $sqlStr = "SELECT us.qty*sm.v1 AS value
                    FROM ". $websoccer->getConfig("db_prefix") ."_user_stock AS us, ". $websoccer->getConfig("db_prefix") ."_stockmarket AS sm
                    WHERE us.user_id='$teamId'
                        AND us.stock_id=sm.id; ";
        $result = $db->executeQuery($sqlStr);
        while ($stockdata = $result->fetch_array())
        {
            $portfolio_value = $portfolio_value+$stockdata['value'];
        }
        $result->free();
        
        return $portfolio_value;
        
    }
    
    /*
     * PUT TEAM ON STOCKMARKET
     *
     */
    public static function putTeamOnStockmarket(WebSoccer $websoccer, DbConnection $db, $teamId, $abbrev, $name, $qty, $v1) {
        
        $stockcolumns['team_id'] = $teamId;
        $stockcolumns['abbrev'] = $abbrev;
        $stockcolumns['name'] = $name;
        $stockcolumns['quantity'] = $qty;
        $stockcolumns['v1'] = $v1;
        
        $fromTable = $websoccer->getConfig('db_prefix') . '_stockmarket';
        
        $db->queryInsert($stockcolumns, $fromTable);
        
    }
    
    /**
     * getting user quantity of own team
     */
    public static function getUserQuantityFromUserTeam(WebSoccer $websoccer, DbConnection $db, $index, $userId) {
        
        $sqlStr = "SELECT qty
                    FROM ". $websoccer->getConfig("db_prefix") ."_user_stock
                    WHERE stock_id='".$index."' AND user_id='".$userId."'";
        $result = $db->executeQuery($sqlStr);
        $qty = $result->fetch_array();
        
        $qty = $qty['qty'];
        
        return $qty;
        
    }
}
?>