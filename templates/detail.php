<?php

namespace BitApps\Audit;

defined( 'ABSPATH' ) || exit;

/** @var array  $detail */
/** @var string $family */
/** @var string $back_url */
/** @var array  $families */

$label         = isset( $families[ $family ]['label'] ) ? $families[ $family ]['label'] : $family;
$trigger_count = \count( $detail['triggers'] );
$action_count  = \count( $detail['actions'] );
?>
<div class="wrap bit-audit">

	<p class="ba-breadcrumb">
		<a href="<?php echo esc_url( $back_url ); ?>"><span class="dashicons dashicons-arrow-left-alt2"></span><?php echo esc_html( $label ); ?></a>
		<span class="ba-sep">/</span>
		<span class="ba-crumb-cur"><?php echo esc_html( $detail['name'] ); ?></span>
	</p>

	<header class="ba-hero ba-hero-detail">
		<div class="ba-hero-main">
			<span class="ba-hero-icon dashicons dashicons-screenoptions"></span>
			<div>
				<h1>
					<?php echo esc_html( $detail['name'] ); ?>
					<?php $ba_tier = isset( $detail['tier'] ) ? $detail['tier'] : ( $detail['isPro'] ? 'pro' : 'free' ); ?>
					<?php if ( 'both' === $ba_tier ) : ?>
						<span class="ba-tag both"><?php esc_html_e( 'Both', 'bit-audit' ); ?></span>
					<?php elseif ( 'pro' === $ba_tier ) : ?>
						<span class="ba-tag pro"><?php esc_html_e( 'Pro', 'bit-audit' ); ?></span>
					<?php else : ?>
						<span class="ba-tag free"><?php esc_html_e( 'Free', 'bit-audit' ); ?></span>
					<?php endif; ?>
				</h1>
				<p>
					<?php
					/* translators: 1: trigger event count, 2: action event count. */
					echo esc_html( sprintf( __( '%1$s trigger events · %2$s action events', 'bit-audit' ), number_format_i18n( $trigger_count ), number_format_i18n( $action_count ) ) );
					?>
				</p>
			</div>
		</div>
		<a class="ba-btn ba-btn-ghost" href="<?php echo esc_url( $back_url ); ?>"><span class="dashicons dashicons-arrow-left-alt2"></span><?php esc_html_e( 'Back', 'bit-audit' ); ?></a>
	</header>

	<?php if ( ! $detail['found'] ) : ?>
		<div class="ba-banner ba-banner-info">
			<span class="dashicons dashicons-info-outline"></span>
			<?php esc_html_e( 'No event details could be parsed for this integration (its events may be registered dynamically).', 'bit-audit' ); ?>
		</div>
	<?php endif; ?>

	<section class="ba-section">
		<div class="ba-section-head">
			<h2><span class="dashicons dashicons-controls-play"></span>
				<?php
				/* translators: %s: count. */
				echo esc_html( sprintf( __( 'Trigger events (%s)', 'bit-audit' ), number_format_i18n( $trigger_count ) ) );
				?>
			</h2>
		</div>
		<div class="ba-table-wrap">
			<table class="ba-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Event', 'bit-audit' ); ?></th>
						<th><?php esc_html_e( 'WordPress hook', 'bit-audit' ); ?></th>
						<th><?php esc_html_e( 'Slug', 'bit-audit' ); ?></th>
						<th><?php esc_html_e( 'Group', 'bit-audit' ); ?></th>
						<th><?php esc_html_e( 'Tier', 'bit-audit' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( ! $detail['triggers'] ) : ?>
					<tr><td colspan="5" class="ba-empty"><?php esc_html_e( 'No trigger events.', 'bit-audit' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $detail['triggers'] as $event ) : ?>
						<tr>
							<td class="ba-name"><?php echo esc_html( $event['name'] ); ?></td>
							<td><?php echo $event['hook'] ? '<code>' . esc_html( $event['hook'] ) . '</code>' : '<span class="ba-muted">—</span>'; ?></td>
							<td class="ba-muted"><?php echo esc_html( $event['slug'] ); ?></td>
							<td class="ba-muted"><?php echo esc_html( $event['group'] ? $event['group'] : '—' ); ?></td>
							<td><span class="ba-tag <?php echo $event['isPro'] ? 'pro' : 'free'; ?>"><?php echo $event['isPro'] ? esc_html__( 'Pro', 'bit-audit' ) : esc_html__( 'Free', 'bit-audit' ); ?></span></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>
		</div>
	</section>

	<section class="ba-section">
		<div class="ba-section-head">
			<h2><span class="dashicons dashicons-update"></span>
				<?php
				/* translators: %s: count. */
				echo esc_html( sprintf( __( 'Action events (%s)', 'bit-audit' ), number_format_i18n( $action_count ) ) );
				?>
			</h2>
		</div>
		<div class="ba-table-wrap">
			<table class="ba-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Event', 'bit-audit' ); ?></th>
						<th><?php esc_html_e( 'Slug', 'bit-audit' ); ?></th>
						<th><?php esc_html_e( 'Group', 'bit-audit' ); ?></th>
						<th><?php esc_html_e( 'Tier', 'bit-audit' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( ! $detail['actions'] ) : ?>
					<tr><td colspan="4" class="ba-empty"><?php esc_html_e( 'No action events.', 'bit-audit' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $detail['actions'] as $event ) : ?>
						<tr>
							<td class="ba-name"><?php echo esc_html( $event['name'] ); ?></td>
							<td class="ba-muted"><?php echo esc_html( $event['slug'] ); ?></td>
							<td class="ba-muted"><?php echo esc_html( $event['group'] ? $event['group'] : '—' ); ?></td>
							<td><span class="ba-tag <?php echo $event['isPro'] ? 'pro' : 'free'; ?>"><?php echo $event['isPro'] ? esc_html__( 'Pro', 'bit-audit' ) : esc_html__( 'Free', 'bit-audit' ); ?></span></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>
		</div>
	</section>
</div>
