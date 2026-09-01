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

// CM23 | 2026-09-01 | Revision 1

/**
 * Provides available and built buildings of current club.
 */
class StadiumEnvironmentModel implements IModel {
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

		$teamId = $this->_websoccer->getUser()->getClubId($this->_websoccer, $this->_db);
		if ($teamId < 1) {
			throw new Exception($this->_i18n->getMessage("feature_requires_team"));
		}

		$dbPrefix = $this->_websoccer->getConfig('db_prefix');
		$now = $this->_websoccer->getNowAsTimestamp();

		// Apply completed one-time fan popularity effects when the manager opens this page.
		StadiumEnvironmentPlugin::applyCompletedFanPopularityBuildings($this->_websoccer, $this->_db, $teamId);

		// Load all building definitions once. This also allows showing locked buildings
		// and the readable name of prerequisites in the "available" table.
		$buildingDefinitions = array();
		$result = $this->_db->querySelect('*', $dbPrefix . '_stadiumbuilding', '1 = 1 ORDER BY name ASC');
		while ($building = $result->fetch_array()) {
			$building = $this->translateBuilding($building);
			$buildingDefinitions[(int) $building['id']] = $building;
		}
		$result->free();

		// Get existing buildings and remember which ones are already completed.
		$existingBuildings = array();
		$ownedBuildingIds = array();
		$completedBuildingIds = array();
		$result = $this->_db->querySelect(
			'*',
			$dbPrefix . '_buildings_of_team INNER JOIN ' . $dbPrefix . '_stadiumbuilding ON id = building_id',
			'team_id = %d ORDER BY construction_deadline DESC',
			$teamId
		);
		while ($building = $result->fetch_array()) {
			$building = $this->translateBuilding($building);
			$buildingId = (int) $building['building_id'];
			$building['under_construction'] = $now < (int) $building['construction_deadline'];
			$building['completed'] = !$building['under_construction'];
			$building['superseded'] = FALSE;
			$building['active'] = FALSE;
			$building['required_building_name'] = $this->getRequiredBuildingName($building, $buildingDefinitions);
			$ownedBuildingIds[$buildingId] = TRUE;
			if ($building['completed']) {
				$completedBuildingIds[$buildingId] = TRUE;
			}
			$existingBuildings[] = $building;
		}
		$result->free();

		// A completed successor replaces the ongoing effects of its direct predecessor.
		// The predecessor stays visible as historical/built infrastructure.
		$supersededBuildingIds = array();
		foreach ($existingBuildings as $building) {
			$requiredBuildingId = (int) $building['required_building_id'];
			if ($building['completed'] && $requiredBuildingId > 0) {
				$supersededBuildingIds[$requiredBuildingId] = TRUE;
			}
		}

		$totals = array(
			'effect_training' => 0.0,
			'effect_youthscouting' => 0,
			'effect_tickets' => 0,
			'effect_fanpopularity' => 0,
			'effect_injury' => 0,
			'effect_income' => 0,
			'effect_merchandising' => 0
		);

		foreach ($existingBuildings as $index => $building) {
			$buildingId = (int) $building['building_id'];
			$superseded = isset($supersededBuildingIds[$buildingId]);
			$active = $building['completed'] && !$superseded;
			$existingBuildings[$index]['superseded'] = $superseded;
			$existingBuildings[$index]['active'] = $active;

			if (!$active) {
				continue;
			}

			$totals['effect_training'] += (float) $building['effect_training'];
			$totals['effect_youthscouting'] += (int) $building['effect_youthscouting'];
			$totals['effect_tickets'] += (int) $building['effect_tickets'];
			$totals['effect_fanpopularity'] += (int) $building['effect_fanpopularity'];
			$totals['effect_injury'] += (int) $building['effect_injury'];
			$totals['effect_income'] += (int) $building['effect_income'];
			$totals['effect_merchandising'] += (int) $building['effect_merchandising'];
		}

		// Show every building which the club does not own yet. If a prerequisite is
		// missing or still under construction, keep the row visible but disable ordering.
		$availableBuildings = array();
		foreach ($buildingDefinitions as $buildingId => $building) {
			if (isset($ownedBuildingIds[$buildingId])) {
				continue;
			}

			$requiredBuildingId = (int) $building['required_building_id'];
			$building['required_building_name'] = $this->getRequiredBuildingName($building, $buildingDefinitions);
			$building['requirement_met'] = $requiredBuildingId < 1 || isset($completedBuildingIds[$requiredBuildingId]);
			$availableBuildings[] = $building;
		}

		return array(
			'existingBuildings' => $existingBuildings,
			'availableBuildings' => $availableBuildings,
			'total_effect_training' => $totals['effect_training'],
			'total_effect_youthscouting' => $totals['effect_youthscouting'],
			'total_effect_tickets' => $totals['effect_tickets'],
			'total_effect_fanpopularity' => $totals['effect_fanpopularity'],
			'total_effect_injury' => $totals['effect_injury'],
			'total_effect_income' => $totals['effect_income'],
			'total_effect_merchandising' => $totals['effect_merchandising']
		);
	}

	private function translateBuilding($building) {
		if ($this->_i18n->hasMessage($building['name'])) {
			$building['name'] = $this->_i18n->getMessage($building['name']);
		}
		if ($building['description'] && $this->_i18n->hasMessage($building['description'])) {
			$building['description'] = $this->_i18n->getMessage($building['description']);
		}
		return $building;
	}

	private function getRequiredBuildingName($building, $buildingDefinitions) {
		$requiredBuildingId = (int) $building['required_building_id'];
		if ($requiredBuildingId < 1 || !isset($buildingDefinitions[$requiredBuildingId])) {
			return '';
		}
		return $buildingDefinitions[$requiredBuildingId]['name'];
	}
}

?>
