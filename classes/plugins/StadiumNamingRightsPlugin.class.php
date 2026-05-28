<?php
/******************************************************

  This file is part of OpenWebSoccer-Sim.

******************************************************/

/**
 * Processes stadium naming-right payouts after completed home matches.
 */
class StadiumNamingRightsPlugin {

	/**
	 * @param MatchCompletedEvent $event completed match event.
	 */
	public static function handleMatchCompleted(MatchCompletedEvent $event) {
		StadiumsDataService::processNamingRightsPayout($event);
	}
}

?>
