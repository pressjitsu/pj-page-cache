<?php
/**
 * Plugin Name: Pj Page Cache
 * Plugin URI: http://pressjitsu.com
 * Description: MySQL-backed full page caching plugin for WordPress.
 * Version: 0.7
 */

if ( ! defined( 'ABSPATH' ) )
	die();

class Pj_Page_Cache {
	private static $ttl = 300;
	private static $unique = array();
	private static $headers = array();
	private static $ignore_cookies = array( 'wordpress_test_cookie', '__utmt', '__utma', '__utmb', '__utmc', '__utmz', '__gads', '__qca', '_ga' );
	private static $ignore_request_keys = array( 'utm_source', 'utm_medium', 'utm_term', 'utm_content', 'utm_campaign' );
	private static $bail_callback = false;
	private static $debug = false;

	private static $lock = false;
	private static $cache = false;
	private static $request_hash = '';
	private static $debug_data = false;
	private static $fcgi_regenerate = false;
	private static $orig_headers = null;

	private static $mysqli = null;
	private static $table_name = '';
	private static $version = 1;

	/**
	 * Runs during advanced-cache.php
	 */
	public static function cache_init() {
		// External cache configuration file.
		if ( file_exists( ABSPATH . 'pj-cache-config.php' ) )
			require_once( ABSPATH . 'pj-cache-config.php' );

		// Store any original headers prior to us messing them up.
		self::$orig_headers = headers_list();

		header( 'X-Pj-Cache-Status: miss' );

		// Filters are not yet available, so hi-jack the $wp_filter global to add our actions.
		$GLOBALS['wp_filter']['clean_post_cache'][10]['pj-page-cache'] = array( 'function' => array( __CLASS__, 'clean_post_cache' ), 'accepted_args' => 1 );
		$GLOBALS['wp_filter']['transition_post_status'][10]['pj-page-cache'] = array( 'function' => array( __CLASS__, 'transition_post_status' ), 'accepted_args' => 3 );
		$GLOBALS['wp_filter']['init'][999]['pj-page-cache'] = array( 'function' => array( __CLASS__ , 'init' ), 'accepted_args' => 1 );

		// wp_pj_page_cache
		self::$table_name = ! empty( $GLOBALS['table_prefix'] ) ? $GLOBALS['table_prefix'] : '';
		self::$table_name .= 'pj_page_cache';

		// Parse external configuration if present.
		if ( ! empty( $GLOBALS['pj_cache_config'] ) )
			self::parse_config( $GLOBALS['pj_cache_config'] );

		// Some things just don't need to be cached.
		if ( self::maybe_bail() )
			return;

		$request_hash = array(
			'request' => self::parse_request_uri( $_SERVER['REQUEST_URI'] ),
			'host' => $_SERVER['HTTP_HOST'],
			'https' => ! empty( $_SERVER['HTTPS'] ) ? $_SERVER['HTTPS'] : '',
			'method' => $_SERVER['REQUEST_METHOD'],
			'unique' => self::$unique,
			'cookies' => self::parse_cookies( $_COOKIE ),
		);

		if ( self::$debug ) {
			self::$debug_data = array( 'request_hash' => $request_hash );
		}

		// Convert to an actual hash.
		self::$request_hash = md5( serialize( $request_hash ) );
		unset( $request_hash );

		$mysqli = self::get_mysqli();
		if ( ! $mysqli )
			return;

		// Look for an existing cache entry by request hash.
		$result = $mysqli->query( sprintf( "SELECT hash, data, updated, locked FROM `%s` WHERE `hash` = '%s' LIMIT 1;", self::$table_name, self::$request_hash ) );

		if ( ! $result instanceof mysqli_result )
			return;

		$cache = $result->fetch_assoc();

		// Something is in cache.
		if ( is_array( $cache ) && ! empty( $cache ) ) {

			$cache['data'] = unserialize( $cache['data'] );
			$serve_cache = true;

			// Cache is outdated.
			if ( $cache['updated'] + self::$ttl < time() ) {

				// If it's not locked, lock it for regeneration and don't serve from cache.
				if ( ! (int) $cache['locked'] ) {
					$mysqli->query( sprintf( "UPDATE `%s` SET locked = 1 WHERE `hash` = '%s' LIMIT 1;", self::$table_name, self::$request_hash ) );
					if ( $mysqli->affected_rows == 1 ) {

						if ( self::can_fcgi_regenerate() ) {
							// Well, actually, if we can serve a stale copy but keep the process running
							// to regenerate the cache in background without affecting the UX, that will be great!
							$serve_cache = true;
							self::$fcgi_regenerate = true;
						} else {
							$serve_cache = false;
						}
					}

				// If it's locked, but the lock is outdated, don't serve from cache.
				} elseif ( (int) $cache['locked'] && $cache['updated'] + self::$ttl + 30 < time() ) {
					$serve_cache = false;
				}
			}

			if ( $serve_cache ) {

				// If we're regenareting in background, consider it a miss.
				if ( ! self::$fcgi_regenerate )
					header( 'X-Pj-Cache-Status: hit' );

				if ( self::$debug ) {
					header( 'X-Pj-Cache-Key: ' . self::$request_hash );
					header( sprintf( 'X-Pj-Cache-Expires: %d', self::$ttl - ( time() - $cache['updated'] ) ) );
				}

				// Output cached status code.
				if ( ! empty( $cache['data']['status'] ) )
					http_response_code( $cache['data']['status'] );

				// Output cached headers.
				if ( is_array( $cache['data']['headers'] ) && ! empty( $cache['data']['headers'] ) )
					foreach ( $cache['data']['headers'] as $header )
						header( $header );

				echo $cache['data']['output'];

				// If we can regenerate in the background, do it.
				if ( self::$fcgi_regenerate ) {
					fastcgi_finish_request();

					// Re-issue any original headers that we had.
					pj_sapi_headers_clean();
					if ( ! empty( self::$orig_headers ) )
						foreach ( self::$orig_headers as $header )
							header( $header );

				} else {
					exit;
				}
			}
		}

		// Cache it, smash it.
		ob_start( array( __CLASS__, 'output_buffer' ) );
	}

