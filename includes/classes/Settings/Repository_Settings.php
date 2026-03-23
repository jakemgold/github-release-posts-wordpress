<?php
/**
 * Repository settings service.
 *
 * @package ChangelogToBlogPost
 */

namespace TenUp\ChangelogToBlogPost\Settings;

use TenUp\ChangelogToBlogPost\Plugin_Constants;

/**
 * Manages the list of tracked GitHub repositories and their per-repo configuration.
 *
 * All repository data is stored as a serialized array in a single wp_options entry
 * identified by Plugin_Constants::OPTION_REPOSITORIES.
 */
class Repository_Settings {

	/**
	 * In-memory cache of the repositories array.
	 *
	 * @var array|null
	 */
	private ?array $cache = null;

	/**
	 * Default maximum number of tracked repositories.
	 * Can be raised via the `ctbp_max_repositories` filter.
	 */
	const MAX_REPOSITORIES = 25;

	/**
	 * Retrieves all tracked repositories.
	 *
	 * @return array<int, array<string, mixed>> Indexed array of repository configuration objects.
	 */
	public function get_repositories(): array {
		if ( null !== $this->cache ) {
			return $this->cache;
		}

		$repos = get_option( Plugin_Constants::OPTION_REPOSITORIES, [] );
		if ( ! is_array( $repos ) ) {
			$this->cache = [];
			return [];
		}

		// Migrate legacy wporg_slug/custom_url → plugin_link on read.
		$this->cache = array_map( [ self::class, 'migrate_plugin_link' ], $repos );
		return $this->cache;
	}

	/**
	 * Retrieves a single repository's configuration by identifier.
	 *
	 * @param string $identifier The `owner/repo` identifier.
	 * @return array<string, mixed> Repo config array, or empty array if not found.
	 */
	public function get_repository( string $identifier ): array {
		foreach ( $this->get_repositories() as $repo ) {
			if ( ( $repo['identifier'] ?? '' ) === $identifier ) {
				return $repo;
			}
		}
		return [];
	}

	/**
	 * Persists the full repositories array.
	 *
	 * @param array<int, array<string, mixed>> $repos Array of repository objects.
	 * @return bool Whether the update succeeded.
	 */
	public function save_repositories( array $repos ): bool {
		$this->cache = null;
		return (bool) update_option( Plugin_Constants::OPTION_REPOSITORIES, array_values( $repos ), false );
	}

	/**
	 * Normalizes a GitHub repository identifier to `owner/repo` format.
	 *
	 * Accepts both `owner/repo` and full GitHub URLs
	 * (e.g., `https://github.com/owner/repo`).
	 *
	 * @param string $input Raw identifier from user input.
	 * @return string Normalized `owner/repo` string.
	 * @throws \InvalidArgumentException If the input cannot be normalized to a valid identifier.
	 */
	public function normalize_identifier( string $input ): string {
		$input = trim( $input );

		// Strip trailing slashes and .git suffix.
		$input = rtrim( $input, '/' );
		$input = preg_replace( '/\.git$/', '', $input );

		// Strip GitHub URL prefix.
		$input = preg_replace( '#^https?://github\.com/#', '', $input );

		// Validate owner/repo format.
		if ( ! preg_match( '#^[A-Za-z0-9_.\-]+/[A-Za-z0-9_.\-]+$#', $input ) ) {
			throw new \InvalidArgumentException(
				sprintf(
					/* translators: %s: user-provided repository identifier */
					__( '"%s" is not a valid GitHub repository. Use owner/repo format or a full GitHub URL.', 'changelog-to-blog-post' ), // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
					$input // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
				)
			);
		}

		return $input;
	}

	/**
	 * Derives a human-readable display name from a GitHub repository name.
	 *
	 * Converts hyphens and underscores to spaces, then applies title case.
	 * For example: `my-awesome-plugin` → `My Awesome Plugin`.
	 *
	 * @param string $repo_name The repository slug portion of the identifier.
	 * @return string Derived display name.
	 */
	public function derive_display_name( string $repo_name ): string {
		$name = str_replace( [ '-', '_' ], ' ', $repo_name );
		return ucwords( $name );
	}

	/**
	 * Adds a new repository to the tracked list.
	 *
	 * @param string $input Raw repository identifier (owner/repo or GitHub URL).
	 * @return array{success: bool, error: string|null, repos: array} Result with repos on success.
	 */
	public function add_repository( string $input ): array {
		try {
			$identifier = $this->normalize_identifier( $input );
		} catch ( \InvalidArgumentException $e ) {
			return [
				'success' => false,
				'error'   => $e->getMessage(),
				'repos'   => $this->get_repositories(),
			];
		}

		$repos = $this->get_repositories();

		// Check for duplicate.
		foreach ( $repos as $repo ) {
			if ( ( $repo['identifier'] ?? '' ) === $identifier ) {
				return [
					'success' => false,
					'error'   => sprintf(
						/* translators: %s: repository identifier */
						__( '"%s" is already being tracked.', 'changelog-to-blog-post' ),
						$identifier
					),
					'repos'   => $repos,
				];
			}
		}

		// Check limit.
		$max = (int) apply_filters( 'ctbp_max_repositories', self::MAX_REPOSITORIES );
		if ( count( $repos ) >= $max ) {
			return [
				'success' => false,
				'error'   => sprintf(
					/* translators: %d: maximum number of repositories */
					__( 'You have reached the maximum of %d tracked repositories.', 'changelog-to-blog-post' ),
					$max
				),
				'repos'   => $repos,
			];
		}

		// Derive display name from repo slug.
		$repo_parts   = explode( '/', $identifier );
		$display_name = $this->derive_display_name( end( $repo_parts ) );

		$repos[] = [
			'identifier'     => $identifier,
			'display_name'   => $display_name,
			'paused'         => false,
			'plugin_link'    => '',
			'author'         => get_current_user_id(),
			'post_status'    => (string) apply_filters( 'ctbp_default_post_status', 'draft' ),
			'categories'     => (array) apply_filters( 'ctbp_default_categories', [] ),
			'tags'           => (array) apply_filters( 'ctbp_default_tags', [] ),
			'featured_image' => 0,
		];

		$this->save_repositories( $repos );

		return [
			'success' => true,
			'error'   => null,
			'repos'   => $repos,
		];
	}

