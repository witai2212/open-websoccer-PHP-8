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
 * Returns all seasons that may currently be completed:
 * - season not ended yet
 * - no uncalculated matches left
 */
if (!function_exists('seasonCompletionGetEligibleSeasons')) {
    function seasonCompletionGetEligibleSeasons($db, $conf) {
        
        $columns = 'S.id AS id, S.name AS name, L.name AS league_name';
        
        $fromTable = $conf['db_prefix'] . '_saison AS S';
        $fromTable .= ' INNER JOIN ' . $conf['db_prefix'] . '_liga AS L ON L.id = S.liga_id';
        
        $whereCondition = 'S.beendet = \'0\'
            AND 0 = (
                SELECT COUNT(*)
                FROM ' . $conf['db_prefix'] . '_spiel AS M
                WHERE M.berechnet = \'0\'
                AND M.saison_id = S.id
            )
            ORDER BY L.name ASC, S.name ASC';
        
        $result = $db->querySelect(
            $columns,
            $fromTable,
            $whereCondition
            );
        
        $seasons = array();
        
        while ($season = $result->fetch_array()) {
            $seasons[] = $season;
        }
        
        $result->free();
        
        return $seasons;
    }
}

/**
 * Renders the common settings form fields used for both:
 * - single season completion
 * - bulk completion of all seasons
 */
if (!function_exists('seasonCompletionRenderSettingsFields')) {
    function seasonCompletionRenderSettingsFields($i18n) {
        
        $formFields = array();
        $formFields['playerdisableage']                  = array('type' => 'number',  'value' => 35, 'required' => 'false');
        $formFields['target_missed_firemanager']          = array('type' => 'boolean', 'value' => 0,  'required' => 'false');
        $formFields['target_missed_popularityreduction']  = array('type' => 'percent', 'value' => 20, 'required' => 'false');
        $formFields['target_missed_penalty']              = array('type' => 'number',  'value' => 0,  'required' => 'false');
        $formFields['target_accomplished_reward']         = array('type' => 'number',  'value' => 0,  'required' => 'false');
        $formFields['youthplayers_age_delete']            = array('type' => 'number',  'value' => 19, 'required' => 'false');
        
        foreach ($formFields as $fieldId => $fieldInfo) {
            echo FormBuilder::createFormGroup(
                $i18n,
                $fieldId,
                $fieldInfo,
                $fieldInfo['value'],
                'season_complete_label_'
                );
        }
    }
}


/**
 * Reads and validates settings submitted from the completion form.
 */
