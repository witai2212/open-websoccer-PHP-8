<?php
/** Vorverträge für ablösefreie Wechsel zum globalen Saisonwechsel. */
class PlayerPrecontractDataService {
    const STATUS_OPEN = 'open';
    const STATUS_ACCEPTED = 'accepted';

    public static function isEligible(WebSoccer $websoccer, DbConnection $db, $playerId) {
        $limit = (int) $websoccer->getConfig('contract_max_number_of_remaining_matches');
        if ($limit < 1) return false;
        $r=$db->querySelect('verein_id, vertrag_spiele, status', $websoccer->getConfig('db_prefix').'_spieler', 'id = %d', (int)$playerId, 1);
        $p=$r->fetch_array(); $r->free();
        return $p && $p['status']=='1' && (int)$p['verein_id']>0 && (int)$p['vertrag_spiele'] < $limit;
    }

    public static function hasAcceptedAgreement(WebSoccer $websoccer, DbConnection $db, $playerId) {
        $r=$db->querySelect('id', $websoccer->getConfig('db_prefix').'_player_precontract', "player_id = %d AND status = 'accepted'", (int)$playerId, 1);
        $row=$r->fetch_array(); $r->free(); return (bool)$row;
    }

    public static function getAcceptedByPlayer(WebSoccer $websoccer, DbConnection $db, $playerId) {
        $prefix=$websoccer->getConfig('db_prefix');
        $sql="SELECT A.*, T.name AS destination_team_name FROM {$prefix}_player_precontract A INNER JOIN {$prefix}_verein T ON T.id=A.destination_team_id WHERE A.player_id=".(int)$playerId." AND A.status='accepted' LIMIT 1";
        $r=$db->executeQuery($sql); $row=$r->fetch_array(); $r->free(); return $row ?: array();
    }

    public static function getOfferByPlayerAndTeam(WebSoccer $websoccer, DbConnection $db, $playerId, $teamId) {
        if ((int) $playerId < 1 || (int) $teamId < 1) return array();
        $prefix = $websoccer->getConfig('db_prefix');
        $sql = "SELECT A.*, T.name AS destination_team_name FROM {$prefix}_player_precontract A INNER JOIN {$prefix}_verein T ON T.id = A.destination_team_id WHERE A.player_id = ".(int)$playerId." AND A.destination_team_id = ".(int)$teamId." AND A.status IN ('open','accepted') ORDER BY A.id DESC LIMIT 1";
        $result = $db->executeQuery($sql);
        $row = $result->fetch_array();
        $result->free();
        return $row ?: array();
    }

    public static function getOpenOfferCount(WebSoccer $websoccer, DbConnection $db, $playerId) {
        $prefix = $websoccer->getConfig('db_prefix');
        $result = $db->querySelect('COUNT(*) AS offer_count', $prefix.'_player_precontract', "player_id = %d AND status = 'open'", (int)$playerId, 1);
        $row = $result->fetch_array();
        $result->free();
        return $row ? (int) $row['offer_count'] : 0;
    }

    public static function getIncoming(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $prefix=$websoccer->getConfig('db_prefix');
        $sql="SELECT A.*, P.vorname AS firstname, P.nachname AS lastname, P.kunstname AS pseudonym, P.position, P.position_main, P.geburtstag, P.w_staerke AS strength, P.marktwert AS marketvalue, T.name AS current_team_name FROM {$prefix}_player_precontract A INNER JOIN {$prefix}_spieler P ON P.id=A.player_id LEFT JOIN {$prefix}_verein T ON T.id=A.current_team_id WHERE A.destination_team_id=".(int)$teamId." AND A.status='accepted' ORDER BY P.position, P.nachname";
        $r=$db->executeQuery($sql); $rows=array(); while($x=$r->fetch_array()){$x['age']=(int)date('Y')-(int)substr($x['geburtstag'],0,4);$rows[]=$x;} $r->free(); return $rows;
    }

