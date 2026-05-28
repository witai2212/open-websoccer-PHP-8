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
 * Model for the post-match analytics page.
 */
class MatchAnalyticsModel implements IModel {
    private $_db;
    private $_i18n;
    private $_websoccer;

    public function __construct($db, $i18n, $websoccer) {
        $this->_db = $db;
        $this->_i18n = $i18n;
        $this->_websoccer = $websoccer;
    }

    public function renderView() {
        return TRUE;
    }

    public function getTemplateParameters() {
        $matchId = (int) $this->_websoccer->getRequestParameter('id');
        if ($matchId < 1) {
            throw new Exception($this->_i18n->getMessage(MSG_KEY_ERROR_PAGENOTFOUND));
        }

        $analytics = MatchAnalyticsDataService::getAnalytics($this->_websoccer, $this->_db, $matchId);
        if (!count($analytics) || !isset($analytics['match']['match_id'])) {
            throw new Exception($this->_i18n->getMessage(MSG_KEY_ERROR_PAGENOTFOUND));
        }

        $analytics['summary_items'] = $this->_createSummaryItems($analytics['match'], $analytics['home_statistics'], $analytics['guest_statistics']);
        $analytics['user_team_id'] = $this->_websoccer->getUser()->getClubId($this->_websoccer, $this->_db);

        return $analytics;
    }

    private function _createSummaryItems($match, $homeStats, $guestStats) {
        $items = array();

        $this->_addComparisonSummary($items, $match['match_home_name'], $match['match_guest_name'], $homeStats['pass_success_percent'], $guestStats['pass_success_percent'], 'matchanalytics_summary_pass_advantage', 5);
        $this->_addComparisonSummary($items, $match['match_home_name'], $match['match_guest_name'], $homeStats['tackle_success_percent'], $guestStats['tackle_success_percent'], 'matchanalytics_summary_tackle_advantage', 5);

        if ($homeStats['shoots'] >= $guestStats['shoots'] + 3) {
            $items[] = sprintf($this->_i18n->getMessage('matchanalytics_summary_shots_advantage'), $match['match_home_name'], $homeStats['shoots'], $guestStats['shoots']);
        } else if ($guestStats['shoots'] >= $homeStats['shoots'] + 3) {
            $items[] = sprintf($this->_i18n->getMessage('matchanalytics_summary_shots_advantage'), $match['match_guest_name'], $guestStats['shoots'], $homeStats['shoots']);
        }

        if ($homeStats['ballcontacts'] >= $guestStats['ballcontacts'] + 15) {
            $items[] = $this->_i18n->getMessage('matchanalytics_summary_ball_advantage', $match['match_home_name']);
        } else if ($guestStats['ballcontacts'] >= $homeStats['ballcontacts'] + 15) {
            $items[] = $this->_i18n->getMessage('matchanalytics_summary_ball_advantage', $match['match_guest_name']);
        }

        if ($homeStats['assists'] > $guestStats['assists']) {
            $items[] = $this->_i18n->getMessage('matchanalytics_summary_assists_advantage', $match['match_home_name']);
        } else if ($guestStats['assists'] > $homeStats['assists']) {
            $items[] = $this->_i18n->getMessage('matchanalytics_summary_assists_advantage', $match['match_guest_name']);
        }

        if (!count($items)) {
            $items[] = $this->_i18n->getMessage('matchanalytics_summary_balanced');
        }

        return $items;
    }

    private function _addComparisonSummary(&$items, $homeName, $guestName, $homeValue, $guestValue, $messageKey, $minimumDifference) {
		if ($homeValue >= $guestValue + $minimumDifference) {
			$items[] = sprintf($this->_i18n->getMessage($messageKey), $homeName, $homeValue, $guestValue);
		} else if ($guestValue >= $homeValue + $minimumDifference) {
			$items[] = sprintf($this->_i18n->getMessage($messageKey), $guestName, $guestValue, $homeValue);
		}
	}
}
?>