if (!function_exists('seasonCompletionReadSubmittedSettings')) {
    function seasonCompletionReadSubmittedSettings($i18n) {
        
        $errors = array();
        
        // Retirement age
        $retirementAgeRaw = isset($_POST['playerdisableage'])
        ? trim((string) $_POST['playerdisableage'])
        : '35';
        
        if ($retirementAgeRaw === '') {
            $retirementAge = 0;
        } elseif (ctype_digit($retirementAgeRaw)) {
            $retirementAge = (int) $retirementAgeRaw;
        } else {
            $retirementAge = 0;
            $errors[] = $i18n->getMessage('validationerror_invalid_value');
        }
        
        // Fire manager checkbox / boolean
        $fireManager = !empty($_POST['target_missed_firemanager']);
        
        // Popularity reduction
        $popularityReducRaw = isset($_POST['target_missed_popularityreduction'])
        ? trim((string) $_POST['target_missed_popularityreduction'])
        : '20';
        
        if ($popularityReducRaw === '') {
            $popularityReduc = 0;
        } elseif (ctype_digit($popularityReducRaw)) {
            $popularityReduc = (int) $popularityReducRaw;
        } else {
            $popularityReduc = 0;
            $errors[] = $i18n->getMessage('validationerror_invalid_value');
        }
        
        // Missed target penalty
        $missedPenaltyRaw = isset($_POST['target_missed_penalty'])
        ? trim((string) $_POST['target_missed_penalty'])
        : '0';
        
        if ($missedPenaltyRaw === '') {
            $missedPenalty = 0.0;
        } elseif (is_numeric($missedPenaltyRaw)) {
            $missedPenalty = (float) $missedPenaltyRaw;
        } else {
            $missedPenalty = 0.0;
            $errors[] = $i18n->getMessage('validationerror_invalid_value');
        }
        
        // Accomplished target reward
        $accomplishRewardRaw = isset($_POST['target_accomplished_reward'])
        ? trim((string) $_POST['target_accomplished_reward'])
        : '0';
        
        if ($accomplishRewardRaw === '') {
            $accomplishReward = 0.0;
        } elseif (is_numeric($accomplishRewardRaw)) {
            $accomplishReward = (float) $accomplishRewardRaw;
        } else {
            $accomplishReward = 0.0;
            $errors[] = $i18n->getMessage('validationerror_invalid_value');
        }
        
        // Youth player deletion age
        $maxYouthAgeRaw = isset($_POST['youthplayers_age_delete'])
        ? trim((string) $_POST['youthplayers_age_delete'])
        : '19';
        
        if ($maxYouthAgeRaw === '') {
            $maxYouthAge = 0;
        } elseif (ctype_digit($maxYouthAgeRaw)) {
            $maxYouthAge = (int) $maxYouthAgeRaw;
        } else {
            $maxYouthAge = 0;
            $errors[] = $i18n->getMessage('validationerror_invalid_value');
        }
        
        // Logical validation
        if ($retirementAge < 0) {
            $errors[] = $i18n->getMessage('validationerror_invalid_value');
        }
        
        if ($popularityReduc < 0 || $popularityReduc > 100) {
            $errors[] = $i18n->getMessage('validationerror_invalid_value');
        }
        
        if ($missedPenalty < 0) {
            $errors[] = $i18n->getMessage('validationerror_invalid_value');
        }
        
        if ($accomplishReward < 0) {
            $errors[] = $i18n->getMessage('validationerror_invalid_value');
        }
        
        if ($maxYouthAge < 0) {
            $errors[] = $i18n->getMessage('validationerror_invalid_value');
        }
        
        return array(
            'errors'            => $errors,
            'retirementAge'     => $retirementAge,
            'fireManager'       => $fireManager,
            'popularityReduc'   => $popularityReduc,
            'missedPenalty'     => $missedPenalty,
            'accomplishReward'  => $accomplishReward,
            'maxYouthAge'       => $maxYouthAge
        );
    }
}


/**
 * Completes one single season.
 *
 * This contains the entire per-season logic:
 * - validates season state
 * - resets stats
 * - retires players
 * - processes promotions/relegations
 * - applies target penalties/rewards
 * - processes youth players
 * - stores season achievements
 * - dispatches season completed event
 */
