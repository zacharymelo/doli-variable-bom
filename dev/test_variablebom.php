<?php
/* Copyright (C) 2026 Zach Melo <zach@digitalproperties.works>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * End-to-end test of module VariableBom, run inside the dev container (it creates demo data):
 *   docker compose cp dev/test_variablebom.php dolibarr:/tmp/ && docker compose exec -u www-data dolibarr php /tmp/test_variablebom.php
 */

define('NOSESSION', 1);
if (php_sapi_name() !== 'cli') {
	exit(1);
}
require '/var/www/html/master.inc.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';

$user->fetch(0, 'admin');
$user->loadRights();
$langs->setDefaultLang('en_US');
$langs->loadLangs(array('main', 'mrp', 'errors'));

$failures = 0;
$run = dol_print_date(dol_now(), '%H%M%S');

/**
 * Print result of a check
 *
 * @param string	$label	Label of check
 * @param bool		$cond	Result
 * @param string	$detail	Detail shown if KO
 * @return void
 */
function check($label, $cond, $detail = '')
{
	global $failures;
	if ($cond) {
		print "  PASS  ".$label."\n";
	} else {
		$failures++;
		print "  FAIL  ".$label.($detail !== '' ? "  => ".$detail : '')."\n";
	}
}

// Modules
foreach (array('modProduct', 'modStock', 'modVariants', 'modBom', 'modMrp', 'modVariableBom') as $mod) {
	$res = activateModule($mod);
	if (!empty($res['errors'])) {
		print "Error enabling $mod: ".implode(', ', $res['errors'])."\n";
		exit(1);
	}
}
$conf->setValues($db);
$user->loadRights('', 1);
$langs->load('variablebom@variablebom');

require_once DOL_DOCUMENT_ROOT.'/variants/class/ProductAttribute.class.php';
require_once DOL_DOCUMENT_ROOT.'/variants/class/ProductAttributeValue.class.php';
require_once DOL_DOCUMENT_ROOT.'/variants/class/ProductCombination.class.php';
require_once DOL_DOCUMENT_ROOT.'/bom/class/bom.class.php';
require_once DOL_DOCUMENT_ROOT.'/mrp/class/mo.class.php';
dol_include_once('/variablebom/class/variablebomresolver.class.php');

check('module enabled', isModEnabled('variablebom'));
check('core hook available', VariableBomResolver::isCoreHookAvailable());
check('table llx_variablebom_rule exists', (bool) $db->query("SELECT rowid FROM ".MAIN_DB_PREFIX."variablebom_rule LIMIT 1"), $db->lasterror());
check('extrafield of BOM lines exists', (bool) $db->query("SELECT variablebom_match FROM ".MAIN_DB_PREFIX."bom_bomline_extrafields LIMIT 1"), $db->lasterror());

// Attributes
$attrs = array();
$vals = array();
foreach (array('SIZE' => array('S', 'M', 'L'), 'COLOR' => array('RED', 'BLUE'), 'WEIGHT' => array('LIGHT', 'HEAVY')) as $attrref => $valrefs) {
	$a = new ProductAttribute($db);
	$a->ref = $attrref.$run;
	$a->label = ucfirst(strtolower($attrref));
	$a->create($user);
	$attrs[$attrref] = $a->id;
	foreach ($valrefs as $valref) {
		$v = new ProductAttributeValue($db);
		$v->fk_product_attribute = $a->id;
		$v->ref = $valref;
		$v->value = ucfirst(strtolower($valref));
		$v->create($user);
		$vals[$attrref][$valref] = $v->id;
	}
}

/**
 * Create a product
 *
 * @param string	$ref	Ref
 * @param float		$cost	Cost price
 * @return Product
 */
