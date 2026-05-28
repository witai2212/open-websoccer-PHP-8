<?php
/******************************************************

  Season rollover helper for OpenWebSoccer-Sim.

******************************************************/

/**
 * Creates safe season names in the format YYYY_01, YYYY_02, ...
 */
class SeasonNameGeneratorService {

    /**
     * Returns the next globally unused season name for the given year.
     *
     * Existing seasons normally use the same name for all leagues, therefore
     * the sequence is calculated globally and not per league.
     *
     * @param WebSoccer $websoccer application context.
     * @param DbConnection $db database connection.
     * @param int|null $year calendar year, defaults to current year.
     * @return string next season name, e.g. 2026_01.
     */
    public static function getNextSeasonName(WebSoccer $websoccer, DbConnection $db, $year = null) {
        $prefix = $websoccer->getConfig('db_prefix');

        $year = ($year === null || (int) $year <= 0) ? (int) date('Y') : (int) $year;
        $year = max(1900, min(9999, $year));

        $like = $year . '\\_%';
        $result = $db->querySelect(
            'name',
            $prefix . '_saison',
            "name LIKE '%s'",
            $like
        );

        $highestNumber = 0;
        $pattern = '/^' . preg_quote((string) $year, '/') . '_(\d{2})$/';

        while ($season = $result->fetch_array()) {
            if (!empty($season['name']) && preg_match($pattern, $season['name'], $matches)) {
                $number = (int) $matches[1];
                if ($number > $highestNumber) {
                    $highestNumber = $number;
                }
            }
        }

        $result->free();

        return sprintf('%04d_%02d', $year, $highestNumber + 1);
    }
}
?>
