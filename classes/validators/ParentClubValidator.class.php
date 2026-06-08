<?php
/******************************************************

  Validates admin-defined parent club assignments.

******************************************************/

class ParentClubValidator implements IValidator {
    private $_i18n;
    private $_websoccer;
    private $_value;
    private $_messageKey = 'parentclub_validation_invalid';

    public function __construct($i18n, $websoccer, $value) {
        $this->_i18n = $i18n;
        $this->_websoccer = $websoccer;
        $this->_value = $value;
    }

    public function isValid() {
        $parentClubId = (int) $this->_value;
        if ($parentClubId <= 0) {
            return true;
        }

        $childClubId = 0;
        if (isset($_POST['id'])) {
            $childClubId = (int) $_POST['id'];
        } elseif (isset($_REQUEST['id'])) {
            $childClubId = (int) $_REQUEST['id'];
        }

        $childLeagueId = isset($_POST['liga_id']) ? (int) $_POST['liga_id'] : 0;
        $childIsNationalTeam = !empty($_POST['nationalteam']);
        $relationshipStatus = isset($_POST['parent_club_status']) ? (string) $_POST['parent_club_status'] : ParentClubDataService::STATUS_ACTIVE;

        $db = DbConnection::getInstance();
        $messageKey = null;
        $valid = ParentClubDataService::validateAssignment(
            $this->_websoccer,
            $db,
            $childClubId,
            $parentClubId,
            $childLeagueId,
            $childIsNationalTeam,
            $relationshipStatus,
            $messageKey
        );

        if (!$valid && strlen((string) $messageKey)) {
            $this->_messageKey = $messageKey;
        }

        return $valid;
    }

    public function getMessage() {
        if ($this->_i18n->hasMessage($this->_messageKey)) {
            return $this->_i18n->getMessage($this->_messageKey);
        }

        return $this->_messageKey;
    }
}
?>
