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

	/**
	 * PHP source with every comment blanked out. Event lists are mined with regexes, and the plugins
	 * keep retired events as commented-out array entries (e.g. WooCommerce's deprecated subscription
	 * and booking events sit in a doc block above the live list), so scanning raw source reports
	 * events the Flow builder no longer offers.
	 */
	public static function readPhp( $absFile ) {
		return self::stripPhpComments( self::read( $absFile ) );
	}

	/** Replace each comment token with its own newlines, keeping every other token verbatim. */
	private static function stripPhpComments( $contents ) {
		if ( '' === $contents || ! \function_exists( 'token_get_all' ) ) {
			return $contents;
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- malformed source must not surface a tokenizer warning in the admin screen.
		$tokens = @token_get_all( $contents );
		if ( ! $tokens ) {
			return $contents;
		}
		$out = '';
		foreach ( $tokens as $token ) {
			if ( ! \is_array( $token ) ) {
				$out .= $token;
				continue;
			}
			if ( T_COMMENT === $token[0] || T_DOC_COMMENT === $token[0] ) {
				$out .= str_repeat( "\n", substr_count( $token[1], "\n" ) );
				continue;
			}
			$out .= $token[1];
		}

		return $out;
	}

	/**
	 * Split the plugins' own inline Pro marker off an event label: both catalogs suffix a Pro-only
	 * event's user-facing label with a bare " Pro" / " pro" ("Add the user to a group pro"), which is
	 * a tier badge rather than part of the name.
	 *
	 * @return array{0:string,1:bool} [label without the marker, isPro]
	 */
	public static function splitProLabel( $label ) {
		if ( preg_match( '/^(.*\S)\s+[Pp]ro$/', (string) $label, $m ) ) {
			return array( $m[1], true );
		}

		return array( (string) $label, false );
	}

	/** Unescape a captured single/double quoted PHP string body. */
	private static function unescapeQuoted( $value ) {
		return str_replace( array( "\\'", '\\"' ), array( "'", '"' ), $value );
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
		$contents = self::readPhp( $absFile );
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
	 * User-facing trigger name from a platform controller's info() return array.
	 *
	 * @return string Empty when info() or its literal name property cannot be read.
	 */
	public static function biTriggerInfoName( $absFile ) {
		$contents = self::readPhp( $absFile );
		if ( '' === $contents || ! preg_match( '/function\s+info\s*\([^)]*\)(?:\s*:\s*[^\{]+)?\s*\{/', $contents, $match, PREG_OFFSET_CAPTURE ) ) {
			return '';
		}

		$start = $match[0][1] + \strlen( $match[0][0] );
		$depth = 1;
		$i     = $start;
		$len   = \strlen( $contents );
		while ( $i < $len && $depth > 0 ) {
			if ( '{' === $contents[ $i ] ) {
				++$depth;
			} elseif ( '}' === $contents[ $i ] ) {
				--$depth;
			}
			++$i;
		}
		$body = substr( $contents, $start, $i - $start );
		if ( ! preg_match( '/[\'\"]name[\'\"]\s*=>\s*(?:__\(\s*)?([\'\"])(.*?)\1/s', $body, $name ) ) {
			return '';
		}

		return trim( $name[2] );
	}

	/**
	 * Trigger events from a Bit Integrations trigger `Hooks.php`.
	 * Each `Hooks::add('hook', [Ctrl, 'method'])` / `add_action('hook', …)` = one event.
	 *
	 * @return array<int,array{hook:string,method:string}>
	 */
	public static function biTriggerEvents( $absFile ) {
		$contents = self::readPhp( $absFile );
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
		$contents = self::readPhp( $absFile );
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
	 * An action's operation list served by its own backend route instead of declared in the frontend
	 * (MailChimp's `mChimp_refresh_modules` route returns `['name' => …, 'label' => …]` entries). The
	 * base list is what a Free install offers; entries added inside the module's Pro gate
	 * (`Helper::isProActivate()`) are the Pro operations.
	 *
	 * @return array<int,array{value:string,label:string,isPro:bool,group:string}>
	 */
	public static function biActionRouteModules( $absDir ) {
		$routes = self::readPhp( $absDir . '/Routes.php' );
		if ( '' === $routes || ! preg_match( "/Route::(?:get|post)\(\s*'[^']*modules?'\s*,\s*\[[^,\]]*,\s*'([^']+)'/i", $routes, $rm ) ) {
			return array();
		}
		$body = self::methodBody( $absDir, $rm[1] );
		if ( '' === $body ) {
			return array();
		}
		$gate = preg_match( '/isProActivate\s*\(|isPro\s*\(/', $body, $gm, PREG_OFFSET_CAPTURE ) ? $gm[0][1] : \strlen( $body );

		$ops = array();
		if ( ! preg_match_all( "/'name'\s*=>\s*'([^']+)'\s*,\s*'label'\s*=>\s*(?:__\(\s*)?'([^']+)'/s", $body, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			return $ops;
		}
		foreach ( $m as $entry ) {
			list( $label, $labelled_pro ) = self::splitProLabel( $entry[2][0] );
			$ops[]                        = array(
				'value' => $entry[1][0],
				'label' => $label,
				'isPro' => $labelled_pro || $entry[0][1] > $gate,
				'group' => '',
			);
		}

		return $ops;
	}

	/** Body of a named method, scanned across every PHP file of a module directory ('' if absent). */
	private static function methodBody( $absDir, $method ) {
		$blob = '';
		foreach ( glob( $absDir . '/*.php' ) ?: array() as $file ) {
			$blob .= "\n" . self::readPhp( $file );
		}
		if ( ! preg_match( '/function\s+' . preg_quote( $method, '/' ) . '\s*\([^)]*\)(?:\s*:\s*[^\{]+)?\s*\{/', $blob, $m, PREG_OFFSET_CAPTURE ) ) {
			return '';
		}
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

		return substr( $blob, $start, $i - $start );
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
			$blob .= "\n" . self::readPhp( $file );
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
			foreach ( self::prefixedHookNames( $blob, 'withPrefix' ) as $hook ) {
				$add( $hook, true, true );
			}
			foreach ( self::prefixedHookNames( $blob, 'withFreePrefix' ) as $hook ) {
				$add( $hook, false, true );
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

	/**
	 * `Config::with(Free)Prefix('name')` occurrences that name a hook. The same helper also namespaces
	 * transient keys and error codes (`$cache_key = Config::withPrefix('gamipress_rank_types')`,
	 * `new WP_Error(Config::withPrefix('asana_unknown_action'))`), which are not operations, so those
	 * two call sites are skipped. The hook itself is often on the line after `Hooks::filter(`, so the
	 * call cannot be required to sit inside one.
	 *
	 * @return string[]
	 */
	private static function prefixedHookNames( $blob, $method ) {
		$names = array();
		if ( ! preg_match_all( "/(.{0,90})Config::{$method}\(\s*'([^']+)'/s", $blob, $m, PREG_SET_ORDER ) ) {
			return $names;
		}
		foreach ( $m as $match ) {
			if ( self::isNonHookPrefixContext( $match[1] ) ) {
				continue;
			}
			$names[] = $match[2];
		}

		return $names;
	}

	/**
	 * True when the text leading up to a `Config::with*Prefix()` call shows it is not naming an
	 * operation hook: a transient key, an error code, a string compared against a field prefix, or a
	 * filter whose result is assigned into the flow builder's response payload
	 * (`$response['tags'] = Hooks::apply(Config::withPrefix('zbigin_get_tags'), …)`) — that populates a
	 * dropdown, it does not run an operation.
	 */
	private static function isNonHookPrefixContext( $before ) {
		$patterns = array(
			'/\$\w*(?:cache|transient|option)\w*\s*=\s*$/i',
			'/WP_Error\(\s*$/',
			'/strpos\(.*$/',
			'/\$\w+\[[^\]]*\]\s*=\s*(?:\(\s*\w+\s*\)\s*)?(?:\w+::\w+\(\s*)?$/',
		);
		foreach ( $patterns as $pattern ) {
			if ( preg_match( $pattern, $before ) ) {
				return true;
			}
		}

		return false;
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

	/** True when the action is a webhook/automation relay — its controller extends WebHooksController. */
	public static function isWebhookRelay( $absDir ) {
		foreach ( glob( $absDir . '/*Controller.php' ) ?: array() as $file ) {
			if ( preg_match( '/class\s+\w+\s+extends\s+WebHooksController\b/', self::read( $file ) ) ) {
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
	 * Events a trigger's `{platform}/get` route callback exposes to the flow builder, for triggers
	 * that hard-code the list (e.g. Academy Lms) rather than parsing StaticData::tasks(). Reads the
	 * callback method named in Routes.php and returns the translatable titles it lists, each with the
	 * entry's own `'isPro' => true` flag — a Free module can still gate individual events behind Pro
	 * (WooCommerce offers 27 events, 13 of them Pro).
	 * Returns [] for callbacks that query the site dynamically (forms/posts have no static names).
	 *
	 * @return array<int,array{name:string,isPro:bool}>
	 */
	public static function biTriggerGetEventNames( $absDir ) {
		$routes = self::readPhp( $absDir . '/Routes.php' );
		if ( '' === $routes || ! preg_match( "/Route::(?:get|post)\(\s*'[^']*\/get'\s*,\s*\[[^,\]]*,\s*'([^']+)'/", $routes, $rm ) ) {
			return array();
		}
		$body = self::methodBody( $absDir, $rm[1] );
		if ( '' === $body ) {
			return array();
		}
		// The callback often only forwards the list (`wp_send_json_success(VoxelTasks::getTaskList())`),
		// so the delegate's body is appended and scanned with it.
		foreach ( self::delegatedMethods( $body ) as $delegate ) {
			$body .= "\n" . self::methodBody( $absDir, $delegate );
		}

		$names = array();
		// A task object built positionally (`new Task(SOME_CONST, __('Title'), __('Note'))`) names the
		// event in its second argument; the third is a description, so the generic sweep below cannot
		// be used for these.
		if ( preg_match_all( '/new\s+[A-Za-z_\\\\][\w\\\\]*\s*\(\s*[^,()]+,\s*(?:__\(\s*)?([\'"])((?:\\\\.|(?!\1).)*)\1/s', $body, $cm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) && \count( $cm ) >= 2 ) {
			foreach ( $cm as $index => $match ) {
				$name = self::unescapeQuoted( $match[2][0] );
				if ( self::looksLikeEventName( $name ) ) {
					$names[ $name ] = self::entryIsPro( $body, $cm, $index );
				}
			}
		}
		// Explicit 'title' => '…' entries always win. The string is read escape-aware (1 = quote char,
		// 2 = body) because titles carry apostrophes — `__('User\'s role change')`.
		if ( ! $names && preg_match_all( '/[\'"]title[\'"]\s*=>\s*(?:__\(\s*)?([\'"])((?:\\\\.|(?!\1).)*)\1/s', $body, $tm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			foreach ( $tm as $index => $title ) {
				$name = self::unescapeQuoted( $title[2][0] );
				if ( ! self::isInterpolated( $name ) ) {
					$names[ $name ] = self::entryIsPro( $body, $tm, $index );
				}
			}
		}
		// Otherwise a hard-coded list of translatable titles (the $types/$tasks array). A single
		// translatable string is more likely a notice than an event list, so this sweep needs two.
		if ( ! $names && preg_match_all( '/__\(\s*([\'"])((?:\\\\.|(?!\1).)*)\1/s', $body, $um, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			foreach ( $um as $index => $match ) {
				$title = self::unescapeQuoted( $match[2][0] );
				if ( self::looksLikeEventName( $title ) ) {
					$names[ $title ] = self::entryIsPro( $body, $um, $index );
				}
			}
			if ( \count( $names ) < 2 ) {
				$names = array();
			}
		}
		// Last, the plain (untranslated) string list a callback loops over to build its titles
		// (Fluent Booking's `$types = ['Booking Scheduled', …]`).
		if ( ! $names && false !== strpos( $body, "'title'" ) ) {
			foreach ( self::plainStringList( $body ) as $title ) {
				if ( self::looksLikeEventName( $title ) ) {
					$names[ $title ] = false;
				}
			}
		}
		$events = array();
		foreach ( $names as $name => $is_pro ) {
			$events[] = array(
				'name'  => (string) $name,
				'isPro' => (bool) $is_pro,
			);
		}

		return $events;
	}

	/**
	 * Whether the list entry a matched title belongs to carries its own `'isPro' => true`. The entry
	 * runs from that title to the start of the next one, which keeps the flag with the event that
	 * declares it.
	 *
	 * @param array<int,array<int,array{0:string,1:int}>> $matches PREG_OFFSET_CAPTURE set
	 */
	private static function entryIsPro( $body, array $matches, $index ) {
		$start = $matches[ $index ][0][1];
		$end   = isset( $matches[ $index + 1 ] ) ? $matches[ $index + 1 ][0][1] : \strlen( $body );

		return (bool) preg_match( '/[\'"]isPro[\'"]\s*=>\s*true/', substr( $body, $start, $end - $start ) );
	}

	/**
	 * Static calls a route callback forwards its list to (`VoxelTasks::getTaskList()`), excluding the
	 * plugin's own guard/response helpers.
	 *
	 * @return string[] method names
	 */
	private static function delegatedMethods( $body ) {
		$methods = array();
		if ( ! preg_match_all( '/\b[A-Za-z_][\w\\\\]*::(\w+)\s*\(\s*\)/', $body, $m ) ) {
			return $methods;
		}
		foreach ( $m[1] as $method ) {
			if ( ! preg_match( '/^(?:is|has|get)?(?:PluginActive|Active|Installed|Activated)$/i', $method ) ) {
				$methods[ $method ] = true;
			}
		}

		return array_keys( $methods );
	}

	/**
	 * The first plain array of quoted strings in a body (`['Booking Scheduled', 'Booking Completed']`).
	 *
	 * @return string[]
	 */
	private static function plainStringList( $body ) {
		if ( ! preg_match( '/=\s*\[\s*([\'"][^\[\]]*?[\'"])\s*,?\s*\]/s', $body, $m ) ) {
			return array();
		}
		if ( ! preg_match_all( '/([\'"])((?:\\\\.|(?!\1).)*)\1/s', $m[1], $sm, PREG_SET_ORDER ) ) {
			return array();
		}
		$values = array();
		foreach ( $sm as $match ) {
			// Only a list of written-out titles qualifies; an options/query array (`'post_type'`,
			// `'publish'`) is not an event list.
			if ( ! preg_match( '/^[A-Z][^_]*\s\S/', $match[2] ) ) {
				return array();
			}
			$values[] = self::unescapeQuoted( $match[2] );
		}

		return \count( $values ) >= 2 ? $values : array();
	}

	/**
	 * An interpolated title ("Piotnet Forms - {$form->settings->form_id}") names a per-site entity the
	 * flow builder lists at runtime, not a catalog event.
	 */
	private static function isInterpolated( $text ) {
		return false !== strpos( $text, '$' ) || false !== strpos( $text, '{' );
	}

	/** Heuristic: a short static title, not an interpolated label or an error/notice string. */
	private static function looksLikeEventName( $text ) {
		if ( self::isInterpolated( $text ) ) {
			return false;
		}
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
		return self::pairTaskTokens( self::readPhp( $absFile ) );
	}

	/**
	 * Pair each `form_name` with its `triggered_entity_id`. The two keys are scanned independently and
	 * paired in source order rather than matched by one spanning pattern, because the task lists are
	 * not uniform: some modules write the hook first (Post), and labels use either quote style with
	 * escaped apostrophes inside (`__("User's Membership Deleted")`). A spanning match mis-pairs the
	 * former (label N with hook N+1) and truncates the latter at the apostrophe.
	 *
	 * @return array<string,string> hook => label
	 */
	private static function pairTaskTokens( $contents ) {
		$labels = array();
		if ( '' === $contents ) {
			return $labels;
		}
		// 1 = key, 2 = quote char, 3 = value (escape-aware, must close on the same quote).
		if ( ! preg_match_all(
			'/[\'"](form_name|triggered_entity_id)[\'"]\s*=>\s*(?:__\(\s*)?([\'"])((?:\\\\.|(?!\2).)*)\2/s',
			$contents,
			$matches,
			PREG_SET_ORDER
		) ) {
			return $labels;
		}

		$name = null;
		$hook = null;
		foreach ( $matches as $token ) {
			$value = str_replace( array( "\\'", '\\"' ), array( "'", '"' ), $token[3] );
			if ( 'form_name' === $token[1] ) {
				$name = $value; // A second label before its hook means the previous entry had none.
			} else {
				$hook = $value;
			}
			if ( null === $name || null === $hook ) {
				continue;
			}
			if ( ! isset( $labels[ $hook ] ) ) {
				$labels[ $hook ] = $name;
			}
			$name = null;
			$hook = null;
		}

		return $labels;
	}

	/**
	 * The same task list as biStaticTaskLabels(), for trigger modules that declare it inside their
	 * controller (e.g. BitCrm's private events()) instead of a dedicated StaticData.php. Scans every
	 * PHP file in the module directory; the first file to name a hook wins.
	 *
	 * @return array<string,string>
	 */
	public static function biTriggerTaskLabels( $absDir ) {
		$labels = array();
		foreach ( glob( $absDir . '/*.php' ) ?: array() as $file ) {
			foreach ( self::biStaticTaskLabels( $file ) as $hook => $label ) {
				if ( ! isset( $labels[ $hook ] ) ) {
					$labels[ $hook ] = $label;
				}
			}
		}

		return $labels;
	}

	/**
	 * An action's operation list, read from the `modules` array its frontend declares (either
	 * `const modules = [ … ]` or a `modules: [ … ]` property, e.g. ZendeskSupportStaticData.modules).
	 * Each entry is `{ value|name: '<op>', label: __('Real Name'), is_pro?: bool, group?: '…' }`.
	 * This is exactly the operation dropdown the Flow builder shows, so it is the authoritative
	 * per-action operation set. Field-option dropdowns (priorities, statuses, …) are NOT named
	 * `modules`, so they are not picked up.
	 *
	 * @return array<int,array{value:string,label:string,isPro:bool,group:string}>
	 */
	public static function frontendActionModules( $absDir ) {
		if ( ! is_dir( $absDir ) ) {
			return array();
		}
		$files = array_merge( glob( $absDir . '/*.jsx' ) ?: array(), glob( $absDir . '/*.js' ) ?: array() );

		// Pass 1: an explicit `modules` array (ZendeskSupport, WP ERP, HefflCRM, …).
		foreach ( $files as $file ) {
			$ops = self::parseModulesArray( self::read( $file ) );
			if ( $ops ) {
				return $ops;
			}
		}
		// Pass 2: the array the operation `<select>` renders its options from, under whatever name the
		// integration gave it (WooCommerce `moduleType`, GamiPress `allActions`).
		$ops = self::parseSelectDrivenOps( $files );
		if ( $ops ) {
			return $ops;
		}
		// Pass 3: an operation list identified by per-entry `is_pro` flags — the operation dropdown
		// (e.g. Registration, PostCreation in <X>HelperFunction.js). Field-option dropdowns
		// (priorities, statuses, languages) carry no is_pro, so they are not matched.
		foreach ( $files as $file ) {
			$ops = self::parseIsProActionOps( self::read( $file ) );
			if ( $ops ) {
				return $ops;
			}
		}

		return array();
	}

	/**
	 * The operation dropdown resolved through the JSX that renders it: find the `<select>` whose
	 * `name` is the operation selector the backend switches on, take the array it maps its `<option>`s
	 * from, and parse that array wherever in the integration's frontend folder it is declared
	 * (`GamiPress.jsx` declares `allActions`, `GamiPressIntegLayout.jsx` renders it). Anchoring on the
	 * select is what keeps field-option arrays (statuses, priorities) out of the result.
	 *
	 * @param string[] $files
	 * @return array<int,array{value:string,label:string,isPro:bool,group:string}>
	 */
	private static function parseSelectDrivenOps( array $files ) {
		$sources = array();
		foreach ( $files as $file ) {
			$sources[] = self::read( $file );
		}
		foreach ( $sources as $src ) {
			foreach ( self::proGatedArrayNames( $src ) as $name ) {
				foreach ( $sources as $lookup ) {
					$ops = self::parseNamedArray( $lookup, $name );
					if ( $ops ) {
						return $ops;
					}
				}
			}
		}
		foreach ( $sources as $src ) {
			foreach ( self::operationSelectBodies( $src ) as $body ) {
				$ops = self::selectLiteralOptions( $body );
				if ( $ops ) {
					return $ops;
				}
				foreach ( self::mappedArrayNames( $body ) as $name ) {
					foreach ( $sources as $lookup ) {
						$ops = self::parseNamedArray( $lookup, $name );
						if ( $ops ) {
							return $ops;
						}
					}
				}
			}
		}

		return array();
	}

	/**
	 * Names of the arrays an integration renders through `checkIsPro(isPro, x.is_pro)` — the helper the
	 * layouts use to Pro-gate an operation option. It marks the operation list wherever it is rendered,
	 * including the custom dropdowns that take `options={…}` instead of `<option>` children
	 * (MasterStudy LMS `allActions`). Field-option arrays are never gated this way.
	 *
	 * @return string[]
	 */
	private static function proGatedArrayNames( $contents ) {
		$names = array();
		if ( ! preg_match_all( '/\bcheckIsPro\s*\(/', $contents, $gm, PREG_OFFSET_CAPTURE ) ) {
			return $names;
		}
		foreach ( $gm[0] as $gate ) {
			$before = substr( $contents, 0, $gate[1] );
			if ( ! preg_match_all( '/([A-Za-z_$][\w$]*(?:\??\.[A-Za-z_$][\w$]*)*)\s*\??\.map\s*\(/', $before, $maps ) ) {
				continue;
			}
			$parts   = preg_split( '/\??\./', end( $maps[1] ) );
			$names[] = end( $parts );
		}

		return array_values( array_unique( $names ) );
	}

	/**
	 * Inner markup of every `<select>` whose `name` is the operation selector the backend switches on.
	 *
	 * @return string[]
	 */
	private static function operationSelectBodies( $contents ) {
		$selectors = array( 'module', 'mainaction', 'actionname', 'action', 'actiontype', 'maintask', 'selectedtask', 'task', 'operation' );
		$bodies    = array();
		if ( ! preg_match_all( '#<select\b([^>]*)>(.*?)</select>#s', $contents, $selects, PREG_SET_ORDER ) ) {
			return $bodies;
		}
		foreach ( $selects as $select ) {
			if ( preg_match( '/\bname\s*=\s*"([^"]+)"/', $select[1], $nm )
				&& \in_array( strtolower( $nm[1] ), $selectors, true ) ) {
				$bodies[] = $select[2];
			}
		}

		return $bodies;
	}

	/**
	 * Operations written as literal `<option value="task">{__('Create Task')}</option>` children
	 * instead of a mapped array (Asana). The empty-value placeholder option is not an operation.
	 *
	 * @return array<int,array{value:string,label:string,isPro:bool,group:string}>
	 */
	private static function selectLiteralOptions( $body ) {
		$ops = array();
		if ( ! preg_match_all( '#<option\b([^>]*)>(.*?)</option>#s', $body, $options, PREG_SET_ORDER ) ) {
			return $ops;
		}
		foreach ( $options as $option ) {
			if ( ! preg_match( '/\bvalue\s*=\s*"([^"]+)"/', $option[0], $vm ) ) {
				continue;
			}
			if ( preg_match( '/__\(\s*([\'"])((?:\\\\.|(?!\1).)*)\1/', $option[2], $lm ) ) {
				$label = self::unescapeQuoted( $lm[2] );
			} else {
				$label = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $option[2] ) ) );
			}
			if ( '' === $label || false !== strpos( $label, '{' ) ) {
				continue;
			}
			list( $label, $labelled_pro ) = self::splitProLabel( $label );
			$ops[]                        = array(
				'value' => $vm[1],
				'label' => $label,
				'isPro' => $labelled_pro,
				'group' => '',
			);
		}

		return $ops;
	}

	/**
	 * Names of the arrays a select maps its options from, in source order. A member expression keeps
	 * its last segment (`gamiPressConf.allActions` => `allActions`).
	 *
	 * @return string[]
	 */
	private static function mappedArrayNames( $body ) {
		if ( ! preg_match_all( '/([A-Za-z_$][\w$]*(?:\??\.[A-Za-z_$][\w$]*)*)\s*\??\.map\s*\(/', $body, $maps ) ) {
			return array();
		}
		$names = array();
		foreach ( $maps[1] as $expression ) {
			$parts   = preg_split( '/\??\./', $expression );
			$names[] = end( $parts );
		}

		return array_values( array_unique( $names ) );
	}

	/** Parse a `const|let|var <name> = [ … ]` / `<name>: [ … ]` operation array (bracket-aware). */
	private static function parseNamedArray( $contents, $name ) {
		if ( ! preg_match( '/\b' . preg_quote( $name, '/' ) . '\s*(?:=|:)\s*\[/', $contents, $m, PREG_OFFSET_CAPTURE ) ) {
			return array();
		}
		$i     = $m[0][1] + \strlen( $m[0][0] );
		$len   = \strlen( $contents );
		$depth = 1;
		$start = $i;
		while ( $i < $len && $depth > 0 ) {
			if ( '[' === $contents[ $i ] ) {
				++$depth;
			} elseif ( ']' === $contents[ $i ] ) {
				--$depth;
			}
			++$i;
		}

		return self::moduleObjects( substr( $contents, $start, $i - $start ) );
	}

	/** Parse the `modules: [ … ]` / `const modules = [ … ]` operation array (bracket-aware). */
	private static function parseModulesArray( $contents ) {
		if ( ! preg_match( '/\bmodules\s*[:=]\s*\[/', $contents, $m, PREG_OFFSET_CAPTURE ) ) {
			return array();
		}
		$i     = $m[0][1] + \strlen( $m[0][0] );
		$len   = \strlen( $contents );
		$depth = 1;
		$start = $i;
		while ( $i < $len && $depth > 0 ) {
			if ( '[' === $contents[ $i ] ) {
				++$depth;
			} elseif ( ']' === $contents[ $i ] ) {
				--$depth;
			}
			++$i;
		}

		return self::moduleObjects( substr( $contents, $start, $i - $start ) );
	}

	/** Treat every `{ value|name, label, is_pro }` object in a file as one operation (≥2 to qualify). */
	private static function parseIsProActionOps( $contents ) {
		$ops = array();
		if ( preg_match_all( '/\{[^{}]*\}/', $contents, $objs ) ) {
			foreach ( $objs[0] as $obj ) {
				if ( false === strpos( $obj, 'is_pro' ) && false === strpos( $obj, 'isPro' ) ) {
					continue;
				}
				foreach ( self::moduleObjects( $obj ) as $op ) {
					$ops[ $op['value'] ] = $op;
				}
			}
		}

		return \count( $ops ) >= 2 ? array_values( $ops ) : array();
	}

	/** Extract `{ value|name, label, is_pro?, group? }` operation entries from a JS object/array blob. */
	private static function moduleObjects( $block ) {
		$ops = array();
		if ( preg_match_all( '/\{[^{}]*\}/', $block, $objs ) ) {
			foreach ( $objs[0] as $obj ) {
				// The operation value is a quoted string, or the SCREAMING_CASE constant an integration
				// defines for it (BuddyBoss `key: CREATE_GROUP_PRO`), whose `_PRO` suffix is the same
				// tier marker the backend operation enum uses.
				if ( ! preg_match( '/\b(?:value|name|key)\s*:\s*(?:([\'"])((?:\\\\.|(?!\1).)*)\1|(?:[A-Za-z_$][\w$]*\.)*([A-Z][A-Z0-9_]*))/', $obj, $vm ) ) {
					continue;
				}
				$label = self::jsLabel( $obj );
				if ( '' === $label ) {
					continue;
				}
				$constant                     = isset( $vm[3] ) ? $vm[3] : '';
				$value                        = '' !== $constant ? $constant : self::unescapeQuoted( $vm[2] );
				list( $label, $labelled_pro ) = self::splitProLabel( $label );
				$ops[]                        = array(
					'value' => $value,
					'label' => $label,
					'isPro' => $labelled_pro
						|| ( '' !== $constant && '_PRO' === substr( $constant, -4 ) )
						|| (bool) preg_match( '/\bis_?[pP]ro\s*:\s*true/', $obj ),
					'group' => self::firstMatch( "/\bgroup\s*:\s*'([^']+)'/", $obj ),
				);
			}
		}

		return $ops;
	}

	/** The `label: __('…')` / `label: "…"` text of a JS object entry ('' when it has none). */
	private static function jsLabel( $obj ) {
		if ( ! preg_match( '/\blabel\s*:\s*(?:__\(\s*)?([\'"])((?:\\\\.|(?!\1).)*)\1/', $obj, $m ) ) {
			return '';
		}

		return self::unescapeQuoted( $m[2] );
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
				list( $label, $labelled_pro ) = self::splitProLabel( $lm[1] );
				$is_pro                       = $labelled_pro ? true : null;
				if ( preg_match( '/\bis_?[pP]ro\s*:\s*(true|false)/', $obj, $pm ) ) {
					$is_pro = 'true' === $pm[1];
				}
				$map[ $key ] = array(
					'label' => $label,
					'isPro' => $is_pro,
				);
			}
		}

		return $map;
	}

	/**
	 * The action catalog the Flow builder offers: every `{ type: '...', is_pro: bool }` in the
	 * SelectAction.jsx `integs` array, in source order. The `is_pro` flag is the product's own
	 * authoritative "fully pro" signal for an action — true only when EVERY operation the action
	 * exposes is Pro (the Flow builder ANDs each module's `is_pro`). It is the source of truth for
	 * the action's tier; do not re-derive "fully pro" from backend heuristics.
	 *
	 * @return array<int,array{name:string,isPro:bool}>
	 */
	public static function parseSelectActions( $absFile ) {
		$contents = self::read( $absFile );
		$rows     = array();
		if ( '' === $contents ) {
			return $rows;
		}
		$start = strpos( $contents, 'integs = [' );
		if ( false === $start ) {
			return $rows;
		}
		$end   = strpos( $contents, "\n  ]", $start );
		$block = false === $end ? substr( $contents, $start ) : substr( $contents, $start, $end - $start );

		// Each `{ type: '...', logo?: '...', is_pro: bool }` is one action entry.
		if ( preg_match_all( '/\{[^{}]*\}/', $block, $objs ) ) {
			foreach ( $objs[0] as $obj ) {
				if ( ! preg_match( "/type:\s*'([^']+)'/", $obj, $tm ) ) {
					continue;
				}
				$rows[] = array(
					'name'  => $tm[1],
					'isPro' => (bool) preg_match( '/is_pro:\s*true/', $obj ),
				);
			}
		}

		return $rows;
	}

	/**
	 * The action catalog display names only (SelectAction.jsx `integs`), in source order.
	 *
	 * @return string[] action display names
	 */
	public static function parseSelectActionTypes( $absFile ) {
		return array_column( self::parseSelectActions( $absFile ), 'name' );
	}

	/** Trigger catalog names (with isPro) from AllTriggersName.php. */
	public static function parseAllTriggers( $absFile ) {
		$contents = self::readPhp( $absFile );
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
