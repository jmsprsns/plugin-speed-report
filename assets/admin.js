/**
 * Content Powered > Plugin Speed Report page behaviour. Only loaded on that page.
 *
 * Runs scans one step (one background page load) at a time, so no request
 * runs long enough to time out, and shows progress as it goes.
 *
 * plugin_speed_report_admin.ajaxUrl, .nonce, .running and .i18n are passed in
 * from PHP (see Plugin_Speed_Report_Admin::enqueue_assets).
 */

/* global plugin_speed_report_admin */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		if ( typeof plugin_speed_report_admin === 'undefined' ) {
			return;
		}

		var config = plugin_speed_report_admin;

		document.body.classList.add( 'psr-js' );

		initScan( config );
		initTooltips();

		// Compare: pick a scan and the page updates.
		document.querySelectorAll( '[data-psr-autosubmit] select' ).forEach( function ( select ) {
			select.addEventListener( 'change', function () {
				select.form.submit();
			} );
		} );

		document.querySelectorAll( 'form[data-psr-confirm]' ).forEach( function ( form ) {
			form.addEventListener( 'submit', function ( event ) {
				if ( ! window.confirm( config.i18n.confirm ) ) {
					event.preventDefault();
				}
			} );
		} );
	} );

	function initScan( config ) {
		var box = document.querySelector( '[data-psr-scan]' );

		if ( ! box ) {
			return;
		}

		var start = box.querySelector( '[data-psr-start]' );
		var progress = box.querySelector( '[data-psr-progress]' );
		var count = box.querySelector( '[data-psr-count]' );
		var bar = box.querySelector( '[data-psr-bar]' );
		var track = bar.parentNode;
		var line = box.querySelector( '[data-psr-line]' );
		var cancel = box.querySelector( '[data-psr-cancel]' );
		var error = box.querySelector( '[data-psr-error]' );
		var stopped = false;

		function post( op ) {
			var body = new FormData();
			body.append( 'action', 'plugin_speed_report_scan' );
			body.append( 'nonce', config.nonce );
			body.append( 'op', op );

			return fetch( config.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: body,
			} ).then( function ( response ) {
				return response.json().catch( function () {
					throw new Error( config.i18n.network );
				} );
			} );
		}

		function show( running ) {
			start.hidden = running;
			progress.hidden = ! running;
			window.onbeforeunload = running
				? function () {
					return true;
				}
				: null;
		}

		function fail( message ) {
			show( false );
			error.textContent = config.i18n.failed + message;
			error.hidden = false;
		}

		function update( data ) {
			var percent = data.total ? Math.round( ( data.step / data.total ) * 100 ) : 0;

			bar.style.width = percent + '%';
			track.setAttribute( 'aria-valuenow', String( percent ) );
			count.textContent = config.i18n.progress.replace( '%1$d', data.step ).replace( '%2$d', data.total );

			if ( data.message ) {
				line.textContent = data.message;
			}
		}

		function next() {
			if ( stopped ) {
				return;
			}

			post( 'step' )
				.then( function ( response ) {
					if ( stopped ) {
						return;
					}
					if ( ! response.success ) {
						fail( response.data && response.data.message ? response.data.message : config.i18n.network );
						return;
					}

					update( response.data );

					if ( response.data.wait ) {
						window.setTimeout( next, 2000 );
						return;
					}

					if ( response.data.done ) {
						window.onbeforeunload = null;
						window.location.href = response.data.redirect;
						return;
					}

					next();
				} )
				.catch( function () {
					fail( config.i18n.network );
				} );
		}

		start.addEventListener( 'click', function () {
			stopped = false;
			error.hidden = true;
			bar.style.width = '0';
			line.textContent = '';
			count.textContent = config.i18n.starting;
			show( true );

			post( 'start' )
				.then( function ( response ) {
					if ( ! response.success ) {
						fail( response.data && response.data.message ? response.data.message : config.i18n.network );
						return;
					}

					update( response.data );
					next();
				} )
				.catch( function () {
					fail( config.i18n.network );
				} );
		} );

		cancel.addEventListener( 'click', function () {
			stopped = true;
			post( 'cancel' ).finally( function () {
				show( false );
				error.textContent = config.i18n.cancelled;
				error.hidden = false;
			} );
		} );

		// A scan left running in this or another tab carries on here.
		if ( config.running ) {
			show( true );
			count.textContent = config.i18n.starting;
			next();
		}
	}

	// Hover and focus labels for the stacked time bar.
	function initTooltips() {
		var pop = null;

		function hide() {
			if ( pop ) {
				pop.remove();
				pop = null;
			}
		}

		function place( target, x, y ) {
			if ( ! pop ) {
				pop = document.createElement( 'div' );
				pop.className = 'psr-tip-pop';
				pop.setAttribute( 'role', 'tooltip' );
				document.body.appendChild( pop );
			}

			pop.textContent = target.getAttribute( 'data-psr-tip' );

			var width = pop.offsetWidth;
			var left = Math.min( Math.max( 8, x - width / 2 ), window.innerWidth - width - 8 );

			pop.style.left = left + 'px';
			pop.style.top = Math.max( 8, y - pop.offsetHeight - 10 ) + 'px';
		}

		document.querySelectorAll( '[data-psr-tip]' ).forEach( function ( segment ) {
			segment.addEventListener( 'mousemove', function ( event ) {
				place( segment, event.clientX, segment.getBoundingClientRect().top );
			} );
			segment.addEventListener( 'focus', function () {
				var rect = segment.getBoundingClientRect();
				place( segment, rect.left + rect.width / 2, rect.top );
			} );
			segment.addEventListener( 'mouseleave', hide );
			segment.addEventListener( 'blur', hide );
		} );

		window.addEventListener( 'scroll', hide, { passive: true } );
	}
} )();
