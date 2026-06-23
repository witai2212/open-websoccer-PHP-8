<?php
/******************************************************

  Season rollover orchestration for OpenWebSoccer-Sim.

******************************************************/

/**
 * High-level operations used by the admin season rollover wizard.
 */
class SeasonRolloverDataService {

    public static function getDefaultOptions() {
        $now = time();
        $leagueStart = SeasonRolloverScheduleService::nextWeekday($now, 5, 18, 0);
        $cupStart = SeasonRolloverScheduleService::nextWeekday($leagueStart + 86400, 2, 19, 0);
        $clStart = SeasonRolloverScheduleService::nextWeekday($leagueStart + 86400, 3, 20, 0);
        $ulStart = SeasonRolloverScheduleService::nextWeekday($leagueStart + 86400, 4, 20, 0);

        return array(
            'season_year' => (int) date('Y'),
            'retirement_age' => 35,
            'fire_manager' => 0,
            'popularity_reduction' => 20,
            'missed_penalty' => 0,
            'accomplish_reward' => 0,
            'max_youth_age' => 19,
            'league_start_date' => SeasonRolloverScheduleService::formatGermanDate($leagueStart),
            'national_cup_start_date' => SeasonRolloverScheduleService::formatGermanDate($cupStart),
            'cl_start_date' => SeasonRolloverScheduleService::formatGermanDate($clStart),
            'ul_start_date' => SeasonRolloverScheduleService::formatGermanDate($ulStart),
            'league_rounds' => 2
        );
    }

    public static function readOptionsFromRequest(array $request) {
        $defaults = self::getDefaultOptions();

        return array(
            'season_year' => isset($request['season_year']) ? (int) $request['season_year'] : $defaults['season_year'],
            'retirement_age' => isset($request['retirement_age']) ? (int) $request['retirement_age'] : $defaults['retirement_age'],
            'fire_manager' => !empty($request['fire_manager']) ? 1 : 0,
            'popularity_reduction' => isset($request['popularity_reduction']) ? (int) $request['popularity_reduction'] : $defaults['popularity_reduction'],
            'missed_penalty' => isset($request['missed_penalty']) ? (int) $request['missed_penalty'] : $defaults['missed_penalty'],
            'accomplish_reward' => isset($request['accomplish_reward']) ? (int) $request['accomplish_reward'] : $defaults['accomplish_reward'],
            'max_youth_age' => isset($request['max_youth_age']) ? (int) $request['max_youth_age'] : $defaults['max_youth_age'],
            'league_start_date' => isset($request['league_start_date']) ? trim((string) $request['league_start_date']) : $defaults['league_start_date'],
            'national_cup_start_date' => isset($request['national_cup_start_date']) ? trim((string) $request['national_cup_start_date']) : $defaults['national_cup_start_date'],
            'cl_start_date' => isset($request['cl_start_date']) ? trim((string) $request['cl_start_date']) : $defaults['cl_start_date'],
            'ul_start_date' => isset($request['ul_start_date']) ? trim((string) $request['ul_start_date']) : $defaults['ul_start_date'],
            'league_rounds' => isset($request['league_rounds']) ? (int) $request['league_rounds'] : $defaults['league_rounds']
        );
    }

    public static function validateOptions(array $options) {
        $errors = array();

        if ($options['season_year'] < 1900 || $options['season_year'] > 9999) {
            $errors[] = 'Ungültiges Saisonjahr.';
        }

        if ($options['retirement_age'] < 0 || $options['retirement_age'] > 99) {
            $errors[] = 'Ungültiges Karriereende-Alter.';
        }

        if ($options['popularity_reduction'] < 0 || $options['popularity_reduction'] > 100) {
            $errors[] = 'Ungültige Fanbeliebtheit-Reduzierung.';
        }

        if ($options['missed_penalty'] < 0 || $options['accomplish_reward'] < 0) {
            $errors[] = 'Prämie/Strafe darf nicht negativ sein.';
        }

        if ($options['max_youth_age'] < 0 || $options['max_youth_age'] > 99) {
            $errors[] = 'Ungültiges Jugendspieler-Alter.';
        }

        if ($options['league_rounds'] < 1 || $options['league_rounds'] > 4) {
            $errors[] = 'Ungültige Anzahl an Liga-Runden.';
        }

        foreach (array('league_start_date', 'national_cup_start_date', 'cl_start_date', 'ul_start_date') as $dateKey) {
            try {
                SeasonRolloverScheduleService::parseGermanDate($options[$dateKey], 12, 0);
            } catch (Exception $e) {
                $errors[] = $dateKey . ': ' . $e->getMessage();
            }
        }

        return $errors;
    }

