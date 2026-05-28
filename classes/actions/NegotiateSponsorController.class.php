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

class NegotiateSponsorController implements IActionController {
	private $_i18n;
	private $_websoccer;
	private $_db;
	
	public function __construct(I18n $i18n, WebSoccer $websoccer, DbConnection $db) {
		$this->_i18n = $i18n;
		$this->_websoccer = $websoccer;
		$this->_db = $db;
	}
	
	public function executeAction($parameters) {
		$user = $this->_websoccer->getUser();
		$teamId = $user->getClubId($this->_websoccer, $this->_db);
		if ($teamId < 1) {
			return null;
		}

		$sponsor = SponsorsDataService::getSponsorinfoByTeamId($this->_websoccer, $this->_db, $teamId);
		if ($sponsor) {
			throw new Exception($this->_i18n->getMessage('sponsor_choose_stillcontract'));
		}

		$teamMatchday = MatchesDataService::getMatchdayNumberOfTeam($this->_websoccer, $this->_db, $teamId);
		if ($teamMatchday < $this->_websoccer->getConfig('sponsor_earliest_matchday')) {
			throw new Exception($this->_i18n->getMessage('sponsor_choose_tooearly', $this->_websoccer->getConfig('sponsor_earliest_matchday')));
		}

		$offerType = isset($parameters['offer_type']) ? $parameters['offer_type'] : '';
		if (!$this->isValidOfferType($offerType)) {
			throw new Exception($this->_i18n->getMessage('sponsor_choose_novalidsponsor'));
		}

		$result = SponsorsDataService::negotiateSponsorOffer($this->_websoccer, $this->_db, $teamId, $parameters['id'], $offerType);

		if (!isset($result['status']) || $result['status'] == 'invalid') {
			throw new Exception($this->_i18n->getMessage('sponsor_choose_novalidsponsor'));
		}

		if ($result['status'] == SponsorsDataService::NEGOTIATION_STATUS_WITHDRAWN) {
			$this->_websoccer->addFrontMessage(new FrontMessage(MESSAGE_TYPE_WARNING,
				$this->_i18n->getMessage('sponsor_negotiation_withdrawn'),
				''));
		} else {
			$this->_websoccer->addFrontMessage(new FrontMessage(MESSAGE_TYPE_SUCCESS,
				$this->_i18n->getMessage('sponsor_negotiation_success'),
				''));
		}

		return null;
	}

	private function isValidOfferType($offerType) {
		return in_array($offerType, array(
			SponsorsDataService::OFFER_TYPE_SAFE,
			SponsorsDataService::OFFER_TYPE_RISKY,
			SponsorsDataService::OFFER_TYPE_FAN,
			SponsorsDataService::OFFER_TYPE_CUP
		));
	}
}

?>
