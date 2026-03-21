<?php
/**
 * Settings tab content.
 *
 * Renders the global settings form sections: AI provider, post defaults,
 * notification preferences, and check frequency.
 *
 * @package ChangelogToBlogPost
 */

// Guard: direct access not allowed.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TenUp\ChangelogToBlogPost\Settings\Global_Settings;
use TenUp\ChangelogToBlogPost\Plugin_Constants;

$global    = new Global_Settings();
$provider  = $global->get_ai_provider();
$defaults  = $global->get_post_defaults();
$notif     = $global->get_notification_settings();
$frequency = $global->get_check_frequency();

// Determine if a trigger/status mismatch warning should be shown.
$trigger_is_published = in_array( $notif['trigger'] ?? '', [ 'publish', 'both' ], true );
$status_is_draft      = ( $defaults['post_status'] ?? 'draft' ) === 'draft';
$show_trigger_warning = $trigger_is_published && $status_is_draft;

$key_based_providers  = [ 'openai', 'anthropic', 'gemini' ];
$no_key_providers     = [ 'classifai', 'wordpress_ai' ];
?>

<h2><?php echo esc_html__( 'AI Provider', 'changelog-to-blog-post' ); ?></h2>
<fieldset>
	<legend class="screen-reader-text"><?php echo esc_html__( 'AI Provider Settings', 'changelog-to-blog-post' ); ?></legend>

	<p>
		<label for="ctbp_ai_provider"><?php echo esc_html__( 'Active AI Provider', 'changelog-to-blog-post' ); ?></label><br>
		<select id="ctbp_ai_provider" name="ctbp_ai_provider">
			<option value="" <?php selected( $provider, '' ); ?>><?php echo esc_html__( '— Select a provider —', 'changelog-to-blog-post' ); ?></option>
			<option value="openai" <?php selected( $provider, 'openai' ); ?>><?php echo esc_html__( 'OpenAI', 'changelog-to-blog-post' ); ?></option>
			<option value="anthropic" <?php selected( $provider, 'anthropic' ); ?>><?php echo esc_html__( 'Anthropic', 'changelog-to-blog-post' ); ?></option>
			<option value="gemini" <?php selected( $provider, 'gemini' ); ?>><?php echo esc_html__( 'Google Gemini', 'changelog-to-blog-post' ); ?></option>
			<option value="classifai" <?php selected( $provider, 'classifai' ); ?>><?php echo esc_html__( 'ClassifAI', 'changelog-to-blog-post' ); ?></option>
			<option value="wordpress_ai" <?php selected( $provider, 'wordpress_ai' ); ?>><?php echo esc_html__( 'WordPress AI API', 'changelog-to-blog-post' ); ?></option>
		</select>
	</p>

	<?php foreach ( $key_based_providers as $p ) : ?>
		<div
			class="ctbp-api-key-row"
			data-provider="<?php echo esc_attr( $p ); ?>"
			<?php echo $provider !== $p ? 'hidden' : ''; ?>
		>
			<p>
				<label for="ctbp_api_key_<?php echo esc_attr( $p ); ?>">
					<?php
					printf(
						/* translators: %s: provider display name */
						esc_html__( '%s API Key', 'changelog-to-blog-post' ),
						esc_html( ucfirst( $p ) )
					);
					?>
				</label><br>
				<input
					type="password"
					id="ctbp_api_key_<?php echo esc_attr( $p ); ?>"
					name="ctbp_api_key_<?php echo esc_attr( $p ); ?>"
					value="<?php echo esc_attr( $global->get_masked_key( $p ) ); ?>"
					class="regular-text"
					autocomplete="new-password"
				>
				<span class="description">
					<?php echo esc_html__( 'Leave unchanged to keep the existing key. Clear the field to remove the key.', 'changelog-to-blog-post' ); ?>
				</span>
			</p>
		</div>
	<?php endforeach; ?>

	<?php foreach ( $no_key_providers as $p ) : ?>
		<div
			class="ctbp-provider-note"
			data-provider="<?php echo esc_attr( $p ); ?>"
			<?php echo $provider !== $p ? 'hidden' : ''; ?>
		>
			<p class="description">
				<?php
				if ( 'classifai' === $p ) {
					echo esc_html__( 'ClassifAI manages its own credentials. Configure API keys within the ClassifAI plugin settings.', 'changelog-to-blog-post' );
				} else {
					echo esc_html__( 'The WordPress AI API uses site-level credentials managed by the WordPress AI API plugin.', 'changelog-to-blog-post' );
				}
				?>
			</p>
		</div>
	<?php endforeach; ?>

	<?php if ( $provider ) : ?>
		<p>
			<button type="button" id="ctbp-test-connection" class="button">
				<?php echo esc_html__( 'Test Connection', 'changelog-to-blog-post' ); ?>
			</button>
			<span id="ctbp-connection-result" aria-live="polite"></span>
		</p>
	<?php endif; ?>
