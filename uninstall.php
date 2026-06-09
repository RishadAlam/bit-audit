<?php
/**
 * Uninstall handler — drops the cached report transients. Bit Audit stores no other data
 * (no options, no custom tables), so there is nothing else to clean up.
 *
 * @package BitApps\Audit
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// The plugin only persists `bit_audit_rpt_*` transients. Remove them (and their timeouts) directly,
// since the plugin's own helpers are not loaded during uninstall.
$like = $wpdb->esc_like( '_transient_bit_audit_rpt_' ) . '%';
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

$like_timeout = $wpdb->esc_like( '_transient_timeout_bit_audit_rpt_' ) . '%';
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like_timeout ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
