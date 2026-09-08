<?php
/**
 * Plugin Name: FX UberMenu Migrator
 * Plugin URI: https://www.webfx.com
 * Description: Export and import UberMenu menus (including menu segments and per-item settings) between sites, with smart URL remapping.
 * Version: 1.0.0
 * Author: The WebFX Team
 * Author URI: https://www.webfx.com
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Text Domain: fx-ubermenu-migrator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'FX_UBERMENU_MIGRATOR_VERSION', '1.0.0' );
define( 'FX_UBERMENU_MIGRATOR_FILE', __FILE__ );
define( 'FX_UBERMENU_MIGRATOR_PATH', plugin_dir_path( __FILE__ ) );
define( 'FX_UBERMENU_MIGRATOR_URL', plugin_dir_url( __FILE__ ) );
define( 'FX_UBERMENU_MIGRATOR_FORMAT', 'fx-ubermenu-pack' );

require_once FX_UBERMENU_MIGRATOR_PATH . 'includes/class-url-resolver.php';
require_once FX_UBERMENU_MIGRATOR_PATH . 'includes/class-exporter.php';
require_once FX_UBERMENU_MIGRATOR_PATH . 'includes/class-importer.php';
require_once FX_UBERMENU_MIGRATOR_PATH . 'includes/class-admin.php';

/**
 * Bootstrap the plugin.
 *
 * @return void
 */
function fx_ubermenu_migrator_init() {
	FX_UberMenu_Migrator_Admin::instance();

	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		require_once FX_UBERMENU_MIGRATOR_PATH . 'includes/class-cli.php';
		WP_CLI::add_command( 'fx-ubermenu', 'FX_UberMenu_Migrator_CLI' );
	}
}
add_action( 'plugins_loaded', 'fx_ubermenu_migrator_init' );