    public static function placeOffer(WebSoccer $websoccer, DbConnection $db, $playerId, $teamId, $userId, $salary, $goalBonus, $handMoney, $contractMatches, $isComputer=false) {
        if (!self::isEligible($websoccer,$db,$playerId)) throw new Exception('Der Spieler kann noch nicht für die nächste Saison angesprochen werden.');
        if (self::hasAcceptedAgreement($websoccer,$db,$playerId)) throw new Exception('Der Spieler hat bereits einen Vertrag für die nächste Saison unterschrieben.');
        $max=(int)$websoccer->getConfig('max_number_of_contract_matches'); if($max<1)$max=60;
        $contractMatches=max(20,min($max,(int)$contractMatches));
        $prefix=$websoccer->getConfig('db_prefix');
        $r=$db->querySelect('verein_id, vertrag_gehalt, vertrag_torpraemie, marktwert', $prefix.'_spieler', 'id = %d', (int)$playerId, 1); $p=$r->fetch_array();$r->free();
        if(!$p || (int)$p['verein_id']==(int)$teamId) throw new Exception('Ein Verein kann dem eigenen Spieler keinen Vorvertrag anbieten.');
        $salary=max((int)$salary,(int)ceil($p['vertrag_gehalt']*1.05));
        $goalBonus=max((int)$goalBonus,(int)$p['vertrag_torpraemie']);
        $handMoney=max(0,(int)$handMoney);
        $waitMin=max(1,(int)$websoccer->getConfig('precontract_decision_matches_min')); $waitMax=max($waitMin,(int)$websoccer->getConfig('precontract_decision_matches_max'));
        $existing=$db->querySelect('id', $prefix.'_player_precontract', "player_id = %d AND destination_team_id = %d AND status = 'open'", array((int)$playerId,(int)$teamId),1);$e=$existing->fetch_array();$existing->free();
        $columns=array('player_id'=>(int)$playerId,'current_team_id'=>(int)$p['verein_id'],'destination_team_id'=>(int)$teamId,'destination_user_id'=>(int)$userId,'contract_salary'=>$salary,'contract_goal_bonus'=>$goalBonus,'hand_money'=>$handMoney,'contract_matches'=>$contractMatches,'created_date'=>$websoccer->getNowAsTimestamp(),'decision_after_matches'=>mt_rand($waitMin,$waitMax),'waited_matches'=>0,'is_computer'=>$isComputer?'1':'0','status'=>'open');
        if($e){$db->queryUpdate($columns,$prefix.'_player_precontract','id = %d',(int)$e['id']);return (int)$e['id'];}
        $db->queryInsert($columns,$prefix.'_player_precontract'); return $db->getLastInsertedId();
    }

    public static function processOpenOffers(WebSoccer $websoccer, DbConnection $db) {
        $prefix=$websoccer->getConfig('db_prefix');
        $db->executeQuery("UPDATE {$prefix}_player_precontract SET waited_matches=waited_matches+1 WHERE status='open'");
        $sql="SELECT player_id FROM {$prefix}_player_precontract WHERE status='open' GROUP BY player_id HAVING MAX(waited_matches >= decision_after_matches)=1";
        $r=$db->executeQuery($sql);$ids=array();while($x=$r->fetch_array())$ids[]=(int)$x['player_id'];$r->free();
        foreach($ids as $playerId) self::decidePlayer($websoccer,$db,$playerId);
        return count($ids);
    }

    private static function decidePlayer(WebSoccer $websoccer, DbConnection $db, $playerId) {
        if (self::hasAcceptedAgreement($websoccer, $db, $playerId)) return;
        $prefix = $websoccer->getConfig('db_prefix');
        $sql = "SELECT A.*, T.strength AS club_strength, T.finanz_budget, L.division FROM {$prefix}_player_precontract A INNER JOIN {$prefix}_verein T ON T.id=A.destination_team_id LEFT JOIN {$prefix}_liga L ON L.id=T.liga_id WHERE A.player_id=".(int)$playerId." AND A.status='open'";
        $r = $db->executeQuery($sql);
        $offers = array();
        while ($o = $r->fetch_array()) {
            $league = max(1, 8 - (int) $o['division']);
            $o['score'] = (int) $o['hand_money'] + ((int) $o['contract_salary'] * (int) $o['contract_matches']) + ((int) $o['contract_goal_bonus'] * 8) + ((int) $o['club_strength'] * 25000) + ($league * 100000);
            $offers[] = $o;
        }
        $r->free();
        if (!$offers) return;
        usort($offers, function($a, $b) { return $b['score'] <=> $a['score']; });
        $best = $offers[0];
        $now = $websoccer->getNowAsTimestamp();
        $db->queryUpdate(array('status' => 'accepted', 'decision_date' => $now), $prefix.'_player_precontract', 'id = %d', (int) $best['id']);
        $db->executeQuery("UPDATE {$prefix}_player_precontract SET status='rejected', decision_date=".(int)$now." WHERE player_id=".(int)$playerId." AND status='open'");

        foreach ($offers as $offer) {
            if ((int) $offer['destination_user_id'] < 1) continue;
            TransferMessagesDataService::createPrecontractMessage(
                $websoccer,
                $db,
                (int) $offer['destination_user_id'],
                ((int) $offer['id'] === (int) $best['id']) ? 'accepted' : 'rejected',
                $playerId,
                (int) $offer['current_team_id'],
                (int) $offer['destination_team_id'],
                array(
                    'hand_money' => (int) $offer['hand_money'],
                    'contract_matches' => (int) $offer['contract_matches'],
                    'contract_salary' => (int) $offer['contract_salary'],
                    'contract_goal_bonus' => (int) $offer['contract_goal_bonus']
                )
            );
        }
    }

