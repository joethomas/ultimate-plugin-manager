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

	/**
	 * Rename extracted source folder.
	 * - If we know the target plugin, rename to its existing base folder.
	 * - Otherwise (fresh install), strip -main/-master suffix so the folder matches the repo name.
	 */
	public static function rename_source_folder($source, $remote_source, $upgrader, $hook_extra) {
		$src_base = basename($source);

		// Fresh installs (unknown plugin): strip common GitHub suffixes.
		if (empty($hook_extra['plugin'])) {
			$maybe = preg_replace('#-(main|master)$#i', '', $src_base);
			if ($maybe !== $src_base) {
				$target = trailingslashit(dirname($source)) . $maybe;
				if (@rename($source, $target)) return $target;
			}
			return $source;
		}

		// Known plugin: force to expected existing base
		$plugin_file   = $hook_extra['plugin'];
		$expected_dir  = dirname(WP_PLUGIN_DIR . '/' . $plugin_file);
		$expected_base = basename($expected_dir);

		if ($src_base === $expected_base) return $source;

		$maybe = preg_replace('#-(main|master)$#i', '', $src_base);
		if ($maybe === $expected_base || $src_base !== $expected_base) {
			$target = trailingslashit(dirname($source)) . $expected_base;
			if (@rename($source, $target)) return $target;
		}
		return $source;
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

			self::upgrade_existing_from_package($file, $prev['package']);
			delete_transient($key);
		}

		add_action('admin_notices', function(){
			echo '<div class="notice notice-error"><p><strong>Ultimate Plugin Manager:</strong> A post-update check failed. We rolled back to the previous version.</p></div>';
		});
	}

	/** Public: rollback to a specific version (wp.org) or direct ZIP URL */
	public static function rollback_to_version($plugin_file, $version_or_zip) {
		if (preg_match('#^https?://#i', $version_or_zip)) {
			return self::upgrade_existing_from_package($plugin_file, $version_or_zip);
		}
		if (!function_exists('plugins_api')) {
			require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		}
		$slug = dirname($plugin_file);
		$api  = plugins_api('plugin_information', ['slug' => $slug, 'fields' => ['versions' => true]]);
		if (is_wp_error($api) || empty($api->versions[$version_or_zip])) {
			return new \WP_Error('upm_no_zip', 'Version ZIP not found for wp.org plugin.');
		}
		return self::upgrade_existing_from_package($plugin_file, $api->versions[$version_or_zip]);
	}

	/** Favorites installer (install new OR update existing) */
	public static function install_favorite(array $fav) {
		if (!current_user_can('install_plugins')) return new \WP_Error('forbidden', 'No permission');

		$type = \upm_normalize_fav_type($fav['type'] ?? '');

		if ($type === 'external') {
			return new \WP_Error('upm_external','External/third-party plugins are not installed automatically.');
		}

		$package = '';
		$target_plugin = ''; // when updating an existing plugin

		if ($type === 'wporg' && !empty($fav['slug'])) {
			if (!function_exists('plugins_api')) require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
			$slug = sanitize_key($fav['slug']);
			$api = plugins_api('plugin_information', ['slug' => $slug, 'fields' => ['sections'=>false]]);
			if (is_wp_error($api) || empty($api->download_link)) return new \WP_Error('upm_no_pkg','Could not resolve wp.org package');
			$package = $api->download_link;
			// If already installed, update that exact plugin
			$target = $slug . '/' . $slug . '.php';
			if (array_key_exists($target, \upm_get_all_plugins())) $target_plugin = $target;

		} elseif ($type === 'zip_dir') {
			$res = self::resolve_zip_from_dir($fav['dir'] ?? '', $fav['pattern'] ?? '');
			if (is_wp_error($res)) return $res;
			$package = $res['url'];

		} elseif ($type === 'github_repo') {
			$res = self::resolve_github_latest_zip($fav['repo'] ?? '', $fav['channel'] ?? 'release');
			if (is_wp_error($res)) return $res;
			$package = $res['url'];

		} elseif ($type === 'zip') {
			$package = esc_url_raw($fav['url'] ?? '');
			if (!$package) return new \WP_Error('bad_favorite','ZIP URL missing.');

		} else {
			return new \WP_Error('bad_favorite','Unknown favorite item.');
		}

		// Decide: update existing or install fresh
		if ($target_plugin) {
			return self::upgrade_existing_from_package($target_plugin, $package);
		}
		return self::install_from_package($package);
	}

	/** -------- low-level helpers -------- */

	/** Update an existing plugin from a custom package URL */
	protected static function upgrade_existing_from_package($plugin_file, $package) {
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		$skin = new \Automatic_Upgrader_Skin();
		$upg  = new \Plugin_Upgrader($skin);

		// Override the package URL for this upgrade.
		add_filter('upgrader_package_options', function($opts) use ($package){
			$opts['package'] = $package;
			return $opts;
		});

		$res = $upg->upgrade($plugin_file, array('plugin' => $plugin_file));

		// Normalize failures to WP_Error so AJAX shows an error.
		if (!$res) {
			$errs = method_exists($skin, 'get_errors') ? $skin->get_errors() : new \WP_Error();
			if (is_wp_error($errs) && $errs->get_error_code()) return $errs;
			return new \WP_Error('upgrade_failed', 'Plugin update failed.');
		}
		return $res; // string plugin file on success
	}

	/** Install a plugin from a ZIP URL; try to activate it; return plugin file on success */
	protected static function install_from_package($package_url) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		if (!WP_Filesystem()) {
			return new \WP_Error('fs_unavailable', 'Could not access the filesystem.');
		}

		// Detect new plugin by diffing before/after.
		wp_clean_plugins_cache(true);
		$before = array_keys(get_plugins());

		$skin = new \Automatic_Upgrader_Skin();
		$upg  = new \Plugin_Upgrader($skin);
		$ok   = $upg->install($package_url); // <-- critical: use install() for fresh installs

		if (!$ok) {
			$errs = method_exists($skin, 'get_errors') ? $skin->get_errors() : new \WP_Error();
			if (is_wp_error($errs) && $errs->get_error_code()) return $errs;
			return new \WP_Error('install_failed', 'Plugin installation failed.');
		}

		wp_clean_plugins_cache(true);
		$after = array_keys(get_plugins());
		$new   = array_values(array_diff($after, $before));
		$plugin_file = $new[0] ?? '';

		// Try to activate
		if ($plugin_file && current_user_can('activate_plugin', $plugin_file) && !is_plugin_active($plugin_file)) {
			$act = activate_plugin($plugin_file);
			if (is_wp_error($act)) return $act;
		}

		return $plugin_file ?: true;
	}

	protected static function resolve_zip_from_dir($dir, $pattern){
		$r = wp_remote_get($dir, array('timeout' => 10));
		if (is_wp_error($r)) return $r;
		$body = wp_remote_retrieve_body($r);
		if (!$body) return new \WP_Error('upm_zipdir_empty', 'No HTML returned');

		preg_match_all('#href=["\']([^"\']+\.zip)["\']#i', $body, $m);
		$candidates = $m[1] ?? array();
		if (!$candidates) return new \WP_Error('upm_zipdir_none', 'No ZIPs found');

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

		$tag = $body['tag_name'] ?? '';
		$zip = $body['zipball_url'] ?? '';
		if (!$zip) {
			if (!$tag) return new \WP_Error('upm_gh_norelease','No release zip found');
			$zip = "https://github.com/$repo/archive/refs/tags/" . rawurlencode($tag) . ".zip";
		}
		return array('version' => $tag ?: 'latest', 'url' => $zip);
	}
}
