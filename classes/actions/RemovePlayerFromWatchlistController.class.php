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
 * Manager of a player can remove player from transfer market
 * as long as there is no bid for him.
 */
class RemovePlayerFromWatchlistController implements IActionController {
	private $_i18n;
	private $_websoccer;
	private $_db;
	
	public function __construct(I18n $i18n, WebSoccer $websoccer, DbConnection $db) {
		$this->_i18n = $i18n;
		$this->_websoccer = $websoccer;
		$this->_db = $db;
	}
	
	/**
	 * (non-PHPdoc)
	 * @see IActionController::executeAction()
	 */
	public function executeAction($parameters) {
		// check if feature is enabled
		if (!$this->_websoccer->getConfig("transfermarket_enabled")) {
			return NULL;
		}
		
		$user = $this->_websoccer->getUser();
		$clubId = $user->getClubId($this->_websoccer, $this->_db);
		$playerId = $parameters["id"];
		
		$this->_db->queryDelete($this->_websoccer->getConfig('db_prefix') . "_watchlist", "spieler_id = %d AND verein_id = %d", array($playerId, $clubId));
		
		// success message
		$this->_websoccer->addFrontMessage(new FrontMessage(MESSAGE_TYPE_SUCCESS, 
				$this->_i18n->getMessage("watchlist_remove_success"),
				""));
		
		return "mywatchlist";
	}
	
}

?>