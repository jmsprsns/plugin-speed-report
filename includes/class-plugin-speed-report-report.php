<?php
/**
 * Turns a saved scan into the report: per-plugin averages, plain-English
 * sentences, suggested fixes and before/after comparisons.
 *
 * @package Plugin_Speed_Report
 */

defined( 'ABSPATH' ) || exit;

/**
 * Report maths and wording.
 */
final class Plugin_Speed_Report_Report {

	/**
	 * Milliseconds one compressed KB adds on a typical mobile connection
	 * (about 2 Mbps). Used only to rank plugins, and shown as such.
	 */
	const MS_PER_KB = 4;

	/**
	 * A duration in seconds, the way people say it: "0.42 s".
	 *
	 * @param float $ms Milliseconds.
	 * @return string
	 */
	public static function seconds( $ms ) {
		$ms = (float) $ms;

		if ( $ms < 5 ) {
			return __( 'under 0.01 s', 'plugin-speed-report' );
		}

		/* translators: %s: number of seconds, e.g. "0.42". */
		return sprintf( __( '%s s', 'plugin-speed-report' ), number_format_i18n( $ms / 1000, $ms >= 10000 ? 1 : 2 ) );
	}

	/**
	 * A size in KB (or MB): "180 KB".
	 *
	 * @param float $bytes Bytes.
	 * @return string
	 */
	public static function kb( $bytes ) {
		$kb = (float) $bytes / 1024;

		if ( $kb >= 1024 ) {
			/* translators: %s: size in megabytes. */
			return sprintf( __( '%s MB', 'plugin-speed-report' ), number_format_i18n( $kb / 1024, 1 ) );
		}

		/* translators: %s: size in kilobytes. */
		return sprintf( __( '%s KB', 'plugin-speed-report' ), number_format_i18n( $kb, $kb > 0 && $kb < 10 ? 1 : 0 ) );
	}

	/**
	 * Display name of an owner.
	 *
	 * @param array  $run Saved scan.
	 * @param string $key Owner key.
	 * @return string
	 */
	public static function name( array $run, $key ) {
		if ( isset( $run['names'][ $key ]['name'] ) && '' !== $run['names'][ $key ]['name'] ) {
			return (string) $run['names'][ $key ]['name'];
		}

		switch ( $key ) {
			case 'core':
				return __( 'WordPress core', 'plugin-speed-report' );
			case 'theme':
				return __( 'Your theme', 'plugin-speed-report' );
			case 'other':
				return __( 'Other files on this site', 'plugin-speed-report' );
			case 'external':
				return __( 'Files from other websites', 'plugin-speed-report' );
		}

		return (string) preg_replace( '/^(?:p|mu):/', '', $key );
	}

	/**
	 * Pages that were measured successfully.
	 *
	 * @param array $run Saved scan.
	 * @return array<int,array<string,mixed>>
	 */
	public static function measured_pages( array $run ) {
		return array_values(
			array_filter(
				(array) $run['pages'],
				function ( $page ) {
					return '' === $page['error'] && isset( $page['owners'] );
				}
			)
		);
	}

