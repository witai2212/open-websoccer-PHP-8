<?php
/******************************************************

  This file is part of OpenWebSoccer-Sim.

******************************************************/

/**
 * Signs a stadium naming-right contract.
 */
class SignStadiumNamingContractController implements IActionController {
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

		if (StadiumsDataService::getActiveNamingContractByTeamId($this->_websoccer, $this->_db, $teamId)) {
			throw new Exception($this->_i18n->getMessage('stadium_naming_err_active_contract'));
		}

		$contract = StadiumsDataService::signStadiumNamingOffer($this->_websoccer, $this->_db, $teamId, (int) $parameters['id']);
		if (!$contract) {
			throw new Exception($this->_i18n->getMessage('stadium_naming_err_invalid_offer'));
		}

		$this->_websoccer->addFrontMessage(new FrontMessage(MESSAGE_TYPE_SUCCESS,
			$this->_i18n->getMessage('stadium_naming_sign_success'),
			$this->_i18n->getMessage('stadium_naming_sign_success_details', $contract['stadium_name'])));

		return 'stadium';
	}
}

?>
