<?php
/**
 * Runs a scan: loads each key page in the background (as a logged-out
 * visitor), collects the profiler's timings, weighs the CSS and JS in the
 * returned HTML, and saves the results.
 *
 * A scan is a series of short steps driven from the admin page, one page
 * load per step, so no request runs long enough to time out.
 *
 * @package Plugin_Speed_Report
 */

defined( 'ABSPATH' ) || exit;

/**
 * Scan runner.
 */
final class Plugin_Speed_Report_Scanner {

	/**
	 * Option holding the scan in progress.
	 */
	const STATE_OPTION = 'plugin_speed_report_scan';

	/**
	 * Option held while a step loads a page.
	 */
	const LOCK_OPTION = 'plugin_speed_report_lock';

	/**
	 * Most pages one scan tests.
	 */
	const MAX_PAGES = 12;

	/**
	 * Most automatically chosen pages.
	 */
	const MAX_AUTO = 10;

	/**
	 * Seconds after which an untouched scan counts as abandoned.
	 */
	const STALE = 120;

	/**
	 * Biggest page or file read, in bytes.
	 */
	const MAX_BYTES = 8388608;

	/**
	 * Pages picked automatically from the site's own content.
	 *
	 * @return array<string,array<string,string>> Keyed by page key.
	 */
	public static function candidate_pages() {
		$pages = array(
			'home' => array(
				'label' => __( 'Home page', 'plugin-speed-report' ),
				'path'  => '/',
			),
		);

		$static   = 'page' === get_option( 'show_on_front' );
		$front_id = $static ? (int) get_option( 'page_on_front' ) : 0;
		$posts_id = $static ? (int) get_option( 'page_for_posts' ) : 0;

		if ( $posts_id && 'publish' === get_post_status( $posts_id ) ) {
			self::add_post_page( $pages, 'blog', $posts_id, __( 'Blog page', 'plugin-speed-report' ) );
		}

		$latest = self::latest( 'post' );
		if ( $latest ) {
			self::add_post_page( $pages, 'post', $latest, __( 'Latest post', 'plugin-speed-report' ) );
		}

		$latest = self::latest( 'page', array_filter( array( $front_id, $posts_id ) ) );
		if ( $latest ) {
			self::add_post_page( $pages, 'page', $latest, __( 'A page', 'plugin-speed-report' ) );
		}

		foreach ( get_post_types(
			array(
				'public'   => true,
				'_builtin' => false,
			),
			'objects'
		) as $type ) {
			if ( count( $pages ) >= self::MAX_AUTO ) {
				break;
			}

			if ( ! empty( $type->has_archive ) ) {
				$link = get_post_type_archive_link( $type->name );
				$path = $link ? Plugin_Speed_Report_Plugin::sanitize_path( $link ) : '';

				if ( '' !== $path ) {
					$pages[ 'archive:' . $type->name ] = array(
						/* translators: %s: post type name, plural, e.g. "Products". */
						'label' => sprintf( __( '%s archive', 'plugin-speed-report' ), $type->labels->name ),
						'path'  => $path,
					);
				}
			}

			$latest = self::latest( $type->name );
			if ( $latest ) {
				self::add_post_page( $pages, 'type:' . $type->name, $latest, $type->labels->singular_name );
			}
		}

		if ( count( $pages ) < self::MAX_AUTO ) {
			$terms = get_terms(
				array(
					'taxonomy'   => 'category',
					'orderby'    => 'count',
					'order'      => 'DESC',
					'number'     => 1,
					'hide_empty' => true,
				)
			);

			if ( is_array( $terms ) && $terms && $terms[0] instanceof WP_Term ) {
				$link = get_term_link( $terms[0] );
				$path = is_string( $link ) ? Plugin_Speed_Report_Plugin::sanitize_path( $link ) : '';

				if ( '' !== $path ) {
					$pages['category'] = array(
						/* translators: %s: category name. */
						'label' => sprintf( __( 'Category: %s', 'plugin-speed-report' ), $terms[0]->name ),
						'path'  => $path,
					);
				}
			}
		}

		return array_slice( $pages, 0, self::MAX_AUTO, true );
	}

	/**
	 * Newest published, unprotected item of a post type.
	 *
	 * @param string $post_type Post type.
	 * @param int[]  $exclude   Post IDs to skip.
	 * @return int Post ID or 0.
	 */
	private static function latest( $post_type, array $exclude = array() ) {
		$ids = get_posts(
			array(
				'post_type'        => $post_type,
				'post_status'      => 'publish',
				'has_password'     => false,
				'numberposts'      => 1,
				'orderby'          => 'date',
				'order'            => 'DESC',
				'post__not_in'     => $exclude, // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in -- At most two IDs.
				'fields'           => 'ids',
				'suppress_filters' => false,
			)
		);

		return $ids ? (int) $ids[0] : 0;
	}

	/**
	 * Add a post's page to the list.
	 *
	 * @param array<string,array<string,string>> $pages Pages so far.
	 * @param string                             $key   Page key.
	 * @param int                                $id    Post ID.
	 * @param string                             $kind  What it is, e.g. "Latest post".
	 * @return void
	 */
	private static function add_post_page( array &$pages, $key, $id, $kind ) {
		$link = get_permalink( $id );
		$path = $link ? Plugin_Speed_Report_Plugin::sanitize_path( $link ) : '';

		if ( '' === $path || '/' === $path ) {
			return;
		}

		$title = wp_strip_all_tags( get_the_title( $id ) );

		$pages[ $key ] = array(
			'label' => '' === $title ? $kind : $kind . ': ' . $title,
			'path'  => $path,
		);
	}

