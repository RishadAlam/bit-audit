<?php
/**
 * Plugin Name:       Bit Audit
 * Plugin URI:        https://bitapps.pro
 * Description:        Audits the Bit ecosystem. Pick a plugin (Bit Integrations or Bit Flows), combine its Free + Pro, and report total/platform integrations, triggers and actions, and per-event detail with Free/Pro/Both tiers. Reads the catalog from a locally installed source checkout of the plugin.
 * Version:           1.1.1
 * Author:            Bit Apps
 * Author URI:        https://bitapps.pro
 * Requires at least: 5.6
 * Requires PHP:      7.4
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       bit-audit
 * Domain Path:       /languages
 *
 * @package BitApps\Audit
 */

namespace BitApps\Audit;

defined( 'ABSPATH' ) || exit;

define( 'BIT_AUDIT_VERSION', '1.1.1' );
define( 'BIT_AUDIT_FILE', __FILE__ );
define( 'BIT_AUDIT_DIR', plugin_dir_path( __FILE__ ) );
define( 'BIT_AUDIT_URL', plugin_dir_url( __FILE__ ) );

/*
 * Autoloading: prefer the Composer autoloader; fall back to a minimal PSR-4 loader so the plugin
 * still runs from a checkout where `composer install` has not been executed.
 */
if ( is_readable( BIT_AUDIT_DIR . 'vendor/autoload.php' ) ) {
	require_once BIT_AUDIT_DIR . 'vendor/autoload.php';
} else {
	spl_autoload_register(
		static function ( $class ) {
			$prefix = __NAMESPACE__ . '\\';
			if ( strpos( $class, $prefix ) !== 0 ) {
				return;
			}
			$path = BIT_AUDIT_DIR . 'includes/' . str_replace( '\\', '/', substr( $class, \strlen( $prefix ) ) ) . '.php';
			if ( is_readable( $path ) ) {
				require_once $path;
			}
		}
	);
}

add_action(
	'init',
	static function () {
		load_plugin_textdomain( 'bit-audit', false, dirname( plugin_basename( BIT_AUDIT_FILE ) ) . '/languages' );
	}
);

add_action(
	'admin_menu',
	static function () {
		add_menu_page(
			__( 'Bit Audit', 'bit-audit' ),
			__( 'Bit Audit', 'bit-audit' ),
			'manage_options',
			'bit-audit',
			array( AdminPage::class, 'render' ),
			'dashicons-chart-area',
			81
		);
	}
);

add_action( 'admin_enqueue_scripts', array( AdminPage::class, 'enqueue' ) );
add_action( 'wp_ajax_bit_audit_report', array( AdminPage::class, 'ajax' ) );
add_action( 'admin_post_bit_audit_export', array( Exporter::class, 'handle' ) );
add_action( 'admin_post_bit_audit_refresh', array( AdminPage::class, 'refresh' ) );
