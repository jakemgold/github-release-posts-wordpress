<?php
/**
 * Shared default WP_Mock for get_post_status_object().
 *
 * @package GitHubReleasePosts\Tests
 */

namespace GitHubReleasePosts\Tests;

/**
 * Installs a `get_post_status_object()` default mock that mirrors what
 * WordPress registers in wp-includes/post.php. Per-test calls to
 * WP_Mock::userFunction( 'get_post_status_object' )->with(...)->andReturn(...)
 * still override on a per-status basis.
 */
trait Post_Status_Defaults {

	protected function install_post_status_defaults(): void {
		\WP_Mock::userFunction( 'get_post_status_object' )
			->andReturnUsing(
				function ( string $status ) {
					$make = function ( string $label, bool $public, bool $private, bool $internal = false ) {
						$obj           = new \stdClass();
						$obj->label    = $label;
						$obj->public   = $public;
						$obj->private  = $private;
						$obj->internal = $internal;
						return $obj;
					};

					$statuses = [
						'publish'    => $make( 'Published', true, false ),
						'future'     => $make( 'Scheduled', false, false ),
						'draft'      => $make( 'Draft', false, false ),
						'pending'    => $make( 'Pending Review', false, false ),
						'private'    => $make( 'Private', false, true ),
						'trash'      => $make( 'Trash', false, false, true ),
						'auto-draft' => $make( 'Auto-Draft', false, false, true ),
						'inherit'    => $make( 'Inherit', false, false, true ),
					];

					return $statuses[ $status ] ?? null;
				}
			)
			->byDefault();
	}
}
