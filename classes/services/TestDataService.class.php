<?php

class TestDataService {
    
    const MAX_SQUAD_SIZE = 23;
    const MAX_OFFERS = 3;
    const TRANSFER_DURATION_DAYS = 7;
    
    private static $logger;
    
    public static function setLogger($logger) {
        self::$logger = $logger;
    }
    
    public static function executeComputerBids(WebSoccer $websoccer, DbConnection $db) {
        
        $now = $websoccer->getNowAsTimestamp();
        $teamIds = self::getComputerControlledTeams($websoccer, $db);
        
        foreach ($teamIds as $teamId) {
            
            //self::$logger->info("Executing bids for team: $teamId");
            
            $budget = self::getTeamBudget($websoccer, $db, $teamId);
            $currentOffers = self::getTeamOfferCount($websoccer, $db, $teamId);
            $squad = self::getTeamSquad($websoccer, $db, $teamId);
            
            // Calculate average team strength
            $teamStrength = self::calculateAverageStrength($squad);
            
            // Manage transfer list if squad exceeds MAX_SQUAD_SIZE players
            if (count($squad) > self::MAX_SQUAD_SIZE) {
                self::manageTransferList($websoccer, $db, $teamId, $squad);
            }
            
            // Skip bidding if the team already has MAX_OFFERS offers
            if ($currentOffers >= self::MAX_OFFERS) continue;
            
            $playersOnMarket = self::getPlayersOnTransferMarket($websoccer, $db);
            
            foreach ($playersOnMarket as $player) {
                $playerStrength = self::calculatePlayerStrength($player);
                
                // Check player strength range
                if (!self::isStrengthWithinRange($playerStrength, $teamStrength)) continue;
                
                // Calculate bid amount and place bid
                $bidAmount = self::calculateBidAmount($player);
                $salaryAmount = self::calculateSalary($player);
                $goalAmount = self::calculateGoal($goal);
                
                if ($bidAmount <= $budget) {
                    self::placeBid($websoccer, $db, $teamId, $player['id'], $bidAmount, $now, $salaryAmount, $goalAmount);
                    $currentOffers++;
                    if ($currentOffers >= self::MAX_OFFERS) break;
                }
            }
        }
    }
    
    private static function getComputerControlledTeams(WebSoccer $websoccer, DbConnection $db) {
        $query = "
            SELECT id
            FROM ". $websoccer->getConfig('db_prefix') ."_verein
            WHERE user_id IS NULL OR user_id <= '0'
            ORDER BY RAND()
            LIMIT 1";
        $result = $db->executeQuery($query);
        
        if (!$result) {
            throw new Exception("Database query failed: " . $db->error());
        }
        
        $teamIds = [];
        while ($team = $result->fetch_array()) {
            $teamIds[] = $team['id'];
        }
        $result->free();
        return $teamIds;
    }
    
    private static function getTeamBudget(WebSoccer $websoccer, DbConnection $db, $teamId) {
        
        $sqlStr = "SELECT finanz_budget
                    FROM ". $websoccer->getConfig("db_prefix") ."_verein
                    WHERE id='$teamId'";
        $result = $db->executeQuery($sqlStr);
        $budget = $result->fetch_array();
        $result->free();
        
        return $budget['finanz_budget'];
        
    }
    
    private static function getTeamOfferCount(WebSoccer $websoccer, DbConnection $db, $teamId) {
        
        $sqlStr = "SELECT COUNT(*) AS offer_count
                    FROM ". $websoccer->getConfig("db_prefix") ."_transfer_angebot
                    WHERE verein_id='$teamId'";
        $result = $db->executeQuery($sqlStr);
        $offers = $result->fetch_assoc();
        $result->free();
        
        return $offers['offer_count'];
        
    }
    
    private static function getTeamSquad(WebSoccer $websoccer, DbConnection $db, $teamId) {
        
        $squad = [];
        
        $sqlStr = "SELECT id, position, w_technik, w_staerke, w_kondition, w_frische
                    FROM ". $websoccer->getConfig("db_prefix") ."_spieler
                    WHERE verein_id = '$teamId'";
        $result = $db->executeQuery($sqlStr);
        //while ($player = $result->fetch_array())
        while ($player = $result->fetch_assoc()) {
            $squad[] = $player;
        }
        $result->free();
        
        return $squad;
    }
    
    private static function getPlayersOnTransferMarket(WebSoccer $websoccer, DbConnection $db) {
        
        $players = [];
        
        $query = "
            SELECT id, w_technik, w_staerke, w_kondition, w_frische, transfer_mindestgebot, vertrag_gehalt, vertrag_torpraemie
            FROM ". $websoccer->getConfig('db_prefix') ."_spieler
            WHERE transfermarkt = 1";
        $result = $db->executeQuery($query);
        
        while ($player = $result->fetch_assoc()) {
            $players[] = $player;
        }
        return $players;
    }
    
