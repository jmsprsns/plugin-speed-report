<?php
/**
 * Times one test request, plugin by plugin.
 *
 * The scanner loads each of your key pages with a single-use token. On that
 * request, and only that one, this class wraps every hook callback, shortcode,
 * block renderer, widget and class autoloader in a timer, so each millisecond,
 * database query and enqueued file is put down to the plugin whose code ran
 * it. Time a plugin spends inside another plugin's hook counts for the inner
 * plugin only, so nothing is counted twice.
 *
 * This file stands alone: the must-use loader includes it before regular
 * plugins load, so their load times count too.
 *
 * @package Plugin_Speed_Report
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'Plugin_Speed_Report_Profiler', false ) ) {
	return;
}

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound, WordPress.Files.FileName.InvalidClassFileName -- The loader includes this one file on its own.

/**
 * A callback wrapped in the profiler's timer. WordPress calls it exactly like
 * the original, with the same arguments, and gets the same return value.
 */
final class Plugin_Speed_Report_Timed {

	/**
	 * The original callback.
	 *
	 * @var callable
	 */
	public $callback;

	/**
	 * Owner key, e.g. "p:woocommerce".
	 *
	 * @var string
	 */
	public $owner;

	/**
	 * What was wrapped: hook, shortcode, block or autoload.
	 *
	 * @var string
	 */
	public $kind;

	/**
	 * Hook name, shortcode tag or block name.
	 *
	 * @var string
	 */
	public $context;

	/**
	 * Constructor.
	 *
	 * @param callable $callback Original callback.
	 * @param string   $owner    Owner key.
	 * @param string   $kind     What was wrapped.
	 * @param string   $context  Hook name, tag or block name.
	 */
	public function __construct( $callback, $owner, $kind, $context ) {
		$this->callback = $callback;
		$this->owner    = $owner;
		$this->kind     = $kind;
		$this->context  = $context;
	}

	/**
	 * Run the original callback inside the timer.
	 *
	 * @param mixed ...$args Arguments WordPress passed.
	 * @return mixed
	 */
	public function __invoke( ...$args ) {
		$profiler = Plugin_Speed_Report_Profiler::$instance;

		if ( null === $profiler ) {
			return call_user_func_array( $this->callback, $args );
		}

		return $profiler->run( $this, $args );
	}
}

/**
 * Per-plugin timer for a single test request.
 */
final class Plugin_Speed_Report_Profiler {

	/**
	 * Option holding the hash and expiry of the one valid token.
	 */
	const TOKEN_OPTION = 'plugin_speed_report_token';

	/**
	 * Option the measurements are written to for the scanner to collect.
	 */
	const RESULT_OPTION = 'plugin_speed_report_sample';

	/**
	 * Query argument carrying the token.
	 */
	const QUERY_ARG = 'psr_profile';

	/**
	 * File name of the must-use loader.
	 */
	const LOADER_FILE = '0-plugin-speed-report.php';

	/**
	 * Filters whose callbacks change what visitors see in the page body.
	 */
	const CONTENT_FILTERS = array(
		'the_content'          => true,
		'the_excerpt'          => true,
		'render_block'         => true,
		'widget_text'          => true,
		'widget_text_content'  => true,
		'widget_block_content' => true,
		'wp_nav_menu_items'    => true,
		'comment_text'         => true,
	);

	/**
	 * Hooks after which shortcodes and block renderers are (re)wrapped.
	 */
	const WRAP_EXTRAS_ON = array(
		'wp'          => true,
		'loop_start'  => true,
		'wp_head'     => true,
		'the_content' => true,
	);

	/**
	 * The running profiler, or null on every normal request.
	 *
	 * @var Plugin_Speed_Report_Profiler|null
	 */
	public static $instance = null;

	/**
	 * "mu" when started by the loader, "plugin" when started late by this
	 * plugin's own main file (plugins before it aren't timed then).
	 *
	 * @var string
	 */
	private $mode;

	/**
	 * When timing started (microtime).
	 *
	 * @var float
	 */
	private $started;

	/**
	 * When the current owner's stretch started.
	 *
	 * @var float
	 */
	private $since;

	/**
	 * Owner whose code is running now.
	 *
	 * @var string
	 */
	private $current = 'core';

	/**
	 * Owners interrupted by the current one; the first is the ambient owner.
	 *
	 * @var string[]
	 */
	private $stack = array();

	/**
	 * Seconds per owner.
	 *
	 * @var array<string,float>
	 */
	private $time = array();

	/**
	 * Wrapped calls per owner.
	 *
	 * @var array<string,int>
	 */
	private $calls = array();

	/**
	 * Wrapped calls each owner made into other code.
	 *
	 * @var array<string,int>
	 */
	private $parent_calls = array();

	/**
	 * Hooks fired while each owner was running.
	 *
	 * @var array<string,int>
	 */
	private $fires = array();

	/**
	 * Database queries per owner.
	 *
	 * @var array<string,int>
	 */
	private $queries = array();

	/**
	 * Database seconds per owner.
	 *
	 * @var array<string,float>
	 */
	private $query_time = array();

	/**
	 * Why each owner counts as used on this page.
	 *
	 * @var array<string,string>
	 */
	private $used = array();

	/**
	 * Inline CSS and JS each owner printed itself.
	 *
	 * @var array<string,array<string,string>>
	 */
	private $inline = array();

	/**
	 * Who enqueued each script and style handle.
	 *
	 * @var array<string,array<string,string>>
	 */
	private $enqueued = array(
		'script' => array(),
		'style'  => array(),
	);

	/**
	 * Post types and taxonomies registered by each owner.
	 *
	 * @var array<string,array<string,string[]>>
	 */
	private $registered = array(
		'post_type' => array(),
		'taxonomy'  => array(),
	);

	/**
	 * Block names rendered on the page.
	 *
	 * @var array<string,int>
	 */
	private $blocks_rendered = array();

	/**
	 * Owner per callback key (false: leave the callback alone).
	 *
	 * @var array<string,string|false>
	 */
	private $owners = array();

	/**
	 * Hook callback IDs that can't be wrapped.
	 *
	 * @var array<string,bool>
	 */
	private $skip = array();

	/**
	 * True once the results are written.
	 *
	 * @var bool
	 */
	private $finished = false;

	/**
	 * Seconds of the profiler's own work kept out of every owner's time.
	 *
	 * @var float
	 */
	private $excluded = 0.0;

	/**
	 * Calibrated cost of one hook firing (the "all" dispatch).
	 *
	 * @var float
	 */
	private $cost_fire = 0.0;

	/**
	 * Calibrated cost a wrapped call adds to the callback's own time.
	 *
	 * @var float
	 */
	private $cost_child = 0.0;

	/**
	 * Calibrated cost a wrapped call adds to the caller's time.
	 *
	 * @var float
	 */
	private $cost_parent = 0.0;

	/**
	 * Must-use plugin files, in load order.
	 *
	 * @var string[]
	 */
	private $mu_files = array();

	/**
	 * Network-activated plugin files, in load order.
	 *
	 * @var string[]
	 */
	private $network_files = array();

	/**
	 * Active plugin files, in load order.
	 *
	 * @var string[]|null
	 */
	private $plugin_files = null;

	/**
	 * Index of the next plugin to load.
	 *
	 * @var int
	 */
	private $next_plugin = 0;

	/**
	 * Waiting for the extra_plugin_headers filter that WordPress 6.7+ fires
	 * just before including each plugin.
	 *
	 * @var bool
	 */
	private $await_header = false;

