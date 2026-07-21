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
 * @author Ingo Hofmann
 */
class SponsorModel implements IModel {
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
		
		$teamId = $this->_websoccer->getUser()->getClubId($this->_websoccer, $this->_db);
		if ($teamId < 1) {
			throw new Exception($this->_i18n->getMessage("feature_requires_team"));
		}
		
		$sponsor = SponsorsDataService::getSponsorinfoByTeamId($this->_websoccer, $this->_db, $teamId);
		$projectionContext = $this->_getProjectionContext($teamId);
		$sponsorEarnings = $this->_getSponsorEarnings($teamId, $sponsor);
		$namingContract = $this->_getNamingContract($teamId);
		
		$sponsors = array();
		$teamMatchday = 0;
		if (!$sponsor) {
			$teamMatchday = MatchesDataService::getMatchdayNumberOfTeam($this->_websoccer, $this->_db, $teamId);
			
			if ($teamMatchday >= $this->_websoccer->getConfig("sponsor_earliest_matchday")) {
				$sponsors = SponsorsDataService::getSponsorOffers($this->_websoccer, $this->_db, $teamId);
				foreach ($sponsors as $index => $offer) {
					$sponsors[$index]["estimated_income"] = $this->_estimateSponsorIncome($offer, $projectionContext);
				}
			}
			
		}

		if ($sponsor) {
			$sponsor["estimated_income"] = $this->_estimateSponsorIncome($sponsor, $projectionContext);
		}

		return array(
			"sponsor" => $sponsor,
			"sponsors" => $sponsors,
			"teamMatchday" => $teamMatchday,
			"sponsor_earnings" => $sponsorEarnings,
			"projection_context" => $projectionContext,
			"naming_contract" => $namingContract
		);
	}

	private function _getProjectionContext($teamId) {
		$now = $this->_websoccer->getNowAsTimestamp();
		$prefix = $this->_websoccer->getConfig("db_prefix");
		$query = "SELECT COUNT(*) AS matches_left,
		                 SUM(CASE WHEN home_verein = '". (int) $teamId ."' THEN 1 ELSE 0 END) AS home_matches_left,
		                 SUM(CASE WHEN spieltyp = 'Pokalspiel' THEN 1 ELSE 0 END) AS cup_matches_left
		          FROM ". $prefix ."_spiel
		          WHERE berechnet = '0' AND datum >= '". (int) $now ."'
		            AND (home_verein = '". (int) $teamId ."' OR gast_verein = '". (int) $teamId ."')";
		$result = $this->_db->executeQuery($query);
		$row = $result->fetch_assoc();
		$result->free();
		$matches = isset($row["matches_left"]) ? (int) $row["matches_left"] : 0;
		if ($matches < 1) {
			$matches = max(1, (int) $this->_websoccer->getConfig("sponsor_matches"));
		}
		$homeMatches = isset($row["home_matches_left"]) ? (int) $row["home_matches_left"] : (int) ceil($matches / 2);
		$cupMatches = isset($row["cup_matches_left"]) ? (int) $row["cup_matches_left"] : 0;

		$teamResult = $this->_db->querySelect(
			"sa_siege, sa_spiele",
			$prefix . "_verein",
			"id = %d",
			(int) $teamId,
			1
		);
		$team = $teamResult->fetch_assoc();
		$teamResult->free();
		$wins = ($team && isset($team["sa_siege"])) ? (int) $team["sa_siege"] : 0;
		$played = ($team && isset($team["sa_spiele"])) ? (int) $team["sa_spiele"] : 0;
		$winRate = $played > 0 ? min(0.8, max(0.15, $wins / $played)) : 0.35;

		return array(
			"matches" => $matches,
			"home_matches" => max(0, $homeMatches),
			"cup_matches" => max(0, $cupMatches),
			"estimated_wins" => (int) round($matches * $winRate)
		);
	}

	private function _estimateSponsorIncome($offer, $context) {
		$income = ((int) $offer["amount_match"] * (int) $context["matches"])
			+ ((int) $offer["amount_home_bonus"] * (int) $context["home_matches"])
			+ ((int) $offer["amount_win"] * (int) $context["estimated_wins"])
			+ ((int) $offer["amount_cup"] * (int) $context["cup_matches"]);
		if (isset($offer["amount_attendance_percent"]) && (int) $offer["amount_attendance_percent"] > 0) {
			$income += (int) round((int) $offer["amount_match"] * ((int) $offer["amount_attendance_percent"] / 100) * 0.75 * (int) $context["home_matches"]);
		}
		return max(0, $income);
	}

	private function _getSponsorEarnings($teamId, $sponsor) {
		$start = ($sponsor && isset($sponsor["signed_date"])) ? (int) $sponsor["signed_date"] : 0;
		$where = "verein_id = %d AND verwendung IN ('match_sponsorpayment_subject','sponsor_championship_bonus_subject','sponsor_cup_bonus_subject')";
		$params = array((int) $teamId);
		if ($start > 0) {
			$where .= " AND datum >= %d";
			$params[] = $start;
		}
		$result = $this->_db->querySelect(
			"COALESCE(SUM(betrag),0) AS total, COUNT(*) AS payments",
			$this->_websoccer->getConfig("db_prefix") . "_konto",
			$where,
			$params,
			1
		);
		$row = $result->fetch_assoc();
		$result->free();
		return array("total" => (int) $row["total"], "payments" => (int) $row["payments"]);
	}

	private function _getNamingContract($teamId) {
		$prefix = $this->_websoccer->getConfig("db_prefix");
		$query = "SELECT C.*, COALESCE(SUM(P.payout_amount),0) AS earned_amount, COUNT(P.id) AS payout_count
		          FROM ". $prefix ."_stadium_naming_contract AS C
		          LEFT JOIN ". $prefix ."_stadium_naming_payout AS P ON P.contract_id = C.id
		          WHERE C.team_id = '". (int) $teamId ."' AND C.status = 'active'
		          GROUP BY C.id ORDER BY C.signed_date DESC LIMIT 1";
		try {
			$result = $this->_db->executeQuery($query);
			$row = $result->fetch_assoc();
			$result->free();
			return $row ? $row : array();
		} catch (Exception $e) {
			return array();
		}
	}
	
}

?>