<?php
/******************************************************

  Parent-club helpers for OpenWebSoccer-Sim.

******************************************************/

/**
 * Data service for admin-defined parent club / affiliate club relationships.
 */
class ParentClubDataService {

    const STATUS_ACTIVE = 'active';
    const STATUS_SUSPENDED = 'suspended';
    const REASON_SAME_DIVISION = 'same_division';
    const REASON_NO_LOWER_LEAGUE = 'same_division_no_lower_league';

    public static function getParentClubForTeam(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $prefix = $websoccer->getConfig('db_prefix');

        $columns = array(
            'P.id' => 'team_id',
            'P.name' => 'team_name',
            'P.kurz' => 'team_short',
            'P.bild' => 'team_logo',
            'PL.id' => 'league_id',
            'PL.name' => 'league_name',
            'PL.land' => 'league_country',
            'PL.division' => 'league_division',
            'C.parent_club_status' => 'relationship_status',
            'C.parent_club_suspended_reason' => 'suspended_reason'
        );

        $fromTable = $prefix . '_verein AS C';
        $fromTable .= ' INNER JOIN ' . $prefix . '_verein AS P ON P.id = C.parent_club_id';
        $fromTable .= ' LEFT JOIN ' . $prefix . '_liga AS PL ON PL.id = P.liga_id';

        $result = $db->querySelect(
            $columns,
            $fromTable,
            "C.id = %d AND C.parent_club_id IS NOT NULL AND C.parent_club_id > 0",
            (int) $teamId,
            1
        );

        $parent = $result->fetch_array();
        $result->free();

        return $parent ? $parent : array();
    }

    public static function getAffiliateClubsForTeam(WebSoccer $websoccer, DbConnection $db, $teamId) {
        $prefix = $websoccer->getConfig('db_prefix');

        $columns = array(
            'C.id' => 'team_id',
            'C.name' => 'team_name',
            'C.kurz' => 'team_short',
            'C.bild' => 'team_logo',
            'L.id' => 'league_id',
            'L.name' => 'league_name',
            'L.land' => 'league_country',
            'L.division' => 'league_division',
            'C.parent_club_status' => 'relationship_status',
            'C.parent_club_suspended_reason' => 'suspended_reason'
        );

        $fromTable = $prefix . '_verein AS C';
        $fromTable .= ' LEFT JOIN ' . $prefix . '_liga AS L ON L.id = C.liga_id';

        $result = $db->querySelect(
            $columns,
            $fromTable,
            "C.parent_club_id = %d AND C.status = '1' ORDER BY C.parent_club_status ASC, C.name ASC",
            (int) $teamId
        );

        $clubs = array();
        while ($club = $result->fetch_array()) {
            $clubs[] = $club;
        }
        $result->free();

        return $clubs;
    }

    public static function validateAssignment(WebSoccer $websoccer, DbConnection $db, $childClubId, $parentClubId, $childLeagueId = 0, $childIsNationalTeam = false, $relationshipStatus = self::STATUS_ACTIVE, &$messageKey = null) {
        $childClubId = (int) $childClubId;
        $parentClubId = (int) $parentClubId;
        $childLeagueId = (int) $childLeagueId;

        if ($parentClubId <= 0) {
            return true;
        }

        if ($childIsNationalTeam) {
            $messageKey = 'parentclub_validation_child_nationalteam';
            return false;
        }

        if ($childClubId > 0 && $childClubId === $parentClubId) {
            $messageKey = 'parentclub_validation_same_club';
            return false;
        }

        $parent = self::getClubLeagueInfo($websoccer, $db, $parentClubId);
        if (!$parent) {
            $messageKey = 'parentclub_validation_parent_missing';
            return false;
        }

        if (!empty($parent['nationalteam']) && $parent['nationalteam'] === '1') {
            $messageKey = 'parentclub_validation_parent_nationalteam';
            return false;
        }

        if ($childClubId > 0) {
            $child = self::getClubLeagueInfo($websoccer, $db, $childClubId);
            if (!$child) {
                $messageKey = 'parentclub_validation_child_missing';
                return false;
            }

            if (!empty($child['nationalteam']) && $child['nationalteam'] === '1') {
                $messageKey = 'parentclub_validation_child_nationalteam';
                return false;
            }

            if ($childLeagueId <= 0) {
                $childLeagueId = (int) $child['league_id'];
            }

            if (self::isParentChainContainingClub($websoccer, $db, $parentClubId, $childClubId)) {
                $messageKey = 'parentclub_validation_cycle';
                return false;
            }
        }

        if ($relationshipStatus !== self::STATUS_SUSPENDED && $childLeagueId > 0 && !empty($parent['league_id'])) {
            $childLeague = self::getLeagueInfo($websoccer, $db, $childLeagueId);
            if ($childLeague && self::leaguesConflict($childLeague, $parent)) {
                $messageKey = 'parentclub_validation_same_division';
                return false;
            }
        }

        return true;
    }

