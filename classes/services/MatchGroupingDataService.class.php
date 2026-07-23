<?php
/******************************************************

  This file is part of OpenWebSoccer-Sim.

******************************************************/

/**
 * Creates stable UI groups for match schedules.
 */
class MatchGroupingDataService {

    public static function groupByDate(array $matches, $nowTimestamp) {
        $groups = array();
        $openKey = '';
        $latestPlayedDate = 0;

        foreach ($matches as $match) {
            $timestamp = self::matchTimestamp($match);
            if ($timestamp < 1) {
                continue;
            }

            $key = date('Y-m-d', $timestamp);
            if (!isset($groups[$key])) {
                $groups[$key] = array(
                    'key' => str_replace('-', '', $key),
                    'timestamp' => strtotime($key . ' 00:00:00'),
                    'matches' => array(),
                    'is_open' => false
                );
            }
            $groups[$key]['matches'][] = $match;

            if (self::isPlayed($match) || $timestamp <= (int) $nowTimestamp) {
                if ($groups[$key]['timestamp'] >= $latestPlayedDate) {
                    $latestPlayedDate = $groups[$key]['timestamp'];
                    $openKey = $key;
                }
            }
        }

        ksort($groups);
        if ($openKey === '' && count($groups)) {
            $keys = array_keys($groups);
            $openKey = reset($keys);
        }

        $result = array();
        foreach ($groups as $key => $group) {
            $group['is_open'] = ($key === $openKey);
            $result[] = $group;
        }
        return $result;
    }

    public static function groupByRound(array $matches) {
        $groups = array();
        foreach ($matches as $match) {
            $round = self::roundName($match);
            if ($round === '') {
                $round = '-';
            }
            if (!isset($groups[$round])) {
                $groups[$round] = array(
                    'key' => substr(md5($round), 0, 10),
                    'name' => $round,
                    'timestamp' => 0,
                    'matches' => array(),
                    'is_open' => false
                );
            }
            $groups[$round]['matches'][] = $match;
            $timestamp = self::matchTimestamp($match);
            if ($timestamp > $groups[$round]['timestamp']) {
                $groups[$round]['timestamp'] = $timestamp;
            }
        }

        uasort($groups, function($a, $b) {
            if ((int) $a['timestamp'] === (int) $b['timestamp']) {
                return strcmp($a['name'], $b['name']);
            }
            return ((int) $a['timestamp'] > (int) $b['timestamp']) ? -1 : 1;
        });

        $result = array();
        $first = true;
        foreach ($groups as $group) {
            $group['is_open'] = $first;
            $first = false;
            $result[] = $group;
        }
        return $result;
    }

    private static function matchTimestamp(array $match) {
        if (isset($match['date'])) {
            return (int) $match['date'];
        }
        if (isset($match['datum'])) {
            return (int) $match['datum'];
        }
        return 0;
    }

    private static function roundName(array $match) {
        if (isset($match['cup_round'])) {
            return trim((string) $match['cup_round']);
        }
        if (isset($match['pokalrunde'])) {
            return trim((string) $match['pokalrunde']);
        }
        return '';
    }

    private static function isPlayed(array $match) {
        if (isset($match['simulated'])) {
            return (int) $match['simulated'] === 1;
        }
        if (isset($match['berechnet'])) {
            return (int) $match['berechnet'] === 1;
        }
        return false;
    }
}
