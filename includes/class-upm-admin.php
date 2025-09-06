<?php
namespace UPM;

defined('ABSPATH') || exit;

class Admin {

	public static function init() {
		add_action('admin_menu', [__CLASS__, 'add_menu']);
		add_action('admin_init', [__CLASS__, 'register_settings']);
		add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
		add_action('wp_ajax_upm_rollback', [__CLASS__, 'ajax_rollback']);
		add_action('wp_ajax_upm_install_favorite', [__CLASS__, 'ajax_install_favorite']);
		add_action('wp_ajax_upm_test_github', [__CLASS__, 'ajax_test_github']);
		add_action('admin_post_upm_sync_favs', [__CLASS__, 'sync_from_file']);
		add_action('admin_post_upm_export_favs', [__CLASS__, 'export_favs']);
	}

	public static function add_menu() {
		add_options_page(
			__('Ultimate Plugin Manager', 'ultimate-plugin-manager'),
			__('Ultimate Plugin Manager', 'ultimate-plugin-manager'),
			'manage_options',
			'upm-settings',
			[__CLASS__, 'render_page']
		);
	}

	public static function register_settings() {
		register_setting('upm_settings_group', 'upm_settings', [
			'type' => 'array',
			'sanitize_callback' => '\upm_sanitize_settings',
			'default' => \upm_default_settings(),
		]);

		add_settings_section(
			'upm_section_main',
			__('Protection, Updates, Freeze & Notes', 'ultimate-plugin-manager'),
			function () {
				echo '<p>' . esc_html__('Choose protected plugins, lock auto-updates, freeze at a version, rollback, and add notes—all here.', 'ultimate-plugin-manager') . '</p>';
			},
			'upm_settings'
		);

		add_settings_section(
			'upm_section_favorites',
			__('Favorites', 'ultimate-plugin-manager'),
			function () {
				echo '<p>' . esc_html__('Quickly install/activate your favorite plugins.', 'ultimate-plugin-manager') . '</p>';
			},
			'upm_favorites'
		);
	}

	public static function enqueue_assets($hook) {
		if ($hook !== 'settings_page_upm-settings') return;
		wp_enqueue_style('upm-admin', UPM_URL . 'assets/admin.css', array(), UPM_VERSION);
		wp_enqueue_script('upm-admin', UPM_URL . 'assets/admin.js', array('jquery'), UPM_VERSION, true);
		wp_localize_script('upm-admin', 'UPMAdmin', array(
			'ajax' => admin_url('admin-ajax.php'),
			'nonce' => wp_create_nonce('upm_nonce'),
		));
	}

	public static function render_page() {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You do not have permission to access this page.', 'ultimate-plugin-manager'));
		}
		$settings  = \upm_get_settings();
		$plugins   = \upm_get_all_plugins();
		$has_token = !empty($settings['github']['token']);

		uasort($plugins, function($a, $b){
			$na = isset($a['Name']) ? $a['Name'] : '';
			$nb = isset($b['Name']) ? $b['Name'] : '';
			return strcasecmp($na, $nb);
		});
		?>
		<div class="wrap upm-wrap">
			<h1><?php esc_html_e('Ultimate Plugin Manager', 'ultimate-plugin-manager'); ?></h1>

			<form method="post" action="options.php" id="upm-form">
				<?php settings_fields('upm_settings_group'); ?>

				<h2 class="nav-tab-wrapper">
					<a href="#" class="nav-tab nav-tab-active" data-upm-tab="main"><?php esc_html_e('Settings', 'ultimate-plugin-manager'); ?></a>
					<a href="#" class="nav-tab" data-upm-tab="favorites"><?php esc_html_e('Favorites', 'ultimate-plugin-manager'); ?></a>
				</h2>

				<div class="upm-tab upm-tab-main">
					<?php do_settings_sections('upm_settings'); ?>

					<div class="upm-controls">
						<input type="search" id="upm-search" placeholder="<?php esc_attr_e('Search plugins…', 'ultimate-plugin-manager'); ?>">
						<div class="upm-legend">
							<span class="dashicons dashicons-lock"></span> <?php esc_html_e('Protected', 'ultimate-plugin-manager'); ?>
							<span class="upm-badge upm-badge-on">ON</span> <?php esc_html_e('Auto-update locked ON', 'ultimate-plugin-manager'); ?>
							<span class="upm-badge upm-badge-off">OFF</span> <?php esc_html_e('Auto-update locked OFF', 'ultimate-plugin-manager'); ?>
							<span class="dashicons dashicons-backup"></span> <?php esc_html_e('Freeze at version', 'ultimate-plugin-manager'); ?>
						</div>
					</div>

