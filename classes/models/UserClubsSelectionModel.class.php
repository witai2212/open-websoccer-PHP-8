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
 * Provides list of teams which are currently managed by the user.
 */
class UserClubsSelectionModel implements IModel {
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
		return (strlen($this->_websoccer->getUser()->username)) ? TRUE : FALSE;
	}
	
	/**
	 * (non-PHPdoc)
	 * @see IModel::getTemplateParameters()
	 */
	public function getTemplateParameters() {
		
		// select general information, including continent/association for multi-continent managers
		$prefix = $this->_websoccer->getConfig("db_prefix");
		$columns = "T.id, T.name, L.land, K.name AS continent_name";
		$fromTable = $prefix . "_verein AS T "
				. "INNER JOIN " . $prefix . "_liga AS L ON L.id = T.liga_id "
				. "LEFT JOIN " . $prefix . "_kontinent AS K ON K.id = L.kontinent_id";
		$result = $this->_db->querySelect($columns, $fromTable,
				"T.user_id = %d AND T.status = '1' AND T.nationalteam != '1' ORDER BY K.name ASC, T.name ASC",
				$this->_websoccer->getUser()->id);
		$teams = array();
		while ($team = $result->fetch_array()) {
			if (class_exists('ContinentalAssociationDataService')) {
				$config = ContinentalAssociationDataService::getAssociationConfig($team['continent_name']);
				$team['association_code'] = $config['code'];
				$team['association_label'] = $config['label'];
			} else {
				$team['association_code'] = '';
				$team['association_label'] = '';
			}
			$teams[] = $team;
		}
		$result->free();
		
		return array("userteams" => $teams);
	}
	
}

?>