	/**
	 * Every owner's numbers, averaged over the measured pages, plugins
	 * ranked by impact (most first). Theme, core and the rest are returned
	 * separately.
	 *
	 * @param array $run Saved scan.
	 * @return array{plugins: array<int,array<string,mixed>>, others: array<string,array<string,mixed>>}
	 */
	public static function owners( array $run ) {
		$pages = self::measured_pages( $run );
		$count = count( $pages );
		$keys  = array();

		foreach ( $pages as $page ) {
			$keys += array_fill_keys( array_keys( $page['owners'] ), true );
			$keys += array_fill_keys( array_keys( (array) $page['assets'] ), true );
		}

		$plugins = array();
		$others  = array();

		foreach ( array_keys( $keys ) as $key ) {
			$row = array(
				'key'      => $key,
				'name'     => self::name( $run, $key ),
				'version'  => isset( $run['names'][ $key ]['version'] ) ? $run['names'][ $key ]['version'] : '',
				'update'   => isset( $run['names'][ $key ]['update'] ) ? $run['names'][ $key ]['update'] : '',
				'ms'       => 0.0,
				'max_ms'   => 0.0,
				'q'        => 0.0,
				'qms'      => 0.0,
				'css'      => 0.0,
				'js'       => 0.0,
				'gz'       => 0.0,
				'bjs'      => 0.0,
				'present'  => 0,
				'used'     => 0,
				'unused'   => array(),
				'used_at'  => array(),
				'rm_pages' => array(),
				'rm'       => 0.0,
				'rm_gz'    => 0.0,
				'per_page' => array(),
				'files'    => array(),
			);

			foreach ( $pages as $page ) {
				$time   = isset( $page['owners'][ $key ] ) ? $page['owners'][ $key ] : array(
					'ms'  => 0,
					'q'   => 0,
					'qms' => null,
				);
				$assets = isset( $page['assets'][ $key ] ) ? $page['assets'][ $key ] : null;
				$bytes  = $assets ? $assets['css'] + $assets['js'] : 0;
				$used   = isset( $page['used'][ $key ] );

				$row['ms']    += $time['ms'];
				$row['max_ms'] = max( $row['max_ms'], $time['ms'] );
				$row['q']     += $time['q'];
				$row['qms']   += (float) $time['qms'];

				if ( $assets ) {
					$row['css'] += $assets['css'];
					$row['js']  += $assets['js'];
					$row['gz']  += $assets['gz'];
					$row['bjs'] += self::blocking_js( $assets );

					foreach ( $assets['files'] as $file ) {
						$id = $file['t'] . '|' . $file['h'] . '|' . $file['n'] . '|' . ( empty( $file['i'] ) ? '' : 'i' );
						if ( ! isset( $row['files'][ $id ] ) ) {
							$row['files'][ $id ] = $file + array( 'pages' => 0 );
						}
						++$row['files'][ $id ]['pages'];
					}
				}

				if ( $time['ms'] >= 1 || $bytes > 0 || $time['q'] > 0 ) {
					++$row['present'];
				}

				if ( $used ) {
					++$row['used'];
					$row['used_at'][] = $page['path'];
				} elseif ( $bytes > 0 ) {
					$row['unused'][] = array(
						'label' => $page['label'],
						'path'  => $page['path'],
						'bytes' => $bytes,
					);

					if ( $assets && $assets['rm'] > 0 ) {
						$row['rm']        += $assets['rm'];
						$row['rm_gz']     += $assets['rm_gz'];
						$row['rm_pages'][] = $page['path'];
					}
				}

				$row['per_page'][] = array(
					'label' => $page['label'],
					'path'  => $page['path'],
					'ms'    => $time['ms'],
					'q'     => $time['q'],
					'bytes' => $bytes,
					'used'  => $used ? $page['used'][ $key ] : '',
				);
			}

			if ( $count > 0 ) {
				foreach ( array( 'ms', 'q', 'qms', 'css', 'js', 'gz', 'bjs' ) as $field ) {
					$row[ $field ] = $row[ $field ] / $count;
				}
			}

			// Removable bytes, averaged over the pages they'd come off.
			if ( $row['rm_pages'] ) {
				$row['rm']    = $row['rm'] / count( $row['rm_pages'] );
				$row['rm_gz'] = $row['rm_gz'] / count( $row['rm_pages'] );
			}

			$row['bytes']  = $row['css'] + $row['js'];
			$row['impact'] = $row['ms'] + $row['gz'] / 1024 * self::MS_PER_KB;
			$row['every']  = $count > 0 && $row['present'] === $count;
			$row['files']  = array_values( $row['files'] );

			usort(
				$row['files'],
				function ( $a, $b ) {
					return $b['b'] - $a['b'];
				}
			);

			if ( 0 === strpos( $key, 'p:' ) || 0 === strpos( $key, 'mu:' ) ) {
				if ( $row['present'] > 0 ) {
					$plugins[] = $row;
				}
			} else {
				$others[ $key ] = $row;
			}
		}

		usort(
			$plugins,
			function ( $a, $b ) {
				return $b['impact'] <=> $a['impact'];
			}
		);

		return array(
			'plugins' => $plugins,
			'others'  => $others,
		);
	}

