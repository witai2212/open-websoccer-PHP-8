<?php
/******************************************************

  Persistent season rollover workflow for OpenWebSoccer-Sim.
  CM23 Task 1026 | 09.09.2026 | Revision 2

******************************************************/

class SeasonRolloverWorkflowService {

    const BATCH_SIZE = 5;

    private static $steps = array(
        'qualification_snapshot' => '1. Internationale Qualifikanten sichern',
        'end_seasons' => '2. Saisons beenden',
        'finance_archive' => '3. Finanzsaison archivieren + Kontoauszüge leeren',
        'new_seasons' => '4. Neue Saisons erstellen',
        'national_cups' => '5. Nationale Pokale vorbereiten',
        'european_cups' => '6. UEFA, CONMEBOL und CONCACAF vorbereiten',
        'league_schedules' => '7. Liga-Spieltage erstellen'
    );

    public static function getSteps() {
        return self::$steps;
    }

    public static function createRun(WebSoccer $websoccer, DbConnection $db, array $options, $adminId = 0) {
        $errors = SeasonRolloverDataService::validateOptions($options);
        if (!empty($errors)) {
            throw new Exception(implode(' ', $errors));
        }
        $blockingErrors = SeasonRolloverValidationService::getBlockingErrorsForStep($websoccer, $db, 'end_seasons');
        if (!empty($blockingErrors)) {
            throw new Exception(implode(' ', $blockingErrors));
        }

        $runId = self::createRunId();
        $backup = self::createBackup($websoccer, $db, $runId);
        $now = time();
        $steps = array();
        foreach (self::$steps as $stepId => $label) {
            $steps[$stepId] = array(
                'label' => $label,
                'status' => 'pending',
                'started_at' => null,
                'finished_at' => null,
                'attempts' => 0,
                'error' => null,
                'result' => null,
                'progress' => null
            );
        }

        $state = array(
            'id' => $runId,
            'status' => 'ready',
            'created_at' => $now,
            'updated_at' => $now,
            'finished_at' => null,
            'restored_at' => null,
            'admin_id' => (int) $adminId,
            'current_step_index' => 0,
            'options' => $options,
            'backup' => $backup,
            'qualification_snapshot' => null,
            'steps' => $steps
        );
        self::saveRun($state);
        self::log($runId, 'workflow', 'ready', 'Saisonwechsel-Lauf angelegt; Datenbank-Backup wurde erstellt.', array(
            'backup_id' => $backup['id'],
            'backup_sha256' => $backup['sha256'],
            'backup_size' => $backup['size']
        ));
        return $state;
    }

    public static function executeNextStep(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $runId, $confirmed) {
        if (!$confirmed) {
            throw new Exception('Der nächste Saisonwechsel-Schritt muss ausdrücklich bestätigt werden.');
        }
        $state = self::loadRun($runId);
        if (!$state) {
            throw new Exception('Saisonwechsel-Lauf wurde nicht gefunden.');
        }
        if ($state['status'] === 'completed') {
            throw new Exception('Der Saisonwechsel ist bereits vollständig abgeschlossen.');
        }
        if ($state['status'] === 'restored') {
            throw new Exception('Dieser Saisonwechsel-Lauf wurde bereits auf sein Backup zurückgesetzt.');
        }
        if ($state['status'] === 'error') {
            throw new Exception('Der Saisonwechsel ist wegen eines Fehlers gestoppt. Bitte zuerst den Fehler prüfen oder das Backup wiederherstellen.');
        }

        $stepIds = array_keys(self::$steps);
        $stepIndex = (int) $state['current_step_index'];
        if (!isset($stepIds[$stepIndex])) {
            $state['status'] = 'completed';
            $state['finished_at'] = time();
            $state['updated_at'] = time();
            self::saveRun($state);
            return $state;
        }

        $stepId = $stepIds[$stepIndex];
        $step =& $state['steps'][$stepId];
        $step['attempts'] = (int) $step['attempts'] + 1;
        if (empty($step['started_at'])) {
            $step['started_at'] = time();
        }
        $step['status'] = 'running';
        $step['error'] = null;
        $state['status'] = 'running';
        $state['updated_at'] = time();
        self::saveRun($state);
        self::log($runId, $stepId, 'running', 'Schritt gestartet.', array('attempt' => $step['attempts']));

        try {
            if ($stepId === 'league_schedules') {
                $batchResult = self::executeLeagueScheduleBatch($websoccer, $db, $state);
                $state = $batchResult['state'];
                $step =& $state['steps'][$stepId];
                $step['result'] = $batchResult['result'];
                $step['progress'] = $batchResult['progress'];
                if ($batchResult['done']) {
                    $step['status'] = 'done';
                    $step['finished_at'] = time();
                    $state['current_step_index'] = $stepIndex + 1;
                    $state['status'] = 'completed';
                    $state['finished_at'] = time();
                    self::log($runId, $stepId, 'done', 'Alle Liga-Spielpläne wurden verarbeitet.', $batchResult['progress']);
                    self::log($runId, 'workflow', 'completed', 'Saisonwechsel vollständig abgeschlossen.');
                } else {
                    $step['status'] = 'in_progress';
                    $state['status'] = 'ready';
                    self::log($runId, $stepId, 'in_progress', 'Liga-Batch abgeschlossen; weitere Ligen sind offen.', $batchResult['progress']);
                }
            } else {
                $result = self::executeRegularStep($websoccer, $db, $i18n, $stepId, $state);
                if ($stepId === 'qualification_snapshot') {
                    $state['qualification_snapshot'] = $result['snapshot'];
                    $step['result'] = $result['summary'];
                } else {
                    $resultErrors = self::collectResultErrors($result);
                    if (!empty($resultErrors)) {
                        throw new Exception(implode(' ', $resultErrors));
                    }
                    $step['result'] = $result;
                }
                $step['status'] = 'done';
                $step['finished_at'] = time();
                $state['current_step_index'] = $stepIndex + 1;
                $state['status'] = 'ready';
                self::log($runId, $stepId, 'done', 'Schritt erfolgreich abgeschlossen.', self::summarizeResult($step['result']));
            }
            $state['updated_at'] = time();
            self::saveRun($state);
            return $state;
        } catch (Exception $e) {
            $state = self::loadRun($runId);
            if (!$state) {
                throw $e;
            }
            $state['steps'][$stepId]['status'] = 'error';
            $state['steps'][$stepId]['error'] = $e->getMessage();
            $state['steps'][$stepId]['finished_at'] = time();
            $state['status'] = 'error';
            $state['updated_at'] = time();
            self::saveRun($state);
            self::log($runId, $stepId, 'error', $e->getMessage());
            throw $e;
        }
    }