function makeProduct($ref, $cost = 0)
{
	global $db, $user, $run;
	$p = new Product($db);
	$p->ref = $ref.'-'.$run;
	$p->label = $ref;
	$p->type = Product::TYPE_PRODUCT;
	$p->status = 1;
	$p->status_buy = 1;
	$p->finished = 1;
	$p->cost_price = $cost;
	if ($p->create($user) <= 0) {
		print "Error product $ref ".$p->error."\n";
		exit(1);
	}
	$p->fetch($p->id);
	$p->weight = (float) $p->weight;
	return $p;
}

/**
 * Create the variants of a product
 *
 * @param Product	$parent	Parent product
 * @param string[]	$axes	Attributes
 * @return array<string,int>	Combination key => id of variant
 */
function makeVariants(Product $parent, array $axes)
{
	global $db, $user, $attrs, $vals;
	$combos = array(array());
	foreach ($axes as $attrref) {
		$new = array();
		foreach ($combos as $c) {
			foreach (array_keys($vals[$attrref]) as $valref) {
				$new[] = $c + array($attrref => $valref);
			}
		}
		$combos = $new;
	}
	$children = array();
	foreach ($combos as $c) {
		$combination = array();
		$variations = array();
		foreach ($c as $attrref => $valref) {
			$combination[$attrs[$attrref]] = $vals[$attrref][$valref];
			$variations[$attrs[$attrref]][$vals[$attrref][$valref]] = array('weight' => 0, 'price' => 0);
		}
		$pc = new ProductCombination($db);
		$pc->createProductCombination($user, $parent, $combination, $variations);
		$found = $pc->fetchByProductCombination2ValuePairs($parent->id, $combination);
		$children[implode('/', $c)] = $found->fk_product_child;
	}
	return $children;
}

/**
 * Add a rule to a line
 *
 * @param int		$bomid	Id of BOM
 * @param int		$lineid	Id of line
 * @param string	$type	Type of rule
 * @param int		$attrid	Attribute
 * @param int		$valid	Value
 * @param ?float	$qty	Qty
 * @return int
 */
function addRule($bomid, $lineid, $type, $attrid, $valid, $qty = null)
{
	global $db, $user;
	$r = new VariableBomRule($db);
	$r->fk_bom = $bomid;
	$r->fk_bomline = $lineid;
	$r->rule_type = $type;
	$r->fk_prod_attr = $attrid;
	$r->fk_prod_attr_val = $valid;
	$r->qty = $qty;
	return $r->create($user);
}

$matched = array('options_'.VariableBomResolver::EXTRAFIELD_MATCH => 1);

$shirt = makeProduct('SHIRT');
$shirtVariants = makeVariants($shirt, array('SIZE', 'COLOR'));
$tee = makeProduct('TEE', 3);
$teeVariants = makeVariants($tee, array('SIZE', 'COLOR'));
$label = makeProduct('LABEL', 0.1);
$labelVariants = makeVariants($label, array('SIZE'));
$thread = makeProduct('THREAD', 0.2);
$threadVariants = makeVariants($thread, array('COLOR', 'WEIGHT'));
$ink = makeProduct('INK', 1);
$patch = makeProduct('PATCH', 0.5);

$bom = new BOM($db);
$bom->label = 'Shirt design';
$bom->fk_product = $shirt->id;
$bom->qty = 1;
$bom->status = BOM::STATUS_DRAFT;
$bom->array_options['options_'.VariableBomResolver::EXTRAFIELD_VARIABLE] = 1;
check('BOM created', $bom->create($user) > 0, $bom->error);
$bom->fetch($bom->id);
$resolver = new VariableBomResolver($db);
check('BOM is variable', $resolver->isVariable($bom));