	/**
	 * Returns true if we can regenerate the request in background.
	 */
	private static function can_fcgi_regenerate() {
		return ( php_sapi_name() == 'fpm-fcgi' && function_exists( 'fastcgi_finish_request' ) && function_exists( 'pj_sapi_headers_clean' ) );
	}

	/**
	 * Initialize and/or return a mysqli object.
	 */
	private static function get_mysqli() {
		if ( self::$mysqli instanceof mysqli )
			return self::$mysqli;

		self::$mysqli = new mysqli( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME );
		if ( self::$mysqli->connect_error ) {
			self::$mysqli->close();
			return false;
		}

		self::$mysqli->set_charset( 'utf8' );
		return self::$mysqli;
	}

	/**
	 * Take a request uri and remove ignored request keys.
	 */
	private static function parse_request_uri( $request_uri ) {
		$parsed = parse_url( $request_uri );

		if ( ! empty( $parsed['query'] ) ) {
			parse_str( $parsed['query'], $query );

			foreach ( $query as $key => $value ) {
				if ( in_array( strtolower( $key ), self::$ignore_request_keys ) ) {
					unset( $query[ $key ] );
				}
			}
		}

		$request_uri = $parsed['path'];
		if ( ! empty( $query ) )
			$request_uri .= '?' . http_build_query( $query );

		return $request_uri;
	}

	/**
	 * Take some cookies and remove ones we don't care about.
	 */
	private static function parse_cookies( $cookies ) {
		foreach ( $cookies as $key => $value ) {
			if ( in_array( strtolower( $key ), self::$ignore_cookies ) ) {
				unset( $cookies[ $key ] );
			}
		}

		return $cookies;
	}

	/**
	 * Check some conditions where pages should never be cached or served from cache.
	 */
	private static function maybe_bail() {

		// Allow an external configuration file to append to the bail method.
		if ( self::$bail_callback && is_callable( self::$bail_callback ) ) {
			$callback_result = call_user_func( self::$bail_callback );
			if ( is_bool( $callback_result ) )
				return $callback_result;
		}

		// Don't cache CLI requests
		if ( php_sapi_name() == 'cli' )
			return true;

		// Don't cache POST requests.
		if ( strtolower( $_SERVER['REQUEST_METHOD'] ) == 'post' )
			return true;

		if ( self::$ttl < 1 )
			return true;

		foreach ( $_COOKIE as $key => $value ) {
			$key = strtolower( $key );

			// Don't cache anything if these cookies are set.
			foreach ( array( 'wp', 'wordpress', 'comment_author' ) as $part ) {
				if ( strpos( $key, $part ) === 0 && ! in_array( $key, self::$ignore_cookies ) ) {
					return true;
				}
			}
		}

		$headers = headers_list();
		foreach ( $headers as $header ) {
			// Don't cache responses with Set-Cookie headers.
			if ( strpos( strtolower( $header ), 'set-cookie' ) === 0 ) {
				return true;
			}
		}

		return false; // Don't bail.
	}

	/**
	 * Allow external configuration.
	 */
	private static function parse_config( $config ) {
		$keys = array(
			'ttl',
			'unique',
			'ignore_cookies',
			'ignore_request_keys',
			'bail_callback',
			'debug',
		);

		foreach ( $keys as $key )
			if ( isset( $config[ $key ] ) )
				self::$$key = $config[ $key ];
	}

