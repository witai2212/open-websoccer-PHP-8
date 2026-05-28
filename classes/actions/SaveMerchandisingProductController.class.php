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
 * Saves team-specific merchandising product settings.
 *
 * A manager can:
 * - enable or disable a product
 * - choose a predefined price factor
 */
class SaveMerchandisingProductController implements IActionController {

	private $_i18n;
	private $_websoccer;
	private $_db;

	public function __construct(I18n $i18n, WebSoccer $websoccer, DbConnection $db) {
		$this->_i18n = $i18n;
		$this->_websoccer = $websoccer;
		$this->_db = $db;
	}

	/**
	 * @see IActionController::executeAction()
	 */
	public function executeAction($parameters) {

		$user = $this->_websoccer->getUser();

		$teamId = $user->getClubId($this->_websoccer, $this->_db);
		if ($teamId < 1) {
			return null;
		}

		$dbPrefix = $this->_websoccer->getConfig("db_prefix");

		$productId = (int) $parameters["productid"];
		$enabled = !empty($parameters["enabled"]) ? "1" : "0";
		$priceFactor = isset($parameters["price_factor"])
			? trim((string) $parameters["price_factor"])
			: "1.00";

		/*
		 * Only allow the factors which are offered in the merchandising.twig.
		 * This prevents manipulated requests such as 0.01 or 99.99.
		 */
		$allowedPriceFactors = array("0.90", "1.00", "1.10");

		if (!in_array($priceFactor, $allowedPriceFactors, true)) {
			throw new Exception("Ungültiger Preisfaktor.");
		}

		/*
		 * Check whether the product exists and is active.
		 */
		$result = $this->_db->querySelect(
			"id",
			$dbPrefix . "_merchandising_product",
			"id = %d AND active = '1'",
			$productId
		);

		$product = $result->fetch_array();
		$result->free();

		if (!$product) {
			throw new Exception("Das gewählte Merchandising-Produkt ist nicht verfügbar.");
		}

		/*
		 * Check whether this team already has a settings row for this product.
		 */
		$result = $this->_db->querySelect(
			"team_id, product_id",
			$dbPrefix . "_merchandising_team_product",
			"team_id = %d AND product_id = %d",
			array($teamId, $productId)
		);

		$existingSetting = $result->fetch_array();
		$result->free();

		$columns = array(
			"enabled" => $enabled,
			"price_factor" => $priceFactor
		);

		if ($existingSetting) {

			/*
			 * Existing setting: update it.
			 */
			$this->_db->queryUpdate(
				$columns,
				$dbPrefix . "_merchandising_team_product",
				"team_id = %d AND product_id = %d",
				array($teamId, $productId)
			);

		} else {

			/*
			 * No row yet: create team setting.
			 */
			$columns["team_id"] = $teamId;
			$columns["product_id"] = $productId;

			$this->_db->queryInsert(
				$columns,
				$dbPrefix . "_merchandising_team_product"
			);
		}

		/*
		 * Success message.
		 */
		$this->_websoccer->addFrontMessage(new FrontMessage(
			MESSAGE_TYPE_SUCCESS,
			$this->_i18n->getMessage("saved_message_title"),
			""
		));

		return null;
	}

}

?>