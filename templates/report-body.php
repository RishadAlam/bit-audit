<?php

namespace BitApps\Audit;

defined( 'ABSPATH' ) || exit;

/** @var array $report */
$family = $report['family'];

/*
 * The catalog is read from the locally installed plugin's source. When the plugin isn't installed, or
 * its frontend source is missing (a built/minified release), render a state panel and stop.
 */
if ( isset( $report['available'] ) && ! $report['available'] ) {
	$message = isset( $report['message'] ) ? $report['message'] : __( 'The catalog source could not be loaded.', 'bit-audit' );
	?>
	<div class="ba-state">
		<span class="ba-state-icon dashicons dashicons-info-outline"></span>
		<h2 class="ba-state-title"><?php echo esc_html( $report['label'] ); ?></h2>
		<p class="ba-state-text"><?php echo esc_html( $message ); ?></p>
	</div>
	<?php
	return;
}

$catalog   = $report['catalog'];
$changelog = $report['changelog'];
?>

<?php if ( ! $report['presence']['free'] && ! $report['presence']['pro'] ) : ?>
	<div class="ba-banner ba-banner-warn">
		<span class="dashicons dashicons-warning"></span>
		<?php
		/* translators: %s: plugin label (e.g. Bit Flows). */
		echo esc_html( sprintf( __( '%s is not installed on this site — showing the shipped catalog for reference.', 'bit-audit' ), $report['label'] ) );
		?>
	</div>
<?php elseif ( ! $report['presence']['active'] ) : ?>
	<div class="ba-banner ba-banner-info">
		<span class="dashicons dashicons-info-outline"></span>
		<?php
		/* translators: %s: plugin label (e.g. Bit Flows). */
		echo esc_html( sprintf( __( '%s is installed but not active. Activate it to use these integrations.', 'bit-audit' ), $report['label'] ) );
		?>
	</div>
<?php endif; ?>

<section class="ba-section">
	<div class="ba-section-head">
		<h2><span class="dashicons dashicons-archive"></span><?php esc_html_e( 'Overview', 'bit-audit' ); ?></h2>
		<p class="ba-section-sub"><?php esc_html_e( 'Everything this plugin ships — Free + Pro combined.', 'bit-audit' ); ?></p>
	</div>
	<div class="ba-cards">
		<?php
		AdminPage::card(
			__( 'Total Integrations', 'bit-audit' ),
			$catalog['total_integrations'],
			__( 'Triggers + Actions', 'bit-audit' ),
			'admin-plugins',
			'indigo'
		);
		if ( isset( $catalog['platform_integrations'] ) ) {
			AdminPage::card(
				__( 'Platform Integrations', 'bit-audit' ),
				$catalog['platform_integrations'],
				__( 'Unique apps · Free + Pro', 'bit-audit' ),
				'networking',
				'cyan'
			);
		}
		AdminPage::card(
			__( 'Triggers', 'bit-audit' ),
			$catalog['trigger_apps'],
			isset( $catalog['apps'] )
				/* translators: 1: free-tier count, 2: pro-tier count. */
				? sprintf( __( 'Free %1$s · Pro %2$s', 'bit-audit' ), AdminPage::fmt( $catalog['apps']['trigger']['free'] ), AdminPage::fmt( $catalog['apps']['trigger']['pro'] ) )
				: '',
			'controls-play',
			'violet'
		);
		AdminPage::card(
			__( 'Actions', 'bit-audit' ),
			$catalog['action_apps'],
			isset( $catalog['apps'] )
				/* translators: 1: free-tier count, 2: pro-tier count. */
				? sprintf( __( 'Free %1$s · Pro %2$s', 'bit-audit' ), AdminPage::fmt( $catalog['apps']['action']['free'] ), AdminPage::fmt( $catalog['apps']['action']['pro'] ) )
				: '',
			'admin-generic',
			'blue'
		);
		AdminPage::card(
			__( 'Trigger Events', 'bit-audit' ),
			$catalog['total_trigger_events'],
			/* translators: 1: free-tier count, 2: pro-tier count. */
			sprintf( __( 'Free %1$s · Pro %2$s', 'bit-audit' ), AdminPage::fmt( $catalog['split']['free']['trigger_events'] ), AdminPage::fmt( $catalog['split']['pro']['trigger_events'] ) ),
			'randomize',
			'teal'
		);
		AdminPage::card(
			__( 'Action Events', 'bit-audit' ),
			$catalog['total_action_events'],
			/* translators: 1: free-tier count, 2: pro-tier count. */
			sprintf( __( 'Free %1$s · Pro %2$s', 'bit-audit' ), AdminPage::fmt( $catalog['split']['free']['action_events'] ), AdminPage::fmt( $catalog['split']['pro']['action_events'] ) ),
			'list-view',
			'green'
		);
		?>
	</div>
</section>

