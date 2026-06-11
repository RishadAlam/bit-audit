<?php

namespace BitApps\Audit;

defined( 'ABSPATH' ) || exit;

/**
 * Locates the Bit families on disk and reports free/pro presence + active state.
 *
 * Directories are resolved by the plugin's TEXT DOMAIN, not a hard-coded folder name, so an install
 * under a non-standard folder (e.g. `bit-integrations-main` from a GitHub "Download ZIP") is still
 * found. The default folder name is used as a fallback when the plugin isn't registered yet.
 */
final class Detector {

	/**
	 * @var array<string,array{
	 *   label:string, free_domain:string, free_default:string, free_main:string,
	 *   pro_domain:string, pro_default:string, pro_main:string, pro_subdir:string
	 * }>
	 */
	public const FAMILIES = array(
		'bit-integrations' => array(
			'label'        => 'Bit Integrations',
			'free_domain'  => 'bit-integrations',
			'free_default' => 'bit-integrations',
			'free_main'    => 'bitwpfi.php',
			'pro_domain'   => 'bit-integrations-pro',
			'pro_default'  => 'bit-integrations-pro',
			'pro_main'     => 'bitwpfi.php',
			'pro_subdir'   => '', // Pro is a separate plugin.
		),
		'bit-pi'           => array(
			'label'        => 'Bit Flows',
			'free_domain'  => 'bit-pi',
			'free_default' => 'bit-pi',
			'free_main'    => 'bit-pi.php',
			'pro_domain'   => '',
			'pro_default'  => '',
			'pro_main'     => 'bit-pi-pro.php',
			'pro_subdir'   => 'pro', // Pro lives in <free>/pro.
		),
	);

	/** @var array<string,string>|null TextDomain => plugin directory name. */
	private static $domainMap = null;

	/** Absolute path inside wp-content/plugins. */
	public static function path( $relative ) {
		return rtrim( WP_PLUGIN_DIR, '/\\' ) . '/' . ltrim( $relative, '/\\' );
	}

	/** Resolved Free plugin directory name for a family (e.g. "bit-integrations" or "bit-integrations-main"). */
	public static function freeDir( $family ) {
		$meta = self::FAMILIES[ $family ];
		$map  = self::domainMap();

		return isset( $map[ $meta['free_domain'] ] ) ? $map[ $meta['free_domain'] ] : $meta['free_default'];
	}

	/** Resolved Pro plugin directory name for a family (a sibling plugin, or a sub-directory of Free). */
	public static function proDir( $family ) {
		$meta = self::FAMILIES[ $family ];
		if ( '' !== $meta['pro_subdir'] ) {
			return self::freeDir( $family ) . '/' . $meta['pro_subdir'];
		}
		$map = self::domainMap();

		return isset( $map[ $meta['pro_domain'] ] ) ? $map[ $meta['pro_domain'] ] : $meta['pro_default'];
	}

	/** Absolute path inside the family's resolved Free plugin directory. */
	public static function freePath( $family, $relative = '' ) {
		return self::path( self::freeDir( $family ) . ( '' !== $relative ? '/' . ltrim( $relative, '/\\' ) : '' ) );
	}

	/** Absolute path inside the family's resolved Pro plugin directory. */
	public static function proPath( $family, $relative = '' ) {
		return self::path( self::proDir( $family ) . ( '' !== $relative ? '/' . ltrim( $relative, '/\\' ) : '' ) );
	}

	/** @return array<string,array{label:string,free:bool,pro:bool,active:bool}> */
	public static function detect() {
		$out = array();
		foreach ( self::FAMILIES as $key => $meta ) {
			$free_dir  = self::freeDir( $key );
			$pro_dir   = self::proDir( $key );
			$free_main = $free_dir . '/' . $meta['free_main'];
			$pro_main  = $pro_dir . '/' . $meta['pro_main'];

			$out[ $key ] = array(
				'label'  => $meta['label'],
				'free'   => is_dir( self::path( $free_dir ) ) && file_exists( self::path( $free_main ) ),
				'pro'    => is_dir( self::path( $pro_dir ) ) && file_exists( self::path( $pro_main ) ),
				'active' => self::isActive( $free_main ),
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
		if ( 'bit-pi' === $family ) {
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
		// Always build fresh while developing so source edits show immediately; cache only in production.
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			return self::auditor( $family )->report();
		}
		$key    = self::cacheKey( $family );
		$cached = get_transient( $key );
		if ( \is_array( $cached ) ) {
			return $cached;
		}
		$report = self::auditor( $family )->report();
		// Never cache an unavailable report (e.g. not installed / source not found) — it would persist
		// after the cause is fixed and Refresh is clicked.
		if ( isset( $report['available'] ) && ! $report['available'] ) {
			return $report;
		}
		set_transient( $key, $report, 10 * MINUTE_IN_SECONDS );

		return $report;
	}

	/** Drop every cached report (used by the Refresh action and on uninstall). */
	public static function flush() {
		foreach ( array_keys( self::FAMILIES ) as $family ) {
			delete_transient( self::cacheKey( $family ) );
		}
	}

	/** TextDomain => directory name for every installed plugin (resolved once per request). */
	private static function domainMap() {
		if ( null !== self::$domainMap ) {
			return self::$domainMap;
		}
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$map = array();
		if ( function_exists( 'get_plugins' ) ) {
			foreach ( get_plugins() as $file => $data ) {
				$dir    = false !== strpos( $file, '/' ) ? dirname( $file ) : '';
				$domain = isset( $data['TextDomain'] ) ? $data['TextDomain'] : '';
				if ( '' !== $dir && '' !== $domain && ! isset( $map[ $domain ] ) ) {
					$map[ $domain ] = $dir;
				}
			}
		}

		return self::$domainMap = $map;
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
