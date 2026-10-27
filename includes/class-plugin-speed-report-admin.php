<?php
/**
 * Admin side: the Content Powered > Plugin Speed Report page (Report,
 * Compare, Pages and Fixes tabs), the scan AJAX endpoint and the form
 * handlers.
 *
 * @package Plugin_Speed_Report
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings page controller.
 */
final class Plugin_Speed_Report_Admin {

	/**
	 * Settings page slug, also used as the settings group.
	 */
	const PAGE = 'plugin-speed-report';

	/**
	 * Lucide icon shown beside the menu item and page title.
	 */
	const ICON = 'gauge';

	/**
	 * Tabs and their icons.
	 */
	const TABS = array(
		'report'  => 'chart-no-axes-column',
		'compare' => 'git-compare-arrows',
		'pages'   => 'files',
		'fixes'   => 'wrench',
	);

	/**
	 * Chart colors for the five biggest plugins (validated categorical set).
	 */
	const SERIES = array( '#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4' );

	/**
	 * Plugin instance.
	 *
	 * @var Plugin_Speed_Report_Plugin
	 */
	private $plugin;

	/**
	 * Hook suffix of the settings page.
	 *
	 * @var string
	 */
	private $hook_suffix = '';

	/**
	 * Constructor.
	 *
	 * @param Plugin_Speed_Report_Plugin $plugin Plugin instance.
	 */
	public function __construct( Plugin_Speed_Report_Plugin $plugin ) {
		$this->plugin = $plugin;

		Plugin_Speed_Report_Menu::init();
		Plugin_Speed_Report_Feedback::init();

		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_head', array( $this, 'print_menu_icon_style' ) );
		add_action( 'wp_ajax_plugin_speed_report_scan', array( $this, 'ajax_scan' ) );
		add_action( 'admin_post_plugin_speed_report_fix', array( $this, 'handle_fix' ) );
		add_action( 'admin_post_plugin_speed_report_delete_run', array( $this, 'handle_delete_run' ) );

		add_filter(
			'plugin_action_links_' . plugin_basename( PLUGIN_SPEED_REPORT_FILE ),
			array( $this, 'add_settings_link' )
		);
	}