if (!function_exists('seasonCompletionCompleteSingleSeason')) {
    function seasonCompletionCompleteSingleSeason(
        $website,
        $db,
        $i18n,
        $conf,
        $seasonId,
        $retirementAge,
        $fireManager,
        $popularityReduc,
        $missedPenalty,
        $accomplishReward,
        $maxYouthAge
        ) {
            
            // Load season and ensure it is still open
            $columns = '*';
            $whereCondition = 'id = %d AND beendet = \'0\'';
            
            $result = $db->querySelect(
                $columns,
                $conf['db_prefix'] . '_saison',
                $whereCondition,
                (int) $seasonId,
                1
                );
            
            $season = $result->fetch_array();
            
            if (!$season) {
                $result->free();
                throw new Exception('Invalid request - Season does not exist or is already completed.');
            }
            
            $result->free();
            
            
            // Final safety check: no uncalculated matches may remain
            $openMatchesResult = $db->querySelect(
                'COUNT(*) AS open_matches',
                $conf['db_prefix'] . '_spiel',
                'saison_id = %d AND berechnet = \'0\'',
                (int) $season['id'],
                1
                );
            
            $openMatchesRow = $openMatchesResult->fetch_array();
            $openMatchesResult->free();
            
            if (!empty($openMatchesRow['open_matches'])) {
                throw new Exception('Season cannot be completed because not all matches have been calculated.');
            }

            $managerMissionsActive = (
                class_exists('ManagerMissionsDataService')
                && ManagerMissionsDataService::isEnabled($website)
            );
            
            
            $seasoncolumns = array();
            $seasoncolumns['beendet'] = '1';
            
            
            // Reset player statistics for teams in this league and increase age
            $playersSql = 'UPDATE ' . $conf['db_prefix'] . '_spieler AS P';
            $playersSql .= ' INNER JOIN ' . $conf['db_prefix'] . '_verein AS T ON T.id = P.verein_id';
            $playersSql .= ' SET ';
            
            $playerResetColumns = array(
                'P.sa_tore',
                'P.sa_spiele',
                'P.sa_karten_gelb',
                'P.sa_karten_gelb_rot',
                'P.sa_karten_rot',
                'P.sa_assists'
            );
            
            foreach ($playerResetColumns as $playerResetColumn) {
                $playersSql .= $playerResetColumn . ' = 0, ';
            }
            
            $playersSql .= 'P.age = P.age + 1';
            $playersSql .= ' WHERE T.liga_id = ' . (int) $season['liga_id'];
            
            $db->executeQuery($playersSql);
            
            
            // Reset statistics of players without team
            $playersSql = 'UPDATE ' . $conf['db_prefix'] . '_spieler AS P';
            $playersSql .= ' SET ';
            
            $firstColumn = true;
            
            foreach ($playerResetColumns as $playerResetColumn) {
                if ($firstColumn) {
                    $firstColumn = false;
                } else {
                    $playersSql .= ', ';
                }
                
                $playersSql .= $playerResetColumn . ' = 0';
            }
            
            $playersSql .= ' WHERE P.status = \'1\'';
            $playersSql .= ' AND (P.verein_id = 0 OR P.verein_id IS NULL)';
            
            $db->executeQuery($playersSql);
            
            
            // Disable old players: set disabled state and remove club assignment
            if ($retirementAge > 0) {
                
                $ageColumn = 'age';
                
                if ($conf['players_aging'] == 'birthday') {
                    $ageColumn = 'TIMESTAMPDIFF(YEAR, geburtstag, CURDATE())';
                }
                
                $retiredcolumns = array();
                $retiredcolumns['P.status'] = '0';
                $retiredcolumns['P.verein_id'] = NULL;
                
                $whereCondition = 'T.liga_id = %d AND ' . (int) $retirementAge . ' <= ' . $ageColumn;
                
                $db->queryUpdate(
                    $retiredcolumns,
                    $conf['db_prefix'] . '_spieler AS P INNER JOIN ' . $conf['db_prefix'] . '_verein AS T ON T.id = P.verein_id',
                    $whereCondition,
                    (int) $season['liga_id']
                    );
            }
            
            
            // Get configurations for league changes
            $result = $db->querySelect(
                'target_league_id, platz_von AS rank_from, platz_bis AS rank_to',
                $conf['db_prefix'] . '_tabelle_markierung',
                'liga_id = %d AND target_league_id IS NOT NULL AND target_league_id > 0',
                (int) $season['liga_id']
                );
            
            $moveConfigs = array();
            
            while ($moveConfig = $result->fetch_array()) {
                $moveConfigs[] = $moveConfig;
            }
            
            $result->free();
            
            
            // Get teams in ranking order
            $columns = 'id, sponsor_id, min_target_rank, user_id';
            $fromTable = $conf['db_prefix'] . '_verein';
            
            $whereCondition = 'liga_id = %d
            AND sa_spiele > 0
            ORDER BY
                sa_punkte DESC,
                (sa_tore - sa_gegentore) DESC,
                sa_siege DESC,
                sa_unentschieden DESC,
                sa_tore DESC';
            
            $result = $db->querySelect(
                $columns,
                $fromTable,
                $whereCondition,
                (int) $season['liga_id']
                );
            
            $rank = 1;
            
            while ($team = $result->fetch_array()) {
                
                // Update achievement of first 5 teams and pay sponsor premium to champion
                if ($rank <= 5) {
                    
                    $seasoncolumns['platz_' . $rank . '_id'] = (int) $team['id'];
                    
                    // Pay sponsor premium to champion only.
                    // Dynamic contracts use fixed snapshots from sponsor_contract.
                    if ($rank === 1 && !empty($team['sponsor_id'])) {
                        $sponsor = SponsorsDataService::getSponsorinfoByTeamId($website, $db, (int) $team['id']);
                        
                        if ($sponsor && (int) $sponsor['amount_championship'] > 0) {
                            BankAccountDataService::creditAmount(
                                $website,
                                $db,
                                $team['id'],
                                (int) $sponsor['amount_championship'],
                                'sponsor_championship_bonus_subject',
                                $sponsor['name']
                                );
                        }
                    }
                }


                // Finalize current-season manager missions before league moves and stat resets.
                // The old min_target_rank season-end reward/penalty block is skipped below
                // while Manager Missions are active, so the mission table is the single source of truth.
                if (
                    $managerMissionsActive
                    && !empty($team['user_id'])
                    && (int) $team['user_id'] > 0
                ) {
                    ManagerMissionsDataService::finalizeTeamSeasonMissions(
                        $website,
                        $db,
                        $i18n,
                        (int) $team['user_id'],
                        (int) $team['id'],
                        (int) $season['id']
                    );
                }
                
                
                // Move to new league if applicable
                foreach ($moveConfigs as $moveConfig) {
                    
                    if (
                        $moveConfig['rank_from'] <= $rank
                        && $moveConfig['rank_to'] >= $rank
                        ) {
                            $teamcolumns = array();
                            $teamcolumns['liga_id'] = (int) $moveConfig['target_league_id'];
                            $teamcolumns['sa_tore'] = 0;
                            $teamcolumns['sa_gegentore'] = 0;
                            $teamcolumns['sa_spiele'] = 0;
                            $teamcolumns['sa_siege'] = 0;
                            $teamcolumns['sa_niederlagen'] = 0;
                            $teamcolumns['sa_unentschieden'] = 0;
                            $teamcolumns['sa_punkte'] = 0;
                            
                            $db->queryUpdate(
                                $teamcolumns,
                                $conf['db_prefix'] . '_verein',
                                'id = %d',
                                (int) $team['id']
                                );
                            
                            break;
                        }
                }
                
                
                // Always create achievement log for team history.
                // user_id may be NULL for unmanaged teams.
                $db->queryInsert(
                    array(
                        'user_id'       => !empty($team['user_id']) ? (int) $team['user_id'] : NULL,
                        'team_id'       => (int) $team['id'],
                        'season_id'     => (int) $season['id'],
                        'rank'          => (int) $rank,
                        'date_recorded' => $website->getNowAsTimestamp()
                    ),
                    $conf['db_prefix'] . '_achievement'
                    );
                
                
                // Manager-related processing
                if (!empty($team['user_id']) && $team['user_id'] > 0) {
                    
                    // Assign badge if applicable
                    $res = $db->querySelect(
                        'id',
                        $conf['db_prefix'] . '_badge',
                        'event = \'completed_season_at_x\'
                            AND event_benchmark = ' . (int) $rank . '
                            AND id NOT IN (
                                SELECT badge_id
                                FROM ' . $conf['db_prefix'] . '_badge_user
                                WHERE user_id = ' . (int) $team['user_id'] . '
                            )',
                                            null, 1
                        );
                    
                    $badge = $res->fetch_array();
                    $res->free();
                    
                    if ($badge) {
                        BadgesDataService::awardBadge(
                            $website,
                            $db,
                            $team['user_id'],
                            $badge['id']
                            );
                    }
                    
                    
                    // Legacy season target check.
                    // When Manager Missions are enabled, this is intentionally skipped because
                    // cm23_manager_mission already applied the new board rules above.
                    if (!$managerMissionsActive) {
                        if (
                            !empty($team['min_target_rank'])
                            && $team['min_target_rank'] > 0
                            && $team['min_target_rank'] < $rank
                            ) {
                                
                                if ($fireManager) {
                                    $db->queryUpdate(
                                        array('user_id' => NULL),
                                        $conf['db_prefix'] . '_verein',
                                        'id = %d',
                                        (int) $team['id']
                                        );
                                    PlayersDataService::resetUnsellableForTeam($website, $db, (int) $team['id']);
                                }
                                
                                if ($popularityReduc > 0) {
                                    
                                    $userres = $db->querySelect(
                                        'fanbeliebtheit',
                                        $conf['db_prefix'] . '_user',
                                        'id = %d',
                                        (int) $team['user_id']
                                        );
                                    
                                    $manager = $userres->fetch_array();
                                    
                                    if ($manager) {
                                        $popularity = max(
                                            1,
                                            (int) $manager['fanbeliebtheit'] - $popularityReduc
                                            );
                                        
                                        $db->queryUpdate(
                                            array('fanbeliebtheit' => $popularity),
                                            $conf['db_prefix'] . '_user',
                                            'id = %d',
                                            (int) $team['user_id']
                                            );
                                    }
                                    
                                    $userres->free();
                                }
                                
                                if ($missedPenalty > 0) {
                                    BankAccountDataService::debitAmount(
                                        $website,
                                        $db,
                                        $team['id'],
                                        $missedPenalty,
                                        'seasontarget_failed_penalty_subject',
                                        $website->getConfig('projectname')
                                        );
                                }
                                
                            } elseif (
                                !empty($team['min_target_rank'])
                                && $team['min_target_rank'] > 0
                                && $team['min_target_rank'] >= $rank
                                && $accomplishReward > 0
                                ) {
                                    BankAccountDataService::creditAmount(
                                        $website,
                                        $db,
                                        $team['id'],
                                        $accomplishReward,
                                        'seasontarget_accomplished_reward_subject',
                                        $website->getConfig('projectname')
                                        );
                            }
                    }
                }
                
                
                // Increase age of youth players
                $youthresult = $db->querySelect(
                    'id, age',
                    $conf['db_prefix'] . '_youthplayer',
                    'team_id = %d',
                    (int) $team['id']
                    );
                
                while ($youthplayer = $youthresult->fetch_array()) {
                    
                    $playerage = (int) $youthplayer['age'] + 1;
                    
                    if ($maxYouthAge > 0 && $maxYouthAge <= $playerage) {
                        
                        // Delete youth player who reached or exceeded max age
                        $db->queryDelete(
                            $conf['db_prefix'] . '_youthplayer',
                            'id = %d',
                            (int) $youthplayer['id']
                            );
                        
                    } else {
                        
                        // Update youth player age
                        $db->queryUpdate(
                            array('age' => $playerage),
                            $conf['db_prefix'] . '_youthplayer',
                            'id = %d',
                            (int) $youthplayer['id']
                            );
                    }
                }
                
                $youthresult->free();
                
                
                // Dispatch event
                $event = new SeasonOfTeamCompletedEvent(
                    $website,
                    $db,
                    $i18n,
                    $team['id'],
                    $season['id'],
                    $rank
                    );
                
                PluginMediator::dispatchEvent($event);
                
                $rank++;
            }
            
            $result->free();
            
            
            // Reset club statistics of teams which have NOT moved to another league
            $teamcolumns = array();
            $teamcolumns['sa_tore'] = 0;
            $teamcolumns['sa_gegentore'] = 0;
            $teamcolumns['sa_spiele'] = 0;
            $teamcolumns['sa_siege'] = 0;
            $teamcolumns['sa_niederlagen'] = 0;
            $teamcolumns['sa_unentschieden'] = 0;
            $teamcolumns['sa_punkte'] = 0;
            
            $db->queryUpdate(
                $teamcolumns,
                $conf['db_prefix'] . '_verein',
                'liga_id = %d',
                (int) $season['liga_id']
                );
            
            
            // Sponsor contracts run for the whole season and expire here.
            SponsorsDataService::expireContractsForSeason($website, $db, (int) $season['id']);
            StadiumsDataService::expireNamingContractsForSeason($website, $db, (int) $season['id']);
            
            // Update season record
            $db->queryUpdate(
                $seasoncolumns,
                $conf['db_prefix'] . '_saison',
                'id = %d',
                (int) $season['id']
                );
            
            return $season;
    }
}