	/**
	 * The pages a scan with these settings tests.
	 *
	 * @param array<string,mixed> $settings Plugin settings.
	 * @return array<int,array<string,string>>
	 */
	public static function pages_to_scan( array $settings ) {
		$pages = array();
		$paths = array();
		$skip  = array_flip( (array) $settings['skip'] );

		foreach ( self::candidate_pages() as $key => $page ) {
			if ( isset( $skip[ $key ] ) || isset( $paths[ $page['path'] ] ) ) {
				continue;
			}
			$paths[ $page['path'] ] = true;
			$pages[]                = array(
				'key'   => $key,
				'label' => $page['label'],
				'path'  => $page['path'],
			);
		}

		foreach ( (array) $settings['custom'] as $path ) {
			$path = Plugin_Speed_Report_Plugin::sanitize_path( $path );

			if ( '' === $path || isset( $paths[ $path ] ) ) {
				continue;
			}
			$paths[ $path ] = true;
			$post_id        = url_to_postid( Plugin_Speed_Report_Plugin::url_for_path( $path ) );
			$title          = $post_id ? wp_strip_all_tags( get_the_title( $post_id ) ) : '';
			$pages[]        = array(
				'key'   => 'custom:' . md5( $path ),
				'label' => '' !== $title ? $title : $path,
				'path'  => $path,
			);
		}

		return array_slice( $pages, 0, self::MAX_PAGES );
	}

	/**
	 * The scan in progress, if any.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function state() {
		$state = get_option( self::STATE_OPTION );

		return is_array( $state ) && isset( $state['id'], $state['step'], $state['total'] ) ? $state : null;
	}

	/**
	 * Whether a scan is running (and not abandoned).
	 *
	 * @return bool
	 */
	public static function is_running() {
		$state = self::state();

		return null !== $state && (int) $state['updated'] > time() - self::STALE;
	}

	/**
	 * Start a scan.
	 *
	 * @param Plugin_Speed_Report_Plugin $plugin Plugin instance.
	 * @return array<string,mixed>|WP_Error Progress, or why it can't start.
	 */
	public static function start( Plugin_Speed_Report_Plugin $plugin ) {
		if ( self::is_running() ) {
			return new WP_Error( 'running', __( 'A scan is already running. Wait for it to finish, or cancel it first.', 'plugin-speed-report' ) );
		}

		$settings = $plugin->get_settings();
		$pages    = self::pages_to_scan( $settings );

		if ( ! $pages ) {
			return new WP_Error( 'no_pages', __( 'There are no pages to test. Choose some under Pages.', 'plugin-speed-report' ) );
		}

		Plugin_Speed_Report_Loader::ensure();

		$samples = min( 5, max( 1, (int) $settings['samples'] ) );
		$state   = array(
			'id'      => strtolower( gmdate( 'ymdHis' ) . wp_generate_password( 4, false, false ) ),
			'user'    => get_current_user_id(),
			'started' => time(),
			'updated' => time(),
			'samples' => $samples,
			'pages'   => $pages,
			'step'    => 0,
			'total'   => count( $pages ) * ( $samples + 1 ),
			'results' => array(),
			'sizes'   => array(),
			'fixes'   => array_keys( Plugin_Speed_Report_Plugin::rules() ),
		);

		update_option( self::STATE_OPTION, $state, false );

		return self::progress( $state, __( 'Starting…', 'plugin-speed-report' ) );
	}

	/**
	 * Cancel the scan in progress.
	 *
	 * @return void
	 */
	public static function cancel() {
		delete_option( self::STATE_OPTION );
		delete_option( self::LOCK_OPTION );
		self::delete_row( Plugin_Speed_Report_Profiler::TOKEN_OPTION );
		self::delete_row( Plugin_Speed_Report_Profiler::RESULT_OPTION );
	}

