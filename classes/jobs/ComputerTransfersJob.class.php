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
 * Process computer transfers.
 * 
 * @author Moritz Schneider
 */
class ComputerTransfersJob extends AbstractJob {
	
	/**
	 * @see AbstractJob::execute()
	 */
	function execute() {	    	    ComputerTransfersDataService::executeComputerBids($this->_websoccer, $this->_db);
	}
}

?>