    private static function calculateAverageStrength($squad) {
        $totalStrength = 0;
        $count = 0;
        foreach ($squad as $player) {
            $totalStrength += ($player['w_technik'] + $player['w_staerke'] + $player['w_kondition'] + $player['w_frische']) / 4;
            $count++;
        }
        return $count > 0 ? $totalStrength / $count : 0;
    }
    
    private static function calculatePlayerStrength($player) {
        return ($player['w_technik'] + $player['w_staerke'] + $player['w_kondition'] + $player['w_frische']) / 4;
    }
    
    private static function isStrengthWithinRange($playerStrength, $teamStrength) {
        $lowerBound = $teamStrength * 0.9;
        $upperBound = $teamStrength * 1.1;
        return $playerStrength >= $lowerBound && $playerStrength <= $upperBound;
    }
    
    private static function calculateBidAmount($player) {
        
        //offer
        $baseAmount = $player['transfer_mindestgebot'];
        $adjustment = $baseAmount * (rand(-10, 10) / 100);
        
        return $baseAmount + $adjustment;
    }
    
    private static function calculateSalary($player) {
        
        //salary
        $baseSalary = $player['vertrag_gehalt'];
        $adjustSalary = $baseSalary * (rand(-10, 10) / 100);
        
        return $baseSalary + $adjustSalary;
    }
    
    private static function calculateGoal($player) {
        
        //goal prime
        $baseGoal = $player['vertrag_torpraemie'];
        $adjustGoal = $baseGoal * (rand(-10, 10) / 100);
        
        return $baseGoal + $adjustGoal;
    }
    
    private static function placeBid(WebSoccer $websoccer, DbConnection $db, $teamId, $playerId, $bidAmount, $now, $salaryAmount, $goalAmount) {
        
        $insStr = "INSERT INTO ". $websoccer->getConfig('db_prefix') . "_transfer_angebot
                    (spieler_id, verein_id, abloese, vertrag_spiele, datum, vertrag_gehalt, vertrag_torpraemie)
                    VALUES ('$playerId', '$teamId', '$bidAmount', '60', '$now', '$salaryAmount', '$goalAmount')";
        $db->executeQuery($insStr);
    }
    
    private static function manageTransferList(WebSoccer $websoccer, DbConnection $db, $teamId, $squad) {
        $positionsCount = self::countPositions($squad);
        $currentTransferList = self::getTeamTransferList($websoccer, $db, $teamId);
        
        if (count($currentTransferList) >= 3) return;
        
        $transferCandidates = [];
        foreach ($squad as $player) {
            if (self::canListPlayerForTransfer($player, $positionsCount)) {
                $transferCandidates[] = $player;
            }
        }
        
        shuffle($transferCandidates);
        foreach ($transferCandidates as $player) {
            if (count($currentTransferList) >= 3) break;
            self::listPlayerForTransfer($websoccer, $db, $player['id']);
            $currentTransferList[] = $player;
        }
    }
    
    private static function countPositions($squad) {
        $counts = [
            'Torwart' => 0,
            'Abwehr' => 0,
            'Mittelfeld' => 0,
            'Sturm' => 0
        ];
        foreach ($squad as $player) {
            if (isset($counts[$player['position']])) {
                $counts[$player['position']]++;
            }
        }
        return $counts;
    }
    
    private static function canListPlayerForTransfer($player, $positionsCount) {
        $position = $player['position'];
        switch ($position) {
            case 'Torwart':
                return $positionsCount['Torwart'] > 2;
            case 'Abwehr':
                return $positionsCount['Abwehr'] > 5;
            case 'Mittelfeld':
                return $positionsCount['Mittelfeld'] > 5;
            case 'Sturm':
                return $positionsCount['Sturm'] > 5;
            default:
                return false;
        }
    }
    
    private static function getTeamTransferList(WebSoccer $websoccer, DbConnection $db, $teamId) {
        
        $transferList = [];
        
        $query = "
            SELECT id, position
            FROM ". $websoccer->getConfig('db_prefix') ."_spieler
            WHERE verein_id ='$teamId' AND transfermarkt = 1";
        $result = $db->executeQuery($query);
        while ($player = $result->fetch_assoc()) {
            $transferList[] = $player;
        }
        return $transferList;
    }
    
    private static function listPlayerForTransfer(WebSoccer $websoccer, DbConnection $db, $playerId) {
        
        $transfer_start = $websoccer->getNowAsTimestamp();
        $transfer_ende = $websoccer->getNowAsTimestamp() + (self::TRANSFER_DURATION_DAYS * 24 * 60 * 60);
        
        $updStr = "UPDATE ". $websoccer->getConfig('db_prefix') . "_spieler
                    SET transfermarkt='1', transfer_start='".$transfer_start."',
                            transfer_ende='".$transfer_ende."'
                    WHERE id='$playerId'";
        $db->executeQuery($updStr);
        
    }
    
    
    public static function huhu2() {
        echo"huhu2<br>";
    }
}

