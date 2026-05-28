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
 * Applies derby/rivalry effects to ticket sales, news, finances and team pressure.
 */
class RivalriesPlugin {

	/**
	 * Forces derby matches to sell out and creates preview news once.
	 *
	 * @param TicketsComputedEvent $event Event.
	 */
	public static function handleTicketsComputed(TicketsComputedEvent $event) {

		$derbyInfo = RivalriesDataService::getDerbyInfoForMatch($event->websoccer, $event->db, $event->match);
		if (!$derbyInfo) {
			return;
		}

		RivalriesDataService::forceSoldOut($event);

		if (self::isConfigEnabled($event->websoccer, 'rivalries_create_preview_news', TRUE)
			&& !RivalriesDataService::hasPreviewNewsCreated($event->websoccer, $event->db, $event->match->id)) {
			self::createPreviewNews($event->websoccer, $event->db, $event->match, $derbyInfo);
			RivalriesDataService::markPreviewNewsCreated($event->websoccer, $event->db, $event->match->id);
		}
	}

	/**
	 * Creates result news and applies post-match derby effects once.
	 *
	 * @param MatchCompletedEvent $event Event.
	 */
	public static function handleCompletedDerby(MatchCompletedEvent $event) {

		$derbyInfo = RivalriesDataService::getDerbyInfoForMatch($event->websoccer, $event->db, $event->match);
		if (!$derbyInfo) {
			return;
		}

		if (!self::isPostProcessed($event->websoccer, $event->db, $event->match->id)) {
			if (self::isConfigEnabled($event->websoccer, 'rivalries_create_result_news', TRUE)) {
				self::createResultNews($event->websoccer, $event->db, $event->match, $derbyInfo);
			}

			RivalriesDataService::processCompletedDerby($event);
		}
	}

	private static function isConfigEnabled(WebSoccer $websoccer, $configKey, $defaultValue) {
		try {
			$value = $websoccer->getConfig($configKey);
		} catch (Exception $e) {
			return $defaultValue;
		}

		if ($value === null || $value === '') {
			return $defaultValue;
		}

		return ($value === TRUE || $value === 1 || $value === '1');
	}

	private static function isPostProcessed(WebSoccer $websoccer, DbConnection $db, $matchId) {
		$result = $db->querySelect(
			'post_processed',
			$websoccer->getConfig('db_prefix') . '_derby_match',
			'match_id = %d',
			(int) $matchId,
			1
		);
		$record = $result->fetch_array();
		$result->free();

		return ($record && $record['post_processed'] === '1');
	}

	private static function createPreviewNews(WebSoccer $websoccer, DbConnection $db, SimulationMatch $match, $derbyInfo) {
		$homeName = self::cleanTeamName($match->homeTeam->name);
		$guestName = self::cleanTeamName($match->guestTeam->name);
		$bonusPercent = RivalriesDataService::getBusinessBonusPercent($derbyInfo['strength']);

		$title = 'Derby-Fieber: ' . $homeName . ' gegen ' . $guestName;
		$message = 'Heute steht ein besonderes Derby an: ' . $homeName . ' empfängt ' . $guestName . '. ';
		$message .= 'Die Rivalität sorgt für ein ausverkauftes Stadion, mehr Druck auf Vorstand und Fans sowie bis zu ' . $bonusPercent . '% zusätzliche Derby-Effekte auf Merchandising und Stadionumfeld.';

		self::insertNews($websoccer, $db, $title, $message, (int) $match->id);
	}

	private static function createResultNews(WebSoccer $websoccer, DbConnection $db, SimulationMatch $match, $derbyInfo) {
		$homeName = self::cleanTeamName($match->homeTeam->name);
		$guestName = self::cleanTeamName($match->guestTeam->name);
		$homeGoals = (int) $match->homeTeam->getGoals();
		$guestGoals = (int) $match->guestTeam->getGoals();

		if ($homeGoals > $guestGoals) {
			$title = 'Derby-Sieg für ' . $homeName;
			$message = $homeName . ' gewinnt das Derby gegen ' . $guestName . ' mit ' . $homeGoals . ':' . $guestGoals . '. Der Erfolg bringt Rückenwind bei Fans und Vorstand.';
		} elseif ($guestGoals > $homeGoals) {
			$title = 'Auswärtssieg im Derby für ' . $guestName;
			$message = $guestName . ' gewinnt auswärts das Derby gegen ' . $homeName . ' mit ' . $guestGoals . ':' . $homeGoals . '. Beim Verlierer steigt der Druck deutlich.';
		} else {
			$title = 'Derby endet unentschieden';
			$message = $homeName . ' und ' . $guestName . ' trennen sich im Derby ' . $homeGoals . ':' . $guestGoals . '. Beide Fanlager nehmen zumindest einen Punkt aus dem besonderen Spiel mit.';
		}

		$message .= ' Die Rivalitätsstärke lag bei ' . (int) $derbyInfo['strength'] . ' von 100.';
		self::insertNews($websoccer, $db, $title, $message, (int) $match->id);
	}

	private static function insertNews(WebSoccer $websoccer, DbConnection $db, $title, $message, $matchId) {
		$db->queryInsert(
			array(
				'datum' => $websoccer->getNowAsTimestamp(),
				'autor_id' => 1,
				'titel' => $title,
				'nachricht' => $message,
				'linktext1' => 'Zum Spiel',
				'linkurl1' => $websoccer->getInternalUrl('match', 'id=' . (int) $matchId),
				'c_br' => '1',
				'c_links' => '1',
				'c_smilies' => '0',
				'status' => '1'
			),
			$websoccer->getConfig('db_prefix') . '_news'
		);
	}

	private static function cleanTeamName($teamName) {
		$teamName = trim((string) $teamName);
		if (!strlen($teamName)) {
			return 'Unbekanntes Team';
		}
		return $teamName;
	}
}
?>
