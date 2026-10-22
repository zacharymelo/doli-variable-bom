<?php
/* Copyright (C) 2026 Zach Melo <zach@digitalproperties.works>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * Add the hook processBOMLines (version 2) to Mo::processBOM() of a Dolibarr 24+ installation.
 *
 * Usage: php apply_hook.php /path/to/dolibarr/htdocs
 *
 * - The file htdocs/mrp/class/mo.class.php is saved as mo.class.php.orig before the first change.
 * - Nothing is done if version 2 of the hook is already there.
 * - Version 1 of the hook (module 1.0.0) is upgraded to version 2.
 * - Nothing is changed if the code is not the expected one.
 *
 * This file is generated: the strings below are the same as the proposed core change.
 */

if (php_sapi_name() !== 'cli') {
	exit(1);
}
if (empty($argv[1])) {
	fwrite(STDERR, "Usage: php ".$argv[0]." /path/to/dolibarr/htdocs\n");
	exit(1);
}

$file = rtrim($argv[1], '/').'/mrp/class/mo.class.php';
if (!is_file($file) || !is_writable($file)) {
	fwrite(STDERR, "File not found or not writable: $file\n");
	exit(1);
}

$content = file_get_contents($file);
if (strpos($content, 'fk_product_bom_processed') !== false) {
	print "The hook processBOMLines (v2) is already in $file. Nothing to do.\n";
	exit(0);
}

