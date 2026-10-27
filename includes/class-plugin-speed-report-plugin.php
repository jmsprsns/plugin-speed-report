<?php
/**
 * Main plugin orchestrator: owns the settings, saved scans and fixes, and
 * boots the admin side and the front-end fixes.
 *
 * @package Plugin_Speed_Report
 */

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin singleton.
 */
final class Plugin_Speed_Report_Plugin {

	/**
	 * Option key holding the plugin's settings array.
	 */
	const OPTION = 'plugin_speed_report_settings';

	/**
	 * Option listing saved scans (summaries only, newest first).
	 */
	const RUNS_OPTION = 'plugin_speed_report_runs';

	/**
	 * Prefix of the option holding one scan's full results.
	 */
	const RUN_PREFIX = 'plugin_speed_report_run_';

	/**
	 * Option holding the asset fixes. Autoloaded: the front end reads it.
	 */
	const RULES_OPTION = 'plugin_speed_report_rules';

	/**
	 * Most custom pages a scan may include.
	 */
	const MAX_CUSTOM = 10;

	/**
	 * Single shared instance.
	 *
	 * @var Plugin_Speed_Report_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Get (and lazily create) the shared instance.
	 *
	 * @return Plugin_Speed_Report_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Wire everything up.
	 */
	private function __construct() {
		if ( is_admin() ) {
			new Plugin_Speed_Report_Admin( $this );
		} else {
			Plugin_Speed_Report_Rules::init();
		}
	}

