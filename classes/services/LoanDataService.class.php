<?php

class LoanDataService {
    const OPTION_NONE = 'none';
    const OPTION_BUY = 'buy_option';
    const OPTION_OBLIGATION = 'buy_obligation';
    const STATUS_ACTIVE = 'active';
    const STATUS_COMPLETED = 'completed';
    const STATUS_RECALLED = 'recalled';
    const STATUS_BOUGHT = 'bought';
    const MIN_RECALL_MATCHES = 5;

    public static function normalizeOptionType($type) {
        return ($type == self::OPTION_BUY || $type == self::OPTION_OBLIGATION) ? $type : self::OPTION_NONE;
    }
    public static function normalizeSalaryShare($share) {
        $share = (int) $share;
        return min(100, max(50, $share));
    }
    public static function getMaxLoanFee($player) {
        $mv = isset($player['player_marketvalue']) ? (int) $player['player_marketvalue'] : (isset($player['marktwert']) ? (int) $player['marktwert'] : 0);
        $sal = isset($player['player_contract_salary']) ? (int) $player['player_contract_salary'] : (isset($player['vertrag_gehalt']) ? (int) $player['vertrag_gehalt'] : 0);
        return max(1000, (int) round(($mv * 0.03) + ($sal * 1.5)));
    }
    public static function getMinBuyFee($player) {
        $mv = isset($player['player_marketvalue']) ? (int) $player['player_marketvalue'] : (isset($player['marktwert']) ? (int) $player['marktwert'] : 0);
        return max(1, (int) round($mv * 0.50));
    }
    public static function getMaxBuyFee($player) {
        $mv = isset($player['player_marketvalue']) ? (int) $player['player_marketvalue'] : (isset($player['marktwert']) ? (int) $player['marktwert'] : 0);
        return max(1, (int) round($mv * 1.50));
    }
    public static function validateOfferTerms(I18n $i18n, $player, $fee, $share, $type, $buyFee) {
        $fee = (int) $fee;
        $share = self::normalizeSalaryShare($share);
        $type = self::normalizeOptionType($type);
        $buyFee = (int) $buyFee;
        if ($fee < 1) throw new Exception($i18n->getMessage('lending_err_fee_invalid'));
        $maxFee = self::getMaxLoanFee($player);
        if ($fee > $maxFee) throw new Exception($i18n->getMessage('lending_err_fee_too_high', number_format($maxFee, 0, ',', ' ')));
        if ($type != self::OPTION_NONE) {
            $min = self::getMinBuyFee($player); $max = self::getMaxBuyFee($player);
            if ($buyFee < $min || $buyFee > $max) throw new Exception(sprintf($i18n->getMessage('lending_err_buy_fee_invalid'), number_format($min, 0, ',', ' '), number_format($max, 0, ',', ' ')));
        } else { $buyFee = 0; }
        return array($fee, $share, $type, $buyFee);
    }

