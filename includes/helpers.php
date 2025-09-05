<?php
defined('ABSPATH') || exit;

/**
 * Default settings (mirrors your current hard-coded lists).
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
	);
}

/**
 * Get settings merged with defaults.
 */
function upm_get_settings() {
	$defaults = upm_default_settings();
	$settings = get_option('upm_settings', array());
	if (!is_array($settings)) {
		$settings = array();
	}
	$merged = array_merge(
		$defaults,
		array_intersect_key($settings, $defaults)
	);

	// Ensure arrays + unique values
	foreach (array('protected','locked_on','locked_off') as $k) {
		if (!isset($merged[$k]) || !is_array($merged[$k])) {
			$merged[$k] = array();
		}
		$merged[$k] = array_values(array_unique(array_filter($merged[$k], 'is_string')));
	}
	return $merged;
}

/**
 * Sanitize incoming settings + prevent conflicts.
 * (A plugin cannot be in both locked_on and locked_off.)
 */
function upm_sanitize_settings($raw) {
	$out = upm_default_settings();

	foreach (array('protected','locked_on','locked_off') as $k) {
		$in = isset($raw[$k]) && is_array($raw[$k]) ? $raw[$k] : array();
		$out[$k] = array();

		foreach ($in as $plugin_file) {
			$plugin_file = sanitize_text_field($plugin_file);
			if ($plugin_file && false !== strpos($plugin_file, '/')) {
				$out[$k][] = $plugin_file;
			}
		}

		$out[$k] = array_values(array_unique($out[$k]));
	}

	// Resolve conflicts between locked_on and locked_off
	if (!empty($out['locked_on']) && !empty($out['locked_off'])) {
		$both = array_intersect($out['locked_on'], $out['locked_off']);
		if (!empty($both)) {
			// Prefer the user's most recent selection by removing from the opposite list.
			// To keep it deterministic, remove conflicts from locked_off.
			$out['locked_off'] = array_values(array_diff($out['locked_off'], $both));
		}
	}

	return $out;
}

/**
 * Get all installed plugins (requires wp-admin/includes/plugin.php).
 */
function upm_get_all_plugins() {
	if (!function_exists('get_plugins')) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	return get_plugins(); // returns [ 'plugin-dir/file.php' => array( 'Name' => '...', ... ) ]
}

/**
 * Utility: plugin slug from file path.
 */
function upm_plugin_slug_from_file($file) {
	$parts = explode('/', $file);
	return sanitize_key($parts[0]);
}
