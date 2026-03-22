<?php
/**
 * PHPUnit bootstrap file.
 *
 * @package ChangelogToBlogPost
 */

require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';

\WP_Mock::bootstrap();