/**
 * Runs global steps which must happen after season completion.
 *
 * For bulk completion this is called only once after all completed seasons.
 */
if (!function_exists('seasonCompletionRunGlobalFinalization')) {
    function seasonCompletionRunGlobalFinalization($website, $db, $i18n) {
        
        // Update salary history
        SalaryStatisticsDataService::updateSalaryStats($website, $db);
        
        // Pay taxes
        try {
            BankAccountDataService::payTaxes($website, $db);
        } catch (Exception $e) {
            echo createErrorMessage(
                $i18n->getMessage('subpage_error_title'),
                $e->getMessage()
                );
        }
        
        // Distribute transfer penalties
        TransferPenaltyDataService::distributePenalties($website, $db);
        
        // Enforce parent-club division rule after all season movements.
        if (class_exists('ParentClubDataService')) {
            $parentClubActions = ParentClubDataService::resolveDivisionConflicts($website, $db);
            if (!empty($parentClubActions)) {
                echo '<div class="alert alert-info"><strong>Mutterverein-Regel:</strong> ' . count($parentClubActions) . ' Divisionskonflikt(e) wurden verarbeitet.</div>';
            }
        }
    }
}



$mainTitle = $i18n->getMessage('season_complete_title');

// Sanitize inputs
$id = isset($_REQUEST['id']) ? (int) $_REQUEST['id'] : 0;

