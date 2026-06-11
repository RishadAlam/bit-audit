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
 * Source: everything is read from the locally installed plugin (a source checkout). The FRONTEND
 * catalog source (SelectAction.jsx + the per-integration `modules` lists) provides the action list,
 * operation labels and tiers; the BACKEND (trigger task lists, action operations) provides event
 * detail. A built/minified release strips the frontend, so if it is absent report() returns an
 * `available => false` sentinel for the dashboard to surface.
 */
final class BitIntegrationsAuditor implements AuditorInterface {

	private $tFree;
	private $tPro;
	private $aFree;
	private $aPro;
	private $feBase;
	private $feSelectAction;
	private $sourceError;
	private $resolved = false;
	private $actionDirs;
	private $feDirs;
	private $feLabels  = array();
	private $feModules = array();
	private $platforms;

	public function __construct() {
		// Backend = locally installed plugin (ships its PHP). Dirs resolved by text domain.
		$this->tFree = Detector::freePath( 'bit-integrations', 'backend/Triggers' );
		$this->tPro  = Detector::proPath( 'bit-integrations', 'backend/Triggers' );
		$this->aFree = Detector::freePath( 'bit-integrations', 'backend/Actions' );
		$this->aPro  = Detector::proPath( 'bit-integrations', 'backend/Actions' );
	}

	/** Locate the frontend catalog source on disk (once); records an error for report() if absent. */
	private function resolveSource() {
		if ( $this->resolved ) {
			return;
		}
		$this->resolved = true;
		$base           = Detector::freePath( 'bit-integrations', 'frontend/src/components' );
		if ( ! is_dir( $base . '/AllIntegrations' ) ) {
			$this->sourceError = new \WP_Error(
				'no_source',
				__( 'Bit Integrations frontend source was not found. Install it as a source checkout (git clone) — a built release strips the frontend.', 'bit-audit' )
			);

			return;
		}
		$this->feBase         = $base . '/AllIntegrations';
		$this->feSelectAction = $base . '/Flow/New/SelectAction.jsx';
	}

	public function report() {
		$fam      = Detector::detect()['bit-integrations'];
		$presence = array(
			'free'   => $fam['free'],
			'pro'    => $fam['pro'],
			'active' => $fam['active'],
		);
		// A complete audit needs BOTH plugins installed: Free ships the catalog + free modules, Pro ships
		// the pro modules' backend (pro trigger events, pro action operations). Report each individually.
		// (Active state is not required — everything is read from the files on disk.)
		if ( ! $fam['free'] ) {
			return $this->unavailable( $presence, 'free_missing', __( 'Bit Integrations (Free) is not installed. Install it to run the audit.', 'bit-audit' ) );
		}
		if ( ! $fam['pro'] ) {
			return $this->unavailable( $presence, 'pro_missing', __( 'Bit Integrations Pro is not installed. Both Free and Pro are required for a complete audit.', 'bit-audit' ) );
		}

		$this->resolveSource();
		if ( $this->sourceError ) {
			return $this->unavailable( $presence, $this->sourceError->get_error_code(), $this->sourceError->get_error_message() );
		}

		$catalog = $this->catalog();

		return array(
			'family'    => 'bit-integrations',
			'label'     => 'Bit Integrations',
			'presence'  => $presence,
			'available' => true,
			'catalog'   => $catalog,
			'changelog' => CatalogScanner::resolveChangelogSlugs(
				CatalogScanner::parseChangelogLatest( Detector::freePath( 'bit-integrations', 'readme.txt' ) ),
				$catalog['per_integration']
			),
		);
	}