	/**
	 * Run the next step: one page load.
	 *
	 * @param Plugin_Speed_Report_Plugin $plugin Plugin instance.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function step( Plugin_Speed_Report_Plugin $plugin ) {
		$state = self::state();

		if ( null === $state ) {
			return new WP_Error( 'no_scan', __( 'No scan is running. It may have been cancelled from another tab.', 'plugin-speed-report' ) );
		}

		$per   = (int) $state['samples'] + 1;
		$index = intdiv( (int) $state['step'], $per );

		if ( (int) $state['step'] >= (int) $state['total'] || ! isset( $state['pages'][ $index ] ) ) {
			return self::finish( $plugin, $state );
		}

		// One page load at a time, even with the report open in two tabs.
		if ( ! self::lock() ) {
			$progress         = self::progress( $state, __( 'Another tab is running this scan…', 'plugin-speed-report' ) );
			$progress['wait'] = true;

			return $progress;
		}

		$attempt = (int) $state['step'] % $per;
		$page    = $state['pages'][ $index ];

		if ( ! isset( $state['results'][ $index ] ) ) {
			$state['results'][ $index ] = array(
				'samples' => array(),
				'error'   => '',
			);
		}

		$result = $state['results'][ $index ];
		$sample = self::measure( Plugin_Speed_Report_Plugin::url_for_path( $page['path'] ), $state['sizes'] );

		if ( is_wp_error( $sample ) ) {
			$result['error'] = $sample->get_error_message();
			$state['step']   = ( $index + 1 ) * $per;
			/* translators: 1: page name, 2: what went wrong. */
			$message = sprintf( __( '%1$s: %2$s', 'plugin-speed-report' ), $page['label'], $result['error'] );
		} elseif ( 0 === $attempt ) {
			// The first load warms caches and is thrown away.
			++$state['step'];
			/* translators: %s: page name. */
			$message = sprintf( __( '%s: warming up', 'plugin-speed-report' ), $page['label'] );
		} else {
			$result['samples'][] = $sample['timing'];
			$result['assets']    = $sample['assets'];
			$result['meta']      = $sample['meta'];
			$result['html']      = $sample['html'];
			$result['used']      = isset( $result['used'] ) ? array_merge( $sample['used'], $result['used'] ) : $sample['used'];
			++$state['step'];
			/* translators: 1: page name, 2: sample number, 3: samples per page, 4: server time, e.g. "0.42 s". */
			$message = sprintf( __( '%1$s: run %2$d of %3$d, %4$s', 'plugin-speed-report' ), $page['label'], $attempt, $per - 1, Plugin_Speed_Report_Report::seconds( $sample['timing']['ms'] ) );
		}

		$state['results'][ $index ] = $result;
		$state['updated']           = time();

		// Cancelled from another tab while this page loaded.
		if ( null === self::state() ) {
			self::unlock();
			return new WP_Error( 'cancelled', __( 'The scan was cancelled.', 'plugin-speed-report' ) );
		}

		if ( (int) $state['step'] >= (int) $state['total'] ) {
			$done = self::finish( $plugin, $state );
			self::unlock();

			return $done;
		}

		update_option( self::STATE_OPTION, $state, false );
		self::unlock();

		return self::progress( $state, $message );
	}

	/**
	 * Take the step lock.
	 *
	 * @return bool False when another step holds it.
	 */
	private static function lock() {
		if ( add_option( self::LOCK_OPTION, time(), '', false ) ) {
			return true;
		}

		// A step that died mid-way (a timeout, say) doesn't hold it forever.
		if ( (int) get_option( self::LOCK_OPTION ) < time() - 90 ) {
			delete_option( self::LOCK_OPTION );

			return add_option( self::LOCK_OPTION, time(), '', false );
		}

		return false;
	}

	/**
	 * Release the step lock.
	 *
	 * @return void
	 */
	private static function unlock() {
		delete_option( self::LOCK_OPTION );
	}

	/**
	 * Progress for the admin page.
	 *
	 * @param array<string,mixed> $state   Scan state.
	 * @param string              $message Latest line.
	 * @return array<string,mixed>
	 */
	private static function progress( array $state, $message ) {
		return array(
			'done'    => false,
			'step'    => (int) $state['step'],
			'total'   => (int) $state['total'],
			'message' => $message,
		);
	}

	/**
	 * Load one page with the profiler on and collect what it measured.
	 *
	 * @param string $url   Page URL (always on this site).
	 * @param array  $sizes File size cache, updated in place.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function measure( $url, array &$sizes ) {
		$token = wp_generate_password( 32, false, false );

		self::delete_row( Plugin_Speed_Report_Profiler::RESULT_OPTION );
		self::write_row(
			Plugin_Speed_Report_Profiler::TOKEN_OPTION,
			(string) wp_json_encode(
				array(
					'hash'    => hash( 'sha256', $token ),
					'expires' => time() + 60,
				)
			)
		);

		$headers = array(
			'Cache-Control' => 'no-cache',
			'Pragma'        => 'no-cache',
		);

		// Sites behind HTTP authentication (staging sites) need it passed on,
		// the same way Site Health's loopback test does.
		if ( isset( $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'] ) ) {
			$headers['Authorization'] = 'Basic ' . base64_encode( wp_unslash( $_SERVER['PHP_AUTH_USER'] ) . ':' . wp_unslash( $_SERVER['PHP_AUTH_PW'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Credentials passed through unchanged.
		}

		$start    = microtime( true );
		$response = wp_remote_get(
			add_query_arg( Plugin_Speed_Report_Profiler::QUERY_ARG, $token, $url ),
			array(
				'timeout'             => 30,
				'redirection'         => 0,
				'sslverify'           => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's loopback filter.
				'headers'             => $headers,
				'user-agent'          => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36 PluginSpeedReport/' . PLUGIN_SPEED_REPORT_VERSION,
				'limit_response_size' => self::MAX_BYTES,
			)
		);
		$wall     = ( microtime( true ) - $start ) * 1000;

		$profile = is_wp_error( $response ) ? null : self::read_result();

		self::delete_row( Plugin_Speed_Report_Profiler::TOKEN_OPTION );
		self::delete_row( Plugin_Speed_Report_Profiler::RESULT_OPTION );

		if ( is_wp_error( $response ) ) {
			/* translators: %s: error message. */
			return new WP_Error( 'http', sprintf( __( 'couldn\'t load the page (%s).', 'plugin-speed-report' ), $response->get_error_message() ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( $code >= 300 && $code < 400 ) {
			$location = (string) wp_remote_retrieve_header( $response, 'location' );
			$location = '' === $location ? '' : remove_query_arg( Plugin_Speed_Report_Profiler::QUERY_ARG, $location );

			/* translators: %s: address it redirects to. */
			return new WP_Error( 'redirect', sprintf( __( 'it redirects to %s. Test that address instead.', 'plugin-speed-report' ), '' === $location ? __( 'another page', 'plugin-speed-report' ) : $location ) );
		}

		if ( $code >= 400 || $code < 200 ) {
			/* translators: %d: HTTP status code. */
			return new WP_Error( 'status', sprintf( __( 'the page returned an error (HTTP %d).', 'plugin-speed-report' ), $code ) );
		}

		if ( null === $profile ) {
			return new WP_Error( 'unmeasured', __( 'the page loaded but wasn\'t measured. A cache in front of WordPress (your host\'s or a CDN\'s) probably served a stored copy. Ask your host to skip caching for addresses containing "psr_profile".', 'plugin-speed-report' ) );
		}

		if ( ! empty( $profile['fatal'] ) ) {
			return new WP_Error( 'fatal', __( 'the page hit a PHP error while it was being measured.', 'plugin-speed-report' ) );
		}

		$html   = (string) wp_remote_retrieve_body( $response );
		$assets = self::weigh_assets( $html, $profile, $sizes );

		$owners  = array();
		$queries = 0;

		foreach ( (array) $profile['owners'] as $owner => $row ) {
			if ( ! is_array( $row ) || ! self::is_owner_key( $owner ) ) {
				continue;
			}
			$q                = isset( $row['q'] ) ? (int) $row['q'] : 0;
			$queries         += $q;
			$owners[ $owner ] = array(
				round( (float) $row['ms'], 2 ),
				$q,
				isset( $row['qms'] ) && null !== $row['qms'] ? round( (float) $row['qms'], 2 ) : null,
			);
		}

		$used = array();
		foreach ( (array) $profile['used'] as $owner => $reason ) {
			if ( self::is_owner_key( $owner ) && is_string( $reason ) ) {
				$used[ $owner ] = substr( $reason, 0, 120 );
			}
		}

		return array(
			'timing' => array(
				'ms'     => round( (float) $profile['total_ms'], 2 ),
				'wall'   => round( $wall, 1 ),
				'q'      => $queries,
				'owners' => $owners,
			),
			'assets' => $assets,
			'used'   => $used,
			'html'   => strlen( $html ),
			'meta'   => array(
				'mode'       => 'mu' === $profile['mode'] ? 'mu' : 'plugin',
				'cache'      => ! empty( $profile['cache'] ),
				'timed_q'    => ! empty( $profile['timed_q'] ),
				'missed_mu'  => array_values( array_filter( (array) $profile['missed_mu'], 'is_string' ) ),
				'shortcodes' => self::clean_owner_lists( $profile['shortcodes'] ),
				'blocks'     => self::clean_owner_lists( $profile['blocks'] ),
				'types'      => self::clean_owner_lists( $profile['types'] ),
				'taxonomies' => self::clean_owner_lists( $profile['taxonomies'] ),
			),
		);
	}

	/**
	 * Whether a string looks like an owner key the profiler produces.
	 *
	 * @param mixed $key Key.
	 * @return bool
	 */
	private static function is_owner_key( $key ) {
		return is_string( $key ) && 1 === preg_match( '/^(?:core|theme|other|external|(?:p|mu):[A-Za-z0-9._-]+)$/D', $key );
	}

	/**
	 * Keep only well-formed owner => [names] lists.
	 *
	 * @param mixed $lists Lists from the profiler.
	 * @return array<string,string[]>
	 */
	private static function clean_owner_lists( $lists ) {
		$clean = array();

		foreach ( (array) $lists as $owner => $names ) {
			if ( ! self::is_owner_key( $owner ) || ! is_array( $names ) ) {
				continue;
			}
			foreach ( $names as $name ) {
				if ( is_string( $name ) && 1 === preg_match( '/^[A-Za-z0-9_\-\/:.]{1,100}$/D', $name ) ) {
					$clean[ $owner ][] = $name;
				}
			}
		}

		return $clean;
	}

	/**
	 * Weigh every stylesheet and script on the page, by owner.
	 *
	 * @param string              $html    Page HTML.
	 * @param array<string,mixed> $profile Profiler results.
	 * @param array               $sizes   File size cache.
	 * @return array<string,array<string,mixed>>
	 */
	private static function weigh_assets( $html, array $profile, array &$sizes ) {
		$handles = array(
			'script' => isset( $profile['handles']['script'] ) && is_array( $profile['handles']['script'] ) ? $profile['handles']['script'] : array(),
			'style'  => isset( $profile['handles']['style'] ) && is_array( $profile['handles']['style'] ) ? $profile['handles']['style'] : array(),
		);
		$owners  = array(
			'script' => array(),
			'style'  => array(),
		);

		foreach ( $handles as $type => $list ) {
			foreach ( $list as $handle => $item ) {
				$owners[ $type ][ $handle ] = self::handle_owner( $item );
			}
		}

		$assets   = array();
		$head_end = stripos( $html, '</head' );
		$head_end = false === $head_end ? 0 : $head_end;

		if ( preg_match_all( '/<(script|link|style)\b([^>]*)>/i', $html, $tags, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			foreach ( $tags as $tag ) {
				$name    = strtolower( $tag[1][0] );
				$attrs   = self::attributes( $tag[2][0] );
				$end     = $tag[0][1] + strlen( $tag[0][0] );
				$in_head = $tag[0][1] < $head_end;
				$id      = isset( $attrs['id'] ) ? $attrs['id'] : '';

				if ( 'script' === $name ) {
					$type = isset( $attrs['type'] ) ? strtolower( trim( $attrs['type'] ) ) : '';

					// Data blocks, templates and scripts something else delays
					// aren't JavaScript the browser runs while the page loads.
					if ( '' !== $type && ! in_array( $type, array( 'text/javascript', 'application/javascript', 'module', 'text/ecmascript' ), true ) ) {
						continue;
					}

					if ( isset( $attrs['src'] ) && '' !== trim( $attrs['src'] ) ) {
						$handle = preg_match( '/^(.+)-js$/D', $id, $m ) ? $m[1] : '';
						$owner  = self::owner_for( $attrs['src'], $handle, 'script', $owners );
						$entry  = self::file_entry( $attrs['src'], 'js', $handle, $sizes );

						$entry['k'] = $in_head && ! isset( $attrs['async'] ) && ! isset( $attrs['defer'] ) && 'module' !== $type;
						self::add_asset( $assets, $owner, $entry );
					} else {
						$close = stripos( $html, '</script', $end );
						$code  = false === $close ? '' : substr( $html, $end, $close - $end );

						if ( '' !== trim( $code ) && preg_match( '/^(.+)-js-(?:extra|after|before|translations)$/D', $id, $m ) && isset( $owners['script'][ $m[1] ] ) ) {
							self::add_asset( $assets, $owners['script'][ $m[1] ], self::inline_entry( $code, 'js', $m[1], $in_head ) );
						}
					}
				} elseif ( 'link' === $name ) {
					$rel = isset( $attrs['rel'] ) ? ' ' . strtolower( $attrs['rel'] ) . ' ' : '';

					if ( false === strpos( $rel, ' stylesheet ' ) || empty( $attrs['href'] ) || isset( $attrs['disabled'] ) ) {
						continue;
					}

					$handle = preg_match( '/^(.+)-css$/D', $id, $m ) ? $m[1] : '';
					$owner  = self::owner_for( $attrs['href'], $handle, 'style', $owners );
					$entry  = self::file_entry( $attrs['href'], 'css', $handle, $sizes );
					$media  = isset( $attrs['media'] ) ? strtolower( $attrs['media'] ) : 'all';

					$entry['k'] = $in_head && 'print' !== $media;
					self::add_asset( $assets, $owner, $entry );
				} else {
					$close = stripos( $html, '</style', $end );
					$code  = false === $close ? '' : substr( $html, $end, $close - $end );

					if ( '' !== trim( $code ) && preg_match( '/^(.+)-inline-css$/D', $id, $m ) && isset( $owners['style'][ $m[1] ] ) ) {
						self::add_asset( $assets, $owners['style'][ $m[1] ], self::inline_entry( $code, 'css', $m[1], $in_head ) );
					}
				}
			}
		}

		// Inline code plugins printed themselves (not through WordPress).
		if ( isset( $profile['inline'] ) && is_array( $profile['inline'] ) ) {
			foreach ( $profile['inline'] as $owner => $types ) {
				if ( ! self::is_owner_key( $owner ) || ! is_array( $types ) ) {
					continue;
				}
				foreach ( array( 'css', 'js' ) as $type ) {
					if ( ! empty( $types[ $type ]['bytes'] ) ) {
						self::add_asset(
							$assets,
							$owner,
							array(
								'n' => '',
								't' => $type,
								'b' => (int) $types[ $type ]['bytes'],
								'g' => (int) $types[ $type ]['gz'],
								'h' => '',
								'i' => true,
							)
						);
					}
				}
			}
		}

		self::move_pulled_in( $assets, $handles, $owners );
		self::mark_removable( $assets, $handles );

		return self::total_assets( $assets );
	}

	/**
	 * Owner of a registered handle: the folder its file is in, else whoever
	 * enqueued it.
	 *
	 * @param mixed $item Handle details from the profiler.
	 * @return string
	 */
	private static function handle_owner( $item ) {
		$by = is_array( $item ) && isset( $item['by'] ) && self::is_owner_key( $item['by'] ) ? $item['by'] : '';

		if ( is_array( $item ) && ! empty( $item['src'] ) && is_string( $item['src'] ) ) {
			$owner = Plugin_Speed_Report_Profiler::owner_of_url( $item['src'] );

			if ( 'other' !== $owner && 'external' !== $owner && 'self' !== $owner ) {
				return $owner;
			}
			if ( '' !== $by ) {
				return $by;
			}

			return 'self' === $owner ? 'other' : $owner;
		}

		return '' !== $by ? $by : 'core';
	}

	/**
	 * Owner of a file on the page.
	 *
	 * @param string $url     File URL.
	 * @param string $handle  Handle from the tag's id, if any.
	 * @param string $type    script or style.
	 * @param array  $owners  Handle owners.
	 * @return string
	 */
	private static function owner_for( $url, $handle, $type, array $owners ) {
		$owner = Plugin_Speed_Report_Profiler::owner_of_url( $url );

		if ( ( 'other' === $owner || 'external' === $owner ) && '' !== $handle && isset( $owners[ $type ][ $handle ] ) ) {
			return $owners[ $type ][ $handle ];
		}

		return 'self' === $owner ? 'other' : $owner;
	}

	/**
	 * A file on the page, with its size.
	 *
	 * @param string $url    File URL.
	 * @param string $type   css or js.
	 * @param string $handle Handle, if known.
	 * @param array  $sizes  Size cache.
	 * @return array<string,mixed>
	 */
	private static function file_entry( $url, $type, $handle, array &$sizes ) {
		$path  = wp_parse_url( $url, PHP_URL_PATH );
		$name  = is_string( $path ) ? rawurldecode( basename( $path ) ) : '';
		$file  = Plugin_Speed_Report_Profiler::local_file( $url );
		$entry = array(
			'n' => substr( sanitize_text_field( $name ), 0, 80 ),
			't' => $type,
			'b' => 0,
			'g' => 0,
			'h' => $handle,
		);

		if ( '' === $file ) {
			$entry['x'] = true;
			return $entry;
		}

		$size  = (int) filesize( $file );
		$mtime = (int) filemtime( $file );
		$key   = md5( $file );

		if ( ! isset( $sizes[ $key ] ) || $sizes[ $key ][0] !== $size || $sizes[ $key ][2] !== $mtime ) {
			$gz = (int) ceil( $size / 3 );

			if ( $size > 0 && $size <= self::MAX_BYTES ) {
				$contents = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file, size checked.
				$gz       = is_string( $contents ) ? Plugin_Speed_Report_Profiler::gzip_size( $contents ) : $gz;
			}

			$sizes[ $key ] = array( $size, $gz, $mtime );
		}

		$entry['b'] = $sizes[ $key ][0];
		$entry['g'] = $sizes[ $key ][1];

		return $entry;
	}

	/**
	 * Inline code WordPress printed for a handle.
	 *
	 * @param string $code    The code.
	 * @param string $type    css or js.
	 * @param string $handle  Handle.
	 * @param bool   $in_head Printed in the head.
	 * @return array<string,mixed>
	 */
	private static function inline_entry( $code, $type, $handle, $in_head ) {
		return array(
			'n' => '',
			't' => $type,
			'b' => strlen( $code ),
			'g' => Plugin_Speed_Report_Profiler::gzip_size( $code ),
			'h' => $handle,
			'i' => true,
			'k' => $in_head && 'css' === $type,
		);
	}

	/**
	 * Add a file to an owner's list.
	 *
	 * @param array  $assets Assets by owner.
	 * @param string $owner  Owner key.
	 * @param array  $entry  File entry.
	 * @return void
	 */
	private static function add_asset( array &$assets, $owner, array $entry ) {
		$assets[ $owner ]['files'][] = $entry;
	}

	/**
	 * WordPress files (jQuery, for example) that are only on the page because
	 * one plugin's files need them count for that plugin.
	 *
	 * @param array $assets  Assets by owner.
	 * @param array $handles Handles from the profiler.
	 * @param array $owners  Handle owners.
	 * @return void
	 */
	private static function move_pulled_in( array &$assets, array $handles, array $owners ) {
		if ( empty( $assets['core']['files'] ) ) {
			return;
		}

		$needed = array();

		foreach ( $handles as $type => $list ) {
			foreach ( $list as $handle => $item ) {
				$owner = $owners[ $type ][ $handle ];

				// Asked for directly: by its enqueuer (unknown counts as WordPress).
				if ( ! empty( $item['q'] ) ) {
					$needed[ $type ][ $handle ][ '' !== $item['by'] ? $item['by'] : 'core' ] = true;
				}

				foreach ( self::all_deps( $handle, $list ) as $dep ) {
					$needed[ $type ][ $dep ][ $owner ] = true;
				}
			}
		}

		$keep = array();

		foreach ( $assets['core']['files'] as $entry ) {
			$type = 'js' === $entry['t'] ? 'script' : 'style';
			$by   = '' !== $entry['h'] && isset( $needed[ $type ][ $entry['h'] ] ) ? array_keys( $needed[ $type ][ $entry['h'] ] ) : array();

			if ( 1 === count( $by ) && ( 0 === strpos( $by[0], 'p:' ) || 0 === strpos( $by[0], 'mu:' ) ) ) {
				$entry['d'] = true;
				self::add_asset( $assets, $by[0], $entry );
			} else {
				$keep[] = $entry;
			}
		}

		$assets['core']['files'] = $keep;
	}

	/**
	 * Every handle a handle depends on, directly or not.
	 *
	 * @param string $handle Handle.
	 * @param array  $list   Handles of that type.
	 * @return string[]
	 */
	private static function all_deps( $handle, array $list ) {
		$seen  = array();
		$queue = isset( $list[ $handle ]['deps'] ) ? (array) $list[ $handle ]['deps'] : array();

		while ( $queue ) {
			$dep = array_shift( $queue );

			if ( ! is_string( $dep ) || isset( $seen[ $dep ] ) || $dep === $handle ) {
				continue;
			}
			$seen[ $dep ] = true;

			if ( isset( $list[ $dep ]['deps'] ) ) {
				foreach ( (array) $list[ $dep ]['deps'] as $next ) {
					$queue[] = $next;
				}
			}
		}

		return array_keys( $seen );
	}

	/**
	 * Mark the files an asset fix would remove: the plugin's own registered
	 * files (and their inline extras), plus WordPress files only they need.
	 *
	 * @param array $assets  Assets by owner.
	 * @param array $handles Handles from the profiler.
	 * @return void
	 */
	private static function mark_removable( array &$assets, array $handles ) {
		foreach ( $assets as $owner => $group ) {
			if ( 0 !== strpos( $owner, 'p:' ) ) {
				continue;
			}

			$folder = substr( $owner, 2 );

			foreach ( $group['files'] as $i => $entry ) {
				$type = 'js' === $entry['t'] ? 'script' : 'style';

				if ( '' === $entry['h'] || ! isset( $handles[ $type ][ $entry['h'] ]['src'] ) ) {
					continue;
				}

				$src_owner = Plugin_Speed_Report_Profiler::owner_of_url( (string) $handles[ $type ][ $entry['h'] ]['src'] );

				if ( ! empty( $entry['d'] ) || 'p:' . $folder === $src_owner ) {
					$assets[ $owner ]['files'][ $i ]['r'] = true;
				}
			}
		}
	}

	/**
	 * Add up each owner's files.
	 *
	 * @param array $assets Assets by owner.
	 * @return array<string,array<string,mixed>>
	 */
	private static function total_assets( array $assets ) {
		$totals = array();

		foreach ( $assets as $owner => $group ) {
			$sum = array(
				'css'   => 0,
				'js'    => 0,
				'gz'    => 0,
				'block' => 0,
				'rm'    => 0,
				'rm_gz' => 0,
				'ext'   => 0,
				'files' => array(),
			);

			foreach ( $group['files'] as $entry ) {
				$sum[ $entry['t'] ] += $entry['b'];
				$sum['gz']          += $entry['g'];

				if ( ! empty( $entry['k'] ) ) {
					$sum['block'] += $entry['b'];
				}
				if ( ! empty( $entry['r'] ) ) {
					$sum['rm']    += $entry['b'];
					$sum['rm_gz'] += $entry['g'];
				}
				if ( ! empty( $entry['x'] ) ) {
					++$sum['ext'];
				}

				$sum['files'][] = $entry;
			}

			if ( $sum['css'] + $sum['js'] > 0 || $sum['ext'] > 0 ) {
				$totals[ $owner ] = $sum;
			}
		}

		return $totals;
	}

	/**
	 * Attributes of an HTML tag.
	 *
	 * @param string $text Everything between the tag name and ">".
	 * @return array<string,string>
	 */
	private static function attributes( $text ) {
		$attrs = array();

		if ( preg_match_all( '/([^\s=\/>"\']+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>"\']+)))?/', $text, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$name = strtolower( $match[1] );

				if ( isset( $attrs[ $name ] ) ) {
					continue;
				}

				$value = '';
				if ( isset( $match[4] ) && '' !== $match[4] ) {
					$value = $match[4];
				} elseif ( isset( $match[3] ) && '' !== $match[3] ) {
					$value = $match[3];
				} elseif ( isset( $match[2] ) ) {
					$value = $match[2];
				}

				$attrs[ $name ] = html_entity_decode( $value, ENT_QUOTES, 'UTF-8' );
			}
		}

		return $attrs;
	}

	/**
	 * Turn the finished scan into a saved report.
	 *
	 * @param Plugin_Speed_Report_Plugin $plugin Plugin instance.
	 * @param array<string,mixed>        $state  Scan state.
	 * @return array<string,mixed>
	 */
	private static function finish( Plugin_Speed_Report_Plugin $plugin, array $state ) {
		$pages = array();
		$meta  = array(
			'shortcodes' => array(),
			'blocks'     => array(),
			'types'      => array(),
			'taxonomies' => array(),
		);
		$env   = array(
			'mode'      => 'mu',
			'cache'     => false,
			'timed_q'   => true,
			'missed_mu' => array(),
		);

		foreach ( $state['pages'] as $index => $page ) {
			$result = isset( $state['results'][ $index ] ) ? $state['results'][ $index ] : array(
				'samples' => array(),
				'error'   => '',
			);
			$row    = array(
				'key'   => $page['key'],
				'label' => $page['label'],
				'path'  => $page['path'],
				'error' => (string) $result['error'],
			);

			if ( '' === $row['error'] && empty( $result['samples'] ) ) {
				$row['error'] = __( 'not measured.', 'plugin-speed-report' );
			}

			if ( '' === $row['error'] ) {
				$row  = array_merge( $row, self::median_page( $result['samples'] ) );
				$row += array(
					'assets' => $result['assets'],
					'used'   => $result['used'],
					'html'   => (int) $result['html'],
				);

				foreach ( array_keys( $meta ) as $kind ) {
					foreach ( $result['meta'][ $kind ] as $owner => $names ) {
						$meta[ $kind ][ $owner ] = array_values( array_unique( array_merge( isset( $meta[ $kind ][ $owner ] ) ? $meta[ $kind ][ $owner ] : array(), $names ) ) );
					}
				}

				if ( 'plugin' === $result['meta']['mode'] ) {
					$env['mode'] = 'plugin';
				}
				$env['cache']     = $env['cache'] || $result['meta']['cache'];
				$env['timed_q']   = $env['timed_q'] && $result['meta']['timed_q'];
				$env['missed_mu'] = array_values( array_unique( array_merge( $env['missed_mu'], $result['meta']['missed_mu'] ) ) );
			}

			$pages[] = $row;
		}

		$run = array(
			'id'      => $state['id'],
			'created' => time(),
			'samples' => (int) $state['samples'],
			'pages'   => $pages,
			'meta'    => $meta,
			'names'   => self::owner_names( $pages ),
			'env'     => array_merge(
				$env,
				array(
					'wp'    => get_bloginfo( 'version' ),
					'php'   => PHP_VERSION,
					'fixes' => array_values( array_filter( (array) $state['fixes'], 'is_string' ) ),
				)
			),
		);

		$plugin->save_run( $run, Plugin_Speed_Report_Report::summary( $run ) );
		delete_option( self::STATE_OPTION );

		return array(
			'done'     => true,
			'step'     => (int) $state['total'],
			'total'    => (int) $state['total'],
			'message'  => __( 'Done. Loading your report…', 'plugin-speed-report' ),
			'redirect' => Plugin_Speed_Report_Admin::tab_url( 'report', array( 'scanned' => 1 ) ),
		);
	}

	/**
	 * Median timings of a page's samples, per owner.
	 *
	 * @param array $samples Timing samples.
	 * @return array<string,mixed>
	 */
	private static function median_page( array $samples ) {
		$owners = array();

		foreach ( $samples as $sample ) {
			foreach ( $sample['owners'] as $owner => $row ) {
				$owners[ $owner ][0][] = $row[0];
				$owners[ $owner ][1][] = $row[1];
				if ( null !== $row[2] ) {
					$owners[ $owner ][2][] = $row[2];
				}
			}
		}

		$count  = count( $samples );
		$result = array();

		foreach ( $owners as $owner => $columns ) {
			// An owner missing from a sample spent nothing in it.
			$ms = array_pad( $columns[0], $count, 0 );
			$q  = array_pad( $columns[1], $count, 0 );

			$result[ $owner ] = array(
				'ms'  => round( self::median( $ms ), 2 ),
				'q'   => (int) round( self::median( $q ) ),
				'qms' => isset( $columns[2] ) ? round( self::median( array_pad( $columns[2], $count, 0 ) ), 2 ) : null,
			);
		}

		return array(
			'ms'     => round( self::median( wp_list_pluck( $samples, 'ms' ) ), 2 ),
			'wall'   => round( self::median( wp_list_pluck( $samples, 'wall' ) ), 1 ),
			'q'      => (int) round( self::median( wp_list_pluck( $samples, 'q' ) ) ),
			'owners' => $result,
		);
	}

	/**
	 * Median of some numbers.
	 *
	 * @param array $values Numbers.
	 * @return float
	 */
	private static function median( array $values ) {
		$values = array_map( 'floatval', array_values( $values ) );

		if ( ! $values ) {
			return 0.0;
		}

		sort( $values );
		$middle = intdiv( count( $values ), 2 );

		return count( $values ) % 2 ? $values[ $middle ] : ( $values[ $middle - 1 ] + $values[ $middle ] ) / 2;
	}

	/**
	 * Display names (and versions, and pending updates) for every owner.
	 *
	 * @param array $pages Page results.
	 * @return array<string,array<string,string>>
	 */
	private static function owner_names( array $pages ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$keys = array();
		foreach ( $pages as $page ) {
			foreach ( array( 'owners', 'assets', 'used' ) as $part ) {
				if ( ! empty( $page[ $part ] ) ) {
					$keys += array_fill_keys( array_keys( $page[ $part ] ), true );
				}
			}
		}

		$plugins = get_plugins();
		$updates = get_site_transient( 'update_plugins' );
		$by_key  = array();

		foreach ( $plugins as $file => $data ) {
			$folder = false === strpos( $file, '/' ) ? $file : dirname( $file );

			$by_key[ 'p:' . $folder ] = array(
				'name'    => $data['Name'],
				'version' => $data['Version'],
				'file'    => $file,
				'update'  => isset( $updates->response[ $file ]->new_version ) ? (string) $updates->response[ $file ]->new_version : '',
			);
		}

		$mu    = function_exists( 'get_mu_plugins' ) ? get_mu_plugins() : array();
		$names = array();

		foreach ( array_keys( $keys ) as $key ) {
			if ( isset( $by_key[ $key ] ) ) {
				$names[ $key ] = $by_key[ $key ];
			} elseif ( 0 === strpos( $key, 'mu:' ) ) {
				$file          = substr( $key, 3 );
				$names[ $key ] = array(
					/* translators: %s: must-use plugin name. */
					'name' => sprintf( __( '%s (must-use)', 'plugin-speed-report' ), isset( $mu[ $file ]['Name'] ) && '' !== $mu[ $file ]['Name'] ? $mu[ $file ]['Name'] : $file ),
				);
			} elseif ( 'theme' === $key ) {
				$names[ $key ] = array(
					/* translators: %s: theme name. */
					'name'    => sprintf( __( 'Theme: %s', 'plugin-speed-report' ), wp_get_theme()->get( 'Name' ) ),
					'version' => (string) wp_get_theme()->get( 'Version' ),
				);
			} elseif ( 0 === strpos( $key, 'p:' ) ) {
				$names[ $key ] = array( 'name' => substr( $key, 2 ) );
			}
		}

		return $names;
	}

	/**
	 * Read the profiler's results, written at the very end of the request.
	 *
	 * @return array<string,mixed>|null
	 */
	private static function read_result() {
		global $wpdb;

		// A request can finish sending before its shutdown work does.
		for ( $try = 0; $try < 20; $try++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Written directly by the profiler, past any cache.
			$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", Plugin_Speed_Report_Profiler::RESULT_OPTION ) );

			if ( is_string( $raw ) && '' !== $raw ) {
				$data = json_decode( $raw, true );

				return is_array( $data ) && isset( $data['format'], $data['owners'], $data['total_ms'] ) ? $data : null;
			}

			usleep( 100000 );
		}

		return null;
	}

	/**
	 * Write an option row directly (never cached, never autoloaded).
	 *
	 * @param string $name  Option name.
	 * @param string $value Value.
	 * @return void
	 */
	private static function write_row( $name, $value ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->replace(
			$wpdb->options,
			array(
				'option_name'  => $name,
				'option_value' => $value,
				'autoload'     => 'no',
			),
			array( '%s', '%s', '%s' )
		);
	}

	/**
	 * Delete an option row directly.
	 *
	 * @param string $name Option name.
	 * @return void
	 */
	private static function delete_row( $name ) {
		global $wpdb;

		$wpdb->delete( $wpdb->options, array( 'option_name' => $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}
}
