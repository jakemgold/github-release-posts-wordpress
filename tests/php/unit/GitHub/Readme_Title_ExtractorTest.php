<?php
/**
 * Tests for Readme_Title_Extractor.
 *
 * @package GitHubReleasePosts\Tests
 */

namespace GitHubReleasePosts\Tests\GitHub;

use GitHubReleasePosts\GitHub\Readme_Title_Extractor;
use WP_Mock\Tools\TestCase;

/**
 * Covers the README → display-name extraction logic, focusing on the
 * markdown / HTML / decoration variants that show up in real OSS READMEs.
 */
class Readme_Title_ExtractorTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
		\WP_Mock::setUp();
		\WP_Mock::userFunction( 'wp_strip_all_tags' )
			->andReturnUsing( fn( $v ) => preg_replace( '/<[^>]*>/', '', (string) $v ) )
			->byDefault();
	}

	public function tearDown(): void {
		\WP_Mock::tearDown();
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Basic happy paths
	// -------------------------------------------------------------------------

	public function test_extracts_simple_atx_heading(): void {
		$this->assertSame( 'Ads.txt', Readme_Title_Extractor::extract( "# Ads.txt\n\nManage ads.txt files." ) );
	}

	public function test_extracts_setext_heading(): void {
		$this->assertSame( 'ElasticPress', Readme_Title_Extractor::extract( "ElasticPress\n=============\n\nA fast Elasticsearch integration." ) );
	}

	public function test_extracts_html_h1(): void {
		$this->assertSame( 'Jetpack', Readme_Title_Extractor::extract( "<h1 align=\"center\">Jetpack</h1>\n\nSecurity, performance, and marketing tools." ) );
	}

	public function test_preserves_canonical_case(): void {
		$this->assertSame( 'WP-CLI', Readme_Title_Extractor::extract( "# WP-CLI\n\nCommand-line interface for WordPress." ) );
	}

	public function test_preserves_internal_punctuation(): void {
		$this->assertSame( 'PocketMine-MP', Readme_Title_Extractor::extract( "# PocketMine-MP\n\nMinecraft PE server." ) );
	}

	// -------------------------------------------------------------------------
	// Decoration stripping
	// -------------------------------------------------------------------------

	public function test_strips_bold_emphasis(): void {
		$this->assertSame( 'WP-CLI', Readme_Title_Extractor::extract( '# **WP-CLI**' ) );
	}

	public function test_strips_italic_emphasis(): void {
		$this->assertSame( 'Project', Readme_Title_Extractor::extract( '# *Project*' ) );
	}

	public function test_strips_code_span_backticks(): void {
		$this->assertSame( 'wp-cli', Readme_Title_Extractor::extract( '# `wp-cli`' ) );
	}

	public function test_unwraps_markdown_link_keeping_text(): void {
		$this->assertSame( 'Gutenberg', Readme_Title_Extractor::extract( '# [Gutenberg](https://wordpress.org/gutenberg)' ) );
	}

	public function test_drops_trailing_badge_images(): void {
		$input = '# Project Name ![Build](https://example.com/badge.svg) ![Coverage](https://example.com/cov.svg)';
		$this->assertSame( 'Project Name', Readme_Title_Extractor::extract( $input ) );
	}

	public function test_drops_leading_emoji(): void {
		$this->assertSame( 'SuperPlugin', Readme_Title_Extractor::extract( '# 🚀 SuperPlugin' ) );
	}

	public function test_drops_leading_arrows(): void {
		$this->assertSame( 'My Project', Readme_Title_Extractor::extract( '# ➡️ My Project' ) );
	}

	public function test_strips_trailing_closing_hashes(): void {
		// Some markdown styles permit `# Heading #`.
		$this->assertSame( 'Heading', Readme_Title_Extractor::extract( '# Heading #' ) );
	}

	public function test_collapses_whitespace(): void {
		$this->assertSame( 'My Plugin', Readme_Title_Extractor::extract( "#   My    Plugin  \n" ) );
	}

	public function test_handles_html_h1_with_inner_emphasis(): void {
		$this->assertSame( 'Jetpack', Readme_Title_Extractor::extract( '<h1><strong>Jetpack</strong></h1>' ) );
	}

	// -------------------------------------------------------------------------
	// First-heading-wins ordering
	// -------------------------------------------------------------------------

	public function test_first_heading_wins_atx_before_setext(): void {
		$md = "# First Heading\n\nSome paragraph.\n\nSecond Heading\n==============\n";
		$this->assertSame( 'First Heading', Readme_Title_Extractor::extract( $md ) );
	}

	public function test_first_heading_wins_setext_before_atx(): void {
		$md = "Setext Title\n============\n\nSome paragraph.\n\n# ATX Title later\n";
		$this->assertSame( 'Setext Title', Readme_Title_Extractor::extract( $md ) );
	}

	public function test_ignores_h2_and_lower_atx(): void {
		$md = "## Subheading\n\n# Real Title\n";
		$this->assertSame( 'Real Title', Readme_Title_Extractor::extract( $md ) );
	}

	// -------------------------------------------------------------------------
	// Rejections — should fall back (return empty string)
	// -------------------------------------------------------------------------

	public function test_returns_empty_for_empty_input(): void {
		$this->assertSame( '', Readme_Title_Extractor::extract( '' ) );
	}

	public function test_returns_empty_for_whitespace_only_input(): void {
		$this->assertSame( '', Readme_Title_Extractor::extract( "   \n\n\t" ) );
	}

	public function test_returns_empty_when_no_heading_found(): void {
		$md = "This is just a paragraph.\n\nAnd another paragraph.\n";
		$this->assertSame( '', Readme_Title_Extractor::extract( $md ) );
	}

	public function test_returns_empty_for_blocklisted_generic_heading(): void {
		$this->assertSame( '', Readme_Title_Extractor::extract( '# Documentation' ) );
		$this->assertSame( '', Readme_Title_Extractor::extract( '# Installation' ) );
		$this->assertSame( '', Readme_Title_Extractor::extract( '# Getting Started' ) );
		$this->assertSame( '', Readme_Title_Extractor::extract( '# README' ) );
	}

	public function test_blocklist_is_case_insensitive(): void {
		$this->assertSame( '', Readme_Title_Extractor::extract( '# INSTALLATION' ) );
		$this->assertSame( '', Readme_Title_Extractor::extract( '# Getting started' ) );
	}

	public function test_returns_empty_when_heading_is_too_short_after_stripping(): void {
		// Just one character after sanitization.
		$this->assertSame( '', Readme_Title_Extractor::extract( '# A' ) );
	}

	public function test_returns_empty_when_heading_is_too_long(): void {
		$long_heading = '# ' . str_repeat( 'long', 20 ); // 80 chars of content.
		$this->assertSame( '', Readme_Title_Extractor::extract( $long_heading ) );
	}

	public function test_returns_empty_when_only_a_badge_image(): void {
		// Entire heading is a badge image — sanitization leaves nothing.
		$this->assertSame( '', Readme_Title_Extractor::extract( '# ![Logo](https://example.com/logo.svg)' ) );
	}

	public function test_strips_bidi_override_characters(): void {
		// U+202E (RLO) would flip the visual order of "REVERSE" if left in.
		$input = "# Real\xE2\x80\xAEREVERSE";
		$this->assertSame( 'RealREVERSE', Readme_Title_Extractor::extract( $input ) );
	}

	public function test_strips_leading_bom(): void {
		// U+FEFF at the start of the heading.
		$input = "# \xEF\xBB\xBFProject";
		$this->assertSame( 'Project', Readme_Title_Extractor::extract( $input ) );
	}

	public function test_strips_soft_hyphen(): void {
		// U+00AD is invisible; would let an attacker spoof a similar-looking name.
		$input = "# Pro\xC2\xADject";
		$this->assertSame( 'Project', Readme_Title_Extractor::extract( $input ) );
	}

	public function test_does_not_match_hash_without_following_space(): void {
		// `#NotAHeading` is not a markdown heading (no space after #).
		$md = "#NotAHeading\n\n# Real Title\n";
		$this->assertSame( 'Real Title', Readme_Title_Extractor::extract( $md ) );
	}
}