    public static function countActiveDivisionConflicts(WebSoccer $websoccer, DbConnection $db) {
        return count(self::getActiveDivisionConflicts($websoccer, $db));
    }

    public static function getActiveDivisionConflicts(WebSoccer $websoccer, DbConnection $db) {
        $relationships = self::getRelationshipLeagueRows($websoccer, $db, true);
        $conflicts = array();

        foreach ($relationships as $relationship) {
            if (self::relationshipHasDivisionConflict($relationship)) {
                $conflicts[] = $relationship;
            }
        }

        return $conflicts;
    }

    public static function resolveDivisionConflicts(WebSoccer $websoccer, DbConnection $db) {
        if (class_exists('ClubPartnershipDataService')) {
            return ClubPartnershipDataService::resolveAutomaticStopsAndConflicts($websoccer, $db);
        }

        $relationships = self::getRelationshipLeagueRows($websoccer, $db, false);
        $actions = array();

        foreach ($relationships as $relationship) {
            $hasConflict = self::relationshipHasDivisionConflict($relationship);
            $status = !empty($relationship['parent_club_status']) ? $relationship['parent_club_status'] : self::STATUS_ACTIVE;
            $reason = !empty($relationship['parent_club_suspended_reason']) ? $relationship['parent_club_suspended_reason'] : '';

            if ($hasConflict) {
                $lowerLeague = self::findLowerLeagueBelowParent($websoccer, $db, $relationship);

                if ($lowerLeague) {
                    self::moveChildClubToLeague($websoccer, $db, (int) $relationship['child_id'], (int) $lowerLeague['id']);

                    $actions[] = array(
                        'action' => 'moved_child_down',
                        'child_id' => (int) $relationship['child_id'],
                        'child_name' => $relationship['child_name'],
                        'parent_id' => (int) $relationship['parent_id'],
                        'parent_name' => $relationship['parent_name'],
                        'old_league' => $relationship['child_league_name'],
                        'new_league' => $lowerLeague['name'],
                        'reason' => self::REASON_SAME_DIVISION
                    );
                } else {
                    self::setRelationshipStatus(
                        $websoccer,
                        $db,
                        (int) $relationship['child_id'],
                        self::STATUS_SUSPENDED,
                        self::REASON_NO_LOWER_LEAGUE
                    );

                    $actions[] = array(
                        'action' => 'suspended',
                        'child_id' => (int) $relationship['child_id'],
                        'child_name' => $relationship['child_name'],
                        'parent_id' => (int) $relationship['parent_id'],
                        'parent_name' => $relationship['parent_name'],
                        'old_league' => $relationship['child_league_name'],
                        'new_league' => '',
                        'reason' => self::REASON_NO_LOWER_LEAGUE
                    );
                }
            } elseif ($status === self::STATUS_SUSPENDED && ($reason === self::REASON_SAME_DIVISION || $reason === self::REASON_NO_LOWER_LEAGUE)) {
                self::setRelationshipStatus(
                    $websoccer,
                    $db,
                    (int) $relationship['child_id'],
                    self::STATUS_ACTIVE,
                    ''
                );

                $actions[] = array(
                    'action' => 'reactivated',
                    'child_id' => (int) $relationship['child_id'],
                    'child_name' => $relationship['child_name'],
                    'parent_id' => (int) $relationship['parent_id'],
                    'parent_name' => $relationship['parent_name'],
                    'old_league' => $relationship['child_league_name'],
                    'new_league' => $relationship['child_league_name'],
                    'reason' => ''
                );
            }
        }

        return $actions;
    }

