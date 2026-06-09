<?php

namespace BitApps\Audit;

defined( 'ABSPATH' ) || exit;

/**
 * Audits the Bit Flows family (Free + Pro combined). (Folder/key remain `bit-pi`.)
 *
 * Catalog source: frontend machine root files
 *   bit-pi/frontend/src/services/machines/<app>/_<app>Machines.ts
 * which carry the full Free+Pro catalog; each entry's `isPro` flag splits the tiers.
 * Per-event WP hooks come from the backend `<Name>Hooks.php` register() arrays.
 */
final class BitPiAuditor implements AuditorInterface {

	private $machinesDir;
	private $backendDirs;
	private $hookMap;

	public function __construct() {
		$this->machinesDir = Detector::path( 'bit-pi/frontend/src/services/machines' );
		$this->backendDirs = array(
			Detector::path( 'bit-pi/backend/app/src/Integrations' ),
			Detector::path( 'bit-pi/pro/backend/app/src/Integrations' ),
		);
	}

	public function report() {
		$fam     = Detector::detect()['bit-pi'];
		$catalog = $this->catalog();

		return array(
			'family'    => 'bit-pi',
			'label'     => 'Bit Flows',
			'presence'  => array(
				'free'   => $fam['free'],
				'pro'    => $fam['pro'],
				'active' => $fam['active'],
			),
			'catalog'   => $catalog,
			'changelog' => CatalogScanner::resolveChangelogSlugs(
				CatalogScanner::parseChangelogLatest( Detector::path( 'bit-pi/readme.txt' ) ),
				$catalog['per_integration']
			),
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
			'total_integrations'    => $triggerApps + $actionApps, // sum; an app with both counts on each side
			'platform_integrations' => $total,                     // unique union (one row per app)
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
		$file = $this->rootFileForSlug( $slug );
		$out  = array(
			'name'     => CatalogScanner::humanize( $slug ),
			'slug'     => $slug,
			'isPro'    => false,
			'found'    => false,
			'triggers' => array(),
			'actions'  => array(),
		);
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

	/** Build (once) a global machineSlug => hook map from every backend Hooks.php register(). */
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
