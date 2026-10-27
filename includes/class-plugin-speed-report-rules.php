<?php
/**
 * Front end: the "only load its CSS and JS where it's used" fixes.
 *
 * For each plugin with a fix, its stylesheets and scripts (the files in its
 * own folder that it registered with WordPress) are dequeued on pages that
 * don't use it. A page uses it when it was seen using it in a scan, is one
 * of its own post types or archives, has its shortcodes or blocks in the
 * content, rendered one of its blocks or shortcodes before the files were
 * printed, or is listed by hand.
 *
 * Files another plugin still needs stay: WordPress prints dependencies of
 * anything left in the queue regardless.
 *
 * @package Plugin_Speed_Report
 */

defined( 'ABSPATH' ) || exit;

/**
 * Asset fixes.
 */
final class Plugin_Speed_Report_Rules {

	/**
	 * Query argument for an admin's preview of a fix.
	 */
	const PREVIEW_ARG = 'psr_preview';

	/**
	 * Query argument carrying the preview nonce.
	 */
	const NONCE_ARG = 'psr_nonce';

	/**
	 * Query arguments page builders use for their editors.
	 */
	const BUILDER_ARGS = array( 'elementor-preview', 'fl_builder', 'et_fb', 'bricks', 'ct_builder', 'tve', 'vc_editable', 'brizy-edit-iframe', 'breakdance', 'oxygen' );

	/**
	 * Saved fixes.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private static $rules = array();

	/**
	 * Folders to unload on this request, once worked out.
	 *
	 * @var string[]|null
	 */
	private static $folders = null;

	/**
	 * Plugin being previewed, once verified.
	 *
	 * @var string
	 */
	private static $preview = '';

	/**
	 * Block namespaces and shortcode tags rendered so far on this request.
	 *
	 * @var array<string,bool>
	 */
	private static $rendered = array();

