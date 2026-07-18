<?php
class SaveMerchandisingSettingsController implements IActionController {
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
            MerchandisingDataService::saveSettings($this->_websoccer, $this->_db, $teamId, $user->id, $parameters);
        } catch (Exception $e) {
            throw new Exception($this->_i18n->hasMessage($e->getMessage()) ? $this->_i18n->getMessage($e->getMessage()) : $e->getMessage());
        }
        $this->_websoccer->addFrontMessage(new FrontMessage(MESSAGE_TYPE_SUCCESS, $this->_i18n->getMessage('merchandising_settings_saved'), ''));
        return 'merchandising';
    }
}
?>