	/**
	 * Render-blocking JavaScript bytes in a page's assets.
	 *
	 * @param array $assets One owner's assets on one page.
	 * @return int
	 */
	private static function blocking_js( array $assets ) {
		$bytes = 0;

		foreach ( $assets['files'] as $file ) {
			if ( 'js' === $file['t'] && ! empty( $file['k'] ) ) {
				$bytes += $file['b'];
			}
		}

		return $bytes;
	}

	/**
	 * Severity of a row: bad, warn or ok.
	 *
	 * @param array $row Owner row.
	 * @return string
	 */
	public static function tone( array $row ) {
		if ( $row['impact'] >= 300 ) {
			return 'bad';
		}

		return $row['impact'] >= 100 ? 'warn' : 'ok';
	}

	/**
	 * The plain-English line for a plugin, e.g. "0.40 s and 180 KB on every
	 * page, including 3 pages that don't use it."
	 *
	 * @param array $row   Owner row.
	 * @param int   $count Pages measured.
	 * @return string
	 */
	public static function sentence( array $row, $count ) {
		if ( $row['ms'] < 5 && $row['bytes'] < 1024 ) {
			return __( 'Barely measurable: under 0.01 s and almost no CSS or JavaScript.', 'plugin-speed-report' );
		}

		if ( $row['bytes'] >= 1024 ) {
			/* translators: 1: server time, e.g. "0.40 s", 2: size, e.g. "180 KB". */
			$cost = sprintf( __( '%1$s and %2$s', 'plugin-speed-report' ), self::seconds( $row['ms'] ), self::kb( $row['bytes'] ) );
		} else {
			$cost = self::seconds( $row['ms'] );
		}

		if ( $row['every'] ) {
			$where = $count > 1 ? __( 'on every page', 'plugin-speed-report' ) : __( 'on the page tested', 'plugin-speed-report' );
		} else {
			/* translators: 1: pages it ran on, 2: pages tested. */
			$where = sprintf( __( 'on %1$d of %2$d pages', 'plugin-speed-report' ), $row['present'], $count );
		}

		$unused = count( $row['unused'] );

		if ( 0 === $row['used'] && $row['present'] > 1 && ( $row['ms'] >= 20 || $row['bytes'] >= 10240 ) ) {
			/* translators: 1: cost, 2: where. */
			return sprintf( __( '%1$s %2$s, and it didn\'t add anything visible to any of them.', 'plugin-speed-report' ), $cost, $where );
		}

		if ( $unused > 0 && $row['bytes'] >= 1024 ) {
			/* translators: 1: cost, 2: where, 3: number of pages. */
			return sprintf( _n( '%1$s %2$s, including %3$d page that doesn\'t use it.', '%1$s %2$s, including %3$d pages that don\'t use it.', $unused, 'plugin-speed-report' ), $cost, $where, $unused );
		}

		/* translators: 1: cost, 2: where. */
		return sprintf( __( '%1$s %2$s.', 'plugin-speed-report' ), $cost, $where );
	}

