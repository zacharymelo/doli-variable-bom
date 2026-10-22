# doli-variable-bom

Dolibarr module **Variable BOM**: one BOM for all the variants of a product, instead of one BOM per variant.
It cuts down the number of BOMs needed for products like screen-printed T-shirts.

Typical case: a screen-printed T-shirt sold in every Size × Color. One BOM of the parent product "Shirt design" is enough:

| BOM line | Setting | Result for a shirt L / Red |
|---|---|---|
| Blank tee (Size × Color) | Matched to variant | Blank tee L / Red |
| Neck label (Size) | Matched to variant | Neck label L |
| Thread (Color × Weight) | Matched to variant, fixed value Weight = Heavy | Thread Red / Heavy |
| Ink | Quantity by Size: L = 2 | 2 × Ink |
| Back patch | Quantity 0, by Size: L = 1 | used for size L only |

## Features

- **Matched to variant** flag on BOM lines (an extrafield of BOM lines): the line uses a parent product with variants, and the
  variant consumed is the one with the same attribute values as the variant to produce.
- **Fixed values**: for an attribute of the component that the product to produce does not have (e.g. thread weight), or to
  always use the same value.
- **Quantity by variant**: the quantity of any line can depend on one attribute of the variant to produce (0 = line not used).
- **Variants tab** on BOMs: rules of each line, check that every variant can be resolved, preview of the result per variant.
- **MO creation**: one select per attribute to choose the variant to produce, with the list of components it will consume.
  Works also for MOs created by the REST API, for the "Reinitialize" button of MOs, and for variable sub-BOMs.

## Requirements

- Dolibarr 24.0 or higher, with modules BOM, Manufacturing Orders (MRP) and Variants enabled.
- The core hook **`processBOMLines`** in `Mo::processBOM()`. It is proposed to Dolibarr; until it is merged, add it with the
  patch of the `core-patch` directory (see below). Without it, the module refuses MOs of variable BOMs instead of consuming
  the parent products. The setup page of the module shows if the hook is available.

## Installation

1. Add the hook to the core (once, and again after each Dolibarr upgrade until it is merged upstream):
   ```bash
   php core-patch/apply_hook.php /path/to/dolibarr/htdocs
   ```
   The script is idempotent, upgrades the previous version of the hook (module 1.0.0) and keeps the original file as
   `mrp/class/mo.class.php.orig`. Git users can instead apply `core-patch/processBOMLines-24.0.patch` (or `-develop.patch`).

   What the hook changes in the core, so you can judge it before patching: `Mo::processBOM()` gets a hook call
   (`processBOMLines`, context `modao`) before it processes the lines of a BOM, guarded by `is_object($hookmanager)`;
   a protected property `fk_product_bom_processed` tells the hook which product a sub BOM produces (no method signature
   change); the contract of the hook is documented in the method docblock. Like every hook, it adds `modao` to the hook
   contexts of the request, and a module returning an error stops the creation of the MO.
2. Copy the `module` directory to `htdocs/custom/variablebom` (or upload the zip in Home > Setup > Modules > Deploy external module).
3. Enable **Variable BOM** in Home > Setup > Modules.

## Usage

1. Create a BOM for the **parent** product with variants (e.g. "Shirt design").
2. Add the lines. For a component with variants, add its parent product and tick **Matched to variant**.
3. In the **Variants** tab of the BOM, set fixed values and quantities by variant if needed, and check that every variant is resolved.
4. Validate the BOM and click **Create MO**: choose the variant to produce in the selects. The MO consumes the resolved variants.

## Known limitations

- Cloning a BOM does not copy the "Matched to variant" flags nor the rules (the core clone does not copy line extrafields).
- The "To consume" list of the MO creation form of the core shows the lines of the BOM as defined; the resolved components are
  shown under the variant selects.
- Costs of a variable BOM are computed by the core from the parent products of the lines.

## Development

`docker compose up -d` starts Dolibarr 24.0 on http://localhost:8080 (admin / admin) with the hook applied and the module
mounted. End-to-end test (creates demo products, BOMs and MOs):

```bash
docker compose cp dev/test_variablebom.php dolibarr:/tmp/ && docker compose exec -u www-data dolibarr php /tmp/test_variablebom.php
```

Module ID 194281 (range 194281–194309 reserved by Zach Melo). License GPL v3 or later.