$show = isset($_REQUEST['show'])
? preg_replace('/[^a-zA-Z0-9_]/', '', (string) $_REQUEST['show'])
: '';

echo '<h1>' . escapeOutput($mainTitle) . '</h1>';

if (!$admin['r_admin'] && !$admin['r_demo'] && !$admin[$page['permissionrole']]) {
    throw new Exception($i18n->getMessage('error_access_denied'));
}


//********** Pick season **********
if (!$show) {
    
    ?>
    <p><?php echo $i18n->getMessage('season_complete_introduction'); ?></p>
    <?php

    $eligibleSeasons = seasonCompletionGetEligibleSeasons($db, $conf);

    if (empty($eligibleSeasons)) {

        echo '<p><strong>' . $i18n->getMessage('season_complete_noseasons') . '</strong></p>';

    } else {

        ?>
        <table class="table table-striped">
            <thead>
                <tr>
                    <th><?php echo $i18n->getMessage('entity_season_name'); ?></th>
                    <th><?php echo $i18n->getMessage('entity_season_liga_id'); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php
            foreach ($eligibleSeasons as $season) {
                echo '<tr>';
                echo '<td><a href="?site=' . escapeOutput($site) . '&amp;show=select&amp;id=' . (int) $season['id'] . '">' . escapeOutput($season['name']) . '</a></td>';
                echo '<td>' . escapeOutput($season['league_name']) . '</td>';
                echo '</tr>';
            }
            ?>
            </tbody>
        </table>

        <p>
            <a class="btn btn-primary" href="?site=<?php echo escapeOutput($site); ?>&amp;show=select_all">
                Complete all ready seasons
            </a>
        </p>
        <?php
    }


//********** Selected single season **********
} elseif ($show == 'select') {

    if ($id <= 0) {
        throw new Exception('Invalid URL - No valid ID provided.');
    }

    // Load season and ensure it is still open
    $columns = '*';
    $whereCondition = 'id = %d AND beendet = \'0\'';

    $result = $db->querySelect(
        $columns,
        $conf['db_prefix'] . '_saison',
        $whereCondition,
        $id,
        1
    );

    $season = $result->fetch_array();

    if (!$season) {
        $result->free();
        throw new Exception('Invalid URL - Season does not exist or is already completed.');
    }

    $result->free();


    // Ensure that all matches are already calculated
    $openMatchesResult = $db->querySelect(
        'COUNT(*) AS open_matches',
        $conf['db_prefix'] . '_spiel',
        'saison_id = %d AND berechnet = \'0\'',
        (int) $id,
        1
    );

    $openMatchesRow = $openMatchesResult->fetch_array();
    $openMatchesResult->free();

    if (!empty($openMatchesRow['open_matches'])) {
        throw new Exception('Season cannot be completed because not all matches have been calculated.');
    }

    ?>
    <form action="<?php echo htmlspecialchars($_SERVER['PHP_SELF'], ENT_QUOTES, 'UTF-8'); ?>" method="post" class="form-horizontal">
        <input type="hidden" name="show" value="complete">
        <input type="hidden" name="id" value="<?php echo (int) $id; ?>">
        <input type="hidden" name="site" value="<?php echo escapeOutput($site); ?>">

        <fieldset>
            <legend><?php echo escapeOutput($season['name']); ?></legend>

            <?php seasonCompletionRenderSettingsFields($i18n); ?>
        </fieldset>

        <div class="form-actions">
            <input
                type="submit"
                class="btn btn-primary"
                accesskey="s"
                title="Alt + s"
                value="<?php echo $i18n->getMessage('season_complete_submit'); ?>"
            >
            <input
                type="reset"
                class="btn"
                value="<?php echo $i18n->getMessage('button_reset'); ?>"
            >
        </div>
    </form>
    <?php


//********** Select all ready seasons **********
} elseif ($show == 'select_all') {

    $eligibleSeasons = seasonCompletionGetEligibleSeasons($db, $conf);

    if (empty($eligibleSeasons)) {

        echo '<p><strong>' . $i18n->getMessage('season_complete_noseasons') . '</strong></p>';
        echo '<p>&raquo; <a href="?site=' . escapeOutput($site) . '">' . $i18n->getMessage('back_label') . '</a></p>';

    } else {

        ?>
        <p>
            The following seasons are ready and will be completed together with the same settings:
        </p>

        <table class="table table-striped">
            <thead>
                <tr>
                    <th><?php echo $i18n->getMessage('entity_season_name'); ?></th>
                    <th><?php echo $i18n->getMessage('entity_season_liga_id'); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php
            foreach ($eligibleSeasons as $season) {
                echo '<tr>';
                echo '<td>' . escapeOutput($season['name']) . '</td>';
                echo '<td>' . escapeOutput($season['league_name']) . '</td>';
                echo '</tr>';
            }
            ?>
            </tbody>
        </table>

        <form action="<?php echo htmlspecialchars($_SERVER['PHP_SELF'], ENT_QUOTES, 'UTF-8'); ?>" method="post" class="form-horizontal">
            <input type="hidden" name="show" value="complete_all">
            <input type="hidden" name="site" value="<?php echo escapeOutput($site); ?>">

            <fieldset>
                <legend>Complete all ready seasons</legend>

                <?php seasonCompletionRenderSettingsFields($i18n); ?>
            </fieldset>

            <div class="form-actions">
                <input
                    type="submit"
                    class="btn btn-primary"
                    accesskey="s"
                    title="Alt + s"
                    value="Complete all ready seasons"
                >
                <input
                    type="reset"
                    class="btn"
                    value="<?php echo $i18n->getMessage('button_reset'); ?>"
                >
            </div>
        </form>
        <?php
    }


//********** Complete single season **********
} elseif ($show == 'complete') {

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method.');
    }

    $err = array();

    if ($admin['r_demo']) {
        $err[] = $i18n->getMessage('validationerror_no_changes_as_demo');
    }

    if ($id <= 0) {
        $err[] = $i18n->getMessage('validationerror_invalid_value');
    }

    $settings = seasonCompletionReadSubmittedSettings($i18n);

    if (!empty($settings['errors'])) {
        $err = array_merge($err, $settings['errors']);
    }

    if (!empty($err)) {

        include('validationerror.inc.php');

    } else {

        seasonCompletionCompleteSingleSeason(
            $website,
            $db,
            $i18n,
            $conf,
            $id,
            $settings['retirementAge'],
            $settings['fireManager'],
            $settings['popularityReduc'],
            $settings['missedPenalty'],
            $settings['accomplishReward'],
            $settings['maxYouthAge']
        );

        seasonCompletionRunGlobalFinalization($website, $db, $i18n);

        echo createSuccessMessage(
            $i18n->getMessage('alert_save_success'),
            ''
        );

        echo '<p>&raquo; <a href="?site=' . escapeOutput($site) . '">' . $i18n->getMessage('back_label') . '</a></p>';
    }