// Version 1 of the hook: revert it first
$v1 = array(
	'	 * @param  float 	$quantity 	Quantity modifier for sub products/BOM
	 * @return int             		Return integer <0 if KO, >0 if OK
	 */
	public function processBOM(User $user, $role, $bom, $quantity)
	{
		$error = 0;

		$quantity /= $bom->qty;
		foreach ($bom->lines as $line) {' => '	 * @param  float 	$quantity 	Quantity modifier for sub products/BOM
	 * @param  int 		$fk_product	Id of product produced by $bom (0 = product of the MO)
	 * @return int             		Return integer <0 if KO, >0 if OK
	 */
	public function processBOM(User $user, $role, $bom, $quantity, $fk_product = 0)
	{
		global $action, $hookmanager;

		$error = 0;

		// A module can replace the lines of the BOM to process (for example to use the variant of a product)
		$bomlines = $bom->lines;
		$hookmanager->initHooks(array(\'modao\'));
		$parameters = array(\'role\' => $role, \'bom\' => $bom, \'quantity\' => $quantity, \'fk_product\' => ($fk_product > 0 ? $fk_product : $this->fk_product));
		$reshook = $hookmanager->executeHooks(\'processBOMLines\', $parameters, $this, $action);
		if ($reshook < 0) {
			$this->setErrorsFromObject($hookmanager);
			return 1;
		} elseif ($reshook > 0 && isset($hookmanager->resArray[\'lines\']) && is_array($hookmanager->resArray[\'lines\'])) {
			$bomlines = $hookmanager->resArray[\'lines\'];
		}

		$quantity /= $bom->qty;
		foreach ($bomlines as $line) {',
	'				$error += $this->processBOM($user, $role, $bom, $quantity_line);' => '				$error += $this->processBOM($user, $role, $bom, $quantity_line, $line->fk_product);'
);
if (strpos($content, 'public function processBOM(User $user, $role, $bom, $quantity, $fk_product = 0)') !== false) {
	foreach ($v1 as $original => $patched) {
		if (substr_count($content, $patched) !== 1) {
			fwrite(STDERR, "A previous version of the hook is in $file but it is not the expected code. Nothing changed.\n");
			exit(1);
		}
		$content = str_replace($patched, $original, $content);
	}
	print "Previous version of the hook reverted.\n";
}

$v2 = array(
	'	 *            sub-assembly product is created for these lines instead of recursing into the sub-BOM.
	 */
	public $noFlattenBomLineIds = array();
' => '	 *            sub-assembly product is created for these lines instead of recursing into the sub-BOM.
	 */
	public $noFlattenBomLineIds = array();

	/**
	 * @var int Id of the product produced by the BOM currently processed by processBOM() (0 = the product of the MO).
	 *          Set while recursing into a sub BOM, so the hook processBOMLines knows which product the sub BOM produces.
	 */
	protected $fk_product_bom_processed = 0;
',
	'	/**
	 * Recurse through BOM only adding products to the list of lines to consume/produce
	 *
	 * @param  User 	$user      	User that modifies
	 * @param  string 	$role    	MoLine Role that products are added as (\'toproduce\', \'toconsume\', \'produced\', \'consumed\')
	 * @param  BOM 		$bom        BOM to parse lines from
	 * @param  float 	$quantity 	Quantity modifier for sub products/BOM
	 * @return int             		Return integer <0 if KO, >0 if OK
	 */
	public function processBOM(User $user, $role, $bom, $quantity)
	{
		$error = 0;

		$quantity /= $bom->qty;
		foreach ($bom->lines as $line) {' => '	/**
	 * Recurse through BOM only adding products to the list of lines to consume/produce
	 *
	 * Before the lines are processed, the hook \'processBOMLines\' (context \'modao\') is called with the parameters
	 * \'role\', \'bom\', \'quantity\' and \'fk_product\' (the product produced by $bom: the product of the MO, or the product
	 * of the parent line for a sub BOM). A module can:
	 * - return > 0 with $this->results[\'lines\'] = array of BOMLine (or objects with the same properties: id, fk_product,
	 *   qty, qty_frozen, efficiency, fk_bom_child, fk_unit, position, disable_stock_change, fk_default_workstation)
	 *   to replace the lines to process. If several modules return lines, the last one wins.
	 * - return < 0 to stop the creation of the MO, with the errors of the module.
	 *
	 * @param  User 	$user      	User that modifies
	 * @param  string 	$role    	MoLine Role that products are added as (\'toproduce\', \'toconsume\', \'produced\', \'consumed\')
	 * @param  BOM 		$bom        BOM to parse lines from
	 * @param  float 	$quantity 	Quantity modifier for sub products/BOM
	 * @return int             		Number of errors (0 if OK)
	 */
	public function processBOM(User $user, $role, $bom, $quantity)
	{
		global $action, $hookmanager;

		$error = 0;

		$bomlines = $bom->lines;
		if (is_object($hookmanager)) {	// No hook manager when the caller defined NOHOOKMANAGER
			$hookmanager->initHooks(array(\'modao\'));
			$hookmanager->error = \'\';	// Errors of previous hooks of the request must not be reported as errors of this one
			$hookmanager->errors = array();
			$parameters = array(\'role\' => $role, \'bom\' => $bom, \'quantity\' => $quantity, \'fk_product\' => ($this->fk_product_bom_processed > 0 ? $this->fk_product_bom_processed : (int) $this->fk_product));
			$reshook = $hookmanager->executeHooks(\'processBOMLines\', $parameters, $this, $action);
			if ($reshook < 0) {
				$this->setErrorsFromObject($hookmanager);
				return 1;
			}
			if ($reshook > 0 && isset($hookmanager->resArray[\'lines\']) && is_array($hookmanager->resArray[\'lines\'])) {
				$bomlines = $hookmanager->resArray[\'lines\'];
			}
		}

		$quantity /= $bom->qty;
		foreach ($bomlines as $line) {',
	'				$bom = new BOM($this->db);
				$bom->fetch((int) $line->fk_bom_child);
				$error += $this->processBOM($user, $role, $bom, $quantity_line);' => '				$bom = new BOM($this->db);
				$bom->fetch((int) $line->fk_bom_child);
				$fk_product_bom_processed_saved = $this->fk_product_bom_processed;
				$this->fk_product_bom_processed = (int) $line->fk_product;	// The sub BOM produces the product of the line
				$error += $this->processBOM($user, $role, $bom, $quantity_line);
				$this->fk_product_bom_processed = $fk_product_bom_processed_saved;'
);
foreach ($v2 as $search => $replace) {
	if (substr_count($content, $search) !== 1) {
		fwrite(STDERR, "The code of $file is not the expected one (Dolibarr 24+ required). Nothing changed.\n");
		exit(1);
	}
	$content = str_replace($search, $replace, $content);
}

if ((!is_file($file.'.orig') && !copy($file, $file.'.orig')) || file_put_contents($file, $content) === false) {
	fwrite(STDERR, "Failed to write $file\n");
	exit(1);
}

print "Hook processBOMLines (v2) added into $file (original kept as $file.orig).\n";
exit(0);
