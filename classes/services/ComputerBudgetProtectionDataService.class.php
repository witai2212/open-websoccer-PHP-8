<?php
/** Garantiert CPU-Vereinen einen dauerhaft spielbaren Mindestetat. */
class ComputerBudgetProtectionDataService {
    public static function getFloor(WebSoccer $websoccer) {
        $floor = (int) $websoccer->getConfig('computer_budget_subsidy_floor');
        return $floor > 0 ? $floor : 5000000;
    }

    public static function protectAfterTransaction(WebSoccer $websoccer, DbConnection $db, $team, $calculatedBudget) {
        if (!empty($team['user_id'])) {
            return (int) $calculatedBudget;
        }
        $floor = self::getFloor($websoccer);
        if ((int) $calculatedBudget >= $floor) {
            return (int) $calculatedBudget;
        }
        $subsidy = $floor - (int) $calculatedBudget;
        self::insertSubsidyStatement($websoccer, $db, (int) $team['team_id'], $subsidy);
        return $floor;
    }

    public static function ensureTeamFloor(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $prefix = $websoccer->getConfig('db_prefix');
        $result = $db->querySelect('id AS team_id, user_id, finanz_budget AS team_budget', $prefix . '_verein', "id = %d AND (user_id IS NULL OR user_id <= 0) AND nationalteam = '0'", (int) $teamId, 1);
        $team = $result->fetch_array();
        $result->free();
        if (!$team) {
            return 0;
        }
        $floor = self::getFloor($websoccer);
        $budget = (int) $team['team_budget'];
        if ($budget >= $floor) {
            return 0;
        }
        $subsidy = $floor - $budget;
        self::insertSubsidyStatement($websoccer, $db, (int) $teamId, $subsidy);
        $db->queryUpdate(array('finanz_budget' => $floor), $prefix . '_verein', 'id = %d', (int) $teamId);
        return $subsidy;
    }

    public static function subsidizeAllComputerClubs(WebSoccer $websoccer, DbConnection $db) {
        $prefix = $websoccer->getConfig('db_prefix');
        $floor = self::getFloor($websoccer);
        $result = $db->executeQuery("SELECT id, finanz_budget FROM {$prefix}_verein WHERE (user_id IS NULL OR user_id <= 0) AND nationalteam = '0' AND status = '1' AND finanz_budget < " . (int) $floor);
        $count = 0;
        $total = 0;
        while ($team = $result->fetch_array()) {
            $subsidy = $floor - (int) $team['finanz_budget'];
            if ($subsidy <= 0) {
                continue;
            }
            self::insertSubsidyStatement($websoccer, $db, (int) $team['id'], $subsidy);
            $db->queryUpdate(array('finanz_budget' => $floor), $prefix . '_verein', 'id = %d', (int) $team['id']);
            $count++;
            $total += $subsidy;
        }
        $result->free();
        return array('clubs' => $count, 'amount' => $total, 'floor' => $floor);
    }

    public static function canSpendWithReserve(WebSoccer $websoccer, DbConnection $db, $teamId, $amount) {
        self::ensureTeamFloor($websoccer, $db, $teamId);
        $result = $db->querySelect('finanz_budget', $websoccer->getConfig('db_prefix') . '_verein', 'id = %d', (int) $teamId, 1);
        $team = $result->fetch_array();
        $result->free();
        return $team && ((int) $team['finanz_budget'] - max(0, (int) $amount)) >= self::getFloor($websoccer);
    }

    private static function insertSubsidyStatement(WebSoccer $websoccer, DbConnection $db, $teamId, $amount) {
        if ((int) $amount <= 0) {
            return;
        }
        $db->queryInsert(array(
            'verein_id' => (int) $teamId,
            'absender' => 'Ligaverband',
            'betrag' => (int) $amount,
            'datum' => $websoccer->getNowAsTimestamp(),
            'verwendung' => 'CPU-Grundfinanzierung'
        ), $websoccer->getConfig('db_prefix') . '_konto');
    }
}
?>
