<?php

namespace BitApps\Audit;

defined( 'ABSPATH' ) || exit;

/** @var array  $families */
/** @var string $family */
/** @var array  $report */
?>
<div class="wrap bit-audit" id="bit-audit-app">

	<header class="ba-hero">
		<div class="ba-hero-main">
			<span class="ba-hero-icon dashicons dashicons-chart-area"></span>
			<div>
				<h1><?php esc_html_e( 'Bit Audit', 'bit-audit' ); ?></h1>
				<p><?php esc_html_e( 'Pick a plugin — its Free + Pro are combined into one report of integrations, triggers, actions and events.', 'bit-audit' ); ?></p>
			</div>
		</div>
		<div class="ba-hero-actions">
			<a class="ba-btn ba-btn-ghost" id="ba-refresh" href="<?php echo esc_url( AdminPage::refresh_url( $family ) ); ?>" title="<?php esc_attr_e( 'Rebuild the cached report', 'bit-audit' ); ?>">
				<span class="dashicons dashicons-update"></span><?php esc_html_e( 'Refresh', 'bit-audit' ); ?>
			</a>
			<a class="ba-btn ba-btn-ghost" id="ba-export-json" href="<?php echo esc_url( AdminPage::export_url( $family, 'json' ) ); ?>">
				<span class="dashicons dashicons-media-code"></span><?php esc_html_e( 'JSON', 'bit-audit' ); ?>
			</a>
			<a class="ba-btn ba-btn-ghost" id="ba-export-csv" href="<?php echo esc_url( AdminPage::export_url( $family, 'csv' ) ); ?>">
				<span class="dashicons dashicons-media-spreadsheet"></span><?php esc_html_e( 'CSV', 'bit-audit' ); ?>
			</a>
		</div>
	</header>

	<nav class="ba-switch" role="tablist" aria-label="<?php esc_attr_e( 'Choose a plugin', 'bit-audit' ); ?>">
		<?php foreach ( $families as $key => $f ) : ?>
			<button
				type="button"
				class="ba-pill <?php echo $key === $family ? 'is-active' : ''; ?>"
				data-family="<?php echo esc_attr( $key ); ?>"
				role="tab"
				aria-controls="ba-report"
				aria-selected="<?php echo $key === $family ? 'true' : 'false'; ?>">
				<span class="ba-pill-label"><?php echo esc_html( $f['label'] ); ?></span>
			</button>
		<?php endforeach; ?>
		<span class="ba-switch-status">
			<span class="ba-dot <?php echo $report['presence']['active'] ? 'live' : 'idle'; ?>"></span>
			<span id="ba-active-label"><?php echo esc_html( $report['presence']['active'] ? __( 'Active', 'bit-audit' ) : __( 'Inactive', 'bit-audit' ) ); ?></span>
		</span>
	</nav>

	<div id="ba-report" class="ba-report" role="tabpanel" tabindex="0" aria-live="polite">
		<?php require BIT_AUDIT_DIR . 'templates/report-body.php'; ?>
	</div>

	<div id="ba-skeleton" class="ba-skeleton" hidden>
		<div class="ba-sk-cards">
			<?php for ( $i = 0; $i < 5; $i++ ) : ?>
				<div class="ba-sk-card"></div>
			<?php endfor; ?>
		</div>
		<div class="ba-sk-cards">
			<?php for ( $i = 0; $i < 6; $i++ ) : ?>
				<div class="ba-sk-card"></div>
			<?php endfor; ?>
		</div>
		<div class="ba-sk-table"></div>
	</div>
</div>
