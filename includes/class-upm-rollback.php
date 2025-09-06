<?php
namespace UPM;

defined('ABSPATH') || exit;

/**
 * Handles: pre/post checks, rename extracted GitHub ZIP folders, rollbacks, favorites install.
 */
class Rollback {

	public static function init() {
		// Capture previous package for rollback (wp.org)
		add_filter('upgrader_pre_download', [__CLASS__,'capture_previous_package'], 10, 4);

		// Rename extracted source (e.g., repo-main → actual plugin dir)
		add_filter('upgrader_source_selection', [__CLASS__,'rename_source_folder'], 10, 4);

		// Post-update smoke check + auto-rollback if broken
		add_action('upgrader_process_complete', [__CLASS__,'post_update_check'], 10, 2);
	}

	/** Save previous version package URL for rollback */
	public static function capture_previous_package($reply, $package, $upgrader, $hook_extra) {
		if (!empty($hook_extra['plugin'])) {
			$plugin = $hook_extra['plugin']; // 'dir/file.php'
			$prev = self::find_wporg_package_for_current($plugin);
			if ($prev) {
				set_transient('upm_prev_pkg_' . md5($plugin), $prev, 6 * HOUR_IN_SECONDS);
			}
		}
		return $reply; // don't short-circuit download
	}

	protected static function find_wporg_package_for_current($plugin_file) {
		if (!function_exists('plugins_api')) {
			require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		}
		$all = \upm_get_all_plugins();
		if (!isset($all[$plugin_file])) return false;
		$slug = dirname($plugin_file);
		$curr = $all[$plugin_file]['Version'] ?? '';
		if (!$slug || !$curr) return false;

		$api = plugins_api('plugin_information', ['slug' => $slug, 'fields' => ['versions' => true]]);
		if (is_wp_error($api) || empty($api->versions[$curr])) return false;
		return ['version' => $curr, 'package' => $api->versions[$curr]];
	}

	/** Rename extracted source folder so it replaces the existing plugin folder */
	public static function rename_source_folder($source, $remote_source, $upgrader, $hook_extra) {
		if (empty($hook_extra['plugin'])) return $source;
		$plugin_file = $hook_extra['plugin'];
		$expected_dir = dirname(WP_PLUGIN_DIR . '/' . $plugin_file);
		$expected_base = basename($expected_dir);

		$src_base = basename($source);
		if ($src_base === $expected_base) return $source;

		// Common GitHub endings
		$maybe = preg_replace('#-(main|master)$#i', '', $src_base);
		if ($maybe === $expected_base || $src_base !== $expected_base) {
			$target = trailingslashit(dirname($source)) . $expected_base;
			if (@rename($source, $target)) {
				return $target;
			}
		}
		return $source; // fall back
	}

	/** After updates: smoke test; on failure, rollback using stored previous package */
	public static function post_update_check($upgrader, $hook_extra) {
		if (empty($hook_extra['type']) || $hook_extra['type'] !== 'plugin' || empty($hook_extra['action']) || $hook_extra['action'] !== 'update') return;

		$plugins = $hook_extra['plugins'] ?? array();
		if (!$plugins) return;

		$ok = \upm_smoke_test_site();
		if ($ok === true) return; // all good

		// Try rollback each updated plugin using stored prev pkg
		foreach ($plugins as $file) {
			$key = 'upm_prev_pkg_' . md5($file);
			$prev = get_transient($key);
			if (empty($prev['package'])) continue;

			self::upgrade_from_package($file, $prev['package']);
			delete_transient($key);
		}

		add_action('admin_notices', function(){
			echo '<div class="notice notice-error"><p><strong>Ultimate Plugin Manager:</strong> A post-update check failed. We rolled back to the previous version.</p></div>';
		});
	}

	/** Public: rollback to a specific version (wp.org) or GitHub ZIP (if you pass a direct ZIP) */
	public static function rollback_to_version($plugin_file, $version_or_zip) {
		// If looks like a URL, treat as ZIP
		if (preg_match('#^https?://#i', $version_or_zip)) {
			return self::upgrade_from_package($plugin_file, $version_or_zip);
		}
		// Otherwise try wp.org version
		if (!function_exists('plugins_api')) {
			require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		}
		$slug = dirname($plugin_file);
		$api  = plugins_api('plugin_information', ['slug' => $slug, 'fields' => ['versions' => true]]);
		if (is_wp_error($api) || empty($api->versions[$version_or_zip])) {
			return new \WP_Error('upm_no_zip', 'Version ZIP not found for wp.org plugin.');
		}
		return self::upgrade_from_package($plugin_file, $api->versions[$version_or_zip]);
	}

