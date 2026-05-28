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
 * Model for the financial forecast page.
 */
class FinancialForecastModel implements IModel {
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
        $teamId = $this->_websoccer->getUser()->getClubId($this->_websoccer, $this->_db);
        if ($teamId < 1) {
            throw new Exception($this->_i18n->getMessage('feature_requires_team'));
        }

        $forecast = FinancialForecastDataService::getForecast($this->_websoccer, $this->_db, $teamId);
        if (!count($forecast)) {
            throw new Exception($this->_i18n->getMessage('feature_requires_team'));
        }

        return $forecast;
    }
}
?>
