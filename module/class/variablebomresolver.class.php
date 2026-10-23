<?php
/* Copyright (C) 2026 Zach Melo <zach@digitalproperties.works>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    class/variablebomresolver.class.php
 * \ingroup variablebom
 * \brief   Resolve the lines of a variable BOM for a variant to produce
 */

require_once DOL_DOCUMENT_ROOT.'/bom/class/bom.class.php';
dol_include_once('/variablebom/class/variablebomrule.class.php');


/**
 * A variable BOM is a BOM whose product to produce is a parent product with variants.
 * Its lines are resolved for each variant to produce:
 * - a line flagged "matched to variant" uses a variant parent product: the variant consumed is the one having,
 *   for each of its attributes, the fixed value defined on the line or else the value of the variant to produce.
 * - the quantity of a line is replaced by the qty rule matching a value of the variant to produce, if any.
 */
class VariableBomResolver
{
	/**
	 * Name of the extrafield of BOM lines that flags a line as matched to variant (created by modVariableBom::init())
	 */
	const EXTRAFIELD_MATCH = 'variablebom_match';

	/**
	 * Name of the extrafield of BOMs that flags a BOM as variable (created by modVariableBom::init())
	 */
	const EXTRAFIELD_VARIABLE = 'variablebom_variable';

	/**
	 * @var DoliDB Database handler
	 */
	public $db;

	/**
	 * @var string Error
	 */
	public $error = '';

	/**
	 * @var string[] Errors
	 */
	public $errors = array();

	/**
	 * @var array<int,array<int,array<int,int>>>	Variants per parent product: [fk_product_parent][fk_product_child][fk_prod_attr] = fk_prod_attr_val
	 */
	private $cache_combinations = array();

	/**
	 * @var array<int,array<int,array{id:int,label:string,values:array<int,string>}>>	Attributes and values used by the variants of a parent product
	 */
	private $cache_attributes = array();

	/**
	 * @var array<int,array<int,VariableBomRule[]>>	Rules per BOM, indexed by id of BOM line
	 */
	private $cache_rules = array();

	/**
	 * @var array<int,array<int,int>>	Ids of lines matched to variant, per BOM
	 */
	private $cache_matched = array();

	/**
	 * @var array<int,string>	Ref of products, for error messages
	 */
	private $cache_refs = array();

