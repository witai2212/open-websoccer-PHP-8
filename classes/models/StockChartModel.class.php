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
define('NUMBER_OF_PLAYERS', 20);

/**
 * Provides list of actual stock exchange market values.
 */
class StockChartModel implements IModel {
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
        
        $userId = $this->_websoccer->getUser()->id;
        if ($userId < 1) {
            throw new Exception($this->_i18n->getMessage(MSG_KEY_ERROR_PAGENOTFOUND));
        }
        $stockId = (int) $this->_websoccer->getRequestParameter("id");
        
        $index = StockMarketDataService::getStockMarketDataById($this->_websoccer, $this->_db, $stockId);
        $grades = StockMarketDataService::getStockMarketDataByIdInverse($this->_websoccer, $this->_db, $stockId);
        
        /*
        print_r($index);
        echo"<br><br>";
        print_r($grades);
        */
        
        $grades = str_replace(",", ".", $grades);
        
        return array("grades" => $grades, "index" => $index);
    }
    
}

?>