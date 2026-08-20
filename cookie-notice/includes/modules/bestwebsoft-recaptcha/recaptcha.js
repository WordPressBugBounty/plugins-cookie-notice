( function( window, document, undefined ) {

	'use strict';

	/**
	 * BestWebSoft reCaptcha re-render controller.
	 *
	 * Autoblocking holds Google's api.js until consent covers it, so the plugin's captcha
	 * never renders and its form cannot be submitted. The plugin's own retry — a
	 * one-second interval in js/script.js — gives up after ten tries, which is less time
	 * than reading the banner takes.
	 *
	 * So we do two things, and nothing else: tell the visitor why the form is not usable
	 * yet, and call the plugin's own gglcptch.prepare() again once Google has actually
	 * arrived. We hold no scripts and rewrite no tags — their script reads
	 * window.grecaptcha lazily, so a late grecaptcha is all it needs.
	 */

	/**
	 * A script the widget is holding right now.
	 *
	 * These attributes are the widget's own public markers for a blocked script, so this
	 * is the one reliable way to tell "we are holding Google" apart from "Google is slow"
	 * — two states with the same observable (no grecaptcha) and opposite advice for the
	 * visitor. Matching on data-src rather than the handle also covers recaptcha.net,
	 * which the plugin uses when its "use reCAPTCHA globally" option is on.
	 */
	var BLOCKED_SELECTOR = 'script.hu-blocked[data-src*="recaptcha/api.js"]';
	var NOTICE_CLASS     = 'cn-bws-recaptcha-notice';
	var POLL_INTERVAL    = 200;
	var POLL_TIMEOUT     = 20000;

	var strings = window.cn_bws_recaptcha || {};

	/**
	 * Was reCAPTCHA ever held on this pageview?
	 *
	 * This is what makes the module safe on a site that has no problem. The widget fires
	 * its unblock event when ANY category is released, so a site with reCAPTCHA set to
	 * essential and, say, analytics set to non-essential would fire it too — and without
	 * this latch we would call into a plugin that was working perfectly well.
	 *
	 * @type {boolean}
	 */
	var sawHeld = false;

	/**
	 * Is the widget holding reCAPTCHA right now?
	 *
	 * Read from the DOM every time rather than latched, so a captcha brought in later by
	 * a popup or an AJAX embed is judged on the state it actually arrives into. The latch
	 * above is a separate question — "did this ever apply to us" — because by the time the
	 * unblock event reaches us the tag has already been released and this returns false.
	 *
	 * @return {boolean}
	 */
	function stillHeld() {
		var held = !! document.querySelector( BLOCKED_SELECTOR );

		if ( held ) {
			sawHeld = true;
		}

		return held;
	}

	/**
	 * Is a usable grecaptcha present? The plugin supports the checkbox, invisible and v3
	 * flows, so accept any entry point they rely on.
	 *
	 * @return {boolean}
	 */
	function recaptchaReady() {
		var g = window.grecaptcha;

		if ( ! g ) {
			return false;
		}

		return typeof g.render === 'function' || typeof g.execute === 'function' ||
			!! ( g.enterprise && ( typeof g.enterprise.render === 'function' || typeof g.enterprise.execute === 'function' ) );
	}

	/**
	 * The plugin's own public entry point, if its script is on the page at all.
	 *
	 * @return {boolean}
	 */
	function canPrepare() {
		return !! ( window.gglcptch && typeof window.gglcptch.prepare === 'function' );
	}

	/**
	 * Captcha wrappers the plugin has rendered.
	 *
	 * @return {NodeList}
	 */
	function wrappers() {
		return document.querySelectorAll( '.gglcptch' );
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
	 * Explain, once per captcha, why the form is not usable yet.
	 *
	 * The notice is appended to the .gglcptch wrapper and NEVER to the .gglcptch_recaptcha
	 * container inside it. That container is the plugin's render target and it only draws
	 * into one that is still empty (`container.is( ':empty' )` in its js/script.js), so a
	 * notice placed there would permanently prevent the captcha we are trying to rescue.
	 *
	 * @return {void}
	 */
	function showNotices() {
		if ( ! strings.heldMessage ) {
			return;
		}

		Array.prototype.forEach.call( wrappers(), function( wrapper ) {
			if ( wrapper.getElementsByClassName( NOTICE_CLASS ).length ) {
				return;
			}

			var notice = document.createElement( 'p' );

			notice.className = NOTICE_CLASS;
			notice.setAttribute( 'role', 'alert' );
			notice.textContent = strings.heldMessage;

			wrapper.appendChild( notice );
		} );
	}

	/**
	 * Correct the plugin's own messaging while the block is ours.
	 *
	 * Its pre-api script disables the submit button and, if Google never arrives, alerts
	 * "Failed to load Google reCAPTCHA. Please check your internet connection and reload
	 * this page." Both the blame and the advice are wrong here — the visitor's connection
	 * is fine and reloading cannot help; accepting cookies is what fixes it. The strings
	 * live on a plain localized object and are read at alert time, so replacing them is
	 * enough. Present only when the plugin's "disable submit button" option is on.
	 *
	 * @return {void}
	 */
	function correctMessages() {
		var pre = window.gglcptch_pre;

		if ( ! pre || ! pre.messages ) {
			return;
		}

		if ( strings.timeoutMessage ) {
			pre.messages.timeout = strings.timeoutMessage;
		}

		if ( strings.waitMessage ) {
			pre.messages.in_progress = strings.waitMessage;
		}
	}

	/**
	 * Hand the page back to the plugin.
	 *
	 * @return {void}
	 */
	function render() {
		clearNotices();

		if ( ! canPrepare() ) {
			return;
		}

		// Their code, their errors: a throw in prepare() must not take this script — and
		// with it any later unblock event — down with it.
		try {
			window.gglcptch.prepare();
		} catch ( e ) {
			if ( strings.debug ) {
				console.warn( 'CC Banner: BestWebSoft reCaptcha — gglcptch.prepare() threw', e );
			}
		}
	}

	/**
	 * Wait for grecaptcha, then re-render.
	 *
	 * The unblock event alone is too early: it says the tag has been released, not that
	 * Google has answered. api.js is loaded async, and that gap is the whole reason the
	 * plugin's own ten-second retry misses.
	 *
	 * @return {void}
	 */
	function renderWhenReady() {
		if ( recaptchaReady() ) {
			render();

			return;
		}

		var waited = 0;
		var timer  = window.setInterval( function() {
			waited += POLL_INTERVAL;

			if ( recaptchaReady() ) {
				window.clearInterval( timer );
				render();
			} else if ( waited >= POLL_TIMEOUT ) {
				// Google genuinely did not answer. Leave the notice standing rather than
				// calling prepare() against an absent grecaptcha, which throws inside
				// their code and renders nothing anyway.
				window.clearInterval( timer );
			}
		}, POLL_INTERVAL );
	}

	/**
	 * Say why the form is unusable, but only while the block is actually ours.
	 *
	 * @return {void}
	 */
	function explainIfHeld() {
		if ( ! stillHeld() ) {
			return;
		}

		correctMessages();
		showNotices();

		if ( strings.debug ) {
			console.warn( 'CC Banner: BestWebSoft reCaptcha — Google held pre-consent, ' + wrappers().length + ' captcha(s) on page' );
		}
	}

	/**
	 * The widget has released the blocked scripts.
	 */
	document.addEventListener( 'cookies-unblocked.hu', function() {
		// Only our problem if reCAPTCHA was one of the things being held. See sawHeld.
		if ( ! stillHeld() && ! sawHeld ) {
			return;
		}

		renderWhenReady();
	}, false );

	// Probe immediately as well as on ready. This script is in the footer, so a captcha
	// printed with the page is already in the DOM and already blocked — which sets the
	// latch before any unblock event can arrive, however fast the visitor answers.
	stillHeld();

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', explainIfHeld, false );
	} else {
		explainIfHeld();
	}

	/**
	 * Second look after load, for a captcha whose markup arrives later than we do — a
	 * comment form pulled in by AJAX, a popup.
	 *
	 * Deliberately no "else render() anyway" branch here. When nothing is held there is
	 * no problem to solve: either reCAPTCHA is essential on this site, or the visitor's
	 * consent was already recorded, and in both cases the plugin loads Google and renders
	 * itself exactly as it would without us. Calling prepare() then would be us
	 * interfering with a working page — and on v3 it would re-run grecaptcha.execute and
	 * restart the plugin's token-expiry timer for no reason.
	 */
	window.addEventListener( 'load', explainIfHeld, false );

} )( window, document );
