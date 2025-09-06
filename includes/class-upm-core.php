<?php
namespace UPM;

defined('ABSPATH') || exit;

class Core {

	public static function init() {
		// Action links & auto-update UI
		add_filter('plugin_action_links', [__CLASS__, 'filter_action_links'], 10, 4);
		add_filter('network_admin_plugin_action_links', [__CLASS__, 'filter_action_links'], 10, 4);
		add_filter('plugin_auto_update_setting_html', [__CLASS__, 'filter_auto_update_toggle'], 10, 2);

		// Enforce auto-updates & freezes
		add_filter('auto_update_plugin', [__CLASS__, 'enforce_auto_updates'], 10, 2);
		add_filter('site_transient_update_plugins', [__CLASS__, 'suppress_updates_for_freeze'], 10, 1);

		// Prevent deactivation of protected plugins
		add_filter('pre_update_option_active_plugins', [__CLASS__, 'prevent_manual_deactivation'], 10, 2);
		add_filter('pre_update_site_option_active_sitewide_plugins', [__CLASS__, 'prevent_sitewide_deactivation'], 10, 2);

		// Lock icon in checkbox column
		add_action('admin_footer-plugins.php', [__CLASS__, 'inject_lock_js']);

		// Intercept deletion
		add_action('load-plugins.php', [__CLASS__, 'intercept_delete_actions']);

		// Notes/Reasons under row
		add_action('after_plugin_row', [__CLASS__, 'render_reason_row'], 10, 2);
	}

	public static function settings() { return \upm_effective_settings(); }

	public static function filter_action_links($actions, $plugin_file, $plugin_data, $context) {
		$s = self::settings();
		if (in_array($plugin_file, $s['protected'], true)) {
			unset($actions['deactivate'], $actions['delete']);
		}
		return $actions;
	}

	public static function filter_auto_update_toggle($html, $plugin_file) {
		$s = self::settings();
		if (in_array($plugin_file, $s['locked_on'], true)) {
			return '<span><span class="dashicons dashicons-lock"></span> Auto-updates locked <span class="upm-badge upm-badge-on">ON</span></span>';
		}
		if (in_array($plugin_file, $s['locked_off'], true)) {
			return '<span><span class="dashicons dashicons-lock"></span> Auto-updates locked <span class="upm-badge upm-badge-off">OFF</span></span>';
		}
		return $html;
	}

	public static function enforce_auto_updates($update, $item) {
		$s = self::settings();
		if (isset($item->plugin)) {
			if (in_array($item->plugin, $s['locked_on'], true))  return true;
			if (in_array($item->plugin, $s['locked_off'], true)) return false;
			// Frozen plugins never auto-update
			if (isset($s['freeze'][$item->plugin])) return false;
		}
		return $update;
	}

	public static function suppress_updates_for_freeze($t) {
		$s = self::settings();
		if (empty($s['freeze']) || !is_object($t) || empty($t->response)) return $t;
		foreach ($s['freeze'] as $file => $ver) {
			unset($t->response[$file]);
			if (isset($t->no_update[$file]) && is_object($t->no_update[$file])) {
				$t->no_update[$file]->new_version = $ver;
			}
		}
		return $t;
	}

	public static function prevent_manual_deactivation($new_value, $old_value) {
		$s = self::settings();
		$new = is_array($new_value) ? $new_value : array();
		$old = is_array($old_value) ? $old_value : array();
		$keep = array_intersect($old, $s['protected']);
		return array_values(array_unique(array_merge($new, $keep)));
	}

	public static function prevent_sitewide_deactivation($new_value, $old_value) {
		$s = self::settings();
		$new = is_array($new_value) ? $new_value : array();
		$old = is_array($old_value) ? $old_value : array();
		foreach ($s['protected'] as $file) {
			if (isset($old[$file])) $new[$file] = $old[$file];
		}
		return $new;
	}

