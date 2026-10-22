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
 * \file    bom_variants.php
 * \ingroup variablebom
 * \brief   Tab "Variants" of a BOM: variant rules of the lines of a variable BOM
 */

// Load Dolibarr environment
$res = 0;
if (!$res && file_exists("../main.inc.php")) {
	$res = @include "../main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}
require_once DOL_DOCUMENT_ROOT.'/bom/class/bom.class.php';
require_once DOL_DOCUMENT_ROOT.'/bom/lib/bom.lib.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
dol_include_once('/variablebom/class/variablebomresolver.class.php');
dol_include_once('/variablebom/lib/variablebom.lib.php');

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var HookManager $hookmanager
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array('mrp', 'products', 'other', 'variablebom@variablebom'));

// Get parameters
$id     = GETPOSTINT('id');
$lineid = GETPOSTINT('lineid');
$action = GETPOST('action', 'aZ09');
$cancel = GETPOST('cancel', 'aZ09');

$object = new BOM($db);
$hookmanager->initHooks(array('bomvariablebom', 'globalcard'));

if ($id <= 0 || $object->fetch($id) <= 0) {
	accessforbidden('Bad value for parameter id');
}

$isdraft = (($object->status == $object::STATUS_DRAFT) ? 1 : 0);
restrictedArea($user, 'bom', $object->id, $object->table_element, '', '', 'rowid', $isdraft);

$permissiontoadd = ($user->hasRight('bom', 'write') && $object->status == $object::STATUS_DRAFT);

$resolver = new VariableBomResolver($db);
$isvariable = $resolver->isVariable($object);

// Line edited, if any
$line = null;
foreach ($object->lines as $tmpline) {
	if ($lineid > 0 && $tmpline->id == $lineid) {
		$line = $tmpline;
	}
}
$matchedlineids = ($isvariable ? $resolver->getMatchedLineIds($object) : array());
$producedattributes = ($isvariable ? $resolver->getVariantAttributes($object->fk_product) : array());
$componentattributes = ((is_object($line) && !empty($matchedlineids[$line->id])) ? $resolver->getVariantAttributes($line->fk_product) : array());

$backtotab = $_SERVER['PHP_SELF'].'?id='.$object->id;


/*
 * Actions
 */

$parameters = array('line' => $line);
$reshook = $hookmanager->executeHooks('doActions', $parameters, $object, $action); // Note that $action and $object may have been modified by some hooks
if ($reshook < 0) {
	setEventMessages($hookmanager->error, $hookmanager->errors, 'errors');
}

