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
define("NUMBER_OF_TOP_NEWS", 5);

/**
 * @author Ingo Hofmann
 */
class TopNewsListModel implements IModel {
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
		$prefix = $this->_websoccer->getConfig("db_prefix");
		$fromTable = $prefix . "_news AS NewsTab LEFT JOIN " . $prefix . "_fanpressure_story_log AS FanStoryTab ON FanStoryTab.news_id = NewsTab.id LEFT JOIN " . $prefix . "_verein AS TeamTab ON TeamTab.id = FanStoryTab.team_id";
		
		// select
		$columns = "NewsTab.id, NewsTab.titel, NewsTab.datum, NewsTab.nachricht, FanStoryTab.team_id, FanStoryTab.user_id, FanStoryTab.event_key, FanStoryTab.context_data, FanStoryTab.mood_change, FanStoryTab.pressure_change, FanStoryTab.board_change, FanStoryTab.chemistry_change, TeamTab.name AS team_name";
		$whereCondition = "NewsTab.status = 1 ORDER BY NewsTab.datum DESC";
		$result = $this->_db->querySelect($columns, $fromTable, $whereCondition, array(), NUMBER_OF_TOP_NEWS);
		
		$articles = array();
		while ($article = $result->fetch_array()) {
			$title = $article["titel"];
			if (isset($article["event_key"]) && strlen((string) $article["event_key"]) && class_exists("FanPressureDataService")) {
				$storyRow = FanPressureDataService::normalizeStoryDisplayRow($this->_websoccer, $this->_i18n, $article);
				$title = $storyRow["title"];
			}
			$articles[] = array("id" => $article["id"],
								"title" => $title,
								"date" => $this->_websoccer->getFormattedDate($article["datum"]));
		}
		$result->free();
		
		return array("topnews" => $articles);
	}
	
	
}

?>