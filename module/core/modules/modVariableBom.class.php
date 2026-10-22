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
 * \file    core/modules/modVariableBom.class.php
 * \ingroup variablebom
 * \brief   Description and activation file for module VariableBom
 */
include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

/**
 * Description and activation class for module VariableBom (one BOM for all variants of a product)
 */
class modVariableBom extends DolibarrModules
{
	/**
	 * Name of the extrafield of BOM lines that flags a line as matched to variant
	 */
	const EXTRAFIELD_MATCH = 'variablebom_match';

	/**
	 * Constructor. Define names, constants, directories, boxes, permissions
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		global $langs, $conf;

		$this->db = $db;

		// Reserved range 194281-194309 (Zach Melo - zachmelo.com)
		$this->numero = 194281;

		$this->family = "products";
		$this->module_position = '90';

		$this->name = preg_replace('/^mod/i', '', get_class($this));
		$this->description = "Variable BOM: one BOM for all the variants of a product, resolved for each variant to produce";
		$this->editor_name = 'Zach Melo';
		$this->editor_url = 'https://zachmelo.com';
		$this->version = '1.2.0';
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
		$this->picto = 'bom';

		$this->module_parts = array(
			'triggers' => 1,
			'login' => 0,
			'substitutions' => 0,
			'menus' => 0,
			'js' => array('/variablebom/js/variablebom.js'),
			// modao: hook processBOMLines of Mo::processBOM(), mocard/bomcard: forms
			'hooks' => array('data' => array('modao', 'mocard', 'bomcard'), 'entity' => '0'),
		);

		$this->dirs = array("/variablebom/temp");

		$this->config_page_url = array("setup.php@variablebom");

		$this->hidden = false;
		$this->depends = array('modBom', 'modMrp', 'modVariants');
		$this->requiredby = array();
		$this->conflictwith = array();
		$this->langfiles = array("variablebom@variablebom");
		$this->phpmin = array(7, 1);
		$this->need_dolibarr_version = array(24, 0);
		$this->warnings_activation = array();
		$this->warnings_activation_ext = array();

		$this->const = array();

		// Tab "Variants" on BOM cards
		$this->tabs = array();
		$this->tabs[] = array('data' => 'bom:+variablebom:VariableBomTab:variablebom@variablebom:$user->hasRight(\'bom\', \'read\'):/variablebom/bom_variants.php?id=__ID__');

		$this->dictionaries = array();
		$this->boxes = array();
		$this->cronjobs = array();

		// No own permissions: the rules of a BOM are managed with the permissions of the BOM module
		$this->rights = array();
		$this->rights_class = 'variablebom';

		$this->menu = array();
	}

	/**
	 * Function called when module is enabled.
	 * Create the tables, and the extrafield of BOM lines used to flag a line as matched to variant.
	 *
	 * @param	string	$options	Options when enabling module ('', 'noboxes')
	 * @return	int<-1,1>			1 if OK, <=0 if KO
	 */
	public function init($options = '')
	{
		$result = $this->_load_tables('/variablebom/sql/');
		if ($result < 0) {
			return -1;
		}

		include_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';
		$extrafields = new ExtraFields($this->db);
		$result = $extrafields->addExtraField(self::EXTRAFIELD_MATCH, 'VariableBomLineMatch', 'boolean', 1000, '', 'bom_bomline', 0, 0, '', '', 1, '', '1', 'VariableBomLineMatchHelp', '', '0', 'variablebom@variablebom', 'isModEnabled("variablebom")');
		if ($result < 0) {
			$this->error = $extrafields->error;
			return -1;
		}

		$this->delete_menus();

		return $this->_init(array(), $options);
	}

	/**
	 * Function called when module is disabled.
	 * Tables and the extrafield are kept, so the rules are not lost.
	 *
	 * @param	string	$options	Options when disabling module ('', 'noboxes')
	 * @return	int<-1,1>			1 if OK, <=0 if KO
	 */
	public function remove($options = '')
	{
		return $this->_remove(array(), $options);
	}
}