	public static function inject_lock_js() {
		$s = self::settings();
		$slugs = array_map('\upm_plugin_slug_from_file', $s['protected']);
		?>
		<style>
			.upm-badge{display:inline-block;padding:0 4px;border-radius:2px;font-size:11px;font-weight:600;line-height:1.65;transform:translateY(-1px)}
			.upm-badge-on{background:#11967A;color:#fff}.upm-badge-off{background:#c42b1c;color:#fff}
			.upm-reason{border:1px solid #e5e7eb;border-left:4px solid #9ca3af;padding:8px 10px;margin:6px 0;border-radius:2px;background:#fff}
			.upm-reason.freeze{border-left-color:#2563eb}
			.upm-reason.lock{border-left-color:#ef4444}
			.upm-reason .dashicons{margin-right:6px;vertical-align:-2px}
			.upm-reason small{color:#6b7280;display:block;margin-top:4px}
		</style>
		<script>
		document.addEventListener('DOMContentLoaded',function(){
			var protectedSlugs = <?php echo wp_json_encode(array_values(array_unique($slugs))); ?>;
			protectedSlugs.forEach(function(slug){
				var row = document.querySelector('tr[data-slug="'+ slug +'"]');
				if(!row) return;
				var th = row.querySelector('th.check-column');
				if(!th) return;
				th.innerHTML='';
				var lock=document.createElement('span');
				lock.className='dashicons dashicons-lock';
				lock.title='This is a protected plugin. It cannot be deactivated or deleted.';
				lock.style.fontSize='18px';lock.style.color='#666';lock.style.paddingLeft='6px';lock.style.paddingTop='2px';
				th.appendChild(lock);
			});
		});
		</script>
		<?php
	}

	public static function intercept_delete_actions() {
		if (!current_user_can('delete_plugins')) return;

		$action  = isset($_REQUEST['action'])  ? sanitize_text_field(wp_unslash($_REQUEST['action']))  : '';
		$action2 = isset($_REQUEST['action2']) ? sanitize_text_field(wp_unslash($_REQUEST['action2'])) : '';
		if ($action !== 'delete-selected' && $action2 !== 'delete-selected') return;

		if (!isset($_REQUEST['checked']) || !is_array($_REQUEST['checked'])) return;

		check_admin_referer('bulk-plugins');

		$s = self::settings();
		$protected = $s['protected'];
		$checked   = array_map('sanitize_text_field', wp_unslash($_REQUEST['checked']));
		$allowed   = array_values(array_diff($checked, $protected));

		if (count($allowed) !== count($checked)) {
			$_REQUEST['checked'] = $allowed;
			set_transient('upm_blocked_delete_notice', 1, 60);
		}
	}

	public static function render_reason_row($file, $data){
		$s = upm_get_settings(); // local notes (site level)
		if (empty($s['notes'][$file]['text'])) return;
		$note = $s['notes'][$file];
		$type = $note['type'] === 'freeze' ? 'freeze' : 'lock';
		$icon = $type === 'freeze' ? 'dashicons-backup' : 'dashicons-shield';
		$text = esc_html($note['text']);
		$who  = !empty($note['author']) ? get_userdata((int)$note['author']) : null;
		$meta = $who ? $who->display_name : __('Admin','ultimate-plugin-manager');
		$when = !empty($note['time']) ? date_i18n( get_option('date_format'), (int)$note['time'] ) : '';
		echo '<tr class="plugin-update-tr"><td colspan="3" class="plugin-update colspanchange">';
		echo '<div class="notice inline notice-info upm-reason '. esc_attr($type) .'"><span class="dashicons '. esc_attr($icon) .'"></span> '. $text;
		if ($when) echo ' <small>'. esc_html__("Set by", "ultimate-plugin-manager") .' '. esc_html($meta) .' — '. esc_html($when) .'</small>';
		echo '</div></td></tr>';
	}
}
