<?php

namespace BitApps\Audit;

defined( 'ABSPATH' ) || exit;

/**
 * Audits the Bit Integrations family (Free + Pro combined).
 *
 * The catalog mirrors what the plugin's own Flow builder offers, so the counts match the product:
 *   - Triggers : the `trigger/list` registry behind SelectTrigger — Free trigger modules whose
 *                controller exposes info(), plus the AllTriggersName Pro catalog. (173)
 *   - Actions  : the SelectAction.jsx `integs` list, each resolved to its backend module. (186)
 *
 * "Total Integrations" sums the two (an app offering both counts on each side); "Platform
 * Integrations" is the unique union. Per-integration trigger/action events are parsed from each
 * module's backend Hooks.php; integrations whose events register dynamically (e.g. Action Hook,
 * or webhook/automation actions with no backend module) surface a single "Dynamic Event".
 */
final class BitIntegrationsAuditor implements AuditorInterface {

	private $tFree;
	private $tPro;
	private $aFree;
	private $aPro;
	private $feBase;
	private $actionDirs;
	private $feDirs;
	private $feLabels = array();
	private $platforms;

	public function __construct() {
		$this->tFree  = Detector::path( 'bit-integrations/backend/Triggers' );
		$this->tPro   = Detector::path( 'bit-integrations-pro/backend/Triggers' );
		$this->aFree  = Detector::path( 'bit-integrations/backend/Actions' );
		$this->aPro   = Detector::path( 'bit-integrations-pro/backend/Actions' );
		$this->feBase = Detector::path( 'bit-integrations/frontend/src/components/AllIntegrations' );
	}

	public function report() {
		$fam     = Detector::detect()['bit-integrations'];
		$catalog = $this->catalog();

		return array(
			'family'    => 'bit-integrations',
			'label'     => 'Bit Integrations',
			'presence'  => array(
				'free'   => $fam['free'],
				'pro'    => $fam['pro'],
				'active' => $fam['active'],
			),
			'catalog'   => $catalog,
			'changelog' => CatalogScanner::resolveChangelogSlugs(
				CatalogScanner::parseChangelogLatest( Detector::path( 'bit-integrations/readme.txt' ) ),
				$catalog['per_integration']
			),
		);
	}

