# FX UberMenu Migrator

Export and import UberMenu menus between environments (local -> staging/prod).

## Features

- Exports the selected nav menu plus any UberMenu **menu segments** it references
- Includes per-item `_ubermenu_settings` and `_ubermenu_custom_item_type`
- Optionally exports/merges `ubermenu_main` / `ubermenu_general` and theme locations
- On import, rewrites URLs to the destination site home
- If a page/post exists at the same path, attaches that object; otherwise creates a custom link

## Usage (Admin)

1. Activate the plugin on both sites
2. On the source site, open **Appearance > UberMenu Migrator**, click the main
   menu row (search/filter if the list is long), and download the export.
   Referenced UberMenu segments are packed automatically.
3. On the destination site, drop the JSON onto the upload box. Leave **Dry run**
   checked and press **Preview import (dry run)** to read the report.
4. Uncheck **Dry run** and run the import for real.

While an import runs, the screen shows a progress bar with a live percentage as the
importer works through menus, items, settings, and theme locations. The report
appears in place when it finishes, without a page reload.

Upload size is capped by PHP (`min(upload_max_filesize, post_max_size)`), not by this
plugin. The import panel prints the effective limit and rejects an oversized pack in
the browser; use WP-CLI for anything larger.

## Usage (WP-CLI)

```bash
wp fx-ubermenu export --menu="Main Menu" --file=main-menu.json
wp fx-ubermenu import --file=main-menu.json --dry-run
wp fx-ubermenu import --file=main-menu.json
```

## Notes

- Media / promo images are not packed; absolute image URLs are rewritten to the destination host when present in custom content HTML
- License keys in UberMenu options are preserved on import
