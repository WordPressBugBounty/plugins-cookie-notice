( function() {
	'use strict';

	/**
	 * Build a form-encoded payload and send it without relying on jQuery.
	 *
	 * @param {string} action
	 * @param {Object} data
	 * @returns {void}
	 */
	const postNoticeAction = function( action, data ) {
		if ( ! window.cnArgsNotice || ! cnArgsNotice.ajaxURL ) {
			return;
		}

		const bodyParams = {
			action: action,
			notice_action: data.noticeAction,
			nonce: data.nonce,
			cn_network: cnArgsNotice.network ? 1 : 0
		};

		if ( typeof data.param !== 'undefined' ) {
			bodyParams.param = data.param;
		}

		const encodeBody = function( params ) {
			return Object.keys( params )
				.map( function( key ) {
					return encodeURIComponent( key ) + '=' + encodeURIComponent( params[ key ] );
				} )
				.join( '&' );
		};

		const body = encodeBody( bodyParams );

		if ( window.fetch ) {
			fetch( cnArgsNotice.ajaxURL, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
				},
				body: body
			} ).catch( function() {
				// fail silently – notice still closes
			} );
		} else {
			// XHR fallback for older browsers.
			var xhr = new XMLHttpRequest();
			xhr.open( 'POST', cnArgsNotice.ajaxURL, true );
			xhr.setRequestHeader( 'Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8' );
			xhr.send( body );
		}
	};

	const hideNotice = function( notice ) {
		if ( notice ) {
			notice.style.display = 'none';
		}
	};

	document.addEventListener( 'DOMContentLoaded', function() {
		// No cookie compliance notice.
		document.addEventListener( 'click', function( event ) {
			const target = event.target;

			if ( ! target || typeof target.closest !== 'function' ) {
				return;
			}

			const dismissButton = target.closest( '.cn-notice .cn-no-compliance .cn-notice-dismiss' );

			if ( ! dismissButton ) {
				return;
			}

			const notice = dismissButton.closest( '.cn-notice' );

			if ( ! notice ) {
				return;
			}

			event.preventDefault();

			let noticeAction = 'dismiss';
			let param = '';

			if ( dismissButton.classList.contains( 'cn-approve' ) ) {
				noticeAction = 'approve';
			} else if ( dismissButton.classList.contains( 'cn-delay' ) ) {
				noticeAction = 'delay';
			} else if ( notice.classList.contains( 'cn-threshold' ) ) {
				noticeAction = 'threshold';

				const noticeText = notice.querySelector( '.cn-notice-text' );
				const delay = noticeText && noticeText.dataset ? parseInt( noticeText.dataset.delay, 10 ) : NaN;

				param = ! isNaN( delay ) && isFinite( delay ) ? delay : '';
			}

			postNoticeAction( 'cn_dismiss_notice', {
				noticeAction: noticeAction,
				nonce: cnArgsNotice.nonce,
				param: param
			} );

			hideNotice( notice );
		} );

		// Review notice.
		document.addEventListener( 'click', function( event ) {
			const target = event.target;

			if ( ! target || typeof target.closest !== 'function' ) {
				return;
			}

			const link = target.closest( '.cn-notice .cn-review .button-link' );

			if ( ! link ) {
				return;
			}

			const notice = link.closest( '.cn-notice' );

			if ( ! notice ) {
				return;
			}

			event.preventDefault();

			let noticeAction = 'dismiss';

			if ( link.classList.contains( 'cn-notice-review' ) ) {
				noticeAction = 'review';
			} else if ( link.classList.contains( 'cn-notice-delay' ) ) {
				noticeAction = 'delay';
			}

			postNoticeAction( 'cn_review_notice', {
				noticeAction: noticeAction,
				nonce: cnArgsNotice.reviewNonce
			} );

			hideNotice( notice );
		} );

		// Engine-changed notice (Cookie_Notice::display_engine_notice()): WordPress's own
		// dismiss button hides it; this deletes its row so it stays dismissed.
		document.addEventListener( 'click', function( event ) {
			const target = event.target;

			if ( ! target || typeof target.closest !== 'function' ) {
				return;
			}

			const notice = target.closest( '.cn-engine-notice' );

			if ( ! notice ) {
				return;
			}

			if ( target.closest( '.notice-dismiss' ) ) {
				sendEngineNoticeRequest( { action: 'cn_dismiss_engine_notice', nonce: notice.dataset.nonce } );
				return;
			}

			const purge = target.closest( '.cn-engine-notice__purge' );

			if ( ! purge || purge.disabled ) {
				return;
			}

			// The plugin's Purge Cache action (Cookie_Notice_Settings::ajax_purge_cache()), with
			// purge_pages: the engine's pull has already run, so without it page caches holding
			// the old banner would only be purged if this pull changed something.
			purge.disabled = true;

			sendEngineNoticeRequest( { action: 'cn_purge_cache', nonce: notice.dataset.purgeNonce, purge_pages: '1' }, function( ok, json ) {
				const purged = ok && !! json && json.success === true;
				purge.textContent = purged ? notice.dataset.purged : notice.dataset.purgeFailed;
				purge.disabled = purged;
			} );
		} );
	} );

	/**
	 * POST to admin-ajax.php for the engine-changed notice, with the network claim this screen
	 * makes. `done( ok, json )` is told whether the server answered 2xx, and its reply as JSON
	 * (null when it is not JSON).
	 *
	 * @param {Object}   params
	 * @param {Function} [done]
	 * @returns {void}
	 */
	/**
	 * A reply's text as JSON, or null.
	 *
	 * @param {string} text
	 * @returns {Object|null}
	 */
	const parseNoticeReply = function( text ) {
		try {
			return text ? JSON.parse( text ) : null;
		} catch ( e ) {
			return null;
		}
	};

	const sendEngineNoticeRequest = function( params, done ) {
		if ( ! window.cnArgsNotice || ! cnArgsNotice.ajaxURL ) {
			return;
		}

		const body = Object.keys( params )
			.map( function( key ) {
				return encodeURIComponent( key ) + '=' + encodeURIComponent( params[ key ] || '' );
			} )
			.concat( cnArgsNotice.network ? [ 'cn_network=1' ] : [] )
			.join( '&' );

		const finish = function( ok, text ) {
			if ( done ) {
				done( ok, parseNoticeReply( text ) );
			}
		};

		if ( window.fetch ) {
			fetch( cnArgsNotice.ajaxURL, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
				},
				body: body
			} ).then( function( response ) {
				return response.text().then( function( text ) {
					finish( response.ok, text );
				} );
			} ).catch( function() {
				finish( false, '' );
			} );
		} else {
			var xhr = new XMLHttpRequest();
			xhr.open( 'POST', cnArgsNotice.ajaxURL, true );
			xhr.setRequestHeader( 'Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8' );
			xhr.onload = function() {
				finish( xhr.status >= 200 && xhr.status < 300, xhr.responseText );
			};
			xhr.onerror = function() {
				finish( false, '' );
			};
			xhr.send( body );
		}
	};
} )();