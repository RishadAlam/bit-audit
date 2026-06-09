<?php

namespace BitApps\Audit;

defined( 'ABSPATH' ) || exit;

/**
 * Filesystem + lightweight source-parsing helpers shared by the auditors.
 */
final class CatalogScanner {

	/** Count immediate sub-directories of $absPath, skipping dotfiles and _underscore folders. */
	public static function countDirs( $absPath ) {
		return \count( self::listDirs( $absPath ) );
	}

	/** @return string[] directory names */
	public static function listDirs( $absPath ) {
		if ( ! is_dir( $absPath ) ) {
			return array();
		}
		$dirs = array();
		foreach ( scandir( $absPath ) ?: array() as $entry ) {
			if ( '.' === $entry || '..' === $entry || '.' === $entry[0] || '_' === $entry[0] ) {
				continue;
			}
			if ( is_dir( $absPath . '/' . $entry ) ) {
				$dirs[] = $entry;
			}
		}
		sort( $dirs );

		return $dirs;
	}

	/** Recursively find files matching a suffix under $absPath. */
	public static function findFiles( $absPath, $suffix ) {
		$out = array();
		if ( ! is_dir( $absPath ) ) {
			return $out;
		}
		$it = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $absPath, \FilesystemIterator::SKIP_DOTS )
		);
		foreach ( $it as $file ) {
			if ( substr( $file->getFilename(), -\strlen( $suffix ) ) === $suffix ) {
				$out[] = $file->getPathname();
			}
		}

		return $out;
	}

	public static function read( $absFile ) {
		return is_readable( $absFile ) ? (string) file_get_contents( $absFile ) : '';
	}

	/*
	------------------------------------------------------------------ *
	 *  Bit Flows catalog (frontend machine root files; on-disk folder is `bit-pi`)
	 * ------------------------------------------------------------------ */

	/**
	 * Parse a bit-pi `_<slug>Machines.ts` root file into per-entry rows. The root declares the app's
	 * full catalog as `triggers: [ {…} ]` and `actions: [ {…} ]`; each entry carries isPro, label and
	 * machineSlug. Entries are read with brace-aware scanning so nested objects / template literals
	 * (e.g. WordPress, Mail) are not dropped.
	 *
	 * @return array{
	 *   slug:string, name:string,
	 *   entries:array<int,array{type:string,isPro:bool,slug:string,name:string,group:string}>
	 * }
	 */
	public static function parsePiRoot( $contents ) {
		$slug    = self::firstMatch( '/appSlug:\s*[\'"]([^\'"]+)[\'"]/', $contents );
		$name    = self::firstMatch( '/\bname:\s*[\'"]([^\'"]+)[\'"]/', $contents );
		$entries = array();

		foreach ( array(
			'trigger' => 'triggers',
			'action'  => 'actions',
		) as $type => $arrayKey ) {
			foreach ( self::piRootArrayObjects( $contents, $arrayKey ) as $obj ) {
				if ( false === strpos( $obj, 'machineSlug' ) && false === strpos( $obj, 'runType' ) ) {
					continue;
				}
				$entries[] = array(
					'type'  => $type,
					'isPro' => (bool) preg_match( '/isPro:\s*true/', $obj ),
					'slug'  => self::firstMatch( '/machineSlug:\s*[\'"]([^\'"]+)[\'"]/', $obj ),
					'name'  => self::firstMatch( '/label:\s*(?:__\(\s*)?[\'"]([^\'"]+)[\'"]/', $obj ),
					'group' => self::firstMatch( '/group:\s*[\'"]([^\'"]+)[\'"]/', $obj ),
				);
			}
		}

		return array(
			'slug'    => $slug ? $slug : '',
			'name'    => $name ? $name : ( $slug ? $slug : '' ),
			'entries' => $entries,
		);
	}

	/**
	 * Top-level `{…}` objects of a `<key>: [ … ]` array in a bit-pi root machine, scanned with brace
	 * depth so nested objects and `${…}` template literals inside an entry stay with that entry.
	 *
	 * @return string[]
	 */
	private static function piRootArrayObjects( $contents, $key ) {
		$objects = array();
		if ( ! preg_match( '/\b' . $key . '\s*:\s*\[/', $contents, $m, PREG_OFFSET_CAPTURE ) ) {
			return $objects;
		}
		$i         = $m[0][1] + \strlen( $m[0][0] );
		$len       = \strlen( $contents );
		$arr_depth = 1;
		while ( $i < $len && $arr_depth > 0 ) {
			$ch = $contents[ $i ];
			if ( '[' === $ch ) {
				++$arr_depth;
			} elseif ( ']' === $ch ) {
				--$arr_depth;
			} elseif ( '{' === $ch && 1 === $arr_depth ) {
				$start = $i;
				$depth = 0;
				while ( $i < $len ) {
					if ( '{' === $contents[ $i ] ) {
						++$depth;
					} elseif ( '}' === $contents[ $i ] ) {
						--$depth;
						if ( 0 === $depth ) {
							++$i;
							break;
						}
					}
					++$i;
				}
				$objects[] = substr( $contents, $start, $i - $start );
				continue;
			}
			++$i;
		}

		return $objects;
	}

	/** Map machineSlug => WP hook from a bit-pi `<Name>Hooks.php` register() array. */
	public static function piHookMap( $absFile ) {
		$map      = array();
		$contents = self::read( $absFile );
		if ( '' === $contents ) {
			return $map;
		}
		if ( preg_match_all( '/[\'"](\w+)[\'"]\s*=>\s*\[\s*[\'"]hook[\'"]\s*=>\s*[\'"]([^\'"]+)[\'"]/', $contents, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $row ) {
				$map[ $row[1] ] = $row[2];
			}
		}

		return $map;
	}

	/*
	------------------------------------------------------------------ *
	 *  Bit Integrations catalog (backend Hooks.php files)
	 * ------------------------------------------------------------------ */

	/**
	 * Trigger events from a Bit Integrations trigger `Hooks.php`.
	 * Each `Hooks::add('hook', [Ctrl, 'method'])` / `add_action('hook', …)` = one event.
	 *
	 * @return array<int,array{hook:string,method:string}>
	 */
	public static function biTriggerEvents( $absFile ) {
		$contents = self::read( $absFile );
		$events   = array();
		if ( '' === $contents ) {
			return $events;
		}
		if ( preg_match_all( '/(?:Hooks::add|add_action)\(\s*[\'"]([^\'"]+)[\'"]\s*,\s*\[[^,\]]*,\s*[\'"]([^\'"]+)[\'"]/', $contents, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $row ) {
				$events[] = array(
					'hook'   => $row[1],
					'method' => $row[2],
				);
			}
		}
		// Fallback: hooks registered without an array callback on the same line.
		if ( ! $events && preg_match_all( '/(?:Hooks::add|add_action)\(\s*[\'"]([^\'"]+)[\'"]/', $contents, $m2 ) ) {
			foreach ( $m2[1] as $hook ) {
				$events[] = array(
					'hook'   => $hook,
					'method' => '',
				);
			}
		}

		return $events;
	}

	/**
	 * Action operations from a Bit Integrations action `Hooks.php`.
	 * Each `Hooks::filter(Config::withFreePrefix('int_op'), [Helper, 'method'])` = one operation.
	 *
	 * @return array<int,array{slug:string,method:string}>
	 */
	public static function biActionEvents( $absFile ) {
		$contents = self::read( $absFile );
		$events   = array();
		if ( '' === $contents ) {
			return $events;
		}
		if ( preg_match_all( '/(?:Hooks::filter|add_filter)\(\s*(?:Config::with\w+\(\s*)?[\'"]([^\'"]+)[\'"]\s*\)?\s*,\s*\[[^,\]]*,\s*[\'"]([^\'"]+)[\'"]/', $contents, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $row ) {
				$events[] = array(
					'slug'   => $row[1],
					'method' => $row[2],
				);
			}
		}

		return $events;
	}

	/**
	 * Action operations for a Bit Integrations action module, read from its backend source
	 * (RecordApiHelper / Controller). Resolution order:
	 *   1. a `switch ($actionName)` — each case is an operation (case label = name; a case body that
	 *      wraps `Config::withPrefix` is Pro);
	 *   2. otherwise the union of: each `Config::withPrefix` (Pro) / `Config::withFreePrefix` (Free)
	 *      hook, and every operation the execution API logs on success
	 *      (`LogHandler::save(['type_name' => …], 'success')` — the real names of if-chain operations).
	 * A Pro-only hook set layered on a direct record-insert gets a Free base store-record; an action
	 * with no detectable operation is a single Free store-record.
	 *
	 * @return array<int,array{key:string,isPro:bool,fromHook:bool}>
	 */
	public static function biActionOperations( $absDir ) {
		$blob = '';
		foreach ( glob( $absDir . '/*.php' ) ?: array() as $file ) {
			$blob .= "\n" . self::read( $file );
		}
		if ( '' === trim( $blob ) ) {
			return array();
		}

		$ops = array();

		// Operations selector switch (the variable the flow passes as the chosen action).
		if ( preg_match( '/switch\s*\(\s*\$(?:actionName|mainAction|action_name|action)\s*\)\s*\{/', $blob, $m, PREG_OFFSET_CAPTURE ) ) {
			$start = $m[0][1] + \strlen( $m[0][0] );
			$depth = 1;
			$i     = $start;
			$len   = \strlen( $blob );
			while ( $i < $len && $depth > 0 ) {
				if ( '{' === $blob[ $i ] ) {
					++$depth;
				} elseif ( '}' === $blob[ $i ] ) {
					--$depth;
				}
				++$i;
			}
			$body = substr( $blob, $start, $i - $start );
			if ( preg_match_all( "/case\s+'([^']+)'\s*:(.*?)(?=case\s+'|default\s*:|$)/s", $body, $cm, PREG_SET_ORDER ) ) {
				foreach ( $cm as $c ) {
					if ( ! isset( $ops[ $c[1] ] ) ) {
						$ops[ $c[1] ] = array(
							'key'      => $c[1],
							'isPro'    => false !== strpos( $c[2], 'Config::withPrefix' ),
							'fromHook' => false,
						);
					}
				}
			}
		}

		if ( ! $ops ) {
			$slug_key = self::normalizeName( basename( $absDir ) );
			$add      = static function ( $key, $is_pro, $from_hook ) use ( &$ops, $slug_key ) {
				if ( self::isNoiseOp( $key ) ) {
					return;
				}
				$nk = self::normalizeName( $key );
				// Dedup hook ops against clean names by dropping the integration's own slug prefix
				// (e.g. acpt_create_cpt → createcpt matches the "Create CPT" selector op).
				if ( $from_hook && '' !== $slug_key && 0 === strpos( $nk, $slug_key ) ) {
					$stripped = substr( $nk, \strlen( $slug_key ) );
					if ( '' !== $stripped ) {
						$nk = $stripped;
					}
				}
				if ( '' === $nk ) {
					return;
				}
				if ( isset( $ops[ $nk ] ) ) {
					if ( $is_pro ) {
						$ops[ $nk ]['isPro'] = true; // Pro wins the tier on a collision
					}
					if ( ! $from_hook && $ops[ $nk ]['fromHook'] ) {
						$ops[ $nk ]['key']      = $key; // a clean (non-hook) label wins
						$ops[ $nk ]['fromHook'] = false;
					}
					return;
				}
				$ops[ $nk ] = array(
					'key'      => $key,
					'isPro'    => $is_pro,
					'fromHook' => $from_hook,
				);
			};

			// Operation enum declared as small-int consts (e.g. BuddyBoss CREATE_GROUP_PRO = 1); a
			// `_PRO` suffix marks the Pro tier. This is the complete operation list — use it alone.
			if ( preg_match_all( '/(?:private|protected|public)\s+const\s+([A-Z][A-Z0-9_]+)\s*=\s*\d{1,3}\s*;/', $blob, $cm, PREG_SET_ORDER ) && \count( $cm ) >= 4 ) {
				foreach ( $cm as $c ) {
					$add( strtolower( str_replace( '_PRO', '', $c[1] ) ), false !== strpos( $c[1], '_PRO' ), false );
				}
				if ( $ops ) {
					return array_values( $ops );
				}
			}
			// Wrapped Pro / Free hooks first, so their tier sticks; the cleaner names below replace the
			// hook label for the same operation (utility & data-fetch hooks are dropped as noise).
			if ( preg_match_all( "/Config::withPrefix\(\s*'([^']+)'/", $blob, $pm ) ) {
				foreach ( $pm[1] as $hook ) {
					$add( $hook, true, true );
				}
			}
			if ( preg_match_all( "/Config::withFreePrefix\(\s*'([^']+)'/", $blob, $fm ) ) {
				foreach ( $fm[1] as $hook ) {
					$add( $hook, false, true );
				}
			}
			// Operation names assigned to the log's $typeName (e.g. GoHighLevel 'Create Contact').
			if ( preg_match_all( "/\\\$type_?[nN]ame\s*=\s*'([^']+)'/", $blob, $tn ) ) {
				foreach ( $tn[1] as $name ) {
					$add( $name, false, false );
				}
			}
			// if/elseif chain comparing the action selector to a named value (CapsuleCRM, Salesforce
			// `$actionName === 'organisation'`). Numeric selectors (named via LogHandler) are skipped.
			$selector = '\$(?:actionName|mainAction|action_name|action|mainTask|selectedTask|actionType)';
			if ( preg_match_all( "/{$selector}\s*===?\s*'([^']+)'|'([^']+)'\s*===?\s*{$selector}/", $blob, $sm, PREG_SET_ORDER ) ) {
				foreach ( $sm as $cmp ) {
					$value = '' !== $cmp[1] ? $cmp[1] : ( isset( $cmp[2] ) ? $cmp[2] : '' );
					if ( '' !== $value && ! ctype_digit( $value ) ) {
						$add( $value, false, false );
					}
				}
			}
			// Each operation the execution API logs on success (drops 'error'-only log types and
			// doing/done phase duplicates).
			if ( preg_match_all( "/LogHandler::save\([^;]*?'type_name'\s*=>\s*'([^']+)'[^;]*?,\s*'(success|error)'/s", $blob, $lm, PREG_SET_ORDER ) ) {
				foreach ( $lm as $log ) {
					if ( 'success' === $log[2] ) {
						$add( $log[1], false, false );
					}
				}
			}

			// Pro add-on hooks layered on a direct record-insert (e.g. MailChimp tag/GDPR on top of a
			// free subscribe) with no Free op of their own: the base store-record is the Free operation.
			$has_free = false;
			foreach ( $ops as $o ) {
				if ( ! $o['isPro'] ) {
					$has_free = true;
					break;
				}
			}
			if ( $ops && ! $has_free && self::hasBaseInsert( $blob ) ) {
				$ops = array(
					'storerecord' => array(
						'key'      => 'store_record',
						'isPro'    => false,
						'fromHook' => false,
					),
				) + $ops;
			}
		}

		return array_values( $ops );
	}

	/** A utility / data-fetch hook or a generic log category, not a user-facing action operation. */
	private static function isNoiseOp( $name ) {
		$n       = self::normalizeName( $name );
		$generic = array( 'field', 'fields', 'file', 'list', 'value', 'validation', 'status', 'meta', 'default', 'none', 'data', 'record', 'group', 'groups', 'custom', 'error', 'success', 'length' );
		if ( \in_array( $n, $generic, true ) ) {
			return true;
		}
		foreach ( array( 'utilit', 'allstatuses', 'wpusersbasic', 'customfields', 'storerelatedlist', 'activateplugin', 'activationstatus', 'pluginactivation', 'getall' ) as $bad ) {
			if ( false !== strpos( $n, $bad ) ) {
				return true;
			}
		}

		return false;
	}

	/** True when the source performs a direct base record-insert (the action's Free operation). */
	private static function hasBaseInsert( $blob ) {
		return (bool) preg_match(
			'/(?:\$this->|self::|->)\s*(?:insertRecord|createRecord|addRecord|upsertRecord|insertData|addSubscriber|createSubscriber|subscribe|insertDeleteRecord)\s*\(/',
			$blob
		);
	}

	/**
	 * Event names a trigger's `{platform}/get` route callback exposes to the flow builder, for
	 * triggers that hard-code the list (e.g. Academy Lms) rather than parsing StaticData::tasks().
	 * Reads the callback method named in Routes.php and returns the translatable titles it lists.
	 * Returns [] for callbacks that query the site dynamically (forms/posts have no static names).
	 *
	 * @return string[]
	 */
	public static function biTriggerGetEventNames( $absDir ) {
		$routes = self::read( $absDir . '/Routes.php' );
		if ( '' === $routes || ! preg_match( "/Route::(?:get|post)\(\s*'[^']*\/get'\s*,\s*\[[^,\]]*,\s*'([^']+)'/", $routes, $rm ) ) {
			return array();
		}
		$method = $rm[1];

		$blob = '';
		foreach ( glob( $absDir . '/*.php' ) ?: array() as $file ) {
			$blob .= "\n" . self::read( $file );
		}
		if ( ! preg_match( '/function\s+' . preg_quote( $method, '/' ) . '\s*\([^)]*\)\s*\{/', $blob, $mm, PREG_OFFSET_CAPTURE ) ) {
			return array();
		}
		$start = $mm[0][1] + \strlen( $mm[0][0] );
		$depth = 1;
		$i     = $start;
		$len   = \strlen( $blob );
		while ( $i < $len && $depth > 0 ) {
			if ( '{' === $blob[ $i ] ) {
				++$depth;
			} elseif ( '}' === $blob[ $i ] ) {
				--$depth;
			}
			++$i;
		}
		$body = substr( $blob, $start, $i - $start );

		$names = array();
		// Explicit 'title' => '…' entries always win.
		if ( preg_match_all( "/'title'\s*=>\s*(?:__\(\s*)?'([^']+)'/", $body, $tm ) ) {
			foreach ( $tm[1] as $title ) {
				$names[ $title ] = true;
			}
		}
		// Otherwise a hard-coded list of translatable titles (the $types/$tasks array).
		if ( ! $names && preg_match_all( "/__\(\s*'([^']+)'/", $body, $um ) ) {
			foreach ( $um[1] as $title ) {
				if ( self::looksLikeEventName( $title ) ) {
					$names[ $title ] = true;
				}
			}
			// A single translatable string is more likely a label/notice than an event list.
			if ( \count( $names ) < 2 ) {
				$names = array();
			}
		}

		return array_keys( $names );
	}

	/** Heuristic: a short title, not an error/notice string. */
	private static function looksLikeEventName( $text ) {
		$low = strtolower( $text );
		foreach ( array( 'not installed', 'not active', 'permission', 'invalid', 'error', 'please', 'failed', 'required', 'missing', 'unable', 'select ', 'choose', 'no data' ) as $bad ) {
			if ( false !== strpos( $low, $bad ) ) {
				return false;
			}
		}

		return '' !== trim( $text ) && \strlen( $text ) <= 80;
	}

	/**
	 * Map trigger hook => human label from a Bit Integrations `StaticData.php` tasks() list.
	 * (`triggered_entity_id` is the hook name; `form_name` is the label.)
	 *
	 * @return array<string,string>
	 */
	public static function biStaticTaskLabels( $absFile ) {
		$contents = self::read( $absFile );
		$labels   = array();
		if ( '' === $contents ) {
			return $labels;
		}
		if ( preg_match_all( '/[\'"]form_name[\'"]\s*=>\s*(?:__\(\s*)?[\'"]([^\'"]+)[\'"].*?[\'"]triggered_entity_id[\'"]\s*=>\s*[\'"]([^\'"]+)[\'"]/s', $contents, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $row ) {
				$labels[ $row[2] ] = $row[1];
			}
		}

		return $labels;
	}

	/**
	 * Map an action's operation value => its real label (and Pro flag) from the integration's
	 * frontend folder, where modules are declared `{ value|name: '<case>', label: __('Real Name'),
	 * is_pro?: bool }`. The op `value` matches the backend switch-case / selector value, so this gives
	 * the user-facing event name instead of a humanized case slug. Keyed by normalized value.
	 *
	 * @return array<string,array{label:string,isPro:bool|null}>
	 */
	public static function frontendActionLabels( $absDir ) {
		$map = array();
		if ( ! is_dir( $absDir ) ) {
			return $map;
		}
		$files = array_merge( glob( $absDir . '/*.jsx' ) ?: array(), glob( $absDir . '/*.js' ) ?: array() );
		foreach ( $files as $file ) {
			$contents = self::read( $file );
			if ( false === strpos( $contents, 'label' ) ) {
				continue;
			}
			if ( ! preg_match_all( '/\{[^{}]*\blabel\s*:[^{}]*\}/', $contents, $objs ) ) {
				continue;
			}
			foreach ( $objs[0] as $obj ) {
				if ( ! preg_match( "/\b(?:value|name)\s*:\s*'([^']+)'/", $obj, $vm )
					|| ! preg_match( "/\blabel\s*:\s*(?:__\(\s*)?'([^']+)'/", $obj, $lm ) ) {
					continue;
				}
				$key = self::normalizeName( $vm[1] );
				if ( '' === $key || isset( $map[ $key ] ) ) {
					continue;
				}
				$is_pro = null;
				if ( preg_match( '/\bis_?[pP]ro\s*:\s*(true|false)/', $obj, $pm ) ) {
					$is_pro = 'true' === $pm[1];
				}
				$map[ $key ] = array(
					'label' => $lm[1],
					'isPro' => $is_pro,
				);
			}
		}

		return $map;
	}

	/**
	 * The action catalog the Flow builder offers: every `{ type: '...' }` in the
	 * SelectAction.jsx `integs` array, in source order.
	 *
	 * @return string[] action display names
	 */
	public static function parseSelectActionTypes( $absFile ) {
		$contents = self::read( $absFile );
		if ( '' === $contents ) {
			return array();
		}
		$start = strpos( $contents, 'integs = [' );
		if ( false === $start ) {
			return array();
		}
		$end   = strpos( $contents, "\n  ]", $start );
		$block = false === $end ? substr( $contents, $start ) : substr( $contents, $start, $end - $start );

		return preg_match_all( "/type:\s*'([^']+)'/", $block, $m ) ? $m[1] : array();
	}

	/** Trigger catalog names (with isPro) from AllTriggersName.php. */
	public static function parseAllTriggers( $absFile ) {
		$contents = self::read( $absFile );
		$rows     = array();
		if ( '' === $contents ) {
			return $rows;
		}
		if ( preg_match_all(
			'/[\'"]([^\'"]+)[\'"]\s*=>\s*\[\s*[\'"]name[\'"]\s*=>\s*[\'"]([^\'"]+)[\'"]\s*,\s*[\'"]isPro[\'"]\s*=>\s*(true|false)/',
			$contents,
			$m,
			PREG_SET_ORDER
		) ) {
			foreach ( $m as $row ) {
				$rows[] = array(
					'slug'  => $row[1],
					'name'  => $row[2],
					'isPro' => 'true' === $row[3],
				);
			}
		}

		return $rows;
	}

	/*
	------------------------------------------------------------------ *
	 *  Changelog (readme.txt) — latest added integrations
	 * ------------------------------------------------------------------ */

	/**
	 * Parse the most recent release that introduced new triggers/actions.
	 * Handles both readme styles:
	 *   Bit Integrations: "- **New Triggers**" / "- Name: N new events added"
	 *   Bit Flows:        "* **Triggers (N)**" / "* Name (NN)"
	 *
	 * @return array{version:string,date:string,triggers:array,actions:array}|null
	 */
	public static function parseChangelogLatest( $absReadme ) {
		$contents = self::read( $absReadme );
		if ( '' === $contents ) {
			return null;
		}
		$pos = strpos( $contents, '== Changelog ==' );
		if ( false !== $pos ) {
			$contents = substr( $contents, $pos );
		}
		$lines  = preg_split( '/\R/', $contents );
		$blocks = array();
		$cur    = null;
		foreach ( $lines as $line ) {
			if ( preg_match( '/^=\s*(.+?)\s*=\s*$/', $line, $h ) ) {
				if ( $cur ) {
					$blocks[] = $cur;
				}
				$cur = array(
					'header' => $h[1],
					'lines'  => array(),
				);
			} elseif ( $cur ) {
				$cur['lines'][] = $line;
			}
		}
		if ( $cur ) {
			$blocks[] = $cur;
		}

		foreach ( $blocks as $block ) {
			$parsed = self::parseChangelogBlock( $block );
			if ( $parsed['triggers'] || $parsed['actions'] ) {
				return $parsed;
			}
		}

		return null;
	}

	private static function parseChangelogBlock( array $block ) {
		$header  = $block['header'];
		$version = self::firstMatch( '/(v?\d[\w.]*)/', $header );
		$date    = self::firstMatch( '/\(([^)]+)\)/', $header );
		if ( '' === $date ) {
			$date = self::firstMatch( '/Release Date\s*[-–]\s*([^_]+)/', implode( "\n", $block['lines'] ) );
		}
		$triggers = array();
		$actions  = array();
		$mode     = '';

		foreach ( $block['lines'] as $line ) {
			if ( preg_match( '/\*\*(.+?)\*\*/', $line, $hm ) ) {
				$head = strtolower( $hm[1] );
				if ( false !== strpos( $head, 'trigger' ) ) {
					$mode = 'triggers';
				} elseif ( false !== strpos( $head, 'action' ) ) {
					$mode = 'actions';
				} else {
					$mode = '';
				}
				continue;
			}
			if ( '' === $mode ) {
				continue;
			}
			$item = self::parseChangelogItem( $line );
			if ( $item ) {
				if ( 'triggers' === $mode ) {
					$triggers[] = $item;
				} else {
					$actions[] = $item;
				}
			}
		}

		return array(
			'version'  => $version ? $version : trim( $header ),
			'date'     => trim( $date ),
			'triggers' => $triggers,
			'actions'  => $actions,
		);
	}

	/** "- WordPress: 33 new events added (Pro)." or "* Heffl CRM (26)" => [name, events]. */
	private static function parseChangelogItem( $line ) {
		$line = trim( $line );
		if ( '' === $line ) {
			return null;
		}
		// Bit Integrations style.
		if ( preg_match( '/^[\-*\s]+(.+?):\s*(\d+)\s+new\s+events?/i', $line, $m ) ) {
			return array(
				'name'   => trim( $m[1] ),
				'events' => (int) $m[2],
			);
		}
		// Bit Flows style: name followed by (NN) at end.
		if ( preg_match( '/^[\-*\s]+(.+?)\s*\((\d+)\)\s*$/', $line, $m ) ) {
			return array(
				'name'   => trim( $m[1] ),
				'events' => (int) $m[2],
			);
		}

		return null;
	}

	/**
	 * Attach an integration `slug` to each changelog trigger/action item by matching its name
	 * against the per-integration catalog, so the dashboard can link "Latest" rows to detail.
	 * Match order: exact (normalized) name, then longest catalog name that prefixes it (covers
	 * "Secure Custom Fields (SCF)" and "MoreConvert Wishlist for WooCommerce"). Unresolved => ''.
	 *
	 * @param array{version:string,date:string,triggers:array,actions:array}|null $changelog
	 * @param array<int,array{name:string,slug:string}>                           $perIntegration
	 * @return array|null
	 */
	public static function resolveChangelogSlugs( $changelog, array $perIntegration ) {
		if ( ! $changelog ) {
			return $changelog;
		}
		$byName = array();
		foreach ( $perIntegration as $row ) {
			$key = self::normalizeName( $row['name'] );
			if ( '' !== $key && ! isset( $byName[ $key ] ) ) {
				$byName[ $key ] = $row['slug'];
			}
		}
		foreach ( array( 'triggers', 'actions' ) as $k ) {
			if ( empty( $changelog[ $k ] ) ) {
				continue;
			}
			foreach ( $changelog[ $k ] as &$item ) {
				$item['slug'] = self::matchCatalogSlug( $item['name'], $byName );
			}
			unset( $item );
		}

		return $changelog;
	}

	/** Lowercase, drop parentheticals, keep alphanumerics — for fuzzy catalog name matching. */
	public static function normalizeName( $name ) {
		$name = strtolower( (string) $name );
		$name = preg_replace( '/\([^)]*\)/', '', $name );

		return preg_replace( '/[^a-z0-9]+/', '', $name );
	}

	/** @param array<string,string> $byName normalized name => slug */
	private static function matchCatalogSlug( $name, array $byName ) {
		$key = self::normalizeName( $name );
		if ( '' === $key ) {
			return '';
		}
		if ( isset( $byName[ $key ] ) ) {
			return $byName[ $key ];
		}
		// Longest catalog name that is a prefix of the (longer) changelog name wins.
		$best    = '';
		$bestLen = 3; // ignore trivially short prefixes to avoid spurious hits
		foreach ( $byName as $ck => $slug ) {
			$len = \strlen( $ck );
			if ( $len > $bestLen && 0 === strpos( $key, $ck ) ) {
				$best    = $slug;
				$bestLen = $len;
			}
		}

		return $best;
	}

	/* ------------------------------------------------------------------ */

	/** Turn a slug/camelCase/hook into a readable Title Case label. */
	public static function humanize( $value ) {
		$value = preg_replace( '/(?<=[a-z0-9])(?=[A-Z])/', ' ', (string) $value );
		$value = str_replace( array( '_', '-' ), ' ', $value );
		$value = trim( preg_replace( '/\s+/', ' ', $value ) );

		return $value ? ucwords( $value ) : '';
	}

	private static function firstMatch( $pattern, $subject ) {
		return preg_match( $pattern, $subject, $m ) ? $m[1] : '';
	}
}
