<?php
/******************************************************

This file is part of OpenWebSoccer-Sim.

******************************************************/

/**
 * Overview page for international cup associations.
 */
class InternationalCupsOverviewModel implements IModel {
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
        $associations = array();
        $currentAssociation = array();

        if (class_exists('ContinentalAssociationDataService')) {
            $associations = array_values(ContinentalAssociationDataService::getAssociationConfigs());

            $clubId = (int) $this->_websoccer->getUser()->getClubId($this->_websoccer, $this->_db);
            if ($clubId > 0) {
                $currentAssociation = ContinentalAssociationDataService::getTeamAssociation($this->_websoccer, $this->_db, $clubId);
            }
        }

        return array(
            'associations' => $associations,
            'current_association' => $currentAssociation
        );
    }
}
?>
