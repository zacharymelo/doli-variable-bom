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
 * \file    lib/variablebom.lib.php
 * \ingroup variablebom
 * \brief   Library of module VariableBom
 */

dol_include_once('/variablebom/class/variablebomresolver.class.php');


/**
 * Prepare array of tabs for the setup page of the module
 *
 * @return	array<array{0:string,1:string,2:string}>	Array of tabs
 */
function variablebomAdminPrepareHead()
{
	global $langs, $conf;

	$langs->load('variablebom@variablebom');

	$h = 0;
	$head = array();

	$head[$h][0] = dol_buildpath('/variablebom/admin/setup.php', 1);
	$head[$h][1] = $langs->trans('Settings');
	$head[$h][2] = 'settings';
	$h++;

	complete_head_from_modules($conf, $langs, null, $head, $h, 'variablebom@variablebom');

	return $head;
}

/**
 * Return a HTML summary of the variant rules of a line of a variable BOM.
 *
 * @param	VariableBomResolver	$resolver	Resolver (keeps caches between lines)
 * @param	BOM					$bom		Variable BOM
 * @param	BOMLine				$line		Line of BOM
 * @return	string							HTML summary
 */
function variablebomLineRulesSummary(VariableBomResolver $resolver, BOM $bom, BOMLine $line)
{
	global $langs;

	$langs->load('variablebom@variablebom');

	$out = array();
	$rules = $resolver->getRulesOfLine($bom, $line->id);
	$matched = $resolver->getMatchedLineIds($bom);

	if (!empty($matched[$line->id])) {
		$attributes = $resolver->getVariantAttributes($line->fk_product);
		foreach ($rules as $rule) {
			if ($rule->rule_type == VariableBomRule::RULE_FIXEDVALUE && !empty($attributes[$rule->fk_prod_attr]['values'][$rule->fk_prod_attr_val])) {
				$out[] = dolPrintLabel($attributes[$rule->fk_prod_attr]['label'].': '.$attributes[$rule->fk_prod_attr]['values'][$rule->fk_prod_attr_val]);
			}
		}
	}

	$attributes = $resolver->getVariantAttributes($bom->fk_product);
	$qtylabels = array();
	$qtyattrid = 0;
	foreach ($rules as $rule) {
		if ($rule->rule_type == VariableBomRule::RULE_QTY && !empty($attributes[$rule->fk_prod_attr]['values'][$rule->fk_prod_attr_val])) {
			$qtyattrid = $rule->fk_prod_attr;
			$qtylabels[] = $attributes[$rule->fk_prod_attr]['values'][$rule->fk_prod_attr_val].'='.price($rule->qty, 0, '', 0, 0);
		}
	}
	if (!empty($qtylabels)) {
		$out[] = dolPrintLabel($langs->trans('VariableBomQtySummary', $attributes[$qtyattrid]['label'], implode(', ', $qtylabels)));
	}

	return implode('<br>', $out);
}

/**
 * Return the number of variants to produce for which a line of a variable BOM can be resolved.
 *
 * @param	VariableBomResolver	$resolver	Resolver (keeps caches between lines)
 * @param	BOM					$bom		Variable BOM
 * @param	BOMLine				$line		Line of BOM
 * @return	array{0:int,1:int}				Number of variants resolved, number of variants
 */
function variablebomLineResolvedCount(VariableBomResolver $resolver, BOM $bom, BOMLine $line)
{
	$variants = $resolver->getVariantCombinations($bom->fk_product);
	$nbok = 0;
	foreach (array_keys($variants) as $fk_product_variant) {
		if (is_array($resolver->getLinesForVariant($bom, $fk_product_variant, $line->id))) {
			$nbok++;
		}
	}

	return array($nbok, count($variants));
}
