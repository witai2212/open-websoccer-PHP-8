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
 * Correct player values and update calculated strength/market value.
 *
 * This job keeps the legacy safety correction (w_staerke must not exceed
 * w_staerke_max) but uses the current incremental player value service for
 * calculated strength and market value updates.
 *
 * @author Ingo Hofmann
 */
class CorrectPlayerValuesJob extends AbstractJob {

    /**
     * @see AbstractJob::execute()
     */
    function execute() {
        PlayersDataService::playerStrengthCorrection($this->_websoccer, $this->_db);
        PlayersStrengthDataService::updateAllPlayersMarketAndStrength($this->_websoccer, $this->_db);
    }
}

?>