	private function catalog() {
		$platforms     = $this->platforms();
		$trigger_count = 0;
		$action_count  = 0;
		$t_events      = 0;
		$a_events      = 0;
		$t_free        = 0;
		$t_pro         = 0;
		$a_free        = 0;
		$a_pro         = 0;
		$ta_free       = 0;
		$ta_pro        = 0;
		$aa_free       = 0;
		$aa_pro        = 0;
		$rows          = array();

		foreach ( $platforms as $p ) {
			if ( $p['isTrigger'] ) {
				++$trigger_count;
				$t_events                 += $p['trigger_events'];
				$p['triggerPro'] ? $t_pro += $p['trigger_events'] : $t_free += $p['trigger_events'];
				$p['triggerPro'] ? ++$ta_pro : ++$ta_free;
			}
			if ( $p['isAction'] ) {
				++$action_count;
				$a_events += $p['action_events'];
				$a_free   += $p['action_free'];
				$a_pro    += $p['action_pro'];
				// An action app counts as Free when it offers any Free operation, else Pro.
				$p['action_free'] > 0 ? ++$aa_free : ++$aa_pro;
			}
			$rows[] = array(
				'name'           => $p['name'],
				'slug'           => $p['key'],
				'isPro'          => $p['isPro'],
				'tier'           => $p['tier'],
				'isTrigger'      => $p['isTrigger'],
				'isAction'       => $p['isAction'],
				'trigger_events' => $p['trigger_events'],
				'action_events'  => $p['action_events'],
			);
		}

		usort(
			$rows,
			static function ( $x, $y ) {
				return strcasecmp( $x['name'], $y['name'] );
			}
		);

		return array(
			'total_integrations'    => $trigger_count + $action_count, // sum; an app with both counts on each side
			'platform_integrations' => \count( $rows ),                // unique union
			'trigger_apps'          => $trigger_count,
			'action_apps'           => $action_count,
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

	public function events( $key ) {
		$platforms = $this->platforms();
		if ( ! isset( $platforms[ $key ] ) ) {
			return array(
				'name'     => CatalogScanner::humanize( $key ),
				'slug'     => $key,
				'isPro'    => true,
				'found'    => false,
				'triggers' => array(),
				'actions'  => array(),
			);
		}
		$p = $platforms[ $key ];

		$triggers = $p['isTrigger'] ? $this->triggerEventDetails( $p['trigger_slug'], $p['triggerPro'] ) : array();
		$actions  = $p['isAction'] ? $this->actionEventDetails( $p['action_slug'] ) : array();

		return array(
			'name'     => $p['name'],
			'slug'     => $key,
			'isPro'    => $p['isPro'],
			'tier'     => $p['tier'],
			'found'    => ! empty( $triggers ) || ! empty( $actions ),
			'triggers' => $triggers,
			'actions'  => $actions,
		);
	}

	/* ------------------------------------------------------------------ */

	/** Build (once) the unified platform catalog keyed by normalized integration name. */
	private function platforms() {
		if ( null !== $this->platforms ) {
			return $this->platforms;
		}
		$out = array();

		foreach ( $this->triggerRegistry() as $key => $t ) {
			$out[ $key ] = array(
				'key'            => $key,
				'name'           => $t['name'],
				'isTrigger'      => true,
				'isAction'       => false,
				'triggerPro'     => $t['isPro'],
				'trigger_slug'   => $t['slug'],
				'action_slug'    => '',
				'trigger_events' => \count( $this->triggerEventDetails( $t['slug'], $t['isPro'] ) ),
				'action_events'  => 0,
				'action_free'    => 0,
				'action_pro'     => 0,
			);
		}

		foreach ( $this->actionRegistry() as $key => $a ) {
			$events     = $this->actionEventDetails( $a['slug'] );
			$action_pro = 0;
			foreach ( $events as $e ) {
				if ( $e['isPro'] ) {
					++$action_pro;
				}
			}
			$action_free = \count( $events ) - $action_pro;

			if ( isset( $out[ $key ] ) ) {
				$out[ $key ]['isAction']      = true;
				$out[ $key ]['action_slug']   = $a['slug'];
				$out[ $key ]['action_events'] = \count( $events );
				$out[ $key ]['action_free']   = $action_free;
				$out[ $key ]['action_pro']    = $action_pro;
				if ( \strlen( $a['name'] ) > \strlen( $out[ $key ]['name'] ) ) {
					$out[ $key ]['name'] = $a['name'];
				}
			} else {
				$out[ $key ] = array(
					'key'            => $key,
					'name'           => $a['name'],
					'isTrigger'      => false,
					'isAction'       => true,
					'triggerPro'     => false,
					'trigger_slug'   => '',
					'action_slug'    => $a['slug'],
					'trigger_events' => 0,
					'action_events'  => \count( $events ),
					'action_free'    => $action_free,
					'action_pro'     => $action_pro,
				);
			}
		}

		// Tier (Free / Pro / Both) from the events themselves: Pro trigger tier, plus per-operation
		// Pro/Free action events. An integration with both Free and Pro events is "Both".
		foreach ( $out as &$p ) {
			$has_free   = ( $p['isTrigger'] && ! $p['triggerPro'] ) || $p['action_free'] > 0;
			$has_pro    = ( $p['isTrigger'] && $p['triggerPro'] ) || $p['action_pro'] > 0;
			$p['tier']  = ( $has_free && $has_pro ) ? 'both' : ( $has_pro ? 'pro' : 'free' );
			$p['isPro'] = 'pro' === $p['tier'];
		}
		unset( $p );

		$this->platforms = $out;

		return $out;
	}

	/**
	 * Trigger registry = what SelectTrigger lists (the `trigger/list` route): Free trigger modules
	 * whose controller exposes info(), plus the AllTriggersName Pro catalog.
	 *
	 * @return array<string,array{name:string,slug:string,isPro:bool}> keyed by normalized name
	 */
	private function triggerRegistry() {
		$reg = array();

		foreach ( CatalogScanner::listDirs( $this->tFree ) as $slug ) {
			$ctrl = $this->tFree . '/' . $slug . '/' . $slug . 'Controller.php';
			if ( is_file( $ctrl ) && preg_match( '/function\s+info\s*\(/', CatalogScanner::read( $ctrl ) ) ) {
				$name = CatalogScanner::humanize( $slug );
				$reg[ CatalogScanner::normalizeName( $name ) ] = array(
					'name'  => $name,
					'slug'  => $slug,
					'isPro' => false,
				);
			}
		}

		// AllTriggersName is the Pro catalog — every entry is a Pro trigger (its own isPro flag).
		foreach ( CatalogScanner::parseAllTriggers( Detector::path( 'bit-integrations/backend/Core/Util/AllTriggersName.php' ) ) as $row ) {
			$key = CatalogScanner::normalizeName( $row['name'] );
			if ( ! isset( $reg[ $key ] ) ) {
				$reg[ $key ] = array(
					'name'  => $row['name'],
					'slug'  => $row['slug'],
					'isPro' => $row['isPro'],
				);
			}
		}

		return $reg;
	}

	/**
	 * Action registry = the SelectAction.jsx `integs` list, each resolved to its backend module.
	 * Tier is decided per operation from the module source (see actionEventDetails), not here.
	 *
	 * @return array<string,array{name:string,slug:string}> keyed by normalized name
	 */
	private function actionRegistry() {
		$reg = array();
		foreach ( CatalogScanner::parseSelectActionTypes( Detector::path( 'bit-integrations/frontend/src/components/Flow/New/SelectAction.jsx' ) ) as $name ) {
			$key = CatalogScanner::normalizeName( $name );
			if ( isset( $reg[ $key ] ) ) {
				continue;
			}
			$reg[ $key ] = array(
				'name' => $name,
				'slug' => $this->resolveActionSlug( $name ),
			);
		}

		return $reg;
	}

	/** Resolve a SelectAction display name to its backend Actions folder slug ('' if none). */
	private function resolveActionSlug( $name ) {
		$map     = $this->actionDirMap();
		$key     = CatalogScanner::normalizeName( $name );
		$aliases = array( 'licensemanagerforwoocommerce' => 'LMFWC' );

		if ( isset( $aliases[ $key ] ) ) {
			return $aliases[ $key ];
		}
		if ( isset( $map[ $key ] ) ) {
			return $map[ $key ];
		}
		// "Make(Integromat)" / "Brevo(SendinBlue)" — match the parenthetical service name.
		if ( preg_match( '/\(([^)]+)\)/', $name, $m ) ) {
			$inner = CatalogScanner::normalizeName( $m[1] );
			if ( isset( $map[ $inner ] ) ) {
				return $map[ $inner ];
			}
		}
		// Text before the bracket.
		$outer = CatalogScanner::normalizeName( preg_replace( '/\(.*$/', '', $name ) );
		if ( '' !== $outer && isset( $map[ $outer ] ) ) {
			return $map[ $outer ];
		}
		// "WP User Registration" → Registration, "GoHighLevel" → HighLevel: longest dir-name suffix.
		$best = '';
		$len  = 2;
		foreach ( $map as $dk => $slug ) {
			if ( \strlen( $dk ) > $len && substr( $key, -\strlen( $dk ) ) === $dk ) {
				$best = $slug;
				$len  = \strlen( $dk );
			}
		}

		return $best;
	}

	/** Normalized-name => actual folder slug across Free + Pro Actions (Free wins on overlap). */
	private function actionDirMap() {
		if ( null !== $this->actionDirs ) {
			return $this->actionDirs;
		}
		$map = array();
		foreach ( array( $this->aPro, $this->aFree ) as $base ) {
			foreach ( CatalogScanner::listDirs( $base ) as $slug ) {
				$map[ CatalogScanner::normalizeName( $slug ) ] = $slug;
			}
		}
		$this->actionDirs = $map;

		return $map;
	}

	/**
	 * Trigger events for a module: the task list returned by its `{platform}/get` route, which for
	 * static triggers resolves to StaticData::tasks(). Triggers without a static task list fall back
	 * to the concrete WP hooks bound in Hooks.php; a fully dynamic trigger surfaces one "Dynamic Event".
	 *
	 * @return array<int,array{name:string,hook:string,slug:string,group:string,isPro:bool}>
	 */
	private function triggerEventDetails( $slug, $isPro ) {
		$events = array();
		if ( '' !== $slug ) {
			$dir = is_dir( $this->tFree . '/' . $slug ) ? $this->tFree . '/' . $slug
				: ( is_dir( $this->tPro . '/' . $slug ) ? $this->tPro . '/' . $slug : '' );
			if ( '' !== $dir ) {
				// Primary: the {platform}/get task list (StaticData::tasks()).
				foreach ( CatalogScanner::biStaticTaskLabels( $dir . '/StaticData.php' ) as $hook => $label ) {
					$events[] = array(
						'name'  => $label,
						'hook'  => $hook,
						'slug'  => $hook,
						'group' => '',
						'isPro' => (bool) $isPro,
					);
				}
				// Secondary: titles the {platform}/get callback hard-codes (e.g. Academy Lms).
				if ( ! $events ) {
					foreach ( CatalogScanner::biTriggerGetEventNames( $dir ) as $name ) {
						$events[] = array(
							'name'  => $name,
							'hook'  => '',
							'slug'  => '',
							'group' => '',
							'isPro' => (bool) $isPro,
						);
					}
				}
				// Fallback: concrete WP hooks bound in Hooks.php.
				if ( ! $events ) {
					foreach ( CatalogScanner::biTriggerEvents( $dir . '/Hooks.php' ) as $ev ) {
						if ( '' === $ev['hook'] ) {
							continue;
						}
						$events[] = array(
							'name'  => CatalogScanner::humanize( $ev['hook'] ),
							'hook'  => $ev['hook'],
							'slug'  => $ev['hook'],
							'group' => '',
							'isPro' => (bool) $isPro,
						);
					}
				}
			}
		}

		if ( ! $events ) {
			$events[] = $this->dynamicTriggerEvent( $isPro );
		}

		return $events;
	}

	/**
	 * Action events for a module: the operations parsed from its backend RecordApiHelper/Controller
	 * (see CatalogScanner::biActionOperations) — each tagged Free or Pro from its `Config::with(Free)Prefix`
	 * wrapper. An action with no parseable operation is a single Free store-record. Webhook/automation
	 * actions with no backend module surface one "Dynamic Event".
	 *
	 * @return array<int,array{name:string,slug:string,group:string,isPro:bool}>
	 */
	private function actionEventDetails( $slug ) {
		if ( '' !== $slug ) {
			$dir = is_dir( $this->aFree . '/' . $slug ) ? $this->aFree . '/' . $slug
				: ( is_dir( $this->aPro . '/' . $slug ) ? $this->aPro . '/' . $slug : '' );
			if ( '' !== $dir ) {
				$labels = $this->frontendLabels( $slug );
				$events = array();
				foreach ( CatalogScanner::biActionOperations( $dir ) as $op ) {
					$name  = $this->actionOpName( $op, $slug );
					$isPro = (bool) $op['isPro'];
					// The frontend modules list carries the user-facing label (and Pro flag) keyed by
					// the operation value — prefer it over the humanized case slug.
					$fe = $this->frontendLabelFor( $op['key'], $slug, $labels );
					if ( null !== $fe ) {
						$name = $fe['label'];
						if ( null !== $fe['isPro'] ) {
							$isPro = $fe['isPro'];
						}
					}
					$events[] = array(
						'name'  => $name,
						'slug'  => $op['key'],
						'group' => '',
						'isPro' => $isPro,
					);
				}
				if ( ! $events ) {
					// Single execution endpoint with no declared operation — store-record (Free).
					$events[] = array(
						'name'  => __( 'Store Record', 'bit-audit' ),
						'slug'  => $slug,
						'group' => '',
						'isPro' => false,
					);
				}

				return $events;
			}
		}

		// No backend module (e.g. IFTTT, Zapier-style webhook) — dynamic, available on the free tier.
		return array(
			array(
				'name'  => __( 'Dynamic Event', 'bit-audit' ),
				'slug'  => '',
				'group' => '',
				'isPro' => false,
			),
		);
	}

	/** Frontend label entry matching an operation value ('' if none). Tries the slug-stripped key too. */
	private function frontendLabelFor( $key, $slug, array $labels ) {
		if ( ! $labels ) {
			return null;
		}
		$nk         = CatalogScanner::normalizeName( $key );
		$candidates = array( $nk );
		$slug_key   = CatalogScanner::normalizeName( $slug );
		if ( '' !== $slug_key && 0 === strpos( $nk, $slug_key ) ) {
			$candidates[] = substr( $nk, \strlen( $slug_key ) );
		}
		foreach ( $candidates as $c ) {
			if ( '' !== $c && isset( $labels[ $c ] ) ) {
				return $labels[ $c ];
			}
		}

		return null;
	}

	/** Frontend modules value => label/isPro map for an action, resolved by its folder. */
	private function frontendLabels( $slug ) {
		$key = CatalogScanner::normalizeName( $slug );
		if ( isset( $this->feLabels[ $key ] ) ) {
			return $this->feLabels[ $key ];
		}
		$dirs                   = $this->feDirMap();
		$this->feLabels[ $key ] = isset( $dirs[ $key ] ) ? CatalogScanner::frontendActionLabels( $dirs[ $key ] ) : array();

		return $this->feLabels[ $key ];
	}

	/** Normalized frontend integration folder name => absolute path. */
	private function feDirMap() {
		if ( null !== $this->feDirs ) {
			return $this->feDirs;
		}
		$map = array();
		foreach ( CatalogScanner::listDirs( $this->feBase ) as $folder ) {
			$map[ CatalogScanner::normalizeName( $folder ) ] = $this->feBase . '/' . $folder;
		}
		$this->feDirs = $map;

		return $map;
	}

	/** Human operation label: humanize the key, dropping the integration's own slug prefix. */
	private function actionOpName( $op, $slug ) {
		$label = CatalogScanner::humanize( $op['key'] );
		if ( ! empty( $op['fromHook'] ) ) {
			$slug_key = CatalogScanner::normalizeName( $slug );
			$words    = explode( ' ', $label );
			$acc      = '';
			$cut      = 0;
			foreach ( $words as $idx => $word ) {
				$acc .= strtolower( $word );
				if ( $acc === $slug_key ) {
					$cut = $idx + 1;
					break;
				}
				if ( \strlen( $acc ) >= \strlen( $slug_key ) ) {
					break;
				}
			}
			if ( $cut > 0 && $cut < \count( $words ) ) {
				$label = implode( ' ', \array_slice( $words, $cut ) );
			}
		}

		return '' !== $label ? $label : CatalogScanner::humanize( $op['key'] );
	}

	/** @return array{name:string,hook:string,slug:string,group:string,isPro:bool} */
	private function dynamicTriggerEvent( $isPro ) {
		return array(
			'name'  => __( 'Dynamic Event', 'bit-audit' ),
			'hook'  => '',
			'slug'  => '',
			'group' => '',
			'isPro' => (bool) $isPro,
		);
	}
}