	/**
	 * Specific fixes worth trying for a plugin.
	 *
	 * @param array $row         Owner row.
	 * @param array $run         Saved scan.
	 * @param array $rules       Current asset fixes.
	 * @param bool  $has_cache   Whether the site has a persistent object cache.
	 * @return array<int,array<string,string>>
	 */
	public static function suggestions( array $row, array $run, array $rules, $has_cache ) {
		$tips   = array();
		$folder = 0 === strpos( $row['key'], 'p:' ) ? substr( $row['key'], 2 ) : '';

		if ( '' !== $folder && isset( $rules[ $folder ] ) ) {
			$tips[] = array(
				'type'  => 'fixed',
				'icon'  => 'circle-check',
				'title' => __( 'Fix on: its CSS and JS load only where it\'s used', 'plugin-speed-report' ),
				'text'  => in_array( $folder, (array) $run['env']['fixes'], true )
					? __( 'This scan already ran with the fix on.', 'plugin-speed-report' )
					: __( 'This scan ran before the fix. Run a new scan to see the difference.', 'plugin-speed-report' ),
			);
		} elseif ( '' !== $folder && $row['rm'] >= 2048 && $row['rm_pages'] ) {
			$first = $row['unused'][0];

			$tips[] = array(
				'type'    => 'unload',
				'icon'    => 'scissors',
				'title'   => __( 'Only load its CSS and JS where it\'s used', 'plugin-speed-report' ),
				'text'    => sprintf(
					/* translators: 1: size, e.g. "120 KB", 2: number of pages, 3: example page name. */
					_n(
						'It adds %1$s of files to %2$d page where it doesn\'t show anything (%3$s). This fix stops loading them on pages like that, and keeps them on pages that use it.',
						'It adds %1$s of files to %2$d pages where it doesn\'t show anything, such as %3$s. This fix stops loading them on pages like those, and keeps them on pages that use it.',
						count( $row['rm_pages'] ),
						'plugin-speed-report'
					),
					self::kb( $row['rm'] ),
					count( $row['rm_pages'] ),
					$first['label']
				),
				'folder'  => $folder,
				'preview' => $first['path'],
			);
		}

		if ( $row['ms'] >= 100 ) {
			$tips[] = array(
				'type'  => 'slow',
				'icon'  => 'timer',
				/* translators: %s: server time. */
				'title' => sprintf( __( 'Uses %s of server time per page', 'plugin-speed-report' ), self::seconds( $row['ms'] ) ),
				'text'  => $row['unused']
					? __( 'That includes pages where it does nothing visible. Unloading files can\'t reduce server time: check its settings for features you don\'t use, keep it updated, or ask its developer whether it can skip work on pages that don\'t need it.', 'plugin-speed-report' )
					: __( 'Check its settings for features you don\'t use and keep it updated. Page caching hides this cost from most visitors, but logged-in users, carts and first visits still pay it.', 'plugin-speed-report' ),
			);
		}

		if ( $row['q'] >= 40 ) {
			$tips[] = array(
				'type'  => 'queries',
				'icon'  => 'database',
				/* translators: %s: number of queries. */
				'title' => sprintf( __( 'Runs %s database queries per page', 'plugin-speed-report' ), number_format_i18n( round( $row['q'] ) ) ),
				'text'  => $has_cache
					? __( 'Your site already has an object cache, so these are queries it can\'t skip. Look for a caching or "lite mode" option in the plugin\'s settings.', 'plugin-speed-report' )
					: __( 'A persistent object cache (Redis or Memcached, often a one-click option at your host) answers many repeated queries from memory.', 'plugin-speed-report' ),
			);
		}

		if ( $row['bjs'] >= 30720 ) {
			$tips[] = array(
				'type'  => 'blocking',
				'icon'  => 'hourglass',
				/* translators: %s: size. */
				'title' => sprintf( __( 'Loads %s of JavaScript that holds up the page', 'plugin-speed-report' ), self::kb( $row['bjs'] ) ),
				'text'  => __( 'These scripts are in the page header without "defer", so browsers wait for them before showing anything. Deferring or delaying them (most optimization plugins can) lets the page appear sooner. Test the page afterwards.', 'plugin-speed-report' ),
			);
		}

		if ( 0 === $row['used'] && $row['present'] > 0 && ( $row['ms'] >= 20 || $row['bytes'] >= 10240 ) && '' !== $folder ) {
			$tips[] = array(
				'type'  => 'unused',
				'icon'  => 'eye-off',
				'title' => __( 'Not seen doing anything on the pages tested', 'plugin-speed-report' ),
				'text'  => __( 'That\'s expected for plugins that work behind the scenes (security, SEO, forms on other pages). If you no longer use it, deactivating it removes all of its cost.', 'plugin-speed-report' ),
			);
		}

		if ( '' !== $row['update'] ) {
			$tips[] = array(
				'type'  => 'update',
				'icon'  => 'download',
				/* translators: %s: version number. */
				'title' => sprintf( __( 'Version %s is available', 'plugin-speed-report' ), $row['update'] ),
				'text'  => __( 'Updates often include speed fixes. Update it, then run a new scan.', 'plugin-speed-report' ),
			);
		}

		return $tips;
	}

