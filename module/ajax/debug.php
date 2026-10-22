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
 * \file    ajax/debug.php
 * \ingroup variablebom
 * \brief   Debug diagnostics of module VariableBom. Gated by admin permission + VARIABLEBOM_DEBUG_MODE.
 *
 * Modes (via ?mode=):
 *   overview  — Module status, core hook, tables, extrafield (default)
 *   bom       — Resolution of every line of a BOM for every variant (?mode=bom&id=12)
 *   settings  — All VARIABLEBOM_* constants
 *   hooks     — Registered hook contexts and actions class
 *   triggers  — Trigger file and events handled
 *   sql       — Read-only diagnostic query (?mode=sql&q=SELECT...)
 *   all       — Every diagnostic except sql
 */

if (!defined('NOREQUIREMENU')) {
	define('NOREQUIREMENU', '1');
}
$res = 0;
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	http_response_code(500);
	exit;
}
dol_include_once('/variablebom/class/variablebomresolver.class.php');

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var User $user
 */

if (!$user->admin) {
	http_response_code(403);
	print 'Admin only';
	exit;
}
if (!getDolGlobalInt('VARIABLEBOM_DEBUG_MODE')) {
	http_response_code(403);
	print 'Debug mode not enabled. Go to VariableBom > Setup and enable Debug Mode.';
	exit;
}

top_httphead('text/plain');

$mode = GETPOST('mode', 'alpha') ? GETPOST('mode', 'alpha') : 'overview';
$run_all = ($mode === 'all');

print "=== VARIABLEBOM DEBUG DIAGNOSTICS ===\n";
print "Timestamp: ".dol_print_date(dol_now(), 'dayhourlog')."\n";
print "Dolibarr: ".DOL_VERSION."\n";
print "Module version: ".getDolGlobalString('MAIN_MODULE_VARIABLEBOM_VERSION', 'unknown')."\n";
print "Mode: $mode\n";
print "Usage: ?mode=overview|bom|settings|hooks|triggers|sql|all  (?mode=bom&id=12, ?mode=sql&q=SELECT...)\n";
print str_repeat('=', 60)."\n\n";

if ($mode === 'overview' || $run_all) {
	print "--- MODULE STATUS ---\n";
	print "isModEnabled('variablebom'): ".(isModEnabled('variablebom') ? 'YES' : 'NO')."\n";
	foreach (array('bom', 'mrp', 'variants') as $module) {
		print "isModEnabled('$module'): ".(isModEnabled($module) ? 'YES' : 'NO')."\n";
	}
	print "Core hook processBOMLines: ".(VariableBomResolver::isCoreHookAvailable() ? 'AVAILABLE' : 'MISSING (MO of variable BOM are refused)')."\n";

	print "\n--- DATABASE ---\n";
	$resql = $db->query("SELECT COUNT(rowid) as nb FROM ".$db->prefix()."variablebom_rule");
	print "  llx_variablebom_rule: ".($resql ? $db->fetch_object($resql)->nb.' rows' : 'TABLE MISSING OR ERROR')."\n";
	$resql = $db->query("SELECT COUNT(rowid) as nb FROM ".$db->prefix()."bom_bomline_extrafields WHERE ".VariableBomResolver::EXTRAFIELD_MATCH." = 1");
	print "  extrafield ".VariableBomResolver::EXTRAFIELD_MATCH." of BOM lines: ".($resql ? $db->fetch_object($resql)->nb.' lines matched to variant' : 'COLUMN MISSING OR ERROR')."\n\n";
}