	/**
	 * Runs when the output buffer stops.
	 */
	public static function output_buffer( $output ) {
		$data = array(
			'output' => $output,
			'headers' => array(),
			'status' => http_response_code(),
		);

		// Clean up headers he don't want to store.
		foreach ( headers_list() as $header ) {
			// Never store X-Pj-Cache-* headers in cache.
			if ( strpos( strtolower( $header ), 'x-pj-cache' ) !== false )
				continue;

			// Never store Set-Cookie headers in cache, just in case somebody
			// was smart enough to completely override self::maybe_bail().
			if ( strpos( strtolower( $header ), 'set-cookie' ) !== false )
				continue;

			$data['headers'][] = $header;
		}

		if ( self::$debug ) {
			$data['debug'] = self::$debug_data;
		}

		$data = serialize( $data );

		$mysqli = self::get_mysqli();
		if ( ! $mysqli )
			return $output;

		$mysqli->query( sprintf( "INSERT INTO `%s` ( id, hash, url_hash, data, updated, locked ) VALUES ( '', '%s', '%s', '%s', '%d', '%d' )
			ON DUPLICATE KEY UPDATE data = '%s', locked = 0, updated = %d;", self::$table_name, self::$request_hash, self::get_url_hash(), $mysqli->real_escape_string( $data ), time(), 0, $mysqli->real_escape_string( $data ), time() ) );

		$mysqli->close();

		// We don't need an output if we're in a background task.
		if ( self::$fcgi_regenerate )
			return;

		return $output;
	}

	/**
	 * Essentially an md5 cache for domain.com/path?query used to
	 * bust caches by URL when needed.
	 */
	private static function get_url_hash( $url = false ) {
		if ( ! $url )
			return md5( $_SERVER['HTTP_HOST'] . self::parse_request_uri( $_SERVER['REQUEST_URI'] ) );

		$parsed = parse_url( $url );
		$request_uri = $parsed['path'];
		if ( ! empty( $parsed['query'] ) )
			$request_uri .= '?' . $parsed['query'];

		return md5( $parsed['host'] . self::parse_request_uri( $request_uri ) );
	}

	public static function transition_post_status( $new_status, $old_status, $post ) {
		if ( $new_status != 'publish' && $old_status != 'publish' )
			return;

		self::clear_cache_by_post_id( $post->ID );
	}

	/**
	 * A post has changed so attempt to clear some cached pages.
	 */
	public static function clean_post_cache( $post_id ) {
		$post = get_post( $post_id );
		if ( $post->post_status != 'publish' )
			return;

		self::clear_cache_by_post_id( $post_id );
	}

	/**
	 * Clear cache by URLs. Gladly accepts (and appreciates) arrays to
	 * minimize the number of SQL queries.
	 */
	public static function clear_cache_by_url( $urls ) {
		$mysqli = self::get_mysqli();
		if ( ! ( $mysqli instanceof mysqli ) )
			return;

		if ( is_string( $urls ) )
			$urls = array( $urls );

		$hashes = array();
		foreach ( $urls as $url ) {
			$hashes[] = "'" . $mysqli->real_escape_string( self::get_url_hash( $url ) ) . "'";
		}

		$hashes = implode( ', ', $hashes );
		$mysqli->query( sprintf( "UPDATE `%s` SET `updated` = 0, `locked` = 0 WHERE `url_hash` IN ( %s );", self::$table_name, $hashes ) );
	}

	public static function clear_cache_by_post_id( $post_id ) {
		self::clear_cache_by_url( array(
			get_option( 'home' ),
			trailingslashit( get_option( 'home' ) ),
			get_permalink( $post_id ),
			get_bloginfo( 'rss2_url' ),
		) );
	}

	/**
	 * Runs during init, schedules events on the main site admin.
	 */
	public static function init() {
		global $wpdb;

		if ( is_main_site() && is_admin() && ! wp_next_scheduled( 'pj_page_cache_gc' ) )
			wp_schedule_event( time(), 'hourly', 'pj_page_cache_gc' );

		// Install or upgrade.
		if ( is_main_site() && is_admin() && get_site_option( 'pj_page_cache_version', 0 ) < self::$version ) {
			$wpdb->query( sprintf( "DROP TABLE IF EXISTS `%s`;", self::$table_name ) );
			$wpdb->query( sprintf( "CREATE TABLE `%s` (
				id int(11) NOT NULL AUTO_INCREMENT,
				hash char(32) NOT NULL,
				url_hash char(32) NOT NULL,
				data longtext,
				updated int(11) NOT NULL,
				locked int(1) NOT NULL,

				PRIMARY KEY (`id`),
				UNIQUE KEY (`hash`),
				KEY (`url_hash`),
				KEY (`updated`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8;", self::$table_name ) );
			update_site_option( 'pj_page_cache_version', self::$version );
		}

		add_action( 'pj_page_cache_gc', array( __CLASS__, 'gc' ) );
	}

	/**
	 * Gargabe Collection
	 *
	 * Removes cache rows that have not been updated in the last 24 hours.
	 * Never run this on a user-facing page.
	 */
	public static function gc() {
		global $wpdb;

		$timestamp = time() - max( self::$ttl, 24 * HOUR_IN_SECONDS );
		$time_start = time();
		$time_limit = 30;
		$batch = 500;

		// Delete in batches to avoid holding locks for long periods of time.
		while ( time() < $time_start + $time_limit ) {
			$affected_rows = $wpdb->query( sprintf( "DELETE FROM `%s` WHERE `updated` < %d LIMIT %d;", self::$table_name, $timestamp, $batch ) );
			if ( $affected_rows < $batch )
				break;
		}
	}
}