<?php
/** Entfernt einen Trainer aus dem aktiven Trainerstab. Nicht verbrauchte Einheiten verfallen. */
class DismissTrainerController implements IActionController {
    private $_i18n;
    private $_websoccer;
    private $_db;

    public function __construct(I18n $i18n, WebSoccer $websoccer, DbConnection $db) {
        $this->_i18n = $i18n;
        $this->_websoccer = $websoccer;
        $this->_db = $db;
    }

    public function executeAction($parameters) {
        $teamId = $this->_websoccer->getUser()->getClubId($this->_websoccer, $this->_db);
        if ($teamId < 1) {
            throw new Exception($this->_i18n->getMessage('feature_requires_team'));
        }
        $trainerId = (int) $parameters['id'];
        if (!TrainingDataService::isTrainerInStaff($this->_websoccer, $this->_db, $teamId, $trainerId)) {
            throw new Exception($this->_i18n->getMessage('training_dismiss_trainer_not_found'));
        }
        $trainer = TrainingDataService::getTrainerById($this->_websoccer, $this->_db, $trainerId);
        if (!isset($trainer['id'])) {
            throw new Exception($this->_i18n->getMessage('training_dismiss_trainer_not_found'));
        }
        $compensation = max(0, ((int) $trainer['salary']) * 2);
        $team = TeamsDataService::getTeamSummaryById($this->_websoccer, $this->_db, $teamId);
        if ((int) $team['team_budget'] < $compensation) {
            throw new Exception($this->_i18n->getMessage('training_dismiss_trainer_too_expensive'));
        }
        if ($compensation > 0) {
            BankAccountDataService::debitAmount(
                $this->_websoccer,
                $this->_db,
                $teamId,
                $compensation,
                'training_trainer_dismissal_subject',
                $trainer['name']
            );
        }
        $units = TrainingDataService::dismissTrainer($this->_websoccer, $this->_db, $teamId, $trainerId);
        $this->_websoccer->addFrontMessage(new FrontMessage(
            MESSAGE_TYPE_SUCCESS,
            $this->_i18n->getMessage('training_dismiss_trainer_success', $units, $compensation),
            ''
        ));
        return 'training';
    }
}
?>
