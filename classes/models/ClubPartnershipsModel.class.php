<?php
/******************************************************

  Club Partnerships 2.0 page model.

******************************************************/

class ClubPartnershipsModel implements IModel {
    private $_db;
    private $_i18n;
    private $_websoccer;

    public function __construct($db, $i18n, $websoccer) {
        $this->_db = $db;
        $this->_i18n = $i18n;
        $this->_websoccer = $websoccer;
    }

    public function renderView() {
        return TRUE;
    }

    public function getTemplateParameters() {
        $user = $this->_websoccer->getUser();
        $teamId = $user->getClubId($this->_websoccer, $this->_db);

        return array(
            'partnerships' => ClubPartnershipDataService::getPageData(
                $this->_websoccer,
                $this->_db,
                $this->_i18n,
                (int) $user->id,
                (int) $teamId
            )
        );
    }
}
?>