	/**
	 * @var array<int,bool>	Flag "Variable BOM" per BOM
	 */
	private $cache_flags = array();


	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;
	}

	/**
	 * Return if the Dolibarr core provides the hook processBOMLines used to resolve the lines of a MO.
	 *
	 * @return bool		True if the hook is available
	 */
	public static function isCoreHookAvailable()
	{
		require_once DOL_DOCUMENT_ROOT.'/mrp/class/mo.class.php';

		$reflection = new ReflectionClass('Mo');
		$content = @file_get_contents((string) $reflection->getFileName());

		return (is_string($content) && strpos($content, "'processBOMLines'") !== false);
	}

	/**
	 * Return if a BOM is flagged "Variable BOM" (extrafield of the BOM, ticked by the user).
	 *
	 * @param	BOM		$bom	BOM
	 * @return	bool			True if flagged
	 */
	public function isFlagged(BOM $bom)
	{
		if (empty($bom->id)) {
			return !empty($bom->array_options['options_'.self::EXTRAFIELD_VARIABLE]);
		}
		if (!isset($this->cache_flags[$bom->id])) {
			if (isset($bom->array_options['options_'.self::EXTRAFIELD_VARIABLE])) {
				$this->cache_flags[$bom->id] = !empty($bom->array_options['options_'.self::EXTRAFIELD_VARIABLE]);
			} else {
				$obj = $this->db->getRow("SELECT ".self::EXTRAFIELD_VARIABLE." as flag FROM ".$this->db->prefix()."bom_bom_extrafields WHERE fk_object = ".((int) $bom->id));
				$this->cache_flags[$bom->id] = (is_object($obj) && !empty($obj->flag));
			}
		}

		return $this->cache_flags[$bom->id];
	}

	/**
	 * Return if a BOM is a variable BOM: flagged "Variable BOM" and its product to produce has variants.
	 *
	 * @param	BOM		$bom	BOM
	 * @return	bool			True if variable BOM
	 */
	public function isVariable(BOM $bom)
	{
		if (!isModEnabled('variants') || empty($bom->fk_product) || !$this->isFlagged($bom)) {
			return false;
		}

		return count($this->getVariantCombinations($bom->fk_product)) > 0;
	}

	/**
	 * Return the variants of a parent product with their attribute values.
	 *
	 * @param	int		$fk_product_parent	Id of parent product
	 * @return	array<int,array<int,int>>	Array [fk_product_child][fk_prod_attr] = fk_prod_attr_val
	 */
	public function getVariantCombinations($fk_product_parent)
	{
		$fk_product_parent = (int) $fk_product_parent;

		if (!isset($this->cache_combinations[$fk_product_parent])) {
			$combinations = array();

			$sql = "SELECT c.fk_product_child, c2v.fk_prod_attr, c2v.fk_prod_attr_val";
			$sql .= " FROM ".$this->db->prefix()."product_attribute_combination as c";
			$sql .= " INNER JOIN ".$this->db->prefix()."product_attribute_combination2val as c2v ON c2v.fk_prod_combination = c.rowid";
			$sql .= " WHERE c.fk_product_parent = ".((int) $fk_product_parent);
			$sql .= " AND c.entity IN (".getEntity('product').")";

			$resql = $this->db->query($sql);
			if ($resql) {
				while ($obj = $this->db->fetch_object($resql)) {
					$combinations[(int) $obj->fk_product_child][(int) $obj->fk_prod_attr] = (int) $obj->fk_prod_attr_val;
				}
				$this->db->free($resql);
			} else {
				dol_syslog(__METHOD__.' '.$this->db->lasterror(), LOG_ERR);
			}

			$this->cache_combinations[$fk_product_parent] = $combinations;
		}

		return $this->cache_combinations[$fk_product_parent];
	}

	/**
	 * Return the ids of the validated BOMs that are variable BOMs (flagged, and their product to produce has variants).
	 *
	 * @return	int[]	Ids of BOMs
	 */
	public function getVariableBomIds()
	{
		$ids = array();

		$sql = "SELECT DISTINCT b.rowid";
		$sql .= " FROM ".$this->db->prefix()."bom_bom as b";
		$sql .= " INNER JOIN ".$this->db->prefix()."bom_bom_extrafields as ef ON ef.fk_object = b.rowid AND ef.".self::EXTRAFIELD_VARIABLE." = 1";
		$sql .= " INNER JOIN ".$this->db->prefix()."product_attribute_combination as c ON c.fk_product_parent = b.fk_product";
		$sql .= " WHERE b.status = ".((int) BOM::STATUS_VALIDATED);
		$sql .= " AND b.entity IN (".getEntity('bom').")";
		$sql .= " AND c.entity IN (".getEntity('product').")";

		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$ids[] = (int) $obj->rowid;
			}
			$this->db->free($resql);
		} else {
			dol_syslog(__METHOD__.' '.$this->db->lasterror(), LOG_ERR);
		}

		return $ids;
	}

	/**
	 * Return the attributes, with their values, used by the variants of a parent product.
	 *
	 * @param	int		$fk_product_parent	Id of parent product
	 * @return	array<int,array{id:int,label:string,values:array<int,string>}>	Attributes indexed by id, sorted on attribute then value position
	 */
	public function getVariantAttributes($fk_product_parent)
	{
		$fk_product_parent = (int) $fk_product_parent;

		if (!isset($this->cache_attributes[$fk_product_parent])) {
			$attributes = array();

			$sql = "SELECT DISTINCT a.rowid as attrid, a.label as attrlabel, a.position as attrposition, v.rowid as valid, v.value as vallabel, v.position as valposition";
			$sql .= " FROM ".$this->db->prefix()."product_attribute_combination as c";
			$sql .= " INNER JOIN ".$this->db->prefix()."product_attribute_combination2val as c2v ON c2v.fk_prod_combination = c.rowid";
			$sql .= " INNER JOIN ".$this->db->prefix()."product_attribute as a ON a.rowid = c2v.fk_prod_attr";
			$sql .= " INNER JOIN ".$this->db->prefix()."product_attribute_value as v ON v.rowid = c2v.fk_prod_attr_val";
			$sql .= " WHERE c.fk_product_parent = ".((int) $fk_product_parent);
			$sql .= " AND c.entity IN (".getEntity('product').")";
			$sql .= $this->db->order('a.position,a.rowid,v.position,v.rowid', 'ASC,ASC,ASC,ASC');

			$resql = $this->db->query($sql);
			if ($resql) {
				while ($obj = $this->db->fetch_object($resql)) {
					if (!isset($attributes[(int) $obj->attrid])) {
						$attributes[(int) $obj->attrid] = array('id' => (int) $obj->attrid, 'label' => (string) $obj->attrlabel, 'values' => array());
					}
					$attributes[(int) $obj->attrid]['values'][(int) $obj->valid] = (string) $obj->vallabel;
				}
				$this->db->free($resql);
			} else {
				dol_syslog(__METHOD__.' '.$this->db->lasterror(), LOG_ERR);
			}

			$this->cache_attributes[$fk_product_parent] = $attributes;
		}

		return $this->cache_attributes[$fk_product_parent];
	}

	/**
	 * Return the ids of the lines of a BOM flagged "matched to variant".
	 *
	 * @param	BOM		$bom	BOM
	 * @return	array<int,int>	Ids of lines
	 */
	public function getMatchedLineIds(BOM $bom)
	{
		if (!isset($this->cache_matched[$bom->id])) {
			$this->cache_matched[$bom->id] = array();

			$sql = "SELECT ef.fk_object";
			$sql .= " FROM ".$this->db->prefix()."bom_bomline_extrafields as ef";
			$sql .= " INNER JOIN ".$this->db->prefix()."bom_bomline as l ON l.rowid = ef.fk_object";
			$sql .= " WHERE l.fk_bom = ".((int) $bom->id);
			$sql .= " AND ef.".self::EXTRAFIELD_MATCH." = 1";

			$resql = $this->db->query($sql);
			if ($resql) {
				while ($obj = $this->db->fetch_object($resql)) {
					$this->cache_matched[$bom->id][(int) $obj->fk_object] = (int) $obj->fk_object;
				}
				$this->db->free($resql);
			} else {
				dol_syslog(__METHOD__.' '.$this->db->lasterror(), LOG_WARNING);
			}
		}

		return $this->cache_matched[$bom->id];
	}

	/**
	 * Return the rules of a line of a BOM.
	 *
	 * @param	BOM		$bom		BOM
	 * @param	int		$lineid		Id of BOM line
	 * @param	bool	$usecache	Use rules already loaded for this BOM
	 * @return	VariableBomRule[]	Rules of the line
	 */
	public function getRulesOfLine(BOM $bom, $lineid, $usecache = true)
	{
		if (!$usecache || !isset($this->cache_rules[$bom->id])) {
			$staticrule = new VariableBomRule($this->db);
			$rulesbyline = $staticrule->fetchAllByBom($bom->id);
			$this->cache_rules[$bom->id] = (is_array($rulesbyline) ? $rulesbyline : array());
		}

		return (empty($this->cache_rules[$bom->id][$lineid]) ? array() : $this->cache_rules[$bom->id][$lineid]);
	}

	/**
	 * Check that a product can be used into a line matched to variant of a BOM.
	 *
	 * @param	BOM		$bom			BOM
	 * @param	int		$fk_product		Id of product of the line
	 * @return	int<-1,1>				Return integer <0 if KO (error in ->error), >0 if OK
	 */
	public function checkVariantMatch(BOM $bom, $fk_product)
	{
		global $langs;

		$langs->load('variablebom@variablebom');

		if (!$this->isVariable($bom)) {
			$this->error = $langs->trans('ErrorVariableBomMatchNeedsVariableBom');
			return -1;
		}
		if (count($this->getVariantCombinations($fk_product)) == 0) {
			$this->error = $langs->trans('ErrorVariableBomMatchNeedsVariantParent');
			return -1;
		}

		return 1;
	}

	/**
	 * Return the lines of a BOM with product and quantity resolved for a variant to produce.
	 * A line with a resolved quantity of 0 is not used for this variant and is not returned.
	 * For a BOM that is not variable, the lines are returned unchanged.
	 *
	 * @param	BOM		$bom					BOM (with its lines loaded)
	 * @param	int		$fk_product_variant		Id of variant to produce (child of the product of the BOM)
	 * @param	int		$onlylineid				Resolve only this line
	 * @return	BOMLine[]|int<-1,-1>			Array of resolved lines (copies of $bom->lines), <0 if KO (errors in ->errors)
	 */
	public function getLinesForVariant(BOM $bom, $fk_product_variant, $onlylineid = 0)
	{
		global $langs;

		$this->error = '';
		$this->errors = array();

		if (!$this->isVariable($bom)) {
			return $bom->lines;
		}

		$langs->load('variablebom@variablebom');

		$producedvariants = $this->getVariantCombinations($bom->fk_product);
		if (empty($producedvariants[(int) $fk_product_variant])) {
			$this->error = $langs->trans('ErrorVariableBomVariantToProduceRequired', $this->getProductRef($bom->fk_product));
			$this->errors[] = $this->error;
			return -1;
		}
		$producedvalues = $producedvariants[(int) $fk_product_variant];
		$matchedlineids = $this->getMatchedLineIds($bom);

		$resolvedlines = array();
		foreach ($bom->lines as $line) {
			if ($onlylineid > 0 && $line->id != $onlylineid) {
				continue;
			}

			$newline = clone $line;

			$fixedvalues = array();
			foreach ($this->getRulesOfLine($bom, $line->id) as $rule) {
				if ($rule->rule_type == VariableBomRule::RULE_QTY) {
					if (isset($producedvalues[$rule->fk_prod_attr]) && $producedvalues[$rule->fk_prod_attr] == $rule->fk_prod_attr_val) {
						$newline->qty = (float) $rule->qty;
					}
				} elseif ($rule->rule_type == VariableBomRule::RULE_FIXEDVALUE) {
					$fixedvalues[(int) $rule->fk_prod_attr] = (int) $rule->fk_prod_attr_val;
				}
			}

			if (empty($newline->qty)) {
				continue;	// Line not used for this variant
			}

			if (!empty($matchedlineids[$line->id])) {
				$componentvariants = $this->getVariantCombinations($line->fk_product);

				// Attributes of the component, with their wanted value
				$wantedvalues = array();
				$missingattributes = array();
				foreach ($componentvariants as $values) {
					foreach (array_keys($values) as $attrid) {
						if (isset($fixedvalues[$attrid])) {
							$wantedvalues[$attrid] = $fixedvalues[$attrid];
						} elseif (isset($producedvalues[$attrid])) {
							$wantedvalues[$attrid] = $producedvalues[$attrid];
						} else {
							$missingattributes[$attrid] = $attrid;
						}
					}
				}

				if (!empty($missingattributes)) {
					$attributes = $this->getVariantAttributes($line->fk_product);
					$labels = array();
					foreach ($missingattributes as $attrid) {
						$labels[] = (empty($attributes[$attrid]) ? $attrid : $attributes[$attrid]['label']);
					}
					$this->errors[] = $langs->trans('ErrorVariableBomAttributeNotDefined', $this->getProductRef($line->fk_product), implode(', ', $labels));
					continue;
				}

				$fk_product_child = 0;
				foreach ($componentvariants as $childid => $values) {
					if ($values == $wantedvalues) {
						$fk_product_child = $childid;
						break;
					}
				}

				if (empty($fk_product_child)) {
					$attributes = $this->getVariantAttributes($line->fk_product);
					$labels = array();
					foreach ($wantedvalues as $attrid => $valid) {
						$labels[] = (empty($attributes[$attrid]) ? $attrid : $attributes[$attrid]['label']).'='.(empty($attributes[$attrid]['values'][$valid]) ? $valid : $attributes[$attrid]['values'][$valid]);
					}
					$this->errors[] = $langs->trans('ErrorVariableBomNoVariantFound', $this->getProductRef($line->fk_product), implode(', ', $labels));
					continue;
				}

				$newline->fk_product = $fk_product_child;
			}

			$resolvedlines[] = $newline;
		}

		if (!empty($this->errors)) {
			$this->error = $this->errors[0];
			return -1;
		}

		return $resolvedlines;
	}

	/**
	 * Return the ref of a product, for error messages.
	 *
	 * @param	int		$fk_product		Id of product
	 * @return	string					Ref of product
	 */
	private function getProductRef($fk_product)
	{
		if (!isset($this->cache_refs[$fk_product])) {
			$obj = $this->db->getRow("SELECT ref FROM ".$this->db->prefix()."product WHERE rowid = ".((int) $fk_product));
			$this->cache_refs[$fk_product] = (is_object($obj) ? (string) $obj->ref : (string) $fk_product);
		}

		return $this->cache_refs[$fk_product];
	}
}
