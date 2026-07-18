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
class NewsDetailsModel implements IModel {
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
		$tablePrefix = $this->_websoccer->getConfig("db_prefix") . "_";
		$fromTable = $tablePrefix . "news AS NewsTab";
		$fromTable .= " LEFT JOIN " . $tablePrefix . "admin AS AdminTab ON NewsTab.autor_id = AdminTab.id";
		$fromTable .= " LEFT JOIN " . $tablePrefix . "fanpressure_story_log AS FanStoryTab ON FanStoryTab.news_id = NewsTab.id";
		$fromTable .= " LEFT JOIN " . $tablePrefix . "verein AS TeamTab ON TeamTab.id = FanStoryTab.team_id";
		$whereCondition = "NewsTab.id = %d AND NewsTab.status = 1 LIMIT 50";
		$parameters = (int) $this->_websoccer->getRequestParameter("id");
		
		$columns = "NewsTab.*, AdminTab.name AS author_name, FanStoryTab.team_id, FanStoryTab.user_id, FanStoryTab.event_key, FanStoryTab.context_data, FanStoryTab.mood_change, FanStoryTab.pressure_change, FanStoryTab.board_change, FanStoryTab.chemistry_change, TeamTab.name AS team_name";
		$result = $this->_db->querySelect($columns, $fromTable, $whereCondition, $parameters);
		$item = $result->fetch_array();
		$result->free();
		
		if (!$item) {
			throw new Exception($this->_i18n->getMessage(MSG_KEY_ERROR_PAGENOTFOUND));
		}
		
		$display = $this->_getDisplayArticle($item);
		
		// convert message
		$message = $display["message"];
		if ($item["c_br"]) {
			$message = nl2br($message);
		}
		if ($item["c_links"]) {
			$message = $this->_strToLink($message);
		}
		
		// related links
		$relatedLinks = array();
		
		if ($item["linktext1"] && $item["linkurl1"]) {
			$relatedLinks[$item["linkurl1"]] = $item["linktext1"];
		}
		if ($item["linktext2"] && $item["linkurl2"]) {
			$relatedLinks[$item["linkurl2"]] = $item["linktext2"];
		}
		if ($item["linktext3"] && $item["linkurl3"]) {
			$relatedLinks[$item["linkurl3"]] = $item["linktext3"];
		}
		
		$article = array("id" => $item["id"],
				"title" => $display["title"],
				"date" => $this->_websoccer->getFormattedDate($item["datum"]),
				"message" => $message,
				"author_name" => $item["author_name"]);
		
		return array("article" => $article, "relatedLinks" => $relatedLinks);
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

	private function _strToLink($str) {

	  //URL
	  $str = preg_replace("#([\t\r\n ])([a-z0-9]+?){1}://([\w\-]+\.([\w\-]+\.)*[\w]+(:[0-9]+)?(/[^ \"\n\r\t<]*)?)#i", 
	  			'\1<a href="\2://\3" target="_blank">\2://\3</a>', $str);
	
	  //EMail
	  $str = preg_replace("#([\n ])([a-z0-9\-_.]+?)@([\w\-]+\.([\w\-\.]+\.)*[\w]+)#i", 
	  		"\\1<a href=\"mailto:\\2@\\3\">\\2@\\3</a>", $str);
	
	  return $str;
	
	}
	
}

?>