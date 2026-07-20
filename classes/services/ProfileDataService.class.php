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
 * Data service for profile.
 */
class ProfileDataService {
    
    public static function fireManager(WebSoccer $websoccer, DbConnection $db, $userId, $clubId) {
        
        $sqlStr = "SELECT fanbeliebtheit, boardsatisfaction
                    FROM ". $websoccer->getConfig("db_prefix") ."_user
                    WHERE id='".$userId."'";
        $result = $db->executeQuery($sqlStr);
        $userdata = $result->fetch_array();
        $result->free();
        
		//notify user
        $notifStr = "INSERT INTO ". $websoccer->getConfig("db_prefix") ."_notification (id, user_id, eventdate, message_key, message_data, target_pageid, seen, team_id)
                    VALUES ('', '".$userId."', '".$now."', 'you_have_been_sacked', 'you_have_been_sacked', 'messages', '0', '".$clubId."')";
        $db->executeQuery($notifStr);
        
		//send message to user
        $msgStr = "INSERT INTO ". $websoccer->getConfig("db_prefix") ."_briefe 
						(id, empfaenger_id, absender_name, datum, betreff, nachricht, gelesen, typ) 
                    VALUES ('', '".$userId."', 'Management', '".$now."', '{sacking}', '{you_have_been_sacked_msg}', '0', 'eingang')";
		//echo $msgStr ."<br>"
        $db->executeQuery($msgStr);
		
		//delete user_id from club
		$updStr = "UPDATE ". $websoccer->getConfig("db_prefix") ."_verein SET user_id='' WHERE id='" .$clubId."'";
		$db->executeQuery($updStr);
          
    }
}
?>