					<table class="widefat striped upm-table">
						<thead>
							<tr>
								<th style="width:34%"><?php esc_html_e('Plugin', 'ultimate-plugin-manager'); ?></th>
								<th class="col-protected"><?php esc_html_e('Protected', 'ultimate-plugin-manager'); ?></th>
								<th class="col-on"><?php esc_html_e('Auto-Update ON', 'ultimate-plugin-manager'); ?></th>
								<th class="col-off"><?php esc_html_e('Auto-Update OFF', 'ultimate-plugin-manager'); ?></th>
								<th class="col-freeze"><?php esc_html_e('Freeze @', 'ultimate-plugin-manager'); ?></th>
								<th class="col-rollback"><?php esc_html_e('Rollback', 'ultimate-plugin-manager'); ?></th>
								<th class="col-notes"><?php esc_html_e('Note', 'ultimate-plugin-manager'); ?></th>
							</tr>
						</thead>
						<tbody id="upm-plugin-rows">
							<?php foreach ($plugins as $file => $data): 
								$name   = isset($data['Name']) ? $data['Name'] : $file;
								$vers   = isset($data['Version']) ? $data['Version'] : '';
								$author = isset($data['Author']) ? $data['Author'] : '';
								$is_prot = in_array($file, $settings['protected'], true);
								$is_on   = in_array($file, $settings['locked_on'], true);
								$is_off  = in_array($file, $settings['locked_off'], true);
								$frozen  = isset($settings['freeze'][$file]) ? $settings['freeze'][$file] : '';
								$note    = isset($settings['notes'][$file]) ? $settings['notes'][$file] : array('text'=>'','type'=>'lock');
							?>
							<tr data-name="<?php echo esc_attr(strtolower($name)); ?>">
								<td>
									<strong><?php echo esc_html($name); ?></strong>
									<?php if ($vers): ?><span class="description">v<?php echo esc_html($vers); ?></span><?php endif; ?>
									<div class="description"><?php echo wp_kses_post($author); ?><br><code><?php echo esc_html($file); ?></code></div>
								</td>
								<td class="col-protected">
									<label class="upm-check">
										<input type="checkbox" name="upm_settings[protected][]" value="<?php echo esc_attr($file); ?>" <?php checked($is_prot); ?> />
										<span class="dashicons dashicons-lock" title="<?php esc_attr_e('Protected', 'ultimate-plugin-manager'); ?>"></span>
									</label>
								</td>
								<td class="col-on">
									<label class="upm-check">
										<input type="checkbox" class="upm-on" name="upm_settings[locked_on][]" value="<?php echo esc_attr($file); ?>" <?php checked($is_on); ?> />
										<span class="upm-badge upm-badge-on">ON</span>
									</label>
								</td>
								<td class="col-off">
									<label class="upm-check">
										<input type="checkbox" class="upm-off" name="upm_settings[locked_off][]" value="<?php echo esc_attr($file); ?>" <?php checked($is_off); ?> />
										<span class="upm-badge upm-badge-off">OFF</span>
									</label>
								</td>
								<td class="col-freeze">
									<input type="text" class="regular-text code upm-freeze" name="upm_settings[freeze][<?php echo esc_attr($file); ?>]" value="<?php echo esc_attr($frozen); ?>" placeholder="<?php esc_attr_e('e.g., 1.2.3', 'ultimate-plugin-manager'); ?>" />
									<?php if ($vers): ?>
										<small class="description"><?php printf(esc_html__('Current: %s', 'ultimate-plugin-manager'), esc_html($vers)); ?></small>
									<?php endif; ?>
								</td>
								<td class="col-rollback">
									<button type="button" class="button upm-rollback" data-file="<?php echo esc_attr($file); ?>"><?php esc_html_e('Rollback…', 'ultimate-plugin-manager'); ?></button>
								</td>
								<td class="col-notes">
									<select name="upm_settings[notes][<?php echo esc_attr($file); ?>][type]">
										<option value="lock" <?php selected(($note['type']??'')==='lock'); ?>><?php esc_html_e('Auto-Update Lock', 'ultimate-plugin-manager'); ?></option>
										<option value="freeze" <?php selected(($note['type']??'')==='freeze'); ?>><?php esc_html_e('Version Freeze', 'ultimate-plugin-manager'); ?></option>
									</select>
									<input type="text" class="regular-text" name="upm_settings[notes][<?php echo esc_attr($file); ?>][text]" value="<?php echo esc_attr($note['text'] ?? ''); ?>" placeholder="<?php esc_attr_e('Optional reason…', 'ultimate-plugin-manager'); ?>" />
								</td>
							</tr>
							<?php endforeach; ?>
						</tbody>
					</table>