    private static function executeRegularStep(WebSoccer $websoccer, DbConnection $db, I18n $i18n, $stepId, array $state) {
        $options = $state['options'];
        switch ($stepId) {
            case 'qualification_snapshot':
                $snapshot = SeasonRolloverQualificationService::captureSnapshot($websoccer, $db);
                return array(
                    'snapshot' => $snapshot,
                    'summary' => array(
                        'uefa_cl_teams' => count($snapshot['uefa']['champions_league']),
                        'uefa_ul_teams' => count($snapshot['uefa']['euro_league']),
                        'conmebol_lib_teams' => count($snapshot['conmebol']['libertadores']),
                        'conmebol_sud_teams' => count($snapshot['conmebol']['sudamericana']),
                        'concacaf_teams' => count($snapshot['concacaf']['champions_cup']),
                        'finance_team_seasons' => count($snapshot['finance_seasons'])
                    )
                );

            case 'end_seasons':
                return SeasonRolloverDataService::executeStep($websoccer, $db, $i18n, 'end_seasons', $options);

            case 'finance_archive':
                if (empty($state['qualification_snapshot']['finance_seasons'])) {
                    throw new Exception('Die vor dem Saisonabschluss gesicherte Team-/Saison-Zuordnung fehlt.');
                }
                return SeasonFinanceHistoryService::archiveAndReset(
                    $websoccer,
                    $db,
                    $state['qualification_snapshot']['finance_seasons']
                );

            case 'new_seasons':
                return SeasonRolloverDataService::executeStep($websoccer, $db, $i18n, 'new_seasons', $options);

            case 'national_cups':
                $leagueStartTimestamp = SeasonRolloverScheduleService::parseGermanDate(
                    $options['league_start_date'],
                    SeasonRolloverScheduleService::LEAGUE_KICKOFF_HOUR,
                    SeasonRolloverScheduleService::LEAGUE_KICKOFF_MINUTE
                );
                $commonLeagueEndTimestamp = SeasonRolloverScheduleService::calculateCommonLeagueEndTimestamp($websoccer, $db, $leagueStartTimestamp, (int) $options['league_rounds']);
                $nationalCupFinalTimestamp = SeasonRolloverScheduleService::getNationalCupFinalTimestamp($commonLeagueEndTimestamp);
                return SeasonRolloverCupService::generateNationalCups(
                    $websoccer,
                    $db,
                    SeasonRolloverScheduleService::parseGermanDate($options['national_cup_start_date'], SeasonRolloverScheduleService::CUP_KICKOFF_HOUR, SeasonRolloverScheduleService::CUP_KICKOFF_MINUTE),
                    $nationalCupFinalTimestamp,
                    $commonLeagueEndTimestamp
                );

            case 'european_cups':
                if (empty($state['qualification_snapshot'])) {
                    throw new Exception('Internationale Qualifikanten wurden vor dem Saisonreset nicht gesichert.');
                }
                $tempSummary = SeasonRolloverQualificationService::applySnapshotToTempTables($websoccer, $db, $state['qualification_snapshot']);
                $leagueStartTimestamp = SeasonRolloverScheduleService::parseGermanDate($options['league_start_date'], SeasonRolloverScheduleService::LEAGUE_KICKOFF_HOUR, SeasonRolloverScheduleService::LEAGUE_KICKOFF_MINUTE);
                $commonLeagueEndTimestamp = SeasonRolloverScheduleService::calculateCommonLeagueEndTimestamp($websoccer, $db, $leagueStartTimestamp, (int) $options['league_rounds']);
                $nationalCupFinalTimestamp = SeasonRolloverScheduleService::getNationalCupFinalTimestamp($commonLeagueEndTimestamp);
                $cups = SeasonRolloverCupService::generateEuropeanCups(
                    $websoccer,
                    $db,
                    SeasonRolloverScheduleService::parseGermanDate($options['cl_start_date'], SeasonRolloverScheduleService::CUP_KICKOFF_HOUR, SeasonRolloverScheduleService::CUP_KICKOFF_MINUTE),
                    SeasonRolloverScheduleService::parseGermanDate($options['ul_start_date'], SeasonRolloverScheduleService::CUP_KICKOFF_HOUR, SeasonRolloverScheduleService::CUP_KICKOFF_MINUTE),
                    SeasonRolloverScheduleService::parseGermanDate($options['conmebol_lib_start_date'], SeasonRolloverScheduleService::CUP_KICKOFF_HOUR, SeasonRolloverScheduleService::CUP_KICKOFF_MINUTE),
                    SeasonRolloverScheduleService::parseGermanDate($options['conmebol_sud_start_date'], SeasonRolloverScheduleService::CUP_KICKOFF_HOUR, SeasonRolloverScheduleService::CUP_KICKOFF_MINUTE),
                    SeasonRolloverScheduleService::parseGermanDate($options['concacaf_start_date'], SeasonRolloverScheduleService::CUP_KICKOFF_HOUR, SeasonRolloverScheduleService::CUP_KICKOFF_MINUTE),
                    $nationalCupFinalTimestamp,
                    $commonLeagueEndTimestamp
                );
                return array('snapshot_temp_tables' => $tempSummary, 'cups' => $cups);
        }
        throw new Exception('Unbekannter Saisonwechsel-Schritt: ' . $stepId);
    }