    public static function completeEligibleSeasons(WebSoccer $websoccer, DbConnection $db, I18n $i18n, array $options) {
        $eligibleSeasons = SeasonRolloverValidationService::getEligibleSeasons($websoccer, $db);
        $completedSeasons = array();
        $errors = array();

        foreach ($eligibleSeasons as $season) {
            try {
                $completedSeasons[] = self::completeSingleSeason(
                    $websoccer,
                    $db,
                    $i18n,
                    (int) $season['id'],
                    (int) $options['retirement_age'],
                    !empty($options['fire_manager']),
                    (int) $options['popularity_reduction'],
                    (int) $options['missed_penalty'],
                    (int) $options['accomplish_reward'],
                    (int) $options['max_youth_age']
                );
            } catch (Exception $e) {
                $errors[] = $season['league_name'] . ' / ' . $season['name'] . ': ' . $e->getMessage();
            }
        }

        $globalFinalization = array();
        if (!empty($completedSeasons)) {
            $globalFinalization = self::runGlobalSeasonFinalization($websoccer, $db);
        }

        return array(
            'completed_seasons' => $completedSeasons,
            'global_finalization' => $globalFinalization,
            'errors' => $errors
        );
    }

    public static function completeSingleSeason(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $seasonId, $retirementAge, $fireManager, $popularityReduc, $missedPenalty, $accomplishReward, $maxYouthAge) {
        $prefix = $websoccer->getConfig('db_prefix');

        $result = $db->querySelect(
            '*',
            $prefix . '_saison',
            "id = %d AND beendet = '0'",
            (int) $seasonId,
            1
        );
        $season = $result->fetch_array();
        $result->free();

        if (!$season) {
            throw new Exception('Saison existiert nicht oder ist bereits beendet.');
        }

        $openMatchesResult = $db->querySelect(
            'COUNT(*) AS open_matches',
            $prefix . '_spiel',
            "saison_id = %d AND berechnet = '0'",
            (int) $season['id'],
            1
        );
        $openMatchesRow = $openMatchesResult->fetch_array();
        $openMatchesResult->free();

        if (!empty($openMatchesRow['open_matches'])) {
            throw new Exception('Nicht alle Ligaspiele wurden berechnet.');
        }

        $managerMissionsActive = (class_exists('ManagerMissionsDataService') && ManagerMissionsDataService::isEnabled($websoccer));

        $seasonColumns = array('beendet' => '1');

        $playerResetColumns = array(
            'P.sa_tore',
            'P.sa_spiele',
            'P.sa_karten_gelb',
            'P.sa_karten_gelb_rot',
            'P.sa_karten_rot',
            'P.sa_assists'
        );

        $playersSql = 'UPDATE ' . $prefix . '_spieler AS P INNER JOIN ' . $prefix . '_verein AS T ON T.id = P.verein_id SET ';
        foreach ($playerResetColumns as $playerResetColumn) {
            $playersSql .= $playerResetColumn . ' = 0, ';
        }
        $playersSql .= 'P.age = P.age + 1 WHERE T.liga_id = ' . (int) $season['liga_id'];
        $db->executeQuery($playersSql);

        $playersSql = 'UPDATE ' . $prefix . '_spieler AS P SET ';
        $firstColumn = true;
        foreach ($playerResetColumns as $playerResetColumn) {
            if ($firstColumn) {
                $firstColumn = false;
            } else {
                $playersSql .= ', ';
            }
            $playersSql .= $playerResetColumn . ' = 0';
        }
        $playersSql .= " WHERE P.status = '1' AND (P.verein_id = 0 OR P.verein_id IS NULL)";
        $db->executeQuery($playersSql);

        if ($retirementAge > 0) {
            $ageColumn = 'age';
            if ($websoccer->getConfig('players_aging') == 'birthday') {
                $ageColumn = 'TIMESTAMPDIFF(YEAR, geburtstag, CURDATE())';
            }

            $db->queryUpdate(
                array('P.status' => '0', 'P.verein_id' => NULL),
                $prefix . '_spieler AS P INNER JOIN ' . $prefix . '_verein AS T ON T.id = P.verein_id',
                'T.liga_id = %d AND ' . (int) $retirementAge . ' <= ' . $ageColumn,
                (int) $season['liga_id']
            );
        }

        $moveResult = $db->querySelect(
            'target_league_id, platz_von AS rank_from, platz_bis AS rank_to',
            $prefix . '_tabelle_markierung',
            'liga_id = %d AND target_league_id IS NOT NULL AND target_league_id > 0',
            (int) $season['liga_id']
        );
        $moveConfigs = array();
        while ($moveConfig = $moveResult->fetch_array()) {
            $moveConfigs[] = $moveConfig;
        }
        $moveResult->free();

        $columns = 'id, sponsor_id, min_target_rank, user_id';
        $whereCondition = "liga_id = %d AND sa_spiele > 0 ORDER BY sa_punkte DESC, (sa_tore - sa_gegentore) DESC, sa_siege DESC, sa_unentschieden DESC, sa_tore DESC";
        $result = $db->querySelect($columns, $prefix . '_verein', $whereCondition, (int) $season['liga_id']);

        $rank = 1;
        $processedTeams = 0;

        while ($team = $result->fetch_array()) {
            $processedTeams++;

            if ($rank <= 5) {
                $seasonColumns['platz_' . $rank . '_id'] = (int) $team['id'];

                if ($rank === 1 && !empty($team['sponsor_id'])) {
                    $sponsor = SponsorsDataService::getSponsorinfoByTeamId($websoccer, $db, (int) $team['id']);
                    if ($sponsor && (int) $sponsor['amount_championship'] > 0) {
                        BankAccountDataService::creditAmount(
                            $websoccer,
                            $db,
                            (int) $team['id'],
                            (int) $sponsor['amount_championship'],
                            'sponsor_championship_bonus_subject',
                            $sponsor['name']
                        );
                    }
                }
            }

            if ($managerMissionsActive && !empty($team['user_id']) && (int) $team['user_id'] > 0) {
                ManagerMissionsDataService::finalizeTeamSeasonMissions(
                    $websoccer,
                    $db,
                    $i18n,
                    (int) $team['user_id'],
                    (int) $team['id'],
                    (int) $season['id']
                );
            }

            foreach ($moveConfigs as $moveConfig) {
                if ((int) $moveConfig['rank_from'] <= $rank && (int) $moveConfig['rank_to'] >= $rank) {
                    $db->queryUpdate(
                        array(
                            'liga_id' => (int) $moveConfig['target_league_id'],
                            'sa_tore' => 0,
                            'sa_gegentore' => 0,
                            'sa_spiele' => 0,
                            'sa_siege' => 0,
                            'sa_niederlagen' => 0,
                            'sa_unentschieden' => 0,
                            'sa_punkte' => 0
                        ),
                        $prefix . '_verein',
                        'id = %d',
                        (int) $team['id']
                    );
                    break;
                }
            }

            $db->queryInsert(
                array(
                    'user_id' => !empty($team['user_id']) ? (int) $team['user_id'] : 0,
                    'team_id' => (int) $team['id'],
                    'season_id' => (int) $season['id'],
                    'rank' => (int) $rank,
                    'date_recorded' => $websoccer->getNowAsTimestamp()
                ),
                $prefix . '_achievement'
            );

            if (!empty($team['user_id']) && (int) $team['user_id'] > 0) {
                self::processManagerSeasonTargets(
                    $websoccer,
                    $db,
                    $team,
                    $rank,
                    $managerMissionsActive,
                    $fireManager,
                    $popularityReduc,
                    $missedPenalty,
                    $accomplishReward
                );
            }

            self::processYouthPlayersForSeasonEnd($websoccer, $db, (int) $team['id'], $maxYouthAge);

            $event = new SeasonOfTeamCompletedEvent(
                $websoccer,
                $db,
                $i18n,
                (int) $team['id'],
                (int) $season['id'],
                (int) $rank
            );
            PluginMediator::dispatchEvent($event);

            $rank++;
        }
        $result->free();

        $db->queryUpdate(
            array(
                'sa_tore' => 0,
                'sa_gegentore' => 0,
                'sa_spiele' => 0,
                'sa_siege' => 0,
                'sa_niederlagen' => 0,
                'sa_unentschieden' => 0,
                'sa_punkte' => 0
            ),
            $prefix . '_verein',
            'liga_id = %d',
            (int) $season['liga_id']
        );

        SponsorsDataService::expireContractsForSeason($websoccer, $db, (int) $season['id']);
        StadiumsDataService::expireNamingContractsForSeason($websoccer, $db, (int) $season['id']);

        $db->queryUpdate(
            $seasonColumns,
            $prefix . '_saison',
            'id = %d',
            (int) $season['id']
        );

        $season['processed_teams'] = $processedTeams;
        return $season;
    }

