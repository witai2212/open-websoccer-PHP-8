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
 * Provides data for information about user's stadium.
 */
class StadiumModel implements IModel {
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
		
		$stadium = StadiumsDataService::getStadiumByTeamId($this->_websoccer, $this->_db, $teamId);
		
		$construction = StadiumsDataService::getCurrentConstructionOrderOfTeam($this->_websoccer, $this->_db, $teamId);
		$namingContract = StadiumsDataService::getActiveNamingContractByTeamId($this->_websoccer, $this->_db, $teamId);
		$namingOffers = array();
		if (!$namingContract) {
			$namingOffers = StadiumsDataService::getStadiumNamingOffers($this->_websoccer, $this->_db, $teamId);
		}
		
		$attendanceStats = array('matches' => array(), 'summary' => array(), 'has_snapshot' => FALSE);
		if ($teamId > 0) {
			$attendanceStats = StadiumAttendanceDataService::getRecentAttendanceByTeam($this->_websoccer, $this->_db, $teamId, 5);
		}
		
		$upgradeCosts = array();
		$stadiumDashboard = array();
		if ($stadium) {
			$upgradeCosts["pitch"] = StadiumsDataService::computeUpgradeCosts($this->_websoccer, "pitch", $stadium);
			$upgradeCosts["videowall"] = StadiumsDataService::computeUpgradeCosts($this->_websoccer, "videowall", $stadium);
			$upgradeCosts["seatsquality"] = StadiumsDataService::computeUpgradeCosts($this->_websoccer, "seatsquality", $stadium);
			$upgradeCosts["vipquality"] = StadiumsDataService::computeUpgradeCosts($this->_websoccer, "vipquality", $stadium);
			$stadiumDashboard = $this->_buildDashboardData($stadium, $attendanceStats);
		}
		
		return array("stadium" => $stadium, "construction" => $construction, "upgradeCosts" => $upgradeCosts,
				"namingContract" => $namingContract, "namingOffers" => $namingOffers,
				"attendanceStats" => $attendanceStats, "stadiumDashboard" => $stadiumDashboard);
	}

	private function _buildDashboardData($stadium, $attendanceStats) {
		$standingCapacity = $this->_toInt($stadium['places_stands']) + $this->_toInt($stadium['places_stands_grand']);
		$seatingCapacity = $this->_toInt($stadium['places_seats']) + $this->_toInt($stadium['places_seats_grand']);
		$vipCapacity = $this->_toInt($stadium['places_vip']);
		$totalCapacity = $standingCapacity + $seatingCapacity + $vipCapacity;

		$maxCapacity = $this->_toInt($this->_websoccer->getConfig('stadium_max_side'))
			+ $this->_toInt($this->_websoccer->getConfig('stadium_max_grand'))
			+ $this->_toInt($this->_websoccer->getConfig('stadium_max_vip'));

		$latestRow = NULL;
		if (isset($attendanceStats['matches'][0]) && isset($attendanceStats['matches'][0]['rows'])) {
			$latestRow = $this->_findAttendanceRow($attendanceStats['matches'][0]['rows'], 'stadium_attendance_total');
		}
		$averageRow = isset($attendanceStats['summary']) ? $this->_findAttendanceRow($attendanceStats['summary'], 'stadium_attendance_total') : NULL;

		return array(
			'total_capacity' => $totalCapacity,
			'standing_capacity' => $standingCapacity,
			'seating_capacity' => $seatingCapacity,
			'vip_capacity' => $vipCapacity,
			'max_capacity' => $maxCapacity,
			'capacity_percent' => $this->_percent($totalCapacity, $maxCapacity),
			'standing_share_percent' => $this->_percent($standingCapacity, $totalCapacity),
			'seating_share_percent' => $this->_percent($seatingCapacity, $totalCapacity),
			'vip_share_percent' => $this->_percent($vipCapacity, $totalCapacity),
			'latest_visitors' => $latestRow ? $this->_toInt($latestRow['visitors']) : 0,
			'latest_utilization_percent' => $latestRow ? (float) $latestRow['utilization_percent'] : 0,
			'latest_revenue' => $latestRow ? $this->_toInt($latestRow['revenue']) : 0,
			'average_visitors' => $averageRow ? $this->_toInt($averageRow['visitors']) : 0,
			'average_utilization_percent' => $averageRow ? (float) $averageRow['utilization_percent'] : 0,
			'average_revenue' => $averageRow ? $this->_toInt($averageRow['revenue']) : 0,
			'has_attendance' => ($latestRow || $averageRow)
		);
	}

	private function _findAttendanceRow($rows, $messageKey) {
		if (!is_array($rows)) {
			return NULL;
		}

		foreach ($rows as $row) {
			if (isset($row['message_key']) && $row['message_key'] == $messageKey) {
				return $row;
			}
		}

		return NULL;
	}

	private function _percent($value, $base) {
		$value = (float) $value;
		$base = (float) $base;
		if ($base <= 0) {
			return 0;
		}
		return round(($value / $base) * 100, 1);
	}

	private function _toInt($value) {
		return (int) round((float) $value);
	}
	
}

?>
