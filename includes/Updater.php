<?php

namespace BitApps\Audit;

use YahnisElsts\PluginUpdateChecker\v5p7\Vcs\Api;
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

defined( 'ABSPATH' ) || exit;

/**
 * Serves plugin updates from the GitHub repository's Releases.
 *
 * Bit Audit is an internal plugin and is not on wordpress.org, so WordPress has no update source for
 * it. The update checker supplies one: it reads the repository's releases, compares the tag against
 * this file's `Version:` header, and feeds the result into the normal update UI (the plugin-list
 * notice, one-click update and the auto-update toggle all work as they would for a hosted plugin).
 */
final class Updater {

	private const REPOSITORY = 'https://github.com/RishadAlam/bit-audit/';

	public static function boot() {
		$loader = BIT_AUDIT_DIR . 'lib/plugin-update-checker/plugin-update-checker.php';
		if ( ! is_readable( $loader ) ) {
			return;
		}
		require_once $loader;

		$checker = PucFactory::buildUpdateChecker( self::REPOSITORY, BIT_AUDIT_FILE, 'bit-audit' );

		// Releases carry a built `bit-audit.zip` holding just the shipped plugin; GitHub's own source
		// archive would drag the repository's dev files (.github, composer.json, phpcs.xml.dist) into
		// the install. Preferred, not required: the checker rewrites an archive's root folder to the
		// plugin slug on its own, so a release without the asset still installs correctly.
		$checker->getVcsApi()->enableReleaseAssets( '/^bit-audit\.zip$/', Api::PREFER_RELEASE_ASSETS );
	}
}