    private static function processManagerSeasonTargets(WebSoccer $websoccer, DbConnection $db, array $team, $rank, $managerMissionsActive, $fireManager, $popularityReduc, $missedPenalty, $accomplishReward) {
        $prefix = $websoccer->getConfig('db_prefix');

        $res = $db->querySelect(
            'id',
            $prefix . '_badge',
            "event = 'completed_season_at_x'
                AND event_benchmark = " . (int) $rank . "
                AND id NOT IN (
                    SELECT badge_id
                    FROM " . $prefix . "_badge_user
                    WHERE user_id = " . (int) $team['user_id'] . "
                )",
            null,
            1
        );
        $badge = $res->fetch_array();
        $res->free();

        if ($badge) {
            BadgesDataService::awardBadge($websoccer, $db, (int) $team['user_id'], (int) $badge['id']);
        }

        if ($managerMissionsActive) {
            return;
        }

        if (!empty($team['min_target_rank']) && (int) $team['min_target_rank'] > 0 && (int) $team['min_target_rank'] < (int) $rank) {
            if ($fireManager) {
                $db->queryUpdate(array('user_id' => NULL), $prefix . '_verein', 'id = %d', (int) $team['id']);
            }

            if ($popularityReduc > 0) {
                $userres = $db->querySelect('fanbeliebtheit', $prefix . '_user', 'id = %d', (int) $team['user_id']);
                $manager = $userres->fetch_array();

                if ($manager) {
                    $popularity = max(1, (int) $manager['fanbeliebtheit'] - (int) $popularityReduc);
                    $db->queryUpdate(array('fanbeliebtheit' => $popularity), $prefix . '_user', 'id = %d', (int) $team['user_id']);
                }
                $userres->free();
            }

            if ($missedPenalty > 0) {
                BankAccountDataService::debitAmount(
                    $websoccer,
                    $db,
                    (int) $team['id'],
                    (int) $missedPenalty,
                    'seasontarget_failed_penalty_subject',
                    $websoccer->getConfig('projectname')
                );
            }
        } elseif (!empty($team['min_target_rank']) && (int) $team['min_target_rank'] > 0 && (int) $team['min_target_rank'] >= (int) $rank && $accomplishReward > 0) {
            BankAccountDataService::creditAmount(
                $websoccer,
                $db,
                (int) $team['id'],
                (int) $accomplishReward,
                'seasontarget_accomplished_reward_subject',
                $websoccer->getConfig('projectname')
            );
        }
    }

