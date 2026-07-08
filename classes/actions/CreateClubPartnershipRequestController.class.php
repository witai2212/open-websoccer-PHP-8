<?php
/******************************************************

  Creates a club partnership request.

******************************************************/

class CreateClubPartnershipRequestController implements IActionController {
    private $_i18n;
    private $_websoccer;
    private $_db;

    public function __construct(I18n $i18n, WebSoccer $websoccer, DbConnection $db) {
        $this->_i18n = $i18n;
        $this->_websoccer = $websoccer;
        $this->_db = $db;
    }

    public function executeAction($parameters) {
        if (!$this->_websoccer->getConfig('club_partnerships_enabled')) {
            return NULL;
        }

        $user = $this->_websoccer->getUser();
        $clubId = $user->getClubId($this->_websoccer, $this->_db);
        if ($clubId < 1) {
            throw new Exception($this->_i18n->getMessage('feature_requires_team'));
        }

        ClubPartnershipDataService::createRequest(
            $this->_websoccer,
            $this->_db,
            $this->_i18n,
            (int) $user->id,
            (int) $clubId,
            (int) $parameters['parentTeamId'],
            (int) $parameters['partnerTeamId']
        );

        $this->_websoccer->addFrontMessage(new FrontMessage(MESSAGE_TYPE_SUCCESS, $this->_i18n->getMessage('clubpartnership_request_created'), ''));
        return 'clubpartnerships';
    }
}
?>