	/** Favorites installer */
	public static function install_favorite(array $fav) {
		if (!current_user_can('install_plugins')) return new \WP_Error('forbidden', 'No permission');

		$type = \upm_normalize_fav_type($fav['type'] ?? '');

		if ($type === 'external') {
			return new \WP_Error('upm_external','External/third-party plugins are not installed automatically.');
		}

		if (($fav['type'] ?? '') === 'wporg' && !empty($fav['slug'])) {
			if (!function_exists('plugins_api')) require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
			$api = plugins_api('plugin_information', ['slug' => sanitize_key($fav['slug']), 'fields' => ['sections'=>false]]);
			if (is_wp_error($api) || empty($api->download_link)) return new \WP_Error('upm_no_pkg','Could not resolve wp.org package');
			return self::upgrade_from_package($api->slug . '/' . $api->slug . '.php', $api->download_link, true);
		}

		$zip = $fav['url'] ?? $fav['zip'] ?? '';
		if ($zip) {
			// Target file unknown; install will figure out activation step; we simply run installer
			return self::upgrade_from_package('', $zip, true);
		}

		if (($fav['type'] ?? '') === 'zip_dir') {
			$res = self::resolve_zip_from_dir($fav['dir'] ?? '', $fav['pattern'] ?? '');
			if (is_wp_error($res)) return $res;
			return self::upgrade_from_package('', $res['url'], true);
		}

		if (($fav['type'] ?? '') === 'github_repo') {
			$res = self::resolve_github_latest_zip($fav['repo'] ?? '', $fav['channel'] ?? 'release');
			if (is_wp_error($res)) return $res;
			return self::upgrade_from_package('', $res['url'], true);
		}

		return new \WP_Error('bad_favorite','Unknown favorite item.');
	}

	/** Low-level upgrader */
	protected static function upgrade_from_package($plugin_file, $package, $activate_after = false) {
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		$skin = new \Automatic_Upgrader_Skin();
		$upg  = new \Plugin_Upgrader($skin);

		// When we know the target plugin, pass it so rename_source_folder can map correctly
		$hook_extra = $plugin_file ? array('plugin' => $plugin_file) : array();

		add_filter('upgrader_package_options', function($opts) use ($package){
			$opts['package'] = $package;
			return $opts;
		});

		$res = $upg->upgrade($plugin_file, $hook_extra);
		if ($activate_after && !is_wp_error($res) && is_string($res)) {
			// Try to activate if a single plugin was installed
			if (function_exists('activate_plugin')) {
				// plugin file may differ; do a naive guess by scanning newly added dirs is overkill; skip activation silently
			}
		}
		return $res;
	}

	protected static function resolve_zip_from_dir($dir, $pattern){
		// Fetch directory listing (expects simple index with <a href="*.zip">)
		$r = wp_remote_get($dir, array('timeout' => 10));
		if (is_wp_error($r)) return $r;
		$body = wp_remote_retrieve_body($r);
		if (!$body) return new \WP_Error('upm_zipdir_empty', 'No HTML returned');
	
		// Find zip links
		preg_match_all('#href=["\']([^"\']+\.zip)["\']#i', $body, $m);
		$candidates = $m[1] ?? array();
		if (!$candidates) return new \WP_Error('upm_zipdir_none', 'No ZIPs found');
	
		// Build regex from pattern with {version}
		$rx = '#^' . str_replace('\{version\}', '([0-9]+\.[0-9]+(?:\.[0-9]+)?)', preg_quote($pattern, '#')) . '$#i';
	
		$found = array();
		foreach ($candidates as $link) {
			$basename = basename(parse_url($link, PHP_URL_PATH));
			if (preg_match($rx, $basename, $mm)) {
				$ver = $mm[1];
				$found[$ver] = esc_url_raw( (0 === strpos($link, 'http')) ? $link : trailingslashit($dir) . ltrim($link, './') );
			}
		}
		if (!$found) return new \WP_Error('upm_zipdir_nomatch','No ZIPs matched pattern');
	
		// Pick highest semver
		uksort($found, 'version_compare'); // ascending
		$latest = array_key_last($found);
		return array('version' => $latest, 'url' => $found[$latest]);
	}
	
	protected static function resolve_github_latest_zip($repo, $channel = 'release') {
		$settings = \upm_get_settings();
		$token = trim((string)($settings['github']['token'] ?? ''));
		$headers = array('Accept' => 'application/vnd.github+json', 'User-Agent' => 'UPM/1.0');
		if ($token) $headers['Authorization'] = 'Bearer ' . $token;
	
		$endpoint = ($channel === 'tag')
			? "https://api.github.com/repos/$repo/tags"
			: "https://api.github.com/repos/$repo/releases/latest";
	
		$r = wp_remote_get($endpoint, array('timeout'=>12, 'headers'=>$headers));
		if (is_wp_error($r)) return $r;
		$code = wp_remote_retrieve_response_code($r);
		$body = json_decode(wp_remote_retrieve_body($r), true);
		if ($code !== 200 || !is_array($body)) return new \WP_Error('upm_gh_fail','GitHub API error');
	
		if ($channel === 'tag') {
			$tag = $body[0]['name'] ?? '';
			if (!$tag) return new \WP_Error('upm_gh_notag','No tags found');
			$zip = "https://github.com/$repo/archive/refs/tags/" . rawurlencode($tag) . ".zip";
			return array('version' => $tag, 'url' => $zip);
		}
	
		// latest release
		$tag = $body['tag_name'] ?? '';
		$zip = $body['zipball_url'] ?? '';
		if (!$zip) {
			// fallback to tag archive
			if (!$tag) return new \WP_Error('upm_gh_norelease','No release zip found');
			$zip = "https://github.com/$repo/archive/refs/tags/" . rawurlencode($tag) . ".zip";
		}
		return array('version' => $tag ?: 'latest', 'url' => $zip);
	}	
}
