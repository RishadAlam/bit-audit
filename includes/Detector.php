<?php

namespace BitApps\Audit;

defined( 'ABSPATH' ) || exit;

/**
 * Locates the Bit families on disk and reports free/pro presence + active state.
 * Everything is path/DB based so it works even when the target plugins are inactive.
 */
final class Detector {

	public const FAMILIES = array(
		'bit-integrations' => array(
			'label'     => 'Bit Integrations',
			'free_dir'  => 'bit-integrations',
			'free_main' => 'bit-integrations/bitwpfi.php',
			'pro_dir'   => 'bit-integrations-pro',
			'pro_main'  => 'bit-integrations-pro/bitwpfi.php',
		),
		'bit-pi'           => array(
			'label'     => 'Bit Flows',
			'free_dir'  => 'bit-pi',
			'free_main' => 'bit-pi/bit-pi.php',
			'pro_dir'   => 'bit-pi/pro',
			'pro_main'  => 'bit-pi/pro',
		),
	);

	/** Absolute path inside wp-content/plugins. */
	public static function path( $relative ) {
		return rtrim( WP_PLUGIN_DIR, '/\\' ) . '/' . ltrim( $relative, '/\\' );
	}

	/** @return array<string,array{label:string,free:bool,pro:bool,active:bool}> */
	public static function detect() {
		$out = array();
		foreach ( self::FAMILIES as $key => $meta ) {
			$freeMainPath = self::path( $meta['free_main'] );
			$proPath      = self::path( $meta['pro_dir'] );
			$free         = is_dir( self::path( $meta['free_dir'] ) ) && file_exists( $freeMainPath );
			// Pro for bit-pi is a directory (symlinked); for bit-integrations a plugin file.
			$pro = is_dir( $proPath ) && file_exists( self::path( $meta['pro_main'] ) );

			$out[ $key ] = array(
				'label'  => $meta['label'],
				'free'   => $free,
				'pro'    => $pro,
				'active' => self::isActive( $meta['free_main'] ),
			);
		}

		return $out;
	}

	/** Default family = first one whose free side is present. */
	public static function defaultFamily() {
		foreach ( self::detect() as $key => $f ) {
			if ( $f['free'] ) {
				return $key;
			}
		}

		return 'bit-integrations';
	}

	/** Build the auditor for a family key. */
	public static function auditor( $family ) {
		if ( $family === 'bit-pi' ) {
			return new BitPiAuditor();
		}

		return new BitIntegrationsAuditor();
	}

	/**
	 * Cached family report. Building a report parses hundreds of source files, so the result is
	 * stored in a transient (keyed by plugin version) and refreshed on demand via flush().
	 *
	 * @return array<string,mixed>
	 */
	public static function report( $family ) {
		if ( ! isset( self::FAMILIES[ $family ] ) ) {
			$family = self::defaultFamily();
		}
		$key    = self::cacheKey( $family );
		$cached = get_transient( $key );
		if ( \is_array( $cached ) ) {
			return $cached;
		}
		$report = self::auditor( $family )->report();
		set_transient( $key, $report, 6 * HOUR_IN_SECONDS );

		return $report;
	}

	/** Drop every cached report (used by the Refresh action and on uninstall). */
	public static function flush() {
		foreach ( array_keys( self::FAMILIES ) as $family ) {
			delete_transient( self::cacheKey( $family ) );
		}
	}

	private static function cacheKey( $family ) {
		return 'bit_audit_rpt_' . sanitize_key( $family ) . '_' . substr( md5( BIT_AUDIT_VERSION ), 0, 8 );
	}

	private static function isActive( $mainFile ) {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return \function_exists( 'is_plugin_active' ) ? is_plugin_active( $mainFile ) : false;
	}
}
