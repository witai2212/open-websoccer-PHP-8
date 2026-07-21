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
class TransfermarketOverviewModel implements IModel {
	private $_db;
	private $_i18n;
	private $_websoccer;
	
	public function __construct($db, $i18n, $websoccer) {
		$this->_db = $db;
		$this->_i18n = $i18n;
		$this->_websoccer = $websoccer;
	}
	
	public function renderView() {
		return ($this->_websoccer->getConfig("transfermarket_enabled") == 1);
	}
	
	public function getTemplateParameters() {
		
		$teamId = $this->_websoccer->getUser()->getClubId($this->_websoccer, $this->_db);
		if ($teamId < 1) {
			throw new Exception($this->_i18n->getMessage("feature_requires_team"));
		}
		
		$positionInput = $this->_websoccer->getRequestParameter("position");
		$positionFilter = $positionInput;
		
		$filterDefinitions = PlayersDataService::getTransfermarketFilterDefinitions($this->_websoccer);
		$advancedFilters = array();
		$advancedFiltersActive = false;
		
		foreach ($filterDefinitions as $filterKey => $filterDefinition) {
			$minValue = $this->_getNumericRequestParameter($filterKey . "_min");
			$maxValue = $this->_getNumericRequestParameter($filterKey . "_max");
			
			if ($minValue !== null && $maxValue !== null && $minValue > $maxValue) {
				$tmpValue = $minValue;
				$minValue = $maxValue;
				$maxValue = $tmpValue;
			}
			
			$advancedFilters[$filterKey] = array(
				"min" => $minValue,
				"max" => $maxValue
			);
			
			if ($minValue !== null || $maxValue !== null) {
				$advancedFiltersActive = true;
			}
		}
		
		$count = PlayersDataService::countPlayersOnTransferList($this->_websoccer, $this->_db, $positionFilter, $advancedFilters);
		$countOffers = PlayersDataService::countPlayerOffers($this->_websoccer, $this->_db);
		
		$eps = $this->_websoccer->getConfig("entries_per_page");
		$paginator = new Paginator($count, $eps, $this->_websoccer);
		
		if ($positionFilter != null && strlen(trim($positionFilter)) > 0) {
		    $paginator->addParameter("position", $positionInput);
		}
		
		foreach ($advancedFilters as $filterKey => $values) {
			if ($values["min"] !== null) {
				$paginator->addParameter($filterKey . "_min", $values["min"]);
			}
			if ($values["max"] !== null) {
				$paginator->addParameter($filterKey . "_max", $values["max"]);
			}
		}
		
		if ($count > 0) {
			$players = PlayersDataService::getPlayersOnTransferList($this->_websoccer, $this->_db, $paginator->getFirstIndex(), $eps, $positionFilter, $advancedFilters);
		} else {
			$players = array();
		}

		$offers = TransfermarketDataService::getTransferOffers($this->_websoccer, $this->_db, $teamId);
		$bids = TransfermarketDataService::getCurrentBidsOfTeam($this->_websoccer, $this->_db, $teamId);
		$myplayers = TransfermarketDataService::getPlayersOnTLByTeamId($this->_websoccer, $this->_db, $teamId);
		$activeTab = $this->_websoccer->getRequestParameter("tab");
		if (!in_array($activeTab, array("market", "offers", "lasttransfers", "mytransfers"))) {
			$activeTab = "market";
		}
		
		return array(
			"transferplayers" => $players,
			"playerscount" => $count,
			"playeroffers" => $countOffers,
			"paginator" => $paginator,
			"transfermarket_filter_definitions" => $filterDefinitions,
			"transfermarket_advancedfilters_active" => $advancedFiltersActive,
			"offers" => $offers,
			"bids" => $bids,
			"myplayers" => $myplayers,
			"active_tab" => $activeTab
		);
	}
	
	private function _getNumericRequestParameter($parameterName) {
		$value = $this->_websoccer->getRequestParameter($parameterName);
		
		if ($value === null) {
			return null;
		}
		
		$value = trim($value);
		if ($value === '') {
			return null;
		}
		
		$value = str_replace(',', '.', $value);
		if (!is_numeric($value)) {
			return null;
		}
		
		return round((float) $value, 2);
	}
	
}

?>