$lineTee = $bom->addLine($tee->id, 1, 0, 0, 1, -1, null, null, 0, $matched);
check('add matched line TEE', $lineTee > 0, $bom->error);
$lineLabel = $bom->addLine($label->id, 1, 0, 0, 1, -1, null, null, 0, $matched);
check('add matched line LABEL', $lineLabel > 0, $bom->error);
$lineThread = $bom->addLine($thread->id, 2, 0, 0, 1, -1, null, null, 0, $matched);
check('add matched line THREAD', $lineThread > 0, $bom->error);
$lineInk = $bom->addLine($ink->id, 1);
check('add plain line INK', $lineInk > 0, $bom->error);
$linePatch = $bom->addLine($patch->id, 0);
check('add plain line PATCH (qty 0 by default)', $linePatch > 0, $bom->error);
$res = $bom->addLine($ink->id, 1, 0, 0, 1, -1, null, null, 0, $matched);
check('trigger refuses a matched line without variants', $res < 0 && strpos(implode(' ', array_merge(array($bom->error), $bom->errors)), $langs->trans('ErrorVariableBomMatchNeedsVariantParent')) !== false, $res.' '.$bom->error.' '.implode(';', $bom->errors));

$bom->fetch($bom->id);
$resolver = new VariableBomResolver($db);
$lines = $resolver->getLinesForVariant($bom, $shirtVariants['L/RED']);
check('resolution fails without fixed value for Weight', !is_array($lines) && strpos($resolver->error, 'Weight') !== false, $resolver->error);

check('rule thread weight=heavy', addRule($bom->id, $lineThread, VariableBomRule::RULE_FIXEDVALUE, $attrs['WEIGHT'], $vals['WEIGHT']['HEAVY']) > 0);
check('rule ink qty L=2', addRule($bom->id, $lineInk, VariableBomRule::RULE_QTY, $attrs['SIZE'], $vals['SIZE']['L'], 2) > 0);
check('rule patch qty L=1', addRule($bom->id, $linePatch, VariableBomRule::RULE_QTY, $attrs['SIZE'], $vals['SIZE']['L'], 1) > 0);

$expected = function ($size, $color) use ($teeVariants, $labelVariants, $threadVariants, $ink, $patch) {
	$e = array($teeVariants[$size.'/'.$color] => 1.0, $labelVariants[$size] => 1.0, $threadVariants[$color.'/HEAVY'] => 2.0, $ink->id => ($size == 'L' ? 2.0 : 1.0));
	if ($size == 'L') {
		$e[$patch->id] = 1.0;
	}
	ksort($e);
	return $e;
};

$resolver = new VariableBomResolver($db);
$allok = true;
foreach ($shirtVariants as $key => $childid) {
	list($size, $color) = explode('/', $key);
	$got = array();
	$lines = $resolver->getLinesForVariant($bom, $childid);
	foreach ((is_array($lines) ? $lines : array()) as $l) {
		$got[$l->fk_product] = (float) $l->qty;
	}
	ksort($got);
	if ($got != $expected($size, $color)) {
		$allok = false;
		print "    $key got ".json_encode($got)." ".implode(';', $resolver->errors)."\n";
	}
}
check('resolution correct for all 6 variants', $allok);

// MO through the core hook
check('BOM validated', $bom->validate($user) > 0, $bom->error);
$bom->fetch($bom->id);
$mo = new Mo($db);
$mo->fk_bom = $bom->id;
$mo->fk_product = $shirtVariants['L/RED'];
$mo->qty = 3;
$mo->label = 'Test L/RED';
$mo->status = Mo::STATUS_DRAFT;
$moid = $mo->create($user);
check('MO created for L/RED', $moid > 0, $mo->error.' '.implode(';', $mo->errors));
if ($moid > 0) {
	$mo->fetch($moid);
	$toconsume = array();
	foreach ($mo->lines as $l) {
		if ($l->role == 'toconsume') {
			$toconsume[$l->fk_product] = (float) $l->qty;
		}
	}
	ksort($toconsume);
	$exp = array_map(function ($q) {
		return $q * 3;
	}, $expected('L', 'RED'));
	check('MO consumes resolved variants x3', $toconsume == $exp, json_encode($toconsume).' vs '.json_encode($exp));
}

$mo2 = new Mo($db);
$mo2->fk_bom = $bom->id;
$mo2->fk_product = $shirt->id;
$mo2->qty = 1;
$mo2->status = Mo::STATUS_DRAFT;
$res = $mo2->create($user);
check('MO refused for parent product', $res < 0 && strpos(implode(' ', array_merge(array($mo2->error), $mo2->errors)), $shirt->ref) !== false, $res.' '.$mo2->error.' '.implode(';', $mo2->errors));