    public static function isRelationshipActive(array $relationship) {
        return empty($relationship['relationship_status']) || $relationship['relationship_status'] === self::STATUS_ACTIVE;
    }

    private static function getRelationshipLeagueRows(WebSoccer $websoccer, DbConnection $db, $activeOnly) {
        $prefix = $websoccer->getConfig('db_prefix');
        $where = "C.parent_club_id IS NOT NULL
            AND C.parent_club_id > 0
            AND C.status = '1'
            AND P.status = '1'
            AND C.nationalteam != '1'
            AND P.nationalteam != '1'";

        if ($activeOnly) {
            $where .= " AND (C.parent_club_status IS NULL OR C.parent_club_status = 'active')";
        }

        $sql = "
            SELECT
                C.id AS child_id,
                C.name AS child_name,
                C.liga_id AS child_league_id,
                C.parent_club_id,
                C.parent_club_status,
                C.parent_club_suspended_reason,
                P.id AS parent_id,
                P.name AS parent_name,
                P.liga_id AS parent_league_id,
                CL.name AS child_league_name,
                CL.land AS child_country,
                CL.division AS child_division,
                PL.name AS parent_league_name,
                PL.land AS parent_country,
                PL.division AS parent_division
            FROM " . $prefix . "_verein AS C
            INNER JOIN " . $prefix . "_verein AS P ON P.id = C.parent_club_id
            LEFT JOIN " . $prefix . "_liga AS CL ON CL.id = C.liga_id
            LEFT JOIN " . $prefix . "_liga AS PL ON PL.id = P.liga_id
            WHERE " . $where . "
            ORDER BY P.name ASC, C.name ASC
        ";

        $result = $db->executeQuery($sql);
        $rows = array();
        while ($row = $result->fetch_array()) {
            $rows[] = $row;
        }
        $result->free();

        return $rows;
    }

    private static function relationshipHasDivisionConflict(array $relationship) {
        if (empty($relationship['child_league_id']) || empty($relationship['parent_league_id'])) {
            return false;
        }

        if ((int) $relationship['child_league_id'] === (int) $relationship['parent_league_id']) {
            return true;
        }

        if (strlen((string) $relationship['child_country'])
            && strlen((string) $relationship['parent_country'])
            && (string) $relationship['child_country'] === (string) $relationship['parent_country']
            && strlen((string) $relationship['child_division'])
            && strlen((string) $relationship['parent_division'])
            && (int) $relationship['child_division'] === (int) $relationship['parent_division']) {
            return true;
        }

        return false;
    }

    private static function leaguesConflict(array $childLeague, array $parentLeague) {
        if (empty($childLeague['league_id']) || empty($parentLeague['league_id'])) {
            return false;
        }

        if ((int) $childLeague['league_id'] === (int) $parentLeague['league_id']) {
            return true;
        }

        if (strlen((string) $childLeague['league_country'])
            && strlen((string) $parentLeague['league_country'])
            && (string) $childLeague['league_country'] === (string) $parentLeague['league_country']
            && strlen((string) $childLeague['league_division'])
            && strlen((string) $parentLeague['league_division'])
            && (int) $childLeague['league_division'] === (int) $parentLeague['league_division']) {
            return true;
        }

        return false;
    }