//********** Complete all ready seasons **********
} elseif ($show == 'complete_all') {

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method.');
    }

    $err = array();

    if ($admin['r_demo']) {
        $err[] = $i18n->getMessage('validationerror_no_changes_as_demo');
    }

    $settings = seasonCompletionReadSubmittedSettings($i18n);

    if (!empty($settings['errors'])) {
        $err = array_merge($err, $settings['errors']);
    }

    if (!empty($err)) {

        include('validationerror.inc.php');

    } else {

        // Re-check eligible seasons at the exact moment of submission
        $eligibleSeasons = seasonCompletionGetEligibleSeasons($db, $conf);

        if (empty($eligibleSeasons)) {

            echo '<p><strong>' . $i18n->getMessage('season_complete_noseasons') . '</strong></p>';
            echo '<p>&raquo; <a href="?site=' . escapeOutput($site) . '">' . $i18n->getMessage('back_label') . '</a></p>';

        } else {

            $completedSeasons = array();
            $bulkErrors = array();

            foreach ($eligibleSeasons as $eligibleSeason) {

                try {

                    seasonCompletionCompleteSingleSeason(
                        $website,
                        $db,
                        $i18n,
                        $conf,
                        (int) $eligibleSeason['id'],
                        $settings['retirementAge'],
                        $settings['fireManager'],
                        $settings['popularityReduc'],
                        $settings['missedPenalty'],
                        $settings['accomplishReward'],
                        $settings['maxYouthAge']
                    );

                    $completedSeasons[] = $eligibleSeason;

                } catch (Exception $e) {

                    $bulkErrors[] =
                        escapeOutput($eligibleSeason['league_name'])
                        . ' / '
                        . escapeOutput($eligibleSeason['name'])
                        . ': '
                        . $e->getMessage();
                }
            }


            // Global finalization only once after all successfully completed seasons
            if (!empty($completedSeasons)) {

                seasonCompletionRunGlobalFinalization($website, $db, $i18n);

                echo createSuccessMessage(
                    $i18n->getMessage('alert_save_success'),
                    ''
                );

                echo '<p><strong>' . count($completedSeasons) . ' season(s) completed.</strong></p>';

                echo '<table class="table table-striped">';
                echo '<thead>';
                echo '<tr>';
                echo '<th>' . $i18n->getMessage('entity_season_name') . '</th>';
                echo '<th>' . $i18n->getMessage('entity_season_liga_id') . '</th>';
                echo '</tr>';
                echo '</thead>';
                echo '<tbody>';

                foreach ($completedSeasons as $completedSeason) {
                    echo '<tr>';
                    echo '<td>' . escapeOutput($completedSeason['name']) . '</td>';
                    echo '<td>' . escapeOutput($completedSeason['league_name']) . '</td>';
                    echo '</tr>';
                }

                echo '</tbody>';
                echo '</table>';
            }


            // Show bulk errors, if any single season could not be completed
            if (!empty($bulkErrors)) {
                echo createErrorMessage(
                    $i18n->getMessage('subpage_error_title'),
                    implode('<br>', $bulkErrors)
                );
            }

            echo '<p>&raquo; <a href="?site=' . escapeOutput($site) . '">' . $i18n->getMessage('back_label') . '</a></p>';
        }
    }


} else {

    throw new Exception('Invalid request.');
}
?>