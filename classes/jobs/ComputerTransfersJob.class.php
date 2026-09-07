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
// CM23 | 2026-09-07 | Revision 2 | Task 1017 formation-driven CPU transfer market
/**
 * Process computer transfers.
 *
 * The formation-driven strategy is deliberately additive:
 * 1. prepare formation-specific squad needs and transfer-list movement,
 * 2. execute the existing ComputerTransfersDataService unchanged
 *    (budget checks, club/manager philosophy, offer limits and legacy rules),
 * 3. protect players that are still required for the formation's starting XI.
 *
 * @author Moritz Schneider
 */
class ComputerTransfersJob extends AbstractJob {
	
	/**
	 * @see AbstractJob::execute()
	 */
	function execute() {
        // Additional formation/position layer. Does not replace the established CPU rules.
        ComputerFormationTransferStrategyDataService::prepareFormationDrivenTransfers($this->_websoccer, $this->_db);

        // Existing CPU transfer engine remains the authoritative legacy decision layer.
        ComputerTransfersDataService::executeComputerBids($this->_websoccer, $this->_db);

        // Do not leave a formation-critical starter on the list after the combined run.
        ComputerFormationTransferStrategyDataService::cleanupFormationDrivenTransfers($this->_websoccer, $this->_db);
	}
}

?>