    private static function executeLeagueScheduleBatch(WebSoccer $websoccer, DbConnection $db, array $state) {
        $stepId = 'league_schedules';
        $progress = isset($state['steps'][$stepId]['progress']) && is_array($state['steps'][$stepId]['progress']) ? $state['steps'][$stepId]['progress'] : null;
        if (!$progress || empty($progress['initialized'])) {
            $progress = self::initializeLeagueScheduleProgress($websoccer, $db, $state['options']);
            $state['steps'][$stepId]['progress'] = $progress;
            $state['updated_at'] = time();
            self::saveRun($state);
        }
        $remaining = array_values(array_diff($progress['season_ids'], $progress['processed_ids']));
        if (empty($remaining)) {
            return array('state' => $state, 'done' => true, 'progress' => $progress, 'result' => array('batch_processed' => 0, 'batch_created_matches' => 0));
        }

        $batchIds = array_slice($remaining, 0, self::BATCH_SIZE);
        $batchProcessed = array();
        $batchSkipped = array();
        $batchCreatedMatches = 0;
        foreach ($batchIds as $seasonId) {
            $seasonId = (int) $seasonId;
            if (!isset($progress['seasons'][(string) $seasonId])) {
                throw new Exception('Liga-Saison ' . $seasonId . ' fehlt im gespeicherten Fortschritt.');
            }
            $season = $progress['seasons'][(string) $seasonId];
            $result = self::generateSingleLeagueSchedule($websoccer, $db, $season, (int) $progress['first_league_friday'], (int) $state['options']['league_rounds'], (int) $progress['maximum_matchdays']);
            $progress['processed_ids'][] = $seasonId;
            $batchProcessed[] = $seasonId;
            $batchCreatedMatches += (int) $result['created_matches'];
            $progress['created_matches'] = (int) $progress['created_matches'] + (int) $result['created_matches'];
            if (!empty($result['skipped'])) {
                $progress['skipped_ids'][] = $seasonId;
                $batchSkipped[] = array('season_id' => $seasonId, 'league' => $season['league_country'] . ' / ' . $season['league_name'], 'reason' => $result['reason']);
            }
            $state['steps'][$stepId]['progress'] = $progress;
            $state['updated_at'] = time();
            self::saveRun($state);
            self::log($state['id'], $stepId, 'league_done', 'Liga verarbeitet.', array(
                'season_id' => $seasonId,
                'league_id' => (int) $season['league_id'],
                'league' => $season['league_country'] . ' / ' . $season['league_name'],
                'created_matches' => (int) $result['created_matches'],
                'skipped' => !empty($result['skipped']) ? 1 : 0,
                'reason' => isset($result['reason']) ? $result['reason'] : null
            ));
        }
        $progress['processed_ids'] = array_values(array_unique(array_map('intval', $progress['processed_ids'])));
        $progress['skipped_ids'] = array_values(array_unique(array_map('intval', $progress['skipped_ids'])));
        $progress['processed'] = count($progress['processed_ids']);
        $progress['remaining'] = max(0, (int) $progress['total'] - (int) $progress['processed']);
        $progress['updated_at'] = time();
        $state['steps'][$stepId]['progress'] = $progress;
        return array(
            'state' => $state,
            'done' => ($progress['remaining'] === 0),
            'progress' => $progress,
            'result' => array('batch_processed' => count($batchProcessed), 'batch_processed_ids' => $batchProcessed, 'batch_skipped' => $batchSkipped, 'batch_created_matches' => $batchCreatedMatches)
        );
    }