	/**
	 * Set when that filter didn't come (older WordPress).
	 *
	 * @var bool
	 */
	private $no_header_marker = false;

	/**
	 * Shortcode and block counts at the last wrap, to skip needless passes.
	 *
	 * @var string
	 */
	private $extras_signature = '';

	/**
	 * The widget being displayed, as an open frame.
	 *
	 * @var array<string,mixed>|null
	 */
	private $widget = null;

	/**
	 * Whether WordPress keeps query timings (SAVEQUERIES).
	 *
	 * @var bool
	 */
	private $save_queries = false;

	/**
	 * Mu-plugins that loaded before the profiler and weren't timed.
	 *
	 * @var string[]
	 */
	private $missed_mu = array();

	/**
	 * Start profiling if this request carries a valid token.
	 *
	 * Runs on every request the loader sees, so the common case (no token)
	 * returns after one isset().
	 *
	 * @param string $mode "mu" or "plugin".
	 * @return void
	 */
	public static function maybe_start( $mode ) {
		if ( null !== self::$instance || ! isset( $_GET[ self::QUERY_ARG ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Authenticated by the single-use token below.
			return;
		}

		$token = $_GET[ self::QUERY_ARG ]; // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Checked against a strict pattern, then the stored hash.

		if ( ! is_string( $token ) || 1 !== preg_match( '/^[A-Za-z0-9]{32}$/D', $token ) ) {
			return;
		}

		if ( ! self::claim_token( $token ) ) {
			return;
		}

		self::$instance = new self( $mode );
	}

	/**
	 * Check the token against the stored hash and use it up.
	 *
	 * Reads and deletes the row directly, past any object cache, so a token
	 * works exactly once even with several requests in flight.
	 *
	 * @param string $token Token from the URL.
	 * @return bool
	 */
	private static function claim_token( $token ) {
		global $wpdb;

		if ( ! isset( $wpdb ) ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Must bypass caches; see above.
		$raw  = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::TOKEN_OPTION ) );
		$data = is_string( $raw ) ? json_decode( $raw, true ) : null;

		if ( ! is_array( $data ) || empty( $data['hash'] ) || empty( $data['expires'] ) || ! is_string( $data['hash'] ) ) {
			return false;
		}

		if ( (int) $data['expires'] < time() || ! hash_equals( $data['hash'], hash( 'sha256', $token ) ) ) {
			return false;
		}

		// Only the request that deletes the row gets to profile.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->delete( $wpdb->options, array( 'option_name' => self::TOKEN_OPTION ) );

		return 1 === (int) $deleted;
	}

	/**
	 * Set everything up. Only runs on a request with a valid token.
	 *
	 * @param string $mode "mu" or "plugin".
	 */
	private function __construct( $mode ) {
		$this->mode = $mode;

		// The scanner needs a fresh, uncached page, and nothing else should
		// run in the background of a timed request.
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		if ( ! defined( 'DISABLE_WP_CRON' ) ) {
			define( 'DISABLE_WP_CRON', true );
		}
		if ( ! defined( 'SAVEQUERIES' ) ) {
			define( 'SAVEQUERIES', true );
		}
		$this->save_queries = (bool) SAVEQUERIES;

		// Our own buffer, so printed output can be measured per callback.
		ob_start();

		if ( 'mu' === $mode ) {
			$this->mu_files = self::normalize_list( wp_get_mu_plugins() );
			$loader         = wp_normalize_path( WPMU_PLUGIN_DIR . '/' . self::LOADER_FILE );
			$position       = array_search( $loader, $this->mu_files, true );

			if ( false !== $position ) {
				$this->missed_mu = array_slice( $this->mu_files, 0, (int) $position );
			}

			if ( function_exists( 'wp_get_active_network_plugins' ) && is_multisite() ) {
				$this->network_files = self::normalize_list( wp_get_active_network_plugins() );
			}
		}

		$this->calibrate();

		add_action( 'mu_plugin_loaded', array( $this, 'on_mu_plugin_loaded' ), PHP_INT_MIN );
		add_action( 'network_plugin_loaded', array( $this, 'on_network_plugin_loaded' ), PHP_INT_MIN );
		add_action( 'muplugins_loaded', array( $this, 'on_muplugins_loaded' ), PHP_INT_MIN );
		add_action( 'plugin_loaded', array( $this, 'on_plugin_loaded' ), PHP_INT_MIN );
		add_action( 'plugins_loaded', array( $this, 'on_plugins_loaded' ), PHP_INT_MIN );
		add_action( 'setup_theme', array( $this, 'on_setup_theme' ), PHP_INT_MAX );
		add_action( 'after_setup_theme', array( $this, 'on_after_setup_theme' ), PHP_INT_MIN );
		add_filter( 'template_include', array( $this, 'on_template_include' ), PHP_INT_MAX );
		add_action( 'shutdown', array( $this, 'on_shutdown' ), PHP_INT_MIN );
		add_action( 'send_headers', 'nocache_headers' );

		add_action( 'registered_post_type', array( $this, 'on_registered_post_type' ) );
		add_action( 'registered_taxonomy', array( $this, 'on_registered_taxonomy' ) );
		add_filter( 'render_block', array( $this, 'on_render_block' ), PHP_INT_MAX, 2 );
		add_action( 'dynamic_sidebar', array( $this, 'on_dynamic_sidebar' ), PHP_INT_MIN );
		add_action( 'dynamic_sidebar_after', array( $this, 'close_widget' ), PHP_INT_MIN );

		if ( $this->save_queries ) {
			add_filter( 'log_query_custom_data', array( $this, 'on_log_query' ), 10, 3 );
		} else {
			add_filter( 'query', array( $this, 'on_query' ) );
		}

		if ( 'plugin' === $mode ) {
			// Started from this plugin's main file: its include is still
			// running, and the plugins after it haven't loaded yet.
			$this->plugin_files = self::normalize_list( wp_get_active_and_valid_plugins() );
		}

		register_shutdown_function( array( $this, 'finish' ) );

		$this->started = microtime( true );
		$this->since   = $this->started;
	}

	/**
	 * Measure what the timers themselves cost, so it can be taken back out.
	 *
	 * Also registers the "all" handler, which stays on for the request.
	 *
	 * @return void
	 */
	private function calibrate() {
		$hook  = 'plugin_speed_report_calibrate';
		$noop  = 'plugin_speed_report_calibrate_noop';
		$count = 400;

		// Warm up, then time a hook with no callbacks, before "all" exists.
		for ( $i = 0; $i < 50; $i++ ) {
			apply_filters( $hook, 0 );
		}
		$t = microtime( true );
		for ( $i = 0; $i < $count; $i++ ) {
			apply_filters( $hook, 0 );
		}
		$bare_fire = microtime( true ) - $t;

		// A hook with one cheap core callback, still unwrapped.
		add_filter( $noop, 'absint' );
		$t = microtime( true );
		for ( $i = 0; $i < $count; $i++ ) {
			apply_filters( $noop, 0 );
		}
		$bare_call = microtime( true ) - $t;

		add_filter( 'all', array( $this, 'on_all' ) );

		$this->since = microtime( true );
		for ( $i = 0; $i < 50; $i++ ) {
			apply_filters( $hook, 0 );
			apply_filters( $noop, 0 );
		}

		$this->excluded = 0.0;
		$t              = microtime( true );
		for ( $i = 0; $i < $count; $i++ ) {
			apply_filters( $hook, 0 );
		}
		$with_fire       = microtime( true ) - $t;
		$this->cost_fire = max( 0.0, ( $with_fire - $bare_fire - $this->excluded ) / $count );

		// The callback itself, called directly: that much is real work.
		$args = array( 0 );
		$t    = microtime( true );
		for ( $i = 0; $i < $count; $i++ ) {
			call_user_func_array( 'absint', $args );
		}
		$pure = microtime( true ) - $t;

		// Now through the wrapper, with the loop's time going to a separate
		// owner so only the callback's share lands on "core".
		$this->excluded = 0.0;
		$this->time     = array();
		$this->current  = 'calibrate';
		$this->since    = microtime( true );
		$t              = microtime( true );
		for ( $i = 0; $i < $count; $i++ ) {
			apply_filters( $noop, 0 );
		}
		$with_call = microtime( true ) - $t;
		$child     = isset( $this->time['core'] ) ? $this->time['core'] : 0.0;

		$this->cost_child  = max( 0.0, ( $child - $pure ) / $count );
		$this->cost_parent = max( 0.0, ( $with_call - $bare_call - $this->excluded - ( $child - $pure ) ) / $count - $this->cost_fire );

		remove_all_filters( $noop );

		$this->time         = array();
		$this->calls        = array();
		$this->parent_calls = array();
		$this->fires        = array();
		$this->excluded     = 0.0;
		$this->current      = 'core';
		$this->stack        = array();
	}

	/**
	 * Runs before every hook: wrap any callbacks not yet wrapped.
	 *
	 * @param string $hook_name Hook being fired.
	 * @return void
	 */
	public function on_all( $hook_name ) {
		$t = microtime( true );

		if ( $this->finished ) {
			return;
		}

		if ( isset( $this->fires[ $this->current ] ) ) {
			++$this->fires[ $this->current ];
		} else {
			$this->fires[ $this->current ] = 1;
		}

		$switched = false;

		if ( $this->await_header && 'extra_plugin_headers' === $hook_name ) {
			$this->start_next_plugin();
			$switched = true;
		} elseif ( isset( self::WRAP_EXTRAS_ON[ $hook_name ] ) ) {
			$this->wrap_extras();
		}

		global $wp_filter;

		if ( isset( $wp_filter[ $hook_name ] ) && is_object( $wp_filter[ $hook_name ] ) ) {
			$hook = $wp_filter[ $hook_name ];

			foreach ( $hook->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $id => $callback ) {
					if ( ! isset( $callback['function'] ) || $callback['function'] instanceof Plugin_Speed_Report_Timed || isset( $this->skip[ $id ] ) ) {
						continue;
					}

					$timed = $this->wrap( $callback['function'], 'hook', (string) $hook_name );

					if ( null === $timed ) {
						$this->skip[ $id ] = true;
						continue;
					}

					$hook->callbacks[ $priority ][ $id ]['function'] = $timed;
				}
			}
		}

		$spent           = microtime( true ) - $t;
		$this->excluded += $spent;

		if ( $switched ) {
			$this->since = microtime( true );
		} else {
			$this->since += $spent;
		}
	}

