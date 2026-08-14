( function( window, document, undefined ) {

	'use strict';

	/**
	 * Gravity Forms reCAPTCHA release controller.
	 *
	 * gravity-forms.php has held the add-on's frontend bundle inert, because that bundle
	 * captures window.grecaptcha by value the moment it runs. Our job is to run it at the
	 * one point where that capture is useful: after Google's api.js has actually defined
	 * grecaptcha, which only happens once the visitor's consent covers it.
	 *
	 * Until then the form cannot be submitted — a Google captcha cannot be satisfied
	 * without contacting Google — so we say so plainly instead of letting the button
	 * spin forever, which is what it did before this module existed.
	 */

	var HELD_SELECTOR = 'script[type="text/plain"][data-cn-gf-recaptcha-src]';
	var NOTICE_CLASS  = 'cn-gf-recaptcha-notice';
	var POLL_INTERVAL = 200;
	var POLL_TIMEOUT  = 20000;

	var strings  = window.cn_gf_recaptcha || {};
	var timedOut = false;

	/**
	 * Has this visitor's consent released the blocked scripts on this pageview?
	 *
	 * This is what tells the two silences apart. grecaptcha being absent means "we are
	 * holding it until you answer the banner" before consent, and "Google genuinely did
	 * not load" after — same observable, opposite advice to the visitor. timedOut alone
	 * cannot distinguish them, because the poll below is armed on every pageview that has
	 * something held, including one where the visitor simply has not answered yet.
	 *
	 * @type {boolean}
	 */
	var consentSeen = false;

	/**
	 * Anything still held on the page right now.
	 *
	 * State is read from the DOM rather than latched in a variable, so a form injected
	 * after an earlier release — an Elementor popup, an AJAX embed — is still handled
	 * instead of being left held while interception is switched off.
	 *
	 * @return {NodeList}
	 */
	function heldScripts() {
		return document.querySelectorAll( HELD_SELECTOR );
	}

	/**
	 * Is a usable grecaptcha present? The add-on supports both the standard and
	 * enterprise entry points, so accept either.
	 *
	 * @return {boolean}
	 */
	function recaptchaReady() {
		var g = window.grecaptcha;

		if ( ! g ) {
			return false;
		}

		return typeof g.execute === 'function' ||
			typeof g.render === 'function' ||
			!! ( g.enterprise && ( typeof g.enterprise.execute === 'function' || typeof g.enterprise.render === 'function' ) );
	}

	/**
	 * Re-insert the add-on bundle so it executes and captures the real grecaptcha.
	 *
	 * @return {void}
	 */
	function release() {
		var held = heldScripts();

		if ( ! held.length ) {
			return;
		}

		Array.prototype.forEach.call( held, function( placeholder ) {
			var script = document.createElement( 'script' );
			var src    = placeholder.getAttribute( 'data-cn-gf-recaptcha-src' );

			script.src = src;

			// The v2 checkbox flow renders on GF's post_render event, which has already
			// fired by now, so ask the add-on to sweep the page once it is in charge.
			// v3 needs nothing here — it fetches its token at submit time.
			script.onload = function() {
				if ( typeof window.gravityformsrecaptchaRenderCheckboxes === 'function' ) {
					window.gravityformsrecaptchaRenderCheckboxes();
				}

				clearNotices();
			};

			// If the add-on bundle itself cannot be fetched, put the placeholder back so
			// the submit stays intercepted with an explanation, rather than letting the
			// form post a token it has no way of producing.
			script.onerror = function() {
				timedOut = true;
				placeholder.setAttribute( 'data-cn-gf-recaptcha-src', src );
				placeholder.removeAttribute( 'data-cn-gf-recaptcha-released' );
				clearNotices();
			};

			// Mark it done rather than removing the placeholder, so a later pass can tell
			// the difference between "not yet released" and "already released".
			placeholder.removeAttribute( 'data-cn-gf-recaptcha-src' );
			placeholder.setAttribute( 'data-cn-gf-recaptcha-released', 'true' );

			( document.head || document.documentElement ).appendChild( script );
		} );
	}

	/**
	 * Wait for grecaptcha, then release. api.js loads asynchronously once unblocked, so
	 * the unblock event alone is too early — that race is the whole reason this exists.
	 *
	 * @return {void}
	 */
	function releaseWhenReady() {
		if ( ! heldScripts().length ) {
			return;
		}

		if ( recaptchaReady() ) {
			timedOut = false;
			release();

			return;
		}

		var waited = 0;
		var timer  = window.setInterval( function() {
			waited += POLL_INTERVAL;

			if ( recaptchaReady() ) {
				window.clearInterval( timer );
				timedOut = false;
				release();
			} else if ( waited >= POLL_TIMEOUT ) {
				// Give up waiting, but stay held. Releasing anyway would run the bundle
				// against a still-absent grecaptcha and reproduce the original hang.
				// Remember it, so the visitor is told reCAPTCHA failed to load rather
				// than being asked to accept cookies they may already have accepted.
				window.clearInterval( timer );
				timedOut = true;
				clearNotices();
			}
		}, POLL_INTERVAL );
	}

	/**
	 * Forms on this page that Gravity Forms has protected with reCAPTCHA.
	 *
	 * @param {Element} form
	 * @return {boolean}
	 */
	function isProtected( form ) {
		return !! form.querySelector( '.ginput_recaptchav3, .ginput_container_recaptcha_checkbox, .gfield_recaptcha_response' );
	}

	/**
	 * Remove any notice we added.
	 *
	 * @return {void}
	 */
	function clearNotices() {
		var notices = document.getElementsByClassName( NOTICE_CLASS );

		while ( notices.length ) {
			notices[0].parentNode.removeChild( notices[0] );
		}
	}

	/**
	 * Show, once per form, why the submission cannot proceed yet.
	 *
	 * @param {Element} form
	 * @return {void}
	 */
	function showNotice( form ) {
		if ( form.getElementsByClassName( NOTICE_CLASS ).length ) {
			return;
		}

		var notice = document.createElement( 'p' );

		notice.className = NOTICE_CLASS;
		notice.setAttribute( 'role', 'alert' );

		// "Did not load" is only honest once consent has actually released the scripts.
		// Before that, the wait is ours by design, and telling the visitor to reload would
		// send them round a loop that cannot help — accepting cookies is what fixes it.
		notice.textContent = timedOut && consentSeen
			? ( strings.unavailableMessage || 'This form could not be submitted because Google reCAPTCHA did not load.' )
			: ( strings.blockedMessage || 'Please accept cookies to submit this form.' );

		form.appendChild( notice );
	}

	/**
	 * While the bundle is still held, stop the submit and explain. Capture phase, so we
	 * run before Gravity Forms' own button handler.
	 */
	document.addEventListener( 'click', function( e ) {
		if ( ! e.target || typeof e.target.closest !== 'function' ) {
			return;
		}

		var button = e.target.closest( '[data-submission-type], input[type="submit"], button[type="submit"]' );

		if ( ! button ) {
			return;
		}

		var form = button.closest( 'form' );

		if ( ! form || ! isProtected( form ) ) {
			return;
		}

		// Nothing held any more (released, or never blocked) — leave the form alone.
		if ( ! heldScripts().length ) {
			return;
		}

		e.preventDefault();
		e.stopPropagation();

		showNotice( form );
	}, true );

	/**
	 * Handle the widget's unblock event.
	 *
	 * Wrapped rather than passed as the handler directly, and deliberately so: an event
	 * listener hands the Event object to its first parameter, so signalling "this wait is
	 * after consent" through a releaseWhenReady() argument would be truthy for every
	 * caller — including the safety net below — and quietly restore the bug this guards.
	 *
	 * timedOut is cleared here because any earlier expiry was the pre-consent wait; the
	 * one that starts now is the only one that can honestly report a load failure.
	 */
	document.addEventListener( 'cookies-unblocked.hu', function() {
		consentSeen = true;
		timedOut    = false;

		releaseWhenReady();
	}, false );

	/**
	 * Gravity Forms fires this after it renders a form, including forms brought in later
	 * by AJAX or a popup. Such a form arrives with its own held placeholder, so re-check
	 * rather than assuming the page was fully handled on first load.
	 */
	document.addEventListener( 'gform/post_render', releaseWhenReady, false );

	/**
	 * Safety net: if reCAPTCHA turns out not to be blocked on this pageview after all —
	 * an excluded handle, a returning visitor whose consent was already recorded, the
	 * widget failing to load — release on our own so we never leave a form worse off
	 * than it was before this module existed.
	 */
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', releaseWhenReady, false );
	} else {
		releaseWhenReady();
	}

} )( window, document );