    private static function initializeLeagueScheduleProgress(WebSoccer $websoccer, DbConnection $db, array $options) {
        $firstLeagueFriday = SeasonRolloverScheduleService::nextWeekday(
            SeasonRolloverScheduleService::parseGermanDate($options['league_start_date'], SeasonRolloverScheduleService::LEAGUE_KICKOFF_HOUR, SeasonRolloverScheduleService::LEAGUE_KICKOFF_MINUTE),
            5,
            SeasonRolloverScheduleService::LEAGUE_KICKOFF_HOUR,
            SeasonRolloverScheduleService::LEAGUE_KICKOFF_MINUTE
        );
        $seasons = SeasonRolloverScheduleService::getOpenSeasonsWithoutLeagueMatches($websoccer, $db);
        $seasonIds = array();
        $seasonMap = array();
        $maximumMatchdays = 0;
        foreach ($seasons as $season) {
            $seasonId = (int) $season['season_id'];
            $teamIds = SeasonRolloverScheduleService::getLeagueTeamIds($websoccer, $db, (int) $season['league_id']);
            $matchdays = SeasonRolloverScheduleService::getLeagueMatchdayCountForTeamCount(count($teamIds), (int) $options['league_rounds']);
            $maximumMatchdays = max($maximumMatchdays, $matchdays);
            $seasonIds[] = $seasonId;
            $seasonMap[(string) $seasonId] = array(
                'season_id' => $seasonId,
                'season_name' => $season['season_name'],
                'league_id' => (int) $season['league_id'],
                'league_name' => $season['league_name'],
                'league_country' => $season['league_country'],
                'league_division' => isset($season['league_division']) ? $season['league_division'] : null,
                'team_count' => count($teamIds),
                'matchday_count' => $matchdays
            );
        }
        return array(
            'initialized' => 1,
            'initialized_at' => time(),
            'updated_at' => time(),
            'first_league_friday' => $firstLeagueFriday,
            'maximum_matchdays' => $maximumMatchdays,
            'season_ids' => $seasonIds,
            'seasons' => $seasonMap,
            'processed_ids' => array(),
            'skipped_ids' => array(),
            'total' => count($seasonIds),
            'processed' => 0,
            'remaining' => count($seasonIds),
            'created_matches' => 0,
            'batch_size' => self::BATCH_SIZE
        );
    }