</fieldset>

<hr>

<h2><?php echo esc_html__( 'Post Defaults', 'changelog-to-blog-post' ); ?></h2>
<fieldset>
	<legend class="screen-reader-text"><?php echo esc_html__( 'Default Post Settings', 'changelog-to-blog-post' ); ?></legend>

	<p>
		<label for="ctbp_default_post_status"><?php echo esc_html__( 'Default Post Status', 'changelog-to-blog-post' ); ?></label><br>
		<select id="ctbp_default_post_status" name="ctbp_default_post_status">
			<option value="draft" <?php selected( $defaults['post_status'] ?? 'draft', 'draft' ); ?>>
				<?php echo esc_html__( 'Draft', 'changelog-to-blog-post' ); ?>
			</option>
			<option value="publish" <?php selected( $defaults['post_status'] ?? 'draft', 'publish' ); ?>>
				<?php echo esc_html__( 'Publish', 'changelog-to-blog-post' ); ?>
			</option>
		</select>
	</p>

	<p>
		<label for="ctbp_default_category"><?php echo esc_html__( 'Default Category', 'changelog-to-blog-post' ); ?></label><br>
		<?php
		wp_dropdown_categories(
			[
				'name'              => 'ctbp_default_category',
				'id'                => 'ctbp_default_category',
				'selected'          => $defaults['category'] ?? 0,
				'show_option_none'  => __( 'None (WordPress default)', 'changelog-to-blog-post' ),
				'option_none_value' => '0',
				'hide_empty'        => false,
			]
		);
		?>
	</p>

	<p>
		<label for="ctbp_default_tags">
			<?php echo esc_html__( 'Default Tags', 'changelog-to-blog-post' ); ?>
			<span class="description"><?php echo esc_html__( '(comma-separated)', 'changelog-to-blog-post' ); ?></span>
		</label><br>
		<input
			type="text"
			id="ctbp_default_tags"
			name="ctbp_default_tags"
			value="<?php echo esc_attr( implode( ', ', (array) ( $defaults['tags'] ?? [] ) ) ); ?>"
			class="regular-text"
		>
	</p>
</fieldset>

<hr>

