<?php
/**
 * Settings tab content.
 *
 * Renders the global settings form sections using the WordPress Settings API.
 * Fields are registered in Settings_Page::register_settings().
 *
 * @package GitHubReleasePosts
 */

// Guard: direct access not allowed.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Jakemgold\GitHubReleasePosts\Admin\Settings_Page;

settings_fields( Settings_Page::OPTION_GROUP );
do_settings_sections( Settings_Page::PAGE_SLUG );