	/**
	 * URL of a tab.
	 *
	 * @param string $tab  Tab key.
	 * @param array  $args Extra query arguments.
	 * @return string
	 */
	public static function tab_url( $tab = 'report', array $args = array() ) {
		return add_query_arg(
			array_merge(
				array(
					'page' => self::PAGE,
					'tab'  => $tab,
				),
				$args
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Inline icon.
	 *
	 * @param string $name  Icon name.
	 * @param int    $size  Size in px.
	 * @param string $class Extra class.
	 * @return string
	 */
	private static function icon( $name, $size = 16, $class = '' ) {
		return Plugin_Speed_Report_Icons::get( $name, $size, $class );
	}

	/**
	 * Add a "Settings" link to the plugin's row on the Plugins screen.
	 *
	 * @param array $links Existing action links (Deactivate, etc.).
	 * @return array
	 */
	public function add_settings_link( $links ) {
		if ( ! is_array( $links ) ) {
			return $links;
		}

		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( self::tab_url() ),
			esc_html__( 'Speed report', 'plugin-speed-report' )
		);

		array_unshift( $links, $settings_link );

		return $links;
	}

	/**
	 * Add the page under the shared Content Powered menu.
	 *
	 * @return void
	 */
	public function register_menu() {
		$icon       = Plugin_Speed_Report_Icons::get( self::ICON, 17, 'plugin-speed-report-menu-icon' );
		$menu_title = $icon . '<span class="plugin-speed-report-menu-label">' . esc_html__( 'Plugin Speed Report', 'plugin-speed-report' ) . '</span>';

		$this->hook_suffix = add_submenu_page(
			Plugin_Speed_Report_Menu::SLUG,
			__( 'Plugin Speed Report', 'plugin-speed-report' ),
			$menu_title,
			'manage_options',
			self::PAGE,
			array( $this, 'render_page' )
		);

		if ( $this->hook_suffix ) {
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
			add_action( 'load-' . $this->hook_suffix, array( $this, 'on_load' ) );
		}
	}

	/**
	 * When the page loads: keep the timing loader installed and mark the body.
	 *
	 * @return void
	 */
	public function on_load() {
		Plugin_Speed_Report_Loader::ensure();

		add_filter(
			'admin_body_class',
			function ( $classes ) {
				return $classes . ' psr-page';
			}
		);
	}

	/**
	 * Register the settings saved from the Pages tab.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			self::PAGE,
			Plugin_Speed_Report_Plugin::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => Plugin_Speed_Report_Plugin::defaults(),
			)
		);
	}

	/**
	 * Clean the submitted settings before they are saved.
	 *
	 * @param mixed $input Raw form values.
	 * @return array<string,mixed>
	 */
	public function sanitize( $input ) {
		$input   = is_array( $input ) ? $input : array();
		$current = $this->plugin->get_settings();
		$keys    = '/^[a-z0-9_:\-]{1,80}$/D';

		$all     = isset( $input['all'] ) && is_array( $input['all'] ) ? $input['all'] : array();
		$include = isset( $input['include'] ) && is_array( $input['include'] ) ? $input['include'] : array();
		$skip    = array();

		foreach ( $all as $key ) {
			if ( is_string( $key ) && preg_match( $keys, $key ) && ! in_array( $key, $include, true ) ) {
				$skip[] = $key;
			}
		}

		$custom = array();
		$lines  = isset( $input['custom'] ) && is_string( $input['custom'] ) ? preg_split( '/\r\n|\r|\n/', $input['custom'] ) : array();

		foreach ( $lines as $line ) {
			$path = Plugin_Speed_Report_Plugin::sanitize_path( $line );

			if ( '' !== $path && ! in_array( $path, $custom, true ) ) {
				$custom[] = $path;
			} elseif ( '' !== trim( $line ) && '' === $path ) {
				add_settings_error(
					self::PAGE,
					'psr-bad-path',
					/* translators: %s: what was typed. */
					sprintf( __( 'Skipped "%s": enter a page address on this site, like /contact/.', 'plugin-speed-report' ), sanitize_text_field( $line ) )
				);
			}
		}

		$samples = isset( $input['samples'] ) ? (int) $input['samples'] : (int) $current['samples'];
		$keep    = isset( $input['keep'] ) ? (int) $input['keep'] : (int) $current['keep'];

		return array(
			'samples' => min( 5, max( 1, $samples ) ),
			'keep'    => in_array( $keep, array( 5, 10, 20, 30 ), true ) ? $keep : 10,
			'skip'    => array_values( array_unique( $skip ) ),
			'custom'  => array_slice( $custom, 0, Plugin_Speed_Report_Plugin::MAX_CUSTOM ),
		);
	}

	/**
	 * Tiny inline style so the menu icon sits neatly beside its label, even
	 * when a long plugin name wraps onto a second line.
	 *
	 * @return void
	 */
	public function print_menu_icon_style() {
		echo '<style id="plugin-speed-report-menu-icon-style">'
			. '#adminmenu a:has(>.plugin-speed-report-menu-icon){display:flex;align-items:flex-start;gap:7px}'
			. '#adminmenu .plugin-speed-report-menu-icon{flex:none;margin-top:1px;opacity:.7}'
			. '#adminmenu li.current .plugin-speed-report-menu-icon,#adminmenu a:hover .plugin-speed-report-menu-icon,#adminmenu a:focus .plugin-speed-report-menu-icon{opacity:1}'
			. '</style>';
	}

	/**
	 * Load the settings-page styles and scripts.
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( $hook !== $this->hook_suffix ) {
			return;
		}

		wp_enqueue_style( 'plugin-speed-report-admin', PLUGIN_SPEED_REPORT_URL . 'assets/admin.css', array(), PLUGIN_SPEED_REPORT_VERSION );
		wp_enqueue_script( 'plugin-speed-report-admin', PLUGIN_SPEED_REPORT_URL . 'assets/admin.js', array(), PLUGIN_SPEED_REPORT_VERSION, true );

		wp_localize_script(
			'plugin-speed-report-admin',
			'plugin_speed_report_admin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'plugin_speed_report_scan' ),
				'running' => Plugin_Speed_Report_Scanner::is_running(),
				'i18n'    => array(
					'starting'  => __( 'Starting…', 'plugin-speed-report' ),
					'cancelled' => __( 'Scan cancelled.', 'plugin-speed-report' ),
					'failed'    => __( 'The scan stopped: ', 'plugin-speed-report' ),
					'network'   => __( 'Couldn\'t reach your site. Check your connection and try again.', 'plugin-speed-report' ),
					/* translators: 1: steps done, 2: total steps. */
					'progress'  => __( '%1$d of %2$d page loads', 'plugin-speed-report' ),
					'confirm'   => __( 'Delete this scan from the history?', 'plugin-speed-report' ),
				),
			)
		);
	}

	/**
	 * The current tab.
	 *
	 * @return string
	 */
	private function current_tab() {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'report'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Navigation only.

		return isset( self::TABS[ $tab ] ) ? $tab : 'report';
	}

	/**
	 * Tab label.
	 *
	 * @param string $tab Tab key.
	 * @return string
	 */
	private function tab_label( $tab ) {
		switch ( $tab ) {
			case 'compare':
				return __( 'Before & after', 'plugin-speed-report' );
			case 'pages':
				return __( 'Pages', 'plugin-speed-report' );
			case 'fixes':
				$count = count( Plugin_Speed_Report_Plugin::rules() );
				/* translators: %d: number of fixes on. */
				return $count ? sprintf( __( 'Fixes (%d)', 'plugin-speed-report' ), $count ) : __( 'Fixes', 'plugin-speed-report' );
		}

		return __( 'Report', 'plugin-speed-report' );
	}

	/**
	 * Render the page.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$tab = $this->current_tab();
		?>
		<div class="psr-header">
			<div class="psr-header-title">
				<span class="psr-header-icon"><?php echo self::icon( self::ICON, 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
				<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
				<span class="psr-version"><?php echo esc_html( 'v' . PLUGIN_SPEED_REPORT_VERSION ); ?></span>
			</div>
			<nav class="psr-tabs" aria-label="<?php esc_attr_e( 'Plugin Speed Report sections', 'plugin-speed-report' ); ?>">
				<?php foreach ( self::TABS as $id => $icon ) : ?>
					<a href="<?php echo esc_url( self::tab_url( $id ) ); ?>" class="psr-tab<?php echo $id === $tab ? ' is-active' : ''; ?>"<?php echo $id === $tab ? ' aria-current="page"' : ''; ?>>
						<?php echo self::icon( $icon, 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
						<?php echo esc_html( $this->tab_label( $id ) ); ?>
					</a>
				<?php endforeach; ?>
			</nav>
		</div>

		<div class="wrap psr-wrap">
			<hr class="wp-header-end">

			<?php
			// Pages outside the Settings menu have to show "Settings saved." themselves.
			settings_errors( self::PAGE );
			$this->render_notice();
			?>

			<div id="poststuff">
				<div id="post-body" class="metabox-holder columns-2">
					<div id="post-body-content">
						<?php
						switch ( $tab ) {
							case 'compare':
								$this->render_compare();
								break;
							case 'pages':
								$this->render_pages();
								break;
							case 'fixes':
								$this->render_fixes();
								break;
							default:
								$this->render_report();
						}
						?>
					</div>

					<div id="postbox-container-1" class="postbox-container">
						<div class="psr-sidebar">
							<?php $this->render_sidebar(); ?>
						</div>
					</div>
				</div>
				<br class="clear">
			</div>
		</div>
		<?php
	}

	/**
	 * One box.
	 *
	 * @param string   $icon   Icon name.
	 * @param string   $title  Title.
	 * @param callable $body   Prints the content.
	 * @param string   $class  Extra class.
	 * @param string   $aside  Extra HTML for the header's right side (already escaped).
	 * @return void
	 */
	private function box( $icon, $title, $body, $class = '', $aside = '' ) {
		?>
		<div class="postbox psr-box <?php echo esc_attr( $class ); ?>">
			<div class="postbox-header">
				<h2 class="hndle">
					<?php echo self::icon( $icon ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
					<?php echo esc_html( $title ); ?>
				</h2>
				<?php echo $aside; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts by the caller. ?>
			</div>
			<div class="inside">
				<?php call_user_func( $body ); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Messages after a form was handled. Only fixed messages, chosen by code.
	 *
	 * @return void
	 */
	private function render_notice() {
		$code     = isset( $_GET['psr_notice'] ) ? sanitize_key( wp_unslash( $_GET['psr_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Picks a fixed message.
		$messages = array(
			'fix_on'      => array( 'success', __( 'Fix turned on. Run a new scan to see the difference, and check a page that uses the plugin.', 'plugin-speed-report' ) ),
			'fix_off'     => array( 'success', __( 'Fix turned off. The plugin\'s files load everywhere again.', 'plugin-speed-report' ) ),
			'fix_saved'   => array( 'success', __( 'Fix updated.', 'plugin-speed-report' ) ),
			'fix_missing' => array( 'error', __( 'That fix needs a recent scan of the plugin. Run a scan and try again.', 'plugin-speed-report' ) ),
			'run_deleted' => array( 'success', __( 'Scan deleted.', 'plugin-speed-report' ) ),
		);

		$scanned = isset( $_GET['scanned'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.

		if ( $scanned ) {
			$runs = Plugin_Speed_Report_Plugin::runs();
			$text = count( $runs ) > 1
				? sprintf(
					/* translators: %s: link to the comparison. */
					esc_html__( 'Scan complete. %s', 'plugin-speed-report' ),
					'<a href="' . esc_url( self::tab_url( 'compare' ) ) . '">' . esc_html__( 'Compare it with the previous scan', 'plugin-speed-report' ) . '</a>'
				)
				: esc_html__( 'Scan complete.', 'plugin-speed-report' );

			echo '<div class="notice notice-success is-dismissible"><p>' . $text . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
		}

		if ( isset( $messages[ $code ] ) ) {
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $messages[ $code ][0] ), esc_html( $messages[ $code ][1] ) );
		}
	}

	// Sidebar.

	/**
	 * Sidebar boxes.
	 *
	 * @return void
	 */
	private function render_sidebar() {
		$settings = $this->plugin->get_settings();
		$pages    = Plugin_Speed_Report_Scanner::pages_to_scan( $settings );
		$loads    = count( $pages ) * ( (int) $settings['samples'] + 1 );
		$latest   = Plugin_Speed_Report_Plugin::runs();
		$latest   = $latest ? $latest[0] : null;

		$this->box(
			'play',
			__( 'Scan', 'plugin-speed-report' ),
			function () use ( $pages, $loads, $settings, $latest ) {
				?>
				<p class="psr-muted">
					<?php
					printf(
						/* translators: 1: number of pages, 2: runs per page, 3: how long it takes, e.g. "about 2 minutes". */
						esc_html__( 'Loads %1$d pages %2$d times each as a logged-out visitor, in the background. Takes %3$s.', 'plugin-speed-report' ),
						count( $pages ),
						(int) $settings['samples'],
						esc_html( self::duration( $loads ) )
					);
					?>
				</p>
				<div class="psr-scan" data-psr-scan>
					<button type="button" class="button button-primary button-hero psr-scan-start" data-psr-start <?php disabled( ! $pages ); ?>>
						<?php echo self::icon( 'play', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
						<?php echo $latest ? esc_html__( 'Run a new scan', 'plugin-speed-report' ) : esc_html__( 'Run your first scan', 'plugin-speed-report' ); ?>
					</button>
					<div class="psr-progress" data-psr-progress hidden>
						<div class="psr-progress-top">
							<span class="psr-spin"><?php echo self::icon( 'loader-circle', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
							<strong data-psr-count><?php esc_html_e( 'Starting…', 'plugin-speed-report' ); ?></strong>
						</div>
						<div class="psr-progress-track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" aria-label="<?php esc_attr_e( 'Scan progress', 'plugin-speed-report' ); ?>"><span data-psr-bar></span></div>
						<p class="psr-progress-line" data-psr-line aria-live="polite"></p>
						<p class="psr-progress-note"><?php esc_html_e( 'Keep this tab open until it finishes.', 'plugin-speed-report' ); ?></p>
						<button type="button" class="button-link psr-cancel" data-psr-cancel><?php esc_html_e( 'Cancel scan', 'plugin-speed-report' ); ?></button>
					</div>
					<p class="psr-scan-error" data-psr-error role="alert" hidden></p>
				</div>
				<?php if ( $latest ) : ?>
					<p class="psr-last">
						<?php echo self::icon( 'history', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
						<?php
						/* translators: %s: how long ago, e.g. "5 mins". */
						printf( esc_html__( 'Last scan %s ago', 'plugin-speed-report' ), esc_html( human_time_diff( (int) $latest['created'] ) ) );
						?>
					</p>
				<?php endif; ?>
				<?php
			},
			'psr-scan-box'
		);

		$this->box(
			'list-checks',
			__( 'How it measures', 'plugin-speed-report' ),
			function () {
				$loader = Plugin_Speed_Report_Loader::status();
				$cache  = wp_using_ext_object_cache();
				?>
				<ul class="psr-checklist">
					<li class="is-ok">
						<?php echo self::icon( 'timer', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
						<span><?php esc_html_e( 'Server time: every plugin\'s own code, timed separately, including the time to load it.', 'plugin-speed-report' ); ?></span>
					</li>
					<li class="is-ok">
						<?php echo self::icon( 'database', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
						<span><?php esc_html_e( 'Database queries, counted against the plugin that ran them.', 'plugin-speed-report' ); ?></span>
					</li>
					<li class="is-ok">
						<?php echo self::icon( 'file-code', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
						<span><?php esc_html_e( 'CSS and JavaScript on each page, weighed file by file.', 'plugin-speed-report' ); ?></span>
					</li>
					<?php if ( 'installed' === $loader ) : ?>
						<li class="is-ok">
							<?php echo self::icon( 'circle-check', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
							<span><?php esc_html_e( 'Timing helper installed, so plugin load times count too.', 'plugin-speed-report' ); ?></span>
						</li>
					<?php else : ?>
						<li class="is-warn">
							<?php echo self::icon( 'triangle-alert', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
							<span>
								<?php
								printf(
									/* translators: %s: folder name. */
									esc_html__( 'The timing helper couldn\'t be added to %s, so plugins that load before this one are only partly timed.', 'plugin-speed-report' ),
									'<code>wp-content/mu-plugins</code>'
								);
								?>
							</span>
						</li>
					<?php endif; ?>
					<li class="<?php echo $cache ? 'is-ok' : 'is-off'; ?>">
						<?php echo self::icon( $cache ? 'zap' : 'info', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
						<span><?php echo $cache ? esc_html__( 'Persistent object cache: on.', 'plugin-speed-report' ) : esc_html__( 'Persistent object cache: off.', 'plugin-speed-report' ); ?></span>
					</li>
				</ul>
				<p class="psr-muted"><?php esc_html_e( 'Your visitors never notice: measuring happens only on the scan\'s own page loads, and page caches are skipped for those.', 'plugin-speed-report' ); ?></p>
				<?php
			}
		);

		$this->render_updates_box();
	}

	/**
	 * Rough duration of a scan, in words.
	 *
	 * @param int $loads Page loads.
	 * @return string
	 */
	private static function duration( $loads ) {
		$seconds = $loads * 1.5;

		if ( $seconds < 60 ) {
			return __( 'under a minute', 'plugin-speed-report' );
		}

		$minutes = (int) ceil( $seconds / 60 );

		/* translators: %d: minutes. */
		return sprintf( _n( 'about %d minute', 'about %d minutes', $minutes, 'plugin-speed-report' ), $minutes );
	}

	/**
	 * Show where updates come from, so it's easy to confirm the repo is wired up.
	 *
	 * @return void
	 */
	private function render_updates_box() {
		$this->box(
			'refresh-cw',
			__( 'Updates', 'plugin-speed-report' ),
			function () {
				$repo_label = preg_replace( '#^https?://[^/]+/#', '', untrailingslashit( PLUGIN_SPEED_REPORT_REPO ) );
				$check_url  = Plugin_Speed_Report_Updater::check_now_url();
				?>
				<p>
					<?php
					/* translators: %s: installed plugin version. */
					printf( esc_html__( 'Installed version: %s', 'plugin-speed-report' ), '<strong>' . esc_html( PLUGIN_SPEED_REPORT_VERSION ) . '</strong>' );
					?>
				</p>

				<?php if ( ! Plugin_Speed_Report_Updater::has_repo() ) : ?>
					<p>
						<?php
						printf(
							/* translators: 1: header name, 2: main plugin file. */
							esc_html__( 'No repo set. Add it to the %1$s header in %2$s to turn on automatic updates.', 'plugin-speed-report' ),
							'<code>Update URI</code>',
							'<code>' . esc_html( plugin_basename( PLUGIN_SPEED_REPORT_FILE ) ) . '</code>'
						);
						?>
					</p>
				<?php elseif ( Plugin_Speed_Report_Updater::is_dev_copy() ) : ?>
					<p>
						<?php
						printf(
							/* translators: 1: repo, 2: constant name. */
							esc_html__( 'Releases come from %1$s, but update checks are off on this development copy so an update can\'t overwrite your work. Define %2$s as false in wp-config.php to test updates here.', 'plugin-speed-report' ),
							'<a href="' . esc_url( PLUGIN_SPEED_REPORT_REPO ) . '" target="_blank" rel="noopener">' . esc_html( $repo_label ) . '</a>',
							'<code>PLUGIN_SPEED_REPORT_DEV_MODE</code>'
						);
						?>
					</p>
				<?php else : ?>
					<p>
						<?php
						printf(
							/* translators: %s: repo. */
							esc_html__( 'Updates come from the latest release of %s.', 'plugin-speed-report' ),
							'<a href="' . esc_url( PLUGIN_SPEED_REPORT_REPO ) . '" target="_blank" rel="noopener">' . esc_html( $repo_label ) . '</a>'
						);
						?>
					</p>
					<?php if ( $check_url ) : ?>
						<p><a class="button" href="<?php echo esc_url( $check_url ); ?>"><?php echo self::icon( 'refresh-cw', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Check for updates', 'plugin-speed-report' ); ?></a></p>
					<?php endif; ?>
				<?php endif; ?>
				<?php
			},
			'psr-updates-box'
		);
	}

	// Report tab.

	/**
	 * The report for the latest scan.
	 *
	 * @return void
	 */
	private function render_report() {
		$run = Plugin_Speed_Report_Plugin::latest_run();

		if ( null === $run ) {
			$this->box(
				'gauge',
				__( 'Which plugin is slowing down your site?', 'plugin-speed-report' ),
				function () {
					?>
					<div class="psr-empty-state">
						<p class="psr-lead"><?php esc_html_e( 'Run a scan and you\'ll get a ranked, plain-English answer, like:', 'plugin-speed-report' ); ?></p>
						<p class="psr-example">
							<?php echo self::icon( 'quote', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
							<?php esc_html_e( 'WooCommerce: 0.40 s and 180 KB on every page, including 3 pages that don\'t use it.', 'plugin-speed-report' ); ?>
						</p>
						<ul class="psr-steps">
							<li><?php echo self::icon( 'scan-search', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><span><strong><?php esc_html_e( 'Measures', 'plugin-speed-report' ); ?></strong> <?php esc_html_e( 'each plugin\'s server time, database queries, and CSS and JavaScript on your key pages.', 'plugin-speed-report' ); ?></span></li>
							<li><?php echo self::icon( 'lightbulb', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><span><strong><?php esc_html_e( 'Suggests fixes', 'plugin-speed-report' ); ?></strong> <?php esc_html_e( 'such as loading a plugin\'s files only on the pages that use it, which you can turn on with one click.', 'plugin-speed-report' ); ?></span></li>
							<li><?php echo self::icon( 'git-compare-arrows', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><span><strong><?php esc_html_e( 'Compares', 'plugin-speed-report' ); ?></strong> <?php esc_html_e( 'before and after, so you can see what a change actually did.', 'plugin-speed-report' ); ?></span></li>
						</ul>
						<p class="psr-muted"><?php esc_html_e( 'Start the scan from the box on the right. Pick which pages it tests under Pages.', 'plugin-speed-report' ); ?></p>
					</div>
					<?php
				}
			);
			return;
		}

		$owners = Plugin_Speed_Report_Report::owners( $run );
		$totals = Plugin_Speed_Report_Report::totals( $run );

		if ( 0 === $totals['pages'] ) {
			$this->box(
				'triangle-alert',
				__( 'No pages could be measured', 'plugin-speed-report' ),
				function () use ( $run ) {
					echo '<p>' . esc_html__( 'Every page in the last scan failed. Here\'s why:', 'plugin-speed-report' ) . '</p>';
					$this->render_pages_table( $run );
				}
			);
			return;
		}

		$this->box(
			'gauge',
			__( 'Summary', 'plugin-speed-report' ),
			function () use ( $run, $owners, $totals ) {
				$this->render_summary( $run, $owners, $totals );
			},
			'psr-summary-box',
			'<span class="psr-box-meta">' . esc_html(
				sprintf(
					/* translators: 1: date and time, 2: number of pages. */
					_n( 'Scanned %1$s · %2$d page', 'Scanned %1$s · %2$d pages', $totals['pages'], 'plugin-speed-report' ),
					wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $run['created'] ),
					$totals['pages']
				)
			) . '</span>'
		);

		$this->box(
			'list-ordered',
			__( 'Your plugins, biggest impact first', 'plugin-speed-report' ),
			function () use ( $run, $owners, $totals ) {
				$this->render_plugin_list( $run, $owners, $totals );
			},
			'psr-plugins-box'
		);

		$this->box(
			'files',
			__( 'Pages tested', 'plugin-speed-report' ),
			function () use ( $run ) {
				$this->render_pages_table( $run );
				$this->render_run_notes( $run );
			}
		);
	}

	/**
	 * Stat tiles, the time breakdown bar and the headline finding.
	 *
	 * @param array $run    Saved scan.
	 * @param array $owners Owner rows.
	 * @param array $totals Totals.
	 * @return void
	 */
	private function render_summary( array $run, array $owners, array $totals ) {
		$tiles = array(
			array( 'timer', Plugin_Speed_Report_Report::seconds( $totals['ms'] ), __( 'Server time per page', 'plugin-speed-report' ), '' ),
			array(
				'puzzle',
				/* translators: %d: percentage. */
				sprintf( __( '%d%%', 'plugin-speed-report' ), $totals['share'] ),
				/* translators: %s: server time. */
				sprintf( __( 'From plugins (%s)', 'plugin-speed-report' ), Plugin_Speed_Report_Report::seconds( $totals['plugin_ms'] ) ),
				$totals['share'] >= 60 ? 'is-warn' : '',
			),
			array( 'file-code', Plugin_Speed_Report_Report::kb( $totals['plugin_b'] ), __( 'Plugin CSS & JS per page', 'plugin-speed-report' ), $totals['plugin_b'] >= 512000 ? 'is-warn' : '' ),
			array( 'database', number_format_i18n( round( $totals['q'] ) ), __( 'Database queries per page', 'plugin-speed-report' ), '' ),
		);
		?>
		<div class="psr-stats">
			<?php foreach ( $tiles as $tile ) : ?>
				<div class="psr-stat <?php echo esc_attr( $tile[3] ); ?>">
					<span class="psr-stat-icon"><?php echo self::icon( $tile[0], 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
					<span class="psr-stat-num"><?php echo esc_html( $tile[1] ); ?></span>
					<span class="psr-stat-label"><?php echo esc_html( $tile[2] ); ?></span>
				</div>
			<?php endforeach; ?>
		</div>

		<?php
		$this->render_breakdown( $owners, $totals );

		$top = $owners['plugins'] ? $owners['plugins'][0] : null;

		if ( $top && $top['impact'] >= 50 ) :
			?>
			<p class="psr-callout <?php echo 'bad' === Plugin_Speed_Report_Report::tone( $top ) ? 'is-bad' : 'is-warn'; ?>">
				<?php echo self::icon( 'lightbulb', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
				<span>
					<?php
					printf(
						/* translators: 1: plugin name, 2: sentence about it. */
						esc_html__( 'Biggest slowdown: %1$s. %2$s', 'plugin-speed-report' ),
						'<strong>' . esc_html( $top['name'] ) . '</strong>',
						esc_html( Plugin_Speed_Report_Report::sentence( $top, $totals['pages'] ) )
					);
					?>
				</span>
			</p>
		<?php elseif ( $top ) : ?>
			<p class="psr-callout is-ok">
				<?php echo self::icon( 'circle-check', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
				<span><?php esc_html_e( 'No plugin stands out: none adds more than about 0.05 s to your pages.', 'plugin-speed-report' ); ?></span>
			</p>
			<?php
		endif;
	}

	/**
	 * Stacked bar of where the server time goes: the five biggest plugins,
	 * the other plugins, the theme and WordPress itself.
	 *
	 * @param array $owners Owner rows.
	 * @param array $totals Totals.
	 * @return void
	 */
	private function render_breakdown( array $owners, array $totals ) {
		if ( $totals['ms'] <= 0 ) {
			return;
		}

		$by_time = $owners['plugins'];
		usort(
			$by_time,
			function ( $a, $b ) {
				return $b['ms'] <=> $a['ms'];
			}
		);

		$segments = array();
		$rest     = 0.0;

		foreach ( $by_time as $i => $row ) {
			if ( $i < count( self::SERIES ) && $row['ms'] >= $totals['ms'] * 0.02 ) {
				$segments[] = array( $row['name'], $row['ms'], self::SERIES[ $i ], '' );
			} else {
				$rest += $row['ms'];
			}
		}

		if ( $rest > 0 ) {
			$segments[] = array( __( 'Other plugins', 'plugin-speed-report' ), $rest, '#8c8f94', '' );
		}

		$theme = isset( $owners['others']['theme'] ) ? $owners['others']['theme']['ms'] : 0;
		$core  = isset( $owners['others']['core'] ) ? $owners['others']['core']['ms'] : 0;

		if ( $theme > 0 ) {
			$segments[] = array( __( 'Theme', 'plugin-speed-report' ), $theme, '#b4b7bb', 'is-neutral' );
		}
		if ( $core > 0 ) {
			$segments[] = array( __( 'WordPress core', 'plugin-speed-report' ), $core, '#d5d7da', 'is-neutral' );
		}

		$sum = array_sum( wp_list_pluck( $segments, 1 ) );

		if ( $sum <= 0 ) {
			return;
		}
		?>
		<div class="psr-breakdown">
			<h3 class="psr-subhead"><?php esc_html_e( 'Where the server time goes', 'plugin-speed-report' ); ?></h3>
			<div class="psr-stack" role="img" aria-label="<?php esc_attr_e( 'Server time by plugin, theme and WordPress core. The same numbers are listed below.', 'plugin-speed-report' ); ?>">
				<?php foreach ( $segments as $segment ) : ?>
					<?php
					$label = sprintf(
						/* translators: 1: name, 2: server time, 3: percentage. */
						__( '%1$s: %2$s (%3$d%%)', 'plugin-speed-report' ),
						$segment[0],
						Plugin_Speed_Report_Report::seconds( $segment[1] ),
						round( $segment[1] / $sum * 100 )
					);
					?>
					<span class="psr-stack-seg" style="<?php echo esc_attr( 'flex-grow:' . round( $segment[1], 2 ) . ';background:' . $segment[2] ); ?>" data-psr-tip="<?php echo esc_attr( $label ); ?>" tabindex="0" aria-label="<?php echo esc_attr( $label ); ?>"></span>
				<?php endforeach; ?>
			</div>
			<ul class="psr-legend">
				<?php foreach ( $segments as $segment ) : ?>
					<li>
						<span class="psr-swatch" style="<?php echo esc_attr( 'background:' . $segment[2] ); ?>"></span>
						<span class="psr-legend-name"><?php echo esc_html( $segment[0] ); ?></span>
						<span class="psr-legend-value"><?php echo esc_html( Plugin_Speed_Report_Report::seconds( $segment[1] ) ); ?></span>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
	}

	/**
	 * The ranked plugin list.
	 *
	 * @param array $run    Saved scan.
	 * @param array $owners Owner rows.
	 * @param array $totals Totals.
	 * @return void
	 */
	private function render_plugin_list( array $run, array $owners, array $totals ) {
		if ( ! $owners['plugins'] ) {
			echo '<p class="psr-empty">' . self::icon( 'circle-check', 16 ) . esc_html__( 'No plugins ran on the pages tested.', 'plugin-speed-report' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG.
			return;
		}

		$rules = Plugin_Speed_Report_Plugin::rules();
		$cache = ! empty( $run['env']['cache'] );
		$max   = max( 1, $owners['plugins'][0]['impact'] );
		?>
		<p class="psr-intro">
			<?php esc_html_e( 'Ranked by estimated delay: server time, plus the time to download the plugin\'s files on a typical mobile connection. Open a plugin for its pages, files and fixes.', 'plugin-speed-report' ); ?>
		</p>
		<div class="psr-legend psr-legend-inline">
			<span><span class="psr-swatch is-time"></span><?php esc_html_e( 'Server time', 'plugin-speed-report' ); ?></span>
			<span><span class="psr-swatch is-weight"></span><?php esc_html_e( 'Download time (estimated)', 'plugin-speed-report' ); ?></span>
		</div>
		<?php
		$major = array();
		$minor = array();

		foreach ( $owners['plugins'] as $rank => $row ) {
			if ( $rank < 5 || $row['impact'] >= 10 || $row['bytes'] >= 1024 ) {
				$major[ $rank ] = $row;
			} else {
				$minor[ $rank ] = $row;
			}
		}
		?>
		<ol class="psr-plugins">
			<?php
			foreach ( $major as $rank => $row ) {
				$this->render_plugin_row( $run, $row, $rank, $max, $rules, $cache, $totals );
			}
			?>
		</ol>
		<?php if ( $minor ) : ?>
			<details class="psr-minor">
				<summary>
					<?php echo self::icon( 'chevron-down', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
					<?php
					/* translators: %d: number of plugins. */
					echo esc_html( sprintf( _n( 'Show %d more plugin with almost no impact', 'Show %d more plugins with almost no impact', count( $minor ), 'plugin-speed-report' ), count( $minor ) ) );
					?>
				</summary>
				<ol class="psr-plugins" start="<?php echo esc_attr( (string) ( count( $major ) + 1 ) ); ?>">
					<?php
					foreach ( $minor as $rank => $row ) {
						$this->render_plugin_row( $run, $row, $rank, $max, $rules, $cache, $totals );
					}
					?>
				</ol>
			</details>
		<?php endif; ?>
		<?php
		$this->render_others( $owners );
	}


	/**
	 * One plugin in the ranked list.
	 *
	 * @param array $run    Saved scan.
	 * @param array $row    Owner row.
	 * @param int   $rank   Position, from 0.
	 * @param float $max    Biggest impact, for the bar.
	 * @param array $rules  Current fixes.
	 * @param bool  $cache  Whether the site has an object cache.
	 * @param array $totals Totals.
	 * @return void
	 */
	private function render_plugin_row( array $run, array $row, $rank, $max, array $rules, $cache, array $totals ) {
		$tips    = Plugin_Speed_Report_Report::suggestions( $row, $run, $rules, $cache );
		$fixable = array_filter(
			$tips,
			function ( $tip ) {
				return 'fixed' !== $tip['type'] && 'unused' !== $tip['type'];
			}
		);
		$tone    = Plugin_Speed_Report_Report::tone( $row );
		$weight  = $row['gz'] / 1024 * Plugin_Speed_Report_Report::MS_PER_KB;
		?>
		<li class="psr-plugin is-<?php echo esc_attr( $tone ); ?>">
			<details>
				<summary>
					<span class="psr-rank"><?php echo esc_html( (string) ( $rank + 1 ) ); ?></span>
					<span class="psr-plugin-main">
						<span class="psr-plugin-name">
							<?php echo esc_html( $row['name'] ); ?>
							<?php if ( $fixable ) : ?>
								<span class="psr-badge">
									<?php echo self::icon( 'lightbulb', 12 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
									<?php
									/* translators: %d: number of suggestions. */
									echo esc_html( sprintf( _n( '%d suggestion', '%d suggestions', count( $fixable ), 'plugin-speed-report' ), count( $fixable ) ) );
									?>
								</span>
							<?php endif; ?>
							<?php if ( isset( $rules[ substr( $row['key'], 2 ) ] ) && 0 === strpos( $row['key'], 'p:' ) ) : ?>
								<span class="psr-badge is-ok"><?php echo self::icon( 'circle-check', 12 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Fix on', 'plugin-speed-report' ); ?></span>
							<?php endif; ?>
						</span>
						<span class="psr-sentence"><?php echo esc_html( Plugin_Speed_Report_Report::sentence( $row, $totals['pages'] ) ); ?></span>
						<span class="psr-bar" aria-hidden="true">
							<span class="psr-bar-time" style="<?php echo esc_attr( 'width:' . round( $row['ms'] / $max * 100, 2 ) . '%' ); ?>"></span>
							<span class="psr-bar-weight" style="<?php echo esc_attr( 'width:' . round( $weight / $max * 100, 2 ) . '%' ); ?>"></span>
						</span>
					</span>
					<span class="psr-chips">
						<span class="psr-chip" title="<?php esc_attr_e( 'Server time per page', 'plugin-speed-report' ); ?>"><?php echo self::icon( 'timer', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php echo esc_html( Plugin_Speed_Report_Report::seconds( $row['ms'] ) ); ?></span>
						<span class="psr-chip" title="<?php esc_attr_e( 'Database queries per page', 'plugin-speed-report' ); ?>"><?php echo self::icon( 'database', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php echo esc_html( number_format_i18n( round( $row['q'] ) ) ); ?></span>
						<span class="psr-chip" title="<?php esc_attr_e( 'CSS and JavaScript per page', 'plugin-speed-report' ); ?>"><?php echo self::icon( 'file-code', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php echo esc_html( Plugin_Speed_Report_Report::kb( $row['bytes'] ) ); ?></span>
					</span>
					<span class="psr-toggle" aria-hidden="true"><?php echo self::icon( 'chevron-down', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
				</summary>
				<div class="psr-plugin-body">
					<?php $this->render_tips( $tips, $row ); ?>
					<?php $this->render_plugin_pages( $row ); ?>
					<?php $this->render_plugin_files( $row ); ?>
				</div>
			</details>
		</li>
		<?php
	}

	/**
	 * Suggested fixes for one plugin.
	 *
	 * @param array $tips Suggestions.
	 * @param array $row  Owner row.
	 * @return void
	 */
	private function render_tips( array $tips, array $row ) {
		if ( ! $tips ) {
			echo '<p class="psr-empty">' . self::icon( 'circle-check', 16 ) . esc_html__( 'Nothing to fix here.', 'plugin-speed-report' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG.
			return;
		}
		?>
		<ul class="psr-tips">
			<?php foreach ( $tips as $tip ) : ?>
				<li class="psr-tip is-<?php echo esc_attr( $tip['type'] ); ?>">
					<span class="psr-tip-icon"><?php echo self::icon( $tip['icon'], 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
					<span class="psr-tip-text">
						<strong><?php echo esc_html( $tip['title'] ); ?></strong>
						<span><?php echo esc_html( $tip['text'] ); ?></span>
						<?php if ( 'unload' === $tip['type'] ) : ?>
							<span class="psr-tip-actions">
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
									<input type="hidden" name="action" value="plugin_speed_report_fix">
									<input type="hidden" name="op" value="add">
									<input type="hidden" name="folder" value="<?php echo esc_attr( $tip['folder'] ); ?>">
									<?php wp_nonce_field( 'plugin_speed_report_fix_' . $tip['folder'] ); ?>
									<button type="submit" class="button button-primary"><?php echo self::icon( 'scissors', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Turn on this fix', 'plugin-speed-report' ); ?></button>
								</form>
								<a class="button" href="<?php echo esc_url( Plugin_Speed_Report_Rules::preview_url( $tip['folder'], $tip['preview'] ) ); ?>" target="_blank" rel="noopener"><?php echo self::icon( 'eye', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Preview a page without them', 'plugin-speed-report' ); ?></a>
							</span>
						<?php elseif ( 'fixed' === $tip['type'] ) : ?>
							<span class="psr-tip-actions">
								<a class="button" href="<?php echo esc_url( self::tab_url( 'fixes' ) . '#fix-' . substr( $row['key'], 2 ) ); ?>"><?php echo self::icon( 'wrench', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Edit the fix', 'plugin-speed-report' ); ?></a>
							</span>
						<?php elseif ( 'update' === $tip['type'] ) : ?>
							<span class="psr-tip-actions">
								<a class="button" href="<?php echo esc_url( admin_url( 'update-core.php' ) ); ?>"><?php echo self::icon( 'download', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Go to Updates', 'plugin-speed-report' ); ?></a>
							</span>
						<?php endif; ?>
					</span>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
	}

	/**
	 * One plugin's numbers on each page.
	 *
	 * @param array $row Owner row.
	 * @return void
	 */
	private function render_plugin_pages( array $row ) {
		?>
		<h4 class="psr-subhead"><?php esc_html_e( 'Page by page', 'plugin-speed-report' ); ?></h4>
		<div class="psr-table-wrap">
			<table class="widefat striped psr-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Page', 'plugin-speed-report' ); ?></th>
						<th scope="col" class="num"><?php esc_html_e( 'Server time', 'plugin-speed-report' ); ?></th>
						<th scope="col" class="num"><?php esc_html_e( 'Queries', 'plugin-speed-report' ); ?></th>
						<th scope="col" class="num"><?php esc_html_e( 'CSS & JS', 'plugin-speed-report' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Used here?', 'plugin-speed-report' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $row['per_page'] as $page ) : ?>
						<tr>
							<td><?php echo esc_html( $page['label'] ); ?> <a class="psr-path" href="<?php echo esc_url( Plugin_Speed_Report_Plugin::url_for_path( $page['path'] ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $page['path'] ); ?></a></td>
							<td class="num"><?php echo esc_html( Plugin_Speed_Report_Report::seconds( $page['ms'] ) ); ?></td>
							<td class="num"><?php echo esc_html( number_format_i18n( $page['q'] ) ); ?></td>
							<td class="num"><?php echo esc_html( $page['bytes'] ? Plugin_Speed_Report_Report::kb( $page['bytes'] ) : '–' ); ?></td>
							<td>
								<?php if ( '' !== $page['used'] ) : ?>
									<span class="psr-tag is-good"><?php echo esc_html( Plugin_Speed_Report_Report::reason( $page['used'] ) ); ?></span>
								<?php elseif ( $page['bytes'] > 0 ) : ?>
									<span class="psr-tag is-bad"><?php esc_html_e( 'No, but loads files', 'plugin-speed-report' ); ?></span>
								<?php else : ?>
									<span class="psr-tag is-none"><?php esc_html_e( 'Nothing visible', 'plugin-speed-report' ); ?></span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * One plugin's CSS and JS files.
	 *
	 * @param array $row Owner row.
	 * @return void
	 */
	private function render_plugin_files( array $row ) {
		if ( ! $row['files'] ) {
			return;
		}
		?>
		<h4 class="psr-subhead"><?php esc_html_e( 'Files it adds', 'plugin-speed-report' ); ?></h4>
		<div class="psr-table-wrap">
			<table class="widefat striped psr-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'File', 'plugin-speed-report' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Type', 'plugin-speed-report' ); ?></th>
						<th scope="col" class="num"><?php esc_html_e( 'Size', 'plugin-speed-report' ); ?></th>
						<th scope="col" class="num"><?php esc_html_e( 'Compressed', 'plugin-speed-report' ); ?></th>
						<th scope="col" class="num"><?php esc_html_e( 'Pages', 'plugin-speed-report' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( array_slice( $row['files'], 0, 25 ) as $file ) : ?>
						<tr>
							<td>
								<?php echo esc_html( self::file_label( $file ) ); ?>
								<?php if ( ! empty( $file['d'] ) ) : ?>
									<span class="psr-tag is-none" title="<?php esc_attr_e( 'A WordPress file that\'s only on the page because this plugin needs it.', 'plugin-speed-report' ); ?>"><?php esc_html_e( 'Pulled in', 'plugin-speed-report' ); ?></span>
								<?php endif; ?>
								<?php if ( ! empty( $file['k'] ) && 'js' === $file['t'] ) : ?>
									<span class="psr-tag is-bad"><?php esc_html_e( 'Blocks rendering', 'plugin-speed-report' ); ?></span>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( 'css' === $file['t'] ? 'CSS' : 'JS' ); ?></td>
							<td class="num"><?php echo esc_html( ! empty( $file['x'] ) ? __( 'external', 'plugin-speed-report' ) : Plugin_Speed_Report_Report::kb( $file['b'] ) ); ?></td>
							<td class="num"><?php echo esc_html( ! empty( $file['x'] ) ? '–' : Plugin_Speed_Report_Report::kb( $file['g'] ) ); ?></td>
							<td class="num"><?php echo esc_html( number_format_i18n( $file['pages'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * A file's display name.
	 *
	 * @param array $file File entry.
	 * @return string
	 */
	private static function file_label( array $file ) {
		if ( ! empty( $file['i'] ) ) {
			return '' !== $file['h']
				/* translators: %s: script or style handle. */
				? sprintf( __( 'Inline code (%s)', 'plugin-speed-report' ), $file['h'] )
				: __( 'Inline code it prints itself', 'plugin-speed-report' );
		}

		if ( '' !== $file['h'] && '' !== $file['n'] ) {
			return $file['n'] . ' (' . $file['h'] . ')';
		}

		return '' !== $file['n'] ? $file['n'] : $file['h'];
	}

	/**
	 * The theme, WordPress core and anything unattributed, after the plugins.
	 *
	 * @param array $owners Owner rows.
	 * @return void
	 */
	private function render_others( array $owners ) {
		if ( ! $owners['others'] ) {
			return;
		}
		?>
		<h3 class="psr-subhead"><?php esc_html_e( 'Not plugins', 'plugin-speed-report' ); ?></h3>
		<div class="psr-table-wrap">
			<table class="widefat psr-table psr-others">
				<tbody>
					<?php foreach ( array( 'theme', 'core', 'other', 'external' ) as $key ) : ?>
						<?php
						if ( ! isset( $owners['others'][ $key ] ) ) {
							continue;
						}
						$row = $owners['others'][ $key ];
						?>
						<tr>
							<th scope="row"><?php echo self::icon( 'theme' === $key ? 'palette' : ( 'core' === $key ? 'layers' : 'globe' ), 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php echo esc_html( $row['name'] ); ?></th>
							<td class="num"><?php echo self::icon( 'timer', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php echo esc_html( Plugin_Speed_Report_Report::seconds( $row['ms'] ) ); ?></td>
							<td class="num"><?php echo self::icon( 'database', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php echo esc_html( number_format_i18n( round( $row['q'] ) ) ); ?></td>
							<td class="num"><?php echo self::icon( 'file-code', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php echo esc_html( Plugin_Speed_Report_Report::kb( $row['bytes'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Table of the pages a scan tested.
	 *
	 * @param array $run Saved scan.
	 * @return void
	 */
	private function render_pages_table( array $run ) {
		?>
		<div class="psr-table-wrap">
			<table class="widefat striped psr-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Page', 'plugin-speed-report' ); ?></th>
						<th scope="col" class="num"><?php esc_html_e( 'Server time', 'plugin-speed-report' ); ?></th>
						<th scope="col" class="num"><?php esc_html_e( 'Queries', 'plugin-speed-report' ); ?></th>
						<th scope="col" class="num"><?php esc_html_e( 'All CSS & JS', 'plugin-speed-report' ); ?></th>
						<th scope="col" class="num"><?php esc_html_e( 'HTML', 'plugin-speed-report' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $run['pages'] as $page ) : ?>
						<tr>
							<td><?php echo esc_html( $page['label'] ); ?> <a class="psr-path" href="<?php echo esc_url( Plugin_Speed_Report_Plugin::url_for_path( $page['path'] ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $page['path'] ); ?></a></td>
							<?php if ( '' !== $page['error'] ) : ?>
								<td colspan="4" class="psr-error-cell"><?php echo self::icon( 'triangle-alert', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php echo esc_html( ucfirst( $page['error'] ) ); ?></td>
							<?php else : ?>
								<?php
								$bytes = 0;
								foreach ( (array) $page['assets'] as $assets ) {
									$bytes += $assets['css'] + $assets['js'];
								}
								?>
								<td class="num"><?php echo esc_html( Plugin_Speed_Report_Report::seconds( $page['ms'] ) ); ?></td>
								<td class="num"><?php echo esc_html( number_format_i18n( $page['q'] ) ); ?></td>
								<td class="num"><?php echo esc_html( Plugin_Speed_Report_Report::kb( $bytes ) ); ?></td>
								<td class="num"><?php echo esc_html( Plugin_Speed_Report_Report::kb( $page['html'] ) ); ?></td>
							<?php endif; ?>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Small print about how a scan ran.
	 *
	 * @param array $run Saved scan.
	 * @return void
	 */
	private function render_run_notes( array $run ) {
		$notes = array(
			sprintf(
				/* translators: %d: runs per page. */
				_n( 'Each page was loaded %d time after a warm-up; the numbers are from that run.', 'Each page was loaded %d times after a warm-up; the numbers are the middle (median) values.', (int) $run['samples'], 'plugin-speed-report' ),
				(int) $run['samples']
			),
			__( 'Pages were loaded as a logged-out visitor with page caching skipped, so these are the costs of building each page from scratch.', 'plugin-speed-report' ),
		);

		if ( 'plugin' === $run['env']['mode'] ) {
			$notes[] = __( 'The timing helper wasn\'t installed for this scan, so plugins that load before Plugin Speed Report weren\'t fully timed.', 'plugin-speed-report' );
		}

		if ( ! empty( $run['env']['missed_mu'] ) ) {
			/* translators: %s: list of file names. */
			$notes[] = sprintf( __( 'These must-use plugins load before the timing helper and weren\'t timed: %s.', 'plugin-speed-report' ), implode( ', ', $run['env']['missed_mu'] ) );
		}
		?>
		<ul class="psr-notes">
			<?php foreach ( $notes as $note ) : ?>
				<li><?php echo esc_html( $note ); ?></li>
			<?php endforeach; ?>
		</ul>
		<?php
	}

	// Compare tab.

	/**
	 * Before and after.
	 *
	 * @return void
	 */
	private function render_compare() {
		$runs = Plugin_Speed_Report_Plugin::runs();

		if ( count( $runs ) < 2 ) {
			$this->box(
				'git-compare-arrows',
				__( 'Before & after', 'plugin-speed-report' ),
				function () use ( $runs ) {
					?>
					<div class="psr-empty-state">
						<p class="psr-lead"><?php echo $runs ? esc_html__( 'You have one scan. Make a change, then run another scan to compare.', 'plugin-speed-report' ) : esc_html__( 'Run two scans to compare them.', 'plugin-speed-report' ); ?></p>
						<ol class="psr-steps is-numbered">
							<li><span><?php esc_html_e( 'Run a scan: that\'s your "before".', 'plugin-speed-report' ); ?></span></li>
							<li><span><?php esc_html_e( 'Make a change: turn on a suggested fix, update or deactivate a plugin, or change a setting.', 'plugin-speed-report' ); ?></span></li>
							<li><span><?php esc_html_e( 'Run another scan and come back here to see what changed, plugin by plugin.', 'plugin-speed-report' ); ?></span></li>
						</ol>
					</div>
					<?php
				}
			);
			return;
		}

		$ids    = wp_list_pluck( $runs, 'id' );
		$after  = isset( $_GET['after'] ) ? sanitize_key( wp_unslash( $_GET['after'] ) ) : $ids[0]; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Navigation only.
		$before = isset( $_GET['before'] ) ? sanitize_key( wp_unslash( $_GET['before'] ) ) : $ids[1]; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Navigation only.
		$after  = in_array( $after, $ids, true ) ? $after : $ids[0];
		$before = in_array( $before, $ids, true ) ? $before : $ids[1];

		$a = Plugin_Speed_Report_Plugin::get_run( $before );
		$b = Plugin_Speed_Report_Plugin::get_run( $after );

		$this->box(
			'git-compare-arrows',
			__( 'Before & after', 'plugin-speed-report' ),
			function () use ( $runs, $before, $after, $a, $b ) {
				?>
				<form method="get" class="psr-compare-form" data-psr-autosubmit>
					<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>">
					<input type="hidden" name="tab" value="compare">
					<label>
						<span><?php esc_html_e( 'Before', 'plugin-speed-report' ); ?></span>
						<?php $this->run_select( 'before', $runs, $before ); ?>
					</label>
					<span class="psr-compare-arrow" aria-hidden="true"><?php echo self::icon( 'arrow-right', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
					<label>
						<span><?php esc_html_e( 'After', 'plugin-speed-report' ); ?></span>
						<?php $this->run_select( 'after', $runs, $after ); ?>
					</label>
					<button type="submit" class="button psr-no-js"><?php esc_html_e( 'Compare', 'plugin-speed-report' ); ?></button>
				</form>
				<?php
				if ( null === $a || null === $b ) {
					echo '<p class="psr-empty">' . esc_html__( 'One of these scans couldn\'t be loaded.', 'plugin-speed-report' ) . '</p>';
					return;
				}

				$this->render_compare_body( $a, $b );
			}
		);

		$this->box(
			'history',
			__( 'Scan history', 'plugin-speed-report' ),
			function () use ( $runs ) {
				$this->render_history( $runs );
			}
		);
	}

	/**
	 * A dropdown of saved scans.
	 *
	 * @param string $name     Field name.
	 * @param array  $runs     Scan summaries.
	 * @param string $selected Selected ID.
	 * @return void
	 */
	private function run_select( $name, array $runs, $selected ) {
		echo '<select name="' . esc_attr( $name ) . '">';

		foreach ( $runs as $run ) {
			printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( $run['id'] ),
				selected( $run['id'], $selected, false ),
				esc_html( self::run_label( $run ) )
			);
		}

		echo '</select>';
	}

	/**
	 * A scan's name in lists.
	 *
	 * @param array $run Scan summary.
	 * @return string
	 */
	private static function run_label( array $run ) {
		$label = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $run['created'] );

		if ( ! empty( $run['fixes'] ) ) {
			/* translators: 1: date, 2: number of fixes. */
			$label = sprintf( _n( '%1$s (%2$d fix on)', '%1$s (%2$d fixes on)', (int) $run['fixes'], 'plugin-speed-report' ), $label, (int) $run['fixes'] );
		}

		return $label;
	}

	/**
	 * The comparison itself.
	 *
	 * @param array $a Before.
	 * @param array $b After.
	 * @return void
	 */
	private function render_compare_body( array $a, array $b ) {
		$ta = Plugin_Speed_Report_Report::totals( $a );
		$tb = Plugin_Speed_Report_Report::totals( $b );

		$tiles = array(
			array( 'timer', __( 'Server time per page', 'plugin-speed-report' ), $ta['ms'], $tb['ms'], 'time' ),
			array( 'puzzle', __( 'Plugins\' server time', 'plugin-speed-report' ), $ta['plugin_ms'], $tb['plugin_ms'], 'time' ),
			array( 'file-code', __( 'Plugin CSS & JS per page', 'plugin-speed-report' ), $ta['plugin_b'], $tb['plugin_b'], 'bytes' ),
			array( 'database', __( 'Database queries per page', 'plugin-speed-report' ), $ta['q'], $tb['q'], 'count' ),
		);
		?>
		<div class="psr-stats is-compare">
			<?php foreach ( $tiles as $tile ) : ?>
				<div class="psr-stat">
					<span class="psr-stat-icon"><?php echo self::icon( $tile[0], 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
					<span class="psr-stat-num"><?php echo esc_html( self::format( $tile[3], $tile[4] ) ); ?></span>
					<span class="psr-stat-label"><?php echo esc_html( $tile[1] ); ?></span>
					<?php self::delta( $tile[2], $tile[3], $tile[4], true ); ?>
				</div>
			<?php endforeach; ?>
		</div>

		<?php
		$pages_a = wp_list_pluck( Plugin_Speed_Report_Report::measured_pages( $a ), 'path' );
		$pages_b = wp_list_pluck( Plugin_Speed_Report_Report::measured_pages( $b ), 'path' );
		sort( $pages_a );
		sort( $pages_b );

		if ( $pages_a !== $pages_b ) :
			?>
			<p class="psr-callout is-warn">
				<?php echo self::icon( 'info', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
				<span><?php esc_html_e( 'These scans measured different pages, so part of the difference comes from that.', 'plugin-speed-report' ); ?></span>
			</p>
			<?php
		endif;

		$fixes_a = (array) $a['env']['fixes'];
		$fixes_b = (array) $b['env']['fixes'];
		$added   = array_diff( $fixes_b, $fixes_a );

		if ( $added ) :
			$names = array();
			foreach ( $added as $folder ) {
				$names[] = Plugin_Speed_Report_Report::name( $b, 'p:' . $folder );
			}
			?>
			<p class="psr-callout">
				<?php echo self::icon( 'wrench', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
				<span>
					<?php
					/* translators: %s: plugin names. */
					printf( esc_html__( 'Fixes turned on between these scans: %s.', 'plugin-speed-report' ), esc_html( implode( ', ', $names ) ) );
					?>
				</span>
			</p>
		<?php endif; ?>

		<div class="psr-table-wrap">
			<table class="widefat striped psr-table psr-compare">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Plugin', 'plugin-speed-report' ); ?></th>
						<th scope="col" class="num"><?php esc_html_e( 'Server time', 'plugin-speed-report' ); ?></th>
						<th scope="col" class="num"><?php esc_html_e( 'Queries', 'plugin-speed-report' ); ?></th>
						<th scope="col" class="num"><?php esc_html_e( 'CSS & JS', 'plugin-speed-report' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( Plugin_Speed_Report_Report::compare( $a, $b ) as $row ) : ?>
						<tr class="<?php echo $row['plugin'] ? '' : 'is-other'; ?>">
							<th scope="row"><?php echo esc_html( $row['name'] ); ?></th>
							<td class="num"><?php echo esc_html( self::format( $row['after']['ms'], 'time' ) ); ?><?php self::delta( $row['before']['ms'], $row['after']['ms'], 'time' ); ?></td>
							<td class="num"><?php echo esc_html( self::format( $row['after']['q'], 'count' ) ); ?><?php self::delta( $row['before']['q'], $row['after']['q'], 'count' ); ?></td>
							<td class="num"><?php echo esc_html( self::format( $row['after']['bytes'], 'bytes' ) ); ?><?php self::delta( $row['before']['bytes'], $row['after']['bytes'], 'bytes' ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<p class="psr-muted"><?php esc_html_e( 'Values are averages per page in the "after" scan, with the change from "before". Small changes in server time (a few hundredths of a second) are normal run-to-run noise.', 'plugin-speed-report' ); ?></p>
		<?php
	}

	/**
	 * Format a value.
	 *
	 * @param float  $value Value.
	 * @param string $kind  time, bytes or count.
	 * @return string
	 */
	private static function format( $value, $kind ) {
		if ( 'time' === $kind ) {
			return Plugin_Speed_Report_Report::seconds( $value );
		}

		if ( 'bytes' === $kind ) {
			return Plugin_Speed_Report_Report::kb( $value );
		}

		return number_format_i18n( round( $value ) );
	}

	/**
	 * Print the change between two values, green when it went down.
	 *
	 * @param float  $before Before.
	 * @param float  $after  After.
	 * @param string $kind   time, bytes or count.
	 * @param bool   $block  Show as its own line.
	 * @return void
	 */
	private static function delta( $before, $after, $kind, $block = false ) {
		$diff = $after - $before;

		// Server time wobbles a little between runs: under 15 ms or 10% is noise.
		$noise = array(
			'time'  => max( 15, abs( $before ) * 0.1 ),
			'bytes' => 512,
			'count' => 0.5,
		);

		if ( abs( $diff ) < $noise[ $kind ] ) {
			printf( '<span class="psr-delta is-same%s">%s</span>', $block ? ' is-block' : '', esc_html__( 'no change', 'plugin-speed-report' ) );
			return;
		}

		$value = 'time' === $kind
			? number_format_i18n( abs( $diff ) / 1000, 2 ) . ' s'
			: self::format( abs( $diff ), $kind );

		$percent = $before > 0 ? ' (' . number_format_i18n( abs( $diff ) / $before * 100 ) . '%)' : '';

		printf(
			'<span class="psr-delta %1$s%2$s">%3$s%4$s</span>',
			$diff < 0 ? 'is-better' : 'is-worse',
			$block ? ' is-block' : '',
			self::icon( $diff < 0 ? 'arrow-down' : 'arrow-up', 12 ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG.
			esc_html( ( $diff < 0 ? '−' : '+' ) . $value . $percent )
		);
	}

	/**
	 * List of saved scans with delete buttons.
	 *
	 * @param array $runs Scan summaries.
	 * @return void
	 */
	private function render_history( array $runs ) {
		?>
		<div class="psr-table-wrap">
			<table class="widefat striped psr-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Scan', 'plugin-speed-report' ); ?></th>
						<th scope="col" class="num"><?php esc_html_e( 'Pages', 'plugin-speed-report' ); ?></th>
						<th scope="col" class="num"><?php esc_html_e( 'Server time', 'plugin-speed-report' ); ?></th>
						<th scope="col" class="num"><?php esc_html_e( 'Plugin CSS & JS', 'plugin-speed-report' ); ?></th>
						<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'plugin-speed-report' ); ?></span></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $runs as $run ) : ?>
						<tr>
							<td><?php echo esc_html( self::run_label( $run ) ); ?></td>
							<td class="num"><?php echo esc_html( $run['pages'] . ( $run['tested'] > $run['pages'] ? ' / ' . $run['tested'] : '' ) ); ?></td>
							<td class="num"><?php echo esc_html( Plugin_Speed_Report_Report::seconds( $run['ms'] ) ); ?></td>
							<td class="num"><?php echo esc_html( Plugin_Speed_Report_Report::kb( $run['plugin_b'] ) ); ?></td>
							<td class="psr-actions-cell">
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-psr-confirm>
									<input type="hidden" name="action" value="plugin_speed_report_delete_run">
									<input type="hidden" name="run" value="<?php echo esc_attr( $run['id'] ); ?>">
									<?php wp_nonce_field( 'plugin_speed_report_delete_' . $run['id'] ); ?>
									<button type="submit" class="button-link psr-delete" aria-label="<?php esc_attr_e( 'Delete this scan', 'plugin-speed-report' ); ?>"><?php echo self::icon( 'trash-2', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	// Pages tab.

	/**
	 * Which pages to test and how thoroughly.
	 *
	 * @return void
	 */
	private function render_pages() {
		$settings   = $this->plugin->get_settings();
		$candidates = Plugin_Speed_Report_Scanner::candidate_pages();
		$skip       = array_flip( (array) $settings['skip'] );
		$name       = Plugin_Speed_Report_Plugin::OPTION;
		?>
		<form action="options.php" method="post" class="psr-form">
			<?php settings_fields( self::PAGE ); ?>

			<?php
			$this->box(
				'files',
				__( 'Pages to test', 'plugin-speed-report' ),
				function () use ( $candidates, $skip, $name, $settings ) {
					?>
					<p class="psr-intro"><?php esc_html_e( 'Picked from your site\'s own content: one of each kind of page, so a plugin that loads files on every page shows up. Untick any you don\'t want tested.', 'plugin-speed-report' ); ?></p>
					<div class="psr-options">
						<?php foreach ( $candidates as $key => $page ) : ?>
							<label class="psr-option">
								<input type="hidden" name="<?php echo esc_attr( $name ); ?>[all][]" value="<?php echo esc_attr( $key ); ?>">
								<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[include][]" value="<?php echo esc_attr( $key ); ?>" <?php checked( ! isset( $skip[ $key ] ) ); ?>>
								<span class="psr-option-icon"><?php echo self::icon( self::page_icon( $key ), 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
								<span class="psr-option-text">
									<strong><?php echo esc_html( $page['label'] ); ?></strong>
									<span><?php echo esc_html( $page['path'] ); ?></span>
								</span>
							</label>
						<?php endforeach; ?>
					</div>

					<h3 class="psr-subhead"><label for="psr-custom"><?php esc_html_e( 'More pages', 'plugin-speed-report' ); ?></label></h3>
					<p class="description" id="psr-custom-help">
						<?php
						printf(
							/* translators: 1: example, 2: maximum. */
							esc_html__( 'One address per line, on this site: for example %1$s for your contact page or a page with a form or slider. Up to %2$d.', 'plugin-speed-report' ),
							'<code>/contact/</code>',
							(int) Plugin_Speed_Report_Plugin::MAX_CUSTOM
						);
						?>
					</p>
					<textarea id="psr-custom" name="<?php echo esc_attr( $name ); ?>[custom]" rows="4" class="large-text code" aria-describedby="psr-custom-help" placeholder="/contact/"><?php echo esc_textarea( implode( "\n", (array) $settings['custom'] ) ); ?></textarea>
					<?php
				}
			);

			$this->box(
				'sliders-horizontal',
				__( 'How thorough', 'plugin-speed-report' ),
				function () use ( $settings, $name ) {
					?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="psr-samples"><?php esc_html_e( 'Runs per page', 'plugin-speed-report' ); ?></label></th>
							<td>
								<select id="psr-samples" name="<?php echo esc_attr( $name ); ?>[samples]">
									<?php for ( $i = 1; $i <= 5; $i++ ) : ?>
										<option value="<?php echo esc_attr( (string) $i ); ?>" <?php selected( (int) $settings['samples'], $i ); ?>><?php echo esc_html( (string) $i ); ?></option>
									<?php endfor; ?>
								</select>
								<p class="description"><?php esc_html_e( 'Each page is loaded this many times (after one warm-up) and the middle result is kept. Three smooths out most noise; use five on busy or shared hosting.', 'plugin-speed-report' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="psr-keep"><?php esc_html_e( 'Scans to keep', 'plugin-speed-report' ); ?></label></th>
							<td>
								<select id="psr-keep" name="<?php echo esc_attr( $name ); ?>[keep]">
									<?php foreach ( array( 5, 10, 20, 30 ) as $keep ) : ?>
										<option value="<?php echo esc_attr( (string) $keep ); ?>" <?php selected( (int) $settings['keep'], $keep ); ?>><?php echo esc_html( (string) $keep ); ?></option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php esc_html_e( 'Older scans are deleted automatically.', 'plugin-speed-report' ); ?></p>
							</td>
						</tr>
					</table>
					<?php
				}
			);

			submit_button( __( 'Save pages', 'plugin-speed-report' ) );
		?>
		</form>
		<?php
	}

	/**
	 * Icon for a kind of page.
	 *
	 * @param string $key Page key.
	 * @return string
	 */
	private static function page_icon( $key ) {
		if ( 'home' === $key ) {
			return 'house';
		}
		if ( 'post' === $key || 'blog' === $key ) {
			return 'newspaper';
		}
		if ( 'category' === $key || 0 === strpos( $key, 'archive:' ) ) {
			return 'layout-grid';
		}
		if ( 0 === strpos( $key, 'type:' ) ) {
			return 'package';
		}

		return 'file-text';
	}

	// Fixes tab.

	/**
	 * The asset fixes that are on.
	 *
	 * @return void
	 */
	private function render_fixes() {
		$rules = Plugin_Speed_Report_Plugin::rules();
		$run   = Plugin_Speed_Report_Plugin::latest_run();

		$this->box(
			'wrench',
			__( 'Load plugins\' files only where they\'re used', 'plugin-speed-report' ),
			function () use ( $rules ) {
				?>
				<p class="psr-intro"><?php esc_html_e( 'Many plugins add their CSS and JavaScript to every page, even when only one page uses them (a contact form, a slider, a shop). A fix stops that: the plugin\'s files load only on pages that use it.', 'plugin-speed-report' ); ?></p>
				<ul class="psr-checklist">
					<li class="is-ok"><?php echo self::icon( 'circle-check', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><span><?php esc_html_e( 'It keeps loading on pages the scan saw it used, its own page types and archives, and any page with its blocks or shortcodes, including ones you add later.', 'plugin-speed-report' ); ?></span></li>
					<li class="is-ok"><?php echo self::icon( 'circle-check', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><span><?php esc_html_e( 'Only the plugin\'s own files are touched, never the admin, page builders\' editors or other plugins\' files.', 'plugin-speed-report' ); ?></span></li>
					<li class="is-ok"><?php echo self::icon( 'circle-check', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><span><?php esc_html_e( 'Turning a fix off puts everything back at once.', 'plugin-speed-report' ); ?></span></li>
				</ul>
				<?php if ( ! $rules ) : ?>
					<p class="psr-empty"><?php echo self::icon( 'info', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'No fixes are on. When a plugin loads files where it isn\'t used, the Report tab suggests one.', 'plugin-speed-report' ); ?></p>
				<?php endif; ?>
				<?php
			}
		);

		foreach ( $rules as $folder => $rule ) {
			$name = $run ? Plugin_Speed_Report_Report::name( $run, 'p:' . $folder ) : $folder;

			$this->box(
				'scissors',
				$name,
				function () use ( $folder, $rule ) {
					$this->render_fix( (string) $folder, $rule );
				},
				'psr-fix-box',
				'<span class="psr-box-meta"><span class="psr-badge is-ok">' . esc_html__( 'On', 'plugin-speed-report' ) . '</span></span>'
			);
		}
	}

	/**
	 * One fix's details and controls.
	 *
	 * @param string $folder Plugin folder.
	 * @param array  $rule   Fix rule.
	 * @return void
	 */
	private function render_fix( $folder, array $rule ) {
		$keeps = array();

		foreach ( (array) $rule['keep'] as $path ) {
			$keeps[] = array( 'file-text', $path );
		}
		foreach ( (array) $rule['types'] as $type ) {
			$object  = get_post_type_object( $type );
			$keeps[] = array(
				'package',
				/* translators: %s: post type name. */
				sprintf( __( 'Every %s page and archive', 'plugin-speed-report' ), $object ? $object->labels->singular_name : $type ),
			);
		}
		foreach ( (array) $rule['taxonomies'] as $taxonomy ) {
			$object  = get_taxonomy( $taxonomy );
			$keeps[] = array(
				'layout-grid',
				/* translators: %s: taxonomy name. */
				sprintf( __( '%s archives', 'plugin-speed-report' ), $object ? $object->labels->singular_name : $taxonomy ),
			);
		}
		if ( $rule['shortcodes'] ) {
			$keeps[] = array(
				'code-xml',
				/* translators: %s: list of shortcodes. */
				sprintf( __( 'Pages with its shortcodes: %s', 'plugin-speed-report' ), '[' . implode( '], [', array_slice( (array) $rule['shortcodes'], 0, 8 ) ) . ']' . ( count( $rule['shortcodes'] ) > 8 ? '…' : '' ) ),
			);
		}
		if ( $rule['blocks'] ) {
			$keeps[] = array(
				'blocks',
				/* translators: %s: list of block namespaces. */
				sprintf( __( 'Pages with its blocks (%s)', 'plugin-speed-report' ), implode( ', ', (array) $rule['blocks'] ) ),
			);
		}

		$preview = Plugin_Speed_Report_Plugin::sanitize_path( (string) $rule['preview'] );
		?>
		<div id="fix-<?php echo esc_attr( $folder ); ?>"></div>
		<p>
			<?php
			/* translators: %s: date. */
			printf( esc_html__( 'On since %s. Its CSS and JavaScript load only on:', 'plugin-speed-report' ), esc_html( wp_date( get_option( 'date_format' ), (int) $rule['created'] ) ) );
			?>
		</p>
		<ul class="psr-keeps">
			<?php foreach ( $keeps as $keep ) : ?>
				<li><?php echo self::icon( $keep[0], 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><span><?php echo esc_html( $keep[1] ); ?></span></li>
			<?php endforeach; ?>
			<?php if ( ! $keeps ) : ?>
				<li><?php echo self::icon( 'info', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><span><?php esc_html_e( 'No pages yet: add any that need it below.', 'plugin-speed-report' ); ?></span></li>
			<?php endif; ?>
		</ul>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="psr-fix-form">
			<input type="hidden" name="action" value="plugin_speed_report_fix">
			<input type="hidden" name="folder" value="<?php echo esc_attr( $folder ); ?>">
			<?php wp_nonce_field( 'plugin_speed_report_fix_' . $folder ); ?>
			<label class="psr-subhead" for="psr-extra-<?php echo esc_attr( $folder ); ?>"><?php esc_html_e( 'Also load it on', 'plugin-speed-report' ); ?></label>
			<p class="description" id="psr-extra-help-<?php echo esc_attr( $folder ); ?>">
				<?php
				printf(
					/* translators: 1: example, 2: example with wildcard. */
					esc_html__( 'One address per line. %1$s matches one page; %2$s matches every page under it.', 'plugin-speed-report' ),
					'<code>/contact/</code>',
					'<code>/shop/*</code>'
				);
				?>
			</p>
			<textarea id="psr-extra-<?php echo esc_attr( $folder ); ?>" name="extra" rows="3" class="large-text code" aria-describedby="psr-extra-help-<?php echo esc_attr( $folder ); ?>"><?php echo esc_textarea( implode( "\n", (array) $rule['extra'] ) ); ?></textarea>
			<div class="psr-fix-actions">
				<button type="submit" name="op" value="save" class="button button-primary"><?php echo self::icon( 'check', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Save', 'plugin-speed-report' ); ?></button>
				<?php if ( '' !== $preview ) : ?>
					<a class="button" href="<?php echo esc_url( Plugin_Speed_Report_Plugin::url_for_path( $preview ) ); ?>" target="_blank" rel="noopener"><?php echo self::icon( 'external-link', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'View a page without them', 'plugin-speed-report' ); ?></a>
				<?php endif; ?>
				<button type="submit" name="op" value="remove" class="button psr-button-danger"><?php echo self::icon( 'undo-2', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Turn off this fix', 'plugin-speed-report' ); ?></button>
			</div>
		</form>
		<?php
	}

	// Handlers.

	/**
	 * AJAX: start, advance or cancel a scan.
	 *
	 * @return void
	 */
	public function ajax_scan() {
		if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( 'plugin_speed_report_scan', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Your session expired. Reload the page and try again.', 'plugin-speed-report' ) ), 403 );
		}

		$op = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';

		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 90 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- One page load can take up to 30 s plus reading the results.
		}

		if ( 'start' === $op ) {
			$result = Plugin_Speed_Report_Scanner::start( $this->plugin );
		} elseif ( 'step' === $op ) {
			$result = Plugin_Speed_Report_Scanner::step( $this->plugin );
		} elseif ( 'cancel' === $op ) {
			Plugin_Speed_Report_Scanner::cancel();
			wp_send_json_success( array( 'cancelled' => true ) );
		} else {
			$result = new WP_Error( 'op', __( 'Unknown request.', 'plugin-speed-report' ) );
		}

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );
	}

	/**
	 * Turn a fix on, update it or turn it off.
	 *
	 * @return void
	 */
	public function handle_fix() {
		$folder = isset( $_POST['folder'] ) ? sanitize_text_field( wp_unslash( $_POST['folder'] ) ) : '';

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'plugin-speed-report' ), 403 );
		}

		check_admin_referer( 'plugin_speed_report_fix_' . $folder );

		if ( ! Plugin_Speed_Report_Plugin::is_plugin_folder( $folder ) ) {
			wp_die( esc_html__( 'Unknown plugin.', 'plugin-speed-report' ), 400 );
		}

		$op    = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';
		$rules = Plugin_Speed_Report_Plugin::rules();

		if ( 'add' === $op ) {
			$rule = self::build_rule( $folder );

			if ( null === $rule ) {
				wp_safe_redirect( self::tab_url( 'report', array( 'psr_notice' => 'fix_missing' ) ) );
				exit;
			}

			if ( isset( $rules[ $folder ]['extra'] ) ) {
				$rule['extra'] = $rules[ $folder ]['extra'];
			}

			$rules[ $folder ] = $rule;
			Plugin_Speed_Report_Plugin::save_rules( $rules );
			wp_safe_redirect( self::tab_url( 'fixes', array( 'psr_notice' => 'fix_on' ) ) );
			exit;
		}

		if ( 'remove' === $op ) {
			unset( $rules[ $folder ] );
			Plugin_Speed_Report_Plugin::save_rules( $rules );
			wp_safe_redirect( self::tab_url( 'fixes', array( 'psr_notice' => 'fix_off' ) ) );
			exit;
		}

		if ( 'save' === $op && isset( $rules[ $folder ] ) ) {
			$extra = array();
			$lines = isset( $_POST['extra'] ) ? preg_split( '/\r\n|\r|\n/', sanitize_textarea_field( wp_unslash( $_POST['extra'] ) ) ) : array();

			foreach ( (array) $lines as $line ) {
				$path = Plugin_Speed_Report_Plugin::sanitize_path( $line, true );
				if ( '' !== $path && ! in_array( $path, $extra, true ) ) {
					$extra[] = $path;
				}
			}

			$rules[ $folder ]['extra'] = array_slice( $extra, 0, 50 );
			Plugin_Speed_Report_Plugin::save_rules( $rules );
			wp_safe_redirect( self::tab_url( 'fixes', array( 'psr_notice' => 'fix_saved' ) ) );
			exit;
		}

		wp_safe_redirect( self::tab_url( 'fixes' ) );
		exit;
	}

	/**
	 * A new fix for a plugin, from what the latest scan saw.
	 *
	 * @param string $folder Plugin folder.
	 * @return array<string,mixed>|null
	 */
	private static function build_rule( $folder ) {
		$run = Plugin_Speed_Report_Plugin::latest_run();
		$key = 'p:' . $folder;

		if ( null === $run ) {
			return null;
		}

		$row = null;
		foreach ( Plugin_Speed_Report_Report::owners( $run )['plugins'] as $candidate ) {
			if ( $candidate['key'] === $key ) {
				$row = $candidate;
				break;
			}
		}

		if ( null === $row ) {
			return null;
		}

		$list = function ( $kind ) use ( $run, $key ) {
			return isset( $run['meta'][ $kind ][ $key ] ) ? array_values( array_filter( (array) $run['meta'][ $kind ][ $key ], 'is_string' ) ) : array();
		};

		// Only types and taxonomies visitors can actually open.
		$types      = array_values( array_filter( $list( 'types' ), 'is_post_type_viewable' ) );
		$taxonomies = array_values( array_filter( $list( 'taxonomies' ), 'is_taxonomy_viewable' ) );

		return array(
			'keep'       => array_values( array_unique( $row['used_at'] ) ),
			'extra'      => array(),
			'types'      => $types,
			'taxonomies' => $taxonomies,
			'shortcodes' => $list( 'shortcodes' ),
			'blocks'     => $list( 'blocks' ),
			'preview'    => $row['unused'] ? $row['unused'][0]['path'] : '',
			'created'    => time(),
		);
	}

	/**
	 * Delete a saved scan.
	 *
	 * @return void
	 */
	public function handle_delete_run() {
		$id = isset( $_POST['run'] ) ? sanitize_key( wp_unslash( $_POST['run'] ) ) : '';

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'plugin-speed-report' ), 403 );
		}

		check_admin_referer( 'plugin_speed_report_delete_' . $id );

		Plugin_Speed_Report_Plugin::delete_run( $id );

		wp_safe_redirect( self::tab_url( 'compare', array( 'psr_notice' => 'run_deleted' ) ) );
		exit;
	}
}