<?php if ( $changelog && ( $changelog['triggers'] || $changelog['actions'] ) ) : ?>
<section class="ba-section">
	<div class="ba-section-head">
		<h2><span class="dashicons dashicons-megaphone"></span><?php esc_html_e( 'Latest integrations', 'bit-audit' ); ?></h2>
		<p class="ba-section-sub">
			<?php
			/* translators: 1: version, 2: release date. */
			echo esc_html( sprintf( __( 'Newly added in %1$s%2$s.', 'bit-audit' ), $changelog['version'], $changelog['date'] ? ' · ' . $changelog['date'] : '' ) );
			?>
		</p>
	</div>
	<div class="ba-grid-2">
		<div class="ba-latest">
			<h3><span class="dashicons dashicons-controls-play"></span><?php esc_html_e( 'New Triggers', 'bit-audit' ); ?></h3>
			<?php if ( $changelog['triggers'] ) : ?>
				<?php AdminPage::latest_list( $changelog['triggers'], $family ); ?>
			<?php else : ?>
				<p class="ba-empty"><?php esc_html_e( 'None in this release.', 'bit-audit' ); ?></p>
			<?php endif; ?>
		</div>
		<div class="ba-latest">
			<h3><span class="dashicons dashicons-update"></span><?php esc_html_e( 'New Actions', 'bit-audit' ); ?></h3>
			<?php if ( $changelog['actions'] ) : ?>
				<?php AdminPage::latest_list( $changelog['actions'], $family ); ?>
			<?php else : ?>
				<p class="ba-empty"><?php esc_html_e( 'None in this release.', 'bit-audit' ); ?></p>
			<?php endif; ?>
		</div>
	</div>
</section>
<?php endif; ?>

<section class="ba-section">
	<div class="ba-section-head ba-section-head-row">
		<div>
			<h2><span class="dashicons dashicons-screenoptions"></span><?php esc_html_e( 'Per-integration events', 'bit-audit' ); ?></h2>
			<p class="ba-section-sub"><?php esc_html_e( 'Trigger and action events per integration. Click a row to see every event.', 'bit-audit' ); ?></p>
		</div>
		<div class="ba-search">
			<span class="dashicons dashicons-search"></span>
			<input type="search" id="ba-filter" placeholder="<?php esc_attr_e( 'Filter integrations…', 'bit-audit' ); ?>" autocomplete="off">
		</div>
	</div>
	<div class="ba-table-wrap">
		<table class="ba-table ba-clickable" id="ba-per-integration">
			<thead>
				<tr>
					<th class="ba-sn"><?php esc_html_e( '#', 'bit-audit' ); ?></th>
					<th><?php esc_html_e( 'Integration', 'bit-audit' ); ?></th>
					<th><?php esc_html_e( 'Type', 'bit-audit' ); ?></th>
					<th><?php esc_html_e( 'Tier', 'bit-audit' ); ?></th>
					<th class="num"><?php esc_html_e( 'Trigger events', 'bit-audit' ); ?></th>
					<th class="num"><?php esc_html_e( 'Action events', 'bit-audit' ); ?></th>
					<th class="ba-arrow"></th>
				</tr>
			</thead>
			<tbody>
				<?php $ba_sn = 0; ?>
				<?php foreach ( $catalog['per_integration'] as $row ) : ?>
					<?php ++$ba_sn; ?>
					<tr data-href="<?php echo esc_url( AdminPage::detail_url( $family, $row['slug'] ) ); ?>" tabindex="0">
						<td class="ba-sn"><?php echo esc_html( number_format_i18n( $ba_sn ) ); ?></td>
						<td class="ba-name"><a href="<?php echo esc_url( AdminPage::detail_url( $family, $row['slug'] ) ); ?>"><?php echo esc_html( $row['name'] ); ?></a></td>
						<td class="ba-types">
							<?php $is_trigger = ! isset( $row['isTrigger'] ) || $row['isTrigger']; ?>
							<?php $is_action = ! isset( $row['isAction'] ) || $row['isAction']; ?>
							<?php
							if ( $is_trigger ) :
								?>
								<span class="ba-type trigger"><?php esc_html_e( 'Trigger', 'bit-audit' ); ?></span><?php endif; ?>
							<?php
							if ( $is_action ) :
								?>
								<span class="ba-type action"><?php esc_html_e( 'Action', 'bit-audit' ); ?></span><?php endif; ?>
						</td>
						<td>
							<?php $ba_tier = isset( $row['tier'] ) ? $row['tier'] : ( $row['isPro'] ? 'pro' : 'free' ); ?>
							<?php if ( 'both' === $ba_tier ) : ?>
								<span class="ba-tag both"><?php esc_html_e( 'Both', 'bit-audit' ); ?></span>
							<?php elseif ( 'pro' === $ba_tier ) : ?>
								<span class="ba-tag pro"><?php esc_html_e( 'Pro', 'bit-audit' ); ?></span>
							<?php else : ?>
								<span class="ba-tag free"><?php esc_html_e( 'Free', 'bit-audit' ); ?></span>
							<?php endif; ?>
						</td>
						<td class="num"><?php echo esc_html( AdminPage::fmt( $row['trigger_events'] ) ); ?></td>
						<td class="num"><?php echo esc_html( AdminPage::fmt( $row['action_events'] ) ); ?></td>
						<td class="ba-arrow"><span class="dashicons dashicons-arrow-right-alt2"></span></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p class="ba-empty" id="ba-filter-empty" hidden><?php esc_html_e( 'No integrations match your filter.', 'bit-audit' ); ?></p>
	</div>
</section>
