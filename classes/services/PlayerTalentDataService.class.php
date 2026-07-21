<?php
/******************************************************

  Central talent distribution and potential rules for CM23.

******************************************************/
class PlayerTalentDataService {

    private static $_defaults = array(1 => 15.5, 2 => 30.0, 3 => 35.0, 4 => 15.0, 5 => 4.0, 6 => 0.5);
    private static $_potentialRanges = array(
        1 => array(45, 65),
        2 => array(55, 75),
        3 => array(65, 85),
        4 => array(78, 92),
        5 => array(88, 98),
        6 => array(96, 100)
    );

    public static function generateTalent(WebSoccer $websoccer) {
        $weights = self::getDistribution($websoccer);
        $roll = mt_rand(1, 1000000) / 10000;
        $sum = 0.0;
        foreach ($weights as $talent => $weight) {
            $sum += $weight;
            if ($roll <= $sum) return (int) $talent;
        }
        return 3;
    }

    public static function getDistribution(WebSoccer $websoccer) {
        $weights = array();
        $total = 0.0;
        for ($talent = 1; $talent <= 6; $talent++) {
            $value = self::$_defaults[$talent];
            try {
                $configured = $websoccer->getConfig('player_talent_probability_' . $talent);
                if ($configured !== null && $configured !== '') $value = (float) $configured;
            } catch (Exception $e) {}
            $value = max(0.0, $value);
            $weights[$talent] = $value;
            $total += $value;
        }
        if ($total <= 0.0) return self::$_defaults;
        foreach ($weights as $talent => $value) $weights[$talent] = ($value / $total) * 100.0;
        return $weights;
    }

    public static function getPotentialRange($talent) {
        $talent = max(1, min(6, (int) $talent));
        return self::$_potentialRanges[$talent];
    }

    public static function generateMaximumStrength($talent, $currentStrength = 1) {
        $range = self::getPotentialRange($talent);
        return max((int) ceil($currentStrength), mt_rand($range[0], $range[1]));
    }

    public static function getPotentialCeiling($talent) {
        $range = self::getPotentialRange($talent);
        return $range[1];
    }

    public static function getMarketPremium($talent) {
        $map = array(1 => 0.60, 2 => 0.75, 3 => 1.00, 4 => 1.35, 5 => 1.85, 6 => 2.60);
        $talent = max(1, min(6, (int) $talent));
        return $map[$talent];
    }

    public static function getPotentialWeight($talent) {
        $map = array(1 => 0.05, 2 => 0.10, 3 => 0.18, 4 => 0.30, 5 => 0.45, 6 => 0.60);
        $talent = max(1, min(6, (int) $talent));
        return $map[$talent];
    }

    public static function getMarketCeilingFactor($talent) {
        $map = array(1 => 0.20, 2 => 0.35, 3 => 0.60, 4 => 0.85, 5 => 1.00, 6 => 1.25);
        $talent = max(1, min(6, (int) $talent));
        return $map[$talent];
    }
}
?>
