<?php
/* Copyright (C) 2026 Zach Melo <zach@digitalproperties.works>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * Add the hook processBOMLines to Mo::processBOM() of a Dolibarr 24+ installation (proposed upstream).
 *
 * Usage: php apply_hook.php /path/to/dolibarr/htdocs
 *
 * The file htdocs/mrp/class/mo.class.php is saved as mo.class.php.orig before being changed.
 * Nothing is done if the hook is already there, or if the code differs from the expected one.
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
if (strpos($content, "'processBOMLines'") !== false) {
	print "The hook processBOMLines is already in $file. Nothing to do.\n";
	exit(0);
}

$replacements = array(
	"	 * @param  float 	\$quantity 	Quantity modifier for sub products/BOM
	 * @return int             		Return integer <0 if KO, >0 if OK
	 */
	public function processBOM(User \$user, \$role, \$bom, \$quantity)
	{
		\$error = 0;

		\$quantity /= \$bom->qty;
		foreach (\$bom->lines as \$line) {" => "	 * @param  float 	\$quantity 	Quantity modifier for sub products/BOM
	 * @param  int 		\$fk_product	Id of product produced by \$bom (0 = product of the MO)
	 * @return int             		Return integer <0 if KO, >0 if OK
	 */
	public function processBOM(User \$user, \$role, \$bom, \$quantity, \$fk_product = 0)
	{
		global \$action, \$hookmanager;

		\$error = 0;

		// A module can replace the lines of the BOM to process (for example to use the variant of a product)
		\$bomlines = \$bom->lines;
		\$hookmanager->initHooks(array('modao'));
		\$parameters = array('role' => \$role, 'bom' => \$bom, 'quantity' => \$quantity, 'fk_product' => (\$fk_product > 0 ? \$fk_product : \$this->fk_product));
		\$reshook = \$hookmanager->executeHooks('processBOMLines', \$parameters, \$this, \$action);
		if (\$reshook < 0) {
			\$this->setErrorsFromObject(\$hookmanager);
			return 1;
		} elseif (\$reshook > 0 && isset(\$hookmanager->resArray['lines']) && is_array(\$hookmanager->resArray['lines'])) {
			\$bomlines = \$hookmanager->resArray['lines'];
		}

		\$quantity /= \$bom->qty;
		foreach (\$bomlines as \$line) {",
	"				\$error += \$this->processBOM(\$user, \$role, \$bom, \$quantity_line);" => "				\$error += \$this->processBOM(\$user, \$role, \$bom, \$quantity_line, \$line->fk_product);",
);

foreach ($replacements as $search => $replace) {
	if (substr_count($content, $search) !== 1) {
		fwrite(STDERR, "The code of $file is not the expected one (Dolibarr 24+ required). Nothing changed.\n");
		exit(1);
	}
	$content = str_replace($search, $replace, $content);
}

if (!copy($file, $file.'.orig') || file_put_contents($file, $content) === false) {
	fwrite(STDERR, "Failed to write $file\n");
	exit(1);
}

print "Hook processBOMLines added into $file (backup: $file.orig).\n";
exit(0);