	/**
	 * Run a wrapped callback, crediting its time to its owner.
	 *
	 * @param Plugin_Speed_Report_Timed $timed Wrapped callback.
	 * @param array                     $args  Arguments.
	 * @return mixed
	 *
	 * @throws Throwable Whatever the callback threw, unchanged.
	 */
	public function run( Plugin_Speed_Report_Timed $timed, array $args ) {
		if ( $this->finished ) {
			return call_user_func_array( $timed->callback, $args );
		}

		$enter  = microtime( true );
		$parent = $this->current;
		$owner  = $timed->owner;

		$this->credit( $parent, $enter - $this->since );
		self::bump( $this->parent_calls, $parent );
		self::bump( $this->calls, $owner );
		$this->stack[] = $parent;
		$this->current = $owner;

		// Queues are watched for everyone (so it's known when WordPress itself
		// asked for a file); printed output only for plugins and the theme.
		$watch   = 'core' !== $owner;
		$scripts = self::queue_count( 'wp_scripts' );
		$styles  = self::queue_count( 'wp_styles' );

		if ( $watch ) {
			$level  = ob_get_level();
			$length = ob_get_length();
			$input  = ( 'hook' === $timed->kind && isset( self::CONTENT_FILTERS[ $timed->context ] ) && isset( $args[0] ) && is_string( $args[0] ) ) ? $args[0] : null;
		}

		$this->excluded += microtime( true ) - $enter;
		$this->since     = microtime( true );
		$error           = null;
		$value           = null;

		try {
			$value = call_user_func_array( $timed->callback, $args );
		} catch ( Throwable $e ) {
			$error = $e;
		}

		$leave = microtime( true );
		$this->credit( $owner, $leave - $this->since );

		if ( null === $error ) {
			$this->note_enqueued( 'wp_scripts', 'script', $scripts, $owner );
			$this->note_enqueued( 'wp_styles', 'style', $styles, $owner );

			if ( $watch ) {
				$this->inspect( $timed, $level, $length, $input, $value );
			}
		}

		$this->current = array_pop( $this->stack );
		$this->since   = microtime( true );

		$this->excluded += $this->since - $leave;

		if ( null !== $error ) {
			throw $error;
		}

		return $value;
	}

	/**
	 * Look at what a plugin's or the theme's callback just did: output it
	 * printed and content it added.
	 *
	 * @param Plugin_Speed_Report_Timed $timed  Wrapped callback.
	 * @param int                       $level  Output buffer level before.
	 * @param int|false                 $length Buffer length before.
	 * @param string|null               $input  Content passed to a content filter.
	 * @param mixed                     $value  Return value.
	 * @return void
	 */
	private function inspect( Plugin_Speed_Report_Timed $timed, $level, $length, $input, $value ) {
		$owner = $timed->owner;

		if ( false !== $length && ob_get_level() === $level ) {
			$now = ob_get_length();

			if ( false !== $now && $now > $length ) {
				$this->note_output( $owner, (string) substr( (string) ob_get_contents(), $length ), 'output' );
			}
		}

		if ( 'theme' === $owner || isset( $this->used[ $owner ] ) ) {
			return;
		}

		if ( 'shortcode' === $timed->kind || 'block' === $timed->kind ) {
			if ( is_string( $value ) && '' !== trim( $value ) ) {
				$this->used[ $owner ] = $timed->kind . ':' . $timed->context;
			}
		} elseif ( null !== $input && is_string( $value ) && $value !== $input ) {
			if ( self::visible_size( $value ) > self::visible_size( $input ) ) {
				$this->used[ $owner ] = 'content:' . $timed->context;
			}
		}
	}

	/**
	 * Record handles a callback added to a dependency queue.
	 *
	 * @param string $global Global name (wp_scripts or wp_styles).
	 * @param string $type   script or style.
	 * @param int    $before Queue length before the callback.
	 * @param string $owner  Owner of the callback.
	 * @return void
	 */
	private function note_enqueued( $global, $type, $before, $owner ) {
		if ( self::queue_count( $global ) <= $before ) {
			return;
		}

		foreach ( array_slice( $GLOBALS[ $global ]->queue, $before ) as $handle ) {
			if ( is_string( $handle ) && ! isset( $this->enqueued[ $type ][ $handle ] ) ) {
				$this->enqueued[ $type ][ $handle ] = $owner;
			}
		}
	}

