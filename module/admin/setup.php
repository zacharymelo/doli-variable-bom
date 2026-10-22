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
 * \file    admin/setup.php
 * \ingroup variablebom
 * \brief   Setup page of module VariableBom
 */

// Load Dolibarr environment
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
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/ajax.lib.php';
dol_include_once('/variablebom/lib/variablebom.lib.php');
dol_include_once('/variablebom/class/variablebomresolver.class.php');

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array('admin', 'variablebom@variablebom'));

if (!$user->admin) {
	accessforbidden();
}


/*
 * Actions
 */

// Boolean settings are saved by ajax_constantonoff(), no action to handle


/*
 * View
 */

llxHeader('', $langs->trans('VariableBomSetup'), '', '', 0, 0, '', '', '', 'mod-variablebom page-admin');

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans("BackToModuleList").'</a>';
print load_fiche_titre($langs->trans('VariableBomSetup'), $linkback, 'title_setup');

$head = variablebomAdminPrepareHead();
print dol_get_fiche_head($head, 'settings', '', -1, 'bom');

print '<span class="opacitymedium">'.$langs->trans('VariableBomSetupDesc').'</span><br><br>';

print load_fiche_titre($langs->trans('VariableBomHowTo'), '', '');
print '<ol>';
foreach (array('VariableBomHowTo1', 'VariableBomHowTo2', 'VariableBomHowTo3', 'VariableBomHowTo4', 'VariableBomHowTo5') as $step) {
	print '<li>'.$langs->trans($step).'</li>';
}
print '</ol><br>';

print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('Parameter').'</td><td>'.$langs->trans('Value').'</td><td></td></tr>';

// Status of the hook of the core
print '<tr class="oddeven"><td>'.$langs->trans('VariableBomCoreHook').'</td><td>';
if (VariableBomResolver::isCoreHookAvailable()) {
	print img_picto('', 'tick', 'class="pictofixedwidth"').$langs->trans('VariableBomCoreHookOk');
} else {
	print img_warning().' <span class="warning">'.$langs->trans('ErrorVariableBomCoreHookMissing').'</span>';
}
print '</td><td class="opacitymedium">'.$langs->trans('VariableBomCoreHookDesc').'</td></tr>';

// Debug mode (always last)
print '<tr class="oddeven"><td>'.$langs->trans('DebugMode').'</td>';
print '<td>'.ajax_constantonoff('VARIABLEBOM_DEBUG_MODE').'</td>';
print '<td class="opacitymedium">'.$langs->trans('DebugModeDesc').'</td></tr>';

print '</table>';
print '</div>';

print dol_get_fiche_end();

llxFooter();
$db->close();
