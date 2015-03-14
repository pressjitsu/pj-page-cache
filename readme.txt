=== Pj Page Cache ===
Contributors: pressjitsu
Tags: cache, performance
Requires at least: 4.1
Tested up to: 4.2
Stable tag: 0.7
License: GPLv3
License URI: http://www.gnu.org/licenses/gpl-3.0.txt

Pj Page Cache is a MySQL-backed full-page cache plugin for WordPress.

== Description ==

The most efficient caching layer on [Pressjitsu](http://pressjitsu.com) is full-page caching backed by a finely tuned MySQL instance. InnoDB's row-level locking and high-speed index lookups ensure very quick cache delivery.

**Requirements**

* PHP 5.3+
* MySQL 5.5+
* InnoDB engine for MySQL (don't even try this with MyISAM)

**How a Request is Served**

When an HTTP requests hits the running WordPress instance, a request hash is generated based on the contents of the request. This hash is then searched within the cached data table, and if found and valid, it is served from cache, which means WordPress' execution stops at the advanced-cache.php level and never goes further to loading any themes, plugins, etc. This is considered a cache hit.

By default, Pj Page Cache is configured with a low cache TTL, which means cached data expires very often. Expired cache entries are not thrown away immediately, but instead used to serve stale data while the cache entry is being regenerated. This technique allows us to avoid race conditions and is particularly useful in high-concurrency environments.

Page caches can also be invalidated by WordPress’ built-in object cache invalidation functions (when publishing a new post, or updating a page for example), or triggered by calling the custom set of invalidation functions.

Invalidated cache entries are deleted by the garbage collection routine which runs once every hour as a background task triggered by WordPress’ scheduling API. To ensure better performance, garbage collection will never delete recent stale cache entries, as well as entries locked for regeneration.

**Configuration**

Unlike most caching plugins, Pj Page Cache does not offer a GUI for configuration. We believe caching should work without any additional user interaction. However, we do provide a caching configuration file (pj-cache-config.php) to alter some settings, such as TTL, ignored query string arguments and cookies (utm_*, _ga*, etc.), additional cache varying buckets (mobile visitors, etc.) and others.

== Installation ==

1. Install and activate the plugin just like any plugin you've installed.
1. Copy the plugin's advanced-cache.php file to your wp-content directory.
1. Place `define( 'WP_CACHE', true );` in your wp-config.php file.
1. Visit your site's Dashboard (main site's Dashboard in a Multisite install).

You'll know then it's working correctly, because WordPress will be freaking fast.

== Frequently Asked Questions ==

= Can I haz CSS/JS minify/concat, image optimization, CDN? =

No. This is a server-side performance only plugin. It makes sure your WordPress-generated pages are delivered fast. To optimize your jQuery sliders and cat pictures, you can use another plugin and/or service in conjunction with Pj Page Cache.

== Changelog ==

= 0.7 =
* Initial public release.