if (empty($reshook)) {
	if ($cancel) {
		header('Location: '.$backtotab);
		exit;
	}

	if ($action == 'save' && $permissiontoadd && $isvariable && is_object($line)) {
		$error = 0;
		$newrules = array();

		// Fixed values of attributes of the component
		foreach ($componentattributes as $attrid => $attribute) {
			$valid = GETPOSTINT('fixedvalue_'.$attrid);
			if ($valid > 0 && isset($attribute['values'][$valid])) {
				$newrules[] = array('rule_type' => VariableBomRule::RULE_FIXEDVALUE, 'fk_prod_attr' => $attrid, 'fk_prod_attr_val' => $valid, 'qty' => null);
			}
		}

		// Quantity by value of one attribute of the product to produce
		$qtyattrid = GETPOSTINT('qty_attr');
		if ($qtyattrid > 0 && isset($producedattributes[$qtyattrid])) {
			foreach (array_keys($producedattributes[$qtyattrid]['values']) as $valid) {
				$qty = trim(GETPOST('qty_'.$qtyattrid.'_'.$valid, 'alpha'));
				if ($qty === '') {
					continue;
				}
				$isnumber = (bool) preg_match('/^[0-9\s.,]+$/', $qty);	// price2num() turns any text into 0, so check the input first
				$qty = price2num($qty, 'MS');
				if (!$isnumber || !is_numeric($qty) || $qty < 0) {
					$error++;
					setEventMessages($langs->trans('ErrorFieldFormat', $langs->transnoentitiesnoconv('Qty')), null, 'errors');
					break;
				}
				$newrules[] = array('rule_type' => VariableBomRule::RULE_QTY, 'fk_prod_attr' => $qtyattrid, 'fk_prod_attr_val' => $valid, 'qty' => (float) $qty);
			}
		}

		if (!$error) {
			$db->begin();

			$staticrule = new VariableBomRule($db);
			if ($staticrule->deleteAllBy('fk_bomline', $line->id) < 0) {
				$error++;
				setEventMessages($staticrule->error, $staticrule->errors, 'errors');
			}

			foreach ($newrules as $newrule) {
				if ($error) {
					break;
				}
				$rule = new VariableBomRule($db);
				$rule->fk_bom = $object->id;
				$rule->fk_bomline = $line->id;
				$rule->rule_type = $newrule['rule_type'];
				$rule->fk_prod_attr = $newrule['fk_prod_attr'];
				$rule->fk_prod_attr_val = $newrule['fk_prod_attr_val'];
				$rule->qty = $newrule['qty'];
				if ($rule->create($user) <= 0) {
					$error++;
					setEventMessages($rule->error, $rule->errors, 'errors');
				}
			}

			if (!$error) {
				$db->commit();
				setEventMessages($langs->trans('VariableBomRulesSaved'), null, 'mesgs');
				header('Location: '.$backtotab);
				exit;
			} else {
				$db->rollback();
			}
		}

		if ($action == 'save' && $error) {
			$action = 'editline';	// Show the line in edit mode again, with the values entered
		}
	}
}


/*
 * View
 */

$form = new Form($db);

$title = $object->ref.' - '.$langs->trans('VariableBomTab');
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-variablebom page-bom_variants');

$head = bomPrepareHead($object);
print dol_get_fiche_head($head, 'variablebom', $langs->trans("BillOfMaterials"), -1, 'bom');

$linkback = '<a href="'.DOL_URL_ROOT.'/bom/bom_list.php?restore_lastsearch_values=1">'.$langs->trans("BackToList").'</a>';
dol_banner_tab($object, 'ref', $linkback, 1, 'ref', 'ref', '<div class="refidno"></div>');
print '<div class="underbanner clearboth"></div>';

print dol_get_fiche_end();

if (!$isvariable) {
	print '<div class="opacitymedium">'.$langs->trans('VariableBomNotVariable').'</div>';
	llxFooter();
	$db->close();
	exit;
}

if (!VariableBomResolver::isCoreHookAvailable()) {
	print '<div class="warning">'.img_warning().' '.$langs->trans('ErrorVariableBomCoreHookMissing').'</div><br>';
}

// How the tab works
print '<div class="opacitymedium">'.$langs->trans('VariableBomTabIntro').'</div>';
print '<ol class="opacitymedium">';
foreach (array('VariableBomTabStep1', 'VariableBomTabStep2', 'VariableBomTabStep3', 'VariableBomTabStep4') as $step) {
	print '<li>'.$langs->trans($step).'</li>';
}
print '</ol>';

// Resolve every line for every variant to produce. $results[lineid][variantid] = resolved line, null (line not used) or error string
$variants = $resolver->getVariantCombinations($object->fk_product);
$results = array();
$productids = array();
foreach ($object->lines as $tmpline) {
	$productids[(int) $tmpline->fk_product] = (int) $tmpline->fk_product;
	foreach (array_keys($variants) as $fk_product_variant) {
		$productids[$fk_product_variant] = $fk_product_variant;
		$resolvedlines = $resolver->getLinesForVariant($object, $fk_product_variant, $tmpline->id);
		if (!is_array($resolvedlines)) {
			$results[$tmpline->id][$fk_product_variant] = implode(' ', $resolver->errors);
		} elseif (empty($resolvedlines)) {
			$results[$tmpline->id][$fk_product_variant] = null;
		} else {
			$results[$tmpline->id][$fk_product_variant] = reset($resolvedlines);
			$productids[(int) $results[$tmpline->id][$fk_product_variant]->fk_product] = (int) $results[$tmpline->id][$fk_product_variant]->fk_product;
		}
	}
}