					<?php submit_button(__('Save Changes', 'ultimate-plugin-manager')); ?>
				</div>

				<div class="upm-tab upm-tab-favorites" style="display:none">
					<?php do_settings_sections('upm_favorites'); ?>
					<fieldset class="upm-gh-token" style="margin:12px 0 16px;padding:12px;border:1px solid #e5e7eb;border-radius:6px;background:#fff;">
						<legend style="font-weight:600"><?php esc_html_e('GitHub (for auto-latest on GitHub favorites)', 'ultimate-plugin-manager'); ?></legend>

						<label for="upm_gh_token" style="display:block;margin-bottom:6px;"><?php esc_html_e('GitHub.com Access Token', 'ultimate-plugin-manager'); ?></label>
						<input type="password"	id="upm_gh_token" name="upm_settings[github][token]" value="" placeholder="<?php echo $has_token ? esc_attr(str_repeat('•', 28)) : ''; ?>" autocomplete="new-password" class="regular-text" style="max-width:640px" />
						<input type="hidden" name="upm_settings[github][_keep]" value="1" />
						<p class="description" style="margin-top:6px;">
							<?php esc_html_e('Leave blank to keep the currently stored token. Enter a new value to replace it.', 'ultimate-plugin-manager'); ?>
						</p>

						<p style="margin-top:10px;">
							<button type="button" class="button" id="upm-gh-test"><?php esc_html_e('Test GitHub API', 'ultimate-plugin-manager'); ?></button>
							<span id="upm-gh-test-status" class="upm-gh-status" aria-live="polite" style="margin-left:8px;"></span>
						</p>
					</fieldset>
					<p><?php esc_html_e('Add your favorites via code or filter (see instructions below). Use the buttons to install/activate quickly.', 'ultimate-plugin-manager'); ?></p>
					<div id="upm-fav-list" class="upm-fav-grid">
						<?php
						$favs = \upm_get_favorites();
						if (!empty($favs)) :
							/* OLD - Before `external` type */
							/* foreach ($favs as $fav) :
								$type = esc_html($fav['type'] ?? '');
								$title = esc_html($fav['title'] ?? ($fav['slug'] ?? basename($fav['url'] ?? $fav['zip'] ?? '')));
								$data = esc_attr(wp_json_encode($fav));
								echo '<div class="upm-fav-card"><h3>'. $title .'</h3><code>'. $type .'</code><div class="actions"><button class="button upm-install-fav" data-fav="'. $data .'">'. esc_html__('Install / Activate','ultimate-plugin-manager') .'</button></div></div>';
							endforeach;*/
							foreach ($favs as $fav) :
								$tNorm = \upm_normalize_fav_type($fav['type'] ?? '');
								$title = esc_html($fav['title'] ?? ($fav['slug'] ?? basename($fav['url'] ?? $fav['zip'] ?? '')));
								echo '<div class="upm-fav-card"><h3>'. $title .'</h3><code>'. esc_html($tNorm) .'</code><div class="actions">';
								if ($tNorm === 'external') {
									$link = \upm_fav_external_url($fav);
									$label = esc_html__('Visit Plugin Site', 'ultimate-plugin-manager');
									echo $link ? '<a class="button button-secondary" href="'. esc_url($link) .'" target="_blank" rel="noopener">'. $label .'</a>' : '<span class="description">'. esc_html__('No URL provided', 'ultimate-plugin-manager') .'</span>';
								} else {
									$data = esc_attr(wp_json_encode($fav));
									echo '<button class="button upm-install-fav" data-fav="'. $data .'">'. esc_html__('Install / Activate','ultimate-plugin-manager') .'</button>';
								}
								echo '</div></div>';
							endforeach;
						else:
							echo '<p>'. esc_html__('No favorites defined yet. See instructions below.', 'ultimate-plugin-manager') .'</p>';
						endif; ?>
					</div>
					<p class="upm-fav-actions">
						<a href="<?php echo esc_url( wp_nonce_url( admin_url('admin-post.php?action=upm_sync_favs'), 'upm_nonce') ); ?>" class="button"><?php esc_html_e('Sync from file', 'ultimate-plugin-manager'); ?></a>
						<a href="<?php echo esc_url( wp_nonce_url( admin_url('admin-post.php?action=upm_export_favs'), 'upm_nonce') ); ?>" class="button"><?php esc_html_e('Export JSON', 'ultimate-plugin-manager'); ?></a>
					</p>
				</div>

			</form>