	/**
	 * Removes a repository from the tracked list by its identifier.
	 *
	 * Does not affect any posts that were previously generated for this repo.
	 *
	 * @param string $identifier The `owner/repo` identifier.
	 * @return bool Whether the repository was found and removed.
	 */
	public function remove_repository( string $identifier ): bool {
		$repos    = $this->get_repositories();
		$count    = count( $repos );
		$filtered = array_filter(
			$repos,
			static function ( $repo ) use ( $identifier ) {
				return ( $repo['identifier'] ?? '' ) !== $identifier;
			}
		);

		if ( count( $filtered ) === $count ) {
			return false; // Not found.
		}

		return $this->save_repositories( array_values( $filtered ) );
	}

	/**
	 * Updates per-repo configuration fields for a specific repository.
	 *
	 * Only the fields present in $config are updated; others are preserved.
	 *
	 * @param string               $identifier The `owner/repo` identifier.
	 * @param array<string, mixed> $config     Fields to update.
	 * @return bool Whether the repository was found and saved.
	 */
	public function update_repository( string $identifier, array $config ): bool {
		$repos = $this->get_repositories();
		$found = false;

		$allowed_fields = [ 'display_name', 'paused', 'plugin_link', 'author', 'post_status', 'categories', 'tags', 'featured_image' ];

		foreach ( $repos as &$repo ) {
			if ( ( $repo['identifier'] ?? '' ) === $identifier ) {
				foreach ( $allowed_fields as $field ) {
					if ( array_key_exists( $field, $config ) ) {
						$repo[ $field ] = $config[ $field ];
					}
				}
				$found = true;
				break;
			}
		}
		unset( $repo );

		if ( ! $found ) {
			return false;
		}

		return $this->save_repositories( $repos );
	}

	/**
	 * Determines whether a plugin link value is a URL or a WP.org slug.
	 *
	 * @param string $value The plugin link value.
	 * @return bool True if the value looks like a URL.
	 */
	public static function is_url( string $value ): bool {
		return (bool) preg_match( '#^https?://#i', $value );
	}

	/**
	 * Validates a plugin link value.
	 *
	 * If the value is a URL, checks basic format. If it looks like a
	 * WP.org slug (plain string), queries the WP.org plugins API.
	 *
	 * @param string $value The plugin link value (URL or slug).
	 * @return array{valid: bool, type: string, warning: string|null} Validation result.
	 */
	public function validate_plugin_link( string $value ): array {
		if ( empty( $value ) ) {
			return [
				'valid'   => false,
				'type'    => '',
				'warning' => null,
			];
		}

		// URL — client-side format check is sufficient, just verify it parses.
		if ( self::is_url( $value ) ) {
			$parsed = wp_parse_url( $value );
			if ( ! empty( $parsed['host'] ) ) {
				return [
					'valid'   => true,
					'type'    => 'url',
					'warning' => null,
				];
			}
			return [
				'valid'   => false,
				'type'    => 'url',
				'warning' => __( 'URL does not appear to be valid.', 'changelog-to-blog-post' ),
			];
		}

		// Slug — validate against WP.org API.
		$url      = 'https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request[slug]=' . rawurlencode( $value );
		$response = wp_remote_get( $url, [ 'timeout' => 10 ] );

		if ( is_wp_error( $response ) ) {
			return [
				'valid'   => false,
				'type'    => 'slug',
				'warning' => $response->get_error_message(),
			];
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( isset( $data['error'] ) || empty( $data ) ) {
			return [
				'valid'   => false,
				'type'    => 'slug',
				'warning' => __( 'Plugin not found on WordPress.org.', 'changelog-to-blog-post' ),
			];
		}

		return [
			'valid'   => true,
			'type'    => 'slug',
			'warning' => null,
		];
	}

	/**
	 * Migrates legacy wporg_slug / custom_url fields to plugin_link.
	 *
	 * Called on get_repositories() to handle repos saved before the merge.
	 * Priority: custom_url > wporg_slug.
	 *
	 * @param array $repo Repository config array.
	 * @return array Updated config with plugin_link.
	 */
	public static function migrate_plugin_link( array $repo ): array {
		if ( array_key_exists( 'plugin_link', $repo ) ) {
			// Already migrated — clean up legacy keys if present.
			unset( $repo['wporg_slug'], $repo['custom_url'] );
			return $repo;
		}

		$plugin_link = '';
		if ( ! empty( $repo['custom_url'] ) ) {
			$plugin_link = $repo['custom_url'];
		} elseif ( ! empty( $repo['wporg_slug'] ) ) {
			$plugin_link = $repo['wporg_slug'];
		}

		$repo['plugin_link'] = $plugin_link;
		unset( $repo['wporg_slug'], $repo['custom_url'] );

		return $repo;
	}
}
