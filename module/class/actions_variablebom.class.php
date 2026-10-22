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
 * \file    class/actions_variablebom.class.php
 * \ingroup variablebom
 * \brief   Hooks of module VariableBom
 */

dol_include_once('/variablebom/class/variablebomresolver.class.php');


/**
 * Hooks of module VariableBom
 */
class ActionsVariableBom
{
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
	 * @var array<string,mixed> Data returned to the caller of the hook
	 */
	public $results = array();

	/**
	 * @var string HTML returned to the caller of the hook
	 */
	public $resprints = '';


	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Hook of Mo::processBOM() (context modao): replace the lines of a variable BOM by the lines resolved
	 * for the product to produce.
	 *
	 * @param	array<string,mixed>	$parameters		Hook parameters: role, bom, quantity, fk_product
	 * @param	Mo					$object			MO
	 * @param	string				$action			Action
	 * @param	HookManager			$hookmanager	Hook manager
	 * @return	int									0 = not a variable BOM, 1 = lines replaced (into ->results['lines']), <0 if KO
	 */
	public function processBOMLines($parameters, &$object, &$action, $hookmanager)
	{
		if (!isModEnabled('variablebom') || empty($parameters['bom']) || !($parameters['bom'] instanceof BOM)) {
			return 0;
		}

		$resolver = new VariableBomResolver($this->db);
		if (!$resolver->isVariable($parameters['bom'])) {
			return 0;
		}

		$lines = $resolver->getLinesForVariant($parameters['bom'], (int) $parameters['fk_product']);
		if (!is_array($lines)) {
			$this->error = $resolver->error;
			$this->errors = $resolver->errors;
			return -1;
		}

		$this->results = array('lines' => $lines);
		return 1;
	}

	/**
	 * Add rows into the forms of BOM and MO cards.
	 * - MO creation from a variable BOM: one select per attribute to choose the variant to produce.
	 * - BOM card of a variable BOM: number of variants, with a link to the tab of variant rules.
	 *
	 * @param	array<string,mixed>	$parameters		Hook parameters
	 * @param	CommonObject		$object			Object of the page
	 * @param	string				$action			Action
	 * @param	HookManager			$hookmanager	Hook manager
	 * @return	int									0
	 */
	public function formObjectOptions($parameters, &$object, &$action, $hookmanager)
	{
		global $langs;

		if (!isModEnabled('variablebom') || empty($parameters['context'])) {
			return 0;
		}
		$contexts = explode(':', $parameters['context']);
		$langs->load('variablebom@variablebom');
		$resolver = new VariableBomResolver($this->db);

		if (in_array('mocard', $contexts) && $action == 'create' && GETPOSTINT('fk_bom') > 0) {
			$bom = new BOM($this->db);
			if ($bom->fetch(GETPOSTINT('fk_bom')) <= 0 || !$resolver->isVariable($bom)) {
				return 0;
			}
			$bom->fetch_product();
			$variants = $resolver->getVariantCombinations($bom->fk_product);
			$selected = (empty($variants[GETPOSTINT('fk_product')]) ? array() : $variants[GETPOSTINT('fk_product')]);

			$out = '<tr class="variablebom_variant"><td class="fieldrequired tdtop">'.$langs->trans('VariableBomVariantToProduce').'</td><td>';
			$out .= '<span class="variablebom_selector" data-url="'.dolPrintHTMLForAttributeUrl(dol_buildpath('/variablebom/ajax/resolve.php', 1)).'" data-bom="'.((int) $bom->id).'" data-help="'.dolPrintHTMLForAttribute($langs->transnoentities('VariableBomSelectVariant')).'">';
			foreach ($resolver->getVariantAttributes($bom->fk_product) as $attrid => $attribute) {
				$out .= '<select class="flat minwidth100 variablebom_attr" data-attr="'.((int) $attrid).'" title="'.dolPrintHTMLForAttribute($attribute['label']).'">';
				$out .= '<option value="0">'.dolPrintLabel($attribute['label']).'...</option>';
				foreach ($attribute['values'] as $valid => $vallabel) {
					$out .= '<option value="'.((int) $valid).'"'.((isset($selected[$attrid]) && $selected[$attrid] == $valid) ? ' selected="selected"' : '').'>'.dolPrintLabel($vallabel).'</option>';
				}
				$out .= '</select> ';
			}
			$out .= '</span>';
			$out .= '<div class="variablebom_resolved opacitymedium">'.$langs->trans('VariableBomSelectVariant').'</div>';
			$out .= '</td></tr>';

			$this->resprints = $out;
			return 0;
		}

		if (in_array('bomcard', $contexts) && $object instanceof BOM && $object->id > 0 && !in_array($action, array('create', 'edit'))) {
			if (!$resolver->isVariable($object)) {
				return 0;
			}
			$url = dol_buildpath('/variablebom/bom_variants.php', 1).'?id='.((int) $object->id);
			$this->resprints = '<tr><td>'.$langs->trans('VariableBom').'</td><td>';
			$this->resprints .= '<a href="'.dolPrintHTMLForAttributeUrl($url).'">'.$langs->trans('VariableBomNbVariants', count($resolver->getVariantCombinations($object->fk_product))).'</a>';
			$this->resprints .= '</td></tr>';
		}

		return 0;
	}
}
