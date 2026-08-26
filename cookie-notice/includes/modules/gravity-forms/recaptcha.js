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

	/** Gravity Forms' own data-submission-type value for a real submit. See isSubmitControl(). */
	var SUBMISSION_TYPE_SUBMIT = 'submit';

	/** Gravity Forms controls that move around a form rather than submitting it. */
	var NAV_SELECTOR = '.gform_next_button, .gform_previous_button, .gform_save_link, .gform_send_resume_link_button';

	var strings  = window.cn_gf_recaptcha || {};
	var timedOut = false;

	/**
	 * Does this visitor's recorded consent COVER Google reCAPTCHA?
	 *
	 * This is what tells the two silences apart. grecaptcha being absent means "we are
	 * holding it until your consent covers it", and "Google genuinely did not load" once
	 * it does — same observable, opposite advice to the visitor. timedOut alone cannot
	 * distinguish them, because the poll below is armed on every pageview that has
	 * something held, whatever the visitor has or has not chosen.
	 *
	 * ⚠️ NOT "did they answer the banner". A visitor who answered by REJECTING
	 * non-essential cookies has a consent record, yet reCAPTCHA stays blocked for them
	 * exactly as it does before any answer — so the honest advice is still "accept
	 * cookies", because accepting is what fixes it. Testing merely that a record EXISTS
	 * sent them to "reCAPTCHA did not load, reload the page": false, and unactionable,
	 * since reloading changes nothing and the hold is ours.
	 *
	 * ⚠️ NOT the `cookies-unblocked.hu` event either. That fires only when the widget
	 * actually unblocked something (its emit is gated on `unblocked > 0`), so for a
	 * returning visitor whose recorded consent already disabled blocking outright it
	 * never fires at all — reporting "has not consented" for someone who had, and pinning
	 * them there once the poll expired.
	 *
	 * So ask the question the widget itself asks per script — `categories[category] ===
	 * true` — against the category THIS SITE has reCAPTCHA in, which the customer can
	 * change and which is localized in alongside the strings.
	 *
	 * Read synchronously at call time rather than latched, so it is correct on a pageview
	 * where no widget event ever fires. `readData` and `prefix` are both on the widget's
	 * documented global (`window.__hu` / `window.hu`). `prefix` is a build-time constant
	 * there and is always 'hu' today; the fallback is not for per-site renaming (there is
	 * none) but so a change to that constant cannot silently strand this lookup.
	 *
	 * Returns false whenever we cannot tell — widget absent because an optimizer dropped
	 * it or it bailed on bot detection, unreadable cookie, no categories. That is the safe
	 * direction: fall back to the pre-consent wording rather than blaming Google for a
	 * hold that is ours.
	 *
	 * @return {boolean}
	 */
	function consentCoversRecaptcha() {
		var hu = window.__hu || window.hu;

		if ( ! hu || typeof hu.readData !== 'function' ) {
			return false;
		}

		try {
			var data = hu.readData( 'cookie', ( hu.prefix || 'hu' ) + '-consent' );
			var categories = data && data.categories;

			return !! categories && categories[ recaptchaCategory() ] === true;
		} catch ( e ) {
			return false;
		}
	}

	/**
	 * The consent category Google reCAPTCHA sits in ON THIS SITE.
	 *
	 * Localized from the same cached portal config the PHP side reads, so a customer who
	 * moved reCAPTCHA to Analytics or Marketing gets the right question asked rather than
	 * a hardcoded 2. Falls back to the shipped default when the value is absent — an older
	 * cached page — which is DEFAULT_CATEGORY in gravity-forms.php.
	 *
	 * @return {number}
	 */
	function recaptchaCategory() {
		return parseInt( strings.category, 10 ) || 2;
	}

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
	 * Is this control the one that actually submits the form?
	 *
	 * Only the submit needs a reCAPTCHA token, so only the submit may be held back. A
	 * multi-page form's Next and Previous, and its save-and-continue link, ask nothing of
	 * Google — stopping those leaves the visitor stuck in a form they cannot page through,
	 * and protects nothing (#47928).
	 *
	 * Telling them apart cannot be done on the button's `type`. Gravity Forms sets the
	 * Previous control's type itself — `o.type = t < r.length ? "submit" : "button"` in
	 * assets/js/dist/scripts-theme.min.js (3.0.3, read 2026-08-25) — so Previous genuinely
	 * IS an `input[type=submit]`, and narrowing the click selector alone would not exclude
	 * it. Three signals, in order of how much we can trust them:
	 *
	 *   1. `data-submission-type`, which the same bundle stamps and reads, with the values
	 *      `S="submit",k="next",x="previous"`. When present it is Gravity Forms' own
	 *      statement of intent, so it decides outright.
	 *   2. Failing that — older markup carries no such attribute — the class. That bundle
	 *      matches navigation as `.gform_next_button` / `.gform_previous_button` and the
	 *      submit as `.gform_button`, so rule the navigation classes out first, because a
	 *      control can carry both.
	 *   3. Failing that too, position — but only as far as the evidence reaches. A theme can
	 *      replace the submit's markup entirely through Gravity Forms' own
	 *      `gform_submit_button` filter, and a replacement keeping neither the attribute nor
	 *      the class would otherwise stop being held, which is the promise 3.1.5 made to
	 *      those sites. On a SINGLE-PAGE form the submit sits in
	 *      `<div class="gform-footer gform_footer ...">` — read off a live customer page on
	 *      2026-08-25. That is the only shape verified, and the only one this catches.
	 *
	 * Beyond that, biased toward letting an unrecognised control through. Over-blocking is
	 * the failure being fixed here, and under-blocking is not a hole: Gravity Forms
	 * validates the token server-side, so a submit that slips through fails its captcha
	 * check rather than bypassing it. Note it cannot hang either — a held bundle never ran,
	 * so it never registered a submission step to stall on.
	 *
	 * THREE THINGS NOT ESTABLISHED, because Gravity Forms is not vendored here and only its
	 * theme bundle and one live page could be read:
	 *
	 *   a. What it stamps on a save-and-continue control. The bundle exports a
	 *      SUBMISSION_TYPE_SAVE_AND_CONTINUE constant but does not carry its value, so if
	 *      that value is "submit" this treats save as a submit and holds it. NAV_SELECTOR
	 *      lists the class, which covers only the attribute-absent case.
	 *   b. Where a MULTI-PAGE form puts its submit. If the last page's footer is not
	 *      `.gform_footer`, signal 3 does nothing there and a themed submit on a multi-page
	 *      form is let through — no worse than before this function existed, but not fixed
	 *      either. Deliberately not guessed at: an earlier attempt to exclude a
	 *      `.gform_page_footer` container was removed once it turned out to veto the very
	 *      shape signal 3 is meant to catch.
	 *   c. Whether a themed save-and-continue link, or a themed Next/Previous, keeps any GF
	 *      class — GF exposes `gform_savecontinue_link` and `gform_next_button` filters just
	 *      as it does `gform_submit_button`. If such a control keeps none and sits in the
	 *      footer, signal 3 holds it — a known over-block, and the accepted cost of closing
	 *      the themed-submit gap, since position cannot tell those two apart. A nav control
	 *      that KEEPS its class is safe: the NAV_SELECTOR veto runs first.
	 *
	 * @param {Element} button
	 * @return {boolean}
	 */
	function isSubmitControl( button ) {
		var declared = button.getAttribute( 'data-submission-type' );

		if ( declared !== null ) {
			return declared === SUBMISSION_TYPE_SUBMIT;
		}

		if ( button.matches( NAV_SELECTOR ) ) {
			return false;
		}

		return button.classList.contains( 'gform_button' ) || !! button.closest( '.gform_footer' );
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
	 * THREE states, not two. Whether consent COVERS reCAPTCHA and whether we gave up
	 * waiting for Google are independent, and the pair decides the advice:
	 *
	 *   covered? timed out?  what the visitor is told
	 *   -------- ----------  -----------------------------------------------------------
	 *   no       no          accept cookies — true, and accepting is what fixes it
	 *   no       yes         accept cookies — still true; that expiry was OUR hold, not
	 *                        Google failing, so "did not load" would be a lie
	 *   yes      no          still loading — transient, resolves when api.js defines
	 *                        grecaptcha. Telling them to accept is wrong (their consent
	 *                        already covers it) and blaming Google is premature
	 *   yes      yes         reCAPTCHA did not load — the only honest failure report
	 *
	 * The `no` rows deliberately collapse two different visitors: one who has not answered
	 * the banner, and one who answered by declining. Both are told to accept, and for both
	 * that is true — reCAPTCHA is held either way, and accepting is the only thing that
	 * releases it. Splitting on "has a consent record" instead of "is reCAPTCHA covered"
	 * put the decliner in row 4 and told them Google had failed.
	 *
	 * Row 3 is what a covered visitor hits while api.js loads, and it had no message of
	 * its own: it fell through to "please accept cookies", to someone who already had.
	 *
	 * @param {Element} form
	 * @return {void}
	 */
	function showNotice( form ) {
		if ( form.getElementsByClassName( NOTICE_CLASS ).length ) {
			return;
		}

		var notice = document.createElement( 'p' );
		var text;

		notice.className = NOTICE_CLASS;
		notice.setAttribute( 'role', 'alert' );

		if ( ! consentCoversRecaptcha() ) {
			text = strings.blockedMessage || 'Please accept cookies to submit this form.';
		} else if ( timedOut ) {
			text = strings.unavailableMessage || 'This form could not be submitted because Google reCAPTCHA did not load.';
		} else {
			text = strings.loadingMessage || 'This form is still loading. Please try again in a moment.';
		}

		notice.textContent = text;

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

		// Match broadly, then let isSubmitControl() decide — a multi-page form's Next and
		// Previous are themselves input[type=submit], so the selector cannot tell them apart.
		var button = e.target.closest( '[data-submission-type], input[type="submit"], button[type="submit"]' );

		if ( ! button || ! isSubmitControl( button ) ) {
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
	 * This is a RELEASE TRIGGER and nothing more. It used to also latch "consent was
	 * given", which it cannot witness — see consentCoversRecaptcha() for why the event is
	 * silent on the covered visitor, and blind to the decliner.
	 *
	 * timedOut is still cleared here: if scripts were just unblocked, any earlier expiry
	 * was the pre-consent wait, and the one starting now is the only one that could
	 * honestly report a load failure. Clearing it on a signal we DO observe is sound;
	 * inferring consent from it was not.
	 */
	document.addEventListener( 'cookies-unblocked.hu', function() {
		timedOut = false;

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
