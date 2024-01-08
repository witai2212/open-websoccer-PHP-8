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
        
        $month = date("M");
        $day = date("d");
        $year = date("Y");
        $weekday = date("D");
        $now = time();
        
        $api_key = $conf["alphavantage_api"];
        
        $sqlStr = "SELECT * FROM ". $websoccer->getConfig("db_prefix") ."_stockmarket ORDER BY id"; 
        $result = $db->executeQuery($sqlStr);
        while ($stockdata = $result->fetch_array())
        {
            
            $ticker = $stockdata['abbrev'];
            $time = $now-$stockdata['timestamp'];

            //only update stockmarket data between Monday an Friday and within 24 hours = 86400 seconds
            if($weekday!="Sat" && $weekday!="Sun" && ($time>=86400)) {
                
                //ALPHAVANTAGE GLOBAL DATA
                //https://www.alphavantage.co/query?function=GLOBAL_QUOTE&symbol=IBM&apikey=DH3TRU61NC6C1R5K
                //SEARCH:
                //https://www.alphavantage.co/query?function=SYMBOL_SEARCH&keywords=cbo&apikey=DH3TRU61NC6C1R5K
                $json = file_get_contents("https://www.alphavantage.co/query?function=GLOBAL_QUOTE&symbol=".$ticker."&apikey=".$api_key."");
                $data = json_decode($json,true);
                
                foreach ($data as $quote) {
                    
                    $price = str_replace(".", ",", $quote['05. price']);
                    
                    $updSql = "UPDATE  ". $websoccer->getConfig("db_prefix") ."_stockmarket
                                SET v10=v9, v9=v8, v8=v7, v7=v6, v6=v5, v5=v4, v4=v3, v3=v2, v2=v1,
                                        v1='".$price."', timestamp=".$now."
                                WHERE abbrev='".$ticker."'";
                    echo"updSql: ". $updSql ."<br>";
                    $db->executeQuery($updSql);
                    
                }
            }
        }
        $result->free();
        
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
            $indexes[$i] = $stockdata;
            $i++;
        }
        $result->free();
        
        return $indexes;
        
    }

    /**
     * getting user quantity of stock_id
     */
    public static function getQuantityFromUsersByIndex(WebSoccer $websoccer, DbConnection $db, $index) {
        
        $sqlStr = "SELECT SUM(qty) AS qty FROM ". $websoccer->getConfig("db_prefix") ."_user_stock
                    WHERE stock_id='".$index."'";
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
        
        //pay price
        $budgetUpdateStr = "UPDATE ". $websoccer->getConfig("db_prefix") ."_verein SET finanz_budget=finanz_budget-$price WHERE id='$teamId'";
        //deduct from available stock on from stockmarket table
        $stockmarketUpdateStr = "UPDATE ". $websoccer->getConfig("db_prefix") ."_stockmarket SET quantity=quantity-$qty WHERE id='$index'";

        $db->executeQuery($stockmarketUpdateStr);
        $db->executeQuery($portfolioUpdateStr);
        $db->executeQuery($budgetUpdateStr);
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
        
        //get money price
        $budgetUpdateStr = "UPDATE ". $websoccer->getConfig("db_prefix") ."_verein SET finanz_budget=finanz_budget+$price WHERE id='$teamId'";
        //deduct from available stock on from stockmarket table
        $stockmarketUpdateStr = "UPDATE ". $websoccer->getConfig("db_prefix") ."_stockmarket SET quantity=quantity+$qty WHERE id='$index'";
        //portfolioUpdateQty
        $portfolioUpdateStr = "UPDATE ". $websoccer->getConfig("db_prefix") ."_user_stock SET qty=qty-$qty WHERE user_id='$teamId' AND stock_id='$index'";
        
        $db->executeQuery($stockmarketUpdateStr);
        $db->executeQuery($portfolioUpdateStr);
        $db->executeQuery($budgetUpdateStr);
    }
}
?>