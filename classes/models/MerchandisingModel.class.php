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
 * Provides merchandising overview, team product settings and sales statistics.
 */
class MerchandisingModel implements IModel {

	private $_db;
	private $_i18n;
	private $_websoccer;

	public function __construct($db, $i18n, $websoccer) {
		$this->_db = $db;
		$this->_i18n = $i18n;
		$this->_websoccer = $websoccer;
	}

	/**
	 * @see IModel::renderView()
	 */
	public function renderView() {
		return TRUE;
	}

	/**
	 * @see IModel::getTemplateParameters()
	 */
	public function getTemplateParameters() {

		$teamId = $this->_websoccer->getUser()->getClubId($this->_websoccer, $this->_db);
		if ($teamId < 1) {
			throw new Exception($this->_i18n->getMessage("feature_requires_team"));
		}

		$dbPrefix = $this->_websoccer->getConfig("db_prefix");

		$products = $this->_getProducts($dbPrefix, $teamId);
		$salesSummary = $this->_getSalesSummary($dbPrefix, $teamId);
		$productStatistics = $this->_getProductStatistics($dbPrefix, $teamId);
		$recentSales = $this->_getRecentSales($dbPrefix, $teamId);

		return array(
			"teamId" => $teamId,
			"products" => $products,
			"salesSummary" => $salesSummary,
			"productStatistics" => $productStatistics,
			"recentSales" => $recentSales,
			"priceFactorOptions" => array("0.90", "1.00", "1.10")
		);
	}

	/**
	 * Returns all active merchandising products including
	 * the team-specific enabled/price settings.
	 *
	 * @param string $dbPrefix
	 * @param int $teamId
	 * @return array
	 */
	private function _getProducts($dbPrefix, $teamId) {

		$columns = array(
			"P.id" => "product_id",
			"P.name" => "name",
			"P.description" => "description",
			"P.purchase_price" => "purchase_price",
			"P.sales_price" => "sales_price",
			"P.base_demand" => "base_demand",
			"P.active" => "product_active",
			"TP.enabled" => "team_enabled",
			"TP.price_factor" => "price_factor"
		);

		$fromTable = $dbPrefix . "_merchandising_product AS P";
		$fromTable .= " LEFT JOIN " . $dbPrefix . "_merchandising_team_product AS TP";
		$fromTable .= " ON TP.product_id = P.id AND TP.team_id = " . (int) $teamId;

		$result = $this->_db->querySelect(
			$columns,
			$fromTable,
			"P.active = '1' ORDER BY P.name ASC"
		);

		$products = array();

		while ($product = $result->fetch_array()) {

			// Translate product name if stored as i18n key
			if ($this->_i18n->hasMessage($product["name"])) {
				$product["name"] = $this->_i18n->getMessage($product["name"]);
			}

			// Translate description if stored as i18n key
			if (!empty($product["description"]) && $this->_i18n->hasMessage($product["description"])) {
				$product["description"] = $this->_i18n->getMessage($product["description"]);
			}

			// Default team setting:
			// if there is no row yet, product is enabled with normal price factor.
			if ($product["team_enabled"] === NULL) {
				$product["enabled"] = "1";
			} else {
				$product["enabled"] = $product["team_enabled"];
			}

			if ($product["price_factor"] === NULL || (float) $product["price_factor"] <= 0) {
				$product["price_factor"] = "1.00";
			}

			$product["purchase_price"] = (int) $product["purchase_price"];
			$product["sales_price"] = (int) $product["sales_price"];
			$product["base_demand"] = (float) $product["base_demand"];

			$product["effective_sales_price"] = (int) round(
				$product["sales_price"] * (float) $product["price_factor"]
			);

			$product["profit_per_item"] =
				$product["effective_sales_price"] - $product["purchase_price"];

			$product["base_demand_percent"] = round(
				$product["base_demand"] * 100,
				2
			);

			$products[] = $product;
		}

		$result->free();

		return $products;
	}

