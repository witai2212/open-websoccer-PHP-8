<?php
/******************************************************

  This file is part of OpenWebSoccer-Sim.

******************************************************/

/**
 * Automatically manages youth teams of computer-controlled clubs.
 *
 * This job only orchestrates the process. The actual logic is implemented in
 * ComputerYouthTeamsDataService.
 */
class ComputerYouthTeamsJob extends AbstractJob {

	/**
	 * @see AbstractJob::execute()
	 */
	function execute() {

		// Safety switch from modules/youth/module.xml
		if ((int) $this->_websoccer->getConfig("computer_youth_enabled") !== 1) {
			echo "[ComputerYouthTeamsJob] disabled by config.\n";
			return;
		}

		ComputerYouthTeamsDataService::execute(
			$this->_websoccer,
			$this->_db,
			$this->_i18n
		);
	}
}

?>