<?php
use Twig\Cache\NullCache;

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
 * @author Ingo Hofmann
 */
class PlayersSearchModel implements IModel {
	private $_db;
	private $_i18n;
	private $_websoccer;
	
	private $_firstName;
	private $_lastName;
	private $_club;
	private $_position;
	private $_strength;
	
	private $_passing;
	private $_shooting;
	private $_tackling;
	private $_heading;
	private $_freekick;
	private $_creativity;
	private $_pace;
	private $_influence;
	private $_flair;
	private $_penalty;
	private $_penalty_killing;
	
	private $_lendableOnly;
	
	public function __construct($db, $i18n, $websoccer) {
		$this->_db = $db;
		$this->_i18n = $i18n;
		$this->_websoccer = $websoccer;
	}
	
	public function renderView() {
	    
		$this->_firstName = $this->_websoccer->getRequestParameter("fname");
		$this->_lastName = $this->_websoccer->getRequestParameter("lname");
		$this->_club = $this->_websoccer->getRequestParameter("club");
		$this->_position = $this->_websoccer->getRequestParameter("position");
		$this->_strength = $this->_websoccer->getRequestParameter("strength");
		
		$this->_passing = $this->_websoccer->getRequestParameter("passing");
		$this->_shooting = $this->_websoccer->getRequestParameter("shooting");
		$this->_heading = $this->_websoccer->getRequestParameter("heading");
		$this->_tackling = $this->_websoccer->getRequestParameter("tackling");
		$this->_freekick = $this->_websoccer->getRequestParameter("freekick");
		$this->_creativity = $this->_websoccer->getRequestParameter("creativity");
		$this->_pace = $this->_websoccer->getRequestParameter("pace");
		$this->_influence = $this->_websoccer->getRequestParameter("influence");
		$this->_flair = $this->_websoccer->getRequestParameter("flair");
		$this->_penalty = $this->_websoccer->getRequestParameter("penalty");
		$this->_penalty_killing = $this->_websoccer->getRequestParameter("penalty_killing");
		
		$this->_lendableOnly = ($this->_websoccer->getRequestParameter("lendable") == "1") ? TRUE : FALSE;
		
		// display content only if user entered any filter
		return ($this->_firstName !== null || $this->_lastName !== null
				|| $this->_club !== null || $this->_position !== null
				|| $this->_strength !== null || $this->_lendableOnly
    		    || $this->_passing !== null || $this->_shooting !== null 
    		    || $this->_heading !== null || $this->_tackling !== null 
    		    || $this->_freekick !== null || $this->_creativity !== null 
    		    || $this->_pace !== null || $this->_influence !== null
		        || $this->_flair !== null || $this->_penalty !== null
		        || $this->_penalty_killing !== null);
	}
	
	public function getTemplateParameters() {
		
		$playersCount = PlayersDataService::findPlayersCount($this->_websoccer, $this->_db, 
		    $this->_firstName, $this->_lastName, $this->_club, $this->_position, $this->_strength, 
		    $this->_passing,
		    $this->_shooting,
		    $this->_heading,
		    $this->_tackling,
		    $this->_freekick,
		    $this->_creativity,
		    $this->_pace,
		    $this->_influence,
		    $this->_flair,
		    $this->_penalty,
		    $this->_penalty_killing,
		    
		    $this->_lendableOnly);
		
		// setup paginator
		$eps = $this->_websoccer->getConfig("entries_per_page");
		$paginator = new Paginator($playersCount, $eps, $this->_websoccer);
		$paginator->addParameter("block", "playerssearch-results");
		$paginator->addParameter("fname", $this->_firstName);
		$paginator->addParameter("lname", $this->_lastName);
		$paginator->addParameter("club", $this->_club);
		$paginator->addParameter("position", $this->_position);
		$paginator->addParameter("strength", $this->_strength);
		
		$paginator->addParameter("passing", $this->_passing);
		$paginator->addParameter("shooting", $this->_shooting);
		$paginator->addParameter("heading", $this->_heading);
		$paginator->addParameter("tackling", $this->_tackling);
		$paginator->addParameter("freekick", $this->_freekick);
		$paginator->addParameter("creativity", $this->_creativity);
		$paginator->addParameter("pace", $this->_pace);
		$paginator->addParameter("influence", $this->_influence);
		$paginator->addParameter("flair", $this->_flair);
		$paginator->addParameter("penalty", $this->_penalty);
		$paginator->addParameter("penalty_killing", $this->_penalty_killingh);
		
		$paginator->addParameter("lendable", $this->_lendableOnly);
		
		// get players records
		if ($playersCount > 0) {
			$players = PlayersDataService::findPlayers($this->_websoccer, $this->_db,
			    $this->_firstName, $this->_lastName, $this->_club, $this->_position, $this->_strength,
			    $this->_passing,
			    $this->_shooting,
			    $this->_heading,
			    $this->_tackling,
			    $this->_freekick,
			    $this->_creativity,
			    $this->_pace,
			    $this->_influence,
			    $this->_flair,
			    $this->_penalty,
			    $this->_penalty_killing,
			    $this->_lendableOnly,
				$paginator->getFirstIndex(), $eps);
			
		} else {
			$players = array();
		}
		
		return array("playersCount" => $playersCount, "players" => $players, "paginator" => $paginator);
	}
	
	
}

?>