<?php

namespace BitApps\Audit;

defined( 'ABSPATH' ) || exit;

/**
 * Audits the Bit Flows family (Free + Pro combined). (Folder/key remain `bit-pi`.)
 *
 * Catalog source: the frontend machine root files
 *   bit-pi/frontend/src/services/machines/<app>/_<app>Machines.ts
 * which carry the full Free+Pro catalog (each entry's `isPro` flag splits the tiers), read from the
 * locally installed plugin (a source checkout). Per-event WP hooks come from the locally installed
 * backend `<Name>Hooks.php` register() arrays. A built/minified release strips the frontend, so if it
 * is absent report() returns an `available => false` sentinel for the dashboard to surface.
 */
final class BitPiAuditor implements AuditorInterface {

	private $machinesDir;
	private $backendDirs;
	private $hookMap;
	private $sourceError;
	private $resolved = false;

	public function __construct() {
		// Backend = locally installed plugin (ships its PHP). Dirs resolved by text domain.
		$this->backendDirs = array(
			Detector::freePath( 'bit-pi', 'backend/app/src/Integrations' ),
			Detector::proPath( 'bit-pi', 'backend/app/src/Integrations' ),
		);
	}

	/** Locate the frontend machine source on disk (once); records an error for report() if absent. */
	private function resolveSource() {
		if ( $this->resolved ) {
			return;
		}
		$this->resolved = true;
		$dir            = Detector::freePath( 'bit-pi', 'frontend/src/services/machines' );
		if ( ! is_dir( $dir ) ) {
			$this->sourceError = new \WP_Error(
				'no_source',
				__( 'Bit Flows frontend source was not found. Install it as a source checkout (git clone) — a built release strips the frontend.', 'bit-audit' )
			);

			return;
		}
		$this->machinesDir = $dir;
	}

	public function report() {
		$fam      = Detector::detect()['bit-pi'];
		$presence = array(
			'free'   => $fam['free'],
			'pro'    => $fam['pro'],
			'active' => $fam['active'],
		);
		// A complete audit needs BOTH the Free plugin (catalog machines) and Pro (pro app backends).
		// Report each individually. (Active state not required — everything is read from disk.)
		if ( ! $fam['free'] ) {
			return $this->unavailable( $presence, 'free_missing', __( 'Bit Flows (Free) is not installed. Install it to run the audit.', 'bit-audit' ) );
		}
		if ( ! $fam['pro'] ) {
			return $this->unavailable( $presence, 'pro_missing', __( 'Bit Flows Pro is not installed. Both Free and Pro are required for a complete audit.', 'bit-audit' ) );
		}

		$this->resolveSource();
		if ( $this->sourceError ) {
			return $this->unavailable( $presence, $this->sourceError->get_error_code(), $this->sourceError->get_error_message() );
		}

		$catalog = $this->catalog();

		return array(
			'family'    => 'bit-pi',
			'label'     => 'Bit Flows',
			'presence'  => $presence,
			'available' => true,
			'catalog'   => $catalog,
			'changelog' => CatalogScanner::resolveChangelogSlugs(
				CatalogScanner::parseChangelogLatest( Detector::freePath( 'bit-pi', 'readme.txt' ) ),
				$catalog['per_integration']
			),
		);
	}

	/** @return array<string,mixed> an unavailable-report sentinel for the dashboard to surface. */
	private function unavailable( array $presence, $reason, $message ) {
		return array(
			'family'    => 'bit-pi',
			'label'     => 'Bit Flows',
			'presence'  => $presence,
			'available' => false,
			'reason'    => $reason,
			'message'   => $message,
			'catalog'   => null,
			'changelog' => null,
		);
	}

	private function rootFiles() {
		$roots = CatalogScanner::findFiles( $this->machinesDir, 'Machines.ts' );

		return array_values(
			array_filter(
				$roots,
				static function ( $p ) {
					$base = basename( $p );
					return '_' === $base[0] && 'allApps.ts' !== $base;
				}
			)
		);
	}

