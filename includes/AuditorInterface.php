<?php

namespace BitApps\Audit;

defined( 'ABSPATH' ) || exit;

interface AuditorInterface {

	/**
	 * Full report (catalog + latest-from-changelog).
	 *
	 * [
	 *   'family'   => 'bit-pi'|'bit-integrations',
	 *   'label'    => string,
	 *   'presence' => ['free'=>bool,'pro'=>bool,'active'=>bool],
	 *   'catalog'  => [
	 *       'total_integrations','trigger_apps','action_apps',
	 *       'total_trigger_events','total_action_events',
	 *       'split' => ['free'=>[...],'pro'=>[...]],
	 *       'per_integration' => [ ['name','slug','isPro','trigger_events','action_events'], ... ],
	 *   ],
	 *   'changelog' => [
	 *       'version','date',
	 *       'triggers' => [ ['name','events'], ... ],
	 *       'actions'  => [ ['name','events'], ... ],
	 *   ] | null,
	 * ]
	 *
	 * @return array<string,mixed>
	 */
	public function report();

	/**
	 * Event-level detail for a single integration (drill-down page).
	 *
	 * [
	 *   'name','slug','isPro','found' => bool,
	 *   'triggers' => [ ['name','hook','slug','group','isPro'], ... ],
	 *   'actions'  => [ ['name','slug','group','isPro'], ... ],
	 * ]
	 *
	 * @param string $slug Integration slug.
	 * @return array<string,mixed>
	 */
	public function events( $slug );
}
