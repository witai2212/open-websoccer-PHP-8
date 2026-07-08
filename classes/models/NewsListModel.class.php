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
define("NEWS_ENTRIES_PER_PAGE", 5);
define("NEWS_TEASER_MAXLENGTH", 256);

/**
 * @author Ingo Hofmann
 */
class NewsListModel implements IModel {
	private $_db;
	private $_i18n;
	private $_websoccer;
	
	public function __construct($db, $i18n, $websoccer) {
		$this->_db = $db;
		$this->_i18n = $i18n;
		$this->_websoccer = $websoccer;
	}
	
	/**
	 * (non-PHPdoc)
	 * @see IModel::renderView()
	 */
	public function renderView() {
		return TRUE;
	}
	
	/**
	 * (non-PHPdoc)
	 * @see IModel::getTemplateParameters()
	 */
	public function getTemplateParameters() {
		$prefix = $this->_websoccer->getConfig("db_prefix");
		$fromTable = $prefix . "_news";
		$whereCondition = "status = %d";
		$parameters = "1";
		
		// count items for pagination
		$result = $this->_db->querySelect("COUNT(*) AS hits", $fromTable, $whereCondition, $parameters);
		$rows = $result->fetch_array();
		$result->free();
		
		// enable paginations
		$eps = NEWS_ENTRIES_PER_PAGE;
		$paginator = new Paginator($rows["hits"], $eps, $this->_websoccer);
		
		// select
		$fromTable = $prefix . "_news AS NewsTab LEFT JOIN " . $prefix . "_fanpressure_story_log AS FanStoryTab ON FanStoryTab.news_id = NewsTab.id LEFT JOIN " . $prefix . "_verein AS TeamTab ON TeamTab.id = FanStoryTab.team_id";
		$columns = "NewsTab.id, NewsTab.titel, NewsTab.datum, NewsTab.nachricht, FanStoryTab.team_id, FanStoryTab.user_id, FanStoryTab.event_key, FanStoryTab.context_data, FanStoryTab.mood_change, FanStoryTab.pressure_change, FanStoryTab.board_change, FanStoryTab.chemistry_change, TeamTab.name AS team_name";
		$whereCondition = "NewsTab.status = %d ORDER BY NewsTab.datum DESC";
		$limit = $paginator->getFirstIndex() .",". $eps;
		$result = $this->_db->querySelect($columns, $fromTable, $whereCondition, $parameters, $limit);
		
		$articles = array();
		while ($article = $result->fetch_array()) {
			$display = $this->_getDisplayArticle($article);
			$articles[] = array("id" => $article["id"],
								"title" => $display["title"],
								"date" => $this->_websoccer->getFormattedDate($article["datum"]),
								"teaser" => $this->_shortenMessage($display["message"]));
		}
		$result->free();
		
		return array("articles" => $articles, "paginator" => $paginator);
	}
	

	private function _getDisplayArticle($article) {
		$title = $article["titel"];
		$message = $article["nachricht"];

		if (isset($article["event_key"]) && strlen((string) $article["event_key"]) && class_exists("FanPressureDataService")) {
			$storyRow = array(
				"team_id" => isset($article["team_id"]) ? $article["team_id"] : 0,
				"user_id" => isset($article["user_id"]) ? $article["user_id"] : 0,
				"event_key" => $article["event_key"],
				"title" => $article["titel"],
				"message" => $article["nachricht"],
				"context_data" => isset($article["context_data"]) ? $article["context_data"] : "",
				"mood_change" => isset($article["mood_change"]) ? $article["mood_change"] : 0,
				"pressure_change" => isset($article["pressure_change"]) ? $article["pressure_change"] : 0,
				"board_change" => isset($article["board_change"]) ? $article["board_change"] : 0,
				"chemistry_change" => isset($article["chemistry_change"]) ? $article["chemistry_change"] : 0,
				"team_name" => isset($article["team_name"]) ? $article["team_name"] : ""
			);
			$storyRow = FanPressureDataService::normalizeStoryDisplayRow($this->_websoccer, $this->_i18n, $storyRow);
			$title = $storyRow["title"];
			$message = $storyRow["message"];
		}

		return array("title" => $title, "message" => $message);
	}

	private function _shortenMessage($message) {
		if (strlen($message) > NEWS_TEASER_MAXLENGTH) {
			$message = wordwrap($message, NEWS_TEASER_MAXLENGTH);
			$message = substr($message, 0, strpos($message, "\n")) . "...";
		}
		return $message;
	}
	
}

?>