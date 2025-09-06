<?php
defined('ABSPATH') || exit;

/**
 * Default settings
 */
function upm_default_settings() {
	return array(
		'protected'  => array(
			'sucuri-scanner/sucuri.php',
			'wordfence/wordfence.php',
			'elementor/elementor.php',
			'elementor-pro/elementor-pro.php',
		),
		'locked_on'  => array(
			'sucuri-scanner/sucuri.php',
			'wordfence/wordfence.php',
		),
		'locked_off' => array(
			'elementor/elementor.php',
			'elementor-pro/elementor-pro.php',
		),
		'freeze'     => array( /* 'plugin/file.php' => '1.2.3' */ ),
		'notes'      => array( /* 'plugin/file.php' => [ 'text'=>'', 'type'=>'freeze|lock', 'author'=>0, 'time'=>0 ] */ ),
		'vuln'       => array(
			'provider'        => '',          // '', 'wpscan', 'patchstack', 'wordfence'
			'api_key'         => '',
			'severity'        => 'high',     // 'low','medium','high','critical'
			'respect_freeze'  => true,       // do not auto-unfreeze to patch
			'respect_wf_ignore' => true,     // if Wordfence is installed, honor ignored vulns
		),
		'network'    => array(
			'policy' => array(
				'protected'  => array(),
				'locked_on'  => array(),
				'locked_off' => array(),
				'freeze'     => array(), // file => version
			),
			'allow_site_override' => array() // file => bool
		),
		'github' => array(
			'token' => '', // optional personal access token for higher GitHub rate limits
		),
		'favorites' => array( /* DB-stored; merged with file */ ),
	);
}

/** Merge + normalize options */
function upm_get_settings() {
	$defaults = upm_default_settings();
	$settings = get_option('upm_settings', array());
	if (!is_array($settings)) $settings = array();
	$merged = array_replace_recursive($defaults, $settings);

	foreach (array('protected','locked_on','locked_off') as $k) {
		if (!is_array($merged[$k])) $merged[$k] = array();
		$merged[$k] = array_values(array_unique(array_filter($merged[$k], 'is_string')));
	}

	if (!is_array($merged['freeze'])) $merged['freeze'] = array();
	if (!is_array($merged['notes']))  $merged['notes']  = array();

	return $merged;
}

/** Site + Network policy merge (simple, safe defaults). */
function upm_effective_settings() {
	if (!is_multisite()) return upm_get_settings();

	$site = upm_get_settings();
	$net  = get_site_option('upm_settings', array());
	$netp = isset($net['network']['policy']) ? $net['network']['policy'] : array();
	$allow = isset($net['network']['allow_site_override']) ? $net['network']['allow_site_override'] : array();

	$eff = $site;

	foreach (array('protected','locked_on','locked_off') as $k) {
		$eff[$k] = isset($netp[$k]) && is_array($netp[$k]) ? array_values(array_unique(array_merge($netp[$k], $site[$k]))) : $site[$k];
		if (!empty($allow)) {
			// If override not allowed for a plugin, force network setting by ensuring it exists (already unioned).
			// (Advanced replacement logic can be added later.)
		}
	}
	if (isset($netp['freeze']) && is_array($netp['freeze'])) {
		$eff['freeze'] = $netp['freeze'] + $site['freeze']; // network freeze wins
	}

	return $eff;
}

