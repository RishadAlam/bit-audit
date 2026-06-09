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

		$report   = Detector::report( $family );
		$filename = 'bit-audit-' . $family . '-' . gmdate( 'Ymd-His' );

		if ( $format === 'json' ) {
			self::json( $report, $filename );
		} else {
			self::csv( $report, $filename );
		}
	}

	private static function json( $report, $filename ) {
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '.json"' );
		echo wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		exit;
	}

	private static function csv( $report, $filename ) {
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '.csv"' );

		$out       = fopen( 'php://output', 'w' );
		$cat       = $report['catalog'];
		$changelog = $report['changelog'];

		fputcsv( $out, array( 'Section', 'Metric', 'Value', 'Detail' ) );

		// Summary.
		$rows = array(
			array( 'Catalog', 'Total Integrations', $cat['total_integrations'], 'triggers + actions' ),
			array( 'Catalog', 'Platform Integrations', isset( $cat['platform_integrations'] ) ? $cat['platform_integrations'] : $cat['total_integrations'], 'unique apps' ),
			array( 'Catalog', 'Triggers', $cat['trigger_apps'], '' ),
			array( 'Catalog', 'Actions', $cat['action_apps'], '' ),
			array( 'Catalog', 'Total Trigger Events', self::scalar( $cat['total_trigger_events'] ), 'free=' . self::scalar( $cat['split']['free']['trigger_events'] ) . ' pro=' . self::scalar( $cat['split']['pro']['trigger_events'] ) ),
			array( 'Catalog', 'Total Action Events', self::scalar( $cat['total_action_events'] ), 'free=' . self::scalar( $cat['split']['free']['action_events'] ) . ' pro=' . self::scalar( $cat['split']['pro']['action_events'] ) ),
		);
		foreach ( $rows as $r ) {
			fputcsv( $out, $r );
		}

		// Latest integrations from changelog.
		if ( $changelog ) {
			foreach ( $changelog['triggers'] as $item ) {
				fputcsv( $out, array( 'Latest trigger', $item['name'], $item['events'], $changelog['version'] ) );
			}
			foreach ( $changelog['actions'] as $item ) {
				fputcsv( $out, array( 'Latest action', $item['name'], $item['events'], $changelog['version'] ) );
			}
		}

		// Per integration.
		foreach ( $cat['per_integration'] as $row ) {
			$types = array();
			if ( ! isset( $row['isTrigger'] ) || $row['isTrigger'] ) {
				$types[] = 'trigger';
			}
			if ( ! isset( $row['isAction'] ) || $row['isAction'] ) {
				$types[] = 'action';
			}
			fputcsv(
				$out,
				array(
					'Integration',
					$row['name'],
					'trigger_events=' . self::scalar( $row['trigger_events'] ) . ' action_events=' . self::scalar( $row['action_events'] ),
					'type=' . implode( '+', $types ) . ' tier=' . ( isset( $row['tier'] ) ? $row['tier'] : ( $row['isPro'] ? 'pro' : 'free' ) ),
				)
			);
		}

		fclose( $out );
		exit;
	}

	private static function scalar( $v ) {
		return null === $v ? 'dynamic' : (string) $v;
	}
}
