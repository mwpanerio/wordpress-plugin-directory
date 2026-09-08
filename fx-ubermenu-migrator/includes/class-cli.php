<?php
/**
 * WP-CLI commands for FX UberMenu Migrator.
 *
 * @package FX_UberMenu_Migrator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * wp fx-ubermenu export|import
 */
class FX_UberMenu_Migrator_CLI {

	/**
	 * Export a menu (and segments) to JSON.
	 *
	 * ## OPTIONS
	 *
	 * [--menu=<menu>]
	 * : Menu name, slug, or term ID. Default: Main Menu
	 *
	 * [--file=<path>]
	 * : Output file path. Prints to STDOUT if omitted.
	 *
	 * ## EXAMPLES
	 *
	 *     wp fx-ubermenu export --menu="Main Menu" --file=main-menu.json
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Assoc args.
	 * @return void
	 */
	public function export( $args, $assoc_args ) {
		$menu_ref = isset( $assoc_args['menu'] ) ? $assoc_args['menu'] : 'Main Menu';
		$menu_id  = $this->resolve_menu_id( $menu_ref );
		if ( ! $menu_id ) {
			WP_CLI::error( 'Menu not found: ' . $menu_ref );
		}

		$exporter = new FX_UberMenu_Migrator_Exporter();
		$pack     = $exporter->export( $menu_id );
		$json     = wp_json_encode( $pack, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

		if ( ! empty( $assoc_args['file'] ) ) {
			$path = $assoc_args['file'];
			$bytes = file_put_contents( $path, $json ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			if ( false === $bytes ) {
				WP_CLI::error( 'Could not write file: ' . $path );
			}
			WP_CLI::success( sprintf( 'Exported %d menu(s) to %s', count( $pack['menus'] ), $path ) );
			return;
		}

		WP_CLI::line( $json );
	}

	/**
	 * Import a pack JSON file.
	 *
	 * ## OPTIONS
	 *
	 * --file=<path>
	 * : Path to the JSON export file.
	 *
	 * [--dry-run]
	 * : Report only; do not write.
	 *
	 * [--no-replace]
	 * : Do not replace menus with the same name; create dated copies.
	 *
	 * [--skip-options]
	 * : Do not merge UberMenu options.
	 *
	 * [--skip-locations]
	 * : Do not assign theme locations.
	 *
	 * ## EXAMPLES
	 *
	 *     wp fx-ubermenu import --file=main-menu.json --dry-run
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Assoc args.
	 * @return void
	 */
	public function import( $args, $assoc_args ) {
		if ( empty( $assoc_args['file'] ) ) {
			WP_CLI::error( 'Please pass --file=path/to/export.json' );
		}

		$path = $assoc_args['file'];
		if ( ! file_exists( $path ) ) {
			WP_CLI::error( 'File not found: ' . $path );
		}

		$raw = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$pack = json_decode( $raw, true );
		if ( ! is_array( $pack ) ) {
			WP_CLI::error( 'Invalid JSON in ' . $path );
		}

		$importer = new FX_UberMenu_Migrator_Importer(
			array(
				'dry_run'          => isset( $assoc_args['dry-run'] ),
				'replace_existing' => ! isset( $assoc_args['no-replace'] ),
				'import_options'   => ! isset( $assoc_args['skip-options'] ),
				'import_locations' => ! isset( $assoc_args['skip-locations'] ),
			)
		);

		$result = $importer->import( $pack );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		foreach ( $result['report'] as $row ) {
			$msg = $row['message'] ?? '';
			$status = $row['status'] ?? '';
			$line = $status ? "[{$status}] {$msg}" : $msg;
			WP_CLI::log( $line );
		}

		WP_CLI::success( ! empty( $result['dry_run'] ) ? 'Dry run complete.' : 'Import complete.' );
	}

	/**
	 * Resolve menu name/slug/ID to term ID.
	 *
	 * @param string $ref Menu reference.
	 * @return int
	 */
	protected function resolve_menu_id( $ref ) {
		if ( is_numeric( $ref ) ) {
			$term = get_term( (int) $ref, 'nav_menu' );
			return ( $term && ! is_wp_error( $term ) ) ? (int) $term->term_id : 0;
		}

		$term = get_term_by( 'name', $ref, 'nav_menu' );
		if ( $term ) {
			return (int) $term->term_id;
		}

		$term = get_term_by( 'slug', sanitize_title( $ref ), 'nav_menu' );
		return $term ? (int) $term->term_id : 0;
	}
}
