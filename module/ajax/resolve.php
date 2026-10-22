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
 * \file    ajax/resolve.php
 * \ingroup variablebom
 * \brief   Return the variant to produce matching attribute values, with the lines of the BOM resolved for it
 */

if (!defined('NOTOKENRENEWAL')) {
	define('NOTOKENRENEWAL', '1');
}
if (!defined('NOREQUIREMENU')) {
	define('NOREQUIREMENU', '1');
}
if (!defined('NOREQUIREHTML')) {
	define('NOREQUIREHTML', '1');
}
if (!defined('NOREQUIREAJAX')) {
	define('NOREQUIREAJAX', '1');
}

$res = 0;
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}
dol_include_once('/variablebom/class/variablebomresolver.class.php');

/**
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array('mrp', 'variablebom@variablebom'));

$fk_bom = GETPOSTINT('fk_bom');
$selected = array_filter(array_map('intval', GETPOST('attr', 'array')));

if (!isModEnabled('variablebom') || !$user->hasRight('bom', 'read')) {
	accessforbidden();
}

top_httphead('application/json');

$response = array('fk_product' => 0, 'label' => '', 'lines' => array(), 'errors' => array());

$bom = new BOM($db);
$resolver = new VariableBomResolver($db);
if ($fk_bom > 0 && $bom->fetch($fk_bom) > 0 && $resolver->isVariable($bom)) {
	foreach ($resolver->getVariantCombinations($bom->fk_product) as $childid => $values) {
		if ($values == $selected) {
			$response['fk_product'] = $childid;
			break;
		}
	}

	if (empty($response['fk_product'])) {
		if (count($selected) == count($resolver->getVariantAttributes($bom->fk_product))) {
			$bom->fetch_product();
			$response['errors'][] = $langs->transnoentities('ErrorVariableBomNoVariantForValues', $bom->product->ref);
		}
	} else {
		$lines = $resolver->getLinesForVariant($bom, $response['fk_product']);
		if (!is_array($lines)) {
			$response['errors'] = $resolver->errors;
			$lines = array();
		}

		// Load in one request the products to show
		$productids = array($response['fk_product'] => $response['fk_product']);
		foreach ($lines as $line) {
			$productids[(int) $line->fk_product] = (int) $line->fk_product;
		}
		$labels = array();
		$sql = "SELECT p.rowid, p.ref, p.label FROM ".$db->prefix()."product as p";
		$sql .= " WHERE p.rowid IN (".$db->sanitize(implode(',', $productids)).")";
		$resql = $db->query($sql);
		if ($resql) {
			while ($obj = $db->fetch_object($resql)) {
				$labels[(int) $obj->rowid] = $obj->ref.' - '.$obj->label;
			}
		}

		$response['label'] = (empty($labels[$response['fk_product']]) ? '' : $labels[$response['fk_product']]);
		foreach ($lines as $line) {
			$response['lines'][] = array(
				'label' => (empty($labels[$line->fk_product]) ? (string) $line->fk_product : $labels[$line->fk_product]),
				'qty' => price($line->qty, 0, '', 0, 0),
			);
		}
	}
}

print json_encode($response);