	/**
	 * Default value for every setting.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults() {
		return array(
			'samples' => 3,
			'keep'    => 10,
			'skip'    => array(),
			'custom'  => array(),
		);
	}

	/**
	 * All settings, with defaults filled in.
	 *
	 * @return array<string,mixed>
	 */
	public function get_settings() {
		$saved = get_option( self::OPTION, array() );

		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	/**
	 * A single setting.
	 *
	 * @param string $key Setting key from {@see defaults()}.
	 * @return mixed Null for an unknown key.
	 */
	public function get_setting( $key ) {
		$settings = $this->get_settings();

		return isset( $settings[ $key ] ) ? $settings[ $key ] : null;
	}

	/**
	 * Clean a page address into a path relative to the home page, such as
	 * "/contact/" or "/?s=shoes". Full URLs are accepted only for this site.
	 * Returns '' for anything else, so a scan can only ever request this site.
	 *
	 * @param mixed $value    Address typed by an admin.
	 * @param bool  $wildcard Allow a trailing * (fix rules only).
	 * @return string
	 */
	public static function sanitize_path( $value, $wildcard = false ) {
		$value = trim( is_string( $value ) ? $value : '' );

		if ( '' === $value ) {
			return '';
		}

		$home      = wp_parse_url( home_url( '/' ) );
		$home_path = isset( $home['path'] ) ? untrailingslashit( $home['path'] ) : '';

		if ( preg_match( '#^https?://#i', $value ) ) {
			$parts = wp_parse_url( $value );

			if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $home['host'] ) || strtolower( $parts['host'] ) !== strtolower( $home['host'] ) ) {
				return '';
			}

			$path = isset( $parts['path'] ) ? $parts['path'] : '/';

			if ( '' !== $home_path ) {
				if ( 0 !== strpos( $path . '/', $home_path . '/' ) ) {
					return '';
				}
				$path = substr( $path, strlen( $home_path ) );
			}

			$value = ( '' === $path ? '/' : $path ) . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );
		}

		$value = (string) preg_replace( '/#.*$/s', '', $value );

		if ( '' === $value || '/' !== $value[0] ) {
			$value = '/' . $value;
		}

		$pattern = $wildcard ? '#^/[^\s\\\\<>"\'`\x00-\x1f\x7f]*$#D' : '#^/[^\s\\\\<>"\'`*\x00-\x1f\x7f]*$#D';

		if ( strlen( $value ) > 300 || 0 === strpos( $value, '//' ) || ! preg_match( $pattern, $value ) ) {
			return '';
		}

		// A * is only allowed as the very last character.
		if ( $wildcard && false !== strpos( substr( $value, 0, -1 ), '*' ) ) {
			return '';
		}

		// No ./ or ../ segments.
		if ( preg_match( '#(?:^|/)\.{1,2}(?:/|\?|$)#', $value ) ) {
			return '';
		}

		return $value;
	}

	/**
	 * Full URL of a path from {@see sanitize_path()}.
	 *
	 * @param string $path Path relative to the home page.
	 * @return string
	 */
	public static function url_for_path( $path ) {
		return untrailingslashit( home_url() ) . $path;
	}

	/**
	 * Saved scans, newest first (summaries).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function runs() {
		$runs = get_option( self::RUNS_OPTION, array() );

		return is_array( $runs ) ? array_values( array_filter( $runs, 'is_array' ) ) : array();
	}

	/**
	 * One scan's full results.
	 *
	 * @param string $id Scan ID.
	 * @return array<string,mixed>|null
	 */
	public static function get_run( $id ) {
		if ( ! is_string( $id ) || ! preg_match( '/^[a-z0-9]{8,20}$/D', $id ) ) {
			return null;
		}

		$run = get_option( self::RUN_PREFIX . $id );

		return is_array( $run ) ? $run : null;
	}

	/**
	 * The newest scan, if any.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function latest_run() {
		$runs = self::runs();

		return $runs ? self::get_run( $runs[0]['id'] ) : null;
	}

	/**
	 * Save a finished scan and drop the oldest beyond the history limit.
	 *
	 * @param array<string,mixed> $run     Full results.
	 * @param array<string,mixed> $summary Summary for the list.
	 * @return void
	 */
	public function save_run( array $run, array $summary ) {
		update_option( self::RUN_PREFIX . $run['id'], $run, false );

		$runs = self::runs();
		array_unshift( $runs, $summary );

		$keep = max( 2, (int) $this->get_setting( 'keep' ) );

		foreach ( array_slice( $runs, $keep ) as $old ) {
			if ( isset( $old['id'] ) && is_string( $old['id'] ) ) {
				delete_option( self::RUN_PREFIX . $old['id'] );
			}
		}

		update_option( self::RUNS_OPTION, array_slice( $runs, 0, $keep ), false );
	}

	/**
	 * Delete one saved scan.
	 *
	 * @param string $id Scan ID.
	 * @return void
	 */
	public static function delete_run( $id ) {
		$runs = array();

		foreach ( self::runs() as $run ) {
			if ( isset( $run['id'] ) && $run['id'] === $id ) {
				delete_option( self::RUN_PREFIX . $id );
				continue;
			}
			$runs[] = $run;
		}

		update_option( self::RUNS_OPTION, $runs, false );
	}

	/**
	 * Asset fixes, keyed by plugin folder.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function rules() {
		$rules = get_option( self::RULES_OPTION, array() );

		return is_array( $rules ) ? $rules : array();
	}

	/**
	 * Save the asset fixes (only the front end reads them, on every page).
	 *
	 * @param array<string,array<string,mixed>> $rules Rules by folder.
	 * @return void
	 */
	public static function save_rules( array $rules ) {
		if ( $rules ) {
			update_option( self::RULES_OPTION, $rules, true );
		} else {
			delete_option( self::RULES_OPTION );
		}
	}

	/**
	 * Whether a string is a valid plugin folder name with that folder present.
	 *
	 * @param mixed $folder Folder name.
	 * @return bool
	 */
	public static function is_plugin_folder( $folder ) {
		return is_string( $folder )
			&& 1 === preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]*$/D', $folder )
			&& false === strpos( $folder, '..' )
			&& is_dir( WP_PLUGIN_DIR . '/' . $folder );
	}
}
