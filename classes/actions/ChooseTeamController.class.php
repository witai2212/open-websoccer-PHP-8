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

class ChooseTeamController implements IActionController {
	private $_i18n;
	private $_websoccer;
	private $_db;
	
	public function __construct(I18n $i18n, WebSoccer $websoccer, DbConnection $db) {
		$this->_i18n = $i18n;
		$this->_websoccer = $websoccer;
		$this->_db = $db;
	}
	
	public function executeAction($parameters) {
		
		$user = $this->_websoccer->getUser();
		
		// check whether featue is enabled
		if (!$this->_websoccer->getConfig("assign_team_automatically")) {
			throw new Exception($this->_i18n->getMessage("freeclubs_msg_error"));
		}
		
		if ($user->getClubId($this->_websoccer, $this->_db) > 0) {
			throw new Exception($this->_i18n->getMessage("freeclubs_msg_error_user_is_already_manager"));
		}
		
		$teamId = (int) $parameters["teamId"];
		
		ManagerCareerDataService::assignFreeClub($this->_websoccer, $this->_db, $this->_i18n, $user->id, $teamId, ManagerCareerDataService::ORIGIN_FREE_CLUB);
		$user->setClubId($teamId);
		
		// success message
		$this->_websoccer->addFrontMessage(new FrontMessage(MESSAGE_TYPE_SUCCESS, 
				$this->_i18n->getMessage("freeclubs_msg_success"),
				""));
		
		return "office";
	}
	
}

?>