	/**
	 * Record printed output: inline CSS and JS, and whether it shows anything.
	 *
	 * @param string $owner  Owner key.
	 * @param string $html   What was printed.
	 * @param string $reason Reason recorded if it's visible.
	 * @return void
	 */
	private function note_output( $owner, $html, $reason ) {
		if ( '' === $html ) {
			return;
		}

		$own_js = 0;

		if ( false !== stripos( $html, '<script' ) || false !== stripos( $html, '<style' ) ) {
			if ( preg_match_all( '#<(script|style)\b([^>]*)>(.*?)</\1\s*>#is', $html, $blocks, PREG_SET_ORDER ) ) {
				foreach ( $blocks as $block ) {
					$type = strtolower( $block[1] );

					// Handles WordPress printed are counted from the page itself.
					if ( preg_match( '/\bid\s*=\s*["\']?[^"\'\s>]+-(?:js|css|js-extra|js-after|js-before|js-translations|inline-css)["\'\s>]/i', $block[2] . '>' ) ) {
						continue;
					}
					if ( 'script' === $type && preg_match( '/\b(?:src\s*=|type\s*=\s*["\']?(?:application\/(?:ld\+)?json|text\/template|text\/html|importmap|speculationrules))/i', $block[2] ) ) {
						continue;
					}

					$key = 'script' === $type ? 'js' : 'css';

					if ( 'js' === $key ) {
						$own_js += strlen( $block[3] );
					}

					if ( ! isset( $this->inline[ $owner ][ $key ] ) ) {
						$this->inline[ $owner ][ $key ] = '';
					}
					$this->inline[ $owner ][ $key ] .= $block[3];
				}
			}
		}

		if ( 'theme' === $owner || isset( $this->used[ $owner ] ) ) {
			return;
		}

		// A plugin printing a real script of its own (a cookie banner built
		// in JavaScript, say) is doing something on this page. A one-line
		// snippet like WooCommerce's no-js class swap isn't.
		if ( $own_js >= 300 ) {
			$this->used[ $owner ] = 'script';
			return;
		}

		// Markup in a <template> is UI that JavaScript shows, so it counts.
		$visible = preg_replace(
			array(
				'#<(script|style|noscript)\b[^>]*>.*?</\1\s*>#is',
				'#<!--.*?-->#s',
				'#<(?:link|meta)\b[^>]*>#i',
				'#<input\b[^>]*\btype\s*=\s*["\']?hidden[^>]*>#i',
			),
			'',
			$html
		);

		if ( is_string( $visible ) && self::visible_size( $visible ) > 0 ) {
			$this->used[ $owner ] = $reason;
		}
	}

