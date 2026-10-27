<?php
/**
 * Plugin Name:       Plugin Speed Report
 * Plugin URI:        https://www.contentpowered.com
 * Description:       Find out which plugin is slowing down your site, in plain English. Measures each plugin's server time, database queries and CSS/JS weight on your key pages, suggests fixes, and compares before and after.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.2
 * Author:            Content Powered
 * Author URI:        https://www.contentpowered.com/wordpress-plugins/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       plugin-speed-report
 * Update URI:        https://github.com/jmsprsns/plugin-speed-report
 *
 * @package Plugin_Speed_Report
 */

defined( 'ABSPATH' ) || exit;

// Version and repo are read from the header above, so a release only needs
// "Version" bumped and the GitHub repo lives in one place ("Update URI").
// The Update URI header also stops WordPress.org offering a same-slug plugin
// as an "update" that would overwrite this one.
$plugin_speed_report_headers = get_file_data(
	__FILE__,
	array(
		'version' => 'Version',
		'repo'    => 'Update URI',
	)
);

define( 'PLUGIN_SPEED_REPORT_VERSION', $plugin_speed_report_headers['version'] );
define( 'PLUGIN_SPEED_REPORT_REPO', $plugin_speed_report_headers['repo'] );
define( 'PLUGIN_SPEED_REPORT_FILE', __FILE__ );
define( 'PLUGIN_SPEED_REPORT_DIR', plugin_dir_path( __FILE__ ) );
define( 'PLUGIN_SPEED_REPORT_URL', plugin_dir_url( __FILE__ ) );

unset( $plugin_speed_report_headers );

// The profiler first: on a scan's own test request it starts timing here if
// the must-use loader didn't already (plugins before this one are missed then).
require_once PLUGIN_SPEED_REPORT_DIR . 'includes/class-plugin-speed-report-profiler.php';
Plugin_Speed_Report_Profiler::maybe_start( 'plugin' );

require_once PLUGIN_SPEED_REPORT_DIR . 'includes/class-plugin-speed-report-icons.php';
require_once PLUGIN_SPEED_REPORT_DIR . 'includes/class-plugin-speed-report-updater.php';
require_once PLUGIN_SPEED_REPORT_DIR . 'includes/class-plugin-speed-report-menu.php';
require_once PLUGIN_SPEED_REPORT_DIR . 'includes/class-plugin-speed-report-feedback.php';
require_once PLUGIN_SPEED_REPORT_DIR . 'includes/class-plugin-speed-report-admin.php';
require_once PLUGIN_SPEED_REPORT_DIR . 'includes/class-plugin-speed-report-plugin.php';
require_once PLUGIN_SPEED_REPORT_DIR . 'includes/class-plugin-speed-report-loader.php';
require_once PLUGIN_SPEED_REPORT_DIR . 'includes/class-plugin-speed-report-rules.php';
require_once PLUGIN_SPEED_REPORT_DIR . 'includes/class-plugin-speed-report-report.php';
require_once PLUGIN_SPEED_REPORT_DIR . 'includes/class-plugin-speed-report-scanner.php';

// Built right away, outside any hook, so WP-Cron background updates and
// remote management tools see new releases too (not only logged-in admins).
Plugin_Speed_Report_Updater::init();

/**
 * Boot the plugin once all other plugins have loaded.
 *
 * @return Plugin_Speed_Report_Plugin
 */
function plugin_speed_report_plugin() {
	return Plugin_Speed_Report_Plugin::instance();
}
add_action( 'plugins_loaded', 'plugin_speed_report_plugin' );

/**
 * Install the must-use timing loader when the plugin is activated.
 *
 * @return void
 */
function plugin_speed_report_activate() {
	Plugin_Speed_Report_Loader::ensure();
}
register_activation_hook( PLUGIN_SPEED_REPORT_FILE, 'plugin_speed_report_activate' );

/**
 * Clean up when the plugin is deactivated: remove the timing loader and stop
 * any scan in progress. Settings, saved scans and fixes stay (uninstall.php
 * removes them); fixes do nothing while the plugin is inactive.
 *
 * @return void
 */
function plugin_speed_report_deactivate() {
	Plugin_Speed_Report_Loader::remove();
	Plugin_Speed_Report_Scanner::cancel();
}
register_deactivation_hook( PLUGIN_SPEED_REPORT_FILE, 'plugin_speed_report_deactivate' );
