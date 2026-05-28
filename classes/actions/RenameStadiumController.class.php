<?php
/******************************************************

  This file is part of OpenWebSoccer-Sim.

******************************************************/

/**
 * Freely renames the user's stadium unless naming rights are active.
 */
class RenameStadiumController implements IActionController {
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

		$newName = StadiumsDataService::sanitizeStadiumName($parameters['stadium_name']);
		if (strlen($newName) < 2) {
			throw new Exception($this->_i18n->getMessage('stadium_rename_err_invalid'));
		}

		if (StadiumsDataService::getActiveNamingContractByTeamId($this->_websoccer, $this->_db, $teamId)) {
			throw new Exception($this->_i18n->getMessage('stadium_rename_err_active_contract'));
		}

		StadiumsDataService::renameStadium($this->_websoccer, $this->_db, $teamId, $newName);

		$this->_websoccer->addFrontMessage(new FrontMessage(MESSAGE_TYPE_SUCCESS,
			$this->_i18n->getMessage('stadium_rename_success'),
			''));

		return 'stadium';
	}
}

?>