if ($mode === 'bom') {
	$id = GETPOSTINT('id');
	print "--- BOM id=$id ---\n";
	$bom = new BOM($db);
	if ($id <= 0 || $bom->fetch($id) <= 0) {
		print "  BOM not found\n\n";
	} else {
		$resolver = new VariableBomResolver($db);
		print "  ref=".$bom->ref." fk_product=".$bom->fk_product." variable=".($resolver->isVariable($bom) ? 'YES' : 'NO')."\n";
		print "  lines matched to variant: ".implode(', ', $resolver->getMatchedLineIds($bom))."\n";
		foreach (array_keys($resolver->getVariantCombinations($bom->fk_product)) as $fk_product_variant) {
			$lines = $resolver->getLinesForVariant($bom, $fk_product_variant);
			print "  variant $fk_product_variant: ";
			if (!is_array($lines)) {
				print "ERROR ".implode(' | ', $resolver->errors)."\n";
				continue;
			}
			$parts = array();
			foreach ($lines as $line) {
				$parts[] = 'line '.$line->id.' -> product '.$line->fk_product.' x '.$line->qty;
			}
			print implode('; ', $parts)."\n";
		}
		print "\n";
	}
}

if ($mode === 'settings' || $run_all) {
	print "--- SETTINGS ---\n";
	$resql = $db->query("SELECT name, value FROM ".$db->prefix()."const WHERE name LIKE 'VARIABLEBOM%' AND entity IN (0, ".((int) $conf->entity).") ORDER BY name");
	if ($resql) {
		while ($row = $db->fetch_object($resql)) {
			print "  ".$row->name." = ".dol_trunc($row->value, 80)."\n";
		}
	}
	print "\n";
}

if ($mode === 'hooks' || $run_all) {
	print "--- HOOKS ---\n";
	if (isset($conf->modules_parts['hooks'])) {
		foreach ($conf->modules_parts['hooks'] as $module => $contexts) {
			if ($module === 'variablebom') {
				print "  contexts: ".(is_array($contexts) ? implode(', ', $contexts) : $contexts)."\n";
			}
		}
	}
	$file = dol_buildpath('/variablebom/class/actions_variablebom.class.php');
	print "  actions class file: ".(file_exists($file) ? 'EXISTS' : 'MISSING')."\n";
	dol_include_once('/variablebom/class/actions_variablebom.class.php');
	foreach (array('processBOMLines', 'formObjectOptions') as $method) {
		print "  method $method(): ".(method_exists('ActionsVariableBom', $method) ? 'defined' : 'MISSING')."\n";
	}
	print "\n";
}

if ($mode === 'triggers' || $run_all) {
	print "--- TRIGGERS ---\n";
	$file = dol_buildpath('/variablebom/core/triggers/interface_99_modVariableBom_VariableBomTrigger.class.php');
	print "  trigger file: ".(file_exists($file) ? 'EXISTS' : 'MISSING')."\n";
	if (file_exists($file)) {
		$matches = array();
		preg_match_all("/case\s+'([^']+)'/", (string) file_get_contents($file), $matches);
		print "  events: ".implode(', ', $matches[1])."\n";
	}
	print "\n";
}

if ($mode === 'sql') {
	$q = trim(GETPOST('q', 'restricthtml'));
	print "--- SQL QUERY ---\n";
	if (stripos($q, 'SELECT') !== 0 || preg_match('/\b(INSERT|UPDATE|DELETE|DROP|ALTER|TRUNCATE|CREATE|GRANT|REVOKE)\b/i', $q)) {
		print "Only SELECT queries are allowed. Example: ?mode=sql&q=SELECT rowid,fk_bomline,rule_type FROM llx_variablebom_rule\n";
	} else {
		if (stripos($q, 'LIMIT') === false) {
			$q .= ' LIMIT 50';
		}
		print "Query: $q\n\n";
		$resql = $db->query($q);
		if ($resql) {
			$first = true;
			while ($obj = $db->fetch_object($resql)) {
				$row = get_object_vars($obj);
				if ($first) {
					print implode("\t", array_keys($row))."\n";
					$first = false;
				}
				print implode("\t", array_map(function ($v) {
					return ($v === null ? 'NULL' : dol_trunc((string) $v, 40));
				}, $row))."\n";
			}
		} else {
			print "SQL ERROR: ".$db->lasterror()."\n";
		}
	}
	print "\n";
}

print "=== END DEBUG ===\n";