/** Sanitize incoming settings */
function upm_sanitize_settings($raw) {
	$out = upm_get_settings();

	foreach (array('protected','locked_on','locked_off') as $k) {
		$out[$k] = array();
		$in = isset($raw[$k]) && is_array($raw[$k]) ? $raw[$k] : array();
		foreach ($in as $file) {
			$file = sanitize_text_field($file);
			if ($file && false !== strpos($file, '/')) $out[$k][] = $file;
		}
		$out[$k] = array_values(array_unique($out[$k]));
	}

	// Freeze: file => version
	$out['freeze'] = array();
	if (!empty($raw['freeze']) && is_array($raw['freeze'])) {
		foreach ($raw['freeze'] as $file => $ver) {
			$file = sanitize_text_field($file);
			$ver  = sanitize_text_field($ver);
			if ($file && $ver) $out['freeze'][$file] = $ver;
		}
	}

	// Notes
	$out['notes'] = array();
	if (!empty($raw['notes']) && is_array($raw['notes'])) {
		foreach ($raw['notes'] as $file => $note) {
			$file = sanitize_text_field($file);
			if (!$file || !is_array($note)) continue;
			$text = isset($note['text']) ? wp_kses_post($note['text']) : '';
			$type = isset($note['type']) ? sanitize_key($note['type']) : '';
			if ($text) {
				$out['notes'][$file] = array(
					'text'   => $text,
					'type'   => in_array($type, array('freeze','lock'), true) ? $type : 'lock',
					'author' => get_current_user_id(),
					'time'   => time(),
				);
			}
		}
	}

	// Normalize Favorite Types
	function upm_normalize_fav_type($t) {
		$t = strtolower(trim((string)$t));
		if (in_array($t, ['wporg','zip','zip_dir','github_repo'], true)) return $t;
		if ($t === '' || in_array($t, ['third-party','third_party','premium','external'], true)) return 'external';
		return 'external';
	}
	
	// External URL Helper
	function upm_fav_external_url(array $fav) {
		if (!empty($fav['url'])) return esc_url_raw($fav['url']);
		$slug = trim((string)($fav['slug'] ?? ''));
		if (!$slug) return '';
		// If slug already looks like a URL, use it; else treat as domain.
		if (filter_var($slug, FILTER_VALIDATE_URL)) return esc_url_raw($slug);
		if (preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $slug)) return 'https://' . $slug;
		return '';
	}

	// Sanitize Favorites
	$out['favorites'] = array();
	if (!empty($raw['favorites']) && is_array($raw['favorites'])) {
		foreach ($raw['favorites'] as $fav) {
			if (!is_array($fav)) continue;
			$type = sanitize_key($fav['type'] ?? '');
			$item = array('type' => $type, 'title' => sanitize_text_field($fav['title'] ?? ''));
			if ($type === 'wporg')       $item['slug']   = sanitize_key($fav['slug'] ?? '');
			if ($type === 'zip')         $item['url']    = esc_url_raw($fav['url'] ?? '');
			if ($type === 'zip_dir')    { $item['dir']    = esc_url_raw($fav['dir'] ?? ''); $item['pattern'] = sanitize_text_field($fav['pattern'] ?? ''); }
			if ($type === 'github_repo') { $item['repo']   = sanitize_text_field($fav['repo'] ?? ''); $item['channel'] = in_array(($fav['channel']??''), array('release','tag'), true) ? $fav['channel'] : 'release'; }
			if (upm_normalize_fav_type($type) === 'external') { $item['url'] = esc_url_raw($fav['url'] ?? ''); $item['slug'] = sanitize_text_field($fav['slug'] ?? ''); }
			$out['favorites'][] = $item;
		}
	}

	$out['github']['token'] = isset($raw['github']['token'])
		? sanitize_text_field($raw['github']['token'])
		: '';

	// Conflict: cannot be both locked ON and OFF
	$both = array_intersect($out['locked_on'], $out['locked_off']);
	if ($both) {
		$out['locked_off'] = array_values(array_diff($out['locked_off'], $both));
	}

	return $out;
}

function upm_get_all_plugins() {
	if (!function_exists('get_plugins')) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	return get_plugins();
}

function upm_plugin_slug_from_file($file) {
	$parts = explode('/', $file);
	return sanitize_key($parts[0]);
}

/** Simple site “smoke test” */
function upm_smoke_test_site($timeout = 10) {
	$url = home_url('/');
	$resp = wp_remote_get($url, array('timeout' => $timeout, 'redirection' => 2));
	if (is_wp_error($resp)) return $resp;
	$code = wp_remote_retrieve_response_code($resp);
	return ($code >= 200 && $code < 400) ? true : new WP_Error('upm_http_fail', 'Non-OK after update: ' . $code);
}

/** Load favorite plugins  */
function upm_favorites_from_file() {
	$file = trailingslashit(UPM_PATH) . 'config/favorites.json';
	if (!file_exists($file)) return array();
	$json = file_get_contents($file);
	$data = json_decode($json, true);
	return is_array($data) ? $data : array();
}

/** Final list the UI uses (file first, then DB overrides/extends) */
function upm_get_favorites() {
	$settings = upm_get_settings();
	$fileFavs = upm_favorites_from_file();
	$dbFavs   = isset($settings['favorites']) && is_array($settings['favorites']) ? $settings['favorites'] : array();

	// Simple merge: file first (version-controlled), then append DB (UI-added)
	return array_values(array_merge($fileFavs, $dbFavs));
}