	/**
	 * Rough size of what a visitor would see in some HTML: its text plus
	 * a fixed amount per image, video, form control and so on.
	 *
	 * @param string $html HTML.
	 * @return int
	 */
	private static function visible_size( $html ) {
		$html  = (string) preg_replace( '#</?template\b[^>]*>#i', '', $html );
		$text  = trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( $html ) ) );
		$media = preg_match_all( '/<(?:img|svg|iframe|video|audio|canvas|button|input|select|textarea|picture|object|embed)\b/i', $html );

		return strlen( $text ) + 10 * (int) $media;
	}

	/**
	 * Wrap a callback, or return null to leave it alone.
	 *
	 * @param callable $callback Callback.
	 * @param string   $kind     What it is.
	 * @param string   $context  Hook name, tag or block name.
	 * @return Plugin_Speed_Report_Timed|null
	 */
	private function wrap( $callback, $kind, $context ) {
		$owner = $this->owner_of_callback( $callback );

		if ( false === $owner ) {
			return null;
		}

		return new Plugin_Speed_Report_Timed( $callback, $owner, $kind, $context );
	}

	/**
	 * Which plugin, theme or core file a callback lives in.
	 *
	 * Returns false for callbacks that are better left alone: our own, ones
	 * that can't be inspected, and ones taking arguments by reference (a
	 * wrapper would pass copies).
	 *
	 * @param mixed $callback Callback.
	 * @return string|false
	 */
	private function owner_of_callback( $callback ) {
		if ( is_string( $callback ) ) {
			$key = $callback;
		} elseif ( is_object( $callback ) ) {
			$key = spl_object_hash( $callback );
		} elseif ( is_array( $callback ) && isset( $callback[0], $callback[1] ) && is_string( $callback[1] ) ) {
			$key = ( is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0] ) . '::' . $callback[1];
		} else {
			return false;
		}

		if ( isset( $this->owners[ $key ] ) ) {
			return $this->owners[ $key ];
		}

		$owner = false;

		try {
			$target = $callback;

			if ( is_string( $target ) && false !== strpos( $target, '::' ) ) {
				$target = explode( '::', $target, 2 );
			}

			if ( is_array( $target ) ) {
				if ( 2 !== count( $target ) || ! isset( $target[0], $target[1] ) || false !== strpos( (string) $target[1], '::' ) ) {
					$this->owners[ $key ] = false;
					return false;
				}

				$class = new ReflectionClass( $target[0] );
				$file  = $class->getFileName();

				if ( $class->hasMethod( $target[1] ) ) {
					$function = $class->getMethod( $target[1] );
				} elseif ( $class->hasMethod( '__call' ) || $class->hasMethod( '__callStatic' ) ) {
					$function = null;
				} else {
					$this->owners[ $key ] = false;
					return false;
				}
			} elseif ( is_string( $target ) || $target instanceof Closure ) {
				$function = new ReflectionFunction( $target );
				$file     = $function->getFileName();
			} elseif ( is_object( $target ) && method_exists( $target, '__invoke' ) ) {
				$class    = new ReflectionClass( $target );
				$function = $class->getMethod( '__invoke' );
				$file     = $class->getFileName();
			} else {
				$this->owners[ $key ] = false;
				return false;
			}

			$by_reference = false;

			if ( null !== $function ) {
				foreach ( $function->getParameters() as $parameter ) {
					if ( $parameter->isPassedByReference() ) {
						$by_reference = true;
						break;
					}
				}
			}

			if ( ! $by_reference ) {
				$owner = $file ? self::owner_of_file( $file ) : 'core';
				$owner = 'self' === $owner ? false : $owner;
			}
		} catch ( ReflectionException $e ) {
			$owner = false;
		}

		$this->owners[ $key ] = $owner;

		return $owner;
	}

	/**
	 * Wrap shortcode handlers and block renderers. They're called directly,
	 * not through hooks, so "all" never sees them.
	 *
	 * @return void
	 */
	private function wrap_extras() {
		global $shortcode_tags;

		$blocks    = class_exists( 'WP_Block_Type_Registry' ) ? WP_Block_Type_Registry::get_instance()->get_all_registered() : array();
		$signature = ( is_array( $shortcode_tags ) ? count( $shortcode_tags ) : 0 ) . '/' . count( $blocks );

		if ( $signature === $this->extras_signature ) {
			return;
		}
		$this->extras_signature = $signature;

		if ( is_array( $shortcode_tags ) ) {
			foreach ( $shortcode_tags as $tag => $callback ) {
				if ( $callback instanceof Plugin_Speed_Report_Timed ) {
					continue;
				}
				$timed = $this->wrap( $callback, 'shortcode', (string) $tag );
				if ( null !== $timed ) {
					$shortcode_tags[ $tag ] = $timed; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Same handler, timed; test request only.
				}
			}
		}

		foreach ( $blocks as $name => $block_type ) {
			if ( ! is_object( $block_type ) || empty( $block_type->render_callback ) || $block_type->render_callback instanceof Plugin_Speed_Report_Timed ) {
				continue;
			}
			$timed = $this->wrap( $block_type->render_callback, 'block', (string) $name );
			if ( null !== $timed ) {
				$block_type->render_callback = $timed;
			}
		}
	}

	/**
	 * Wrap the registered class autoloaders, keeping their order.
	 *
	 * @return void
	 */
	private function wrap_autoloaders() {
		$loaders = spl_autoload_functions();

		if ( ! is_array( $loaders ) || ! $loaders ) {
			return;
		}

		$wrapped = array();
		$changed = false;

		foreach ( $loaders as $loader ) {
			$timed = $loader instanceof Plugin_Speed_Report_Timed ? null : $this->wrap( $loader, 'autoload', 'autoload' );

			if ( null !== $timed ) {
				$changed = true;
			}
			$wrapped[] = null === $timed ? $loader : $timed;
		}

		if ( ! $changed ) {
			return;
		}

		try {
			foreach ( $loaders as $loader ) {
				spl_autoload_unregister( $loader );
			}
			foreach ( $wrapped as $loader ) {
				spl_autoload_register( $loader );
			}
		} catch ( Throwable $e ) {
			// Put the originals back exactly as they were.
			foreach ( (array) spl_autoload_functions() as $loader ) {
				spl_autoload_unregister( $loader );
			}
			foreach ( $loaders as $loader ) {
				spl_autoload_register( $loader );
			}
		}
	}

	/**
	 * Add time to an owner.
	 *
	 * @param string $owner   Owner key.
	 * @param float  $seconds Seconds.
	 * @return void
	 */
	private function credit( $owner, $seconds ) {
		if ( isset( $this->time[ $owner ] ) ) {
			$this->time[ $owner ] += $seconds;
		} else {
			$this->time[ $owner ] = $seconds;
		}
	}

	/**
	 * Increment a counter.
	 *
	 * @param array  $counter Counters by key.
	 * @param string $key     Key.
	 * @return void
	 */
	private static function bump( array &$counter, $key ) {
		if ( isset( $counter[ $key ] ) ) {
			++$counter[ $key ];
		} else {
			$counter[ $key ] = 1;
		}
	}

	/**
	 * Length of a dependency queue, 0 before it exists.
	 *
	 * @param string $global wp_scripts or wp_styles.
	 * @return int
	 */
	private static function queue_count( $global ) {
		return ( isset( $GLOBALS[ $global ] ) && $GLOBALS[ $global ] instanceof WP_Dependencies && is_array( $GLOBALS[ $global ]->queue ) ) ? count( $GLOBALS[ $global ]->queue ) : 0;
	}

	/**
	 * Change the owner of time spent outside any callback (loading a plugin
	 * file, the theme's templates, WordPress itself).
	 *
	 * @param string $owner Owner key.
	 * @return void
	 */
	private function set_ambient( $owner ) {
		if ( 'self' === $owner ) {
			$owner = 'core';
		}

		if ( $this->stack ) {
			$this->stack[0] = $owner;
			return;
		}

		$now = microtime( true );
		$this->credit( $this->current, $now - $this->since );
		$this->current = $owner;
		$this->since   = $now;
	}

	/**
	 * Owner of the file after $file in a load-order list, or core.
	 *
	 * @param string[] $files Files in load order.
	 * @param string   $file  The one that just loaded.
	 * @return string
	 */
	private static function next_owner( array $files, $file ) {
		$position = array_search( wp_normalize_path( (string) $file ), $files, true );

		if ( false !== $position && isset( $files[ $position + 1 ] ) ) {
			return self::owner_of_file( $files[ $position + 1 ] );
		}

		return 'core';
	}

	/**
	 * Normalize a list of file paths.
	 *
	 * @param mixed $files Paths.
	 * @return string[]
	 */
	private static function normalize_list( $files ) {
		return array_values( array_map( 'wp_normalize_path', array_filter( (array) $files, 'is_string' ) ) );
	}

	/**
	 * A must-use plugin finished loading.
	 *
	 * @param string $file Its file.
	 * @return void
	 */
	public function on_mu_plugin_loaded( $file ) {
		$this->set_ambient( self::next_owner( $this->mu_files, $file ) );
	}

	/**
	 * A network-activated plugin finished loading.
	 *
	 * @param string $file Its file.
	 * @return void
	 */
	public function on_network_plugin_loaded( $file ) {
		$this->set_ambient( self::next_owner( $this->network_files, $file ) );
	}

	/**
	 * Must-use plugins are done; regular plugins come next.
	 *
	 * @return void
	 */
	public function on_muplugins_loaded() {
		$this->set_ambient( 'core' );

		if ( null === $this->plugin_files ) {
			$this->plugin_files = self::normalize_list( wp_get_active_and_valid_plugins() );
		}

		$this->next_plugin = 0;
		$this->expect_plugin();
	}

	/**
	 * A regular plugin finished loading.
	 *
	 * @param string $file Its file.
	 * @return void
	 */
	public function on_plugin_loaded( $file ) {
		if ( $this->await_header ) {
			// The marker never came, so this WordPress doesn't fire it.
			$this->no_header_marker = true;
		}

		$this->set_ambient( 'core' );

		if ( null === $this->plugin_files ) {
			return;
		}

		$position = array_search( wp_normalize_path( (string) $file ), $this->plugin_files, true );

		if ( false !== $position ) {
			$this->next_plugin = (int) $position + 1;
		}

		$this->expect_plugin();
	}

	/**
	 * Get ready to time the next plugin's include.
	 *
	 * @return void
	 */
	private function expect_plugin() {
		$this->await_header = false;

		if ( ! isset( $this->plugin_files[ $this->next_plugin ] ) ) {
			return;
		}

		if ( $this->no_header_marker || version_compare( $GLOBALS['wp_version'], '6.7', '<' ) ) {
			$this->start_next_plugin();
		} else {
			$this->await_header = true;
		}
	}

	/**
	 * The next plugin's include is starting now.
	 *
	 * @return void
	 */
	private function start_next_plugin() {
		$this->await_header = false;

		if ( isset( $this->plugin_files[ $this->next_plugin ] ) ) {
			$this->set_ambient( self::owner_of_file( $this->plugin_files[ $this->next_plugin ] ) );
		}
	}

	/**
	 * All plugins loaded: wrap their autoloaders.
	 *
	 * @return void
	 */
	public function on_plugins_loaded() {
		$this->await_header = false;
		$this->set_ambient( 'core' );

		$t = microtime( true );
		$this->wrap_autoloaders();
		$this->since += microtime( true ) - $t;
	}

	/**
	 * The theme's functions.php is about to load.
	 *
	 * @return void
	 */
	public function on_setup_theme() {
		$this->set_ambient( 'theme' );
	}

	/**
	 * The theme is loaded.
	 *
	 * @return void
	 */
	public function on_after_setup_theme() {
		$this->set_ambient( 'core' );

		$t = microtime( true );
		$this->wrap_autoloaders();
		$this->since += microtime( true ) - $t;
	}

	/**
	 * The page template is about to render: time outside callbacks from
	 * here on is the theme's.
	 *
	 * @param string $template Template file.
	 * @return string Unchanged.
	 */
	public function on_template_include( $template ) {
		$this->set_ambient( 'theme' );

		return $template;
	}

	/**
	 * WordPress is shutting down.
	 *
	 * @return void
	 */
	public function on_shutdown() {
		$this->close_widget();
		$this->set_ambient( 'core' );
	}

	/**
	 * Note who registered a post type.
	 *
	 * @param string $post_type Post type.
	 * @return void
	 */
	public function on_registered_post_type( $post_type ) {
		$this->note_registered( 'post_type', $post_type );
	}

	/**
	 * Note who registered a taxonomy.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @return void
	 */
	public function on_registered_taxonomy( $taxonomy ) {
		$this->note_registered( 'taxonomy', $taxonomy );
	}

	/**
	 * Record a registration against the owner running now.
	 *
	 * @param string $type post_type or taxonomy.
	 * @param string $name Name.
	 * @return void
	 */
	private function note_registered( $type, $name ) {
		if ( 'core' === $this->current || 'theme' === $this->current || ! is_string( $name ) ) {
			return;
		}

		$this->registered[ $type ][ $name ] = $this->current;
	}

	/**
	 * Count rendered blocks by name.
	 *
	 * @param string $content Block HTML.
	 * @param array  $block   Parsed block.
	 * @return string Unchanged.
	 */
	public function on_render_block( $content, $block ) {
		if ( is_array( $block ) && ! empty( $block['blockName'] ) && is_string( $block['blockName'] ) ) {
			self::bump( $this->blocks_rendered, $block['blockName'] );
		}

		return $content;
	}

	/**
	 * A widget is about to display: time it as a frame of its own.
	 *
	 * @param array $widget Registered widget.
	 * @return void
	 */
	public function on_dynamic_sidebar( $widget ) {
		$this->close_widget();

		if ( ! is_array( $widget ) || empty( $widget['callback'] ) ) {
			return;
		}

		$callback = $widget['callback'];

		// Widgets are objects of the plugin's own class extending WP_Widget.
		if ( is_array( $callback ) && isset( $callback[0] ) && is_object( $callback[0] ) ) {
			$callback = array( $callback[0], '__construct' );
		}

		$owner = $this->owner_of_callback( $callback );

		if ( false === $owner ) {
			return;
		}

		$now = microtime( true );
		$this->credit( $this->current, $now - $this->since );
		$this->stack[] = $this->current;
		$this->current = $owner;
		$this->since   = $now;
		$this->widget  = array(
			'owner'  => $owner,
			'level'  => ob_get_level(),
			'length' => ob_get_length(),
			'depth'  => count( $this->stack ),
		);
	}

	/**
	 * Close the open widget frame, if any.
	 *
	 * @return void
	 */
	public function close_widget() {
		if ( null === $this->widget ) {
			return;
		}

		$widget       = $this->widget;
		$this->widget = null;

		// Only close it if nothing else is still open above it.
		if ( count( $this->stack ) !== $widget['depth'] || $this->current !== $widget['owner'] ) {
			return;
		}

		$now = microtime( true );
		$this->credit( $this->current, $now - $this->since );

		if ( false !== $widget['length'] && ob_get_level() === $widget['level'] ) {
			$length = ob_get_length();
			if ( false !== $length && $length > $widget['length'] ) {
				$this->note_output( $widget['owner'], (string) substr( (string) ob_get_contents(), $widget['length'] ), 'widget' );
			}
		}

		$this->current = array_pop( $this->stack );
		$this->since   = microtime( true );
	}

	/**
	 * Count a logged query against the owner running now (SAVEQUERIES on).
	 *
	 * @param array  $data  Custom query data.
	 * @param string $query SQL.
	 * @param float  $time  Seconds.
	 * @return array Unchanged.
	 */
	public function on_log_query( $data, $query, $time ) {
		self::bump( $this->queries, $this->current );

		if ( isset( $this->query_time[ $this->current ] ) ) {
			$this->query_time[ $this->current ] += (float) $time;
		} else {
			$this->query_time[ $this->current ] = (float) $time;
		}

		return $data;
	}

	/**
	 * Count a query (SAVEQUERIES defined as false, so no timings).
	 *
	 * @param string $query SQL.
	 * @return string Unchanged.
	 */
	public function on_query( $query ) {
		self::bump( $this->queries, $this->current );

		return $query;
	}

	/**
	 * Stop the clock and write the results for the scanner.
	 *
	 * @return void
	 */
	public function finish() {
		if ( $this->finished ) {
			return;
		}

		$end = microtime( true );
		$this->credit( $this->current, $end - $this->since );
		$this->finished = true;
		self::$instance = null;

		remove_filter( 'all', array( $this, 'on_all' ) );

		global $wpdb;

		if ( ! isset( $wpdb ) ) {
			return;
		}

		$data = $this->results( $end );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Written past the object cache for the scanner's direct read.
		$wpdb->replace(
			$wpdb->options,
			array(
				'option_name'  => self::RESULT_OPTION,
				'option_value' => (string) wp_json_encode( $data ),
				'autoload'     => 'no',
			),
			array( '%s', '%s', '%s' )
		);
	}

	/**
	 * Everything the scanner needs from this request.
	 *
	 * @param float $end When the clock stopped.
	 * @return array<string,mixed>
	 */
	private function results( $end ) {
		$request_start = isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : $this->started;
		$bootstrap     = max( 0.0, $this->started - $request_start );

		$this->credit( 'core', $bootstrap );

		$owners = array();
		$total  = 0.0;

		foreach ( $this->time as $owner => $seconds ) {
			$overhead = ( isset( $this->calls[ $owner ] ) ? $this->calls[ $owner ] * $this->cost_child : 0 )
				+ ( isset( $this->parent_calls[ $owner ] ) ? $this->parent_calls[ $owner ] * $this->cost_parent : 0 )
				+ ( isset( $this->fires[ $owner ] ) ? $this->fires[ $owner ] * $this->cost_fire : 0 );

			$seconds = max( 0.0, $seconds - $overhead );
			$total  += $seconds;

			$owners[ $owner ] = array(
				'ms'    => round( $seconds * 1000, 2 ),
				'calls' => isset( $this->calls[ $owner ] ) ? $this->calls[ $owner ] : 0,
			);
		}

		foreach ( $this->queries as $owner => $count ) {
			if ( ! isset( $owners[ $owner ] ) ) {
				$owners[ $owner ] = array(
					'ms'    => 0,
					'calls' => 0,
				);
			}
			$owners[ $owner ]['q']   = $count;
			$owners[ $owner ]['qms'] = isset( $this->query_time[ $owner ] ) ? round( $this->query_time[ $owner ] * 1000, 2 ) : null;
		}

		unset( $owners['self'] );

		// Inline CSS and JS each owner printed, with compressed sizes.
		$inline = array();
		foreach ( $this->inline as $owner => $types ) {
			foreach ( $types as $type => $code ) {
				$inline[ $owner ][ $type ] = array(
					'bytes' => strlen( $code ),
					'gz'    => self::gzip_size( $code ),
				);
			}
		}

		$blocks    = $this->block_owners();
		$used      = $this->used;
		$post_type = '';
		$taxonomy  = '';

		foreach ( $this->blocks_rendered as $name => $count ) {
			$namespace = strstr( $name, '/', true );
			if ( false !== $namespace && isset( $blocks['namespaces'][ $namespace ] ) ) {
				$owner = $blocks['namespaces'][ $namespace ];
				if ( ! isset( $used[ $owner ] ) ) {
					$used[ $owner ] = 'block:' . $name;
				}
			}
		}

		// The page itself being one of a plugin's post types or terms.
		if ( function_exists( 'get_queried_object' ) ) {
			$object = get_queried_object();

			if ( $object instanceof WP_Post ) {
				$post_type = $object->post_type;
			} elseif ( $object instanceof WP_Post_Type ) {
				$post_type = $object->name;
			} elseif ( $object instanceof WP_Term ) {
				$taxonomy = $object->taxonomy;
			}

			if ( '' !== $post_type && isset( $this->registered['post_type'][ $post_type ] ) ) {
				$owner = $this->registered['post_type'][ $post_type ];
				if ( ! isset( $used[ $owner ] ) ) {
					$used[ $owner ] = 'post_type:' . $post_type;
				}
			}

			if ( '' !== $taxonomy && isset( $this->registered['taxonomy'][ $taxonomy ] ) ) {
				$owner = $this->registered['taxonomy'][ $taxonomy ];
				if ( ! isset( $used[ $owner ] ) ) {
					$used[ $owner ] = 'taxonomy:' . $taxonomy;
				}
			}
		}

		unset( $used['self'], $used['core'], $used['theme'] );

		$error = error_get_last();
		$fatal = is_array( $error ) && in_array( $error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ), true );

		return array(
			'format'     => 1,
			'mode'       => $this->mode,
			'status'     => (int) http_response_code(),
			'fatal'      => $fatal,
			'total_ms'   => round( $total * 1000, 2 ),
			'wall_ms'    => round( ( $end - $request_start ) * 1000, 2 ),
			'owners'     => $owners,
			'used'       => $used,
			'inline'     => $inline,
			'handles'    => $this->handles(),
			'shortcodes' => $this->shortcode_owners(),
			'blocks'     => $blocks['by_owner'],
			'types'      => self::group_by_owner( $this->registered['post_type'] ),
			'taxonomies' => self::group_by_owner( $this->registered['taxonomy'] ),
			'queried'    => array(
				'post_type' => $post_type,
				'taxonomy'  => $taxonomy,
			),
			'cache'      => function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache(),
			'timed_q'    => $this->save_queries,
			'memory'     => memory_get_peak_usage(),
			'missed_mu'  => array_map( 'basename', $this->missed_mu ),
		);
	}

	/**
	 * Printed script and style handles: where each lives, what it needs, who
	 * queued it, and whether it was queued directly or only as a dependency.
	 *
	 * @return array<string,array<string,array<string,mixed>>>
	 */
	private function handles() {
		$handles = array(
			'script' => array(),
			'style'  => array(),
		);

		foreach ( array(
			'script' => 'wp_scripts',
			'style'  => 'wp_styles',
		) as $type => $global ) {
			if ( ! isset( $GLOBALS[ $global ] ) || ! $GLOBALS[ $global ] instanceof WP_Dependencies ) {
				continue;
			}

			$deps   = $GLOBALS[ $global ];
			$queued = array_flip( array_filter( (array) $deps->queue, 'is_string' ) );

			foreach ( (array) $deps->done as $handle ) {
				if ( ! is_string( $handle ) || ! isset( $deps->registered[ $handle ] ) ) {
					continue;
				}

				$item = $deps->registered[ $handle ];

				$handles[ $type ][ $handle ] = array(
					'src'  => is_string( $item->src ) ? $item->src : '',
					'deps' => array_values( array_filter( (array) $item->deps, 'is_string' ) ),
					'by'   => isset( $this->enqueued[ $type ][ $handle ] ) ? $this->enqueued[ $type ][ $handle ] : '',
					'q'    => isset( $queued[ $handle ] ),
				);
			}
		}

		return $handles;
	}

	/**
	 * Shortcode tags by owner.
	 *
	 * @return array<string,string[]>
	 */
	private function shortcode_owners() {
		global $shortcode_tags;

		$owners = array();

		foreach ( (array) $shortcode_tags as $tag => $callback ) {
			$owner = $callback instanceof Plugin_Speed_Report_Timed ? $callback->owner : $this->owner_of_callback( $callback );

			if ( is_string( $owner ) && 'core' !== $owner ) {
				$owners[ $owner ][] = (string) $tag;
			}
		}

		return $owners;
	}

	/**
	 * Block namespaces by owner, from each block's renderer or its files.
	 *
	 * @return array{namespaces: array<string,string>, by_owner: array<string,string[]>}
	 */
	private function block_owners() {
		$namespaces = array();

		if ( class_exists( 'WP_Block_Type_Registry' ) ) {
			foreach ( WP_Block_Type_Registry::get_instance()->get_all_registered() as $name => $block_type ) {
				$namespace = strstr( (string) $name, '/', true );

				if ( false === $namespace || 'core' === $namespace || isset( $namespaces[ $namespace ] ) ) {
					continue;
				}

				$owner = false;

				if ( $block_type->render_callback instanceof Plugin_Speed_Report_Timed ) {
					$owner = $block_type->render_callback->owner;
				} elseif ( ! empty( $block_type->render_callback ) ) {
					$owner = $this->owner_of_callback( $block_type->render_callback );
				}

				if ( ! is_string( $owner ) || 'core' === $owner ) {
					$owner = self::block_owner_from_files( $block_type );
				}

				if ( is_string( $owner ) && 'core' !== $owner ) {
					$namespaces[ $namespace ] = $owner;
				}
			}
		}

		$by_owner = array();
		foreach ( $namespaces as $namespace => $owner ) {
			$by_owner[ $owner ][] = $namespace;
		}

		return array(
			'namespaces' => $namespaces,
			'by_owner'   => $by_owner,
		);
	}

	/**
	 * Owner of a block from where its scripts and styles live.
	 *
	 * @param WP_Block_Type $block_type Block type.
	 * @return string|false
	 */
	private static function block_owner_from_files( $block_type ) {
		$handles = array();

		foreach ( array( 'editor_script_handles', 'script_handles', 'view_script_handles', 'style_handles', 'editor_style_handles', 'view_style_handles', 'editor_script', 'script', 'view_script', 'style', 'editor_style' ) as $property ) {
			if ( isset( $block_type->$property ) ) {
				foreach ( (array) $block_type->$property as $handle ) {
					if ( is_string( $handle ) ) {
						$handles[] = $handle;
					}
				}
			}
		}

		foreach ( $handles as $handle ) {
			foreach ( array( 'wp_scripts', 'wp_styles' ) as $global ) {
				if ( isset( $GLOBALS[ $global ]->registered[ $handle ] ) && is_string( $GLOBALS[ $global ]->registered[ $handle ]->src ) ) {
					$owner = self::owner_of_url( $GLOBALS[ $global ]->registered[ $handle ]->src );

					if ( 0 === strpos( $owner, 'p:' ) || 0 === strpos( $owner, 'mu:' ) ) {
						return $owner;
					}
				}
			}
		}

		return false;
	}

	/**
	 * Turn name => owner into owner => names.
	 *
	 * @param array<string,string> $map Name to owner.
	 * @return array<string,string[]>
	 */
	private static function group_by_owner( array $map ) {
		$grouped = array();

		foreach ( $map as $name => $owner ) {
			$grouped[ $owner ][] = (string) $name;
		}

		return $grouped;
	}

	/**
	 * Gzip-compressed size of some text (about a third of it without zlib).
	 *
	 * @param string $text Text.
	 * @return int
	 */
	public static function gzip_size( $text ) {
		if ( '' === $text ) {
			return 0;
		}

		if ( function_exists( 'gzencode' ) ) {
			$compressed = gzencode( $text, 6 );

			if ( is_string( $compressed ) ) {
				return strlen( $compressed );
			}
		}

		return (int) ceil( strlen( $text ) / 3 );
	}

	/**
	 * Key folders, normalized with a trailing slash.
	 *
	 * @return array<string,string>
	 */
	private static function dirs() {
		static $dirs = null;

		if ( null === $dirs ) {
			$dirs = array(
				'self'    => trailingslashit( wp_normalize_path( dirname( __DIR__ ) ) ),
				'loader'  => wp_normalize_path( WPMU_PLUGIN_DIR . '/' . self::LOADER_FILE ),
				'plugins' => trailingslashit( wp_normalize_path( WP_PLUGIN_DIR ) ),
				'mu'      => trailingslashit( wp_normalize_path( WPMU_PLUGIN_DIR ) ),
				'themes'  => trailingslashit( wp_normalize_path( WP_CONTENT_DIR . '/themes' ) ),
			);
		}

		return $dirs;
	}

	/**
	 * Owner key for a PHP file: "p:folder" (or "p:file.php" for a single-file
	 * plugin), "mu:name", "theme", "self" or "core".
	 *
	 * @param string $file Absolute path.
	 * @return string
	 */
	public static function owner_of_file( $file ) {
		static $cache = array();

		if ( isset( $cache[ $file ] ) ) {
			return $cache[ $file ];
		}

		$dirs = self::dirs();
		$path = wp_normalize_path( (string) $file );

		// Plugins symlinked into the plugins folder report their real path.
		if ( ! empty( $GLOBALS['wp_plugin_paths'] ) && is_array( $GLOBALS['wp_plugin_paths'] ) ) {
			foreach ( $GLOBALS['wp_plugin_paths'] as $plugin_dir => $real_dir ) {
				$real_dir = trailingslashit( wp_normalize_path( $real_dir ) );
				if ( 0 === strpos( $path, $real_dir ) ) {
					$path = trailingslashit( wp_normalize_path( $plugin_dir ) ) . substr( $path, strlen( $real_dir ) );
					break;
				}
			}
		}

		if ( 0 === strpos( $path, $dirs['self'] ) || $path === $dirs['loader'] ) {
			$owner = 'self';
		} elseif ( 0 === strpos( $path, $dirs['plugins'] ) ) {
			$owner = 'p:' . self::first_segment( substr( $path, strlen( $dirs['plugins'] ) ) );
		} elseif ( 0 === strpos( $path, $dirs['mu'] ) ) {
			$owner = 'mu:' . self::first_segment( substr( $path, strlen( $dirs['mu'] ) ) );
		} elseif ( 0 === strpos( $path, $dirs['themes'] ) || self::in_theme_dir( $path ) ) {
			$owner = 'theme';
		} else {
			$owner = 'core';
		}

		$cache[ $file ] = $owner;

		return $owner;
	}

	/**
	 * Whether a path is in an extra registered theme folder.
	 *
	 * @param string $path Normalized path.
	 * @return bool
	 */
	private static function in_theme_dir( $path ) {
		if ( empty( $GLOBALS['wp_theme_directories'] ) || ! is_array( $GLOBALS['wp_theme_directories'] ) ) {
			return false;
		}

		foreach ( $GLOBALS['wp_theme_directories'] as $dir ) {
			if ( is_string( $dir ) && 0 === strpos( $path, trailingslashit( wp_normalize_path( $dir ) ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * First path segment.
	 *
	 * @param string $rest Path relative to a base folder.
	 * @return string
	 */
	private static function first_segment( $rest ) {
		$rest  = ltrim( (string) $rest, '/' );
		$slash = strpos( $rest, '/' );

		return false === $slash ? $rest : substr( $rest, 0, $slash );
	}

	/**
	 * URL path prefixes of the site's code folders, longest first.
	 *
	 * @return array<int,array<string,string>>
	 */
	public static function url_bases() {
		static $bases = null;

		if ( null !== $bases ) {
			return $bases;
		}

		$bases   = array();
		$uploads = wp_get_upload_dir();
		$folders = array(
			array( plugins_url(), WP_PLUGIN_DIR, 'plugins' ),
			array( WPMU_PLUGIN_URL, WPMU_PLUGIN_DIR, 'mu' ),
			array( get_theme_root_uri(), get_theme_root(), 'theme' ),
			array( includes_url(), ABSPATH . WPINC, 'core' ),
			array( site_url( 'wp-admin/' ), ABSPATH . 'wp-admin', 'core' ),
			array( isset( $uploads['baseurl'] ) ? $uploads['baseurl'] : '', isset( $uploads['basedir'] ) ? $uploads['basedir'] : '', 'uploads' ),
			array( content_url(), WP_CONTENT_DIR, 'content' ),
		);

		foreach ( $folders as $folder ) {
			$path = wp_parse_url( (string) $folder[0], PHP_URL_PATH );

			if ( ! is_string( $path ) || '' === $folder[1] ) {
				continue;
			}

			$bases[] = array(
				'path' => trailingslashit( $path ),
				'dir'  => trailingslashit( wp_normalize_path( $folder[1] ) ),
				'kind' => $folder[2],
			);
		}

		usort(
			$bases,
			function ( $a, $b ) {
				return strlen( $b['path'] ) - strlen( $a['path'] );
			}
		);

		return $bases;
	}

	/**
	 * Find which code folder a URL points into.
	 *
	 * Matches on the path alone, so files served through a CDN host still
	 * count for the plugin they came from.
	 *
	 * @param string $url URL or root-relative path.
	 * @return array{base: array<string,string>, rest: string}|null
	 */
	private static function match_url( $url ) {
		$path = wp_parse_url( (string) $url, PHP_URL_PATH );

		if ( ! is_string( $path ) || '' === $path ) {
			return null;
		}

		foreach ( self::url_bases() as $base ) {
			if ( 0 === strpos( $path, $base['path'] ) ) {
				return array(
					'base' => $base,
					'rest' => substr( $path, strlen( $base['path'] ) ),
				);
			}
		}

		return null;
	}

	/**
	 * Owner key for a script or stylesheet URL. Same keys as owner_of_file(),
	 * plus "other" (this site, outside any plugin or theme) and "external".
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function owner_of_url( $url ) {
		$match = self::match_url( $url );

		if ( null === $match ) {
			$host = wp_parse_url( (string) $url, PHP_URL_HOST );
			$home = wp_parse_url( home_url(), PHP_URL_HOST );

			return ( ! is_string( $host ) || '' === $host || strtolower( $host ) === strtolower( (string) $home ) ) ? 'other' : 'external';
		}

		$segment = self::first_segment( rawurldecode( $match['rest'] ) );

		switch ( $match['base']['kind'] ) {
			case 'plugins':
				return self::dirs()['self'] === $match['base']['dir'] . $segment . '/' ? 'self' : 'p:' . $segment;
			case 'mu':
				return 'mu:' . $segment;
			case 'theme':
				return 'theme';
			case 'core':
				return 'core';
			case 'uploads':
				// Files a plugin generates under uploads/<its folder>/ (page
				// builders' CSS, for example) belong to that plugin.
				if ( '' !== $segment && 1 === preg_match( '/^[A-Za-z0-9._-]+$/D', $segment ) && is_dir( self::dirs()['plugins'] . $segment ) ) {
					return 'p:' . $segment;
				}
				return 'other';
		}

		return 'other';
	}

	/**
	 * The file on disk behind a script or stylesheet URL, or '' when it
	 * isn't a .css or .js file inside one of the site's code folders.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function local_file( $url ) {
		$match = self::match_url( $url );

		if ( null === $match ) {
			return '';
		}

		$rest = rawurldecode( $match['rest'] );

		if ( ! preg_match( '/\.(?:css|js|mjs)$/iD', $rest ) || false !== strpos( $rest, "\0" ) ) {
			return '';
		}

		$base = realpath( $match['base']['dir'] );
		$file = realpath( $match['base']['dir'] . $rest );

		if ( false === $base || false === $file || ! is_file( $file ) ) {
			return '';
		}

		$base = trailingslashit( wp_normalize_path( $base ) );
		$file = wp_normalize_path( $file );

		// Never follow ../ or a symlink out of the folder.
		if ( 0 !== strpos( $file, $base ) || ! preg_match( '/\.(?:css|js|mjs)$/iD', $file ) ) {
			return '';
		}

		return $file;
	}
}