	/**
	 * Hook in, only when there's something to do.
	 *
	 * @return void
	 */
	public static function init() {
		self::$rules = Plugin_Speed_Report_Plugin::rules();

		if ( ! self::$rules && ! isset( $_GET[ self::PREVIEW_ARG ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified in preview_folder().
			return;
		}

		add_filter( 'render_block', array( __CLASS__, 'note_block' ), 10, 2 );
		add_filter( 'do_shortcode_tag', array( __CLASS__, 'note_shortcode' ), 10, 2 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'apply' ), PHP_INT_MAX );
		add_action( 'wp_print_styles', array( __CLASS__, 'apply' ), 1 );
		add_action( 'wp_print_scripts', array( __CLASS__, 'apply' ), 1 );
		add_action( 'wp_print_footer_scripts', array( __CLASS__, 'apply' ), 1 );
		add_action( 'wp_footer', array( __CLASS__, 'preview_banner' ), PHP_INT_MAX );
	}

	/**
	 * Remember a rendered block's namespace.
	 *
	 * @param string $content Block HTML.
	 * @param array  $block   Parsed block.
	 * @return string Unchanged.
	 */
	public static function note_block( $content, $block ) {
		if ( is_array( $block ) && ! empty( $block['blockName'] ) && is_string( $block['blockName'] ) ) {
			$namespace = strstr( $block['blockName'], '/', true );

			if ( false !== $namespace ) {
				self::$rendered[ 'block:' . $namespace ] = true;
			}
		}

		return $content;
	}

	/**
	 * Remember a rendered shortcode.
	 *
	 * @param string $output Shortcode output.
	 * @param string $tag    Tag.
	 * @return string Unchanged.
	 */
	public static function note_shortcode( $output, $tag ) {
		self::$rendered[ 'shortcode:' . $tag ] = true;

		return $output;
	}

	/**
	 * Dequeue the files of every plugin unloaded on this page.
	 *
	 * @return void
	 */
	public static function apply() {
		$folders = self::folders();

		if ( ! $folders ) {
			return;
		}

		$prefixes = array();
		foreach ( $folders as $folder ) {
			$path = wp_parse_url( plugins_url( $folder . '/' ), PHP_URL_PATH );
			if ( is_string( $path ) && '' !== $path ) {
				$prefixes[] = $path;
			}
		}

		foreach ( array( 'wp_scripts', 'wp_styles' ) as $global ) {
			if ( ! isset( $GLOBALS[ $global ] ) || ! $GLOBALS[ $global ] instanceof WP_Dependencies ) {
				continue;
			}

			$deps = $GLOBALS[ $global ];

			foreach ( (array) $deps->queue as $handle ) {
				if ( ! isset( $deps->registered[ $handle ] ) || ! is_string( $deps->registered[ $handle ]->src ) ) {
					continue;
				}

				$path = wp_parse_url( $deps->registered[ $handle ]->src, PHP_URL_PATH );

				if ( ! is_string( $path ) ) {
					continue;
				}

				foreach ( $prefixes as $prefix ) {
					if ( 0 === strpos( $path, $prefix ) ) {
						$deps->dequeue( $handle );
						break;
					}
				}
			}
		}
	}

	/**
	 * Plugin folders to unload on this request.
	 *
	 * Worked out once per request, but blocks and shortcodes rendered later
	 * (before the footer files print) can still put a plugin back.
	 *
	 * @return string[]
	 */
	private static function folders() {
		if ( null === self::$folders ) {
			self::$folders = array();

			if ( self::skip_request() ) {
				return self::$folders;
			}

			foreach ( self::$rules as $folder => $rule ) {
				if ( is_array( $rule ) && Plugin_Speed_Report_Plugin::is_plugin_folder( $folder ) && ! self::page_uses( $rule ) ) {
					self::$folders[] = $folder;
				}
			}

			$preview = self::preview_folder();
			if ( '' !== $preview && ! in_array( $preview, self::$folders, true ) ) {
				self::$folders[] = $preview;
			}
		}

		$folders = array();

		foreach ( self::$folders as $folder ) {
			if ( $folder === self::$preview || ! isset( self::$rules[ $folder ] ) || ! self::rendered_now( self::$rules[ $folder ] ) ) {
				$folders[] = $folder;
			}
		}

		return $folders;
	}

	/**
	 * Requests a fix must never touch: anything but a normal front-end page,
	 * and page builders' editors.
	 *
	 * @return bool
	 */
	private static function skip_request() {
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || is_feed() || is_embed() || is_customize_preview() ) {
			return true;
		}

		foreach ( self::BUILDER_ARGS as $arg ) {
			if ( isset( $_GET[ $arg ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Presence check only.
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether the current page uses a plugin, judged before rendering.
	 *
	 * @param array $rule Fix rule.
	 * @return bool
	 */
	private static function page_uses( array $rule ) {
		$path = self::current_path();

		foreach ( array_merge( (array) $rule['keep'], (array) $rule['extra'] ) as $keep ) {
			if ( self::path_matches( $path, (string) $keep ) ) {
				return true;
			}
		}

		$types = array_values( array_filter( (array) $rule['types'], 'is_string' ) );
		if ( $types && ( is_singular( $types ) || is_post_type_archive( $types ) ) ) {
			return true;
		}

		$taxonomies = array_values( array_filter( (array) $rule['taxonomies'], 'is_string' ) );
		if ( $taxonomies && is_tax( $taxonomies ) ) {
			return true;
		}

		if ( is_singular() ) {
			$post    = get_queried_object();
			$content = $post instanceof WP_Post ? (string) $post->post_content : '';

			// Synced patterns can hide any block: assume the plugin is needed.
			if ( false !== strpos( $content, '<!-- wp:block ' ) ) {
				return true;
			}

			foreach ( (array) $rule['blocks'] as $namespace ) {
				if ( is_string( $namespace ) && false !== strpos( $content, '<!-- wp:' . $namespace . '/' ) ) {
					return true;
				}
			}

			foreach ( (array) $rule['shortcodes'] as $tag ) {
				if ( is_string( $tag ) && has_shortcode( $content, $tag ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Whether one of the plugin's blocks or shortcodes has rendered on this
	 * request so far (block themes render the whole page before the head).
	 *
	 * @param array $rule Fix rule.
	 * @return bool
	 */
	private static function rendered_now( array $rule ) {
		if ( ! self::$rendered ) {
			return false;
		}

		foreach ( (array) $rule['blocks'] as $namespace ) {
			if ( isset( self::$rendered[ 'block:' . $namespace ] ) ) {
				return true;
			}
		}

		foreach ( (array) $rule['shortcodes'] as $tag ) {
			if ( isset( self::$rendered[ 'shortcode:' . $tag ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The requested path relative to the home page, lowercased, without the
	 * query string: "/", "/contact/".
	 *
	 * @return string
	 */
	private static function current_path() {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only compared, never output.
		$path = wp_parse_url( $uri, PHP_URL_PATH );
		$path = is_string( $path ) ? $path : '/';
		$home = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$home = is_string( $home ) ? untrailingslashit( $home ) : '';

		if ( '' !== $home && 0 === strpos( $path . '/', $home . '/' ) ) {
			$path = substr( $path, strlen( $home ) );
		}

		return strtolower( '/' . ltrim( $path, '/' ) );
	}

	/**
	 * Whether a path matches a kept path ("/shop/", or "/shop/*").
	 *
	 * @param string $path Current path.
	 * @param string $keep Kept path.
	 * @return bool
	 */
	private static function path_matches( $path, $keep ) {
		$keep = strtolower( (string) strtok( $keep, '?' ) );

		if ( '' === $keep ) {
			return false;
		}

		if ( '*' === substr( $keep, -1 ) ) {
			return 0 === strpos( $path, substr( $keep, 0, -1 ) );
		}

		return untrailingslashit( $path ) === untrailingslashit( $keep );
	}

	/**
	 * Folder an admin is previewing without its files, if the link is valid.
	 *
	 * @return string
	 */
	private static function preview_folder() {
		if ( ! isset( $_GET[ self::PREVIEW_ARG ], $_GET[ self::NONCE_ARG ] ) ) {
			return '';
		}

		$folder = sanitize_text_field( wp_unslash( $_GET[ self::PREVIEW_ARG ] ) );
		$nonce  = sanitize_text_field( wp_unslash( $_GET[ self::NONCE_ARG ] ) );

		if ( ! current_user_can( 'manage_options' ) || ! Plugin_Speed_Report_Plugin::is_plugin_folder( $folder ) || ! wp_verify_nonce( $nonce, 'psr_preview_' . $folder ) ) {
			return '';
		}

		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}

		self::$preview = $folder;

		return $folder;
	}

	/**
	 * Preview link for a plugin on a page.
	 *
	 * @param string $folder Plugin folder.
	 * @param string $path   Page path.
	 * @return string
	 */
	public static function preview_url( $folder, $path ) {
		return add_query_arg(
			array(
				self::PREVIEW_ARG => rawurlencode( $folder ),
				self::NONCE_ARG   => wp_create_nonce( 'psr_preview_' . $folder ),
			),
			Plugin_Speed_Report_Plugin::url_for_path( $path )
		);
	}

	/**
	 * A small bar telling the admin they're looking at a preview.
	 *
	 * @return void
	 */
	public static function preview_banner() {
		if ( '' === self::$preview ) {
			return;
		}

		$name = self::$preview;

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		foreach ( get_plugins() as $file => $data ) {
			if ( 0 === strpos( $file, self::$preview . '/' ) ) {
				$name = $data['Name'];
				break;
			}
		}

		$close = remove_query_arg( array( self::PREVIEW_ARG, self::NONCE_ARG ) );
		?>
		<div id="psr-preview-bar" role="status" style="position:fixed;z-index:999999;left:50%;bottom:16px;transform:translateX(-50%);max-width:calc(100% - 32px);box-sizing:border-box;display:flex;flex-wrap:wrap;align-items:center;gap:6px 14px;padding:10px 16px;border-radius:8px;background:#1d2327;color:#fff;font:14px/1.4 -apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;box-shadow:0 6px 24px rgba(0,0,0,.25)">
			<span>
				<?php
				/* translators: %s: plugin name. */
				printf( esc_html__( 'Preview: this page without %s\'s CSS and JS. Check it still looks and works right.', 'plugin-speed-report' ), '<strong>' . esc_html( $name ) . '</strong>' );
				?>
			</span>
			<a href="<?php echo esc_url( $close ); ?>" style="color:#72aee6"><?php esc_html_e( 'Close preview', 'plugin-speed-report' ); ?></a>
		</div>
		<?php
	}
}