	/**
	 * Site-wide totals of a scan.
	 *
	 * @param array $run Saved scan.
	 * @return array<string,float|int>
	 */
	public static function totals( array $run ) {
		$pages  = self::measured_pages( $run );
		$count  = count( $pages );
		$totals = array(
			'pages'      => $count,
			'tested'     => count( (array) $run['pages'] ),
			'ms'         => 0.0,
			'plugin_ms'  => 0.0,
			'q'          => 0.0,
			'plugin_q'   => 0.0,
			'bytes'      => 0.0,
			'plugin_b'   => 0.0,
			'html'       => 0.0,
			'share'      => 0,
			'plugins'    => 0,
			'plugin_gz'  => 0.0,
			'all_assets' => 0.0,
		);

		if ( ! $count ) {
			return $totals;
		}

		$plugins = array();

		foreach ( $pages as $page ) {
			$totals['ms']   += $page['ms'];
			$totals['q']    += $page['q'];
			$totals['html'] += $page['html'];

			foreach ( $page['owners'] as $key => $time ) {
				if ( self::is_plugin( $key ) ) {
					$totals['plugin_ms'] += $time['ms'];
					$totals['plugin_q']  += $time['q'];
					$plugins[ $key ]      = true;
				}
			}

			foreach ( (array) $page['assets'] as $key => $assets ) {
				$totals['bytes'] += $assets['css'] + $assets['js'];

				if ( self::is_plugin( $key ) ) {
					$totals['plugin_b']  += $assets['css'] + $assets['js'];
					$totals['plugin_gz'] += $assets['gz'];
					$plugins[ $key ]      = true;
				}
			}
		}

		foreach ( array( 'ms', 'plugin_ms', 'q', 'plugin_q', 'bytes', 'plugin_b', 'html', 'plugin_gz' ) as $field ) {
			$totals[ $field ] = $totals[ $field ] / $count;
		}

		$totals['share']   = $totals['ms'] > 0 ? (int) round( $totals['plugin_ms'] / $totals['ms'] * 100 ) : 0;
		$totals['plugins'] = count( $plugins );

		return $totals;
	}

	/**
	 * Whether an owner key is a plugin (regular or must-use).
	 *
	 * @param string $key Owner key.
	 * @return bool
	 */
	public static function is_plugin( $key ) {
		return 0 === strpos( $key, 'p:' ) || 0 === strpos( $key, 'mu:' );
	}

	/**
	 * Summary saved in the scan list.
	 *
	 * @param array $run Saved scan.
	 * @return array<string,mixed>
	 */
	public static function summary( array $run ) {
		$totals = self::totals( $run );

		return array(
			'id'        => $run['id'],
			'created'   => $run['created'],
			'pages'     => $totals['pages'],
			'tested'    => $totals['tested'],
			'ms'        => round( $totals['ms'], 1 ),
			'plugin_ms' => round( $totals['plugin_ms'], 1 ),
			'plugin_b'  => (int) round( $totals['plugin_b'] ),
			'q'         => round( $totals['q'], 1 ),
			'fixes'     => count( (array) $run['env']['fixes'] ),
		);
	}