    public static function getOfferByPlayerId(WebSoccer $websoccer, DbConnection $db, $playerId) {
        $result = $db->querySelect('*', $websoccer->getConfig('db_prefix') . '_loan_offer', 'player_id = %d AND status = \'open\' ORDER BY id DESC', (int) $playerId, 1);
        $row = $result->fetch_array(); $result->free(); return $row ? $row : array();
    }
    public static function saveOffer(WebSoccer $websoccer, DbConnection $db, $playerId, $lenderTeamId, $fee, $share, $type, $buyFee, $computer = false) {
        $table = $websoccer->getConfig('db_prefix') . '_loan_offer';
        $old = self::getOfferByPlayerId($websoccer, $db, $playerId);
        $cols = array('player_id'=>(int)$playerId,'lender_team_id'=>(int)$lenderTeamId,'loan_fee_per_match'=>(int)$fee,'salary_share_percent'=>self::normalizeSalaryShare($share),'option_type'=>self::normalizeOptionType($type),'buy_fee'=>(int)$buyFee,'created_date'=>$websoccer->getNowAsTimestamp(),'created_by_computer'=>$computer?'1':'0','status'=>'open');
        if (isset($old['id'])) $db->queryUpdate($cols, $table, 'id = %d', (int)$old['id']); else $db->queryInsert($cols, $table);
    }
    public static function closeOffer(WebSoccer $websoccer, DbConnection $db, $playerId, $status = 'closed') {
        $db->queryUpdate(array('status'=>$status), $websoccer->getConfig('db_prefix') . '_loan_offer', 'player_id = %d AND status = \'open\'', (int)$playerId);
    }
    public static function createLoan(WebSoccer $websoccer, DbConnection $db, $playerId, $lenderTeamId, $borrowerTeamId, $matches, $fee, $share, $type, $buyFee) {
        $db->queryInsert(array('player_id'=>(int)$playerId,'lender_team_id'=>(int)$lenderTeamId,'borrower_team_id'=>(int)$borrowerTeamId,'start_date'=>$websoccer->getNowAsTimestamp(),'total_matches'=>(int)$matches,'remaining_matches'=>(int)$matches,'matches_completed'=>0,'loan_fee_per_match'=>(int)$fee,'salary_share_percent'=>self::normalizeSalaryShare($share),'option_type'=>self::normalizeOptionType($type),'buy_fee'=>(int)$buyFee,'min_recall_matches'=>self::MIN_RECALL_MATCHES,'status'=>self::STATUS_ACTIVE,'created_date'=>$websoccer->getNowAsTimestamp(),'completed_date'=>0), $websoccer->getConfig('db_prefix') . '_loan');
        return $db->getLastInsertedId();
    }
    public static function getLoanById(WebSoccer $websoccer, DbConnection $db, $id) {
        $result = $db->querySelect('*', $websoccer->getConfig('db_prefix') . '_loan', 'id = %d', (int)$id, 1);
        $row = $result->fetch_array(); $result->free(); return $row ? $row : array();
    }
    public static function getActiveLoanByPlayerId(WebSoccer $websoccer, DbConnection $db, $playerId) {
        $result = $db->querySelect('*', $websoccer->getConfig('db_prefix') . '_loan', 'player_id = %d AND status = \'active\' ORDER BY id DESC', (int)$playerId, 1);
        $row = $result->fetch_array(); $result->free(); return $row ? $row : array();
    }
    public static function completeLoan(WebSoccer $websoccer, DbConnection $db, $loanId, $status) {
        $db->queryUpdate(array('status'=>$status,'completed_date'=>$websoccer->getNowAsTimestamp()), $websoccer->getConfig('db_prefix') . '_loan', 'id = %d', (int)$loanId);
    }

