<?php
use Twig\Cache\NullCache;

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
 * Player search for the transfer section.
 * Lists all visible players except own players. Hidden player attributes are
 * neither displayed nor available as filters when hide_strength_attributes is active.
 *
 * @author Ingo Hofmann
 */
class PlayersSearchModel implements IModel {
	private $_db;
	private $_i18n;
	private $_websoccer;
	
	private $_positionFilter;
	private $_advancedFilters = array();
	private $_advancedFiltersActive = false;
	
	public function __construct($db, $i18n, $websoccer) {
		$this->_db = $db;
		$this->_i18n = $i18n;
		$this->_websoccer = $websoccer;
	}
	
	public function renderView() {
		$this->_positionFilter = $this->_websoccer->getRequestParameter("position");
		$this->_readAdvancedFilters();
		
		return ($this->_websoccer->getConfig("transfermarket_enabled") == 1);
	}
	
	public function getTemplateParameters() {
		$teamId = $this->_websoccer->getUser()->getClubId($this->_websoccer, $this->_db);
		if ($teamId < 1) {
			throw new Exception($this->_i18n->getMessage("feature_requires_team"));
		}
		
		$playersCount = PlayersDataService::countTransferSectionPlayerSearch(
			$this->_websoccer,
			$this->_db,
			$teamId,
			$this->_positionFilter,
			$this->_advancedFilters
		);
		
		$eps = $this->_websoccer->getConfig("entries_per_page");
		$paginator = new Paginator($playersCount, $eps, $this->_websoccer);
		
		if ($this->_positionFilter != null && strlen(trim($this->_positionFilter)) > 0) {
			$paginator->addParameter("position", $this->_positionFilter);
		}
		
		foreach ($this->_advancedFilters as $filterKey => $values) {
			if ($values["min"] !== null) {
				$paginator->addParameter($filterKey . "_min", $values["min"]);
			}
			if ($values["max"] !== null) {
				$paginator->addParameter($filterKey . "_max", $values["max"]);
			}
		}
		
		if ($playersCount > 0) {
			$players = PlayersDataService::findTransferSectionPlayerSearch(
				$this->_websoccer,
				$this->_db,
				$teamId,
				$this->_positionFilter,
				$this->_advancedFilters,
				$paginator->getFirstIndex(),
				$eps
			);
		} else {
			$players = array();
		}
		
		return array(
			"playersCount" => $playersCount,
			"players" => $players,
			"paginator" => $paginator,
			"playerssearch_filter_definitions" => PlayersDataService::getTransfermarketFilterDefinitions($this->_websoccer),
			"playerssearch_advancedfilters_active" => $this->_advancedFiltersActive
		);
	}
	
	private function _readAdvancedFilters() {
		$filterDefinitions = PlayersDataService::getTransfermarketFilterDefinitions($this->_websoccer);
		$this->_advancedFilters = array();
		$this->_advancedFiltersActive = false;
		
		foreach ($filterDefinitions as $filterKey => $filterDefinition) {
			$minValue = $this->_getNumericRequestParameter($filterKey . "_min");
			$maxValue = $this->_getNumericRequestParameter($filterKey . "_max");
			
			if ($minValue !== null && $maxValue !== null && $minValue > $maxValue) {
				$tmpValue = $minValue;
				$minValue = $maxValue;
				$maxValue = $tmpValue;
			}
			
			$this->_advancedFilters[$filterKey] = array(
				"min" => $minValue,
				"max" => $maxValue
			);
			
			if ($minValue !== null || $maxValue !== null) {
				$this->_advancedFiltersActive = true;
			}
		}
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
