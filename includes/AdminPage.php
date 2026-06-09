<?php

namespace BitApps\Audit;

defined( 'ABSPATH' ) || exit;

final class AdminPage {

	/** Enqueue assets only on our screen. */
	public static function enqueue( $hook ) {
		if ( 'toplevel_page_bit-audit' !== $hook ) {
			return;
		}
		wp_enqueue_style( 'dashicons' );
		wp_enqueue_style( 'bit-audit', BIT_AUDIT_URL . 'assets/admin.css', array(), BIT_AUDIT_VERSION );
		wp_enqueue_script( 'bit-audit', BIT_AUDIT_URL . 'assets/admin.js', array(), BIT_AUDIT_VERSION, true );
		wp_localize_script(
			'bit-audit',
			'bitAudit',
			array(
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'nonce'         => wp_create_nonce( 'bit_audit_report' ),
				'exportBase'    => admin_url( 'admin-post.php' ),
				'exportNonce'   => wp_create_nonce( 'bit_audit_export' ),
				'activeLabel'   => __( 'Active', 'bit-audit' ),
				'inactiveLabel' => __( 'Inactive', 'bit-audit' ),
				'errorMessage'  => __( 'Could not load this report. Please try again.', 'bit-audit' ),
			)
		);
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'bit-audit' ) );
		}

		$families = Detector::detect();
		// Read-only navigation parameters (which family / integration to display); sanitized and
		// validated below. No state change, so no nonce required.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$family      = isset( $_GET['family'] ) ? sanitize_key( wp_unslash( $_GET['family'] ) ) : Detector::defaultFamily();
		$integration = isset( $_GET['integration'] ) ? sanitize_text_field( wp_unslash( $_GET['integration'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $families[ $family ] ) ) {
			$family = Detector::defaultFamily();
		}

		$auditor = Detector::auditor( $family );

		if ( '' !== $integration ) {
			$detail   = $auditor->events( $integration );
			$back_url = add_query_arg(
				array(
					'page'   => 'bit-audit',
					'family' => $family,
				),
				admin_url( 'admin.php' )
			);
			include BIT_AUDIT_DIR . 'templates/detail.php';
			return;
		}

		$report = Detector::report( $family );
		include BIT_AUDIT_DIR . 'templates/dashboard.php';
	}

	/** AJAX: return the report body fragment for a chosen family. */
	public static function ajax() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'bit-audit' ) ), 403 );
		}
		check_ajax_referer( 'bit_audit_report', 'nonce' );

		$families = Detector::detect();
		$family   = isset( $_POST['family'] ) ? sanitize_key( wp_unslash( $_POST['family'] ) ) : Detector::defaultFamily();
		if ( ! isset( $families[ $family ] ) ) {
			$family = Detector::defaultFamily();
		}

		$report = Detector::report( $family );

		wp_send_json_success(
			array(
				'family'   => $family,
				'presence' => $report['presence'],
				'html'     => self::body( $report ),
			)
		);
	}

	/** Clear cached reports, then return to the dashboard. */
	public static function refresh() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'bit-audit' ) );
		}
		check_admin_referer( 'bit_audit_refresh' );

		Detector::flush();

		$family = isset( $_GET['family'] ) ? sanitize_key( wp_unslash( $_GET['family'] ) ) : Detector::defaultFamily();
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'      => 'bit-audit',
					'family'    => $family,
					'refreshed' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/** Render the metrics body to a string (used by initial render + AJAX). */
	public static function body( $report ) {
		ob_start();
		include BIT_AUDIT_DIR . 'templates/report-body.php';
		return ob_get_clean();
	}

	/** A metric card. */
	public static function card( $label, $value, $sub = '', $icon = 'chart-bar', $accent = 'indigo' ) {
		$numeric = ( null !== $value && is_numeric( $value ) );
		printf(
			'<div class="ba-card ba-accent-%s">
				<div class="ba-card-top">
					<span class="ba-card-icon dashicons dashicons-%s"></span>
				</div>
				<div class="ba-card-value"%s>%s</div>
				<div class="ba-card-label">%s</div>
				%s
			</div>',
			esc_attr( $accent ),
			esc_attr( $icon ),
			$numeric ? ' data-count="' . esc_attr( (float) $value ) . '"' : '',
			esc_html( self::fmt( $value ) ),
			esc_html( $label ),
			'' !== $sub ? '<div class="ba-card-sub">' . esc_html( $sub ) . '</div>' : ''
		);
	}

	public static function fmt( $value ) {
		if ( null === $value ) {
			return '—';
		}
		if ( is_numeric( $value ) ) {
			return number_format_i18n( (float) $value );
		}
		return (string) $value;
	}

	/**
	 * Render a "Latest integrations" list. Items resolved to a catalog slug become clickable
	 * links to that integration's event-detail page; unresolved items stay as plain rows.
	 *
	 * @param array<int,array{name:string,events:int,slug?:string}> $items
	 * @param string                                                $family
	 */
	public static function latest_list( array $items, $family ) {
		echo '<ul class="ba-latest-list">';
		foreach ( $items as $item ) {
			$count = '<span class="ba-latest-count">' . esc_html( self::fmt( $item['events'] ) ) . '</span>';
			$slug  = isset( $item['slug'] ) ? $item['slug'] : '';
			if ( '' !== $slug ) {
				printf(
					'<li class="has-link"><a class="ba-latest-link" href="%s"><span class="ba-latest-name">%s</span><span class="ba-latest-meta">%s<span class="dashicons dashicons-arrow-right-alt2"></span></span></a></li>',
					esc_url( self::detail_url( $family, $slug ) ),
					esc_html( $item['name'] ),
					$count // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_html above.
				);
			} else {
				printf(
					'<li><span class="ba-latest-name">%s</span>%s</li>',
					esc_html( $item['name'] ),
					$count // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_html above.
				);
			}
		}
		echo '</ul>';
	}

	public static function detail_url( $family, $slug ) {
		return add_query_arg(
			array(
				'page'        => 'bit-audit',
				'family'      => $family,
				'integration' => rawurlencode( $slug ),
			),
			admin_url( 'admin.php' )
		);
	}

	public static function export_url( $family, $format ) {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=bit_audit_export&family=' . rawurlencode( $family ) . '&format=' . $format ),
			'bit_audit_export'
		);
	}

	public static function refresh_url( $family ) {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=bit_audit_refresh&family=' . rawurlencode( $family ) ),
			'bit_audit_refresh'
		);
	}
}