    private static function getClubLeagueInfo(WebSoccer $websoccer, DbConnection $db, $clubId) {
        $prefix = $websoccer->getConfig('db_prefix');

        $columns = array(
            'C.id' => 'club_id',
            'C.name' => 'club_name',
            'C.nationalteam' => 'nationalteam',
            'C.parent_club_id' => 'parent_club_id',
            'L.id' => 'league_id',
            'L.name' => 'league_name',
            'L.land' => 'league_country',
            'L.division' => 'league_division'
        );

        $fromTable = $prefix . '_verein AS C LEFT JOIN ' . $prefix . '_liga AS L ON L.id = C.liga_id';

        $result = $db->querySelect($columns, $fromTable, 'C.id = %d', (int) $clubId, 1);
        $club = $result->fetch_array();
        $result->free();

        return $club ? $club : null;
    }

    private static function getLeagueInfo(WebSoccer $websoccer, DbConnection $db, $leagueId) {
        $prefix = $websoccer->getConfig('db_prefix');

        $columns = array(
            'id' => 'league_id',
            'name' => 'league_name',
            'land' => 'league_country',
            'division' => 'league_division'
        );

        $result = $db->querySelect($columns, $prefix . '_liga', 'id = %d', (int) $leagueId, 1);
        $league = $result->fetch_array();
        $result->free();

        return $league ? $league : null;
    }

    private static function isParentChainContainingClub(WebSoccer $websoccer, DbConnection $db, $parentClubId, $blockedClubId) {
        $prefix = $websoccer->getConfig('db_prefix');
        $currentParentId = (int) $parentClubId;
        $visited = array();

        while ($currentParentId > 0) {
            if ($currentParentId === (int) $blockedClubId) {
                return true;
            }

            if (isset($visited[$currentParentId])) {
                return true;
            }
            $visited[$currentParentId] = true;

            $result = $db->querySelect('parent_club_id', $prefix . '_verein', 'id = %d', $currentParentId, 1);
            $row = $result->fetch_array();
            $result->free();

            if (!$row || empty($row['parent_club_id'])) {
                break;
            }

            $currentParentId = (int) $row['parent_club_id'];
        }

        return false;
    }

    private static function findLowerLeagueBelowParent(WebSoccer $websoccer, DbConnection $db, array $relationship) {
        if (!strlen((string) $relationship['parent_country']) || !strlen((string) $relationship['parent_division'])) {
            return null;
        }

        $prefix = $websoccer->getConfig('db_prefix');
        $result = $db->querySelect(
            'id, name, land, division',
            $prefix . '_liga',
            "land = '%s' AND division > %d ORDER BY division ASC, name ASC",
            array((string) $relationship['parent_country'], (int) $relationship['parent_division']),
            1
        );

        $league = $result->fetch_array();
        $result->free();

        return $league ? $league : null;
    }

    private static function moveChildClubToLeague(WebSoccer $websoccer, DbConnection $db, $childClubId, $leagueId) {
        $prefix = $websoccer->getConfig('db_prefix');

        $db->queryUpdate(
            array(
                'liga_id' => (int) $leagueId,
                'sa_tore' => 0,
                'sa_gegentore' => 0,
                'sa_spiele' => 0,
                'sa_siege' => 0,
                'sa_niederlagen' => 0,
                'sa_unentschieden' => 0,
                'sa_punkte' => 0,
                'parent_club_status' => self::STATUS_ACTIVE,
                'parent_club_suspended_reason' => ''
            ),
            $prefix . '_verein',
            'id = %d',
            (int) $childClubId
        );
    }

    private static function setRelationshipStatus(WebSoccer $websoccer, DbConnection $db, $childClubId, $status, $reason) {
        $prefix = $websoccer->getConfig('db_prefix');

        $db->queryUpdate(
            array(
                'parent_club_status' => (string) $status,
                'parent_club_suspended_reason' => (string) $reason
            ),
            $prefix . '_verein',
            'id = %d',
            (int) $childClubId
        );
    }
}
?>
