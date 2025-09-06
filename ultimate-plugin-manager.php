<?php
/*
	Plugin Name: Ultimate Plugin Manager
	Description: Lock down key plugins: prevent deactivate/delete, force auto-updates ON/OFF, freeze versions, rollback safely, and add admin notes—all from one screen.
	Version: 0.3.0
	Author: Joe Thomas
	Author URI: https://localboost.com
	Text Domain: ultimate-plugin-manager
	Requires at least: 5.8
	Tested up to: 6.x

	GitHub Plugin URI: joethomas/ultimate-plugin-manager
	Primary Branch: main
*/

defined('ABSPATH') || exit;

define('UPM_VERSION', '0.3.0');
define('UPM_FILE', __FILE__);
define('UPM_PATH', plugin_dir_path(__FILE__));
define('UPM_URL',  plugin_dir_url(__FILE__));

require_once UPM_PATH . 'includes/helpers.php';
require_once UPM_PATH . 'includes/class-upm-core.php';
require_once UPM_PATH . 'includes/class-upm-admin.php';
require_once UPM_PATH . 'includes/class-upm-rollback.php';
require_once UPM_PATH . 'includes/class-upm-vuln.php';

register_activation_hook(__FILE__, function () {
	if (false === get_option('upm_settings')) {
		add_option('upm_settings', upm_default_settings(), false);
	}
	\UPM\Vuln::schedule();
});

register_deactivation_hook(__FILE__, function(){
	\UPM\Vuln::unschedule();
});

add_action('plugins_loaded', function () {
	\UPM\Core::init();
	if (is_admin()) {
		\UPM\Admin::init();
	}
	\UPM\Rollback::init();   // rename handling + safety checks
	\UPM\Vuln::init();       // provider-agnostic vuln scan scaffold
});

// "Settings" link on Plugins list row
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($links) {
	$settings_url = admin_url('options-general.php?page=upm-settings');
	$links[] = '<a href="' . esc_url($settings_url) . '">' . esc_html__('Settings', 'ultimate-plugin-manager') . '</a>';
	return $links;
});
