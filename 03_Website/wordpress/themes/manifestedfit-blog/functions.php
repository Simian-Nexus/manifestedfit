<?php
/**
 * Manifested Fit Blog — child theme functions.
 *
 * Block themes load theme.json automatically; we enqueue style.css for the
 * few extras it can't express (button shadows, outline variant, helpers).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

add_action( 'wp_enqueue_scripts', function () {
	wp_enqueue_style(
		'manifestedfit-blog',
		get_stylesheet_uri(),
		array(),
		wp_get_theme()->get( 'Version' )
	);
} );

// Also load the brand extras inside the block editor so drafts preview on-brand.
add_action( 'after_setup_theme', function () {
	add_editor_style( 'style.css' );
} );
