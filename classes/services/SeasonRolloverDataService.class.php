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
        $libStart = SeasonRolloverScheduleService::nextWeekday($leagueStart + 86400, 2, 20, 0);
        $sudStart = SeasonRolloverScheduleService::nextWeekday($leagueStart + 86400, 4, 20, 0);
        $concacafStart = SeasonRolloverScheduleService::nextWeekday($leagueStart + 86400, 2, 19, 0);

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
            'conmebol_lib_start_date' => SeasonRolloverScheduleService::formatGermanDate($libStart),
            'conmebol_sud_start_date' => SeasonRolloverScheduleService::formatGermanDate($sudStart),
            'concacaf_start_date' => SeasonRolloverScheduleService::formatGermanDate($concacafStart),
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
            'conmebol_lib_start_date' => isset($request['conmebol_lib_start_date']) ? trim((string) $request['conmebol_lib_start_date']) : $defaults['conmebol_lib_start_date'],
            'conmebol_sud_start_date' => isset($request['conmebol_sud_start_date']) ? trim((string) $request['conmebol_sud_start_date']) : $defaults['conmebol_sud_start_date'],
            'concacaf_start_date' => isset($request['concacaf_start_date']) ? trim((string) $request['concacaf_start_date']) : $defaults['concacaf_start_date'],
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

        foreach (array('league_start_date', 'national_cup_start_date', 'cl_start_date', 'ul_start_date', 'conmebol_lib_start_date', 'conmebol_sud_start_date', 'concacaf_start_date') as $dateKey) {
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

        $retirementSummary = array('retired_players' => 0, 'replacement_players' => 0);
        if ($retirementAge > 0) {
            $ageColumn = 'P.age';
            if ($websoccer->getConfig('players_aging') == 'birthday') {
                $ageColumn = 'TIMESTAMPDIFF(YEAR, P.geburtstag, CURDATE())';
            }

            $retirementSummary = self::retirePlayersOfLeagueAndCreateReplacements(
                $websoccer,
                $db,
                (int) $season['liga_id'],
                (int) $retirementAge,
                $ageColumn
            );
        }

        if (class_exists('ComputerYouthTeamsDataService')) {
            ComputerYouthTeamsDataService::promoteEligibleYouthPlayersForLeague($websoccer, $db, (int) $season['liga_id'], true);
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

            self::processYouthPlayersForSeasonEnd($websoccer, $db, $i18n, (int) $team['id'], !empty($team['user_id']) ? (int) $team['user_id'] : 0, $maxYouthAge);

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
        $season['retirement_summary'] = $retirementSummary;
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

    private static function processYouthPlayersForSeasonEnd(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $teamId, $userId, $maxYouthAge) {
        $prefix = $websoccer->getConfig('db_prefix');
        $result = $db->querySelect('*', $prefix . '_youthplayer', 'team_id = %d', (int) $teamId);

        while ($youthplayer = $result->fetch_array()) {
            $playerAge = (int) $youthplayer['age'] + 1;
            $playerName = trim($youthplayer['firstname'] . ' ' . $youthplayer['lastname']);

            if ($maxYouthAge > 0 && $maxYouthAge <= $playerAge) {
                $professionalPlayerId = self::convertYouthPlayerToFreeProfessional($websoccer, $db, $youthplayer, $playerAge);
                if ((int) $userId > 0) {
                    self::sendSeasonRolloverInboxMessage(
                        $websoccer,
                        $db,
                        $i18n,
                        (int) $userId,
                        'Jugendspieler wurde freigegeben',
                        'Der Jugendspieler ' . $playerName . ' hat das Höchstalter erreicht und wurde als vertragsloser Profispieler freigegeben. Spieler-ID: ' . (int) $professionalPlayerId . '.'
                    );
                }
            } else {
                $db->queryUpdate(array('age' => $playerAge), $prefix . '_youthplayer', 'id = %d', (int) $youthplayer['id']);

                if ((int) $userId > 0 && $maxYouthAge > 0 && ($maxYouthAge - 1) <= $playerAge) {
                    self::sendSeasonRolloverInboxMessage(
                        $websoccer,
                        $db,
                        $i18n,
                        (int) $userId,
                        'Jugendspieler vor Altersgrenze',
                        'Der Jugendspieler ' . $playerName . ' ist jetzt ' . (int) $playerAge . ' Jahre alt. Wenn er nicht vor dem nächsten Saisonwechsel in den Profikader übernommen wird, wird er freigegeben.'
                    );
                }
            }
        }

        $result->free();
    }

    private static function retirePlayersOfLeagueAndCreateReplacements(WebSoccer $websoccer, DbConnection $db, $leagueId, $retirementAge, $ageColumnSql) {
        $prefix = $websoccer->getConfig('db_prefix');
        $fromTable = $prefix . '_spieler AS P INNER JOIN ' . $prefix . '_verein AS T ON T.id = P.verein_id INNER JOIN ' . $prefix . '_liga AS L ON L.id = T.liga_id';
        $columns = 'P.*, T.id AS team_id, T.user_id AS team_user_id, L.land AS league_country, T.strength AS team_strength';
        $whereCondition = 'T.liga_id = %d AND P.status = \'1\' AND ' . (int) $retirementAge . ' <= ' . $ageColumnSql;

        $retiringPlayers = array();
        $result = $db->querySelect($columns, $fromTable, $whereCondition, (int) $leagueId);
        while ($player = $result->fetch_array()) {
            $retiringPlayers[] = $player;
        }
        $result->free();

        foreach ($retiringPlayers as $player) {
            $db->queryUpdate(array('status' => '0'), $prefix . '_spieler', 'id = %d', (int) $player['id']);
            $db->executeQuery('UPDATE ' . $prefix . '_spieler SET verein_id = NULL WHERE id = ' . (int) $player['id']);
        }

        $replacementCount = self::createReplacementPlayersForCpuTeams($websoccer, $db, $retiringPlayers);

        return array(
            'retired_players' => count($retiringPlayers),
            'replacement_players' => (int) $replacementCount
        );
    }

    private static function createReplacementPlayersForCpuTeams(WebSoccer $websoccer, DbConnection $db, array $retiringPlayers) {
        if (empty($retiringPlayers)) {
            return 0;
        }

        $created = 0;
        foreach ($retiringPlayers as $retiredPlayer) {
            if (!empty($retiredPlayer['team_user_id']) && (int) $retiredPlayer['team_user_id'] > 0) {
                continue;
            }

            $teamId = isset($retiredPlayer['team_id']) ? (int) $retiredPlayer['team_id'] : 0;
            if ($teamId <= 0) {
                continue;
            }

            self::createSeasonReplacementPlayer($websoccer, $db, $teamId, $retiredPlayer);
            $created++;
        }

        return $created;
    }

    private static function createSeasonReplacementPlayer(WebSoccer $websoccer, DbConnection $db, $teamId, array $templatePlayer) {
        $strength = max(25, min(70, (int) round(((int) $templatePlayer['w_staerke'] * 0.75) + mt_rand(-4, 8))));
        $talent = PlayerTalentDataService::generateTalent($websoccer);
        $maxStrength = PlayerTalentDataService::generateMaximumStrength($talent, $strength);
        $age = mt_rand(18, 22);
        $birthday = date('Y-m-d', strtotime('-' . $age . ' years', $websoccer->getNowAsTimestamp()));
        $position = isset($templatePlayer['position']) ? $templatePlayer['position'] : 'Mittelfeld';
        $mainPosition = self::normalizeMainPositionForPosition($position, isset($templatePlayer['position_main']) ? $templatePlayer['position_main'] : '');
        $country = isset($templatePlayer['league_country']) && strlen($templatePlayer['league_country']) ? $templatePlayer['league_country'] : (isset($templatePlayer['nation']) ? $templatePlayer['nation'] : 'Deutschland');
        $salary = max(1000, (int) round(max(1, (int) $templatePlayer['vertrag_gehalt']) * 0.45));

        list($firstName, $lastName) = self::getFallbackGeneratedName($country);

        $columns = array(
            'verein_id' => (int) $teamId,
            'vorname' => $firstName,
            'nachname' => $lastName,
            'geburtstag' => $birthday,
            'age' => $age,
            'position' => $position,
            'position_main' => $mainPosition,
            'nation' => $country,
            'w_staerke' => $strength,
            'w_staerke_max' => $maxStrength,
            'w_technik' => self::randomSkillNear($strength),
            'w_kondition' => mt_rand(55, 82),
            'w_frische' => mt_rand(65, 95),
            'w_zufriedenheit' => mt_rand(55, 85),
            'w_talent' => $talent,
            'personality' => class_exists('PlayerPersonalityDataService') ? PlayerPersonalityDataService::getRandomTrait() : 'professional',
            'w_passing' => self::randomSkillNear($strength),
            'w_shooting' => self::randomSkillNear($strength),
            'w_heading' => self::randomSkillNear($strength),
            'w_tackling' => self::randomSkillNear($strength),
            'w_freekick' => self::randomSkillNear($strength),
            'w_pace' => self::randomSkillNear($strength),
            'w_creativity' => self::randomSkillNear($strength),
            'w_influence' => self::randomSkillNear($strength),
            'w_flair' => self::randomSkillNear($strength),
            'w_penalty' => self::randomSkillNear($strength),
            'w_penalty_killing' => self::randomSkillNear($strength),
            'vertrag_gehalt' => $salary,
            'vertrag_spiele' => 60,
            'vertrag_torpraemie' => max(0, (int) round($salary * 0.1)),
            'status' => '1'
        );

        $db->queryInsert($columns, $websoccer->getConfig('db_prefix') . '_spieler');
        $playerId = (int) $db->getLastInsertedId();
        if (class_exists('PlayerMarketValueDataService')) {
            PlayerMarketValueDataService::recalculatePlayer($websoccer, $db, $playerId);
        }
    }

    private static function convertYouthPlayerToFreeProfessional(WebSoccer $websoccer, DbConnection $db, array $youthplayer, $age) {
        $strength = max(1, min(100, (int) $youthplayer['strength']));
        $talent = PlayerTalentDataService::generateTalent($websoccer);
        $maxStrength = PlayerTalentDataService::generateMaximumStrength($talent, $strength);
        $birthday = date('Y-m-d', strtotime('-' . (int) $age . ' years', $websoccer->getNowAsTimestamp()));
        $mainPosition = self::normalizeMainPositionForPosition($youthplayer['position'], '');
        $salary = max(1000, (int) $websoccer->getConfig('youth_salary_per_strength') * $strength);

        $columns = array(
            'vorname' => $youthplayer['firstname'],
            'nachname' => $youthplayer['lastname'],
            'geburtstag' => $birthday,
            'age' => (int) $age,
            'position' => $youthplayer['position'],
            'position_main' => $mainPosition,
            'nation' => $youthplayer['nation'],
            'w_staerke' => $strength,
            'w_staerke_max' => $maxStrength,
            'w_technik' => self::randomSkillNear($strength),
            'w_kondition' => mt_rand(45, 75),
            'w_frische' => mt_rand(55, 90),
            'w_zufriedenheit' => mt_rand(45, 75),
            'w_talent' => $talent,
            'personality' => class_exists('PlayerPersonalityDataService') ? PlayerPersonalityDataService::getRandomTrait() : 'professional',
            'w_passing' => self::randomSkillNear($strength),
            'w_shooting' => self::randomSkillNear($strength),
            'w_heading' => self::randomSkillNear($strength),
            'w_tackling' => self::randomSkillNear($strength),
            'w_freekick' => self::randomSkillNear($strength),
            'w_pace' => self::randomSkillNear($strength),
            'w_creativity' => self::randomSkillNear($strength),
            'w_influence' => self::randomSkillNear($strength),
            'w_flair' => self::randomSkillNear($strength),
            'w_penalty' => self::randomSkillNear($strength),
            'w_penalty_killing' => self::randomSkillNear($strength),
            'vertrag_gehalt' => $salary,
            'vertrag_spiele' => 0,
            'vertrag_torpraemie' => 0,
            'transfermarkt' => '1',
            'transfer_start' => $websoccer->getNowAsTimestamp(),
            'transfer_ende' => $websoccer->getNowAsTimestamp() + max(1, (int) $websoccer->getConfig('transfermarket_duration_days')) * 86400,
            'status' => '1'
        );

        $db->connection->begin_transaction();
        try {
            $db->queryInsert($columns, $websoccer->getConfig('db_prefix') . '_spieler');
            $professionalPlayerId = (int) $db->getLastInsertedId();

            if (class_exists('PlayerTraitsDataService')) {
                PlayerTraitsDataService::copyYouthTraitsToProfessionalPlayer($websoccer, $db, (int) $youthplayer['id'], $professionalPlayerId);
            }
            if (class_exists('PlayerMarketValueDataService')) {
                PlayerMarketValueDataService::recalculatePlayer($websoccer, $db, $professionalPlayerId);
            }

            $db->queryDelete($websoccer->getConfig('db_prefix') . '_youthplayer', 'id = %d', (int) $youthplayer['id']);
            $db->connection->commit();
            return $professionalPlayerId;
        } catch (Exception $e) {
            $db->connection->rollback();
            throw $e;
        }
    }

    private static function normalizeMainPositionForPosition($position, $fallback) {
        $fallback = trim((string) $fallback);
        if (strlen($fallback)) {
            return $fallback;
        }
        if ($position === 'Torwart') {
            return 'T';
        }
        if ($position === 'Abwehr') {
            $items = array('IV', 'LV', 'RV');
            return $items[mt_rand(0, count($items) - 1)];
        }
        if ($position === 'Sturm') {
            $items = array('MS', 'LS', 'RS');
            return $items[mt_rand(0, count($items) - 1)];
        }
        $items = array('ZM', 'DM', 'OM', 'LM', 'RM');
        return $items[mt_rand(0, count($items) - 1)];
    }

    private static function randomSkillNear($strength) {
        return max(1, min(100, (int) $strength + mt_rand(-12, 12)));
    }

    private static function getFallbackGeneratedName($country) {
        $baseFolder = BASE_FOLDER . '/admin/config/names/' . $country;
        if (file_exists($baseFolder . '/firstnames.txt') && file_exists($baseFolder . '/lastnames.txt')) {
            $firstNames = file($baseFolder . '/firstnames.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $lastNames = file($baseFolder . '/lastnames.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (!empty($firstNames) && !empty($lastNames)) {
                return array($firstNames[mt_rand(0, count($firstNames) - 1)], $lastNames[mt_rand(0, count($lastNames) - 1)]);
            }
        }

        $firstNames = array('Luca', 'Mateo', 'Noah', 'Julian', 'Emil', 'Leo', 'Nico', 'Samuel', 'Gabriel', 'Tomas');
        $lastNames = array('Santos', 'Silva', 'Garcia', 'Rossi', 'Costa', 'Meyer', 'Lopez', 'Fernandez', 'Schmidt', 'Martinez');
        return array($firstNames[mt_rand(0, count($firstNames) - 1)], $lastNames[mt_rand(0, count($lastNames) - 1)]);
    }

    private static function sendSeasonRolloverInboxMessage(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $userId, $subject, $message) {
        if ((int) $userId < 1) {
            return;
        }
        if (strlen($subject) > 50) {
            $subject = substr($subject, 0, 47) . '...';
        }
        $db->queryInsert(array(
            'empfaenger_id' => (int) $userId,
            'absender_name' => 'Saisonwechsel',
            'datum' => $websoccer->getNowAsTimestamp(),
            'betreff' => $subject,
            'nachricht' => $message,
            'gelesen' => '0',
            'typ' => 'eingang'
        ), $websoccer->getConfig('db_prefix') . '_briefe');
    }


    public static function runGlobalSeasonFinalization(WebSoccer $websoccer, DbConnection $db) {
        SalaryStatisticsDataService::updateSalaryStats($websoccer, $db);
        BankAccountDataService::payTaxes($websoccer, $db);
        $transferPenaltyDistribution = TransferPenaltyDataService::distributePenalties($websoccer, $db);
        $precontractTransfers = PlayerPrecontractDataService::executeAcceptedTransfers($websoccer, $db);
        $parentClubConflictResolution = array();
        if (class_exists('ParentClubDataService')) {
            $parentClubConflictResolution = ParentClubDataService::resolveDivisionConflicts($websoccer, $db);
        }

        return array(
            'salary_statistics_updated' => 1,
            'taxes_processed' => 1,
            'precontract_transfers' => $precontractTransfers,
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

        $concacaf = null;
        if (class_exists('ConcacafDataService')) {
            try {
                $concacaf = ConcacafDataService::rebuildQualificationAndTempTables($websoccer, $db);
            } catch (Exception $e) {
                $concacaf = array('error' => $e->getMessage());
            }
        }

        return array(
            'uefa' => array(
                'allocation' => $allocation,
                'teams' => $teams,
                'team_count' => count($teams)
            ),
            'conmebol' => $conmebol,
            'concacaf' => $concacaf
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
                    SeasonRolloverScheduleService::parseGermanDate($options['ul_start_date'], 20, 0),
                    SeasonRolloverScheduleService::parseGermanDate($options['conmebol_lib_start_date'], 20, 0),
                    SeasonRolloverScheduleService::parseGermanDate($options['conmebol_sud_start_date'], 20, 0),
                    SeasonRolloverScheduleService::parseGermanDate($options['concacaf_start_date'], 19, 0)
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
