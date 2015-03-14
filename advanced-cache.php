<?php
/**
 * Pj Page Cache Drop-in
 *
 * Place this file in wp-content/advanced-cache.php and enable
 * WP_CACHE in your wp-config.php file. Before enabling WP_CACHE
 * make sure wp-content/plugins/pj-page-cache/pj-page-cache.php
 * exists and is active.
 */
if ( ! defined( 'ABSPATH' ) )
	die();

function pj_page_cache_init() {
	$plugin_dir = defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : WP_CONTENT_DIR . '/plugins';
	if ( file_exists( $plugin_dir . '/pj-page-cache/pj-page-cache.php' ) )
		require_once( $plugin_dir . '/pj-page-cache/pj-page-cache.php' );

	Pj_Page_Cache::cache_init();
}
pj_page_cache_init();