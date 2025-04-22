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
class UEFAEuroLeagueModel implements IModel {
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
		
		$phase = $this->_websoccer->getRequestParameter('phase');
		$str_phase = strtolower($phase);
		    
		// get CL data
		$el = UEFAEuroLeagueDataService::getELData($this->_websoccer, $this->_db);
		$elId = $el['id'];
		
		$elGroupId = UEFAEuroLeagueDataService::getElGroupId($this->_websoccer, $this->_db, $elId);
		
		// get Cl groups
		$groups = UEFAEuroLeagueDataService::getELGroups($this->_websoccer, $this->_db, $elGroupId);
		
		
		if($phase=='A' || $phase=='B' || $phase=='C' || $phase=='D') {
		    $gr_phase_data = UEFAEuroLeagueDataService::getELGroupDataByGroup($this->_websoccer, $this->_db, $elGroupId, $phase);
		    $group_title = "group_title_". $str_phase;
		    $cup_round = "Gruppen";
		    $cup_group = "Gruppe $phase";
		    
		} else if($phase=='round1') {
		    //get round 1 data
		    $group_title = "1round_title";
		    $cup_round = "Runde 1";
		    $cup_group = NULL;
		    
		} else if($phase=='afinal') {
		    //get a_final data
		    $group_title = "afinal_title";
		    $cup_round = "Achtelfinale";
		    $cup_group = NULL;
		    
		} else if($phase=='qfinal') {
		    //get group quarter finale data
		    $group_title = "qfinal_title";
		    $cup_round = "Viertelfinale";
		    $cup_group = NULL;
		    
		} else if($phase=='sfinal') {
		    //get semi final data
		    $group_title = "sfinal_title";
		    $cup_round = "Halbfinale";
		    $cup_group = NULL;
		    
		} else if($phase=='final') {
		    //get final data
		    $group_title = "final_title";
		    $cup_round = "Finale";
		    $cup_group = NULL;
		    
		} else {
		    $gr_phase_data = UEFAEuroLeagueDataService::getELGroupDataByGroup($this->_websoccer, $this->_db, $elGroupId, $phase="A");
		    $group_title = "group_title_a";
		    $cup_round = "Gruppen";
		    $cup_group = "Gruppe A";
		}
		
		// get matches
		$matches = UEFAEuroLeagueDataService::getELMatchesByRound($this->_websoccer, $this->_db, $cup_name='UEFA Euro League', $cup_round, $cup_group);
		/*echo"<pre>";
		print_r($matches);
		echo"</pre>";*/
		
		return array("el" => $el, "groups" => $groups, "group_title" => $group_title, "group_table" => $gr_phase_data, "matches" => $matches);
	}
	
}

?>