// Variable sub-BOM
$panel = makeProduct('PANEL');
makeVariants($panel, array('SIZE', 'COLOR'));
$fabric = makeProduct('FABRIC', 2);
$fabricVariants = makeVariants($fabric, array('COLOR'));
$panelBom = new BOM($db);
$panelBom->label = 'Panel';
$panelBom->fk_product = $panel->id;
$panelBom->qty = 1;
$panelBom->status = BOM::STATUS_DRAFT;
$panelBom->array_options['options_'.VariableBomResolver::EXTRAFIELD_VARIABLE] = 1;
$panelBom->create($user);
$panelBom->fetch($panelBom->id);
$lineFabric = $panelBom->addLine($fabric->id, 1, 0, 0, 1, -1, null, null, 0, $matched);
addRule($panelBom->id, $lineFabric, VariableBomRule::RULE_QTY, $attrs['SIZE'], $vals['SIZE']['L'], 2);
$panelBom->fetch($panelBom->id);
$panelBom->validate($user);
$shirtBom2 = new BOM($db);
$shirtBom2->label = 'Shirt with sub-BOM';
$shirtBom2->fk_product = $shirt->id;
$shirtBom2->qty = 1;
$shirtBom2->status = BOM::STATUS_DRAFT;
$shirtBom2->array_options['options_'.VariableBomResolver::EXTRAFIELD_VARIABLE] = 1;
$shirtBom2->create($user);
$shirtBom2->fetch($shirtBom2->id);
$shirtBom2->addLine($panel->id, 1, 0, 0, 1, -1, $panelBom->id, null, 0, $matched);
$shirtBom2->fetch($shirtBom2->id);
$shirtBom2->validate($user);
$mo3 = new Mo($db);
$mo3->fk_bom = $shirtBom2->id;
$mo3->fk_product = $shirtVariants['L/RED'];
$mo3->qty = 3;
$mo3->status = Mo::STATUS_DRAFT;
$mo3id = $mo3->create($user);
check('MO with variable sub-BOM created', $mo3id > 0, $mo3->error.' '.implode(';', $mo3->errors));
if ($mo3id > 0) {
	$mo3->fetch($mo3id);
	$toconsume = array();
	foreach ($mo3->lines as $l) {
		if ($l->role == 'toconsume') {
			$toconsume[$l->fk_product] = (float) $l->qty;
		}
	}
	check('sub-BOM flattened to FABRIC RED x6', $toconsume == array($fabricVariants['RED'] => 6.0), json_encode($toconsume));
}

// Non variable BOM unchanged
$plain = new BOM($db);
$plain->label = 'Plain';
$plain->fk_product = $ink->id;
$plain->qty = 1;
$plain->status = BOM::STATUS_DRAFT;
$plain->create($user);
$plain->fetch($plain->id);
$res = $plain->addLine($tee->id, 1, 0, 0, 1, -1, null, null, 0, $matched);
check('trigger refuses a matched line into a non variable BOM', $res < 0, (string) $res);
$plain->addLine($patch->id, 4);
$plain->fetch($plain->id);
$plain->validate($user);
$mo4 = new Mo($db);
$mo4->fk_bom = $plain->id;
$mo4->fk_product = $ink->id;
$mo4->qty = 2;
$mo4->status = Mo::STATUS_DRAFT;
$mo4id = $mo4->create($user);
$consumed = ($mo4id > 0 ? $db->getRow("SELECT fk_product, qty FROM ".MAIN_DB_PREFIX."mrp_production WHERE fk_mo = ".((int) $mo4id)." AND role = 'toconsume'") : null);
check('MO of a plain BOM unchanged', is_object($consumed) && $consumed->fk_product == $patch->id && (float) $consumed->qty == 8.0, json_encode($consumed));

