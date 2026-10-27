<?php
/**
 * The must-use loader: a 20-line file in wp-content/mu-plugins that starts
 * the profiler before any regular plugin loads, so every plugin's load time
 * is measured. On normal page views it does nothing but one isset().
 *
 * @package Plugin_Speed_Report
 */

defined( 'ABSPATH' ) || exit;

/**
 * Installs, checks and removes the loader file.
 */
final class Plugin_Speed_Report_Loader {

	/**
	 * Line that marks the file as ours. Nothing without it is ever deleted.
	 */
	const MARKER = '@plugin-speed-report-loader';

	/**
	 * Full path of the loader file.
	 *
	 * @return string
	 */
	public static function path() {
		return WPMU_PLUGIN_DIR . '/' . Plugin_Speed_Report_Profiler::LOADER_FILE;
	}

	/**
	 * What the loader file should contain.
	 *
	 * @return string
	 */
	public static function contents() {
		$folder = basename( PLUGIN_SPEED_REPORT_DIR );

		return '<?php
/**
 * Plugin Name: Plugin Speed Report (timing loader)
 * Description: Lets Plugin Speed Report time every plugin from the start of its own test requests. Does nothing on normal page views. Added and removed by Plugin Speed Report.
 * Author:      Content Powered
 * Author URI:  https://www.contentpowered.com/wordpress-plugins/
 * Version:     ' . PLUGIN_SPEED_REPORT_VERSION . '
 *
 * ' . self::MARKER . '
 */

defined( \'ABSPATH\' ) || exit;

if ( isset( $_GET[\'psr_profile\'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The profiler checks a single-use token.
	$plugin_speed_report_profiler = WP_PLUGIN_DIR . \'/\' . ' . var_export( $folder, true ) . ' . \'/includes/class-plugin-speed-report-profiler.php\';

	if ( is_readable( $plugin_speed_report_profiler ) ) {
		require_once $plugin_speed_report_profiler;
		Plugin_Speed_Report_Profiler::maybe_start( \'mu\' );
	}

	unset( $plugin_speed_report_profiler );
}
';
	}

	/**
	 * Whether the site lets plugins write files.
	 *
	 * @return bool
	 */
	public static function can_write() {
		return ! ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS );
	}

	/**
	 * Current state: installed, outdated, missing, foreign (a file of the
	 * same name that isn't ours) or blocked (file changes turned off).
	 *
	 * @return string
	 */
	public static function status() {
		$path = self::path();

		if ( ! file_exists( $path ) ) {
			return self::can_write() ? 'missing' : 'blocked';
		}

		$current = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.

		if ( false === strpos( $current, self::MARKER ) ) {
			return 'foreign';
		}

		return self::contents() === $current ? 'installed' : 'outdated';
	}

	/**
	 * Install or refresh the loader when it's missing or out of date.
	 *
	 * @return bool True when the current loader is in place afterwards.
	 */
	public static function ensure() {
		$status = self::status();

		if ( 'installed' === $status ) {
			return true;
		}

		if ( 'missing' !== $status && 'outdated' !== $status ) {
			return false;
		}

		if ( ! self::can_write() || ! wp_mkdir_p( WPMU_PLUGIN_DIR ) || ! is_writable( WPMU_PLUGIN_DIR ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
			return false;
		}

		$written = file_put_contents( self::path(), self::contents(), LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Fixed content, our own file.

		if ( false === $written ) {
			return false;
		}

		if ( function_exists( 'opcache_invalidate' ) ) {
			opcache_invalidate( self::path(), true );
		}

		return 'installed' === self::status();
	}

	/**
	 * Remove the loader, but only if the file is ours.
	 *
	 * @return void
	 */
	public static function remove() {
		$path = self::path();

		if ( ! file_exists( $path ) ) {
			return;
		}

		$current = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.

		if ( false !== strpos( $current, self::MARKER ) ) {
			wp_delete_file( $path );
		}
	}
}
