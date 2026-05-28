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
class ChampionsleagueModel implements IModel {
    
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
        
        // Default view: Group A
        if (!$phase) {
            $phase = 'A';
        }
        
        $str_phase = strtolower($phase);
        
        // Prevent undefined variable for knockout phases
        $gr_phase_data = array();
        
        // get CL data
        $cl = ChampionsleagueDataService::getCLData(
            $this->_websoccer,
            $this->_db
            );
        
        if (!$cl) {
            return array(
                "cl" => array(),
                "groups" => array(),
                "group_title" => "",
                "group_table" => array(),
                "matches" => array()
            );
        }
        
        $clId = $cl['id'];
        
        $clGroupId = ChampionsleagueDataService::getClGroupId(
            $this->_websoccer,
            $this->_db,
            $clId
            );
        
        // get CL groups
        $groups = ChampionsleagueDataService::getCLGroups(
            $this->_websoccer,
            $this->_db,
            $clGroupId
            );
        
        
        if ($phase == 'A' || $phase == 'B' || $phase == 'C' || $phase == 'D') {
            
            $gr_phase_data = ChampionsleagueDataService::getCLGroupDataByGroup(
                $this->_websoccer,
                $this->_db,
                $clGroupId,
                $phase,
                16
                );
            
            $group_title = "group_title_" . $str_phase;
            $cup_round = "Gruppen";
            
            // IMPORTANT:
            // Must be A / B / C / D, not "Gruppe A"
            $cup_group = $phase;
            
        } else if ($phase == 'round1') {
            
            $group_title = "1round_title";
            $cup_round = "Runde 1";
            $cup_group = NULL;
            
        } else if ($phase == 'afinal') {
            
            $group_title = "afinal_title";
            $cup_round = "Achtelfinale";
            $cup_group = NULL;
            
        } else if ($phase == 'vfinal') {
            
            $group_title = "qfinal_title";
            $cup_round = "Viertelfinale";
            $cup_group = NULL;
            
        } else if ($phase == 'hfinal') {
            
            $group_title = "sfinal_title";
            $cup_round = "Halbfinale";
            $cup_group = NULL;
            
        } else if ($phase == 'final') {
            
            $group_title = "final_title";
            $cup_round = "Finale";
            $cup_group = NULL;
            
        } else {
            
            // Fallback: Group A
            $phase = "A";
            
            $gr_phase_data = ChampionsleagueDataService::getCLGroupDataByGroup(
                $this->_websoccer,
                $this->_db,
                $clGroupId,
                $phase,
                16
                );
            
            $group_title = "group_title_a";
            $cup_round = "Gruppen";
            $cup_group = "A";
        }
        
        // get matches
        $matches = ChampionsleagueDataService::getCLMatchesByRound(
            $this->_websoccer,
            $this->_db,
            'Champions League',
            $cup_round,
            $cup_group
            );
        
        return array(
            "cl" => $cl,
            "groups" => $groups,
            "group_title" => $group_title,
            "group_table" => $gr_phase_data,
            "matches" => $matches
        );
    }
}
?>