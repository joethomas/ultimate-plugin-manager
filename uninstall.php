<?php
// If this file is called directly, abort.
if (!defined('WP_UNINSTALL_PLUGIN')) exit;

// Clean up option on uninstall (not on deactivate).
delete_option('upm_settings');

// If multisite, clean up on uninstall (not on deactivate).
if (is_multisite()) delete_site_option('upm_settings');
