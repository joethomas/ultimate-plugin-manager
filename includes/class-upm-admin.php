<?php
namespace UPM;

defined('ABSPATH') || exit;

class Admin {

	public static function init() {
		add_action('admin_menu', [__CLASS__, 'add_menu']);
		add_action('admin_init', [__CLASS__, 'register_settings']);
		add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
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
			__('Protection & Auto-Updates', 'ultimate-plugin-manager'),
			function () {
				echo '<p>' . esc_html__('Choose which plugins are protected (cannot be deactivated or deleted) and lock their auto-update status ON or OFF. A plugin cannot be locked both ON and OFF.', 'ultimate-plugin-manager') . '</p>';
				echo '<p><em>' . esc_html__('Tip: Consider protecting Ultimate Plugin Manager itself so protections cannot be disabled accidentally.', 'ultimate-plugin-manager') . '</em></p>';
			},
			'upm_settings'
		);

		// Fields are rendered in the page template for a full plugin table UX.
	}

	public static function enqueue_assets($hook) {
		if ($hook !== 'settings_page_upm-settings') return;

		wp_enqueue_style('upm-admin', UPM_URL . 'assets/admin.css', array(), UPM_VERSION);
		wp_enqueue_script('upm-admin', UPM_URL . 'assets/admin.js', array('jquery'), UPM_VERSION, true);

		wp_localize_script('upm-admin', 'UPMAdmin', array(
			'i18n' => array(
				'search' => esc_html__('Search plugins…', 'ultimate-plugin-manager'),
			),
		));
	}

	public static function render_page() {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You do not have permission to access this page.', 'ultimate-plugin-manager'));
		}

		$settings = \upm_get_settings();
		$plugins  = \upm_get_all_plugins();

		// Sort by name for nicer UX
		uasort($plugins, function($a, $b){
			$na = isset($a['Name']) ? $a['Name'] : '';
			$nb = isset($b['Name']) ? $b['Name'] : '';
			return strcasecmp($na, $nb);
		});
		?>
		<div class="wrap upm-wrap">
			<h1><?php esc_html_e('Ultimate Plugin Manager', 'ultimate-plugin-manager'); ?></h1>

			<form method="post" action="options.php" id="upm-form">
				<?php
				settings_fields('upm_settings_group');
				do_settings_sections('upm_settings');
				?>

				<div class="upm-controls">
					<input type="search" id="upm-search" placeholder="<?php esc_attr_e('Search plugins…', 'ultimate-plugin-manager'); ?>">
					<div class="upm-legend">
						<span class="dashicons dashicons-lock"></span> <?php esc_html_e('Protected (no deactivate/delete)', 'ultimate-plugin-manager'); ?>
						<span class="upm-badge upm-badge-on">ON</span> <?php esc_html_e('Auto-update locked ON', 'ultimate-plugin-manager'); ?>
						<span class="upm-badge upm-badge-off">OFF</span> <?php esc_html_e('Auto-update locked OFF', 'ultimate-plugin-manager'); ?>
					</div>
				</div>

				<table class="widefat striped upm-table">
					<thead>
						<tr>
							<th style="width:40%"><?php esc_html_e('Plugin', 'ultimate-plugin-manager'); ?></th>
							<th class="col-protected"><?php esc_html_e('Protected', 'ultimate-plugin-manager'); ?></th>
							<th class="col-on"><?php esc_html_e('Auto-Update ON', 'ultimate-plugin-manager'); ?></th>
							<th class="col-off"><?php esc_html_e('Auto-Update OFF', 'ultimate-plugin-manager'); ?></th>
						</tr>
					</thead>
					<tbody id="upm-plugin-rows">
						<?php foreach ($plugins as $file => $data): ?>
							<?php
							$name   = isset($data['Name']) ? $data['Name'] : $file;
							$vers   = isset($data['Version']) ? $data['Version'] : '';
							$author = isset($data['Author']) ? $data['Author'] : '';
							$is_prot = in_array($file, $settings['protected'], true);
							$is_on   = in_array($file, $settings['locked_on'], true);
							$is_off  = in_array($file, $settings['locked_off'], true);
							?>
							<tr data-name="<?php echo esc_attr(strtolower($name)); ?>">
								<td>
									<strong><?php echo esc_html($name); ?></strong>
									<?php if ($vers): ?>
										<span class="description">v<?php echo esc_html($vers); ?></span>
									<?php endif; ?>
									<div class="description"><?php echo wp_kses_post($author); ?><br><code><?php echo esc_html($file); ?></code></div>
								</td>
								<td class="col-protected">
									<label class="upm-check">
										<input type="checkbox" name="upm_settings[protected][]" value="<?php echo esc_attr($file); ?>" <?php checked($is_prot); ?> />
										<span class="dashicons dashicons-lock" title="<?php esc_attr_e('Protected (cannot be deactivated or deleted)', 'ultimate-plugin-manager'); ?>"></span>
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
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<?php submit_button(__('Save Changes', 'ultimate-plugin-manager')); ?>
			</form>
		</div>
		<?php
	}
}