    private static function processYouthPlayersForSeasonEnd(WebSoccer $websoccer, DbConnection $db, $teamId, $maxYouthAge) {
        $prefix = $websoccer->getConfig('db_prefix');
        $result = $db->querySelect('id, age', $prefix . '_youthplayer', 'team_id = %d', (int) $teamId);

        while ($youthplayer = $result->fetch_array()) {
            $playerAge = (int) $youthplayer['age'] + 1;

            if ($maxYouthAge > 0 && $maxYouthAge <= $playerAge) {
                $db->queryDelete($prefix . '_youthplayer', 'id = %d', (int) $youthplayer['id']);
            } else {
                $db->queryUpdate(array('age' => $playerAge), $prefix . '_youthplayer', 'id = %d', (int) $youthplayer['id']);
            }
        }

        $result->free();
    }

    public static function runGlobalSeasonFinalization(WebSoccer $websoccer, DbConnection $db) {
        SalaryStatisticsDataService::updateSalaryStats($websoccer, $db);
        BankAccountDataService::payTaxes($websoccer, $db);
        $transferPenaltyDistribution = TransferPenaltyDataService::distributePenalties($websoccer, $db);
        $parentClubConflictResolution = array();
        if (class_exists('ParentClubDataService')) {
            $parentClubConflictResolution = ParentClubDataService::resolveDivisionConflicts($websoccer, $db);
        }

        return array(
            'salary_statistics_updated' => 1,
            'taxes_processed' => 1,
            'transfer_penalty_distribution' => $transferPenaltyDistribution,
            'parent_club_conflict_resolution' => $parentClubConflictResolution
        );
    }