    private static function generateSingleLeagueSchedule(WebSoccer $websoccer, DbConnection $db, array $season, $firstLeagueFriday, $rounds, $maximumMatchdays) {
        $prefix = $websoccer->getConfig('db_prefix');
        $seasonId = (int) $season['season_id'];
        $leagueId = (int) $season['league_id'];
        $existingResult = $db->querySelect('COUNT(*) AS hits', $prefix . '_spiel', "saison_id = %d AND spieltyp = 'Ligaspiel'", $seasonId, 1);
        $existingRow = $existingResult->fetch_array();
        $existingResult->free();
        if ($existingRow && (int) $existingRow['hits'] > 0) {
            return array('created_matches' => 0, 'skipped' => 1, 'reason' => 'Spielplan war bereits vorhanden; Liga wurde nicht erneut verarbeitet.');
        }
        $teamIds = SeasonRolloverScheduleService::getLeagueTeamIds($websoccer, $db, $leagueId);
        if (count($teamIds) < 2) {
            return array('created_matches' => 0, 'skipped' => 1, 'reason' => 'Nicht genug aktive Teams.');
        }
        $fullSchedule = self::createFullLeagueSchedule($teamIds, $rounds);
        if (empty($fullSchedule)) {
            return array('created_matches' => 0, 'skipped' => 1, 'reason' => 'Spielplan konnte nicht erzeugt werden.');
        }

        $matchdayCount = count($fullSchedule);
        $maximumMatchdays = max($matchdayCount, (int) $maximumMatchdays);
        $createdMatches = 0;
        $db->connection->begin_transaction();
        try {
            foreach ($fullSchedule as $matchdayIndex => $matches) {
                $matchdayNumber = $matchdayIndex + 1;
                if ($matchdayCount <= 1 || $maximumMatchdays <= 1) {
                    $weekIndex = max(0, $maximumMatchdays - 1);
                } else {
                    $weekIndex = (int) round($matchdayIndex * ($maximumMatchdays - 1) / ($matchdayCount - 1));
                }
                $matchweekFriday = SeasonRolloverScheduleService::addDays($firstLeagueFriday, $weekIndex * 7, SeasonRolloverScheduleService::LEAGUE_KICKOFF_HOUR, SeasonRolloverScheduleService::LEAGUE_KICKOFF_MINUTE);
                $isFinalMatchday = ($matchdayNumber === $matchdayCount);
                foreach ($matches as $matchIndex => $match) {
                    $homeTeam = (int) $match[0];
                    $guestTeam = (int) $match[1];
                    $preferredOffset = $isFinalMatchday ? 4 : ($matchIndex % 5);
                    $timestamp = self::findLeagueMatchTimestamp($websoccer, $db, array($homeTeam, $guestTeam), $matchweekFriday, $preferredOffset, $isFinalMatchday);
                    $db->queryInsert(array(
                        'spieltyp' => SeasonRolloverScheduleService::MATCH_TYPE_LEAGUE,
                        'liga_id' => $leagueId,
                        'saison_id' => $seasonId,
                        'spieltag' => $matchdayNumber,
                        'home_verein' => $homeTeam,
                        'gast_verein' => $guestTeam,
                        'datum' => $timestamp
                    ), $prefix . '_spiel');
                    $createdMatches++;
                }
            }
            $db->connection->commit();
        } catch (Exception $e) {
            $db->connection->rollback();
            throw $e;
        }
        return array('created_matches' => $createdMatches, 'skipped' => 0, 'reason' => null);
    }

    private static function createFullLeagueSchedule(array $teamIds, $rounds) {
        $rounds = max(1, min(4, (int) $rounds));
        $baseSchedule = array_values(ScheduleGenerator::createRoundRobinSchedule($teamIds));
        if (empty($baseSchedule)) {
            return array();
        }
        $fullSchedule = array();
        for ($round = 1; $round <= $rounds; $round++) {
            foreach ($baseSchedule as $matchesOfMatchday) {
                $matchesForThisMatchday = array();
                foreach ($matchesOfMatchday as $match) {
                    if (!isset($match[0], $match[1])) {
                        continue;
                    }
                    $matchesForThisMatchday[] = ($round % 2 === 1) ? array((int) $match[0], (int) $match[1]) : array((int) $match[1], (int) $match[0]);
                }
                $fullSchedule[] = $matchesForThisMatchday;
            }
        }
        return $fullSchedule;
    }

    private static function findLeagueMatchTimestamp(WebSoccer $websoccer, DbConnection $db, array $teamIds, $matchweekFriday, $preferredOffset, $isFinalMatchday) {
        $preferredOffset = max(0, min(4, (int) $preferredOffset));
        if ($isFinalMatchday) {
            $candidate = SeasonRolloverScheduleService::addDays($matchweekFriday, 4, SeasonRolloverScheduleService::LEAGUE_KICKOFF_HOUR, SeasonRolloverScheduleService::LEAGUE_KICKOFF_MINUTE);
            if (SeasonRolloverScheduleService::isConfiguredCupRoundDay($websoccer, $db, $candidate) || SeasonRolloverScheduleService::teamsHaveMatchOnDay($websoccer, $db, $teamIds, $candidate)) {
                throw new Exception('Der gemeinsame letzte Ligaspieltag kollidiert mit einem Pokaltermin. Bitte die Pokaltermine anpassen.');
            }
            return $candidate;
        }
        $offsets = array();
        for ($step = 0; $step < 5; $step++) {
            $offsets[] = ($preferredOffset + $step) % 5;
        }
        foreach ($offsets as $offset) {
            $candidate = SeasonRolloverScheduleService::addDays($matchweekFriday, $offset, SeasonRolloverScheduleService::LEAGUE_KICKOFF_HOUR, SeasonRolloverScheduleService::LEAGUE_KICKOFF_MINUTE);
            if (SeasonRolloverScheduleService::isConfiguredCupRoundDay($websoccer, $db, $candidate)) {
                continue;
            }
            if (SeasonRolloverScheduleService::teamsHaveMatchOnDay($websoccer, $db, $teamIds, $candidate)) {
                continue;
            }
            return $candidate;
        }
        throw new Exception('Kein konfliktfreier Liga-Termin innerhalb der Spielwoche gefunden.');
    }

