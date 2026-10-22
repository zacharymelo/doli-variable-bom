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
 * \file    core/triggers/interface_99_modVariableBom_VariableBomTrigger.class.php
 * \ingroup variablebom
 * \brief   Triggers of module VariableBom
 */

require_once DOL_DOCUMENT_ROOT.'/core/triggers/dolibarrtriggers.class.php';


/**
 * Triggers of module VariableBom
 */
class InterfaceVariableBomTrigger extends DolibarrTriggers
{
	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;

		$this->name = preg_replace('/^Interface/i', '', get_class($this));
		$this->family = "products";
		$this->description = "Triggers of module VariableBom";
		$this->version = '1.1.0';
		$this->picto = 'bom';
	}

	/**
	 * Return name of trigger file
	 *
	 * @return string Name of trigger file
	 */
	public function getName()
	{
		return 'VariableBomTrigger';
	}

	/**
	 * Return description of trigger file
	 *
	 * @return string Description of trigger file
	 */
	public function getDesc()
	{
		return 'Triggers of module VariableBom';
	}

	/**
	 * Execute the triggers of the module.
	 *
	 * @param string		$action		Event action code
	 * @param CommonObject	$object		Object
	 * @param User			$user		Object user
	 * @param Translate		$langs		Object langs
	 * @param Conf			$conf		Object conf
	 * @return int						Return integer <0 if KO, 0 if no triggered ran, >0 if OK
	 */
	public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
	{
		if (!isModEnabled('variablebom')) {
			return 0;
		}

		switch ($action) {
			case 'BOMLINE_CREATE':
			case 'BOMLINE_MODIFY':
				// A line matched to variant must use a variant parent product into a variable BOM
				if (!empty($object->array_options['options_variablebom_match'])) {
					dol_include_once('/variablebom/class/variablebomresolver.class.php');
					$bom = new BOM($this->db);
					$bom->fetch($object->fk_bom);
					$resolver = new VariableBomResolver($this->db);
					if ($resolver->checkVariantMatch($bom, $object->fk_product) < 0) {
						$this->errors[] = $resolver->error;
						return -1;
					}
					return 1;
				}
				break;

			case 'BOMLINE_DELETE':
				dol_include_once('/variablebom/class/variablebomrule.class.php');
				$rule = new VariableBomRule($this->db);
				if ($rule->deleteAllBy('fk_bomline', $object->id) < 0) {
					$this->errors[] = $rule->error;
					return -1;
				}
				return 1;

			case 'BOM_DELETE':
				dol_include_once('/variablebom/class/variablebomrule.class.php');
				$rule = new VariableBomRule($this->db);
				if ($rule->deleteAllBy('fk_bom', $object->id) < 0) {
					$this->errors[] = $rule->error;
					return -1;
				}
				return 1;

			case 'MO_CREATE':
				// Without the hook processBOMLines of the core, a MO of a variable BOM would consume the parent products
				if (!empty($object->fk_bom)) {
					dol_include_once('/variablebom/class/variablebomresolver.class.php');
					$bom = new BOM($this->db);
					$bom->fetch($object->fk_bom);
					$resolver = new VariableBomResolver($this->db);
					if ($resolver->isVariable($bom) && !VariableBomResolver::isCoreHookAvailable()) {
						$langs->load('variablebom@variablebom');
						$this->errors[] = $langs->trans('ErrorVariableBomCoreHookMissing');
						return -1;
					}
				}
				break;
		}

		return 0;
	}
}