// Load in one request the products to show
$products = array();
$sql = "SELECT p.rowid, p.ref, p.label, p.fk_product_type, p.entity, p.tosell, p.tobuy";
$sql .= " FROM ".$db->prefix()."product as p";
$sql .= " WHERE p.rowid IN (".$db->sanitize(implode(',', $productids ? $productids : array(0))).")";
$sql .= $db->order('p.ref', 'ASC');
$resql = $db->query($sql);
if ($resql) {
	while ($obj = $db->fetch_object($resql)) {
		$tmpproduct = new Product($db);
		$tmpproduct->id = (int) $obj->rowid;
		$tmpproduct->ref = $obj->ref;
		$tmpproduct->label = $obj->label;
		$tmpproduct->type = (int) $obj->fk_product_type;
		$tmpproduct->entity = (int) $obj->entity;
		$tmpproduct->status = (int) $obj->tosell;
		$tmpproduct->status_buy = (int) $obj->tobuy;
		$products[$tmpproduct->id] = $tmpproduct;
	}
	$db->free($resql);
} else {
	dol_print_error($db);
}
// Variants to produce, sorted on ref
$variantids = array();
foreach (array_keys($products) as $tmpid) {
	if (isset($variants[$tmpid])) {
		$variantids[] = $tmpid;
	}
}

// Why the rules can not be edited
if (!$permissiontoadd) {
	if ($object->status != $object::STATUS_DRAFT) {
		print '<div class="info">'.$langs->trans('VariableBomReadOnlyValidated', '<a href="'.DOL_URL_ROOT.'/bom/bom_card.php?id='.((int) $object->id).'">'.$langs->trans('BillOfMaterials').'</a>').'</div>';
	} else {
		print '<div class="info">'.$langs->trans('VariableBomReadOnlyNoPermission').'</div>';
	}
}

$morehtmlright = '<a href="#" class="variablebom_showall">'.img_picto('', 'folder-open', 'class="paddingright"').$langs->trans('VariableBomShowAllResults').'</a>';
$morehtmlright .= ' &nbsp; <a href="#" class="variablebom_hideall">'.img_picto('', 'folder', 'class="paddingright"').$langs->trans('VariableBomHideAllResults').'</a>';
print load_fiche_titre($langs->trans('VariableBomLines', count($variants)), $morehtmlright, '');

print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent" id="variablebom_lines">';
print '<tr class="liste_titre">';
print '<td>'.$langs->trans('Product').'</td>';
print '<td class="right">'.$langs->trans('Qty').'</td>';
print '<td class="center">'.$form->textwithpicto($langs->trans('VariableBomLineMatch'), $langs->trans('VariableBomLineMatchHelp')).'</td>';
print '<td>'.$form->textwithpicto($langs->trans('VariableBomRules'), $langs->trans('VariableBomRulesHelp')).'</td>';
print '<td class="center">'.$form->textwithpicto($langs->trans('VariableBomResolution'), $langs->trans('VariableBomResolutionHelp')).'</td>';
print '<td></td>';
print '</tr>';