    public static function createBackup(WebSoccer $websoccer, DbConnection $db, $backupId = null) {
        $backupId = $backupId ? self::sanitizeId($backupId) : self::createRunId();
        if (!$backupId) {
            throw new Exception('Ungültige Backup-ID.');
        }
        $dirs = self::ensureStorage();
        $prefix = $websoccer->getConfig('db_prefix');
        $backupFile = $dirs['backups'] . '/backup_' . $backupId . '.cm23backup';
        $metaFile = $dirs['backups'] . '/backup_' . $backupId . '.meta.json';
        $handle = @fopen($backupFile, 'wb');
        if (!$handle) {
            throw new Exception('Backup-Datei konnte nicht erstellt werden: ' . $backupFile);
        }
        $tables = self::getPrefixedTables($db, $prefix);
        fwrite($handle, 'CM23-SEASON-ROLLOVER-BACKUP|1|' . time() . '|' . base64_encode($prefix) . "\n");
        try {
            foreach ($tables as $tableInfo) {
                if ($tableInfo['type'] !== 'BASE TABLE') {
                    continue;
                }
                $table = $tableInfo['name'];
                $quotedTable = self::quoteIdentifier($table);
                $createResult = $db->executeQuery('SHOW CREATE TABLE ' . $quotedTable);
                $createRow = $createResult->fetch_array(MYSQLI_NUM);
                $createResult->free();
                if (!$createRow || !isset($createRow[1])) {
                    throw new Exception('Tabellenstruktur konnte nicht gesichert werden: ' . $table);
                }
                self::writeBackupSql($handle, 'DROP TABLE IF EXISTS ' . $quotedTable);
                self::writeBackupSql($handle, $createRow[1]);
                $dataResult = $db->executeQuery('SELECT * FROM ' . $quotedTable);
                $fields = $dataResult->fetch_fields();
                $columnNames = array();
                foreach ($fields as $field) {
                    $columnNames[] = self::quoteIdentifier($field->name);
                }
                $rowBatch = array();
                while ($row = $dataResult->fetch_assoc()) {
                    $values = array();
                    foreach ($row as $value) {
                        $values[] = self::escapeSqlValue($db, $value);
                    }
                    $rowBatch[] = '(' . implode(',', $values) . ')';
                    if (count($rowBatch) >= 100) {
                        self::writeBackupSql($handle, 'INSERT INTO ' . $quotedTable . ' (' . implode(',', $columnNames) . ') VALUES ' . implode(',', $rowBatch));
                        $rowBatch = array();
                    }
                }
                $dataResult->free();
                if (!empty($rowBatch)) {
                    self::writeBackupSql($handle, 'INSERT INTO ' . $quotedTable . ' (' . implode(',', $columnNames) . ') VALUES ' . implode(',', $rowBatch));
                }
            }
            fwrite($handle, 'END|' . count($tables) . "\n");
            fclose($handle);
        } catch (Exception $e) {
            fclose($handle);
            @unlink($backupFile);
            throw $e;
        }
        $meta = array(
            'id' => $backupId,
            'created_at' => time(),
            'prefix' => $prefix,
            'file' => basename($backupFile),
            'size' => (int) @filesize($backupFile),
            'sha256' => hash_file('sha256', $backupFile),
            'table_count' => count(array_filter($tables, function($tableInfo) { return $tableInfo['type'] === 'BASE TABLE'; }))
        );
        self::writeJsonFile($metaFile, $meta);
        return $meta;
    }