    public static function recordMatchAndApplyDevelopment(WebSoccer $websoccer, DbConnection $db, SimulationMatch $match, &$cols, $info, $simPlayer = null) {
        $loan = self::getActiveLoanByPlayerId($websoccer, $db, $info['id']);
        if (!isset($loan['id'])) return;
        $minutes = $simPlayer ? (int)$simPlayer->getMinutesPlayed() : 0;
        $grade = $simPlayer ? round((float)$simPlayer->getMark(), 2) : 0;
        $goals = $simPlayer ? (int)$simPlayer->getGoals() : 0;
        $assists = $simPlayer ? (int)$simPlayer->getAssists() : 0;
        $quality = self::calculateDestinationQuality($websoccer, $db, $loan['borrower_team_id'], $minutes);
        $partnershipBonusPercent = 0;
        if (class_exists('ClubPartnershipDataService')) {
            $partnershipBonusPercent = ClubPartnershipDataService::getLoanDevelopmentBonusPercent($websoccer, $db, (int) $loan['lender_team_id'], (int) $loan['borrower_team_id']);
            if ($partnershipBonusPercent > 0) {
                $quality = min(100, $quality + $partnershipBonusPercent);
            }
        }
        $bonus = self::calculateDevelopmentBonus($info, $minutes, $grade, $quality);
        if ($bonus > 0 && $partnershipBonusPercent > 0) {
            $bonus = round($bonus * (1 + ($partnershipBonusPercent / 100)), 3);
        }
        $attribute = '';
        if ($bonus > 0) $attribute = self::applyDevelopmentBonus($cols, $info, $bonus);
        $db->queryInsert(array('loan_id'=>(int)$loan['id'],'player_id'=>(int)$loan['player_id'],'match_id'=>(int)$match->id,'match_date'=>$websoccer->getNowAsTimestamp(),'minutes_played'=>$minutes,'grade'=>$grade,'goals'=>$goals,'assists'=>$assists,'destination_quality'=>$quality,'development_bonus'=>$bonus,'attribute_key'=>$attribute,'created_date'=>$websoccer->getNowAsTimestamp()), $websoccer->getConfig('db_prefix') . '_loan_report');
        self::settleSalaryShare($websoccer, $db, $loan, $info);
        $db->queryUpdate(array('remaining_matches'=>max(0,(int)$loan['remaining_matches']-1),'matches_completed'=>(int)$loan['matches_completed']+1,'total_development_bonus'=>(float)$loan['total_development_bonus']+$bonus), $websoccer->getConfig('db_prefix') . '_loan', 'id = %d', (int)$loan['id']);
        if ($bonus > 0) self::notifyDevelopment($websoccer, $db, $loan, $info, $bonus, $attribute);
    }
    private static function calculateDestinationQuality(WebSoccer $websoccer, DbConnection $db, $teamId, $minutes) {
        $from = $websoccer->getConfig('db_prefix') . '_verein AS T LEFT JOIN ' . $websoccer->getConfig('db_prefix') . '_liga AS L ON L.id = T.liga_id';
        $r = $db->querySelect('T.strength,T.tactical_style,L.division', $from, 'T.id = %d', (int)$teamId, 1); $t = $r->fetch_array(); $r->free();
        $q = 50; if ($t) { $q += min(20,max(-10,round(((int)$t['strength']-50)/4))); if ((int)$t['division']>0) $q += max(-15,10-((int)$t['division']*5)); if ($t['tactical_style']=='youth_focused'||$t['tactical_style']=='youth-focused') $q += 8; }
        if ($minutes >= 70) $q += 20; elseif ($minutes >= 45) $q += 10; elseif ($minutes == 0) $q -= 15; return min(100,max(1,(int)$q));
    }
    private static function calculateDevelopmentBonus($info, $minutes, $grade, $quality) {
        if ($minutes < 45) return 0; $age = isset($info['age'])?(int)$info['age']:25; $talent = isset($info['w_talent'])?(int)$info['w_talent']:3;
        $ageF = ($age<=21)?1.25:(($age<=24)?1.0:0.6); $talF = 0.8+($talent*0.12); $gradeF = ($grade>0&&$grade<=2.5)?1.2:(($grade>=4.0)?0.7:1.0); $minF = min(1.25,$minutes/90); $qF = max(0.6,min(1.3,$quality/75));
        return round(min(0.25, max(0, 0.06*$ageF*$talF*$gradeF*$minF*$qF)), 3);
    }
    private static function applyDevelopmentBonus(&$cols, $info, $bonus) {
        $p = isset($info['position'])?$info['position']:'Mittelfeld';
        if ($p == 'Torwart') $pool = array('w_staerke','w_technik','w_penalty_killing'); elseif ($p == 'Abwehr') $pool = array('w_staerke','w_tackling','w_heading','w_passing'); elseif ($p == 'Sturm') $pool = array('w_staerke','w_shooting','w_heading','w_pace'); else $pool = array('w_staerke','w_passing','w_creativity','w_tackling');
        $a = $pool[array_rand($pool)]; $current = isset($info[$a]) ? (float)$info[$a] : 0; $maximum = ($a == 'w_staerke') ? 99.99 : 100; $cols[$a] = min($maximum, round($current + $bonus, 3)); return $a;
    }
    private static function settleSalaryShare(WebSoccer $websoccer, DbConnection $db, $loan, $info) {
        $share = self::normalizeSalaryShare($loan['salary_share_percent']); if ($share >= 100) return; $salary = isset($info['vertrag_gehalt']) ? (int)$info['vertrag_gehalt'] : 0; $part = (int)round($salary * (100-$share) / 100); if ($part <= 0) return;
        $b = TeamsDataService::getTeamSummaryById($websoccer,$db,$loan['borrower_team_id']); $l = TeamsDataService::getTeamSummaryById($websoccer,$db,$loan['lender_team_id']);
        BankAccountDataService::creditAmount($websoccer,$db,$loan['borrower_team_id'],$part,'lending_salary_share_subject',$l['team_name']); BankAccountDataService::debitAmount($websoccer,$db,$loan['lender_team_id'],$part,'lending_salary_share_subject',$b['team_name']);
    }