foreach ($object->lines as $tmpline) {
	$tmpproduct = (empty($products[$tmpline->fk_product]) ? null : $products[$tmpline->fk_product]);
	$ismatched = !empty($matchedlineids[$tmpline->id]);
	$isedit = ($action == 'editline' && $permissiontoadd && is_object($line) && $line->id == $tmpline->id);

	$nbok = 0;
	foreach ($results[$tmpline->id] as $result) {
		if (!is_string($result)) {
			$nbok++;
		}
	}
	$badge = dolGetBadge($nbok.' / '.count($variants), '', ($nbok < count($variants) ? 'warning' : 'success'));

	// Toggle of the results of the line
	$fold = '<a href="#" class="variablebom_fold" data-line="'.((int) $tmpline->id).'" title="'.dolPrintHTMLForAttribute($langs->trans('VariableBomPreviewHelp')).'">';
	$fold .= '<span class="variablebom_fold_closed"'.($isedit ? ' style="display: none"' : '').'>'.img_picto('', 'folder', 'class="paddingright"').'</span>';
	$fold .= '<span class="variablebom_fold_open"'.($isedit ? '' : ' style="display: none"').'>'.img_picto('', 'folder-open', 'class="paddingright"').'</span>';
	$fold .= '</a>';

	if ($isedit) {
		// The line is edited in place: its rules, then Save / Cancel
		$componentattributes = ($ismatched ? $resolver->getVariantAttributes($tmpline->fk_product) : array());
		$currentfixedvalues = array();
		$currentqtys = array();
		$currentqtyattrid = 0;
		foreach ($resolver->getRulesOfLine($object, $tmpline->id) as $rule) {
			if ($rule->rule_type == VariableBomRule::RULE_FIXEDVALUE) {
				$currentfixedvalues[$rule->fk_prod_attr] = $rule->fk_prod_attr_val;
			} elseif ($rule->rule_type == VariableBomRule::RULE_QTY) {
				$currentqtyattrid = $rule->fk_prod_attr;
				$currentqtys[$rule->fk_prod_attr][$rule->fk_prod_attr_val] = $rule->qty;
			}
		}
		if (GETPOSTISSET('qty_attr')) {
			$currentqtyattrid = GETPOSTINT('qty_attr');
		}

		print '<tr class="oddeven tredited" id="variablebom_line_'.((int) $tmpline->id).'"><td colspan="6">';
		print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
		print '<input type="hidden" name="token" value="'.newToken().'">';
		print '<input type="hidden" name="action" value="save">';
		print '<input type="hidden" name="id" value="'.$object->id.'">';
		print '<input type="hidden" name="lineid" value="'.$tmpline->id.'">';

		print '<div class="paddingtop paddingbottom">'.$fold.(is_object($tmpproduct) ? $tmpproduct->getNomUrl(1).' - '.dolPrintLabel($tmpproduct->label) : '').'</div>';

		if ($ismatched) {
			print '<table class="noborder centpercent">';
			print '<tr class="liste_titre"><td class="titlefieldmiddle">'.$form->textwithpicto($langs->trans('VariableBomComponentAttributes'), $langs->trans('VariableBomComponentAttributesHelp')).'</td><td>'.$langs->trans('Value').'</td></tr>';
			foreach ($componentattributes as $attrid => $attribute) {
				$isshared = isset($producedattributes[$attrid]);
				$selected = (GETPOSTISSET('fixedvalue_'.$attrid) ? GETPOSTINT('fixedvalue_'.$attrid) : (empty($currentfixedvalues[$attrid]) ? 0 : $currentfixedvalues[$attrid]));
				print '<tr class="oddeven"><td>'.dolPrintLabel($attribute['label']).'</td><td>';
				$options = array(0 => ($isshared ? $langs->trans('VariableBomSameAsProduced') : $langs->trans('VariableBomChooseValue')));
				$options += $attribute['values'];
				print $form->selectarray('fixedvalue_'.$attrid, $options, $selected, 0, 0, 0, '', 0, 0, 0, '', 'minwidth200');
				if (!$isshared && empty($selected)) {
					print ' '.img_warning($langs->trans('ErrorVariableBomAttributeNotDefined', (is_object($tmpproduct) ? $tmpproduct->ref : ''), $attribute['label']));
				}
				print '</td></tr>';
			}
			print '</table>';
		} else {
			print '<div class="opacitymedium paddingbottom">'.$langs->trans('VariableBomLineNotMatchedNote').'</div>';
		}

		print '<table class="noborder centpercent">';
		print '<tr class="liste_titre"><td class="titlefieldmiddle">'.$form->textwithpicto($langs->trans('VariableBomQtyByVariant'), $langs->trans('VariableBomQtyByVariantHelp')).'</td><td>';
		$options = array(0 => $langs->trans('VariableBomQtyNone'));
		foreach ($producedattributes as $attrid => $attribute) {
			$options[$attrid] = $langs->trans('VariableBomQtyAttribute').' '.$attribute['label'];
		}
		print $form->selectarray('qty_attr', $options, $currentqtyattrid, 0, 0, 0, '', 0, 0, 0, '', 'minwidth200 variablebom_qtyattr');
		print '</td></tr>';
		foreach ($producedattributes as $attrid => $attribute) {
			foreach ($attribute['values'] as $valid => $vallabel) {
				$value = (GETPOSTISSET('qty_'.$attrid.'_'.$valid) ? GETPOST('qty_'.$attrid.'_'.$valid, 'alpha') : (isset($currentqtys[$attrid][$valid]) ? price($currentqtys[$attrid][$valid], 0, '', 0, 0) : ''));
				print '<tr class="oddeven variablebom_qty variablebom_qty_'.$attrid.'"'.($attrid == $currentqtyattrid ? '' : ' style="display: none"').'>';
				print '<td>'.dolPrintLabel($attribute['label'].': '.$vallabel).'</td>';
				print '<td><input type="text" class="flat right width75" name="qty_'.$attrid.'_'.$valid.'" value="'.dolPrintHTMLForAttribute($value).'" placeholder="'.dolPrintHTMLForAttribute(price($tmpline->qty, 0, '', 0, 0)).'"></td>';
				print '</tr>';
			}
		}
		print '<tr class="oddeven variablebom_qty_help"'.($currentqtyattrid > 0 ? '' : ' style="display: none"').'><td colspan="2"><span class="opacitymedium">'.$langs->trans('VariableBomQtyHelp', price($tmpline->qty, 0, '', 0, 0)).'</span></td></tr>';
		print '</table>';

		print $form->buttonsSaveCancel('Save', 'Cancel');
		print '</form>';
		print '</td></tr>';
	} else {
		print '<tr class="oddeven" id="variablebom_line_'.((int) $tmpline->id).'">';
		print '<td class="tdoverflowmax300">'.$fold.(is_object($tmpproduct) ? $tmpproduct->getNomUrl(1).' - '.dolPrintLabel($tmpproduct->label) : '').'</td>';
		print '<td class="right">'.price($tmpline->qty, 0, '', 0, 0).'</td>';
		print '<td class="center">'.($ismatched ? yn(1) : '').'</td>';
		print '<td class="small">'.variablebomLineRulesSummary($resolver, $object, $tmpline).'</td>';
		print '<td class="center">'.$badge.'</td>';
		print '<td class="center nowraponall">';
		if ($permissiontoadd) {
			print '<a class="editfielda" href="'.$_SERVER['PHP_SELF'].'?id='.$object->id.'&action=editline&token='.newToken().'&lineid='.$tmpline->id.'">'.img_edit().'</a>';
		}
		print '</td>';
		print '</tr>';
	}

	// Result of the line for each variant to produce (folded unless the line is edited)
	foreach ($variantids as $fk_product_variant) {
		$result = $results[$tmpline->id][$fk_product_variant];
		print '<tr class="oddeven variablebom_sub" data-parent="'.((int) $tmpline->id).'"'.($isedit ? '' : ' style="display: none"').'>';
		print '<td class="tdoverflowmax300" style="padding-left: 5%">'.img_picto('', 'rightarrow', 'class="paddingright opacitymedium"').$products[$fk_product_variant]->getNomUrl(1).'</td>';
		if (is_string($result)) {
			print '<td></td><td colspan="4">'.img_warning().' <span class="warning">'.dolPrintLabel($result).'</span></td>';
		} elseif (empty($result) || empty($products[$result->fk_product])) {
			print '<td></td><td colspan="4" class="opacitymedium">'.$langs->trans('VariableBomNotUsed').'</td>';
		} else {
			print '<td class="right">'.price($result->qty, 0, '', 0, 0).'</td>';
			print '<td colspan="4">'.$products[$result->fk_product]->getNomUrl(1).' - '.dolPrintLabel($products[$result->fk_product]->label).'</td>';
		}
		print '</tr>';
	}
}
print '</table>';
print '</div>';

// End of page
llxFooter();
$db->close();