<h2><?php echo esc_html__( 'Notifications', 'changelog-to-blog-post' ); ?></h2>
<fieldset>
	<legend class="screen-reader-text"><?php echo esc_html__( 'Notification Settings', 'changelog-to-blog-post' ); ?></legend>

	<p>
		<label>
			<input
				type="checkbox"
				name="ctbp_notifications_enabled"
				value="1"
				<?php checked( ! empty( $notif['enabled'] ) ); ?>
			>
			<?php echo esc_html__( 'Enable email notifications', 'changelog-to-blog-post' ); ?>
		</label>
	</p>

	<p>
		<label for="ctbp_notification_email"><?php echo esc_html__( 'Primary Notification Email', 'changelog-to-blog-post' ); ?></label><br>
		<input
			type="email"
			id="ctbp_notification_email"
			name="ctbp_notification_email"
			value="<?php echo esc_attr( $notif['email'] ?? get_option( 'admin_email' ) ); ?>"
			class="regular-text"
		>
	</p>

	<p>
		<label for="ctbp_notification_email_secondary">
			<?php echo esc_html__( 'Secondary Notification Email', 'changelog-to-blog-post' ); ?>
			<span class="description"><?php echo esc_html__( '(optional)', 'changelog-to-blog-post' ); ?></span>
		</label><br>
		<input
			type="email"
			id="ctbp_notification_email_secondary"
			name="ctbp_notification_email_secondary"
			value="<?php echo esc_attr( $notif['email_secondary'] ?? '' ); ?>"
			class="regular-text"
		>
	</p>

	<p>
		<label for="ctbp_notification_trigger"><?php echo esc_html__( 'Send notifications', 'changelog-to-blog-post' ); ?></label><br>
		<select id="ctbp_notification_trigger" name="ctbp_notification_trigger">
			<option value="draft" <?php selected( $notif['trigger'] ?? 'draft', 'draft' ); ?>>
				<?php echo esc_html__( 'When draft is created', 'changelog-to-blog-post' ); ?>
			</option>
			<option value="publish" <?php selected( $notif['trigger'] ?? 'draft', 'publish' ); ?>>
				<?php echo esc_html__( 'When post is published', 'changelog-to-blog-post' ); ?>
			</option>
			<option value="both" <?php selected( $notif['trigger'] ?? 'draft', 'both' ); ?>>
				<?php echo esc_html__( 'Both', 'changelog-to-blog-post' ); ?>
			</option>
		</select>
	</p>

	<?php if ( $show_trigger_warning ) : ?>
		<div class="notice notice-warning inline">
			<p>
				<?php echo esc_html__( 'Note: Notifications are set to trigger on publish, but the default post status is "Draft". No notifications will be sent automatically until posts are manually published.', 'changelog-to-blog-post' ); ?>
			</p>
		</div>
	<?php endif; ?>
</fieldset>

<hr>

<h2><?php echo esc_html__( 'Check Frequency', 'changelog-to-blog-post' ); ?></h2>
<fieldset>
	<legend class="screen-reader-text"><?php echo esc_html__( 'Release Check Frequency Settings', 'changelog-to-blog-post' ); ?></legend>

	<p>
		<label for="ctbp_check_frequency"><?php echo esc_html__( 'Check for new releases', 'changelog-to-blog-post' ); ?></label><br>
		<select id="ctbp_check_frequency" name="ctbp_check_frequency">
			<option value="hourly" <?php selected( $frequency, 'hourly' ); ?>><?php echo esc_html__( 'Hourly', 'changelog-to-blog-post' ); ?></option>
			<option value="twicedaily" <?php selected( $frequency, 'twicedaily' ); ?>><?php echo esc_html__( 'Twice Daily', 'changelog-to-blog-post' ); ?></option>
			<option value="daily" <?php selected( $frequency, 'daily' ); ?>><?php echo esc_html__( 'Daily', 'changelog-to-blog-post' ); ?></option>
			<option value="weekly" <?php selected( $frequency, 'weekly' ); ?>><?php echo esc_html__( 'Weekly', 'changelog-to-blog-post' ); ?></option>
		</select>
	</p>

	<?php
	$next_check = wp_next_scheduled( Plugin_Constants::CRON_HOOK_RELEASE_CHECK );
	if ( $next_check ) :
		?>
		<p class="description">
			<?php
			printf(
				/* translators: %s: human-readable time until next check */
				esc_html__( 'Next check: %s', 'changelog-to-blog-post' ),
				esc_html( human_time_diff( time(), $next_check ) . ' ' . __( 'from now', 'changelog-to-blog-post' ) )
			);
			?>
		</p>
	<?php else : ?>
		<p class="description">
			<?php echo esc_html__( 'Scheduled check not found. The check will be scheduled on next plugin activation.', 'changelog-to-blog-post' ); ?>
		</p>
	<?php endif; ?>
</fieldset>
