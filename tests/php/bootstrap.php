<?php
/**
 * PHPUnit bootstrap file.
 *
 * @package ChangelogToBlogPost
 */

require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';

// Define WordPress constants used by the plugin.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/wordpress/' );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}
if ( ! defined( 'WEEK_IN_SECONDS' ) ) {
	define( 'WEEK_IN_SECONDS', 604800 );
}
if ( ! defined( 'CHANGELOG_TO_BLOG_POST_VERSION' ) ) {
	define( 'CHANGELOG_TO_BLOG_POST_VERSION', '1.0.0-test' );
}
if ( ! defined( 'CHANGELOG_TO_BLOG_POST_URL' ) ) {
	define( 'CHANGELOG_TO_BLOG_POST_URL', 'https://example.com/wp-content/plugins/changelog-to-blog-post/' );
}
if ( ! defined( 'CHANGELOG_TO_BLOG_POST_PATH' ) ) {
	define( 'CHANGELOG_TO_BLOG_POST_PATH', dirname( __DIR__, 2 ) . '/' );
}
if ( ! defined( 'CHANGELOG_TO_BLOG_POST_INC' ) ) {
	define( 'CHANGELOG_TO_BLOG_POST_INC', dirname( __DIR__, 2 ) . '/includes/' );
}

// Stub WP_Error class if not already defined (WP_Mock doesn't provide it).
if ( ! class_exists( 'WP_Error' ) ) {
	// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
	class WP_Error {
		private string $code;
		private string $message;
		private mixed $data;

		public function __construct( string $code = '', string $message = '', mixed $data = '' ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}

		public function get_error_data(): mixed {
			return $this->data;
		}
	}
}

// Stub WP_Post class if not already defined.
if ( ! class_exists( 'WP_Post' ) ) {
	// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
	class WP_Post {
		public int $ID = 0;
		public string $post_title = '';
		public string $post_status = '';

		public function __construct( $props = [] ) {
			foreach ( $props as $key => $value ) {
				$this->$key = $value;
			}
		}
	}
}

// Stub WP_Query class if not already defined.
if ( ! class_exists( 'WP_Query' ) ) {
	// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
	class WP_Query {
		/** @var array */
		public array $posts = [];

		/** @var array|null Static override for test mocking. */
		public static ?array $mock_posts = null;

		public function __construct( $args = [] ) {
			if ( null !== self::$mock_posts ) {
				$this->posts = self::$mock_posts;
			}
		}

		public static function reset_mock(): void {
			self::$mock_posts = null;
		}
	}
}

// Stub is_wp_error() if not already defined.
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}

\WP_Mock::bootstrap();
