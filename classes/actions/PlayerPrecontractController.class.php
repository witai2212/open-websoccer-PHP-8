<?php
class PlayerPrecontractController implements IActionController {
 private $_i18n; private $_websoccer; private $_db;
 public function __construct(I18n $i18n, WebSoccer $websoccer, DbConnection $db){$this->_i18n=$i18n;$this->_websoccer=$websoccer;$this->_db=$db;}
 public function executeAction($p){$user=$this->_websoccer->getUser();$club=$user->getClubId($this->_websoccer,$this->_db);if($club<1)throw new Exception($this->_i18n->getMessage('error_action_required_team'));
  PlayerPrecontractDataService::placeOffer($this->_websoccer,$this->_db,(int)$p['id'],$club,(int)$user->id,(int)$p['contract_salary'],(int)$p['contract_goal_bonus'],(int)$p['handmoney'],(int)$p['contract_matches'],false);
  $this->_websoccer->addFrontMessage(new FrontMessage(MESSAGE_TYPE_SUCCESS,$this->_i18n->getMessage('precontract_offer_success'),'')); return 'player';}
}
?>