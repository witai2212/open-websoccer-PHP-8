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
 * Provides names of cups and their rounds.
 */
class CupsModel implements IModel {
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
		
		// userId
		$user = $this->_websoccer->getUser();
	    $userId = $user->id;
		
		// user teamId
		$teamId = $this->_websoccer->getUser()->getClubId($this->_websoccer, $this->_db);
		if ($teamId < 1) {
			throw new Exception($this->_i18n->getMessage("feature_requires_team"));
		}
		
		$myCup = CupsDataService::getCupDataByTeamId($this->_websoccer, $this->_db, $teamId);
		$cupId = $this->_websoccer->getRequestParameter('cup');
		$cups = CupsDataService::getCups($this->_websoccer, $this->_db);
		
		if($cupId < 1) {
			$cupId = $myCup['id'];
		}
		
		$cup = CupsDataService::getCupDataByCupId($this->_websoccer, $this->_db, $cupId);
		$matches = CupsDataService::getMatchesByCupname($this->_websoccer, $this->_db, $cup['name']);
		
		return array('cups' => $cups, 'cup' => $cup, 'matches' => $matches, "user_id" => $userId);
	}
	
}

?>