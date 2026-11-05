<?php

namespace BitApps\Audit;

defined( 'ABSPATH' ) || exit;

final class Exporter {

	public static function handle() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'bit-audit' ) );
		}
		check_admin_referer( 'bit_audit_export' );

		$family = isset( $_GET['family'] ) ? sanitize_key( wp_unslash( $_GET['family'] ) ) : Detector::defaultFamily();
		if ( ! isset( Detector::FAMILIES[ $family ] ) ) {
			$family = Detector::defaultFamily();
		}
		$format = isset( $_GET['format'] ) && $_GET['format'] === 'csv' ? 'csv' : 'json';

		$report = self::withEvents( Detector::report( $family ), Detector::auditor( $family ) );
		$report = array_merge(
			array(
				'exported_at'   => gmdate( 'c' ),
				'audit_version' => BIT_AUDIT_VERSION,
			),
			$report
		);

		$filename = 'bit-audit-' . $family . '-' . gmdate( 'Ymd-His' );

		if ( $format === 'json' ) {
			self::json( $report, $filename );
		} else {
			self::csv( $report, $filename );
		}
	}

	/**
	 * @param array<string,mixed> $report
	 * @return array<string,mixed>
	 */
	private static function withEvents( array $report, AuditorInterface $auditor ) {
		if ( ! isset( $report['catalog']['per_integration'] ) || ! \is_array( $report['catalog']['per_integration'] ) ) {
			return $report;
		}
		foreach ( $report['catalog']['per_integration'] as &$row ) {
			$detail          = $auditor->events( $row['slug'] );
			$row['found']    = ! empty( $detail['found'] );
			$row['triggers'] = isset( $detail['triggers'] ) ? $detail['triggers'] : array();
			$row['actions']  = isset( $detail['actions'] ) ? $detail['actions'] : array();
		}
		unset( $row );

		return $report;
	}

	private static function json( $report, $filename ) {
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '.json"' );
		echo wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		exit;
	}

	/**
	 * One CSV holding several tables, each with its own header row and a blank row between them:
	 * report, overview, latest integrations, integrations, events.
	 */
	private static function csv( $report, $filename ) {
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '.csv"' );

		$out = fopen( 'php://output', 'w' );
		if ( false === $out ) {
			exit;
		}
		$cat       = $report['catalog'];
		$changelog = $report['changelog'];
		$presence  = isset( $report['presence'] ) ? $report['presence'] : array();

		self::row( $out, array( 'Report', 'Value' ) );
		self::row( $out, array( 'Family', $report['family'] ) );
		self::row( $out, array( 'Label', $report['label'] ) );
		self::row( $out, array( 'Exported at (UTC)', $report['exported_at'] ) );
		self::row( $out, array( 'Bit Audit version', $report['audit_version'] ) );
		self::row( $out, array( 'Free installed', self::yesNo( ! empty( $presence['free'] ) ) ) );
		self::row( $out, array( 'Pro installed', self::yesNo( ! empty( $presence['pro'] ) ) ) );
		self::row( $out, array( 'Active', self::yesNo( ! empty( $presence['active'] ) ) ) );

		// An unavailable report carries no catalog (plugin not installed, or a built release with no
		// frontend source). Export the reason it could not be built rather than a sheet of blanks.
		if ( ! \is_array( $cat ) ) {
			self::row( $out, array( 'Status', 'Unavailable' ) );
			self::row( $out, array( 'Reason', isset( $report['reason'] ) ? $report['reason'] : '' ) );
			self::row( $out, array( 'Message', isset( $report['message'] ) ? $report['message'] : '' ) );
			fclose( $out );
			exit;
		}

		self::blank( $out );
		self::row( $out, array( 'Metric', 'Total', 'Free', 'Pro', 'Note' ) );
		self::row( $out, array( 'Total Integrations', $cat['total_integrations'], '', '', 'Triggers + Actions' ) );
		self::row( $out, array( 'Platform Integrations', isset( $cat['platform_integrations'] ) ? $cat['platform_integrations'] : $cat['total_integrations'], '', '', 'Unique apps' ) );
		self::row( $out, array( 'Triggers', $cat['trigger_apps'], self::nested( $cat, array( 'apps', 'trigger', 'free' ) ), self::nested( $cat, array( 'apps', 'trigger', 'pro' ) ), '' ) );
		self::row( $out, array( 'Actions', $cat['action_apps'], self::nested( $cat, array( 'apps', 'action', 'free' ) ), self::nested( $cat, array( 'apps', 'action', 'pro' ) ), '' ) );
		self::row( $out, array( 'Trigger Events', self::scalar( $cat['total_trigger_events'] ), self::scalar( $cat['split']['free']['trigger_events'] ), self::scalar( $cat['split']['pro']['trigger_events'] ), '' ) );
		self::row( $out, array( 'Action Events', self::scalar( $cat['total_action_events'] ), self::scalar( $cat['split']['free']['action_events'] ), self::scalar( $cat['split']['pro']['action_events'] ), '' ) );

		if ( $changelog && ( $changelog['triggers'] || $changelog['actions'] ) ) {
			self::blank( $out );
			self::row( $out, array( 'Latest Integrations', 'Type', 'Events', 'Slug', 'Version', 'Date' ) );
			foreach ( array(
				'Trigger' => $changelog['triggers'],
				'Action'  => $changelog['actions'],
			) as $type => $items ) {
				foreach ( $items as $item ) {
					self::row(
						$out,
						array(
							$item['name'],
							$type,
							self::scalar( $item['events'] ),
							isset( $item['slug'] ) ? $item['slug'] : '',
							$changelog['version'],
							isset( $changelog['date'] ) ? $changelog['date'] : '',
						)
					);
				}
			}
		}

		self::blank( $out );
		self::row( $out, array( '#', 'Integration', 'Slug', 'Type', 'Tier', 'Trigger Events', 'Action Events', 'Events Parsed' ) );
		$sn = 0;
		foreach ( $cat['per_integration'] as $row ) {
			self::row(
				$out,
				array(
					++$sn,
					$row['name'],
					$row['slug'],
					self::type( $row ),
					self::tier( $row ),
					self::scalar( $row['trigger_events'] ),
					self::scalar( $row['action_events'] ),
					self::yesNo( ! empty( $row['found'] ) ),
				)
			);
		}

		self::blank( $out );
		self::row( $out, array( 'Integration', 'Integration Slug', 'Kind', 'Event', 'WordPress Hook', 'Event Slug', 'Group', 'Tier' ) );
		foreach ( $cat['per_integration'] as $row ) {
			foreach ( array(
				'Trigger' => $row['triggers'],
				'Action'  => $row['actions'],
			) as $kind => $events ) {
				foreach ( $events as $event ) {
					self::row(
						$out,
						array(
							$row['name'],
							$row['slug'],
							$kind,
							$event['name'],
							isset( $event['hook'] ) ? $event['hook'] : '',
							$event['slug'],
							$event['group'],
							! empty( $event['isPro'] ) ? 'Pro' : 'Free',
						)
					);
				}
			}
		}

		fclose( $out );
		exit;
	}

	private static function type( array $row ) {
		$is_trigger = ! isset( $row['isTrigger'] ) || $row['isTrigger'];
		$is_action  = ! isset( $row['isAction'] ) || $row['isAction'];
		if ( $is_trigger && $is_action ) {
			return 'Both';
		}

		return $is_trigger ? 'Trigger' : 'Action';
	}

	private static function tier( array $row ) {
		$tier = isset( $row['tier'] ) ? $row['tier'] : ( $row['isPro'] ? 'pro' : 'free' );

		return ucfirst( $tier );
	}

	private static function nested( array $data, array $path ) {
		foreach ( $path as $key ) {
			if ( ! \is_array( $data ) || ! isset( $data[ $key ] ) ) {
				return '';
			}
			$data = $data[ $key ];
		}

		return $data;
	}

	private static function yesNo( $flag ) {
		return $flag ? 'Yes' : 'No';
	}

	private static function blank( $out ) {
		self::row( $out, array() );
	}

	/**
	 * Write one CSV record.
	 *
	 * $escape is passed explicitly because PHP 8.4 deprecates relying on its default, and an empty
	 * string is the RFC 4180 behaviour spreadsheets expect — the default backslash escape mangles any
	 * field ending in one.
	 *
	 * @param resource                   $out
	 * @param array<int,string|int|null> $fields
	 */
	private static function row( $out, array $fields ) {
		fputcsv( $out, $fields, ',', '"', '' );
	}

	private static function scalar( $v ) {
		return null === $v ? 'dynamic' : (string) $v;
	}
}
