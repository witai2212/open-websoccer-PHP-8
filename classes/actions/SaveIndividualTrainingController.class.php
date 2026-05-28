<?php
/******************************************************

  This file is part of OpenWebSoccer-Sim.

******************************************************/

/**
 * Saves or cancels the individual training target for one player.
 */
class SaveIndividualTrainingController implements IActionController {
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
            throw new Exception($this->_i18n->getMessage('feature_requires_team'));
        }

        $playerId = isset($parameters['player_id']) ? (int) $parameters['player_id'] : 0;
        $attribute = isset($parameters['attribute']) ? $parameters['attribute'] : '';

        try {
            $result = IndividualTrainingDataService::saveIndividualTraining($this->_websoccer, $this->_db, $teamId, $playerId, $attribute);
        } catch (Exception $e) {
            throw new Exception($this->_i18n->getMessage($e->getMessage()));
        }

        if ($result === 'cancelled') {
            $messageKey = 'individual_training_cancelled';
        } elseif ($result === 'unchanged') {
            $messageKey = 'individual_training_unchanged';
        } else {
            $messageKey = 'individual_training_saved';
        }

        $this->_websoccer->addFrontMessage(new FrontMessage(
            MESSAGE_TYPE_SUCCESS,
            $this->_i18n->getMessage($messageKey),
            ''
        ));

        return 'individual-training';
    }
}
?>
