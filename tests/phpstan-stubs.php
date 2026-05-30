<?php
/**
 * PHPStan-only stubs for symbols defined at runtime that the analyser can't
 * discover statically. This file is referenced as a bootstrap file from
 * phpstan.neon.dist; it is never executed at runtime.
 *
 * @package GitHubReleasePosts
 */

// Plugin constants are defined via `define()` at the top of
// github-release-posts.php behind environment guards, so PHPStan's static
// analysis pass doesn't see them. Declare them here so usages elsewhere
// resolve cleanly.
define( 'GHRP_VERSION', '1.0.0' );
define( 'GHRP_URL', '' );
define( 'GHRP_PATH', '' );
define( 'GHRP_INC', '' );

// WordPress 7.0 AI Client API. Not yet present in phpstan-wordpress stubs.
if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
	/**
	 * Creates an AI prompt builder.
	 *
	 * @param string|array<string, mixed> $prompt Prompt text or args.
	 * @return mixed Builder object (supports `using_max_tokens`, `using_model`, etc.).
	 */
	function wp_ai_client_prompt( $prompt ) {
		// Stub only — real implementation is provided by WordPress 7.0+.
		return null;
	}
}