	private function catalog() {
		$total       = 0;
		$triggerApps = 0;
		$actionApps  = 0;
		$t_events    = 0;
		$a_events    = 0;
		$t_free      = 0;
		$t_pro       = 0;
		$a_free      = 0;
		$a_pro       = 0;
		$ta_free     = 0;
		$ta_pro      = 0;
		$aa_free     = 0;
		$aa_pro      = 0;
		$rows        = array();

		foreach ( $this->rootFiles() as $file ) {
			$root = CatalogScanner::parsePiRoot( CatalogScanner::read( $file ) );
			if ( '' === $root['slug'] && ! $root['entries'] ) {
				continue;
			}
			++$total;

			$t            = 0;
			$a            = 0;
			$pro_count    = 0;
			$has_free_trg = false;
			$has_free_act = false;
			foreach ( $root['entries'] as $e ) {
				if ( $e['isPro'] ) {
					++$pro_count;
				}
				if ( 'trigger' === $e['type'] ) {
					++$t;
					++$t_events;
					$e['isPro'] ? $t_pro++ : $t_free++;
					$has_free_trg = $has_free_trg || ! $e['isPro'];
				} else {
					++$a;
					++$a_events;
					$e['isPro'] ? $a_pro++ : $a_free++;
					$has_free_act = $has_free_act || ! $e['isPro'];
				}
			}
			if ( $t > 0 ) {
				++$triggerApps;
				$has_free_trg ? ++$ta_free : ++$ta_pro;
			}
			if ( $a > 0 ) {
				++$actionApps;
				$has_free_act ? ++$aa_free : ++$aa_pro;
			}

			$rows[] = array(
				'name'           => $root['name'],
				'slug'           => $root['slug'],
				'isPro'          => $pro_count > 0 && $pro_count === \count( $root['entries'] ),
				'trigger_events' => $t,
				'action_events'  => $a,
			);
		}

		usort(
			$rows,
			static function ( $x, $y ) {
				return strcasecmp( $x['name'], $y['name'] );
			}
		);

		return array(
			'total_integrations'    => $triggerApps + $actionApps,
			'platform_integrations' => $total,
			'trigger_apps'          => $triggerApps,
			'action_apps'           => $actionApps,
			'apps'                  => array(
				'trigger' => array(
					'free' => $ta_free,
					'pro'  => $ta_pro,
				),
				'action'  => array(
					'free' => $aa_free,
					'pro'  => $aa_pro,
				),
			),
			'total_trigger_events'  => $t_events,
			'total_action_events'   => $a_events,
			'split'                 => array(
				'free' => array(
					'trigger_events' => $t_free,
					'action_events'  => $a_free,
				),
				'pro'  => array(
					'trigger_events' => $t_pro,
					'action_events'  => $a_pro,
				),
			),
			'per_integration'       => $rows,
		);
	}

	public function events( $slug ) {
		$out = array(
			'name'     => CatalogScanner::humanize( $slug ),
			'slug'     => $slug,
			'isPro'    => false,
			'found'    => false,
			'triggers' => array(),
			'actions'  => array(),
		);
		$d = Detector::detect()['bit-pi'];
		if ( empty( $d['free'] ) || empty( $d['pro'] ) ) {
			return $out;
		}
		$this->resolveSource();
		if ( $this->sourceError ) {
			return $out;
		}
		$file = $this->rootFileForSlug( $slug );
		if ( '' === $file ) {
			return $out;
		}

		$root         = CatalogScanner::parsePiRoot( CatalogScanner::read( $file ) );
		$out['found'] = true;
		$out['name']  = $root['name'];
		$hooks        = $this->hookMap();
		$pro_count    = 0;

		foreach ( $root['entries'] as $e ) {
			if ( $e['isPro'] ) {
				++$pro_count;
			}
			if ( 'trigger' === $e['type'] ) {
				$out['triggers'][] = array(
					'name'  => $e['name'] ? $e['name'] : CatalogScanner::humanize( $e['slug'] ),
					'hook'  => isset( $hooks[ $e['slug'] ] ) ? $hooks[ $e['slug'] ] : '',
					'slug'  => $e['slug'],
					'group' => $e['group'],
					'isPro' => $e['isPro'],
				);
			} else {
				$out['actions'][] = array(
					'name'  => $e['name'] ? $e['name'] : CatalogScanner::humanize( $e['slug'] ),
					'slug'  => $e['slug'],
					'group' => $e['group'],
					'isPro' => $e['isPro'],
				);
			}
		}
		$out['isPro'] = $pro_count > 0 && $pro_count === \count( $root['entries'] );

		return $out;
	}

	private function rootFileForSlug( $slug ) {
		foreach ( $this->rootFiles() as $file ) {
			$root = CatalogScanner::parsePiRoot( CatalogScanner::read( $file ) );
			if ( $slug === $root['slug'] ) {
				return $file;
			}
		}

		return '';
	}

	/** Build (once) a global machineSlug => hook map from every locally installed Hooks.php register(). */
	private function hookMap() {
		if ( null !== $this->hookMap ) {
			return $this->hookMap;
		}
		$this->hookMap = array();
		foreach ( $this->backendDirs as $dir ) {
			foreach ( CatalogScanner::findFiles( $dir, 'Hooks.php' ) as $file ) {
				foreach ( CatalogScanner::piHookMap( $file ) as $slug => $hook ) {
					$this->hookMap[ $slug ] = $hook;
				}
			}
		}

		return $this->hookMap;
	}
}