    public static function restoreBackup(WebSoccer $websoccer, DbConnection $db, $backupId) {
        $backupId = self::sanitizeId($backupId);
        if (!$backupId) {
            throw new Exception('Ungültige Backup-ID.');
        }
        $dirs = self::ensureStorage();
        $backupFile = $dirs['backups'] . '/backup_' . $backupId . '.cm23backup';
        $metaFile = $dirs['backups'] . '/backup_' . $backupId . '.meta.json';
        if (!is_file($backupFile) || !is_file($metaFile)) {
            throw new Exception('Backup wurde nicht gefunden.');
        }
        $meta = self::readJsonFile($metaFile);
        if (!$meta || empty($meta['sha256'])) {
            throw new Exception('Backup-Metadaten sind ungültig.');
        }
        if (!hash_equals($meta['sha256'], hash_file('sha256', $backupFile))) {
            throw new Exception('Backup-Prüfsumme stimmt nicht. Wiederherstellung wurde abgebrochen.');
        }
        $prefix = $websoccer->getConfig('db_prefix');
        if (!isset($meta['prefix']) || $meta['prefix'] !== $prefix) {
            throw new Exception('Backup gehört nicht zum aktuellen Datenbank-Präfix.');
        }
        $safetyId = 'pre_restore_' . date('Ymd_His') . '_' . substr(md5(uniqid('', true)), 0, 6);
        $safetyBackup = self::createBackup($websoccer, $db, $safetyId);
        $db->executeQuery('SET FOREIGN_KEY_CHECKS = 0');
        try {
            $currentTables = self::getPrefixedTables($db, $prefix);
            foreach ($currentTables as $tableInfo) {
                if ($tableInfo['type'] === 'VIEW') {
                    $db->executeQuery('DROP VIEW IF EXISTS ' . self::quoteIdentifier($tableInfo['name']));
                }
            }
            foreach ($currentTables as $tableInfo) {
                if ($tableInfo['type'] === 'BASE TABLE') {
                    $db->executeQuery('DROP TABLE IF EXISTS ' . self::quoteIdentifier($tableInfo['name']));
                }
            }
            $handle = fopen($backupFile, 'rb');
            if (!$handle) {
                throw new Exception('Backup-Datei konnte nicht geöffnet werden.');
            }
            while (($line = fgets($handle)) !== false) {
                $line = rtrim($line, "\r\n");
                if (strpos($line, 'SQL|') !== 0) {
                    continue;
                }
                $sql = base64_decode(substr($line, 4), true);
                if ($sql === false || $sql === '') {
                    fclose($handle);
                    throw new Exception('Backup enthält einen ungültigen SQL-Eintrag.');
                }
                $db->executeQuery($sql);
            }
            fclose($handle);
            $db->executeQuery('SET FOREIGN_KEY_CHECKS = 1');
        } catch (Exception $e) {
            try { $db->executeQuery('SET FOREIGN_KEY_CHECKS = 1'); } catch (Exception $ignore) {}
            throw $e;
        }
        $run = self::loadRun($backupId);
        if ($run) {
            $run['status'] = 'restored';
            $run['restored_at'] = time();
            $run['updated_at'] = time();
            self::saveRun($run);
        }
        self::log($backupId, 'restore', 'done', 'Datenbank-Backup wurde wiederhergestellt.', array('backup_id' => $backupId, 'safety_backup_id' => $safetyBackup['id']));
        return array('restored_backup' => $meta, 'safety_backup' => $safetyBackup);
    }

    public static function listBackups($limit = 10) {
        $dirs = self::ensureStorage();
        $files = glob($dirs['backups'] . '/backup_*.meta.json');
        if (!$files) { return array(); }
        usort($files, function($a, $b) { return filemtime($b) <=> filemtime($a); });
        $backups = array();
        foreach (array_slice($files, 0, max(1, (int) $limit)) as $file) {
            $meta = self::readJsonFile($file);
            if ($meta) { $backups[] = $meta; }
        }
        return $backups;
    }

    public static function loadRun($runId) {
        $runId = self::sanitizeId($runId);
        if (!$runId) { return null; }
        $dirs = self::ensureStorage();
        return self::readJsonFile($dirs['runs'] . '/run_' . $runId . '.json');
    }

    public static function getLatestRun() {
        $dirs = self::ensureStorage();
        $files = glob($dirs['runs'] . '/run_*.json');
        if (!$files) { return null; }
        usort($files, function($a, $b) { return filemtime($b) <=> filemtime($a); });
        return self::readJsonFile($files[0]);
    }

