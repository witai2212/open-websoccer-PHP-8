<?php
class MarketValueMaintenanceService {
    public static function getLeagues(WebSoccer $websoccer, DbConnection $db) {
        return self::fetchAll($db, "SELECT id, name FROM " . $websoccer->getConfig('db_prefix') . "_liga WHERE status = '1' ORDER BY name");
    }

    public static function getClubs(WebSoccer $websoccer, DbConnection $db, $leagueId = 0) {
        $where = $leagueId > 0 ? " AND liga_id = " . (int) $leagueId : '';
        return self::fetchAll($db, "SELECT id, name, liga_id FROM " . $websoccer->getConfig('db_prefix') . "_verein WHERE status = '1'" . $where . " ORDER BY name");
    }

    public static function run(WebSoccer $websoccer, DbConnection $db, $scope, $scopeId, $preview, $limit, $adminId = 0) {
        $scope = in_array($scope, array('all','league','club','player')) ? $scope : 'all';
        $scopeId = (int) $scopeId;
        $limit = max(1, min(5000, (int) $limit));
        $prefix = $websoccer->getConfig('db_prefix');
        $where = "P.status = '1'";
        if ($scope === 'league') $where .= " AND V.liga_id = " . $scopeId;
        if ($scope === 'club') $where .= " AND P.verein_id = " . $scopeId;
        if ($scope === 'player') $where .= " AND P.id = " . $scopeId;
        $order = ($scope === 'all') ? 'P.on_update ASC, P.id ASC' : 'P.id ASC';
        $sql = "SELECT P.id, P.vorname, P.nachname, P.kunstname, P.marktwert, P.w_staerke_calc, V.name AS club_name, L.name AS league_name
                FROM {$prefix}_spieler P
                LEFT JOIN {$prefix}_verein V ON V.id = P.verein_id
                LEFT JOIN {$prefix}_liga L ON L.id = V.liga_id
                WHERE {$where} ORDER BY {$order} LIMIT {$limit}";
        $rows = self::fetchAll($db, $sql);
        $result = array('processed'=>0,'changed'=>0,'increased'=>0,'decreased'=>0,'unchanged'=>0,'old_total'=>0,'new_total'=>0,'details'=>array());
        foreach ($rows as $row) {
            $old = (int) $row['marktwert'];
            $calc = PlayersStrengthDataService::calculatePlayerStats($websoccer, $db, (int) $row['id'], !$preview);
            $new = (int) $calc['market_value'];
            $delta = $new - $old;
            $percent = $old > 0 ? round(($delta / $old) * 100, 1) : ($new > 0 ? 100 : 0);
            $result['processed']++;
            $result['old_total'] += $old;
            $result['new_total'] += $new;
            if ($delta > 0) { $result['changed']++; $result['increased']++; }
            elseif ($delta < 0) { $result['changed']++; $result['decreased']++; }
            else $result['unchanged']++;
            if (count($result['details']) < 200) {
                $name = strlen(trim($row['kunstname'])) ? trim($row['kunstname']) : trim($row['vorname'] . ' ' . $row['nachname']);
                $result['details'][] = array('id'=>(int)$row['id'],'name'=>$name,'club'=>$row['club_name'],'league'=>$row['league_name'],'old'=>$old,'new'=>$new,'delta'=>$delta,'percent'=>$percent);
            }
        }
        usort($result['details'], function($a,$b){ return abs($b['percent']) <=> abs($a['percent']); });
        if (!$preview && $result['processed'] > 0) self::logRun($websoccer,$db,$adminId,$scope,$scopeId,$result);
        return $result;
    }

    public static function getHistory(WebSoccer $websoccer, DbConnection $db, $limit = 25) {
        $table = $websoccer->getConfig('db_prefix') . '_marketvalue_job_log';
        return self::fetchAll($db, "SELECT * FROM {$table} ORDER BY created_at DESC, id DESC LIMIT " . max(1,min(100,(int)$limit)));
    }

    private static function logRun(WebSoccer $websoccer, DbConnection $db, $adminId, $scope, $scopeId, $result) {
        $table = $websoccer->getConfig('db_prefix') . '_marketvalue_job_log';
        $sql = "INSERT INTO {$table} (admin_id, scope_type, scope_id, processed, changed_count, increased_count, decreased_count, unchanged_count, old_total, new_total, created_at)
                VALUES (".(int)$adminId.", '".$scope."', ".(int)$scopeId.", ".(int)$result['processed'].", ".(int)$result['changed'].", ".(int)$result['increased'].", ".(int)$result['decreased'].", ".(int)$result['unchanged'].", ".(int)$result['old_total'].", ".(int)$result['new_total'].", ".$websoccer->getNowAsTimestamp().")";
        $db->executeQuery($sql);
    }

    private static function fetchAll(DbConnection $db, $sql) {
        $items = array(); $result = $db->executeQuery($sql);
        while ($row = $result->fetch_array()) $items[] = $row;
        $result->free(); return $items;
    }
}
?>
