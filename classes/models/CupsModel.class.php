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

/*
 * Task 1001 - National cup overview
 * Revision 1 - 2026-08-24
 */

require_once __DIR__ . '/../services/InternationalCupPhaseHelper.class.php';

/**
 * Provides national cup overview data.
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
		
		$user = $this->_websoccer->getUser();
		$userId = $user->id;
		
		$teamId = $user->getClubId($this->_websoccer, $this->_db);
		if ($teamId < 1) {
			throw new Exception($this->_i18n->getMessage("feature_requires_team"));
		}
		
		$myCup = CupsDataService::getCupDataByTeamId($this->_websoccer, $this->_db, $teamId);
		$cupId = (int) $this->_websoccer->getRequestParameter('cup');
		$cups = CupsDataService::getCups($this->_websoccer, $this->_db);
		
		if ($cupId < 1 && isset($myCup['id'])) {
			$cupId = (int) $myCup['id'];
		}
		if ($cupId < 1 && count($cups) && isset($cups[0]['id'])) {
			$cupId = (int) $cups[0]['id'];
		}
		
		$cup = ($cupId > 0)
			? CupsDataService::getCupDataByCupId($this->_websoccer, $this->_db, $cupId)
			: null;
		
		if ($cup && isset($cup['name']) && strlen((string) $cup['name'])) {
			InternationalCupPhaseHelper::selectCurrentPhase(
				$this->_websoccer,
				$this->_db,
				(string) $cup['name']
			);
			
			$overview = EuropeanCupDataService::getCupOverview(
				$this->_websoccer,
				$this->_db,
				(string) $cup['name'],
				'cups',
				'cups'
			);
		} else {
			$overview = EuropeanCupDataService::getCupOverview(
				$this->_websoccer,
				$this->_db,
				'',
				'cups',
				'cups'
			);
		}
		
		$overview['cups'] = $cups;
		$overview['selected_cup_id'] = $cupId;
		$overview['user_id'] = $userId;
		
		return $overview;
	}
	
}

?>