<?php
class StartMerchandisingDevelopmentController implements IActionController {
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
        try {
            MerchandisingDataService::startDevelopment(
                $this->_websoccer,
                $this->_db,
                $teamId,
                $user->id,
                (int) $parameters['productid'],
                isset($parameters['playerid']) ? (int) $parameters['playerid'] : 0,
                isset($parameters['quality']) ? $parameters['quality'] : 'standard'
            );
        } catch (Exception $e) {
            throw new Exception($this->_i18n->hasMessage($e->getMessage()) ? $this->_i18n->getMessage($e->getMessage()) : $e->getMessage());
        }
        $this->_websoccer->addFrontMessage(new FrontMessage(MESSAGE_TYPE_SUCCESS, $this->_i18n->getMessage('merchandising_development_started'), ''));
        return 'merchandising';
    }
}
?>
