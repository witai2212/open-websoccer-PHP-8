<?php
/******************************************************

  This file is part of OpenWebSoccer-Sim.

******************************************************/

// CM23 Task 1009 | 2026-08-25 | Revision 1
/**
 * Provides the complete merchandising management page.
 */
class MerchandisingModel implements IModel {
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
        $user = $this->_websoccer->getUser();
        $teamId = $user->getClubId($this->_websoccer, $this->_db);
        if ($teamId < 1) {
            throw new Exception($this->_i18n->getMessage('feature_requires_team'));
        }

        $data = MerchandisingDataService::getPageData(
            $this->_websoccer,
            $this->_db,
            $this->_i18n,
            $teamId,
            $user->id
        );
        $data['teamId'] = $teamId;
        $tab = (string) $this->_websoccer->getRequestParameter('tab');
        $allowedTabs = array('overview','collections','development','warehouse','players','marketing','statistics');
        $data['activeTab'] = in_array($tab, $allowedTabs, true) ? $tab : 'overview';
        $data['merchandisingTrendStatistics'] = array();

        if ($data['activeTab'] === 'statistics') {
            $data['merchandisingTrendStatistics'] = MerchandisingStatisticsDataService::getTrendStatistics(
                $this->_websoccer,
                $this->_db,
                $teamId,
                30
            );

            if (isset($data['productStatistics']) && is_array($data['productStatistics'])) {
                foreach ($data['productStatistics'] as $index => $row) {
                    $demand = isset($row['demand_units']) ? max(0, (int) $row['demand_units']) : 0;
                    $sold = isset($row['units_sold']) ? max(0, (int) $row['units_sold']) : 0;
                    $data['productStatistics'][$index]['fulfilment_percent'] = $demand > 0
                        ? round(($sold / $demand) * 100, 1)
                        : NULL;
                }
            }
        }

        return $data;
    }
}
?>
