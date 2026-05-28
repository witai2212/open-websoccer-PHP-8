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
 * Provides teams without manager.
 */
class FreeClubsModel implements IModel {
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
	    
	    $user = $this->_websoccer->getUser();
	    $userId = ($user->id) ? (int) $user->id : 0;
	    $managerScore = 0;
	    $careerEnabled = ManagerCareerDataService::isEnabled($this->_websoccer);
	    $freeClubReputationCheck = ManagerCareerDataService::isFreeClubReputationCheckEnabled($this->_websoccer);
	    
	    if ($userId > 0) {
	        $freeClubs = ManagerCareerDataService::getFreeClubsForManager($this->_websoccer, $this->_db, $userId, 80, TRUE);
	        $careerData = ManagerCareerDataService::getCareerPageData($this->_websoccer, $this->_db, $this->_i18n, $userId, $userId);
	        $managerScore = (isset($careerData['manager_score'])) ? (int) $careerData['manager_score'] : 0;
	    } else {
	        // Guests cannot have a manager reputation yet. Show classic free clubs around score 0.
	        $freeClubs = TeamsDataService::getFreeClubs($this->_websoccer, $this->_db, 0, 25);
	    }
	    
		return array(
		    "countries" => $freeClubs,
		    "manager_score" => $managerScore,
		    "career_enabled" => $careerEnabled,
		    "freeclub_rep_check" => $freeClubReputationCheck
		);
	}
	
}

?>