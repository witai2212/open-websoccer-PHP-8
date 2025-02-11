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
 * Provides teams without manager.
 */
class MyOffersModel implements IModel {
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
	    
	    $user = $this->_websoccer->getUser();
	    $userId = $user->id;
	    
	    $team = TeamsDataService::getTeamByUserId($this->_websoccer, $this->_db, $userId);
	    $teamId = $team['team_id'];
	    
	    $myoffers = TransfermarketDataService::getTransferOffers($this->_websoccer, $this->_db, $teamId);
		$mybids = TransfermarketDataService::getCurrentBidsOfTeam($this->_websoccer, $this->_db, $teamId);
		$myplayers = TransfermarketDataService::getPlayersOnTLByTeamId($this->_websoccer, $this->_db, $teamId);
		
		//echo"<pre>";
		//print_r($myplayers);
		//echo"</pre>";
		/*
		Array ( [4568] => Array ( [0] => 1000000 [amount] => 1000000 [1] => 0 [hand_money] => 0 [2] => 60 [contract_matches] => 60 [3] => 15000 
		[contract_salary] => 15000 [4] => 1500 [contract_goalbonus] => 1500 [5] => 1737483163 [date] => 1737483163 [6] => 1 [ishighest] => 1 
		[7] => 4568 [player_id] => 4568 [8] => Jover [player_firstname] => Jover [9] => Querano [player_lastname] => Querano [
		10] => [player_pseudonym] => [11] => 1737819158 [auction_end] => 1737819158 ) )
		*/
	    
		return array("offers" => $myoffers, "bids" => $mybids, "myplayers" => $myplayers);
	}
	
}

?>