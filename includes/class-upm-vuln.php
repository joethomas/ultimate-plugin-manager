<?php
namespace UPM;

defined('ABSPATH') || exit;

/**
 * Vulnerability-aware locks (scaffold):
 * - Daily cron scans (provider-agnostic: WPScan/Patchstack/Wordfence)
 * - If severity >= threshold, force locked_on = true (unless Wordfence ignore or respect_freeze blocks it)
 */
class Vuln {

	const CRON_HOOK = 'upm_vuln_scan';

	public static function init() {
		add_action(self::CRON_HOOK, [__CLASS__, 'run_scan']);
	}

	public static function schedule() {
		if (!wp_next_scheduled(self::CRON_HOOK)) {
			wp_schedule_event(time() + HOUR_IN_SECONDS, 'twicedaily', self::CRON_HOOK);
		}
	}

	public static function unschedule() {
		$ts = wp_next_scheduled(self::CRON_HOOK);
		if ($ts) wp_unschedule_event($ts, self::CRON_HOOK);
	}

	public static function run_scan() {
		$opts = \upm_get_settings();
		$v = $opts['vuln'];
		$provider = $v['provider'] ?? '';
		if (!$provider) {
			// Auto-pick: no API keys? fall back to Wordfence if present
			if (defined('WORDFENCE_VERSION')) $provider = 'wordfence'; else return;
		}

		$list = \upm_get_all_plugins();
		$targets = [];
		foreach ($list as $file => $data) {
			$targets[$file] = $data['Version'] ?? '';
		}

		$results = self::scan_with_provider($provider, $v, $targets);
		if (!is_array($results)) return;

		$ignored = self::wordfence_ignored_plugins($v['respect_wf_ignore'] ?? true);
		$changed = false;

		foreach ($results as $file => $issues) {
			if (empty($issues)) continue;

			// Skip if Wordfence ignore covers this plugin
			if (!empty($ignored) && in_array($file, $ignored, true)) continue;

			$max = self::max_severity($issues);
			if (self::cmp_severity($max, ($v['severity'] ?? 'high')) >= 0) {
				// enforce locked_on unless freeze must be respected
				$s = \upm_get_settings();
				if (!in_array($file, $s['locked_on'], true)) {
					$s['locked_on'][] = $file;
					$changed = true;
				}
				if (!($v['respect_freeze'] ?? true)) {
					unset($s['freeze'][$file]);
				}
				update_option('upm_settings', $s);
			}
		}

		if ($changed) {
			add_action('admin_notices', function(){
				echo '<div class="notice notice-warning is-dismissible"><p><strong>Ultimate Plugin Manager:</strong> Vulnerabilities detected—auto-updates were enabled for some plugins.</p></div>';
			});
		}
	}

	/** Provider shim (minimal WPScan-only example; extendable) */
	protected static function scan_with_provider($provider, $vconf, $targets) {
		switch ($provider) {
			case 'wpscan':
				$key = trim((string)($vconf['api_key'] ?? ''));
				if (!$key) return [];
				// Minimal, privacy-friendly approach: WPScan has plugin slug-based queries.
				// Here we just return an empty array scaffold. Replace with real API calls when you’re ready.
				return []; // ['plugin-dir/file.php' => [ ['severity'=>'high','id'=>'…'], ... ]]
			case 'patchstack':
				return [];
			case 'wordfence':
				// If Wordfence is installed, you could read its local results here.
				return [];
			default:
				return [];
		}
	}

	/** Try to consult Wordfence "Scan Ignore" list; filterable. */
	protected static function wordfence_ignored_plugins($respect) {
		if (!$respect) return [];
		$ignored = [];

		// Best-effort: if Wordfence is present, attempt to read any ignore list it stores.
		// Because internal keys may change, we also offer a filter for exact control.
		if (defined('WORDFENCE_VERSION')) {
			// Common patterns (safe fallbacks if not present)
			$candidates = array('wordfence_scanignore', 'wf_scan_ignore', 'wfVulnIgnores');
			foreach ($candidates as $key) {
				$val = get_option($key);
				if (is_array($val)) {
					// You can transform this to plugin file list if structure is known.
					// Leave as empty by default to avoid false matches.
				}
			}
		}
		/**
		 * Filter to supply ignored plugin files according to Wordfence's UI state.
		 * Return array like ['plugin-dir/file.php', ...]
		 */
		$ignored = apply_filters('upm/wordfence_ignored_plugins', $ignored);
		return $ignored;
	}

	protected static function max_severity(array $issues) {
		$max = 'low';
		foreach ($issues as $i) {
			$sev = strtolower($i['severity'] ?? 'low');
			if (self::cmp_severity($sev, $max) > 0) $max = $sev;
		}
		return $max;
	}

	/** Compare severities */
	protected static function cmp_severity($a, $b) {
		$rank = array('low'=>1,'medium'=>2,'high'=>3,'critical'=>4);
		return ($rank[$a] ?? 0) <=> ($rank[$b] ?? 0);
	}
}
