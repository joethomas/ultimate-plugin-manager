<?php
// If this file is called directly, abort.
if (!defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

// Clean up option on uninstall (not on deactivate).
delete_option('upm_settings');

// If multisite and you later store site_option, also delete_site_option('upm_settings');