    public static function executeAcceptedTransfers(WebSoccer $websoccer, DbConnection $db) {
        $prefix = $websoccer->getConfig('db_prefix');
        $r = $db->executeQuery("SELECT * FROM {$prefix}_player_precontract WHERE status='accepted'");
        $count = 0;
        while ($a = $r->fetch_array()) {
            $destination = TeamsDataService::getTeamSummaryById($websoccer, $db, (int) $a['destination_team_id']);
            $current = TeamsDataService::getTeamSummaryById($websoccer, $db, (int) $a['current_team_id']);
            if (!$destination) continue;
            BankAccountDataService::debitAmount($websoccer, $db, (int) $a['destination_team_id'], (int) $a['hand_money'], 'Handgeld für ablösefreien Wechsel', 'Spieler');
            $db->queryUpdate(array('verein_id'=>(int)$a['destination_team_id'],'vertrag_gehalt'=>(int)$a['contract_salary'],'vertrag_torpraemie'=>(int)$a['contract_goal_bonus'],'vertrag_spiele'=>(int)$a['contract_matches'],'transfermarkt'=>'0','transfer_start'=>0,'transfer_ende'=>0,'transfer_mindestgebot'=>0,'lending_fee'=>0), $prefix.'_spieler', 'id = %d', (int) $a['player_id']);
            $db->queryInsert(array('spieler_id'=>(int)$a['player_id'],'seller_user_id'=>!empty($current['user_id'])?(int)$current['user_id']:0,'seller_club_id'=>(int)$a['current_team_id'],'buyer_user_id'=>(int)$a['destination_user_id'],'buyer_club_id'=>(int)$a['destination_team_id'],'datum'=>$websoccer->getNowAsTimestamp(),'directtransfer_amount'=>0), $prefix.'_transfer');
            $details = array('hand_money'=>(int)$a['hand_money'],'contract_matches'=>(int)$a['contract_matches'],'contract_salary'=>(int)$a['contract_salary'],'contract_goal_bonus'=>(int)$a['contract_goal_bonus']);
            if (!empty($current['user_id'])) {
                TransferMessagesDataService::createPrecontractMessage($websoccer, $db, (int) $current['user_id'], 'completed', (int) $a['player_id'], (int) $a['current_team_id'], (int) $a['destination_team_id'], $details);
            }
            if ((int) $a['destination_user_id'] > 0) {
                TransferMessagesDataService::createPrecontractMessage($websoccer, $db, (int) $a['destination_user_id'], 'completed', (int) $a['player_id'], (int) $a['current_team_id'], (int) $a['destination_team_id'], $details);
            }
            TransferMessagesDataService::createMajorTransferNewsForPlayer($websoccer, $db, (int) $a['player_id'], (int) $a['current_team_id'], (int) $a['destination_team_id'], 0);
            $db->queryUpdate(array('status'=>'completed','completed_date'=>$websoccer->getNowAsTimestamp()), $prefix.'_player_precontract', 'id = %d', (int) $a['id']);
            $count++;
        }
        $r->free();
        return $count;
    }
    
    public static function getCurrentPreContractOffers(WebSoccer $websoccer, DbConnection $db, $teamId) {
        
        $prefix=$websoccer->getConfig('db_prefix');
        $sql="SELECT cCOUNT(*) as offers FROM {$prefix}_player_precontract WHERE A.destination_team_id=".(int)$teamId."";
        $r=$db->executeQuery($sql); $rows=array();
        $r->free();
        
        return $r;
        
    }

    public static function createComputerOffers(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $prefix=$websoccer->getConfig('db_prefix');$limit=(int)$websoccer->getConfig('contract_max_number_of_remaining_matches');if($limit<1)return 0;
        $sql="SELECT P.* FROM {$prefix}_spieler P WHERE P.status='1' AND P.verein_id>0 AND P.verein_id<>".(int)$teamId." AND P.vertrag_spiele<".$limit." AND NOT EXISTS (SELECT 1 FROM {$prefix}_player_precontract A WHERE A.player_id=P.id AND A.destination_team_id=".(int)$teamId." AND A.status IN ('open','accepted')) ORDER BY RAND() LIMIT 8";
        $r=$db->executeQuery($sql);$made=0;while($p=$r->fetch_array()){
            if($made>=1)break; $salary=max((int)ceil($p['vertrag_gehalt']*(1.05+mt_rand(0,15)/100)),(int)ceil((int)$p['marktwert']/800));$hand=min((int)$p['marktwert'],max($salary*3,(int)round((int)$p['marktwert']*(0.03+mt_rand(0,5)/100))));
            try{self::placeOffer($websoccer,$db,(int)$p['id'],(int)$teamId,0,$salary,(int)$p['vertrag_torpraemie'],$hand,(int)$websoccer->getConfig('max_number_of_contract_matches'),true);$made++;}catch(Exception $e){}
        }$r->free();return $made;
    }

    private static function playerName(WebSoccer $websoccer, DbConnection $db, $id){$r=$db->querySelect('vorname,nachname,kunstname',$websoccer->getConfig('db_prefix').'_spieler','id = %d',(int)$id,1);$p=$r->fetch_array();$r->free();return $p['kunstname']?:trim($p['vorname'].' '.$p['nachname']);}
}
?>