    public static function canRecallLoan(WebSoccer $websoccer, DbConnection $db, $loan) {
        if (!isset($loan['id']) || $loan['status'] != self::STATUS_ACTIVE || (int)$loan['matches_completed'] < max(self::MIN_RECALL_MATCHES,(int)$loan['min_recall_matches'])) return false;
        $r = $db->querySelect('COUNT(*) AS reports, SUM(minutes_played) AS minutes', $websoccer->getConfig('db_prefix') . '_loan_report', 'loan_id = %d', (int)$loan['id']); $s = $r->fetch_array(); $r->free();
        return ((int)$s['reports'] >= self::MIN_RECALL_MATCHES && (int)$s['minutes'] < ((int)$s['reports'] * 45));
    }
    public static function recallLoan(WebSoccer $websoccer, DbConnection $db, $loan) {
        $player = self::getPlayerRow($websoccer,$db,$loan['player_id']);
        $db->queryUpdate(array('lending_matches'=>0,'lending_owner_id'=>0,'lending_fee'=>0,'verein_id'=>(int)$loan['lender_team_id']), $websoccer->getConfig('db_prefix') . '_spieler', 'id = %d', (int)$loan['player_id']);
        self::completeLoan($websoccer,$db,$loan['id'],self::STATUS_RECALLED); self::closeOffer($websoccer,$db,$loan['player_id'],'closed'); self::notifyLoanStatus($websoccer,$db,$loan,$player,'lending_notification_recalled');
    }
    public static function buyLoanPlayer(WebSoccer $websoccer, DbConnection $db, $loan) {
        if ($loan['option_type'] != self::OPTION_BUY && $loan['option_type'] != self::OPTION_OBLIGATION) throw new Exception('no option');
        $fee = (int)$loan['buy_fee']; $b = TeamsDataService::getTeamSummaryById($websoccer,$db,$loan['borrower_team_id']); $l = TeamsDataService::getTeamSummaryById($websoccer,$db,$loan['lender_team_id']); if ((int)$b['team_budget'] < $fee) throw new Exception('budget');
        BankAccountDataService::debitAmount($websoccer,$db,$loan['borrower_team_id'],$fee,'lending_buy_fee_subject',$l['team_name']); BankAccountDataService::creditAmount($websoccer,$db,$loan['lender_team_id'],$fee,'lending_buy_fee_subject',$b['team_name']);
        $now = $websoccer->getNowAsTimestamp(); $db->queryUpdate(array('lending_matches'=>0,'lending_owner_id'=>0,'lending_fee'=>0,'verein_id'=>(int)$loan['borrower_team_id'],'last_transfer'=>$now), $websoccer->getConfig('db_prefix') . '_spieler', 'id = %d', (int)$loan['player_id']);
        $db->queryInsert(array('spieler_id'=>(int)$loan['player_id'],'seller_user_id'=>(int)$l['user_id'],'seller_club_id'=>(int)$loan['lender_team_id'],'buyer_user_id'=>(int)$b['user_id'],'buyer_club_id'=>(int)$loan['borrower_team_id'],'datum'=>$now,'directtransfer_amount'=>$fee), $websoccer->getConfig('db_prefix') . '_transfer');
        self::completeLoan($websoccer,$db,$loan['id'],self::STATUS_BOUGHT);
        self::closeOffer($websoccer,$db,$loan['player_id'],'accepted');
        TransferMessagesDataService::createTransferCompleted(
            $websoccer,
            $db,
            (int) $loan['player_id'],
            (int) $loan['lender_team_id'],
            (int) $loan['borrower_team_id'],
            $fee,
            !empty($l['user_id']) ? (int) $l['user_id'] : 0,
            !empty($b['user_id']) ? (int) $b['user_id'] : 0,
            array('source' => 'loan_option')
        );
    }
    public static function handleEndOfLoan(WebSoccer $websoccer, DbConnection $db, &$cols, $info) {
        $loan = self::getActiveLoanByPlayerId($websoccer,$db,$info['id']);
        if (!isset($loan['id'])) {
            return '';
        }
        if ($loan['option_type'] == self::OPTION_OBLIGATION && (int)$loan['buy_fee'] > 0) {
            try {
                self::buyLoanPlayer($websoccer,$db,$loan);
                unset($cols['lending_owner_id'],$cols['lending_fee'],$cols['verein_id']);
                return self::STATUS_BOUGHT;
            } catch (Exception $e) {
                self::notifyLoanStatus($websoccer,$db,$loan,$info,'lending_notification_obligation_failed');
            }
        }
        self::completeLoan($websoccer,$db,$loan['id'],self::STATUS_COMPLETED);
        return self::STATUS_COMPLETED;
    }
    private static function getPlayerRow(WebSoccer $websoccer, DbConnection $db, $playerId) { $r=$db->querySelect('*',$websoccer->getConfig('db_prefix').'_spieler','id = %d',(int)$playerId,1); $p=$r->fetch_array(); $r->free(); return $p?$p:array(); }
    private static function playerName($p) { return (isset($p['kunstname']) && strlen($p['kunstname'])) ? $p['kunstname'] : trim((isset($p['vorname'])?$p['vorname']:'') . ' ' . (isset($p['nachname'])?$p['nachname']:'')); }
    private static function notifyDevelopment(WebSoccer $websoccer, DbConnection $db, $loan, $info, $bonus, $attr) { $l=TeamsDataService::getTeamSummaryById($websoccer,$db,$loan['lender_team_id']); if (!empty($l['user_id'])) NotificationsDataService::createNotification($websoccer,$db,$l['user_id'],'lending_notification_development',array('player'=>self::playerName($info),'bonus'=>number_format($bonus,2,',',' '),'attribute'=>$attr),'lending_development','loans',''); }
    private static function notifyLoanStatus(WebSoccer $websoccer, DbConnection $db, $loan, $player, $key) {
        $l = TeamsDataService::getTeamSummaryById($websoccer, $db, $loan['lender_team_id']);
        $b = TeamsDataService::getTeamSummaryById($websoccer, $db, $loan['borrower_team_id']);
        $event = ($key == 'lending_notification_recalled') ? 'recalled' : 'obligation_failed';
        $details = array(
            'matches' => isset($loan['total_matches']) ? (int) $loan['total_matches'] : 0,
            'loan_fee_per_match' => isset($loan['loan_fee_per_match']) ? (int) $loan['loan_fee_per_match'] : 0,
            'salary_share_percent' => isset($loan['salary_share_percent']) ? (int) $loan['salary_share_percent'] : 100,
            'buy_fee' => isset($loan['buy_fee']) ? (int) $loan['buy_fee'] : 0
        );
        if (!empty($l['user_id'])) {
            TransferMessagesDataService::createLoanMessage($websoccer, $db, $l['user_id'], $event, $loan['player_id'], $loan['lender_team_id'], $loan['borrower_team_id'], $details, $loan['borrower_team_id']);
        }
        if (!empty($b['user_id'])) {
            TransferMessagesDataService::createLoanMessage($websoccer, $db, $b['user_id'], $event, $loan['player_id'], $loan['lender_team_id'], $loan['borrower_team_id'], $details, $loan['lender_team_id']);
        }
    }
}

?>
