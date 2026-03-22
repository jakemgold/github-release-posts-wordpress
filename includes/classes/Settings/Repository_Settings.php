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
		$repos = get_option( Plugin_Constants::OPTION_REPOSITORIES, [] );
		return is_array( $repos ) ? $repos : [];
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
		return (bool) update_option( Plugin_Constants::OPTION_REPOSITORIES, array_values( $repos ) );
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
					__( '"%s" is not a valid GitHub repository. Use owner/repo format or a full GitHub URL.', 'changelog-to-blog-post' ),
					$input
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
			'identifier'   => $identifier,
			'display_name' => $display_name,
			'paused'       => false,
			'wporg_slug'   => '',
			'custom_url'   => '',
			'post_status'  => '',
			'category'     => 0,
			'tags'         => [],
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
		$repos   = $this->get_repositories();
		$count   = count( $repos );
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

		$allowed_fields = [ 'display_name', 'paused', 'wporg_slug', 'custom_url', 'post_status', 'category', 'tags' ];

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
	 * Validates a WordPress.org plugin slug by querying the plugins API.
	 *
	 * This is a best-effort check — an invalid result shows a warning but
	 * does not block saving (BR-004).
	 *
	 * @param string $slug The WordPress.org plugin slug to check.
	 * @return array{valid: bool, warning: string|null} Validation result.
	 */
	public function validate_wporg_slug( string $slug ): array {
		if ( empty( $slug ) ) {
			return [ 'valid' => false, 'warning' => __( 'Slug is empty.', 'changelog-to-blog-post' ) ];
		}

		$url      = 'https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request[slug]=' . rawurlencode( $slug );
		$response = wp_remote_get( $url, [ 'timeout' => 10 ] );

		if ( is_wp_error( $response ) ) {
			return [ 'valid' => false, 'warning' => $response->get_error_message() ];
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( isset( $data['error'] ) || empty( $data ) ) {
			return [
				'valid'   => false,
				'warning' => __( 'Plugin not found on WordPress.org. You can still save, but the WP.org download link will not be used.', 'changelog-to-blog-post' ),
			];
		}

		return [ 'valid' => true, 'warning' => null ];
	}
}