	/**
	 * Per-plugin differences between two scans, biggest change first.
	 *
	 * @param array $before Earlier scan.
	 * @param array $after  Later scan.
	 * @return array<int,array<string,mixed>>
	 */
	public static function compare( array $before, array $after ) {
		$a    = self::owners( $before );
		$b    = self::owners( $after );
		$rows = array();

		foreach ( array(
			'before' => $a,
			'after'  => $b,
		) as $side => $owners ) {
			foreach ( array_merge( $owners['plugins'], array_values( $owners['others'] ) ) as $row ) {
				if ( ! isset( $rows[ $row['key'] ] ) ) {
					$rows[ $row['key'] ] = array(
						'key'    => $row['key'],
						'name'   => $row['name'],
						'before' => null,
						'after'  => null,
					);
				}
				$rows[ $row['key'] ][ $side ] = array(
					'ms'    => $row['ms'],
					'q'     => $row['q'],
					'bytes' => $row['bytes'],
				);

				if ( 'after' === $side ) {
					$rows[ $row['key'] ]['name'] = $row['name'];
				}
			}
		}

		$empty = array(
			'ms'    => 0,
			'q'     => 0,
			'bytes' => 0,
		);

		foreach ( $rows as $key => $row ) {
			$rows[ $key ]['change'] = abs( ( $row['after'] ? $row['after']['ms'] : 0 ) - ( $row['before'] ? $row['before']['ms'] : 0 ) )
				+ abs( ( $row['after'] ? $row['after']['bytes'] : 0 ) - ( $row['before'] ? $row['before']['bytes'] : 0 ) ) / 1024 * self::MS_PER_KB;
			$rows[ $key ]['before'] = $row['before'] ? $row['before'] : $empty;
			$rows[ $key ]['after']  = $row['after'] ? $row['after'] : $empty;
			$rows[ $key ]['plugin'] = self::is_plugin( $key );
		}

		$rows = array_values( $rows );

		usort(
			$rows,
			function ( $x, $y ) {
				if ( $x['plugin'] !== $y['plugin'] ) {
					return $x['plugin'] ? -1 : 1;
				}

				return $y['change'] <=> $x['change'];
			}
		);

		return $rows;
	}

	/**
	 * Plain-English reason a plugin counted as used on a page.
	 *
	 * @param string $reason Reason code from the profiler, e.g. "shortcode:gallery".
	 * @return string
	 */
	public static function reason( $reason ) {
		$parts = explode( ':', (string) $reason, 2 );
		$what  = isset( $parts[1] ) ? $parts[1] : '';

		switch ( $parts[0] ) {
			case 'shortcode':
				/* translators: %s: shortcode tag. */
				return sprintf( __( 'Shortcode [%s]', 'plugin-speed-report' ), $what );
			case 'block':
				/* translators: %s: block name. */
				return sprintf( __( 'Block %s', 'plugin-speed-report' ), $what );
			case 'widget':
				return __( 'Widget', 'plugin-speed-report' );
			case 'script':
				return __( 'Runs its own script here', 'plugin-speed-report' );
			case 'content':
				return __( 'Added to the content', 'plugin-speed-report' );
			case 'post_type':
				/* translators: %s: post type name. */
				return sprintf( __( 'Its own page type (%s)', 'plugin-speed-report' ), $what );
			case 'taxonomy':
				/* translators: %s: taxonomy name. */
				return sprintf( __( 'Its own archive (%s)', 'plugin-speed-report' ), $what );
		}

		return __( 'Shows something on the page', 'plugin-speed-report' );
	}
}
