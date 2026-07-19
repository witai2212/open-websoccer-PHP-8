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
                    //$price = $data['c'];
                    
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
        
        //delete stock if qty = 0
        $delStockStr = "DELETE FROM ". $websoccer->getConfig("db_prefix") ."_user_stock WHERE qty<=0";
        $db->executeQuery($delStockStr);
        
    }
    
    /**
     * getting stockdata from _stockmarket table
     */
    public static function getStockMarketData(WebSoccer $websoccer, DbConnection $db, $teamId = 0) {
        
        $indexes = array();
        $i=0;
        
        $sqlStr = "SELECT sm.* FROM ". $websoccer->getConfig("db_prefix") ."_stockmarket AS sm ORDER BY sm.abbrev";
        $result = $db->executeQuery($sqlStr);
        while ($stockdata = $result->fetch_array())
        {
            $usr_stockStr = "SELECT COALESCE(SUM(qty), 0) AS qty
                    FROM ". $websoccer->getConfig("db_prefix") ."_user_stock 
                    WHERE stock_id='". (int) $stockdata['id'] ."'
                      AND user_id='". (int) $teamId ."'";
            $result2 = $db->executeQuery($usr_stockStr);
            $userstock = $result2->fetch_array();
            
            $indexes[$i] = $stockdata;
            $indexes[$i]['user_qty'] = isset($userstock['qty']) ? (int) $userstock['qty'] : 0;
            $indexes[$i]['total_qty'] = self::totalQtyByStockId($websoccer, $db, $stockdata['id']);
            $indexes[$i]['owned_percent'] = self::getOwnershipPercent($websoccer, $db, $stockdata['id'], $teamId);
            $indexes[$i]['is_majority_owner'] = ($indexes[$i]['owned_percent'] >= 51);
            $indexes[$i]['can_fire_manager'] = self::canFireManagerByMajorityOwnership($websoccer, $db, $stockdata['id'], $teamId);
            $i++; 
        }
        $result->free();
        
        return self::applyStockRecommendations($websoccer, $db, $teamId, $indexes, false);
        
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
        //(index.v1*index.qty*0.95
		
        $sqlStr = "SELECT us.*, SUM(us.qty) index_qty, AVG(us.price) AS avg_price, 
                            SUM(us.qty)*AVG(us.price) AS value_bought, 
                            (REPLACE(sm.v1,',','.')*SUM(us.qty)) AS curr_value,
                            sm.name,
                            sm.team_id,
                            sm.quantity,
                            REPLACE(sm.v1, ',', '.') AS v1, sm.v2, sm.v3, sm.v4
                    FROM ". $websoccer->getConfig("db_prefix") ."_user_stock AS us,
                        ". $websoccer->getConfig("db_prefix") ."_stockmarket AS sm
                    WHERE us.user_id='".$teamId."' 
                        AND sm.id=us.stock_id
					GROUP BY us.stock_id
                    ORDER BY id";
		//echo $sqlStr ."<br>";
        $result = $db->executeQuery($sqlStr);
        while ($stockdata = $result->fetch_array())
        {
            $totalQty = self::totalQtyByStockId($websoccer, $db, $stockdata['stock_id']);
            $indexes[$i] = $stockdata;
            $indexes[$i]['total_qty'] = $totalQty;
            $indexes[$i]['owned_percent'] = self::getOwnershipPercent($websoccer, $db, $stockdata['stock_id'], $teamId);
            $i++;
        }
        $result->free();
        
        return self::applyStockRecommendations($websoccer, $db, $teamId, $indexes, true);
        
    }
    
    /*
     * GET TOTAL AVAILABLE ON MARKET BY stock_id
     */
    static function totalQtyByStockId(WebSoccer $websoccer, DbConnection $db, $stockId) {
        $sqlStr = "SELECT (sm.quantity + COALESCE(SUM(us.qty), 0)) AS total
                    FROM ". $websoccer->getConfig("db_prefix") ."_stockmarket AS sm
                    LEFT JOIN ". $websoccer->getConfig("db_prefix") ."_user_stock AS us ON us.stock_id = sm.id
                    WHERE sm.id='".(int) $stockId."'
                    GROUP BY sm.id, sm.quantity";
        $result = $db->executeQuery($sqlStr);
        $qty = $result->fetch_array();
        $result->free();
        
        return ($qty && isset($qty['total'])) ? (int) $qty['total'] : 0;
    }

    /**
     * getting user quantity of stock_id
     */
    public static function getQuantityFromUsersByIndex(WebSoccer $websoccer, DbConnection $db, $index, $userId) {
    
        $index  = (int) $index;
        $userId = (int) $userId;
    
        if ($index < 1 || $userId < 1) {
            return 0;
        }
    
        $sqlStr = "SELECT COALESCE(SUM(qty), 0) AS qty
                     FROM ". $websoccer->getConfig("db_prefix") ."_user_stock
                    WHERE stock_id='". $index ."'
                      AND user_id='". $userId ."'";
        $result = $db->executeQuery($sqlStr);
        $row = $result->fetch_array();
        $result->free();
    
        return ($row && isset($row['qty'])) ? (int) $row['qty'] : 0;
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
        $priceStr = "SELECT v1 FROM ". $websoccer->getConfig("db_prefix") ."_stockmarket WHERE id='".$index."'";
        $priceResult = $db->executeQuery($priceStr);
        $price = $priceResult->fetch_array();
        
        $price = $price['v1'];
        
        $price = self::normalizePrice($price);
        if ($price <= 0) {
            return 0;
        }
        $max = (int) floor($cash/$price);
        $majorityLimitedMax = self::getMaxBuyQtyRespectingMajorityLimit($websoccer, $db, $index, $vereinId);
        if ($majorityLimitedMax >= 0) {
            $max = min($max, $majorityLimitedMax);
        }
        
        return max(0, (int) $max);
        
    }
    
    /**
     * buy stock
     */
    public static function buyStock(WebSoccer $websoccer, DbConnection $db, $index, $qty, $teamId) {
        $index = (int) $index;
        $qty = max(0, (int) $qty);
        $teamId = (int) $teamId;
        if ($index < 1 || $qty < 1 || $teamId < 1) {
            return false;
        }

        $maxQty = self::getUsersMaxByIndex($websoccer, $db, $index, $teamId);
        $availableQty = self::getAvailableQuantity($websoccer, $db, $index);
        $qty = min($qty, $maxQty, $availableQty);
        if ($qty < 1) {
            return false;
        }
        
        //get index price
        $indexPriceStr = "SELECT v1 FROM ". $websoccer->getConfig("db_prefix") ."_stockmarket
                    WHERE id='".$index."'";
        $indexPrice = $db->executeQuery($indexPriceStr);
        $price = $indexPrice->fetch_array();
        $price = self::normalizePrice($price['v1']);
        $v1 = $price;
        
        //index price
        $totalPrice = $price*$qty;
        
        //buy stock transactions
        $portfolioInsertStr = "INSERT ". $websoccer->getConfig("db_prefix") ."_user_stock
                                (user_id, stock_id, qty, price) VALUES ('$teamId','$index','$qty','$v1')";
        $db->executeQuery($portfolioInsertStr);
        
        // credit / debit amount
        BankAccountDataService::debitAmount($websoccer, $db, $teamId, $totalPrice, "buy_stock_message", "sender_name");
        
        //deduct from available stock on from stockmarket table
        $stockmarketUpdateStr = "UPDATE ". $websoccer->getConfig("db_prefix") ."_stockmarket SET quantity=quantity-$qty WHERE id='$index'";
        $db->executeQuery($stockmarketUpdateStr);

        self::applyMajorityBoardControl($websoccer, $db, $index);
        
        return true;
    }

    /**
     * sell stock
     */
    public static function sellStock(WebSoccer $websoccer, DbConnection $db, $index, $qty, $teamId) {
    
        $index  = (int) $index;
        $qty    = (int) $qty;
        $teamId = (int) $teamId;
    
        if ($index < 1 || $qty < 1 || $teamId < 1) {
            return false;
        }
    
        // Check real owned quantity server-side.
        $ownedQty = (int) self::getQuantityFromUsersByIndex($websoccer, $db, $index, $teamId);
    
        if ($ownedQty < 1) {
            return false;
        }
    
        // Do not allow selling more than the user really owns.
        $qty = min($qty, $ownedQty);
    
        if ($qty < 1) {
            return false;
        }
    
        // Get current stock price.
        $indexPriceStr = "SELECT v1
                            FROM ". $websoccer->getConfig("db_prefix") ."_stockmarket
                           WHERE id='". $index ."'
                           LIMIT 1";
        $indexPrice = $db->executeQuery($indexPriceStr);
        $priceRow = $indexPrice->fetch_array();
        $indexPrice->free();
    
        if (!$priceRow || !isset($priceRow['v1'])) {
            return false;
        }
    
        $unitPrice = self::normalizePrice($priceRow['v1']);
    
        if ($unitPrice <= 0) {
            return false;
        }
    
        // Sale revenue minus 5%.
        $totalCredit = $unitPrice * $qty * 0.95;
    
        // Reduce portfolio rows correctly.
        // buyStock() inserts one row per purchase, so we must consume rows one by one.
        $remainingQty = $qty;
    
        $portfolioRowsStr = "SELECT id, qty
                               FROM ". $websoccer->getConfig("db_prefix") ."_user_stock
                              WHERE user_id='". $teamId ."'
                                AND stock_id='". $index ."'
                                AND qty > 0
                              ORDER BY id ASC";
        $portfolioRows = $db->executeQuery($portfolioRowsStr);
    
        while ($row = $portfolioRows->fetch_array()) {
            if ($remainingQty <= 0) {
                break;
            }
    
            $rowId  = (int) $row['id'];
            $rowQty = (int) $row['qty'];
    
            if ($rowId < 1 || $rowQty < 1) {
                continue;
            }
    
            $deductQty = min($remainingQty, $rowQty);
    
            if ($deductQty >= $rowQty) {
                $deleteRowStr = "DELETE FROM ". $websoccer->getConfig("db_prefix") ."_user_stock
                                  WHERE id='". $rowId ."'
                                  LIMIT 1";
                $db->executeQuery($deleteRowStr);
            } else {
                $updateRowStr = "UPDATE ". $websoccer->getConfig("db_prefix") ."_user_stock
                                    SET qty = qty - ". $deductQty ."
                                  WHERE id='". $rowId ."'
                                  LIMIT 1";
                $db->executeQuery($updateRowStr);
            }
    
            $remainingQty -= $deductQty;
        }
    
        $portfolioRows->free();
    
        // Safety check. Should not happen because ownedQty was checked above.
        if ($remainingQty > 0) {
            return false;
        }
    
        // Return sold shares to market availability.
        $stockmarketUpdateStr = "UPDATE ". $websoccer->getConfig("db_prefix") ."_stockmarket
                                    SET quantity = quantity + ". $qty ."
                                  WHERE id='". $index ."'";
        $db->executeQuery($stockmarketUpdateStr);
    
        // Credit money after successful portfolio update.
        BankAccountDataService::creditAmount(
            $websoccer,
            $db,
            $teamId,
            $totalCredit,
            "sell_stock_message",
            "sender_name"
        );
    
        // Extra cleanup, just in case older data already contains zero/negative rows.
        $cleanupStr = "DELETE FROM ". $websoccer->getConfig("db_prefix") ."_user_stock
                        WHERE user_id='". $teamId ."'
                          AND stock_id='". $index ."'
                          AND qty <= 0";
        $db->executeQuery($cleanupStr);
    
        self::applyMajorityBoardControl($websoccer, $db, $index);
    
        return true;
    }
    

    public static function hasFinancialAdvisorForStocks(WebSoccer $websoccer, DbConnection $db, $teamId) {
        return self::getFinancialAdvisorStockBonus($websoccer, $db, $teamId) > 0;
    }

    public static function getFinancialAdvisorStockBonus(WebSoccer $websoccer, DbConnection $db, $teamId) {
        if ((int) $teamId < 1 || !class_exists('ClubStaffDataService')) {
            return 0;
        }
        return (int) ClubStaffDataService::getRoleBonus($websoccer, $db, $teamId, ClubStaffDataService::ROLE_FINANCIAL_ADVISOR);
    }

    public static function applyStockRecommendations(WebSoccer $websoccer, DbConnection $db, $teamId, $indexes, $portfolioMode = false) {
        $teamId = (int) $teamId;
        $advisorBonus = self::getFinancialAdvisorStockBonus($websoccer, $db, $teamId);
        if ($advisorBonus <= 0 || !is_array($indexes)) {
            return $indexes;
        }

        foreach ($indexes as $key => $index) {
            $indexes[$key]['stock_recommendation'] = self::buildStockRecommendation($websoccer, $db, $teamId, $index, $advisorBonus, $portfolioMode);
        }
        return $indexes;
    }

    private static function buildStockRecommendation(WebSoccer $websoccer, DbConnection $db, $teamId, $index, $advisorBonus, $portfolioMode = false) {
        $score = 50;
        $reasons = array();
        $risk = 'medium';

        $v1 = self::normalizePrice(isset($index['v1']) ? $index['v1'] : 0);
        $v2 = self::normalizePrice(isset($index['v2']) ? $index['v2'] : 0);
        $v3 = self::normalizePrice(isset($index['v3']) ? $index['v3'] : 0);
        $v4 = self::normalizePrice(isset($index['v4']) ? $index['v4'] : 0);
        $trendScore = 0;

        if ($v1 > 0 && $v2 > 0) {
            if ($v1 > $v2) {
                $trendScore += 14;
                $reasons[] = 'stockmarket_recommend_reason_price_up';
            } elseif ($v1 < $v2) {
                $trendScore -= 14;
                $reasons[] = 'stockmarket_recommend_reason_price_down';
            } else {
                $reasons[] = 'stockmarket_recommend_reason_price_stable';
            }
        }
        if ($v2 > 0 && $v3 > 0) {
            $trendScore += ($v2 > $v3) ? 7 : (($v2 < $v3) ? -7 : 0);
        }
        if ($v3 > 0 && $v4 > 0) {
            $trendScore += ($v3 > $v4) ? 4 : (($v3 < $v4) ? -4 : 0);
        }
        $score += $trendScore;

        $avgReference = self::averagePositive(array($v2, $v3, $v4));
        if ($avgReference > 0 && $v1 > 0) {
            if ($v1 < ($avgReference * 0.94) && $trendScore >= 0) {
                $score += 6;
                $reasons[] = 'stockmarket_recommend_reason_recovery_chance';
            } elseif ($v1 > ($avgReference * 1.10)) {
                $score -= 5;
                $reasons[] = 'stockmarket_recommend_reason_price_high';
            }
        }

        $availableQty = isset($index['quantity']) ? (int) $index['quantity'] : 0;
        if ($availableQty > 0) {
            $score += 4;
        } else if (!$portfolioMode) {
            $score -= 20;
            $reasons[] = 'stockmarket_recommend_reason_no_available_shares';
        }

        $ownedPercent = isset($index['owned_percent']) ? (float) $index['owned_percent'] : 0.0;
        if ($ownedPercent >= 45 && $ownedPercent < 51) {
            $score += 7;
            $reasons[] = 'stockmarket_recommend_reason_majority_close';
        } elseif ($ownedPercent >= 51) {
            $score += 4;
            $reasons[] = 'stockmarket_recommend_reason_majority_control';
        }

        $club = self::getRecommendationClubData($websoccer, $db, isset($index['team_id']) ? (int) $index['team_id'] : 0);
        if ($club) {
            $clubBudget = isset($club['finanz_budget']) ? (int) $club['finanz_budget'] : 0;
            $clubStrength = isset($club['strength']) ? (int) $club['strength'] : 0;
            $board = isset($club['board_satisfaction']) ? (int) $club['board_satisfaction'] : 50;
            $fans = isset($club['fan_mood']) ? (int) $club['fan_mood'] : 50;

            if ($clubBudget > 5000000) {
                $score += 8;
                $reasons[] = 'stockmarket_recommend_reason_finance_strong';
            } elseif ($clubBudget < 0) {
                $score -= 12;
                $reasons[] = 'stockmarket_recommend_reason_finance_weak';
                $risk = 'high';
            }

            if ($clubStrength >= 82) {
                $score += 7;
                $reasons[] = 'stockmarket_recommend_reason_sport_strong';
            } elseif ($clubStrength > 0 && $clubStrength < 68) {
                $score -= 7;
                $reasons[] = 'stockmarket_recommend_reason_sport_weak';
                $risk = 'high';
            }

            if ($board >= 75 || $fans >= 75) {
                $score += 3;
            } elseif ($board <= 35 || $fans <= 35) {
                $score -= 4;
                $risk = 'high';
            }
        } else {
            $risk = 'medium';
        }

        $profitPercent = 0;
        if ($portfolioMode && isset($index['avg_price'])) {
            $avgBuy = self::normalizePrice($index['avg_price']);
            if ($avgBuy > 0 && $v1 > 0) {
                $profitPercent = (($v1 - $avgBuy) / $avgBuy) * 100;
                if ($profitPercent >= 15) {
                    $score -= 6;
                    $reasons[] = 'stockmarket_recommend_reason_profit_available';
                } elseif ($profitPercent <= -10) {
                    $score -= 4;
                    $reasons[] = 'stockmarket_recommend_reason_loss_position';
                    $risk = 'high';
                }
            }
        }

        if (count($reasons) < 1) {
            $reasons[] = 'stockmarket_recommend_reason_mixed_signals';
        }
        $reasons = array_values(array_unique($reasons));
        $reasons = array_slice($reasons, 0, min(3, max(1, (int) floor($advisorBonus / 2))));

        if ($portfolioMode) {
            if ($score >= 66 || ($ownedPercent >= 45 && $ownedPercent < 51)) {
                $action = 'hold';
                $css = 'success';
            } elseif ($score <= 42 || ($profitPercent >= 15 && $trendScore < 0)) {
                $action = 'sell';
                $css = 'important';
            } else {
                $action = 'watch';
                $css = 'warning';
            }
        } else {
            if ($availableQty <= 0) {
                $action = 'watch';
                $css = 'warning';
            } elseif ($score >= 68) {
                $action = 'buy';
                $css = 'success';
            } elseif ($score <= 40) {
                $action = 'avoid';
                $css = 'important';
            } else {
                $action = 'watch';
                $css = 'warning';
            }
        }

        $advisorQuality = min(20, $advisorBonus * 2);
        $confidence = (int) max(35, min(90, 50 + abs($score - 50) + $advisorQuality));
        if ($advisorBonus < 4) {
            $confidence = min($confidence, 65);
        }

        return array(
            'action' => $action,
            'action_key' => 'stockmarket_recommend_action_' . $action,
            'label_class' => $css,
            'confidence' => $confidence,
            'risk' => $risk,
            'risk_key' => 'stockmarket_recommend_risk_' . $risk,
            'reasons' => $reasons,
            'score' => (int) max(0, min(100, $score))
        );
    }

    private static function averagePositive($values) {
        $sum = 0;
        $count = 0;
        foreach ($values as $value) {
            $value = (float) $value;
            if ($value > 0) {
                $sum += $value;
                $count++;
            }
        }
        return ($count > 0) ? ($sum / $count) : 0;
    }

    private static function getRecommendationClubData(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $teamId = (int) $teamId;
        if ($teamId < 1) {
            return false;
        }

        $sqlStr = "SELECT id, finanz_budget, strength, board_satisfaction, fan_mood, platz
                     FROM ". $websoccer->getConfig("db_prefix") ."_verein
                    WHERE id='". $teamId ."'
                    LIMIT 1";
        $result = $db->executeQuery($sqlStr);
        $row = $result->fetch_array();
        $result->free();
        return ($row && isset($row['id'])) ? $row : false;
    }

    private static function normalizePrice($price) {
        return (float) str_replace(',', '.', (string) $price);
    }

    public static function getAvailableQuantity(WebSoccer $websoccer, DbConnection $db, $stockId) {
        $sqlStr = "SELECT quantity FROM ". $websoccer->getConfig("db_prefix") ."_stockmarket WHERE id='".(int) $stockId."'";
        $result = $db->executeQuery($sqlStr);
        $row = $result->fetch_array();
        $result->free();
        return ($row && isset($row['quantity'])) ? max(0, (int) $row['quantity']) : 0;
    }

    public static function getOwnershipPercent(WebSoccer $websoccer, DbConnection $db, $stockId, $teamId) {
        $stockId = (int) $stockId;
        $teamId = (int) $teamId;
        if ($stockId < 1 || $teamId < 1) {
            return 0;
        }

        $owned = (int) self::getQuantityFromUsersByIndex($websoccer, $db, $stockId, $teamId);
        $total = self::totalQtyByStockId($websoccer, $db, $stockId);
        if ($total < 1) {
            return 0;
        }
        return round(($owned / $total) * 100, 2);
    }

    public static function getMajorityStockOfTeam(WebSoccer $websoccer, DbConnection $db, $teamId, $excludeStockId = 0) {
        $sqlStr = "SELECT sm.id, sm.team_id, sm.name, SUM(us.qty) AS owned_qty,
                          (sm.quantity + COALESCE(total_owned.total_qty, 0)) AS total_qty
                   FROM ". $websoccer->getConfig("db_prefix") ."_user_stock AS us
                   INNER JOIN ". $websoccer->getConfig("db_prefix") ."_stockmarket AS sm ON sm.id = us.stock_id
                   LEFT JOIN (
                        SELECT stock_id, SUM(qty) AS total_qty
                        FROM ". $websoccer->getConfig("db_prefix") ."_user_stock
                        GROUP BY stock_id
                   ) AS total_owned ON total_owned.stock_id = sm.id
                   WHERE us.user_id = '".(int) $teamId."'
                     AND sm.team_id IS NOT NULL
                     AND sm.team_id > 0
                     AND sm.id != '".(int) $excludeStockId."'
                   GROUP BY sm.id, sm.team_id, sm.name, sm.quantity, total_owned.total_qty
                   HAVING total_qty > 0 AND (owned_qty / total_qty) >= 0.51
                   LIMIT 1";
        $result = $db->executeQuery($sqlStr);
        $row = $result->fetch_array();
        $result->free();
        return ($row && isset($row['id'])) ? $row : false;
    }

    public static function getMaxBuyQtyRespectingMajorityLimit(WebSoccer $websoccer, DbConnection $db, $stockId, $teamId) {
        $stockId = (int) $stockId;
        $teamId = (int) $teamId;
        if ($stockId < 1 || $teamId < 1) {
            return 0;
        }

        $stock = self::getStockMarketDataById($websoccer, $db, $stockId);
        if (!$stock || !isset($stock['id']) || empty($stock['team_id'])) {
            return -1;
        }

        $alreadyHasOtherMajority = self::getMajorityStockOfTeam($websoccer, $db, $teamId, $stockId);
        if (!$alreadyHasOtherMajority) {
            return -1;
        }

        $owned = (int) self::getQuantityFromUsersByIndex($websoccer, $db, $stockId, $teamId);
        $total = self::totalQtyByStockId($websoccer, $db, $stockId);
        if ($total < 1) {
            return 0;
        }

        // Keep this purchase below 51%, because this manager already controls another listed club.
        $maxAllowedOwnedQty = (int) floor(($total * 51 - 1) / 100);
        return max(0, $maxAllowedOwnedQty - $owned);
    }

    public static function canFireManagerByMajorityOwnership(WebSoccer $websoccer, DbConnection $db, $stockId, $ownerTeamId) {
        $stock = self::getStockMarketDataById($websoccer, $db, $stockId);
        if (!$stock || empty($stock['team_id'])) {
            return false;
        }

        $targetTeamId = (int) $stock['team_id'];
        $ownerTeamId = (int) $ownerTeamId;
        if ($targetTeamId < 1 || $ownerTeamId < 1 || $targetTeamId === $ownerTeamId) {
            return false;
        }

        if (self::getOwnershipPercent($websoccer, $db, $stockId, $ownerTeamId) < 51) {
            return false;
        }

        $sqlStr = "SELECT user_id FROM ". $websoccer->getConfig("db_prefix") ."_verein WHERE id='".$targetTeamId."' LIMIT 1";
        $result = $db->executeQuery($sqlStr);
        $team = $result->fetch_array();
        $result->free();

        return ($team && isset($team['user_id']) && (int) $team['user_id'] > 0);
    }

    public static function applyMajorityBoardControl(WebSoccer $websoccer, DbConnection $db, $stockId = 0) {
        $where = "sm.team_id IS NOT NULL AND sm.team_id > 0";
        if ((int) $stockId > 0) {
            $where .= " AND sm.id = '".(int) $stockId."'";
        }

        $sqlStr = "SELECT sm.id, sm.team_id, us.user_id AS owner_team_id, SUM(us.qty) AS owned_qty,
                          (sm.quantity + COALESCE(total_owned.total_qty, 0)) AS total_qty
                   FROM ". $websoccer->getConfig("db_prefix") ."_stockmarket AS sm
                   INNER JOIN ". $websoccer->getConfig("db_prefix") ."_user_stock AS us ON us.stock_id = sm.id
                   LEFT JOIN (
                        SELECT stock_id, SUM(qty) AS total_qty
                        FROM ". $websoccer->getConfig("db_prefix") ."_user_stock
                        GROUP BY stock_id
                   ) AS total_owned ON total_owned.stock_id = sm.id
                   WHERE ".$where."
                   GROUP BY sm.id, sm.team_id, us.user_id, sm.quantity, total_owned.total_qty
                   HAVING total_qty > 0 AND (owned_qty / total_qty) >= 0.51";
        $result = $db->executeQuery($sqlStr);
        while ($row = $result->fetch_array()) {
            if ((int) $row['owner_team_id'] === (int) $row['team_id']) {
                $db->executeQuery("UPDATE ". $websoccer->getConfig("db_prefix") ."_verein SET board_satisfaction = 100 WHERE id='".(int) $row['team_id']."'");
            }
        }
        $result->free();
    }

    public static function fireManagerByMajorityOwner(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $stockId, $ownerTeamId, $ownerUserId) {
        $stockId = (int) $stockId;
        $ownerTeamId = (int) $ownerTeamId;
        $ownerUserId = (int) $ownerUserId;
        if (!self::canFireManagerByMajorityOwnership($websoccer, $db, $stockId, $ownerTeamId)) {
            throw new Exception($i18n->getMessage('stockmarket_control_err_not_allowed'));
        }

        $stock = self::getStockMarketDataById($websoccer, $db, $stockId);
        $targetTeamId = (int) $stock['team_id'];

        $sqlStr = "SELECT id, name, user_id FROM ". $websoccer->getConfig("db_prefix") ."_verein WHERE id='".$targetTeamId."' LIMIT 1";
        $result = $db->executeQuery($sqlStr);
        $targetTeam = $result->fetch_array();
        $result->free();
        if (!$targetTeam || (int) $targetTeam['user_id'] < 1) {
            throw new Exception($i18n->getMessage('stockmarket_control_err_no_manager'));
        }

        $oldManagerUserId = (int) $targetTeam['user_id'];
        $db->executeQuery("UPDATE ". $websoccer->getConfig("db_prefix") ."_verein SET user_id = 0, user_id_actual = NULL, interimmanager = '0', board_satisfaction = 50 WHERE id='".$targetTeamId."'");
        PlayersDataService::resetUnsellableForTeam($websoccer, $db, $targetTeamId);

        $now = $websoccer->getNowAsTimestamp();
        $db->queryInsert(array(
            'empfaenger_id' => $oldManagerUserId,
            'absender_id' => $ownerUserId,
            'absender_name' => $i18n->getMessage('sender_name'),
            'datum' => $now,
            'betreff' => $i18n->getMessage('stockmarket_control_fired_subject'),
            'nachricht' => $i18n->getMessage('stockmarket_control_fired_message', $targetTeam['name']),
            'gelesen' => '0',
            'typ' => 'eingang'
        ), $websoccer->getConfig('db_prefix') . '_briefe');

        return true;
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
    

    /**
     * Returns stadium capacity from either the aliased StadiumsDataService result
     * (places_stands, places_seats, ...) or a raw cm23_stadion row (p_steh, p_sitz, ...).
     */
    private static function getStadiumCapacity($stadium) {
        if (!$stadium || !is_array($stadium)) {
            return 0;
        }

        $keys = array(
            array('places_stands', 'p_steh'),
            array('places_seats', 'p_sitz'),
            array('places_stands_grand', 'p_haupt_steh'),
            array('places_seats_grand', 'p_haupt_sitz'),
            array('places_vip', 'p_vip')
        );

        $capacity = 0;
        foreach ($keys as $pair) {
            $value = 0;
            if (isset($stadium[$pair[0]])) {
                $value = $stadium[$pair[0]];
            } elseif (isset($stadium[$pair[1]])) {
                $value = $stadium[$pair[1]];
            }
            $capacity += (int) $value;
        }

        return $capacity;
    }


    private static function normalizeTeamMarketValue($teamValue) {

        if (is_array($teamValue)) {
            if (isset($teamValue['team_marketvalue'])) {
                return (int) $teamValue['team_marketvalue'];
            }
            if (isset($teamValue[0])) {
                return (int) $teamValue[0];
            }
            return 0;
        }

        return (int) $teamValue;
    }

    private static function getTeamMarketValue(WebSoccer $websoccer, DbConnection $db, $teamId) {

        return self::normalizeTeamMarketValue(TeamsDataService::getTeamValue($websoccer, $db, $teamId));
    }

    private static function getConfiguredStartingValue() {

        global $conf;
        $startingValue = isset($conf['default_starting_value']) ? (int) $conf['default_starting_value'] : 50;

        if ($startingValue < 1) {
            $startingValue = 50;
        }

        return $startingValue;
    }

    /**
     * Returns display data for the IPO/listing box on the finances page.
     * Existing clubStockmarketCriteria() is intentionally kept for backward compatibility:
     * it returns TRUE if criteria are NOT met and FALSE if the club may be listed.
     */
    public static function getClubStockmarketListingInfo(WebSoccer $websoccer, DbConnection $db, $teamId) {

        global $conf;
        $teamId = (int) $teamId;
        $clubValue = (int) round(self::clubValue($websoccer, $db, $teamId), 0);
        $initialPrice = self::getConfiguredStartingValue();
        $totalShares = ($initialPrice > 0) ? max(1, (int) round($clubValue / $initialPrice, 0)) : 0;

        // Keep the old stockmarket mechanics: quantity is the amount of shares that can be bought.
        // The IPO cash is intentionally equal to the old full club value unless changed later by balancing.
        $ipoIncome = $clubValue;

        $minStadiumSize = isset($conf['min_stadium_size']) ? (int) $conf['min_stadium_size'] : 0;
        $minTeamValue = isset($conf['min_team_value']) ? (int) $conf['min_team_value'] : 0;
        $minTitlesWon = isset($conf['min_team_titles_won']) ? (int) $conf['min_team_titles_won'] : 0;

        $listedStock = self::checkClubOnStockmarket($websoccer, $db, $teamId);

        $stadium = StadiumsDataService::getStadiumByTeamId($websoccer, $db, $teamId);
        $stadiumSize = self::getStadiumCapacity($stadium);

        $teamMarketValue = self::getTeamMarketValue($websoccer, $db, $teamId);
        $titles = TeamsDataService::getNumberTeamTitlesWon($websoccer, $db, $teamId);
        $titlesWon = is_array($titles) ? count($titles) : (int) $titles;

        $requirements = array(
            array(
                'key' => 'not_listed',
                'label_key' => 'stockmarket_ipo_req_not_listed',
                'required' => 1,
                'actual' => $listedStock ? 0 : 1,
                'met' => !$listedStock
            ),
            array(
                'key' => 'stadium_size',
                'label_key' => 'stockmarket_ipo_req_stadium_size',
                'required' => $minStadiumSize,
                'actual' => $stadiumSize,
                'met' => ($stadiumSize >= $minStadiumSize)
            ),
            array(
                'key' => 'team_value',
                'label_key' => 'stockmarket_ipo_req_team_value',
                'required' => $minTeamValue,
                'actual' => $teamMarketValue,
                'met' => ($teamMarketValue >= $minTeamValue)
            ),
            array(
                'key' => 'titles_won',
                'label_key' => 'stockmarket_ipo_req_titles_won',
                'required' => $minTitlesWon,
                'actual' => $titlesWon,
                'met' => ($titlesWon >= $minTitlesWon)
            )
        );

        $criteriaMet = true;
        foreach ($requirements as $requirement) {
            if (!$requirement['met']) {
                $criteriaMet = false;
                break;
            }
        }

        return array(
            'is_listed' => ($listedStock ? true : false),
            'stock_id' => ($listedStock && isset($listedStock['id'])) ? (int) $listedStock['id'] : 0,
            'criteria_met' => $criteriaMet,
            'requirements' => $requirements,
            'club_value' => $clubValue,
            'ipo_income' => $ipoIncome,
            'initial_price' => $initialPrice,
            'shares' => $totalShares
        );
    }

    /*
     * Check club stockmarket criteria
     */
    public static function clubStockmarketCriteria(WebSoccer $websoccer, DbConnection $db, $teamId) {
        
        global $conf;
        $criteria_not_met = 0;
        
        //GET GLOBAL SETTINGS
        $min_stadium_size = isset($conf["min_stadium_size"]) ? (int) $conf["min_stadium_size"] : 0;
        $min_team_value = isset($conf["min_team_value"]) ? (int) $conf["min_team_value"] : 0;
        $active_credits = isset($conf["active_credits"]) ? $conf["active_credits"] : 0;
        $min_team_titles_won = isset($conf["min_team_titles_won"]) ? (int) $conf["min_team_titles_won"] : 0;
        
        //CHECK IF ALREADY ON STOCKMARKET
        $onStockmarket = self::checkClubOnStockmarket($websoccer, $db, $teamId);
        if($onStockmarket==true) {
            $criteria_not_met++;
        }
        
        //CHECK IF ACTIVE CREDITS
        //NOT SET YET
        
        //GET STADIUM SIZE >=25.000
        $stadium = StadiumsDataService::getStadiumByTeamId($websoccer, $db, $teamId);
        $stadium_size = self::getStadiumCapacity($stadium);
        
        if($stadium_size<$min_stadium_size) {
            $criteria_not_met++;
        }
        
        //GET TEAM VALUE >= 25.000.000
        $team_marketvalue = self::getTeamMarketValue($websoccer, $db, $teamId);
        if($team_marketvalue<$min_team_value) {
            $criteria_not_met++;
        }
        
        //GET HISTORY Championships, Cups, etc. --> admin setting
        $titles = TeamsDataService::getNumberTeamTitlesWon($websoccer, $db, $teamId);
        $titles_count = is_array($titles) ? count($titles) : (int) $titles;
        if($titles_count < $min_team_titles_won) {
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
        $team_marketvalue = self::normalizeTeamMarketValue(TeamsDataService::getTeamValueByTeamId($websoccer, $db, $teamId));
        
        //GET STADIUM SIZE + VALUE
        $stadium = StadiumsDataService::getStadiumByTeamId($websoccer, $db, $teamId);
        $stadium_size = self::getStadiumCapacity($stadium);
        $stadium_value = $stadium_size*100000;
        
        //GET CLUB TITLES WON
        $titles = TeamsDataService::getNumberTeamTitlesWon($websoccer, $db, $teamId);
        $titles_count = is_array($titles) ? count($titles) : (int) $titles;
        $titles_value = $titles_count * 1000000;
        
        //GET PORTFOLIO VALUE
        $portfolio_value = self::getPortfolioValue($websoccer, $db, $teamId);
        $portfolio_value = $portfolio_value*0.5;
        
        $total_club_value = $finance_budget+$team_marketvalue+($stadium_value*0.1)+$titles_value+$portfolio_value;
        
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
    
    public static function processSeasonDividends(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $teamId, $seasonId) {
        $teamId = (int) $teamId;
        $seasonId = (int) $seasonId;
        if ($teamId < 1 || $seasonId < 1 || !self::tableExists($websoccer, $db, 'stockmarket_dividend')) {
            return 0;
        }

        $stock = self::checkClubOnStockmarket($websoccer, $db, $teamId);
        if (!$stock || !isset($stock['id'])) {
            return 0;
        }
        $stockId = (int) $stock['id'];

        $existing = $db->querySelect('id', $websoccer->getConfig('db_prefix') . '_stockmarket_dividend', 'stock_id = %d AND team_id = %d AND season_id = %d', array($stockId, $teamId, $seasonId), 1);
        $existingRow = $existing->fetch_array();
        $existing->free();
        if ($existingRow) {
            return 0;
        }

        $profit = self::getSeasonProfit($websoccer, $db, $teamId, $seasonId);
        if ($profit <= 0) {
            return 0;
        }

        $budgetResult = $db->querySelect('finanz_budget', $websoccer->getConfig('db_prefix') . '_verein', 'id = %d', $teamId, 1);
        $budgetRow = $budgetResult->fetch_array();
        $budgetResult->free();
        $budget = ($budgetRow && isset($budgetRow['finanz_budget'])) ? (int) $budgetRow['finanz_budget'] : 0;
        if ($budget <= 0) {
            return 0;
        }

        $dividendPool = (int) floor($profit * 0.10);
        $dividendPool = min($dividendPool, (int) floor($budget * 0.20));
        if ($dividendPool < 1) {
            return 0;
        }

        $totalShares = self::totalQtyByStockId($websoccer, $db, $stockId);
        if ($totalShares < 1) {
            return 0;
        }

        $dividendPerShare = $dividendPool / $totalShares;
        $now = $websoccer->getNowAsTimestamp();

        BankAccountDataService::debitAmount($websoccer, $db, $teamId, $dividendPool, 'stockmarket_dividend_payment', 'sender_name');

        $db->queryInsert(array(
            'stock_id' => $stockId,
            'team_id' => $teamId,
            'season_id' => $seasonId,
            'dividend_pool' => $dividendPool,
            'dividend_per_share' => $dividendPerShare,
            'created_date' => $now
        ), $websoccer->getConfig('db_prefix') . '_stockmarket_dividend');

        $dividendIdResult = $db->querySelect('id', $websoccer->getConfig('db_prefix') . '_stockmarket_dividend', 'stock_id = %d AND team_id = %d AND season_id = %d', array($stockId, $teamId, $seasonId), 1);
        $dividendRow = $dividendIdResult->fetch_array();
        $dividendIdResult->free();
        $dividendId = ($dividendRow && isset($dividendRow['id'])) ? (int) $dividendRow['id'] : 0;

        $holdersSql = "SELECT user_id AS team_id, SUM(qty) AS shares
                       FROM ". $websoccer->getConfig('db_prefix') ."_user_stock
                       WHERE stock_id='".$stockId."'
                       GROUP BY user_id
                       HAVING shares > 0";
        $holders = $db->executeQuery($holdersSql);
        while ($holder = $holders->fetch_array()) {
            $holderTeamId = (int) $holder['team_id'];
            $shares = (int) $holder['shares'];
            $amount = (int) floor($shares * $dividendPerShare);
            if ($holderTeamId > 0 && $amount > 0) {
                BankAccountDataService::creditAmount($websoccer, $db, $holderTeamId, $amount, 'stockmarket_dividend_income', 'sender_name');
                if ($dividendId > 0 && self::tableExists($websoccer, $db, 'stockmarket_dividend_payment')) {
                    $db->queryInsert(array(
                        'dividend_id' => $dividendId,
                        'user_id' => $holderTeamId,
                        'shares' => $shares,
                        'amount' => $amount,
                        'created_date' => $now
                    ), $websoccer->getConfig('db_prefix') . '_stockmarket_dividend_payment');
                }
            }
        }
        $holders->free();

        return $dividendPool;
    }

    private static function getSeasonProfit(WebSoccer $websoccer, DbConnection $db, $teamId, $seasonId) {
        $sqlStr = "SELECT MIN(datum) AS date_from, MAX(datum) AS date_to
                   FROM ". $websoccer->getConfig('db_prefix') ."_spiel
                   WHERE saison_id='".(int) $seasonId."'
                     AND (home_verein='".(int) $teamId."' OR gast_verein='".(int) $teamId."')";
        $result = $db->executeQuery($sqlStr);
        $range = $result->fetch_array();
        $result->free();
        if (!$range || empty($range['date_from']) || empty($range['date_to'])) {
            return 0;
        }

        $sqlStr = "SELECT COALESCE(SUM(betrag), 0) AS profit
                   FROM ". $websoccer->getConfig('db_prefix') ."_konto
                   WHERE verein_id='".(int) $teamId."'
                     AND datum >= '".(int) $range['date_from']."'
                     AND datum <= '".(int) $range['date_to']."'";
        $result = $db->executeQuery($sqlStr);
        $profit = $result->fetch_array();
        $result->free();
        return ($profit && isset($profit['profit'])) ? (int) $profit['profit'] : 0;
    }

    private static function tableExists(WebSoccer $websoccer, DbConnection $db, $tableNameWithoutPrefix) {
        $tableName = str_replace('`', '', $websoccer->getConfig('db_prefix') . '_' . $tableNameWithoutPrefix);
        $result = $db->executeQuery("SHOW TABLES LIKE '".$tableName."'");
        $row = $result->fetch_array();
        $result->free();
        return ($row) ? true : false;
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