	/** @return array<string,mixed> an unavailable-report sentinel for the dashboard to surface. */
	private function unavailable( array $presence, $reason, $message ) {
		return array(
			'family'    => 'bit-integrations',
			'label'     => 'Bit Integrations',
			'presence'  => $presence,
			'available' => false,
			'reason'    => $reason,
			'message'   => $message,
			'catalog'   => null,
			'changelog' => null,
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
			'total_integrations'    => $trigger_count + $action_count,
			'platform_integrations' => \count( $rows ),
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
		$d = Detector::detect()['bit-integrations'];
		if ( empty( $d['free'] ) || empty( $d['pro'] ) ) {
			return array(
				'name'     => CatalogScanner::humanize( $key ),
				'slug'     => $key,
				'isPro'    => true,
				'found'    => false,
				'triggers' => array(),
				'actions'  => array(),
			);
		}
		$this->resolveSource();
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
		$this->resolveSource();
		if ( $this->sourceError ) {
			$this->platforms = array();

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
	 * Trigger registry = Free trigger modules whose backend controller exposes info() (read locally),
	 * plus the AllTriggersName Pro catalog (read locally).
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

		foreach ( CatalogScanner::parseAllTriggers( Detector::freePath( 'bit-integrations', 'backend/Core/Util/AllTriggersName.php' ) ) as $row ) {
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
	 * Action registry = the SelectAction.jsx `integs` list (read from GitHub), each resolved to its
	 * backend module folder (resolved against the locally installed Actions dirs).
	 *
	 * @return array<string,array{name:string,slug:string}> keyed by normalized name
	 */
	private function actionRegistry() {
		$reg = array();
		foreach ( CatalogScanner::parseSelectActionTypes( $this->feSelectAction ) as $name ) {
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
		if ( preg_match( '/\(([^)]+)\)/', $name, $m ) ) {
			$inner = CatalogScanner::normalizeName( $m[1] );
			if ( isset( $map[ $inner ] ) ) {
				return $map[ $inner ];
			}
		}
		$outer = CatalogScanner::normalizeName( preg_replace( '/\(.*$/', '', $name ) );
		if ( '' !== $outer && isset( $map[ $outer ] ) ) {
			return $map[ $outer ];
		}
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

	/** Normalized-name => actual folder slug across the locally installed Free + Pro Actions. */
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
	 * Trigger events for a module: the task list its `{platform}/get` route returns (StaticData::tasks()),
	 * read from the locally installed backend; falls back to the callback's hard-coded titles, then the
	 * concrete WP hooks in Hooks.php, then a single "Dynamic Event".
	 *
	 * @return array<int,array{name:string,hook:string,slug:string,group:string,isPro:bool}>
	 */
	private function triggerEventDetails( $slug, $isPro ) {
		$events = array();
		if ( '' !== $slug ) {
			$dir = is_dir( $this->tFree . '/' . $slug ) ? $this->tFree . '/' . $slug
				: ( is_dir( $this->tPro . '/' . $slug ) ? $this->tPro . '/' . $slug : '' );
			if ( '' !== $dir ) {
				foreach ( CatalogScanner::biStaticTaskLabels( $dir . '/StaticData.php' ) as $hook => $label ) {
					$events[] = array(
						'name'  => $label,
						'hook'  => $hook,
						'slug'  => $hook,
						'group' => '',
						'isPro' => (bool) $isPro,
					);
				}
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
	 * Action events for a module: the operation dropdown declared in the frontend `modules` list
	 * (read from GitHub — authoritative names + tier), then a curated override, then the backend
	 * RecordApiHelper/Controller operations (read locally).
	 *
	 * @return array<int,array{name:string,slug:string,group:string,isPro:bool}>
	 */
	private function actionEventDetails( $slug ) {
		if ( '' !== $slug ) {
			$modules = $this->frontendModules( $slug );
			if ( $modules ) {
				$events = array();
				foreach ( $modules as $mod ) {
					$events[] = array(
						'name'  => $mod['label'],
						'slug'  => $mod['value'],
						'group' => $mod['group'],
						'isPro' => $mod['isPro'],
					);
				}

				return $events;
			}
		}

		if ( '' !== $slug ) {
			$override = $this->actionOverride( $slug );
			if ( $override ) {
				$events = array();
				foreach ( $override as $op ) {
					$events[] = array(
						'name'  => $op[0],
						'slug'  => sanitize_title( $op[0] ),
						'group' => '',
						'isPro' => $op[1],
					);
				}

				return $events;
			}
		}

		if ( '' !== $slug ) {
			$dir = is_dir( $this->aFree . '/' . $slug ) ? $this->aFree . '/' . $slug
				: ( is_dir( $this->aPro . '/' . $slug ) ? $this->aPro . '/' . $slug : '' );
			if ( '' !== $dir ) {
				if ( CatalogScanner::isWebhookRelay( $dir ) ) {
					return array(
						array(
							'name'  => __( 'Send Data', 'bit-audit' ),
							'slug'  => 'send-data',
							'group' => '',
							'isPro' => false,
						),
					);
				}
				$labels = $this->frontendLabels( $slug );
				$events = array();
				foreach ( CatalogScanner::biActionOperations( $dir ) as $op ) {
					$name  = $this->actionOpName( $op, $slug );
					$isPro = (bool) $op['isPro'];
					$fe    = $this->frontendLabelFor( $op['key'], $slug, $labels );
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

		return array(
			array(
				'name'  => __( 'Dynamic Event', 'bit-audit' ),
				'slug'  => '',
				'group' => '',
				'isPro' => false,
			),
		);
	}

	/** Frontend label entry matching an operation value (null if none). Tries the slug-stripped key too. */
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

	/** Frontend modules value => label/isPro map for an action, resolved by its folder (from GitHub). */
	private function frontendLabels( $slug ) {
		$key = CatalogScanner::normalizeName( $slug );
		if ( isset( $this->feLabels[ $key ] ) ) {
			return $this->feLabels[ $key ];
		}
		$dirs                   = $this->feDirMap();
		$this->feLabels[ $key ] = isset( $dirs[ $key ] ) ? CatalogScanner::frontendActionLabels( $dirs[ $key ] ) : array();

		return $this->feLabels[ $key ];
	}

	/** The action's operation list (`modules`) from its frontend folder (from GitHub), resolved by slug. */
	private function frontendModules( $slug ) {
		$key = CatalogScanner::normalizeName( $slug );
		if ( isset( $this->feModules[ $key ] ) ) {
			return $this->feModules[ $key ];
		}
		$dirs                    = $this->feDirMap();
		$this->feModules[ $key ] = isset( $dirs[ $key ] ) ? CatalogScanner::frontendActionModules( $dirs[ $key ] ) : array();

		return $this->feModules[ $key ];
	}

	/** Normalized frontend integration folder name => absolute path (GitHub-fetched). */
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

	/**
	 * Verified operation lists for actions that dispatch operations at runtime (insert/update probes,
	 * numeric switches, $actions->flag sub-ops) with no static op list any generic parser can read.
	 * Each op is [label, isPro].
	 *
	 * @return array<int,array{0:string,1:bool}>|null
	 */
	private function actionOverride( $slug ) {
		$pro  = static function ( array $names ) {
			return array_map( static fn( $n ) => array( $n, true ), $names );
		};
		$free = static function ( array $names ) {
			return array_map( static fn( $n ) => array( $n, false ), $names );
		};
		$map  = array(
			'activecampaign'  => $pro( array( 'Create Contact', 'Update Contact', 'Add to List', 'Add Tags', 'Add Account Contact' ) ),
			'keap'            => $pro( array( 'Add Contact', 'Add Tags' ) ),
			'salesmate'       => $pro( array( 'Create Contact', 'Create Deal', 'Create Company', 'Create Product' ) ),
			'zohorecruit'     => $pro( array( 'Create Record in Module', 'Add Note to Record', 'Create Related Records' ) ),
			'zohocrm'         => $pro( array( 'Insert Record', 'Upsert Records', 'Tag Records', 'Add Attachment', 'Trigger Workflow', 'Send to Approval', 'Trigger Blueprint', 'Apply Assignment Rule', 'Capture GCLID' ) ),
			'sendfox'         => $free( array( 'Create List', 'Create Contact', 'Unsubscribe Contact' ) ),
			'moosend'         => $free( array( 'Subscribe', 'Unsubscribe', 'Unsubscribe from List' ) ),
			'constantcontact' => $free( array( 'Add Contact', 'Update Contact' ) ),
			'benchmark'       => $free( array( 'Add Contact', 'Update Contact' ) ),
			'sendinblue'      => $free( array( 'Add Contact', 'Update Contact', 'Double Opt-in Contact' ) ),
			'zagomail'        => $free( array( 'Create Subscriber', 'Update Subscriber', 'Add Tags' ) ),
		);
		$key  = CatalogScanner::normalizeName( $slug );

		return isset( $map[ $key ] ) ? $map[ $key ] : null;
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