// Flag "Variable BOM" and its checks
check('flag migration done once', getDolGlobalInt('VARIABLEBOM_FLAG_MIGRATED') == 1);
$bad = new BOM($db);
$bad->label = 'Flag without variants';
$bad->fk_product = $ink->id;
$bad->qty = 1;
$bad->status = BOM::STATUS_DRAFT;
$bad->array_options['options_'.VariableBomResolver::EXTRAFIELD_VARIABLE] = 1;
$res = $bad->create($user);
check('flag refused on a product without variants', $res < 0 && strpos(implode(' ', array_merge(array($bad->error), $bad->errors)), $langs->trans('ErrorVariableBomFlagNeedsVariants')) !== false, $res.' '.$bad->error.' '.implode(';', $bad->errors));

$unflagged = new BOM($db);
$unflagged->label = 'Unflagged parent BOM';
$unflagged->fk_product = $shirt->id;
$unflagged->qty = 1;
$unflagged->status = BOM::STATUS_DRAFT;
check('unflagged BOM of a parent product created', $unflagged->create($user) > 0, $unflagged->error);
$unflagged->fetch($unflagged->id);
$resolver = new VariableBomResolver($db);
check('unflagged BOM is not variable', !$resolver->isVariable($unflagged));
$res = $unflagged->addLine($tee->id, 1, 0, 0, 1, -1, null, null, 0, $matched);
check('matched line refused on an unflagged BOM', $res < 0, (string) $res);
check('plain line accepted on an unflagged BOM', $unflagged->addLine($tee->id, 1) > 0, $unflagged->error);
$unflagged->fetch($unflagged->id);
$unflagged->validate($user);
$mo5 = new Mo($db);
$mo5->fk_bom = $unflagged->id;
$mo5->fk_product = $shirt->id;
$mo5->qty = 1;
$mo5->status = Mo::STATUS_DRAFT;
$mo5id = $mo5->create($user);
$consumed = ($mo5id > 0 ? $db->getRow("SELECT fk_product FROM ".MAIN_DB_PREFIX."mrp_production WHERE fk_mo = ".((int) $mo5id)." AND role = 'toconsume'") : null);
check('unflagged BOM: MO of the parent product behaves as core', is_object($consumed) && $consumed->fk_product == $tee->id, json_encode($consumed).' '.$mo5->error);

// Cleanup of rules by triggers
$draftBom = new BOM($db);
$draftBom->label = 'To delete';
$draftBom->fk_product = $shirt->id;
$draftBom->qty = 1;
$draftBom->status = BOM::STATUS_DRAFT;
$draftBom->array_options['options_'.VariableBomResolver::EXTRAFIELD_VARIABLE] = 1;
$draftBom->create($user);
$draftBom->fetch($draftBom->id);
$l1 = $draftBom->addLine($ink->id, 1);
$l2 = $draftBom->addLine($patch->id, 1);
addRule($draftBom->id, $l1, VariableBomRule::RULE_QTY, $attrs['SIZE'], $vals['SIZE']['L'], 2);
addRule($draftBom->id, $l2, VariableBomRule::RULE_QTY, $attrs['SIZE'], $vals['SIZE']['L'], 2);
$draftBom->fetch($draftBom->id);
$draftBom->deleteLine($user, $l1);
check('rules of a deleted line removed', $db->getRow("SELECT COUNT(rowid) as nb FROM ".MAIN_DB_PREFIX."variablebom_rule WHERE fk_bomline = ".((int) $l1))->nb == 0);
$draftBom->delete($user);
check('rules of a deleted BOM removed', $db->getRow("SELECT COUNT(rowid) as nb FROM ".MAIN_DB_PREFIX."variablebom_rule WHERE fk_bom = ".((int) $draftBom->id))->nb == 0);

print "\nBOM for UI checks: id ".$bom->id." (".$bom->ref.")\n";
print ($failures ? "$failures FAILURE(S)\n" : "ALL PASSED\n");
exit($failures ? 1 : 0);
