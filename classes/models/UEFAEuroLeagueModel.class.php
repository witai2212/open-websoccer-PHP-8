<?php
/******************************************************

This file is part of OpenWebSoccer-Sim.

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
    
    public function renderView() {
        return TRUE;
    }
    
    public function getTemplateParameters() {
        
        $phase = $this->_websoccer->getRequestParameter('phase');
        
        if (!$phase) {
            $phase = 'A';
        }
        
        $str_phase = strtolower($phase);
        $gr_phase_data = array();
        
        // get EL data
        $el = UEFAEuroLeagueDataService::getELData($this->_websoccer, $this->_db);
        
        if (!$el) {
            return array(
                "el" => array(),
                "groups" => array(),
                "group_title" => "",
                "group_table" => array(),
                "matches" => array()
            );
        }
        
        $elId = $el['id'];
        
        $elGroupId = UEFAEuroLeagueDataService::getElGroupId(
            $this->_websoccer,
            $this->_db,
            $elId
            );
        
        // get EL groups
        $groups = UEFAEuroLeagueDataService::getELGroups(
            $this->_websoccer,
            $this->_db,
            $elGroupId
            );
        
        
        if ($phase == 'A' || $phase == 'B' || $phase == 'C' || $phase == 'D') {
            
            $gr_phase_data = UEFAEuroLeagueDataService::getELGroupDataByGroup(
                $this->_websoccer,
                $this->_db,
                $elGroupId,
                $phase
                );
            
            $group_title = "group_title_" . $str_phase;
            $cup_round = "Gruppen";
            
            // IMPORTANT:
            // DB group value is expected to be A / B / C / D, not "Gruppe A"
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
            
        } else if ($phase == 'sfinal') {
            
            $group_title = "sfinal_title";
            $cup_round = "Halbfinale";
            $cup_group = NULL;
            
        } else if ($phase == 'final') {
            
            $group_title = "final_title";
            $cup_round = "Finale";
            $cup_group = NULL;
            
        } else {
            
            // fallback: group A
            $phase = "A";
            $str_phase = "a";
            
            $gr_phase_data = UEFAEuroLeagueDataService::getELGroupDataByGroup(
                $this->_websoccer,
                $this->_db,
                $elGroupId,
                $phase
                );
            
            $group_title = "group_title_a";
            $cup_round = "Gruppen";
            $cup_group = "A";
        }
        
        // get matches
        $matches = UEFAEuroLeagueDataService::getELMatchesByRound(
            $this->_websoccer,
            $this->_db,
            'UEFA Euro League',
            $cup_round,
            $cup_group
            );
        
        return array(
            "el" => $el,
            "groups" => $groups,
            "group_title" => $group_title,
            "group_table" => $gr_phase_data,
            "matches" => $matches
        );
    }
}
?>