    public static function getLogEntries($runId, $limit = 100) {
        $runId = self::sanitizeId($runId);
        if (!$runId) { return array(); }
        $dirs = self::ensureStorage();
        $file = $dirs['logs'] . '/run_' . $runId . '.log';
        if (!is_file($file)) { return array(); }
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!$lines) { return array(); }
        $lines = array_slice($lines, -max(1, (int) $limit));
        $entries = array();
        foreach ($lines as $line) {
            $entry = json_decode($line, true);
            if (is_array($entry)) { $entries[] = $entry; }
        }
        return $entries;
    }

    public static function formatTimestamp($timestamp) {
        return $timestamp ? date('d.m.Y H:i:s', (int) $timestamp) : '-';
    }

    private static function collectResultErrors($value, $path = '') {
        $errors = array();
        if (!is_array($value)) { return $errors; }
        foreach ($value as $key => $item) {
            $currentPath = $path === '' ? (string) $key : $path . '.' . $key;
            if ($key === 'errors' && is_array($item) && !empty($item)) {
                foreach ($item as $error) {
                    if ((string) $error !== '') { $errors[] = $currentPath . ': ' . (string) $error; }
                }
                continue;
            }
            if ($key === 'error' && !is_array($item) && trim((string) $item) !== '') {
                $errors[] = $currentPath . ': ' . (string) $item;
                continue;
            }
            if (is_array($item)) { $errors = array_merge($errors, self::collectResultErrors($item, $currentPath)); }
        }
        return $errors;
    }

    private static function summarizeResult($value) {
        if (!is_array($value)) { return array('result' => $value); }
        $summary = array();
        foreach ($value as $key => $item) {
            if (is_array($item)) { $summary[$key] = count($item); }
            elseif (is_bool($item)) { $summary[$key] = $item ? 1 : 0; }
            else { $summary[$key] = $item; }
        }
        return $summary;
    }

    private static function saveRun(array $state) {
        $runId = self::sanitizeId($state['id']);
        if (!$runId) { throw new Exception('Ungültige Saisonwechsel-Lauf-ID.'); }
        $dirs = self::ensureStorage();
        self::writeJsonFile($dirs['runs'] . '/run_' . $runId . '.json', $state);
    }

    private static function log($runId, $step, $status, $message, array $context = array()) {
        $runId = self::sanitizeId($runId);
        if (!$runId) { return; }
        $dirs = self::ensureStorage();
        $entry = array('timestamp' => time(), 'step' => (string) $step, 'status' => (string) $status, 'message' => (string) $message, 'context' => $context);
        @file_put_contents($dirs['logs'] . '/run_' . $runId . '.log', json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
    }

    private static function createRunId() {
        try { $random = bin2hex(random_bytes(4)); }
        catch (Exception $e) { $random = substr(md5(uniqid('', true)), 0, 8); }
        return date('Ymd_His') . '_' . $random;
    }

    private static function sanitizeId($value) {
        $value = (string) $value;
        return preg_match('/^[a-zA-Z0-9_-]{3,80}$/', $value) ? $value : null;
    }

    private static function ensureStorage() {
        $base = BASE_FOLDER . '/generated/season-rollover';
        $dirs = array('base' => $base, 'runs' => $base . '/runs', 'logs' => $base . '/logs', 'backups' => $base . '/backups');
        foreach ($dirs as $dir) {
            if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
                throw new Exception('Saisonwechsel-Speicherverzeichnis konnte nicht erstellt werden: ' . $dir);
            }
        }
        $htaccess = $base . '/.htaccess';
        if (!is_file($htaccess)) { @file_put_contents($htaccess, "Require all denied\nDeny from all\n"); }
        $index = $base . '/index.php';
        if (!is_file($index)) { @file_put_contents($index, "<?php http_response_code(403); exit; ?>\n"); }
        return $dirs;
    }

    private static function writeJsonFile($file, array $data) {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) { throw new Exception('Saisonwechsel-Status konnte nicht serialisiert werden.'); }
        $tmp = $file . '.tmp';
        if (@file_put_contents($tmp, $json, LOCK_EX) === false) { throw new Exception('Saisonwechsel-Status konnte nicht gespeichert werden.'); }
        if (!@rename($tmp, $file)) { @unlink($tmp); throw new Exception('Saisonwechsel-Status konnte nicht atomar gespeichert werden.'); }
    }

    private static function readJsonFile($file) {
        if (!is_file($file)) { return null; }
        $json = @file_get_contents($file);
        if ($json === false || $json === '') { return null; }
        $data = json_decode($json, true);
        return is_array($data) ? $data : null;
    }

    private static function getPrefixedTables(DbConnection $db, $prefix) {
        $result = $db->executeQuery('SHOW FULL TABLES');
        $tables = array();
        while ($row = $result->fetch_array(MYSQLI_NUM)) {
            $name = isset($row[0]) ? (string) $row[0] : '';
            $type = isset($row[1]) ? strtoupper((string) $row[1]) : 'BASE TABLE';
            if (strpos($name, $prefix . '_') === 0) { $tables[] = array('name' => $name, 'type' => $type); }
        }
        $result->free();
        return $tables;
    }

    private static function quoteIdentifier($identifier) {
        return '`' . str_replace('`', '``', (string) $identifier) . '`';
    }

    private static function escapeSqlValue(DbConnection $db, $value) {
        if ($value === null) { return 'NULL'; }
        return "'" . $db->connection->real_escape_string((string) $value) . "'";
    }

    private static function writeBackupSql($handle, $sql) {
        if (fwrite($handle, 'SQL|' . base64_encode($sql) . "\n") === false) {
            throw new Exception('Backup-Datei konnte nicht vollständig geschrieben werden.');
        }
    }
}
?>