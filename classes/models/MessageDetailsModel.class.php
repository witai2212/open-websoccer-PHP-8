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
class MessageDetailsModel implements IModel {
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
		
		$id = $this->_websoccer->getRequestParameter("id");
		$message = MessagesDataService::getMessageById($this->_websoccer, $this->_db, $id);
		if ($message) {
			$message["content_html"] = $this->_formatMessageContent($message["content"]);
		}
		
		// update "seen" state
		if ($message && !$message["seen"]) {
			$columns["gelesen"] = "1";
			$fromTable = $this->_websoccer->getConfig("db_prefix") . "_briefe";
			$whereCondition = "id = %d";
			
			$this->_db->queryUpdate($columns, $fromTable, $whereCondition, $id);
		}
		
		return array("message" => $message);
	}

	private function _formatMessageContent($content) {
		$content = htmlspecialchars((string) $content, ENT_QUOTES, "UTF-8");

		$pattern = "~(https?://[^\s<]+|/\?page=[^\s<]+|\?page=[^\s<]+)~i";
		$content = preg_replace_callback($pattern, array($this, "_replaceMessageLink"), $content);

		return nl2br($content);
	}

	private function _replaceMessageLink($matches) {
		$url = $matches[0];
		$trailing = "";
		while (strlen($url) && preg_match("/[\.,;:\)\]]$/", $url)) {
			$trailing = substr($url, -1) . $trailing;
			$url = substr($url, 0, -1);
		}

		$href = $url;
		if (strpos($href, "?page=") === 0) {
			$href = "/" . $href;
		}

		return "<a href=\"" . $href . "\">" . $url . "</a>" . $trailing;
	}
	
}

?>