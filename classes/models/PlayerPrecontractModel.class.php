<?php
class PlayerPrecontractModel implements IModel {
    private $_db;
    private $_i18n;
    private $_websoccer;

    public function __construct($db, $i18n, $websoccer) {
        $this->_db = $db;
        $this->_i18n = $i18n;
        $this->_websoccer = $websoccer;
    }

    public function renderView() {
        return true;
    }

    public function getTemplateParameters() {
        $playerId = (int) $this->_websoccer->getRequestParameter('id');
        $player = PlayersDataService::getPlayerById($this->_websoccer, $this->_db, $playerId);
        $user = $this->_websoccer->getUser();
        $teamId = $user ? (int) $user->getClubId($this->_websoccer, $this->_db) : 0;

        if (!$player || $teamId < 1 || !PlayerPrecontractDataService::isEligible($this->_websoccer, $this->_db, $playerId)) {
            throw new Exception($this->_i18n->getMessage('precontract_not_eligible'));
        }

        $accepted = PlayerPrecontractDataService::getAcceptedByPlayer($this->_websoccer, $this->_db, $playerId);
        if (isset($accepted['id'])) {
            throw new Exception($this->_i18n->getMessage('precontract_locked'));
        }

        $existingOffer = PlayerPrecontractDataService::getOfferByPlayerAndTeam(
            $this->_websoccer,
            $this->_db,
            $playerId,
            $teamId
        );

        return array(
            'player' => $player,
            'existing_offer' => $existingOffer
        );
    }
}
?>