			<h2><?php esc_html_e('How to add Favorites', 'ultimate-plugin-manager'); ?></h2>
			<ol>
				<li><?php esc_html_e('Programmatically (recommended): use the filter below in a small mu-plugin or your theme.', 'ultimate-plugin-manager'); ?></li>
				<li><?php esc_html_e('Each favorite is either a wp.org slug, a direct ZIP, or a GitHub release ZIP.', 'ultimate-plugin-manager'); ?></li>
			</ol>
			<pre><code><?php echo esc_html("add_filter('upm/favorites', function(\$list){\n\t\$list[] = ['type'=>'wporg','slug'=>'query-monitor','title'=>'Query Monitor'];\n\t\$list[] = ['type'=>'zip','url'=>'https://example.com/custom-plugin.zip','title'=>'My ZIP Plugin'];\n\t\$list[] = ['type'=>'github','zip'=>'https://github.com/org/repo/archive/refs/tags/v1.2.3.zip','title'=>'Repo v1.2.3'];\n\treturn \$list;\n});"); ?></code></pre>
		</div>
		<?php
	}

	public static function ajax_rollback() {
		check_ajax_referer('upm_nonce','_wpnonce');
		if (!current_user_can('update_plugins')) wp_send_json_error('forbidden');

		$file = isset($_POST['file']) ? sanitize_text_field(wp_unslash($_POST['file'])) : '';
		$version = isset($_POST['version']) ? sanitize_text_field(wp_unslash($_POST['version'])) : '';

		if (!$file || !$version) wp_send_json_error('bad_request');

		$r = \UPM\Rollback::rollback_to_version($file, $version);
		is_wp_error($r) ? wp_send_json_error($r->get_error_message()) : wp_send_json_success('ok');
	}

	public static function ajax_install_favorite() {
		check_ajax_referer('upm_nonce','_wpnonce');
		if (!current_user_can('install_plugins')) wp_send_json_error('forbidden');

		$fav = isset($_POST['fav']) ? json_decode(stripslashes((string)$_POST['fav']), true) : null;
		if (!$fav || !is_array($fav)) wp_send_json_error('bad_request');

		$r = \UPM\Rollback::install_favorite($fav); // reuse upgrader helpers
		is_wp_error($r) ? wp_send_json_error($r->get_error_message()) : wp_send_json_success('ok');
	}

	public static function ajax_test_github() {
		check_ajax_referer('upm_nonce','_wpnonce');
		if (!current_user_can('manage_options')) wp_send_json_error('forbidden');
	
		$posted_token = isset($_POST['token']) ? sanitize_text_field(wp_unslash($_POST['token'])) : '';
		$settings     = \upm_get_settings();
		$token        = $posted_token !== '' ? $posted_token : ($settings['github']['token'] ?? '');
	
		if ($token === '') {
			wp_send_json_error(['message' => 'No token provided or stored.']);
		}
	
		$headers = [
			'Accept'        => 'application/vnd.github+json',
			'User-Agent'    => 'UPM/1.0',
			'Authorization' => 'Bearer ' . $token,
		];
	
		$r = wp_remote_get('https://api.github.com/rate_limit', ['timeout' => 10, 'headers' => $headers]);
		if (is_wp_error($r)) {
			wp_send_json_error(['message' => $r->get_error_message()]);
		}
		$code = wp_remote_retrieve_response_code($r);
		$body = json_decode(wp_remote_retrieve_body($r), true);
	
		if ($code !== 200 || !is_array($body)) {
			$msg = !empty($body['message']) ? $body['message'] : 'GitHub API error.';
			wp_send_json_error(['message' => $msg, 'code' => $code]);
		}
	
		$core = $body['resources']['core'] ?? [];
		$limit = (int)($core['limit'] ?? 0);
		$remain = (int)($core['remaining'] ?? 0);
		$reset  = (int)($core['reset'] ?? 0);
	
		wp_send_json_success([
			'limit'    => $limit,
			'remaining'=> $remain,
			'reset'    => $reset, // epoch seconds
		]);
	}

	public static function sync_from_file() {
		check_admin_referer('upm_nonce');
		if (!current_user_can('manage_options')) wp_die('forbidden');
		$settings = \upm_get_settings();
		$settings['favorites'] = array(); // reset DB-added list; file remains the source of truth
		update_option('upm_settings', $settings);
		wp_safe_redirect( admin_url('options-general.php?page=upm-settings') );
		exit;
	}
	
	public static function export_favs() {
		check_admin_referer('upm_nonce');
		if (!current_user_can('manage_options')) wp_die('forbidden');
		$favs = \upm_get_favorites();
		nocache_headers();
		header('Content-Type: application/json; charset=utf-8');
		header('Content-Disposition: attachment; filename="favorites.json"');
		echo wp_json_encode($favs, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
		exit;
	}
}
