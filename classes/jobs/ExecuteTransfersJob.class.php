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

  CM23 Task 1027 | 08.09.2026 | Revision 1

******************************************************/

/**
 * Process open transfers.
 * Accepted precontracts are executed as soon as the current contract reaches zero.
 * 
 * @author Ingo Hofmann
 */
class ExecuteTransfersJob extends AbstractJob {
	
	/**
	 * @see AbstractJob::execute()
	 */
	function execute() {
		TransfermarketDataService::executeOpenTransfers($this->_websoccer, $this->_db);
		PlayerPrecontractDataService::processOpenOffers($this->_websoccer, $this->_db);
		PlayerPrecontractDataService::executeAcceptedTransfers($this->_websoccer, $this->_db);
		ComputerTransfersDataService::executeComputerBids($this->_websoccer, $this->_db);
	}
}

?>