    public static function createNewSeasonsForAllLeagues(WebSoccer $websoccer, DbConnection $db, $year) {
        $prefix = $websoccer->getConfig('db_prefix');
        if (class_exists('ParentClubDataService')) {
            ParentClubDataService::resolveDivisionConflicts($websoccer, $db);
        }
        $seasonName = SeasonNameGeneratorService::getNextSeasonName($websoccer, $db, (int) $year);
        $leagues = SeasonRolloverValidationService::getLeaguesWithoutOpenSeason($websoccer, $db);

        $created = array();
        $errors = array();

        foreach ($leagues as $league) {
            try {
                $db->queryInsert(
                    array(
                        'name' => $seasonName,
                        'liga_id' => (int) $league['league_id'],
                        'beendet' => '0'
                    ),
                    $prefix . '_saison'
                );

                $created[] = array(
                    'id' => $db->getLastInsertedId(),
                    'season_name' => $seasonName,
                    'league' => $league
                );
            } catch (Exception $e) {
                $errors[] = $league['league_country'] . ' / ' . $league['league_name'] . ': ' . $e->getMessage();
            }
        }

        $transferPenaltyDistribution = array();
        if (!empty($created)) {
            $transferPenaltyDistribution = TransferPenaltyDataService::distributePenalties($websoccer, $db);
        }

        return array(
            'season_name' => $seasonName,
            'created_seasons' => $created,
            'transfer_penalty_distribution' => $transferPenaltyDistribution,
            'errors' => $errors
        );
    }

    public static function rebuildUefaTemp(WebSoccer $websoccer, DbConnection $db) {
        $allocation = UefaDataService::updateUefaQualificationPlacesByRanking($websoccer, $db);
        $teams = UefaDataService::getUefaPlacesByLand($websoccer, $db);

        if (method_exists('UefaDataService', 'syncLegacyTempTablesFromUefaTemp')) {
            UefaDataService::syncLegacyTempTablesFromUefaTemp($websoccer, $db);
        }

        $conmebol = null;
        if (class_exists('ConmebolDataService')) {
            try {
                $conmebol = ConmebolDataService::rebuildQualificationAndTempTables($websoccer, $db);
            } catch (Exception $e) {
                $conmebol = array('error' => $e->getMessage());
            }
        }

        return array(
            'allocation' => $allocation,
            'teams' => $teams,
            'team_count' => count($teams),
            'conmebol' => $conmebol
        );
    }

    public static function executeStep(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $step, array $options) {
        $blockingErrors = SeasonRolloverValidationService::getBlockingErrorsForStep($websoccer, $db, $step);
        if (!empty($blockingErrors)) {
            throw new Exception(implode(' ', $blockingErrors));
        }

        switch ($step) {
            case 'end_seasons':
                return self::completeEligibleSeasons($websoccer, $db, $i18n, $options);

            case 'uefa_temp':
                return self::rebuildUefaTemp($websoccer, $db);

            case 'new_seasons':
                return self::createNewSeasonsForAllLeagues($websoccer, $db, (int) $options['season_year']);

            case 'national_cups':
                return SeasonRolloverCupService::generateNationalCups(
                    $websoccer,
                    $db,
                    SeasonRolloverScheduleService::parseGermanDate($options['national_cup_start_date'], 19, 0)
                );

            case 'european_cups':
                return SeasonRolloverCupService::generateEuropeanCups(
                    $websoccer,
                    $db,
                    SeasonRolloverScheduleService::parseGermanDate($options['cl_start_date'], 20, 0),
                    SeasonRolloverScheduleService::parseGermanDate($options['ul_start_date'], 20, 0)
                );

            case 'league_schedules':
                return SeasonRolloverScheduleService::generateLeagueSchedulesForOpenSeasons(
                    $websoccer,
                    $db,
                    SeasonRolloverScheduleService::parseGermanDate($options['league_start_date'], 18, 0),
                    (int) $options['league_rounds']
                );
        }

        throw new Exception('Unbekannter Schritt: ' . $step);
    }

    public static function executeAll(WebSoccer $websoccer, DbConnection $db, I18n $i18n, array $options) {
        $blockingErrors = SeasonRolloverValidationService::getBlockingErrorsForStep($websoccer, $db, 'execute_all');
        if (!empty($blockingErrors)) {
            throw new Exception(implode(' ', $blockingErrors));
        }

        $steps = array('end_seasons', 'uefa_temp', 'new_seasons', 'national_cups', 'european_cups', 'league_schedules');
        $results = array();

        foreach ($steps as $step) {
            $results[$step] = self::executeStep($websoccer, $db, $i18n, $step, $options);
        }

        return $results;
    }
}
?>