	/**
	 * Returns overall merchandising totals for current club.
	 *
	 * @param string $dbPrefix
	 * @param int $teamId
	 * @return array
	 */
	private function _getSalesSummary($dbPrefix, $teamId) {

		$columns = array(
			"COALESCE(SUM(units_sold), 0)" => "units_sold",
			"COALESCE(SUM(revenue), 0)" => "revenue",
			"COALESCE(SUM(costs), 0)" => "costs",
			"COALESCE(SUM(profit), 0)" => "profit",
			"COUNT(DISTINCT match_id)" => "matches_with_sales"
		);

		$result = $this->_db->querySelect(
			$columns,
			$dbPrefix . "_merchandising_sales",
			"team_id = %d",
			$teamId
		);

		$summary = $result->fetch_array();
		$result->free();

		if (!$summary) {
			$summary = array(
				"units_sold" => 0,
				"revenue" => 0,
				"costs" => 0,
				"profit" => 0,
				"matches_with_sales" => 0
			);
		}

		$summary["units_sold"] = (int) $summary["units_sold"];
		$summary["revenue"] = (int) $summary["revenue"];
		$summary["costs"] = (int) $summary["costs"];
		$summary["profit"] = (int) $summary["profit"];
		$summary["matches_with_sales"] = (int) $summary["matches_with_sales"];

		if ($summary["matches_with_sales"] > 0) {
			$summary["avg_profit_per_match"] = (int) round(
				$summary["profit"] / $summary["matches_with_sales"]
			);
		} else {
			$summary["avg_profit_per_match"] = 0;
		}

		return $summary;
	}

	/**
	 * Returns aggregated sales statistics by product.
	 *
	 * @param string $dbPrefix
	 * @param int $teamId
	 * @return array
	 */
	private function _getProductStatistics($dbPrefix, $teamId) {

		$columns = array(
			"S.product_id" => "product_id",
			"P.name" => "product_name",
			"COALESCE(SUM(S.units_sold), 0)" => "units_sold",
			"COALESCE(SUM(S.revenue), 0)" => "revenue",
			"COALESCE(SUM(S.costs), 0)" => "costs",
			"COALESCE(SUM(S.profit), 0)" => "profit",
			"COUNT(DISTINCT S.match_id)" => "matches_with_sales"
		);

		$fromTable = $dbPrefix . "_merchandising_sales AS S";
		$fromTable .= " INNER JOIN " . $dbPrefix . "_merchandising_product AS P";
		$fromTable .= " ON P.id = S.product_id";

		$result = $this->_db->querySelect(
			$columns,
			$fromTable,
			"S.team_id = %d
			 GROUP BY S.product_id, P.name
			 ORDER BY profit DESC, revenue DESC, units_sold DESC",
			$teamId
		);

		$statistics = array();

		while ($row = $result->fetch_array()) {

			if ($this->_i18n->hasMessage($row["product_name"])) {
				$row["product_name"] = $this->_i18n->getMessage($row["product_name"]);
			}

			$row["product_id"] = (int) $row["product_id"];
			$row["units_sold"] = (int) $row["units_sold"];
			$row["revenue"] = (int) $row["revenue"];
			$row["costs"] = (int) $row["costs"];
			$row["profit"] = (int) $row["profit"];
			$row["matches_with_sales"] = (int) $row["matches_with_sales"];

			$statistics[] = $row;
		}

		$result->free();

		return $statistics;
	}

	/**
	 * Returns the latest merchandising sales rows.
	 *
	 * @param string $dbPrefix
	 * @param int $teamId
	 * @return array
	 */
	private function _getRecentSales($dbPrefix, $teamId) {

		$columns = array(
			"S.id" => "sale_id",
			"S.match_id" => "match_id",
			"S.product_id" => "product_id",
			"P.name" => "product_name",
			"S.units_sold" => "units_sold",
			"S.revenue" => "revenue",
			"S.costs" => "costs",
			"S.profit" => "profit",
			"S.created_date" => "created_date"
		);

		$fromTable = $dbPrefix . "_merchandising_sales AS S";
		$fromTable .= " INNER JOIN " . $dbPrefix . "_merchandising_product AS P";
		$fromTable .= " ON P.id = S.product_id";

		$result = $this->_db->querySelect(
			$columns,
			$fromTable,
			"S.team_id = %d
			 ORDER BY S.created_date DESC, S.id DESC
			 LIMIT 20",
			$teamId
		);

		$sales = array();

		while ($row = $result->fetch_array()) {

			if ($this->_i18n->hasMessage($row["product_name"])) {
				$row["product_name"] = $this->_i18n->getMessage($row["product_name"]);
			}

			$row["sale_id"] = (int) $row["sale_id"];
			$row["match_id"] = (int) $row["match_id"];
			$row["product_id"] = (int) $row["product_id"];
			$row["units_sold"] = (int) $row["units_sold"];
			$row["revenue"] = (int) $row["revenue"];
			$row["costs"] = (int) $row["costs"];
			$row["profit"] = (int) $row["profit"];
			$row["created_date"] = (int) $row["created_date"];

			$sales[] = $row;
		}

		$result->free();

		return $sales;
	}

}

?>