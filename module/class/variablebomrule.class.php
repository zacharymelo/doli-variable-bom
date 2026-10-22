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
 * \file    class/variablebomrule.class.php
 * \ingroup variablebom
 * \brief   CRUD class for the variant rules of a BOM line
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';


/**
 * Variant rule of a BOM line of a variable BOM.
 *
 * - RULE_FIXEDVALUE: the attribute fk_prod_attr of the component is always fk_prod_attr_val, whatever the variant to produce.
 * - RULE_QTY: when the variant to produce has the value fk_prod_attr_val for attribute fk_prod_attr, qty replaces the
 *   quantity of the line (0 = the line is not used for this variant).
 */
class VariableBomRule extends CommonObject
{
	/**
	 * @var string Module of the object
	 */
	public $module = 'variablebom';

	/**
	 * @var string ID to identify managed object
	 */
	public $element = 'variablebomrule';

	/**
	 * @var string Name of table without prefix where object is stored
	 */
	public $table_element = 'variablebom_rule';

	const RULE_FIXEDVALUE = 'fixedvalue';
	const RULE_QTY = 'qty';

	/**
	 * @var array<string,array{type:string,label:string,enabled:int<0,2>|string,position:int,notnull?:int,visible:int<-6,6>|string,default?:string,index?:int}>  Array with all fields and their property.
	 */
	public $fields = array(
		'rowid' => array('type' => 'integer', 'label' => 'TechnicalID', 'enabled' => 1, 'visible' => -1, 'position' => 1, 'notnull' => 1, 'index' => 1),
		'entity' => array('type' => 'integer', 'label' => 'Entity', 'enabled' => 1, 'visible' => 0, 'position' => 5, 'notnull' => 1, 'default' => '1', 'index' => 1),
		'fk_bom' => array('type' => 'integer:BOM:bom/class/bom.class.php', 'label' => 'BillOfMaterials', 'enabled' => 1, 'visible' => 0, 'position' => 10, 'notnull' => 1, 'index' => 1),
		'fk_bomline' => array('type' => 'integer:BOMLine:bom/class/bomline.class.php', 'label' => 'BOMLine', 'enabled' => 1, 'visible' => 0, 'position' => 20, 'notnull' => 1, 'index' => 1),
		'rule_type' => array('type' => 'varchar(16)', 'label' => 'Type', 'enabled' => 1, 'visible' => 0, 'position' => 30, 'notnull' => 1),
		'fk_prod_attr' => array('type' => 'integer:ProductAttribute:variants/class/ProductAttribute.class.php', 'label' => 'ProductAttribute', 'enabled' => 1, 'visible' => 0, 'position' => 40, 'notnull' => 1),
		'fk_prod_attr_val' => array('type' => 'integer:ProductAttributeValue:variants/class/ProductAttributeValue.class.php', 'label' => 'Value', 'enabled' => 1, 'visible' => 0, 'position' => 50, 'notnull' => 1),
		'qty' => array('type' => 'double(24,8)', 'label' => 'Quantity', 'enabled' => 1, 'visible' => 0, 'position' => 60, 'notnull' => 0),
		'import_key' => array('type' => 'varchar(14)', 'label' => 'ImportId', 'enabled' => 1, 'visible' => -2, 'position' => 1000, 'notnull' => -1),
	);

	/**
	 * @var int Entity
	 */
	public $entity;

	/**
	 * @var int Id of BOM
	 */
	public $fk_bom;

	/**
	 * @var int Id of BOM line
	 */
	public $fk_bomline;

	/**
	 * @var string Type of rule (self::RULE_FIXEDVALUE or self::RULE_QTY)
	 */
	public $rule_type;

	/**
	 * @var int Id of product attribute
	 */
	public $fk_prod_attr;

	/**
	 * @var int Id of product attribute value
	 */
	public $fk_prod_attr_val;

	/**
	 * @var ?float Quantity (for RULE_QTY only)
	 */
	public $qty;

	/**
	 * @var string Import key
	 */
	public $import_key;


	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;

		$this->ismultientitymanaged = 1;
		$this->isextrafieldmanaged = 0;
	}

	/**
	 * Create object into database
	 *
	 * @param  User 	$user      User that creates
	 * @param  int<0,1>	$notrigger 0=launch triggers after, 1=disable triggers
	 * @return int             	Return integer <0 if KO, Id of created object if OK
	 */
	public function create(User $user, $notrigger = 1)
	{
		global $conf;

		if (empty($this->entity)) {
			$this->entity = $conf->entity;
		}

		return $this->createCommon($user, $notrigger);
	}

	/**
	 * Delete object in database
	 *
	 * @param User 		$user       User that deletes
	 * @param int<0,1>	$notrigger  0=launch triggers after, 1=disable triggers
	 * @return int             		Return integer <0 if KO, >0 if OK
	 */
	public function delete(User $user, $notrigger = 1)
	{
		return $this->deleteCommon($user, $notrigger);
	}

	/**
	 * Load all rules of a BOM, grouped by line.
	 *
	 * @param	int		$fk_bom		Id of BOM
	 * @return	array<int,VariableBomRule[]>|int<-1,-1>		Array of rules indexed by id of BOM line, <0 if KO
	 */
	public function fetchAllByBom($fk_bom)
	{
		return $this->fetchAllByField('fk_bom', $fk_bom);
	}

	/**
	 * Load all rules of a BOM line.
	 *
	 * @param	int		$fk_bomline		Id of BOM line
	 * @return	VariableBomRule[]|int<-1,-1>	Array of rules, <0 if KO
	 */
	public function fetchAllByLine($fk_bomline)
	{
		$result = $this->fetchAllByField('fk_bomline', $fk_bomline);
		if (!is_array($result)) {
			return $result;
		}

		return (empty($result[$fk_bomline]) ? array() : $result[$fk_bomline]);
	}

	/**
	 * Load rules filtered on a field, grouped by line.
	 *
	 * @param	string	$field		'fk_bom' or 'fk_bomline'
	 * @param	int		$value		Id to filter on
	 * @return	array<int,VariableBomRule[]>|int<-1,-1>		Array of rules indexed by id of BOM line, <0 if KO
	 */
	private function fetchAllByField($field, $value)
	{
		$sql = "SELECT ".$this->getFieldList('t');
		$sql .= " FROM ".$this->db->prefix().$this->table_element." as t";
		$sql .= " WHERE t.".$this->db->sanitize($field)." = ".((int) $value);
		$sql .= " AND t.entity IN (".getEntity('bom').")";
		$sql .= $this->db->order('t.rowid', 'ASC');

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			$this->errors[] = $this->error;
			return -1;
		}

		$records = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$record = new self($this->db);
			$record->setVarsFromFetchObj($obj);
			$records[(int) $record->fk_bomline][] = $record;
		}
		$this->db->free($resql);

		return $records;
	}

	/**
	 * Delete all rules of a BOM line or of a BOM.
	 *
	 * @param	string	$field		'fk_bom' or 'fk_bomline'
	 * @param	int		$value		Id of BOM or of BOM line
	 * @return	int<-1,1>			Return integer <0 if KO, >0 if OK
	 */
	public function deleteAllBy($field, $value)
	{
		if (!in_array($field, array('fk_bom', 'fk_bomline'))) {
			return -1;
		}

		$sql = "DELETE FROM ".$this->db->prefix().$this->table_element;
		$sql .= " WHERE ".$field." = ".((int) $value);

		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			$this->errors[] = $this->error;
			return -1;
		}

		return 1;
	}
}
