<?php
namespace UPM;

defined('ABSPATH') || exit;

class Core {

	public static function init() {
		// Hide deactivate/delete links for protected plugins
		add_filter('plugin_action_links', [__CLASS__, 'filter_action_links'], 10, 4);
		add_filter('network_admin_plugin_action_links', [__CLASS__, 'filter_action_links'], 10, 4);

		// Replace auto-update toggle UI with lock badges
		add_filter('plugin_auto_update_setting_html', [__CLASS__, 'filter_auto_update_toggle'], 10, 2);

		// Enforce auto-update status
		add_filter('auto_update_plugin', [__CLASS__, 'enforce_auto_updates'], 10, 2);

		// Block deactivation via options update
		add_filter('pre_update_option_active_plugins', [__CLASS__, 'prevent_manual_deactivation'], 10, 2);
		// Multisite: also keep sitewide actives from being removed
		add_filter('pre_update_site_option_active_sitewide_plugins', [__CLASS__, 'prevent_sitewide_deactivation'], 10, 2);

		// Hide checkboxes for protected plugins + show lock in Plugins screen
		add_action('admin_footer-plugins.php', [__CLASS__, 'inject_lock_js']);

		// Intercept and prevent deletion of protected plugins (bulk/single)
		add_action('load-plugins.php', [__CLASS__, 'intercept_delete_actions']);

		// Admin notice if we stripped protected plugins from deletion
		add_action('admin_notices', [__CLASS__, 'maybe_notice_protected_blocked']);
	}

	public static function filter_action_links($actions, $plugin_file, $plugin_data, $context) {
		$s = \upm_get_settings();
		if (in_array($plugin_file, $s['protected'], true)) {
			unset($actions['deactivate']);
			unset($actions['delete']);
		}
		return $actions;
	}

	public static function filter_auto_update_toggle($html, $plugin_file) {
		$s = \upm_get_settings();

		if (in_array($plugin_file, $s['locked_on'], true)) {
			return '<span><span class="dashicons dashicons-lock" title="Protected plugin"></span> ' .
				'Auto-updates locked <span class="upm-badge upm-badge-on">ON</span></span>';
		}
		if (in_array($plugin_file, $s['locked_off'], true)) {
			return '<span><span class="dashicons dashicons-lock" title="Protected plugin"></span> ' .
				'Auto-updates locked <span class="upm-badge upm-badge-off">OFF</span></span>';
		}
		return $html;
	}

	public static function enforce_auto_updates($update, $item) {
		$s = \upm_get_settings();
		if (isset($item->plugin)) {
			if (in_array($item->plugin, $s['locked_on'], true)) {
				return true;
			}
			if (in_array($item->plugin, $s['locked_off'], true)) {
				return false;
			}
		}
		return $update;
	}

	public static function prevent_manual_deactivation($new_value, $old_value) {
		$s = \upm_get_settings();
		$new = is_array($new_value) ? $new_value : array();
		$old = is_array($old_value) ? $old_value : array();
		$protect = $s['protected'];

		// Ensure any protected plugin that was previously active stays active.
		$keep = array_intersect($old, $protect);
		$merged = array_values(array_unique(array_merge($new, $keep)));
		return $merged;
	}

	public static function prevent_sitewide_deactivation($new_value, $old_value) {
		// $new_value and $old_value are associative arrays of plugin_file => time
		$s = \upm_get_settings();
		$protect = $s['protected'];

		$new = is_array($new_value) ? $new_value : array();
		$old = is_array($old_value) ? $old_value : array();

		foreach ($protect as $plugin_file) {
			if (isset($old[$plugin_file])) {
				// Re-add to sitewide actives if it was there before.
				$new[$plugin_file] = $old[$plugin_file];
			}
		}
		return $new;
	}

	public static function inject_lock_js() {
		$s = \upm_get_settings();
		$slugs = array_map('\upm_plugin_slug_from_file', $s['protected']);
		?>
		<style>
			.upm-badge{display:inline-block;padding:0 4px;border-radius:2px;font-size:11px;font-weight:600;line-height:1.65;transform:translateY(-1px)}
			.upm-badge-on{background:#11967A;color:#fff}
			.upm-badge-off{background:#c42b1c;color:#fff}
		</style>
		<script>
		document.addEventListener('DOMContentLoaded',function(){
			var protectedSlugs = <?php echo wp_json_encode(array_values(array_unique($slugs))); ?>;
			protectedSlugs.forEach(function(slug){
				var row = document.querySelector('tr[data-slug="'+ slug +'"]');
				if(!row) return;
				var th = row.querySelector('th.check-column');
				if(!th) return;
				th.innerHTML = '';
				var lock = document.createElement('span');
				lock.className = 'dashicons dashicons-lock';
				lock.title = 'This is a protected plugin. It cannot be deactivated or deleted.';
				lock.style.fontSize = '18px';
				lock.style.color = '#666';
				lock.style.paddingLeft = '6px';
				lock.style.paddingTop = '2px';
				th.appendChild(lock);
			});
		});
		</script>
		<?php
	}

	/**
	 * Prevent deletion of protected plugins via bulk/single delete actions.
	 * We strip protected plugins from the 'checked' list and show a notice.
	 */
	public static function intercept_delete_actions() {
		if (!current_user_can('delete_plugins')) {
			return;
		}

		$action  = isset($_REQUEST['action']) ? sanitize_text_field(wp_unslash($_REQUEST['action'])) : '';
		$action2 = isset($_REQUEST['action2']) ? sanitize_text_field(wp_unslash($_REQUEST['action2'])) : '';
		if ($action !== 'delete-selected' && $action2 !== 'delete-selected') {
			return;
		}

		if (!isset($_REQUEST['checked']) || !is_array($_REQUEST['checked'])) {
			return;
		}

		check_admin_referer('bulk-plugins');

		$s = \upm_get_settings();
		$protected = $s['protected'];
		$checked   = array_map('sanitize_text_field', wp_unslash($_REQUEST['checked']));
		$allowed   = array_values(array_diff($checked, $protected));

		if (count($allowed) !== count($checked)) {
			// Update request payload in-place to prevent deletion of protected.
			$_REQUEST['checked'] = $allowed;
			// Flag a notice for after redirect or completion.
			set_transient('upm_blocked_delete_notice', 1, 60);
		}
	}

	public static function maybe_notice_protected_blocked() {
		if (get_transient('upm_blocked_delete_notice')) {
			delete_transient('upm_blocked_delete_notice');
			echo '<div class="notice notice-warning is-dismissible"><p><strong>Ultimate Plugin Manager:</strong> Protected plugins were skipped and not deleted.</p></div>';
		}
	}
}
