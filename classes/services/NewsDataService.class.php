<?php
/** Zentrale Pflege der öffentlichen News. */
class NewsDataService {
    public static function trimToMaximum(WebSoccer $websoccer, DbConnection $db) {
        $maximum = (int) $websoccer->getConfig('news_max_entries');
        if ($maximum < 1) {
            $maximum = 50;
        }

        $prefix = $websoccer->getConfig('db_prefix');
        $table = $prefix . '_news';
        $result = $db->executeQuery("SELECT COUNT(*) AS hits FROM {$table}");
        $row = $result->fetch_array();
        $result->free();
        $count = $row ? (int) $row['hits'] : 0;
        if ($count <= $maximum) {
            return 0;
        }

        $sql = "DELETE FROM {$table}
                WHERE id NOT IN (
                    SELECT id FROM (
                        SELECT id
                        FROM {$table}
                        ORDER BY datum DESC, id DESC
                        LIMIT " . (int) $maximum . "
                    ) AS NewsToKeep
                )";
        $db->executeQuery($sql);
        return max(0, $count - $maximum);
    }
}
?>
